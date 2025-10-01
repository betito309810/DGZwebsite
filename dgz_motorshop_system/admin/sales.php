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
        <div style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; justify-content: flex-end;">
            <a href="?export=csv" style="padding: 8px 15px; background-color: #007bff; color: white; border-radius: 3px; text-decoration: none;">
                Export to CSV
            </a>

            <button onclick="openSalesReportModal()" class="btn btn-success">
                Generate Sales Report
            </button>
        </div>

        <!-- Sales Report Modal -->
        <div id="salesReportModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
            <div class="modal-content" style="background: white; padding: 20px; border-radius: 8px; width: 90%; max-width: 500px; position: relative;">
                <span class="close" onclick="closeSalesReportModal()" style="position: absolute; right: 20px; top: 10px; font-size: 24px; cursor: pointer;">&times;</span>
                <h2 style="margin-bottom: 20px;">Generate Sales Report</h2>
                <form id="salesReportForm" method="GET" action="sales_report_pdf.php" style="display: flex; flex-direction: column; gap: 15px;">
                    <div>
                        <label for="reportPeriod" style="display: block; margin-bottom: 5px; font-weight: 500;">Select Period:</label>
                        <select id="reportPeriod" name="period" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="annually">Annually</option>
                        </select>
                    </div>

                    <div>
                        <label for="customerType" style="display: block; margin-bottom: 5px; font-weight: 500;">Select Customer Type:</label>
                        <select id="customerType" name="customer_type" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="all">All Customers</option>
                            <option value="walkin">Walk-in Customers</option>
                            <option value="online">Online Customers</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Generate Report</button>
                </form>
            </div>
        </div>

        <script>
            function openSalesReportModal() {
                document.getElementById('salesReportModal').style.display = 'flex';
            }

            function closeSalesReportModal() {
                document.getElementById('salesReportModal').style.display = 'none';
            }

            // Close modal when clicking outside
            document.getElementById('salesReportModal').addEventListener('click', function(event) {
                if (event.target === this) {
                    closeSalesReportModal();
                }
            });
        </script>


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
                            <?php $paymentDetails = parsePaymentProofValue($o['payment_proof'] ?? null, $o['reference_no'] ?? null); ?>

                            <td>
                                <?php if(!empty($paymentDetails['reference'])): ?>
                                    <span style="font-weight:600; color:#1d4ed8;"><?=htmlspecialchars($paymentDetails['reference'])?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">Not provided</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(!empty($paymentDetails['image'])): ?>
                                    <a href="../<?=htmlspecialchars(ltrim($paymentDetails['image'], '/'))?>" target="_blank" style="color:#0ea5e9; font-weight:600;">
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
                    <div class="period-selector">
                        <select class="period-dropdown" id="periodSelector">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="annually">Annually</option>
                        </select>
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
            <div class="chart-card">
                <div class="chart-header">
                    
                    <h2><i class="fa-solid fa-chart-pie"></i>
                        Sales Trend</h2>
                    <select id="timeFilter" aria-label="Select time period">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="annually">Annually</option>
                    </select>
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

    <script>
    // Profile and dropdown functions
    function toggleDropdown() {
        const dropdown = document.getElementById('userDropdown');
        if (!dropdown) return;
        dropdown.classList.toggle('show');
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        sidebar.classList.toggle('mobile-open');
    }

    function openProfileModal() {
        const profileModal = document.getElementById('profileModal');
        if (!profileModal) return;
        profileModal.classList.add('show');
        profileModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }

    function closeProfileModal() {
        const profileModal = document.getElementById('profileModal');
        if (!profileModal) return;
        profileModal.classList.remove('show');
        profileModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }

    // Sales Report Modal functions
    function openSalesReportModal() {
        document.getElementById('salesReportModal').style.display = 'flex';
    }

    function closeSalesReportModal() {
        document.getElementById('salesReportModal').style.display = 'none';
    }

    // Event listeners for profile modal
    document.addEventListener('DOMContentLoaded', () => {
        const profileTrigger = document.getElementById('profileTrigger');
        if (profileTrigger) {
            profileTrigger.addEventListener('click', openProfileModal);
        }

        const profileModalClose = document.getElementById('profileModalClose');
        if (profileModalClose) {
            profileModalClose.addEventListener('click', closeProfileModal);
        }

        // Close modal when clicking outside
        const salesReportModal = document.getElementById('salesReportModal');
        if (salesReportModal) {
            salesReportModal.addEventListener('click', function(event) {
                if (event.target === this) {
                    closeSalesReportModal();
                }
            });
        }

        // Initialize sales analytics
        initializeSalesAnalytics();
        initializePieChart();
    });

    // Sales Analytics Widget
    function initializeSalesAnalytics() {
        const periodSelector = document.getElementById('periodSelector');
        const totalSalesEl = document.getElementById('totalSales');
        const totalOrdersEl = document.getElementById('totalOrders');
        const widget = document.querySelector('.sales-widget');

        if (!periodSelector || !totalSalesEl || !totalOrdersEl) {
            console.error('Sales analytics elements not found');
            return;
        }

        /**
         * Fetch sales analytics data from backend API
         */
        async function fetchSalesData(period) {
            try {
                console.log(`Fetching sales data for period: ${period}`);
                const response = await fetch(`sales_api.php?period=${period}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                
                // Check if we got an error response
                if (data.error) {
                    throw new Error(data.error);
                }
                
                return data;
            } catch (error) {
                console.error('Error fetching sales data:', error);
                // Return fallback data
                return {
                    totalSales: 0,
                    totalOrders: 0,
                    period: 'Error'
                };
            }
        }

        /**
         * Update the sales analytics widget
         */
        async function updateStats(period) {
            // Add loading state
            if (widget) {
                widget.classList.add('loading');
            }

            try {
                const data = await fetchSalesData(period);
                console.log('Sales data received:', data);

                // Update values with formatted numbers
                totalSalesEl.textContent = `₱${data.totalSales.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                })}`;

                totalOrdersEl.textContent = data.totalOrders.toLocaleString();

            } catch (error) {
                console.error('Error updating stats:', error);
                // Show error state
                totalSalesEl.textContent = 'Error';
                totalOrdersEl.textContent = 'Error';
            } finally {
                // Remove loading state
                if (widget) {
                    widget.classList.remove('loading');
                }
            }
        }

        // Event listener for period selector
        periodSelector.addEventListener('change', function() {
            updateStats(this.value);
        });

        // Initialize with default period
        updateStats('daily');
    }

    // Pie Chart functionality
    function initializePieChart() {
        const ctx = document.getElementById('salesPieChart');
        const timeFilter = document.getElementById('timeFilter');
        
        if (!ctx || !timeFilter) {
            console.error('Pie chart elements not found');
            return;
        }

        let salesChart = null;

        /**
         * Load and render pie chart data
         */
        function loadChartData(period = 'daily') {
            console.log(`Loading chart data for period: ${period}`);
            
            fetch(`chart_data.php?period=${period}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Chart data received:', data);
                    
                    const labels = data.map(item => item.product_name);
                    const values = data.map(item => item.total_qty);
                    const colors = data.map(item => item.color);

                    // Destroy existing chart
                    if (salesChart) {
                        salesChart.destroy();
                    }

                    // Create new chart
                    salesChart = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: values,
                                backgroundColor: colors,
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { 
                                    display: false 
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (context) => {
                                            const label = context.label || "";
                                            const value = context.parsed || 0;
                                            return `${label}: ${value}`;
                                        }
                                    }
                                }
                            }
                        }
                    });

                    // Update legend
                    updateLegend(data, colors, values);
                })
                .catch(error => {
                    console.error('Error loading chart data:', error);
                    updateLegend([], [], []);
                });
        }

        /**
         * Update the chart legend
         */
        function updateLegend(data, colors, values) {
            const legendContainer = document.getElementById('chartLegend');
            if (!legendContainer) return;

            legendContainer.innerHTML = '';
            
            if (!data.length) {
                legendContainer.innerHTML = "<div class='legend-empty'>No data for this period.</div>";
                return;
            }

            data.forEach((item, index) => {
                const legendItem = document.createElement('div');
                legendItem.classList.add('legend-item');
                legendItem.innerHTML = `
                    <span class="legend-color" style="background-color:${colors[index]}"></span>
                    ${item.product_name} (${values[index]})
                `;
                legendContainer.appendChild(legendItem);
            });
        }

        // Event listener for time filter
        timeFilter.addEventListener('change', function() {
            loadChartData(this.value);
        });

        // Initial load
        loadChartData();
    }
</script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
        console.log('DOMContentLoaded event fired, initializing sales analytics and pie chart');
        // sales analytics and pie chart rendering
        const ctx = document.getElementById('salesPieChart').getContext('2d');
        let salesChart;

        /**
         * Load and render the sales pie chart data for the given period.
         * @param {string} period - The period to filter data by (daily, weekly, monthly, annually).
         */
        function loadChartData(period = 'daily') {
            fetch(`chart_data.php?period=${period}`)
                .then(response => {
                    if (!response.ok) {
                        console.error('Network response was not ok for chart_data.php:', response.statusText);
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Chart data received:', data);
                    const labels = data.map(item => item.product_name);
                    const values = data.map(item => item.total_qty);
                    const colors = data.map(item => item.color);

                    if (salesChart) {
                        salesChart.destroy();
                    }

                    salesChart = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: values,
                                backgroundColor: colors
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: (context) => {
                                            const label = context.label || "";
                                            const value = context.parsed || 0;
                                            return `${label}: ${value}`;
                                        }
                                    }
                                }
                            }
                        }
                    });

                    // Custom legend rendering below the chart
                    const legendContainer = document.getElementById('chartLegend');
                    legendContainer.innerHTML = '';
                    if (!data.length) {
                        legendContainer.innerHTML = "<div class='legend-empty'>No data for this period.</div>";
                        return;
                    }
                    data.forEach((item, index) => {
                        const legendItem = document.createElement('div');
                        legendItem.classList.add('legend-item');
                        legendItem.innerHTML = `
                        <span class="legend-color" style="background-color:${colors[index]}"></span>
                        ${item.product_name} (${values[index]})
                    `;
                        legendContainer.appendChild(legendItem);
                    });
                })
                .catch(error => {
                    console.error('Error loading chart data:', error);
                    const legendContainer = document.getElementById('chartLegend');
                    legendContainer.innerHTML = "<div class='legend-empty'>Failed to load data.</div>";
                });
        }

        // Event listener for pie chart period filter change
        document.getElementById('timeFilter').addEventListener('change', function () {
            loadChartData(this.value);
        });

        // Initial load of pie chart data with default period 'daily'
        loadChartData();

        //
        // Sales analytics widget logic
        const periodSelector = document.getElementById('periodSelector');
        const totalSalesEl = document.getElementById('totalSales');
        const totalOrdersEl = document.getElementById('totalOrders');
        const widget = document.querySelector('.sales-widget');

        /**
         * Fetch sales analytics data from backend API for the given period.
         * @param {string} period - The period to filter data by (daily, weekly, monthly, annually).
         * @returns {Promise<Object>} - The sales data object with totalSales and totalOrders.
         */
        async function fetchSalesData(period) {
            try {
                const response = await fetch(`sales_api.php?period=${period}`);

                if (!response.ok) {
                    console.error('Network response was not ok for sales_api.php:', response.statusText);
                    throw new Error('Network response was not ok');
                }

                const data = await response.json();
                console.log('Sales data received:', data);
                return data;
            } catch (error) {
                console.error('Error fetching sales data:', error);
                // Return fallback data in case of error
                return {
                    totalSales: 0,
                    totalOrders: 0,
                    period: 'Error'
                };
            }
        }

        /**
         * Update the sales analytics widget with fetched data.
         * @param {string} period - The period to filter data by.
         */
        async function updateStats(period) {
            // Add loading state
            widget.classList.add('loading');

            try {
                // Fetch real data from backend
                const data = await fetchSalesData(period);

                // Update values with formatted numbers
                totalSalesEl.textContent = `₱${data.totalSales.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        })}`;

                totalOrdersEl.textContent = data.totalOrders.toLocaleString();

            } catch (error) {
                console.error('Error updating stats:', error);
                // Show error state
                totalSalesEl.textContent = 'Error';
                totalOrdersEl.textContent = 'Error';
            } finally {
                // Remove loading state
                widget.classList.remove('loading');
            }
        }

        // Event listener for sales analytics period selector change
        periodSelector.addEventListener('change', function () {
            updateStats(this.value);
        });

        // Initialize sales analytics widget with default period 'daily'
        updateStats('daily');

        // Add interactivity animation on stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function () {
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 150);
            });
        });

        // Optional: Auto-refresh sales analytics data every 30 seconds
        setInterval(() => {
            const currentPeriod = periodSelector.value;
            updateStats(currentPeriod);
        }, 30000);



        // Removed duplicate sales analytics chart block to fix 'ctx' redeclaration error and JS conflicts.

        // Ensure modal visibility
        const salesReportModal = document.getElementById('salesReportModal');
        const closeModalButton = document.getElementById('closeModal');

        if (!salesReportModal || !closeModalButton) {
            console.error('Modal or button elements not found');
            return;
        }

        closeModalButton.addEventListener('click', () => {
            salesReportModal.style.display = 'none';
        });

        window.addEventListener('click', (event) => {
            if (event.target === salesReportModal) {
                salesReportModal.style.display = 'none';
            }
        });

        // Transaction details modal logic
        const transactionModal = document.getElementById('transactionModal');
        const modalCloseBtn = transactionModal.querySelector('.close');

        // Event listener for transaction row clicks
        document.querySelectorAll('.transaction-row').forEach(row => {
            row.addEventListener('click', function() {
                const orderId = this.getAttribute('data-order-id');
                if (orderId) {
                    loadTransactionDetails(orderId);
                }
            });
        });

        // Close modal when clicking the close button
        modalCloseBtn.addEventListener('click', () => {
            transactionModal.style.display = 'none';
        });

        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            if (event.target === transactionModal) {
                transactionModal.style.display = 'none';
            }
        });

        /**
         * Load and display transaction details in the modal.
         * @param {string} orderId - The order ID to fetch details for.
         */
        async function loadTransactionDetails(orderId) {
            try {
                // Fetch transaction details from get_transaction_details.php
                const response = await fetch(`get_transaction_details.php?order_id=${orderId}`);
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                const data = await response.json();

                // Build modal content dynamically to match POS modal style
                const modalBody = document.querySelector('#transactionModal .modal-body');
                modalBody.innerHTML = `
                    <div class="transaction-info">
                        <h2>Transaction Details</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <label>Customer:</label>
                                <span>${data.order.customer_name || 'N/A'}</span>
                            </div>
                            <div class="info-item">
                                <label>Invoice #:</label>
                                <span>${data.order.invoice_number || 'N/A'}</span>
                            </div>
                            <div class="info-item">
                                <label>Date:</label>
                                <span>${new Date(data.order.created_at).toLocaleString() || 'N/A'}</span>
                            </div>
                            <div class="info-item">
                                <label>Status:</label>
                                <span>${data.order.status || 'N/A'}</span>
                            </div>
                            <div class="info-item">
                                <label>Payment Method:</label>
                                <span>${data.order.payment_method || 'N/A'}</span>
                            </div>
                            ${data.order.customer_name.toLowerCase() === 'walk-in' ? '' : `
                            <div class="info-item">
                                <label>Email:</label>
                                <span>${data.order.email || 'N/A'}</span>
                            </div>
                            <div class="info-item">
                                <label>Phone:</label>
                                <span>${data.order.contact || 'N/A'}</span>
                            </div>
                            <div class="info-item">
                                <label>Reference:</label>
                                <span>${data.order.reference_number || 'N/A'}</span>
                            </div>
                            `}
                        </div>
                    </div>
                    <div class="order-items">
                        <h4>Order Items</h4>
                        <div class="table-responsive">
                            <table class="items-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Quantity</th>
                                        <th>Price</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.items.map(item => `
                                        <tr>
                                            <td>${item.name || item.product_name || 'N/A'}</td>
                                            <td>${item.qty || item.quantity || 0}</td>
                                            <td>₱${parseFloat(item.price).toFixed(2)}</td>
                                            <td>₱${(parseFloat(item.price) * (item.qty || item.quantity || 0)).toFixed(2)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" style="text-align: right; font-weight: 600;">Total:</td>
                                        <td id="modal-total">₱${parseFloat(data.order.total).toFixed(2)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                `;

                // Show modal
                transactionModal.style.display = 'block';

            } catch (error) {
                console.error('Error loading transaction details:', error);
                alert('Failed to load transaction details. Please try again.');
            }
    </script>
    <script src="../assets/js/notifications.js"></script>
    <script src="../assets/js/transaction-details.js"></script>
</body>

</html>
