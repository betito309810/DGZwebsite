<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

requireCustomerAuthentication();
$customer = getAuthenticatedCustomer();

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$logoAsset = assetUrl('assets/logo.png');
$homeUrl = orderingUrl('index.php');

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

$orderStmt = $pdo->prepare('SELECT id, tracking_code, created_at, total, status FROM orders WHERE customer_id = ? ORDER BY created_at DESC');
$orderStmt->execute([(int) $customer['id']]);
$orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

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
<body class="customer-orders-page">
<header class="customer-orders-header">
    <a href="<?= htmlspecialchars($homeUrl) ?>" class="customer-orders-logo">
        <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop logo">
    </a>
    <div class="customer-orders-meta">
        <span>Signed in as <strong><?= htmlspecialchars($customer['full_name'] ?? '') ?></strong></span>
        <a class="customer-orders-link" href="<?= htmlspecialchars(orderingUrl('logout.php')) ?>">Logout</a>
    </div>
</header>
<main class="customer-orders-wrapper">
    <h1>My Orders</h1>
    <?php foreach ($alerts as $type => $messages): ?>
        <?php foreach ($messages as $message): ?>
            <div class="customer-orders-alert customer-orders-alert--<?= htmlspecialchars($type) ?>" role="alert"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if (empty($orders)): ?>
        <p class="customer-orders-empty">You have not placed any orders yet. <a href="<?= htmlspecialchars($homeUrl) ?>">Start shopping</a>.</p>
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
                <article class="customer-order-card">
                    <header class="customer-order-card__header">
                        <div>
                            <h2>Order #<?= (int) $order['id'] ?></h2>
                            <?php if (!empty($order['tracking_code'])): ?>
                                <p class="customer-order-card__tracking">Tracking code: <?= htmlspecialchars($order['tracking_code']) ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="customer-order-card__status customer-order-card__status--<?= htmlspecialchars($order['status']) ?>">
                            <?= htmlspecialchars($statusLabels[$order['status']] ?? ucfirst($order['status'])) ?>
                        </span>
                    </header>
                    <dl class="customer-order-card__details">
                        <div>
                            <dt>Placed on</dt>
                            <dd><?= htmlspecialchars($orderDateFormatted) ?></dd>
                        </div>
                        <div>
                            <dt>Total</dt>
                            <dd>₱<?= number_format((float) $order['total'], 2) ?></dd>
                        </div>
                    </dl>
                    <?php if ($order['status'] === 'approved'): ?>
                        <form method="post" class="customer-order-card__actions" data-customer-cancel-form>
                            <input type="hidden" name="cancel_order_id" value="<?= (int) $order['id'] ?>">
                            <button type="submit" class="customer-order-card__button customer-order-card__button--danger">Cancel order</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
</body>
</html>
