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
            
            <a href="#" class="cart-btn" onclick="showCart()">
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
            <a href="#about" class="nav-link">ABOUT</a>
            
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
            <h1 class="section-title">Trending Motorcycle Parts</h1>
            
            <!-- Featured Products -->
            <div class="featured-products">
                <?php 
                $featured = array_slice($products, 0, 4);
                $sampleNames = ['Akrapovic Slip-On Exhausts', 'Akrapovic Racing Exhausts', 'Rizoma Side Mirror', 'CRG Clutch Lever'];
                foreach($featured as $index => $p): 
                ?>
                <div class="featured-card">
                    <div class="product-image">
                        <i class="fas fa-cog"></i>
                    </div>
                    <div class="product-name"><?= isset($sampleNames[$index]) ? $sampleNames[$index] : htmlspecialchars($p['name']) ?></div>
                    <div class="product-price">₱<?=number_format($p['price'],2)?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div style="text-align: center;">
                <a href="#all-products" class="shop-all-btn">SHOP ALL</a>
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
                <h2 style="margin: 50px 0 30px 0; font-size: 28px; color: #2d3436; text-align: center;">All Products</h2>
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
                        
                        <form method="post" action="claudecheckout.php" class="buy-form">
                            <input type="hidden" name="product_id" value="<?= $p['id']?>">
                            <input type="number" name="qty" value="1" min="1" max="<?=max(1,$p['quantity'])?>" class="qty-input" <?= $p['quantity'] == 0 ? 'disabled' : '' ?>>
                            <button type="submit" class="buy-btn" <?= $p['quantity'] == 0 ? 'disabled' : '' ?>>
                                <?= $p['quantity'] == 0 ? 'Out of Stock' : 'Buy Now' ?>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Simple cart functionality
        let cartCount = 0;

        function showCart() {
            alert('Cart functionality will redirect to checkout page');
            // You can implement actual cart functionality here
            // window.location.href = 'cart.php';
        }

        // Search functionality
        document.querySelector('.search-bar').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                const searchTerm = this.value.toLowerCase();
                filterProducts(searchTerm);
            }
        });

        document.querySelector('.search-btn').addEventListener('click', function() {
            const searchTerm = document.querySelector('.search-bar').value.toLowerCase();
            filterProducts(searchTerm);
        });

        function filterProducts(searchTerm) {
            const products = document.querySelectorAll('.product-card');
            products.forEach(product => {
                const productName = product.querySelector('h3').textContent.toLowerCase();
                const productDescription = product.querySelector('.product-description').textContent.toLowerCase();
                
                if (productName.includes(searchTerm) || productDescription.includes(searchTerm) || searchTerm === '') {
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

        // Update cart count when forms are submitted
        document.querySelectorAll('.buy-form').forEach(form => {
            form.addEventListener('submit', function() {
                cartCount++;
                document.getElementById('cartCount').textContent = cartCount;
            });
        });
    </script>
</body>
</html>