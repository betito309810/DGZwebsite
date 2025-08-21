<?php
require 'config.php';
$pdo = db();
$products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();

// Group products by category if you have a category field, otherwise we'll use sample categories
$categories = [
    'EXHAUST' => [],
    'FUEL MANAGEMENT' => [],
    'AIR CLEANERS' => [],
    'HANDLEBARS & CONTROLS' => [],
    'WINDSHIELDS & WINDSCREENS' => [],
    'BATTERIES & ELECTRICAL' => [],
    'DRIVE & TRANSMISSION' => [],
    'BRAKES' => [],
    'AUDIO & SPEAKERS' => [],
    'WHEEL & AXLE' => [],
    'BUMPERS & PROTECTION' => [],
    'CAB & INTERIOR' => [],
    'MIRRORS' => [],
    'SUSPENSION' => [],
    'TIRES' => [],
    'OTHER' => []
];

// Distribute products into categories (you can modify this based on your actual category field)
foreach($products as $product) {
    $categories['OTHER'][] = $product;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DGZ Motorshop - Motorcycle Parts & Accessories</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/index.css">
    <style>

    </style>
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <img src="assets/logo.png" alt="Company Logo">
            </div>

            <div class="search-container">
                <input type="text" class="search-bar" placeholder="Search by Category, Part, Brand...">
                <button class="search-btn">
                    <i class="fas fa-search"></i>
                </button>
            </div>

            <a href="#" class="cart-btn" id="cartButton" onclick="handleCartClick(event)">
                <i class="fas fa-shopping-cart"></i>
                <span>Cart</span>
                <div class="cart-count" id="cartCount">0</div>
            </a>
        </div>
    </header>

    <!-- Navigation -->
    <nav class="nav">
        <div class="nav-content">
            <a href="#home" class="nav-link active">HOME</a>
            <a href="#new" class="nav-link">NEW</a>
            <a href="about.php" class="nav-link">ABOUT</a>

        </div>
    </nav>

    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <h3 class="sidebar-title">Shop by Category</h3>
            <ul class="category-list">
                <li class="category-item"><a href="#air-cleaner" class="category-link">Air Cleaner</a></li>
                <li class="category-item"><a href="#batteries" class="category-link">Batteries & Electrical</a></li>
                <li class="category-item"><a href="#brakes" class="category-link">Brakes</a></li>
                <li class="category-item"><a href="#chains" class="category-link">Chains</a></li>
                <li class="category-item"><a href="#clutch" class="category-link">Clutch</a></li>
                <li class="category-item"><a href="#decals" class="category-link">Decals</a></li>
                <li class="category-item"><a href="#engine" class="category-link">Engine Parts</a></li>
                <li class="category-item"><a href="#exhaust" class="category-link">Exhaust</a></li>
                <li class="category-item"><a href="#filters" class="category-link">Filters</a></li>
                <li class="category-item"><a href="#fuel" class="category-link">Fuel System</a></li>
                <li class="category-item"><a href="#lighting" class="category-link">Lighting</a></li>
                <li class="category-item"><a href="#lubricant" class="category-link">Lubricant & Fluids</a></li>
                <li class="category-item"><a href="#mirrors" class="category-link">Mirrors</a></li>
                <li class="category-item"><a href="#suspension" class="category-link">Suspension</a></li>
                <li class="category-item"><a href="#tires" class="category-link">Tires</a></li>
            </ul>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <h1 class="section-title">Trending Products</h1>

            <!-- Featured Products - Top 4 Trending from Database -->
            <div class="featured-products">
                <?php 
        // Get top 4 selling products from database
        $topProducts = $pdo->query('
        SELECT p.*, SUM(oi.qty) as total_sold 
        FROM order_items oi 
        JOIN products p ON p.id = oi.product_id 
        GROUP BY p.id 
        ORDER BY total_sold DESC 
        LIMIT 4
        ')->fetchAll();
    
        // If no top products yet, show some featured products as fallback
         if (empty($topProducts)) {
        $topProducts = array_slice($products, 0, 4);
        }
    
        foreach($topProducts as $p): 
     ?>
                <div class="featured-card">
                    <div class="product-image">
                        <i class="fas fa-cog"></i>
                    </div>
                    <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                    <div class="product-price">₱<?= number_format($p['price'], 2) ?></div>
                    <?php if (isset($p['total_sold']) && $p['total_sold'] > 0): ?>
                    <div class="trending-badge">
                        <i class="fas fa-fire"></i> <?= intval($p['total_sold']) ?> sold
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Category Icons -->
            <div class="category-grid">
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-car-side"></i></div>
                    <div class="category-name">Exhaust</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-gas-pump"></i></div>
                    <div class="category-name">Fuel Management</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-wind"></i></div>
                    <div class="category-name">Air Cleaners</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-grip-horizontal"></i></div>
                    <div class="category-name">Handlebars & Controls</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="category-name">Windshields & Windscreens</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-battery-three-quarters"></i></div>
                    <div class="category-name">Batteries & Electrical</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-cogs"></i></div>
                    <div class="category-name">Drive & Transmission</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-dot-circle"></i></div>
                    <div class="category-name">Brakes</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-volume-up"></i></div>
                    <div class="category-name">Audio & Speakers</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-circle"></i></div>
                    <div class="category-name">Wheel & Axle</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-shield-virus"></i></div>
                    <div class="category-name">Bumpers & Protection</div>
                </div>
                <div class="category-card">
                    <div class="category-icon"><i class="fas fa-car"></i></div>
                    <div class="category-name">Cab & Interior</div>
                </div>
            </div>

            <!-- All Products -->
            <div id="all-products">
                <h2 style="margin: 50px 0 30px 0; font-size: 28px; color: #2d3436; text-align: center;">All Products
                </h2>
                <div class="products-grid">
                    <?php foreach($products as $p): ?>
                    <div class="product-card">
                        <div class="product-header">
                            <div class="product-avatar">
                                <i class="fas fa-motorcycle"></i>
                            </div>
                            <div class="product-info">
                                <h3><?=htmlspecialchars($p['name'])?></h3>
                            </div>
                        </div>

                        <p class="product-description"><?=htmlspecialchars($p['description'])?></p>

                        <div class="product-footer">
                            <div class="price">₱<?=number_format($p['price'],2)?></div>
                            <div class="stock <?= $p['quantity'] <= 5 ? ($p['quantity'] == 0 ? 'out' : 'low') : '' ?>">
                                <?= $p['quantity'] == 0 ? 'Out of Stock' : $p['quantity'] . ' in stock' ?>
                            </div>
                        </div>

                        <!-- Buy Now Form -->
                        <form method="get" action="checkout.php" class="buy-form">
                            <input type="hidden" name="product_id" value="<?= $p['id']?>">
                            <input type="number" name="qty" value="1" min="1" max="<?=max(1,$p['quantity'])?>"
                                class="qty-input" <?= $p['quantity'] == 0 ? 'disabled' : '' ?>>
                            <button type="submit" class="buy-btn" <?= $p['quantity'] == 0 ? 'disabled' : '' ?>>
                                <?= $p['quantity'] == 0 ? 'Out of Stock' : 'Buy Now' ?>
                            </button>
                        </form>

                        <!-- Add to Cart Button -->
                        <button class="add-cart-btn"
                            onclick="addToCart(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', <?= $p['price'] ?>, 1)"
                            <?= $p['quantity'] == 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-cart-plus"></i> Add to Cart
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Footer -->

    </div>
    <script>
        // Cart functionality
        let cartCount = 0;
        let cartItems = [];

        // Save cart to localStorage
        function saveCart() {
            localStorage.setItem('cartItems', JSON.stringify(cartItems));
            localStorage.setItem('cartCount', cartCount.toString());
        }

        // Load cart from localStorage
        function loadCart() {
            const savedCart = localStorage.getItem('cartItems');
            const savedCount = localStorage.getItem('cartCount');

            if (savedCart) {
                try {
                    cartItems = JSON.parse(savedCart);
                } catch (e) {
                    cartItems = [];
                    console.error('Error parsing cart items:', e);
                }
            }
            if (savedCount) {
                cartCount = parseInt(savedCount);
                document.getElementById('cartCount').textContent = cartCount;
            }
        }

        // Handle cart button click
        function handleCartClick(event) {
            event.preventDefault();

            if (cartItems.length === 0) {
                // Show message instead of alert
                showToast('Your cart is empty! Add some items first.');
                return;
            }

            // Redirect to checkout with cart data
            const cartData = encodeURIComponent(JSON.stringify(cartItems));
            window.location.href = 'checkout.php?cart=' + cartData;
        }

        function addToCart(productId, productName, price, quantity = 1) {
            // Check if product already in cart
            const existingItem = cartItems.find(item => item.id === productId);

            if (existingItem) {
                existingItem.quantity += quantity;
            } else {
                cartItems.push({
                    id: productId,
                    name: productName,
                    price: price,
                    quantity: quantity
                });
            }

            cartCount += quantity;
            document.getElementById('cartCount').textContent = cartCount;

            // Save to localStorage
            saveCart();

            // Show confirmation
            showToast(`${productName} added to cart!`);
        }

        function showToast(message) {
            // Remove existing toast if any
            const existingToast = document.querySelector('.toast-message');
            if (existingToast) {
                existingToast.remove();
            }

            // Create toast element
            const toast = document.createElement('div');
            toast.className = 'toast-message';
            toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #2196f3;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease;
        `;
            toast.textContent = message;

            document.body.appendChild(toast);

            // Remove after 3 seconds
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100px); opacity: 0; }
        }
        `;
        document.head.appendChild(style);

        // Load cart on page load
        document.addEventListener('DOMContentLoaded', function () {
            loadCart();
        });

        // Search functionality
        document.querySelector('.search-bar').addEventListener('keyup', function (e) {
            if (e.key === 'Enter') {
                const searchTerm = this.value.toLowerCase();
                filterProducts(searchTerm);
            }
        });

        document.querySelector('.search-btn').addEventListener('click', function () {
            const searchTerm = document.querySelector('.search-bar').value.toLowerCase();
            filterProducts(searchTerm);
        });

        function filterProducts(searchTerm) {
            const products = document.querySelectorAll('.product-card');
            products.forEach(product => {
                const productName = product.querySelector('h3').textContent.toLowerCase();
                const productDescription = product.querySelector('.product-description').textContent
                    .toLowerCase();

                if (productName.includes(searchTerm) || productDescription.includes(searchTerm) ||
                    searchTerm === '') {
                    product.style.display = 'block';
                } else {
                    product.style.display = 'none';
                }
            });
        }

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Update cart count when buy forms are submitted
        document.querySelectorAll('.buy-form').forEach(form => {
            form.addEventListener('submit', function () {
                cartCount++;
                document.getElementById('cartCount').textContent = cartCount;
            });
        });
    </script>

    </div>
    <footer class="footer">
        <div class="footer-content">
            <div class="social-links">
                <a href="#"><i class="fab fa-facebook-f"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-youtube"></i></a>
            </div>
            <p>DGZ Motorshop - Sto. Niño Branch</p>
            <p>© 2022-2025 DGZ Motorshop. All rights reserved.</p>

    </footer>
</body>

</html>