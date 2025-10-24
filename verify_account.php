<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

$customerStylesheet = assetUrl('assets/css/public/customer.css');
$indexStylesheet = assetUrl('assets/css/public/index.css');
$customerScript = assetUrl('assets/js/public/customer.js');
$logoAsset = assetUrl('assets/logo.png');
$shopUrl = orderingUrl('index.php');
$loginUrl = orderingUrl('login.php');
$ordersUrl = orderingUrl('my_orders.php');

$status = 'idle';
$alertClass = 'customer-auth-alert';
$alertMessage = '';
$buttonLabel = 'Return to shop';
$buttonUrl = $shopUrl;
$firstName = null;

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($token === '') {
    $status = 'error';
    $alertClass .= ' customer-auth-alert--error';
    $alertMessage = 'This verification link is invalid. Please request a new one or contact support.';
} else {
    try {
        $pdo = db();

        $verificationTokenColumn = customerFindColumn($pdo, ['verification_token', 'email_verification_token']);
        $emailVerifiedColumn = customerFindColumn($pdo, ['email_verified_at', 'verified_at']);
        $fullNameColumn = customerFindColumn($pdo, ['full_name', 'name']) ?? 'full_name';
        $firstNameColumn = customerFindColumn($pdo, ['first_name', 'firstname', 'given_name']);
        $middleNameColumn = customerFindColumn($pdo, ['middle_name', 'middlename', 'middle']);
        $lastNameColumn = customerFindColumn($pdo, ['last_name', 'lastname', 'surname', 'family_name']);

        if ($verificationTokenColumn === null) {
            throw new RuntimeException('Verification token column is missing.');
        }

        $selectParts = ['`id` AS id', '`' . $fullNameColumn . '` AS full_name'];
        if ($firstNameColumn !== null) {
            $selectParts[] = '`' . $firstNameColumn . '` AS first_name';
        }
        if ($middleNameColumn !== null) {
            $selectParts[] = '`' . $middleNameColumn . '` AS middle_name';
        }
        if ($lastNameColumn !== null) {
            $selectParts[] = '`' . $lastNameColumn . '` AS last_name';
        }
        if ($emailVerifiedColumn !== null) {
            $selectParts[] = '`' . $emailVerifiedColumn . '` AS email_verified_at';
        }

        $projection = implode(', ', array_unique($selectParts));
        $stmt = $pdo->prepare('SELECT ' . $projection . ' FROM customers WHERE `' . $verificationTokenColumn . '` = ? LIMIT 1');
        $stmt->execute([$token]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$record) {
            $status = 'error';
            $alertClass .= ' customer-auth-alert--error';
            $alertMessage = 'This verification link is invalid or has already been used.';
        } else {
            $emailVerifiedValue = $record['email_verified_at'] ?? null;
            $isVerified = $emailVerifiedValue !== null && trim((string) $emailVerifiedValue) !== '';

            $nameParts = [];
            foreach (['first_name', 'middle_name', 'last_name'] as $key) {
                $part = trim((string) ($record[$key] ?? ''));
                if ($part !== '') {
                    $nameParts[] = $part;
                }
            }

            $fullName = trim((string) ($record['full_name'] ?? ''));
            if ($fullName === '' && $nameParts !== []) {
                $fullName = implode(' ', $nameParts);
            }

            $firstName = $record['first_name'] ?? null;
            if (!$firstName || trim((string) $firstName) === '') {
                $firstName = extractCustomerFirstName($fullName);
            }

            if ($isVerified) {
                $status = 'already';
                $alertMessage = 'Your email address is already verified. You can sign in to continue.';
                $buttonLabel = 'Go to login';
                $buttonUrl = $loginUrl;
            } else {
                $status = 'success';
                $alertClass .= ' customer-auth-alert--success';

                $pdo->beginTransaction();
                try {
                    if ($emailVerifiedColumn !== null) {
                        $updateSql = 'UPDATE customers SET `' . $emailVerifiedColumn . '` = NOW(), `' . $verificationTokenColumn . '` = NULL WHERE id = ?';
                    } else {
                        $updateSql = 'UPDATE customers SET `' . $verificationTokenColumn . '` = NULL WHERE id = ?';
                    }
                    $update = $pdo->prepare($updateSql);
                    $update->execute([(int) $record['id']]);
                    $pdo->commit();
                } catch (Throwable $updateException) {
                    $pdo->rollBack();
                    throw $updateException;
                }

                customerLogin((int) $record['id']);
                customerSessionRefresh();

                $alertMessage = 'Thanks, ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '! Your email is now verified and your account is ready.';
                $buttonLabel = 'Start shopping';
                $buttonUrl = $shopUrl;
            }
        }
    } catch (Throwable $exception) {
        error_log('Unable to verify customer email: ' . $exception->getMessage());
        $status = 'error';
        $alertClass .= ' customer-auth-alert--error';
        $alertMessage = 'We ran into a problem verifying your email. Please try again or contact support.';
    }
}

$customerSession = getAuthenticatedCustomer();
$bodyState = $customerSession ? 'authenticated' : 'guest';
$bodyFirstName = $customerSession['first_name'] ?? ($customerSession['full_name'] ?? '');

if (!$alertMessage) {
    $alertMessage = 'Use the button below to continue.';
}
?>
<!doctype html>
<html lang="en" data-customer-session="<?= htmlspecialchars($bodyState) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your DGZ Motorshop account</title>
    <link rel="icon" type="image/x-icon" href="dgz_motorshop_system/assets/android-chrome-512x512.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($indexStylesheet) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($customerStylesheet) ?>">
</head>
<body class="customer-auth-page" data-customer-auth="verify" data-customer-first-name="<?= htmlspecialchars($bodyFirstName) ?>">
    <main class="customer-auth-card" aria-labelledby="verifyHeading">
        <a href="<?= htmlspecialchars($shopUrl) ?>" class="customer-auth-logo">
            <img src="<?= htmlspecialchars($logoAsset) ?>" alt="DGZ Motorshop logo">
        </a>
        <h1 id="verifyHeading">Account verification</h1>
        <div class="<?= htmlspecialchars($alertClass) ?>" role="status">
            <?= $alertMessage ?>
        </div>
        <div class="customer-auth-actions">
            <a class="customer-auth-submit" href="<?= htmlspecialchars($buttonUrl) ?>"><?= htmlspecialchars($buttonLabel) ?></a>
            <?php if ($status === 'success'): ?>
                <p class="customer-auth-footer">Want to review your orders? <a href="<?= htmlspecialchars($ordersUrl) ?>">View my orders</a></p>
            <?php elseif ($status === 'error' || $status === 'already'): ?>
                <p class="customer-auth-footer">Need to sign in? <a href="<?= htmlspecialchars($loginUrl) ?>">Log in</a> or <a href="<?= htmlspecialchars(orderingUrl('register.php')) ?>">create a new account</a>.</p>
            <?php endif; ?>
        </div>
    </main>
    <script src="<?= htmlspecialchars($customerScript) ?>" defer></script>
</body>
</html>
