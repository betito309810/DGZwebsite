<?php
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
/**
 * Sales Report PDF Generator
 * Generates a printable PDF sales report with filters for period and customer type.
 * Uses Dompdf library to convert HTML to PDF.
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../vendor/autoload.php'; // Load Dompdf and other dependencies

use Dompdf\Dompdf;
use Dompdf\Options;

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();

// Get filter parameters
$period = $_GET['period'] ?? 'daily';
$customer_type = $_GET['customer_type'] ?? 'all'; // 'all', 'walkin', 'online'

// Validate parameters
$valid_periods = ['daily', 'weekly', 'monthly', 'annually'];
$valid_customer_types = ['all', 'walkin', 'online'];

if (!in_array($period, $valid_periods)) {
    $period = 'daily';
}
if (!in_array($customer_type, $valid_customer_types)) {
    $customer_type = 'all';
}

// Build SQL query based on filters
$sql = "SELECT * FROM orders WHERE status IN ('approved','completed')";

// Calculate start and end dates for the selected period
$start_date = null;
$end_date = null;

switch ($period) {
    case 'daily':
        $sql .= " AND DATE(created_at) = CURDATE()";
        $period_title = 'Daily Sales Report - ' . date('F j, Y');
        $start_date = date('Y-m-d 00:00:00');
        $end_date = date('Y-m-d 23:59:59');
        break;
    case 'weekly':
        $sql .= " AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
        $period_title = 'Weekly Sales Report - Week ' . date('W, Y');
        $start_date = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end_date = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        break;
    case 'monthly':
        $sql .= " AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())";
        $period_title = 'Monthly Sales Report - ' . date('F Y');
        $start_date = date('Y-m-01 00:00:00');
        $end_date = date('Y-m-t 23:59:59');
        break;
    case 'annually':
        $sql .= " AND YEAR(created_at) = YEAR(CURDATE())";
        $period_title = 'Annual Sales Report - ' . date('Y');
        $start_date = date('Y-01-01 00:00:00');
        $end_date = date('Y-12-31 23:59:59');
        break;
}

// Add customer type filter
if ($customer_type === 'walkin') {
    // Assuming walk-in customers use 'cash' payment method
    $sql .= " AND payment_method = 'cash'";
    $period_title .= ' (Walk-in Customers)';
} elseif ($customer_type === 'online') {
    // Assuming online customers use non-cash payment methods
    $sql .= " AND payment_method != 'cash'";
    $period_title .= ' (Online Customers)';
} else {
    $period_title .= ' (All Customers)';
}

$top_items_sql = "
    SELECT p.name AS product_name, COALESCE(SUM(oi.qty),0) AS total_qty
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    INNER JOIN products p ON p.id = oi.product_id
    WHERE o.status IN ('approved','completed')
    AND o.created_at BETWEEN :start_date AND :end_date
    GROUP BY p.id, p.name
    ORDER BY total_qty DESC
    LIMIT 5
";

$top_items_stmt = $pdo->prepare($top_items_sql);
$top_items_stmt->bindValue(':start_date', $start_date);
$top_items_stmt->bindValue(':end_date', $end_date);
$top_items_stmt->execute();
$top_items = $top_items_stmt->fetchAll(PDO::FETCH_ASSOC);

$sql .= " ORDER BY created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    $total_orders = count($orders);
    $total_sales = array_sum(array_column($orders, 'total'));

$date_range_display = 'Date: ' . date('m/d/Y', strtotime($start_date)) . ' to ' . date('m/d/Y', strtotime($end_date));

// Generate HTML for PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($period_title) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
        .header h1 { margin: 0; color: #333; }
        .header p { margin: 5px 0; color: #666; }
        .date-range { font-size: 14px; margin-bottom: 15px; font-weight: bold; }
        .summary { margin-bottom: 30px; }
        .summary table { width: 100%; border-collapse: collapse; }
        .summary th, .summary td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        .summary th { background-color: #f5f5f5; }
        .top-items { margin-bottom: 30px; }
        .top-items table { width: 100%; border-collapse: collapse; }
        .top-items th, .top-items td { padding: 8px; text-align: left; border: 1px solid #ddd; font-size: 12px; }
        .top-items th { background-color: #f5f5f5; }
        .orders { margin-top: 30px; }
        .orders h2 { margin-bottom: 15px; }
        .orders table { width: 100%; border-collapse: collapse; }
        .orders th, .orders td { padding: 8px; text-align: left; border: 1px solid #ddd; font-size: 12px; }
        .orders th { background-color: #f5f5f5; }
        .total-row { font-weight: bold; background-color: #e9ecef; }
        .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>DGZ Motorshop</h1>
        <h2>' . htmlspecialchars($period_title) . '</h2>
        <p>Generated on: ' . date('F j, Y g:i A') . '</p>
    </div>

    <div class="date-range">' . htmlspecialchars($date_range_display) . '</div>

    <div class="summary">
        <h2>Sales Summary</h2>
        <table>
            <tr>
                <th>Total Orders</th>
                <td>' . number_format($total_orders) . '</td>
            </tr>
            <tr>
                <th>Total Sales Amount</th>
<td>&#8369;' . number_format($total_sales, 2) . '</td>
            </tr>
        </table>
    </div>

    <div class="top-items">
        <h2>Top 5 Items Sold</h2>
        <table>
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Quantity Sold</th>
                </tr>
            </thead>
            <tbody>';

foreach ($top_items as $item) {
    $html .= '
                <tr>
                    <td>' . htmlspecialchars($item['product_name']) . '</td>
                    <td>' . number_format($item['total_qty']) . '</td>
                </tr>';
}
$html .= '
            </tbody>
        </table>
    </div>

    <div class="orders">
        <h2>Order Details</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Invoice</th>
                    <th>Customer Name</th>
                    <th>Contact</th>
                    <th>Payment Method</th>
                    <th>Total</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>';
foreach ($orders as $order) {
    $html .= '
                <tr>
                    <td>' . htmlspecialchars($order['id']) . '</td>
                    <td>' . htmlspecialchars($order['invoice_number'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($order['customer_name']) . '</td>
                    <td>' . htmlspecialchars($order['contact'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($order['payment_method']) . '</td>
<td>' . number_format($order['total'], 2) . '</td>
                    <td>' . date('M d, Y g:i A', strtotime($order['created_at'])) . '</td>
                </tr>';
}


    $html .= '
                </tbody>
            </table>
        </div>

        <div class="footer">
            <p>This report was generated automatically by the DGZ Motorshop System.</p>
        </div>
    </body>
    </html>';

    // Configure Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans'); // Changed to DejaVu Sans for better Unicode support including peso sign
    $options->set('isFontSubsettingEnabled', true);
    $options->set('isUnicodeEnabled', true); // Ensure Unicode support

    // Replace peso sign encoding with actual character
    $html = str_replace('&#8369;', '₱', $html);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Output PDF
    $filename = 'sales_report_' . $period . '_' . $customer_type . '_' . date('Y-m-d') . '.pdf';
    $dompdf->stream($filename, array('Attachment' => true));

} catch (Exception $e) {
    // Handle errors
    http_response_code(500);
    echo 'Error generating report: ' . htmlspecialchars($e->getMessage());
}
