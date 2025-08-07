<?php
// ============================================
// FILE: includes/header.php
// Main customer-facing header with navigation
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>DGZ Motorshop</title>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/framework.css">
    <link rel="stylesheet" href="../assets/css/navigation.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
</head>
<body>
    <!-- Top Header -->
    <header class="top-header sticky-nav" id="mainHeader">
        <div class="container">
            <div class="header-content">
                <!-- Brand Logo -->
                <a href="index.php" class="brand-logo">TEAM DGZ</a>
                
                <!-- Search Bar -->
                <div class="search-container">
                    <form action="search.php" method="GET">
                        <input type="text" 
                               class="search-bar" 
                               name="q" 
                               placeholder="Search by Category, Part Name..."
                               value="<?php echo isset($_GET['q']) ? htmlspecialchars($_GET['q']) : ''; ?>">
                        <button type="submit" class="search-icon">🔍</button>
                    </form>
                </div>
                
                <!-- Header Actions -->
                <div class="header-actions">
                    <!-- Mobile Menu Toggle -->
                    <button class="mobile-menu-toggle" id="mobileMenuToggle">☰</button>
                    
                    <!-- Account Link -->
                    <a href="customer/account.php" class="account-link">
                        👤 <span class="d-none d-md-inline">Account</span>
                    </a>
                    
                    <!-- Shopping Cart -->
                    <a href="customer/cart.php" class="cart-link">
                        🛒 
                        <span class="d-none d-md-inline">Cart</span>
                        <?php if (isset($_SESSION['cart_count']) && $_SESSION['cart_count'] > 0): ?>
                            <span class="cart-count"><?php echo $_SESSION['cart_count']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Main Navigation -->
    <nav class="main-nav">
        <div class="container">
            <ul class="nav-links" id="mainNavLinks">
                <li class="nav-item">
                    <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                        HOME
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="customer/catalog.php" class="<?php echo strpos($_SERVER['PHP_SELF'], 'catalog') !== false ? 'active' : ''; ?>">
                        SHOP
                    </a>
                    <!-- Dropdown Menu -->
                    <div class="dropdown-menu">
                        <a href="customer/catalog.php?category=all">All Products</a>
                        <a href="customer/catalog.php?category=new">New Arrivals</a>
                        <a href="customer/catalog.php?category=featured">Featured</a>
                        <a href="customer/catalog.php?category=sale">On Sale</a>
                    </div>
                </li>
                
                <li class="nav-item">
                    <a href="customer/categories.php" class="<?php echo strpos($_SERVER['PHP_SELF'], 'categories') !== false ? 'active' : ''; ?>">
                        CATEGORIES
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="about.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'about.php' ? 'active' : ''; ?>">
                        ABOUT
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="contact.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'contact.php' ? 'active' : ''; ?>">
                        CONTACT
                    </a>
                </li>
                
                <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'admin'): ?>
                    <li class="nav-item">
                        <a href="admin/index.php" style="color: var(--brand-secondary);">
                            ADMIN PANEL
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>










