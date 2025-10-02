<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/email.php';

$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $msg = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.';
    } else {
        // Check if user exists
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Store token in DB
            $stmt = $pdo->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?');
            $stmt->execute([$token, $expires, $user['id']]);

            // Send email
            $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/dgz_motorshop_system/admin/reset_password.php?token=" . $token;
            $subject = 'Password Reset Request';
            $body = "
                <p>You requested a password reset for your DGZ Motorshop account.</p>
                <p>Click the link below to reset your password:</p>
                <a href='$resetLink'>Reset Password</a>
                <p>This link will expire in 1 hour.</p>
                <p>If you did not request this, please ignore this email.</p>
            ";

            if (sendEmail($email, $subject, $body)) {
                $msg = 'If an account with that email exists, a password reset link has been sent.';
            } else {
                $msg = 'Failed to send email. Please try again later.';
            }
        } else {
            // Don't reveal if email exists or not for security
            $msg = 'If an account with that email exists, a password reset link has been sent.';
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/login/login.css">
</head>
<body>
    <div class="logo">
        <img src="../assets/logo.png" alt="Company Logo">
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
