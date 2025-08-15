<?php
require '../config.php';
session_start();
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
$role = $_SESSION['role'] ?? '';
$products = $pdo->query('SELECT * FROM products ORDER BY created_at DESC')->fetchAll();

// Handle stock updates (admin only)
if($role === 'admin' && isset($_POST['update_stock'])) {
    $id = intval($_POST['id']);
    $change = intval($_POST['change']);
    $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([$change,$id]);
    header('Location: inventory.php'); exit;
}

// Handle export to CSV
if(isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product Code','Name','Quantity','Low Stock Threshold','Date Added']);
    foreach($products as $p) {
        fputcsv($out, [$p['code'],$p['name'],$p['quantity'],$p['low_stock_threshold'],$p['created_at']]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Inventory Management</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<h2>Inventory Management</h2>
<a href="dashboard.php">Back to Dashboard</a> | <a href="inventory.php?export=csv">Export CSV</a>
<table border="1" cellpadding="5">
<tr><th>Code</th><th>Name</th><th>Quantity</th><th>Low Stock Threshold</th><th>Date Added</th><?php if($role==='admin') echo '<th>Update Stock</th>';?></tr>
<?php foreach($products as $p): 
$low = $p['quantity'] <= $p['low_stock_threshold'];
?>
<tr style="<?php if($low) echo 'background-color:#fdd'; ?>">
<td><?=htmlspecialchars($p['code'])?></td>
<td><?=htmlspecialchars($p['name'])?></td>
<td><?=intval($p['quantity'])?></td>
<td><?=intval($p['low_stock_threshold'])?></td>
<td><?=$p['created_at']?></td>
<?php if($role==='admin'): ?>
<td>
<form method="post" style="display:inline-block">
<input type="hidden" name="id" value="<?=$p['id']?>">
<input type="number" name="change" value="0" style="width:60px">
<button name="update_stock">Apply</button>
</form>
</td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</table>
</body>
</html>
