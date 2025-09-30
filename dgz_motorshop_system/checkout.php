<?php
require __DIR__ . '/config/config.php';
$pdo = db();
$errors = [];
$referenceInput = '';


if (!function_exists('ordersHasReferenceColumn')) {
    function ordersHasReferenceColumn(PDO $pdo) {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'reference_no'");
            $hasColumn = $stmt !== false && $stmt->fetch() !== false;
        } catch (Throwable $e) {
            $hasColumn = false;
        }

        return $hasColumn;
    }
}


if (!function_exists('ordersHasColumn')) {
    function ordersHasColumn(PDO $pdo, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM orders LIKE ?");
            $stmt->execute([$column]);
            $cache[$column] = $stmt !== false && $stmt->fetch() !== false;
        } catch (Throwable $e) {
            $cache[$column] = false;
        }
        return $cache[$column];
    }
}

if (!function_exists('normaliseCartItems')) {
    function normaliseCartItems($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalised = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = isset($item['id']) ? (int) $item['id'] : 0;
            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            $price = isset($item['price']) ? (float) $item['price'] : 0.0;
            $name = isset($item['name']) ? trim((string) $item['name']) : '';

            if ($id <= 0 || $quantity <= 0) {
                continue;
            }

            $normalised[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : 'Product',
                'price' => $price,
                'quantity' => $quantity,
            ];
        }

        return $normalised;
    }
}


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

    $cartItems = normaliseCartItems($cartItems);
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
            'quantity' => $qty,
            'stock' => $p['quantity'] ?? null,
        ]];
    }
}

// Check for cart data in POST (for form submissions)
if (isset($_POST['cart'])) {
    $cartItems = json_decode($_POST['cart'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $cartItems = [];
    }

    // Fetch stock quantity for each cart item from database and add to cartItems
    foreach ($cartItems as &$item) {
        $stmt = $pdo->prepare('SELECT quantity FROM products WHERE id = ?');
        $stmt->execute([$item['id']]);
        $product = $stmt->fetch();
        $item['stock'] = $product ? (int)$product['quantity'] : null;
    }
    unset($item);

    $cartItems = normaliseCartItems($cartItems);
}

$cartItems = normaliseCartItems($cartItems);

// If no cart items, show error (unless success page)
if (empty($cartItems) && !(isset($_GET['success']) && $_GET['success'] === '1')) {
    echo '<div style="max-width: 400px; margin: 50px auto; background: white; padding: 40px; border-radius: 20px; text-align: center;">';
    echo '<i class="fas fa-shopping-cart" style="font-size: 48px; color: #636e72; margin-bottom: 20px;"></i>';
    echo '<h2 style="color: #2d3436; margin-bottom: 15px;">Your Cart is Empty</h2>';
    echo '<p style="color: #636e72; margin-bottom: 25px;">Add some products to your cart before proceeding to checkout.</p>';
    echo '<a href="index.php" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: 600; margin-right: 10px;">Continue Shopping</a>';
    echo '</div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['customer_name'])) {
    $customer_name = trim($_POST['customer_name']);
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address']);
    $payment_method = $_POST['payment_method'] ?? '';
    $referenceInput = trim($_POST['reference_number'] ?? '');
    $proof_path = null;

    // Validate required email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }

    $referenceNumber = preg_replace('/[^A-Za-z0-9\- ]/', '', $referenceInput);
    $referenceNumber = strtoupper(substr($referenceNumber, 0, 50));
    if ($referenceNumber === '') {
        $errors[] = 'Reference number is required.';
    }

    if ($payment_method === '') {
        $errors[] = 'Please select a payment method.';
    }

    if (!empty($_FILES['proof']['tmp_name'])) {
        if ($_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Unable to upload the proof of payment. Please try again.';
        } else {
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $_FILES['proof']['tmp_name']) : null;
            if ($finfo) {
                finfo_close($finfo);
            }

            if (!$mime || !isset($allowed[$mime])) {
                $errors[] = 'Please upload a valid image (JPG, PNG, GIF, or WEBP).';
            } else {
                $uploadsRoot = __DIR__ . '/uploads';
                $uploadDir = $uploadsRoot . '/payment-proofs';
                $publicUploadDir = 'uploads/payment-proofs';

                $setupOk = true;
                if (!is_dir($uploadsRoot) && !mkdir($uploadsRoot, 0777, true) && !is_dir($uploadsRoot)) {
                    $errors[] = 'Failed to prepare the uploads storage.';
                    $setupOk = false;
                }

                if ($setupOk && !is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                    $errors[] = 'Failed to prepare the uploads storage.';
                    $setupOk = false;
                }

                if ($setupOk && !is_writable($uploadDir) && !chmod($uploadDir, 0777)) {
                    $errors[] = 'Uploads folder is not writable.';
                    $setupOk = false;
                }

                if ($setupOk) {
                    try {
                        $random = bin2hex(random_bytes(8));
                    } catch (Exception $e) {
                        $random = (string) time();
                    }

                    $storedFileName = sprintf('%s.%s', $random, $allowed[$mime]);
                    $targetPath = $uploadDir . '/' . $storedFileName;

                    $moved = move_uploaded_file($_FILES['proof']['tmp_name'], $targetPath);
                    if (!$moved) {
                        $fileContents = @file_get_contents($_FILES['proof']['tmp_name']);
                        if ($fileContents === false || @file_put_contents($targetPath, $fileContents) === false) {
                            $errors[] = 'Failed to save the uploaded proof of payment.';
                        } else {
                            $proof_path = $publicUploadDir . '/' . $storedFileName;
                        }
                    } else {
                        $proof_path = $publicUploadDir . '/' . $storedFileName;
                    }
                }
            }
        }
    }

    // Calculate total from cart items
    $total = 0;
    foreach ($cartItems as $item) {
        $total += $item['price'] * $item['quantity'];
    }

    // Validate stock quantity for each cart item
    foreach ($cartItems as $item) {
        $stmt = $pdo->prepare('SELECT quantity FROM products WHERE id = ?');
        $stmt->execute([$item['id']]);
        $product = $stmt->fetch();

        if (!$product || $item['quantity'] > $product['quantity']) {
            $available = $product ? (int)$product['quantity'] : 0;
            $errors[] = "The quantity for product '{$item['name']}' exceeds available stock. Stock: {$available} left.";
            $errors[] = "Stock: {$available} left.";
        }
    }

    if (empty($errors)) {

        $hasReferenceColumn = ordersHasReferenceColumn($pdo);

        $columns = ['customer_name', 'address', 'total', 'payment_method', 'payment_proof'];
        $values  = [$customer_name, $address, $total, $payment_method, $proof_path];

        // Write to new columns when present
        try { $hasEmailColumn = ordersHasColumn($pdo, 'email'); } catch (Throwable $e) { $hasEmailColumn = false; }
        try { $hasPhoneColumn = ordersHasColumn($pdo, 'phone'); } catch (Throwable $e) { $hasPhoneColumn = false; }
        try { $hasLegacyContact = ordersHasColumn($pdo, 'contact'); } catch (Throwable $e) { $hasLegacyContact = false; }

        if ($hasEmailColumn) { $columns[] = 'email'; $values[] = $email; }
        if ($hasPhoneColumn) { $columns[] = 'phone'; $values[] = $phone; }
        if ($hasLegacyContact) { $columns[] = 'contact'; $values[] = $email; }

        if ($hasReferenceColumn) { $columns[] = 'reference_no'; $values[] = $referenceNumber; }

        $columns[] = 'status';
        $values[]  = 'pending';

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO orders (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);


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

        // Redirect (PRG) to avoid resubmission
        header('Location: checkout.php?success=1&order_id=' . urlencode((string) $order_id));
        exit;
    }
}

// Success page via GET (PRG target)
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $order_id = (int) ($_GET['order_id'] ?? 0);
    echo '<div style="max-width: 600px; margin: 50px auto; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); text-align: center;">';
    echo '<i class="fas fa-check-circle" style="font-size: 48px; color: #00b894; margin-bottom: 20px;"></i>';
    echo '<h2 style="color: #2d3436; margin-bottom: 20px;">Order Placed Successfully!</h2>';
    echo '<p style="color: #636e72; margin-bottom: 10px;">Order ID: <strong>' . $order_id . '</strong></p>';
    echo '<p style="color: #636e72; margin-bottom: 30px;">Status: <span style="background: #fdcb6e; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">Pending</span></p>';
    echo '<a href="index.php" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: 600;">Back to Shop</a>';
    echo '<script>try{localStorage.removeItem("cartItems");localStorage.removeItem("cartCount");}catch(e){}</script>';
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
    <link rel="stylesheet" href="assets/css/checkout.css">
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
            <?php if (!empty($errors)): ?>
            <div class="form-alert">
                <strong>We couldn't process your order:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="cart" value='<?= htmlspecialchars(json_encode($cartItems)) ?>'>
                
                <!-- Contact Section -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-user"></i>
                        Contact
                    </h2>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Mobile number (optional)</label>
                        <input type="tel" name="phone" placeholder="Mobile No." value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
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
                            <input type="text" name="customer_name" value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Last name</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" placeholder="Street address, apartment, suite, etc." required><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Postal code</label>
                            <input type="text" name="postal_code" value="<?= htmlspecialchars($_POST['postal_code'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="city" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
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
                            <input type="radio" name="payment_method" value="GCash" id="gcash" <?= (($_POST['payment_method'] ?? 'GCash') === 'GCash') ? 'checked' : '' ?>>
                            <label for="gcash">
                                <i class="fas fa-mobile-alt"></i>&nbsp; GCash
                            </label>
                        </div>
                    </div>
                    
                    <div class="qr-code">
                        <img src="assets/QR.png" alt="qrcode">
                    </div>

                    <div class="form-group">
                        <label for="reference_number">Reference Number</label>
                        <input type="text" name="reference_number" id="reference_number" maxlength="50" value="<?= htmlspecialchars($referenceInput) ?>" placeholder="e.g. GCASH123456" required>
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
            <div id="orderItemsContainer" class="order-items">
                <?php foreach ($cartItems as $index => $item): ?>
                <div class="order-item" data-index="<?= $index ?>">
                    <div class="item-image">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="item-details">
                        <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                        <div class="item-category">Product</div>
                    </div>
                    <div class="item-meta">
                        <!-- Changed quantity badge to input field for quantity update -->
                        <div class="item-price">₱ <?= number_format($item['price'], 2) ?></div>
                        <input type="number" class="quantity-input" min="1" value="<?= $item['quantity'] ?>" data-index="<?= $index ?>" style="width: 50px; margin-right: 10px;">
                        <button type="button" class="item-remove" data-index="<?= $index ?>">Remove</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="orderEmptyState" class="order-empty" style="<?= empty($cartItems) ? '' : 'display:none;' ?>">
                <i class="fas fa-shopping-basket"></i>
                <p>Your cart is empty.</p>
                <a href="index.php" class="order-empty-link">Continue shopping</a>
            </div>

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
                <span id="summarySubtotalLabel">Subtotal, <?= count($cartItems) ?> item<?= count($cartItems) > 1 ? 's' : '' ?></span>
                <span id="summarySubtotalValue">₱ <?= number_format($subtotal, 2) ?></span>
            </div>

            <div class="summary-row discount-row" style="display: none;">
                <span>Order discount<br><small>DGZ1 - 10% OFF</small></span>
                <span>-₱ 0.00</span>
            </div>

            <div class="summary-row total">
                <span>Total</span>
                <span id="summaryTotalValue">₱ <?= number_format($total, 2) ?></span>
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

        const cartInput = document.querySelector('input[name="cart"]');
        const orderItemsContainer = document.getElementById('orderItemsContainer');
        const emptyState = document.getElementById('orderEmptyState');
        const subtotalLabel = document.getElementById('summarySubtotalLabel');
        const subtotalValue = document.getElementById('summarySubtotalValue');
        const totalValue = document.getElementById('summaryTotalValue');
        const submitButton = document.querySelector('.submit-btn');

        let cartState = [];
        try {
            cartState = JSON.parse(cartInput.value || '[]') || [];
        } catch (error) {
            cartState = [];
        }

        function normaliseItem(rawItem = {}) {
            const price = Number(rawItem.price);
            const quantity = Number(rawItem.quantity);

            return {
                id: rawItem.id ?? null,
                name: rawItem.name ?? 'Product',
                price: Number.isFinite(price) ? price : 0,
                quantity: Number.isFinite(quantity) && quantity > 0 ? quantity : 1,
            };
        }

        function formatPeso(value) {
            const amount = Number(value) || 0;
            return '₱ ' + amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function syncCartInput() {
            try {
                cartInput.value = JSON.stringify(cartState);
            } catch (error) {
                console.error('Unable to synchronise cart state.', error);
            }
        }

        function syncBrowserStorage() {
            try {
                localStorage.setItem('cartItems', JSON.stringify(cartState));
                const totalItems = cartState.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
                localStorage.setItem('cartCount', String(totalItems));
            } catch (error) {
                console.error('Unable to persist cart to localStorage.', error);
            }
        }

        function updateSummary() {
            const totals = cartState.reduce((acc, item) => {
                const { price, quantity } = normaliseItem(item);
                acc.items += quantity;
                acc.subtotal += price * quantity;
                return acc;
            }, { items: 0, subtotal: 0 });

            subtotalLabel.textContent = `Subtotal, ${totals.items} item${totals.items === 1 ? '' : 's'}`;
            subtotalValue.textContent = formatPeso(totals.subtotal);
            totalValue.textContent = formatPeso(totals.subtotal);

            const hasItems = cartState.length > 0;
            submitButton.disabled = !hasItems;
            emptyState.style.display = hasItems ? 'none' : 'block';
        }

        function createOrderItemRow(item, index) {
            const row = document.createElement('div');
            row.className = 'order-item';
            row.dataset.index = String(index);

            const image = document.createElement('div');
            image.className = 'item-image';
            image.innerHTML = '<i class="fas fa-box"></i>';
            row.appendChild(image);

            const details = document.createElement('div');
            details.className = 'item-details';

            const name = document.createElement('div');
            name.className = 'item-name';
            name.textContent = item && item.name ? String(item.name) : 'Product';
            details.appendChild(name);

            const category = document.createElement('div');
            category.className = 'item-category';
            category.textContent = 'Product';
            details.appendChild(category);

            row.appendChild(details);

            const meta = document.createElement('div');
            meta.className = 'item-meta';

            // Replace quantity badge with input field for quantity update
            const qty = document.createElement('input');
            qty.type = 'number';
            qty.className = 'quantity-input';
            qty.min = 1;
            qty.value = item.quantity;
            qty.dataset.index = String(index);
            qty.style.width = '65px';
            qty.style.marginBottom = '4px';
            meta.appendChild(qty);

            const price = document.createElement('div');
            price.className = 'item-price';
            price.textContent = formatPeso(item.price);
            meta.appendChild(price);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'item-remove';
            remove.dataset.index = String(index);
            remove.textContent = 'Remove';
            meta.appendChild(remove);

            row.appendChild(meta);

            return row;
        }

        function renderOrderItems() {
            if (!orderItemsContainer) {
                return;
            }

            orderItemsContainer.innerHTML = '';
            cartState = cartState.map((item) => normaliseItem(item));

            cartState.forEach((item, index) => {
                orderItemsContainer.appendChild(createOrderItemRow(item, index));
            });

            updateSummary();
            syncCartInput();
            syncBrowserStorage();
        }

        orderItemsContainer?.addEventListener('click', (event) => {
            const target = event.target.closest('.item-remove');
            if (!target) {
                return;
            }

            const index = Number(target.dataset.index);
            if (!Number.isInteger(index) || index < 0 || index >= cartState.length) {
                return;
            }

            cartState.splice(index, 1);
            renderOrderItems();

            if (cartState.length === 0) {
                setTimeout(() => {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }, 150);
            }
        });

        // Add event listener for quantity input changes
        orderItemsContainer?.addEventListener('input', (event) => {
            const target = event.target;
            if (!target.classList.contains('quantity-input')) {
                return;
            }

            const index = Number(target.dataset.index);
            if (!Number.isInteger(index) || index < 0 || index >= cartState.length) {
                return;
            }

            let newQuantity = parseInt(target.value);
            if (isNaN(newQuantity) || newQuantity < 1) {
                newQuantity = 1;
                target.value = newQuantity;
            }

            // Get the product stock quantity from cartState or fetch from server if needed
            const productId = cartState[index].id;
            // For simplicity, assume stock quantity is available in cartState as stock
            const stockQuantity = cartState[index].stock ?? null;

            if (stockQuantity !== null && newQuantity > stockQuantity) {
                alert(`Only ${stockQuantity} pcs available.`);
                newQuantity = stockQuantity;
                target.value = newQuantity;
            }

            cartState[index].quantity = newQuantity;
            updateSummary();
            syncCartInput();
            syncBrowserStorage();
        });

        renderOrderItems();
    </script>
</body>
</html>
