<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }

try {
    $pdo = db();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Simple stats
try {
    $today = $pdo->prepare("SELECT COUNT(*) as c, COALESCE(SUM(total),0) as s FROM orders WHERE DATE(created_at)=CURDATE()");
    $today->execute(); 
    $t = $today->fetch();
} catch (Exception $e) {
    error_log("Today's stats query failed: " . $e->getMessage());
    $t = ['c' => 0, 's' => 0];
}

// Low stock items
try {
    $low = $pdo->query('SELECT * FROM products WHERE quantity <= low_stock_threshold')->fetchAll();
} catch (Exception $e) {
    error_log("Low stock query failed: " . $e->getMessage());
    $low = [];
}

// Determine time range for top products
$range = isset($_GET['top_range']) ? $_GET['top_range'] : 'daily';

// Build date filter with prepared statement
$date_condition = "";
$params = [];

switch($range) {
    case 'weekly':
        $date_condition = "AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        break;
    case 'monthly':
        $date_condition = "AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        break;
    default:
        $date_condition = "AND DATE(o.created_at) = CURDATE()";
        break;
}

// Top selling products query (fixed and consolidated)
try {
    $sql = "
        SELECT p.*, COALESCE(SUM(oi.qty), 0) as sold 
        FROM products p 
        LEFT JOIN order_items oi ON oi.product_id = p.id 
        LEFT JOIN orders o ON o.id = oi.order_id 
        WHERE 1=1 {$date_condition}
        GROUP BY p.id 
        ORDER BY sold DESC 
        LIMIT 5
    ";
    
    $top = $pdo->query($sql)->fetchAll();
} catch (Exception $e) {
    error_log("Top products query failed: " . $e->getMessage());
    $top = [];
}
// --- Low stock data for the bell ---
try {
    $stmt = $pdo->query("
        SELECT 
            id,
            name,
            quantity as stock,
            low_stock_threshold as min_level
        FROM products
        WHERE quantity <= low_stock_threshold
        ORDER BY quantity ASC
        LIMIT 10
    ");
    $low_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $low_count = count($low_items);
} catch (Exception $e) {
    error_log("Low stock notification query failed: " . $e->getMessage());
    $low_items = [];
    $low_count = 0;
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                <a href="dashboard.php" class="nav-link active">
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
                <a href="sales.php" class="nav-link">
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

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <!-- Notification Bell and User Menu -->
        <header class="header">
          
            <!-- Avatar and Dropdown -->
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Dashboard</h2>
            </div>
              <div class="notif-menu">
                <button class="notif-bell" id="notifBell" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if (!empty($low_count)) : ?>
                    <span class="badge"><?= htmlspecialchars($low_count) ?></span>
                    <?php endif; ?>
                </button>

                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-head">
                        <i class="fas fa-exclamation-triangle" style="color: #e74c3c; margin-right: 8px;"></i>
                        Low Stock Items
                    </div>
                    <?php if ($low_count === 0): ?>
                    <div class="notif-empty">
                        <i class="fas fa-check-circle" style="color: #27ae60; font-size: 24px; margin-bottom: 10px;"></i>
                        <p>All good! No low stock items.</p>
                    </div>
                    <?php else: ?>
                    <ul class="notif-list">
                        <?php foreach ($low_items as $it): ?>
                        <li>
                            <span class="n-name"><?= htmlspecialchars($it['name']) ?></span>
                            <span class="n-qty"><?= (int)$it['stock'] ?> left</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="inventory.php" class="notif-link">
                        <i class="fas fa-arrow-right"></i>
                        Go to Inventory
                    </a>
                    <?php endif; ?>
                </div>
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

        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <!-- Stats Overview -->
            <div class="stats-overview">
                <div class="stat-card">
                    <div class="stat-value"><?= intval($t['c']) ?></div>
                    <div class="stat-label">Today's Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">₱<?= number_format($t['s'], 2) ?></div>
                    <div class="stat-label">Today's Sales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= count($low) ?></div>
                    <div class="stat-label">Low Stock Items</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= count($top) ?></div>
                    <div class="stat-label">Top Products</div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Low Stock Widget -->
                <div class="widget low-stock">
                    <div class="widget-header">
                        <h3 class="widget-title">
                            <i class="fas fa-exclamation-triangle" style="color: #e74c3c; margin-right: 8px;"></i>
                            Low Stock Alert
                        </h3>
                    </div>
                    <div class="widget-content">
                        <?php if (empty($low)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"
                                style="font-size: 2rem; margin-bottom: 10px; color: #27ae60;"></i>
                            <p>All products are well stocked!</p>
                        </div>
                        <?php else: ?>
                        <ul class="item-list">
                            <?php foreach($low as $l): ?>
                            <li class="list-item">
                                <span class="item-name"><?= htmlspecialchars($l['name']) ?></span>
                                <span class="item-value"><?= intval($l['quantity']) ?> left</span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Selling Widget -->
                <div class="widget top-selling">
                    <div class="widget-header">
                        <h3 class="widget-title">
                            <i class="fas fa-trophy" style="color: #f39c12;"></i>
                            Top Selling Products
                        </h3>
                        <!-- Time Range Selector for Top Products -->
                        <div class="period-selector">
                            <form method="get" style="margin: 0;">
                                <select name="top_range" id="top_range" onchange="this.form.submit()">
                                    <option value="daily" <?= $range === 'daily' ? 'selected' : '' ?>>Daily</option>
                                    <option value="weekly" <?= $range === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    <option value="monthly" <?= $range === 'monthly' ? 'selected' : '' ?>>Monthly
                                    </option>
                                </select>
                            </form>
                        </div>
                    </div>
                    <div class="widget-content">
                        <?php if (empty($top)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar" style="font-size: 2rem; margin-bottom: 10px;"></i>
                            <p>No sales data available for selected period</p>
                        </div>
                        <?php else: ?>
                        <ul class="item-list">
                            <?php foreach($top as $it): ?>
                            <li class="list-item">
                                <span class="item-name"><?= htmlspecialchars($it['name']) ?></span>
                                <span class="item-value"><?= intval($it['sold']) ?> sold</span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
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
    </script>
    <!-- Notification Bell Script -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const bell = document.getElementById('notifBell');
        const panel = document.getElementById('notifDropdown');
        
        if (bell && panel) {
            bell.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Close user dropdown if open
                const userDropdown = document.getElementById('userDropdown');
                if (userDropdown) {
                    userDropdown.classList.remove('show');
                }
                
                panel.classList.toggle('show');
            });

            // Close when clicking outside
            document.addEventListener('click', function(e) {
                if (!panel.contains(e.target) && !bell.contains(e.target)) {
                    panel.classList.remove('show');
                }
            });

            // Prevent dropdown from closing when clicking inside it
            panel.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }
    });
    </script>

</body>

</html>