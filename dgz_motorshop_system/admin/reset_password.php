<?php
require __DIR__ . '/../config/config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pdo = db();
$msg = '';
$validToken = false;
$userId = null;

$token = $_GET['token'] ?? '';

if (!empty($token)) {
    // Verify token
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            $validToken = true;
            $userId = $user['id'];
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
    } else {
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Update password and clear token
        try {
            $stmt = $pdo->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?');
            $stmt->execute([$hashedPassword, $userId]);

            // Redirect to login with success
            header('Location: login.php?msg=' . urlencode('Password reset successfully. Please log in.'));
            exit;
        } catch (PDOException $e) {
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
                    <input name="password" type="password" required>
                </label>

                <label>
                    Confirm New Password:
                    <input name="confirm_password" type="password" required>
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
</body>
</html>
