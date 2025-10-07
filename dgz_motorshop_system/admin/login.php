<?php
require __DIR__ . '/../config/config.php';
$pdo = db();
$msg = $_GET['msg'] ?? '';
$status = $_GET['status'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $email = $_POST['email']; $pass = $_POST['password'];
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if ($u) {
        if (password_verify($pass, (string) $u['password'])) {
            $_SESSION['user_id']=$u['id'];
            $_SESSION['role']=$u['role'];

            // Persist commonly used profile information for faster access in
            // areas where the full database record is not required.
            $resolvedName = null;
            if (!empty($u['name'])) {
                $resolvedName = $u['name'];
            } elseif (!empty($u['full_name'])) {
                $resolvedName = $u['full_name'];
            } elseif (!empty($u['first_name']) || !empty($u['last_name'])) {
                $first = trim((string) ($u['first_name'] ?? ''));
                $last = trim((string) ($u['last_name'] ?? ''));
                $resolvedName = trim($first . ' ' . $last);
            }

            if (!empty($resolvedName)) {
                $_SESSION['user_name'] = $resolvedName;
            }

            if (!empty($u['created_at'])) {
                $_SESSION['user_created_at'] = $u['created_at'];
            } elseif (!empty($u['date_created'])) {
                $_SESSION['user_created_at'] = $u['date_created'];
            }

            header('Location: dashboard.php'); exit;
        }
    }
    $msg='Invalid credentials';
    $status = 'error';
}
$hasMessage = $msg !== '';
$alertClass = ($status === 'success') ? 'success-msg' : 'error-msg';
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/login/login.css">
</head>
<body>
    <!-- Logo placeholder - replace src with your logo path -->
    <div class="logo">
        <img src="../assets/logo.png" alt="Company Logo">
    </div>
    
    <div class="login-container">
        <h2>Admin / Staff Login</h2>
        
        <?php if($hasMessage): ?>
            <div class="<?= $alertClass ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        
        <form method="post">
            <label>
                Email:
                <input name="email" type="email" required>
            </label>
            
            <label>
                Password:
                <input name="password" type="password" required>
            </label>
            
            <button type="submit">Login</button>
        </form>

        <div class="forgot-password">
            <a href="forgot_password.php">Forgot Password?</a>
        </div>
    </div>
</body>
</html>
