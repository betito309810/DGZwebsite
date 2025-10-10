<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/product_variants.php'; // Added: variant helpers for checkout validation.
$pdo = db();
$errors = [];
$referenceInput = '';
$supportsTrackingCodes = ordersSupportsTrackingCodes($pdo);
$trackingCodeForRedirect = null;


if (!function_exists('ordersHasReferenceColumn')) {
    function ordersHasReferenceColumn(PDO $pdo) {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'reference_no'");
            $hasColumn = $stmt !== false && $stmt->fetch() !== false;
        } catch (Exception $e) {
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
        } catch (Exception $e) {
            $cache[$column] = false;
        }
        return $cache[$column];
    }
}

if (!function_exists('ordersSupportsTrackingCodes')) {
    function ordersSupportsTrackingCodes(PDO $pdo): bool
    {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'tracking_code'");
            $hasColumn = $stmt !== false && $stmt->fetch() !== false;
        } catch (Exception $e) {
            error_log('Unable to detect orders.tracking_code column: ' . $e->getMessage());
            $hasColumn = false;
        }

        return $hasColumn;
    }
}

if (!function_exists('generateTrackingCodeCandidate')) {
    function generateTrackingCodeCandidate(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $segment = static function () use ($alphabet): string {
            $characters = '';
            for ($i = 0; $i < 4; $i++) {
                $characters .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $characters;
        };

        return 'DGZ-' . $segment() . '-' . $segment();
    }
}

if (!function_exists('generateUniqueTrackingCode')) {
    function generateUniqueTrackingCode(PDO $pdo, int $maxAttempts = 5): string
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = generateTrackingCodeCandidate();
            $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE tracking_code = ? LIMIT 1');
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() === false) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique tracking code.');
    }
}

if (!function_exists('ensureOrdersCustomerNoteColumn')) {
    /**
     * Added helper to make sure the orders table can store cashier notes when the schema allows it.
     */
    function ensureOrdersCustomerNoteColumn(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $ensured = true;

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'customer_note'");
            $hasCustomerNote = $stmt !== false && $stmt->fetch() !== false;
            if ($hasCustomerNote) {
                return;
            }

            $pdo->exec("ALTER TABLE orders ADD COLUMN customer_note TEXT NULL");
        } catch (Exception $e) {
            error_log('Unable to add customer_note column: ' . $e->getMessage());
        }
    }
}

ensureOrdersCustomerNoteColumn($pdo); // Added call to prepare storage for customer cashier notes

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
            $variantId = $item['variantId'] ?? $item['variant_id'] ?? null;
            $variantLabel = isset($item['variantLabel']) ? trim((string) $item['variantLabel']) : '';
            if ($variantLabel === '' && isset($item['variant_label'])) {
                $variantLabel = trim((string) $item['variant_label']);
            }
            $variantPrice = $item['variantPrice'] ?? $item['variant_price'] ?? null;
            if ($variantPrice !== null) {
                $price = (float) $variantPrice;
            }

            if ($id <= 0 || $quantity <= 0) {
                continue;
            }

            $normalised[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : 'Product',
                'price' => $price,
                'quantity' => $quantity,
                'variant_id' => $variantId !== null ? (int) $variantId : null,
                'variant_label' => $variantLabel,
                'variant_price' => $variantPrice !== null ? (float) $variantPrice : $price,
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
        $variants = fetchProductVariants($pdo, (int) $p['id']);
        $defaultVariant = null;
        foreach ($variants as $variantRow) {
            if (!empty($variantRow['is_default'])) {
                $defaultVariant = $variantRow;
                break;
            }
        }
        if ($defaultVariant === null && !empty($variants)) {
            $defaultVariant = $variants[0];
        }
        $unitPrice = $defaultVariant ? (float) $defaultVariant['price'] : (float) $p['price'];
        $unitStock = null;
        if ($defaultVariant) {
            $unitStock = $defaultVariant['quantity'] !== null ? (int) $defaultVariant['quantity'] : null;
        } else {
            $unitStock = isset($p['quantity']) ? (int) $p['quantity'] : null;
        }
        $cartItems = [[
            'id' => $p['id'],
            'name' => $p['name'],
            'price' => $unitPrice,
            'quantity' => $qty,
            'stock' => $unitStock,
            'variant_id' => $defaultVariant['id'] ?? null,
            'variant_label' => $defaultVariant['label'] ?? '',
            'variant_price' => $defaultVariant ? (float) $defaultVariant['price'] : (float) $p['price'],
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
        $variantId = $item['variant_id'] ?? $item['variantId'] ?? null;
        if ($variantId) {
            $stmt = $pdo->prepare('SELECT quantity FROM product_variants WHERE id = ?');
            $stmt->execute([(int) $variantId]);
            $variantRow = $stmt->fetch();
            $item['stock'] = $variantRow ? (int) $variantRow['quantity'] : null;
        } else {
            $stmt = $pdo->prepare('SELECT quantity FROM products WHERE id = ?');
            $stmt->execute([$item['id']]);
            $product = $stmt->fetch();
            $item['stock'] = $product ? (int)$product['quantity'] : null;
        }
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
    $customerNote = trim((string) ($_POST['customer_note'] ?? '')); // Added capture for optional cashier note
    if (mb_strlen($customerNote) > 500) {
        $customerNote = mb_substr($customerNote, 0, 500); // Added guard to keep notes reasonably short
    }
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
                $uploadsRoot = dirname(__DIR__) . '/uploads';
                $uploadDir = $uploadsRoot . '/payment-proofs';
                $publicUploadDir = '../uploads/payment-proofs';

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
        $available = null;
        if (!empty($item['variant_id'])) {
            $variantStmt = $pdo->prepare('SELECT quantity FROM product_variants WHERE id = ?');
            $variantStmt->execute([(int) $item['variant_id']]);
            $variantRow = $variantStmt->fetch();
            $available = $variantRow ? (int) $variantRow['quantity'] : 0;
        } else {
            $stmt = $pdo->prepare('SELECT quantity FROM products WHERE id = ?');
            $stmt->execute([$item['id']]);
            $product = $stmt->fetch();
            $available = $product ? (int)$product['quantity'] : 0;
        }

        if ($available !== null && $item['quantity'] > $available) {
            $label = $item['variant_label'] ?? '';
            $displayName = $label !== '' ? $item['name'] . ' (' . $label . ')' : $item['name'];
            $errors[] = "The quantity for product '{$displayName}' exceeds available stock. Stock: {$available} left.";
        }
    }

    if (empty($errors) && $supportsTrackingCodes) {
        try {
            $trackingCodeForRedirect = generateUniqueTrackingCode($pdo);
        } catch (Throwable $exception) {
            error_log('Failed to generate tracking code: ' . $exception->getMessage());
            $errors[] = 'We ran into a problem while generating your tracking code. Please try again.';
        }
    }

    if (empty($errors)) {

        $hasReferenceColumn = ordersHasReferenceColumn($pdo);

        $columns = ['customer_name', 'address', 'total', 'payment_method', 'payment_proof'];
        $values  = [$customer_name, $address, $total, $payment_method, $proof_path];

        // Write to new columns when present
        try { $hasEmailColumn = ordersHasColumn($pdo, 'email'); } catch (Exception $e) { $hasEmailColumn = false; }
        try { $hasPhoneColumn = ordersHasColumn($pdo, 'phone'); } catch (Exception $e) { $hasPhoneColumn = false; }
        try { $hasLegacyContact = ordersHasColumn($pdo, 'contact'); } catch (Exception $e) { $hasLegacyContact = false; }
        try { $hasCustomerNoteColumn = ordersHasColumn($pdo, 'customer_note'); } catch (Exception $e) { $hasCustomerNoteColumn = false; } // Added detection for dedicated notes column
        try { $hasLegacyNotesColumn = ordersHasColumn($pdo, 'notes'); } catch (Exception $e) { $hasLegacyNotesColumn = false; } // Added fallback for legacy installs using generic notes

        if ($hasEmailColumn) { $columns[] = 'email'; $values[] = $email; }
        if ($hasPhoneColumn) { $columns[] = 'phone'; $values[] = $phone; }
        if ($hasLegacyContact) { $columns[] = 'contact'; $values[] = $email; }

        if ($hasReferenceColumn) { $columns[] = 'reference_no'; $values[] = $referenceNumber; }
        if ($supportsTrackingCodes && $trackingCodeForRedirect !== null) { $columns[] = 'tracking_code'; $values[] = $trackingCodeForRedirect; }
        if ($hasCustomerNoteColumn) { $columns[] = 'customer_note'; $values[] = $customerNote !== '' ? $customerNote : null; } // Added storage for cashier notes when column exists
        elseif ($hasLegacyNotesColumn) { $columns[] = 'notes'; $values[] = $customerNote !== '' ? $customerNote : null; } // Added fallback storage for systems that already expose a generic notes column

        if (ordersHasColumn($pdo, 'order_type')) {
            $columns[] = 'order_type';
            $values[] = 'online';
        }

        $columns[] = 'status';
        $values[]  = 'pending';

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO orders (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);


        $order_id = $pdo->lastInsertId();

        // Insert order items and update stock
        foreach ($cartItems as $item) {
            $variantId = $item['variant_id'] ?? null;
            if ($variantId) {
                $variantStmt = $pdo->prepare('SELECT id, product_id, quantity, label FROM product_variants WHERE id = ?');
                $variantStmt->execute([(int) $variantId]);
                $variantRow = $variantStmt->fetch();

                if ($variantRow && $item['quantity'] <= (int) $variantRow['quantity']) {
                    $stmt2 = $pdo->prepare('INSERT INTO order_items (order_id, product_id, variant_id, variant_label, qty, price) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt2->execute([
                        $order_id,
                        (int) $variantRow['product_id'],
                        (int) $variantRow['id'],
                        $item['variant_label'] ?? ($variantRow['label'] ?? null),
                        $item['quantity'],
                        $item['price'],
                    ]);

                    $pdo->prepare('UPDATE product_variants SET quantity = quantity - ? WHERE id = ?')->execute([$item['quantity'], (int) $variantRow['id']]);
                    $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$item['quantity'], (int) $variantRow['product_id']]);
                }
            } else {
                $product = $pdo->prepare('SELECT * FROM products WHERE id = ?');
                $product->execute([$item['id']]);
                $p = $product->fetch();

                if ($p && $item['quantity'] <= $p['quantity']) {
                    $stmt2 = $pdo->prepare('INSERT INTO order_items (order_id, product_id, variant_id, variant_label, qty, price) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt2->execute([$order_id, $item['id'], null, null, $item['quantity'], $item['price']]);

                    $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$item['quantity'], $item['id']]);
                }
            }
        }

        // Redirect (PRG) to avoid resubmission
        $query = ['success' => '1'];
        if ($trackingCodeForRedirect !== null) {
            $query['tracking_code'] = $trackingCodeForRedirect;
        }

        if ($order_id > 0) {
            $query['order_id'] = (string) $order_id;
        }

        header('Location: checkout.php?' . http_build_query($query));
        exit;
    }
}

// Success page via GET (PRG target)
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $order_id = (int) ($_GET['order_id'] ?? 0);
    $trackingCodeDisplay = '';
    if (isset($_GET['tracking_code'])) {
        $trackingCodeDisplay = strtoupper(preg_replace('/[^A-Z0-9\-]/', '', (string) $_GET['tracking_code']));
    }

    echo '<div style="max-width: 600px; margin: 50px auto; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); text-align: center;">';
    echo '<i class="fas fa-check-circle" style="font-size: 48px; color: #00b894; margin-bottom: 20px;"></i>';
    echo '<h2 style="color: #2d3436; margin-bottom: 20px;">Order Placed Successfully!</h2>';
    if ($trackingCodeDisplay !== '') {
        echo '<p style="color: #636e72; margin-bottom: 10px;">Tracking Code: <strong>' . htmlspecialchars($trackingCodeDisplay, ENT_QUOTES, 'UTF-8') . '</strong></p>';
        echo '<p style="color: #636e72; margin-bottom: 10px;">Keep this code handy to check your order status on the Track Order page.</p>';
    } elseif ($order_id > 0) {
        echo '<p style="color: #636e72; margin-bottom: 10px;">Order ID: <strong>' . $order_id . '</strong></p>';
    }
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
    <link rel="stylesheet" href="../assets/css/public/checkout.css">
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <img src="../assets/logo.png" alt="Company Logo">
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
                        <label>Email <span class="required-indicator">*</span></label>
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
                        <label>Address <span class="required-indicator">*</span></label>
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
                    <div class="form-group">
                        <!-- Added note textarea so customers can leave instructions for the cashier -->
                        <label for="customer_note">Notes for the cashier</label>
                        <textarea name="customer_note" id="customer_note" maxlength="500" placeholder="Add delivery instructions, preferred pickup time, etc."><?= htmlspecialchars($_POST['customer_note'] ?? '') ?></textarea>
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
                        <img src="../assets/QR.png" alt="">
                    </div>

                    <div class="form-group">
                        <label for="reference_number">Reference Number <span class="required-indicator">*</span></label>
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
            <!-- Clear cart button keeps summary synchronized with local storage -->
            <button type="button" id="clearCartButton" class="clear-cart-btn" style="<?= empty($cartItems) ? 'display:none;' : '' ?>">Clear Cart</button>
            <div id="orderItemsContainer" class="order-items">
                <?php foreach ($cartItems as $index => $item): ?>
                <div class="order-item" data-index="<?= $index ?>">
                    <div class="item-image">
                        <i class="fas fa-box"></i>
                    </div>
                        <div class="item-details">
                            <div class="item-header">
                                <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                                <span class="item-price">₱ <?= number_format($item['price'], 2) ?></span>
                            </div>
                        <div class="item-category">
                            <?= htmlspecialchars($item['variant_label'] !== '' ? 'Variant: ' . $item['variant_label'] : 'Product') ?>
                        </div>
                        </div>
                    <div class="item-meta">
                        <!-- Quantity input remains editable so buyers can adjust before checkout -->
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
        const clearCartButton = document.getElementById('clearCartButton');
        const checkoutForm = document.querySelector('.checkout-form form');

        let cartState = [];
        try {
            cartState = JSON.parse(cartInput.value || '[]') || [];
        } catch (error) {
            cartState = [];
        }

        // Guard submission so blank required fields cannot slip through trimming
        checkoutForm?.addEventListener('submit', (event) => {
            const emailField = checkoutForm.querySelector('input[name="email"]');
            const addressField = checkoutForm.querySelector('textarea[name="address"]');
            const referenceField = checkoutForm.querySelector('#reference_number');
            const requiredFields = [emailField, addressField, referenceField];

            let invalidField = null;
            requiredFields.forEach((field) => {
                if (!field) {
                    return;
                }
                field.value = field.value.trim();
                field.setCustomValidity('');
                if (!invalidField && field.value === '') {
                    invalidField = field;
                }
            });

            if (invalidField) {
                event.preventDefault();
                invalidField.reportValidity();
            }
        });

        function normaliseItem(rawItem = {}) {
            const basePrice = rawItem.price ?? rawItem.variant_price;
            const price = Number(basePrice);
            const quantity = Number(rawItem.quantity);
            const variantId = rawItem.variantId ?? rawItem.variant_id ?? null;
            const variantLabelRaw = rawItem.variantLabel ?? rawItem.variant_label ?? '';
            const stockValue = rawItem.stock ?? null;

            return {
                id: rawItem.id ?? null,
                name: rawItem.name ?? 'Product',
                price: Number.isFinite(price) ? price : 0,
                quantity: Number.isFinite(quantity) && quantity > 0 ? quantity : 1,
                variantId: variantId !== null ? Number(variantId) : null,
                variantLabel: variantLabelRaw ? String(variantLabelRaw) : '',
                variantPrice: Number.isFinite(price) ? price : 0,
                stock: stockValue !== undefined ? stockValue : null,
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
            if (clearCartButton) {
                clearCartButton.style.display = hasItems ? 'block' : 'none';
                clearCartButton.disabled = !hasItems;
            }
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

            const header = document.createElement('div');
            header.className = 'item-header';

            const name = document.createElement('span');
            name.className = 'item-name';
            name.textContent = item && item.name ? String(item.name) : 'Product';
            header.appendChild(name);

            const price = document.createElement('span');
            price.className = 'item-price';
            price.textContent = formatPeso(item.price);
            header.appendChild(price);

            details.appendChild(header);

            const category = document.createElement('div');
            category.className = 'item-category';
            category.textContent = item.variantLabel ? `Variant: ${item.variantLabel}` : 'Product';
            details.appendChild(category);

            row.appendChild(details);

            const meta = document.createElement('div');
            meta.className = 'item-meta';

            // Quantity input remains editable so buyers can adjust before checkout
            const qty = document.createElement('input');
            qty.type = 'number';
            qty.className = 'quantity-input';
            qty.min = 1;
            qty.value = item.quantity;
            qty.dataset.index = String(index);
            qty.style.width = '65px';
            qty.style.marginBottom = '4px';
            meta.appendChild(qty);

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

        // Clearing the cart wipes local storage and refreshes the summary instantly
        clearCartButton?.addEventListener('click', () => {
            cartState = [];
            renderOrderItems();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        renderOrderItems();
    </script>
</body>
</html>
