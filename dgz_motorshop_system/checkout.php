<?php
require 'config.php';
$pdo = db();

// Handle both single product and cart scenarios
$product_id = intval($_GET['product_id'] ?? 0);
$qty = max(1, intval($_GET['qty'] ?? 1));
$cartItems = [];

// Check if we have cart data from URL
if (isset($_GET['cart'])) {
    $cartData = urldecode($_GET['cart']);
    $cartItems = json_decode($cartData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $cartItems = [];
    }
}

// Check if we have a single product but no cart
if ($product_id > 0 && empty($cartItems)) {
    $product = $pdo->prepare('SELECT * FROM products WHERE id=?');
    $product->execute([$product_id]);
    $p = $product->fetch();
    
    if ($p) {
        $cartItems = [[
            'id' => $p['id'],
            'name' => $p['name'],
            'price' => $p['price'],
            'quantity' => $qty
        ]];
    }
}

// Check for cart data in POST (for form submissions)
if (isset($_POST['cart'])) {
    $cartItems = json_decode($_POST['cart'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $cartItems = [];
    }
}

// If no cart items, show error
if (empty($cartItems)) {
    echo '<div style="max-width: 400px; margin: 50px auto; background: white; padding: 40px; border-radius: 20px; text-align: center;">';
    echo '<i class="fas fa-shopping-cart" style="font-size: 48px; color: #636e72; margin-bottom: 20px;"></i>';
    echo '<h2 style="color: #2d3436; margin-bottom: 15px;">Your Cart is Empty</h2>';
    echo '<p style="color: #636e72; margin-bottom: 25px;">Add some products to your cart before proceeding to checkout.</p>';
    echo '<a href="index.php" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: 600; margin-right: 10px;">Continue Shopping</a>';
    echo '</div>';
    exit;
}

// Process the order when form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['customer_name'])) {
    $customer_name = $_POST['customer_name'];
    $contact = $_POST['contact'];
    $address = $_POST['address'];
    $payment_method = $_POST['payment_method'];
    $proof_path = null;
    
    if (!empty($_FILES['proof']['tmp_name'])) {
        if (!is_dir('uploads')) mkdir('uploads', 0777, true);
        $fn = 'uploads/' . time() . '_' . basename($_FILES['proof']['name']);
        move_uploaded_file($_FILES['proof']['tmp_name'], $fn);
        $proof_path = $fn;
    }
    
    // Calculate total from cart items
    $total = 0;
    foreach ($cartItems as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    
    // Insert order
    $stmt = $pdo->prepare('INSERT INTO orders (customer_name, contact, address, total, payment_method, payment_proof, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$customer_name, $contact, $address, $total, $payment_method, $proof_path, 'pending']);
    $order_id = $pdo->lastInsertId();
    
    // Insert order items and update stock
    foreach ($cartItems as $item) {
        $product = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $product->execute([$item['id']]);
        $p = $product->fetch();
        
        if ($p && $item['quantity'] <= $p['quantity']) {
            $stmt2 = $pdo->prepare('INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?, ?, ?, ?)');
            $stmt2->execute([$order_id, $item['id'], $item['quantity'], $item['price']]);
            
            // Decrease stock
            $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$item['quantity'], $item['id']]);
        }
    }
    
    // Clear cart using JavaScript
    echo '<script>localStorage.removeItem("cartItems"); localStorage.removeItem("cartCount");</script>';
    
    // Success message
    echo '<div style="max-width: 600px; margin: 50px auto; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); text-align: center;">';
    echo '<i class="fas fa-check-circle" style="font-size: 48px; color: #00b894; margin-bottom: 20px;"></i>';
    echo '<h2 style="color: #2d3436; margin-bottom: 20px;">Order Placed Successfully!</h2>';
    echo '<p style="color: #636e72; margin-bottom: 10px;">Order ID: <strong>' . $order_id . '</strong></p>';
    echo '<p style="color: #636e72; margin-bottom: 30px;">Status: <span style="background: #fdcb6e; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">Pending</span></p>';
    echo '<a href="index.php" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: 600;">Back to Shop</a>';
    echo '</div>';
    exit;
}

// Show checkout form
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - DGZ Motorshop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/checkout.css">
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <img src="assets/logo.png" alt="Company Logo">
            </div>
            <a href="index.php" class="continue-shopping-btn">
            <i class="fas fa-arrow-left"></i> Continue Shopping
        </a>
        </div>
    </header>

    <div class="container">
        <!-- Left Column - Checkout Form -->
        <div class="checkout-form">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="cart" value='<?= htmlspecialchars(json_encode($cartItems)) ?>'>
                
                <!-- Contact Section -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-user"></i>
                        Contact
                    </h2>
                    <div class="form-group">
                        <input type="text" name="contact" placeholder="Mobile No. or Email" required>
                    </div>
                </div>

                <!-- Billing Address Section -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-map-marker-alt"></i>
                        Billing Address
                    </h2>
                    <div class="form-row">
                        <div class="form-group">
                            <label>First name</label>
                            <input type="text" name="customer_name" required>
                        </div>
                        <div class="form-group">
                            <label>Last name</label>
                            <input type="text" name="last_name">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" placeholder="Street address, apartment, suite, etc." required></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Postal code</label>
                            <input type="text" name="postal_code">
                        </div>
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="city">
                        </div>
                    </div>
                </div>

                <!-- Payment Section -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-credit-card"></i>
                        Payment
                    </h2>
                    <div class="payment-methods">
                        <div class="payment-option">
                            <input type="radio" name="payment_method" value="Cash" id="cash" checked>
                            <label for="cash">
                                <i class="fas fa-money-bill-wave"></i>&nbsp; Cash
                            </label>
                        </div>
                        <div class="payment-option">
                            <input type="radio" name="payment_method" value="GCash" id="gcash">
                            <label for="gcash">
                                <i class="fas fa-mobile-alt"></i>&nbsp; GCash
                            </label>
                        </div>
                    </div>
                    
                    <div class="qr-code">
                        <div style="width: 150px; height: 150px; background: #f0f0f0; border-radius: 12px; margin: 0 auto; display: flex; align-items: center; justify-content: center; border: 2px solid #e9ecef;">
                            <i class="fas fa-qrcode" style="font-size: 48px; color: #636e72;"></i>
                        </div>
                    </div>
                    
                    <div class="file-upload">
                        <input type="file" name="proof" id="proof" accept="image/*">
                        <label for="proof">
                            <i class="fas fa-cloud-upload-alt"></i>
                            Upload Proof of Payment
                        </label>
                    </div>
                </div>

                <button type="submit" class="submit-btn">
                    <i class="fas fa-lock"></i>&nbsp; Submit Order
                </button>
            </form>
        </div>

        <!-- Right Column - Order Summary -->
        <div class="order-summary">
            <?php foreach ($cartItems as $item): ?>
            <div class="order-item">
                <div class="item-image">
                    <i class="fas fa-box"></i>
                </div>
                <div class="item-details">
                    <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                    <div class="item-category">Product</div>
                </div>
                <div class="quantity-badge">×<?= $item['quantity'] ?></div>
                <div class="item-price">₱ <?= number_format($item['price'], 2) ?></div>
            </div>
            <?php endforeach; ?>

            <div class="discount-section">
                <div class="discount-input">
                    <input type="text" placeholder="DISCOUNT CODE" style="margin-bottom: 0;">
                    <button type="button" class="apply-btn">Apply</button>
                </div>
            </div>

            <?php
            $subtotal = 0;
            foreach ($cartItems as $item) {
                $subtotal += $item['price'] * $item['quantity'];
            }
            $total = $subtotal;
            ?>

            <div class="summary-row">
                <span>Subtotal, <?= count($cartItems) ?> item<?= count($cartItems) > 1 ? 's' : '' ?></span>
                <span>₱ <?= number_format($subtotal, 2) ?></span>
            </div>

            <div class="summary-row discount-row" style="display: none;">
                <span>Order discount<br><small>DGZ1 - 10% OFF</small></span>
                <span>-₱ 0.00</span>
            </div>

            <div class="summary-row total">
                <span>Total</span>
                <span>₱ <?= number_format($total, 2) ?></span>
            </div>
        </div>
    </div>

    <script>
        // File upload feedback
        document.getElementById('proof').addEventListener('change', function(e) {
            const label = document.querySelector('label[for="proof"]');
            if (e.target.files.length > 0) {
                label.innerHTML = '<i class="fas fa-check"></i> ' + e.target.files[0].name;
                label.style.background = '#00b894';
                label.style.color = 'white';
            }
        });

        // Payment method toggle
        document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const proofSection = document.querySelector('.file-upload').parentElement;
                if (this.value === 'GCash') {
                    proofSection.style.display = 'block';
                } else {
                    proofSection.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>