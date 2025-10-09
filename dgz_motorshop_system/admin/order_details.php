<?php
/**
 * order_details.php
 * API endpoint to return detailed information for a specific order.
 * Returns JSON with order information and order items.
 */

require __DIR__ . '/../config/config.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$order_id = $_GET['order_id'] ?? null;
if (!$order_id || !is_numeric($order_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order ID']);
    exit;
}

try {
    $pdo = db();

    // Fetch order information
    $order_sql = "SELECT * FROM orders WHERE id = ?";
    $order_stmt = $pdo->prepare($order_sql);
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    // Fetch order items
    $items_sql = "
        SELECT oi.*, COALESCE(oi.description, p.name) AS product_name
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ";
    $items_stmt = $pdo->prepare($items_sql);
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse payment proof value
    $paymentDetails = parsePaymentProofValue($order['payment_proof'] ?? null, $order['reference_no'] ?? null);

    // Prepare response data
    $response = [
        'order' => [
            'id' => $order['id'],
            'invoice_number' => $order['invoice_number'] ?? 'N/A',
            'customer_name' => $order['customer_name'],
            'phone' => $order['phone'] ?? 'N/A',
            'address' => $order['address'] ?? 'N/A',
            'total' => $order['total'],
            'payment_method' => $order['payment_method'],
            'reference' => $paymentDetails['reference'],
            'image' => $paymentDetails['image'],
            'status' => $order['status'],
            'created_at' => $order['created_at'],
            'email' => $order['email'] ?? 'N/A'
        ],
        'items' => array_map(function($item) {
            return [
                'product_name' => $item['product_name'],
                'quantity' => $item['qty'],
                'price' => $item['price'],
                'subtotal' => $item['qty'] * $item['price']
            ];
        }, $items)
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
