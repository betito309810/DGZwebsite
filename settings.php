<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

requireCustomerAuthentication();
$customer = getAuthenticatedCustomer();
$pdo = db();

$fullNameColumn = customerFindColumn($pdo, ['full_name', 'name']);
$firstNameColumn = customerFindColumn($pdo, ['first_name', 'firstname', 'given_name']);
$middleNameColumn = customerFindColumn($pdo, ['middle_name', 'middlename', 'middle']);
$lastNameColumn = customerFindColumn($pdo, ['last_name', 'lastname', 'surname', 'family_name']);
$addressColumn = customerFindColumn($pdo, ['address_line1', 'address', 'address1', 'street']);
$cityColumn = customerFindColumn($pdo, ['city', 'town', 'municipality']);
$postalColumn = customerFindColumn($pdo, ['postal_code', 'postal', 'zip_code', 'zipcode', 'zip']);

$currentFirstName = trim((string)($customer['first_name'] ?? ''));
if ($currentFirstName === '' && $firstNameColumn !== null) {
    $currentFirstName = trim((string)($customer[$firstNameColumn] ?? ''));
}

$currentMiddleName = trim((string)($customer['middle_name'] ?? ''));
if ($currentMiddleName === '' && $middleNameColumn !== null) {
    $currentMiddleName = trim((string)($customer[$middleNameColumn] ?? ''));
}

$currentLastName = trim((string)($customer['last_name'] ?? ''));
if ($currentLastName === '' && $lastNameColumn !== null) {
    $currentLastName = trim((string)($customer[$lastNameColumn] ?? ''));
}

$currentFullName = trim((string)($customer['full_name'] ?? ''));
if ($currentFullName === '' && $fullNameColumn !== null) {
    $currentFullName = trim((string)($customer[$fullNameColumn] ?? ''));
}

$addressSources = array_values(array_unique(array_filter([$addressColumn, 'address_line1', 'address', 'address1', 'street'])));
$currentAddress = '';
foreach ($addressSources as $alias) {
    if (isset($customer[$alias])) {
        $candidate = trim((string)$customer[$alias]);
        if ($candidate !== '') {
            $currentAddress = $candidate;
            break;
        }
    }
}

$postalSources = array_values(array_unique(array_filter([$postalColumn, 'postal_code', 'postal', 'zip_code', 'zipcode', 'zip'])));
$currentPostal = '';
foreach ($postalSources as $alias) {
    if (isset($customer[$alias])) {
        $candidate = trim((string)$customer[$alias]);
        if ($candidate !== '') {
            $currentPostal = $candidate;
            break;
        }
    }
}

$citySources = array_values(array_unique(array_filter([$cityColumn, 'city', 'town', 'municipality'])));
$currentCity = '';
foreach ($citySources as $alias) {
    if (isset($customer[$alias])) {
        $candidate = trim((string)$customer[$alias]);
        if ($candidate !== '') {
            $currentCity = $candidate;
            break;
        }
    }
}

$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerStylesheet = assetUrl('assets/css/public/customer.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$logoAsset = assetUrl('assets/logo.png');
$homeUrl = orderingUrl('index.php');
$loginUrl = orderingUrl('login.php');
$myOrdersUrl = orderingUrl('my_orders.php');
$logoutUrl = orderingUrl('logout.php');
$customerSessionStatusEndpoint = orderingUrl('api/customer-session-status.php');
$customerSessionHeartbeatInterval = 5000;

$values = [
    'first_name' => trim((string)($_POST['first_name'] ?? $currentFirstName)),
    'middle_name' => trim((string)($_POST['middle_name'] ?? $currentMiddleName)),
    'last_name' => trim((string)($_POST['last_name'] ?? $currentLastName)),
    'email' => trim((string)($_POST['email'] ?? ($customer['email'] ?? ''))),
    'phone' => trim((string)($_POST['phone'] ?? ($customer['phone'] ?? ''))),
    'address' => trim((string)($_POST['address'] ?? $currentAddress)),
    'postal_code' => trim((string)($_POST['postal_code'] ?? $currentPostal)),
    'city' => trim((string)($_POST['city'] ?? $currentCity)),
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
        if ($values['first_name'] === '') {
            $errors['first_name'] = 'Please enter your first name.';
        }

        if ($values['last_name'] === '') {
            $errors['last_name'] = 'Please enter your last name.';
        }

        if ($values['email'] === '') {
            $errors['email'] = 'Please enter your email.';
        } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email.';
        }
        // Normalize phone a bit
        $normalizedPhone = normalizeCustomerPhone($values['phone']);
        if ($values['phone'] === '') {
            $errors['phone'] = 'Please enter your phone number.';
        } elseif ($normalizedPhone === '') {
            $errors['phone'] = 'Please enter a valid phone number.';
        } else {
            $values['phone'] = $normalizedPhone;
        }

        if ($values['address'] === '') {
            $errors['address'] = 'Please enter your address.';
        }

        if ($values['postal_code'] === '') {
            $errors['postal_code'] = 'Please enter your postal code.';
        }

        if ($values['city'] === '') {
            $errors['city'] = 'Please enter your city.';
        }

        if (!$errors) {
            try {
                $updates = [];
                $params = [];
                $fullNameParts = [$values['first_name']];
                if ($values['middle_name'] !== '') {
                    $fullNameParts[] = $values['middle_name'];
                }
                $fullNameParts[] = $values['last_name'];
                $resolvedFullName = trim(implode(' ', array_filter($fullNameParts, static function (string $part): bool {
                    return trim($part) !== '';
                })));

                if ($firstNameColumn !== null && $values['first_name'] !== $currentFirstName) {
                    $updates[] = "`$firstNameColumn` = ?";
                    $params[] = $values['first_name'];
                }
                if ($middleNameColumn !== null && $values['middle_name'] !== $currentMiddleName) {
                    $updates[] = "`$middleNameColumn` = ?";
                    $params[] = $values['middle_name'] !== '' ? $values['middle_name'] : null;
                }
                if ($lastNameColumn !== null && $values['last_name'] !== $currentLastName) {
                    $updates[] = "`$lastNameColumn` = ?";
                    $params[] = $values['last_name'];
                }
                if ($fullNameColumn !== null && $resolvedFullName !== '' && $resolvedFullName !== $currentFullName) {
                    $updates[] = "`$fullNameColumn` = ?";
                    $params[] = $resolvedFullName;
                }
                if ($values['email'] !== (string)($customer['email'] ?? '')) { $updates[] = 'email = ?'; $params[] = $values['email']; }
                if ($values['phone'] !== (string)($customer['phone'] ?? '')) { $updates[] = 'phone = ?'; $params[] = $values['phone']; }
                if ($addressColumn !== null && $values['address'] !== $currentAddress) { $updates[] = "`$addressColumn` = ?"; $params[] = $values['address']; }
                if ($postalColumn !== null && $values['postal_code'] !== $currentPostal) { $updates[] = "`$postalColumn` = ?"; $params[] = $values['postal_code']; }
                if ($cityColumn !== null && $values['city'] !== $currentCity) { $updates[] = "`$cityColumn` = ?"; $params[] = $values['city']; }
                if ($updates) {
                    $params[] = (int)$customer['id'];
                    $sql = 'UPDATE customers SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    customerSessionRefresh();
                    $customer = getAuthenticatedCustomer();
                    $currentFirstName = trim((string)($customer['first_name'] ?? ''));
                    if ($currentFirstName === '' && $firstNameColumn !== null) {
                        $currentFirstName = trim((string)($customer[$firstNameColumn] ?? ''));
                    }
                    $currentMiddleName = trim((string)($customer['middle_name'] ?? ''));
                    if ($currentMiddleName === '' && $middleNameColumn !== null) {
                        $currentMiddleName = trim((string)($customer[$middleNameColumn] ?? ''));
                    }
                    $currentLastName = trim((string)($customer['last_name'] ?? ''));
                    if ($currentLastName === '' && $lastNameColumn !== null) {
                        $currentLastName = trim((string)($customer[$lastNameColumn] ?? ''));
                    }
                    $currentFullName = trim((string)($customer['full_name'] ?? ''));
                    if ($currentFullName === '' && $fullNameColumn !== null) {
                        $currentFullName = trim((string)($customer[$fullNameColumn] ?? ''));
                    }
                    $addressSources = array_values(array_unique(array_filter([$addressColumn, 'address_line1', 'address', 'address1', 'street'])));
                    foreach ($addressSources as $alias) {
                        if (isset($customer[$alias])) {
                            $candidate = trim((string)$customer[$alias]);
                            if ($candidate !== '') {
                                $currentAddress = $candidate;
                                break;
                            }
                        }
                    }
                    $postalSources = array_values(array_unique(array_filter([$postalColumn, 'postal_code', 'postal', 'zip_code', 'zipcode', 'zip'])));
                    foreach ($postalSources as $alias) {
                        if (isset($customer[$alias])) {
                            $candidate = trim((string)$customer[$alias]);
                            if ($candidate !== '') {
                                $currentPostal = $candidate;
                                break;
                            }
                        }
                    }
                    $citySources = array_values(array_unique(array_filter([$cityColumn, 'city', 'town', 'municipality'])));
                    foreach ($citySources as $alias) {
                        if (isset($customer[$alias])) {
                            $candidate = trim((string)$customer[$alias]);
                            if ($candidate !== '') {
                                $currentCity = $candidate;
                                break;
                            }
                        }
                    }
                    $values['email'] = trim((string)($customer['email'] ?? $values['email']));
                    $values['phone'] = trim((string)($customer['phone'] ?? $values['phone']));
                    $values['first_name'] = $currentFirstName;
                    $values['middle_name'] = $currentMiddleName;
                    $values['last_name'] = $currentLastName;
                    $values['address'] = $currentAddress;
                    $values['postal_code'] = $currentPostal;
                    $values['city'] = $currentCity;
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
    </head>
<body class="customer-orders-page" data-customer-session="authenticated"
    data-customer-session-heartbeat="<?= htmlspecialchars($customerSessionStatusEndpoint) ?>"
    data-customer-session-heartbeat-interval="<?= (int) $customerSessionHeartbeatInterval ?>"
    data-customer-login-url="<?= htmlspecialchars($loginUrl) ?>">
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
<main class="account-settings">
    <?php foreach ($success as $msg): ?>
        <div class="customer-orders-alert customer-orders-alert--success" role="alert"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php if (!empty($errors['general'])): ?>
        <div class="customer-orders-alert customer-orders-alert--error" role="alert"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>
    <div class="account-settings__stack">
        <form method="post" class="account-settings__profile">
            <input type="hidden" name="action" value="profile">
            <section class="account-settings__card account-settings__card--contact">
                <h2 class="account-settings__title">
                    <i class="fas fa-user" aria-hidden="true"></i>
                    Contact
                </h2>
                <div class="form-split">
                    <div class="form-field<?= !empty($errors['first_name']) ? ' has-error' : '' ?>">
                        <label for="first_name">First name <span class="required-indicator">*</span></label>
                        <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($values['first_name']) ?>" required autocomplete="given-name">
                        <?php if (!empty($errors['first_name'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['first_name']) ?></p><?php endif; ?>
                    </div>
                    <div class="form-field<?= !empty($errors['last_name']) ? ' has-error' : '' ?>">
                        <label for="last_name">Last name <span class="required-indicator">*</span></label>
                        <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($values['last_name']) ?>" required autocomplete="family-name">
                        <?php if (!empty($errors['last_name'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['last_name']) ?></p><?php endif; ?>
                    </div>
                </div>
                <div class="form-field">
                    <label for="middle_name">Middle name <span class="optional">(optional)</span></label>
                    <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($values['middle_name']) ?>" autocomplete="additional-name">
                </div>
                <div class="form-field<?= !empty($errors['email']) ? ' has-error' : '' ?>">
                    <label for="email">Email <span class="required-indicator">*</span></label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" required autocomplete="email">
                    <?php if (!empty($errors['email'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['email']) ?></p><?php endif; ?>
                </div>
                <div class="form-field<?= !empty($errors['phone']) ? ' has-error' : '' ?>">
                    <label for="phone">Mobile number <span class="required-indicator">*</span></label>
                    <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($values['phone']) ?>" required autocomplete="tel" inputmode="tel">
                    <?php if (!empty($errors['phone'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['phone']) ?></p><?php endif; ?>
                </div>
                <div class="form-actions account-settings__actions account-settings__actions--contact">
                    <button class="form-action-button form-action-button--primary" type="submit">Save</button>
                    <button class="form-action-button form-action-button--secondary" type="reset">Cancel</button>
                </div>
            </section>
            <section class="account-settings__card account-settings__card--billing">
                <h2 class="account-settings__title">
                    <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                    Billing Address
                </h2>
                <div class="form-field<?= !empty($errors['address']) ? ' has-error' : '' ?>">
                    <label for="address">Address <span class="required-indicator">*</span></label>
                    <textarea id="address" name="address" rows="3" required autocomplete="street-address"><?= htmlspecialchars($values['address']) ?></textarea>
                    <?php if (!empty($errors['address'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['address']) ?></p><?php endif; ?>
                </div>
                <div class="form-split">
                    <div class="form-field<?= !empty($errors['postal_code']) ? ' has-error' : '' ?>">
                        <label for="postal_code">Postal code <span class="required-indicator">*</span></label>
                        <input type="text" id="postal_code" name="postal_code" value="<?= htmlspecialchars($values['postal_code']) ?>" required autocomplete="postal-code">
                        <?php if (!empty($errors['postal_code'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['postal_code']) ?></p><?php endif; ?>
                    </div>
                    <div class="form-field<?= !empty($errors['city']) ? ' has-error' : '' ?>">
                        <label for="city">City <span class="required-indicator">*</span></label>
                        <input type="text" id="city" name="city" value="<?= htmlspecialchars($values['city']) ?>" required autocomplete="address-level2">
                        <?php if (!empty($errors['city'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['city']) ?></p><?php endif; ?>
                    </div>
                </div>
                <div class="form-actions account-settings__actions account-settings__actions--billing">
                    <button class="form-action-button form-action-button--primary" type="submit">Save</button>
                    <button class="form-action-button form-action-button--secondary" type="reset">Cancel</button>
                </div>
            </section>
        </form>
        <section class="account-settings__card account-settings__card--password">
            <h2 class="account-settings__title">
                <i class="fas fa-lock" aria-hidden="true"></i>
                Change Password
            </h2>
            <form method="post" class="account-settings__password-form">
                <input type="hidden" name="action" value="password">
                <div class="form-field<?= !empty($errors['current_password']) ? ' has-error' : '' ?>">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                    <?php if (!empty($errors['current_password'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['current_password']) ?></p><?php endif; ?>
                </div>
                <div class="form-field<?= !empty($errors['new_password']) ? ' has-error' : '' ?>">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password">
                    <?php if (!empty($errors['new_password'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['new_password']) ?></p><?php endif; ?>
                </div>
                <div class="form-field<?= !empty($errors['confirm_password']) ? ' has-error' : '' ?>">
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
                    <?php if (!empty($errors['confirm_password'])): ?><p class="field-error" role="alert"><?= htmlspecialchars($errors['confirm_password']) ?></p><?php endif; ?>
                </div>
                <div class="form-actions account-settings__actions">
                    <button class="form-action-button form-action-button--primary" type="submit">Update Password</button>
                </div>
            </form>
        </section>
    </div>
</main>
<script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
</body>
</html>
