<?php
// ============================================
// FILE: includes/admin_sidebar.php
// Admin sidebar navigation
// ============================================

$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
?>

<aside class="admin-sidebar" id="adminSidebar">
    <nav>
        <ul class="admin-nav-list">
            <!-- Dashboard -->
            <li class="admin-nav-item">
                <a href="index.php" class="admin-nav-link <?php echo $current_page == 'index.php' && $current_dir == 'admin' ? 'active' : ''; ?>">
                    <span class="admin-nav-icon"></span>
                    <span class="admin-nav-text">Dashboard</span>
                </a>
            </li>
            
            <!-- Products Management -->
            <li class="admin-nav-item has-submenu <?php echo $current_dir == 'products' ? 'open' : ''; ?>">
                <a href="#" class="admin-nav-link <?php echo $current_dir == 'products' ? 'active' : ''; ?>" 
                   onclick="toggleSubmenu(this)">
                    <span class="admin-nav-icon"></span>
                    <span class="admin-nav-text">Products</span>
                    <span class="admin-nav-arrow">▶</span>
                </a>
                <ul class="admin-submenu">
                    <li><a href="products/index.php" class="admin-submenu-link <?php echo $current_page == 'index.php' && $current_dir == 'products' ? 'active' : ''; ?>">All Products</a></li>
                    <li><a href="products/add.php" class="admin-submenu-link <?php echo $current_page == 'add.php' ? 'active' : ''; ?>">Add Product</a></li>
                    <li><a href="products/categories.php" class="admin-submenu-link <?php echo $current_page == 'categories.php' ? 'active' : ''; ?>">Categories</a></li>
                </ul>
            </li>
            
            <!-- Inventory Management -->
            <li class="admin-nav-item has-submenu <?php echo $current_dir == 'inventory' ? 'open' : ''; ?>">
                <a href="#" class="admin-nav-link <?php echo $current_dir == 'inventory' ? 'active' : ''; ?>" 
                   onclick="toggleSubmenu(this)">
                    <span class="admin-nav-icon"></span>
                    <span class="admin-nav-text">Inventory</span>
                    <?php 
                    // Check for low stock items
                    // $low_stock_count = getLowStockCount();
                    $low_stock_count = 3; // Placeholder
                    if ($low_stock_count > 0): 
                    ?>
                        <span class="notification-badge"><?php echo $low_stock_count; ?></span>
                    <?php endif; ?>
                    <span class="admin-nav-arrow">▶</span>
                </a>
                <ul class="admin-submenu">
                    <li><a href="inventory/index.php" class="admin-submenu-link <?php echo $current_page == 'index.php' && $current_dir == 'inventory' ? 'active' : ''; ?>">Overview</a></li>
                    <li><a href="inventory/stock_alerts.php" class="admin-submenu-link <?php echo $current_page == 'stock_alerts.php' ? 'active' : ''; ?>">Stock Alerts</a></li>
                    <li><a href="inventory/reports.php" class="admin-submenu-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">Reports</a></li>
                </ul>
            </li>
            
            <!-- Orders Management -->
            <li class="admin-nav-item has-submenu <?php echo $current_dir == 'orders' ? 'open' : ''; ?>">
                <a href="#" class="admin-nav-link <?php echo $current_dir == 'orders' ? 'active' : ''; ?>" 
                   onclick="toggleSubmenu(this)">
                    <span class="admin-nav-icon"></span>
                    <span class="admin-nav-text">Orders</span>
                    <?php 
                    // Check for pending orders
                    // $pending_orders = getPendingOrdersCount();
                    $pending_orders = 5; // Placeholder
                    if ($pending_orders > 0): 
                    ?>
                        <span class="notification-badge"><?php echo $pending_orders; ?></span>
                    <?php endif; ?>
                    <span class="admin-nav-arrow">▶</span>
                </a>
                <ul class="admin-submenu">
                    <li><a href="orders/index.php" class="admin-submenu-link <?php echo $current_page == 'index.php' && $current_dir == 'orders' ? 'active' : ''; ?>">All Orders</a></li>
                    <li><a href="orders/pending.php" class="admin-submenu-link <?php echo $current_page == 'pending.php' ? 'active' : ''; ?>">Pending Orders</a></li>
                    <li><a href="orders/completed.php" class="admin-submenu-link <?php echo $current_page == 'completed.php' ? 'active' : ''; ?>">Completed</a></li>
                </ul>
            </li>
            
            <!-- Sales Management -->
            <li class="admin-nav-item has-submenu <?php echo $current_dir == 'sales' ? 'open' : ''; ?>">
                <a href="#" class="admin-nav-link <?php echo $current_dir == 'sales' ? 'active' : ''; ?>" 
                   onclick="toggleSubmenu(this)">
                    <span class="admin-nav-icon"></span>
                    <span class="admin-nav-text">Sales</span>
                    <span class="admin-nav-arrow">▶</span>
                </a>
                <ul class="admin-submenu">
                    <li><a href="sales/index.php" class="admin-submenu-link <?php echo $current_page == 'index.php' && $current_dir == 'sales' ? 'active' : ''; ?>">Overview</a></li>
                    <li><a href="sales/pos.php" class="admin-submenu-link <?php echo $current_page == 'pos.php' ? 'active' : ''; ?>">Point of Sale</a></li>
                    <li><a href="sales/reports.php" class="admin-submenu-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">Reports</a></li>
                    <li><a href="sales/analytics.php" class="admin-submenu-link <?php echo $current_page == 'analytics.php' ? 'active' : ''; ?>">Analytics</a></li>
                </ul>
            </li>
            
            <!-- Customers -->
            <li class="admin-nav-item">
                <a href="customers.php" class="admin-nav-link <?php echo $current_page == 'customers.php' ? 'active' : ''; ?>">
                    <span class="admin-nav-icon"></span>
                    <span class="admin-nav-text">Customers</span>
                </a>
            </li>
            
            <!-- Settings -->
            <li class="admin-nav-item has-submenu">
                <a href="#" class="admin-nav-link" onclick="toggleSubmenu(this)">
                    <span class="admin-nav-icon"></span>
                    <span class="admin-nav-text">Settings</span>
                    <span class="admin-nav-arrow">▶</span>
                </a>
                <ul class="admin-submenu">
                    <li><a href="settings/general.php" class="admin-submenu-link">General</a></li>
                    <li><a href="settings/users.php" class="admin-submenu-link">User Management</a></li>
                    <li><a href="settings/backup.php" class="admin-submenu-link">Backup</a></li>
                </ul>
            </li>
        </ul>
    </nav>
</aside>