<?php
require '../config.php';
$pdo = db();

$period = $_GET['period'] ?? 'daily';

$where = '';
if ($period === 'daily') {
    // Change 'sale_date' if your date column has a different name
    $where = "WHERE DATE(created_at) = CURDATE()"; 
} elseif ($period === 'weekly') {
    // Change 'sale_date' if your date column has a different name
    $where = "WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($period === 'monthly') {
    // Change 'sale_date' if your date column has a different name
    $where = "WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
}

$sql = "
    SELECT 
        p.name, -- Change 'product_name' if your product name column is different
        SUM(s.quantity) AS total_qty -- Change 'quantity' if your quantity column is different
    FROM 
        product s -- Change 'sales' if your sales table is named differently
    JOIN 
        products p ON s.id = p.id -- Change 'products' and column names ('product_id', 'id') if they are different
    $where
    GROUP BY 
        p.name -- Change 'product_name' to match the column you are grouping by
    ORDER BY 
        total_qty DESC
    LIMIT 10
";

$stmt = $pdo->query($sql);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// The rest of the code for generating colors and JSON output is fine
$colors = [
    '#FF6384','#36A2EB','#FFCE56','#4BC0C0',
    '#9966FF','#FF9F40','#66FF66','#FF6699',
    '#3399FF','#FFCC99'
];

$data = [];
foreach ($results as $i => $row) {
    $row['color'] = $colors[$i % count($colors)];
    $data[] = $row;
}

header('Content-Type: application/json');
echo json_encode($data);

?>