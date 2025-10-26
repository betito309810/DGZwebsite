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
        'customer_name' => ['customer_name', 'name', 'customer_fullname', 'customer_full_name', 'full_name', 'customer'],
        'customer_display_name' => ['customer_display_name', 'display_name', 'recipient_name', 'contact_name'],
        'customer_first_name' => ['customer_first_name', 'first_name', 'firstname', 'customer_first', 'given_name', 'fname'],
        'customer_last_name' => ['customer_last_name', 'last_name', 'lastname', 'customer_last', 'surname', 'lname'],
        'customer_id' => ['customer_id', 'customerId', 'customerID', 'customerid'],
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
    if ($customerName === '' && array_key_exists('customer_display_name', $order)) {
        $customerName = trim((string) ($order['customer_display_name'] ?? ''));
    }

    $customerFirstName = array_key_exists('customer_first_name', $order)
        ? trim((string) ($order['customer_first_name'] ?? ''))
        : '';
    $customerLastName = array_key_exists('customer_last_name', $order)
        ? trim((string) ($order['customer_last_name'] ?? ''))
        : '';

    if ($customerName === '') {
        $composed = trim(trim($customerFirstName . ' ' . $customerLastName));
        if ($composed !== '') {
            $customerName = $composed;
        }
    }

    if ($customerName === '' && $customerFirstName !== '') {
        $customerName = $customerFirstName;
    }

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

    $customerId = 0;
    if (array_key_exists('customer_id', $order)) {
        $customerId = (int) ($order['customer_id'] ?? 0);
    }

    if ($customerName === '' && $customerId > 0) {
        try {
            $customerStmt = $pdo->prepare(
                'SELECT full_name, name, first_name, firstname, given_name, fname, last_name, lastname, surname, lname, middle_name, middlename FROM customers WHERE id = ? LIMIT 1'
            );
            if ($customerStmt && $customerStmt->execute([$customerId])) {
                $customerRow = $customerStmt->fetch(PDO::FETCH_ASSOC);
                if ($customerRow) {
                    $nameCandidates = [
                        trim((string) ($customerRow['full_name'] ?? '')),
                        trim((string) ($customerRow['name'] ?? '')),
                        trim((string) ($customerRow['firstname'] ?? '')),
                        trim((string) ($customerRow['lastname'] ?? '')),
                        trim((string) ($customerRow['given_name'] ?? '')),
                        trim((string) ($customerRow['surname'] ?? '')),
                        trim((string) ($customerRow['fname'] ?? '')),
                        trim((string) ($customerRow['lname'] ?? '')),
                    ];
                    $first = trim((string) ($customerRow['first_name'] ?? ''));
                    $last = trim((string) ($customerRow['last_name'] ?? ''));
                    $middle = trim((string) ($customerRow['middle_name'] ?? ($customerRow['middlename'] ?? '')));
                    $nameCandidates[] = trim($first . ' ' . $last);
                    $nameCandidates[] = trim($first . ' ' . $middle . ' ' . $last);

                    foreach ($nameCandidates as $candidateName) {
                        if ($candidateName !== '') {
                            $customerName = $candidateName;
                            break;
                        }
                    }
                }
            }
        } catch (Throwable $customerLookupException) {
            error_log('Unable to resolve customer name for tracking lookup: ' . $customerLookupException->getMessage());
        }
    }

    if ($customerName === '' && $customerId > 0) {
        try {
            $userStmt = $pdo->prepare(
                'SELECT full_name, name, first_name, firstname, last_name, lastname FROM users WHERE id = ? LIMIT 1'
            );
            if ($userStmt && $userStmt->execute([$customerId])) {
                $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($userRow) {
                    $first = trim((string) ($userRow['first_name'] ?? ($userRow['firstname'] ?? '')));
                    $last = trim((string) ($userRow['last_name'] ?? ($userRow['lastname'] ?? '')));
                    $userCandidates = [
                        trim((string) ($userRow['full_name'] ?? '')),
                        trim((string) ($userRow['name'] ?? '')),
                        trim($first . ' ' . $last),
                        $first,
                    ];

                    foreach ($userCandidates as $candidate) {
                        if ($candidate !== '') {
                            $customerName = $candidate;
                            break;
                        }
                    }
                }
            }
        } catch (Throwable $userLookupException) {
            error_log('Unable to resolve user name for tracking lookup: ' . $userLookupException->getMessage());
        }
    }

    $finalNormalizedCustomerName = strtolower(preg_replace('/[^a-z]/', '', $customerName));
    if ($finalNormalizedCustomerName !== '' && strpos($finalNormalizedCustomerName, 'walkin') !== false) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'In-store purchases are not available for online tracking. Please contact the store directly for updates.',
        ]);
        exit;
    }

    $customerName = $customerName !== '' ? $customerName : 'Customer';

    $paymentMethodRaw = '';
    if (array_key_exists('payment_method', $order)) {
        $paymentMethodRaw = (string) ($order['payment_method'] ?? '');
    }
    $paymentMethodKey = strtolower(trim($paymentMethodRaw));
    $paymentMethodLabels = [
        'gcash' => 'GCash',
        'maya' => 'Maya',
    ];
    $paymentMethodDisplay = $paymentMethodLabels[$paymentMethodKey] ?? ($paymentMethodRaw !== '' ? $paymentMethodRaw : 'Not specified');

    $internalId = (int) ($order['id'] ?? 0);

    echo json_encode([
        'success' => true,
        'order' => [
            'trackingCode' => $trackingCode,
            'internalId' => $internalId,
            'customerName' => $customerName,
            'status' => $status,
            'statusMessage' => $statusMessages[$status] ?? 'We found your order. Stay tuned for updates!',
            'createdAt' => $createdAt !== '' ? $createdAt : 'Processing',
            'paymentMethod' => $paymentMethodDisplay,
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
