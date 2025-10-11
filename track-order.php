<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';

$logoAsset = assetUrl('assets/logo.png');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$trackStylesheet = assetUrl('assets/css/public/track-order.css');
$cartScript = assetUrl('assets/js/public/cart.js');
$searchScript = assetUrl('assets/js/public/search.js');
$trackScript = assetUrl('assets/js/public/track-order.js');
$homeUrl = orderingUrl('index.php');
$aboutUrl = orderingUrl('about.php');
$trackOrderUrl = orderingUrl('track-order.php');
$checkoutUrl = orderingUrl('checkout.php');
$orderStatusEndpoint = orderingUrl('order_status.php');
$productPlaceholder = assetUrl('assets/img/product-placeholder.svg');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your DGZ Motorshop Order</title>
    <!-- Font Awesome for consistent iconography across the storefront -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Shared index stylesheet for consistent footer and other styles -->
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <!-- Page specific stylesheet lives alongside the other public assets -->
    <link rel="stylesheet" href="<?= htmlspecialchars($trackStylesheet) ?>">
</head>
<body>
    <!-- Header: mirrors the home page layout so the experience feels seamless -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop Logo">
            </div>

            <div class="search-container">
                <input type="text" class="search-bar" placeholder="Search by Category, Part, Brand...">
                <button class="search-btn" type="button">
                    <i class="fas fa-search"></i>
                </button>
            </div>

            <a href="#" class="cart-btn" id="cartButton">
                <i class="fas fa-shopping-cart" aria-hidden="true"></i>
                <span>Cart</span>
                <div class="cart-count" id="cartCount">0</div>
            </a>
        </div>
    </header>

    <!-- Navigation: adds the Track Order entry while keeping existing links -->
    <nav class="nav">
        <div class="nav-content">
            <a href="<?= htmlspecialchars($homeUrl) ?>" class="nav-link">HOME</a>
            <a href="<?= htmlspecialchars($aboutUrl) ?>" class="nav-link">ABOUT</a>
            <a href="<?= htmlspecialchars($trackOrderUrl) ?>" class="nav-link active">TRACK ORDER</a>
        </div>
    </nav>

    <!-- Main tracker module -->
    <main class="tracker-wrapper">
        <section class="tracker-card">
            <h1>Track Your Order</h1>
            <p>Enter the tracking code from your confirmation message to see the latest status and important details.</p>

            <!-- Order lookup form -->
            <form id="orderTrackerForm" class="tracker-form">
                <label for="trackingCodeInput">Tracking Code</label>
                <input type="text" id="trackingCodeInput" name="tracking_code" class="tracker-input" placeholder="e.g. DGZ-ABCD-1234" autocomplete="off" required>
                <button type="submit" class="tracker-button">Track Order</button>
            </form>

            <!-- Feedback area for validation messages / API errors -->
            <div id="trackerFeedback" class="feedback-message" hidden></div>

            <!-- Status card displayed once a valid order is found -->
            <div id="trackerStatusPanel" class="status-panel" hidden>
                <div class="status-header">
                    <div id="trackerStatusPill" class="status-pill" data-status="pending">pending</div>
                    <p id="trackerStatusMessage">We found your order. Stay tuned for updates!</p>
                </div>
                <div id="trackerStatusDetails" class="status-details"></div>
            </div>
        </section>
    </main>

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

    <!-- Shared cart functionality -->
    <script>
        window.dgzPaths = Object.assign({}, window.dgzPaths || {}, {
            checkout: <?= json_encode($checkoutUrl) ?>,
            orderStatus: <?= json_encode($orderStatusEndpoint) ?>,
            productPlaceholder: <?= json_encode($productPlaceholder) ?>
        });
    </script>
    <script src="<?= htmlspecialchars($cartScript) ?>"></script>
    <script src="<?= htmlspecialchars($searchScript) ?>"></script>
    <!-- Page specific logic for handling status lookups -->
    <script src="<?= htmlspecialchars($trackScript) ?>"></script>
</body>
</html>
