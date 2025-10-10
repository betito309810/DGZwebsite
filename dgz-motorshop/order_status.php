<?php
// Allow the public order tracker to query order details securely.
header('Content-Type: application/json');

require_once __DIR__ . '/../dgz_motorshop_system/config/config.php';

if (!function_exists('ordersSupportsTrackingCodes')) {
    function ordersSupportsTrackingCodes(PDO $pdo): bool
    {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'tracking_code'");
            $hasColumn = $stmt !== false && $stmt->fetch() !== false;
        } catch (Exception $exception) {
            error_log('Unable to detect orders.tracking_code column: ' . $exception->getMessage());
            $hasColumn = false;
        }

        return $hasColumn;
    }
}

if (!function_exists('ordersHasColumn')) {
    function ordersHasColumn(PDO $pdo, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        try {
            $stmt = $pdo->prepare('SHOW COLUMNS FROM orders LIKE ?');
            $stmt->execute([$column]);
            $cache[$column] = $stmt !== false && $stmt->fetch() !== false;
        } catch (Exception $exception) {
            error_log('Unable to detect orders.' . $column . ' column: ' . $exception->getMessage());
            $cache[$column] = false;
        }

        return $cache[$column];
    }
}

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

$trackingCodeInput = null;
if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
    $trackingCodeInput = $decoded['trackingCode']
        ?? $decoded['tracking_code']
        ?? $decoded['orderId']
        ?? $decoded['order_id']
        ?? null;
}

if ($trackingCodeInput === null) {
    $trackingCodeInput = $_POST['trackingCode']
        ?? $_POST['tracking_code']
        ?? $_POST['orderId']
        ?? $_POST['order_id']
        ?? null;
}

$trackingCodeInput = is_string($trackingCodeInput) ? trim($trackingCodeInput) : '';
$normalizedTrackingCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $trackingCodeInput));

// Tracking codes follow the DGZ-XXXX-XXXX pattern (11 significant characters without hyphens).
if (strlen($normalizedTrackingCode) !== 11 || strpos($normalizedTrackingCode, 'DGZ') !== 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a valid tracking code (e.g. DGZ-ABCD-1234).',
    ]);
    exit;
}

$normalizedTrackingCode = 'DGZ-' . substr($normalizedTrackingCode, 3, 4) . '-' . substr($normalizedTrackingCode, 7, 4);

try {
    $pdo = db();

    if (!ordersSupportsTrackingCodes($pdo)) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Order tracking is temporarily unavailable. Please try again later.',
        ]);
        exit;
    }

    $columns = [
        'id',
        'tracking_code',
        'customer_name',
        'status',
        'created_at',
        'total',
        'payment_method',
    ];

    $hasOrderTypeColumn = ordersHasColumn($pdo, 'order_type');
    if ($hasOrderTypeColumn) {
        $columns[] = 'order_type';
    }

    // Fetch a small, focused subset of the order information to share with customers.
    $sql = 'SELECT ' . implode(', ', $columns) . ' FROM orders WHERE tracking_code = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$normalizedTrackingCode]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'We could not find an order with that tracking code. Please double check and try again.',
        ]);
        exit;
    }

    $trackingCode = (string) ($order['tracking_code'] ?? '');
    if ($trackingCode === '') {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'We could not find an order with that tracking code. Please double check and try again.',
        ]);
        exit;
    }

    $customerName = (string) ($order['customer_name'] ?? '');
    $normalizedCustomerName = strtolower(preg_replace('/[^a-z]/', '', $customerName));

    if ($normalizedCustomerName !== '' && strpos($normalizedCustomerName, 'walkin') !== false) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'In-store purchases are not available for online tracking. Please contact the store directly for updates.',
        ]);
        exit;
    }

    if ($hasOrderTypeColumn) {
        $orderType = strtolower((string) ($order['order_type'] ?? ''));
        if ($orderType !== '' && $orderType !== 'online') {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'We could not find an order with that tracking code. Please double check and try again.',
            ]);
            exit;
        }
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
            'trackingCode' => $trackingCode,
            'internalId' => (int) $order['id'],
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
