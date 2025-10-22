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
    'identifier' => trim($_POST['identifier'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    if ($values['identifier'] === '') {
        $errors['identifier'] = 'Enter the email or phone number associated with your account.';
    }
    if ($password === '') {
        $errors['password'] = 'Please enter your password.';
    }

    if ($errors === []) {
        try {
            $pdo = db();
            $candidate = null;
            $stmt = $pdo->prepare('SELECT id, full_name, email, phone, password_hash FROM customers WHERE email = ? LIMIT 1');
            if (filter_var($values['identifier'], FILTER_VALIDATE_EMAIL)) {
                $stmt->execute([$values['identifier']]);
                $candidate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($candidate === null) {
                $stmt = $pdo->prepare('SELECT id, full_name, email, phone, password_hash FROM customers WHERE phone = ? LIMIT 1');
                $stmt->execute([$values['identifier']]);
                $candidate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if (!$candidate || empty($candidate['password_hash']) || !password_verify($password, (string) $candidate['password_hash'])) {
                $errors['general'] = 'We could not find a matching account. Double check your details and try again.';
            } else {
                customerLogin((int) $candidate['id']);
                $redirect = $_POST['redirect'] ?? ($_GET['redirect'] ?? orderingUrl('my_orders.php'));
                if (!is_string($redirect) || $redirect === '') {
                    $redirect = orderingUrl('my_orders.php');
                }
                header('Location: ' . $redirect);
                exit;
            }
        } catch (Throwable $exception) {
            error_log('Unable to log in customer: ' . $exception->getMessage());
            $errors['general'] = 'Something went wrong while signing you in. Please try again later.';
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
    <title>Log in to DGZ Motorshop</title>
    <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
</head>
<body class="customer-auth-page" data-customer-auth="login">
    <main class="customer-auth-card" aria-labelledby="loginHeading">
        <a href="<?= htmlspecialchars(orderingUrl('index.php')) ?>" class="customer-auth-logo">
            <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop logo">
        </a>
        <h1 id="loginHeading">Welcome back</h1>
        <p class="customer-auth-subtitle">Access your saved details and track recent orders.</p>
        <?php if (!empty($errors['general'])): ?>
            <div class="customer-auth-alert" role="alert"><?= htmlspecialchars($errors['general']) ?></div>
        <?php endif; ?>
        <form class="customer-auth-form" method="post" novalidate data-customer-form>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectParam) ?>">
            <div class="form-field<?= isset($errors['identifier']) ? ' has-error' : '' ?>">
                <label for="identifier">Email or phone number</label>
                <input type="text" id="identifier" name="identifier" value="<?= htmlspecialchars($values['identifier']) ?>" required autocomplete="email">
                <?php if (isset($errors['identifier'])): ?>
                    <p class="field-error" role="alert"><?= htmlspecialchars($errors['identifier']) ?></p>
                <?php endif; ?>
            </div>
            <div class="form-field<?= isset($errors['password']) ? ' has-error' : '' ?>">
                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    <button type="button" class="password-toggle" data-toggle-target="password" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <p class="field-error" role="alert"><?= htmlspecialchars($errors['password']) ?></p>
                <?php endif; ?>
            </div>
            <button type="submit" class="customer-auth-submit">Log in</button>
        </form>
        <div class="customer-auth-footer">
            <a href="<?= htmlspecialchars(orderingUrl('forgot_password.php')) ?>">Forgot password?</a>
            <span>·</span>
            <a href="<?= htmlspecialchars(orderingUrl('register.php')) ?>">Create an account</a>
        </div>
    </main>
    <script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
</body>
</html>
