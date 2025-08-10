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
    <title>Admin Login</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <h2>Admin / Staff Login</h2>
    <?php if($msg) echo '<p style="color:red">'.$msg.'</p>';?>
    <form method="post">
        <label>Email: <input name="email" required></label><br>
        <label>Password: <input name="password" type="password" required></label><br>
        <button>Login</button>
    </form>
</body>

</html>