<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    $newStatus = $_POST['new_status'] ?? '';
    $allowedStatuses = ['pending','approved','completed'];

    if($orderId > 0 && in_array($newStatus, $allowedStatuses, true)) {
        $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $orderId]);
        header('Location: pos.php?status_updated=1');
        exit;
    }

    header('Location: pos.php?status_updated=0');
    exit;
}

$products = $pdo->query('SELECT * FROM products')->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['pos_checkout'])) {
    // simple POS flow: product_id[], qty[]
    $items = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $amount_paid = floatval($_POST['amount_paid'] ?? 0); // FIX: Get amount paid from form
    
    if (empty($items)) {
        echo "<script>alert('No item selected in POS!'); window.location='pos.php';</script>";
        exit;
    }

    $salesTotal = 0;
    foreach($items as $i=>$pid){
        $pstmt = $pdo->prepare('SELECT * FROM products WHERE id=?');
        $pstmt->execute([intval($pid)]);
        $p = $pstmt->fetch();
        if($p){
            $q = max(1,intval($qtys[$i]));
            if ($q > $p['quantity']) {
                $q = $p['quantity']; // clamp to available
            }
            $salesTotal += $p['price'] * $q;
        }
    }

    // FIX: Validate payment amount
    if ($amount_paid < $salesTotal) {
        echo "<script>alert('Insufficient payment amount!'); window.location='pos.php';</script>";
        exit;
    }

    // Calculate VAT components
    $vatable = $salesTotal / 1.12;
    $vat = $salesTotal - $vatable;
    $change = $amount_paid - $salesTotal; // FIX: Calculate change

    try {
        // Begin transaction
        $pdo->beginTransaction();

        // FIX: Create order with amount_paid and change
        $stmt = $pdo->prepare('INSERT INTO orders (customer_name, contact, address, total, payment_method, status, vatable, vat, amount_paid, change_amount) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute(['Walk-in', 'N/A', 'N/A', $salesTotal, 'Cash', 'completed', $vatable, $vat, $amount_paid, $change]);
        $order_id = $pdo->lastInsertId();

        // Process items
        foreach($items as $i=>$pid){
            $pstmt = $pdo->prepare('SELECT * FROM products WHERE id=?');
            $pstmt->execute([intval($pid)]);
            $p = $pstmt->fetch();
            if($p){
                $q = max(1,intval($qtys[$i]));
                if ($q > $p['quantity']) {
                    $q = $p['quantity'];
                }
                // Insert order item
                $pdo->prepare('INSERT INTO order_items (order_id,product_id,qty,price) VALUES (?,?,?,?)')
                    ->execute([$order_id, $p['id'], $q, $p['price']]);
                
                // Update product quantity
                $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')
                    ->execute([$q, $p['id']]);
            }
        }

        // Commit transaction
        $pdo->commit();
        
        // FIX: Pass transaction data to the success page
        header('Location: pos.php?ok=1&order_id=' . $order_id . '&amount_paid=' . $amount_paid . '&change=' . $change);
        exit;
    } catch (Exception $e) {
        // Rollback on error
        $pdo->rollBack();
        echo "<script>alert('Error processing transaction: " . addslashes($e->getMessage()) . "'); window.location='pos.php';</script>";
        exit;
    }
}


    SELECT * FROM orders
    WHERE
        (payment_method IS NOT NULL AND payment_method <> '' AND LOWER(payment_method) <> 'cash')
        OR (payment_proof IS NOT NULL AND payment_proof <> '')
        OR status IN ('pending','approved')
    ORDER BY created_at DESC
");

$onlineOrdersStmt->execute();
$onlineOrders = $onlineOrdersStmt->fetchAll();
foreach ($onlineOrders as &$onlineOrder) {
    $details = parsePaymentProofValue($onlineOrder['payment_proof'] ?? null);
    $onlineOrder['reference_number'] = $details['reference'];
    $onlineOrder['proof_image'] = $details['image'];
}
unset($onlineOrder);
$statusOptions = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'completed' => 'Completed'
];
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

        <div class="pos-tabs">
            <button type="button" class="pos-tab-button active" data-target="walkinTab">
                <i class="fas fa-store"></i>
                POS Checkout
            </button>
            <button type="button" class="pos-tab-button" data-target="onlineTab">
                <i class="fas fa-shopping-bag"></i>
                Online Orders
            </button>
        </div>

        <div id="walkinTab" class="tab-panel active">
        <div style="display: flex; align-items: center; gap: 0px; margin: 15px 0; flex-wrap: wrap;">
            <button type="button" id="openProductModal"
                style="padding: 8px 18px; background: #3498db; color: #fff; border: none; border-radius: 6px; font-size: 15px; cursor: pointer;"><i
                    class="fas fa-search"></i> Search Product</button>
                    <!-- Large total display -->
            <div class="top-total-simple" style="font-size: 2.2rem; font-weight: bold; color: #111; min-width: 85%; text-align: right;">
                <span id="topTotalAmountSimple">₱0.00</span>
        </div>
        <!-- Optionally keep the small total below for mobile or reference -->
        <div class="top-total" style="display:none;">Total Amount: ₱ <span id="topTotalAmount">0.00</span></div>
                   
        
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
                    <label>Sales Total</label>
                    <div id="salesTotalAmount" class="value">₱0.00</div>
                </div>
                <div class="totals-item">
                    <label>Discount</label>
                    <div id="discountAmount" class="value">₱0.00</div>
                </div>
                <div class="totals-item">
                    <label>Vatable</label>
                    <div id="vatableAmount" class="value">₱0.00</div>
                </div>
                <div class="totals-item">
                    <label>VAT (12%)</label>
                    <div id="vatAmount" class="value">₱0.00</div>
                </div>
                <div class="totals-item">
                    <label for="amountReceived">Amount Received</label>
                    <input type="number" id="amountReceived" name="amount_paid" min="0" step="0.01" placeholder="0.00">
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

        <!-- Receipt Preview Modal -->
        <div id="receiptModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:10px; max-width:400px; width:95%; margin:auto; padding:24px; position:relative; box-shadow:0 8px 32px rgba(0,0,0,0.18);">
                <button id="closeReceiptModal" style="position:absolute; top:10px; right:10px; background:none; border:none; font-size:20px; color:#888; cursor:pointer;">&times;</button>
                <div id="receiptContent" style="font-family: 'Courier New', monospace; font-size: 14px;">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">DGZ Motorshop</h2>
                        <p style="margin: 5px 0;">123 Main Street</p>
                        <p style="margin: 5px 0;">Phone: (123) 456-7890</p>
                        <p style="margin: 5px 0;">Receipt #: <span id="receiptNumber"></span></p>
                        <p style="margin: 5px 0;">Date: <span id="receiptDate"></span></p>
                        <p style="margin: 5px 0;">Cashier: <span id="receiptCashier"></span></p>
                    </div>
                    <div style="border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 10px 0; margin: 10px 0;">
                        <table id="receiptItems" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left;">Item</th>
                                    <th style="text-align: right;">Qty</th>
                                    <th style="text-align: right;">Price</th>
                                    <th style="text-align: right;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Items will be inserted here -->
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 10px;">
                        <div style="display: flex; justify-content: space-between;">
                            <span>Sales Total:</span>
                            <span id="receiptSalesTotal">₱0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>Discount:</span>
                            <span id="receiptDiscount">₱0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>Vatable:</span>
                            <span id="receiptVatable">₱0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>VAT (12%):</span>
                            <span id="receiptVat">₱0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>Amount Paid:</span>
                            <span id="receiptAmountPaid">₱0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span>Change:</span>
                            <span id="receiptChange">₱0.00</span>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 20px;">
                        <p>Thank you for shopping!</p>
                        <p>Please come again</p>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <button onclick="printReceipt()" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-print"></i> Print Receipt
                    </button>
                </div>
            </div>
        </div>

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
        </div><!-- /#walkinTab -->

        <div id="onlineTab" class="tab-panel">
            <?php if(isset($_GET['status_updated'])): ?>
                <?php $success = $_GET['status_updated'] === '1'; ?>
                <div class="status-alert <?= $success ? 'success' : 'error' ?>">
                    <i class="fas <?= $success ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= $success ? 'Order status updated.' : 'Unable to update order status.' ?>
                </div>
            <?php endif; ?>

            <div class="online-orders-container">
                <table class="online-orders-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Total</th>
                            <th>Reference</th>
                            <th>Proof</th>
                            <th>Status</th>
                            <th>Placed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($onlineOrders)): ?>
                            <tr>
                                <td colspan="8" class="empty-cell">
                                    <i class="fas fa-inbox"></i>
                                    No online orders yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($onlineOrders as $order): ?>
                                <?php $imagePath = $order['proof_image'] ? '../' . ltrim($order['proof_image'], '/') : ''; ?>
                                <tr>
                                    <td>#<?= (int) $order['id'] ?></td>
                                    <td><?= htmlspecialchars($order['customer_name'] ?? 'Customer') ?></td>
                                    <td><?= htmlspecialchars($order['contact'] ?? 'N/A') ?></td>
                                    <td>₱<?= number_format((float) $order['total'], 2) ?></td>
                                    <td>
                                        <?php if(!empty($order['reference_number'])): ?>
                                            <span class="reference-badge"><?= htmlspecialchars($order['reference_number']) ?></span>
                                        <?php else: ?>
                                            <span class="muted">Not provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button"
                                            class="view-proof-btn"
                                            data-image="<?= htmlspecialchars($imagePath) ?>"
                                            data-reference="<?= htmlspecialchars($order['reference_number'] ?? '') ?>"
                                            data-customer="<?= htmlspecialchars($order['customer_name'] ?? 'Customer') ?>">
                                            <i class="fas fa-receipt"></i> View
                                        </button>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars(ucfirst($order['status'])) ?></span>
                                        <form method="post" class="status-form">
                                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                            <input type="hidden" name="update_order_status" value="1">
                                            <select name="new_status">
                                                <?php foreach($statusOptions as $value => $label): ?>
                                                    <option value="<?= $value ?>" <?= $order['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="status-save">Update</button>
                                        </form>
                                    </td>
                                    <td><?= date('M d, Y g:i A', strtotime($order['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="proofModal" class="proof-modal" aria-hidden="true">
            <div class="proof-modal-content">
                <button type="button" class="proof-close" id="closeProofModal">&times;</button>
                <h3 class="proof-title">Payment Proof</h3>
                <p class="proof-reference">Reference: <span id="proofReferenceValue">Not provided</span></p>
                <p class="proof-customer">Customer: <span id="proofCustomerName">N/A</span></p>
                <div class="proof-image-wrapper">
                    <img id="proofImage" src="" alt="Payment proof preview" />
                    <div id="proofNoImage" class="proof-empty">No proof uploaded.</div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Prepare product data for search (from PHP)
const allProducts = [<?php foreach($products as $p) : ?> {
    id: <?= json_encode($p['id']) ?>,
    name: <?= json_encode($p['name']) ?>,
    price: <?= json_encode($p['price']) ?>,
    quantity: <?= json_encode($p['quantity']) ?>
}, <?php endforeach; ?>];

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
    
    // Check if already in table
    const table = document.getElementById('posTable');
    const existing = table.querySelector(`tr[data-product-id='${product.id}']`);
    if (existing) {
        // If already present, increment qty
        const qtyInput = existing.querySelector('input[type=number]');
        qtyInput.value = Math.min(parseInt(qtyInput.value) + 1, product.quantity);
    } else {
        // Add new row with remove button instead of checkbox
        const tr = document.createElement('tr');
        tr.setAttribute('data-product-id', product.id);
        tr.innerHTML = `
            <td class="pos-name">${product.name}</td>
            <td class="pos-price">₱${parseFloat(product.price).toFixed(2)}</td>
            <td class="pos-available">${product.quantity}</td>
            <td style="display: flex; align-items: center; gap: 8px;">
                <input type='hidden' name='product_id[]' value='${product.id}'>
                <input type='number' class='pos-qty' name='qty[]' value='1' min='1' max='${product.quantity}'>
                <button type='button' class='remove-btn' onclick='removeProductFromPOS(this)' 
                        style='background:#e74c3c; color:#fff; border:none; border-radius:3px; padding:4px 6px; cursor:pointer; font-size:11px; min-width:24px; height:24px; display:flex; align-items:center; justify-content:center;'>
                    <i class='fas fa-times'></i>
                </button>
            </td>`;
        table.appendChild(tr);
    }
    
    // Hide empty state when products are added
    updateEmptyStateVisibility();
    savePosTableToStorage();
    
    // FIXED: Always call recalcTotal after adding product
    recalcTotal();
}

// Filter table as user types
document.getElementById('productSearchInput').oninput = function () {
    renderProductTable(this.value.trim());
};

// FIXED: Function to remove product from POS table
function removeProductFromPOS(button) {
    const tr = button.closest('tr');
    tr.remove();
    updateEmptyStateVisibility();
    savePosTableToStorage();
    
    // FIXED: Always call recalcTotal after removing product
    recalcTotal();
}

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

// Save POS table data to localStorage
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

// Function to show/hide empty state based on table content
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
// Add this function at the top of your script section
function formatPeso(n) {
    n = Number(n) || 0;
    return '₱' + n.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// FIXED: Live update for totals panel with proper formatting
function recalcTotal() {
    let subtotal = 0;
    document.querySelectorAll('#posTable tr[data-product-id]').forEach(row => {
        const price = parseFloat(row.querySelector('.pos-price').textContent.replace(/[^\d.-]/g, ''));
        const qty = parseInt(row.querySelector('.pos-qty').value) || 0;
        subtotal += price * qty;
    });
    
    const salesTotal = subtotal;
    const discount = 0;
    const vatable = salesTotal / 1.12;
    const vat = Math.round((salesTotal - vatable) * 100) / 100;
    
    // Update all totals displays with proper formatting
    document.getElementById('salesTotalAmount').textContent = formatPeso(salesTotal);
    document.getElementById('discountAmount').textContent = formatPeso(discount);
    document.getElementById('vatableAmount').textContent = formatPeso(vatable);
    document.getElementById('vatAmount').textContent = formatPeso(vat);
    document.getElementById('topTotalAmountSimple').textContent = formatPeso(salesTotal);
    
    // FIX: Proper change calculation with validation
    const amountReceived = parseFloat(document.getElementById('amountReceived').value) || 0;
    let change = 0;
    
    if (amountReceived >= salesTotal && salesTotal > 0) {
        change = amountReceived - salesTotal;
    } else if (amountReceived > 0 && salesTotal > 0) {
        // If amount received is less than total, show negative change (insufficient)
        change = amountReceived - salesTotal;
    }
    
    document.getElementById('changeAmount').textContent = formatPeso(change);
    
    // FIX: Add visual feedback for insufficient payment
    const changeElement = document.getElementById('changeAmount');
    if (change < 0 && salesTotal > 0) {
        changeElement.style.color = '#e74c3c';
        changeElement.title = 'Insufficient payment';
    } else {
        changeElement.style.color = '#27ae60';
        changeElement.title = '';
    }
}
// FIX: Improved form validation before checkout
document.getElementById('posForm').addEventListener('submit', function (e) {
    const rows = document.querySelectorAll('#posTable tr[data-product-id]');
    if (rows.length === 0) {
        e.preventDefault();
        document.getElementById('productModal').style.display = 'none';
        alert('No item selected in POS!');
        return;
    }
    
    const salesTotal = parseFloat(document.getElementById('salesTotalAmount').textContent.replace(/[^\d.-]/g, '')) || 0;
    const amountReceived = parseFloat(document.getElementById('amountReceived').value) || 0;
    
    // FIX: Better validation messages
    if (amountReceived <= 0) {
        e.preventDefault();
        alert('Please enter the amount received from customer!');
        document.getElementById('amountReceived').focus();
        return;
    }
    
    if (amountReceived < salesTotal) {
        e.preventDefault();
        const shortage = salesTotal - amountReceived;
        alert(`Insufficient payment! Need ${formatPeso(shortage)} more.`);
        document.getElementById('amountReceived').focus();
        return;
    }
});

// Also update the generateReceipt function to use proper formatting
// FIX: Generate receipt with actual transaction data
function generateReceiptFromTransaction() {
    const urlParams = new URLSearchParams(window.location.search);
    const amountPaid = parseFloat(urlParams.get('amount_paid')) || 0;
    const change = parseFloat(urlParams.get('change')) || 0;
    const orderId = urlParams.get('order_id') || '';
    
    const items = [];
    let salesTotal = 0;
    
    // Get items from localStorage (they should still be there)
    const savedData = JSON.parse(localStorage.getItem('posTable') || '[]');
    savedData.forEach(item => {
        const price = parseFloat(item.price.replace(/[^\d.-]/g, ''));
        const qty = parseInt(item.qty);
        const total = price * qty;
        salesTotal += total;
        
        items.push({ 
            name: item.name, 
            price: price, 
            qty: qty, 
            total: total 
        });
    });

    const discount = 0;
    const vatable = salesTotal / 1.12;
    const vat = Math.round((salesTotal - vatable) * 100) / 100;

    // Populate receipt with actual transaction data
    document.getElementById('receiptNumber').textContent = 'INV-' + (orderId || Date.now());
    document.getElementById('receiptDate').textContent = new Date().toLocaleString();
    document.getElementById('receiptCashier').textContent = 'Admin';

    const tbody = document.getElementById('receiptItems').querySelector('tbody');
    tbody.innerHTML = '';
    items.forEach(item => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="text-align: left">${item.name}</td>
            <td style="text-align: right">${item.qty}</td>
            <td style="text-align: right">${formatPeso(item.price)}</td>
            <td style="text-align: right">${formatPeso(item.total)}</td>
        `;
        tbody.appendChild(tr);
    });

    // Update receipt totals with actual transaction data
    document.getElementById('receiptSalesTotal').textContent = formatPeso(salesTotal);
    document.getElementById('receiptDiscount').textContent = formatPeso(discount);
    document.getElementById('receiptVatable').textContent = formatPeso(vatable);
    document.getElementById('receiptVat').textContent = formatPeso(vat);
    document.getElementById('receiptAmountPaid').textContent = formatPeso(amountPaid);
    document.getElementById('receiptChange').textContent = formatPeso(change);

    // Show receipt modal
    document.getElementById('receiptModal').style.display = 'flex';
}
// FIX: Updated DOMContentLoaded handler
window.addEventListener('DOMContentLoaded', function () {
    const data = JSON.parse(localStorage.getItem('posTable') || '[]');
    data.forEach(item => {
        const product = allProducts.find(p => p.id == item.id);
        if (product) {
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
        }
    });
    
    // Update empty state and recalculate totals
    updateEmptyStateVisibility();
    recalcTotal();
    
    // FIX: Handle checkout success with proper data
    if (window.location.search.includes('ok=1')) {
        generateReceiptFromTransaction();
        
        // Clear POS table and localStorage after showing receipt
        setTimeout(() => {
            const table = document.getElementById('posTable');
            while (table.rows.length > 1) {
                table.deleteRow(1);
            }
            localStorage.removeItem('posTable');
            
            updateEmptyStateVisibility();
            recalcTotal();
        }, 1000); // Small delay to ensure receipt is generated first

        // Remove URL parameters without reloading
        if (window.history.replaceState) {
            const url = window.location.pathname;
            window.history.replaceState({}, document.title, url);
        }
    }
});

// FIX: Add input validation for amount received
document.getElementById('amountReceived').addEventListener('input', function() {
    // Ensure only positive numbers
    if (this.value < 0) {
        this.value = 0;
    }
    recalcTotal();
});

// FIX: Add Enter key support for amount received input
document.getElementById('amountReceived').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        document.querySelector('button[name="pos_checkout"]').click();
    }
});
// Function to print receipt
function printReceipt() {
    const receiptContent = document.getElementById('receiptContent').innerHTML;
    const w = window.open('', '_blank');
    w.document.write(`
        <html>
            <head>
                <title>Print Receipt</title>
                <style>
                    body { font-family: 'Courier New', monospace; font-size: 14px; }
                    @media print {
                        @page { margin: 0; }
                        body { margin: 1cm; }
                    }
                </style>
            </head>
            <body>${receiptContent}</body>
        </html>
    `);
    w.document.close();
    w.focus();
    w.print();
    w.close();
}

// Close receipt modal
document.getElementById('closeReceiptModal').onclick = function() {
    document.getElementById('receiptModal').style.display = 'none';
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

// FIXED: Restore POS table from localStorage on page load
window.addEventListener('DOMContentLoaded', function () {
    const data = JSON.parse(localStorage.getItem('posTable') || '[]');
    data.forEach(item => {
        const product = allProducts.find(p => p.id == item.id);
        if (product) {
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
        }
    });
    
    // Update empty state and recalculate totals
    updateEmptyStateVisibility();
    recalcTotal();
    
    // Handle checkout success
    if (window.location.search.includes('ok=1')) {
        generateReceipt();
        // Clear POS table and localStorage
        const table = document.getElementById('posTable');
        while (table.rows.length > 1) {
            table.deleteRow(1);
        }
        localStorage.removeItem('posTable');
        
        updateEmptyStateVisibility();
        recalcTotal();

        // Remove ok=1 from the URL without reloading
        if (window.history.replaceState) {
            const url = window.location.href.replace(/(\?|&)ok=1/, '');
            window.history.replaceState({}, document.title, url);
        }
    }
});

// FIXED: Event listeners for real-time updates
document.addEventListener('DOMContentLoaded', function() {
    // Listen for quantity changes using event delegation
    document.getElementById('posTable').addEventListener('input', function(e) {
        if (e.target.classList.contains('pos-qty')) {
            savePosTableToStorage();
            recalcTotal();
        }
    });
    
    // Listen for amount received changes
    document.getElementById('amountReceived').addEventListener('input', function() {
        recalcTotal();
    });
});

// Prevent checkout if no products in POS table or insufficient payment
document.getElementById('posForm').addEventListener('submit', function (e) {
    const rows = document.querySelectorAll('#posTable tr[data-product-id]');
    if (rows.length === 0) {
        e.preventDefault();
        document.getElementById('productModal').style.display = 'none';
        alert('No item selected in POS!');
        return;
    }
    
    const salesTotal = parseFloat(document.getElementById('salesTotalAmount').textContent.replace(/[^\d.-]/g, '')) || 0;
    const amountReceived = parseFloat(document.getElementById('amountReceived').value) || 0;
    if (amountReceived < salesTotal) {
        e.preventDefault();
        alert('Insufficient payment amount!');
        return;
    }
});

// FIXED: Clear function to show empty state and recalculate
document.getElementById('clearPosTable').onclick = function () {
    const table = document.getElementById('posTable');
    // Remove all rows except the first (header)
    while (table.rows.length > 1) {
        table.deleteRow(1);
    }

    updateEmptyStateVisibility();
    localStorage.removeItem('posTable');

    // FIXED: Recalculate totals after clearing
    recalcTotal();
};

// Tab navigation for POS and online orders
const tabButtons = document.querySelectorAll('.pos-tab-button');
const tabPanels = document.querySelectorAll('.tab-panel');
tabButtons.forEach(button => {
    button.addEventListener('click', () => {
        tabButtons.forEach(btn => btn.classList.remove('active'));
        tabPanels.forEach(panel => panel.classList.remove('active'));

        button.classList.add('active');
        const target = document.getElementById(button.dataset.target);
        if (target) {
            target.classList.add('active');
        }
    });
});

// Proof of payment modal logic
const proofModal = document.getElementById('proofModal');
const proofImage = document.getElementById('proofImage');
const proofReferenceValue = document.getElementById('proofReferenceValue');
const proofCustomerName = document.getElementById('proofCustomerName');
const proofNoImage = document.getElementById('proofNoImage');

function closeProofModal() {
    proofModal.classList.remove('show');
    proofModal.setAttribute('aria-hidden', 'true');
    proofImage.removeAttribute('src');
    proofImage.style.display = 'none';
    proofNoImage.style.display = 'none';
}

document.querySelectorAll('.view-proof-btn').forEach(button => {
    button.addEventListener('click', () => {
        const image = button.dataset.image;
        const reference = button.dataset.reference || '';
        const customer = button.dataset.customer || 'Customer';

        proofReferenceValue.textContent = reference !== '' ? reference : 'Not provided';
        proofCustomerName.textContent = customer;

        if (image) {
            proofImage.src = image;
            proofImage.style.display = 'block';
            proofNoImage.style.display = 'none';
        } else {
            proofImage.removeAttribute('src');
            proofImage.style.display = 'none';
            proofNoImage.style.display = 'flex';
        }

        proofModal.classList.add('show');
        proofModal.setAttribute('aria-hidden', 'false');
    });
});

document.getElementById('closeProofModal').addEventListener('click', closeProofModal);
proofModal.addEventListener('click', (event) => {
    if (event.target === proofModal) {
        closeProofModal();
    }
});
    </script>
    <!-- Total Sales Panel 
    <script src="../assets/js/totalPanel.js"></script>-->
    
     

</body>

</html>