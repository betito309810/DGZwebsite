<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require_once __DIR__ . '/dgz_motorshop_system/includes/product_variants.php'; // Added: variant helpers for checkout validation.
require_once __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';
require_once __DIR__ . '/dgz_motorshop_system/includes/email.php';
$pdo = db();
$errors = [];
$referenceInput = '';
$supportsTrackingCodes = false;
$trackingCodeForRedirect = null;
$checkoutStylesheet = assetUrl('assets/css/public/checkout.css');
$checkoutModalStylesheet = assetUrl('assets/css/public/checkoutModals.css');
$logoAsset = assetUrl('assets/logo.png');
$productPlaceholder = assetUrl('assets/img/product-placeholder.svg');
$qrAsset = assetUrl('assets/QR.png');
$mayaQrAsset = assetUrl('assets/QR-maya.png'); // Maya QR asset (add the image at this path to enable the toggle)
$selectedPaymentMethod = $_POST['payment_method'] ?? 'GCash';
if (!in_array($selectedPaymentMethod, ['GCash', 'Maya'], true)) {
    $selectedPaymentMethod = 'GCash';
}
$currentQrAsset = $selectedPaymentMethod === 'Maya' ? $mayaQrAsset : $qrAsset;
$currentQrAlt = $selectedPaymentMethod === 'Maya' ? 'Maya payment QR code' : 'GCash payment QR code';
$shopUrl = orderingUrl('index.php');
$inventoryAvailabilityApi = orderingUrl('api/inventory-availability.php');

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$customerSessionState = customerSessionExport();
$isCustomerAuthenticated = !empty($customerSessionState['authenticated']);
$customerFirstName = $customerSessionState['firstName'] ?? null;
$customerAccount = $isCustomerAuthenticated ? getAuthenticatedCustomer() : null;
$bodyCustomerState = $isCustomerAuthenticated ? 'authenticated' : 'guest';
$bodyCustomerFirstName = $customerFirstName ?? '';
$loginUrl = orderingUrl('login.php');
$registerUrl = orderingUrl('register.php');
$myOrdersUrl = orderingUrl('my_orders.php');
$logoutUrl = orderingUrl('logout.php');

$storedFullName = trim((string) ($customerAccount['full_name'] ?? ''));
$storedEmail = trim((string) ($customerAccount['email'] ?? ''));
$storedPhone = trim((string) ($customerAccount['phone'] ?? ''));
$storedAddress = trim((string) ($customerAccount['address_line1'] ?? ''));
$storedCity = trim((string) ($customerAccount['city'] ?? ''));
$storedPostal = trim((string) ($customerAccount['postal_code'] ?? ''));
$customerHasSavedAddress = $storedAddress !== '' && $storedCity !== '' && $storedPostal !== '';

$defaultAddressMode = ($customerHasSavedAddress && !isset($_GET['edit_address'])) ? 'summary' : 'edit';
$addressMode = isset($_POST['address_mode']) ? (string) $_POST['address_mode'] : $defaultAddressMode;
if ($addressMode !== 'summary' && $addressMode !== 'edit') {
    $addressMode = $defaultAddressMode;
}
$showAddressSummary = $customerHasSavedAddress && $addressMode === 'summary';

$formValues = [
    'email' => trim((string) ($_POST['email'] ?? '')),
    'phone' => trim((string) ($_POST['phone'] ?? '')),
    'facebook_account' => trim((string) ($_POST['facebook_account'] ?? '')),
    'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
    'address' => trim((string) ($_POST['address'] ?? '')),
    'postal_code' => trim((string) ($_POST['postal_code'] ?? '')),
    'city' => trim((string) ($_POST['city'] ?? '')),
    'customer_note' => trim((string) ($_POST['customer_note'] ?? '')),
];

if ($customerAccount) {
    if ($formValues['email'] === '' && $storedEmail !== '') {
        $formValues['email'] = $storedEmail;
    }
    if ($formValues['phone'] === '' && $storedPhone !== '') {
        $formValues['phone'] = $storedPhone;
    }
    if ($formValues['customer_name'] === '' && $storedFullName !== '') {
        $formValues['customer_name'] = $storedFullName;
    }

    if ($addressMode === 'summary' && $customerHasSavedAddress) {
        $formValues['customer_name'] = $storedFullName;
        $formValues['address'] = $storedAddress;
        $formValues['postal_code'] = $storedPostal;
        $formValues['city'] = $storedCity;
    } else {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $customerHasSavedAddress) {
            if ($formValues['address'] === '') {
                $formValues['address'] = $storedAddress;
            }
            if ($formValues['postal_code'] === '') {
                $formValues['postal_code'] = $storedPostal;
            }
            if ($formValues['city'] === '') {
                $formValues['city'] = $storedCity;
            }
        }
    }
}


if (!function_exists('ensureOrdersTrackingCodeColumn')) {
    /**
     * Added helper to opportunistically provision the tracking_code column when it is missing.
     */
    function ensureOrdersTrackingCodeColumn(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $ensured = true;

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'tracking_code'");
            $hasColumn = $stmt !== false && $stmt->fetch() !== false;
            if ($hasColumn) {
                return;
            }

            $pdo->exec("ALTER TABLE orders ADD COLUMN tracking_code VARCHAR(20) NULL");

            try {
                $pdo->exec("ALTER TABLE orders ADD UNIQUE KEY idx_tracking_code (tracking_code)");
            } catch (Exception $e) {
                // Ignore duplicate key errors and log everything else for diagnostics.
                if (stripos($e->getMessage(), 'duplicate') === false) {
                    error_log('Unable to add unique index for tracking_code: ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('Unable to ensure tracking_code column exists: ' . $e->getMessage());
        }
    }
}


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

if (!function_exists('resolveTrackOrderUrl')) {
    function resolveTrackOrderUrl(): string
    {
        return absoluteUrl(orderingUrl('track-order.php'));
    }
}

ensureOrdersTrackingCodeColumn($pdo);
$supportsTrackingCodes = ordersSupportsTrackingCodes($pdo);

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
            $image = isset($item['image']) ? trim((string) $item['image']) : null;
            $stock = isset($item['stock']) ? (int) $item['stock'] : null;
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
                'image' => $image !== null && $image !== '' ? $image : null,
                'stock' => $stock !== null ? $stock : null,
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

    // Enrich with stock and image from DB so UI can enforce limits and show thumbnails
    foreach ($cartItems as &$item) {
        try {
            $productStmt = $pdo->prepare('SELECT id, image, quantity FROM products WHERE id = ?');
            $productStmt->execute([(int) $item['id']]);
            $productRow = $productStmt->fetch();

            // Resolve product image
            if ($productRow && !empty($productRow['image'])) {
                $item['image'] = publicAsset($productRow['image']);
            }

            // Resolve stock depending on variant
            $variantId = $item['variant_id'] ?? null;
            if ($variantId) {
                $vStmt = $pdo->prepare('SELECT quantity FROM product_variants WHERE id = ?');
                $vStmt->execute([(int) $variantId]);
                $vRow = $vStmt->fetch();
                $item['stock'] = $vRow ? (int) $vRow['quantity'] : ($item['stock'] ?? null);
            } else {
                if ($productRow) {
                    $item['stock'] = isset($productRow['quantity']) ? (int) $productRow['quantity'] : ($item['stock'] ?? null);
                }
            }
        } catch (Throwable $e) {
            // Best-effort enrichment; ignore failures
        }
    }
    unset($item);
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
        $imageUrl = '';
        if (!empty($p['image'])) {
            $imageUrl = publicAsset($p['image']);
        }
        $cartItems = [[
            'id' => $p['id'],
            'name' => $p['name'],
            'price' => $unitPrice,
            'quantity' => $qty,
            'stock' => $unitStock,
            'image' => $imageUrl !== '' ? $imageUrl : null,
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

    // Fetch stock quantity and image for each cart item from database and add to cartItems
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

        // Attach product image if available
        try {
            $imgStmt = $pdo->prepare('SELECT image FROM products WHERE id = ?');
            $imgStmt->execute([(int) $item['id']]);
            $imgRow = $imgStmt->fetch();
            if ($imgRow && !empty($imgRow['image'])) {
                $item['image'] = publicAsset($imgRow['image']);
            }
        } catch (Throwable $e) {
            // ignore
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
    echo '<a href="' . htmlspecialchars($shopUrl, ENT_QUOTES, 'UTF-8') . '" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: 600; margin-right: 10px;">Continue Shopping</a>';
    echo '</div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isCustomerAuthenticated || !$customerAccount) {
        $errors[] = 'Please log in or create an account before checking out.';
    }
    // Treat customer_name as full name
    $customer_name = $formValues['customer_name'];
    $email = $formValues['email'];
    $phone = $formValues['phone'];
    $facebookAccount = $formValues['facebook_account'];
    $address = $formValues['address'];
    $postalCode = $formValues['postal_code'];
    $city = $formValues['city'];
    $customerNote = $formValues['customer_note']; // Added capture for optional cashier note
    if (mb_strlen($customerNote) > 500) {
        $customerNote = mb_substr($customerNote, 0, 500); // Added guard to keep notes reasonably short
    }
    if ($customerAccount) {
        if ($addressMode === 'summary' && $customerHasSavedAddress) {
            $customer_name = $storedFullName !== '' ? $storedFullName : $customer_name;
            $address = $storedAddress !== '' ? $storedAddress : $address;
            $postalCode = $storedPostal !== '' ? $storedPostal : $postalCode;
            $city = $storedCity !== '' ? $storedCity : $city;
        }
        if ($storedFullName !== '' && $addressMode !== 'edit') {
            $customer_name = $storedFullName;
        }
        if ($storedEmail !== '') {
            $email = $storedEmail;
        }
        if ($storedPhone !== '') {
            $phone = $storedPhone;
        }
    }

    $formValues['customer_name'] = $customer_name;
    $formValues['email'] = $email;
    $formValues['phone'] = $phone;
    $formValues['address'] = $address;
    $formValues['postal_code'] = $postalCode;
    $formValues['city'] = $city;
    $formValues['customer_note'] = $customerNote;
    $formValues['facebook_account'] = $facebookAccount;

    $payment_method = $_POST['payment_method'] ?? '';
    $referenceInput = trim($_POST['reference_number'] ?? '');
    $proof_path = null;

    // Validate required email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }

    // Basic required checks for phone and facebook account
    if ($phone === '') {
        $errors[] = 'Mobile number is required.';
    } elseif (mb_strlen(preg_replace('/\D+/', '', $phone)) > 12) {
        $errors[] = 'Mobile number must be at most 12 digits.';
    }

    if ($facebookAccount === '') {
        $errors[] = 'Facebook account is required.';
    }

    $referenceNumber = preg_replace('/[^A-Za-z0-9\- ]/', '', $referenceInput);
    $referenceNumber = strtoupper(substr($referenceNumber, 0, 50));
    if ($referenceNumber === '') {
        $errors[] = 'Reference number is required.';
    }

    if ($payment_method === '') {
        $errors[] = 'Please select a payment method.';
    }

    // Basic server-side validation for required address parts
    if ($address === '') {
        $errors[] = 'Address is required.';
    }
    if ($postalCode === '') {
        $errors[] = 'Postal code is required.';
    }
    if ($city === '') {
        $errors[] = 'City is required.';
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
                $uploadsRoot = __DIR__ . '/dgz_motorshop_system/uploads';
                $uploadDir = $uploadsRoot . '/payment-proofs';
                $publicUploadDir = 'dgz_motorshop_system/uploads/payment-proofs';

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
    } else {
        // Require proof of payment image upload
        $errors[] = 'Proof of payment image is required.';
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
        try { $hasFacebookColumn = ordersHasColumn($pdo, 'facebook_account'); } catch (Exception $e) { $hasFacebookColumn = false; }
        try { $hasCustomerNoteColumn = ordersHasColumn($pdo, 'customer_note'); } catch (Exception $e) { $hasCustomerNoteColumn = false; } // Added detection for dedicated notes column
        try { $hasLegacyNotesColumn = ordersHasColumn($pdo, 'notes'); } catch (Exception $e) { $hasLegacyNotesColumn = false; } // Added fallback for legacy installs using generic notes
        try { $hasPostalCodeColumn = ordersHasColumn($pdo, 'postal_code'); } catch (Exception $e) { $hasPostalCodeColumn = false; }
        try { $hasCityColumn = ordersHasColumn($pdo, 'city'); } catch (Exception $e) { $hasCityColumn = false; }

        try { $hasCustomerIdColumn = ordersHasColumn($pdo, 'customer_id'); } catch (Exception $e) { $hasCustomerIdColumn = false; }

        if ($hasEmailColumn) { $columns[] = 'email'; $values[] = $email; }
        if ($hasPhoneColumn) { $columns[] = 'phone'; $values[] = $phone; }
        if ($hasLegacyContact) { $columns[] = 'contact'; $values[] = $email; }

        if ($hasReferenceColumn) { $columns[] = 'reference_no'; $values[] = $referenceNumber; }
        if ($supportsTrackingCodes && $trackingCodeForRedirect !== null) { $columns[] = 'tracking_code'; $values[] = $trackingCodeForRedirect; }
        if ($hasFacebookColumn) { $columns[] = 'facebook_account'; $values[] = $facebookAccount; }
        if ($hasCustomerNoteColumn) { $columns[] = 'customer_note'; $values[] = $customerNote !== '' ? $customerNote : null; } // Added storage for cashier notes when column exists
        elseif ($hasLegacyNotesColumn) { $columns[] = 'notes'; $values[] = $customerNote !== '' ? $customerNote : null; } // Added fallback storage for systems that already expose a generic notes column
        if ($hasPostalCodeColumn) { $columns[] = 'postal_code'; $values[] = $postalCode; }
        if ($hasCityColumn) { $columns[] = 'city'; $values[] = $city; }

        if ($hasCustomerIdColumn) { $columns[] = 'customer_id'; $values[] = $customerAccount ? (int) $customerAccount['id'] : null; }

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

        if ($customerAccount) {
            $shouldSyncAddress = ($addressMode === 'edit') || !$customerHasSavedAddress;
            $updates = [];
            $updateValues = [];

            if ($customer_name !== '' && $customer_name !== $storedFullName) {
                $updates[] = 'full_name = ?';
                $updateValues[] = $customer_name;
            }

            if ($shouldSyncAddress) {
                if ($address !== '' && $address !== $storedAddress) {
                    $updates[] = 'address_line1 = ?';
                    $updateValues[] = $address;
                }
                if ($city !== '' && $city !== $storedCity) {
                    $updates[] = 'city = ?';
                    $updateValues[] = $city;
                }
                if ($postalCode !== '' && $postalCode !== $storedPostal) {
                    $updates[] = 'postal_code = ?';
                    $updateValues[] = $postalCode;
                }
            }

            if (!empty($updates)) {
                $updates[] = 'updated_at = NOW()';
                $updateValues[] = (int) $customerAccount['id'];
                $updateSql = 'UPDATE customers SET ' . implode(', ', $updates) . ' WHERE id = ?';
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute($updateValues);
                customerSessionRefresh();
            }
        }

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

        if ($supportsTrackingCodes && $trackingCodeForRedirect !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $nameParts = array_filter([
                trim($customer_name),
            ], static function ($value) {
                return $value !== '';
            });

            $displayName = !empty($nameParts) ? implode(' ', $nameParts) : 'there';
            $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
            $safeTrackingCode = htmlspecialchars($trackingCodeForRedirect, ENT_QUOTES, 'UTF-8');
            $trackOrderUrl = resolveTrackOrderUrl();
            $safeTrackOrderUrl = htmlspecialchars($trackOrderUrl, ENT_QUOTES, 'UTF-8');

            $emailSubject = 'Your DGZ Motorshop Tracking Code';
            $emailBody = '<p>Hi ' . $safeName . ',</p>'
                . '<p>We received your order, we will review your order and wait for your order to approve.</p>'
                . '<p>Your tracking code is <strong>' . $safeTrackingCode . '</strong>.</p>'
                . '<p>You can use this code on the <a href="' . $safeTrackOrderUrl . '">Track Order page</a> to follow the progress of your purchase.</p>'
                . '<p>Thank you,<br>DGZ Motorshop</p>';

            try {
                sendEmail($email, $emailSubject, $emailBody);
            } catch (Throwable $exception) {
                error_log('Failed to send order tracking code email: ' . $exception->getMessage());
            }
        }

        // Redirect (PRG) to avoid resubmission
        $query = ['success' => '1'];
        if ($trackingCodeForRedirect !== null) {
            $query['tracking_code'] = $trackingCodeForRedirect;
        }

        header('Location: checkout.php?' . http_build_query($query));
        exit;
    }
}

// Success page via GET (PRG target)
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $trackingCodeDisplay = '';
    if (isset($_GET['tracking_code'])) {
        $trackingCodeDisplay = strtoupper(preg_replace('/[^A-Z0-9\-]/', '', (string) $_GET['tracking_code']));
    }

    echo '<div style="max-width: 600px; margin: 50px auto; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); text-align: center;">';
    echo '<i class="fas fa-check-circle" style="font-size: 48px; color: #00b894; margin-bottom: 20px;"></i>';
    echo '<h2 style="color: #2d3436; margin-bottom: 20px;">Order Placed Successfully!</h2>';
    if ($trackingCodeDisplay !== '') {
        $trackOrderUrl = resolveTrackOrderUrl();
        $safeTrackOrderUrl = htmlspecialchars($trackOrderUrl, ENT_QUOTES, 'UTF-8');
        echo '<div style="display: inline-block; padding: 16px 28px; margin-bottom: 18px; background: #2563eb; color: white; font-size: 24px; font-weight: 700; letter-spacing: 2px; border-radius: 12px;">'
            . htmlspecialchars($trackingCodeDisplay, ENT_QUOTES, 'UTF-8')
            . '</div>';
        echo '<p style="color: #2d3436; margin-bottom: 10px; font-weight: 600;">Here is your tracking code. Keep it safe to check your order status.</p>';
        echo '<p style="color: #636e72; margin-bottom: 10px;">We received your order, we will review your order and wait for your order to approve.</p>';
        echo '<p style="color: #636e72; margin-bottom: 10px;">We also sent this code to your email so you can track the status anytime.</p>';
        echo '<p style="color: #2d3436; margin-bottom: 20px; font-weight: 500;">Use this code on the <a style="color: #0984e3; text-decoration: underline;" href="' . $safeTrackOrderUrl . '">Track Order page</a> to follow your order.</p>';
    } else {
        echo '<p style="color: #636e72; margin-bottom: 10px;">We received your order, we will review your order and wait for your order to approve.</p>';
        echo '<p style="color: #636e72; margin-bottom: 10px;">Please check your email for your tracking code.</p>';
    }
    echo '<p style="color: #636e72; margin-bottom: 30px;">Status: <span style="background: #fdcb6e; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">Pending</span></p>';
    echo '<a href="' . htmlspecialchars($shopUrl, ENT_QUOTES, 'UTF-8') . '" style="background: #2563eb; color: white; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: 600;">Back to Shop</a>';
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
     <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($checkoutStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($checkoutModalStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
</head>
<body data-customer-session="<?= htmlspecialchars($bodyCustomerState) ?>" data-customer-first-name="<?= htmlspecialchars($bodyCustomerFirstName) ?>" data-auth-required="checkout">
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <a href="<?= htmlspecialchars($shopUrl) ?>" aria-label="Go to DGZ Motorshop home">
                    <img src="<?= htmlspecialchars($logoAsset) ?>" alt="Company Logo">
                </a>
            </div>

            <div class="header-actions">
                <a href="<?= htmlspecialchars($shopUrl) ?>" class="continue-shopping-btn">
                    <i class="fas fa-arrow-left"></i> Continue Shopping
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

    <?php require __DIR__ . '/dgz_motorshop_system/includes/login_required_modal.php'; ?>

    <div class="container">
        <!-- Left Column - Checkout Form -->
        <div class="checkout-form">
            <?php if (!$isCustomerAuthenticated): ?>
                <div class="checkout-login-alert">Log in or create an account to finish checkout.</div>
            <?php endif; ?>
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
                <input type="hidden" name="address_mode" value="<?= htmlspecialchars($addressMode) ?>" data-billing-mode-input>
                
                <!-- Contact Section -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-user"></i>
                        Contact
                    </h2>
                    <div class="form-group">
                        <!-- Required indicator styling hook: edit .required-indicator in dgz_motorshop_system/assets/css/public/checkout.css -->
                        <label>Email <span class="required-indicator">*</span></label>
                        <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($formValues['email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Mobile number <span class="required-indicator">*</span></label>
                        <input type="tel" name="phone" placeholder="Mobile No." inputmode="numeric" maxlength="12" value="<?= htmlspecialchars($formValues['phone']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Facebook account <span class="required-indicator">*</span></label>
                        <input type="text" name="facebook_account" placeholder="Facebook profile or link" value="<?= htmlspecialchars($formValues['facebook_account']) ?>" required>
                    </div>
                </div>

                <!-- Billing Address Section -->
                <div class="section section--billing" data-billing-section data-billing-mode="<?= htmlspecialchars($addressMode) ?>">
                    <h2 class="section-title">
                        <i class="fas fa-map-marker-alt"></i>
                        Billing Address
                    </h2>
                    <?php if ($showAddressSummary): ?>
                        <div class="billing-summary" data-billing-summary>
                            <dl class="billing-summary__details">
                                <div>
                                    <dt>Name</dt>
                                    <dd><?= htmlspecialchars($formValues['customer_name']) ?></dd>
                                </div>
                                <div>
                                    <dt>Address</dt>
                                    <dd><?= nl2br(htmlspecialchars($formValues['address'])) ?></dd>
                                </div>
                                <div class="billing-summary__inline">
                                    <div>
                                        <dt>City</dt>
                                        <dd><?= htmlspecialchars($formValues['city']) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Postal code</dt>
                                        <dd><?= htmlspecialchars($formValues['postal_code']) ?></dd>
                                    </div>
                                </div>
                            </dl>
                            <p class="billing-summary__hint">We’ll reuse this billing information for future orders.</p>
                            <button type="button" class="billing-summary__edit" data-billing-edit>
                                <i class="fas fa-pen"></i>
                                Edit billing address
                            </button>
                        </div>
                    <?php endif; ?>
                    <div class="billing-form<?= $showAddressSummary ? ' is-hidden' : '' ?>" data-billing-form <?= $showAddressSummary ? 'hidden' : '' ?>>
                        <div class="form-group">
                            <label>Full name <span class="required-indicator">*</span></label>
                            <input type="text" name="customer_name" value="<?= htmlspecialchars($formValues['customer_name']) ?>" <?= $showAddressSummary ? '' : 'required' ?> data-billing-required>
                        </div>
                        <div class="form-group">
                            <label>Address <span class="required-indicator">*</span></label>
                            <textarea name="address" placeholder="Street address, apartment, suite, etc." <?= $showAddressSummary ? '' : 'required' ?> data-billing-required><?= htmlspecialchars($formValues['address']) ?></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Postal code <span class="required-indicator">*</span></label>
                                <input type="text" name="postal_code" value="<?= htmlspecialchars($formValues['postal_code']) ?>" <?= $showAddressSummary ? '' : 'required' ?> data-billing-required>
                            </div>
                            <div class="form-group">
                                <label>City <span class="required-indicator">*</span></label>
                                <input type="text" name="city" value="<?= htmlspecialchars($formValues['city']) ?>" <?= $showAddressSummary ? '' : 'required' ?> data-billing-required>
                            </div>
                        </div>
                        <div class="form-group">
                            <!-- Added note textarea so customers can leave instructions for the cashier -->
                            <label for="customer_note">Notes for the cashier</label>
                            <textarea name="customer_note" id="customer_note" maxlength="500" placeholder="Add delivery instructions, preferred pickup time, etc."><?= htmlspecialchars($formValues['customer_note']) ?></textarea>
                        </div>
                        <?php if ($showAddressSummary): ?>
                            <button type="button" class="billing-form__cancel" data-billing-cancel>Cancel</button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Section -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-credit-card"></i>
                        Payment
                    </h2>
                    <div class="payment-methods" role="radiogroup" aria-label="Select a payment method">
                        <div class="payment-option">
                            <input
                                type="radio"
                                name="payment_method"
                                value="GCash"
                                id="payment_gcash"
                                data-qr="<?= htmlspecialchars($qrAsset) ?>"
                                data-qr-alt="GCash payment QR code"
                                <?= $selectedPaymentMethod === 'GCash' ? 'checked' : '' ?>
                            >
                            <label for="payment_gcash">
                                <i class="fas fa-mobile-alt" aria-hidden="true"></i>
                                <span>GCash</span>
                            </label>
                        </div>
                        <div class="payment-option">
                            <input
                                type="radio"
                                name="payment_method"
                                value="Maya"
                                id="payment_maya"
                                data-qr="<?= htmlspecialchars($mayaQrAsset) ?>"
                                data-qr-alt="Maya payment QR code"
                                <?= $selectedPaymentMethod === 'Maya' ? 'checked' : '' ?>
                            >
                            <label for="payment_maya">
                                <i class="fas fa-wallet" aria-hidden="true"></i>
                                <span>Maya</span>
                            </label>
                        </div>
                    </div>

                    <div class="qr-code" data-default-qr="<?= htmlspecialchars($qrAsset) ?>">
                        <img id="paymentQrImage" src="<?= htmlspecialchars($currentQrAsset) ?>" alt="<?= htmlspecialchars($currentQrAlt) ?>">
                    </div>

                    <div class="form-group">
                        <label for="reference_number">Reference Number <span class="required-indicator">*</span></label>
                        <input type="text" name="reference_number" id="reference_number" maxlength="50" value="<?= htmlspecialchars($referenceInput) ?>" placeholder="e.g. GCASH123456" required>
                    </div>

                    <div class="file-upload">
                        <input type="file" name="proof" id="proof" accept="image/*" required>
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
            <h2 class="summary-title">Order Summary</h2>
            <!-- Clear cart button keeps summary synchronized with local storage -->
            <button type="button" id="clearCartButton" class="clear-cart-btn" style="<?= empty($cartItems) ? 'display:none;' : '' ?>">Clear Cart</button>
            <div id="orderItemsContainer" class="order-items">
                <?php foreach ($cartItems as $index => $item): ?>
                <div class="order-item" data-index="<?= $index ?>">
                    <div class="item-image">
                        <?php $imgSrc = !empty($item['image']) ? $item['image'] : $productPlaceholder; ?>
                        <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                    </div>
                    <div class="item-details">
                        <div class="item-header">
                            <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                            <span class="item-price">₱ <?= number_format($item['price'], 2) ?></span>
                        </div>
                        <div class="item-category">
                            <?= htmlspecialchars($item['variant_label'] !== '' ? 'Variant: ' . $item['variant_label'] : 'Product') ?>
                        </div>
                        <?php if (isset($item['stock']) && $item['stock'] !== null): ?>
                        <div class="item-stock">Stock: <?= (int) $item['stock'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="item-meta">
                        <!-- Quantity input remains editable so buyers can adjust before checkout -->
                        <div class="quantity-control" data-index="<?= $index ?>">
                            <button type="button" class="qty-btn qty-btn--decrease" data-index="<?= $index ?>" aria-label="Decrease quantity">
                                <i class="fas fa-minus" aria-hidden="true"></i>
                            </button>
                            <input type="number" class="quantity-input" min="1" <?= isset($item['stock']) && $item['stock'] !== null ? 'max="'.(int)$item['stock'].'"' : '' ?> value="<?= $item['quantity'] ?>" data-index="<?= $index ?>">
                            <button type="button" class="qty-btn qty-btn--increase" data-index="<?= $index ?>" aria-label="Increase quantity">
                                <i class="fas fa-plus" aria-hidden="true"></i>
                            </button>
                        </div>
                        <button type="button" class="item-remove" data-index="<?= $index ?>">Remove</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="orderEmptyState" class="order-empty" style="<?= empty($cartItems) ? '' : 'display:none;' ?>">
                <i class="fas fa-shopping-basket"></i>
                <p>Your cart is empty.</p>
                <a href="<?= htmlspecialchars($shopUrl) ?>" class="order-empty-link">Continue shopping</a>
            </div>

            <div id="inventoryAdjustmentNotice" class="inventory-notice" role="alert" aria-live="polite"></div>

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

    <div id="highValueConfirmModal" class="checkout-modal" hidden>
        <div class="checkout-modal__dialog">
            <h3>Large Transaction</h3>
            <p>This transaction is too big, would you like to personally go to our store or would you like to proceed?</p>
            <div class="checkout-modal__actions">
                <button type="button" class="checkout-modal__button checkout-modal__button--primary" data-high-value-proceed>
                    Yes, I would like to proceed
                </button>
                <button type="button" class="checkout-modal__button checkout-modal__button--secondary" data-high-value-cancel>
                    Ok
                </button>
            </div>
        </div>
    </div>

    <div id="highValueBlockedModal" class="checkout-modal" hidden>
        <div class="checkout-modal__dialog">
            <h3>Amount Too High</h3>
            <p>This transaction is too big for our online ordering. We would advise you to personally go to our physical store to shop!</p>
            <div class="checkout-modal__actions">
                <button type="button" class="checkout-modal__button checkout-modal__button--primary" data-high-value-blocked-ok>
                    Ok
                </button>
            </div>
        </div>
    </div>

    <script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
    <script>
        const proofInput = document.getElementById('proof');
        const proofLabel = document.querySelector('label[for="proof"]');
        const qrImage = document.getElementById('paymentQrImage');
        const paymentRadios = Array.from(document.querySelectorAll('input[name="payment_method"]'));
        const referenceField = document.getElementById('reference_number');

        const defaultProofLabel = proofLabel ? proofLabel.innerHTML : '';

        function resetProofUploadAppearance() {
            if (!proofLabel) {
                return;
            }
            proofLabel.innerHTML = defaultProofLabel;
            proofLabel.style.background = '';
            proofLabel.style.color = '';
        }

        // File upload feedback
        proofInput?.addEventListener('change', (event) => {
            if (!proofLabel) {
                return;
            }

            if (event.target.files.length > 0) {
                proofLabel.innerHTML = '<i class="fas fa-check"></i> ' + event.target.files[0].name;
                proofLabel.style.background = '#00b894';
                proofLabel.style.color = 'white';
            } else {
                resetProofUploadAppearance();
            }
        });

        const referencePlaceholders = {
            GCash: 'e.g. GCASH123456',
            Maya: 'e.g. MAYA123456',
        };

        function applyPaymentSelection(selectedRadio) {
            if (!selectedRadio) {
                return;
            }

            const paymentValue = selectedRadio.value;
            if (qrImage) {
                const newSrc = selectedRadio.getAttribute('data-qr');
                if (newSrc) {
                    qrImage.src = newSrc;
                }
                const newAlt = selectedRadio.getAttribute('data-qr-alt');
                if (newAlt) {
                    qrImage.alt = newAlt;
                }
            }

            if (referenceField && referencePlaceholders[paymentValue]) {
                referenceField.placeholder = referencePlaceholders[paymentValue];
            }
        }

        // Payment method toggle
        paymentRadios.forEach((radio) => {
            radio.addEventListener('change', () => {
                applyPaymentSelection(radio);
            });
        });

        // Apply initial selection state on page load
        const initiallyChecked = paymentRadios.find((radio) => radio.checked) ?? paymentRadios[0] ?? null;
        if (initiallyChecked) {
            applyPaymentSelection(initiallyChecked);
        }

        const cartInput = document.querySelector('input[name="cart"]');
        const orderItemsContainer = document.getElementById('orderItemsContainer');
        const emptyState = document.getElementById('orderEmptyState');
        const subtotalLabel = document.getElementById('summarySubtotalLabel');
        const subtotalValue = document.getElementById('summarySubtotalValue');
        const totalValue = document.getElementById('summaryTotalValue');
        const submitButton = document.querySelector('.submit-btn');
        const clearCartButton = document.getElementById('clearCartButton');
        const checkoutForm = document.querySelector('.checkout-form form');
        const highValueConfirmModal = document.getElementById('highValueConfirmModal');
        const highValueBlockedModal = document.getElementById('highValueBlockedModal');
        const highValueProceedButton = document.querySelector('[data-high-value-proceed]');
        const highValueCancelButton = document.querySelector('[data-high-value-cancel]');
        const highValueBlockedOkButton = document.querySelector('[data-high-value-blocked-ok]');
        const inventoryNotice = document.getElementById('inventoryAdjustmentNotice');
        const inventoryCheckUrl = <?= json_encode($inventoryAvailabilityApi) ?>;
        const inventoryRefreshIntervalMs = 30000;

        let cartState = [];
        try {
            cartState = JSON.parse(cartInput.value || '[]') || [];
        } catch (error) {
            cartState = [];
        }

        let currentTotals = { items: 0, subtotal: 0 };
        let highValueOverride = false;
        let pendingHighValueSubmission = null;
        let inventoryRefreshTimer = null;
        let inventoryFetchPromise = null;

        function openModal(modal) {
            if (!modal) {
                return;
            }
            modal.removeAttribute('hidden');
        }

        function closeModal(modal) {
            if (!modal) {
                return;
            }
            modal.setAttribute('hidden', 'hidden');
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showInventoryNotice(messages) {
            if (!inventoryNotice) {
                return;
            }

            if (!Array.isArray(messages) || messages.length === 0) {
                inventoryNotice.innerHTML = '';
                inventoryNotice.classList.remove('inventory-notice--visible');
                return;
            }

            const list = messages
                .map((message) => `<li>${escapeHtml(message)}</li>`)
                .join('');
            inventoryNotice.innerHTML = `<ul>${list}</ul>`;
            inventoryNotice.classList.add('inventory-notice--visible');
        }

        function formatItemLabel(item) {
            if (!item) {
                return 'This item';
            }
            const name = item.name ? String(item.name) : 'This item';
            const variant = item.variantLabel ? ` (${item.variantLabel})` : '';
            return `${name}${variant}`;
        }

        function makeInventoryKey(item) {
            const productId = item && item.id ? Number(item.id) : 0;
            const variantId = item && item.variantId !== undefined && item.variantId !== null
                ? Number(item.variantId)
                : null;
            return `${productId}:${variantId !== null ? variantId : 'base'}`;
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
                return;
            }

            if (currentTotals.subtotal >= 100000) {
                event.preventDefault();
                highValueOverride = false;
                pendingHighValueSubmission = null;
                openModal(highValueBlockedModal);
                return;
            }

            if (!highValueOverride && currentTotals.subtotal >= 70000) {
                event.preventDefault();
                pendingHighValueSubmission = () => {
                    highValueOverride = true;
                    if (typeof checkoutForm.requestSubmit === 'function') {
                        checkoutForm.requestSubmit(submitButton ?? undefined);
                    } else {
                        checkoutForm.submit();
                    }
                };
                openModal(highValueConfirmModal);
                return;
            }

            if (highValueOverride) {
                highValueOverride = false;
            }
        });

        function normaliseItem(rawItem = {}) {
            const basePrice = rawItem.price ?? rawItem.variant_price;
            const price = Number(basePrice);
            let quantity = Number(rawItem.quantity);
            const variantId = rawItem.variantId ?? rawItem.variant_id ?? null;
            const variantLabelRaw = rawItem.variantLabel ?? rawItem.variant_label ?? '';
            const stockValue = rawItem.stock ?? null;
            const imageValue = rawItem.image ?? null;
            const stock = stockValue === null || stockValue === undefined
                ? null
                : Math.max(0, Math.floor(Number(stockValue)));
            const isUnavailable = rawItem.unavailable === true || (stock !== null && stock <= 0);

            if (!Number.isFinite(quantity)) {
                quantity = isUnavailable ? 0 : 1;
            }

            quantity = Math.floor(quantity);

            if (quantity < 0) {
                quantity = 0;
            }

            if (isUnavailable) {
                quantity = 0;
            } else if (stock !== null && quantity > stock) {
                quantity = Math.max(1, stock);
            } else if (!isUnavailable && quantity === 0) {
                quantity = stock !== null && stock > 0 ? 1 : 0;
            }

            return {
                id: rawItem.id ?? null,
                name: rawItem.name ?? 'Product',
                price: Number.isFinite(price) ? price : 0,
                quantity,
                variantId: variantId !== null ? Number(variantId) : null,
                variantLabel: variantLabelRaw ? String(variantLabelRaw) : '',
                variantPrice: Number.isFinite(price) ? price : 0,
                stock,
                unavailable: isUnavailable,
                image: imageValue ?? null,
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
                const totalItems = cartState.reduce((sum, item) => {
                    const quantity = Number(item.quantity);
                    return sum + (Number.isFinite(quantity) && quantity > 0 ? quantity : 0);
                }, 0);
                localStorage.setItem('cartCount', String(totalItems));
            } catch (error) {
                console.error('Unable to persist cart to localStorage.', error);
            }
        }

        function commitQuantityChange(input, options = {}) {
            const { allowEmpty = false, showAlert = true, enforceMax = true } = options;

            if (!input) {
                return;
            }

            const index = Number(input.dataset.index);
            if (!Number.isInteger(index) || index < 0 || index >= cartState.length) {
                return;
            }

            const cartItem = cartState[index];
            if (!cartItem) {
                return;
            }

            if (cartItem.unavailable) {
                input.value = '0';
                input.dataset.previousValidValue = '0';
                return;
            }

            const rawValue = String(input.value).trim();
            const stockRaw = cartItem.stock;
            const stockQuantity = stockRaw !== undefined && stockRaw !== null
                ? Number(stockRaw)
                : null;
            const minimumQuantity = stockQuantity !== null && stockQuantity <= 0 ? 0 : 1;

            if (rawValue === '') {
                if (allowEmpty) {
                    return;
                }

                const previous = Number.parseInt(input.dataset.previousValidValue || '', 10);
                let fallback = Number.isFinite(previous) && previous >= minimumQuantity ? previous : minimumQuantity;
                if (stockQuantity !== null) {
                    fallback = Math.min(fallback, Math.max(minimumQuantity, stockQuantity));
                }

                input.value = String(fallback);
                input.dataset.previousValidValue = String(fallback);
                cartState[index].quantity = fallback;
                if (fallback === 0) {
                    cartState[index].unavailable = true;
                }
                updateSummary();
                syncCartInput();
                syncBrowserStorage();
                return;
            }

            let parsed = Number.parseInt(rawValue, 10);
            if (!Number.isFinite(parsed) || parsed < minimumQuantity) {
                if (!enforceMax) {
                    return;
                }
                parsed = minimumQuantity;
            }

            if (stockQuantity !== null && parsed > stockQuantity) {
                if (!enforceMax) {
                    return;
                }

                if (showAlert) {
                    alert(`Only ${stockQuantity} stock available.`);
                }

                const previous = Number.parseInt(input.dataset.previousValidValue || '', 10);
                let fallback;
                if (Number.isFinite(previous) && previous >= minimumQuantity && previous <= stockQuantity) {
                    fallback = previous;
                } else {
                    fallback = Math.max(minimumQuantity, stockQuantity);
                }

                parsed = fallback;
            }

            input.value = String(parsed);
            input.dataset.previousValidValue = String(parsed);
            cartState[index].quantity = parsed;
            if (parsed === 0) {
                cartState[index].unavailable = true;
            }
            updateSummary();
            syncCartInput();
            syncBrowserStorage();
        }

        function updateSummary() {
            const totals = cartState.reduce((acc, item) => {
                const { price, quantity } = normaliseItem(item);
                if (quantity > 0) {
                    acc.items += quantity;
                    acc.subtotal += price * quantity;
                }
                return acc;
            }, { items: 0, subtotal: 0 });

            subtotalLabel.textContent = `Subtotal, ${totals.items} item${totals.items === 1 ? '' : 's'}`;
            subtotalValue.textContent = formatPeso(totals.subtotal);
            totalValue.textContent = formatPeso(totals.subtotal);

            currentTotals = totals;
            if (currentTotals.subtotal < 70000) {
                highValueOverride = false;
            }

            const hasItems = cartState.length > 0;
            const hasPurchasableItems = cartState.some((item) => normaliseItem(item).quantity > 0);
            submitButton.disabled = !hasPurchasableItems;
            emptyState.style.display = hasItems ? 'none' : 'block';
            if (clearCartButton) {
                clearCartButton.style.display = hasItems ? 'block' : 'none';
                clearCartButton.disabled = !hasItems;
            }

            if (!hasItems) {
                showInventoryNotice([]);
            }
        }

        function createOrderItemRow(item, index) {
            const row = document.createElement('div');
            row.className = 'order-item';
            row.dataset.index = String(index);

            const image = document.createElement('div');
            image.className = 'item-image';
            const imgSrc = item && item.image ? String(item.image) : '<?= htmlspecialchars($productPlaceholder) ?>';
            image.innerHTML = `<img src="${imgSrc}" alt="${(item && item.name) ? String(item.name).replace(/"/g, '&quot;') : 'Product'}">`;
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

            const stockValue = item.stock !== undefined && item.stock !== null ? Number(item.stock) : null;
            const isUnavailable = item.unavailable === true || (stockValue !== null && stockValue <= 0);

            if (stockValue !== null) {
                const stock = document.createElement('div');
                stock.className = 'item-stock';
                stock.textContent = isUnavailable ? 'Stock: 0' : `Stock: ${stockValue}`;
                details.appendChild(stock);
            }

            if (isUnavailable) {
                const status = document.createElement('div');
                status.className = 'item-status item-status--unavailable';
                status.textContent = 'This item is currently out of stock.';
                details.appendChild(status);
            }

            row.appendChild(details);

            const meta = document.createElement('div');
            meta.className = 'item-meta';

            // Quantity input remains editable so buyers can adjust before checkout
            const qtyWrapper = document.createElement('div');
            qtyWrapper.className = 'quantity-control';
            qtyWrapper.dataset.index = String(index);

            const decreaseBtn = document.createElement('button');
            decreaseBtn.type = 'button';
            decreaseBtn.className = 'qty-btn qty-btn--decrease';
            decreaseBtn.dataset.index = String(index);
            decreaseBtn.setAttribute('aria-label', 'Decrease quantity');
            decreaseBtn.innerHTML = '<i class="fas fa-minus" aria-hidden="true"></i>';

            const qty = document.createElement('input');
            qty.type = 'number';
            qty.className = 'quantity-input';
            qty.min = isUnavailable ? 0 : 1;
            qty.value = item.quantity;
            qty.dataset.index = String(index);
            qty.dataset.previousValidValue = String(item.quantity);
            if (stockValue !== null && stockValue > 0) {
                qty.max = String(stockValue);
            } else if (isUnavailable) {
                qty.max = '0';
            }

            const increaseBtn = document.createElement('button');
            increaseBtn.type = 'button';
            increaseBtn.className = 'qty-btn qty-btn--increase';
            increaseBtn.dataset.index = String(index);
            increaseBtn.setAttribute('aria-label', 'Increase quantity');
            increaseBtn.innerHTML = '<i class="fas fa-plus" aria-hidden="true"></i>';

            qtyWrapper.appendChild(decreaseBtn);
            qtyWrapper.appendChild(qty);
            qtyWrapper.appendChild(increaseBtn);

            if (isUnavailable) {
                qty.disabled = true;
                decreaseBtn.disabled = true;
                increaseBtn.disabled = true;
                qtyWrapper.classList.add('quantity-control--disabled');
            }

            meta.appendChild(qtyWrapper);

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

        function applyInventorySnapshot(snapshotItems) {
            if (!Array.isArray(snapshotItems) || snapshotItems.length === 0) {
                return;
            }

            const updateMap = new Map();
            snapshotItems.forEach((entry) => {
                const productId = Number(entry.product_id ?? entry.productId ?? 0);
                if (!Number.isFinite(productId) || productId <= 0) {
                    return;
                }
                const variantRaw = entry.variant_id ?? entry.variantId ?? null;
                const variantId = variantRaw !== null && variantRaw !== undefined
                    ? Number(variantRaw)
                    : null;
                const stockRaw = entry.stock ?? null;
                const stock = stockRaw === null || stockRaw === undefined
                    ? 0
                    : Math.max(0, Math.floor(Number(stockRaw)));
                const key = `${productId}:${variantId !== null ? variantId : 'base'}`;
                updateMap.set(key, {
                    productId,
                    variantId,
                    stock,
                });
            });

            if (updateMap.size === 0) {
                return;
            }

            const adjustmentMessages = [];
            let shouldRender = false;

            cartState = cartState.map((item) => {
                const key = makeInventoryKey(item);
                if (!updateMap.has(key)) {
                    return item;
                }

                const update = updateMap.get(key);
                const previousStock = item.stock ?? null;
                const previousQuantity = Number(item.quantity) || 0;
                const wasUnavailable = Boolean(item.unavailable);
                const nextStock = update.stock;
                const updatedItem = { ...item, stock: nextStock };

                if (nextStock <= 0) {
                    if (previousQuantity !== 0 || !wasUnavailable) {
                        adjustmentMessages.push(`${formatItemLabel(item)} is now out of stock. Quantity set to 0.`);
                    }
                    updatedItem.quantity = 0;
                    updatedItem.unavailable = true;
                    if (!wasUnavailable || previousQuantity !== 0 || previousStock !== nextStock) {
                        shouldRender = true;
                    }
                } else {
                    const maxAllowed = Math.max(1, nextStock);
                    let nextQuantity = previousQuantity;

                    if (previousQuantity <= 0 || wasUnavailable) {
                        nextQuantity = Math.min(maxAllowed, Math.max(1, previousQuantity || 1));
                        if (wasUnavailable) {
                            adjustmentMessages.push(`${formatItemLabel(item)} is back in stock. Quantity set to ${nextQuantity}.`);
                        }
                    }

                    if (nextQuantity > maxAllowed) {
                        nextQuantity = maxAllowed;
                        adjustmentMessages.push(`${formatItemLabel(item)} quantity reduced to ${maxAllowed} due to limited stock.`);
                    }

                    updatedItem.quantity = nextQuantity;
                    updatedItem.unavailable = false;

                    if (nextQuantity !== previousQuantity || wasUnavailable || previousStock !== nextStock) {
                        shouldRender = true;
                    }
                }

                if (previousStock !== nextStock && nextStock <= 0 && !wasUnavailable) {
                    shouldRender = true;
                }

                return updatedItem;
            });

            if (shouldRender) {
                renderOrderItems();
            } else {
                updateSummary();
                syncCartInput();
                syncBrowserStorage();
            }

            if (adjustmentMessages.length > 0) {
                showInventoryNotice(adjustmentMessages);
            }
        }

        function refreshInventoryAvailability(options = {}) {
            const { force = false } = options;

            if (!inventoryCheckUrl || typeof inventoryCheckUrl !== 'string' || inventoryCheckUrl === '') {
                return;
            }

            if (cartState.length === 0) {
                return;
            }

            if (!force && document.hidden) {
                return;
            }

            if (inventoryFetchPromise) {
                return;
            }

            const payloadItems = cartState
                .map((item) => {
                    const productId = Number(item.id ?? item.productId ?? 0);
                    if (!Number.isFinite(productId) || productId <= 0) {
                        return null;
                    }
                    const variantId = item.variantId !== null && item.variantId !== undefined
                        ? Number(item.variantId)
                        : null;
                    return {
                        product_id: productId,
                        variant_id: variantId,
                    };
                })
                .filter((value) => value !== null);

            if (payloadItems.length === 0) {
                return;
            }

            inventoryFetchPromise = fetch(inventoryCheckUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items: payloadItems }),
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Unexpected status ${response.status}`);
                    }
                    return response.json();
                })
                .then((data) => {
                    if (data && Array.isArray(data.items)) {
                        applyInventorySnapshot(data.items);
                    }
                })
                .catch((error) => {
                    console.error('Unable to refresh inventory availability.', error);
                })
                .finally(() => {
                    inventoryFetchPromise = null;
                });
        }

        function scheduleInventoryRefresh() {
            if (!inventoryCheckUrl || typeof inventoryCheckUrl !== 'string' || inventoryCheckUrl === '') {
                return;
            }

            if (inventoryRefreshTimer) {
                clearInterval(inventoryRefreshTimer);
            }

            inventoryRefreshTimer = setInterval(() => {
                if (document.hidden) {
                    return;
                }
                refreshInventoryAvailability();
            }, inventoryRefreshIntervalMs);
        }

        orderItemsContainer?.addEventListener('click', (event) => {
            const removeTarget = event.target.closest('.item-remove');
            if (removeTarget) {
                const index = Number(removeTarget.dataset.index);
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
                return;
            }

            const adjustButton = event.target.closest('.qty-btn');
            if (!adjustButton) {
                return;
            }

            const control = adjustButton.closest('.quantity-control');
            const input = control?.querySelector('.quantity-input');
            if (!control || !input) {
                return;
            }

            const index = Number(adjustButton.dataset.index);
            if (!Number.isInteger(index) || index < 0 || index >= cartState.length) {
                return;
            }

            const delta = adjustButton.classList.contains('qty-btn--increase') ? 1 : -1;
            const currentValue = Number(input.value) || 1;
            const nextValue = Math.max(1, currentValue + delta);

            if (nextValue === currentValue) {
                return;
            }

            input.value = String(nextValue);
            commitQuantityChange(input, { allowEmpty: false, showAlert: true, enforceMax: true });
        });

        orderItemsContainer?.addEventListener('focusin', (event) => {
            const target = event.target;
            if (!target.classList.contains('quantity-input')) {
                return;
            }

            const current = Number.parseInt(target.value, 10);
            const safeValue = Number.isFinite(current) && current >= 1 ? current : 1;
            target.dataset.previousValidValue = String(safeValue);
        });

        // Add event listener for quantity input changes
        orderItemsContainer?.addEventListener('input', (event) => {
            const target = event.target;
            if (!target.classList.contains('quantity-input')) {
                return;
            }
            commitQuantityChange(target, { allowEmpty: true, showAlert: false, enforceMax: false });
        });

        orderItemsContainer?.addEventListener('focusout', (event) => {
            const target = event.target;
            if (!target.classList.contains('quantity-input')) {
                return;
            }

            commitQuantityChange(target, { allowEmpty: false, showAlert: true, enforceMax: true });
        });

        // Clearing the cart wipes local storage and refreshes the summary instantly
        clearCartButton?.addEventListener('click', () => {
            cartState = [];
            renderOrderItems();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            showInventoryNotice([]);
        });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                return;
            }
            refreshInventoryAvailability({ force: true });
        });

        window.addEventListener('focus', () => {
            refreshInventoryAvailability({ force: true });
        });

        highValueProceedButton?.addEventListener('click', () => {
            closeModal(highValueConfirmModal);
            const resume = pendingHighValueSubmission;
            pendingHighValueSubmission = null;
            if (typeof resume === 'function') {
                resume();
            }
        });

        highValueCancelButton?.addEventListener('click', () => {
            closeModal(highValueConfirmModal);
            pendingHighValueSubmission = null;
        });

        highValueBlockedOkButton?.addEventListener('click', () => {
            closeModal(highValueBlockedModal);
        });

        renderOrderItems();
        scheduleInventoryRefresh();
        refreshInventoryAvailability({ force: true });
    </script>
</body>
</html>
