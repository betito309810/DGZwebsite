<?php
/**
 * chart_data.php
 * Returns JSON data for the pie chart (most bought items) based on the selected period.
 * Periods supported: daily, weekly, monthly.
 * 
 * Database schema expected (from your init.sql):
 * - products(id, name, ...)
 * - orders(id, created_at, ...)
 * - order_items(id, order_id, product_id, qty, ...)
 */

header('Content-Type: application/json');

// Try to include your existing config.php which should define a db() function returning PDO.
// Adjust the relative path if needed.
$loadedExternalConfig = false;
$possibleConfigs = [
    __DIR__ . '/../config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/../../config.php'
];

foreach ($possibleConfigs as $cfg) {
    if (file_exists($cfg)) {
        require_once $cfg;
        if (function_exists('db')) {
            $loadedExternalConfig = true;
            break;
        }
    }
}

// Fallback minimal config if no external config.php/db() found.
if (!$loadedExternalConfig) {
    function db() {
        // Adjust credentials to your environment if different.
        $host = '127.0.0.1';
        $db   = 'dgz_db';
        $user = 'root';
        $pass = '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO($dsn, $user, $pass, $options);
    }
}

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed', 'details' => $e->getMessage()]);
    exit;
}

// Whitelist period
$period = isset($_GET['period']) ? strtolower(trim($_GET['period'])) : 'daily';
if (!in_array($period, ['daily','weekly','monthly'], true)) {
    $period = 'daily';
}

// Build WHERE clause based on period (using orders.created_at as the sale date)
$where = "1=1";
switch ($period) {
    case 'daily':
        $where = "DATE(o.created_at) = CURDATE()";
        break;
    case 'weekly':
        // ISO week (mode 1) so Monday is the first day of the week
        $where = "YEARWEEK(o.created_at, 1) = YEARWEEK(CURDATE(), 1)";
        break;
    case 'monthly':
        $where = "YEAR(o.created_at) = YEAR(CURDATE()) AND MONTH(o.created_at) = MONTH(CURDATE())";
        break;
}

// Query top 10 most bought items (sum of order_items.qty)
$sql = "
    SELECT p.name AS product_name, COALESCE(SUM(oi.qty),0) AS total_qty
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    INNER JOIN products p ON p.id = oi.product_id
    WHERE $where
    GROUP BY p.id, p.name
    ORDER BY total_qty DESC, p.name ASC
    LIMIT 10
";

try {
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed', 'details' => $e->getMessage()]);
    exit;
}

// Predefined nice color palette (10 colors). Extend if you expect >10 items.
$palette = [
    '#FF6384','#36A2EB','#FFCE56','#4BC0C0','#9966FF',
    '#FF9F40','#66FF66','#FF6699','#3399FF','#FFCC99'
];

$out = [];
$idx = 0;
foreach ($rows as $row) {
    $color = $palette[$idx % count($palette)];
    $out[] = [
        'product_name' => $row['product_name'],
        'total_qty'    => (int)$row['total_qty'],
        'color'        => $color
    ];
    $idx++;
}

echo json_encode($out);
