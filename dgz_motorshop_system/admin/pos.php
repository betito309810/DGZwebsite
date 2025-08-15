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
    <h2>POS - Walk-in</h2>
    <button type="button" id="openProductModal" style="margin: 15px 0; padding: 8px 18px; background: #3498db; color: #fff; border: none; border-radius: 6px; font-size: 15px; cursor: pointer;"><i class="fas fa-search"></i> Search Product</button>
    <form method="post" id="posForm">
        <table id="posTable">
            <tr>
                <th>Product</th>
                <th>Price</th>
                <th>Available</th>
                <th>Qty</th>
            </tr>
            <?php foreach($products as $p): ?>
            <tr data-product-id="<?=$p['id']?>">
                <td><?=htmlspecialchars($p['name'])?></td>
                <td>₱<?=number_format($p['price'],2)?></td>
                <td><?=intval($p['quantity'])?></td>
                <td><input type="checkbox" name="product_id[]" value="<?=$p['id']?>"> <input type="number" name="qty[]"
                        value="1" min="1" max="<?=max(1,$p['quantity'])?>"></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <button type="button" id="clearPosTable" style="margin:10px 0 0 0; background:#e74c3c; color:#fff; border:none; border-radius:6px; font-size:15px; padding:8px 18px; cursor:pointer;">Clear</button>
    <button name="pos_checkout" type="submit">Settle Payment (Complete)</button>
    </form>
    <?php if(!empty($_GET['ok'])) echo '<p>Transaction recorded.</p>'; ?>

    <!-- Product Search Modal -->
    <div id="productModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:10px; max-width:600px; width:95%; margin:auto; padding:24px; position:relative; box-shadow:0 8px 32px rgba(0,0,0,0.18);">
            <button id="closeProductModal" style="position:absolute; top:10px; right:10px; background:none; border:none; font-size:20px; color:#888; cursor:pointer;">&times;</button>
            <h3 style="margin-bottom:12px;">Search Product</h3>
            <input type="text" id="productSearchInput" placeholder="Type product name..." style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:5px; margin-bottom:12px;">
            <div style="max-height:320px; overflow-y:auto;">
                <table id="productSearchTable" style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th style="padding:8px 6px; text-align:left;">Product</th>
                            <th style="padding:8px 6px; text-align:right;">Price</th>
                            <th style="padding:8px 6px; text-align:center;">Stock</th>
                            <th style="padding:8px 6px; text-align:center;">Select</th>
                        </tr>
                    </thead>
                    <tbody id="productSearchTableBody">
                        <!-- JS will populate -->
                    </tbody>
                </table>
            </div>
            <button id="addSelectedProducts" style="margin-top:14px; background:#3498db; color:#fff; border:none; border-radius:6px; font-size:15px; padding:8px 18px; cursor:pointer; width:100%;">Add Selected</button>
        </div>
    </div>

    <script>
    // Modal open/close
    document.getElementById('openProductModal').onclick = function() {
        document.getElementById('productModal').style.display = 'flex';
        document.getElementById('productSearchInput').value = '';
        document.getElementById('productSearchResults').innerHTML = '';
        document.getElementById('productSearchInput').focus();
    };
    document.getElementById('closeProductModal').onclick = function() {
        document.getElementById('productModal').style.display = 'none';
    };
    // Close modal on outside click
    document.getElementById('productModal').onclick = function(e) {
        if(e.target === this) this.style.display = 'none';
    };

    // Prepare product data for search (from PHP)
    const allProducts = [
        <?php foreach($products as $p): ?>
        {
            id: <?=json_encode($p['id'])?>,
            name: <?=json_encode($p['name'])?>,
            price: <?=json_encode($p['price'])?>,
            quantity: <?=json_encode($p['quantity'])?>
        },
        <?php endforeach; ?>
    ];

    // Render all products in table
    function renderProductTable(filter = '') {
        const tbody = document.getElementById('productSearchTableBody');
        tbody.innerHTML = '';
        let filtered = allProducts;
        if(filter) {
            filtered = allProducts.filter(p => p.name.toLowerCase().includes(filter.toLowerCase()));
        }
        if(filtered.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan='4' style='text-align:center; color:#888; padding:16px;'>No products found.</td>`;
            tbody.appendChild(tr);
            return;
        }
        filtered.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${p.name}</td><td style='text-align:right;'>₱${parseFloat(p.price).toFixed(2)}</td><td style='text-align:center;'>${p.quantity}</td><td style='text-align:center;'><input type='checkbox' class='product-select-checkbox' data-id='${p.id}'></td>`;
            tbody.appendChild(tr);
        });
    }

    // Show all products when modal opens
    document.getElementById('openProductModal').onclick = function() {
        document.getElementById('productModal').style.display = 'flex';
        document.getElementById('productSearchInput').value = '';
        renderProductTable();
        document.getElementById('productSearchInput').focus();
    };
    document.getElementById('closeProductModal').onclick = function() {
        document.getElementById('productModal').style.display = 'none';
    };
    // Close modal on outside click
    document.getElementById('productModal').onclick = function(e) {
        if(e.target === this) this.style.display = 'none';
    };

    // Filter table as user types
    document.getElementById('productSearchInput').oninput = function() {
        renderProductTable(this.value.trim());
    };

    // Add selected products to POS table
    document.getElementById('addSelectedProducts').onclick = function() {
        const checkboxes = document.querySelectorAll('.product-select-checkbox:checked');
        if(checkboxes.length === 0) {
            alert('Please select at least one product to add.');
            return;
        }
        checkboxes.forEach(cb => {
            const pid = cb.getAttribute('data-id');
            const product = allProducts.find(p => p.id == pid);
            if(product) addProductToPOS(product);
        });
        document.getElementById('productModal').style.display = 'none';
    };

    function addProductToPOS(product) {
        // Check if already in table
        const table = document.getElementById('posTable');
        const existing = table.querySelector(`tr[data-product-id='${product.id}']`);
        if(existing) {
            // If already present, check the box and increment qty
            const checkbox = existing.querySelector('input[type=checkbox]');
            const qtyInput = existing.querySelector('input[type=number]');
            checkbox.checked = true;
            qtyInput.value = Math.min(parseInt(qtyInput.value)+1, product.quantity);
        } else {
            // Add new row
            const tr = document.createElement('tr');
            tr.setAttribute('data-product-id', product.id);
            tr.innerHTML = `<td>${product.name}</td><td>₱${parseFloat(product.price).toFixed(2)}</td><td>${product.quantity}</td><td><input type='checkbox' name='product_id[]' value='${product.id}' checked> <input type='number' name='qty[]' value='1' min='1' max='${product.quantity}'></td>`;
            table.appendChild(tr);
        }
    }
    // Clear POS table (remove all rows except header)
    document.getElementById('clearPosTable').onclick = function() {
        const table = document.getElementById('posTable');
        // Remove all rows except the first (header)
        while(table.rows.length > 1) {
            table.deleteRow(1);
        }
    };
    </script>
</body>

</html>