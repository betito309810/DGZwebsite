<?php
if (!isset($role)) {
    $role = $_SESSION['role'] ?? '';
}

$activePage = $activePage ?? basename($_SERVER['PHP_SELF'] ?? '');

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

$staffAllowedPages = [
    'dashboard.php',
    'sales.php',
    'pos.php',
    'inventory.php',
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
                    <?php echo htmlspecialchars($item['label']); ?>
                </a>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
