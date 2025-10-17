    <?php
    require __DIR__ . '/../config/config.php';
    $pdo = db();
    $msg = $_GET['msg'] ?? '';
    $status = $_GET['status'] ?? '';

    if (isset($_GET['logout'])) {
    logoutUser($pdo);

    $redirect = 'login.php?status=success&msg=' . urlencode('You have been logged out.');
    header('Location: ' . $redirect);
    exit;
}

    if (!empty($_SESSION['forced_logout'])) {
        $msg = $_SESSION['forced_logout_message'] ?? "You’ve been logged out because your account was used to sign in on another device.";
        $status = 'error';
        unset($_SESSION['forced_logout'], $_SESSION['forced_logout_message']);
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        $email = $_POST['email']; $pass = $_POST['password'];
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?');
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if (!$u) {
            $msg='Invalid credentials';
            $status = 'error';
        } elseif (!empty($u['deleted_at'])) {
            $msg = 'Your account has been deactivated. Please contact an administrator.';
            $status = 'error';
        } elseif (!password_verify($pass, (string) $u['password'])) {
            $msg='Invalid credentials';
            $status = 'error';
        } else {
            session_regenerate_id(true);

            try {
                $newToken = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                error_log('Unable to generate new session token: ' . $e->getMessage());
                try {
                    $newToken = bin2hex(random_bytes(16));
                } catch (Throwable $fallbackException) {
                    error_log('Unable to generate fallback session token: ' . $fallbackException->getMessage());
                    $newToken = hash('sha256', microtime(true) . '-' . mt_rand());
                }
            }

            try {
                $updateToken = $pdo->prepare('UPDATE users SET current_session_token = ? WHERE id = ?');
                $updateToken->execute([$newToken, $u['id']]);
            } catch (Throwable $e) {
                error_log('Unable to persist session token: ' . $e->getMessage());
            }

            $_SESSION['user_id']=$u['id'];
            $_SESSION['role']=$u['role'];
            $_SESSION['session_token'] = $newToken;

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
    $hasMessage = $msg !== '';
    $alertClass = ($status === 'success') ? 'success-msg' : 'error-msg';
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login</title>
        <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/style.css">
        <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/login/login.css">
    </head>
    <body>
        <!-- Logo placeholder - replace src with your logo path -->
        <div class="logo">
            <img src="../dgz_motorshop_system/assets/logo.png" alt="Company Logo">
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
