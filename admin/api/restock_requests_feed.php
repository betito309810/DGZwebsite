<?php
require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/restock_request_helpers.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    return;
}

$role = $_SESSION['role'] ?? '';

try {
    $collections = fetchRestockRequestCollections($pdo);
    $pending = $collections['pending'] ?? [];
    $history = $collections['history'] ?? [];

    $response = [
        'success' => true,
        'pending_html' => renderRestockRequestRows($pending, $role),
        'pending_count' => count($pending),
        'history_html' => renderRestockHistoryRows($history),
        'history_count' => count($history),
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Throwable $e) {
    error_log('Failed to build restock requests feed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
