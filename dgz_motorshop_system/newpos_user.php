<?php
// === newpos.php (Updated as per user request) ===
// POS page with remove item feature, fixed table size, scrollable list, and 12% VAT

require 'config.php';
$pdo = db();
$products = $pdo->query('SELECT * FROM products')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>POS</title>
  <link rel="stylesheet" href="newpos.css">
  <script>
    // Function to update totals including VAT
    function updateTotals() {
      let rows = document.querySelectorAll("#posTable tbody tr");
      let subtotal = 0;
      rows.forEach(row => {
        let price = parseFloat(row.querySelector(".price").dataset.value);
        let qty = parseInt(row.querySelector(".qty").value) || 0;
        subtotal += price * qty;
      });
      let vat = subtotal * 0.12;
      let total = subtotal + vat;

      document.getElementById("subtotal").textContent = "₱" + subtotal.toFixed(2);
      document.getElementById("vat").textContent = "₱" + vat.toFixed(2);
      document.getElementById("total").textContent = "₱" + total.toFixed(2);

      let received = parseFloat(document.getElementById("amountReceived").value) || 0;
      let change = received - total;
      document.getElementById("change").textContent = "₱" + (change >= 0 ? change.toFixed(2) : "0.00");
    }

    // Function to remove a row
    function removeRow(btn) {
      btn.closest("tr").remove();
      updateTotals();
    }
  </script>
</head>
<body>
  <div class="main-content">
    <div class="header"><h2>POS</h2></div>
    <table id="posTable">
      <thead>
        <tr>
          <th>Product</th>
          <th>Price</th>
          <th>Available</th>
          <th>Qty</th>
          <th>Remove</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($products as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['name']) ?></td>
          <td class="price" data-value="<?= $p['price'] ?>">₱<?= number_format($p['price'], 2) ?></td>
          <td><?= $p['quantity'] ?></td>
          <td><input type="number" class="qty" value="1" min="1" max="<?= $p['quantity'] ?>" oninput="updateTotals()"></td>
          <td><button type="button" onclick="removeRow(this)">❌</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="totals-panel">
      <div class="totals-item"><label>Subtotal</label><div class="value" id="subtotal">₱0.00</div></div>
      <div class="totals-item"><label>VAT (12%)</label><div class="value" id="vat">₱0.00</div></div>
      <div class="totals-item"><label>Total</label><div class="value" id="total">₱0.00</div></div>
      <div class="totals-item"><label>Amount Received</label><input id="amountReceived" type="number" value="0" oninput="updateTotals()"></div>
      <div class="totals-item"><label>Change</label><div class="value" id="change">₱0.00</div></div>
    </div>
  </div>
  <script>updateTotals();</script>
</body>
</html>
