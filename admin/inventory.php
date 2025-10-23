<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../dgz_motorshop_system/includes/product_variants.php';
require_once __DIR__ . '/includes/restock_request_helpers.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();
$canManualAdjust = in_array($role, ['admin', 'staff'], true);
$userId = $_SESSION['user_id'] ?? 0;
$allowedPriorities = ['low', 'medium', 'high'];
$sortableColumns = [
    'name' => 'name',
    'quantity' => 'quantity',
];
$allowedSortKeys = array_keys($sortableColumns);

ensureRestockVariantColumns($pdo);

$restockFormDefaults = [
    'product' => '',
    'variant' => '',
    'quantity' => '',
    'category' => '',
    'category_new' => '',
    'brand' => '',
    'brand_new' => '',
    'supplier' => '',
    'supplier_new' => '',
    'priority' => '',
    'notes' => '',
];
$restockFormData = $restockFormDefaults;

require_once __DIR__ . '/includes/inventory_notifications.php';
$notificationManageLink = 'inventory.php';
$inventoryNotificationData = loadInventoryNotifications($pdo);
$inventoryNotifications = $inventoryNotificationData['notifications'];
$inventoryNotificationCount = $inventoryNotificationData['active_count'];

// Fetch the authenticated user's information for the profile modal
$current_user = null;
try {
    $stmt = $pdo->prepare('SELECT name, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();
} catch (Exception $e) {
    error_log('User lookup failed: ' . $e->getMessage());
}

// Cache the current user's name for activity logs that survive account removal.
$currentUserName = $current_user['name'] ?? null;

function format_profile_date(?string $datetime): string
{
    if (!$datetime) {
        return 'N/A';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return 'N/A';
    }

    return date('F j, Y g:i A', $timestamp);
}

$profile_name = $current_user['name'] ?? 'N/A';
$profile_role = !empty($current_user['role']) ? ucfirst($current_user['role']) : 'N/A';
$profile_created = format_profile_date($current_user['created_at'] ?? null);

// Load products and their variants ahead of the restock workflow so validation can reference them.
$productsActiveClause = productsArchiveActiveCondition($pdo);
$allProducts = $pdo->query('SELECT * FROM products WHERE ' . $productsActiveClause . ' ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
$productVariantsMap = [];
if (!empty($allProducts)) {
    $productIds = array_column($allProducts, 'id');
    $variantRowsByProduct = !empty($productIds) ? fetchVariantsForProducts($pdo, $productIds) : [];

    foreach ($variantRowsByProduct as $productId => $variantRows) {
        $productVariantsMap[(int) $productId] = array_map(static function (array $variant): array {
            return [
                'id' => (int) ($variant['id'] ?? 0),
                'label' => (string) ($variant['label'] ?? ''),
                'sku' => (string) ($variant['sku'] ?? ''),
                'quantity' => isset($variant['quantity']) ? (int) $variant['quantity'] : 0,
                'is_default' => (int) ($variant['is_default'] ?? 0),
            ];
        }, $variantRows);
    }
}

$productVariantsBootstrap = [];
foreach ($productVariantsMap as $productId => $variants) {
    $productVariantsBootstrap[(string) $productId] = $variants;
}

// Handle restock request submission (available to any authenticated user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_restock_request'])) {
    $restockFormData = [
        'product' => $_POST['restock_product'] ?? '',
        'variant' => $_POST['restock_variant'] ?? '',
        'quantity' => $_POST['restock_quantity'] ?? '',
        'category' => $_POST['restock_category'] ?? '',
        'category_new' => $_POST['restock_category_new'] ?? '',
        'brand' => $_POST['restock_brand'] ?? '',
        'brand_new' => $_POST['restock_brand_new'] ?? '',
        'supplier' => $_POST['restock_supplier'] ?? '',
        'supplier_new' => $_POST['restock_supplier_new'] ?? '',
        'priority' => $_POST['restock_priority'] ?? '',
        'notes' => $_POST['restock_notes'] ?? '',
    ];
    $productId = intval($_POST['restock_product'] ?? 0);
    $variantId = intval($_POST['restock_variant'] ?? 0);
    $requestedQuantity = intval($_POST['restock_quantity'] ?? 0);
    $priority = strtolower(trim($_POST['restock_priority'] ?? ''));
    $notes = trim($_POST['restock_notes'] ?? '');
    $categoryChoice = trim($_POST['restock_category'] ?? '');
    $categoryNew = trim($_POST['restock_category_new'] ?? '');
    $brandChoice = trim($_POST['restock_brand'] ?? '');
    $brandNew = trim($_POST['restock_brand_new'] ?? '');
    $supplierChoice = trim($_POST['restock_supplier'] ?? '');
    $supplierNew = trim($_POST['restock_supplier_new'] ?? '');

    $resolveChoice = static function (string $selected, string $newValue): string {
        if ($selected === '__addnew__') {
            return $newValue;
        }
        if ($selected !== '') {
            return $selected;
        }
        return $newValue;
    };

    $category = trim($resolveChoice($categoryChoice, $categoryNew));
    $brand = trim($resolveChoice($brandChoice, $brandNew));
    $supplier = trim($resolveChoice($supplierChoice, $supplierNew));

    if (!$userId) {
        $error_message = 'Unable to submit restock request: user not found.';
    } elseif (!$productId || $requestedQuantity <= 0) {
        $error_message = 'Please select a product and enter a valid quantity for the restock request.';
    } elseif (!in_array($priority, $allowedPriorities, true)) {
        $error_message = 'Please choose a valid priority level.';
    } else {
        if (!isset($error_message)) {
            try {
                $productStmt = $pdo->prepare('SELECT category, brand, supplier FROM products WHERE id = ? AND ' . $productsActiveClause);
                $productStmt->execute([$productId]);
                $productMeta = $productStmt->fetch(PDO::FETCH_ASSOC);
                if (!$productMeta) {
                    throw new RuntimeException('Selected product could not be found.');
                }
                $category = trim($category !== '' ? $category : ($productMeta['category'] ?? ''));
                $brand = trim($brand !== '' ? $brand : ($productMeta['brand'] ?? ''));
                $supplier = trim($supplier !== '' ? $supplier : ($productMeta['supplier'] ?? ''));
            } catch (Exception $e) {
                $error_message = 'Unable to load product details: ' . $e->getMessage();
            }
        }

        $variantLabel = null;
        if (!isset($error_message)) {
            $variantsForProduct = $productVariantsMap[$productId] ?? [];
            if (!empty($variantsForProduct)) {
                if ($variantId <= 0) {
                    $error_message = 'Please select a variant for the chosen product before submitting the request.';
                } else {
                    $matchedVariant = null;
                    foreach ($variantsForProduct as $variantRow) {
                        if ((int) ($variantRow['id'] ?? 0) === $variantId) {
                            $matchedVariant = $variantRow;
                            break;
                        }
                    }

                    if ($matchedVariant === null) {
                        $error_message = 'The selected variant is no longer available. Refresh the page and try again.';
                    } else {
                        $variantLabel = trim((string) ($matchedVariant['label'] ?? ''));
                        if ($variantLabel === '') {
                            $variantLabel = 'Variant #' . $variantId;
                        }
                    }
                }
            } else {
                $variantId = 0;
            }
        }

        if (!isset($error_message)) {
            try {
                $stmt = $pdo->prepare('INSERT INTO restock_requests (product_id, variant_id, variant_label, requested_by, requested_by_name, quantity_requested, priority_level, notes, category, brand, supplier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $productId,
                    $variantId > 0 ? $variantId : null,
                    $variantLabel,
                    $userId,
                    $currentUserName,
                    $requestedQuantity,
                    $priority,
                    $notes,
                    $category === '' ? null : $category,
                    $brand === '' ? null : $brand,
                    $supplier === '' ? null : $supplier
                ]);
                $requestId = (int) $pdo->lastInsertId();

                $logStmt = $pdo->prepare('INSERT INTO restock_request_history (request_id, status, noted_by, noted_by_name) VALUES (?, ?, ?, ?)');
                $logStmt->execute([
                    $requestId,
                    'pending',
                    $userId ?: null,
                    $currentUserName
                ]);
                $success_message = 'Restock request submitted successfully!';
                $restockFormData = $restockFormDefaults;
            } catch (Exception $e) {
                $error_message = 'Failed to submit restock request: ' . $e->getMessage();
            }
        }
    }
}


// New: capture manual adjustment feedback so we can flash a message and return to the edited row
if ($canManualAdjust && isset($_POST['update_stock'])) {
    $id = intval($_POST['id'] ?? 0);
    $change = intval($_POST['change'] ?? 0);
    $variantId = intval($_POST['variant_id'] ?? 0);
    $redirectTarget = 'inventory.php';

    $returnViewRaw = $_POST['return_view'] ?? '';
    if (is_string($returnViewRaw)) {
        $returnViewRaw = trim($returnViewRaw);
    } else {
        $returnViewRaw = '';
    }

    if ($returnViewRaw !== '') {
        $returnViewData = [];
        parse_str($returnViewRaw, $returnViewData);
        if (!empty($returnViewData)) {
            $allowedViewKeys = array_flip(['search', 'brand', 'category', 'page', 'sort', 'direction']);
            $returnViewData = array_intersect_key($returnViewData, $allowedViewKeys);

            if (isset($returnViewData['page'])) {
                $pageValue = max(1, (int) $returnViewData['page']);
                if ($pageValue === 1) {
                    unset($returnViewData['page']);
                } else {
                    $returnViewData['page'] = (string) $pageValue;
                }
            }

            if (isset($returnViewData['sort'])) {
                $sortValue = (string) $returnViewData['sort'];
                if (!in_array($sortValue, $allowedSortKeys, true)) {
                    unset($returnViewData['sort'], $returnViewData['direction']);
                } else {
                    $returnViewData['sort'] = $sortValue;
                    if (isset($returnViewData['direction'])) {
                        $directionValue = strtolower((string) $returnViewData['direction']);
                        if (!in_array($directionValue, ['asc', 'desc'], true)) {
                            unset($returnViewData['direction']);
                        } else {
                            $returnViewData['direction'] = $directionValue;
                        }
                    }
                }
            } else {
                unset($returnViewData['direction']);
            }

            foreach (['search', 'brand', 'category'] as $textKey) {
                if (isset($returnViewData[$textKey])) {
                    $textValue = trim((string) $returnViewData[$textKey]);
                    if ($textValue === '') {
                        unset($returnViewData[$textKey]);
                    } else {
                        $returnViewData[$textKey] = $textValue;
                    }
                }
            }

            $queryString = http_build_query($returnViewData);
            if ($queryString !== '') {
                $redirectTarget .= '?' . $queryString;
            }
        }
    }

    if ($id) {
        $redirectTarget .= '#product-' . $id;
    }

    $variantsForProduct = $id ? ($productVariantsMap[$id] ?? []) : [];
    $selectedVariantMeta = null;
    if (!empty($variantsForProduct)) {
        if ($variantId <= 0) {
            $_SESSION['manual_adjust_message'] = [
                'type' => 'warning',
                'text' => 'Select a variant before adjusting stock for this product.',
                'product_id' => $id,
                'variant_id' => null,
            ];

            header('Location: ' . $redirectTarget);
            exit;
        }

        foreach ($variantsForProduct as $variantRow) {
            if ((int) ($variantRow['id'] ?? 0) === $variantId) {
                $selectedVariantMeta = $variantRow;
                break;
            }
        }

        if ($variantId > 0 && $selectedVariantMeta === null) {
            $_SESSION['manual_adjust_message'] = [
                'type' => 'error',
                'text' => 'The selected variant is no longer available. Refresh and try again.',
                'product_id' => $id,
                'variant_id' => null,
            ];

            header('Location: ' . $redirectTarget);
            exit;
        }
    } else {
        $variantId = 0;
    }

    if ($id && $change !== 0) {
        try {
            $pdo->beginTransaction();

            $lookupStmt = $pdo->prepare('SELECT name, quantity FROM products WHERE id = ? AND ' . $productsActiveClause . ' LIMIT 1');
            $lookupStmt->execute([$id]);
            $productRow = $lookupStmt->fetch(PDO::FETCH_ASSOC);

            if (!$productRow) {
                throw new RuntimeException('The selected product could not be found.');
            }

            $currentQuantity = isset($productRow['quantity']) ? (int) $productRow['quantity'] : 0;
            $proposedQuantity = $currentQuantity + $change;
            $boundedQuantity = max(0, $proposedQuantity);
            $productEffectiveChange = $boundedQuantity - $currentQuantity;

            $variantEffectiveChange = $change;
            $variantLimitedChange = false;
            if ($selectedVariantMeta !== null && $variantId > 0) {
                $variantLookup = $pdo->prepare('SELECT quantity FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1');
                $variantLookup->execute([$variantId, $id]);
                $variantRow = $variantLookup->fetch(PDO::FETCH_ASSOC);

                if (!$variantRow) {
                    throw new RuntimeException('The selected variant could not be found.');
                }

                $variantCurrentQuantity = isset($variantRow['quantity']) ? (int) $variantRow['quantity'] : 0;
                if ($change < 0) {
                    $variantProposedQuantity = $variantCurrentQuantity + $change;
                    $variantBoundedQuantity = max(0, $variantProposedQuantity);
                    $variantEffectiveChange = $variantBoundedQuantity - $variantCurrentQuantity;
                    if ($variantEffectiveChange !== $change) {
                        $variantLimitedChange = true;
                    }
                } else {
                    $variantEffectiveChange = $change;
                }
            }

            $productLimitedChange = ($change < 0 && $productEffectiveChange !== $change);

            $effectiveChange = $productEffectiveChange;
            if ($selectedVariantMeta !== null && $variantId > 0) {
                if ($change < 0) {
                    $effectiveChange = max($productEffectiveChange, $variantEffectiveChange);
                } else {
                    $effectiveChange = min($productEffectiveChange, $variantEffectiveChange);
                }
                $boundedQuantity = $currentQuantity + $effectiveChange;
            }

            if ($effectiveChange !== 0) {
                $updateStmt = $pdo->prepare('UPDATE products SET quantity = ? WHERE id = ?');
                $updateStmt->execute([$boundedQuantity, $id]);

                if ($selectedVariantMeta !== null && $variantId > 0) {
                    adjustVariantQuantity($pdo, $variantId, $effectiveChange);
                } else {
                    adjustDefaultVariantQuantity($pdo, $id, $effectiveChange);
                }
            }

            $pdo->commit();

            if ($effectiveChange === 0) {
                $noChangeText = 'No stock was adjusted because the product is already at zero.';
                if ($selectedVariantMeta !== null && $variantId > 0 && $change < 0 && $variantLimitedChange && $variantEffectiveChange === 0) {
                    $variantLabel = trim((string) ($selectedVariantMeta['label'] ?? ''));
                    $variantSku = trim((string) ($selectedVariantMeta['sku'] ?? ''));
                    if ($variantLabel === '' && $variantSku !== '') {
                        $variantLabel = $variantSku;
                    }
                    if ($variantLabel === '') {
                        $variantLabel = 'Variant #' . $variantId;
                    } elseif ($variantSku !== '' && stripos($variantLabel, $variantSku) === false) {
                        $variantLabel .= ' (' . $variantSku . ')';
                    }
                    $noChangeText = sprintf('No stock was adjusted because the selected variant (%s) is already at zero.', $variantLabel);
                }

                $_SESSION['manual_adjust_message'] = [
                    'type' => 'warning',
                    'text' => $noChangeText,
                    'product_id' => $id,
                    'variant_id' => $variantId > 0 ? $variantId : null,
                ];
            } else {
                $verb = $effectiveChange > 0 ? 'added to' : 'removed from';
                $quantityChanged = abs($effectiveChange);
                $limitedNotes = [];
                if ($change < 0) {
                    if ($productLimitedChange && $effectiveChange === $productEffectiveChange) {
                        $limitedNotes[] = 'limited by available product stock';
                    }
                    if ($variantLimitedChange && $selectedVariantMeta !== null && $effectiveChange === $variantEffectiveChange) {
                        $limitedNotes[] = 'limited by selected variant stock';
                    }
                }

                $variantLabel = '';
                if ($selectedVariantMeta !== null && $variantId > 0) {
                    $variantName = trim((string) ($selectedVariantMeta['label'] ?? ''));
                    $variantSku = trim((string) ($selectedVariantMeta['sku'] ?? ''));
                    if ($variantName === '' && $variantSku !== '') {
                        $variantName = $variantSku;
                    }
                    if ($variantName === '') {
                        $variantName = 'Variant #' . $variantId;
                    } elseif ($variantSku !== '' && stripos($variantName, $variantSku) === false) {
                        $variantName .= ' (' . $variantSku . ')';
                    }
                    $variantLabel = $variantName;
                }

                $message = sprintf('%dpcs %s "%s"', $quantityChanged, $verb, $productRow['name']);
                if ($variantLabel !== '') {
                    $message .= ' — Variant: ' . $variantLabel;
                }
                if (!empty($limitedNotes)) {
                    $message .= ' (' . implode('; ', $limitedNotes) . ')';
                }

                $_SESSION['manual_adjust_message'] = [
                    'type' => 'success',
                    'text' => $message,
                    'product_id' => $id,
                    'variant_id' => $variantId > 0 ? $variantId : null,
                ];
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $_SESSION['manual_adjust_message'] = [
                'type' => 'error',
                'text' => 'Unable to update the product quantity. Please try again.',
                'product_id' => $id,
                'variant_id' => $variantId > 0 ? $variantId : null,
            ];
        }
    } else {
        $_SESSION['manual_adjust_message'] = [
            'type' => 'warning',
            'text' => 'Enter a quantity before submitting a manual adjustment.',
            'product_id' => $id,
            'variant_id' => $variantId > 0 ? $variantId : null,
        ];
    }

    header('Location: ' . $redirectTarget);
    exit;
}

// New: hydrate any manual adjustment flash message for the next render cycle
$manualAdjustFeedback = $_SESSION['manual_adjust_message'] ?? null;
if ($manualAdjustFeedback) {
    unset($_SESSION['manual_adjust_message']);
}
// Handle stock entry submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock'])) {
    $product_id = $_POST['product_id'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $supplier = $_POST['supplier'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $purchase_price = isset($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : 0;
    
    if ($product_id && $quantity > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
            $stmt->execute([$quantity, $product_id]);

            adjustDefaultVariantQuantity($pdo, (int) $product_id, $quantity);
            $stmt = $pdo->prepare("INSERT INTO stock_entries (product_id, quantity_added, purchase_price, supplier, notes, stock_in_by, stock_in_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $product_id,
                $quantity,
                $purchase_price,
                $supplier,
                $notes,
                $_SESSION['user_id'],
                $currentUserName
            ]);
            $pdo->commit();
            $success_message = "Stock updated successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error updating stock: " . $e->getMessage();
        }
    } else {
        $error_message = "Please select a product and enter a valid quantity.";
    }

}

// Lookups for restock request form selects
$categoryOptions = $pdo->query('SELECT DISTINCT category FROM products WHERE ' . $productsActiveClause . ' AND category IS NOT NULL AND category != "" ORDER BY category ASC')->fetchAll(PDO::FETCH_COLUMN);
$brandOptions = $pdo->query('SELECT DISTINCT brand FROM products WHERE ' . $productsActiveClause . ' AND brand IS NOT NULL AND brand != "" ORDER BY brand ASC')->fetchAll(PDO::FETCH_COLUMN);
$supplierOptions = $pdo->query('SELECT DISTINCT supplier FROM products WHERE ' . $productsActiveClause . ' AND supplier IS NOT NULL AND supplier != "" ORDER BY supplier ASC')->fetchAll(PDO::FETCH_COLUMN);

// Handle search/filter/pagination for the inventory listing
$search = trim($_GET['search'] ?? '');
$brandFilter = trim($_GET['brand'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, $page);
$limit = 20;
$offset = ($page - 1) * $limit;

$sort = $_GET['sort'] ?? '';
$direction = strtolower($_GET['direction'] ?? 'asc');
if (!in_array($direction, ['asc', 'desc'], true)) {
    $direction = 'asc';
}

$whereSql = 'WHERE ' . $productsActiveClause;
$filterParams = [];

if ($search !== '') {
    $whereSql .= ' AND (name LIKE :search_name OR code LIKE :search_code)';
    $filterParams[':search_name'] = "%$search%";
    $filterParams[':search_code'] = "%$search%";
}

if ($brandFilter !== '') {
    $whereSql .= ' AND brand = :brand_filter';
    $filterParams[':brand_filter'] = $brandFilter;
}

if ($categoryFilter !== '') {
    $whereSql .= ' AND category = :category_filter';
    $filterParams[':category_filter'] = $categoryFilter;
}

$countSql = 'SELECT COUNT(*) FROM products ' . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($filterParams);
$totalInventoryProducts = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalInventoryProducts / $limit);

$orderBySql = 'ORDER BY created_at DESC';
$currentSort = '';
$currentDirection = '';
if (isset($sortableColumns[$sort])) {
    $orderBySql = 'ORDER BY ' . $sortableColumns[$sort] . ' ' . strtoupper($direction);
    $currentSort = $sort;
    $currentDirection = $direction;
}

$inventorySql = 'SELECT * FROM products ' . $whereSql . ' ' . $orderBySql . ' LIMIT :limit OFFSET :offset';
$inventoryStmt = $pdo->prepare($inventorySql);
foreach ($filterParams as $placeholder => $value) {
    $inventoryStmt->bindValue($placeholder, $value);
}
$inventoryStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$inventoryStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$inventoryStmt->execute();
$inventoryProducts = $inventoryStmt->fetchAll();

$startRecord = $totalInventoryProducts > 0 ? $offset + 1 : 0;
$endRecord = min($offset + $limit, $totalInventoryProducts);

$nameSortDirection = ($currentSort === 'name' && $currentDirection === 'asc') ? 'desc' : 'asc';
$nameSortParams = $_GET;
unset($nameSortParams['page']);
$nameSortParams['page'] = 1;
$nameSortParams['sort'] = 'name';
$nameSortParams['direction'] = $nameSortDirection;
$nameSortQuery = http_build_query($nameSortParams);
$nameSortUrl = 'inventory.php' . ($nameSortQuery ? '?' . $nameSortQuery : '');
$nameSortIndicator = '';
if ($currentSort === 'name') {
    $nameSortIndicator = $currentDirection === 'asc' ? '▲' : '▼';
} else {
    $nameSortIndicator = '↕';
}

$quantitySortDirection = ($currentSort === 'quantity' && $currentDirection === 'asc') ? 'desc' : 'asc';
$quantitySortParams = $_GET;
unset($quantitySortParams['page']);
$quantitySortParams['page'] = 1;
$quantitySortParams['sort'] = 'quantity';
$quantitySortParams['direction'] = $quantitySortDirection;
$quantitySortQuery = http_build_query($quantitySortParams);
$quantitySortUrl = 'inventory.php' . ($quantitySortQuery ? '?' . $quantitySortQuery : '');
$quantitySortIndicator = '';
if ($currentSort === 'quantity') {
    $quantitySortIndicator = $currentDirection === 'asc' ? '▲' : '▼';
} else {
    $quantitySortIndicator = '↕';
}

$manualAdjustReturnParams = [
    'search' => $search !== '' ? $search : null,
    'brand' => $brandFilter !== '' ? $brandFilter : null,
    'category' => $categoryFilter !== '' ? $categoryFilter : null,
    'page' => $page > 1 ? (string) $page : null,
    'sort' => $currentSort !== '' ? $currentSort : null,
    'direction' => $currentDirection !== '' ? $currentDirection : null,
];

$manualAdjustReturnParams = array_filter($manualAdjustReturnParams, static function ($value) {
    return $value !== null && $value !== '';
});
$manualAdjustReturnView = http_build_query($manualAdjustReturnParams);

$exportParams = $_GET;
unset($exportParams['export'], $exportParams['page']);
$exportParams['export'] = 'csv';
$exportQuery = http_build_query($exportParams);
$exportUrl = 'inventory.php' . ($exportQuery ? '?' . $exportQuery : '?export=csv');

// Restock requests overview data
$restockRequests = $pdo->query('
    SELECT rr.*, p.name AS product_name, p.code AS product_code,
           rr.variant_label,
           pv.label AS variant_current_label,
           COALESCE(requester.name, rr.requested_by_name) AS requester_name,
           COALESCE(reviewer.name, rr.reviewed_by_name) AS reviewer_name
    FROM restock_requests rr
    LEFT JOIN products p ON p.id = rr.product_id
    LEFT JOIN product_variants pv ON pv.id = rr.variant_id
    LEFT JOIN users requester ON requester.id = rr.requested_by
    LEFT JOIN users reviewer ON reviewer.id = rr.reviewed_by
    ORDER BY rr.created_at DESC
')->fetchAll(PDO::FETCH_ASSOC);

foreach ($restockRequests as &$restockRow) {
    if (empty($restockRow['variant_label']) && !empty($restockRow['variant_current_label'])) {
        $restockRow['variant_label'] = $restockRow['variant_current_label'];
    }
}
unset($restockRow);

$pendingRestockRequests = array_values(array_filter($restockRequests, function ($row) {
    return strtolower($row['status'] ?? 'pending') === 'pending';
}));

$resolvedRestockRequests = array_values(array_filter($restockRequests, function ($row) {
    return strtolower($row['status'] ?? 'pending') !== 'pending';
}));

$defaultRestockTab = !empty($pendingRestockRequests) ? 'pending' : 'processed';
if (!empty($pendingRestockRequests)) {
    $defaultRestockTab = 'pending';
}

$restockHistory = $pdo->query('
    SELECT h.*,
           rr.quantity_requested AS request_quantity,
           rr.priority_level AS request_priority,
           rr.notes AS request_notes,
           rr.category AS request_category,
           rr.brand AS request_brand,
           rr.supplier AS request_supplier,
           rr.variant_label,
           pv.label AS variant_current_label,
           p.name AS product_name, p.code AS product_code,
           COALESCE(requester.name, rr.requested_by_name) AS requester_name,
           COALESCE(reviewer.name, rr.reviewed_by_name) AS reviewer_name,
           COALESCE(status_user.name, h.noted_by_name) AS status_user_name
    FROM restock_request_history h
    JOIN restock_requests rr ON rr.id = h.request_id
    LEFT JOIN products p ON p.id = rr.product_id
    LEFT JOIN product_variants pv ON pv.id = rr.variant_id
    LEFT JOIN users requester ON requester.id = rr.requested_by
    LEFT JOIN users reviewer ON reviewer.id = rr.reviewed_by
    LEFT JOIN users status_user ON status_user.id = h.noted_by
    ORDER BY h.created_at DESC
')->fetchAll(PDO::FETCH_ASSOC);

foreach ($restockHistory as &$historyRow) {
    if (empty($historyRow['variant_label']) && !empty($historyRow['variant_current_label'])) {
        $historyRow['variant_label'] = $historyRow['variant_current_label'];
    }
}
unset($historyRow);

if (!function_exists('getPriorityClass')) {
    function getPriorityClass(string $priority): string
    {
        switch ($priority) {
            case 'high':
                return 'badge-high';
            case 'medium':
                return 'badge-medium';
            default:
                return 'badge-low';
        }
    }
}

if (!function_exists('getStatusClass')) {
    function getStatusClass(string $status): string
    {
        switch ($status) {
            case 'approved':
                return 'status-approved';
            case 'denied':
            case 'declined':
                return 'status-denied';
            case 'fulfilled':
                return 'status-fulfilled';
            default:
                return 'status-pending';
        }
    }
}

// Handle export to CSV
if(isset($_GET['export']) && $_GET['export'] == 'csv') {
    $exportSql = 'SELECT code, name, quantity, low_stock_threshold, created_at FROM products ' . $whereSql . ' ' . $orderBySql;
    $exportStmt = $pdo->prepare($exportSql);
    foreach ($filterParams as $placeholder => $value) {
        $exportStmt->bindValue($placeholder, $value);
    }
    $exportStmt->execute();
    $exportProducts = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product Code','Name','Quantity','Low Stock Threshold','Date Added']);
    foreach($exportProducts as $p) {
        fputcsv($out, [$p['code'],$p['name'],$p['quantity'],$p['low_stock_threshold'],$p['created_at']]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/style.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/inventory/inventory.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/inventory/restockRequest.css">
    <style>
        .inventory-actions {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }

        .btn-primary, .btn-secondary {
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary { 
            background-color: #007bff;
            color: white;
            border: none;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
            border: none;
        }

        .btn-accent {
            background-color: #17a2b8;
            color: #fff;
            border: none;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
            position: relative;
        }

        .close {
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 24px;
            cursor: pointer;
        }

        .inventory-table th .sort-link {
            color: inherit;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .inventory-table th .sort-link:hover {
            text-decoration: underline;
        }

        .inventory-table th .sort-indicator {
            font-size: 12px;
            line-height: 1;
        }

        .hidden {
            display: none;
        }

        .status-toggle-btn {
            background-color: #17a2b8;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .status-toggle-btn.active {
            box-shadow: inset 0 0 0 2px rgba(255,255,255,0.4);
        }

        .restock-status {
            margin: 20px 0;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }

        .tab-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .status-history-header {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .open-requests {
            margin-bottom: 20px;
        }

        .tab-btn {
            background-color: #e9ecef;
            border: none;
            border-radius: 999px;
            padding: 8px 18px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #495057;
        }

        .tab-btn.active {
            background-color: #17a2b8;
            color: #fff;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .requests-table {
            width: 100%;
            border-collapse: collapse;
        }

        .requests-table th,
        .requests-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .requests-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }

        .product-cell {
            display: flex;
            flex-direction: column;
        }

        .product-name {
            font-weight: 600;
            color: #343a40;
        }

        .product-code {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .priority-badge,
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge-high {
            background-color: #f8d7da;
            color: #721c24;
        }

        .badge-medium {
            background-color: #fff3cd;
            color: #856404;
        }

        .badge-low {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .status-pending {
            background-color: #e2e3e5;
            color: #383d41;
        }

        .status-approved {
            background-color: #d4edda;
            color: #155724;
        }

        .status-denied {
            background-color: #f8d7da;
            color: #721c24;
        }

        .status-fulfilled {
            background-color: #cce5ff;
            color: #004085;
        }

        .notes-cell {
            white-space: pre-wrap;
            max-width: 260px;
        }

        .muted {
            color: #6c757d;
        }

        .action-cell {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .inline-form {
            margin: 0;
        }

        .btn-approve,
        .btn-decline {
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: 100%;
            justify-content: center;
        }

        .btn-approve {
            background-color: #28a745;
            color: #fff;
        }

        .btn-decline {
            background-color: #dc3545;
            color: #fff;
        }

        .btn-approve:hover {
            background-color: #218838;
        }

        .btn-decline:hover {
            background-color: #c82333;
        }

        .action-cell form + form {
            margin-top: 4px;
        }

        @media (max-width: 768px) {
            .action-cell {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 6px;
            }

            .btn-approve,
            .btn-decline {
                width: auto;
            }
        }

        /* Alert Styles */
        .alert {
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.15);
        }

        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error   { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Floating variant so feedback is visible while scrolling */
        .alert-floating {
            position: fixed;
            top: 120px; /* align near Add Stock row */
            left: calc(260px + 30px); /* sidebar width + page padding */
            z-index: 10050;
            max-width: 520px;
            width: auto;
        }

        @media (max-width: 1024px) {
            .alert-floating { left: 16px; right: 16px; width: auto; }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <?php
        $activePage = 'inventory.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>
    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Inventory</h2>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/partials/notification_menu.php'; ?>
                <div class="user-menu">
                    <div class="user-avatar" onclick="toggleDropdown()">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <a href="login.php?logout=1" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Messages (floating so they remain visible when scrolling) -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-floating" role="status" data-float-alert>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error alert-floating" role="status" data-float-alert>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <script>
            // Auto-dismiss floating alerts after a short delay
            (function () {
                function dismiss(node) {
                    if (!node) return;
                    node.style.transition = 'opacity .4s ease, transform .4s ease';
                    node.style.opacity = '0';
                    node.style.transform = 'translateY(-6px)';
                    setTimeout(function () { try { node.remove(); } catch (e) {} }, 450);
                }

                // Inventory table flash (manual adjust)
                var tableFlash = document.querySelector('[data-inventory-flash]');
                if (tableFlash) { setTimeout(function () { dismiss(tableFlash); }, 4000); }

                // Top-level success/error alerts
                var floatAlerts = document.querySelectorAll('[data-float-alert]');
                floatAlerts.forEach(function (node) {
                    setTimeout(function () { dismiss(node); }, 4000);
                });
            })();
        </script>

        <div class="inventory-actions">
            
            <button class="btn-accent" onclick="toggleRestockForm()" type="button">
                <i class="fas fa-truck-loading"></i> Restock Request
            </button>
            <button class="status-toggle-btn" type="button" onclick="toggleRestockStatus()" id="restockStatusButton">
                <i class="fas fa-clipboard-check"></i> Request Status
            </button>
           
        </div>
        <!-- Restock Request Form -->

        <div id="restockRequestForm" class="restock-request hidden" aria-hidden="true">
            <div class="restock-form-header">
                <h3><i class="fas fa-clipboard-list"></i> Submit Restock Request</h3>
                <div class="product-picker-actions">
                    <button type="button" class="product-filter-apply" data-product-filter>
                        Apply Filters
                    </button>
                    <button type="button" class="product-filter-reset" data-product-filter-clear>
                        Reset
                    </button>
                </div>
            </div>
            <form method="post" class="restock-form"
                data-initial-product="<?php echo htmlspecialchars($restockFormData['product']); ?>"
                data-initial-variant="<?php echo htmlspecialchars($restockFormData['variant']); ?>"
                data-initial-quantity="<?php echo htmlspecialchars($restockFormData['quantity']); ?>"
                data-initial-category="<?php echo htmlspecialchars($restockFormData['category']); ?>"
                data-initial-category-new="<?php echo htmlspecialchars($restockFormData['category_new']); ?>"
                data-initial-brand="<?php echo htmlspecialchars($restockFormData['brand']); ?>"
                data-initial-brand-new="<?php echo htmlspecialchars($restockFormData['brand_new']); ?>"
                data-initial-supplier="<?php echo htmlspecialchars($restockFormData['supplier']); ?>"
                data-initial-supplier-new="<?php echo htmlspecialchars($restockFormData['supplier_new']); ?>"
                data-initial-priority="<?php echo htmlspecialchars($restockFormData['priority']); ?>"
                data-initial-notes="<?php echo htmlspecialchars($restockFormData['notes']); ?>">
                <input type="hidden" name="submit_restock_request" value="1">
                <?php
                    $selectedProductId = isset($restockFormData['product']) ? (int) $restockFormData['product'] : 0;
                    $selectedProductVariants = $selectedProductId > 0 ? ($productVariantsMap[$selectedProductId] ?? []) : [];
                    $selectedVariantId = isset($restockFormData['variant']) ? (int) $restockFormData['variant'] : 0;
                ?>
                <div class="restock-grid">
                    <div class="form-group">
                        <label for="restock_product">Product</label>
                        <!-- Added product search tools so staff can quickly locate a product option -->
                        <div class="product-picker-tools">
                            <div class="product-picker-search-wrapper">
                                <input
                                    type="text"
                                    id="restock_product_search"
                                    class="product-picker-search"
                                    placeholder="Search by product name or code"
                                    autocomplete="off"
                                >
                                <ul class="product-picker-suggestions" data-product-suggestions></ul>
                            </div>
                        </div>
                        <select id="restock_product" name="restock_product" required>
                            <option value="">Select Product</option>
                            <?php foreach ($allProducts as $product): ?>
                                <?php
                                    $restockProductId = (int) ($product['id'] ?? 0);
                                    $variantCount = isset($productVariantsMap[$restockProductId]) ? count($productVariantsMap[$restockProductId]) : 0;
                                ?>
                                <option 
                                    value="<?php echo $restockProductId; ?>"
                                    data-name="<?php echo htmlspecialchars($product['name'] ?? ''); ?>"
                                    data-category="<?php echo htmlspecialchars($product['category'] ?? ''); ?>"
                                    data-brand="<?php echo htmlspecialchars($product['brand'] ?? ''); ?>"
                                    data-supplier="<?php echo htmlspecialchars($product['supplier'] ?? ''); ?>"
                                    data-code="<?php echo htmlspecialchars($product['code'] ?? ''); ?>"
                                    data-variant-count="<?php echo $variantCount; ?>"
                                    <?php echo ($restockFormData['product'] === (string) $restockProductId) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($product['name']); ?>
                                    (Current: <?php echo $product['quantity']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" data-restock-variant-field>
                        <label for="restock_variant">Variant</label>
                        <select id="restock_variant" name="restock_variant" <?php echo empty($selectedProductVariants) ? 'disabled' : 'required'; ?>>
                            <?php if (empty($selectedProductVariants)): ?>
                                <option value="">No variants available</option>
                            <?php else: ?>
                                <option value="">Select variant</option>
                                <?php foreach ($selectedProductVariants as $variantRow): ?>
                                    <?php
                                        $variantId = (int) ($variantRow['id'] ?? 0);
        	                            $variantLabel = trim((string) ($variantRow['label'] ?? 'Variant #' . $variantId));
                                    ?>
                                    <option value="<?php echo $variantId; ?>" <?php echo $selectedVariantId === $variantId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($variantLabel !== '' ? $variantLabel : 'Variant #' . $variantId); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="restock_category">Category</label>
                        <select id="restock_category" name="restock_category">
                            <option value="">Select category</option>
                            <?php foreach ($categoryOptions as $categoryOption): ?>
                                <option value="<?php echo htmlspecialchars($categoryOption); ?>" <?php echo ($restockFormData['category'] === $categoryOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($categoryOption); ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__" <?php echo ($restockFormData['category'] === '__addnew__' || ($restockFormData['category'] === '' && $restockFormData['category_new'] !== '')) ? 'selected' : ''; ?>>Add new category...</option>
                        </select>
                        <input type="text" id="restock_category_new" name="restock_category_new" class="optional-input" placeholder="Enter new category" value="<?php echo htmlspecialchars($restockFormData['category_new']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="restock_brand">Brand</label>
                        <select id="restock_brand" name="restock_brand">
                            <option value="">Select brand</option>
                            <?php foreach ($brandOptions as $brandOption): ?>
                                <option value="<?php echo htmlspecialchars($brandOption); ?>" <?php echo ($restockFormData['brand'] === $brandOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($brandOption); ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__" <?php echo ($restockFormData['brand'] === '__addnew__' || ($restockFormData['brand'] === '' && $restockFormData['brand_new'] !== '')) ? 'selected' : ''; ?>>Add new brand...</option>
                        </select>
                        <input type="text" id="restock_brand_new" name="restock_brand_new" class="optional-input" placeholder="Enter new brand" value="<?php echo htmlspecialchars($restockFormData['brand_new']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="restock_supplier">Supplier</label>
                        <select id="restock_supplier" name="restock_supplier">
                            <option value="">Select supplier</option>
                            <?php foreach ($supplierOptions as $supplierOption): ?>
                                <option value="<?php echo htmlspecialchars($supplierOption); ?>" <?php echo ($restockFormData['supplier'] === $supplierOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($supplierOption); ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__" <?php echo ($restockFormData['supplier'] === '__addnew__' || ($restockFormData['supplier'] === '' && $restockFormData['supplier_new'] !== '')) ? 'selected' : ''; ?>>Add new supplier...</option>
                        </select>
                        <input type="text" id="restock_supplier_new" name="restock_supplier_new" class="optional-input" placeholder="Enter new supplier" value="<?php echo htmlspecialchars($restockFormData['supplier_new']); ?>">
                    </div>
                    <div class="form-group quantity-group">
                        <label for="restock_quantity">Requested Quantity</label>
                        <input type="number" id="restock_quantity" name="restock_quantity" min="1" placeholder="Enter quantity" required value="<?php echo htmlspecialchars($restockFormData['quantity']); ?>">
                    </div>
                    <div class="form-group notes-group span-two">
                        <label for="restock_notes">Reason / Notes</label>
                        <textarea id="restock_notes" name="restock_notes" placeholder="Provide additional details for the restock request..."><?php echo htmlspecialchars($restockFormData['notes']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="restock_priority">Priority Level</label>
                        <select id="restock_priority" name="restock_priority" required>
                            <option value="">Select Priority</option>
                            <option value="low" <?php echo ($restockFormData['priority'] === 'low') ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo ($restockFormData['priority'] === 'medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo ($restockFormData['priority'] === 'high') ? 'selected' : ''; ?>>High</option>
                        </select>
                    </div>
                    <div class="restock-actions span-three">
                        <button type="submit" class="submit-btn">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                        <button type="button" class="btn-secondary" onclick="toggleRestockForm()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="restockStatusPanel" class="restock-status hidden">
            <h3 style="margin-top:0; display:flex; align-items:center; gap:10px;">
                <i class="fas fa-clipboard-list"></i> Request Status
            </h3>
            <?php if (empty($restockRequests)): ?>
                <p class="muted" style="margin: 12px 0 0;"><i class="fas fa-inbox"></i> No restock requests yet.</p>
            <?php else: ?>
                <?php $hasPendingRequests = !empty($pendingRestockRequests); ?>
                <div class="status-history-header">
                    <button type="button" class="tab-btn <?php echo $hasPendingRequests ? 'active' : ''; ?>" id="openRequestsButton" data-target="openRequestsPanel">
                        <i class="fas fa-hourglass-half"></i> Open Requests
                    </button>
                    <button type="button" class="tab-btn <?php echo $hasPendingRequests ? '' : 'active'; ?>" id="statusHistoryTab" data-target="processedRequests">
                        <i class="fas fa-clipboard-check"></i> Status History
                    </button>
                </div>

                <div id="openRequestsPanel" class="tab-panel open-requests <?php echo $hasPendingRequests ? 'active' : ''; ?>">
                    <?php if (empty($pendingRestockRequests)): ?>
                        <p class="muted" style="margin: 12px 0 20px;">
                            <i class="fas fa-check-circle"></i> No open restock requests at the moment.
                        </p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="requests-table">
                                <thead>
                                    <tr>
                                        <th>Requested At</th>
                                        <th>Product</th>
                                        <th>Quantity</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Requested By</th>
                                        <th>Last Update</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingRestockRequests as $request): ?>
                                        <?php
                                            $status = strtolower($request['status'] ?? 'pending');
                                            $priority = strtolower($request['priority_level'] ?? '');
                                            $updatedAt = $request['updated_at'] ?? null;
                                            $lastTimestamp = $updatedAt ?: ($request['created_at'] ?? null);
                                        ?>
                                        <tr>
                                            <td>
                                                <?php echo !empty($request['created_at']) ? date('M d, Y H:i', strtotime($request['created_at'])) : '—'; ?>
                                            </td>
                                            <td>
                                                <div class="product-cell">
                                                    <span class="product-name"><?php echo htmlspecialchars($request['product_name'] ?? 'Product removed'); ?></span>
                                                    <?php if (!empty($request['variant_label'])): ?>
                                                        <span class="product-variant">Variant: <?php echo htmlspecialchars($request['variant_label']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($request['product_code'])): ?>
                                                        <span class="product-code">Code: <?php echo htmlspecialchars($request['product_code']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo (int) ($request['quantity_requested'] ?? 0); ?></td>
                                            <td>
                                                <?php if ($priority !== ''): ?>
                                                    <span class="priority-badge <?php echo getPriorityClass($priority); ?>"><?php echo ucfirst($priority); ?></span>
                                                <?php else: ?>
                                                    <span class="muted">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo getStatusClass($status); ?>"><?php echo ucfirst($status); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($request['requester_name'] ?? 'Unknown'); ?></td>
                                            <td>
                                                <?php echo $lastTimestamp ? date('M d, Y H:i', strtotime($lastTimestamp)) : '—'; ?>
                                            </td>
                                            <td><?php echo !empty($request['notes']) ? nl2br(htmlspecialchars($request['notes'])) : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="processedRequests" class="tab-panel <?php echo $hasPendingRequests ? '' : 'active'; ?>">
                    <?php if (empty($restockHistory)): ?>
                        <p class="muted" style="margin: 12px 0 0;"><i class="fas fa-inbox"></i> No request history logged yet.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="requests-table">
                                <thead>
                                    <tr>
                                        <th>Logged At</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Brand</th>
                                        <th>Supplier</th>
                                        <th>Quantity</th>
                                        <th>Priority</th>
                                        <th>Requested By</th>
                                        <th>Status</th>
                                        <th>Logged By</th>
                                        <th>Approved / Declined By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($restockHistory as $entry): ?>
                                        <?php $status = strtolower($entry['status'] ?? 'pending'); ?>
                                        <tr>
                                            <td><?php echo date('M d, Y H:i', strtotime($entry['created_at'])); ?></td>
                                            <td>
                                                <div class="product-cell">
                                                    <span class="product-name"><?php echo htmlspecialchars($entry['product_name'] ?? 'Product removed'); ?></span>
                                                    <?php if (!empty($entry['variant_label'])): ?>
                                                        <span class="product-variant">Variant: <?php echo htmlspecialchars($entry['variant_label']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($entry['product_code'])): ?>
                                                        <span class="product-code">Code: <?php echo htmlspecialchars($entry['product_code']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($entry['request_category'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($entry['request_brand'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($entry['request_supplier'] ?? ''); ?></td>
                                            <td><?php echo (int) $entry['request_quantity']; ?></td>
                                            <td>
                                                <?php $priority = strtolower($entry['request_priority'] ?? ''); ?>
                                                <span class="priority-badge <?php echo getPriorityClass($priority); ?>"><?php echo ucfirst($priority); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($entry['requester_name'] ?? 'Unknown'); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo getStatusClass($status); ?>"><?php echo ucfirst($status); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($entry['status_user_name'] ?? 'System'); ?></td>
                                            <td><?php echo ($status === 'approved' || $status === 'declined') ? htmlspecialchars($entry['reviewer_name'] ?? 'Unknown') : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>




        <!-- Main Inventory Table -->
        <div class="inventory-page-actions">
            <?php if ($role === 'admin' || $role === 'staff'): ?>
            <a href="stockEntry.php" class="btn-action add-stock-btn">Add Stock</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-action export-btn">Export CSV</a>
        </div>

        <div id="inventoryTable" class="table-container">
            <?php if ($manualAdjustFeedback): ?>
            <div class="inventory-alert inventory-alert--<?= htmlspecialchars($manualAdjustFeedback['type'] ?? 'info') ?>" role="status" data-inventory-flash>
                <?= htmlspecialchars($manualAdjustFeedback['text'] ?? '') ?>
            </div>
            <?php endif; ?>

            <form method="get" class="inventory-filter-form" id="inventoryFilterForm">
                <input type="hidden" name="page" value="1">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($currentSort) ?>">
                <input type="hidden" name="direction" value="<?= htmlspecialchars($currentDirection ?: 'asc') ?>">
                <div class="filter-row">
                    <div class="filter-search-group">
                        <input type="text" name="search" aria-label="Search inventory" placeholder="Search product by name or code..." value="<?= htmlspecialchars($search) ?>" class="filter-search-input">
                        <button type="button" class="filter-clear" aria-label="Clear search" data-filter-clear>&times;</button>
                    </div>
                </div>
                <div class="filter-row filter-row--selects">
                    <select name="brand" aria-label="Filter by brand" class="filter-select">
                        <option value="">All Brands</option>
                        <?php foreach ($brandOptions as $brandOption): ?>
                        <option value="<?= htmlspecialchars($brandOption) ?>" <?= ($brandFilter === $brandOption) ? 'selected' : '' ?>><?= htmlspecialchars($brandOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="category" aria-label="Filter by category" class="filter-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categoryOptions as $categoryOption): ?>
                        <option value="<?= htmlspecialchars($categoryOption) ?>" <?= ($categoryFilter === $categoryOption) ? 'selected' : '' ?>><?= htmlspecialchars($categoryOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="filter-submit" data-filter-submit>Filter</button>
                </div>
            </form>

            <div class="table-wrapper">
                <table class="inventory-table manual-inventory-table">
                    <thead>
                        <tr>
                            <th scope="col">Code</th>
                            <th scope="col">
                                <a href="<?= htmlspecialchars($nameSortUrl) ?>" class="sort-link">
                                    Name
                                    <span class="sort-indicator"><?= htmlspecialchars($nameSortIndicator) ?></span>
                                </a>
                            </th>
                            <th scope="col">
                                <a href="<?= htmlspecialchars($quantitySortUrl) ?>" class="sort-link">
                                    Qty
                                    <span class="sort-indicator"><?= htmlspecialchars($quantitySortIndicator) ?></span>
                                </a>
                            </th>
                            <th scope="col">Price</th>
                            <?php if ($canManualAdjust): ?>
                            <th scope="col">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventoryProducts)): ?>
                        <tr>
                            <td colspan="<?= $canManualAdjust ? 5 : 4 ?>" class="empty-state">
                                <i class="fas fa-inbox"></i>
                                No inventory items found matching the criteria
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($inventoryProducts as $p):
                            $quantityValue = isset($p['quantity']) ? (int) $p['quantity'] : 0;
                            $quantityValue = max(0, $quantityValue);
                            $thresholdValue = isset($p['low_stock_threshold']) ? (int) $p['low_stock_threshold'] : 0;
                            $thresholdValue = max(0, $thresholdValue);
                            $low = $quantityValue <= $thresholdValue;
                            $productPrice = isset($p['price']) ? (float) $p['price'] : 0;
                            $brandLabel = trim((string) ($p['brand'] ?? ''));
                            $categoryLabel = trim((string) ($p['category'] ?? ''));
                            // New: flag the row that just received a manual adjustment so we can highlight it
                            $isFlashProduct = $manualAdjustFeedback && isset($manualAdjustFeedback['product_id']) && intval($manualAdjustFeedback['product_id']) === intval($p['id']);
                            $variantsForProduct = $productVariantsMap[(int) $p['id']] ?? [];
                            $hasVariants = !empty($variantsForProduct);
                            $lastAdjustedVariantId = 0;
                            if ($isFlashProduct && isset($manualAdjustFeedback['variant_id'])) {
                                $lastAdjustedVariantId = (int) ($manualAdjustFeedback['variant_id'] ?? 0);
                            }
                            $displayQuantityValue = $quantityValue;
                            $quantityContextText = 'All variants total';
                            $quantityContextState = 'product';
                            if ($hasVariants && $lastAdjustedVariantId > 0) {
                                foreach ($variantsForProduct as $variantRow) {
                                    $variantRowId = isset($variantRow['id']) ? (int) $variantRow['id'] : 0;
                                    if ($variantRowId !== $lastAdjustedVariantId) {
                                        continue;
                                    }
                                    $displayQuantityValue = isset($variantRow['quantity']) ? max(0, (int) $variantRow['quantity']) : 0;
                                    $variantLabelText = trim((string) ($variantRow['label'] ?? ''));
                                    $variantSkuText = trim((string) ($variantRow['sku'] ?? ''));
                                    if ($variantLabelText === '' && $variantSkuText !== '') {
                                        $variantLabelText = $variantSkuText;
                                    }
                                    if ($variantLabelText === '') {
                                        $variantLabelText = 'Variant #' . $variantRowId;
                                    }
                                    $quantityContextText = $variantLabelText !== '' ? 'Variant qty — ' . $variantLabelText : 'Variant qty';
                                    $quantityContextState = 'variant';
                                    break;
                                }
                            }
                            $rowClasses = [];
                            if ($low) {
                                $rowClasses[] = 'low-stock';
                            }
                            if ($isFlashProduct) {
                                $rowClasses[] = 'manual-adjust-highlight';
                            }
                            $rowClassAttribute = empty($rowClasses) ? '' : ' class="' . implode(' ', $rowClasses) . '"';
                        ?>
                        <tr<?= $rowClassAttribute ?> id="product-<?= (int) $p['id'] ?>" data-product-id="<?= (int) $p['id'] ?>" data-flash-product="<?= $isFlashProduct ? 'true' : 'false' ?>" data-last-variant-id="<?= $lastAdjustedVariantId ?>">
                            <td><?= htmlspecialchars($p['code']) ?></td>
                            <td>
                                <div class="product-info">
                                    <span class="product-name"><?= htmlspecialchars($p['name']) ?></span>
                                    <?php if ($brandLabel !== '' || $categoryLabel !== '' || !$canManualAdjust): ?>
                                    <div class="product-meta">
                                        <?php if ($brandLabel !== ''): ?>
                                        <span class="product-meta__badge"><?= htmlspecialchars($brandLabel) ?></span>
                                        <?php endif; ?>
                                        <?php if ($categoryLabel !== ''): ?>
                                        <span class="product-meta__badge product-meta__badge--muted"><?= htmlspecialchars($categoryLabel) ?></span>
                                        <?php endif; ?>
                                        <?php if (!$canManualAdjust): ?>
                                        <span class="product-meta__note">Low stock threshold: <span class="product-meta__value"><?= $thresholdValue ?></span></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="inventory-quantity" data-product-quantity>
                                    <span class="inventory-quantity__value" data-quantity-display data-default-quantity="<?= $quantityValue ?>"><?= $displayQuantityValue ?></span>
                                    <?php if ($hasVariants): ?>
                                    <span class="inventory-quantity__context" data-quantity-context data-context-state="<?= htmlspecialchars($quantityContextState) ?>" title="<?= htmlspecialchars($quantityContextText) ?>"><?= htmlspecialchars($quantityContextText) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>₱<?= number_format($productPrice, 2) ?></td>
                            <?php if ($canManualAdjust): ?>
                            <td>
                                <div class="manual-adjust">
                                    <form method="post" class="manual-adjust-form">
                                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                        <input type="hidden" name="return_view" value="<?= htmlspecialchars($manualAdjustReturnView) ?>">
                                        <?php if ($hasVariants): ?>
                                        <div class="manual-adjust-field manual-adjust-field--variant">
                                            <label class="manual-adjust-field__hint" for="manualAdjustVariant<?= (int) $p['id'] ?>">Variant</label>
                                            <select
                                                id="manualAdjustVariant<?= (int) $p['id'] ?>"
                                                name="variant_id"
                                                class="manual-adjust-select"
                                            >
                                                <option value=""<?= $lastAdjustedVariantId === 0 ? ' selected' : '' ?>>Select variant</option>
                                                <?php foreach ($variantsForProduct as $variant):
                                                    $variantOptionId = (int) ($variant['id'] ?? 0);
                                                    if ($variantOptionId <= 0) {
                                                        continue;
                                                    }
                                                    $variantLabelText = trim((string) ($variant['label'] ?? ''));
                                                    $variantSkuText = trim((string) ($variant['sku'] ?? ''));
                                                    $variantQuantityValue = isset($variant['quantity']) ? (int) $variant['quantity'] : 0;
                                                    if ($variantLabelText === '' && $variantSkuText !== '') {
                                                        $variantLabelText = $variantSkuText;
                                                    }
                                                    if ($variantLabelText === '') {
                                                        $variantLabelText = 'Variant #' . $variantOptionId;
                                                    }
                                                    $skuNote = '';
                                                    if ($variantSkuText !== '' && strcasecmp($variantSkuText, $variantLabelText) !== 0) {
                                                        $skuNote = ' — SKU: ' . $variantSkuText;
                                                    }
                                                    $defaultNote = !empty($variant['is_default']) ? ' (Default)' : '';
                                                    $optionLabel = $variantLabelText . $skuNote . $defaultNote;
                                                    $isSelected = $lastAdjustedVariantId === $variantOptionId;
                                                ?>
                                                <option value="<?= $variantOptionId ?>" data-variant-quantity="<?= $variantQuantityValue ?>"<?= $isSelected ? ' selected' : '' ?>><?= htmlspecialchars($optionLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php else: ?>
                                        <input type="hidden" name="variant_id" value="0">
                                        <div class="manual-adjust-field manual-adjust-field--variant-note">
                                            <span class="manual-adjust-variant-note">Single SKU</span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="manual-adjust-field manual-adjust-field--quantity">
                                            <label class="manual-adjust-field__hint" for="manualAdjust<?= (int) $p['id'] ?>">Quantity</label>
                                            <input
                                                type="number"
                                                id="manualAdjust<?= (int) $p['id'] ?>"
                                                name="change"
                                                value="0"
                                                step="1"
                                                class="manual-adjust-input"
                                            >
                                        </div>
                                        <button type="submit" name="update_stock" class="manual-adjust-button">Manual Adjust</button>
                                    </form>
                                    <div class="manual-adjust-meta">
                                        Low stock threshold: <span class="manual-adjust-meta__value"><?= $thresholdValue ?></span>
                                    </div>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalInventoryProducts > 0): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    Showing <?= $startRecord ?> to <?= $endRecord ?> of <?= $totalInventoryProducts ?> entries
                </div>
                <div class="pagination">
                    <?php
                    $currentParams = $_GET;
                    unset($currentParams['page']);
                    $queryPrefix = http_build_query($currentParams);
                    $queryPrefix = $queryPrefix ? $queryPrefix . '&' : '';
                    ?>

                    <?php if ($page > 1): ?>
                    <a href="?<?= $queryPrefix ?>page=<?= ($page - 1) ?>" class="prev">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                    <?php else: ?>
                    <span class="prev disabled">
                        <i class="fas fa-chevron-left"></i> Prev
                    </span>
                    <?php endif; ?>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($totalPages, $page + 2);

                    if ($start_page > 1): ?>
                    <a href="?<?= $queryPrefix ?>page=1">1</a>
                    <?php if ($start_page > 2): ?>
                    <span>...</span>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <?php if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                    <?php else: ?>
                    <a href="?<?= $queryPrefix ?>page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end_page < $totalPages): ?>
                    <?php if ($end_page < $totalPages - 1): ?>
                    <span>...</span>
                    <?php endif; ?>
                    <a href="?<?= $queryPrefix ?>page=<?= $totalPages ?>"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="?<?= $queryPrefix ?>page=<?= ($page + 1) ?>" class="next">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php else: ?>
                    <span class="next disabled">
                        Next <i class="fas fa-chevron-right"></i>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <div class="modal-overlay" id="profileModal" aria-hidden="true">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
            <button type="button" class="modal-close" id="profileModalClose" aria-label="Close profile information">
                <i class="fas fa-times"></i>
            </button>
            <h3 id="profileModalTitle">Profile information</h3>
            <div class="profile-info">
                <div class="profile-row">
                    <span class="profile-label">Name</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_name) ?></span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">Role</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_role) ?></span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">Date created</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_created) ?></span>
                </div>
            </div>
        </div>
    </div>
    <!-- user menu -->
     <script src="../dgz_motorshop_system/assets/js/dashboard/userMenu.js"></script>
     <script type="application/json" id="inventoryVariants">
        <?php echo json_encode($productVariantsBootstrap, JSON_PRETTY_PRINT); ?>
     </script>
     <!-- Inventory JS -->
      <script src="../dgz_motorshop_system/assets/js/inventory/inventoryMain.js"></script>
    <script src="../dgz_motorshop_system/assets/js/notifications.js"></script>
</body>
</html>
