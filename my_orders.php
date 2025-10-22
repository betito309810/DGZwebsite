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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order_id'])) {
    $orderId = (int) $_POST['cancel_order_id'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = ? AND customer_id = ? FOR UPDATE');
        $stmt->execute([$orderId, (int) $customer['id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $alerts['error'][] = 'We could not find that order.';
        } elseif ($order['status'] !== 'approved') {
            $alerts['error'][] = 'Only approved orders can be cancelled online.';
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
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Unable to cancel customer order: ' . $exception->getMessage());
        $alerts['error'][] = 'We could not cancel that order. Please try again or contact support.';
    }
}

if (!function_exists('ordersColumnExists')) {
    function ordersColumnExists(PDO $pdo, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM orders LIKE ?");
            $stmt->execute([$column]);
            $cache[$column] = $stmt !== false && $stmt->fetch() !== false;
        } catch (Throwable $exception) {
            error_log('Unable to inspect orders column ' . $column . ': ' . $exception->getMessage());
            $cache[$column] = false;
        }

        return $cache[$column];
    }
}

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

$orderColumns = ['id', 'tracking_code', 'created_at', 'total', 'status', 'customer_name', 'address'];
foreach (['postal_code', 'city', 'email', 'phone', 'facebook_account', 'customer_note', 'reference_no'] as $column) {
    if (ordersColumnExists($pdo, $column)) {
        $orderColumns[] = $column;
    }
}

$orderColumns = array_unique($orderColumns);
$statusFilter = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';
$orderSql = 'SELECT ' . implode(', ', $orderColumns) . ' FROM orders WHERE customer_id = ?' . ($statusFilter === 'complete' ? " AND status = 'complete'" : '') . ' ORDER BY created_at DESC';
$orderStmt = $pdo->prepare($orderSql);
$orderStmt->execute([(int) $customer['id']]);
$orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

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
    'approved' => 'Approved',
    'delivery' => 'Out for delivery',
    'complete' => 'Completed',
    'cancelled_by_staff' => 'Cancelled by staff',
    'cancelled_by_customer' => 'Cancelled',
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
        <?php $isCompleted = ($statusFilter === 'complete'); ?>
        <a class="customer-orders-tab<?= $isCompleted ? '' : ' is-active' ?>" href="<?= htmlspecialchars($myOrdersUrl) ?>">All</a>
        <a class="customer-orders-tab<?= $isCompleted ? ' is-active' : '' ?>" href="<?= htmlspecialchars($myOrdersUrl) ?>?status=complete">Completed</a>
    </nav>
    <?php foreach ($alerts as $type => $messages): ?>
        <?php foreach ($messages as $message): ?>
            <div class="customer-orders-alert customer-orders-alert--<?= htmlspecialchars($type) ?>" role="alert"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if (empty($orders)): ?>
        <?php if ($statusFilter === 'complete'): ?>
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
                    $contactEmail = trim((string) ($order['email'] ?? ''));
                    $contactPhone = trim((string) ($order['phone'] ?? ''));
                    $facebookAccount = trim((string) ($order['facebook_account'] ?? ''));
                    $referenceNumber = trim((string) ($order['reference_no'] ?? ''));
                    $addressLine = trim((string) ($order['address'] ?? ''));
                    $postalCode = trim((string) ($order['postal_code'] ?? ''));
                    $city = trim((string) ($order['city'] ?? ''));
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
                    $customerNote = trim((string) ($order['customer_note'] ?? ''));
                ?>
                <article class="customer-order-card" data-order-card>
                    <header class="customer-order-card__header">
                        <div>
                            <h2>Order #<?= (int) $order['id'] ?></h2>
                            <?php if (!empty($order['tracking_code'])): ?>
                                <p class="customer-order-card__tracking">Tracking code: <?= htmlspecialchars($order['tracking_code']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="customer-order-card__meta">
                            <span class="customer-order-card__status customer-order-card__status--<?= htmlspecialchars($order['status']) ?>">
                                <?= htmlspecialchars($statusLabels[$order['status']] ?? ucfirst($order['status'])) ?>
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
                            <?php if ($contactEmail !== '' || $contactPhone !== '' || $facebookAccount !== '' || $referenceNumber !== ''): ?>
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
                                        <?php if ($referenceNumber !== ''): ?>
                                            <div><dt>Reference #</dt><dd><?= htmlspecialchars($referenceNumber) ?></dd></div>
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
                        <?php if (in_array($order['status'], ['approved', 'pending'], true)): ?>
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
