<?php
require __DIR__ . '/../config/config.php';
$pdo = db();
$msg = $_GET['msg'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $email = $_POST['email']; $pass = $_POST['password'];
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if ($u) {
        $storedHash = (string) $u['password'];
        $isPasswordHash = password_get_info($storedHash)['algo'] !== 0;
        if ($isPasswordHash) {
            $isValid = password_verify($pass, $storedHash);
        } else {
            $sha256 = hash('sha256', $pass);
            $isValid = hash_equals(strtolower($storedHash), strtolower($sha256));
        }

        if($isValid){
            $_SESSION['user_id']=$u['id'];
            $_SESSION['role']=$u['role'];
            header('Location: dashboard.php'); exit;
        }
    }
    $msg='Invalid credentials';
}
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
        
        <?php if($msg): ?>
            <div class="error-msg"><?= htmlspecialchars($msg) ?></div>
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
