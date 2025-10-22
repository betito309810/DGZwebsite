<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

requireCustomerAuthentication();
$customer = getAuthenticatedCustomer();
$pdo = db();

$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerStylesheet = assetUrl('assets/css/public/customer.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$logoAsset = assetUrl('assets/logo.png');
$homeUrl = orderingUrl('index.php');
$myOrdersUrl = orderingUrl('my_orders.php');
$logoutUrl = orderingUrl('logout.php');

$values = [
    'email' => trim((string)($_POST['email'] ?? ($customer['email'] ?? ''))),
    'phone' => trim((string)($_POST['phone'] ?? ($customer['phone'] ?? ''))),
    'current_password' => '',
    'new_password' => '',
    'confirm_password' => '',
];

$errors = [];
$success = [];

if (!function_exists('settingsVerifyPassword')) {
    function settingsVerifyPassword(PDO $pdo, int $customerId, string $currentHash, string $password): bool {
        if ($currentHash !== '' && password_verify($password, $currentHash)) {
            return true;
        }
        $legacy = customerFetchLegacyPassword($pdo, $customerId);
        if ($legacy === null || $legacy === '') {
            return false;
        }
        if (password_verify($password, $legacy)) { return true; }
        foreach ([$password, hash('sha256', $password), hash('sha1', $password), md5($password)] as $candidate) {
            if (hash_equals((string)$legacy, (string)$candidate)) { return true; }
        }
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email.';
        }
        // Normalize phone a bit
        $normalizedPhone = normalizeCustomerPhone($values['phone']);
        if ($values['phone'] !== '' && $normalizedPhone === '') {
            $errors['phone'] = 'Please enter a valid phone number.';
        } else {
            $values['phone'] = $normalizedPhone;
        }

        if (!$errors) {
            try {
                $updates = [];
                $params = [];
                if ($values['email'] !== (string)($customer['email'] ?? '')) { $updates[] = 'email = ?'; $params[] = $values['email'] !== '' ? $values['email'] : null; }
                if ($values['phone'] !== (string)($customer['phone'] ?? '')) { $updates[] = 'phone = ?'; $params[] = $values['phone'] !== '' ? $values['phone'] : null; }
                if ($updates) {
                    $params[] = (int)$customer['id'];
                    $sql = 'UPDATE customers SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    customerSessionRefresh();
                    $customer = getAuthenticatedCustomer();
                }
                $success[] = 'Profile updated successfully.';
            } catch (Throwable $e) {
                error_log('Unable to update customer profile: ' . $e->getMessage());
                $errors['general'] = 'Something went wrong updating your profile.';
            }
        }
    } elseif ($action === 'password') {
        $values['current_password'] = (string)($_POST['current_password'] ?? '');
        $values['new_password'] = (string)($_POST['new_password'] ?? '');
        $values['confirm_password'] = (string)($_POST['confirm_password'] ?? '');

        if ($values['new_password'] === '' || strlen($values['new_password']) < 6) {
            $errors['new_password'] = 'Use at least 6 characters.';
        } elseif ($values['new_password'] !== $values['confirm_password']) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        if (!$errors) {
            try {
                // Load stored password hash/legacy and verify
                $stmt = $pdo->prepare('SELECT id, password_hash FROM customers WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$customer['id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $canLogin = settingsVerifyPassword($pdo, (int)$customer['id'], (string)($row['password_hash'] ?? ''), $values['current_password']);
                if (!$canLogin) {
                    $errors['current_password'] = 'Current password is incorrect.';
                } else {
                    $newHash = password_hash($values['new_password'], PASSWORD_DEFAULT);
                    customerPersistPasswordHash($pdo, (int)$customer['id'], $newHash);
                    $success[] = 'Password updated successfully.';
                }
            } catch (Throwable $e) {
                error_log('Unable to change customer password: ' . $e->getMessage());
                $errors['general'] = 'Unable to change your password right now.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en" data-customer-session="authenticated">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Settings - DGZ Motorshop</title>
    <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
    <style>
        .settings-wrapper { max-width: 880px; margin: 2rem auto; padding: 0 1.5rem 3rem; }
        .settings-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        .settings-card { background: #fff; border-radius: 1rem; padding: 1.25rem 1.5rem; box-shadow: 0 20px 45px rgba(15,23,42,.08); }
        .settings-card h2 { margin: 0 0 1rem; font-size: 1.2rem; }
        .settings-actions { margin-top: .75rem; display: flex; gap: .5rem; }
        .settings-submit { background: #2563eb; color: #fff; border: 0; border-radius: .7rem; padding: .6rem 1rem; font-weight: 600; cursor: pointer; }
    </style>
    </head>
<body class="customer-orders-page" data-customer-session="authenticated">
<header class="customer-orders-header">
    <div class="customer-orders-brand">
        <a href="<?= htmlspecialchars($homeUrl) ?>" class="customer-orders-logo">
            <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop logo">
        </a>
    </div>
    <div class="customer-orders-actions">
        <a href="<?= htmlspecialchars($homeUrl) ?>" class="customer-orders-continue">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            Continue Shopping
        </a>
        <div class="account-menu" data-account-menu>
            <button type="button" class="account-menu__trigger" data-account-trigger aria-haspopup="true" aria-expanded="false">
                <span class="account-menu__avatar" aria-hidden="true"><i class="fas fa-user-circle"></i></span>
                <span class="account-menu__label">Settings</span>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="account-menu__dropdown" data-account-dropdown hidden>
                <a href="<?= htmlspecialchars($myOrdersUrl) ?>" class="account-menu__link">My Orders</a>
                <a href="<?= htmlspecialchars($logoutUrl) ?>" class="account-menu__link">Logout</a>
            </div>
        </div>
    </div>
    </header>
<main class="settings-wrapper">
    <?php foreach ($success as $msg): ?>
        <div class="customer-orders-alert customer-orders-alert--success" role="alert"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php if (!empty($errors['general'])): ?>
        <div class="customer-orders-alert customer-orders-alert--error" role="alert"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>
    <div class="settings-grid">
        <section class="settings-card">
            <h2>Contact details</h2>
            <form method="post">
                <input type="hidden" name="action" value="profile">
                <div class="form-field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($values['email']) ?>">
                    <?php if (!empty($errors['email'])): ?><p class="field-error"><?= htmlspecialchars($errors['email']) ?></p><?php endif; ?>
                </div>
                <div class="form-field">
                    <label for="phone">Mobile number</label>
                    <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($values['phone']) ?>">
                    <?php if (!empty($errors['phone'])): ?><p class="field-error"><?= htmlspecialchars($errors['phone']) ?></p><?php endif; ?>
                </div>
                <div class="settings-actions"><button class="settings-submit" type="submit">Save</button></div>
            </form>
        </section>
        <section class="settings-card">
            <h2>Change password</h2>
            <form method="post">
                <input type="hidden" name="action" value="password">
                <div class="form-field">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password">
                    <?php if (!empty($errors['current_password'])): ?><p class="field-error"><?= htmlspecialchars($errors['current_password']) ?></p><?php endif; ?>
                </div>
                <div class="form-field">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password">
                    <?php if (!empty($errors['new_password'])): ?><p class="field-error"><?= htmlspecialchars($errors['new_password']) ?></p><?php endif; ?>
                </div>
                <div class="form-field">
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password">
                    <?php if (!empty($errors['confirm_password'])): ?><p class="field-error"><?= htmlspecialchars($errors['confirm_password']) ?></p><?php endif; ?>
                </div>
                <div class="settings-actions"><button class="settings-submit" type="submit">Update Password</button></div>
            </form>
        </section>
    </div>
</main>
<script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
</body>
</html>
