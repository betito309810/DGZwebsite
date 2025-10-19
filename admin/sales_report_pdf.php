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
require __DIR__ . '/includes/sales_periods.php';
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
$value = $_GET['value'] ?? null;
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

$periodInfo = resolve_sales_period($period, $value);
$period = $periodInfo['period'];

$sql = "SELECT * FROM orders WHERE status IN ('approved','completed') AND created_at >= :start AND created_at < :end";
$queryParams = [
    ':start' => $periodInfo['start'],
    ':end' => $periodInfo['end'],
];

$period_title = sprintf('%s Sales Report - %s', ucfirst($periodInfo['period']), $periodInfo['label']);

// Add customer type filter
$topItemsFilter = '';
if ($customer_type === 'walkin') {
    // Assuming walk-in customers use 'cash' payment method
    $sql .= " AND payment_method = 'cash'";
    $topItemsFilter = " AND o.payment_method = 'cash'";
    $period_title .= ' (Walk-in Customers)';
} elseif ($customer_type === 'online') {
    // Assuming online customers use non-cash payment methods
    $sql .= " AND payment_method != 'cash'";
    $topItemsFilter = " AND o.payment_method != 'cash'";
    $period_title .= ' (Online Customers)';
} else {
    $period_title .= ' (All Customers)';
}

$top_items_sql = "
    SELECT
        CASE
            WHEN p.id IS NOT NULL AND TRIM(COALESCE(p.name, '')) <> '' THEN p.name
            WHEN TRIM(COALESCE(oi.description, '')) <> '' THEN oi.description
            ELSE 'Service'
        END AS item_name,
        COALESCE(SUM(oi.qty), 0) AS total_qty,
        COALESCE(SUM(oi.qty * oi.price), 0) AS total_sales
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE o.status IN ('approved','completed')
    AND o.created_at >= :start AND o.created_at < :end
    $topItemsFilter
    GROUP BY item_name
    ORDER BY total_qty DESC
";

$top_items_stmt = $pdo->prepare($top_items_sql);
$top_items_stmt->execute($queryParams);
$top_items = $top_items_stmt->fetchAll(PDO::FETCH_ASSOC);

$sql .= " ORDER BY created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($queryParams);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load logo for header
    $logoPath = realpath(__DIR__ . '/../dgz_motorshop_system/assets/logo.png');
    $logoDataUri = '';
    if ($logoPath && file_exists($logoPath)) {
        $logoData = file_get_contents($logoPath);
        if ($logoData !== false) {
            $logoDataUri = 'data:image/png;base64,' . base64_encode($logoData);
        }
    }

    // Calculate totals
    $total_orders = count($orders);
    $total_sales = array_sum(array_map('floatval', array_column($orders, 'total')));

    $rangeStartDisplay = date('m/d/Y', strtotime($periodInfo['range_start']));
    $rangeEndDisplay = date('m/d/Y', strtotime($periodInfo['range_end']));
    $date_range_display = $rangeStartDisplay === $rangeEndDisplay
        ? 'Date: ' . $rangeStartDisplay
        : 'Date: ' . $rangeStartDisplay . ' to ' . $rangeEndDisplay;

// Generate HTML for PDF
    $logoImgTag = $logoDataUri
        ? '<img src="' . $logoDataUri . '" alt="DGZ Motorshop Logo" class="logo">'
        : '';

    $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($period_title) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }

        .header .logo { margin-bottom: 8px; }
        .header .logo img { max-height: 64px; }

        .header .logo { display: block; margin: 0 auto 12px; max-width: 160px; height: auto; }

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
        .total-row { font-weight: bold; background-color: #e9ecef; }
        .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">

        ' . $logoImgTag . '

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
            <td> PHP ' . number_format($total_sales, 2) . '</td>
            </tr>
        </table>
    </div>

    <div class="top-items">
        <h2>Items Sold</h2>
        <table>
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Quantity Sold</th>
                    <th>Total Sales</th>
                </tr>
            </thead>
            <tbody>';

foreach ($top_items as $item) {
    $html .= '
                <tr>
                    <td>' . htmlspecialchars($item['item_name']) . '</td>
                    <td>' . number_format($item['total_qty']) . '</td>
                    <td> PHP ' . number_format($item['total_sales'], 2) . '</td>
                </tr>';
}
if (empty($top_items)) {
    $html .= '
                <tr>
                    <td colspan="3" style="text-align:center; color:#64748b;">No items recorded for this period.</td>
                </tr>';
}
$html .= '
            </tbody>
        </table>
    </div>
    </body>
    </html>';

    // Rebuild HTML using a safe template that avoids any embedded PHP in the content
    $generatedOnDisplay = date('F j, Y g:i A');
    $safeTitle = htmlspecialchars($period_title, ENT_QUOTES, 'UTF-8');
    $safeDateRange = htmlspecialchars($date_range_display, ENT_QUOTES, 'UTF-8');
    $totalOrdersStr = number_format($total_orders);
    $totalSalesStr = number_format($total_sales, 2);

    // Optional logo embed (reused from receipt)
    $logoBase64 = '';
    $logoPath = __DIR__ . '/../dgz_motorshop_system/assets/logo.png';
    if (is_file($logoPath)) {
        $data = @file_get_contents($logoPath);
        if ($data !== false) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode($data);
        }
    }
    $logoHtml = $logoBase64 !== '' ? '<div class="logo"><img src="' . $logoBase64 . '" alt="DGZ Motorshop Logo"></div>' : '';

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{$safeTitle}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
        .header .logo { margin-bottom: 8px; }
        .header .logo img { max-height: 64px; }
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
        .total-row { font-weight: bold; background-color: #e9ecef; }
        .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        {$logoHtml}
        <h1>DGZ Motorshop</h1>
        <h2>{$safeTitle}</h2>
        <p>Generated on: {$generatedOnDisplay}</p>
    </div>

    <div class="date-range">{$safeDateRange}</div>

    <div class="summary">
        <h2>Sales Summary</h2>
        <table>
            <tr>
                <th>Total Orders</th>
                <td>{$totalOrdersStr}</td>
            </tr>
            <tr>
                <th>Total Sales Amount</th>
                <td> PHP {$totalSalesStr}</td>
            </tr>
        </table>
    </div>

    <div class="top-items">
        <h2>Items Sold</h2>
        <table>
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Quantity Sold</th>
                    <th>Total Sales</th>
                </tr>
            </thead>
            <tbody>
HTML;

    // Append item rows into the new HTML template
    if (!empty($top_items)) {
        foreach ($top_items as $item) {
            $name = htmlspecialchars($item['item_name'] ?? 'Item', ENT_QUOTES, 'UTF-8');
            $qty = number_format((float)($item['total_qty'] ?? 0));
            $sales = number_format((float)($item['total_sales'] ?? 0), 2);
            $html .= "\n                <tr>\n                    <td>{$name}</td>\n                    <td>{$qty}</td>\n                    <td> PHP {$sales}</td>\n                </tr>";
        }
    } else {
        $html .= "\n                <tr>\n                    <td colspan=\"3\" style=\"text-align:center; color:#64748b;\">No items recorded for this period.</td>\n                </tr>";
    }

    // Close table and document
    $html .= "\n            </tbody>\n        </table>\n    </div>\n</body>\n</html>";

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
