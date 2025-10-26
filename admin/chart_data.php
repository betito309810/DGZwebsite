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
            p.name AS product_name,
            COALESCE(SUM(oi.qty), 0) AS total_qty
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        INNER JOIN products p ON p.id = oi.product_id
        WHERE o.created_at >= :start
          AND o.created_at < :end
          AND o.status IN ('approved', 'delivery', 'completed', 'complete')
        GROUP BY p.id, p.name
        ORDER BY total_qty DESC, p.name ASC
        LIMIT 10
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start' => $periodInfo['start'],
        ':end' => $periodInfo['end'],
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $palette = [
        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
        '#FF9F40', '#66FF66', '#FF6699', '#3399FF', '#FFCC99',
    ];

    $items = [];
    foreach ($rows as $index => $row) {
        $items[] = [
            'product_name' => $row['product_name'],
            'total_qty' => (int) ($row['total_qty'] ?? 0),
            'color' => $palette[$index % count($palette)],
        ];
    }

    echo json_encode([
        'period' => $periodInfo['period'],
        'value' => $periodInfo['value'],
        'label' => $periodInfo['label'],
        'range' => [
            'start' => $periodInfo['range_start'],
            'end' => $periodInfo['range_end'],
        ],
        'items' => $items,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
}
