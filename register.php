<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

$customerSession = getAuthenticatedCustomer();
if ($customerSession !== null) {
    header('Location: ' . orderingUrl('index.php'));
    exit;
}

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$logoAsset = assetUrl('assets/logo.png');

$errors = [];
$values = [
    'full_name' => trim($_POST['full_name'] ?? ''),
    'email' => trim($_POST['email'] ?? ''),
    'phone' => trim($_POST['phone'] ?? ''),
    'facebook_account' => trim($_POST['facebook_account'] ?? ''),
    'address' => trim($_POST['address'] ?? ''),
    'postal_code' => trim($_POST['postal_code'] ?? ''),
    'city' => trim($_POST['city'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($values['full_name'] === '') {
        $errors['full_name'] = 'Please enter your full name.';
    }

    if ($values['email'] === '') {
        $errors['email'] = 'Please enter your email address.';
    } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($values['phone'] === '') {
        $errors['phone'] = 'Please enter your phone number.';
    } else {
        $normalizedPhone = normalizeCustomerPhone($values['phone']);
        if ($normalizedPhone === '') {
            $errors['phone'] = 'Please enter a valid phone number.';
        } else {
            $values['phone'] = $normalizedPhone;
        }
    }

    if ($values['facebook_account'] === '') {
        $errors['facebook_account'] = 'Please share your Facebook account so we can contact you.';
    }

    if ($values['address'] === '') {
        $errors['address'] = 'Please enter your street address.';
    }

    if ($values['postal_code'] === '') {
        $errors['postal_code'] = 'Please enter your postal code.';
    }

    if ($values['city'] === '') {
        $errors['city'] = 'Please enter your city.';
    }

    if ($password === '' || strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $errors['password'] = 'Passwords do not match.';
    }

    if ($errors === []) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE email = ?');
            $stmt->execute([$values['email']]);
            if ($stmt->fetchColumn() > 0) {
                $errors['email'] = 'An account with this email already exists.';
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE phone = ?');
            $stmt->execute([$values['phone']]);
            if ($stmt->fetchColumn() > 0) {
                $errors['phone'] = 'An account with this phone number already exists.';
            }

            if ($errors === []) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $addressColumn = customerFindColumn($pdo, ['address_line1', 'address', 'address1', 'street']);
                $cityColumn = customerFindColumn($pdo, ['city', 'town', 'municipality']);
                $postalColumn = customerFindColumn($pdo, ['postal_code', 'postal', 'zip_code', 'zipcode', 'zip']);
                $facebookColumn = customerFindColumn($pdo, ['facebook_account', 'facebook', 'fb_account']);

                $columns = ['full_name', 'email', 'phone', 'password_hash'];
                $placeholders = ['?', '?', '?', '?'];
                $insertValues = [
                    $values['full_name'],
                    $values['email'],
                    $values['phone'],
                    $passwordHash,
                ];

                if ($facebookColumn !== null) {
                    $columns[] = $facebookColumn;
                    $placeholders[] = '?';
                    $insertValues[] = $values['facebook_account'];
                }

                if ($addressColumn !== null) {
                    $columns[] = $addressColumn;
                    $placeholders[] = '?';
                    $insertValues[] = $values['address'];
                }

                if ($postalColumn !== null) {
                    $columns[] = $postalColumn;
                    $placeholders[] = '?';
                    $insertValues[] = $values['postal_code'];
                }

                if ($cityColumn !== null) {
                    $columns[] = $cityColumn;
                    $placeholders[] = '?';
                    $insertValues[] = $values['city'];
                }

                $quotedColumns = array_map(static function (string $column): string {
                    return '`' . $column . '`';
                }, $columns);

                $sql = 'INSERT INTO customers (' . implode(', ', $quotedColumns) . ', `created_at`) VALUES (' . implode(', ', $placeholders) . ', NOW())';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($insertValues);
                $customerId = (int) $pdo->lastInsertId();
                customerPersistPasswordHash($pdo, $customerId, $passwordHash);
                customerLogin($customerId);

                $redirect = $_GET['redirect'] ?? orderingUrl('index.php');
                if (!is_string($redirect) || $redirect === '') {
                    $redirect = orderingUrl('index.php');
                }
                header('Location: ' . $redirect);
                exit;
            }
        } catch (Throwable $exception) {
            error_log('Unable to register customer: ' . $exception->getMessage());
            $errors['general'] = 'Something went wrong while creating your account. Please try again.';
        }
    }
}

$redirectParam = isset($_GET['redirect']) ? (string) $_GET['redirect'] : '';
?>
<!doctype html>
<html lang="en" data-customer-session="guest">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create your DGZ Motorshop account</title>
    <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
</head>
<body class="customer-auth-page" data-customer-auth="register">
    <main class="customer-auth-card" aria-labelledby="registerHeading">
        <a href="<?= htmlspecialchars(orderingUrl('index.php')) ?>" class="customer-auth-logo">
            <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop logo">
        </a>
        <h1 id="registerHeading">Create your account</h1>
        <p class="customer-auth-subtitle">Track your orders faster and save your checkout details.</p>
        <?php if (!empty($errors['general'])): ?>
            <div class="customer-auth-alert" role="alert"><?= htmlspecialchars($errors['general']) ?></div>
        <?php endif; ?>
        <form class="customer-auth-form" method="post" novalidate data-customer-form>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectParam) ?>">
            <div class="form-field<?= isset($errors['full_name']) ? ' has-error' : '' ?>">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($values['full_name']) ?>" required autocomplete="name">
                <?php if (isset($errors['full_name'])): ?>
                    <p class="field-error" role="alert"><?= htmlspecialchars($errors['full_name']) ?></p>
                <?php endif; ?>
            </div>
            <div class="form-split">
                <div class="form-field<?= isset($errors['email']) ? ' has-error' : '' ?>">
                    <label for="email">Email address <span class="required-indicator">*</span></label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" autocomplete="email" required>
                    <?php if (isset($errors['email'])): ?>
                        <p class="field-error" role="alert"><?= htmlspecialchars($errors['email']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="form-field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
                    <label for="phone">Phone number <span class="required-indicator">*</span></label>
                    <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($values['phone']) ?>" autocomplete="tel" required>
                    <?php if (isset($errors['phone'])): ?>
                        <p class="field-error" role="alert"><?= htmlspecialchars($errors['phone']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-field<?= isset($errors['facebook_account']) ? ' has-error' : '' ?>">
                <label for="facebook_account">Facebook account <span class="required-indicator">*</span></label>
                <input type="text" id="facebook_account" name="facebook_account" value="<?= htmlspecialchars($values['facebook_account']) ?>" autocomplete="off" required>
                <?php if (isset($errors['facebook_account'])): ?>
                    <p class="field-error" role="alert"><?= htmlspecialchars($errors['facebook_account']) ?></p>
                <?php endif; ?>
            </div>
            <div class="form-field<?= isset($errors['address']) ? ' has-error' : '' ?>">
                <label for="address">Address <span class="required-indicator">*</span></label>
                <textarea id="address" name="address" rows="3" autocomplete="street-address" required><?= htmlspecialchars($values['address']) ?></textarea>
                <?php if (isset($errors['address'])): ?>
                    <p class="field-error" role="alert"><?= htmlspecialchars($errors['address']) ?></p>
                <?php endif; ?>
            </div>
            <div class="form-split">
                <div class="form-field<?= isset($errors['postal_code']) ? ' has-error' : '' ?>">
                    <label for="postal_code">Postal code <span class="required-indicator">*</span></label>
                    <input type="text" id="postal_code" name="postal_code" value="<?= htmlspecialchars($values['postal_code']) ?>" autocomplete="postal-code" required>
                    <?php if (isset($errors['postal_code'])): ?>
                        <p class="field-error" role="alert"><?= htmlspecialchars($errors['postal_code']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="form-field<?= isset($errors['city']) ? ' has-error' : '' ?>">
                    <label for="city">City <span class="required-indicator">*</span></label>
                    <input type="text" id="city" name="city" value="<?= htmlspecialchars($values['city']) ?>" autocomplete="address-level2" required>
                    <?php if (isset($errors['city'])): ?>
                        <p class="field-error" role="alert"><?= htmlspecialchars($errors['city']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-field<?= isset($errors['password']) ? ' has-error' : '' ?>">
                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                    <button type="button" class="password-toggle" data-toggle-target="password" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <div class="form-field<?= isset($errors['password']) ? ' has-error' : '' ?>">
                <label for="confirm_password">Confirm password</label>
                <div class="password-field">
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    <button type="button" class="password-toggle" data-toggle-target="confirm_password" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <p class="field-error" role="alert"><?= htmlspecialchars($errors['password']) ?></p>
                <?php endif; ?>
            </div>
            <button type="submit" class="customer-auth-submit">Create account</button>
        </form>
        <p class="customer-auth-footer">Already have an account? <a href="<?= htmlspecialchars(orderingUrl('login.php')) ?>">Log in</a></p>
    </main>
    <script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
</body>
</html>
