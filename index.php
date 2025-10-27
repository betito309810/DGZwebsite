<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require_once __DIR__ . '/dgz_motorshop_system/includes/product_variants.php'; // Added: load helpers for variant-aware storefront rendering.
require_once __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';
$pdo = db();
$productsActiveClause = productsArchiveActiveCondition($pdo, '', true);
$products = $pdo->query('SELECT * FROM products WHERE ' . $productsActiveClause . ' ORDER BY name')->fetchAll();

$bestSellerTotals = [];
$saleStatuses = ['pending', 'payment_verification', 'approved', 'delivery', 'completed', 'complete'];
if (!empty($saleStatuses)) {
    $placeholders = implode(',', array_fill(0, count($saleStatuses), '?'));
    try {
        $bestSellerStmt = $pdo->prepare(
            'SELECT oi.product_id, SUM(oi.qty) AS total_sold
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id IS NOT NULL AND o.status IN (' . $placeholders . ')
             GROUP BY oi.product_id'
        );
        $bestSellerStmt->execute($saleStatuses);
        foreach ($bestSellerStmt->fetchAll(PDO::FETCH_ASSOC) as $bestSellerRow) {
            $productId = isset($bestSellerRow['product_id']) ? (int) $bestSellerRow['product_id'] : 0;
            $soldCount = isset($bestSellerRow['total_sold']) ? (int) $bestSellerRow['total_sold'] : 0;
            if ($productId > 0) {
                $bestSellerTotals[$productId] = max(0, $soldCount);
            }
        }
    } catch (Exception $exception) {
        error_log('Unable to compute best seller totals: ' . $exception->getMessage());
        $bestSellerTotals = [];
    }
}
$productIds = array_column($products, 'id');
$productVariantMap = fetchVariantsForProducts($pdo, $productIds); // Added: preload variant rows for customer UI.

$variantIds = [];
foreach ($productVariantMap as $variants) {
    foreach ($variants as $variantRow) {
        $variantId = isset($variantRow['id']) ? (int) $variantRow['id'] : 0;
        if ($variantId > 0) {
            $variantIds[$variantId] = $variantId;
        }
    }
}

$reservationSummary = inventoryReservationsFetchMap($pdo, $productIds, array_values($variantIds));

foreach ($productVariantMap as $productId => &$variants) {
    foreach ($variants as &$variantRow) {
        $variantId = isset($variantRow['id']) ? (int) $variantRow['id'] : 0;
        if ($variantId <= 0) {
            continue;
        }

        $reserved = $reservationSummary['variants'][$variantId] ?? 0;
        $available = max(0, (int) ($variantRow['quantity'] ?? 0) - $reserved);
        $variantRow['available_quantity'] = $available;
        $variantRow['quantity'] = $available;
    }
}
unset($variants, $variantRow);

$logoAsset = assetUrl('assets/logo.png');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$productPlaceholder = assetUrl('assets/img/product-placeholder.svg');
$checkoutModalStylesheet = assetUrl('assets/css/public/checkoutModals.css');
$dialogsScript = assetUrl('assets/js/shared/dialogs.js');
$cartScript = assetUrl('assets/js/public/cart.js');
$searchScript = assetUrl('assets/js/public/search.js');
$mobileNavScript = assetUrl('assets/js/public/mobileNav.js');
$mobileFiltersScript = assetUrl('assets/js/public/mobileFilters.js');
$galleryScript = assetUrl('assets/js/public/productGallery.js');
$termsScript = assetUrl('assets/js/public/termsNotice.js');
$inventoryAvailabilityScript = assetUrl('assets/js/public/inventoryAvailability.js');
$inventoryAvailabilityApi = orderingUrl('api/inventory-availability.php');
$homeUrl = orderingUrl('index.php');
$aboutUrl = orderingUrl('about.php');
$trackOrderUrl = orderingUrl('track-order.php');
$checkoutUrl = orderingUrl('checkout.php');
$cartUrl = $checkoutUrl;
$productImagesEndpoint = orderingUrl('api/product-images.php');
$customerCartEndpoint = orderingUrl('api/customer-cart.php');
$customerSessionStatusEndpoint = orderingUrl('api/customer-session-status.php');
$customerSessionHeartbeatInterval = 200;

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$customerSessionState = customerSessionExport();
$isCustomerAuthenticated = !empty($customerSessionState['authenticated']);
$customerFirstName = $customerSessionState['firstName'] ?? null;
$loginUrl = orderingUrl('login.php');
$registerUrl = orderingUrl('register.php');
$myOrdersUrl = orderingUrl('my_orders.php');
$settingsUrl = orderingUrl('settings.php');
$logoutUrl = orderingUrl('logout.php');

$bodyCustomerState = $isCustomerAuthenticated ? 'authenticated' : 'guest';
$bodyCustomerFirstName = $customerFirstName !== null ? $customerFirstName : '';

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
     <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($checkoutModalStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
</head>

<body data-customer-session="<?= htmlspecialchars($bodyCustomerState) ?>"
    data-customer-first-name="<?= htmlspecialchars($bodyCustomerFirstName) ?>"
    data-customer-session-heartbeat="<?= htmlspecialchars($customerSessionStatusEndpoint) ?>"
    data-customer-session-heartbeat-interval="<?= (int) $customerSessionHeartbeatInterval ?>"
    data-customer-login-url="<?= htmlspecialchars($loginUrl) ?>">
    <!-- Header -->
    <header class="customer-orders-header customer-orders-header--with-search">
        <div class="customer-orders-brand">
            <a href="<?= htmlspecialchars($homeUrl) ?>" class="customer-orders-logo" aria-label="DGZ Motorshop home">
                <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop logo">
            </a>
        </div>
        <div class="customer-orders-search" role="search">
            <div class="search-container">
                <input type="text" class="search-bar" placeholder="Search by Category, Part, Brand..." aria-label="Search products">
            </div>
        </div>
        <div class="customer-orders-actions">
            <a href="<?= htmlspecialchars($cartUrl ?? '#') ?>" class="customer-orders-cart" id="cartButton">
                <i class="fas fa-shopping-cart" aria-hidden="true"></i>
                <span class="customer-orders-cart__label">Cart</span>
                <span class="customer-orders-cart__count" id="cartCount">0</span>
            </a>
            <div class="account-menu" data-account-menu>
                <?php if ($isCustomerAuthenticated): ?>
                    <button type="button" class="account-menu__trigger" data-account-trigger aria-haspopup="true" aria-expanded="false">
                        <span class="account-menu__avatar" aria-hidden="true"><i class="fas fa-user-circle"></i></span>
                        <span class="account-menu__label"><?= htmlspecialchars($customerFirstName ?? 'Account') ?></span>
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="account-menu__dropdown" data-account-dropdown hidden>
                        <a href="<?= htmlspecialchars($myOrdersUrl) ?>" class="account-menu__link">My Orders</a>
                        <a href="<?= htmlspecialchars($settingsUrl) ?>" class="account-menu__link">Settings</a>
                        <a href="<?= htmlspecialchars($logoutUrl) ?>" class="account-menu__link">Logout</a>
                    </div>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($loginUrl) ?>" class="account-menu__guest" data-account-login>
                        <span class="account-menu__avatar" aria-hidden="true"><i class="fas fa-user-circle"></i></span>
                        <span class="account-menu__label">Log In</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Navigation -->
    <nav class="nav" id="primaryNav" aria-label="Primary navigation">
        <div class="nav-content">
            <a href="<?= htmlspecialchars($homeUrl) ?>" class="nav-link active">HOME</a>
            <a href="<?= htmlspecialchars($aboutUrl) ?>" class="nav-link">ABOUT</a>
            <a href="<?= htmlspecialchars($trackOrderUrl) ?>" class="nav-link">TRACK ORDER</a>
        </div>
    </nav>

    <div class="nav-backdrop" id="navBackdrop" hidden></div>

    <?php require __DIR__ . '/dgz_motorshop_system/includes/login_required_modal.php'; ?>

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
                <div class="catalog-header">
                    <div class="catalog-header__spacer" aria-hidden="true"></div>
                    <h2 id="productSectionTitle" class="catalog-title">All Products</h2>
                    <div class="catalog-toolbar-desktop" id="desktopCatalogToolbar" aria-label="Catalog sorting controls">
                        <div class="catalog-sort">
                            <label for="desktopSortSelect" class="catalog-sort__label">
                                <i class="fas fa-arrow-down-wide-short" aria-hidden="true"></i>
                                <span>Sort by</span>
                            </label>
                            <div class="catalog-sort__select-wrapper">
                                <select id="desktopSortSelect" class="catalog-sort__select" aria-label="Sort products">
                                    <option value="recommended">Recommended</option>
                                    <option value="best-seller">Best Sellers</option>
                                    <option value="newest">Newest</option>
                                    <option value="price-asc">Price: Low to High</option>
                                    <option value="price-desc">Price: High to Low</option>
                                    <option value="name-asc">Name: A to Z</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="products-grid">
                    <?php foreach($products as $p):
                        $category = isset($p['category']) ? $p['category'] : '';
                        $brand = isset($p['brand']) ? $p['brand'] : '';
                        $variantsForProduct = $productVariantMap[$p['id']] ?? [];
                        $variantSummary = summariseVariantStock($variantsForProduct);
                        $displayPrice = isset($variantSummary['price']) ? $variantSummary['price'] : $p['price'];
                        $productReserved = $reservationSummary['products'][$p['id']] ?? 0;
                        $availableProductQuantity = max(0, (int) ($p['quantity'] ?? 0) - $productReserved);
                        $rawDisplayQuantity = isset($variantSummary['quantity']) ? (int) $variantSummary['quantity'] : $availableProductQuantity;
                        $displayQuantity = max(0, $rawDisplayQuantity);
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
                        $defaultVariantAvailable = null;
                        if ($defaultVariant !== null) {
                            $defaultQty = isset($defaultVariant['available_quantity'])
                                ? (int) $defaultVariant['available_quantity']
                                : (isset($defaultVariant['quantity']) ? (int) $defaultVariant['quantity'] : null);
                            if ($defaultQty !== null && $defaultQty < 0) {
                                $defaultQty = 0;
                            }
                            $defaultVariantAvailable = $defaultQty;
                            if ($defaultQty !== null && $defaultQty <= 0) {
                                foreach ($variantsForProduct as $variantRow) {
                                    $candidateQty = isset($variantRow['available_quantity'])
                                        ? (int) $variantRow['available_quantity']
                                        : (isset($variantRow['quantity']) ? (int) $variantRow['quantity'] : null);
                                    if ($candidateQty !== null && $candidateQty < 0) {
                                        $candidateQty = 0;
                                    }
                                    if ($candidateQty === null || $candidateQty > 0) {
                                        $defaultVariant = $variantRow;
                                        $defaultVariantAvailable = $candidateQty !== null ? $candidateQty : $defaultVariantAvailable;
                                        break;
                                    }
                                }
                            }
                        }
                        $defaultVariantQuantity = $defaultVariantAvailable !== null
                            ? max(0, (int) $defaultVariantAvailable)
                            : '';
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
                    <?php
                        $createdAtRaw = $p['created_at'] ?? null;
                        $createdTimestamp = 0;
                        if (!empty($createdAtRaw)) {
                            $createdParsed = strtotime((string) $createdAtRaw);
                            if ($createdParsed !== false) {
                                $createdTimestamp = (int) $createdParsed;
                            }
                        }
                        $totalSold = isset($bestSellerTotals[$p['id']]) ? (int) $bestSellerTotals[$p['id']] : 0;
                    ?>
                    <div class="product-card"
                        data-category="<?= htmlspecialchars($categorySlug) ?>"
                        data-brand="<?= htmlspecialchars(strtolower($brand)) ?>"
                        data-product-id="<?= (int) $p['id'] ?>"
                        data-product-name="<?= htmlspecialchars($p['name']) ?>"
                        data-product-brand="<?= htmlspecialchars($brand) ?>"
                        data-product-category-label="<?= htmlspecialchars($category) ?>"
                        data-product-description="<?= htmlspecialchars($p['description'], ENT_QUOTES) ?>"
                        data-product-created="<?= $createdTimestamp ?>"
                        data-product-sold="<?= $totalSold ?>"
                        data-product-price="<?= htmlspecialchars(number_format((float)$displayPrice, 2, '.', '')) ?>"
                        data-product-quantity="<?= $displayQuantity ?>"
                        data-product-variants="<?= $variantsJson ?>"
                        data-product-default-variant-id="<?= htmlspecialchars($defaultVariant['id'] ?? '') ?>"
                        data-product-default-variant-label="<?= htmlspecialchars($defaultVariant['label'] ?? '') ?>"
                        data-product-default-variant-price="<?= htmlspecialchars(isset($defaultVariant['price']) ? number_format((float)$defaultVariant['price'], 2, '.', '') : '') ?>"
                        data-product-default-variant-quantity="<?= htmlspecialchars($defaultVariantQuantity) ?>"
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

                        <!-- Removed inline product description from grid card; still available in modal via data-product-description -->
                        <p class="product-meta" style="font-size:12px;color:#888;">
                            Category: <?= htmlspecialchars($category) ?> | Brand: <?= htmlspecialchars($brand) ?>
                        </p>

                        <div class="product-footer">
                            <div class="price">₱<?=number_format($displayPrice,2)?></div>
                            <div class="stock <?= $displayQuantity <= 5 ? ($displayQuantity == 0 ? 'out' : 'low') : '' ?>" data-stock="<?= (int) $displayQuantity ?>">
                                <?php if ($displayQuantity == 0): ?>
                                    <span class="stock-status-text">Out of stock</span>
                                <?php elseif ($displayQuantity <= 5): ?>
                                    <span class="stock-indicator" aria-hidden="true"></span>
                                    <span class="stock-status-text">Stock: <?= (int) $displayQuantity ?></span>
                                <?php else: ?>
                                    <span class="stock-indicator" aria-hidden="true"></span>
                                    <span class="stock-status-text">Stock: <?= (int) $displayQuantity ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="buy-form">
                            <input type="number" name="qty" value="1" min="1" max="<?=max(1,$displayQuantity)?>"
                                class="qty-input" <?= $displayQuantity == 0 ? 'disabled' : '' ?> data-gallery-ignore="true">

                            <button class="add-cart-btn" data-gallery-ignore="true"
                                onclick="(function(button) {
                                    const qtyInput = button.parentElement.querySelector('.qty-input');
                                    const qty = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;
                                    if (qtyInput && !qtyInput.checkValidity()) {
                                        qtyInput.reportValidity();
                                        return false;
                                    }

                                    const card = button.closest('.product-card');
                                    let variants = [];
                                    if (card) {
                                        try {
                                            variants = JSON.parse(card.dataset.productVariants || '[]');
                                        } catch (error) {
                                            variants = [];
                                        }
                                    }

                                    if (Array.isArray(variants) && variants.length > 0) {
                                        if (typeof window.openProductModalFromCard === 'function' && card) {
                                            window.openProductModalFromCard(card, {
                                                forceVariantSelection: true,
                                                focusVariantSelector: true,
                                                hideBuyButton: true,
                                                presetQuantity: qty
                                            });
                                        } else {
                                            if (window.dgzAlert && typeof window.dgzAlert === 'function') {
                                                window.dgzAlert('Please select a variant for this product before adding it to your cart.');
                                            } else {
                                                window.alert('Please select a variant for this product before adding it to your cart.');
                                            }
                                        }
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
                <button type="button" class="sort-option" data-sort="best-seller" data-label="Best Sellers" data-short-label="Best" aria-pressed="false">Best Sellers</button>
                <button type="button" class="sort-option" data-sort="newest" data-label="Newest" data-short-label="New" aria-pressed="false">Newest</button>
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
            <div class="terms-overlay__content" id="termsScrollRegion" tabindex="0" data-terms-scroll>
                <p class="terms-overlay__description">
                    Please review the Terms and Conditions below. Scroll to the bottom to enable the accept button.
                </p>
                <ol class="terms-overlay__list">
                    <li><strong>Payment and approval</strong> — You must pay for your order first before it is reviewed and approved. After an order is approved, <strong>cancellations are no longer allowed</strong>.</li>
                    <li><strong>Delivery coverage</strong> — We can deliver to far cities; we will review your location first and confirm delivery availability, lead times, and fees before fulfillment.</li>
                    <li><strong>Delivery fee</strong> — The delivery fee must be paid to the courier upon receiving the order, unless otherwise stated on your checkout method.</li>
                    <li><strong>Condition and liability</strong> — All products are checked and packed in good condition before shipping. Once the item is handed to the courier, DGZ Motorshop is no longer responsible for any loss or damage in transit.</li>
                    <li><strong>Contact information</strong> — Please provide accurate address and contact details so the courier can reach you. Delays caused by incomplete or incorrect information are not covered.</li>
                    <li><strong>Acceptance</strong> — By continuing, you acknowledge that you have read and agree to these terms.</li>
                </ol>
            </div>
            <button type="button" class="terms-overlay__button" id="termsAcceptButton" disabled data-terms-accept>I Understand and Accept</button>
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
    <script src="<?= htmlspecialchars($dialogsScript) ?>"></script>
    <script>
        window.dgzPaths = Object.assign({}, window.dgzPaths || {}, {
            checkout: <?= json_encode($checkoutUrl) ?>,
            productImages: <?= json_encode($productImagesEndpoint) ?>,
            productPlaceholder: <?= json_encode($productPlaceholder) ?>,
            inventoryAvailability: <?= json_encode($inventoryAvailabilityApi) ?>,
            customerCart: <?= json_encode($customerCartEndpoint) ?>
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
    <script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
    <script src="<?= htmlspecialchars($termsScript) ?>"></script>
    <!-- Live inventory watcher -->
    <script src="<?= htmlspecialchars($inventoryAvailabilityScript) ?>"></script>
    </div>
   <footer class="footer">
    <div class="footer-content">
        <!-- Contact info on the far left -->
        <div class="footer-column contact-info">
            <p><strong>Visit Us:</strong> Lot 2 Blk 3 Dolores Road, Brgy. Sto. Niño, Antipolo City</p>
            <p><strong>Call:</strong> <a href="tel:+639123456789">09536514033</a></p>
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
