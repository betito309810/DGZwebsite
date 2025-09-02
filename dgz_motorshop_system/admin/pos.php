<?php
require '../config.php';
$pdo = db();
$products = $pdo->query('SELECT * FROM products')->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pos_checkout'])) {
    // simple POS flow: product_id[], qty[]
    $items = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    if (empty($items)) {
    echo "<script>alert('No item selected in POS!'); window.location='pos.php';</script>";
    exit;
}

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
    // Modal overlay 
    if (empty($items)) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('productModal').style.display = 'none';
            alert('No item selected in POS!');
            window.location='pos.php';
        });
    </script>";
    exit;
}
// Server side validation for adding products if someone bypasses the UI
foreach($items as $i=>$pid){
    $pstmt = $pdo->prepare('SELECT * FROM products WHERE id=?');
    $pstmt->execute([intval($pid)]);
    $p = $pstmt->fetch();
    if($p && $p['quantity'] > 0){
        $q = max(1,intval($qtys[$i]));
        if ($q > $p['quantity']) $q = $p['quantity']; // clamp to available
        $total += $p['price'] * $q;
    } else {
        // skip if stock 0
        continue;
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/pos.css">
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="../assets/logo.png" alt="Company Logo">
            </div>
        </div>
        <nav class="nav-menu">
            <div class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-home nav-icon"></i>
                    Dashboard
                </a>
            </div>
            <div class="nav-item">
                <a href="products.php" class="nav-link">
                    <i class="fas fa-box nav-icon"></i>
                    Products
                </a>
            </div>
            <div class="nav-item">
                <a href="sales.php" class="nav-link">
                    <i class="fas fa-chart-line nav-icon"></i>
                    Sales
                </a>
            </div>
            <div class="nav-item">
                <a href="pos.php" class="nav-link active">
                    <i class="fas fa-cash-register nav-icon "></i>
                    POS
                </a>
            </div>
            <div class="nav-item">
                <a href="inventory.php" class="nav-link">
                    <i class="fas fa-boxes nav-icon"></i>
                    Inventory
                </a>
            </div>
        </nav>
    </aside>
    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>POS</h2>
            </div>
            <div class="user-menu">
                <div class="user-avatar" onclick="toggleDropdown()">
                    <i class="fas fa-user"></i>
                </div>
                <div class="dropdown-menu" id="userDropdown">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user-cog"></i> Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                    <a href="login.php?logout=1" class="dropdown-item logout">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </header>

        <button type="button" id="openProductModal"
            style="margin: 15px 0; padding: 8px 18px; background: #3498db; color: #fff; border: none; border-radius: 6px; font-size: 15px; cursor: pointer;"><i
                class="fas fa-search"></i> Search Product</button>
        
        <form method="post" id="posForm">
            <div class="pos-table-container">
                <table id="posTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Available</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody id="posTableBody">
                        <!-- JS will populate rows here -->
                    </tbody>
                </table>
                <div class="pos-empty-state" id="posEmptyState">
                    <i class="fas fa-shopping-cart"></i>
                    <p>No items in cart. Click "Search Product" to add items.</p>
                </div>
            </div>
            <!-- POS Totals Panel (separate from the table) -->
            <div id="totalsPanel" class="totals-panel">
                <div class="totals-item">
                    <label>Total</label>
                    <div id="totalAmount" class="value">₱0.00</div>
                </div>
                <div class="totals-item">
                    <label for="amountReceived">Amount Received</label>
                    <input type="number" id="amountReceived" min="0" step="0.01" placeholder="0.00">
                </div>
                <div class="totals-item">
                    <label>Change</label>
                    <div id="changeAmount" class="value">₱0.00</div>
                </div>
            </div>
           
            
            <button type="button" id="clearPosTable"
                style="margin:10px 0 0 0; background:#e74c3c; color:#fff; border:none; border-radius:6px; font-size:15px; padding:8px 18px; cursor:pointer;">Clear</button>
            <button name="pos_checkout" type="submit">Settle Payment (Complete)</button>
        </form>
        <p></p>
        <?php if(!empty($_GET['ok'])) echo '<p>Transaction recorded.</p>'; ?>

        <!-- Product Search Modal -->
        <div id="productModal"
            style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
            <div
                style="background:#fff; border-radius:10px; max-width:600px; width:95%; margin:auto; padding:24px; position:relative; box-shadow:0 8px 32px rgba(0,0,0,0.18);">
                <button id="closeProductModal"
                    style="position:absolute; top:10px; right:10px; background:none; border:none; font-size:20px; color:#888; cursor:pointer;">&times;</button>
                <h3 style="margin-bottom:12px;">Search Product</h3>
                <input type="text" id="productSearchInput" placeholder="Type product name..."
                    style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:5px; margin-bottom:12px;">
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
                <button id="addSelectedProducts"
                    style="margin-top:14px; background:#3498db; color:#fff; border:none; border-radius:6px; font-size:15px; padding:8px 18px; cursor:pointer; width:100%;">Add
                </button>
            </div>
        </div>
    </main>

    <script>
        // Remove the first duplicate event handler and keep only this one:

// Modal open/close
document.getElementById('openProductModal').onclick = function () {
    document.getElementById('productModal').style.display = 'flex';
    document.getElementById('productSearchInput').value = '';
    renderProductTable(); // This will show all products when modal opens
    document.getElementById('productSearchInput').focus();
};

document.getElementById('closeProductModal').onclick = function () {
    document.getElementById('productModal').style.display = 'none';
};

// Close modal on outside click
document.getElementById('productModal').onclick = function (e) {
    if (e.target === this) this.style.display = 'none';
};

// Prepare product data for search (from PHP)
const allProducts = [<?php foreach($products as $p) : ?> {
    id: <?= json_encode($p['id']) ?>,
    name: <?= json_encode($p['name']) ?>,
    price: <?= json_encode($p['price']) ?>,
    quantity: <?= json_encode($p['quantity']) ?>
}, <?php endforeach; ?>];

// Render all products in table
function renderProductTable(filter = '') {
    const tbody = document.getElementById('productSearchTableBody');
    tbody.innerHTML = '';
    let filtered = allProducts;
    if (filter) {
        filtered = allProducts.filter(p => p.name.toLowerCase().includes(filter.toLowerCase()));
    }
    if (filtered.length === 0) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td colspan='4' style='text-align:center; color:#888; padding:16px;'>No products found.</td>`;
        tbody.appendChild(tr);
        return;
    }
    /*
    filtered.forEach(p => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${p.name}</td><td style='text-align:right;'>₱${parseFloat(p.price).toFixed(2)}</td><td style='text-align:center;'>${p.quantity}</td><td style='text-align:center;'><input type='checkbox' class='product-select-checkbox' data-id='${p.id}'></td>`;
        tbody.appendChild(tr);
    });*/
    filtered.forEach(p => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
        <td>${p.name}</td>
        <td style='text-align:right;'>₱${parseFloat(p.price).toFixed(2)}</td>
        <td style='text-align:center;'>${p.quantity}</td>
        <td style='text-align:center;'>
            ${
                p.quantity > 0 
                ? `<input type='checkbox' class='product-select-checkbox' data-id='${p.id}'>`
                : `<span style="color:#e74c3c;font-size:13px;">Out of Stock</span>`
            }
        </td>`;
                tbody.appendChild(tr);
            });
}
// Prevent adding out-of-stock products from dev tools
        function addProductToPOS(product) {
            if (parseInt(product.quantity) <= 0) {
                alert(product.name + " is out of stock and cannot be added.");
                return;
            }
            // existing add-to-table logic...
        }
// Filter table as user types
document.getElementById('productSearchInput').oninput = function () {
    renderProductTable(this.value.trim());
};

// Add selected products to POS table
document.getElementById('addSelectedProducts').onclick = function () {
    const checkboxes = document.querySelectorAll('.product-select-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one product to add.');
        return;
    }
    checkboxes.forEach(cb => {
        const pid = cb.getAttribute('data-id');
        const product = allProducts.find(p => p.id == pid);
        if (product) addProductToPOS(product);
    });
    document.getElementById('productModal').style.display = 'none';
};

        //Save POS table data to localStorage
        function savePosTableToStorage() {
            const rows = [];
            document.querySelectorAll('#posTable tr[data-product-id]').forEach(tr => {
                rows.push({
                    id: tr.getAttribute('data-product-id'),
                    name: tr.querySelector('.pos-name').textContent,
                    price: tr.querySelector('.pos-price').textContent,
                    available: tr.querySelector('.pos-available').textContent,
                    qty: tr.querySelector('.pos-qty').value
                });
            });
            localStorage.setItem('posTable', JSON.stringify(rows));
        }

        //Restore POS table from localStorage on page load
        window.addEventListener('DOMContentLoaded', function () {
            const data = JSON.parse(localStorage.getItem('posTable') || '[]');
            data.forEach(item => {
                addProductToPOS({
                    id: item.id,
                    name: item.name,
                    price: item.price.replace(/[^\d.]/g, ''), // Remove ₱ and keep number
                    quantity: item.available,
                });
                // Set the correct qty value after row is added
                const table = document.getElementById('posTable');
                const tr = table.querySelector(`tr[data-product-id='${item.id}']`);
                if (tr) {
                    tr.querySelector('.pos-qty').value = item.qty;
                }
            });
        });

        //Clear localStorage when checkout is completed or clear button is clicked
        function clearPosTable() {
            // ...your code to clear the table...
            localStorage.removeItem('posTable');
        }

        // UPDATED: Modified addProductToPOS function to handle empty state visibility
        function addProductToPOS(product) {
            // Check if already in table
            const table = document.getElementById('posTable');
            const existing = table.querySelector(`tr[data-product-id='${product.id}']`);
            if (existing) {
                // If already present, check the box and increment qty
                const checkbox = existing.querySelector('input[type=checkbox]');
                const qtyInput = existing.querySelector('input[type=number]');
                checkbox.checked = true;
                qtyInput.value = Math.min(parseInt(qtyInput.value) + 1, product.quantity);
            } else {
                // Add new row
                const tr = document.createElement('tr');
                tr.setAttribute('data-product-id', product.id);
                tr.innerHTML =
                    `
            <td class="pos-name">${product.name}</td>
            <td class="pos-price">₱${parseFloat(product.price).toFixed(2)}</td>
            <td class="pos-available">${product.quantity}</td>
            <td><input type='checkbox' name='product_id[]' value='${product.id}' checked> <input type='number' class='pos-qty' name='qty[]' value='1' min='1' max='${product.quantity}'></td>`;
                table.appendChild(tr);
            }
            
            // ADDED: Hide empty state when products are added
            updateEmptyStateVisibility();
            savePosTableToStorage(); // Save to localStorage
        }
        
        // ADDED: Function to show/hide empty state based on table content
        function updateEmptyStateVisibility() {
            const table = document.getElementById('posTable');
            const emptyState = document.getElementById('posEmptyState');
            const hasProducts = table.querySelectorAll('tr[data-product-id]').length > 0;
            
            if (hasProducts) {
                emptyState.style.display = 'none';
            } else {
                emptyState.style.display = 'flex';
            }
        }
        
        // Save POS table to localStorage on input change
        document.getElementById('posTable').addEventListener('input', function (e) {
            if (e.target.classList.contains('pos-qty')) {
                savePosTableToStorage();
            }
        });

        // Show alert and clear POS table if payment is settled
        window.addEventListener('DOMContentLoaded', function () {
            // ADDED: Update empty state visibility on page load
            updateEmptyStateVisibility();
            
            if (window.location.search.includes('ok=1')) {
                alert('Payment settled! Transaction recorded.');
                // Clear POS table and localStorage
                const table = document.getElementById('posTable');
                while (table.rows.length > 1) {
                    table.deleteRow(1);
                }
                localStorage.removeItem('posTable');
                
                // ADDED: Show empty state after clearing
                updateEmptyStateVisibility();

                // Remove ok=1 from the URL without reloading
                if (window.history.replaceState) {
                    const url = window.location.href.replace(/(\?|&)ok=1/, '');
                    window.history.replaceState({}, document.title, url);
                }
            }
        });


        // Prevent checkout if no products in POS table
        //Modal overlay 
        document.getElementById('posForm').addEventListener('submit', function (e) {
            const rows = document.querySelectorAll('#posTable tr[data-product-id]');
            if (rows.length === 0) {
                e.preventDefault();
                document.getElementById('productModal').style.display = 'none'; // close modal if open
                alert('No item selected in POS!');
            }
        });


        // UPDATED: Modified clear function to show empty state
        document.getElementById('clearPosTable').onclick = function () {
            const table = document.getElementById('posTable');
            // Remove all rows except the first (header)
            while (table.rows.length > 1) {
                table.deleteRow(1);
            }
            
            // ADDED: Show empty state after clearing
            updateEmptyStateVisibility();
            savePosTableToStorage(); // Save to localStorage
            localStorage.removeItem('posTable'); // Clear localStorage
        };
        
        // Toggle user dropdown
        function toggleDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Toggle mobile sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function (event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');

            if (!userMenu.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function (event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-toggle');

            if (window.innerWidth <= 768 &&
                !sidebar.contains(event.target) &&
                !toggle.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });
    </script>
    <!-- Total Sales Panel -->
     <script src="../assets/js/totalPanel.js"></script>
     

</body>

</html>