<?php
// === newpos_suggested.php (Suggested extra features) ===
// Includes customer name input, discount feature, and receipt preview

require 'config.php';
$pdo = db();
$products = $pdo->query('SELECT * FROM products')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>POS (Enhanced)</title>
  <link rel="stylesheet" href="newpos.css">
  <script>
    function updateTotals() {
      let rows = document.querySelectorAll("#posTable tbody tr");
      let subtotal = 0;
      rows.forEach(row => {
        let price = parseFloat(row.querySelector(".price").dataset.value);
        let qty = parseInt(row.querySelector(".qty").value) || 0;
        subtotal += price * qty;
      });
      let discount = parseFloat(document.getElementById("discount").value) || 0;
      let vat = subtotal * 0.12;
      let total = subtotal + vat - discount;

      document.getElementById("subtotal").textContent = "₱" + subtotal.toFixed(2);
      document.getElementById("vat").textContent = "₱" + vat.toFixed(2);
      document.getElementById("total").textContent = "₱" + total.toFixed(2);

      let received = parseFloat(document.getElementById("amountReceived").value) || 0;
      let change = received - total;
      document.getElementById("change").textContent = "₱" + (change >= 0 ? change.toFixed(2) : "0.00");

      // Update receipt preview
      document.getElementById("receiptContent").innerHTML = `
        <h3>Receipt</h3>
        <p><b>Customer:</b> ${document.getElementById("customerName").value}</p>
        <p>Subtotal: ₱${subtotal.toFixed(2)}</p>
        <p>VAT (12%): ₱${vat.toFixed(2)}</p>
        <p>Discount: ₱${discount.toFixed(2)}</p>
        <p>Total: ₱${total.toFixed(2)}</p>
        <p>Received: ₱${received.toFixed(2)}</p>
        <p>Change: ₱${(change >= 0 ? change.toFixed(2) : "0.00")}</p>
      `;
    }

    function removeRow(btn) {
      btn.closest("tr").remove();
      updateTotals();
    }
  </script>
</head>
<body>
  <div class="main-content">
    <div class="header"><h2>POS (Enhanced)</h2></div>
    <div style="margin-bottom:10px;">
      <label>Customer Name:</label>
      <input type="text" id="customerName" placeholder="Enter customer name" oninput="updateTotals()">
    </div>
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
      <div class="totals-item"><label>Discount</label><input id="discount" type="number" value="0" oninput="updateTotals()"></div>
      <div class="totals-item"><label>Total</label><div class="value" id="total">₱0.00</div></div>
      <div class="totals-item"><label>Amount Received</label><input id="amountReceived" type="number" value="0" oninput="updateTotals()"></div>
      <div class="totals-item"><label>Change</label><div class="value" id="change">₱0.00</div></div>
    </div>
    <div id="receiptContent" style="margin-top:20px; padding:10px; border:1px solid #ccc; background:#fff;"></div>
  </div>
  <script>updateTotals();</script>
</body>
</html>
