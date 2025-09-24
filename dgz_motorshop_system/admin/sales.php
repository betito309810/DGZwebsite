<?php
require __DIR__ . '/../config/config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }

$pdo = db();
$role = $_SESSION['role'] ?? '';

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
     <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <title>Sales</title>
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="../assets/logo.png" alt="Company Logo">
            </div>
        </div>
        <nav class="nav-menu">
            <div class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-home nav-icon"></i>
                    Dashboard
                </a>
            </div>
            <div class="nav-item">
                <a href="products.php" class="nav-link">
                    <i class="fas fa-box nav-icon"></i>
                    Products
                </a>
            </div>
            <div class="nav-item">
                <a href="sales.php" class="nav-link active">
                    <i class="fas fa-chart-line nav-icon"></i>
                    Sales
                </a>
            </div>
            <div class="nav-item">
                <a href="pos.php" class="nav-link">
                    <i class="fas fa-cash-register nav-icon"></i>
                    POS
                </a>
            </div>
            <div class="nav-item">
                <a href="inventory.php" class="nav-link">
                    <i class="fas fa-boxes nav-icon"></i>
                    Inventory
                </a>
            </div>

            <div class="nav-item">
                <a href="stockRequests.php" class="nav-link">
                    <i class="fas fa-clipboard-list nav-icon"></i>
                    Stock Requests
                </a>
            </div>


        </nav>
    </aside>

    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Sales</h2>
            </div>
            <div class="user-menu">
                <div class="user-avatar" onclick="toggleDropdown()">
                    <i class="fas fa-user"></i>
                </div>
                <div class="dropdown-menu" id="userDropdown">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user-cog"></i> Profile
                    </a>
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
        </header>

        <!-- Export Button -->
        <div style="margin-bottom: 20px;">
            <a href="?export=csv">
                Export to CSV
            </a>
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
    <div id="transactionModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Transaction Details</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <div class="transaction-info">
                    <h4>Order Information</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Customer:</label>
                            <span id="modal-customer"></span>
                        </div>
                        <div class="info-item">
                            <label>Invoice #:</label>
                            <span id="modal-invoice"></span>
                        </div>
                        <div class="info-item">
                            <label>Date:</label>
                            <span id="modal-date"></span>
                        </div>
                        <div class="info-item">
                            <label>Status:</label>
                            <span id="modal-status"></span>
                        </div>
                        <div class="info-item">
                            <label>Payment Method:</label>
                            <span id="modal-payment"></span>
                        </div>
                        <div class="info-item" id="modal-reference-wrapper" style="display:none;">
                            <label>Reference:</label>
                            <span id="modal-reference"></span>
                        </div>
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
                            <tbody id="modal-items">
                                <!-- Items will be inserted here -->
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" style="text-align: right;"><strong>Total:</strong></td>
                                    <td id="modal-total"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // sales trend piechart
        (function(){
    const ctx = document.getElementById('salesPieChart').getContext('2d');
    let salesChart = null;

    function renderChart(payload) {
        const labels = payload.map(item => item.product_name);
        const values = payload.map(item => Number(item.total_qty));
        const colors = payload.map(item => item.color);

        if (salesChart) {
            salesChart.destroy();
        }

        salesChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
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

        // Render custom legend below the chart
        const legend = document.getElementById('chartLegend');
        legend.innerHTML = "";
        if (!payload.length) {
            legend.innerHTML = "<div class='legend-empty'>No data for this period.</div>";
            return;
        }
        payload.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'legend-item';
            row.innerHTML = `
                <span class="legend-swatch" style="background:${item.color}"></span>
                <span class="legend-name">${item.product_name}</span>
                <span class="legend-count">${item.total_qty}</span>
            `;
            legend.appendChild(row);
        });
    }

    async function loadChartData(period='daily') {
        try {
            const res = await fetch(`chart_data.php?period=${encodeURIComponent(period)}`, { cache: 'no-store' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            renderChart(Array.isArray(data) ? data : []);
        } catch (e) {
            console.error(e);
            renderChart([]);
        }
    }

    document.getElementById('timeFilter').addEventListener('change', function() {
        loadChartData(this.value);
    });

    // Initial load
    loadChartData('daily');
})();



        // sales analytics chart
        const ctx = document.getElementById('salesPieChart').getContext('2d');
        let salesChart;

        function loadChartData(period = 'daily') {
            fetch(`chart_data.php?period=${period}`)
                .then(response => response.json())
                .then(data => {
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
                        }
                    });

                    // Custom legend
                    const legendContainer = document.getElementById('chartLegend');
                    legendContainer.innerHTML = '';
                    data.forEach((item, index) => {
                        const legendItem = document.createElement('div');
                        legendItem.classList.add('legend-item');
                        legendItem.innerHTML = `
                        <span class="legend-color" style="background-color:${colors[index]}"></span>
                        ${item.product_name} (${values[index]})
                    `;
                        legendContainer.appendChild(legendItem);
                    });
                });
        }

        document.getElementById('timeFilter').addEventListener('change', function () {
            loadChartData(this.value);
        });

        // Load default
        loadChartData();


        //
        // Replace the sample data section with this code:

        const periodSelector = document.getElementById('periodSelector');
        const totalSalesEl = document.getElementById('totalSales');
        const totalOrdersEl = document.getElementById('totalOrders');
        //const chartTitleEl = document.getElementById('chartTitle');
        const widget = document.querySelector('.sales-widget');

        // Function to fetch sales data from PHP backend
        async function fetchSalesData(period) {
            try {
                const response = await fetch(`sales_api.php?period=${period}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
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

        // Function to update stats with real data
        async function updateStats(period) {
            // Add loading state
            widget.classList.add('loading');

            try {
                // Fetch real data from backend
                const data = await fetchSalesData(period);

                // Update values with animation
                totalSalesEl.textContent = `₱${data.totalSales.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        })}`;

                totalOrdersEl.textContent = data.totalOrders.toLocaleString();

                /* Update chart title
                const periodNames = {
                    daily: 'Daily',
                    weekly: 'Weekly', 
                    monthly: 'Monthly'
                };
                chartTitleEl.textContent = `${periodNames[period]} Sales Trend`;*/

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

        // Event listener for period change
        periodSelector.addEventListener('change', function () {
            updateStats(this.value);
        });

        // Initialize with daily data
        updateStats('daily');

        // Add some interactivity
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function () {
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 150);
            });
        });

        // Optional: Auto-refresh data every 30 seconds
        setInterval(() => {
            const currentPeriod = periodSelector.value;
            updateStats(currentPeriod);
        }, 30000);

        // Toggle user dropdown
        function toggleDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Toggle mobile sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function (event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');

            if (!userMenu.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function (event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-toggle');

            if (window.innerWidth <= 768 &&
                !sidebar.contains(event.target) &&
                !toggle.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Transaction Modal Functionality
        const modal = document.getElementById('transactionModal');
        const closeBtn = document.querySelector('.modal .close');

        // Close modal when clicking the close button
        closeBtn.onclick = function() {
            modal.style.display = "none";
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }

        // Add click event to transaction rows
        document.querySelectorAll('.transaction-row').forEach(row => {
            row.addEventListener('click', async function() {
                const orderId = this.getAttribute('data-order-id');
                try {
                    const response = await fetch(`get_transaction_details.php?order_id=${orderId}`);
                    if (!response.ok) throw new Error('Failed to fetch transaction details');
                    const data = await response.json();
                    
                    // Update modal content
                    document.getElementById('modal-customer').textContent = data.order.customer_name;
                    document.getElementById('modal-date').textContent = new Date(data.order.created_at).toLocaleString();
                    document.getElementById('modal-status').textContent = data.order.status;
                    document.getElementById('modal-payment').textContent = data.order.payment_method;
                    document.getElementById('modal-invoice').textContent = data.order.invoice_number || 'N/A';

                    const referenceWrapper = document.getElementById('modal-reference-wrapper');
                    const referenceValue = document.getElementById('modal-reference');
                    if ((data.order.payment_method || '').toLowerCase() === 'gcash' && data.order.reference_number) {
                        referenceWrapper.style.display = 'block';
                        referenceValue.textContent = data.order.reference_number;
                    } else {
                        referenceWrapper.style.display = 'none';
                        referenceValue.textContent = '';
                    }
                    
                    // Update items table
                    const itemsBody = document.getElementById('modal-items');
                    itemsBody.innerHTML = '';
                    data.items.forEach(item => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${item.name}</td>
                            <td>${item.qty}</td>
                            <td>₱${parseFloat(item.price).toFixed(2)}</td>
                            <td>₱${(item.qty * item.price).toFixed(2)}</td>
                        `;
                        itemsBody.appendChild(row);
                    });
                    
                    document.getElementById('modal-total').textContent = `₱${parseFloat(data.order.total).toFixed(2)}`;
                    
                    // Show modal
                    modal.style.display = "block";
                } catch (error) {
                    console.error('Error fetching transaction details:', error);
                    alert('Failed to load transaction details. Please try again.');
                }
            });
        });
    </script>
</body>

</html>
