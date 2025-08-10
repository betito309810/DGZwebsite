<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
// simple stats
$today = $pdo->prepare("SELECT COUNT(*) as c, COALESCE(SUM(total),0) as s FROM orders WHERE DATE(created_at)=CURDATE()");
$today->execute(); $t = $today->fetch();
$low = $pdo->query('SELECT * FROM products WHERE quantity <= low_stock_threshold')->fetchAll();
$top = $pdo->query('SELECT p.*, SUM(oi.qty) as sold FROM order_items oi JOIN products p ON p.id=oi.product_id GROUP BY p.id ORDER BY sold DESC LIMIT 5')->fetchAll();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/dashboard.css">
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
                <a href="orders.php" class="nav-link">
                    <i class="fas fa-shopping-cart nav-icon"></i>
                    Orders
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Dashboard</h2>
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
                                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 10px; color: #27ae60;"></i>
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
                            <i class="fas fa-trophy" style="color: #f39c12; margin-right: 8px;"></i>
                            Top Selling Products
                        </h3>
                    </div>
                    <div class="widget-content">
                        <?php if (empty($top)): ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-bar" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                <p>No sales data available yet</p>
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