<?php
require __DIR__ . '/../config/config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
$role = $_SESSION['role'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;
$allowedPriorities = ['low', 'medium', 'high'];

$restockFormDefaults = [
    'product' => '',
    'quantity' => '',
    'category' => '',
    'category_new' => '',
    'brand' => '',
    'brand_new' => '',
    'supplier' => '',
    'supplier_new' => '',
    'priority' => '',
    'needed_by' => '',
    'notes' => '',
];
$restockFormData = $restockFormDefaults;

require_once __DIR__ . '/includes/inventory_notifications.php';
$notificationManageLink = 'inventory.php';
$inventoryNotificationData = loadInventoryNotifications($pdo);
$inventoryNotifications = $inventoryNotificationData['notifications'];
$inventoryNotificationCount = $inventoryNotificationData['active_count'];

// Handle restock request submission (available to any authenticated user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_restock_request'])) {
    $restockFormData = [
        'product' => $_POST['restock_product'] ?? '',
        'quantity' => $_POST['restock_quantity'] ?? '',
        'category' => $_POST['restock_category'] ?? '',
        'category_new' => $_POST['restock_category_new'] ?? '',
        'brand' => $_POST['restock_brand'] ?? '',
        'brand_new' => $_POST['restock_brand_new'] ?? '',
        'supplier' => $_POST['restock_supplier'] ?? '',
        'supplier_new' => $_POST['restock_supplier_new'] ?? '',
        'priority' => $_POST['restock_priority'] ?? '',
        'needed_by' => $_POST['restock_needed_by'] ?? '',
        'notes' => $_POST['restock_notes'] ?? '',
    ];
    $productId = intval($_POST['restock_product'] ?? 0);
    $requestedQuantity = intval($_POST['restock_quantity'] ?? 0);
    $priority = strtolower(trim($_POST['restock_priority'] ?? ''));
    $neededByRaw = trim($_POST['restock_needed_by'] ?? '');
    $notes = trim($_POST['restock_notes'] ?? '');
    $categoryChoice = trim($_POST['restock_category'] ?? '');
    $categoryNew = trim($_POST['restock_category_new'] ?? '');
    $brandChoice = trim($_POST['restock_brand'] ?? '');
    $brandNew = trim($_POST['restock_brand_new'] ?? '');
    $supplierChoice = trim($_POST['restock_supplier'] ?? '');
    $supplierNew = trim($_POST['restock_supplier_new'] ?? '');

    $resolveChoice = static function (string $selected, string $newValue): string {
        if ($selected === '__addnew__') {
            return $newValue;
        }
        if ($selected !== '') {
            return $selected;
        }
        return $newValue;
    };

    $category = trim($resolveChoice($categoryChoice, $categoryNew));
    $brand = trim($resolveChoice($brandChoice, $brandNew));
    $supplier = trim($resolveChoice($supplierChoice, $supplierNew));

    if (!$userId) {
        $error_message = 'Unable to submit restock request: user not found.';
    } elseif (!$productId || $requestedQuantity <= 0) {
        $error_message = 'Please select a product and enter a valid quantity for the restock request.';
    } elseif (!in_array($priority, $allowedPriorities, true)) {
        $error_message = 'Please choose a valid priority level.';
    } else {
        $neededBy = null;
        if ($neededByRaw !== '') {
            $date = DateTime::createFromFormat('Y-m-d', $neededByRaw);
            if ($date && $date->format('Y-m-d') === $neededByRaw) {
                $neededBy = $date->format('Y-m-d');
            } else {
                $error_message = 'Invalid date provided for "Needed By".';
            }
        }

        if (!isset($error_message)) {
            try {
                $productStmt = $pdo->prepare('SELECT category, brand, supplier FROM products WHERE id = ?');
                $productStmt->execute([$productId]);
                $productMeta = $productStmt->fetch(PDO::FETCH_ASSOC);
                if (!$productMeta) {
                    throw new RuntimeException('Selected product could not be found.');
                }
                $category = trim($category !== '' ? $category : ($productMeta['category'] ?? ''));
                $brand = trim($brand !== '' ? $brand : ($productMeta['brand'] ?? ''));
                $supplier = trim($supplier !== '' ? $supplier : ($productMeta['supplier'] ?? ''));
            } catch (Exception $e) {
                $error_message = 'Unable to load product details: ' . $e->getMessage();
            }
        }

        if (!isset($error_message)) {
            try {
                $stmt = $pdo->prepare('INSERT INTO restock_requests (product_id, requested_by, quantity_requested, priority_level, needed_by, notes, category, brand, supplier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $productId,
                    $userId,
                    $requestedQuantity,
                    $priority,
                    $neededBy,
                    $notes,
                    $category === '' ? null : $category,
                    $brand === '' ? null : $brand,
                    $supplier === '' ? null : $supplier
                ]);
                $requestId = (int) $pdo->lastInsertId();

                $logStmt = $pdo->prepare('INSERT INTO restock_request_history (request_id, status, noted_by) VALUES (?, ?, ?)');
                $logStmt->execute([$requestId, 'pending', $userId ?: null]);
                $success_message = 'Restock request submitted successfully!';
                $restockFormData = $restockFormDefaults;
            } catch (Exception $e) {
                $error_message = 'Failed to submit restock request: ' . $e->getMessage();
            }
        }
    }
}


// Handle stock updates (admin only)
if ($role === 'admin' && isset($_POST['update_stock'])) {
    $id = intval($_POST['id'] ?? 0);
    $change = intval($_POST['change'] ?? 0);

    if ($id && $change !== 0) {
        $stmt = $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?');
        $stmt->execute([$change, $id]);
    }

    header('Location: inventory.php');
    exit;
}
// Handle stock entry submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock'])) {
    $product_id = $_POST['product_id'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $supplier = $_POST['supplier'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $purchase_price = isset($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : 0;
    
    if ($product_id && $quantity > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
            $stmt->execute([$quantity, $product_id]);
            $stmt = $pdo->prepare("INSERT INTO stock_entries (product_id, quantity_added, purchase_price, supplier, notes, stock_in_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$product_id, $quantity, $purchase_price, $supplier, $notes, $_SESSION['user_id']]);
            $pdo->commit();
            $success_message = "Stock updated successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error updating stock: " . $e->getMessage();
        }
    } else {
        $error_message = "Please select a product and enter a valid quantity.";
    }

}

// Get recent stock entries with user information
$recent_entries = $pdo->query("
    SELECT se.*, p.name as product_name, u.name as user_name 
    FROM stock_entries se 
    JOIN products p ON p.id = se.product_id 
    LEFT JOIN users u ON u.id = se.stock_in_by 
    ORDER BY se.created_at DESC 
    LIMIT 10
")->fetchAll();

// Get all products for the main inventory table
$products = $pdo->query('SELECT * FROM products ORDER BY created_at DESC')->fetchAll();

// Lookups for restock request form selects
$categoryOptions = $pdo->query('SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != "" ORDER BY category ASC')->fetchAll(PDO::FETCH_COLUMN);
$brandOptions = $pdo->query('SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != "" ORDER BY brand ASC')->fetchAll(PDO::FETCH_COLUMN);
$supplierOptions = $pdo->query('SELECT DISTINCT supplier FROM products WHERE supplier IS NOT NULL AND supplier != "" ORDER BY supplier ASC')->fetchAll(PDO::FETCH_COLUMN);

// Restock requests overview data
$restockRequests = $pdo->query('
    SELECT rr.*, p.name AS product_name, p.code AS product_code,
           requester.name AS requester_name, reviewer.name AS reviewer_name
    FROM restock_requests rr
    LEFT JOIN products p ON p.id = rr.product_id
    LEFT JOIN users requester ON requester.id = rr.requested_by
    LEFT JOIN users reviewer ON reviewer.id = rr.reviewed_by
    ORDER BY rr.created_at DESC
')->fetchAll(PDO::FETCH_ASSOC);

$pendingRestockRequests = array_values(array_filter($restockRequests, function ($row) {
    return strtolower($row['status'] ?? 'pending') === 'pending';
}));

$resolvedRestockRequests = array_values(array_filter($restockRequests, function ($row) {
    return strtolower($row['status'] ?? 'pending') !== 'pending';
}));

$defaultRestockTab = !empty($pendingRestockRequests) ? 'pending' : 'processed';
if (!empty($pendingRestockRequests)) {
    $defaultRestockTab = 'pending';
}

$restockHistory = $pdo->query('
    SELECT h.*, 
           rr.quantity_requested AS request_quantity,
           rr.priority_level AS request_priority,
           rr.needed_by AS request_needed_by,
           rr.notes AS request_notes,
           rr.category AS request_category,
           rr.brand AS request_brand,
           rr.supplier AS request_supplier,
           p.name AS product_name, p.code AS product_code,
           requester.name AS requester_name,
           reviewer.name AS reviewer_name,
           status_user.name AS status_user_name
    FROM restock_request_history h
    JOIN restock_requests rr ON rr.id = h.request_id
    LEFT JOIN products p ON p.id = rr.product_id
    LEFT JOIN users requester ON requester.id = rr.requested_by
    LEFT JOIN users reviewer ON reviewer.id = rr.reviewed_by
    LEFT JOIN users status_user ON status_user.id = h.noted_by
    ORDER BY h.created_at DESC
')->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('getPriorityClass')) {
    function getPriorityClass(string $priority): string
    {
        switch ($priority) {
            case 'high':
                return 'badge-high';
            case 'medium':
                return 'badge-medium';
            default:
                return 'badge-low';
        }
    }
}

if (!function_exists('getStatusClass')) {
    function getStatusClass(string $status): string
    {
        switch ($status) {
            case 'approved':
                return 'status-approved';
            case 'denied':
            case 'declined':
                return 'status-denied';
            case 'fulfilled':
                return 'status-fulfilled';
            default:
                return 'status-pending';
        }
    }
}

// Handle export to CSV
if(isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product Code','Name','Quantity','Low Stock Threshold','Date Added']);
    foreach($products as $p) {
        fputcsv($out, [$p['code'],$p['name'],$p['quantity'],$p['low_stock_threshold'],$p['created_at']]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/inventory/inventory.css">
    <link rel="stylesheet" href="../assets/css/inventory/restockRequest.css">
    <style>
        .inventory-actions {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }

        .btn-primary, .btn-secondary {
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary { 
            background-color: #007bff;
            color: white;
            border: none;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
            border: none;
        }

        .btn-accent {
            background-color: #17a2b8;
            color: #fff;
            border: none;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
            position: relative;
        }

        .close {
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 24px;
            cursor: pointer;
        }

        /* Recent Entries Styles */
        .recent-entries {
            margin-top: 30px;
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .recent-entries h3 {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }

        .toggle-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
        }

        .entries-table {
            width: 100%;
            border-collapse: collapse;
        }

        .entries-table th, .entries-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .entries-table th {
            background-color: #f8f9fa;
        }

        .hidden {
            display: none;
        }

        .status-toggle-btn {
            background-color: #17a2b8;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .status-toggle-btn.active {
            box-shadow: inset 0 0 0 2px rgba(255,255,255,0.4);
        }

        .restock-status {
            margin: 20px 0;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }

        .tab-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .status-history-header {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .status-history-header .tab-btn {
            cursor: default;
        }

        .tab-btn {
            background-color: #e9ecef;
            border: none;
            border-radius: 999px;
            padding: 8px 18px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #495057;
        }

        .tab-btn.active {
            background-color: #17a2b8;
            color: #fff;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .requests-table {
            width: 100%;
            border-collapse: collapse;
        }

        .requests-table th,
        .requests-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .requests-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }

        .product-cell {
            display: flex;
            flex-direction: column;
        }

        .product-name {
            font-weight: 600;
            color: #343a40;
        }

        .product-code {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .priority-badge,
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge-high {
            background-color: #f8d7da;
            color: #721c24;
        }

        .badge-medium {
            background-color: #fff3cd;
            color: #856404;
        }

        .badge-low {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .status-pending {
            background-color: #e2e3e5;
            color: #383d41;
        }

        .status-approved {
            background-color: #d4edda;
            color: #155724;
        }

        .status-denied {
            background-color: #f8d7da;
            color: #721c24;
        }

        .status-fulfilled {
            background-color: #cce5ff;
            color: #004085;
        }

        .notes-cell {
            white-space: pre-wrap;
            max-width: 260px;
        }

        .muted {
            color: #6c757d;
        }

        .action-cell {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .inline-form {
            margin: 0;
        }

        .btn-approve,
        .btn-decline {
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: 100%;
            justify-content: center;
        }

        .btn-approve {
            background-color: #28a745;
            color: #fff;
        }

        .btn-decline {
            background-color: #dc3545;
            color: #fff;
        }

        .btn-approve:hover {
            background-color: #218838;
        }

        .btn-decline:hover {
            background-color: #c82333;
        }

        .action-cell form + form {
            margin-top: 4px;
        }

        @media (max-width: 768px) {
            .action-cell {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 6px;
            }

            .btn-approve,
            .btn-decline {
                width: auto;
            }
        }

        /* Alert Styles */
        .alert {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
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
                <a href="dashboard.php" class="nav-link ">
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
                <a href="pos.php" class="nav-link">
                    <i class="fas fa-cash-register nav-icon"></i>
                    POS
                </a>
            </div>
            <div class="nav-item">
                <a href="inventory.php" class="nav-link active">
                    <i class="fas fa-boxes nav-icon"></i>
                    Inventory
                </a>
            </div>
            <div class="nav-item">
                <a href="stockRequests.php" class="nav-link">
                    <i class="fas fa-clipboard-list nav-icon"></i>
                    Stock Requests
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
                <h2>Inventory</h2>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/partials/notification_menu.php'; ?>
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
                        <?php if ($role === 'admin'): ?>
                        <a href="userManagement.php" class="dropdown-item">
                            <i class="fas fa-users-cog"></i> User Management
                        </a>
                        <?php endif; ?>
                        <a href="login.php?logout=1" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Messages -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        

        <div class="inventory-actions">
            
            <button class="btn-accent" onclick="toggleRestockForm()" type="button">
                <i class="fas fa-truck-loading"></i> Restock Request
            </button>
            <button class="status-toggle-btn" type="button" onclick="toggleRestockStatus()" id="restockStatusButton">
                <i class="fas fa-clipboard-check"></i> Request Status
            </button>
           
        </div>
        <!-- Restock Request Form -->

        <div id="restockRequestForm" class="restock-request hidden">
            <h3><i class="fas fa-clipboard-list"></i> Submit Restock Request</h3>
            <form method="post" class="restock-form"
                data-initial-product="<?php echo htmlspecialchars($restockFormData['product']); ?>"
                data-initial-quantity="<?php echo htmlspecialchars($restockFormData['quantity']); ?>"
                data-initial-category="<?php echo htmlspecialchars($restockFormData['category']); ?>"
                data-initial-category-new="<?php echo htmlspecialchars($restockFormData['category_new']); ?>"
                data-initial-brand="<?php echo htmlspecialchars($restockFormData['brand']); ?>"
                data-initial-brand-new="<?php echo htmlspecialchars($restockFormData['brand_new']); ?>"
                data-initial-supplier="<?php echo htmlspecialchars($restockFormData['supplier']); ?>"
                data-initial-supplier-new="<?php echo htmlspecialchars($restockFormData['supplier_new']); ?>"
                data-initial-priority="<?php echo htmlspecialchars($restockFormData['priority']); ?>"
                data-initial-needed-by="<?php echo htmlspecialchars($restockFormData['needed_by']); ?>"
                data-initial-notes="<?php echo htmlspecialchars($restockFormData['notes']); ?>">
                <input type="hidden" name="submit_restock_request" value="1">
                <div class="restock-grid">
                    <div class="form-group">
                        <label for="restock_product">Product</label>
                        <select id="restock_product" name="restock_product" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                                <option 
                                    value="<?php echo $product['id']; ?>"
                                    data-category="<?php echo htmlspecialchars($product['category'] ?? ''); ?>"
                                    data-brand="<?php echo htmlspecialchars($product['brand'] ?? ''); ?>"
                                    data-supplier="<?php echo htmlspecialchars($product['supplier'] ?? ''); ?>"
                                    <?php echo ($restockFormData['product'] === (string) $product['id']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($product['name']); ?>
                                    (Current: <?php echo $product['quantity']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="restock_category">Category</label>
                        <select id="restock_category" name="restock_category">
                            <option value="">Select category</option>
                            <?php foreach ($categoryOptions as $categoryOption): ?>
                                <option value="<?php echo htmlspecialchars($categoryOption); ?>" <?php echo ($restockFormData['category'] === $categoryOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($categoryOption); ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__" <?php echo ($restockFormData['category'] === '__addnew__' || ($restockFormData['category'] === '' && $restockFormData['category_new'] !== '')) ? 'selected' : ''; ?>>Add new category...</option>
                        </select>
                        <input type="text" id="restock_category_new" name="restock_category_new" class="optional-input" placeholder="Enter new category" value="<?php echo htmlspecialchars($restockFormData['category_new']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="restock_brand">Brand</label>
                        <select id="restock_brand" name="restock_brand">
                            <option value="">Select brand</option>
                            <?php foreach ($brandOptions as $brandOption): ?>
                                <option value="<?php echo htmlspecialchars($brandOption); ?>" <?php echo ($restockFormData['brand'] === $brandOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($brandOption); ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__" <?php echo ($restockFormData['brand'] === '__addnew__' || ($restockFormData['brand'] === '' && $restockFormData['brand_new'] !== '')) ? 'selected' : ''; ?>>Add new brand...</option>
                        </select>
                        <input type="text" id="restock_brand_new" name="restock_brand_new" class="optional-input" placeholder="Enter new brand" value="<?php echo htmlspecialchars($restockFormData['brand_new']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="restock_supplier">Supplier</label>
                        <select id="restock_supplier" name="restock_supplier">
                            <option value="">Select supplier</option>
                            <?php foreach ($supplierOptions as $supplierOption): ?>
                                <option value="<?php echo htmlspecialchars($supplierOption); ?>" <?php echo ($restockFormData['supplier'] === $supplierOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($supplierOption); ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__" <?php echo ($restockFormData['supplier'] === '__addnew__' || ($restockFormData['supplier'] === '' && $restockFormData['supplier_new'] !== '')) ? 'selected' : ''; ?>>Add new supplier...</option>
                        </select>
                        <input type="text" id="restock_supplier_new" name="restock_supplier_new" class="optional-input" placeholder="Enter new supplier" value="<?php echo htmlspecialchars($restockFormData['supplier_new']); ?>">
                    </div>
                    <div class="form-group quantity-group">
                        <label for="restock_quantity">Requested Quantity</label>
                        <input type="number" id="restock_quantity" name="restock_quantity" min="1" placeholder="Enter quantity" required value="<?php echo htmlspecialchars($restockFormData['quantity']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="restock_priority">Priority Level</label>
                        <select id="restock_priority" name="restock_priority" required>
                            <option value="">Select Priority</option>
                            <option value="low" <?php echo ($restockFormData['priority'] === 'low') ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo ($restockFormData['priority'] === 'medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo ($restockFormData['priority'] === 'high') ? 'selected' : ''; ?>>High</option>
                        </select>
                    </div>
                    <div class="form-group notes-group span-two">
                        <label for="restock_notes">Reason / Notes</label>
                        <textarea id="restock_notes" name="restock_notes" placeholder="Provide additional details for the restock request..."><?php echo htmlspecialchars($restockFormData['notes']); ?></textarea>
                    </div>
                    <div class="form-group needed-group">
                        <label for="restock_needed_by">Needed By</label>
                        <input type="date" id="restock_needed_by" name="restock_needed_by" value="<?php echo htmlspecialchars($restockFormData['needed_by']); ?>">
                    </div>
                    <div class="restock-actions span-three">
                        <button type="submit" class="submit-btn">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                        <button type="button" class="btn-secondary" onclick="toggleRestockForm()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="restockStatusPanel" class="restock-status hidden">
            <h3 style="margin-top:0; display:flex; align-items:center; gap:10px;">
                <i class="fas fa-clipboard-list"></i> Request Status
            </h3>
            <?php if (empty($restockRequests)): ?>
                <p class="muted" style="margin: 12px 0 0;"><i class="fas fa-inbox"></i> No restock requests yet.</p>
            <?php else: ?>
                <div class="status-history-header">
                    <span class="tab-btn active" aria-current="true">
                        <i class="fas fa-clipboard-check"></i> Status History
                    </span>
                </div>

                <div id="processedRequests" class="tab-panel active">
                    <?php if (empty($restockHistory)): ?>
                        <p class="muted" style="margin: 12px 0 0;"><i class="fas fa-inbox"></i> No request history logged yet.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="requests-table">
                                <thead>
                                    <tr>
                                        <th>Logged At</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Brand</th>
                                        <th>Supplier</th>
                                        <th>Quantity</th>
                                        <th>Priority</th>
                                        <th>Needed By</th>
                                        <th>Requested By</th>
                                        <th>Status</th>
                                        <th>Logged By</th>
                                        <th>Approved / Declined By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($restockHistory as $entry): ?>
                                        <?php $status = strtolower($entry['status'] ?? 'pending'); ?>
                                        <tr>
                                            <td><?php echo date('M d, Y H:i', strtotime($entry['created_at'])); ?></td>
                                            <td>
                                                <div class="product-cell">
                                                    <span class="product-name"><?php echo htmlspecialchars($entry['product_name'] ?? 'Product removed'); ?></span>
                                                    <?php if (!empty($entry['product_code'])): ?>
                                                        <span class="product-code">Code: <?php echo htmlspecialchars($entry['product_code']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($entry['request_category'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($entry['request_brand'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($entry['request_supplier'] ?? ''); ?></td>
                                            <td><?php echo (int) $entry['request_quantity']; ?></td>
                                            <td>
                                                <?php $priority = strtolower($entry['request_priority'] ?? ''); ?>
                                                <span class="priority-badge <?php echo getPriorityClass($priority); ?>"><?php echo ucfirst($priority); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($entry['request_needed_by'])): ?>
                                                    <?php echo date('M d, Y', strtotime($entry['request_needed_by'])); ?>
                                                <?php else: ?>
                                                    <span class="muted">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($entry['requester_name'] ?? 'Unknown'); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo getStatusClass($status); ?>"><?php echo ucfirst($status); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($entry['status_user_name'] ?? 'System'); ?></td>
                                            <td><?php echo ($status === 'approved' || $status === 'declined') ? htmlspecialchars($entry['reviewer_name'] ?? 'Unknown') : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>




        <!-- Main Inventory Table -->

        <div class="inventory-actions">
            <a href="stockEntry.php" class="btn-action add-stock-btn">Add Stock</a>
            <a href="inventory.php?export=csv" class="btn-action export-btn">Export CSV</a>
        </div>


        <table border="1" cellpadding="5">
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Quantity</th>
                <th>Low Stock Threshold</th>
                <th>Date Added</th>

                <?php if ($role === 'admin') echo '<th>Update Stock</th>'; ?>
            </tr>
            <?php foreach($products as $p):
                $low = $p['quantity'] <= $p['low_stock_threshold'];
            ?>
            <tr style="<?php if($low) echo 'background-color:#fdd'; ?>">
                <td><?=htmlspecialchars($p['code'])?></td>
                <td><?=htmlspecialchars($p['name'])?></td>
                <td><?=intval($p['quantity'])?></td>
                <td><?=intval($p['low_stock_threshold'])?></td>
                <td><?=$p['created_at']?></td>

                <?php if ($role === 'admin'): ?>
                <td>
                    <form method="post">
                        <input type="hidden" name="id" value="<?=$p['id']?>">
                        <input type="number" name="change" value="0" step="1">
                        <button type="submit" name="update_stock">Apply</button>
                    </form>
                </td>
                <?php endif; ?>

            </tr>
            <?php endforeach; ?>
        </table>

        <!-- Recent Stock Entries Section -->
        <div class="recent-entries">
            <h3>
                <i class="fas fa-history"></i> Recent Stock Entries
                <button class="toggle-btn" onclick="toggleRecentEntries()">
                    <i class="fas fa-chevron-down" id="toggleIcon"></i>
                </button>
            </h3>
            <div id="recentEntriesContent" class="hidden">
                <table class="entries-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Quantity Added</th>
                            <th>Cost (per unit)</th>
                            <th>Supplier</th>
                            <th>Stock in by</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_entries as $entry): ?>
                            <tr>
                                <td><?php echo date('M d, Y H:i', strtotime($entry['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($entry['product_name']); ?></td>
                                <td><?php echo $entry['quantity_added']; ?></td>
                                <td>₱<?php echo number_format($entry['purchase_price'], 2); ?></td>
                                <td><?php echo htmlspecialchars($entry['supplier']); ?></td>
                                <td><?php echo htmlspecialchars($entry['user_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($entry['notes']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_entries)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">No recent stock entries</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <script>
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

        // Stock Entry Modal Functions
        function openStockModal() {
            document.getElementById('stockEntryModal').style.display = 'block';
        }

        function closeStockModal() {
            document.getElementById('stockEntryModal').style.display = 'none';
        }

        function toggleRestockForm() {
            const form = document.getElementById('restockRequestForm');
            form.classList.toggle('hidden');
        }

        function toggleRestockStatus() {
            const panel = document.getElementById('restockStatusPanel');
            const button = document.getElementById('restockStatusButton');
            if (!panel || !button) {
                return;
            }
            const isHidden = panel.classList.toggle('hidden');
            button.classList.toggle('active', !isHidden);
            if (!isHidden) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('stockEntryModal');
            if (event.target == modal) {
                closeStockModal();
            }
        }

        // Toggle Recent Entries Section
        function toggleRecentEntries() {
            const content = document.getElementById('recentEntriesContent');
            const icon = document.getElementById('toggleIcon');
            
            content.classList.toggle('hidden');
            if (content.classList.contains('hidden')) {
                icon.className = 'fas fa-chevron-down';
            } else {
                icon.className = 'fas fa-chevron-up';
            }
        }

        // Show alerts for 5 seconds then fade out
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            const restockFormEl = document.querySelector('.restock-form');
            const productSelect = document.getElementById('restock_product');
            const categorySelect = document.getElementById('restock_category');
            const categoryNewInput = document.getElementById('restock_category_new');
            const brandSelect = document.getElementById('restock_brand');
            const brandNewInput = document.getElementById('restock_brand_new');
            const supplierSelect = document.getElementById('restock_supplier');
            const supplierNewInput = document.getElementById('restock_supplier_new');
            const statusPanel = document.getElementById('restockStatusPanel');
            const statusButton = document.getElementById('restockStatusButton');
            const quantityInput = document.getElementById('restock_quantity');
            const prioritySelect = document.getElementById('restock_priority');
            const neededByInput = document.getElementById('restock_needed_by');
            const notesTextarea = document.getElementById('restock_notes');

            function handleSelectChange(selectEl, inputEl) {
                if (!selectEl || !inputEl) {
                    return;
                }
                const needsInput = selectEl.value === '__addnew__';
                inputEl.style.display = needsInput ? 'block' : 'none';
                inputEl.required = needsInput;
                if (!needsInput) {
                    inputEl.value = '';
                }
            }

            function setSelectOrInput(selectEl, inputEl, value) {
                if (!selectEl || !inputEl) {
                    return;
                }
                const trimmed = (value || '').trim();
                const options = Array.from(selectEl.options).map(opt => opt.value);

                if (trimmed !== '' && options.includes(trimmed)) {
                    selectEl.value = trimmed;
                    inputEl.style.display = 'none';
                    inputEl.required = false;
                    inputEl.value = '';
                } else if (trimmed !== '') {
                    selectEl.value = '__addnew__';
                    inputEl.style.display = 'block';
                    inputEl.required = true;
                    inputEl.value = trimmed;
                } else {
                    selectEl.value = '';
                    inputEl.style.display = 'none';
                    inputEl.required = false;
                    inputEl.value = '';
                }
            }

            const selectMappings = [
                { select: categorySelect, input: categoryNewInput },
                { select: brandSelect, input: brandNewInput },
                { select: supplierSelect, input: supplierNewInput },
            ];

            selectMappings.forEach(({ select, input }) => {
                if (select && input) {
                    select.addEventListener('change', () => handleSelectChange(select, input));
                    handleSelectChange(select, input);
                }
            });

            function updateProductMeta() {
                if (!productSelect) {
                    return;
                }

                const selectedOption = productSelect.options[productSelect.selectedIndex];
                if (!selectedOption) {
                    selectMappings.forEach(({ select, input }) => {
                        setSelectOrInput(select, input, '');
                        handleSelectChange(select, input);
                    });
                    return;
                }

                setSelectOrInput(categorySelect, categoryNewInput, selectedOption.getAttribute('data-category') || '');
                setSelectOrInput(brandSelect, brandNewInput, selectedOption.getAttribute('data-brand') || '');
                setSelectOrInput(supplierSelect, supplierNewInput, selectedOption.getAttribute('data-supplier') || '');
                handleSelectChange(categorySelect, categoryNewInput);
                handleSelectChange(brandSelect, brandNewInput);
                handleSelectChange(supplierSelect, supplierNewInput);
            }

            let hasInitialFormData = false;
            if (restockFormEl) {
                const data = restockFormEl.dataset;
                const initialCategoryValue = data.initialCategoryNew || data.initialCategory;
                const initialBrandValue = data.initialBrandNew || data.initialBrand;
                const initialSupplierValue = data.initialSupplierNew || data.initialSupplier;

                if (
                    data.initialProduct || data.initialQuantity || initialCategoryValue ||
                    initialBrandValue || initialSupplierValue || data.initialPriority ||
                    data.initialNeededBy || data.initialNotes
                ) {
                    hasInitialFormData = true;
                    if (productSelect) {
                        productSelect.value = data.initialProduct || '';
                    }
                    if (quantityInput) {
                        quantityInput.value = data.initialQuantity || '';
                    }
                    setSelectOrInput(categorySelect, categoryNewInput, initialCategoryValue || '');
                    handleSelectChange(categorySelect, categoryNewInput);
                    setSelectOrInput(brandSelect, brandNewInput, initialBrandValue || '');
                    handleSelectChange(brandSelect, brandNewInput);
                    setSelectOrInput(supplierSelect, supplierNewInput, initialSupplierValue || '');
                    handleSelectChange(supplierSelect, supplierNewInput);
                    if (prioritySelect) {
                        prioritySelect.value = data.initialPriority || '';
                    }
                    if (neededByInput) {
                        neededByInput.value = data.initialNeededBy || '';
                    }
                    if (notesTextarea) {
                        notesTextarea.value = data.initialNotes || '';
                    }
                }
            }

            if (productSelect) {
                productSelect.addEventListener('change', () => {
                    updateProductMeta();
                });
                if (!hasInitialFormData) {
                    updateProductMeta();
                }
            }

            if (statusPanel && statusButton && !statusPanel.classList.contains('hidden')) {
                statusButton.classList.add('active');
            }

            if (statusPanel) {
                const tabButtons = statusPanel.querySelectorAll('.tab-btn');
                const tabPanels = statusPanel.querySelectorAll('.tab-panel');

                tabButtons.forEach(button => {
                    button.addEventListener('click', () => {
                        const targetId = button.getAttribute('data-target');

                        tabButtons.forEach(btn => btn.classList.toggle('active', btn === button));
                        tabPanels.forEach(panel => {
                            panel.classList.toggle('active', panel.id === targetId);
                        });
                    });
                });
            }
        });
    </script>
    <script src="../assets/js/notifications.js"></script>
</body>
</html>
