<?php
// ============================================
// FILE: includes/sidebar.php
// Category sidebar navigation
// ============================================

// Get categories from database
// $categories = getCategories(); // Assume this function exists
$categories = [
    'all' => 'ALL CATEGORIES',
    'batteries-electrical' => 'BATTERIES & ELECTRICAL',
    'brakes' => 'BRAKES',
    'cables' => 'CABLES',
    'engine' => 'ENGINE',
    'mobile' => 'MOBILE',
    'handguards' => 'HANDGUARDS SPARE PARTS',
    'lighting' => 'LIGHTING',
    'sprocket' => 'SPROCKET',
    'swing-arm' => 'SWING ARM',
    'full-system' => 'FULL SYSTEM',
    'underbone' => 'UNDERBONE',
    'airbox-pipes' => 'AIRBOX & PIPES',
    'lubricants' => 'LUBRICANT & FLUIDS',
    'bearings' => 'BEARINGS',
    'carburetor' => 'CARBURETOR',
    'misc' => 'MISCELLANEOUS'
];

$current_category = isset($_GET['category']) ? $_GET['category'] : '';
?>

<aside class="sidebar-nav">
    <h3 class="sidebar-title">SHOP BY CATEGORY</h3>
    
    <ul class="category-list">
        <?php foreach ($categories as $slug => $name): ?>
            <li class="category-item">
                <a href="customer/catalog.php?category=<?php echo $slug; ?>" 
                   class="<?php echo $current_category == $slug ? 'active' : ''; ?>">
                    <span class="category-icon"></span>
                    <?php echo $name; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    
    <!-- Quick Links -->
    <div class="mt-4">
        <h4 class="sidebar-title" style="font-size: var(--font-size-base);">QUICK LINKS</h4>
        <ul class="category-list">
            <li class="category-item">
                <a href="customer/catalog.php?filter=new">
                    <span class="category-icon" style="background: var(--success);"></span>
                    New Arrivals
                </a>
            </li>
            <li class="category-item">
                <a href="customer/catalog.php?filter=sale">
                    <span class="category-icon" style="background: var(--error);"></span>
                    On Sale
                </a>
            </li>
            <li class="category-item">
                <a href="customer/catalog.php?filter=featured">
                    <span class="category-icon" style="background: var(--warning);"></span>
                    Featured
                </a>
            </li>
        </ul>
    </div>
</aside>
