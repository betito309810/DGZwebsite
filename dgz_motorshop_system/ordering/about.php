<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About DGZ Motorshop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/public/shared.css">
    <link rel="stylesheet" href="../assets/css/public/about.css">
    <link rel="stylesheet" href="../assets/css/public/faq.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <a href="index.php" class="logo" aria-label="DGZ Motorshop home">
                <img src="../assets/logo.png" alt="DGZ Motorshop Logo">
            </a>
            <div class="header-actions">
                <a href="#" class="cart-btn" id="cartButton">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Cart</span>
                    <div class="cart-count" id="cartCount">0</div>
                </a>
            </div>
        </div>
    </header>

    <!-- Navigation -->
       <nav class="nav">
        <div class="nav-content">
            <a href="index.php" class="nav-link">HOME</a>
            <a href="about.php" class="nav-link active">ABOUT</a>
            <a href="track-order.php" class="nav-link">TRACK ORDER</a>

        </div>
    </nav>

    <!-- About Content -->
    <main class="about-page">
        <section class="about-location">
            <div class="about-location__copy">
                <span class="about-location__eyebrow">Visit Us</span>
                <h1>Visit Our Sto. Niño Branch</h1>
                <p>Drop by our Antipolo location for premium motorcycle parts, expert advice, and dependable service tailored to your ride.</p>
                <ul class="location-details">
                    <li>
                        <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                        <span>Lot 2 Blk 3 Dolores Road, Brgy. Sto. Niño, Antipolo City</span>
                    </li>
                    <li>
                        <i class="fas fa-phone" aria-hidden="true"></i>
                        <span><a href="tel:+639123456789">+63 912 345 6789</a></span>
                    </li>
                    <li>
                        <i class="fas fa-envelope" aria-hidden="true"></i>
                        <span><a href="mailto:orders@dgzmotorshop.com">orders@dgzmotorshop.com</a></span>
                    </li>
                    <li>
                        <i class="fas fa-clock" aria-hidden="true"></i>
                        <span>Open Monday to Saturday, 9:00 AM – 6:00 PM</span>
                    </li>
                </ul>
            </div>
            <div class="map-container">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d304.1754523334477!2d121.17856621742249!3d14.583767431828697!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397bf77cbabe495%3A0x3856d006fdaa046d!2sDGZ%20Antipolo%20Sto.%20Ni%C3%B1o!5e1!3m2!1sen!2sph!4v1759803072975!5m2!1sen!2sph" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="DGZ Motorshop Sto. Niño map"></iframe>
            </div>
        </section>

        <section class="faq-section">
            <div class="faq-header">
                <h2>Frequently Asked Questions</h2>
                <p>Answers to popular questions before you visit or order from DGZ Motorshop.</p>
            </div>
            <div class="faq-list">
                <article class="faq-item">
                    <h3 class="faq-question">Do you offer installation services?</h3>
                    <p class="faq-answer">Yes, our Sto. Niño branch has certified mechanics ready to install any parts you purchase from us.</p>
                </article>

                <article class="faq-item">
                    <h3 class="faq-question">What brands of motorcycle parts do you carry?</h3>
                    <p class="faq-answer">We stock a wide range of trusted local and international brands to make sure riders find the perfect parts for their bikes.</p>
                </article>

                <article class="faq-item">
                    <h3 class="faq-question">Can I order parts online for store pickup?</h3>
                    <p class="faq-answer">Absolutely. Place your order through our online catalog and choose in-store pickup so everything is ready when you arrive.</p>
                </article>

                <article class="faq-item">
                    <h3 class="faq-question">What are your store hours?</h3>
                    <p class="faq-answer">We are open Monday to Saturday from 9:00 AM to 6:00 PM, with extended hours during peak riding season.</p>
                </article>
            </div>
        </section>
    </main>

    <!-- Footer -->
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
    <!-- Cart functionality -->
        <script src="../assets/js/public/cart.js"></script>
</body>
</html>
