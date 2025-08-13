<?php
require '../config.php';
$pdo = db();
$products = $pdo->query('SELECT * FROM products')->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pos_checkout'])) {
    // simple POS flow: product_id[], qty[]
    $items = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $total = 0;
    foreach($items as $i=>$pid){
        $pstmt = $pdo->prepare('SELECT * FROM products WHERE id=?');
        $pstmt->execute([intval($pid)]);
        $p = $pstmt->fetch();
        if($p){
            $q = max(1,intval($qtys[$i]));
            $total += $p['price'] * $q;
        }
    }
    // create a generic customer "Walk-in"
    $stmt = $pdo->prepare('INSERT INTO orders (customer_name,contact,address,total,payment_method,status) VALUES (?,?,?,?,?,?)');
    $stmt->execute(['Walk-in','N/A','N/A',$total,'Cash','completed']);
    $order_id = $pdo->lastInsertId();
    foreach($items as $i=>$pid){
        $pstmt = $pdo->prepare('SELECT * FROM products WHERE id=?');
        $pstmt->execute([intval($pid)]);
        $p = $pstmt->fetch();
        if($p){
            $q = max(1,intval($qtys[$i]));
            $pdo->prepare('INSERT INTO order_items (order_id,product_id,qty,price) VALUES (?,?,?,?)')->execute([$order_id,$p['id'],$q,$p['price']]);
            $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$q,$p['id']]);
        }
    }
    header('Location: pos.php?ok=1');
    exit;
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">    
    <title>POS - DGZ</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <h2>POS - Walk-in</h2><a href="dashboard.php">Back to dashboard</a>
    <form method="post">
        <table>
            <tr>
                <th>Product</th>
                <th>Price</th>
                <th>Available</th>
                <th>Qty</th>
            </tr>
            <?php foreach($products as $p): ?>
            <tr>
                <td><?=htmlspecialchars($p['name'])?></td>
                <td>₱<?=number_format($p['price'],2)?></td>
                <td><?=intval($p['quantity'])?></td>
                <td><input type="checkbox" name="product_id[]" value="<?=$p['id']?>"> <input type="number" name="qty[]"
                        value="1" min="1" max="<?=max(1,$p['quantity'])?>"></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <button name="pos_checkout" type="submit">Settle Payment (Complete)</button>
    </form>
    <?php if(!empty($_GET['ok'])) echo '<p>Transaction recorded.</p>'; ?>
</body>

</html>