<?php
require 'config.php';
$pdo = db();

if($_SERVER['REQUEST_METHOD']==='POST') {
    $product_id = intval($_POST['product_id'] ?? 0);
    $qty = max(1,intval($_POST['qty'] ?? 1));
    $product = $pdo->prepare('SELECT * FROM products WHERE id=?');
    $product->execute([$product_id]);
    $p = $product->fetch();
    if(!$p){ exit('Product not found'); }
    if($qty > $p['quantity']){ exit('Not enough stock'); }

    // Simple customer form if not yet sent
    if(empty($_POST['customer_name'])) {
        // show form
        ?>
        <!doctype html><html><head><meta charset="utf-8"><title>Checkout</title><link rel="stylesheet" href="assets/style.css"></head><body>
        <h2>Checkout - <?=htmlspecialchars($p['name'])?></h2>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="product_id" value="<?= $p['id']?>">
          <input type="hidden" name="qty" value="<?= $qty ?>">
          <label>Your name: <input name="customer_name" required></label><br>
          <label>Contact: <input name="contact" required></label><br>
          <label>Address: <textarea name="address" required></textarea></label><br>
          <label>Payment method:
            <select name="payment_method">
              <option>Cash</option>
              <option>GCash</option>
            </select>
          </label><br>
          <label>Payment proof (optional for GCash): <input type="file" name="proof"></label><br>
          <button type="submit">Place Order</button>
        </form>
        </body></html>
        <?php
        exit;
    }

    // process order
    $customer_name = $_POST['customer_name'];
    $contact = $_POST['contact'];
    $address = $_POST['address'];
    $payment_method = $_POST['payment_method'];
    $proof_path = null;
    if(!empty($_FILES['proof']['tmp_name'])){
        if(!is_dir('uploads')) mkdir('uploads',0777,true);
        $fn = 'uploads/' . time() . '_' . basename($_FILES['proof']['name']);
        move_uploaded_file($_FILES['proof']['tmp_name'],$fn);
        $proof_path = $fn;
    }
    $total = $p['price'] * $qty;
    $stmt = $pdo->prepare('INSERT INTO orders (customer_name,contact,address,total,payment_method,payment_proof,status) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$customer_name,$contact,$address,$total,$payment_method,$proof_path,'pending']);
    $order_id = $pdo->lastInsertId();
    $stmt2 = $pdo->prepare('INSERT INTO order_items (order_id,product_id,qty,price) VALUES (?,?,?,?)');
    $stmt2->execute([$order_id,$p['id'],$qty,$p['price']]);

    // decrease stock
    $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$qty,$p['id']]);

    echo '<p>Order placed! Order ID: '.$order_id.' - Status: Pending. You may check admin panel.</p>';
    echo '<p><a href="index.php">Back to shop</a></p>';
    exit;
}
echo 'Invalid access';
?>