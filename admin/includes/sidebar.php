<?php
if (!isset($role)) {
    $role = $_SESSION['role'] ?? '';
}

$activePage = $activePage ?? basename($_SERVER['PHP_SELF'] ?? '');

if (!isset($sidebarPdo) || !($sidebarPdo instanceof PDO)) {
    try {
        $sidebarPdo = isset($pdo) && $pdo instanceof PDO ? $pdo : db();
    } catch (Throwable $e) {
        $sidebarPdo = null;
    }
}

if (!isset($onlineOrderBadgeCount)) {
    try {
        $onlineOrderBadgeCount = $sidebarPdo instanceof PDO ? countOnlineOrdersByStatus($sidebarPdo) : 0;
    } catch (Throwable $e) {
        $onlineOrderBadgeCount = 0;
    }
}

if (!isset($stockRequestBadgeCount)) {
    try {
        $stockRequestBadgeCount = $sidebarPdo instanceof PDO ? countPendingRestockRequests($sidebarPdo) : 0;
    } catch (Throwable $e) {
        $stockRequestBadgeCount = 0;
    }
}

$navItems = [
    [
        'href' => 'dashboard.php',
        'icon' => 'fas fa-home nav-icon',
        'label' => 'Dashboard',
    ],
    [
        'href' => 'products.php',
        'icon' => 'fas fa-box nav-icon',
        'label' => 'Products',
    ],
    [
        'href' => 'sales.php',
        'icon' => 'fas fa-chart-line nav-icon',
        'label' => 'Sales',
    ],
    [
        'href' => 'pos.php',
        'icon' => 'fas fa-cash-register nav-icon',
        'label' => 'POS',
    ],
    [
        'href' => 'inventory.php',
        'icon' => 'fas fa-boxes nav-icon',
        'label' => 'Inventory',
    ],
    [
        'href' => 'stockRequests.php',
        'icon' => 'fas fa-clipboard-list nav-icon',
        'label' => 'Stock Requests',
    ],
];

foreach ($navItems as &$navItem) {
    if ($navItem['href'] === 'pos.php') {
        $navItem['badge'] = (int) $onlineOrderBadgeCount;
        $navItem['badge_attr'] = 'data-sidebar-pos-count';
    } elseif ($navItem['href'] === 'stockRequests.php') {
        $navItem['badge'] = (int) $stockRequestBadgeCount;
        $navItem['badge_attr'] = 'data-sidebar-stock-count';
    }
}
unset($navItem);

$staffAllowedPages = [
    'dashboard.php',
    'products.php',
    'sales.php',
    'pos.php',
    'inventory.php',
    'stockEntry.php',
];

?>
<aside
    class="sidebar"
    id="sidebar"
    data-sidebar-counts-feed="<?= htmlspecialchars(appBaseUrl() . '/admin/sidebarCountsFeed.php', ENT_QUOTES, 'UTF-8'); ?>"
>
    <div class="sidebar-header">
        <div class="logo">
            <img src="../dgz_motorshop_system/assets/logo.png" alt="Company Logo">
        </div>
    </div>
    <nav class="nav-menu">
        <?php foreach ($navItems as $item): ?>
            <?php
                $href = $item['href'];
                if ($role === 'staff' && !in_array($href, $staffAllowedPages, true)) {
                    continue;
                }
                $isActive = $activePage === $href;
            ?>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars($href); ?>" class="nav-link<?php echo $isActive ? ' active' : ''; ?>">
                    <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i>
                    <span class="nav-text"><?php echo htmlspecialchars($item['label']); ?></span>
                    <?php if (!empty($item['badge_attr'])): ?>
                        <?php
                            $badgeAttr = htmlspecialchars($item['badge_attr'], ENT_QUOTES, 'UTF-8');
                            $badgeCount = (int) ($item['badge'] ?? 0);
                            $badgeText = $badgeCount > 0 ? (string) $badgeCount : '';
                            $badgeHidden = $badgeText === '' ? ' hidden' : '';
                        ?>
                        <span class="nav-badge" <?= $badgeAttr ?><?= $badgeHidden ?>><?= htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </a>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
