<?php
require __DIR__ . '/../config/config.php';

// Block access if the visitor is not authenticated.
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = db();

/**
 * Retrieve the latest orders and restock requests with the requesting user's name.
 *
 * @param PDO $pdo Active database connection.
 * @return array<int, array<string, mixed>> Ordered list of order rows.
 */
function fetchOrdersWithUsers(PDO $pdo): array
{
    $sql = "SELECT o.*, u.username
            FROM orders o
            JOIN users u ON o.user_id = u.id
            ORDER BY o.created_at DESC";

    $stmt = $pdo->query($sql);

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

try {
    $orders = fetchOrdersWithUsers($pdo);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $orders,
    ]);
} catch (Throwable $e) {
    error_log('Failed to build orders feed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
    ]);
}
