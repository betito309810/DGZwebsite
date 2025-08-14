<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
$conds = [];
$sql = "SELECT * FROM orders ORDER BY created_at DESC";
$orders = $pdo->query($sql)->fetchAll();
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/sales.css">
    <title>Sales</title>
    
</head>

<body>
    <h2>Sales</h2><a href="dashboard.php">Back</a>
    <table>
        <tr>
            <th>ID</th>
            <th>Customer</th>
            <th>Total</th>
            <th>Payment</th>
            <th>Status</th>
            <th>Date</th>
        </tr>
        <?php foreach($orders as $o): ?>
        <tr>
            <td><?=$o['id']?></td>
            <td><?=htmlspecialchars($o['customer_name'])?></td>
            <td>₱<?=number_format($o['total'],2)?></td>
            <td><?=htmlspecialchars($o['payment_method'])?></td>
            <td><?=htmlspecialchars($o['status'])?></td>
            <td><?=$o['created_at']?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>

</html>