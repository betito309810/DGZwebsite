<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/inventory_notifications.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = db();
    ensureInventoryNotificationSchema($pdo); // Keep created_at immutable before we mark rows as read.
    $stmt = $pdo->prepare("UPDATE inventory_notifications SET is_read = 1 WHERE status = 'active' AND is_read = 0");
    $stmt->execute();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
