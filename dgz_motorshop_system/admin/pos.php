<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/email.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();
$notificationManageLink = 'inventory.php';

require_once __DIR__ . '/includes/inventory_notifications.php';
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

$allowedStatuses = ['pending', 'approved', 'completed'];

function ordersSupportsInvoiceNumbers(PDO $pdo): bool
{
    static $supports = null;

    if ($supports !== null) {
        return $supports;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'invoice_number'");
        $supports = $stmt !== false && $stmt->fetch() !== false;
    } catch (Throwable $e) {
        $supports = false;
        error_log('Unable to detect invoice_number column: ' . $e->getMessage());
    }

    return $supports;
}

/**
 * Generate a unique invoice number with an INV- prefix.
 */
function generateInvoiceNumber(PDO $pdo): string
{
    if (!ordersSupportsInvoiceNumbers($pdo)) {
        return '';
    }

    $prefix = 'INV-';

    $offset = strlen($prefix) + 1;
    $forUpdate = $pdo->inTransaction() ? ' FOR UPDATE' : '';

    try {
        $stmt = $pdo->prepare(
            "SELECT invoice_number\n"
            . "  FROM orders\n"
            . " WHERE invoice_number LIKE ?\n"
            . " ORDER BY CAST(SUBSTRING(invoice_number, {$offset}) AS UNSIGNED) DESC\n"
            . " LIMIT 1{$forUpdate}"
        );
        $stmt->execute([$prefix . '%']);
        $lastInvoice = $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Unable to fetch last invoice number: ' . $e->getMessage());
        $lastInvoice = null;
    }

    // ✅ fixed SQL
    try {
        $stmt = $pdo->query(
            "SELECT invoice_number\n"
            . "         FROM orders\n"
            . "         WHERE invoice_number IS NOT NULL\n"
            . "           AND invoice_number <> ''\n"
            . "         ORDER BY id DESC\n"
            . "         LIMIT 1"
        );
    } catch (Throwable $e) {
        error_log('Unable to fetch last invoice number: ' . $e->getMessage());
        $stmt = false;
    }

    $lastInvoice = $stmt ? $stmt->fetchColumn() : null;


    $nextNumber = 1;
    if (is_string($lastInvoice) && preg_match('/(\d+)/', $lastInvoice, $matches)) {
        $nextNumber = (int) $matches[1] + 1;
    }

    $checkStmt = null;
    try {
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE invoice_number = ?');
    } catch (Throwable $e) {
        error_log('Unable to prepare invoice uniqueness check: ' . $e->getMessage());
    }

    $counter = 0;
    do {
        $candidate = $prefix . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
        $exists = false;

        if ($checkStmt) {
            try {
                $checkStmt->execute([$candidate]);
                $exists = ((int) $checkStmt->fetchColumn()) > 0;
            } catch (Throwable $e) {
                error_log('Unable to verify invoice uniqueness: ' . $e->getMessage());
                $exists = false;
            }
        }

        if (!$exists) {
            return $candidate;
        }

        $nextNumber++;
        $counter++;
    } while ($counter < 25);

    try {
        $fallback = $prefix . strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $e) {
        error_log('Unable to generate random invoice number: ' . $e->getMessage());
        $fallback = $prefix . (string) time();
    }

    return $fallback;
}
//fixed approving status 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    $newStatus = isset($_POST['new_status']) ? strtolower(trim((string) $_POST['new_status'])) : '';

    $_SESSION['pos_active_tab'] = 'online';

    $statusParam = '0';
    $supportsInvoiceNumbers = ordersSupportsInvoiceNumbers($pdo);

    if ($orderId > 0 && in_array($newStatus, ['approved', 'completed'], true)) {
        $selectSql = $supportsInvoiceNumbers
            ? 'SELECT status, invoice_number FROM orders WHERE id = ?'
            : 'SELECT status FROM orders WHERE id = ?';
        $orderStmt = $pdo->prepare($selectSql);
        $orderStmt->execute([$orderId]);
        $currentOrder = $orderStmt->fetch();

        if ($currentOrder) {
            $currentStatus = strtolower((string) ($currentOrder['status'] ?? ''));
            if ($currentStatus === '') {
                $currentStatus = 'pending';
            }
            $transitions = [
                'pending' => ['approved', 'completed'],
                'approved' => ['completed'],
                'completed' => [],
            ];

            if (!array_key_exists($currentStatus, $transitions)) {
                $currentStatus = 'pending';
            }

            $allowedNext = $transitions[$currentStatus] ?? [];

            if ($newStatus === $currentStatus || in_array($newStatus, $allowedNext, true)) {
                $fields = [];
                $params = [];

                if ($newStatus !== $currentStatus) {
                    $fields[] = 'status = ?';
                    $params[] = $newStatus;
                }

                $existingInvoice = $supportsInvoiceNumbers
                    ? (string) ($currentOrder['invoice_number'] ?? '')
                    : '';
                $needsInvoice = $supportsInvoiceNumbers
                    && in_array($newStatus, ['approved', 'completed'], true)
                    && $existingInvoice === '';

                if ($needsInvoice) {
                    $generatedInvoice = generateInvoiceNumber($pdo);
                    if ($generatedInvoice !== '') {
                        $fields[] = 'invoice_number = ?';
                        $params[] = $generatedInvoice;
                    }
                }

                if (empty($fields)) {
                    // No state change required but treat as success.
                    $statusParam = '1';
                } else {
                    $params[] = $orderId;
                    $updateSql = 'UPDATE orders SET ' . implode(', ', $fields) . ' WHERE id = ?';
                    $stmt = $pdo->prepare($updateSql);
                    $success = $stmt->execute($params);
                    $statusParam = $success ? '1' : '0';

                    if ($success && $newStatus === 'approved') {
                        // Send email to customer with detailed order summary
                        $orderInfoStmt = $pdo->prepare(
                            'SELECT customer_name, email, phone, invoice_number, total, created_at FROM orders WHERE id = ? LIMIT 1'
                        );
                        $orderInfoStmt->execute([$orderId]);
                        $orderInfo = $orderInfoStmt->fetch();

                        $customerEmail = $orderInfo['email'] ?? '';
                        if ($customerEmail && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                            // Load order items
                            $itemsStmt = $pdo->prepare(
                                'SELECT oi.qty, oi.price, p.name AS product_name
                                 FROM order_items oi
                                 LEFT JOIN products p ON p.id = oi.product_id
                                 WHERE oi.order_id = ?'
                            );
                            $itemsStmt->execute([$orderId]);
                            $items = $itemsStmt->fetchAll() ?: [];

                            $itemsHtml = '';
                            $itemsTotal = 0.0;
                            foreach ($items as $it) {
                                $name = htmlspecialchars($it['product_name'] ?? 'Item', ENT_QUOTES, 'UTF-8');
                                $qty = (int) ($it['qty'] ?? 0);
                                $price = (float) ($it['price'] ?? 0);
                                $line = $qty * $price;
                                $itemsTotal += $line;
                                $itemsHtml .= sprintf(
                                    '<tr><td style="padding:6px 8px; border-bottom:1px solid #eee;">%s</td><td style="padding:6px 8px; text-align:center; border-bottom:1px solid #eee;">%d</td><td style="padding:6px 8px; text-align:right; border-bottom:1px solid #eee;">₱%s</td><td style="padding:6px 8px; text-align:right; border-bottom:1px solid #eee;">₱%s</td></tr>',
                                    $name,
                                    $qty,
                                    number_format($price, 2),
                                    number_format($line, 2)
                                );
                            }

                            $customerName = trim((string) ($orderInfo['customer_name'] ?? 'Customer'));
                            $invoiceNumber = trim((string) ($orderInfo['invoice_number'] ?? ''));
                            $createdAt = (string) ($orderInfo['created_at'] ?? '');
                            $orderTotal = (float) ($orderInfo['total'] ?? $itemsTotal);

                            $prettyDate = $createdAt !== '' ? date('F j, Y g:i A', strtotime($createdAt)) : date('F j, Y g:i A');
                            $displayInvoice = $invoiceNumber !== '' ? $invoiceNumber : 'INV-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);

                            $subject = 'Order Approved - DGZ Motorshop Invoice ' . $displayInvoice;

                            $body = '<div style="font-family: Arial, sans-serif; font-size:14px; color:#333;">'
                                . '<h2 style="color:#111; margin-bottom:8px;">Your Order is Approved</h2>'
                                . '<p style="margin:0 0 12px;">Hi ' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ',</p>'
                                . '<p style="margin:0 0 12px;">Good news! Your order #' . (int) $orderId . ' has been approved and is now being processed.</p>'
                                . '<p style="margin:0 0 12px;">Invoice Number: <strong>' . htmlspecialchars($displayInvoice, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                                . 'Order Date: ' . htmlspecialchars($prettyDate, ENT_QUOTES, 'UTF-8') . '</p>'
                                . '<h3 style="margin:16px 0 8px;">Order Summary</h3>'
                                . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #eee;">'
                                . '<thead>'
                                . '<tr style="background:#f9f9f9;">'
                                . '<th style="text-align:left; padding:8px; border-bottom:1px solid #eee;">Item</th>'
                                . '<th style="text-align:center; padding:8px; border-bottom:1px solid #eee;">Qty</th>'
                                . '<th style="text-align:right; padding:8px; border-bottom:1px solid #eee;">Price</th>'
                                . '<th style="text-align:right; padding:8px; border-bottom:1px solid #eee;">Subtotal</th>'
                                . '</tr>'
                                . '</thead>'
                                . '<tbody>' . $itemsHtml . '</tbody>'
                                . '<tfoot>'
                                . '<tr>'
                                . '<td colspan="3" style="padding:8px; text-align:right;"><strong>Total:</strong></td>'
                                . '<td style="padding:8px; text-align:right;"><strong>₱' . number_format($orderTotal, 2) . '</strong></td>'
                                . '</tr>'
                                . '</tfoot>'
                                . '</table>'
                                . '<p style="margin:16px 0 0;">Thank you for shopping with <strong>DGZ Motorshop</strong>!</p>'
                                . '</div>';

                            // Fire and forget email
                            try { sendEmail($customerEmail, $subject, $body); } catch (Throwable $e) { /* already logged in helper */ }
                        }
                    }
                }
            }
        }
    }

    header('Location: pos.php?' . http_build_query([
        'tab' => 'online',
        'status_updated' => $statusParam,
    ]));
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pos_checkout'])) {
    $productIds = isset($_POST['product_id']) ? (array) $_POST['product_id'] : [];
    $quantities = isset($_POST['qty']) ? (array) $_POST['qty'] : [];
    $amountPaid = isset($_POST['amount_paid']) ? (float) $_POST['amount_paid'] : 0.0;

    if (empty($productIds)) {
        $_SESSION['pos_active_tab'] = 'walkin';
        echo "<script>alert('No item selected in POS!'); window.location='pos.php';</script>";
        exit;
    }

    $cartItems = [];
    $salesTotal = 0.0;

    foreach ($productIds as $index => $rawProductId) {
        $productId = (int) $rawProductId;
        if ($productId <= 0) {
            continue;
        }

        $qty = isset($quantities[$index]) ? (int) $quantities[$index] : 0;
        if ($qty <= 0) {
            $qty = 1;
        }

        $productStmt = $pdo->prepare('SELECT id, name, price, quantity FROM products WHERE id = ?');
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch();

        if (!$product) {
            continue;
        }

        $availableQty = (int) $product['quantity'];
        if ($availableQty <= 0) {
            continue;
        }

        if ($qty > $availableQty) {
            $qty = $availableQty;
        }

        $lineTotal = (float) $product['price'] * $qty;
        $salesTotal += $lineTotal;

        $cartItems[] = [
            'id' => (int) $product['id'],
            'qty' => $qty,
            'price' => (float) $product['price'],
        ];
    }

    if (empty($cartItems)) {
        $_SESSION['pos_active_tab'] = 'walkin';
        echo "<script>alert('No item selected in POS!'); window.location='pos.php';</script>";
        exit;
    }

    if ($amountPaid < $salesTotal) {
        $_SESSION['pos_active_tab'] = 'walkin';
        echo "<script>alert('Insufficient payment amount!'); window.location='pos.php';</script>";
        exit;
    }

    $vatable = $salesTotal / 1.12;
    $vat = $salesTotal - $vatable;
    $change = $amountPaid - $salesTotal;

    try {
        $pdo->beginTransaction();

        $supportsInvoiceNumbers = ordersSupportsInvoiceNumbers($pdo);
        $invoiceNumber = $supportsInvoiceNumbers ? generateInvoiceNumber($pdo) : '';

        $orderColumns = [
            'customer_name',
            'address',
            'total',
            'payment_method',
            'status',
            'vatable',
            'vat',
            'amount_paid',
            'change_amount',
        ];

        $orderValues = [
            'Walk-in',
            'N/A',
            'N/A',
            $salesTotal,
            'Cash',
            'completed',
            $vatable,
            $vat,
            $amountPaid,
            $change,
        ];

        if ($supportsInvoiceNumbers) {
            $orderColumns[] = 'invoice_number';
            $orderValues[] = $invoiceNumber;
        }

        $placeholders = implode(', ', array_fill(0, count($orderColumns), '?'));
        $orderStmt = $pdo->prepare(
            'INSERT INTO orders (' . implode(', ', $orderColumns) . ') VALUES (' . $placeholders . ')'
        );
        $orderStmt->execute($orderValues);

        $orderId = (int) $pdo->lastInsertId();

        $itemInsertStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?,?,?,?)');
        $inventoryUpdateStmt = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?');

        foreach ($cartItems as $item) {
            $itemInsertStmt->execute([$orderId, $item['id'], $item['qty'], $item['price']]);
            $inventoryUpdateStmt->execute([$item['qty'], $item['id']]);
        }

        $pdo->commit();

        $params = [
            'ok' => 1,
            'order_id' => $orderId,
            'amount_paid' => number_format($amountPaid, 2, '.', ''),
            'change' => number_format($change, 2, '.', ''),
        ];

        if ($supportsInvoiceNumbers) {
            $params['invoice_number'] = $invoiceNumber;
        }

        header('Location: pos.php?' . http_build_query($params));
        exit;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        $_SESSION['pos_active_tab'] = 'walkin';
        $message = addslashes($exception->getMessage());
        echo "<script>alert('Error processing transaction: {$message}'); window.location='pos.php';</script>";
        exit;
    }
}

$productQuery = $pdo->query('SELECT id, name, price, quantity FROM products ORDER BY name');
$products = $productQuery->fetchAll();

$productCatalog = array_map(static function (array $product): array {
    return [
        'id' => (int) $product['id'],
        'name' => (string) $product['name'],
        'price' => (float) $product['price'],
        'quantity' => (int) $product['quantity'],
    ];
}, $products);

$allowedTabs = ['walkin', 'online'];
$activeTab = 'walkin';

if (!empty($_SESSION['pos_active_tab']) && in_array($_SESSION['pos_active_tab'], $allowedTabs, true)) {
    $activeTab = $_SESSION['pos_active_tab'];
    unset($_SESSION['pos_active_tab']);
} elseif (!empty($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true)) {
    $activeTab = $_GET['tab'];
}

// Pagination and filtering for online orders
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

$statusFilter = '';
if (isset($_GET['status_filter'])) {
    $tmp = strtolower(trim((string) $_GET['status_filter']));
    if (in_array($tmp, ['pending', 'approved', 'completed'], true)) {
        $statusFilter = $tmp;
    }
}

// Build the WHERE clause for online orders
$whereConditions = [
    "(payment_method IS NOT NULL AND payment_method <> '' AND LOWER(payment_method) = 'gcash')",
    "(payment_proof IS NOT NULL AND payment_proof <> '')",
    "status IN ('pending','approved')"
];
$whereClause = "(" . implode(" OR ", $whereConditions) . ")";

// Add status filter if specified
$params = [];
if ($statusFilter) {
    $whereClause .= " AND status = ?";
    $params[] = $statusFilter;
}

// Get total count for pagination
$countParams = $params;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE " . $whereClause);
$countStmt->execute($countParams);
$totalOrders = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalOrders / $perPage);

// Clamp current page to valid range
if ($totalPages > 0 && $page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Get orders for current page (inject safe integers for LIMIT/OFFSET)
$sqlOnlineOrders = "SELECT * FROM orders
     WHERE " . $whereClause . "
     ORDER BY created_at DESC
     LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
$onlineOrdersStmt = $pdo->prepare($sqlOnlineOrders);
$onlineOrdersStmt->execute($params);
$onlineOrders = $onlineOrdersStmt->fetchAll();

foreach ($onlineOrders as &$order) {
    $details = parsePaymentProofValue($order['payment_proof'] ?? null, $order['reference_no'] ?? null);
    $order['reference_number'] = $details['reference'];
    $order['proof_image'] = $details['image'];
    $order['status'] = strtolower((string) ($order['status'] ?? 'pending'));
}
unset($order);

$statusOptions = [
    'approved' => 'Approved',
    'completed' => 'Completed',
];

$productCatalogJson = json_encode($productCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($productCatalogJson === false) {
    $productCatalogJson = '[]';
}

$receiptData = null;
if (isset($_GET['ok'], $_GET['order_id']) && $_GET['ok'] === '1') {
    $requestedOrderId = (int) $_GET['order_id'];

    if ($requestedOrderId > 0) {
        $orderStmt = $pdo->prepare(
            'SELECT id, customer_name, total, vatable, vat, amount_paid, change_amount, created_at, invoice_number
             FROM orders
             WHERE id = ? LIMIT 1'
        );
        $orderStmt->execute([$requestedOrderId]);
        $orderRow = $orderStmt->fetch();

        if ($orderRow) {
            $itemsStmt = $pdo->prepare(
                'SELECT oi.product_id, oi.qty, oi.price, p.name
                 FROM order_items oi
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = ?'
            );
            $itemsStmt->execute([$requestedOrderId]);
            $itemRows = $itemsStmt->fetchAll();

            $items = array_map(static function (array $item): array {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    $name = 'Item #' . (int) ($item['product_id'] ?? 0);
                }

                $price = (float) ($item['price'] ?? 0);
                $qty = (int) ($item['qty'] ?? 0);

                return [
                    'name' => $name,
                    'price' => $price,
                    'qty' => $qty,
                    'total' => $price * $qty,
                ];
            }, $itemRows ?: []);

            $receiptData = [
                'order_id' => (int) $orderRow['id'],
                'customer_name' => (string) ($orderRow['customer_name'] ?? 'Customer'),
                'created_at' => (string) ($orderRow['created_at'] ?? ''),
                'invoice_number' => (string) ($orderRow['invoice_number'] ?? ''),
                'sales_total' => (float) ($orderRow['total'] ?? 0),
                'vatable' => (float) ($orderRow['vatable'] ?? 0),
                'vat' => (float) ($orderRow['vat'] ?? 0),
                'amount_paid' => (float) ($orderRow['amount_paid'] ?? 0),
                'change' => (float) ($orderRow['change_amount'] ?? 0),
                'discount' => 0.0,
                'cashier' => (string) ($_SESSION['username'] ?? 'Admin'),
                'items' => $items,
            ];
        }
    }
}

$receiptDataJson = $receiptData ? json_encode($receiptData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : 'null';
if ($receiptDataJson === false) {
    $receiptDataJson = 'null';
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <title>POS - DGZ</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/pos/pos.css">
</head>

<body>
    <?php
        $activePage = 'pos.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>

    <main class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>POS</h2>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/partials/notification_menu.php'; ?>
                <div class="user-menu">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <button type="button" class="dropdown-item" id="profileTrigger">
                            <i class="fas fa-user-cog"></i> Profile
                        </button>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <?php if ($role === 'admin'): ?>
                        <a href="userManagement.php" class="dropdown-item">
                            <i class="fas fa-users-cog"></i> User Management
                        </a>
                        <?php endif; ?>
                        <a href="login.php?logout=1" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="pos-tabs">
            <button type="button" class="pos-tab-button<?= $activeTab === 'walkin' ? ' active' : '' ?>" data-tab="walkin">
                <i class="fas fa-store"></i>
                POS Checkout
            </button>
            <button type="button" class="pos-tab-button<?= $activeTab === 'online' ? ' active' : '' ?>" data-tab="online">
                <i class="fas fa-shopping-bag"></i>
                Online Orders
            </button>
        </div>

        <div id="walkinTab" class="tab-panel<?= $activeTab === 'walkin' ? ' active' : '' ?>">
            <div class="walkin-actions">
                <button type="button" id="openProductModal" class="primary-button">
                    <i class="fas fa-search"></i> Search Product
                </button>
                 <div class="top-total-simple">
                    <span id="topTotalAmountSimple">₱0.00</span>
                </div>
            </div>

            <form method="post" id="posForm">
                <div class="pos-table-container">
                    <table id="posTable">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Available</th>
                                <th>Qty</th>
                            </tr>
                        </thead>
                        <tbody id="posTableBody"></tbody>
                    </table>
                    <div class="pos-empty-state" id="posEmptyState">
                        <i class="fas fa-shopping-cart"></i>
                        <p>No items in cart. Click "Search Product" to add items.</p>
                    </div>
                </div>

                <div id="totalsPanel" class="totals-panel">
                    <div class="totals-item">
                        <label>Sales Total</label>
                        <div id="salesTotalAmount" class="value">₱0.00</div>
                    </div>
                    <div class="totals-item">
                        <label>Discount</label>
                        <div id="discountAmount" class="value">₱0.00</div>
                    </div>
                    <div class="totals-item">
                        <label>Vatable</label>
                        <div id="vatableAmount" class="value">₱0.00</div>
                    </div>
                    <div class="totals-item">
                        <label>VAT (12%)</label>
                        <div id="vatAmount" class="value">₱0.00</div>
                    </div>
                    <div class="totals-item">
                        <label for="amountReceived">Amount Received</label>
                        <input type="number" id="amountReceived" name="amount_paid" min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="totals-item">
                        <label>Change</label>
                        <div id="changeAmount" class="value">₱0.00</div>
                    </div>
                </div>

                <div class="pos-actions">
                    <button type="button" id="clearPosTable" class="danger-button">Clear</button>
                    <button name="pos_checkout" id="settlePaymentButton" type="submit" class="success-button">Settle Payment (Complete)</button>
                </div>
            </form>
        </div>

        <div id="onlineTab" class="tab-panel<?= $activeTab === 'online' ? ' active' : '' ?>">
            <?php if (isset($_GET['status_updated'])): ?>
                <?php $success = $_GET['status_updated'] === '1'; ?>
                <div class="status-alert <?= $success ? 'success' : 'error' ?>">
                    <i class="fas <?= $success ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= $success ? 'Order status updated.' : 'Unable to update order status.' ?>
                </div>
            <?php endif; ?>

            <div class="online-orders-filters">
                <div class="filter-group">
                    <label for="statusFilter">Filter by Status:</label>
                    <select id="statusFilter" name="status_filter">
                        <option value="">All Orders</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
              
            </div>

            <div class="online-orders-container">
                <table class="online-orders-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Total</th>
                            <th>Reference</th>
                            <th>Proof</th>
                            <th>Status</th>
                            <th>Placed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($onlineOrders)): ?>
                            <tr>
                                <td colspan="8" class="empty-cell">
                                    <i class="fas fa-inbox"></i>
                                    No online orders yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($onlineOrders as $order): ?>
                                <?php
                                $imagePath = '';
                                if (!empty($order['proof_image'])) {
                                    $imagePath = '../' . ltrim($order['proof_image'], '/');
                                }
                                $statusValue = $order['status'] !== '' ? $order['status'] : 'pending';
                                $createdAt = !empty($order['created_at']) ? date('M d, Y g:i A', strtotime($order['created_at'])) : 'N/A';
                                $statusTransitions = [
                                    'pending' => ['approved', 'completed'],
                                    'approved' => ['completed'],
                                    'completed' => [],
                                ];
                                $availableStatusChanges = $statusTransitions[$statusValue] ?? [];
                                ?>
                                <tr class="online-order-row" data-order-id="<?= (int) $order['id'] ?>">
                                    <td>#<?= (int) $order['id'] ?></td>
                                    <td><?= htmlspecialchars($order['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?php 
                                        $display = '';
                                        if (!empty($order['email'])) { $display = $order['email']; }
                                        elseif (!empty($order['phone'])) { $display = $order['phone']; }
                                        echo htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
                                    ?></td>
                                    <td>₱<?= number_format((float) $order['total'], 2) ?></td>
                                    <td>
                                        <?php if (!empty($order['reference_number'])): ?>
                                            <span class="reference-badge"><?= htmlspecialchars($order['reference_number'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="muted">Not provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="view-proof-btn"
                                            data-image="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>"
                                            data-reference="<?= htmlspecialchars($order['reference_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-customer="<?= htmlspecialchars($order['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="fas fa-receipt"></i> View
                                        </button>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst($statusValue), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <form method="post" class="status-form">
                                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                            <input type="hidden" name="update_order_status" value="1">
                                            <select name="new_status" <?= empty($availableStatusChanges) ? 'disabled' : '' ?>>
                                                <?php if (empty($availableStatusChanges)): ?>
                                                    <option value="">Completed</option>
                                                <?php else: ?>
                                                    <?php foreach ($availableStatusChanges as $value): ?>
                                                        <option value="<?= $value ?>"><?= $statusOptions[$value] ?></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                            <button type="submit" class="status-save" <?= empty($availableStatusChanges) ? 'disabled' : '' ?>>Update</button>
                                        </form>
                                    </td>
                                    <td><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="pagination-container">
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?tab=online&page=<?= $page - 1 ?><?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" class="pagination-btn">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if ($startPage > 1): ?>
                        <a href="?tab=online&page=1<?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" class="pagination-btn">1</a>
                        <?php if ($startPage > 2): ?>
                            <span class="pagination-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="?tab=online&page=<?= $i ?><?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" 
                           class="pagination-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="pagination-ellipsis">...</span>
                        <?php endif; ?>
                        <a href="?tab=online&page=<?= $totalPages ?><?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" class="pagination-btn"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?tab=online&page=<?= $page + 1 ?><?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" class="pagination-btn">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                          <div class="orders-info">
                    <span>Showing <?= count($onlineOrders) ?> of <?= $totalOrders ?> orders</span>
                </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php endif; ?>
        </div>
    </main>

    <!-- Online order details modal (POS online orders detail feature) -->
    <div id="onlineOrderModal" class="modal-overlay" style="display:none;">
        <div class="modal-content transaction-modal">
            <div class="modal-header">
                <h3>Transaction Details</h3>
                <button type="button" class="modal-close" id="closeOnlineOrderModal" aria-label="Close order details">&times;</button>
            </div>
            <div class="modal-body">
                <div class="transaction-info">
                    <h4>Order Information</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Customer:</label>
                            <span id="onlineOrderCustomer">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Invoice #:</label>
                            <span id="onlineOrderInvoice">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Date:</label>
                            <span id="onlineOrderDate">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Status:</label>
                            <span id="onlineOrderStatus">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Payment Method:</label>
                            <span id="onlineOrderPayment">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Email:</label>
                            <span id="onlineOrderEmail"></span>
                        </div>
                        <div class="info-item">
                            <label>Phone:</label>
                            <span id="onlineOrderPhone"></span>
                        </div>
                        <div class="info-item" id="onlineOrderReferenceWrapper" style="display:none;">
                            <label>Reference:</label>
                            <span id="onlineOrderReference"></span>
                        </div>
                    </div>
                </div>
                <div class="order-items">
                    <h4>Order Items</h4>
                    <div class="table-responsive">
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Price</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody id="onlineOrderItemsBody"></tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" style="text-align: right;"><strong>Total:</strong></td>
                                    <td id="onlineOrderTotal">₱0.00</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('mobile-open');
    }

    function openProfileModal() {
        if (!document.getElementById('profileModal')) {
            return;
        }

        const profileModal = document.getElementById('profileModal');
        profileModal.classList.add('show');
        profileModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }

    function closeProfileModal() {
        if (!document.getElementById('profileModal')) {
            return;
        }

        const profileModal = document.getElementById('profileModal');
        profileModal.classList.remove('show');
        profileModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }

    
</script>
    <!-- Profile modal -->
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

    <div id="productModal" class="modal-overlay" style="display:none;">
        <div class="modal-content large-modal">
            <button id="closeProductModal" type="button" class="modal-close">&times;</button>
            <h3>Search Product</h3>
            <input type="text" id="productSearchInput" placeholder="Type product name...">
            <div class="modal-table-wrapper">
                <table id="productSearchTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Select</th>
                        </tr>
                    </thead>
                    <tbody id="productSearchTableBody"></tbody>
                </table>
            </div>
            <button id="addSelectedProducts" type="button" class="primary-button full-width">Add</button>
        </div>
    </div>

    <div id="receiptModal" class="modal-overlay" style="display:none;">
        <div class="modal-content receipt-modal">
            <button id="closeReceiptModal" type="button" class="modal-close">&times;</button>
            <div id="receiptContent" class="receipt-content">
                <div class="receipt-header">
                    <h2>DGZ Motorshop</h2>
                    <p>123 Main Street</p>
                    <p>Phone: (123) 456-7890</p>
                    <p>Receipt #: <span id="receiptNumber"></span></p>
                    <p>Date: <span id="receiptDate"></span></p>
                    <p>Cashier: <span id="receiptCashier"></span></p>
                </div>
                <div class="receipt-body">
                    <table id="receiptItems">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Item</th>
                                <th style="text-align:right;">Qty</th>
                                <th style="text-align:right;">Price</th>
                                <th style="text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="receiptItemsBody"></tbody>
                    </table>
                </div>
                <div class="receipt-totals">
                    <div><span>Sales Total:</span> <span id="receiptSalesTotal">₱0.00</span></div>
                    <div><span>Discount:</span> <span id="receiptDiscount">₱0.00</span></div>
                    <div><span>Vatable:</span> <span id="receiptVatable">₱0.00</span></div>
                    <div><span>VAT (12%):</span> <span id="receiptVat">₱0.00</span></div>
                    <div><span>Amount Paid:</span> <span id="receiptAmountPaid">₱0.00</span></div>
                    <div><span>Change:</span> <span id="receiptChange">₱0.00</span></div>
                </div>
                <div class="receipt-footer">
                    <p>Thank you for shopping!</p>
                    <p>Please come again.</p>
                </div>
            </div>
            <div class="receipt-actions">
                <button type="button" id="printReceiptButton" class="primary-button">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>

    <div id="proofModal" class="proof-modal" aria-hidden="true">
        <div class="proof-modal-content">
            <button type="button" class="proof-close" id="closeProofModal">&times;</button>
            <h3 class="proof-title">Payment Proof</h3>
            <p class="proof-reference">Reference: <span id="proofReferenceValue">Not provided</span></p>
            <p class="proof-customer">Customer: <span id="proofCustomerName">N/A</span></p>
            <div class="proof-image-wrapper">
                <img id="proofImage" src="" alt="Payment proof preview" />
                <div id="proofNoImage" class="proof-empty">No proof uploaded.</div>
            </div>
        </div>
    </div>

    <script>
        const productCatalog = <?= $productCatalogJson ?>;
        const initialActiveTab = <?= json_encode($activeTab) ?>;
        const checkoutReceipt = <?= $receiptDataJson ?>;

        document.addEventListener('DOMContentLoaded', () => {
            const posStateKey = 'posTable';
            const tabStateKey = 'posActiveTab';
            let hasShownCheckoutAlert = false;

            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.querySelector('.mobile-toggle');
            const userMenu = document.querySelector('.user-menu');
            const userAvatar = document.querySelector('.user-avatar');
            const userDropdown = document.getElementById('userDropdown');

            const posForm = document.getElementById('posForm');
            const posTableBody = document.getElementById('posTableBody');
            const posEmptyState = document.getElementById('posEmptyState');
            const amountReceivedInput = document.getElementById('amountReceived');
            const settlePaymentButton = document.getElementById('settlePaymentButton');
            const profileButton = document.getElementById('profileTrigger');
            const profileModal = document.getElementById('profileModal');
            const profileModalClose = document.getElementById('profileModalClose');

            const totals = {
                sales: document.getElementById('salesTotalAmount'),
                discount: document.getElementById('discountAmount'),
                vatable: document.getElementById('vatableAmount'),
                vat: document.getElementById('vatAmount'),
                topTotal: document.getElementById('topTotalAmountSimple'),
                change: document.getElementById('changeAmount'),
            };

            const clearPosTableButton = document.getElementById('clearPosTable');
            const openProductModalButton = document.getElementById('openProductModal');
            const productModal = document.getElementById('productModal');
            const closeProductModalButton = document.getElementById('closeProductModal');
            const productSearchInput = document.getElementById('productSearchInput');
            const productSearchTableBody = document.getElementById('productSearchTableBody');
            const addSelectedProductsButton = document.getElementById('addSelectedProducts');

            const receiptModal = document.getElementById('receiptModal');
            const closeReceiptModalButton = document.getElementById('closeReceiptModal');
            const printReceiptButton = document.getElementById('printReceiptButton');
            const receiptItemsBody = document.getElementById('receiptItemsBody');

            const proofModal = document.getElementById('proofModal');
            const proofImage = document.getElementById('proofImage');
            const proofReferenceValue = document.getElementById('proofReferenceValue');
            const proofCustomerName = document.getElementById('proofCustomerName');
            const proofNoImage = document.getElementById('proofNoImage');
            const closeProofModalButton = document.getElementById('closeProofModal');

            const onlineOrderModal = document.getElementById('onlineOrderModal');
            const closeOnlineOrderModalButton = document.getElementById('closeOnlineOrderModal');
            const onlineOrderCustomer = document.getElementById('onlineOrderCustomer');
            const onlineOrderInvoice = document.getElementById('onlineOrderInvoice');
            const onlineOrderDate = document.getElementById('onlineOrderDate');
            const onlineOrderStatus = document.getElementById('onlineOrderStatus');
            const onlineOrderPayment = document.getElementById('onlineOrderPayment');
            const onlineOrderEmail = document.getElementById('onlineOrderEmail');
            const onlineOrderPhone = document.getElementById('onlineOrderPhone');
            const onlineOrderReferenceWrapper = document.getElementById('onlineOrderReferenceWrapper');
            const onlineOrderReference = document.getElementById('onlineOrderReference');
            const onlineOrderItemsBody = document.getElementById('onlineOrderItemsBody');
            const onlineOrderTotal = document.getElementById('onlineOrderTotal');

            const tabButtons = document.querySelectorAll('.pos-tab-button');
            const tabPanels = {
                walkin: document.getElementById('walkinTab'),
                online: document.getElementById('onlineTab'),
            };

            const openProfileModal = () => {
                if (!profileModal) {
                    return;
                }

                profileModal.classList.add('show');
                profileModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
            };

            const closeProfileModal = () => {
                if (!profileModal) {
                    return;
                }

                profileModal.classList.remove('show');
                profileModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
            };

            // ===== Online order details modal functionality =====
            const openOnlineOrderModalOverlay = () => {
                if (!onlineOrderModal) {
                    return;
                }

                onlineOrderModal.style.display = 'flex';
                document.body.classList.add('modal-open');
            };

            const closeOnlineOrderModalOverlay = () => {
                if (!onlineOrderModal) {
                    return;
                }

                onlineOrderModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            };

            const populateOnlineOrderModal = (order, items) => {
                if (!onlineOrderModal) {
                    return;
                }

                const safeCustomer = (order.customer_name || 'Customer').toString();
                const safeInvoice = (order.invoice_number || 'N/A').toString();
                const safeStatus = (order.status || 'pending').toString().toLowerCase();
                const safePayment = (order.payment_method || 'N/A').toString();
                const referenceNumber = (order.reference_number || '').toString();

                onlineOrderCustomer.textContent = safeCustomer;
                onlineOrderInvoice.textContent = safeInvoice !== '' ? safeInvoice : 'N/A';

                if (order.created_at) {
                    const createdDate = new Date(order.created_at);
                    onlineOrderDate.textContent = Number.isNaN(createdDate.getTime())
                        ? order.created_at
                        : createdDate.toLocaleString();
                } else {
                    onlineOrderDate.textContent = 'N/A';
                }

                const capitalisedStatus = safeStatus.charAt(0).toUpperCase() + safeStatus.slice(1);
                onlineOrderStatus.textContent = capitalisedStatus;
                onlineOrderPayment.textContent = safePayment !== '' ? safePayment : 'N/A';
                onlineOrderEmail.textContent = (order.email || '').toString();
                onlineOrderPhone.textContent = (order.phone || '').toString();

                if (referenceNumber && safePayment.toLowerCase() === 'gcash') {
                    onlineOrderReferenceWrapper.style.display = 'flex';
                    onlineOrderReference.textContent = referenceNumber;
                } else {
                    onlineOrderReferenceWrapper.style.display = 'none';
                    onlineOrderReference.textContent = '';
                }

                while (onlineOrderItemsBody.firstChild) {
                    onlineOrderItemsBody.removeChild(onlineOrderItemsBody.firstChild);
                }

                if (!Array.isArray(items) || items.length === 0) {
                    const emptyRow = document.createElement('tr');
                    const emptyCell = document.createElement('td');
                    emptyCell.colSpan = 4;
                    emptyCell.textContent = 'No items found for this order.';
                    emptyCell.style.textAlign = 'center';
                    emptyCell.style.color = '#6b7280';
                    emptyCell.style.padding = '12px';
                    emptyRow.appendChild(emptyCell);
                    onlineOrderItemsBody.appendChild(emptyRow);
                } else {
                    items.forEach((item) => {
                        const qty = Number(item.qty) || 0;
                        const price = Number(item.price) || 0;
                        const subtotal = qty * price;

                        const row = document.createElement('tr');

                        const nameCell = document.createElement('td');
                        nameCell.textContent = (item.name || `Item #${item.product_id || ''}`).toString();
                        row.appendChild(nameCell);

                        const qtyCell = document.createElement('td');
                        qtyCell.textContent = qty.toString();
                        row.appendChild(qtyCell);

                        const priceCell = document.createElement('td');
                        priceCell.textContent = formatPeso(price);
                        row.appendChild(priceCell);

                        const subtotalCell = document.createElement('td');
                        subtotalCell.textContent = formatPeso(subtotal);
                        row.appendChild(subtotalCell);

                        onlineOrderItemsBody.appendChild(row);
                    });
                }

                const totalAmount = Number(order.total) || 0;
                onlineOrderTotal.textContent = formatPeso(totalAmount);
            };

            function formatPeso(value) {
                const amount = Number(value) || 0;
                return '₱' + amount.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            }

            function updateEmptyState() {
                const hasRows = posTableBody.querySelector('tr') !== null;
                posEmptyState.style.display = hasRows ? 'none' : 'flex';
            }

            function getSalesTotal() {
                let subtotal = 0;
                posTableBody.querySelectorAll('tr').forEach((row) => {
                    const price = parseFloat(row.querySelector('.pos-price').dataset.rawPrice || '0');
                    const qty = parseInt(row.querySelector('.pos-qty').value, 10) || 0;
                    subtotal += price * qty;
                });
                return subtotal;
            }

            function updateSettleButtonState() {
                if (!settlePaymentButton) {
                    return;
                }

                const hasRows = posTableBody.querySelector('tr') !== null;
                const amountReceived = parseFloat(amountReceivedInput?.value || '0');
                const shouldEnable = hasRows && amountReceived > 0;

                settlePaymentButton.disabled = !shouldEnable;
            }

            function recalcTotals() {
                const salesTotal = getSalesTotal();
                const discount = 0;
                const vatable = salesTotal / 1.12;
                const vat = salesTotal - vatable;
                const amountReceived = parseFloat(amountReceivedInput.value || '0');
                const change = amountReceived - salesTotal;

                totals.sales.textContent = formatPeso(salesTotal);
                totals.discount.textContent = formatPeso(discount);
                totals.vatable.textContent = formatPeso(vatable);
                totals.vat.textContent = formatPeso(vat);
                totals.topTotal.textContent = formatPeso(salesTotal);
                totals.change.textContent = formatPeso(change);

                if (salesTotal > 0 && amountReceived < salesTotal) {
                    totals.change.style.color = '#e74c3c';
                    totals.change.title = 'Insufficient payment';
                } else if (salesTotal > 0) {
                    totals.change.style.color = '#27ae60';
                    totals.change.title = '';
                } else {
                    totals.change.style.color = '';
                    totals.change.title = '';
                }

                updateSettleButtonState();
            }

            function persistTableState() {
                const rows = [];
                posTableBody.querySelectorAll('tr').forEach((row) => {
                    rows.push({
                        id: row.dataset.productId,
                        name: row.querySelector('.pos-name').textContent,
                        price: parseFloat(row.querySelector('.pos-price').dataset.rawPrice || '0'),
                        available: parseInt(row.querySelector('.pos-available').textContent, 10) || 0,
                        qty: parseInt(row.querySelector('.pos-qty').value, 10) || 1,
                    });
                });

                try {
                    if (rows.length > 0) {
                        localStorage.setItem(posStateKey, JSON.stringify(rows));
                    } else {
                        localStorage.removeItem(posStateKey);
                    }
                } catch (error) {
                    console.error('Unable to persist POS table state.', error);
                }
            }

            function clearTable() {
                posTableBody.innerHTML = '';
                updateEmptyState();
                if (amountReceivedInput) {
                    amountReceivedInput.value = '';
                }
                recalcTotals();
                try {
                    localStorage.removeItem(posStateKey);
                } catch (error) {
                    console.error('Unable to clear POS state.', error);
                }
            }

            function createRow(item) {
                if (posTableBody.querySelector(`[data-product-id="${item.id}"]`)) {
                    return;
                }

                const tr = document.createElement('tr');
                tr.dataset.productId = String(item.id);

                const nameCell = document.createElement('td');
                nameCell.className = 'pos-name';
                nameCell.textContent = item.name;
                tr.appendChild(nameCell);

                const priceCell = document.createElement('td');
                priceCell.className = 'pos-price';
                priceCell.dataset.rawPrice = String(item.price);
                priceCell.textContent = formatPeso(item.price);
                tr.appendChild(priceCell);

                const availableCell = document.createElement('td');
                availableCell.className = 'pos-available';
                availableCell.textContent = Number.isFinite(item.available) ? item.available : 0;
                tr.appendChild(availableCell);

                const qtyCell = document.createElement('td');
                qtyCell.className = 'pos-actions';

                const productInput = document.createElement('input');
                productInput.type = 'hidden';
                productInput.name = 'product_id[]';
                productInput.value = item.id;
                qtyCell.appendChild(productInput);

                const qtyInput = document.createElement('input');
                qtyInput.type = 'number';
                qtyInput.className = 'pos-qty';
                qtyInput.name = 'qty[]';
                qtyInput.min = '1';
                if (Number.isFinite(item.max) && item.max > 0) {
                    qtyInput.max = String(item.max);
                }
                qtyInput.value = Math.max(1, item.qty || 1);
                qtyCell.appendChild(qtyInput);

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'remove-btn';
                removeButton.setAttribute('aria-label', 'Remove item');
                removeButton.innerHTML = "<i class='fas fa-times'></i>";
                qtyCell.appendChild(removeButton);

                tr.appendChild(qtyCell);
                posTableBody.appendChild(tr);
            }

            function addProductById(productId) {
                const product = productCatalog.find((item) => String(item.id) === String(productId));
                if (!product) {
                    return;
                }

                const availableQty = Number(product.quantity) || 0;
                if (availableQty <= 0) {
                    alert(`${product.name} is out of stock and cannot be added.`);
                    return;
                }

                const existingRow = posTableBody.querySelector(`[data-product-id="${product.id}"]`);
                if (existingRow) {
                    const qtyInput = existingRow.querySelector('.pos-qty');
                    const currentQty = parseInt(qtyInput.value, 10) || 0;
                    const maxQty = Math.max(parseInt(qtyInput.max, 10) || 0, availableQty);
                    const newQty = Math.min(currentQty + 1, maxQty);
                    qtyInput.max = String(maxQty);
                    existingRow.querySelector('.pos-available').textContent = availableQty;
                    qtyInput.value = newQty;
                } else {
                    createRow({
                        id: product.id,
                        name: product.name,
                        price: Number(product.price) || 0,
                        available: availableQty,
                        qty: 1,
                        max: availableQty,
                    });
                }

                updateEmptyState();
                recalcTotals();
                persistTableState();
            }

            function restoreTableState() {
                let data = [];
                try {
                    data = JSON.parse(localStorage.getItem(posStateKey) || '[]');
                } catch (error) {
                    data = [];
                }

                data.forEach((item) => {
                    const product = productCatalog.find((productItem) => String(productItem.id) === String(item.id));
                    const availableQty = product ? Number(product.quantity) : Number(item.available);
                    createRow({
                        id: item.id,
                        name: product ? product.name : item.name,
                        price: product ? Number(product.price) : Number(item.price),
                        available: Number.isFinite(availableQty) ? availableQty : 0,
                        qty: Number(item.qty) || 1,
                        max: Math.max(Number(item.qty) || 1, Number.isFinite(availableQty) ? availableQty : 0),
                    });
                });

                updateEmptyState();
                recalcTotals();
            }

            function openProductModal() {
                productModal.style.display = 'flex';
                productSearchInput.value = '';
                renderProductTable();
                productSearchInput.focus();
            }

            function closeProductModal() {
                productModal.style.display = 'none';
            }

            function renderProductTable(filter = '') {
                if (!productSearchTableBody) {
                    return;
                }

                const normalisedFilter = filter.toLowerCase();
                const filteredProducts = normalisedFilter
                    ? productCatalog.filter((item) => item.name.toLowerCase().includes(normalisedFilter))
                    : productCatalog;

                productSearchTableBody.innerHTML = '';

                if (filteredProducts.length === 0) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 4;
                    cell.textContent = 'No products found.';
                    cell.style.textAlign = 'center';
                    cell.style.color = '#888';
                    cell.style.padding = '16px';
                    row.appendChild(cell);
                    productSearchTableBody.appendChild(row);
                    return;
                }

                filteredProducts.forEach((product) => {
                    const row = document.createElement('tr');

                    const nameCell = document.createElement('td');
                    nameCell.textContent = product.name;
                    row.appendChild(nameCell);

                    const priceCell = document.createElement('td');
                    priceCell.textContent = formatPeso(product.price);
                    priceCell.style.textAlign = 'right';
                    row.appendChild(priceCell);

                    const stockCell = document.createElement('td');
                    stockCell.textContent = product.quantity;
                    stockCell.style.textAlign = 'center';
                    row.appendChild(stockCell);

                    const actionCell = document.createElement('td');
                    actionCell.style.textAlign = 'center';

                    if (Number(product.quantity) > 0) {
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.className = 'product-select-checkbox';
                        checkbox.dataset.id = product.id;
                        actionCell.appendChild(checkbox);
                    } else {
                        const span = document.createElement('span');
                        span.textContent = 'Out of Stock';
                        span.style.color = '#e74c3c';
                        span.style.fontSize = '13px';
                        actionCell.appendChild(span);
                    }

                    row.appendChild(actionCell);
                    productSearchTableBody.appendChild(row);
                });
            }

            function setActiveTab(tabName, options = {}) {
                if (!['walkin', 'online'].includes(tabName)) {
                    return;
                }

                const { skipPersistence = false } = options;

                tabButtons.forEach((button) => {
                    button.classList.toggle('active', button.dataset.tab === tabName);
                });

                Object.entries(tabPanels).forEach(([name, panel]) => {
                    panel.classList.toggle('active', name === tabName);
                });

                if (!skipPersistence) {
                    try {
                        localStorage.setItem(tabStateKey, tabName);
                    } catch (error) {
                        console.error('Unable to persist POS tab state.', error);
                    }

                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', tabName);
                    window.history.replaceState({}, document.title, url.toString());
                }
            }

            function initialiseActiveTab() {
                const url = new URL(window.location.href);
                const paramTab = url.searchParams.get('tab');
                if (['walkin', 'online'].includes(paramTab)) {
                    setActiveTab(paramTab);
                    return;
                }

                let storedTab = null;
                try {
                    storedTab = localStorage.getItem(tabStateKey);
                } catch (error) {
                    storedTab = null;
                }

                if (['walkin', 'online'].includes(storedTab)) {
                    setActiveTab(storedTab, { skipPersistence: true });
                } else {
                    setActiveTab(initialActiveTab, { skipPersistence: true });
                }
            }

            function cleanupSuccessParams() {
                const url = new URL(window.location.href);
                ['ok', 'order_id', 'amount_paid', 'change', 'invoice_number'].forEach((param) => url.searchParams.delete(param));
                window.history.replaceState({}, document.title, url.toString());
            }

            function generateReceiptFromTransaction() {
                const params = new URLSearchParams(window.location.search);
                if (params.get('ok') !== '1') {
                    return;
                }

                const hasServerReceipt = Boolean(
                    checkoutReceipt &&
                    Array.isArray(checkoutReceipt.items) &&
                    checkoutReceipt.items.length > 0
                );

                let items = [];
                let salesTotal = 0;
                let discount = 0;
                let vatable = 0;
                let vat = 0;
                let amountPaid = 0;
                let change = 0;
                let cashierName = 'Admin';
                let createdAt = new Date();
                let orderId = params.get('order_id') || '';
                let invoiceNumber = params.get('invoice_number') || '';

                if (hasServerReceipt) {
                    items = checkoutReceipt.items.map((item) => {
                        const price = Number(item.price) || 0;
                        const qty = Number(item.qty) || 0;
                        const total = Number(item.total);
                        return {
                            name: String(item.name || 'Item'),
                            price,
                            qty,
                            total: Number.isFinite(total) ? total : price * qty,
                        };
                    });

                    salesTotal = Number(checkoutReceipt.sales_total) || items.reduce((sum, item) => sum + item.total, 0);
                    discount = Number(checkoutReceipt.discount) || 0;
                    vatable = Number(checkoutReceipt.vatable);
                    vat = Number(checkoutReceipt.vat);
                    amountPaid = Number(checkoutReceipt.amount_paid) || parseFloat(params.get('amount_paid') || '0');
                    change = Number(checkoutReceipt.change) || parseFloat(params.get('change') || '0');
                    cashierName = checkoutReceipt.cashier || cashierName;

                    if (checkoutReceipt.order_id) {
                        orderId = checkoutReceipt.order_id;
                    }

                     if (checkoutReceipt.invoice_number) {
                        invoiceNumber = checkoutReceipt.invoice_number;
                    }

                    if (checkoutReceipt.created_at) {
                        const parsedDate = new Date(checkoutReceipt.created_at);
                        if (!Number.isNaN(parsedDate.getTime())) {
                            createdAt = parsedDate;
                        }
                    }

                    if (!Number.isFinite(vatable) || vatable <= 0) {
                        vatable = salesTotal / 1.12;
                    }

                    if (!Number.isFinite(vat) || vat < 0) {
                        vat = salesTotal - vatable;
                    }
                } else {
                    let savedRows = [];
                    try {
                        savedRows = JSON.parse(localStorage.getItem(posStateKey) || '[]');
                    } catch (error) {
                        savedRows = [];
                    }

                    if (savedRows.length === 0) {
                        cleanupSuccessParams();
                        return;
                    }

                    items = savedRows.map((item) => {
                        const price = Number(item.price) || 0;
                        const qty = Number(item.qty) || 0;
                        return {
                            name: String(item.name || 'Item'),
                            price,
                            qty,
                            total: price * qty,
                        };
                    });

                    salesTotal = items.reduce((sum, item) => sum + item.total, 0);
                    amountPaid = parseFloat(params.get('amount_paid') || '0');
                    change = parseFloat(params.get('change') || '0');
                    vatable = salesTotal / 1.12;
                    vat = salesTotal - vatable;
                    cashierName = 'Admin';
                    createdAt = new Date();
                }

                receiptItemsBody.innerHTML = '';
                items.forEach((item) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td style="text-align:left;">${item.name}</td>
                        <td style="text-align:right;">${item.qty}</td>
                        <td style="text-align:right;">${formatPeso(item.price)}</td>
                        <td style="text-align:right;">${formatPeso(item.total)}</td>
                    `;
                    receiptItemsBody.appendChild(row);
                });

                if (!invoiceNumber) {
                    invoiceNumber = orderId ? `INV-${orderId}` : `INV-${Date.now()}`;
                }

                document.getElementById('receiptNumber').textContent = invoiceNumber;
                document.getElementById('receiptDate').textContent = Number.isNaN(createdAt.getTime()) ? new Date().toLocaleString() : createdAt.toLocaleString();
                document.getElementById('receiptCashier').textContent = cashierName || 'Admin';
                document.getElementById('receiptSalesTotal').textContent = formatPeso(salesTotal);
                document.getElementById('receiptDiscount').textContent = formatPeso(discount);
                document.getElementById('receiptVatable').textContent = formatPeso(vatable);
                document.getElementById('receiptVat').textContent = formatPeso(vat);
                document.getElementById('receiptAmountPaid').textContent = formatPeso(amountPaid);
                document.getElementById('receiptChange').textContent = formatPeso(change);

                receiptModal.style.display = 'flex';

                if (!hasShownCheckoutAlert) {
                    alert('Payment settled successfully.');
                    hasShownCheckoutAlert = true;
                }

                clearTable();
                cleanupSuccessParams();
            }

            function closeReceiptModal() {
                receiptModal.style.display = 'none';
            }

            function printReceipt() {
                const receiptContentElement = document.getElementById('receiptContent');
                if (!receiptContentElement) {
                    return;
                }

                const w = window.open('', '_blank');
                if (!w) {
                    alert('Unable to open the receipt print preview. Please allow pop-ups for this site.');
                    return;
                }

                w.document.write(`
                    <html>
                        <head>
                            <title>Print Receipt</title>
                            <style>
                                body { font-family: 'Courier New', monospace; font-size: 14px; }
                                @media print {
                                    @page { margin: 0; }
                                    body { margin: 1cm; }
                                }
                            </style>
                        </head>
                        <body>${receiptContentElement.innerHTML}</body>
                    </html>
                `);
                w.document.close();

                const triggerPrint = () => {
                    w.focus();
                    if ('onafterprint' in w) {
                        w.addEventListener('afterprint', () => w.close(), { once: true });
                    } else {
                        setTimeout(() => {
                            try {
                                w.close();
                            } catch (error) {
                                console.error('Unable to close print preview window.', error);
                            }
                        }, 500);
                    }
                    w.print();
                };

                if (w.document.readyState === 'complete') {
                    triggerPrint();
                } else {
                    w.addEventListener('load', triggerPrint, { once: true });
                }
            }

            function closeProofModal() {
                proofModal.classList.remove('show');
                proofModal.setAttribute('aria-hidden', 'true');
                proofImage.removeAttribute('src');
                proofImage.style.display = 'none';
                proofNoImage.style.display = 'none';
            }

            // Event bindings
            mobileToggle?.addEventListener('click', () => {
                sidebar?.classList.toggle('mobile-open');
            });

            document.addEventListener('click', (event) => {
                if (window.innerWidth <= 768 && sidebar && mobileToggle) {
                    if (!sidebar.contains(event.target) && !mobileToggle.contains(event.target)) {
                        sidebar.classList.remove('mobile-open');
                    }
                }
            });

            userAvatar?.addEventListener('click', () => {
                if (userDropdown) {
                    userDropdown.classList.toggle('show');
                }
            });

            document.addEventListener('click', (event) => {
                if (userMenu && userDropdown && !userMenu.contains(event.target)) {
                    userDropdown.classList.remove('show');
                }
            });

            profileButton?.addEventListener('click', (event) => {
                event.preventDefault();
                userDropdown?.classList.remove('show');
                openProfileModal();
            });

            profileModalClose?.addEventListener('click', () => {
                closeProfileModal();
            });

            profileModal?.addEventListener('click', (event) => {
                if (event.target === profileModal) {
                    closeProfileModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    if (profileModal?.classList.contains('show')) {
                        closeProfileModal();
                    }

                    if (onlineOrderModal && onlineOrderModal.style.display !== 'none') {
                        closeOnlineOrderModalOverlay();
                    }
                }
            });

            openProductModalButton?.addEventListener('click', openProductModal);
            closeProductModalButton?.addEventListener('click', closeProductModal);
            productModal?.addEventListener('click', (event) => {
                if (event.target === productModal) {
                    closeProductModal();
                }
            });

            productSearchInput?.addEventListener('input', (event) => {
                renderProductTable(event.target.value.trim());
            });

            addSelectedProductsButton?.addEventListener('click', () => {
                const selected = productModal.querySelectorAll('.product-select-checkbox:checked');
                if (selected.length === 0) {
                    alert('Please select at least one product to add.');
                    return;
                }

                selected.forEach((checkbox) => {
                    addProductById(checkbox.dataset.id);
                    checkbox.checked = false;
                });

                closeProductModal();
            });

            posTableBody.addEventListener('click', (event) => {
                const removeButton = event.target.closest('.remove-btn');
                if (removeButton) {
                    event.preventDefault();
                    const row = removeButton.closest('tr');
                    if (row) {
                        row.remove();
                        updateEmptyState();
                        recalcTotals();
                        persistTableState();
                    }
                }
            });

            posTableBody.addEventListener('input', (event) => {
                if (event.target.classList.contains('pos-qty')) {
                    const input = event.target;
                    const min = parseInt(input.min, 10) || 1;
                    const max = parseInt(input.max, 10);
                    let value = parseInt(input.value, 10);

                    if (!Number.isFinite(value) || value < min) {
                        value = min;
                    }

                    if (Number.isFinite(max) && max > 0 && value > max) {
                        value = max;
                    }

                    input.value = value;
                    recalcTotals();
                    persistTableState();
                }
            });

            amountReceivedInput?.addEventListener('input', () => {
                recalcTotals();
                updateSettleButtonState();
            });

            clearPosTableButton?.addEventListener('click', () => {
                clearTable();
            });

            posForm?.addEventListener('submit', (event) => {
                const rows = posTableBody.querySelectorAll('tr');
                if (rows.length === 0) {
                    event.preventDefault();
                    closeProductModal();
                    alert('No item selected in POS!');
                    return;
                }

                const salesTotal = getSalesTotal();
                const amountReceived = parseFloat(amountReceivedInput.value || '0');

                if (amountReceived <= 0) {
                    event.preventDefault();
                    alert('Please enter the amount received from the customer!');
                    amountReceivedInput.focus();
                    return;
                }

                if (amountReceived < salesTotal) {
                    event.preventDefault();
                    const shortage = salesTotal - amountReceived;
                    alert(`Insufficient payment! Need ${formatPeso(shortage)} more.`);
                    amountReceivedInput.focus();
                }
            });

            tabButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    setActiveTab(button.dataset.tab);
                });
            });

            const statusAlert = document.querySelector('.status-alert');
            if (statusAlert) {
                const url = new URL(window.location.href);
                url.searchParams.delete('status_updated');
                window.history.replaceState({}, document.title, url.toString());
            }

            document.querySelectorAll('.view-proof-btn').forEach((button) => {
                button.addEventListener('click', () => {
                    const image = button.dataset.image;
                    const reference = button.dataset.reference || '';
                    const customer = button.dataset.customer || 'Customer';

                    proofReferenceValue.textContent = reference !== '' ? reference : 'Not provided';
                    proofCustomerName.textContent = customer;

                    if (image) {
                        proofImage.src = image;
                        proofImage.style.display = 'block';
                        proofNoImage.style.display = 'none';
                    } else {
                        proofImage.removeAttribute('src');
                        proofImage.style.display = 'none';
                        proofNoImage.style.display = 'flex';
                    }

                    proofModal.classList.add('show');
                    proofModal.setAttribute('aria-hidden', 'false');
                });
            });

            document.querySelectorAll('.online-order-row').forEach((row) => {
                row.addEventListener('click', async (event) => {
                    if (
                        event.target.closest('.status-form') ||
                        event.target.closest('.view-proof-btn') ||
                        event.target.tagName === 'BUTTON' ||
                        event.target.tagName === 'SELECT'
                    ) {
                        return;
                    }

                    const orderId = row.dataset.orderId;
                    if (!orderId) {
                        return;
                    }

                    try {
                        const response = await fetch(`get_transaction_details.php?order_id=${encodeURIComponent(orderId)}`);
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();
                        populateOnlineOrderModal(data.order || {}, Array.isArray(data.items) ? data.items : []);
                        openOnlineOrderModalOverlay();
                    } catch (error) {
                        console.error('Unable to load online order details.', error);
                        alert('Failed to load order details. Please try again.');
                    }
                });
            });

            closeProofModalButton?.addEventListener('click', closeProofModal);
            proofModal?.addEventListener('click', (event) => {
                if (event.target === proofModal) {
                    closeProofModal();
                }
            });

            // Status filter functionality
            const statusFilter = document.getElementById('statusFilter');
            statusFilter?.addEventListener('change', (event) => {
                const selectedStatus = event.target.value;
                const url = new URL(window.location.href);
                url.searchParams.set('tab', 'online');
                url.searchParams.set('page', '1'); // Reset to first page when filtering
                
                if (selectedStatus) {
                    url.searchParams.set('status_filter', selectedStatus);
                } else {
                    url.searchParams.delete('status_filter');
                }
                
                window.location.href = url.toString();
            });

            closeOnlineOrderModalButton?.addEventListener('click', closeOnlineOrderModalOverlay);
            onlineOrderModal?.addEventListener('click', (event) => {
                if (event.target === onlineOrderModal) {
                    closeOnlineOrderModalOverlay();
                }
            });

            closeReceiptModalButton?.addEventListener('click', closeReceiptModal);
            receiptModal?.addEventListener('click', (event) => {
                if (event.target === receiptModal) {
                    closeReceiptModal();
                }
            });

            printReceiptButton?.addEventListener('click', printReceipt);

            // Initialisation
            updateSettleButtonState();
            const urlParams = new URLSearchParams(window.location.search);
            const shouldRestoreTableState = !(
                urlParams.get('ok') === '1' &&
                checkoutReceipt &&
                Array.isArray(checkoutReceipt.items) &&
                checkoutReceipt.items.length > 0
            );

            if (shouldRestoreTableState) {
                restoreTableState();
            } else {
                try {
                    localStorage.removeItem(posStateKey);
                } catch (error) {
                    console.error('Unable to clear POS state after checkout.', error);
                }
                updateEmptyState();
                recalcTotals();
            }

            initialiseActiveTab();
            generateReceiptFromTransaction();
        });
    </script>
    <script src="../assets/js/notifications.js"></script>
</body>

</html>
