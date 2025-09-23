<?php
// sales_api.php - Create this new file to handle API requests
require __DIR__. '/../config/config.php';
header('Content-Type: application/json');

if(empty($_SESSION['user_id'])){ 
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']); 
    exit; 
}

$pdo = db();
$period = $_GET['period'] ?? 'daily';

try {
    $data = [];
    
    switch($period) {
        case 'daily':
            // Get today's sales
            $sql = "SELECT 
                        COUNT(*) as total_orders, 
                        COALESCE(SUM(total), 0) as total_sales 
                    FROM orders 
                    WHERE DATE(created_at) = CURDATE()";
            break;
            
        case 'weekly':
            // Get this week's sales (Monday to Sunday)
            $sql = "SELECT 
                        COUNT(*) as total_orders, 
                        COALESCE(SUM(total), 0) as total_sales 
                    FROM orders 
                    WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
            break;
            
        case 'monthly':
            // Get this month's sales
            $sql = "SELECT 
                        COUNT(*) as total_orders, 
                        COALESCE(SUM(total), 0) as total_sales 
                    FROM orders 
                    WHERE YEAR(created_at) = YEAR(CURDATE()) 
                    AND MONTH(created_at) = MONTH(CURDATE())";
            break;
            
        default:
            throw new Exception('Invalid period');
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $data = [
        'totalSales' => (float)$result['total_sales'],
        'totalOrders' => (int)$result['total_orders'],
        'period' => ucfirst($period)
    ];
    
    echo json_encode($data);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}