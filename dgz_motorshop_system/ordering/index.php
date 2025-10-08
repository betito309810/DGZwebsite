<?php
require __DIR__ . '/../config/config.php';
$pdo = db();
$products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();

$categories = [];
foreach ($products as $product) {
    $category = trim($product['category'] ?? '');
    if ($category === '') {
        $category = 'Other';
    }
    $normalized = strtolower($category);
    if (!isset($categories[$normalized])) {
        $categories[$normalized] = $category;
    }
}

natcasesort($categories);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DGZ Motorshop - Motorcycle Parts & Accessories</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/public/index.css">
    <style>

    </style>
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <img src="../assets/logo.png" alt="Company Logo">
            </div>

            <div class="search-container">
                <input type="text" class="search-bar" placeholder="Search by Category, Part, Brand...">
                <button class="search-btn">
                    <i class="fas fa-search"></i>
                </button>
                
            </div>

            <a href="#" class="cart-btn" id="cartButton">
                <i class="fas fa-shopping-cart"></i>
                <span>Cart</span>
                <div class="cart-count" id="cartCount">0</div>
            </a>
        </div>
    </header>

    <!-- Navigation -->
    <nav class="nav">
        <div class="nav-content">
            <a href="index.php" class="nav-link active">HOME</a>
            <a href="about.php" class="nav-link">ABOUT</a>
            <a href="track-order.php" class="nav-link">TRACK ORDER</a>

        </div>
    </nav>

    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h3 class="sidebar-title">Shop by Category</h3>
            <ul class="category-list">
                <li class="category-item"><a href="#" class="category-link active" data-category="all">All Products</a></li>
                <?php foreach ($categories as $slug => $categoryLabel):
                ?>
                <li class="category-item">
                    <a href="#" class="category-link" data-category="<?= htmlspecialchars($slug) ?>">
                        <?= htmlspecialchars($categoryLabel) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
          

            <!-- All Products -->
            <div id="all-products">
                <h2 id="productSectionTitle" style="margin: 10px 0 30px 0; font-size: 28px; color: #2d3436; text-align: center;">All Products
                </h2>
                <div class="products-grid">
                    <?php foreach($products as $p):
                        $category = isset($p['category']) ? $p['category'] : '';
                        $brand = isset($p['brand']) ? $p['brand'] : '';
                    ?>
                    <?php
                        $categorySlug = strtolower(trim($category ?: 'Other'));
                    ?>
                    <div class="product-card" data-category="<?= htmlspecialchars($categorySlug) ?>" data-brand="<?= htmlspecialchars(strtolower($brand)) ?>">
                        <div class="product-header">
                            <div class="product-avatar">
                                <i class="fas fa-motorcycle"></i>
                            </div>
                            <div class="product-info">
                                <h3><?=htmlspecialchars($p['name'])?></h3>
                            </div>
                        </div>

                        <p class="product-description"><?=htmlspecialchars($p['description'])?></p>
                        <p class="product-meta" style="font-size:12px;color:#888;">
                            Category: <?= htmlspecialchars($category) ?> | Brand: <?= htmlspecialchars($brand) ?>
                        </p>

                        <div class="product-footer">
                            <div class="price">₱<?=number_format($p['price'],2)?></div>
                            <div class="stock <?= $p['quantity'] <= 5 ? ($p['quantity'] == 0 ? 'out' : 'low') : '' ?>">
                                <?= $p['quantity'] == 0 ? 'Out of Stock' : $p['quantity'] . ' in stock' ?>
                            </div>
                        </div>

                        <!-- Buy Now area now feeds into the shared cart flow -->
                        <div class="buy-form">
                            <input type="number" name="qty" value="1" min="1" max="<?=max(1,$p['quantity'])?>"
                                class="qty-input" <?= $p['quantity'] == 0 ? 'disabled' : '' ?>>
                            <button type="button" class="buy-btn" <?= $p['quantity'] == 0 ? 'disabled' : '' ?>
                                onclick="(function(button) {
                                    const qtyInput = button.parentElement.querySelector('.qty-input');
                                    if (qtyInput && !qtyInput.checkValidity()) {
                                        qtyInput.reportValidity();
                                        return;
                                    }
                                    const qty = qtyInput ? parseInt(qtyInput.value) || 1 : 1;
                                    buyNow(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', <?= $p['price'] ?>, qty);
                                })(this)">
                                <?= $p['quantity'] == 0 ? 'Out of Stock' : 'Buy Now' ?>
                            </button>
                        </div>

                        <!-- Add to Cart Button -->
                        <button class="add-cart-btn"
                            onclick="(function(button) {
                                const qtyInput = button.parentElement.querySelector('.qty-input');
                                const qty = qtyInput ? parseInt(qtyInput.value) || 1 : 1;
                                const stock = <?= $p['quantity'] ?>;
                                if (qtyInput && !qtyInput.checkValidity()) {
                                    qtyInput.reportValidity();
                                    return false;
                                }
                                addToCart(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', <?= $p['price'] ?>, qty);
                            })(this)"
                            <?= $p['quantity'] == 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-cart-plus"></i> Add to Cart
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Footer -->

    </div>
    <!-- Cart functionality -->
     <script src="../assets/js/public/cart.js"></script>
    <!-- Search functionality -->
     <script src="../assets/js/public/search.js"></script>
    </div>
   <footer class="footer">
    <div class="footer-content">
        <!-- Contact info on the far left -->
        <div class="footer-column contact-info">
            <p><strong>Visit Us:</strong> Lot 2 Blk 3 Dolores Road, Brgy. Sto. Niño, Antipolo City</p>
            <p><strong>Call:</strong> <a href="tel:+639123456789">+63 912 345 6789</a></p>
            <p><strong>Email:</strong> <a href="mailto:orders@dgzmotorshop.com">orders@dgzmotorshop.com</a></p>
        </div>
        
        <!-- Social and copyright on the right -->
        <div class="footer-column footer-meta">
            <div class="social-links">
                <a href="https://www.facebook.com/dgzstonino"><i class="fab fa-facebook-f"></i></a>
            </div>
            <p>© 2022-2025 DGZ Motorshop - Sto. Niño Branch. All rights reserved.</p>
        </div>
    </div>
</footer>
</body>

</html>
