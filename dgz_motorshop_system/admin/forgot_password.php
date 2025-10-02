<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/email.php';

$pdo = db();
$msg = '';

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

            try {
                // Send email
                $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/dgz_motorshop_system/admin/reset_password.php?token=" . $token;
                $subject = 'Password Reset Request';
                $body = "
                    <p>You requested a password reset for your DGZ Motorshop account.</p>
                    <p>Click the link below to reset your password:</p>
                    <p><a href='$resetLink' style='padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Reset Password</a></p>
                    <p>Or copy and paste this link in your browser:</p>
                    <p>$resetLink</p>
                    <p>This link will expire in 1 hour.</p>
                    <p>If you did not request this, please ignore this email.</p>
                ";

                $emailSent = sendEmail($email, $subject, $body);
                
                if (!$emailSent) {
                    error_log("Failed to send password reset email to: " . $email);
                    throw new Exception("Failed to send the reset email. Please verify your email address and try again.");
                }
                
                // Only show success message if email was actually sent
                $msg = 'A password reset link has been sent to your email address. Please check your inbox and spam folder.';
                
            } catch (Exception $e) {
                // Roll back the token update if email sending failed
                $stmt = $pdo->prepare('UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE id = ?');
                $stmt->execute([$user['id']]);
                
                $msg = $e->getMessage();
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
