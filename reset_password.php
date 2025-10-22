<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$logoAsset = assetUrl('assets/logo.png');

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$errors = [];
$success = false;
$pdo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['token'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($token === '') {
        $errors['token'] = 'The reset link is invalid or has expired.';
    }
    if ($password === '' || strlen($password) < 8) {
        $errors['password'] = 'Choose a password with at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors['password'] = 'Passwords do not match.';
    }

    if ($errors === []) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT customer_id FROM customer_password_resets WHERE token = ? AND expires_at >= NOW() LIMIT 1');
            $stmt->execute([$token]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                $errors['token'] = 'The reset link is invalid or has expired.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->beginTransaction();
                $update = $pdo->prepare('UPDATE customers SET password_hash = ? WHERE id = ?');
                $update->execute([$passwordHash, (int) $record['customer_id']]);
                $delete = $pdo->prepare('DELETE FROM customer_password_resets WHERE customer_id = ?');
                $delete->execute([(int) $record['customer_id']]);
                $pdo->commit();
                $success = true;
            }
        } catch (Throwable $exception) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Unable to reset customer password: ' . $exception->getMessage());
            $errors['general'] = 'We could not update your password. Please try again later.';
        }
    }
}
?>
<!doctype html>
<html lang="en" data-customer-session="guest">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set a new DGZ Motorshop password</title>
    <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
</head>
<body class="customer-auth-page" data-customer-auth="reset">
    <main class="customer-auth-card" aria-labelledby="resetHeading">
        <a href="<?= htmlspecialchars(orderingUrl('index.php')) ?>" class="customer-auth-logo">
            <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop logo">
        </a>
        <h1 id="resetHeading">Set a new password</h1>
        <?php if ($success): ?>
            <p class="customer-auth-subtitle">Your password has been updated. You can now <a href="<?= htmlspecialchars(orderingUrl('login.php')) ?>">log in</a>.</p>
        <?php else: ?>
            <p class="customer-auth-subtitle">Create a strong password to protect your account.</p>
            <?php if (!empty($errors['general'])): ?>
                <div class="customer-auth-alert" role="alert"><?= htmlspecialchars($errors['general']) ?></div>
            <?php endif; ?>
            <form class="customer-auth-form" method="post" novalidate data-customer-form>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <?php if (isset($errors['token'])): ?>
                    <p class="field-error" role="alert"><?= htmlspecialchars($errors['token']) ?></p>
                <?php endif; ?>
                <div class="form-field<?= isset($errors['password']) ? ' has-error' : '' ?>">
                    <label for="password">New password</label>
                    <div class="password-field">
                        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" data-toggle-target="password" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="form-field<?= isset($errors['password']) ? ' has-error' : '' ?>">
                    <label for="confirm_password">Confirm new password</label>
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
                <button type="submit" class="customer-auth-submit">Update password</button>
            </form>
        <?php endif; ?>
    </main>
    <script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
</body>
</html>
