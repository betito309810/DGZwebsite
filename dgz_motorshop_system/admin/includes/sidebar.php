<?php
if (!isset($role)) {
    $role = $_SESSION['role'] ?? '';
}

$activePage = $activePage ?? basename($_SERVER['PHP_SELF'] ?? '');

if (!isset($onlineOrderBadgeCount)) {
    try {
        $sidebarPdo = isset($pdo) && $pdo instanceof PDO ? $pdo : db();
        $onlineOrderBadgeCount = countOnlineOrdersByStatus($sidebarPdo);
    } catch (Throwable $e) {
        $onlineOrderBadgeCount = 0;
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

if (!empty($onlineOrderBadgeCount)) {
    foreach ($navItems as &$navItem) {
        if ($navItem['href'] === 'pos.php') {
            $navItem['badge'] = (int) $onlineOrderBadgeCount;
            break;
        }
    }
    unset($navItem);
}

$staffAllowedPages = [
    'dashboard.php',
    'products.php',
    'sales.php',
    'pos.php',
    'inventory.php',
    'stockEntry.php',
];

?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <img src="../assets/logo.png" alt="Company Logo">
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
                    <?php if (!empty($item['badge']) && !$isActive): ?>
                        <span class="nav-badge" data-sidebar-pos-count><?= (int) $item['badge'] ?></span>
                    <?php endif; ?>
                </a>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
