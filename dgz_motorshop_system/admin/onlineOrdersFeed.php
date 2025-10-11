<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/online_orders_helpers.php';
require_once __DIR__ . '/includes/decline_reasons.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = db();

$statusFilter = $_GET['status_filter'] ?? '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 15;

try {
    $declineReasons = fetchOrderDeclineReasons($pdo);
    $declineLookup = [];
    foreach ($declineReasons as $reason) {
        $declineLookup[(int) ($reason['id'] ?? 0)] = (string) ($reason['label'] ?? '');
    }

    $data = fetchOnlineOrdersData($pdo, [
        'page' => $page,
        'per_page' => $perPage,
        'status' => $statusFilter,
        'decline_reason_lookup' => $declineLookup,
    ]);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $data,
    ]);
} catch (Throwable $e) {
    error_log('Failed to fetch online orders feed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
    ]);
}

