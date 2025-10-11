<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require_once __DIR__ . '/dgz_motorshop_system/includes/product_variants.php'; // Added: load helpers for variant-aware storefront rendering.
$pdo = db();
$products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();
$productVariantMap = fetchVariantsForProducts($pdo, array_column($products, 'id')); // Added: preload variant rows for customer UI.

$logoAsset = assetUrl('assets/logo.png');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$productPlaceholder = assetUrl('assets/img/product-placeholder.svg');
$cartScript = assetUrl('assets/js/public/cart.js');
$searchScript = assetUrl('assets/js/public/search.js');
$mobileNavScript = assetUrl('assets/js/public/mobileNav.js');
$mobileFiltersScript = assetUrl('assets/js/public/mobileFilters.js');
$galleryScript = assetUrl('assets/js/public/productGallery.js');
$termsScript = assetUrl('assets/js/public/termsNotice.js');
$homeUrl = orderingUrl('index.php');
$aboutUrl = orderingUrl('about.php');
$trackOrderUrl = orderingUrl('track-order.php');
$checkoutUrl = orderingUrl('checkout.php');
$productImagesEndpoint = orderingUrl('api/product-images.php');

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
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <style>

    </style>
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-controls="primaryNav" aria-expanded="false">
                <span class="mobile-nav-toggle__icon" aria-hidden="true"></span>
                <span class="sr-only">Toggle navigation</span>
            </button>
            <div class="logo">
                <img src="<?= htmlspecialchars($logoAsset) ?>" alt="Company Logo">
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

    <div class="header-offset" aria-hidden="true"></div>

    <!-- Navigation -->
    <nav class="nav" id="primaryNav" aria-label="Primary navigation">
        <div class="nav-content">
            <a href="<?= htmlspecialchars($homeUrl) ?>" class="nav-link active">HOME</a>
            <a href="<?= htmlspecialchars($aboutUrl) ?>" class="nav-link">ABOUT</a>
            <a href="<?= htmlspecialchars($trackOrderUrl) ?>" class="nav-link">TRACK ORDER</a>
        </div>
    </nav>

    <div class="nav-backdrop" id="navBackdrop" hidden></div>

    <div class="mobile-toolbar" id="mobileCatalogToolbar" aria-label="Catalog controls">
        <button type="button" class="toolbar-btn" id="mobileFilterToggle" aria-controls="categorySidebar" aria-expanded="false">
            <i class="fas fa-sliders" aria-hidden="true"></i>
            <span class="toolbar-btn__label">Filter</span>
        </button>
        <button type="button" class="toolbar-btn" id="mobileSortToggle" aria-controls="sortSheet" aria-expanded="false">
            <i class="fas fa-arrow-down-wide-short" aria-hidden="true"></i>
            <span class="toolbar-btn__label">
                Sort
                <span class="toolbar-btn__value" id="mobileSortValue">Recommended</span>
            </span>
        </button>
    </div>

    <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>

    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar" id="categorySidebar" aria-labelledby="mobileFilterToggle">
            <button type="button" class="sidebar-close" id="sidebarCloseButton">
                <i class="fas fa-xmark" aria-hidden="true"></i>
                <span class="sidebar-close__label">Close</span>
            </button>
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
                        $variantsForProduct = $productVariantMap[$p['id']] ?? [];
                        $variantSummary = summariseVariantStock($variantsForProduct);
                        $displayPrice = isset($variantSummary['price']) ? $variantSummary['price'] : $p['price'];
                        $displayQuantity = isset($variantSummary['quantity']) ? $variantSummary['quantity'] : $p['quantity'];
                        $defaultVariant = null;
                        foreach ($variantsForProduct as $variantRow) {
                            if (!empty($variantRow['is_default'])) {
                                $defaultVariant = $variantRow;
                                break;
                            }
                        }
                        if ($defaultVariant === null && !empty($variantsForProduct)) {
                            $defaultVariant = $variantsForProduct[0];
                        }
                        if ($defaultVariant !== null) {
                            $defaultQty = isset($defaultVariant['quantity']) ? (int) $defaultVariant['quantity'] : null;
                            if ($defaultQty !== null && $defaultQty <= 0) {
                                foreach ($variantsForProduct as $variantRow) {
                                    $candidateQty = isset($variantRow['quantity']) ? (int) $variantRow['quantity'] : null;
                                    if ($candidateQty === null || $candidateQty > 0) {
                                        $defaultVariant = $variantRow;
                                        break;
                                    }
                                }
                            }
                        }
                        $variantsJson = htmlspecialchars(json_encode($variantsForProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    ?>
                    <?php
                        $categorySlug = strtolower(trim($category ?: 'Other'));
                    ?>
                    <?php
                        // Added: resolve the primary photo that should appear on the grid.
                        $rawImagePath = trim((string)($p['image'] ?? ''));
                        $hasCustomImage = $rawImagePath !== '';
                        $normalizedImagePath = $hasCustomImage ? publicAsset($rawImagePath) : $productPlaceholder;
                    ?>
                    <div class="product-card"
                        data-category="<?= htmlspecialchars($categorySlug) ?>"
                        data-brand="<?= htmlspecialchars(strtolower($brand)) ?>"
                        data-product-id="<?= (int) $p['id'] ?>"
                        data-product-name="<?= htmlspecialchars($p['name']) ?>"
                        data-product-brand="<?= htmlspecialchars($brand) ?>"
                        data-product-category-label="<?= htmlspecialchars($category) ?>"
                        data-product-description="<?= htmlspecialchars($p['description'], ENT_QUOTES) ?>"
                        data-product-price="<?= htmlspecialchars(number_format((float)$displayPrice, 2, '.', '')) ?>"
                        data-product-quantity="<?= (int) $displayQuantity ?>"
                        data-product-variants="<?= $variantsJson ?>"
                        data-product-default-variant-id="<?= htmlspecialchars($defaultVariant['id'] ?? '') ?>"
                        data-product-default-variant-label="<?= htmlspecialchars($defaultVariant['label'] ?? '') ?>"
                        data-product-default-variant-price="<?= htmlspecialchars(isset($defaultVariant['price']) ? number_format((float)$defaultVariant['price'], 2, '.', '') : '') ?>"
                        data-product-default-variant-quantity="<?= htmlspecialchars(isset($defaultVariant['quantity']) ? (int)$defaultVariant['quantity'] : '') ?>"
                        data-primary-image="<?= htmlspecialchars($hasCustomImage ? $normalizedImagePath : $productPlaceholder) ?>"
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
                            <div class="price">₱<?=number_format($displayPrice,2)?></div>
                            <div class="stock <?= $displayQuantity <= 5 ? ($displayQuantity == 0 ? 'out' : 'low') : '' ?>" data-stock="<?= (int) $displayQuantity ?>">
                                <?php if ($displayQuantity == 0): ?>
                                    <span class="stock-status-text">Out of stock</span>
                                <?php else: ?>
                                    <span class="stock-indicator" aria-hidden="true"></span>
                                    <span class="stock-status-text">In stock</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="buy-form">
                            <input type="number" name="qty" value="1" min="1" max="<?=max(1,$displayQuantity)?>"
                                class="qty-input" <?= $displayQuantity == 0 ? 'disabled' : '' ?> data-gallery-ignore="true">

                            <button class="add-cart-btn" data-gallery-ignore="true"
                                onclick="(function(button) {
                                    const qtyInput = button.parentElement.querySelector('.qty-input');
                                    const qty = qtyInput ? parseInt(qtyInput.value) || 1 : 1;
                                    if (qtyInput && !qtyInput.checkValidity()) {
                                        qtyInput.reportValidity();
                                        return false;
                                    }
                                    addToCart(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', <?= $displayPrice ?>, qty, <?= isset($defaultVariant['id']) ? (int)$defaultVariant['id'] : 'null' ?>, '<?= htmlspecialchars(addslashes($defaultVariant['label'] ?? '')) ?>', <?= isset($defaultVariant['price']) ? $defaultVariant['price'] : $displayPrice ?>);
                                })(this)"
                                <?= $displayQuantity == 0 ? 'disabled' : '' ?>>
                                <i class="fas fa-cart-plus"></i> Add to Cart
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Footer -->

    </div>
    <div class="sort-sheet" id="sortSheet" role="dialog" aria-modal="true" aria-labelledby="sortSheetTitle" hidden>
        <div class="sort-sheet__panel">
            <div class="sort-sheet__header">
                <h2 class="sort-sheet__title" id="sortSheetTitle">Sort Products</h2>
                <button type="button" class="sort-sheet__close" id="sortSheetClose" aria-label="Close sort options">
                    <i class="fas fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <div class="sort-sheet__options" role="radiogroup" aria-labelledby="sortSheetTitle">
                <button type="button" class="sort-option is-active" data-sort="recommended" data-label="Recommended" data-short-label="Recommended" aria-pressed="true">Recommended</button>
                <button type="button" class="sort-option" data-sort="price-asc" data-label="Price: Low to High" data-short-label="Price ↑" aria-pressed="false">Price: Low to High</button>
                <button type="button" class="sort-option" data-sort="price-desc" data-label="Price: High to Low" data-short-label="Price ↓" aria-pressed="false">Price: High to Low</button>
                <button type="button" class="sort-option" data-sort="name-asc" data-label="Name: A to Z" data-short-label="Name A–Z" aria-pressed="false">Name: A to Z</button>
            </div>
        </div>
    </div>

    <div class="terms-overlay" id="termsOverlay" role="dialog" aria-modal="true" aria-labelledby="termsTitle" hidden>
        <div class="terms-overlay__backdrop" aria-hidden="true"></div>
        <div class="terms-overlay__dialog" role="document">
            <h2 class="terms-overlay__title" id="termsTitle">Before you continue</h2>
            <p class="terms-overlay__description">
                By placing an order with DGZ Motorshop, you agree that:
            </p>
            <ol class="terms-overlay__list">
                <li>The customer who confirms the booking will arrange the courier and shoulder the delivery fee.</li>
                <li>Once the items are handed over to the courier, the shop is not responsible for any damages during transit.</li>
            </ol>
            <button type="button" class="terms-overlay__button" id="termsAcceptButton">I Understand and Accept</button>
        </div>
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
                            <img id="productGalleryMain" src="<?= htmlspecialchars($productPlaceholder) ?>" alt="Selected product photo">
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
                    <div class="product-gallery-variants" id="productGalleryVariants" hidden>
                        <!-- Added: variant selector so buyers can choose size before adding to cart. -->
                        <span class="product-gallery-variants__label">Select variant</span>
                        <div class="product-gallery-variants__list" id="productGalleryVariantList"></div>
                    </div>
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
    <script>
        window.dgzPaths = Object.assign({}, window.dgzPaths || {}, {
            checkout: <?= json_encode($checkoutUrl) ?>,
            productImages: <?= json_encode($productImagesEndpoint) ?>,
            productPlaceholder: <?= json_encode($productPlaceholder) ?>
        });
    </script>
    <script src="<?= htmlspecialchars($cartScript) ?>"></script>
    <!-- Search functionality -->
    <script src="<?= htmlspecialchars($searchScript) ?>"></script>
    <!-- Mobile primary navigation -->
    <script src="<?= htmlspecialchars($mobileNavScript) ?>"></script>
    <!-- Mobile filter & sort controls -->
    <script src="<?= htmlspecialchars($mobileFiltersScript) ?>"></script>
    <!-- Added: storefront gallery controller that powers the modal defined above. -->
    <script src="<?= htmlspecialchars($galleryScript) ?>"></script>
    <!-- Terms acknowledgement overlay -->
    <script src="<?= htmlspecialchars($termsScript) ?>"></script>
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
