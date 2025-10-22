<?php
require __DIR__. '/../config/config.php';
require_once __DIR__ . '/includes/online_orders_helpers.php';
if(empty($_SESSION['user_id'])){
    http_response_code(401);
    exit('Unauthorized');
}

function resolveCashierDisplay(PDO $pdo, array $row): string
{
    $candidates = [];
    foreach (['cashier_name', 'cashier_username'] as $key) {
        if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
            $candidates[] = $row[$key];
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $userId = isset($row['processed_by_user_id']) ? (int) $row['processed_by_user_id'] : 0;
    if ($userId > 0) {
        static $cache = [];
        if (!array_key_exists($userId, $cache)) {
            $cache[$userId] = fetchUserDisplayName($pdo, $userId) ?? '';
        }

        $fallback = $cache[$userId];
        if (is_string($fallback)) {
            $fallback = trim($fallback);
            if ($fallback !== '') {
                return $fallback;
            }
        }
    }

    return 'Unassigned';
}

if (!isset($_GET['order_id'])) {
    http_response_code(400);
    exit('Order ID is required');
}

$pdo = db();
$supportsProcessedBy = ordersSupportsProcessedBy($pdo);
$supportsCustomerAccounts = ordersSupportsCustomerAccounts($pdo);
$order_id = (int)$_GET['order_id'];

try {
    // Get order details
    $buildOrderDetailsQuery = static function (bool $withProcessedBy) use ($supportsCustomerAccounts, $pdo): string {
        $selectParts = [
            'o.*',
            'r.label AS decline_reason_label',
        ];

        if ($withProcessedBy) {
            $selectParts[] = 'u.username AS cashier_username';
            $selectParts[] = 'u.name AS cashier_name';
        } else {
            $selectParts[] = 'NULL AS cashier_username';
            $selectParts[] = 'NULL AS cashier_name';
        }

        if ($supportsCustomerAccounts) {
            if (customersHasColumn($pdo, 'full_name')) {
                $selectParts[] = 'c.full_name AS customer_full_name';
            }
            if (customersHasColumn($pdo, 'email')) {
                $selectParts[] = 'c.email AS customer_email';
            }
            foreach (['phone', 'contact', 'contact_number', 'contact_no', 'mobile', 'telephone'] as $customerPhoneColumn) {
                if (customersHasColumn($pdo, $customerPhoneColumn)) {
                    $selectParts[] = 'c.' . $customerPhoneColumn . ' AS customer_phone';
                    break;
                }
            }
            if (customersHasColumn($pdo, 'facebook_account')) {
                $selectParts[] = 'c.facebook_account AS customer_facebook_account';
            }
            if (customersHasColumn($pdo, 'address')) {
                $selectParts[] = 'c.address AS customer_address';
            }
            if (customersHasColumn($pdo, 'postal_code')) {
                $selectParts[] = 'c.postal_code AS customer_postal_code';
            }
            if (customersHasColumn($pdo, 'city')) {
                $selectParts[] = 'c.city AS customer_city';
            }
        }

        $joins = [
            'LEFT JOIN order_decline_reasons r ON r.id = o.decline_reason_id',
        ];

        if ($withProcessedBy) {
            $joins[] = 'LEFT JOIN users u ON u.id = o.processed_by_user_id';
        }

        if ($supportsCustomerAccounts) {
            $joins[] = 'LEFT JOIN customers c ON c.id = o.customer_id';
        }

        return 'SELECT ' . implode(', ', $selectParts)
            . ' FROM orders o '
            . implode(' ', $joins)
            . ' WHERE o.id = ?';
    };

    $sql = $buildOrderDetailsQuery($supportsProcessedBy);

    $stmt = null;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id]);
    } catch (Throwable $e) {
        if ($supportsProcessedBy) {
            error_log('Cashier join failed, retrying without processed_by_user_id: ' . $e->getMessage());
            $supportsProcessedBy = false;
            $fallbackSql = $buildOrderDetailsQuery(false);
            $stmt = $pdo->prepare($fallbackSql);
            $stmt->execute([$order_id]);
        } else {
            throw $e;
        }
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        exit('Order not found');
    }

    $order = normalizeOnlineOrderRow($order);

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

    $order['reference_number'] = $details['reference'] !== ''
        ? $details['reference']
        : (string) ($order['reference_no'] ?? ($order['reference_number'] ?? ''));
    $order['cashier_display_name'] = resolveCashierDisplay($pdo, $order);
    if (
        (!isset($order['cashier_name']) || trim((string) $order['cashier_name']) === '')
        && $order['cashier_display_name'] !== 'Unassigned'
    ) {
        $order['cashier_name'] = $order['cashier_display_name'];
    }
    $order['phone'] = $order['phone'] ?? null;
    $order['email'] = $order['email'] ?? null;
    $order['facebook_account'] = $order['facebook_account'] ?? ($order['facebook'] ?? null);
    $order['address'] = $order['address'] ?? ($order['customer_address'] ?? null);
    $order['postal_code'] = $order['postal_code'] ?? null;
    $order['city'] = $order['city'] ?? null;
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
