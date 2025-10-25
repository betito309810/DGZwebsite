<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

function upgradeCustomerPassword(PDO $pdo, int $customerId, string $password): void
{
    try {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        customerPersistPasswordHash($pdo, $customerId, $newHash);
    } catch (Throwable $exception) {
        error_log('Unable to upgrade customer password hash: ' . $exception->getMessage());
    }
}

function verifyCustomerPassword(PDO $pdo, array $candidate, string $password): bool
{
    $customerId = (int) ($candidate['id'] ?? 0);
    if ($customerId <= 0) {
        return false;
    }

    $storedHash = (string) ($candidate['password_hash'] ?? '');
    if ($storedHash !== '') {
        if (password_verify($password, $storedHash)) {
            if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                upgradeCustomerPassword($pdo, $customerId, $password);
            }

            return true;
        }
    }

    $legacySecret = customerFetchLegacyPassword($pdo, $customerId);
    if ($legacySecret === null || $legacySecret === '') {
        return false;
    }

    if (password_verify($password, $legacySecret)) {
        upgradeCustomerPassword($pdo, $customerId, $password);
        return true;
    }

    $legacyCandidates = [
        $password,
        hash('sha256', $password),
        hash('sha1', $password),
        md5($password),
    ];

    foreach ($legacyCandidates as $legacyCandidate) {
        if (hash_equals((string) $legacySecret, (string) $legacyCandidate)) {
            upgradeCustomerPassword($pdo, $customerId, $password);
            return true;
        }
    }

    return false;
}

$customerSession = getAuthenticatedCustomer();
if ($customerSession !== null) {
    header('Location: ' . orderingUrl('index.php'));
    exit;
}

$forcedLogoutMessage = null;
if (!empty($_SESSION['customer_forced_logout'])) {
    $forcedLogoutMessage = (string) ($_SESSION['customer_forced_logout_message'] ?? 'You’ve been logged out. Please sign in again.');
    unset($_SESSION['customer_forced_logout'], $_SESSION['customer_forced_logout_message']);
}

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$logoAsset = assetUrl('assets/logo.png');
$customerSessionStatusEndpoint = orderingUrl('api/customer-session-status.php');
$customerSessionHeartbeatInterval = 5000;
$loginPageUrl = orderingUrl('login.php');

$errors = [];
$values = [
    'identifier' => trim($_POST['identifier'] ?? ''),
];

if ($forcedLogoutMessage !== null) {
    $errors['general'] = $forcedLogoutMessage;
}

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
            $identifier = $values['identifier'];

            $fullNameColumn = customerFindColumn($pdo, ['full_name', 'name']) ?? 'full_name';
            $emailColumn = customerFindColumn($pdo, ['email', 'email_address']) ?? 'email';
            $phoneColumn = customerFindColumn($pdo, ['phone', 'mobile', 'contact_number', 'contact']) ?? 'phone';
            $passwordHashColumn = customerFindColumn($pdo, ['password_hash']) ?? 'password_hash';
            $firstNameColumn = customerFindColumn($pdo, ['first_name', 'firstname', 'given_name']);
            $middleNameColumn = customerFindColumn($pdo, ['middle_name', 'middlename', 'middle']);
            $lastNameColumn = customerFindColumn($pdo, ['last_name', 'lastname', 'surname', 'family_name']);
            $emailVerifiedColumn = customerFindColumn($pdo, ['email_verified_at', 'verified_at']);
            $verificationTokenColumn = customerFindColumn($pdo, ['verification_token', 'email_verification_token']);

            $selectColumns = [
                '`id` AS id',
                '`' . $fullNameColumn . '` AS full_name',
                '`' . $emailColumn . '` AS email',
                '`' . $phoneColumn . '` AS phone',
                '`' . $passwordHashColumn . '` AS password_hash',
            ];

            if ($firstNameColumn !== null) {
                $selectColumns[] = '`' . $firstNameColumn . '` AS first_name';
            }

            if ($middleNameColumn !== null) {
                $selectColumns[] = '`' . $middleNameColumn . '` AS middle_name';
            }

            if ($lastNameColumn !== null) {
                $selectColumns[] = '`' . $lastNameColumn . '` AS last_name';
            }

            if ($emailVerifiedColumn !== null) {
                $selectColumns[] = '`' . $emailVerifiedColumn . '` AS email_verified_at';
            }

            if ($verificationTokenColumn !== null) {
                $selectColumns[] = '`' . $verificationTokenColumn . '` AS verification_token';
            }

            $projection = implode(', ', array_unique($selectColumns));

            if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                $stmt = $pdo->prepare('SELECT ' . $projection . ' FROM customers WHERE `' . $emailColumn . '` = ? LIMIT 1');
                $stmt->execute([$identifier]);
                $candidate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($candidate === null) {
                $normalizedPhone = normalizeCustomerPhone($identifier);
                if ($normalizedPhone !== '') {
                    $stmt = $pdo->prepare('SELECT ' . $projection . ' FROM customers WHERE `' . $phoneColumn . '` = ? LIMIT 1');
                    $stmt->execute([$normalizedPhone]);
                    $candidate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }

            if ($candidate === null && $identifier !== '') {
                $stmt = $pdo->prepare('SELECT ' . $projection . ' FROM customers WHERE `' . $phoneColumn . '` = ? LIMIT 1');
                $stmt->execute([$identifier]);
                $candidate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            // Differentiate between: (1) no matching account, and (2) wrong password
            if (!$candidate) {
                // Only show the big banner when the identifier has no record
                $errors['general'] = 'We could not find a matching account. Double check your details and try again.';
            } elseif (!verifyCustomerPassword($pdo, $candidate, $password)) {
                // Keep the account existence private but guide the user clearly
                $errors['password'] = 'Incorrect password. Please try again.';
            } else {
                $emailVerifiedValue = $candidate['email_verified_at'] ?? null;
                $isVerified = $emailVerifiedValue !== null && trim((string) $emailVerifiedValue) !== '';

                if (!$isVerified) {
                    $errors['general'] = 'Please verify your email address before logging in. Check your inbox for the verification link we sent you.';
                } else {
                    customerLogin((int) $candidate['id']);
                    $redirect = $_POST['redirect'] ?? ($_GET['redirect'] ?? orderingUrl('index.php'));
                    if (!is_string($redirect) || $redirect === '') {
                        $redirect = orderingUrl('index.php');
                    }
                    header('Location: ' . $redirect);
                    exit;
                }
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
<body class="customer-auth-page" data-customer-auth="login"
    data-customer-session-heartbeat="<?= htmlspecialchars($customerSessionStatusEndpoint) ?>"
    data-customer-session-heartbeat-interval="<?= (int) $customerSessionHeartbeatInterval ?>"
    data-customer-login-url="<?= htmlspecialchars($loginPageUrl) ?>">
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
