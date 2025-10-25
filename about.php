<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

$logoAsset = assetUrl('assets/logo.png');
$mobileNavScript = assetUrl('assets/js/public/mobileNav.js');
$aboutStylesheet = assetUrl('assets/css/public/about.css');
$faqStylesheet = assetUrl('assets/css/public/faq.css');
$cartScript = assetUrl('assets/js/public/cart.js');
$checkoutModalStylesheet = assetUrl('assets/css/public/checkoutModals.css');
$customerStylesheet = assetUrl('assets/css/public/customer.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$homeUrl = orderingUrl('index.php');
$aboutUrl = orderingUrl('about.php');
$trackOrderUrl = orderingUrl('track-order.php');
$checkoutUrl = orderingUrl('checkout.php');
$loginUrl = orderingUrl('login.php');
$myOrdersUrl = orderingUrl('my_orders.php');
$settingsUrl = orderingUrl('settings.php');
$logoutUrl = orderingUrl('logout.php');
$productPlaceholder = assetUrl('assets/img/product-placeholder.svg');
$customerCartEndpoint = orderingUrl('api/customer-cart.php');

$customerSessionState = customerSessionExport();
$isCustomerAuthenticated = !empty($customerSessionState['authenticated']);
$customerFirstName = $customerSessionState['firstName'] ?? null;
$bodyCustomerState = $isCustomerAuthenticated ? 'authenticated' : 'guest';
$bodyCustomerFirstName = $customerFirstName !== null ? $customerFirstName : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About DGZ Motorshop</title>
     <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($aboutStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($faqStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($checkoutModalStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
</head>
<body data-customer-session="<?= htmlspecialchars($bodyCustomerState) ?>" data-customer-first-name="<?= htmlspecialchars($bodyCustomerFirstName) ?>">
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-controls="primaryNav" aria-expanded="false">
                <span class="mobile-nav-toggle__icon" aria-hidden="true"></span>
                <span class="sr-only">Toggle navigation</span>
            </button>
            <div class="logo">
                <a href="<?= htmlspecialchars($homeUrl) ?>" aria-label="Go to DGZ Motorshop home">
                    <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop Logo">
                </a>
            </div>
            <div class="header-actions">
                <a href="#" class="cart-btn" id="cartButton">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Cart</span>
                    <div class="cart-count" id="cartCount">0</div>
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
        </div>
    </header>

    <div class="header-offset" aria-hidden="true"></div>

    <!-- Navigation -->
    <nav class="nav" id="primaryNav" aria-label="Primary navigation">
        <div class="nav-content">
            <a href="<?= htmlspecialchars($homeUrl) ?>" class="nav-link">HOME</a>
            <a href="<?= htmlspecialchars($aboutUrl) ?>" class="nav-link active">ABOUT</a>
            <a href="<?= htmlspecialchars($trackOrderUrl) ?>" class="nav-link">TRACK ORDER</a>
        </div>
    </nav>

    <div class="nav-backdrop" id="navBackdrop" hidden></div>

    <!-- About Content -->
    <div class="about-container">
        <div class="location-section">
            <h2>Visit Our Sto. Niño Branch</h2>
            <p>Drop by our Antipolo location for premium motorcycle parts, expert advice, and dependable service tailored to your ride.</p>
            <div class="map-container">
<!-- Google Maps iframe -->
<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d304.1754523334477!2d121.17856621742249!3d14.583767431828697!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397bf77cbabe495%3A0x3856d006fdaa046d!2sDGZ%20Antipolo%20Sto.%20Ni%C3%B1o!5e1!3m2!1sen!2sph!4v1759803072975!5m2!1sen!2sph" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>

            </div>
        </div>

        <section class="faq-section">
            <!-- FAQ Section: update questions and answers below -->
            <h2>Frequently Asked Questions</h2>

            <article class="faq-item">
                <!-- FAQ Item 1: edit the question and answer text -->
                <h3 class="faq-question">Do you offer installation services?</h3>
                <p class="faq-answer">Yes, our Sto. Niño branch has certified mechanics ready to install any parts you purchase from us.</p>
            </article>

            <article class="faq-item">
                <!-- FAQ Item 2: edit the question and answer text -->
                <h3 class="faq-question">What brands of motorcycle parts do you carry?</h3>
                <p class="faq-answer">We stock a wide range of trusted local and international brands to make sure riders find the perfect parts for their bikes.</p>
            </article>

            <article class="faq-item">
                <!-- FAQ Item 3: edit the question and answer text -->
                <h3 class="faq-question">Can I request a refund if the item I received is damaged?</h3>
                <p class="faq-answer">We’re not liable for damages after the item is handed to the courier. All products are checked in good condition before shipping.</p>
            </article>

            <article class="faq-item">
                <!-- FAQ Item 4: edit the question and answer text -->
                <h3 class="faq-question">What are your store hours?</h3>
                <p class="faq-answer">We are open Monday to Saturday from 8:00 AM to 10:00 PM, with extended hours during peak riding season.</p>
            </article>
        </section>
    </div>

    <!-- Footer -->
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
    <!-- Cart functionality -->
    <script>
        window.dgzPaths = Object.assign({}, window.dgzPaths || {}, {
            checkout: <?= json_encode($checkoutUrl) ?>,
            productPlaceholder: <?= json_encode($productPlaceholder) ?>,
            customerCart: <?= json_encode($customerCartEndpoint) ?>
        });
    </script>
    <script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
    <script src="<?= htmlspecialchars($cartScript) ?>"></script>
    <script src="<?= htmlspecialchars($mobileNavScript) ?>"></script>
</body>
</html>
