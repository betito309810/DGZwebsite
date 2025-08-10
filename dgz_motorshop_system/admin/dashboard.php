<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
// simple stats
$today = $pdo->prepare("SELECT COUNT(*) as c, COALESCE(SUM(total),0) as s FROM orders WHERE DATE(created_at)=CURDATE()");
$today->execute(); $t = $today->fetch();
$low = $pdo->query('SELECT * FROM products WHERE quantity <= low_stock_threshold')->fetchAll();
$top = $pdo->query('SELECT p.*, SUM(oi.qty) as sold FROM order_items oi JOIN products p ON p.id=oi.product_id GROUP BY p.id ORDER BY sold DESC LIMIT 5')->fetchAll();
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Dashboard</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <h2>Dashboard</h2>
    <nav><a href="products.php">Products</a> | <a href="sales.php">Sales</a> | <a href="login.php?logout=1">Logout</a>
    </nav>
    <p>Today's orders: <?=intval($t['c'])?> | Today's sales: ₱<?=number_format($t['s'],2)?></p>
    <h3>Low stock</h3>
    <ul>
        <?php foreach($low as $l): ?><li><?=htmlspecialchars($l['name'])?> (<?=intval($l['quantity'])?>)</li>
        <?php endforeach; ?>
    </ul>
    <h3>Top selling</h3>
    <ul>
        <?php foreach($top as $it): ?><li><?=htmlspecialchars($it['name'])?> - <?=intval($it['sold'])?> sold</li>
        <?php endforeach; ?>
    </ul>
</body>

</html>