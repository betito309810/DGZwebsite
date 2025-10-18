<?php
require __DIR__. '/../config/config.php';
require_once __DIR__ . '/../dgz_motorshop_system/includes/product_variants.php'; // Added: helper that manages product variant lookups and payloads.
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
$role = $_SESSION['role'] ?? '';
$isStaff = ($role === 'staff');
enforceStaffAccess();

$productFormError = null;
if (isset($_SESSION['products_error'])) {
    $productFormError = (string) $_SESSION['products_error'];
    unset($_SESSION['products_error']);
}
// Added: helper utilities that manage product image uploads in a single place.
if (!function_exists('ensureProductImageDirectory')) {
    /**
     * Ensure the upload directory for a product exists and return its absolute path.
     */
    function ensureProductImageDirectory(int $productId): string
    {
        $uploadsRoot = __DIR__ . '/../dgz_motorshop_system/uploads';
        if (!is_dir($uploadsRoot)) {
            mkdir($uploadsRoot, 0775, true);
        }

        $productsRoot = $uploadsRoot . '/products';
        if (!is_dir($productsRoot)) {
            mkdir($productsRoot, 0775, true);
        }

        $productDir = $productsRoot . '/' . $productId;
        if (!is_dir($productDir)) {
            mkdir($productDir, 0775, true);
        }

        return $productDir;
    }
}

if (!function_exists('moveUploadedProductImage')) {
    /**
     * Move the uploaded main product image into place and return the stored relative path.
     */
    function moveUploadedProductImage(?array $file, int $productId): ?string
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Image upload failed with error code ' . ($file['error'] ?? 'unknown'));
        }

        $imageMeta = @getimagesize($file['tmp_name'] ?? '');
        if ($imageMeta === false) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $extension = image_type_to_extension($imageMeta[2] ?? IMAGETYPE_JPEG, false);
        if (!$extension) {
            $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        }

        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $extension));
        if ($extension === '' || !preg_match('/^(jpe?g|png|gif|webp)$/', $extension)) {
            $extension = 'jpg';
        }

        $targetDir = ensureProductImageDirectory($productId);
        $targetPath = $targetDir . '/main.' . $extension;
        $previousMainFiles = glob($targetDir . '/main.*');

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to move uploaded image to product directory.');
        }

        @chmod($targetPath, 0644);

        foreach ($previousMainFiles as $previous) {
            if ($previous !== $targetPath && is_file($previous)) {
                @unlink($previous);
            }
        }

        // Store a project-relative path that matches the actual upload location.
        // Existing rows may still contain 'uploads/products/...'; we handle both when rendering.
        return 'dgz_motorshop_system/uploads/products/' . $productId . '/main.' . $extension;
    }
}

if (!function_exists('normaliseUploadedFilesArray')) {
    /**
     * Normalise the $_FILES multi-upload structure into a flat array for iteration.
     */
    function normaliseUploadedFilesArray(?array $files): array
    {
        if (!$files || !isset($files['name'])) {
            return [];
        }

        if (!is_array($files['name'])) {
            return [$files];
        }

        $normalised = [];
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $normalised[] = [
                'name' => $files['name'][$i] ?? null,
                'type' => $files['type'][$i] ?? null,
                'tmp_name' => $files['tmp_name'][$i] ?? null,
                'error' => $files['error'][$i] ?? null,
                'size' => $files['size'][$i] ?? null,
            ];
        }

        return $normalised;
    }
}

if (!function_exists('persistGalleryUploads')) {
    /**
     * Save any additional gallery images and persist references to the database.
     */
    function persistGalleryUploads(PDO $pdo, ?array $files, int $productId): void
    {
        $normalised = normaliseUploadedFilesArray($files);
        if (empty($normalised)) {
            return;
        }

        $storedPaths = [];
        $uploadDir = ensureProductImageDirectory($productId);

        foreach ($normalised as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $imageMeta = @getimagesize($file['tmp_name'] ?? '');
            if ($imageMeta === false) {
                continue;
            }

            $extension = image_type_to_extension($imageMeta[2] ?? IMAGETYPE_JPEG, false);
            if (!$extension) {
                $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            }

            $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $extension));
            if ($extension === '' || !preg_match('/^(jpe?g|png|gif|webp)$/', $extension)) {
                $extension = 'jpg';
            }

            try {
                $randomSuffix = random_int(1000, 9999);
            } catch (Exception $e) {
                $randomSuffix = mt_rand(1000, 9999);
            }

            $fileName = sprintf('gallery-%s-%04d.%s', date('YmdHis'), $randomSuffix, $extension);
            $targetPath = $uploadDir . '/' . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                continue;
            }

            @chmod($targetPath, 0644);
            // Persist the project-relative path to where files are saved on disk.
            $storedPaths[] = 'dgz_motorshop_system/uploads/products/' . $productId . '/' . $fileName;
        }

        if (empty($storedPaths)) {
            return;
        }

        $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM product_images WHERE product_id = ?');
        $sortStmt->execute([$productId]);
        $sortOrder = (int) $sortStmt->fetchColumn();

        $insertStmt = $pdo->prepare('INSERT INTO product_images (product_id, file_path, sort_order) VALUES (?, ?, ?)');
        foreach ($storedPaths as $path) {
            $sortOrder++;
            $insertStmt->execute([$productId, $path, $sortOrder]);
        }
    }
}

if (!function_exists('releaseProductHistoryReferences')) {
    /**
     * Detach product history rows so a product can be deleted even if the
     * history table keeps a foreign key back to products.
     */
    function releaseProductHistoryReferences(PDO $pdo, int $productId): void
    {
        try {
            $pdo->prepare('UPDATE product_add_history SET product_id = NULL WHERE product_id = ?')->execute([$productId]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            // Legacy schemas may keep product_id NOT NULL; fall back to removing
            // the rows entirely so the delete can proceed.
            $pdo->prepare('DELETE FROM product_add_history WHERE product_id = ?')->execute([$productId]);
        }
    }
}

if (!function_exists('productImageAbsolutePath')) {
    /**
     * Translate a stored relative upload path into an absolute filesystem path.
     */
    function productImageAbsolutePath(string $storedPath): ?string
    {
        $normalised = str_replace('\\', '/', trim($storedPath));
        if ($normalised === '') {
            return null;
        }

        $normalised = ltrim($normalised, '/');
        if (strpos($normalised, '..') !== false) {
            return null;
        }

        // Accept both legacy 'uploads/products/…' and current 'dgz_motorshop_system/uploads/products/…'
        if (
            strpos($normalised, 'dgz_motorshop_system/uploads/products/') !== 0 &&
            strpos($normalised, 'uploads/products/') !== 0
        ) {
            return null;
        }

        static $basePath = null;
        if ($basePath === null) {
            $resolved = realpath(__DIR__ . '/..');
            $basePath = $resolved !== false ? $resolved : __DIR__ . '/..';
        }

        return $basePath . '/' . $normalised;
    }
}

if (!function_exists('deleteMainProductImage')) {
    /**
     * Remove the stored main image for a product from the filesystem.
     */
    function deleteMainProductImage(int $productId, ?string $storedPath = null): void
    {
        $productDir = __DIR__ . '/../dgz_motorshop_system/uploads/products/' . $productId;
        if (is_dir($productDir)) {
            foreach (glob($productDir . '/main.*') as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        if ($storedPath) {
            $absolute = productImageAbsolutePath($storedPath);
            if ($absolute && is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }
}

if (!function_exists('reorderGallerySortOrder')) {
    /**
     * Re-pack gallery sort orders so they remain sequential after deletions.
     */
    function reorderGallerySortOrder(PDO $pdo, int $productId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$productId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($ids)) {
            return;
        }

        $update = $pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ?');
        $order = 0;
        foreach ($ids as $id) {
            $order++;
            $update->execute([$order, (int) $id]);
        }
    }
}

if (!function_exists('removeGalleryImages')) {
    /**
     * Delete gallery images by id and return the removed rows for history logging.
     */
    function removeGalleryImages(PDO $pdo, int $productId, array $imageIds): array
    {
        $normalisedIds = [];
        foreach ($imageIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalisedIds[$id] = $id;
            }
        }

        if (empty($normalisedIds)) {
            return [];
        }

        $normalisedIds = array_values($normalisedIds);
        $placeholders = implode(',', array_fill(0, count($normalisedIds), '?'));
        $params = array_merge([$productId], $normalisedIds);

        $select = $pdo->prepare(
            "SELECT id, file_path FROM product_images WHERE product_id = ? AND id IN ($placeholders)"
        );
        $select->execute($params);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($rows)) {
            return [];
        }

        $idsToDelete = [];
        foreach ($rows as $row) {
            $idsToDelete[] = (int) ($row['id'] ?? 0);
            $storedPath = $row['file_path'] ?? '';
            $absolute = $storedPath ? productImageAbsolutePath($storedPath) : null;
            if ($absolute && is_file($absolute)) {
                @unlink($absolute);
            }
        }

        if (!empty($idsToDelete)) {
            $deletePlaceholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $deleteParams = array_merge([$productId], $idsToDelete);
            $delete = $pdo->prepare(
                "DELETE FROM product_images WHERE product_id = ? AND id IN ($deletePlaceholders)"
            );
            $delete->execute($deleteParams);
        }

        reorderGallerySortOrder($pdo, $productId);

        return $rows;
    }
}

if (!function_exists('fetchGalleryImagesForProducts')) {
    /**
     * Bulk fetch gallery images for the supplied product ids.
     */
    function fetchGalleryImagesForProducts(PDO $pdo, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, product_id, file_path, sort_order FROM product_images WHERE product_id IN ($placeholders) ORDER BY product_id ASC, sort_order ASC, id ASC"
        );
        $stmt->execute($productIds);

        $gallery = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $rawPath = $row['file_path'] ?? '';
            $normalisedPath = ltrim(str_replace('\\', '/', trim((string) $rawPath)), '/');
            if ($normalisedPath !== '' && strpos($normalisedPath, 'dgz_motorshop_system/') !== 0) {
                // Back-compat for rows saved with 'uploads/products/...'
                $normalisedPath = 'dgz_motorshop_system/' . $normalisedPath;
            }
            $url = $normalisedPath !== '' ? '../' . $normalisedPath : '';

            $gallery[$productId][] = [
                'id' => (int) ($row['id'] ?? 0),
                'file_path' => $rawPath,
                'url' => $url,
            ];
        }

        return $gallery;
    }
}

if (!function_exists('syncProductVariants')) {
    /**
     * Added: persist variant rows (insert/update/delete) and capture a diff summary for history logging.
     */
    function syncProductVariants(PDO $pdo, int $productId, array $variants): array
    {
        $existing = fetchProductVariants($pdo, $productId);
        $existingById = [];
        foreach ($existing as $variant) {
            $existingById[$variant['id']] = $variant;
        }

        $processedIds = [];
        $added = [];
        $updated = [];

        $insertStmt = $pdo->prepare('INSERT INTO product_variants (product_id, label, sku, price, quantity, is_default, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $updateStmt = $pdo->prepare('UPDATE product_variants SET label = ?, sku = ?, price = ?, quantity = ?, is_default = ?, sort_order = ? WHERE id = ? AND product_id = ?');

        foreach ($variants as $variant) {
            $variantId = isset($variant['id']) && $variant['id'] ? (int) $variant['id'] : null;
            $label = $variant['label'];
            $sku = $variant['sku'] ?? null;
            $price = (float) $variant['price'];
            $quantity = (int) $variant['quantity'];
            $isDefault = !empty($variant['is_default']) ? 1 : 0;
            $sortOrder = (int) $variant['sort_order'];

            if ($variantId !== null && isset($existingById[$variantId])) {
                $original = $existingById[$variantId];
                $needsUpdate = (
                    $label !== $original['label'] ||
                    $sku !== ($original['sku'] ?? null) ||
                    $price !== (float) $original['price'] ||
                    $quantity !== (int) $original['quantity'] ||
                    $isDefault !== (int) $original['is_default'] ||
                    $sortOrder !== (int) $original['sort_order']
                );

                if ($needsUpdate) {
                    $updateStmt->execute([$label, $sku, $price, $quantity, $isDefault, $sortOrder, $variantId, $productId]);
                    $updated[] = [
                        'id' => $variantId,
                        'label' => $label,
                        'before' => $original,
                        'after' => array_merge($original, [
                            'label' => $label,
                            'sku' => $sku,
                            'price' => $price,
                            'quantity' => $quantity,
                            'is_default' => $isDefault,
                            'sort_order' => $sortOrder,
                        ]),
                    ];
                }

                $processedIds[] = $variantId;
            } else {
                $insertStmt->execute([$productId, $label, $sku, $price, $quantity, $isDefault, $sortOrder]);
                $newId = (int) $pdo->lastInsertId();
                $processedIds[] = $newId;
                $added[] = [
                    'id' => $newId,
                    'label' => $label,
                    'price' => $price,
                    'quantity' => $quantity,
                    'is_default' => $isDefault,
                ];
            }
        }

        $deleted = [];
        foreach ($existing as $variant) {
            if (!in_array($variant['id'], $processedIds, true)) {
                $deleted[] = $variant;
            }
        }

        if (!empty($deleted)) {
            $deleteIds = array_column($deleted, 'id');
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $deleteStmt = $pdo->prepare("DELETE FROM product_variants WHERE product_id = ? AND id IN ($placeholders)");
            $deleteStmt->execute(array_merge([$productId], $deleteIds));
        }

        return [
            'added' => $added,
            'updated' => $updated,
            'deleted' => $deleted,
        ];
    }
}

if (!function_exists('productCodeExists')) {
    /**
     * Determine whether a product code is already assigned to another product record.
     */
    function productCodeExists(PDO $pdo, string $code, ?int $excludeProductId = null): bool
    {
        $normalised = trim($code);
        if ($normalised === '') {
            return false;
        }

        $sql = 'SELECT 1 FROM products WHERE code = ?';
        $params = [$normalised];
        if ($excludeProductId !== null && $excludeProductId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeProductId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }
}

// Product Add History for modal (HTML table, not JSON)
if (isset($_GET['history']) && $_GET['history'] == '1') {
    $sql = "
        SELECT h.created_at, h.action, h.details,
               p.code AS product_code, p.name AS product_name,
               p.price, p.quantity, p.brand, p.category,
               COALESCE(u.name, CONCAT('User #', h.user_id)) AS added_by
        FROM product_add_history h
        LEFT JOIN products p ON p.id = h.product_id
        LEFT JOIN users u ON u.id = h.user_id
        ORDER BY h.created_at DESC
        LIMIT 10
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    ?>
<table class="entries-table">
    <thead>
        <tr>
            <th>Date</th>
            <th>Product Code</th>
            <th>Product Name</th>
            <th>Brand</th>
            <th>Category</th>
            <th>Price</th>
            <th>Quantity</th>
            <th>Added by</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $entry): ?>
        <?php
            // Added: parse structured history payloads so the table keeps values even after deletion edits.
            $rawDetails = $entry['details'] ?? '';
            $decodedDetails = json_decode($rawDetails, true);
            $detailsIsStructured = json_last_error() === JSON_ERROR_NONE && is_array($decodedDetails);
            $snapshot = $detailsIsStructured && isset($decodedDetails['snapshot']) && is_array($decodedDetails['snapshot']) ? $decodedDetails['snapshot'] : [];
            $changes = $detailsIsStructured && isset($decodedDetails['changes']) && is_array($decodedDetails['changes']) ? $decodedDetails['changes'] : [];
            $summaryText = $detailsIsStructured && !empty($decodedDetails['summary']) ? $decodedDetails['summary'] : '';

            $displayCode = $entry['product_code'] ?? '';
            if ($displayCode === '' && isset($snapshot['code'])) {
                $displayCode = $snapshot['code'];
            }

            $displayName = $entry['product_name'] ?? '';
            if ($displayName === '' && isset($snapshot['name'])) {
                $displayName = $snapshot['name'];
            }

            $displayBrand = $entry['brand'] ?? '';
            if ($displayBrand === '' && isset($snapshot['brand'])) {
                $displayBrand = $snapshot['brand'];
            }

            $displayCategory = $entry['category'] ?? '';
            if ($displayCategory === '' && isset($snapshot['category'])) {
                $displayCategory = $snapshot['category'];
            }

            $displayPrice = $entry['price'];
            if ($displayPrice === null && isset($snapshot['price'])) {
                $displayPrice = $snapshot['price'];
            }
            $displayPrice = $displayPrice === null ? 0 : (float) $displayPrice;

            $displayQuantity = $entry['quantity'];
            if ($displayQuantity === null && isset($snapshot['quantity'])) {
                $displayQuantity = $snapshot['quantity'];
            }
            $displayQuantity = $displayQuantity === null ? 0 : (float) $displayQuantity;

            $detailsHtml = '';
            if ($detailsIsStructured) {
                if ($summaryText !== '') {
                    $detailsHtml .= '<div>' . htmlspecialchars($summaryText) . '</div>';
                }
                if (!empty($changes)) {
                    $detailsHtml .= '<ul class="history-change-list">';
                    foreach ($changes as $change) {
                        $fromValue = $change['from'];
                        $toValue = $change['to'];
                        if (($change['type'] ?? '') === 'currency') {
                            $fromValue = $fromValue === null ? '-' : '₱' . number_format((float) $fromValue, 2);
                            $toValue = $toValue === null ? '-' : '₱' . number_format((float) $toValue, 2);
                        } elseif (($change['type'] ?? '') === 'number') {
                            $fromValue = $fromValue === null ? '-' : number_format((float) $fromValue);
                            $toValue = $toValue === null ? '-' : number_format((float) $toValue);
                        } else {
                            $fromValue = ($fromValue === null || $fromValue === '') ? '-' : $fromValue;
                            $toValue = ($toValue === null || $toValue === '') ? '-' : $toValue;
                        }
                        $detailsHtml .= '<li>' . htmlspecialchars(($change['label'] ?? 'Field') . ': ' . $fromValue . ' → ' . $toValue) . '</li>';
                    }
                    $detailsHtml .= '</ul>';
                }
            } elseif ($rawDetails !== '') {
                $detailsHtml = '<div>' . htmlspecialchars($rawDetails) . '</div>';
            }
        ?>
        <tr>
            <td><?= date('M d, Y H:i', strtotime($entry['created_at'])) ?></td>
            <td><span class="product-code"><?= htmlspecialchars($displayCode !== '' ? $displayCode : '-') ?></span></td>
            <td><span class="product-name"><?= htmlspecialchars($displayName !== '' ? $displayName : '-') ?></span></td>
            <td><span class="brand-badge"><?= htmlspecialchars($displayBrand !== '' ? $displayBrand : '-') ?></span></td>
            <td><span class="category-badge"><?= htmlspecialchars($displayCategory !== '' ? $displayCategory : '-') ?></span></td>
            <td><span class="price">₱<?= number_format($displayPrice, 2) ?></span></td>
            <td><span class="quantity"><?= number_format($displayQuantity) ?></span></td>
            <td><span class="user-name"><?= htmlspecialchars($entry['added_by'] ?? '-') ?></span></td>
            <td>
                <?php 
                            $action = $entry['action'] ?? 'add';
                            $actionClass = 'action-badge';
                            $actionText = '';
                            
                            switch($action) {
                                case 'edit':
                                    $actionClass .= ' action-edit';
                                    $actionText = 'Edited';
                                    break;
                                case 'delete':
                                    $actionClass .= ' action-delete';
                                    $actionText = 'Deleted';
                                    break;
                                default:
                                    $actionClass .= ' action-add';
                                    $actionText = 'Added';
                            }
                            
                ?>
                <span class="<?= $actionClass ?>"><?= $actionText ?></span>
                <?php if ($detailsHtml !== ''): ?>
                <div class="action-details"><?= $detailsHtml ?></div>
                <?php endif; ?>
            </td>

        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr>
            <td colspan="9" style="text-align:center;">No product history</td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>
<link rel="stylesheet" href="../dgz_motorshop_system/assets/css/products/products_history.css">
<?php
    exit;
}

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

if(isset($_GET['delete'])) {
    $product_id = intval($_GET['delete']);

    try {
        // Added: wrap the entire deletion in a transaction so history and clean-up are atomic.
        $pdo->beginTransaction();

        // Lock the product row while we gather the snapshot.
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if ($product) {
            // Try to record deletion in history (optional)
            try {
                // Added: persist a snapshot of the product before it disappears so history remains readable.
                $historyPayload = [
                    'summary' => sprintf(
                        'Deleted %s (%s).',
                        $product['name'] ?? 'product',
                        $product['code'] ?? 'no code'
                    ),
                    'snapshot' => [
                        'code' => $product['code'] ?? null,
                        'name' => $product['name'] ?? null,
                        'description' => $product['description'] ?? null,
                        'price' => $product['price'] ?? null,
                        'quantity' => $product['quantity'] ?? null,
                        'low_stock_threshold' => $product['low_stock_threshold'] ?? null,
                        'brand' => $product['brand'] ?? null,
                        'category' => $product['category'] ?? null,
                        'supplier' => $product['supplier'] ?? null,
                        'image' => $product['image'] ?? null,
                    ],
                ];

                $pdo->prepare('INSERT INTO product_add_history (product_id, user_id, action, details) VALUES (?, ?, ?, ?)')
                    ->execute([
                        $product_id,
                        $_SESSION['user_id'],
                        'delete',
                        json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
            } catch (Exception $e) {
                // History failed but continue with deletion
                error_log('Failed to record product deletion history: ' . $e->getMessage());
            }

            // Added: cascade clean-up for dependent tables to satisfy FK constraints.
            try {
                $pdo->prepare('UPDATE inventory_notifications SET product_id = NULL WHERE product_id = ?')->execute([$product_id]);
            } catch (PDOException $inventoryException) {
                if ($inventoryException->getCode() !== '23000') {
                    throw $inventoryException;
                }

                // Older schemas keep product_id NOT NULL; fall back to deleting the rows entirely.
                $pdo->prepare('DELETE FROM inventory_notifications WHERE product_id = ?')->execute([$product_id]);
            }

            $pdo->prepare('DELETE FROM stock_entries WHERE product_id = ?')->execute([$product_id]);

            try {
                $pdo->prepare('UPDATE order_items SET product_id = NULL, variant_id = NULL WHERE product_id = ?')->execute([$product_id]);
            } catch (PDOException $orderItemsException) {
                if ($orderItemsException->getCode() !== '23000') {
                    throw $orderItemsException;
                }

                // Some deployments still mark product_id as NOT NULL and rely on hard deletes.
                $pdo->prepare('DELETE FROM order_items WHERE product_id = ?')->execute([$product_id]);
            }

            $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$product_id]);
            $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$product_id]);
            releaseProductHistoryReferences($pdo, $product_id);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$product_id]);
        } else {
            $_SESSION['products_error'] = 'Product not found or already deleted.';
        }

        $pdo->commit();
    } catch (Exception $e) {
        // Added: rollback on failure to keep DB consistent when any step fails.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Log the error and redirect
        error_log('Product deletion failed: ' . $e->getMessage());
        $_SESSION['products_error'] = 'Unable to delete product. Please try again.';
    }

    header('Location: products.php');
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_product'])){
    // Added: normalise raw form values once so they can be reused across actions.
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $price = isset($_POST['price']) ? (float) $_POST['price'] : 0.0;
    $qty = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 0;
    $low = isset($_POST['low_stock_threshold']) ? (int) $_POST['low_stock_threshold'] : 0;
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    if ($code !== '' && productCodeExists($pdo, $code, $id > 0 ? $id : null)) {
        $_SESSION['products_error'] = sprintf('Product code "%s" is already in use. Please choose a different code.', $code);
        header('Location: products.php');
        exit;
    }

    $mainImageFile = $_FILES['image'] ?? null;
    $galleryImageFiles = $_FILES['gallery_images'] ?? null;
    $removeMainImage = isset($_POST['remove_main_image']) && (string) $_POST['remove_main_image'] === '1';
    $removeGalleryIds = $_POST['remove_gallery_ids'] ?? [];
    if (!is_array($removeGalleryIds)) {
        $removeGalleryIds = [$removeGalleryIds];
    }

    $variantsPayloadJson = $_POST['variants_payload'] ?? '';
    $variantRecords = normaliseVariantPayload($variantsPayloadJson);
    if (!empty($variantRecords)) {
        atLeastOneVariantIsDefault($variantRecords);
        $variantSummary = summariseVariantStock($variantRecords);
        $qty = (int) ($variantSummary['quantity'] ?? $qty);
        $price = (float) ($variantSummary['price'] ?? $price);
    }
    
    // Handle brand and category
    $brand = trim($_POST['brand'] ?? '');
    $brandNew = trim($_POST['brand_new'] ?? '');
    // Added: honour the optional text input when the select is left empty or set to "Add new".
    if ($brand === '__addnew__' || ($brand === '' && $brandNew !== '')) {
        $brand = $brandNew;
    }
    $brand = $brand === '' ? null : $brand;

    $category = trim($_POST['category'] ?? '');
    $categoryNew = trim($_POST['category_new'] ?? '');
    // Added: same fallback behaviour for the category field.
    if ($category === '__addnew__' || ($category === '' && $categoryNew !== '')) {
        $category = $categoryNew;
    }
    $category = $category === '' ? null : $category;

    $supplier = trim($_POST['supplier'] ?? '');
    $supplierNew = trim($_POST['supplier_new'] ?? '');
    // Added: allow suppliers typed in the text box without toggling the select.
    if ($supplier === '__addnew__' || ($supplier === '' && $supplierNew !== '')) {
        $supplier = $supplierNew;
    }
    $supplier = $supplier === '' ? null : $supplier;

    // Added: build a snapshot of the latest field values to reuse in history payloads.
    $currentSnapshot = [
        'code' => $code,
        'name' => $name,
        'description' => $desc,
        'price' => $price,
        'quantity' => $qty,
        'low_stock_threshold' => $low,
        'brand' => $brand,
        'category' => $category,
        'supplier' => $supplier,
        'image' => null,
        'variants' => $variantRecords,
    ];

    $user_id = $_SESSION['user_id'];
    
    if($id > 0) {
        // Get old product data for history
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $old_product = $stmt->fetch();
        $previousVariants = fetchProductVariants($pdo, $id); // Added: capture variant snapshot before modifications.

        $existingImagePath = $old_product['image'] ?? null;
        $newImagePath = null;
        $removedMainImage = false;

        if ($removeMainImage) {
            deleteMainProductImage($id, $existingImagePath);
            $existingImagePath = null;
            $removedMainImage = true;
        }

        try {
            $newImagePath = moveUploadedProductImage($mainImageFile, $id);
        } catch (RuntimeException $e) {
            // Added: keep processing but surface the failure in logs for debugging.
            error_log('Product image upload failed: ' . $e->getMessage());
        }

        $imagePathForUpdate = $newImagePath !== null ? $newImagePath : $existingImagePath;
        $currentSnapshot['image'] = $imagePathForUpdate;

        // Update product (now persisting the image column as well).
        $stmt = $pdo->prepare('UPDATE products SET code=?, name=?, description=?, price=?, quantity=?, low_stock_threshold=?, brand=?, category=?, supplier=?, image=? WHERE id=?');
        $stmt->execute([$code, $name, $desc, $price, $qty, $low, $brand, $category, $supplier, $imagePathForUpdate, $id]);

        $removedGalleryRows = removeGalleryImages($pdo, $id, $removeGalleryIds);
        persistGalleryUploads($pdo, $galleryImageFiles, $id);
        $variantSyncSummary = syncProductVariants($pdo, $id, $variantRecords); // Added: store variant rows alongside the product.
        $currentSnapshot['variants'] = fetchProductVariants($pdo, $id); // Added: refresh snapshot with persisted variant IDs.

        // Record edit in history
        $changes = [];
        $previousSnapshot = [
            'code' => $old_product['code'] ?? null,
            'name' => $old_product['name'] ?? null,
            'description' => $old_product['description'] ?? null,
            'price' => $old_product['price'] ?? null,
            'quantity' => $old_product['quantity'] ?? null,
            'low_stock_threshold' => $old_product['low_stock_threshold'] ?? null,
            'brand' => $old_product['brand'] ?? null,
            'category' => $old_product['category'] ?? null,
            'supplier' => $old_product['supplier'] ?? null,
            'image' => $old_product['image'] ?? null,
            'variants' => $previousVariants,
        ];

        // Added: build structured change list so history modal can render exact edits.
        $fieldsToCompare = [
            'code' => ['label' => 'Code', 'type' => 'text'],
            'name' => ['label' => 'Name', 'type' => 'text'],
            'description' => ['label' => 'Description', 'type' => 'text'],
            'price' => ['label' => 'Price', 'type' => 'currency'],
            'quantity' => ['label' => 'Quantity', 'type' => 'number'],
            'low_stock_threshold' => ['label' => 'Low stock threshold', 'type' => 'number'],
            'brand' => ['label' => 'Brand', 'type' => 'text'],
            'category' => ['label' => 'Category', 'type' => 'text'],
            'supplier' => ['label' => 'Supplier', 'type' => 'text'],
            'image' => ['label' => 'Main image', 'type' => 'text'],
        ];

        foreach ($fieldsToCompare as $field => $meta) {
            $previousValue = $previousSnapshot[$field];
            $newValue = $currentSnapshot[$field];
            if ($previousValue != $newValue) {
                $changes[] = [
                    'field' => $field,
                    'label' => $meta['label'],
                    'type' => $meta['type'],
                    'from' => $previousValue,
                    'to' => $newValue,
                ];
            }
        }

        $removedGallerySummaries = [];
        foreach ($removedGalleryRows as $row) {
            $path = isset($row['file_path']) ? (string) $row['file_path'] : '';
            $removedGallerySummaries[] = $path !== '' ? basename(str_replace('\\', '/', $path)) : ('Image #' . ($row['id'] ?? '?'));
        }

        if (!empty($removedGallerySummaries)) {
            $changes[] = [
                'field' => 'gallery_images',
                'label' => 'Gallery images',
                'type' => 'text',
                'from' => implode(', ', $removedGallerySummaries),
                'to' => 'Removed',
            ];
        }

        if (!empty($variantSyncSummary['added']) || !empty($variantSyncSummary['updated']) || !empty($variantSyncSummary['deleted'])) {
            // Added: highlight variant activity in the history feed so staff can audit stock per size.
            $variantChangeFragments = [];
            if (!empty($variantSyncSummary['added'])) {
                $labels = array_map(static fn($row) => $row['label'], $variantSyncSummary['added']);
                $variantChangeFragments[] = 'Added ' . implode(', ', $labels);
            }
            if (!empty($variantSyncSummary['updated'])) {
                $labels = array_map(static fn($row) => $row['label'], $variantSyncSummary['updated']);
                $variantChangeFragments[] = 'Updated ' . implode(', ', $labels);
            }
            if (!empty($variantSyncSummary['deleted'])) {
                $labels = array_map(static fn($row) => $row['label'], $variantSyncSummary['deleted']);
                $variantChangeFragments[] = 'Removed ' . implode(', ', $labels);
            }

            $changes[] = [
                'field' => 'variants',
                'label' => 'Variants',
                'type' => 'text',
                'from' => '',
                'to' => implode('; ', $variantChangeFragments),
            ];
        }

        $historyPayload = [
            'summary' => sprintf('Updated product information (%d change%s).', count($changes), count($changes) === 1 ? '' : 's'),
            'changes' => $changes,
            'snapshot' => $currentSnapshot,
            'previous' => $previousSnapshot,
            'variant_changes' => $variantSyncSummary,
            'removed_gallery_images' => $removedGallerySummaries,
            'removed_main_image' => $removedMainImage,
        ];

        $pdo->prepare('INSERT INTO product_add_history (product_id, user_id, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$id, $user_id, 'edit', json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } else {
        // Insert new product
        $stmt = $pdo->prepare('INSERT INTO products (code, name, description, price, quantity, low_stock_threshold, brand, category, supplier, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$code, $name, $desc, $price, $qty, $low, $brand, $category, $supplier, null]);
        $product_id = $pdo->lastInsertId();

        $storedImagePath = null;
        try {
            $storedImagePath = moveUploadedProductImage($mainImageFile, (int) $product_id);
        } catch (RuntimeException $e) {
            error_log('Product image upload failed: ' . $e->getMessage());
        }

        if ($storedImagePath !== null) {
            $pdo->prepare('UPDATE products SET image = ? WHERE id = ?')->execute([$storedImagePath, $product_id]);
        }

        $currentSnapshot['image'] = $storedImagePath;
        persistGalleryUploads($pdo, $galleryImageFiles, (int) $product_id);
        $variantSyncSummary = syncProductVariants($pdo, (int) $product_id, $variantRecords); // Added: seed variants for the new product.
        $currentSnapshot['variants'] = fetchProductVariants($pdo, (int) $product_id); // Added: snapshot uses stored variant rows.

        // Record addition in history
        $historyPayload = [
            'summary' => sprintf(
                'Added %s (%s). Stock: %s • Price: ₱%s • Brand: %s • Category: %s • Supplier: %s%s',
                $name !== '' ? $name : 'Unnamed product',
                $code !== '' ? $code : 'no code',
                number_format($qty),
                number_format($price, 2),
                $brand ?? '-',
                $category ?? '-',
                $supplier ?? '-',
                $storedImagePath ? ' • Photo uploaded' : ''
            ),
            'snapshot' => $currentSnapshot,
            'variant_changes' => $variantSyncSummary,
        ];

        $pdo->prepare('INSERT INTO product_add_history (product_id, user_id, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$product_id, $user_id, 'add', json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    header('Location: products.php'); exit;
}
// Fetch unique brands and categories (before pagination to avoid unnecessary full fetch)
$brands = $pdo->query('SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != ""')->fetchAll(PDO::FETCH_COLUMN);
$categories = $pdo->query('SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != ""')->fetchAll(PDO::FETCH_COLUMN);
$suppliers = $pdo->query('SELECT DISTINCT supplier FROM products WHERE supplier IS NOT NULL AND supplier != ""')->fetchAll(PDO::FETCH_COLUMN);
$productCodeIndexRows = $pdo->query('SELECT id, code FROM products WHERE code IS NOT NULL AND code != ""')->fetchAll(PDO::FETCH_ASSOC);
$productCodeIndex = [];
foreach ($productCodeIndexRows as $row) {
    $code = isset($row['code']) ? trim((string) $row['code']) : '';
    if ($code === '') {
        continue;
    }
    $productCodeIndex[] = [
        'id' => isset($row['id']) ? (int) $row['id'] : 0,
        'code' => $code,
    ];
}

/**
 * Handle product filtering, search, and pagination functionality (styled after sales.php).
 * Retrieves GET parameters for search term, brand, category, supplier filters, and page.
 * Builds dynamic SQL queries: one for COUNT(*) to get total records, and one for paginated results using named parameters.
 * Uses prepared statements with bindValue to prevent SQL injection.
 * Pagination: max 15 entries per page, calculates offset based on page number.
 * Preserves filters in pagination links.
 */
$search = $_GET['search'] ?? '';
$brand_filter = $_GET['brand'] ?? '';
$category_filter = $_GET['category'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$sort = $_GET['sort'] ?? '';
$direction = strtolower($_GET['direction'] ?? 'asc');
$direction = $direction === 'desc' ? 'desc' : 'asc';

// Pagination setup (matching sales.php style)
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$limit = 15;
$offset = ($page - 1) * $limit;

// Build the base WHERE clause (shared for COUNT and SELECT)
$where_sql = 'WHERE 1=1';
$filter_params = []; // Named parameters for filters only
if ($search !== '') {
    // Search in product name or code
    $where_sql .= ' AND (name LIKE :search_name OR code LIKE :search_code)';
    $filter_params[':search_name'] = "%$search%";
    $filter_params[':search_code'] = "%$search%";
}
if ($brand_filter !== '') {
    // Filter by specific brand
    $where_sql .= ' AND brand = :brand_filter';
    $filter_params[':brand_filter'] = $brand_filter;
}
if ($category_filter !== '') {
    // Filter by specific category
    $where_sql .= ' AND category = :category_filter';
    $filter_params[':category_filter'] = $category_filter;
}
if ($supplier_filter !== '') {
    // Filter by specific supplier
    $where_sql .= ' AND supplier = :supplier_filter';
    $filter_params[':supplier_filter'] = $supplier_filter;
}

// First, get total count for pagination (using filter params)
$count_sql = 'SELECT COUNT(*) FROM products ' . $where_sql;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($filter_params);
$total_products = $count_stmt->fetchColumn();
$total_pages = ceil($total_products / $limit);

// Main query with LIMIT and OFFSET (using named params like sales.php)
$order_sql = 'ORDER BY id DESC';
if ($sort === 'name') {
    $order_sql = 'ORDER BY name ' . strtoupper($direction) . ', id DESC';
}
$sql = 'SELECT * FROM products ' . $where_sql . ' ' . $order_sql . ' LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($filter_params as $placeholder => $value) {
    $stmt->bindValue($placeholder, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();
$productIds = array_column($products, 'id');
$productVariantMap = fetchVariantsForProducts($pdo, $productIds); // Added: preload variants for listing/actions.
$productGalleryMap = fetchGalleryImagesForProducts($pdo, $productIds);

// Calculate showing info (like sales.php)
$start_record = $offset + 1;
$end_record = min($offset + $limit, $total_products);
$currentSort = $sort === 'name' ? 'name' : '';
$currentDirection = $currentSort === 'name' ? $direction : '';
$nameSortDirection = ($currentSort === 'name' && $currentDirection === 'asc') ? 'desc' : 'asc';
$nameSortParams = $_GET;
unset($nameSortParams['page']);
$nameSortParams['page'] = 1;
$nameSortParams['sort'] = 'name';
$nameSortParams['direction'] = $nameSortDirection;
$nameSortQuery = http_build_query($nameSortParams);
$nameSortUrl = 'products.php' . ($nameSortQuery ? '?' . $nameSortQuery : '');
$nameSortIndicator = '';
if ($currentSort === 'name') {
    $nameSortIndicator = $currentDirection === 'asc' ? '▲' : '▼';
} else {
    $nameSortIndicator = '↕';
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/style.css">
<link rel="stylesheet" href="../dgz_motorshop_system/assets/css/sales/sales.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/products/products.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/products/variants.css"> <!-- Added: styles for the variant editor grid. -->
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/products/product_modals.css"> <!-- Added: widened horizontal modal layout. -->
    <style>
        .products-alert {
            margin: 20px auto;
            max-width: 1200px;
            padding: 14px 18px;
            border-radius: 6px;
            border: 1px solid #f5c6cb;
            background-color: #f8d7da;
            color: #721c24;
            font-size: 0.95rem;
        }
    </style>

</head>

<body>
    <!-- Sidebar -->
    <?php
        $activePage = 'products.php';
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
                <h2>Products - Add / Edit </h2>
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
        <?php if ($productFormError !== null && $productFormError !== ''): ?>
        <div class="products-alert"><?= htmlspecialchars($productFormError) ?></div>
        <?php endif; ?>
        <!-- Action buttons aligned to the right -->
        <div class="products-action-bar">
            <?php if (!$isStaff): ?>
            <button id="openAddModal" class="add-btn" type="button">
                <i class="fas fa-plus"></i> Add Product
            </button>
            <?php endif; ?>
            <button id="openHistoryModal" class="history-btn" type="button">
                <i class="fas fa-history"></i> History
            </button>
        </div>

        <!-- Product Add History Modal (like stockEntry.php) -->
        <div id="historyModal"
            style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:flex-end;">
            <div class="modal-content"
                style="background:white; padding:30px; border-radius:10px; width:100%; max-width:1600px; position:relative; box-shadow:0 4px 6px rgba(0,0,0,0.1); margin:50px;">
                <button type="button" id="closeHistoryModal"
                    style="position:absolute; top:20px; right:25px; background:none; border:none; font-size:24px; color:#888; cursor:pointer;">&times;</button>
                <h3 style="margin:0 0 20px 0; font-size:1.5em; color:#333;">Product Add History</h3>
                <div class="recent-entries" style="width:100%;">
                    <div id="historyList"
                        style="max-height:610px; overflow-y:auto; width:100%; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.05); border:1px solid #e2e8f0;">
                    </div>
                </div>
            </div>
        </div>
        <!-- user menu & sidebar-->
       <script src="../dgz_motorshop_system/assets/js/dashboard/userMenu.js"></script>

        <!-- Add Product Modal -->
        <!-- Added: Modal overlay uses reusable class to inherit horizontal layout styles. -->
        <div id="addModal" class="modal-portal">
            <div class="modal-content-horizontal">
                <div class="modal-close-bar"> <!-- Updated: Close control now lives inside the modal canvas so it stays anchored while scrolling. -->
                    <button type="button" id="closeAddModal" class="modal-close-button">&times;</button> <!-- Updated: Modal-level close button stays visible when scrolling. -->
                </div>
                <form method="post" id="addProductForm" enctype="multipart/form-data" class="product-modal__form">
                    <h3>Add Product</h3>
                    <input type="hidden" name="id" value="0">
                    <div class="product-modal__layout">
                        <div class="product-modal__panel product-modal__details">
                            <div class="product-modal__grid">
                                <!-- Updated: Field wrapper keeps inputs aligned within the new grid layout. -->
                                <div class="product-modal__field">
                                    <label for="addProductCode">Product Code</label>
                                    <input id="addProductCode" name="code" required placeholder="Enter product code">
                                </div>
                                <div class="product-modal__field">
                                    <label for="addProductName">Name</label>
                                    <input id="addProductName" name="name" required placeholder="Enter product name">
                                </div>
                                <div class="product-modal__field">
                                    <label for="brandSelect">Brand</label>
                                    <select name="brand" id="brandSelect" onchange="toggleBrandInput(this)">
                                        <option value="">Select brand</option>
                                        <?php foreach($brands as $b): ?>
                                        <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__addnew__">Add new brand...</option>
                                    </select>
                                    <input name="brand_new" id="brandNewInput" placeholder="Enter new brand" style="display:none; margin-top:6px;">
                                </div>
                                <div class="product-modal__field">
                                    <label for="categorySelect">Category</label>
                                    <select name="category" id="categorySelect" onchange="toggleCategoryInput(this)">
                                        <option value="">Select category</option>
                                        <?php foreach($categories as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__addnew__">Add new category...</option>
                                    </select>
                                    <input name="category_new" id="categoryNewInput" placeholder="Enter new category" style="display:none; margin-top:6px;">
                                </div>
                                <div class="product-modal__field product-modal__full">
                                    <label for="addProductDescription">Description</label>
                                    <textarea id="addProductDescription" name="description" placeholder="Enter product description"></textarea>
                                </div>
                                <div class="product-modal__field">
                                    <label for="addProductQuantity">Quantity</label>
                                    <input id="addProductQuantity" name="quantity" type="number" min="0" required placeholder="Enter quantity or leave for variants" data-variant-total-quantity>
                                </div>
                                <div class="product-modal__field">
                                    <label for="addProductPrice">Price per unit</label>
                                    <input id="addProductPrice" name="price" type="number" min="0" step="0.01" required placeholder="Enter price or use variant default" data-variant-default-price>
                                </div>
                                <div class="product-modal__field">
                                    <label for="supplierSelect">Supplier</label>
                                    <select id="supplierSelect" name="supplier" onchange="toggleSupplierInput(this)">
                                        <option value="">Select supplier</option>
                                        <?php foreach($suppliers as $s): ?>
                                        <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__addnew__">Add new supplier...</option>
                                    </select>
                                    <input name="supplier_new" id="supplierNewInput" placeholder="Enter new supplier" style="display:none; margin-top:6px;">
                                </div>
                                <div class="product-modal__field">
                                    <label for="addProductLowStock">Low Stock Threshold</label>
                                    <input id="addProductLowStock" name="low_stock_threshold" value="5" type="number" min="0" required>
                                </div>
                            </div>
                        </div>
                        <div class="product-modal__panel product-modal__media">
                            <!-- Added: Media panel now sits beside the detail grid but above the variants section. -->
                            <label>Product Image:
                                <input name="image" type="file" accept="image/*" onchange="previewAddImage(event)">
                            </label>
                            <p>Upload square photos (~600×600px). Files are stored under <code>/uploads/products</code>.</p>
                            <label>Additional Gallery Images:
                                <input name="gallery_images[]" type="file" accept="image/*" multiple>
                            </label>
                            <img id="addImagePreview" class="modal-image-preview" src="../dgz_motorshop_system/assets/img/product-placeholder.svg" alt="Preview">
                        </div>
                        <div class="product-modal__panel product-modal__variants">
                            <div class="variant-editor" data-variant-editor data-context="create" data-initial-variants="[]">
                                <!-- Added: repeatable variant rows now anchored beneath the media section for clarity. -->
                                <div class="variant-editor__header">
                                    <h4>Variants / Sizes</h4>
                                    <button type="button" class="variant-editor__add" data-variant-add>
                                        <i class="fas fa-plus"></i> Add Variant
                                    </button>
                                </div>
                                <p class="variant-editor__hint">Each variant may represent a size (e.g., 50ml, 100ml) or configuration.</p>
                                <div class="variant-editor__rows" data-variant-rows></div>
                                <template data-variant-template>
                                    <div class="variant-row" data-variant-row>
                                        <input type="hidden" data-variant-id>
                                        <div class="variant-row__field">
                                            <label>Label / Size
                                                <!-- Updated: allow blank variant rows so base price/qty can be used without validation blocking. -->
                                                <input type="text" data-variant-label placeholder="e.g., 100ml">
                                            </label>
                                        </div>
                                        <div class="variant-row__field">
                                            <label>SKU (optional)
                                                <input type="text" data-variant-sku placeholder="Custom SKU">
                                            </label>
                                        </div>
                                        <div class="variant-row__field">
                                            <label>Price
                                                <input type="number" min="0" step="0.01" data-variant-price>
                                            </label>
                                        </div>
                                        <div class="variant-row__field">
                                            <label>Quantity
                                                <input type="number" min="0" data-variant-quantity>
                                            </label>
                                        </div>
                                        <div class="variant-row__field variant-row__field--default">
                                            <label class="variant-default-toggle">
                                                <input type="radio" name="create_variant_default" data-variant-default>
                                                Default
                                            </label>
                                        </div>
                                        <button type="button" class="variant-row__remove" data-variant-remove aria-label="Remove variant">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </template>
                                <input type="hidden" name="variants_payload" data-variants-payload>
                            </div>
                        </div>
                    </div>
                    <button name="save_product" type="submit">Add</button>
                </form>
            </div>
       </div>
       <!-- Fallback Synchroniser -->
       <script src="../dgz_motorshop_system/assets/js/products/fbSynchroniser.js"></script>
       <script src="../dgz_motorshop_system/assets/js/products/tableFilters.js"></script>
        <script>
            window.PRODUCT_CODE_INDEX = <?= json_encode($productCodeIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
        </script>

        <!-- Products table displaying filtered/search results -->
        <div id="productsTable" class="table-container">
            <form method="get" class="products-filter-form" id="productsFilterForm">
                <input type="hidden" name="page" value="1">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($currentSort) ?>">
                <input type="hidden" name="direction" value="<?= htmlspecialchars($currentDirection ?: 'asc') ?>">
                <div class="filter-row">
                    <div class="filter-search-group">
                        <input type="text" name="search" aria-label="Search products" placeholder="Search product by name or code..."
                            value="<?= htmlspecialchars($search ?? '') ?>" class="filter-search-input">
                        <button type="button" class="filter-clear" aria-label="Clear search" data-filter-clear>&times;</button>
                    </div>
                </div>
                <div class="filter-row filter-row--selects">
                    <select name="brand" aria-label="Filter by brand" class="filter-select">
                        <option value="">All Brands</option>
                        <?php foreach ($brands as $b): ?>
                        <option value="<?= htmlspecialchars($b) ?>" <?= ($brand_filter ?? '') === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="category" aria-label="Filter by category" class="filter-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= ($category_filter ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="supplier" aria-label="Filter by supplier" class="filter-select">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= ($supplier_filter ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="filter-submit" data-filter-submit>Filter</button>
                </div>
            </form>
            <div class="table-wrapper">
                <table class="products-table">
                    <thead>
                        <tr>
                            <th scope="col">Code</th>
                            <th scope="col">
                                <a href="<?= htmlspecialchars($nameSortUrl) ?>" class="sort-link">
                                    Name
                                    <span class="sort-indicator"><?= htmlspecialchars($nameSortIndicator) ?></span>
                                </a>
                            </th>
                            <th scope="col">Qty</th>
                            <th scope="col">Price</th>
                            <?php if (!$isStaff): ?>
                            <th scope="col">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="<?= $isStaff ? 4 : 5 ?>" style="text-align: center; padding: 40px; color: #6b7280;">
                                <i class="fas fa-inbox" style="font-size: 36px; margin-bottom: 8px; display: block;"></i>
                                No products found matching the criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                    <?php foreach($products as $p): ?>
                    <?php
                        // Added: embed variant JSON so the modal can hydrate the new variant editor.
                        $variantsForProduct = $productVariantMap[$p['id']] ?? [];
                        $variantsJson = htmlspecialchars(json_encode($variantsForProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $defaultVariantLabel = '';
                        foreach ($variantsForProduct as $variantRow) {
                            if (!empty($variantRow['is_default'])) {
                                $defaultVariantLabel = $variantRow['label'];
                                break;
                            }
                        }

                        $rawImagePath = $p['image'] ?? '';
                        $normalisedImagePath = $rawImagePath !== '' ? ltrim(str_replace('\\', '/', $rawImagePath), '/') : '';
                        if ($normalisedImagePath !== '' && strpos($normalisedImagePath, 'dgz_motorshop_system/') !== 0) {
                            // Back-compat for older rows without the system prefix
                            $normalisedImagePath = 'dgz_motorshop_system/' . $normalisedImagePath;
                        }
                        $imageUrl = $normalisedImagePath !== '' ? '../' . $normalisedImagePath : '../dgz_motorshop_system/assets/img/product-placeholder.svg';

                        // Added: package the row payload so the detail modal can display read-only information.
                        $galleryImagesForProduct = array_values($productGalleryMap[$p['id']] ?? []);

                        $productDetailPayload = [
                            'id' => (int) $p['id'],
                            'code' => $p['code'] ?? '',
                            'name' => $p['name'] ?? '',
                            'description' => $p['description'] ?? '',
                            'brand' => $p['brand'] ?? '',
                            'category' => $p['category'] ?? '',
                            'supplier' => $p['supplier'] ?? '',
                            'quantity' => isset($p['quantity']) ? (float) $p['quantity'] : null,
                            'price' => isset($p['price']) ? (float) $p['price'] : null,
                            'low_stock_threshold' => isset($p['low_stock_threshold']) ? (float) $p['low_stock_threshold'] : null,
                            'image' => $rawImagePath,
                            'imageUrl' => $imageUrl,
                            'variants' => $variantsForProduct,
                            'defaultVariant' => $defaultVariantLabel,
                            'gallery' => $galleryImagesForProduct,
                        ];
                        $productDetailJson = htmlspecialchars(json_encode($productDetailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                        $galleryJson = htmlspecialchars(json_encode($galleryImagesForProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr class="product-row" data-product="<?=$productDetailJson?>">
                            <td><?=htmlspecialchars($p['code'])?></td>
                            <td><?=htmlspecialchars($p['name'])?></td>
                            <td><?=intval($p['quantity'])?></td>
                            <td>₱<?=number_format($p['price'],2)?></td>
                            <?php if (!$isStaff): ?>
                            <td>
                                <a href="#" class="edit-btn action-btn" data-id="<?=$p['id']?>"
                                    data-code="<?=htmlspecialchars($p['code'])?>" data-name="<?=htmlspecialchars($p['name'])?>"
                                    data-description="<?=htmlspecialchars($p['description'])?>"
                                    data-price="<?=htmlspecialchars($p['price'])?>"
                                    data-quantity="<?=htmlspecialchars($p['quantity'])?>"
                                    data-low="<?=htmlspecialchars($p['low_stock_threshold'])?>"
                                    data-brand="<?=htmlspecialchars($p['brand'] ?? '')?>"
                                    data-category="<?=htmlspecialchars($p['category'] ?? '')?>"
                                    data-supplier="<?=htmlspecialchars($p['supplier'] ?? '')?>"
                                    data-image="<?=htmlspecialchars($p['image'] ?? '')?>"
                                    data-image-url="<?=htmlspecialchars($imageUrl)?>"
                                    data-variants="<?=$variantsJson?>"
                                    data-gallery="<?=$galleryJson?>"
                                    data-default-variant="<?=htmlspecialchars($defaultVariantLabel)?>"><i class="fas fa-edit"></i>Edit</a>
                                <a href="products.php?delete=<?=$p['id']?>" class="delete-btn action-btn"
                                    onclick="return confirm('Delete?')"> <i class="fas fa-trash"></i>Delete</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_products > 0): ?>
            <!-- Pagination container (styled after sales.php: info + links, preserving filter parameters) -->
            <div class="pagination-container">
                <div class="pagination-info">
                    Showing <?=$start_record?> to <?=$end_record?> of <?=$total_products?> entries
                </div>
                <div class="pagination">
                    <?php
                    // Prepare current parameters without 'page' for base links (to preserve filters)
                    $current_params = $_GET;
                    unset($current_params['page']);
                    $base_query = http_build_query($current_params);
                    $separator = $base_query ? '&' : '?';
                    ?>

                    <!-- Previous button -->
                    <?php if ($page > 1): ?>
                    <a href="?<?= $base_query . $separator ?>page=<?= ($page - 1) ?>" class="prev">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                    <?php else: ?>
                    <span class="prev disabled">
                        <i class="fas fa-chevron-left"></i> Prev
                    </span>
                    <?php endif; ?>

                    <!-- Page numbers -->
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    // Show first page if not in range
                    if ($start_page > 1): ?>
                    <a href="?<?= $base_query . $separator ?>page=1">1</a>
                    <?php if ($start_page > 2): ?>
                    <span>...</span>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- Show page numbers in range -->
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <?php if ($i == $page): ?>
                    <span class="current"><?= $i ?></span>
                    <?php else: ?>
                    <a href="?<?= $base_query . $separator ?>page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    
                    <!-- Show last page if not in range -->
                    <?php if ($end_page < $total_pages): ?>
                    <?php if ($end_page < $total_pages - 1): ?>
                    <span>...</span>
                    <?php endif; ?>
                    <a href="?<?= $base_query . $separator ?>page=<?= $total_pages ?>"><?= $total_pages ?></a>
                    <?php endif; ?>

                    <!-- Next button -->
                    <?php if ($page < $total_pages): ?>
                    <a href="?<?= $base_query . $separator ?>page=<?= ($page + 1) ?>" class="next">
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
        <!-- Product Detail Modal -->
        <div id="productDetailModal" class="modal-portal">
            <div class="modal-content-horizontal">
                <div class="modal-close-bar">
                    <button type="button" id="closeProductDetailModal" class="modal-close-button">&times;</button>
                </div>
                <div class="product-modal__form product-modal__form--readonly">
                    <h3>Product Detail</h3>
                    <div class="product-modal__layout">
                        <div class="product-modal__panel product-modal__details">
                            <div class="product-modal__grid">
                                <div class="product-modal__field">
                                    <label>Product Code</label>
                                    <div class="product-detail__value" data-detail-field="code">&mdash;</div>
                                </div>
                                <div class="product-modal__field">
                                    <label>Name</label>
                                    <div class="product-detail__value" data-detail-field="name">&mdash;</div>
                                </div>
                                <div class="product-modal__field">
                                    <label>Brand</label>
                                    <div class="product-detail__value" data-detail-field="brand">&mdash;</div>
                                </div>
                                <div class="product-modal__field">
                                    <label>Category</label>
                                    <div class="product-detail__value" data-detail-field="category">&mdash;</div>
                                </div>
                                <div class="product-modal__field">
                                    <label>Supplier</label>
                                    <div class="product-detail__value" data-detail-field="supplier">&mdash;</div>
                                </div>
                                <div class="product-modal__field">
                                    <label>Quantity</label>
                                    <div class="product-detail__value" data-detail-field="quantity">&mdash;</div>
                                </div>
                                <div class="product-modal__field">
                                    <label>Price per unit</label>
                                    <div class="product-detail__value" data-detail-field="price">&mdash;</div>
                                </div>
                                <div class="product-modal__field">
                                    <label>Low Stock Threshold</label>
                                    <div class="product-detail__value" data-detail-field="low_stock_threshold">&mdash;</div>
                                </div>
                                <div class="product-modal__field product-modal__full">
                                    <label>Description</label>
                                    <div class="product-detail__value product-detail__value--multiline" data-detail-field="description">No description provided.</div>
                                </div>
                                <div class="product-modal__field product-modal__full">
                                    <label>Default Variant</label>
                                    <div class="product-detail__value" data-detail-field="defaultVariant">&mdash;</div>
                                </div>
                            </div>
                        </div>
                        <div class="product-modal__panel product-modal__media">
                            <div class="product-detail__gallery" data-detail-gallery-wrapper>
                                <p class="product-detail__gallery-title">Product images</p>
                                <div class="product-detail__gallery-grid" data-detail-gallery-grid>
                                    <div class="product-detail__gallery-item product-detail__gallery-item--main" data-detail-main-item>
                                        <img class="product-detail__gallery-thumb product-detail__gallery-thumb--main" data-detail-image src="../dgz_motorshop_system/assets/img/product-placeholder.svg" alt="Product preview">
                                        <span class="product-detail__gallery-caption">Main product image</span>
                                    </div>
                                </div>
                                <p class="product-detail__gallery-empty" data-detail-gallery-empty hidden>No additional gallery images available for this product.</p>
                            </div>
                        </div>
                        <div class="product-modal__panel product-modal__variants">
                            <div class="product-detail__variants" data-detail-variants>
                                <p class="product-detail__variants-empty">No variants available for this product.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Edit Product Modal -->
        <!-- Added: Reuse the horizontal modal overlay for the edit form. -->
        <div id="editModal" class="modal-portal">
            <div class="modal-content-horizontal">
                <div class="modal-close-bar"> <!-- Updated: Close control now lives inside the modal canvas so it stays anchored while scrolling. -->
                    <button type="button" id="closeEditModal" class="modal-close-button">&times;</button> <!-- Updated: Modal-level close button stays visible when scrolling. -->
                </div>
                <form method="post" id="editProductForm" enctype="multipart/form-data" class="product-modal__form">
                    <h3>Edit Product</h3>
                    <input type="hidden" name="id" id="edit_id">
                    <div class="product-modal__layout">
                        <div class="product-modal__panel product-modal__details">
                            <div class="product-modal__grid">
                                <!-- Added: Layout mirrors the add modal so both dialogs remain consistent. -->
                                <div class="product-modal__field">
                                    <label for="edit_code">Product Code</label>
                                    <input name="code" id="edit_code" required placeholder="Enter product code">
                                </div>
                                <div class="product-modal__field">
                                    <label for="edit_name">Name</label>
                                    <input name="name" id="edit_name" required placeholder="Enter product name">
                                </div>
                                <div class="product-modal__field">
                                    <label for="edit_brand">Brand</label>
                                    <select name="brand" id="edit_brand" onchange="toggleBrandInputEdit(this)">
                                        <option value="">Select brand</option>
                                        <?php foreach($brands as $b): ?>
                                        <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__addnew__">Add new brand...</option>
                                    </select>
                                    <input name="brand_new" id="edit_brand_new" placeholder="Enter new brand" style="display:none; margin-top:6px;">
                                </div>
                                <div class="product-modal__field">
                                    <label for="edit_category">Category</label>
                                    <select name="category" id="edit_category" onchange="toggleCategoryInputEdit(this)">
                                        <option value="">Select category</option>
                                        <?php foreach($categories as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__addnew__">Add new category...</option>
                                    </select>
                                    <input name="category_new" id="edit_category_new" placeholder="Enter new category" style="display:none; margin-top:6px;">
                                </div>
                                <div class="product-modal__field product-modal__full">
                                    <label for="edit_description">Description</label>
                                    <textarea name="description" id="edit_description" placeholder="Enter product description"></textarea>
                                </div>
                                <div class="product-modal__field">
                                    <label for="edit_quantity">Quantity</label>
                                    <input name="quantity" id="edit_quantity" type="number" min="0" required placeholder="Enter quantity or leave for variants" data-variant-total-quantity>
                                </div>
                                <div class="product-modal__field">
                                    <label for="edit_price">Price per unit</label>
                                    <input name="price" id="edit_price" type="number" min="0" step="0.01" required placeholder="Enter price or use variant default" data-variant-default-price>
                                </div>
                                <div class="product-modal__field">
                                    <label for="edit_supplier">Supplier</label>
                                    <select name="supplier" id="edit_supplier" onchange="toggleSupplierInputEdit(this)">
                                        <option value="">Select supplier</option>
                                        <?php foreach($suppliers as $s): ?>
                                        <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__addnew__">Add new supplier...</option>
                                    </select>
                                    <input name="supplier_new" id="edit_supplier_new" placeholder="Enter new supplier" style="display:none; margin-top:6px;">
                                </div>
                                <div class="product-modal__field">
                                    <label for="edit_low">Low Stock Threshold</label>
                                    <input name="low_stock_threshold" id="edit_low" value="5" type="number" min="0" required>
                                </div>
                            </div>
                        </div>
                        <div class="product-modal__panel product-modal__media">
                            <!-- Added: Media panel mirrors add modal so imagery stays aligned. -->
                            <div class="product-modal__main" data-main-image-wrapper>
                                <p class="product-modal__gallery-title">Product image</p>
                                <div class="product-modal__gallery-item product-modal__gallery-item--main">
                                    <img id="editImagePreview" class="modal-image-preview product-modal__main-thumb" src="../dgz_motorshop_system/assets/img/product-placeholder.svg" alt="Product image preview">
                                    <label class="product-modal__gallery-remove product-modal__gallery-remove--main" for="edit_remove_main_image" data-main-image-toggle hidden>
                                        <input type="checkbox" id="edit_remove_main_image" name="remove_main_image" value="1">
                                        <span>Remove current image</span>
                                    </label>
                                </div>
                            </div>
                            <label class="product-modal__file-label">Replace product image:
                                <input name="image" id="edit_image" type="file" accept="image/*" onchange="previewEditImage(event)">
                            </label>
                            <label>Additional Gallery Images:
                                <input name="gallery_images[]" id="edit_gallery_images" type="file" accept="image/*" multiple>
                            </label>
                            <div class="product-modal__gallery" data-gallery-container hidden>
                                <p class="product-modal__gallery-title">Existing gallery images</p>
                                <p class="product-modal__gallery-hint">Tick the images you want to remove before saving.</p>
                                <div class="product-modal__gallery-grid" data-gallery-list></div>
                            </div>
                        </div>
                        <div class="product-modal__panel product-modal__variants">
                            <div class="variant-editor" data-variant-editor data-context="edit" data-initial-variants="[]">
                                <!-- Added: Editable variant grid spans the full width under the media column. -->
                                <div class="variant-editor__header">
                                    <h4>Variants / Sizes</h4>
                                    <button type="button" class="variant-editor__add" data-variant-add>
                                        <i class="fas fa-plus"></i> Add Variant
                                    </button>
                                </div>
                                <p class="variant-editor__hint">Update stock and pricing for each size below.</p>
                                <div class="variant-editor__rows" data-variant-rows></div>
                                <template data-variant-template>
                                    <div class="variant-row" data-variant-row>
                                        <input type="hidden" data-variant-id>
                                        <div class="variant-row__field">
                                            <label>Label / Size
                                                <!-- Updated: variant label no longer required so admins can skip variant setup. -->
                                                <input type="text" data-variant-label placeholder="e.g., 90/90-14">
                                            </label>
                                        </div>
                                        <div class="variant-row__field">
                                            <label>SKU (optional)
                                                <input type="text" data-variant-sku placeholder="Custom SKU">
                                            </label>
                                        </div>
                                        <div class="variant-row__field">
                                            <label>Price
                                                <input type="number" min="0" step="0.01" data-variant-price>
                                            </label>
                                        </div>
                                        <div class="variant-row__field">
                                            <label>Quantity
                                                <input type="number" min="0" data-variant-quantity>
                                            </label>
                                        </div>
                                        <div class="variant-row__field variant-row__field--default">
                                            <label class="variant-default-toggle">
                                                <input type="radio" name="edit_variant_default" data-variant-default>
                                                Default
                                            </label>
                                        </div>
                                        <button type="button" class="variant-row__remove" data-variant-remove aria-label="Remove variant">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </template>
                                <input type="hidden" name="variants_payload" data-variants-payload>
                            </div>
                        </div>
                    </div>
                    <button name="save_product" type="submit">Save Changes</button>
                </form>
            </div>
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
    <!-- Edit modal fallback toggles -->
    <script src="../dgz_motorshop_system/assets/js/products/variantsForm.js"></script> <!-- Added: drives the variant add/edit UI. -->
    <script src="../dgz_motorshop_system/assets/js/products/editModal.js"></script>
    <!-- History Modal -->
     <script src="../dgz_motorshop_system/assets/js/products/historyDOM.js"></script>
    <!-- Notificaitons -->
     <script src="../dgz_motorshop_system/assets/js/notifications.js"></script>

</body>

</html>
