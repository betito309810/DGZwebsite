<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
if(isset($_GET['delete'])){ $pdo->prepare('DELETE FROM products WHERE id=?')->execute([intval($_GET['delete'])]); header('Location: products.php'); exit; }
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_product'])){
    $name = $_POST['name']; $code = $_POST['code']; $desc = $_POST['description']; $price = floatval($_POST['price']); $qty = intval($_POST['quantity']); $low = intval($_POST['low_stock_threshold']);
    $id = intval($_POST['id']);
    if($id>0){
        $pdo->prepare('UPDATE products SET code=?,name=?,description=?,price=?,quantity=?,low_stock_threshold=? WHERE id=?')->execute([$code,$name,$desc,$price,$qty,$low,$id]);
    } else {
        $pdo->prepare('INSERT INTO products (code,name,description,price,quantity,low_stock_threshold) VALUES (?,?,?,?,?,?)')->execute([$code,$name,$desc,$price,$qty,$low]);
    }
    header('Location: products.php'); exit;
}
$products = $pdo->query('SELECT * FROM products')->fetchAll();
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Products</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <h2>Products</h2>
    <a href="dashboard.php">Back</a>
    <h3>Add / Edit Product</h3>
    <form method="post">
        <input type="hidden" name="id" value="0">
        <label>Code: <input name="code" required></label><br>
        <label>Name: <input name="name" required></label><br>
        <label>Description: <textarea name="description"></textarea></label><br>
        <label>Price: <input name="price" required></label><br>
        <label>Quantity: <input name="quantity" required></label><br>
        <label>Low stock threshold: <input name="low_stock_threshold" value="5" required></label><br>
        <button name="save_product" type="submit">Save</button>
    </form>
    <h3>All Products</h3>
    <table>
        <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Qty</th>
            <th>Price</th>
            <th>Action</th>
        </tr>
        <?php foreach($products as $p): ?>
        <tr>
            <td><?=htmlspecialchars($p['code'])?></td>
            <td><?=htmlspecialchars($p['name'])?></td>
            <td><?=intval($p['quantity'])?></td>
            <td>₱<?=number_format($p['price'],2)?></td>
            <td><a href="products.php?delete=<?=$p['id']?>" onclick="return confirm('Delete?')">Delete</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>

</html>