<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your DGZ Motorshop Order</title>
    <!-- Font Awesome for consistent iconography across the storefront -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Page specific stylesheet lives alongside the other public assets -->
    <link rel="stylesheet" href="../assets/css/public/track-order.css">
</head>
<body>
    <!-- Header: mirrors the home page layout so the experience feels seamless -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <img src="../assets/logo.png" alt="DGZ Motorshop Logo">
            </div>

            <!-- Optional search bar retained for visual consistency with the homepage -->
            <div class="search-container">
                <input type="text" class="search-bar" placeholder="Search by Category, Part, Brand...">
                <button class="search-btn" type="button" aria-label="Search">
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

    <!-- Navigation: adds the Track Order entry while keeping existing links -->
    <nav class="nav">
        <div class="nav-content">
            <a href="index.php" class="nav-link">HOME</a>
            <a href="about.php" class="nav-link">ABOUT</a>
            <a href="track-order.php" class="nav-link active">TRACK ORDER</a>
        </div>
    </nav>

    <!-- Main tracker module -->
    <main class="tracker-wrapper">
        <section class="tracker-card">
            <h1>Track Your Order</h1>
            <p>Enter the order ID from your confirmation message to see the latest status and important details.</p>

            <!-- Order lookup form -->
            <form id="orderTrackerForm" class="tracker-form">
                <label for="orderIdInput">Order ID</label>
                <input type="number" id="orderIdInput" name="order_id" class="tracker-input" placeholder="e.g. 1024" min="1" required>
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

    <!-- Footer reused from the other public pages -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-column contact-info">
                <p><strong>Visit Us:</strong> Lot 2 Blk 3 Dolores Road, Brgy. Sto. Niño, Antipolo City</p>
                <p><strong>Call:</strong> <a href="tel:+639123456789">+63 912 345 6789</a></p>
                <p><strong>Email:</strong> <a href="mailto:orders@dgzmotorshop.com">orders@dgzmotorshop.com</a></p>
            </div>
            <div class="footer-column footer-meta">
                <div class="social-links">
                    <a href="https://www.facebook.com/dgzstonino"><i class="fab fa-facebook-f"></i></a>
                </div>
                <p>© 2022-2025 DGZ Motorshop - Sto. Niño Branch. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Shared cart functionality -->
    <script src="../assets/js/public/cart.js"></script>
    <!-- Page specific logic for handling status lookups -->
    <script src="../assets/js/public/track-order.js"></script>
</body>
</html>
