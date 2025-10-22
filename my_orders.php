<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

requireCustomerAuthentication();
$customer = getAuthenticatedCustomer();

$customerSessionState = customerSessionExport();
$customerFirstName = $customerSessionState['firstName'] ?? extractCustomerFirstName($customer['full_name'] ?? '');

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$cartScript = assetUrl('assets/js/public/cart.js');
$logoAsset = assetUrl('assets/logo.png');
$homeUrl = orderingUrl('index.php');
$logoutUrl = orderingUrl('logout.php');
$myOrdersUrl = orderingUrl('my_orders.php');
$settingsUrl = orderingUrl('settings.php');
$cartUrl = orderingUrl('checkout.php');

$pdo = db();
$alerts = [
    'success' => [],
    'error' => [],
];

if (!function_exists('orderItemsColumnExists')) {
    function orderItemsColumnExists(PDO $pdo, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM order_items LIKE ?");
            $stmt->execute([$column]);
            $cache[$column] = $stmt !== false && $stmt->fetch() !== false;
        } catch (Throwable $exception) {
            error_log('Unable to inspect order_items column ' . $column . ': ' . $exception->getMessage());
            $cache[$column] = false;
        }

        return $cache[$column];
    }
}

if (!function_exists('ordersDescribe')) {
    function ordersDescribe(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM orders');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $exception) {
            error_log('Unable to describe orders table: ' . $exception->getMessage());
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            if (!isset($row['Field'])) {
                continue;
            }
            $columns[strtolower((string) $row['Field'])] = (string) $row['Field'];
        }

        return $columns;
    }
}

if (!function_exists('ordersFindColumn')) {
    function ordersFindColumn(PDO $pdo, array $candidates): ?string
    {
        static $columnCache = null;
        if ($columnCache === null) {
            $columnCache = ordersDescribe($pdo);
        }

        foreach ($candidates as $candidate) {
            $normalized = strtolower($candidate);
            if (isset($columnCache[$normalized])) {
                return $columnCache[$normalized];
            }
        }

        return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_update_order_id'])) {
    $orderId = (int) $_POST['payment_update_order_id'];
    $referenceInput = trim((string) ($_POST['reference_number'] ?? ''));
    $referenceNumber = preg_replace('/[^A-Za-z0-9\- ]/', '', $referenceInput);
    $referenceNumber = strtoupper(substr($referenceNumber, 0, 50));

    $orderIdColumn = ordersFindColumn($pdo, ['id', 'order_id']);
    $customerIdColumn = ordersFindColumn($pdo, ['customer_id', 'customerId', 'customerID', 'customerid']);
    $orderStatusColumn = ordersFindColumn($pdo, ['status', 'order_status']);
    $orderReferenceColumn = ordersFindColumn($pdo, ['reference_no', 'reference_number', 'reference', 'ref_no']);
    $orderProofColumn = ordersFindColumn($pdo, ['payment_proof', 'proof_of_payment', 'payment_proof_path', 'proof']);

    if ($orderIdColumn === null || $customerIdColumn === null) {
        $alerts['error'][] = 'We could not update that order because the orders table is missing required columns.';
    } else {
        $errors = [];
        $newProofPath = null;

        if (!empty($_FILES['payment_proof']['tmp_name'] ?? '')) {
            $fileError = (int) ($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($fileError !== UPLOAD_ERR_OK) {
                $errors[] = 'Unable to upload the proof of payment. Please try again.';
            } else {
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                ];

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? finfo_file($finfo, $_FILES['payment_proof']['tmp_name']) : null;
                if ($finfo) {
                    finfo_close($finfo);
                }

                if (!$mime || !isset($allowed[$mime])) {
                    $errors[] = 'Please upload a valid image (JPG, PNG, GIF, or WEBP).';
                } else {
                    $uploadsRoot = __DIR__ . '/dgz_motorshop_system/uploads';
                    $uploadDir = $uploadsRoot . '/payment-proofs';
                    $publicUploadDir = 'dgz_motorshop_system/uploads/payment-proofs';

                    $setupOk = true;
                    if (!is_dir($uploadsRoot) && !mkdir($uploadsRoot, 0777, true) && !is_dir($uploadsRoot)) {
                        $errors[] = 'Failed to prepare the uploads storage.';
                        $setupOk = false;
                    }

                    if ($setupOk && !is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                        $errors[] = 'Failed to prepare the uploads storage.';
                        $setupOk = false;
                    }

                    if ($setupOk && !is_writable($uploadDir) && !chmod($uploadDir, 0777)) {
                        $errors[] = 'Uploads folder is not writable.';
                        $setupOk = false;
                    }

                    if ($setupOk) {
                        try {
                            $random = bin2hex(random_bytes(8));
                        } catch (Exception $e) {
                            $random = (string) time();
                        }

                        $storedFileName = sprintf('%s.%s', $random, $allowed[$mime]);
                        $targetPath = $uploadDir . '/' . $storedFileName;

                        $moved = move_uploaded_file($_FILES['payment_proof']['tmp_name'], $targetPath);
                        if (!$moved) {
                            $fileContents = @file_get_contents($_FILES['payment_proof']['tmp_name']);
                            if ($fileContents === false || @file_put_contents($targetPath, $fileContents) === false) {
                                $errors[] = 'Failed to save the uploaded proof of payment.';
                            } else {
                                $newProofPath = $publicUploadDir . '/' . $storedFileName;
                            }
                        } else {
                            $newProofPath = $publicUploadDir . '/' . $storedFileName;
                        }
                    }
                }
            }
        }

        if ($orderReferenceColumn === null && $referenceNumber !== '') {
            $errors[] = 'We could not update the reference number because the orders table is missing a reference column.';
        }

        if ($orderProofColumn === null && $newProofPath !== null) {
            $errors[] = 'We could not save the proof of payment because the orders table is missing a payment proof column.';
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $alerts['error'][] = $error;
            }
        } else {
            $transactionActive = false;
            try {
                try {
                    if (!$pdo->inTransaction()) {
                        $transactionActive = $pdo->beginTransaction();
                    } else {
                        $transactionActive = true;
                    }
                } catch (Throwable $transactionException) {
                    error_log('Database does not support transactions for customer payment updates: ' . $transactionException->getMessage());
                    $transactionActive = false;
                }

                $selectParts = ['`' . $orderIdColumn . '` AS `id`'];
                if ($orderStatusColumn !== null) {
                    $selectParts[] = '`' . $orderStatusColumn . '` AS `status`';
                }
                if ($orderReferenceColumn !== null) {
                    $selectParts[] = '`' . $orderReferenceColumn . '` AS `current_reference`';
                }
                if ($orderProofColumn !== null) {
                    $selectParts[] = '`' . $orderProofColumn . '` AS `current_proof`';
                }

                $sql = 'SELECT ' . implode(', ', $selectParts)
                    . ' FROM orders WHERE `' . $orderIdColumn . '` = ? AND `' . $customerIdColumn . '` = ? FOR UPDATE';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$orderId, (int) $customer['id']]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$order) {
                    $alerts['error'][] = 'We could not find that order.';
                    if ($transactionActive && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } else {
                    $status = strtolower((string) ($order['status'] ?? ''));
                    if ($status === '') {
                        $status = 'pending';
                    }

                    $lockedStatuses = ['delivery'];
                    $terminalStatuses = ['complete', 'completed', 'cancelled_by_staff', 'cancelled_by_customer', 'disapproved'];

                    if (in_array($status, $lockedStatuses, true) || in_array($status, $terminalStatuses, true)) {
                        $alerts['error'][] = 'This order can no longer be updated.';
                        if ($transactionActive && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    } else {
                        $updates = [];
                        $params = [];

                        if ($orderReferenceColumn !== null) {
                            $updates[] = '`' . $orderReferenceColumn . '` = ?';
                            $params[] = $referenceNumber !== '' ? $referenceNumber : null;
                        }

                        if ($orderProofColumn !== null && $newProofPath !== null) {
                            $updates[] = '`' . $orderProofColumn . '` = ?';
                            $params[] = $newProofPath;
                        }

                        if (empty($updates)) {
                            $alerts['error'][] = 'There were no changes to save for that order.';
                            if ($transactionActive && $pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                        } else {
                            try {
                                $pdo->exec("ALTER TABLE orders ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL");
                            } catch (Throwable $e) {
                                // Column already exists; ignore errors.
                            }

                            $updates[] = "`updated_at` = NOW()";
                            $sql = 'UPDATE orders SET ' . implode(', ', $updates) . ' WHERE `' . $orderIdColumn . '` = ?';
                            $params[] = $orderId;

                            $updateStmt = $pdo->prepare($sql);
                            $updateStmt->execute($params);
                            if ($transactionActive && $pdo->inTransaction()) {
                                $pdo->commit();
                            }

                            $alerts['success'][] = 'Payment details updated for your order.';
                        }
                    }
                }
            } catch (Throwable $exception) {
                if ($transactionActive && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Unable to update customer payment details: ' . $exception->getMessage());
                $alerts['error'][] = 'We could not update that order. Please try again or contact support.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order_id'])) {
    $orderId = (int) $_POST['cancel_order_id'];
    $transactionActive = false;
    try {
        try {
            if (!$pdo->inTransaction()) {
                $transactionActive = $pdo->beginTransaction();
            } else {
                $transactionActive = true;
            }
        } catch (Throwable $transactionException) {
            error_log('Database does not support transactions for customer cancellations: ' . $transactionException->getMessage());
            $transactionActive = false;
        }
        $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = ? AND customer_id = ? FOR UPDATE');
        $stmt->execute([$orderId, (int) $customer['id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $alerts['error'][] = 'We could not find that order.';
            if ($transactionActive && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } else {
            $status = strtolower((string) ($order['status'] ?? ''));
            if ($status === '') {
                $status = 'pending';
            }

            $lockedStatuses = ['delivery'];
            $terminalStatuses = ['complete', 'completed', 'cancelled_by_staff', 'cancelled_by_customer', 'disapproved'];

            if (in_array($status, $lockedStatuses, true)) {
                $alerts['error'][] = 'Orders that are already out for delivery can no longer be cancelled online.';
                if ($transactionActive && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif (in_array($status, $terminalStatuses, true)) {
                $alerts['error'][] = 'This order can no longer be cancelled online.';
                if ($transactionActive && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $itemStmt = $pdo->prepare('SELECT product_id, variant_id, qty FROM order_items WHERE order_id = ?');
                $itemStmt->execute([$orderId]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $qty = (int) ($item['qty'] ?? 0);
                    if ($qty <= 0) {
                        continue;
                    }
                    if (!empty($item['variant_id'])) {
                        $variantUpdate = $pdo->prepare('UPDATE product_variants SET quantity = quantity + ? WHERE id = ?');
                        $variantUpdate->execute([$qty, (int) $item['variant_id']]);
                    } elseif (!empty($item['product_id'])) {
                        $productUpdate = $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?');
                        $productUpdate->execute([$qty, (int) $item['product_id']]);
                    }
                }

                try {
                    $pdo->exec("ALTER TABLE orders ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL");
                } catch (Throwable $e) {
                    // ignore when column already exists
                }
                $update = $pdo->prepare("UPDATE orders SET status = 'cancelled_by_customer', updated_at = NOW() WHERE id = ?");
                $update->execute([$orderId]);
                $alerts['success'][] = 'Your order has been cancelled. We restocked the items to our inventory.';
            }
        }
        if ($transactionActive && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($transactionActive && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Unable to cancel customer order: ' . $exception->getMessage());
        $alerts['error'][] = 'We could not cancel that order. Please try again or contact support.';
    }
}

$orderIdColumn = ordersFindColumn($pdo, ['id', 'order_id']);
$customerIdColumn = ordersFindColumn($pdo, ['customer_id', 'customerId', 'customerID', 'customerid']);
$orderTotalColumn = ordersFindColumn($pdo, ['total', 'grand_total', 'amount', 'total_amount']);
$orderStatusColumn = ordersFindColumn($pdo, ['status', 'order_status']);
$orderTrackingColumn = ordersFindColumn($pdo, ['tracking_code', 'tracking_number', 'tracking_no']);
$orderCreatedAtColumn = ordersFindColumn($pdo, ['created_at', 'order_date', 'date_created', 'created']);
$orderCustomerNameColumn = ordersFindColumn($pdo, ['customer_name', 'name']);
$orderAddressColumn = ordersFindColumn($pdo, ['address', 'address_line1', 'address1', 'street']);
$orderPostalColumn = ordersFindColumn($pdo, ['postal_code', 'postal', 'zip_code', 'zipcode', 'zip']);
$orderCityColumn = ordersFindColumn($pdo, ['city', 'town', 'municipality']);
$orderEmailColumn = ordersFindColumn($pdo, ['email', 'email_address']);
$orderPhoneColumn = ordersFindColumn($pdo, ['phone', 'mobile', 'contact_number', 'contact']);
$orderFacebookColumn = ordersFindColumn($pdo, ['facebook_account', 'facebook', 'fb_account']);
$orderCustomerNoteColumn = ordersFindColumn($pdo, ['customer_note', 'notes', 'note']);
$orderReferenceColumn = ordersFindColumn($pdo, ['reference_no', 'reference_number', 'reference', 'ref_no']);
$orderProofColumn = ordersFindColumn($pdo, ['payment_proof', 'proof_of_payment', 'payment_proof_path', 'proof']);

$orderSelectParts = [];
$appendSelect = static function (?string $column, string $alias) use (&$orderSelectParts): void {
    if ($column === null) {
        return;
    }

    $orderSelectParts[] = '`' . $column . '` AS `' . $alias . '`';
};

if ($orderIdColumn === null) {
    $alerts['error'][] = 'We could not load your orders because the orders table is missing an ID column.';
} else {
    $appendSelect($orderIdColumn, 'id');
}

if ($orderTrackingColumn !== null) {
    $appendSelect($orderTrackingColumn, 'tracking_code');
}

if ($orderCreatedAtColumn !== null) {
    $appendSelect($orderCreatedAtColumn, 'created_at');
} else {
    $orderSelectParts[] = 'NULL AS `created_at`';
}

if ($orderTotalColumn !== null) {
    $appendSelect($orderTotalColumn, 'total');
} else {
    $orderSelectParts[] = '0 AS `total`';
}

if ($orderStatusColumn !== null) {
    $appendSelect($orderStatusColumn, 'status');
} else {
    $orderSelectParts[] = "'pending' AS `status`";
}

if ($orderCustomerNameColumn !== null) {
    $appendSelect($orderCustomerNameColumn, 'customer_name');
}

if ($orderAddressColumn !== null) {
    $appendSelect($orderAddressColumn, 'address');
}

if ($orderPostalColumn !== null) {
    $appendSelect($orderPostalColumn, 'postal_code');
}

if ($orderCityColumn !== null) {
    $appendSelect($orderCityColumn, 'city');
}

if ($orderEmailColumn !== null) {
    $appendSelect($orderEmailColumn, 'email');
}

if ($orderPhoneColumn !== null) {
    $appendSelect($orderPhoneColumn, 'phone');
}

if ($orderFacebookColumn !== null) {
    $appendSelect($orderFacebookColumn, 'facebook_account');
}

if ($orderCustomerNoteColumn !== null) {
    $appendSelect($orderCustomerNoteColumn, 'customer_note');
}

if ($orderReferenceColumn !== null) {
    $appendSelect($orderReferenceColumn, 'reference_no');
}

if ($orderProofColumn !== null) {
    $appendSelect($orderProofColumn, 'payment_proof');
}

$statusFilter = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';
$completedStatusFilters = ['complete', 'completed'];
$isCompletedFilter = in_array($statusFilter, $completedStatusFilters, true);
$orderWhereParts = [];
$orderParams = [];

if ($customerIdColumn === null) {
    $alerts['error'][] = 'We could not match orders to your account because the orders table does not track customer IDs.';
} else {
    $orderWhereParts[] = '`' . $customerIdColumn . '` = ?';
    $orderParams[] = (int) $customer['id'];
}

if ($isCompletedFilter) {
    if ($orderStatusColumn !== null) {
        $completedStatusValues = array_values(array_unique($completedStatusFilters));
        if (!empty($completedStatusValues)) {
            $placeholders = implode(', ', array_fill(0, count($completedStatusValues), '?'));
            $orderWhereParts[] = '`' . $orderStatusColumn . '` IN (' . $placeholders . ')';
            foreach ($completedStatusValues as $completedStatus) {
                $orderParams[] = $completedStatus;
            }
        }
    } else {
        $alerts['error'][] = 'Unable to filter by status because the orders table is missing a status column.';
    }
}

$orders = [];
if ($orderIdColumn !== null && $customerIdColumn !== null) {
    $orderSql = 'SELECT ' . implode(', ', $orderSelectParts) . ' FROM orders';
    if (!empty($orderWhereParts)) {
        $orderSql .= ' WHERE ' . implode(' AND ', $orderWhereParts);
    }
    $orderSql .= ' ORDER BY ' . ($orderCreatedAtColumn !== null ? '`' . $orderCreatedAtColumn . '`' : '`' . $orderIdColumn . '`') . ' DESC';

    $orderStmt = $pdo->prepare($orderSql);
    $orderStmt->execute($orderParams);
    $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
}

$orderItemsMap = [];
if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $selectParts = ['oi.*'];
    $joinClause = '';
    if (orderItemsColumnExists($pdo, 'product_id')) {
        $selectParts[] = 'p.name AS product_join_name';
        $joinClause = 'LEFT JOIN products p ON oi.product_id = p.id ';
    }

    $itemsSql = 'SELECT ' . implode(', ', $selectParts)
        . ' FROM order_items oi '
        . $joinClause
        . 'WHERE oi.order_id IN (' . $placeholders . ') '
        . 'ORDER BY oi.id';
    $itemStmt = $pdo->prepare($itemsSql);
    $itemStmt->execute($orderIds);

    while ($row = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId === 0) {
            continue;
        }
        if (!isset($orderItemsMap[$orderId])) {
            $orderItemsMap[$orderId] = [];
        }
        $nameCandidates = [
            trim((string) ($row['product_join_name'] ?? '')),
            trim((string) ($row['product_name'] ?? '')),
            trim((string) ($row['description'] ?? '')),
            trim((string) ($row['name'] ?? '')),
            trim((string) ($row['item_name'] ?? '')),
            trim((string) ($row['label'] ?? '')),
        ];
        $displayName = 'Item';
        foreach ($nameCandidates as $candidate) {
            if ($candidate !== '') {
                $displayName = $candidate;
                break;
            }
        }

        $variantLabel = trim((string) ($row['variant_label'] ?? $row['variant'] ?? ''));
        $quantity = (int) ($row['qty'] ?? $row['quantity'] ?? 0);
        if ($quantity <= 0) {
            $quantity = 1;
        }
        $price = $row['price'] ?? $row['unit_price'] ?? $row['amount'] ?? 0.0;
        $price = (float) $price;
        $orderItemsMap[$orderId][] = [
            'name' => $displayName,
            'variant' => $variantLabel,
            'quantity' => $quantity,
            'price' => $price,
        ];
    }
}

$statusLabels = [
    'pending' => 'Pending review',
    'payment_verification' => 'Awaiting payment verification',
    'approved' => 'Approved',
    'delivery' => 'Out for delivery',
    'complete' => 'Completed',
    'completed' => 'Completed',
    'cancelled_by_staff' => 'Cancelled by staff',
    'cancelled_by_customer' => 'Cancelled',
    'disapproved' => 'Disapproved',
];
?>
<!doctype html>
<html lang="en" data-customer-session="authenticated">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Orders - DGZ Motorshop</title>
    <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
</head>
<body class="customer-orders-page" data-customer-session="authenticated" data-customer-first-name="<?= htmlspecialchars($customerFirstName) ?>">
<header class="customer-orders-header">
    <div class="customer-orders-brand">
        <a href="<?= htmlspecialchars($homeUrl) ?>" class="customer-orders-logo">
            <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop logo">
        </a>
    </div>
    <div class="customer-orders-actions">
        <a href="<?= htmlspecialchars($homeUrl) ?>" class="customer-orders-continue">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            Continue Shopping
        </a>
        <a href="<?= htmlspecialchars($cartUrl) ?>" class="customer-orders-cart" id="cartButton">
            <i class="fas fa-shopping-cart" aria-hidden="true"></i>
            <span class="customer-orders-cart__label">Cart</span>
            <span class="customer-orders-cart__count" id="cartCount">0</span>
        </a>
        <div class="account-menu" data-account-menu>
            <button type="button" class="account-menu__trigger" data-account-trigger aria-haspopup="true" aria-expanded="false">
                <span class="account-menu__avatar" aria-hidden="true"><i class="fas fa-user-circle"></i></span>
                <span class="account-menu__label"><?= htmlspecialchars($customerFirstName) ?></span>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="account-menu__dropdown" data-account-dropdown hidden>
                <a href="<?= htmlspecialchars($myOrdersUrl) ?>" class="account-menu__link">My Orders</a>
                <a href="<?= htmlspecialchars($settingsUrl) ?>" class="account-menu__link">Settings</a>
                <a href="<?= htmlspecialchars($logoutUrl) ?>" class="account-menu__link">Logout</a>
            </div>
        </div>
    </div>
</header>
<main class="customer-orders-wrapper">
    <h1>My Orders</h1>
    <nav class="customer-orders-tabs" aria-label="Order filters">
        <a class="customer-orders-tab<?= $isCompletedFilter ? '' : ' is-active' ?>" href="<?= htmlspecialchars($myOrdersUrl) ?>">All</a>
        <a class="customer-orders-tab<?= $isCompletedFilter ? ' is-active' : '' ?>" href="<?= htmlspecialchars($myOrdersUrl) ?>?status=complete">Completed</a>
    </nav>
    <?php foreach ($alerts as $type => $messages): ?>
        <?php foreach ($messages as $message): ?>
            <div class="customer-orders-alert customer-orders-alert--<?= htmlspecialchars($type) ?>" role="alert"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if (empty($orders)): ?>
        <?php if ($isCompletedFilter): ?>
            <p class="customer-orders-empty">No completed orders yet. <a href="<?= htmlspecialchars($homeUrl) ?>">Start shopping</a></p>
        <?php else: ?>
            <p class="customer-orders-empty">You have not placed any orders yet. <a href="<?= htmlspecialchars($homeUrl) ?>">Start shopping</a>.</p>
        <?php endif; ?>
    <?php else: ?>
        <div class="customer-orders-list">
            <?php foreach ($orders as $order): ?>
                <?php
                    $orderDateRaw = $order['created_at'] ?? '';
                    $orderDateFormatted = $orderDateRaw;
                    try {
                        if ($orderDateRaw !== '') {
                            $orderDateFormatted = (new DateTime($orderDateRaw))->format('M d, Y g:i A');
                        }
                    } catch (Throwable $e) {
                        $orderDateFormatted = $orderDateRaw;
                    }
                ?>
                <?php
                    $orderId = (int) $order['id'];
                    $orderItems = $orderItemsMap[$orderId] ?? [];
                    $statusKey = strtolower((string) ($order['status'] ?? ''));
                    if ($statusKey === '') {
                        $statusKey = 'pending';
                    }
                    $statusLabel = $statusLabels[$statusKey] ?? ucwords(str_replace('_', ' ', $statusKey));
                    $nonCancellableStatuses = ['delivery', 'complete', 'completed', 'cancelled_by_staff', 'cancelled_by_customer', 'disapproved'];
                    $canCancel = !in_array($statusKey, $nonCancellableStatuses, true);

                    $contactEmail = trim((string) ($order['email'] ?? $order['customer_email'] ?? ''));
                    $contactPhone = trim((string) ($order['phone'] ?? $order['customer_phone'] ?? $order['contact'] ?? ''));
                    $facebookAccount = trim((string) ($order['facebook_account'] ?? $order['facebook'] ?? ''));
                    $referenceNumber = trim((string) ($order['reference_no'] ?? $order['reference_number'] ?? ''));
                    $paymentProofPath = trim((string) ($order['payment_proof'] ?? $order['proof_of_payment'] ?? ''));
                    $addressLine = trim((string) ($order['address'] ?? $order['customer_address'] ?? ''));
                    $postalCode = trim((string) ($order['postal_code'] ?? $order['postal'] ?? $order['zip_code'] ?? ''));
                    $city = trim((string) ($order['city'] ?? $order['town'] ?? $order['municipality'] ?? ''));
                    $billingLines = [];
                    if ($addressLine !== '') {
                        $billingLines[] = $addressLine;
                    }
                    $cityLineParts = array_filter([$city, $postalCode], static function ($value) {
                        return trim((string) $value) !== '';
                    });
                    if (!empty($cityLineParts)) {
                        $billingLines[] = implode(', ', array_map('trim', $cityLineParts));
                    }
                    $billingDisplay = implode("\n", $billingLines);
                    $customerNote = trim((string) ($order['customer_note'] ?? $order['notes'] ?? ''));
                    $paymentProofUrl = '';
                    if ($paymentProofPath !== '') {
                        $normalizedUrl = normalizePaymentProofPath($paymentProofPath);
                        if ($normalizedUrl !== '' && $normalizedUrl !== '/' && strncmp($normalizedUrl, '../', 3) !== 0) {
                            $paymentProofUrl = $normalizedUrl;
                        } else {
                            $candidatePaths = [$paymentProofPath];
                            $normalizedRelative = normalizePaymentProofPath($paymentProofPath, '');
                            if ($normalizedRelative !== '' && !in_array($normalizedRelative, $candidatePaths, true)) {
                                $candidatePaths[] = $normalizedRelative;
                            }

                            foreach ($candidatePaths as $candidatePath) {
                                if ($candidatePath === '') {
                                    continue;
                                }

                                if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $candidatePath) === 1 || strncmp($candidatePath, 'data:', 5) === 0) {
                                    $paymentProofUrl = $candidatePath;
                                    break;
                                }

                                $url = assetUrl($candidatePath);
                                if ($url !== '' && $url !== '/') {
                                    $paymentProofUrl = $url;
                                    break;
                                }

                                $prefixed = 'dgz_motorshop_system/' . ltrim($candidatePath, '/');
                                if ($prefixed !== $candidatePath) {
                                    $url = assetUrl($prefixed);
                                    if ($url !== '' && $url !== '/') {
                                        $paymentProofUrl = $url;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    $canUpdatePayment = $canCancel;
                ?>
                <article class="customer-order-card" data-order-card>
                    <header class="customer-order-card__header">
                        <div>
                            <h2>Order #<?= (int) $order['id'] ?></h2>
                            <?php $trackingCode = trim((string) ($order['tracking_code'] ?? $order['tracking_number'] ?? '')); ?>
                            <?php if ($trackingCode !== ''): ?>
                                <p class="customer-order-card__tracking">Tracking code: <?= htmlspecialchars($trackingCode) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="customer-order-card__meta">
                            <span class="customer-order-card__status customer-order-card__status--<?= htmlspecialchars($statusKey) ?>">
                                <?= htmlspecialchars($statusLabel) ?>
                            </span>
                            <button type="button" class="customer-order-card__toggle" data-order-toggle aria-expanded="false">
                                <span data-order-toggle-label>View order details</span>
                                <i class="fas fa-chevron-down" aria-hidden="true"></i>
                            </button>
                        </div>
                    </header>
                    <dl class="customer-order-card__summary">
                        <div>
                            <dt>Placed on</dt>
                            <dd><?= htmlspecialchars($orderDateFormatted) ?></dd>
                        </div>
                        <div>
                            <dt>Total</dt>
                            <dd>₱<?= number_format((float) $order['total'], 2) ?></dd>
                        </div>
                    </dl>
                    <div class="customer-order-card__details-panel" data-order-details hidden>
                        <div class="customer-order-card__items">
                            <h3>Items</h3>
                            <?php if (empty($orderItems)): ?>
                                <p class="customer-order-card__empty">No items were recorded for this order.</p>
                            <?php else: ?>
                                <?php foreach ($orderItems as $item): ?>
                                    <?php $lineTotal = max(0, (int) $item['quantity']) * (float) $item['price']; ?>
                                    <div class="customer-order-item">
                                        <div>
                                            <p class="customer-order-item__name"><?= htmlspecialchars($item['name']) ?></p>
                                            <?php if (($item['variant'] ?? '') !== ''): ?>
                                                <p class="customer-order-item__variant">Variant: <?= htmlspecialchars($item['variant']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="customer-order-item__meta">
                                            <span><?= (int) $item['quantity'] ?> × ₱<?= number_format((float) $item['price'], 2) ?></span>
                                            <span class="customer-order-item__total">₱<?= number_format($lineTotal, 2) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="customer-order-card__info-grid">
                            <?php if ($contactEmail !== '' || $contactPhone !== '' || $facebookAccount !== ''): ?>
                                <div class="customer-order-card__section">
                                    <h3>Contact details</h3>
                                    <dl>
                                        <?php if ($contactEmail !== ''): ?>
                                            <div><dt>Email</dt><dd><?= htmlspecialchars($contactEmail) ?></dd></div>
                                        <?php endif; ?>
                                        <?php if ($contactPhone !== ''): ?>
                                            <div><dt>Mobile</dt><dd><?= htmlspecialchars($contactPhone) ?></dd></div>
                                        <?php endif; ?>
                                        <?php if ($facebookAccount !== ''): ?>
                                            <div><dt>Facebook</dt><dd><?= htmlspecialchars($facebookAccount) ?></dd></div>
                                        <?php endif; ?>
                                    </dl>
                                </div>
                            <?php endif; ?>
                            <?php if ($billingDisplay !== ''): ?>
                                <div class="customer-order-card__section">
                                    <h3>Billing address</h3>
                                    <p><?= nl2br(htmlspecialchars($billingDisplay)) ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if ($customerNote !== ''): ?>
                                <div class="customer-order-card__section">
                                    <h3>Notes for the cashier</h3>
                                    <p><?= nl2br(htmlspecialchars($customerNote)) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php $hasStoredPaymentDetails = ($referenceNumber !== '' || $paymentProofUrl !== ''); ?>
                        <?php if ($hasStoredPaymentDetails || $canUpdatePayment): ?>
                            <div class="customer-order-card__section customer-order-card__section--payment">
                                <div class="customer-payment-card">
                                    <div class="customer-payment-card__intro">
                                        <div>
                                            <h3>Payment details</h3>
                                            <p class="customer-payment-card__subtitle">Add or update your payment reference so we can verify your order faster.</p>
                                        </div>
                                        <?php if ($paymentProofUrl !== ''): ?>
                                            <a class="customer-payment-card__view-proof" href="<?= htmlspecialchars($paymentProofUrl) ?>" target="_blank" rel="noopener">View proof</a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($hasStoredPaymentDetails): ?>
                                        <div class="customer-payment-card__stored">
                                            <?php if ($referenceNumber !== ''): ?>
                                                <div class="customer-payment-card__stored-item">
                                                    <span class="customer-payment-card__stored-label">Reference #</span>
                                                    <span class="customer-payment-card__stored-value"><?= htmlspecialchars($referenceNumber) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($paymentProofUrl !== ''): ?>
                                                <p class="customer-payment-card__note">Proof of payment uploaded.</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($canUpdatePayment): ?>
                                        <form method="post" enctype="multipart/form-data" class="customer-payment-card__form">
                                            <input type="hidden" name="payment_update_order_id" value="<?= (int) $order['id'] ?>">
                                            <div class="customer-payment-card__fields">
                                                <div class="customer-payment-card__field">
                                                    <label for="payment-reference-<?= (int) $order['id'] ?>">Reference number</label>
                                                    <input type="text" name="reference_number" id="payment-reference-<?= (int) $order['id'] ?>" maxlength="50" value="<?= htmlspecialchars($referenceNumber) ?>" placeholder="e.g. DGZ123456789">
                                                    <p class="customer-payment-card__help">Letters, numbers, spaces, and hyphens only.</p>
                                                </div>
                                                <div class="customer-payment-card__field">
                                                    <label for="payment-proof-<?= (int) $order['id'] ?>">Upload proof of payment</label>
                                                    <div class="customer-payment-card__file">
                                                        <input type="file" name="payment_proof" id="payment-proof-<?= (int) $order['id'] ?>" accept="image/*">
                                                    </div>
                                                    <p class="customer-payment-card__help">Accepted formats: JPG, PNG, GIF, or WEBP.</p>
                                                </div>
                                            </div>
                                            <div class="customer-payment-card__actions">
                                                <button type="submit" class="customer-order-card__button customer-payment-card__submit">Save payment details</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($canCancel): ?>
                            <div class="customer-order-card__footer">
                                <form method="post" class="customer-order-card__actions" data-customer-cancel-form>
                                    <input type="hidden" name="cancel_order_id" value="<?= (int) $order['id'] ?>">
                                    <button type="submit" class="customer-order-card__button customer-order-card__button--danger">Cancel order</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<script>
    window.dgzPaths = Object.assign({}, window.dgzPaths || {}, {
        checkout: <?= json_encode($cartUrl) ?>
    });
</script>
<script src="<?= htmlspecialchars($cartScript) ?>"></script>
<script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
</body>
</html>
