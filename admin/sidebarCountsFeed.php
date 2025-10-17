<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/inventory_notifications.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = db();
} catch (Throwable $exception) {
    error_log('Sidebar counts feed failed to connect: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$onlineOrderCount = 0;
$restockRequestCount = 0;
$inventoryNotificationCount = 0;

try {
    $onlineOrderCount = countOnlineOrdersByStatus($pdo);
} catch (Throwable $exception) {
    error_log('Sidebar counts feed failed to load online orders: ' . $exception->getMessage());
}

try {
    $restockRequestCount = countPendingRestockRequests($pdo);
} catch (Throwable $exception) {
    error_log('Sidebar counts feed failed to load restock requests: ' . $exception->getMessage());
}

try {
    $inventoryData = loadInventoryNotifications($pdo);
    if (isset($inventoryData['active_count'])) {
        $inventoryNotificationCount = (int) $inventoryData['active_count'];
    }
} catch (Throwable $exception) {
    error_log('Sidebar counts feed failed to load inventory notifications: ' . $exception->getMessage());
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'data' => [
        'online_orders' => (int) $onlineOrderCount,
        'restock_requests' => (int) $restockRequestCount,
        'inventory_notifications' => (int) $inventoryNotificationCount,
    ],
]);
