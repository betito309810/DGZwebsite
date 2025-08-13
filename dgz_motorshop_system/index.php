<?php
require 'config.php';
$pdo = db();
$products = $pdo->query('SELECT * FROM products')->fetchAll();
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/
  <title>DGZ Motorshop - Shop</title>
  <link rel="stylesheet" href="assets/style.css">
</head>

<body>
  <header>
    <h1>DGZ Motorshop - Online Shop</h1>
    <nav><a href="index.php">Shop</a> | <a href="pos.php">POS</a> | <a href="admin/login.php">Admin</a></nav>
  </header>
  <main>
    <h2>Products</h2>
    <div class="grid">
      <?php foreach($products as $p): ?>
      <div class="card">
        <h3><?=htmlspecialchars($p['name'])?></h3>
        <p><?=htmlspecialchars($p['description'])?></p>
        <p>Price: ₱<?=number_format($p['price'],2)?></p>
        <p>Stock: <?=intval($p['quantity'])?></p>
        <form method="post" action="checkout.php">
          <input type="hidden" name="product_id" value="<?= $p['id']?>">
          <label>Qty: <input type="number" name="qty" value="1" min="1" max="<?=max(1,$p['quantity'])?>"></label><br>
          <button type="submit">Buy / Checkout</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </main>
  <footer>DGZ Motorshop &copy; <?=date('Y')?></footer>
</body>

</html>