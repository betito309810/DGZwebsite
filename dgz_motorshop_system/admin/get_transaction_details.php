<?php
require __DIR__. '/../config/config.php';
if(empty($_SESSION['user_id'])){ 
    http_response_code(401);
    exit('Unauthorized');
}

if (!isset($_GET['order_id'])) {
    http_response_code(400);
    exit('Order ID is required');
}

$pdo = db();
$order_id = (int)$_GET['order_id'];

try {
    // Get order details
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        exit('Order not found');
    }

    // Get order items with product details
    $stmt = $pdo->prepare("
        SELECT oi.*, p.name, p.code 
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Send response
    header('Content-Type: application/json');
    $details = parsePaymentProofValue($order['payment_proof'] ?? null, $order['reference_no'] ?? null);

    $order['reference_number'] = $details['reference'];

    echo json_encode([
        'order' => $order,
        'items' => $items
    ]);

} catch (Exception $e) {
    http_response_code(500);
    exit('Server error: ' . $e->getMessage());
}
