<?php
require __DIR__ . '/../config/config.php';
$pdo = db();
$msg = $_GET['msg'] ?? '';
$status = $_GET['status'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';

    if ($email !== '' && $pass !== '') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL');
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if ($u && password_verify($pass, (string) $u['password'])) {
            $_SESSION['user_id']=$u['id'];
            $_SESSION['role']=$u['role'];
            header('Location: dashboard.php'); exit;
        }

        $inactiveStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND deleted_at IS NOT NULL');
        $inactiveStmt->execute([$email]);
        if ($inactiveStmt->fetchColumn()) {
            $msg = 'This account has been deactivated. Please contact an administrator.';
            $status = 'error';
        } else {
            $msg='Invalid credentials';
            $status = 'error';
        }
    } else {
        $msg='Email and password are required.';
        $status = 'error';
    }
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
