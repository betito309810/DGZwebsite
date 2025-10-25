<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$logoAsset = assetUrl('assets/logo.png');
$customerSessionStatusEndpoint = orderingUrl('api/customer-session-status.php');
$customerSessionHeartbeatInterval = 5000;
$loginUrl = orderingUrl('login.php');

$message = null;
$error = null;
$identifier = trim($_POST['identifier'] ?? '');
$pdo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($identifier === '') {
        $error = 'Enter the email or phone number tied to your account.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id, email, phone FROM customers WHERE email = ? LIMIT 1');
            $customer = null;
            if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                $stmt->execute([$identifier]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($customer === null) {
                $stmt = $pdo->prepare('SELECT id, email, phone FROM customers WHERE phone = ? LIMIT 1');
                $stmt->execute([$identifier]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($customer) {
                $token = bin2hex(random_bytes(32));
                $contact = $customer['email'] ?? $customer['phone'];

                $pdo->prepare('DELETE FROM customer_password_resets WHERE customer_id = ?')->execute([(int) $customer['id']]);
                $insert = $pdo->prepare('INSERT INTO customer_password_resets (customer_id, token, contact, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())');
                $insert->execute([(int) $customer['id'], $token, $contact]);

                $resetUrl = absoluteUrl(orderingUrl('reset_password.php?token=' . urlencode($token)));

                $message = 'If your account exists, you\'ll receive password reset instructions shortly.';
                $message .= ' <a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Reset your password now</a>.';
            } else {
                $message = 'If your account exists, you\'ll receive password reset instructions shortly.';
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Unable to create customer reset token: ' . $exception->getMessage());
            $error = 'We hit a snag generating your reset link. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en" data-customer-session="guest">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your DGZ Motorshop password</title>
    <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
</head>
<body class="customer-auth-page" data-customer-auth="forgot"
    data-customer-session-heartbeat="<?= htmlspecialchars($customerSessionStatusEndpoint) ?>"
    data-customer-session-heartbeat-interval="<?= (int) $customerSessionHeartbeatInterval ?>"
    data-customer-login-url="<?= htmlspecialchars($loginUrl) ?>">
    <main class="customer-auth-card" aria-labelledby="forgotHeading">
        <a href="<?= htmlspecialchars(orderingUrl('index.php')) ?>" class="customer-auth-logo">
            <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop logo">
        </a>
        <h1 id="forgotHeading">Forgot password</h1>
        <p class="customer-auth-subtitle">Enter your registered email or phone number to receive a reset link.</p>
        <?php if ($message): ?>
            <div class="customer-auth-alert" role="alert"><?= $message ?></div>
        <?php elseif ($error): ?>
            <div class="customer-auth-alert" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form class="customer-auth-form" method="post" novalidate data-customer-form>
            <div class="form-field">
                <label for="identifier">Email or phone number</label>
                <input type="text" id="identifier" name="identifier" value="<?= htmlspecialchars($identifier) ?>" required>
            </div>
            <button type="submit" class="customer-auth-submit">Send reset link</button>
        </form>
        <p class="customer-auth-footer"><a href="<?= htmlspecialchars($loginUrl) ?>">Back to login</a></p>
    </main>
    <script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
</body>
</html>
