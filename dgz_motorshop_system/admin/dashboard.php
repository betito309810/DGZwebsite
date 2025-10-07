<?php
require __DIR__. '/../config/config.php';
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

$role = $_SESSION['role'] ?? '';
enforceStaffAccess();

require_once __DIR__ . '/includes/inventory_notifications.php';
require_once __DIR__ . '/includes/sales_periods.php';
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

$topPeriodParam = $_GET['top_period'] ?? ($_GET['top_range'] ?? 'daily');
$topValueParam = $_GET['top_value'] ?? null;

try {
    $topPeriodInfo = resolve_sales_period($topPeriodParam, $topValueParam);
} catch (Throwable $e) {
    error_log('Top products period resolution failed: ' . $e->getMessage());
    $topPeriodInfo = resolve_sales_period('daily');
}

$topPeriod = $topPeriodInfo['period'];
$topPeriodValue = $topPeriodInfo['value'];
$topPeriodLabel = $topPeriodInfo['label'];
$topRangeStart = $topPeriodInfo['range_start'];
$topRangeEnd = $topPeriodInfo['range_end'];

function format_top_selling_range(string $startDate, string $endDate): string
{
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate) ?: null;
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate) ?: null;

    if (!$start) {
        return $startDate;
    }

    if (!$end) {
        $end = $start;
    }

    if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
        return $start->format('F j, Y');
    }

    return sprintf(
        '%s - %s',
        $start->format('F j, Y'),
        $end->format('F j, Y')
    );
}

$topRangeDisplay = format_top_selling_range($topRangeStart, $topRangeEnd);

$topPickerType = 'date';
$topPickerLabel = 'Select day';
switch ($topPeriod) {
    case 'weekly':
        $topPickerType = 'week';
        $topPickerLabel = 'Select week';
        break;
    case 'monthly':
        $topPickerType = 'month';
        $topPickerLabel = 'Select month';
        break;
    case 'annually':
        $topPickerType = 'number';
        $topPickerLabel = 'Select year';
        break;
}

// Top selling products query (fixed and consolidated)
try {
    $sql = "
        SELECT p.*, COALESCE(SUM(oi.qty), 0) AS sold
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        INNER JOIN products p ON p.id = oi.product_id
        WHERE o.created_at >= :start
          AND o.created_at < :end
          AND o.status IN ('pending', 'payment_verification', 'approved', 'completed')
        GROUP BY p.id
        ORDER BY sold DESC
        LIMIT 5
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start' => $topPeriodInfo['start'],
        ':end' => $topPeriodInfo['end'],
    ]);
    $top = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Top products query failed: " . $e->getMessage());
    $top = [];
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
    <link rel="stylesheet" href="../assets/css/dashboard/dashboard.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <!-- Sidebar -->
    <?php
        $activePage = 'dashboard.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>

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

        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <!-- Stats Overview -->
            <div class="stats-overview">
                <a class="stat-card" href="sales.php">
                    <div class="stat-value"><?= intval($t['c']) ?></div>
                    <div class="stat-label">Today's Orders</div>
                </a>
                <a class="stat-card" href="sales.php">
                    <div class="stat-value">₱<?= number_format($t['s'], 2) ?></div>
                    <div class="stat-label">Today's Sales</div>
                </a>
                <a class="stat-card" href="inventory.php">
                    <div class="stat-value"><?= count($low) ?></div>
                    <div class="stat-label">Low Stock Items</div>
                </a>
                <a class="stat-card" href="sales.php">
                    <div class="stat-value"><?= count($top) ?></div>
                    <div class="stat-label">Top Products</div>
                </a>
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
                        <form
                            method="get"
                            action="dashboard.php"
                            id="topSellingFilters"
                            class="widget-controls period-form"
                            data-period="<?= htmlspecialchars($topPeriod) ?>"
                            data-value="<?= htmlspecialchars($topPeriodValue) ?>"
                            data-range="<?= htmlspecialchars($topRangeDisplay) ?>"
                        >
                            <?php foreach ($_GET as $param => $value): ?>
                                <?php if (!in_array($param, ['top_period', 'top_value', 'top_range'], true) && !is_array($value)): ?>
                                    <input type="hidden" name="<?= htmlspecialchars($param) ?>" value="<?= htmlspecialchars($value) ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <div class="control-group">
                                <label for="topSellingPeriod" class="control-label">View</label>
                                <select name="top_period" id="topSellingPeriod" class="period-dropdown" data-period-select>
                                    <option value="daily" <?= $topPeriod === 'daily' ? 'selected' : '' ?>>Daily</option>
                                    <option value="weekly" <?= $topPeriod === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    <option value="monthly" <?= $topPeriod === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                    <option value="annually" <?= $topPeriod === 'annually' ? 'selected' : '' ?>>Annually</option>
                                </select>
                            </div>
                            <div class="control-group">
                                <label for="topSellingPicker" class="control-label" id="topSellingPickerLabel"><?= htmlspecialchars($topPickerLabel) ?></label>
                                <input
                                    type="<?= htmlspecialchars($topPickerType) ?>"
                                    name="top_value"
                                    id="topSellingPicker"
                                    class="period-input"
                                    data-period-input
                                    value="<?= htmlspecialchars($topPeriodValue) ?>"
                                >
                                <span class="control-hint" id="topSellingRangeHint"><?= htmlspecialchars($topRangeDisplay) ?></span>
                            </div>
                        </form>
                    </div>
                    <div class="widget-content">
                        <div class="selected-period">Showing data for <strong id="topSellingSelectedLabel"><?= htmlspecialchars($topPeriodLabel) ?></strong></div>
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

    <script src="../assets/js/notifications.js"></script>
    <script src="../assets/js/sales/periodFilters.js"></script>
    <script src="../assets/js/dashboard/topSellingFilters.js"></script>
    <script src="../assets/js/dashboard/userMenu.js"></script>

</body>

</html>
