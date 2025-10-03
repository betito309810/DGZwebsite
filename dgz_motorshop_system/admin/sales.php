<?php
require __DIR__ . '/../config/config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }

$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();

/**
 * Generate sales report for a given period.
 * Supports 'daily', 'weekly', 'monthly', and 'annually' periods.
 * Returns an associative array with total orders and total sales amount.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $period The period for the report: 'daily', 'weekly', 'monthly', or 'annually'.
 * @return array Associative array with keys 'total_orders' and 'total_sales'.
 */
function generateSalesReport(PDO $pdo, string $period): array
{
    $sql = '';
    switch ($period) {
        case 'daily':
            // Sales for today
            $sql = "SELECT 
                        COUNT(*) AS total_orders, 
                        COALESCE(SUM(total), 0) AS total_sales 
                    FROM orders 
                    WHERE DATE(created_at) = CURDATE()
                    AND status IN ('approved','completed')";
            break;
        case 'weekly':
            // Sales for current week (Monday to Sunday)
            $sql = "SELECT 
                        COUNT(*) AS total_orders, 
                        COALESCE(SUM(total), 0) AS total_sales 
                    FROM orders 
                    WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
                    AND status IN ('approved','completed')";
            break;
        case 'monthly':
            // Sales for current month
            $sql = "SELECT 
                        COUNT(*) AS total_orders, 
                        COALESCE(SUM(total), 0) AS total_sales 
                    FROM orders 
                    WHERE YEAR(created_at) = YEAR(CURDATE()) 
                    AND MONTH(created_at) = MONTH(CURDATE())
                    AND status IN ('approved','completed')";
            break;
        case 'annually':
            // Sales for current year
            $sql = "SELECT 
                        COUNT(*) AS total_orders, 
                        COALESCE(SUM(total), 0) AS total_sales 
                    FROM orders 
                    WHERE YEAR(created_at) = YEAR(CURDATE())
                    AND status IN ('approved','completed')";
            break;
        default:
            // Invalid period, return zeros
            return ['total_orders' => 0, 'total_sales' => 0.0];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_orders' => (int)($result['total_orders'] ?? 0),
        'total_sales' => (float)($result['total_sales'] ?? 0.0)
    ];
}

// Handle CSV export FIRST - before any other queries
if(isset($_GET['export']) && $_GET['export'] == 'csv') {
    // Get ALL orders for export
    $export_sql = "SELECT * FROM orders WHERE status IN ('approved','completed') ORDER BY created_at DESC";
    $export_orders = $pdo->query($export_sql)->fetchAll();
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Invoice','Customer Name','Contact','Address','Total','Payment Method','Payment Reference','Proof Image','Status','Created At']);
    foreach($export_orders as $o) {

        fputcsv($out, [
            $o['id'],
            $o['invoice_number'] ?? '',
            $o['customer_name'],
            $o['contact'],
            $o['address'],
            $o['total'],
            $o['payment_method'],
            ($details = parsePaymentProofValue($o['payment_proof'] ?? null, $o['reference_no'] ?? null))['reference'],
            $details['image'],
            $o['status'],
            $o['created_at']
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/includes/inventory_notifications.php';
$notificationManageLink = 'inventory.php';
$inventoryNotificationData = loadInventoryNotifications($pdo);
$inventoryNotifications = $inventoryNotificationData['notifications'];
$inventoryNotificationCount = $inventoryNotificationData['active_count'];

// Fetch the authenticated user's information for the profile modal
$current_user = null;
try {
    $stmt = $pdo->prepare('SELECT name, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();
} catch (Exception $e) {
    error_log('User lookup failed: ' . $e->getMessage());
}

function format_profile_date(?string $datetime): string
{
    if (!$datetime) {
        return 'N/A';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return 'N/A';
    }

    return date('F j, Y g:i A', $timestamp);
}

$profile_name = $current_user['name'] ?? 'N/A';
$profile_role = !empty($current_user['role']) ? ucfirst($current_user['role']) : 'N/A';
$profile_created = format_profile_date($current_user['created_at'] ?? null);

// Pagination variables
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Count total records
$count_sql = "SELECT COUNT(*) FROM orders WHERE status IN ('approved','completed')";
$total_records = $pdo->query($count_sql)->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get orders with pagination
$sql = "SELECT * FROM orders WHERE status IN ('approved','completed') ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

// Calculate showing info
$start_record = $offset + 1;
$end_record = min($offset + $records_per_page, $total_records);
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/sales/sales.css">
    <link rel="stylesheet" href="../assets/css/sales/piechart.css">
    <link rel="stylesheet" href="../assets/css/sales/transaction-modal.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <title>Sales</title>
</head>

<body>
    <!-- Sidebar -->
    <?php
        $activePage = 'sales.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>

    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Sales</h2>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/partials/notification_menu.php'; ?>
                <div class="user-menu">
                    <div class="user-avatar" onclick="toggleDropdown()">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <button type="button" class="dropdown-item" id="profileTrigger">
                            <i class="fas fa-user-cog"></i> Profile
                        </button>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <?php if ($role === 'admin'): ?>
                        <a href="userManagement.php" class="dropdown-item">
                            <i class="fas fa-users-cog"></i> User Management
                        </a>
                        <?php endif; ?>
                        <a href="login.php?logout=1" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Export Button and PDF Report Generator -->
        <div class="action-buttons">
            <a href="?export=csv" class="btn btn-export">
                <i class="fas fa-file-export"></i>
                Export to CSV
            </a>
            <button type="button" id="openSalesReport" class="btn btn-generate">
                <i class="fas fa-chart-line"></i>
                Generate Sales Report
            </button>
        </div>

        <!-- Table Container -->
        <div class="table-container">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Invoice</th>
                            <th>Customer Name</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th>Total</th>
                            <th>Payment Method</th>
                            <th>Reference</th>
                            <th>Proof</th>
                            <th>Status</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($orders)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 40px; color: #6b7280;">
                                <i class="fas fa-inbox"
                                    style="font-size: 48px; margin-bottom: 10px; display: block;"></i>
                                No sales records found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach($orders as $o): ?>
                        <tr class="transaction-row" data-order-id="<?=$o['id']?>" style="cursor: pointer;">
                            <td><?=$o['id']?></td>
                            <td><?=$o['invoice_number'] ? htmlspecialchars($o['invoice_number']) : 'N/A'?></td>
                            <td><?=htmlspecialchars($o['customer_name'])?></td>
                            <td><?=htmlspecialchars($o['contact'] ?? 'N/A')?></td>
                            <td><?=htmlspecialchars($o['address'] ?? 'N/A')?></td>
                            <td>₱<?=number_format($o['total'],2)?></td>
                            <td><?=htmlspecialchars($o['payment_method'])?></td>
                            <?php
                            $paymentDetails = parsePaymentProofValue($o['payment_proof'] ?? null, $o['reference_no'] ?? null);
                            $proofUrl = normalizePaymentProofPath($paymentDetails['image'] ?? '');
                            ?>

                            <td>
                                <?php if(!empty($paymentDetails['reference'])): ?>
                                    <span style="font-weight:600; color:#1d4ed8;"><?=htmlspecialchars($paymentDetails['reference'])?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">Not provided</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($proofUrl !== ''): ?>
                                    <a href="<?=htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8')?>" target="_blank" style="color:#0ea5e9; font-weight:600;">
                                        View Proof
                                    </a>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">No image</span>
                                <?php endif; ?>
                            </td>
                            <td><?=htmlspecialchars($o['status'])?></td>
                            <td><?=date('M d, Y g:i A', strtotime($o['created_at']))?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_records > 0): ?>
            <!-- Pagination -->
            <div class="pagination-container">
                <div class="pagination-info">
                    Showing <?=$start_record?> to <?=$end_record?> of <?=$total_records?> entries
                </div>
                <div class="pagination">
                    <!-- Previous button -->
                    <?php if($current_page > 1): ?>
                    <a href="?page=<?=($current_page-1)?>" class="prev">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                    <?php else: ?>
                    <span class="prev disabled">
                        <i class="fas fa-chevron-left"></i> Prev
                    </span>
                    <?php endif; ?>

                    <!-- Page numbers -->
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    // Show first page if not in range
                    if($start_page > 1): ?>
                    <a href="?page=1">1</a>
                    <?php if($start_page > 2): ?>
                    <span>...</span>
                    <?php endif;
                    endif;
                    
                    // Show page numbers in range
                    for($i = $start_page; $i <= $end_page; $i++):
                        if($i == $current_page): ?>
                    <span class="current"><?=$i?></span>
                    <?php else: ?>
                    <a href="?page=<?=$i?>"><?=$i?></a>
                    <?php endif;
                    endfor;
                    
                    // Show last page if not in range
                    if($end_page < $total_pages):
                        if($end_page < $total_pages - 1): ?>
                    <span>...</span>
                    <?php endif; ?>
                    <a href="?page=<?=$total_pages?>"><?=$total_pages?></a>
                    <?php endif; ?>

                    <!-- Next button -->
                    <?php if($current_page < $total_pages): ?>
                    <a href="?page=<?=($current_page+1)?>" class="next">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php else: ?>
                    <span class="next disabled">
                        Next <i class="fas fa-chevron-right"></i>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sales Widget -->
        <!-- Add this after the Export Button and before the Table Container -->
        <div class="stat-overview">
            <div class="sales-widget">
                <div class="widget-header">
                    <h2 class="widget-title">
                        <i class="fas fa-chart-line"></i>
                        Sales Analytics
                    </h2>
                    <div class="widget-controls" id="analyticsFilters">
                        <div class="control-group">
                            <label for="analyticsPeriod" class="control-label">View</label>
                            <select class="period-dropdown" id="analyticsPeriod" data-period-select>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>
                        <div class="control-group">
                            <label for="analyticsPicker" class="control-label" id="analyticsPickerLabel">Select day</label>
                            <input id="analyticsPicker" class="period-input" type="date" data-period-input>
                            <span class="control-hint" id="analyticsRangeHint"></span>
                        </div>
                    </div>
                </div>

                <div class="stats-container">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div class="stat-value" id="totalSales">₱0.00</div>
                        <div class="stat-label">Total Sales</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-value" id="totalOrders">0</div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                </div>


            </div>

            <!-- piecharat widget -->
            <div class="chart-widget">
                <div class="chart-header">
                    <h2><i class="fa-solid fa-chart-pie"></i>
                        Sales Trend</h2>
                    <div class="widget-controls" id="trendFilters">
                        <div class="control-group">
                            <label for="trendPeriod" class="control-label">View</label>
                            <select id="trendPeriod" class="period-dropdown" data-period-select>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>
                        <div class="control-group">
                            <label for="trendPicker" class="control-label" id="trendPickerLabel">Select day</label>
                            <input id="trendPicker" class="period-input" type="date" data-period-input>
                            <span class="control-hint" id="trendRangeHint"></span>
                        </div>
                    </div>
                </div>
                <div class="chart-canvas-wrap">
                    
                    <canvas id="salesPieChart"></canvas>
                   
                </div>
                
                <div class="chart-legend" id="chartLegend" aria-live="polite"></div>
               
            </div>
        </div>
    </main>

    <!-- Transaction Details Modal -->
        <!-- Transaction Details Modal -->
    <div id="transactionModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <div class="modal-body">
                <!-- Content will be dynamically inserted here -->
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="profileModal" aria-hidden="true">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
            <button type="button" class="modal-close" id="profileModalClose" aria-label="Close profile information">
                <i class="fas fa-times"></i>
            </button>
            <h3 id="profileModalTitle">Profile information</h3>
            <div class="profile-info">
                <div class="profile-row">
                    <span class="profile-label">Name</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_name) ?></span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">Role</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_role) ?></span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">Date created</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_created) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- New Modal for Sales Report Options -->
    <div id="salesReportModal" class="modal">
        <div class="modal-content">
            <span class="close" id="closeModal">&times;</span>
            <h2>Generate Sales Report</h2>
            <form id="salesReportForm" method="GET" action="sales_report_pdf.php">
                <label for="reportPeriod">Select Period:</label>
                <select id="reportPeriod" name="period">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="annually">Annually</option>
                </select>

                <label for="customerType">Select Customer Type:</label>
                <select id="customerType" name="customer_type">
                    <option value="all">All Customers</option>
                    <option value="walkin">Walk-in Customers</option>
                    <option value="online">Online Customers</option>
                </select>

                <button type="submit" class="btn btn-primary">Generate Report</button>
            </form>
        </div>
    </div>
    <!-- Sales period helpers -->
    <script src="../assets/js/sales/periodFilters.js"></script>
    <!-- Sales report modal -->
    <script src="../assets/js/sales/salesReportModal.js"></script>
    <!-- Sales analytics widget -->
    <script src="../assets/js/sales/salesAnalytics.js"></script>
    <!-- Pie chart widget -->
    <script src="../assets/js/sales/pieChart.js"></script>
    
        
    <!--Transaction modal-->
    <script src="../assets/js/sales/transactionModal.js"></script>
    <script src="../assets/js/notifications.js"></script>
    <script src="../assets/js/transaction-details.js"></script>
    <script src="../assets/js/dashboard/userMenu.js"></script>
</body>

</html>
