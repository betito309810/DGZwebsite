<?php
require __DIR__ . '/../config/config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pdo = db();
$msg = '';
$validToken = false;
$userId = null;
$currentHash = null;

/**
 * Guarantee the reset token table exists before we start querying it.
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

// Prune expired records so stale links don't pile up
$pdo->exec('DELETE FROM password_resets WHERE expires_at <= NOW()');

$token = $_GET['token'] ?? '';

if (!empty($token)) {
    // Verify token against the password_resets table
    try {
        $stmt = $pdo->prepare('
            SELECT pr.user_id, u.password
            FROM password_resets pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.token = ? AND pr.expires_at > NOW()
            LIMIT 1
        ');
        $stmt->execute([$token]);
        $passwordReset = $stmt->fetch();

        if ($passwordReset) {
            $validToken = true;
            $userId = (int) $passwordReset['user_id'];
            $currentHash = $passwordReset['password'] ?? null;
        } else {
            $msg = 'Invalid or expired reset token.';
        }
    } catch (PDOException $e) {
        $msg = 'Database error: ' . $e->getMessage();
    }
} else {
    $msg = 'No reset token provided.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($password)) {
        $msg = 'Please enter a new password.';
    } elseif (strlen($password) < 6) {
        $msg = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $msg = 'Passwords do not match.';
    } elseif ($currentHash !== null && password_verify($password, (string) $currentHash)) {
        // Guard against reusing the existing password to encourage better hygiene
        $msg = 'Please choose a password you have not used previously.';
    } else {
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Update password and clear token
        try {
            $pdo->beginTransaction();

            // Store the new password and remove any outstanding reset links for this user
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([$hashedPassword, $userId]);

            $stmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?');
            $stmt->execute([$userId]);

            $pdo->commit();

            // Redirect to login with success
            $query = http_build_query([
                'msg' => 'Password reset successfully. Please log in.',
                'status' => 'success',
            ]);
            header('Location: login.php?' . $query);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/login/login.css">
</head>
<body>
    <div class="logo">
        <img src="../assets/logo.png" alt="Company Logo">
    </div>

    <div class="login-container">
        <h2>Reset Password</h2>

        <?php if ($msg): ?>
            <div class="error-msg"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if ($validToken): ?>
            <form method="post">
                <label>
                    New Password:
                    <div class="password-field">
                        <input id="password" name="password" class="password-input" type="password" required>
                        <button type="button" class="toggle-password" data-toggle-target="password">Show</button>
                    </div>
                </label>

                <label>
                    Confirm New Password:
                    <div class="password-field">
                        <input id="confirm_password" name="confirm_password" class="password-input" type="password" required>
                        <button type="button" class="toggle-password" data-toggle-target="confirm_password">Show</button>
                    </div>
                </label>

                <button type="submit">Reset Password</button>
            </form>
        <?php else: ?>
            <p>The reset link is invalid or has expired.</p>
            <a href="forgot_password.php">Request a new reset link</a>
        <?php endif; ?>

        <div class="back-to-login">
            <a href="login.php">Back to Login</a>
        </div>
    </div>
    <script src="../assets/js/login/reset-password.js"></script>
</body>
</html>
