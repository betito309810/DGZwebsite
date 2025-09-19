<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
// Product Add History for modal (HTML table, not JSON)
if (isset($_GET['history']) && $_GET['history'] == '1') {
    $sql = "
        SELECT h.created_at, h.action, h.details,
               p.code AS product_code, p.name AS product_name,
               p.price, p.quantity, p.brand, p.category,
               COALESCE(u.name, CONCAT('User #', h.user_id)) AS added_by
        FROM product_add_history h
        LEFT JOIN products p ON p.id = h.product_id
        LEFT JOIN users u ON u.id = h.user_id
        ORDER BY h.created_at DESC
        LIMIT 10
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <table class="entries-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Product Code</th>
                <th>Product Name</th>
                <th>Brand</th>
                <th>Category</th>
                <th>Price</th>
                <th>Quantity</th>
                <th>Added by</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $entry): ?>
                <tr>
                    <td><?= date('M d, Y H:i', strtotime($entry['created_at'])) ?></td>
                    <td><span class="product-code"><?= htmlspecialchars($entry['product_code'] ?? '-') ?></span></td>
                    <td><span class="product-name"><?= htmlspecialchars($entry['product_name'] ?? '-') ?></span></td>
                    <td><span class="brand-badge"><?= htmlspecialchars($entry['brand'] ?? '-') ?></span></td>
                    <td><span class="category-badge"><?= htmlspecialchars($entry['category'] ?? '-') ?></span></td>
                    <td><span class="price">₱<?= number_format($entry['price'] ?? 0, 2) ?></span></td>
                    <td><span class="quantity"><?= number_format($entry['quantity'] ?? 0) ?></span></td>
                    <td><span class="user-name"><?= htmlspecialchars($entry['added_by'] ?? '-') ?></span></td>
                    <td>
                        <?php 
                            $action = $entry['action'] ?? 'add';
                            $actionClass = 'action-badge';
                            $actionText = '';
                            
                            switch($action) {
                                case 'edit':
                                    $actionClass .= ' action-edit';
                                    $actionText = 'Edited';
                                    break;
                                case 'delete':
                                    $actionClass .= ' action-delete';
                                    $actionText = 'Deleted';
                                    break;
                                default:
                                    $actionClass .= ' action-add';
                                    $actionText = 'Added';
                            }
                        ?>
                        <span class="<?= $actionClass ?>"><?= $actionText ?></span>
                        <?php if (!empty($entry['details'])): ?>
                            <div class="action-details"><?= htmlspecialchars($entry['details']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" style="text-align:center;">No product history</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <style>
        /* Match stockEntry.php table styles */
        .entries-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border: 1px solid #eee;
            font-size: 0.95em;
        }
        .entries-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        .entries-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            color: #555;
        }
        .entries-table tbody tr:hover {
            background: #f8f9fa;
        }
        .product-code {
            font-family: monospace;
            padding: 2px 6px;
            background: #f1f1f1;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .product-name {
            font-weight: 500;
            color: #333;
        }
        .brand-badge, .category-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        .brand-badge {
            background: #e3f2fd;
            color: #1976d2;
        }
        .category-badge {
            background: #fff3e0;
            color: #f57c00;
        }
        .price {
            font-weight: 500;
            color: #2e7d32;
            white-space: nowrap;
        }
        .quantity {
            font-family: monospace;
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            color: #333;
        }
        .user-name {
            color: #666;
        }
        .action-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            font-weight: 500;
            margin-bottom: 4px;
        }
        .action-add {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .action-edit {
            background: #e3f2fd;
            color: #1565c0;
        }
        .action-delete {
            background: #fbe9e7;
            color: #d84315;
        }
        .action-details {
            font-size: 0.85em;
            color: #666;
            margin-top: 2px;
            white-space: normal;
            max-width: 300px;
        }
    </style>
    <?php
    exit;
}

if(isset($_GET['delete'])) {
    $product_id = intval($_GET['delete']);
    
    // Get product details before deletion for history
    $product = $pdo->query("SELECT * FROM products WHERE id = $product_id")->fetch();
    if ($product) {
        // Record deletion in history
        $details = "Deleted product - Code: {$product['code']}, Stock: {$product['quantity']}, Price: ₱{$product['price']}";
        $pdo->prepare('INSERT INTO product_add_history (product_id, user_id, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$product_id, $_SESSION['user_id'], 'delete', $details]);
            
        // Delete the product
        $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$product_id]);
    }
    header('Location: products.php'); 
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_product'])){
    $name = $_POST['name']; 
    $code = $_POST['code']; 
    $desc = $_POST['description']; 
    $price = floatval($_POST['price']); 
    $qty = intval($_POST['quantity']); 
    $low = intval($_POST['low_stock_threshold']);
    $id = intval($_POST['id']);
    
    // Handle brand and category
    $brand = $_POST['brand'];
    if($brand === '__addnew__' && !empty($_POST['brand_new'])) {
        $brand = $_POST['brand_new'];
    }
    
    $category = $_POST['category'];
    if($category === '__addnew__' && !empty($_POST['category_new'])) {
        $category = $_POST['category_new'];
    }
    
    $supplier = $_POST['supplier'];
    if($supplier === '__addnew__' && !empty($_POST['supplier_new'])) {
        $supplier = $_POST['supplier_new'];
    }

    $user_id = $_SESSION['user_id'];
    
    if($id > 0) {
        // Get old product data for history
        $old_product = $pdo->query("SELECT * FROM products WHERE id = $id")->fetch();
        
        // Update product
        $stmt = $pdo->prepare('UPDATE products SET code=?, name=?, description=?, price=?, quantity=?, low_stock_threshold=?, brand=?, category=?, supplier=? WHERE id=?');
        $stmt->execute([$code, $name, $desc, $price, $qty, $low, $brand, $category, $supplier, $id]);
        
        // Record edit in history
        $changes = [];
        if($old_product['name'] !== $name) $changes[] = "name: {$old_product['name']} → $name";
        if($old_product['price'] != $price) $changes[] = "price: ₱{$old_product['price']} → ₱$price";
        if($old_product['quantity'] != $qty) $changes[] = "quantity: {$old_product['quantity']} → $qty";
        if($old_product['brand'] !== $brand) $changes[] = "brand: {$old_product['brand']} → $brand";
        if($old_product['category'] !== $category) $changes[] = "category: {$old_product['category']} → $category";
        
        $details = !empty($changes) ? "Changes: " . implode(", ", $changes) : "";
        $pdo->prepare('INSERT INTO product_add_history (product_id, user_id, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$id, $user_id, 'edit', $details]);
    } else {
        // Insert new product
        $stmt = $pdo->prepare('INSERT INTO products (code, name, description, price, quantity, low_stock_threshold, brand, category, supplier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$code, $name, $desc, $price, $qty, $low, $brand, $category, $supplier]);
        $product_id = $pdo->lastInsertId();
        
        // Record addition in history
        $details = "Initial stock: $qty, Price: ₱$price, Brand: $brand, Category: $category";
        $pdo->prepare('INSERT INTO product_add_history (product_id, user_id, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$product_id, $user_id, 'add', $details]);
    }
    header('Location: products.php'); exit;
}
$products = $pdo->query('SELECT * FROM products')->fetchAll();
// Fetch unique brands and categories
$brands = $pdo->query('SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != ""')->fetchAll(PDO::FETCH_COLUMN);
$categories = $pdo->query('SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != ""')->fetchAll(PDO::FETCH_COLUMN);
$suppliers = $pdo->query('SELECT DISTINCT supplier FROM products WHERE supplier IS NOT NULL AND supplier != ""')->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/products.css">

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
                <a href="products.php" class="nav-link active">
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
                <a href="pos.php" class="nav-link">
                    <i class="fas fa-cash-register nav-icon"></i>
                    POS
                </a>
            </div>
            <div class="nav-item">
                <a href="Inventory.php" class="nav-link">
                    <i class="fas fa-boxes nav-icon"></i>
                    Inventory
                </a>
            </div>
             <div class="nav-item">
                <a href="stockEntry.php" class="nav-link ">
                    <i class="fas fa-truck-loading nav-icon"></i>
                    Stock Entry
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
                <h2>Products - Add / Edit </h2>
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
        <div style="display:flex; gap:12px; margin-bottom:12px;">
            <button id="openAddModal" class="add-btn" type="button">
                <i class="fas fa-plus"></i> Add Product
            </button>
            <button id="openHistoryModal" class="history-btn" type="button">
                <i class="fas fa-history"></i> History
            </button>
        </div>

<!-- Product Add History Modal (like stockEntry.php) -->
<div id="historyModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:flex-end;">
    <div class="modal-content" style="background:white; padding:30px; border-radius:10px; width:100%; max-width:1600px; position:relative; box-shadow:0 4px 6px rgba(0,0,0,0.1); margin:50px;">
        <button type="button" id="closeHistoryModal" style="position:absolute; top:20px; right:25px; background:none; border:none; font-size:24px; color:#888; cursor:pointer;">&times;</button>
        <h3 style="margin:0 0 20px 0; font-size:1.5em; color:#333;">Product Add History</h3>
        <div class="recent-entries" style="width:100%;">
            <div id="historyList" style="max-height:610px; overflow-y:auto; width:100%; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.05); border:1px solid #e2e8f0;"></div>
        </div>
    </div>
</div>

<script>
// Product Add History Modal functionality (like stockEntry.php)
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('historyModal');
    const list = document.getElementById('historyList');
    
    // Open modal and load history
    document.getElementById('openHistoryModal').onclick = function() {
        modal.style.display = 'flex';
        list.innerHTML = '<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>';
        
        fetch('products.php?history=1', {cache:'no-store'})
            .then(response => response.text())
            .then(html => list.innerHTML = html)
            .catch(error => list.innerHTML = '<div style="text-align:center;color:#dc3545;padding:20px;">Error loading history: ' + error + '</div>');
    };

    // Close modal on X button click
    document.getElementById('closeHistoryModal').onclick = function() {
        modal.style.display = 'none';
    };

    // Close modal on outside click
    modal.onclick = function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    };

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            modal.style.display = 'none';
        }
    });
});
document.getElementById('historyModal').addEventListener('click', function (e) {
    if (e.target === this) this.style.display = 'none';
});
</script>
<!-- Add Product Modal -->
<div id="addModal"
    style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
    <div class="modal-content-horizontal" style="display:grid; grid-template-columns: 2fr 1fr; gap:40px; max-width:820px; width:95vw; padding:36px 36px 28px 36px; border-radius:16px; background:#fff; box-shadow:0 8px 32px rgba(0,0,0,0.18); position:relative; align-items:start;">
        <button type="button" id="closeAddModal"
            style="position:absolute; top:14px; right:18px; background:none; border:none; font-size:24px; color:#888;">&times;</button>
        <form method="post" enctype="multipart/form-data" style="display:grid; grid-template-columns: 1fr 1fr; gap:18px 24px; width:100%; align-items:start; background:none; box-shadow:none; border:none; padding:0; margin:0;">
            <h3 style="margin-bottom:8px; grid-column:1/3;">Add Product</h3>
            <input type="hidden" name="id" value="0">
            <label style="grid-column:1;">Product Code:
                <input name="code" required placeholder="Enter product code">
            </label>
            <label style="grid-column:2;">Name:
                <input name="name" required placeholder="Enter product name">
            </label>
            <label style="grid-column:1;">Brand:
                <select name="brand" id="brandSelect" onchange="toggleBrandInput(this)">
                    <option value="">Select brand</option>
                    <?php foreach($brands as $b): ?>
                        <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                    <?php endforeach; ?>
                    <option value="__addnew__">Add new brand...</option>
                </select>
                <input name="brand_new" id="brandNewInput" placeholder="Enter new brand" style="display:none; margin-top:6px;">
            </label>
            <label style="grid-column:2;">Category:
                <select name="category" id="categorySelect" onchange="toggleCategoryInput(this)">
                    <option value="">Select category</option>
                    <?php foreach($categories as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                    <option value="__addnew__">Add new category...</option>
                </select>
                <input name="category_new" id="categoryNewInput" placeholder="Enter new category" style="display:none; margin-top:6px;">
            </label>
            <label style="grid-column:1/3;">Description:
                <textarea name="description" placeholder="Enter product description"></textarea>
            </label>
            <label style="grid-column:1;">Quantity:
                <input name="quantity" type="number" min="0" required placeholder="Enter quantity">
            </label>
            <label style="grid-column:2;">Price:
                <input name="price" type="number" min="0" step="0.01" required placeholder="Enter price">
            </label>
            <label style="grid-column:1;">Supplier:
                <select name="supplier" id="supplierSelect" onchange="toggleSupplierInput(this)">
                    <option value="">Select supplier</option>
                    <?php foreach($suppliers as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                    <option value="__addnew__">Add new supplier...</option>
                </select>
                <input name="supplier_new" id="supplierNewInput" placeholder="Enter new supplier" style="display:none; margin-top:6px;">
            </label>
            <label style="grid-column:2;">Low Stock Threshold:
                <input name="low_stock_threshold" value="5" type="number" min="0" required>
            </label>
            <button name="save_product" type="submit" style="margin-top:10px; grid-column:1/3;">Add</button>
        </form>
        <div class="modal-image-upload" style="display:flex; flex-direction:column; align-items:center; justify-content:flex-start; gap:18px; min-width:180px; max-width:220px;">
            <label style="width:100%;text-align:center;">Product Image:
                <input name="image" type="file" accept="image/*" onchange="previewAddImage(event)">
            </label>
            <img id="addImagePreview" class="modal-image-preview" src="https://via.placeholder.com/120x120?text=No+Image" alt="Preview">
        </div>
    </div>
</div>
<script>
function previewAddImage(event) {
    const [file] = event.target.files;
    if (file) {
        document.getElementById('addImagePreview').src = URL.createObjectURL(file);
    } else {
        document.getElementById('addImagePreview').src = 'https://via.placeholder.com/120x120?text=No+Image';
    }
}
function toggleBrandInput(sel) {
    const input = document.getElementById('brandNewInput');
    if (sel.value === '__addnew__') {
        input.style.display = 'block';
        input.required = true;
    } else {
        input.style.display = 'none';
        input.required = false;
    }
}
function toggleCategoryInput(sel) {
    const input = document.getElementById('categoryNewInput');
    if (sel.value === '__addnew__') {
        input.style.display = 'block';
        input.required = true;
    } else {
        input.style.display = 'none';
        input.required = false;
    }
}
function toggleSupplierInput(sel) {
    const input = document.getElementById('supplierNewInput');
    if (sel.value === '__addnew__') {
        input.style.display = 'block';
        input.required = true;
    } else {
        input.style.display = 'none';
        input.required = false;
    }
}
</script>
        <h3>All Products</h3>
        <div id="productsTable">
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
                <td> <a href="#" class="edit-btn action-btn" data-id="<?=$p['id']?>" data-code="<?=htmlspecialchars($p['code'])?>"
                        data-name="<?=htmlspecialchars($p['name'])?>"
                        data-description="<?=htmlspecialchars($p['description'])?>"
                        data-price="<?=htmlspecialchars($p['price'])?>"
                        data-quantity="<?=htmlspecialchars($p['quantity'])?>"
                        data-low="<?=htmlspecialchars($p['low_stock_threshold'])?>"><i class="fas fa-edit"></i>Edit</a>
                        <a href="products.php?delete=<?=$p['id']?>" class="delete-btn action-btn" onclick="return confirm('Delete?')"> <i class="fas fa-trash"></i>Delete</a>
            </td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <!-- Edit Product Modal -->
        <div id="editModal"
            style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
            <div class="modal-content-horizontal" style="display:grid; grid-template-columns: 2fr 1fr; gap:40px; max-width:820px; width:95vw; padding:36px 36px 28px 36px; border-radius:16px; background:#fff; box-shadow:0 8px 32px rgba(0,0,0,0.18); position:relative; align-items:start;">
                <button type="button" id="closeEditModal"
                    style="position:absolute; top:14px; right:18px; background:none; border:none; font-size:24px; color:#888;">&times;</button>
                <form method="post" id="editProductForm" enctype="multipart/form-data" style="display:grid; grid-template-columns: 1fr 1fr; gap:18px 24px; width:100%; align-items:start; background:none; box-shadow:none; border:none; padding:0; margin:0;">
                    <h3 style="margin-bottom:8px; grid-column:1/3;">Edit Product</h3>
                    <input type="hidden" name="id" id="edit_id">
                    <label style="grid-column:1;">Product Code:
                        <input name="code" id="edit_code" required placeholder="Enter product code">
                    </label>
                    <label style="grid-column:2;">Name:
                        <input name="name" id="edit_name" required placeholder="Enter product name">
                    </label>
                    <label style="grid-column:1;">Brand:
                        <select name="brand" id="edit_brand" onchange="toggleBrandInputEdit(this)">
                            <option value="">Select brand</option>
                            <?php foreach($brands as $b): ?>
                                <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__">Add new brand...</option>
                        </select>
                        <input name="brand_new" id="edit_brand_new" placeholder="Enter new brand" style="display:none; margin-top:6px;">
                    </label>
                    <label style="grid-column:2;">Category:
                        <select name="category" id="edit_category" onchange="toggleCategoryInputEdit(this)">
                            <option value="">Select category</option>
                            <?php foreach($categories as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__">Add new category...</option>
                        </select>
                        <input name="category_new" id="edit_category_new" placeholder="Enter new category" style="display:none; margin-top:6px;">
                    </label>
                    <label style="grid-column:1/3;">Description:
                        <textarea name="description" id="edit_description" placeholder="Enter product description"></textarea>
                    </label>
                    <label style="grid-column:1;">Quantity:
                        <input name="quantity" id="edit_quantity" type="number" min="0" required placeholder="Enter quantity">
                    </label>
                    <label style="grid-column:2;">Price:
                        <input name="price" id="edit_price" type="number" min="0" step="0.01" required placeholder="Enter price">
                    </label>
                    <label style="grid-column:1;">Supplier:
                        <select name="supplier" id="edit_supplier" onchange="toggleSupplierInputEdit(this)">
                            <option value="">Select supplier</option>
                            <?php foreach($suppliers as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__">Add new supplier...</option>
                        </select>
                        <input name="supplier_new" id="edit_supplier_new" placeholder="Enter new supplier" style="display:none; margin-top:6px;">
                    </label>
                    <label style="grid-column:2;">Low Stock Threshold:
                        <input name="low_stock_threshold" id="edit_low" value="5" type="number" min="0" required>
                    </label>
                    <button name="save_product" type="submit" style="margin-top:10px; grid-column:1/3;">Save Changes</button>
                </form>
                <div class="modal-image-upload" style="display:flex; flex-direction:column; align-items:center; justify-content:flex-start; gap:18px; min-width:180px; max-width:220px;">
                    <label style="width:100%;text-align:center;">Product Image:
                        <input name="image" id="edit_image" type="file" accept="image/*" onchange="previewEditImage(event)">
                    </label>
                    <img id="editImagePreview" class="modal-image-preview" src="https://via.placeholder.com/120x120?text=No+Image" alt="Preview">
                </div>
            </div>
        </div>
    </main>
    <script>
        document.getElementById('openHistoryModal').addEventListener('click', () => {
            const modal = document.getElementById('historyModal');
            const list = document.getElementById('historyList');
            modal.style.display = 'flex';
            list.innerHTML = '<div style="text-align:center;padding:0px;"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>';

            fetch('products.php?history=1', {
                cache: 'no-store',
                headers: {
                    'Accept': 'text/html'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                list.innerHTML = html;
            })
            .catch(error => {
                list.innerHTML = `<div style="text-align:center;padding:20px;color:#dc3545;">
                    <i class="fas fa-exclamation-circle"></i> Error loading history: ${error.message}
                </div>`;
            });
});

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

        // Edit product functionality
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('edit_id').value = this.dataset.id;
                document.getElementById('edit_code').value = this.dataset.code;
                document.getElementById('edit_name').value = this.dataset.name;
                document.getElementById('edit_description').value = this.dataset.description;
                document.getElementById('edit_price').value = this.dataset.price;
                document.getElementById('edit_quantity').value = this.dataset.quantity;
                document.getElementById('edit_low').value = this.dataset.low;
                // Set dropdowns to blank/default for now (backend logic can be added to set correct value)
                document.getElementById('edit_brand').value = '';
                document.getElementById('edit_category').value = '';
                document.getElementById('edit_supplier').value = '';
                document.getElementById('edit_brand_new').style.display = 'none';
                document.getElementById('edit_category_new').style.display = 'none';
                document.getElementById('edit_supplier_new').style.display = 'none';
                document.getElementById('editImagePreview').src = 'https://via.placeholder.com/120x120?text=No+Image';
                document.getElementById('editModal').style.display = 'flex';
            });
        });
// Edit modal dropdown/input toggles and image preview
function previewEditImage(event) {
    const [file] = event.target.files;
    if (file) {
        document.getElementById('editImagePreview').src = URL.createObjectURL(file);
    } else {
        document.getElementById('editImagePreview').src = 'https://via.placeholder.com/120x120?text=No+Image';
    }
}
function toggleBrandInputEdit(sel) {
    const input = document.getElementById('edit_brand_new');
    if (sel.value === '__addnew__') {
        input.style.display = 'block';
        input.required = true;
    } else {
        input.style.display = 'none';
        input.required = false;
    }
}
function toggleCategoryInputEdit(sel) {
    const input = document.getElementById('edit_category_new');
    if (sel.value === '__addnew__') {
        input.style.display = 'block';
        input.required = true;
    } else {
        input.style.display = 'none';
        input.required = false;
    }
}
function toggleSupplierInputEdit(sel) {
    const input = document.getElementById('edit_supplier_new');
    if (sel.value === '__addnew__') {
        input.style.display = 'block';
        input.required = true;
    } else {
        input.style.display = 'none';
        input.required = false;
    }
}
        document.getElementById('closeEditModal').onclick = function () {
            document.getElementById('editModal').style.display = 'none';
        };
        // Optional: close modal when clicking outside the modal content
        document.getElementById('editModal').addEventListener('click', function (e) {
            if (e.target === this) this.style.display = 'none';
        });

        // Add product modal functionality
            document.getElementById('openAddModal').onclick = function () {
        document.getElementById('addModal').style.display = 'flex';
    };
    document.getElementById('closeAddModal').onclick = function () {
        document.getElementById('addModal').style.display = 'none';
    };
    document.getElementById('addModal').addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
    });
    
    </script>

</body>

</html>