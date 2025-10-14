<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../dgz_motorshop_system/includes/email.php';

$pdo = db();
$msg = '';

/**
 * Ensure the temporary password reset storage exists before we try to use it.
 * The static flag prevents running the DDL multiple times per request.
 */
if (!function_exists('ensurePasswordResetTable')) {
    function ensurePasswordResetTable(PDO $pdo): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_user (user_id)
)
SQL;

        $pdo->exec($sql);
        $checked = true;
    }
}

ensurePasswordResetTable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    error_log("Password reset requested for email: " . $email);

    if (empty($email)) {
        $msg = 'Please enter your email address.';
        error_log("Empty email provided in password reset form");
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.';
        error_log("Invalid email format provided: " . $email);
    } else {
        // Ensure we only proceed when the email belongs to a registered user
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate a cryptographically secure reset token (expiry handled in the insert below)
            $token = bin2hex(random_bytes(32));

            // Persist the token in the dedicated password_resets table
            $tokenSaved = false;
            try {
                $pdo->beginTransaction();

                // Drop any expired records so the table stays tidy
                $pdo->exec('DELETE FROM password_resets WHERE expires_at <= NOW()');

                // Remove older tokens of the same user to keep only the latest one active
                $stmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?');
                $stmt->execute([$user['id']]);

                $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
                $stmt->execute([$user['id'], $token]);

                $pdo->commit();
                $tokenSaved = true;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Failed to store password reset token: ' . $e->getMessage());
                $msg = 'We could not start the reset process right now. Please try again in a moment.';
            }

            if ($tokenSaved) {
                try {
                    // Build a reset link using the current host; default to HTTPS when possible
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                    if ($basePath === '/' || $basePath === '\\') {
                        $basePath = '';
                    }
                    $resetPath = ($basePath === '' ? '' : $basePath) . '/reset_password.php?token=' . urlencode($token);
                    $resetLink = $scheme . '://' . $_SERVER['HTTP_HOST'] . $resetPath;
                    $subject = 'Password Reset Request';
                    $body = "
                        <p>You requested a password reset for your DGZ Motorshop account.</p>
                        <p>Click the link below to reset your password:</p>
                        <p><a href='$resetLink' style='padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Reset Password</a></p>
                        <p>Or copy and paste this link in your browser:</p>
                        <p>$resetLink</p>
                        <p>This link will expire in 5 minutes.</p>
                        <p>If you did not request this, please ignore this email.</p>
                    ";

                    $emailSent = sendEmail($email, $subject, $body);
                    
                    if (!$emailSent) {
                        error_log("Failed to send password reset email to: " . $email);
                        throw new Exception("Failed to send the reset email. Please verify your email address and try again.");
                    }
                    
                    // Only show success message if email was actually sent
                    $msg = 'A password reset link has been sent to your email address. Please check your inbox and spam folder within 5 minutes.';
                    
                } catch (Exception $e) {
                    // Roll back the token record if email sending failed
                    $stmt = $pdo->prepare('DELETE FROM password_resets WHERE token = ?');
                    $stmt->execute([$token]);
                    
                    $msg = $e->getMessage();
                }
            }
        } else {
            // For non-existent emails, add a small delay to prevent email enumeration
            sleep(1);
            $msg = 'A password reset link has been sent to your email address if it exists in our system.';
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/style.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/login/login.css">
</head>
<body>
    <div class="logo">
        <img src="../dgz_motorshop_system/assets/logo.png" alt="Company Logo">
    </div>

    <div class="login-container">
        <h2>Forgot Password</h2>

        <?php if ($msg): ?>
            <div class="error-msg"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <form method="post">
            <label>
                Email:
                <input name="email" type="email" required>
            </label>

            <button type="submit">Send Reset Link</button>
        </form>

        <div class="back-to-login">
            <a href="login.php">Back to Login</a>
        </div>
    </div>
</body>
</html>
