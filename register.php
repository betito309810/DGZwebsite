<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

$customerSession = getAuthenticatedCustomer();
if ($customerSession !== null) {
    header('Location: ' . orderingUrl('my_orders.php'));
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
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($values['full_name'] === '') {
        $errors['full_name'] = 'Please enter your full name.';
    }

    if ($values['email'] === '' && $values['phone'] === '') {
        $errors['contact'] = 'Provide either your email address or phone number so we can reach you.';
    }

    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($values['phone'] !== '') {
        $normalizedPhone = normalizeCustomerPhone($values['phone']);
        if ($normalizedPhone === '') {
            $errors['phone'] = 'Please enter a valid phone number.';
        } else {
            $values['phone'] = $normalizedPhone;
        }
    }

    if ($password === '' || strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $errors['password'] = 'Passwords do not match.';
    }

    if ($errors === []) {
        try {
            $pdo = db();
            if ($values['email'] !== '') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE email = ?');
                $stmt->execute([$values['email']]);
                if ($stmt->fetchColumn() > 0) {
                    $errors['email'] = 'An account with this email already exists.';
                }
            }

            if ($values['phone'] !== '') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE phone = ?');
                $stmt->execute([$values['phone']]);
                if ($stmt->fetchColumn() > 0) {
                    $errors['phone'] = 'An account with this phone number already exists.';
                }
            }

            if ($errors === []) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO customers (full_name, email, phone, password_hash, created_at) VALUES (?, ?, ?, ?, NOW())');
                $stmt->execute([
                    $values['full_name'],
                    $values['email'] !== '' ? $values['email'] : null,
                    $values['phone'] !== '' ? $values['phone'] : null,
                    $passwordHash,
                ]);
                $customerId = (int) $pdo->lastInsertId();
                customerPersistPasswordHash($pdo, $customerId, $passwordHash);
                customerLogin($customerId);

                $redirect = $_GET['redirect'] ?? orderingUrl('my_orders.php');
                if (!is_string($redirect) || $redirect === '') {
                    $redirect = orderingUrl('my_orders.php');
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
                    <label for="email">Email address <span class="optional">Optional*</span></label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" autocomplete="email">
                    <?php if (isset($errors['email'])): ?>
                        <p class="field-error" role="alert"><?= htmlspecialchars($errors['email']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="form-field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
                    <label for="phone">Phone number <span class="optional">Optional*</span></label>
                    <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($values['phone']) ?>" autocomplete="tel">
                    <?php if (isset($errors['phone'])): ?>
                        <p class="field-error" role="alert"><?= htmlspecialchars($errors['phone']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (isset($errors['contact'])): ?>
                <p class="field-error" role="alert"><?= htmlspecialchars($errors['contact']) ?></p>
            <?php endif; ?>
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
