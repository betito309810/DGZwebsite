<?php
require '../config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $pdo = db();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Fetch the authenticated user's information for the profile modal
$current_user = null;
try {
    $stmt = $pdo->prepare('SELECT name, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();
} catch (Exception $e) {
    error_log('User lookup failed: ' . $e->getMessage());
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

// Low stock items for the widget
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
switch ($range) {
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

// Ensure notification storage exists and update notification feed
$notifications = [];
$active_notification_count = 0;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('active','resolved') DEFAULT 'active',
        quantity_at_event INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL DEFAULT NULL,
        CONSTRAINT fk_inventory_notifications_product
            FOREIGN KEY (product_id) REFERENCES products(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insert notifications for items that are currently low on stock and have no active alert yet
    $low_stock_now = $pdo->query("SELECT id, name, quantity, low_stock_threshold FROM products WHERE quantity <= low_stock_threshold")
                         ->fetchAll(PDO::FETCH_ASSOC);

    $check_notification_stmt = $pdo->prepare("SELECT id FROM inventory_notifications WHERE product_id = ? AND status = 'active' LIMIT 1");
    $create_notification_stmt = $pdo->prepare('INSERT INTO inventory_notifications (product_id, title, message, quantity_at_event) VALUES (?, ?, ?, ?)');

    foreach ($low_stock_now as $item) {
        $check_notification_stmt->execute([$item['id']]);
        if (!$check_notification_stmt->fetchColumn()) {
            $title = $item['name'] . ' is low on stock';
            $message = 'Only ' . intval($item['quantity']) . ' left (minimum ' . intval($item['low_stock_threshold']) . ').';
            $create_notification_stmt->execute([
                $item['id'],
                $title,
                $message,
                intval($item['quantity'])
            ]);
        }
    }

    // Resolve notifications if the product has been restocked
    $active_notifications = $pdo->query("SELECT n.id, p.quantity, p.low_stock_threshold FROM inventory_notifications n LEFT JOIN products p ON p.id = n.product_id WHERE n.status = 'active'")
                               ->fetchAll(PDO::FETCH_ASSOC);
    $resolve_notification_stmt = $pdo->prepare("UPDATE inventory_notifications SET status = 'resolved', resolved_at = IF(resolved_at IS NULL, NOW(), resolved_at) WHERE id = ? AND status = 'active'");

    foreach ($active_notifications as $record) {
        $product_quantity = isset($record['quantity']) ? (int) $record['quantity'] : null;
        $threshold = isset($record['low_stock_threshold']) ? (int) $record['low_stock_threshold'] : null;

        if ($product_quantity === null || ($threshold !== null && $product_quantity > $threshold)) {
            $resolve_notification_stmt->execute([$record['id']]);
        }
    }

    // Fetch the latest notifications for display
    $notifications = $pdo->query("SELECT n.*, p.name AS product_name FROM inventory_notifications n LEFT JOIN products p ON p.id = n.product_id ORDER BY n.created_at DESC LIMIT 10")
                         ->fetchAll(PDO::FETCH_ASSOC);

    $active_notification_count = (int) $pdo->query("SELECT COUNT(*) FROM inventory_notifications WHERE status = 'active'")->fetchColumn();
} catch (Exception $e) {
    error_log("Low stock notification query failed: " . $e->getMessage());
    $notifications = [];
    $active_notification_count = 0;
}

function format_time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return '';
    }

    $diff = time() - $timestamp;
    if ($diff < 0) {
        $diff = 0;
    }

    if ($diff < 60) {
        return 'Just now';
    }

    $minutes = floor($diff / 60);
    if ($minutes < 60) {
        return $minutes === 1 ? '1 minute ago' : $minutes . ' minutes ago';
    }

    $hours = floor($diff / 3600);
    if ($hours < 24) {
        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }

    $days = floor($diff / 86400);
    if ($days < 7) {
        return $days === 1 ? '1 day ago' : $days . ' days ago';
    }

    $weeks = floor($diff / 604800);
    if ($weeks < 4) {
        return $weeks === 1 ? '1 week ago' : $weeks . ' weeks ago';
    }

    return date('M j, Y', $timestamp);
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
            <div class="header-right">
                <div class="notif-menu">
                    <button class="notif-bell" id="notifBell" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if (!empty($active_notification_count)) : ?>
                        <span class="badge"><?= htmlspecialchars($active_notification_count) ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-head">
                            <i class="fas fa-bell" aria-hidden="true"></i>
                            Notifications
                        </div>
                        <?php if (empty($notifications)): ?>
                        <div class="notif-empty">
                            <i class="fas fa-check-circle" aria-hidden="true"></i>
                            <p>No notifications yet.</p>
                        </div>
                        <?php else: ?>
                        <ul class="notif-list">
                            <?php foreach ($notifications as $note): ?>
                            <li class="notif-item <?= $note['status'] === 'resolved' ? 'resolved' : 'active' ?>">
                                <div class="notif-row">
                                    <span class="notif-title">
                                        <?= htmlspecialchars($note['title']) ?>
                                    </span>
                                    <?php if ($note['status'] === 'resolved'): ?>
                                    <span class="notif-status">Resolved</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($note['message'])): ?>
                                <p class="notif-message"><?= htmlspecialchars($note['message']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($note['product_name'])): ?>
                                <span class="notif-product"><?= htmlspecialchars($note['product_name']) ?></span>
                                <?php endif; ?>
                                <span class="notif-time"><?= htmlspecialchars(format_time_ago($note['created_at'])) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="notif-footer">
                            <a href="inventory.php" class="notif-link">
                                <i class="fas fa-arrow-right"></i>
                                Manage inventory
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
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
                        <a href="login.php?logout=1" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
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

        document.addEventListener('DOMContentLoaded', function() {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');
            const bell = document.getElementById('notifBell');
            const panel = document.getElementById('notifDropdown');
            const profileButton = document.getElementById('profileTrigger');
            const profileModal = document.getElementById('profileModal');
            const profileModalClose = document.getElementById('profileModalClose');

            document.addEventListener('click', function(event) {
                if (userMenu && dropdown && !userMenu.contains(event.target)) {
                    dropdown.classList.remove('show');
                }

                const sidebar = document.getElementById('sidebar');
                const toggle = document.querySelector('.mobile-toggle');

                if (window.innerWidth <= 768 &&
                    sidebar && toggle &&
                    !sidebar.contains(event.target) &&
                    !toggle.contains(event.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            });

            if (bell && panel) {
                bell.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    if (dropdown) {
                        dropdown.classList.remove('show');
                    }

                    panel.classList.toggle('show');
                });

                document.addEventListener('click', function(e) {
                    if (!panel.contains(e.target) && !bell.contains(e.target)) {
                        panel.classList.remove('show');
                    }
                });

                panel.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }

            if (profileButton && profileModal) {
                const openProfileModal = function() {
                    profileModal.classList.add('show');
                    profileModal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('modal-open');
                };

                const closeProfileModal = function() {
                    profileModal.classList.remove('show');
                    profileModal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('modal-open');
                };

                profileButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    if (dropdown) {
                        dropdown.classList.remove('show');
                    }
                    openProfileModal();
                });

                if (profileModalClose) {
                    profileModalClose.addEventListener('click', function() {
                        closeProfileModal();
                    });
                }

                profileModal.addEventListener('click', function(event) {
                    if (event.target === profileModal) {
                        closeProfileModal();
                    }
                });

                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape' && profileModal.classList.contains('show')) {
                        closeProfileModal();
                    }
                });
            }
        });
    </script>

</body>

</html>
