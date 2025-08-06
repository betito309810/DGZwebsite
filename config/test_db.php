// test_db.php
<?php
try {
    $pdo = new PDO("mysql:host=localhost", "root", "");
    echo "✅ MySQL connection works!";
    
    // Test if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE 'dgz_motorshop'");
    if ($stmt->rowCount() > 0) {
        echo "<br>✅ Database 'dgz_motorshop' exists!";
    } else {
        echo "<br>❌ Database 'dgz_motorshop' needs to be created!";
    }
} catch(PDOException $e) {
    echo "❌ Connection failed: " . $e->getMessage();
}
?>