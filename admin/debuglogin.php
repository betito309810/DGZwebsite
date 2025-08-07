<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Start with basic PHP test
echo "PHP is working!<br>";

// Test session start   
try {
    session_start();
    echo "Session started successfully!<br>";
} catch (Exception $e) {
    echo "Session error: " . $e->getMessage() . "<br>";
}

// Test if database config exists
if (file_exists('config/database.php')) {
    echo "Database config file exists!<br>";
    
    try {
        $config = include 'config/database.php';
        echo "Database config loaded successfully!<br>";
        
        // Test database connection
        $db_config = $config['connections']['mysql'];
        $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['database']};charset={$db_config['charset']}";
        
        $pdo = new PDO($dsn, $db_config['username'], $db_config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        echo "Database connection successful!<br>";
        
    } catch (Exception $e) {
        echo "Database error: " . $e->getMessage() . "<br>";
    }
} else {
    echo "Database config file NOT found!<br>";
}

// Initialize variables
$email = '';
$errors = [];

// Basic form processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "Form submitted!<br>";
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    
    // Basic validation
    if (empty($email)) {
        $errors[] = "Email is required";
    }
    if (empty($password)) {
        $errors[] = "Password is required";
    }
    
    if (empty($errors)) {
        echo "Validation passed!<br>";
        // Here we would normally check the database
        echo "Email: " . $email . "<br>";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Login - DGZ Workshop</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 500px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="email"], input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        button {
            background: #007cba;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
        }
        button:hover {
            background: #005a87;
        }
        .error {
            color: red;
            margin-bottom: 20px;
            padding: 10px;
            background: #ffe6e6;
            border-radius: 4px;
        }
        .debug {
            background: #e6f3ff;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border-left: 4px solid #007cba;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="debug">
            <h3>Debug Information:</h3>
            <p>PHP Version: <?php echo phpversion(); ?></p>
            <p>Current Time: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        
        <h1>Debug Login - DGZ Workshop</h1>
        
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>