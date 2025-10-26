<?php
// Allow the public order tracker to query order details securely.
header('Content-Type: application/json');

require_once __DIR__ . '/dgz_motorshop_system/config/config.php';

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
// Accept lowercase letters by stripping non-alphanumerics case-insensitively,
// then uppercase the remaining characters.
$normalizedTrackingCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $trackingCodeInput));

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

    $trackingCodeColumn = ordersFindColumn($pdo, ['tracking_code', 'tracking_number', 'tracking_no']);

    if ($trackingCodeColumn === null) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Order tracking is temporarily unavailable. Please try again later.',
        ]);
        exit;
    }

    $columnCandidates = [
        'id' => ['id', 'order_id'],
        'tracking_code' => [$trackingCodeColumn],
        'customer_name' => ['customer_name', 'name'],
        'status' => ['status', 'order_status'],
        'created_at' => ['created_at', 'order_date', 'date_created', 'created'],
        'total' => ['total', 'grand_total', 'amount', 'total_amount'],
        'payment_method' => ['payment_method', 'payment_type', 'payment'],
    ];

    $resolvedColumns = [];
    $selectParts = [];

    foreach ($columnCandidates as $alias => $candidates) {
        $column = null;
        if ($alias === 'tracking_code') {
            $column = $trackingCodeColumn;
        } else {
            $column = ordersFindColumn($pdo, $candidates);
        }

        if ($column === null) {
            continue;
        }

        $resolvedColumns[$alias] = $column;
        $safeColumn = '`' . str_replace('`', '``', $column) . '`';
        $safeAlias = '`' . str_replace('`', '``', $alias) . '`';
        $selectParts[] = $safeColumn . ' AS ' . $safeAlias;
    }

    if (!isset($resolvedColumns['tracking_code'])) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Order tracking is temporarily unavailable. Please try again later.',
        ]);
        exit;
    }

    $orderTypeColumn = ordersFindColumn($pdo, ['order_type', 'order_origin', 'source']);
    if ($orderTypeColumn !== null) {
        $resolvedColumns['order_type'] = $orderTypeColumn;
        $safeColumn = '`' . str_replace('`', '``', $orderTypeColumn) . '`';
        $selectParts[] = $safeColumn . ' AS `order_type`';
    }

    if (empty($selectParts)) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Order tracking is temporarily unavailable. Please try again later.',
        ]);
        exit;
    }

    $whereColumn = '`' . str_replace('`', '``', $resolvedColumns['tracking_code']) . '`';
    $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM orders WHERE ' . $whereColumn . ' = ? LIMIT 1';
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

    if ($orderTypeColumn !== null) {
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
    if ($status === 'completed') {
        $status = 'complete';
    } elseif ($status === 'cancelled') {
        $status = 'cancelled';
    }

    $statusMessages = [
        'pending' => 'Your order is being reviewed by our team.',
        'payment_verification' => 'We are verifying your payment details. Thank you for your patience.',
        'approved' => 'Great news! Your order has been approved and is moving to fulfillment.',
        'delivery' => 'Your order has been handed to the courier and is on its way.',
        'complete' => 'Your order has been completed. Thank you for shopping with us!',
        'cancelled_by_staff' => 'This order was cancelled by our team. Please contact us for more details.',
        'cancelled_by_customer' => 'This order was cancelled at your request.',
        'disapproved' => 'Unfortunately this order was disapproved. Please contact our team for help.',
        'cancelled' => 'This order has been cancelled.',
        'canceled' => 'This order has been cancelled.',
    ];

    $rawTotal = isset($order['total']) ? (float) $order['total'] : 0.0;
    $formattedTotal = '₱' . number_format($rawTotal, 2);
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
