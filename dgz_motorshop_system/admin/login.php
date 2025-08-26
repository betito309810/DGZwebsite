<?php
require '../config.php';
$pdo = db();
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $email = $_POST['email']; $pass = $_POST['password'];
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if($u && hash('sha256',$pass) === $u['password']){
        $_SESSION['user_id']=$u['id'];
        $_SESSION['role']=$u['role'];
        header('Location: dashboard.php'); exit;
    } else $msg='Invalid credentials';
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/login.css">
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
    </div>
</body>
</html>