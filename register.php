<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';
require __DIR__ . '/dgz_motorshop_system/includes/email.php';

$customerSession = getAuthenticatedCustomer();
if ($customerSession !== null) {
    header('Location: ' . orderingUrl('index.php'));
    exit;
}

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$logoAsset = assetUrl('assets/logo.png');
$customerSessionStatusEndpoint = orderingUrl('api/customer-session-status.php');
$loginUrl = orderingUrl('login.php');

$errors = [];
$successMessage = '';
$values = [
    'first_name' => trim($_POST['first_name'] ?? ''),
    'middle_name' => trim($_POST['middle_name'] ?? ''),
    'last_name' => trim($_POST['last_name'] ?? ''),
    'email' => trim($_POST['email'] ?? ''),
    'phone' => trim($_POST['phone'] ?? ''),
    'address' => trim($_POST['address'] ?? ''),
    'postal_code' => trim($_POST['postal_code'] ?? ''),
    'city' => trim($_POST['city'] ?? ''),
];

if (isset($_GET['success']) && $_GET['success'] === '1') {
    $successMessage = 'Thank you for signing up! Please check your email for a verification link to activate your account.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($values['first_name'] === '') {
        $errors['first_name'] = 'Please enter your first name.';
    }

    if ($values['last_name'] === '') {
        $errors['last_name'] = 'Please enter your last name.';
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
                $fullNameColumn = customerFindColumn($pdo, ['full_name', 'name']);
                $firstNameColumn = customerFindColumn($pdo, ['first_name', 'firstname', 'given_name']);
                $middleNameColumn = customerFindColumn($pdo, ['middle_name', 'middlename', 'middle']);
                $lastNameColumn = customerFindColumn($pdo, ['last_name', 'lastname', 'surname', 'family_name']);
                $verificationTokenColumn = customerFindColumn($pdo, ['verification_token', 'email_verification_token']);
                $emailVerifiedColumn = customerFindColumn($pdo, ['email_verified_at', 'verified_at']);

                $fullNameParts = [$values['first_name']];
                if ($values['middle_name'] !== '') {
                    $fullNameParts[] = $values['middle_name'];
                }
                $fullNameParts[] = $values['last_name'];
                $resolvedFullName = trim(implode(' ', array_filter($fullNameParts, static function (string $part): bool {
                    return trim($part) !== '';
                })));

                $columns = [];
                $placeholders = [];
                $insertValues = [];

                $emailColumn = customerFindColumn($pdo, ['email', 'email_address']);
                $phoneColumn = customerFindColumn($pdo, ['phone', 'mobile', 'contact_number', 'contact']);
                $passwordHashColumn = customerFindColumn($pdo, ['password_hash']);

                if ($emailColumn === null || $phoneColumn === null || $passwordHashColumn === null) {
                    throw new RuntimeException('Customer table is missing required columns.');
                }

                $pushColumn = static function (string $column, $value) use (&$columns, &$placeholders, &$insertValues): void {
                    $columns[] = $column;
                    $placeholders[] = '?';
                    $insertValues[] = $value;
                };

                $pushColumn($emailColumn, $values['email']);
                $pushColumn($phoneColumn, $values['phone']);
                $pushColumn($passwordHashColumn, $passwordHash);

                if ($fullNameColumn !== null) {
                    $pushColumn($fullNameColumn, $resolvedFullName);
                }

                if ($firstNameColumn !== null) {
                    $pushColumn($firstNameColumn, $values['first_name']);
                }

                if ($middleNameColumn !== null) {
                    $pushColumn($middleNameColumn, $values['middle_name'] !== '' ? $values['middle_name'] : null);
                }

                if ($lastNameColumn !== null) {
                    $pushColumn($lastNameColumn, $values['last_name']);
                }

                if ($addressColumn !== null) {
                    $pushColumn($addressColumn, $values['address']);
                }

                if ($postalColumn !== null) {
                    $pushColumn($postalColumn, $values['postal_code']);
                }

                if ($cityColumn !== null) {
                    $pushColumn($cityColumn, $values['city']);
                }

                try {
                    $verificationToken = bin2hex(random_bytes(32));
                } catch (Throwable $tokenException) {
                    $verificationToken = hash('sha256', uniqid('', true));
                }
                if ($verificationTokenColumn !== null) {
                    $pushColumn($verificationTokenColumn, $verificationToken);
                }

                if ($emailVerifiedColumn !== null) {
                    $pushColumn($emailVerifiedColumn, null);
                }

                $createdAtColumn = customerFindColumn($pdo, ['created_at']);
                if ($createdAtColumn !== null) {
                    $columns[] = $createdAtColumn;
                    $placeholders[] = 'NOW()';
                }

                $quotedColumns = array_map(static function (string $column): string {
                    return '`' . $column . '`';
                }, $columns);

                $sql = 'INSERT INTO customers (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($insertValues);
                $customerId = (int) $pdo->lastInsertId();
                customerPersistPasswordHash($pdo, $customerId, $passwordHash);

                $verificationLink = absoluteUrl(orderingUrl('verify_account.php?token=' . urlencode($verificationToken)));
                $emailSubject = 'Verify your DGZ Motorshop account';
                $emailBody = '<p>Hi ' . htmlspecialchars($values['first_name']) . ',</p>'
                    . '<p>Thanks for creating an account with DGZ Motorshop. Please confirm your email address to activate your account.</p>'
                    . '<p><a href="' . htmlspecialchars($verificationLink) . '" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">Verify email address</a></p>'
                    . '<p>If the button above does not work, copy and paste this link into your browser:<br>'
                    . '<a href="' . htmlspecialchars($verificationLink) . '">' . htmlspecialchars($verificationLink) . '</a></p>'
                    . '<p>If you did not create an account, you can ignore this email.</p>'
                    . '<p>– DGZ Motorshop</p>';

                if (!sendEmail($values['email'], $emailSubject, $emailBody)) {
                    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
                    $errors['general'] = 'We could not send the verification email. Please try again later.';
                } else {
                    $redirect = orderingUrl('register.php?success=1');
                    header('Location: ' . $redirect);
                    exit;
                }
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
<body class="customer-auth-page" data-customer-auth="register"
    data-customer-session-heartbeat="<?= htmlspecialchars($customerSessionStatusEndpoint) ?>"
    data-customer-login-url="<?= htmlspecialchars($loginUrl) ?>">
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
            <?php if ($successMessage !== ''): ?>
                <div class="customer-auth-alert customer-auth-alert--success" role="status"><?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
            <div class="form-split">
                <div class="form-field<?= isset($errors['first_name']) ? ' has-error' : '' ?>">
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($values['first_name']) ?>" required autocomplete="given-name">
                    <?php if (isset($errors['first_name'])): ?>
                        <p class="field-error" role="alert"><?= htmlspecialchars($errors['first_name']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="form-field<?= isset($errors['last_name']) ? ' has-error' : '' ?>">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($values['last_name']) ?>" required autocomplete="family-name">
                    <?php if (isset($errors['last_name'])): ?>
                        <p class="field-error" role="alert"><?= htmlspecialchars($errors['last_name']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-field">
                <label for="middle_name">Middle name <span class="optional">(optional)</span></label>
                <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($values['middle_name']) ?>" autocomplete="additional-name">
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
