<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }

$pdo = db();

// Handle export to CSV
if(isset($_GET['export']) && $_GET['export'] == 'csv') {
    // Get ALL orders for export
    $export_sql = "SELECT * FROM orders ORDER BY created_at DESC";
    $export_orders = $pdo->query($export_sql)->fetchAll();
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Customer Name','Contact','Address','Total','Payment Method','Payment Proof','Status','Created At']);
    foreach($export_orders as $o) {
        fputcsv($out, [$o['id'],$o['customer_name'],$o['contact'],$o['address'],$o['total'],$o['payment_method'],$o['payment_proof'],$o['status'],$o['created_at']]);
    }
    fclose($out);
    exit;
}


// Pagination variables
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Count total records
$count_sql = "SELECT COUNT(*) FROM orders";
$total_records = $pdo->query($count_sql)->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get orders with pagination
$sql = "SELECT * FROM orders ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
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
    <link rel="stylesheet" href="../assets/css/sales.css">
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
                <a href="stockEntry.php" class="nav-link ">
                    <i class="fas fa-truck-loading nav-icon"></i>
                    Stock Entry
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
                    <a href="login.php?logout=1" class="dropdown-item logout">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </header>

        <!-- Export CSV Button -->
         <a href="sales.php?export=csv">Export CSV</a>   

        <!-- Table Container -->
        <div class="table-container">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($orders)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">
                                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px; display: block;"></i>
                                    No sales records found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($orders as $o): ?>
                            <tr>
                                <td><?=$o['id']?></td>
                                <td><?=htmlspecialchars($o['customer_name'])?></td>
                                <td>₱<?=number_format($o['total'],2)?></td>
                                <td><?=htmlspecialchars($o['payment_method'])?></td>
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
    </main>

    <script>
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
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');
            
            if (!userMenu.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-toggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !toggle.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });
    </script>
</body>

</html>