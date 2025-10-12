<?php
require __DIR__. '/../config/config.php';
if(empty($_SESSION['user_id'])){
    http_response_code(401);
    exit('Unauthorized');
}

function resolveCashierDisplay(array $row): string
{
    $candidates = [];
    foreach (['cashier_username', 'cashier_name'] as $key) {
        if (!empty($row[$key])) {
            $candidates[] = $row[$key];
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return 'Unassigned';
}

if (!isset($_GET['order_id'])) {
    http_response_code(400);
    exit('Order ID is required');
}

$pdo = db();
$order_id = (int)$_GET['order_id'];

try {
    // Get order details
    $stmt = $pdo->prepare(
        "SELECT o.*, r.label AS decline_reason_label, u.username AS cashier_username, u.name AS cashier_name
         FROM orders o
         LEFT JOIN order_decline_reasons r ON r.id = o.decline_reason_id
         LEFT JOIN users u ON u.id = o.processed_by_user_id
         WHERE o.id = ?"
    );
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        exit('Order not found');
    }

    // Get order items with product details
    $stmt = $pdo->prepare("
        SELECT oi.*, COALESCE(oi.description, p.name) AS name, p.code
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Send response
    header('Content-Type: application/json');
    $details = parsePaymentProofValue($order['payment_proof'] ?? null, $order['reference_no'] ?? null);

    $order['reference_number'] = $details['reference'];
    $order['cashier_display_name'] = resolveCashierDisplay($order);
    $order['phone'] = $order['phone'] ?? null;
    $order['customer_note'] = isset($order['customer_note']) && $order['customer_note'] !== null
        ? (string) $order['customer_note']
        : (isset($order['notes']) ? (string) $order['notes'] : ''); // Added normalized field for cashier notes

    echo json_encode([
        'order' => $order,
        'items' => $items
    ]);

} catch (Exception $e) {
    http_response_code(500);
    exit('Server error: ' . $e->getMessage());
}
