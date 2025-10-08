<?php
// Allow the public order tracker to query order details securely.
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

// Only accept POST requests to keep the endpoint predictable.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Unsupported request method.',
    ]);
    exit;
}

// Decode JSON payloads while still supporting standard form submissions as a fallback.
$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody, true);

$orderIdInput = null;
if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
    $orderIdInput = $decoded['orderId'] ?? null;
}

if ($orderIdInput === null) {
    $orderIdInput = $_POST['orderId'] ?? $_POST['order_id'] ?? null;
}

$orderId = filter_var($orderIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a valid numeric order ID.',
    ]);
    exit;
}

try {
    $pdo = db();

    // Fetch a small, focused subset of the order information to share with customers.
    $stmt = $pdo->prepare('SELECT id, customer_name, status, created_at, total, payment_method FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'We could not find an order with that ID. Please double check and try again.',
        ]);
        exit;
    }

    // Normalise values for consistent display in the UI.
    $status = strtolower((string) ($order['status'] ?? 'pending'));
    $statusMessages = [
        'pending' => 'Your order is being reviewed by our team.',
        'approved' => 'Great news! Your order has been approved and is moving to fulfillment.',
        'completed' => 'Your order has been completed. Thank you for shopping with us!',
        'disapproved' => 'Unfortunately this order was disapproved. Please contact our team for help.',
        'cancelled' => 'This order has been cancelled.',
        'canceled' => 'This order has been cancelled.',
    ];

    $formattedTotal = '₱' . number_format((float) ($order['total'] ?? 0), 2);
    $createdAt = $order['created_at'] ?? '';
    if ($createdAt !== '') {
        try {
            $createdAt = (new DateTime($createdAt))->format('M d, Y g:i A');
        } catch (Exception $e) {
            // Leave the original string if parsing fails.
        }
    }

    echo json_encode([
        'success' => true,
        'order' => [
            'id' => (int) $order['id'],
            'customerName' => $order['customer_name'] !== '' ? $order['customer_name'] : 'Customer',
            'status' => $status,
            'statusMessage' => $statusMessages[$status] ?? 'We found your order. Stay tuned for updates!',
            'createdAt' => $createdAt !== '' ? $createdAt : 'Processing',
            'paymentMethod' => $order['payment_method'] !== '' ? $order['payment_method'] : 'Not specified',
            'total' => $formattedTotal,
        ],
    ]);
} catch (Throwable $exception) {
    // Avoid leaking internal details while providing a helpful error message.
    error_log('Order status lookup failed: ' . $exception->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Something went wrong while fetching your order status. Please try again later.',
    ]);
}
