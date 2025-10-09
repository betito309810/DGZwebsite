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
                    <?php
                        // Added: resolve the primary photo that should appear on the grid.
                        $rawImagePath = trim((string)($p['image'] ?? ''));
                        $hasCustomImage = $rawImagePath !== '';
                        $normalizedImagePath = $hasCustomImage ? '../' . ltrim($rawImagePath, '/') : '../assets/img/product-placeholder.svg';
                    ?>
                    <div class="product-card"
                        data-category="<?= htmlspecialchars($categorySlug) ?>"
                        data-brand="<?= htmlspecialchars(strtolower($brand)) ?>"
                        data-product-id="<?= (int) $p['id'] ?>"
                        data-product-name="<?= htmlspecialchars($p['name']) ?>"
                        data-product-brand="<?= htmlspecialchars($brand) ?>"
                        data-product-category-label="<?= htmlspecialchars($category) ?>"
                        data-product-description="<?= htmlspecialchars($p['description'], ENT_QUOTES) ?>"
                        data-product-price="<?= htmlspecialchars(number_format((float)$p['price'], 2, '.', '')) ?>"
                        data-product-quantity="<?= (int) $p['quantity'] ?>"
                        data-primary-image="<?= htmlspecialchars($hasCustomImage ? $normalizedImagePath : '../assets/img/product-placeholder.svg') ?>"
                        tabindex="0"
                        aria-label="View <?= htmlspecialchars($p['name']) ?> details">
                        <!-- Updated: the hero thumbnail now acts as the primary trigger for the richer detail modal. -->
                        <div class="product-photo">
                            <img src="<?= htmlspecialchars($normalizedImagePath) ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
                        </div>
                        <div class="product-info">
                            <h3><?=htmlspecialchars($p['name'])?></h3>
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
                                class="qty-input" <?= $p['quantity'] == 0 ? 'disabled' : '' ?> data-gallery-ignore="true">
                            <button type="button" class="buy-btn" <?= $p['quantity'] == 0 ? 'disabled' : '' ?>
                                data-gallery-ignore="true"
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
                        <button class="add-cart-btn" data-gallery-ignore="true"
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
    <!-- Updated: Modal container now mirrors a marketplace-style product detail layout for richer previews. -->
    <div class="product-gallery-modal" id="productGalleryModal" aria-hidden="true" tabindex="-1">
        <div class="product-gallery-dialog" role="dialog" aria-modal="true" aria-labelledby="productGalleryTitle">
            <button type="button" class="product-gallery-close" id="productGalleryClose" aria-label="Close product gallery">
                <i class="fas fa-times"></i>
            </button>
            <div class="product-gallery-content">
                <section class="product-gallery-media">
                    <div class="product-gallery-main">
                        <button type="button" class="gallery-nav gallery-nav--prev" id="productGalleryPrev" aria-label="View previous photo">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <figure>
                            <img id="productGalleryMain" src="../assets/img/product-placeholder.svg" alt="Selected product photo">
                            <figcaption id="productGalleryImageCaption"></figcaption>
                        </figure>
                        <button type="button" class="gallery-nav gallery-nav--next" id="productGalleryNext" aria-label="View next photo">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div class="product-gallery-thumbs" id="productGalleryThumbs" role="list"></div>
                    <div class="product-gallery-status" id="productGalleryStatus"></div>
                </section>
                <section class="product-gallery-details" aria-live="polite">
                    <p class="product-gallery-brand" id="productGalleryBrand"></p>
                    <h2 class="product-gallery-heading" id="productGalleryTitle"></h2>
                    <div class="product-gallery-price" id="productGalleryPrice"></div>
                    <div class="product-gallery-meta">
                        <span id="productGalleryCategory"></span>
                        <span id="productGalleryStock"></span>
                    </div>
                    <p class="product-gallery-description" id="productGalleryDescription"></p>
                    <!-- Added: interactive purchase controls in the modal so shoppers can set a quantity and checkout directly. -->
                    <div class="product-gallery-actions">
                        <div class="product-gallery-quantity">
                            <label for="productGalleryQuantity">Quantity</label>
                            <input type="number" id="productGalleryQuantity" min="1" value="1">
                        </div>
                        <div class="product-gallery-buttons">
                            <button type="button" id="productGalleryBuyButton" class="product-gallery-buy">Buy Now</button>
                            <button type="button" id="productGalleryCartButton" class="product-gallery-cart">
                                <i class="fas fa-cart-plus" aria-hidden="true"></i>
                                <span>Add to Cart</span>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
    <!-- Cart functionality -->
     <script src="../assets/js/public/cart.js"></script>
    <!-- Search functionality -->
     <script src="../assets/js/public/search.js"></script>
    <!-- Added: storefront gallery controller that powers the modal defined above. -->
     <script src="../assets/js/public/productGallery.js"></script>
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
