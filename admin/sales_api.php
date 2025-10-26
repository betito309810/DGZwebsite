<?php

require __DIR__ . '/../config/config.php';
require __DIR__ . '/includes/sales_periods.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = db();
$period = $_GET['period'] ?? 'daily';
$value = $_GET['value'] ?? null;

try {
    $periodInfo = resolve_sales_period($period, $value);

    $sql = "
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(total), 0) AS total_sales
        FROM orders
        WHERE created_at >= :start
          AND created_at < :end
          AND status IN ('approved', 'delivery', 'completed', 'complete')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start' => $periodInfo['start'],
        ':end' => $periodInfo['end'],
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_orders' => 0, 'total_sales' => 0];

    echo json_encode([
        'totalSales' => (float) ($result['total_sales'] ?? 0),
        'totalOrders' => (int) ($result['total_orders'] ?? 0),
        'period' => $periodInfo['period'],
        'value' => $periodInfo['value'],
        'label' => $periodInfo['label'],
        'range' => [
            'start' => $periodInfo['range_start'],
            'end' => $periodInfo['range_end'],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
}
