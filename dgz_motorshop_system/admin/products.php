<?php
require __DIR__. '/../config/config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();
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
        <?php
            // Added: parse structured history payloads so the table keeps values even after deletion edits.
            $rawDetails = $entry['details'] ?? '';
            $decodedDetails = json_decode($rawDetails, true);
            $detailsIsStructured = json_last_error() === JSON_ERROR_NONE && is_array($decodedDetails);
            $snapshot = $detailsIsStructured && isset($decodedDetails['snapshot']) && is_array($decodedDetails['snapshot']) ? $decodedDetails['snapshot'] : [];
            $changes = $detailsIsStructured && isset($decodedDetails['changes']) && is_array($decodedDetails['changes']) ? $decodedDetails['changes'] : [];
            $summaryText = $detailsIsStructured && !empty($decodedDetails['summary']) ? $decodedDetails['summary'] : '';

            $displayCode = $entry['product_code'] ?? '';
            if ($displayCode === '' && isset($snapshot['code'])) {
                $displayCode = $snapshot['code'];
            }

            $displayName = $entry['product_name'] ?? '';
            if ($displayName === '' && isset($snapshot['name'])) {
                $displayName = $snapshot['name'];
            }

            $displayBrand = $entry['brand'] ?? '';
            if ($displayBrand === '' && isset($snapshot['brand'])) {
                $displayBrand = $snapshot['brand'];
            }

            $displayCategory = $entry['category'] ?? '';
            if ($displayCategory === '' && isset($snapshot['category'])) {
                $displayCategory = $snapshot['category'];
            }

            $displayPrice = $entry['price'];
            if ($displayPrice === null && isset($snapshot['price'])) {
                $displayPrice = $snapshot['price'];
            }
            $displayPrice = $displayPrice === null ? 0 : (float) $displayPrice;

            $displayQuantity = $entry['quantity'];
            if ($displayQuantity === null && isset($snapshot['quantity'])) {
                $displayQuantity = $snapshot['quantity'];
            }
            $displayQuantity = $displayQuantity === null ? 0 : (float) $displayQuantity;

            $detailsHtml = '';
            if ($detailsIsStructured) {
                if ($summaryText !== '') {
                    $detailsHtml .= '<div>' . htmlspecialchars($summaryText) . '</div>';
                }
                if (!empty($changes)) {
                    $detailsHtml .= '<ul class="history-change-list">';
                    foreach ($changes as $change) {
                        $fromValue = $change['from'];
                        $toValue = $change['to'];
                        if (($change['type'] ?? '') === 'currency') {
                            $fromValue = $fromValue === null ? '-' : '₱' . number_format((float) $fromValue, 2);
                            $toValue = $toValue === null ? '-' : '₱' . number_format((float) $toValue, 2);
                        } elseif (($change['type'] ?? '') === 'number') {
                            $fromValue = $fromValue === null ? '-' : number_format((float) $fromValue);
                            $toValue = $toValue === null ? '-' : number_format((float) $toValue);
                        } else {
                            $fromValue = ($fromValue === null || $fromValue === '') ? '-' : $fromValue;
                            $toValue = ($toValue === null || $toValue === '') ? '-' : $toValue;
                        }
                        $detailsHtml .= '<li>' . htmlspecialchars(($change['label'] ?? 'Field') . ': ' . $fromValue . ' → ' . $toValue) . '</li>';
                    }
                    $detailsHtml .= '</ul>';
                }
            } elseif ($rawDetails !== '') {
                $detailsHtml = '<div>' . htmlspecialchars($rawDetails) . '</div>';
            }
        ?>
        <tr>
            <td><?= date('M d, Y H:i', strtotime($entry['created_at'])) ?></td>
            <td><span class="product-code"><?= htmlspecialchars($displayCode !== '' ? $displayCode : '-') ?></span></td>
            <td><span class="product-name"><?= htmlspecialchars($displayName !== '' ? $displayName : '-') ?></span></td>
            <td><span class="brand-badge"><?= htmlspecialchars($displayBrand !== '' ? $displayBrand : '-') ?></span></td>
            <td><span class="category-badge"><?= htmlspecialchars($displayCategory !== '' ? $displayCategory : '-') ?></span></td>
            <td><span class="price">₱<?= number_format($displayPrice, 2) ?></span></td>
            <td><span class="quantity"><?= number_format($displayQuantity) ?></span></td>
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
                <?php if ($detailsHtml !== ''): ?>
                <div class="action-details"><?= $detailsHtml ?></div>
                <?php endif; ?>
            </td>

        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr>
            <td colspan="9" style="text-align:center;">No product history</td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>
<link rel="stylesheet" href="../assets/css/products/products_history.css">
<?php
    exit;
}

require_once __DIR__ . '/includes/inventory_notifications.php';
$notificationManageLink = 'inventory.php';
$inventoryNotificationData = loadInventoryNotifications($pdo);
$inventoryNotifications = $inventoryNotificationData['notifications'];
$inventoryNotificationCount = $inventoryNotificationData['active_count'];

// Fetch the authenticated user's information for the profile modal
$current_user = null;
try {
    $stmt = $pdo->prepare('SELECT name, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();
} catch (Exception $e) {
    error_log('User lookup failed: ' . $e->getMessage());
}

function format_profile_date(?string $datetime): string
{
    if (!$datetime) {
        return 'N/A';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return 'N/A';
    }

    return date('F j, Y g:i A', $timestamp);
}

$profile_name = $current_user['name'] ?? 'N/A';
$profile_role = !empty($current_user['role']) ? ucfirst($current_user['role']) : 'N/A';
$profile_created = format_profile_date($current_user['created_at'] ?? null);

if(isset($_GET['delete'])) {
    $product_id = intval($_GET['delete']);
    
    try {
        // Added: wrap the entire deletion in a transaction so history and clean-up are atomic.
        $pdo->beginTransaction();

        // Get product details before deletion (using prepared statement)
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if ($product) {
            // Add this for debugging
error_log("Attempting to delete product ID: $product_id");
if ($product) {
    error_log("Product found: " . json_encode($product));
} else {
    error_log("Product not found for deletion");
}
            // Try to record deletion in history (optional)
            try {
                // Added: persist a snapshot of the product before it disappears so history remains readable.
                $historyPayload = [
                    'summary' => sprintf(
                        'Deleted %s (%s).',
                        $product['name'] ?? 'product',
                        $product['code'] ?? 'no code'
                    ),
                    'snapshot' => [
                        'code' => $product['code'] ?? null,
                        'name' => $product['name'] ?? null,
                        'description' => $product['description'] ?? null,
                        'price' => $product['price'] ?? null,
                        'quantity' => $product['quantity'] ?? null,
                        'low_stock_threshold' => $product['low_stock_threshold'] ?? null,
                        'brand' => $product['brand'] ?? null,
                        'category' => $product['category'] ?? null,
                        'supplier' => $product['supplier'] ?? null,
                    ],
                ];

                $pdo->prepare('INSERT INTO product_add_history (product_id, user_id, action, details) VALUES (?, ?, ?, ?)')
                    ->execute([
                        $product_id,
                        $_SESSION['user_id'],
                        'delete',
                        json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
            } catch (Exception $e) {
                // History failed but continue with deletion
                error_log("Failed to record product deletion history: " . $e->getMessage());
            }
            
            // Added: cascade clean-up for dependent tables to satisfy FK constraints.
            $pdo->prepare('DELETE FROM stock_entries WHERE product_id = ?')->execute([$product_id]);
            $pdo->prepare('UPDATE order_items SET product_id = NULL WHERE product_id = ?')->execute([$product_id]);
            $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$product_id]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        // Added: rollback on failure to keep DB consistent when any step fails.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Log the error and redirect
        error_log("Product deletion failed: " . $e->getMessage());
    }
    
    header('Location: products.php'); 
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_product'])){
    // Added: normalise raw form values once so they can be reused across actions.
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $price = isset($_POST['price']) ? (float) $_POST['price'] : 0.0;
    $qty = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 0;
    $low = isset($_POST['low_stock_threshold']) ? (int) $_POST['low_stock_threshold'] : 0;
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    
    // Handle brand and category
    $brand = trim($_POST['brand'] ?? '');
    $brandNew = trim($_POST['brand_new'] ?? '');
    // Added: honour the optional text input when the select is left empty or set to "Add new".
    if ($brand === '__addnew__' || ($brand === '' && $brandNew !== '')) {
        $brand = $brandNew;
    }
    $brand = $brand === '' ? null : $brand;

    $category = trim($_POST['category'] ?? '');
    $categoryNew = trim($_POST['category_new'] ?? '');
    // Added: same fallback behaviour for the category field.
    if ($category === '__addnew__' || ($category === '' && $categoryNew !== '')) {
        $category = $categoryNew;
    }
    $category = $category === '' ? null : $category;

    $supplier = trim($_POST['supplier'] ?? '');
    $supplierNew = trim($_POST['supplier_new'] ?? '');
    // Added: allow suppliers typed in the text box without toggling the select.
    if ($supplier === '__addnew__' || ($supplier === '' && $supplierNew !== '')) {
        $supplier = $supplierNew;
    }
    $supplier = $supplier === '' ? null : $supplier;

    // Added: build a snapshot of the latest field values to reuse in history payloads.
    $currentSnapshot = [
        'code' => $code,
        'name' => $name,
        'description' => $desc,
        'price' => $price,
        'quantity' => $qty,
        'low_stock_threshold' => $low,
        'brand' => $brand,
        'category' => $category,
        'supplier' => $supplier,
    ];

    $user_id = $_SESSION['user_id'];
    
    if($id > 0) {
        // Get old product data for history
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$old_product = $stmt->fetch();
        
        // Update product
        $stmt = $pdo->prepare('UPDATE products SET code=?, name=?, description=?, price=?, quantity=?, low_stock_threshold=?, brand=?, category=?, supplier=? WHERE id=?');
        $stmt->execute([$code, $name, $desc, $price, $qty, $low, $brand, $category, $supplier, $id]);
        
        // Record edit in history
        $changes = [];
        $previousSnapshot = [
            'code' => $old_product['code'] ?? null,
            'name' => $old_product['name'] ?? null,
            'description' => $old_product['description'] ?? null,
            'price' => $old_product['price'] ?? null,
            'quantity' => $old_product['quantity'] ?? null,
            'low_stock_threshold' => $old_product['low_stock_threshold'] ?? null,
            'brand' => $old_product['brand'] ?? null,
            'category' => $old_product['category'] ?? null,
            'supplier' => $old_product['supplier'] ?? null,
        ];

        // Added: build structured change list so history modal can render exact edits.
        $fieldsToCompare = [
            'code' => ['label' => 'Code', 'type' => 'text'],
            'name' => ['label' => 'Name', 'type' => 'text'],
            'description' => ['label' => 'Description', 'type' => 'text'],
            'price' => ['label' => 'Price', 'type' => 'currency'],
            'quantity' => ['label' => 'Quantity', 'type' => 'number'],
            'low_stock_threshold' => ['label' => 'Low stock threshold', 'type' => 'number'],
            'brand' => ['label' => 'Brand', 'type' => 'text'],
            'category' => ['label' => 'Category', 'type' => 'text'],
            'supplier' => ['label' => 'Supplier', 'type' => 'text'],
        ];

        foreach ($fieldsToCompare as $field => $meta) {
            $previousValue = $previousSnapshot[$field];
            $newValue = $currentSnapshot[$field];
            if ($previousValue != $newValue) {
                $changes[] = [
                    'field' => $field,
                    'label' => $meta['label'],
                    'type' => $meta['type'],
                    'from' => $previousValue,
                    'to' => $newValue,
                ];
            }
        }

        $historyPayload = [
            'summary' => sprintf('Updated product information (%d change%s).', count($changes), count($changes) === 1 ? '' : 's'),
            'changes' => $changes,
            'snapshot' => $currentSnapshot,
            'previous' => $previousSnapshot,
        ];

        $pdo->prepare('INSERT INTO product_add_history (product_id, user_id, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$id, $user_id, 'edit', json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } else {
        // Insert new product
        $stmt = $pdo->prepare('INSERT INTO products (code, name, description, price, quantity, low_stock_threshold, brand, category, supplier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$code, $name, $desc, $price, $qty, $low, $brand, $category, $supplier]);
        $product_id = $pdo->lastInsertId();
        
        // Record addition in history
        $historyPayload = [
            'summary' => sprintf(
                'Added %s (%s). Stock: %s • Price: ₱%s • Brand: %s • Category: %s • Supplier: %s',
                $name !== '' ? $name : 'Unnamed product',
                $code !== '' ? $code : 'no code',
                number_format($qty),
                number_format($price, 2),
                $brand ?? '-',
                $category ?? '-',
                $supplier ?? '-'
            ),
            'snapshot' => $currentSnapshot,
        ];

        $pdo->prepare('INSERT INTO product_add_history (product_id, user_id, action, details) VALUES (?, ?, ?, ?)')
            ->execute([$product_id, $user_id, 'add', json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    header('Location: products.php'); exit;
}
// Fetch unique brands and categories (before pagination to avoid unnecessary full fetch)
$brands = $pdo->query('SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != ""')->fetchAll(PDO::FETCH_COLUMN);
$categories = $pdo->query('SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != ""')->fetchAll(PDO::FETCH_COLUMN);
$suppliers = $pdo->query('SELECT DISTINCT supplier FROM products WHERE supplier IS NOT NULL AND supplier != ""')->fetchAll(PDO::FETCH_COLUMN);

/**
 * Handle product filtering, search, and pagination functionality (styled after sales.php).
 * Retrieves GET parameters for search term, brand, category, supplier filters, and page.
 * Builds dynamic SQL queries: one for COUNT(*) to get total records, and one for paginated results using named parameters.
 * Uses prepared statements with bindValue to prevent SQL injection.
 * Pagination: max 15 entries per page, calculates offset based on page number.
 * Preserves filters in pagination links.
 */
$search = $_GET['search'] ?? '';
$brand_filter = $_GET['brand'] ?? '';
$category_filter = $_GET['category'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';

// Pagination setup (matching sales.php style)
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$limit = 15;
$offset = ($page - 1) * $limit;

// Build the base WHERE clause (shared for COUNT and SELECT)
$where_sql = 'WHERE 1=1';
$filter_params = []; // Params for filters only
if ($search) {
    // Search in product name or code
    $where_sql .= ' AND (name LIKE ? OR code LIKE ?)';
    $filter_params[] = "%$search%";
    $filter_params[] = "%$search%";
}
if ($brand_filter) {
    // Filter by specific brand
    $where_sql .= ' AND brand = ?';
    $filter_params[] = $brand_filter;
}
if ($category_filter) {
    // Filter by specific category
    $where_sql .= ' AND category = ?';
    $filter_params[] = $category_filter;
}
if ($supplier_filter) {
    // Filter by specific supplier
    $where_sql .= ' AND supplier = ?';
    $filter_params[] = $supplier_filter;
}

// First, get total count for pagination (using filter params)
$count_sql = 'SELECT COUNT(*) FROM products ' . $where_sql;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($filter_params);
$total_products = $count_stmt->fetchColumn();
$total_pages = ceil($total_products / $limit);

// Main query with LIMIT and OFFSET (using named params like sales.php)
$sql = 'SELECT * FROM products ' . $where_sql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($filter_params as $param) {
    $stmt->bindValue($stmt->paramCount + 1, $param); // Bind filter params positionally first
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

// Calculate showing info (like sales.php)
$start_record = $offset + 1;
$end_record = min($offset + $limit, $total_products);
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/sales/sales.css">
    <link rel="stylesheet" href="../assets/css/products/products.css">

</head>

<body>
    <!-- Sidebar -->
    <?php
        $activePage = 'products.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>

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
            <div class="header-right">
                <?php include __DIR__ . '/partials/notification_menu.php'; ?>
                <div class="user-menu">
                    <div class="user-avatar" onclick="toggleDropdown()">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <button type="button" class="dropdown-item" id="profileTrigger">
                            <i class="fas fa-user-cog"></i> Profile
                        </button>
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
        <!-- Header row aligning "All Products" title with action buttons to save space -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <h3>All Products</h3>
            <div style="display: flex; gap: 12px;">
                <button id="openAddModal" class="add-btn" type="button">
                    <i class="fas fa-plus"></i> Add Product
                </button>
                <button id="openHistoryModal" class="history-btn" type="button">
                    <i class="fas fa-history"></i> History
                </button>
            </div>
        </div>

        <!-- Filter and search form for products by category, brand, supplier, and search term -->
        <form method="GET" style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: end;">
            <input type="text" name="search" placeholder="Search product by name or code..." value="<?= htmlspecialchars($search ?? '') ?>" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; min-width: 200px;">
            
            <select name="brand" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="">All Brands</option>
                <?php foreach ($brands as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>" <?= ($brand_filter ?? '') === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
            
            <select name="category" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= ($category_filter ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
            
            <select name="supplier" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="">All Suppliers</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= ($supplier_filter ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Filter</button>
        </form>

        <!-- Product Add History Modal (like stockEntry.php) -->
        <div id="historyModal"
            style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:flex-end;">
            <div class="modal-content"
                style="background:white; padding:30px; border-radius:10px; width:100%; max-width:1600px; position:relative; box-shadow:0 4px 6px rgba(0,0,0,0.1); margin:50px;">
                <button type="button" id="closeHistoryModal"
                    style="position:absolute; top:20px; right:25px; background:none; border:none; font-size:24px; color:#888; cursor:pointer;">&times;</button>
                <h3 style="margin:0 0 20px 0; font-size:1.5em; color:#333;">Product Add History</h3>
                <div class="recent-entries" style="width:100%;">
                    <div id="historyList"
                        style="max-height:610px; overflow-y:auto; width:100%; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.05); border:1px solid #e2e8f0;">
                    </div>
                </div>
            </div>
        </div>

        <script>
            function toggleDropdown() {
                const dropdown = document.getElementById('userDropdown');
                if (!dropdown) {
                    return;
                }

                dropdown.classList.toggle('show');
            }

            function toggleSidebar() {
                const sidebar = document.getElementById('sidebar');
                if (!sidebar) {
                    return;
                }

                sidebar.classList.toggle('mobile-open');
            }

            function openProfileModal() {
                const profileModal = document.getElementById('profileModal');
                if (!profileModal) {
                    return;
                }

                profileModal.classList.add('show');
                profileModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
            }

            function closeProfileModal() {
                const profileModal = document.getElementById('profileModal');
                if (!profileModal) {
                    return;
                }

                profileModal.classList.remove('show');
                profileModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
            }
        </script>
        <!-- Add Product Modal -->
        <div id="addModal"
            style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
            <div class="modal-content-horizontal"
                style="display:grid; grid-template-columns: 2fr 1fr; gap:40px; max-width:820px; width:95vw; padding:36px 36px 28px 36px; border-radius:16px; background:#fff; box-shadow:0 8px 32px rgba(0,0,0,0.18); position:relative; align-items:start;">
                <button type="button" id="closeAddModal"
                    style="position:absolute; top:14px; right:18px; background:none; border:none; font-size:24px; color:#888;">&times;</button>
                <form method="post" enctype="multipart/form-data"
                    style="display:grid; grid-template-columns: 1fr 1fr; gap:18px 24px; width:100%; align-items:start; background:none; box-shadow:none; border:none; padding:0; margin:0;">
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
                        <input name="brand_new" id="brandNewInput" placeholder="Enter new brand"
                            style="display:none; margin-top:6px;">
                    </label>
                    <label style="grid-column:2;">Category:
                        <select name="category" id="categorySelect" onchange="toggleCategoryInput(this)">
                            <option value="">Select category</option>
                            <?php foreach($categories as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__">Add new category...</option>
                        </select>
                        <input name="category_new" id="categoryNewInput" placeholder="Enter new category"
                            style="display:none; margin-top:6px;">
                    </label>
                    <label style="grid-column:1/3;">Description:
                        <textarea name="description" placeholder="Enter product description"></textarea>
                    </label>
                    <label style="grid-column:1;">Quantity:
                        <input name="quantity" type="number" min="0" required placeholder="Enter quantity">
                    </label>
                    <label style="grid-column:2;">Price per unit:
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
                        <input name="supplier_new" id="supplierNewInput" placeholder="Enter new supplier"
                            style="display:none; margin-top:6px;">
                    </label>
                    <label style="grid-column:2;">Low Stock Threshold:
                        <input name="low_stock_threshold" value="5" type="number" min="0" required>
                    </label>
                    <button name="save_product" type="submit" style="margin-top:10px; grid-column:1/3;">Add</button>
                </form>
                <div class="modal-image-upload"
                    style="display:flex; flex-direction:column; align-items:center; justify-content:flex-start; gap:18px; min-width:180px; max-width:220px;">
                    <label style="width:100%;text-align:center;">Product Image:
                        <input name="image" type="file" accept="image/*" onchange="previewAddImage(event)">
                    </label>
                    <img id="addImagePreview" class="modal-image-preview"
                        src="https://via.placeholder.com/120x120?text=No+Image" alt="Preview">
                </div>
            </div>
        </div>
        <script>
            function previewAddImage(event) {
                const [file] = event.target.files;
                if (file) {
                    document.getElementById('addImagePreview').src = URL.createObjectURL(file);
                } else {
                    document.getElementById('addImagePreview').src =
                    'https://via.placeholder.com/120x120?text=No+Image';
                }
            }

            // Added: helper to synchronise select + optional text input when editing records.
            function setSelectWithFallback(selectId, inputId, value) {
                const selectEl = document.getElementById(selectId);
                const inputEl = document.getElementById(inputId);
                if (!selectEl || !inputEl) {
                    return;
                }

                const normalisedValue = value || '';
                const hasMatchingOption = Array.from(selectEl.options).some(opt => opt.value === normalisedValue && normalisedValue !== '');

                if (normalisedValue && hasMatchingOption) {
                    selectEl.value = normalisedValue;
                    inputEl.style.display = 'none';
                    inputEl.required = false;
                    inputEl.value = '';
                } else if (normalisedValue) {
                    selectEl.value = '__addnew__';
                    inputEl.style.display = 'block';
                    inputEl.required = true;
                    inputEl.value = normalisedValue;
                } else {
                    selectEl.value = '';
                    inputEl.style.display = 'none';
                    inputEl.required = false;
                    inputEl.value = '';
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
        <!-- Products table displaying filtered/search results -->
        <div id="productsTable">
            <table>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Action</th>
                </tr>
            <?php if (empty($products)): ?>
            <tr>
                <td colspan="5" style="text-align: center; padding: 20px;">No products found matching the criteria.</td>
            </tr>
            <?php else: ?>
            <?php foreach($products as $p): ?>
            <tr>
                <td><?=htmlspecialchars($p['code'])?></td>
                <td><?=htmlspecialchars($p['name'])?></td>
                <td><?=intval($p['quantity'])?></td>
                <td>₱<?=number_format($p['price'],2)?></td>
                <td> <a href="#" class="edit-btn action-btn" data-id="<?=$p['id']?>"
                        data-code="<?=htmlspecialchars($p['code'])?>" data-name="<?=htmlspecialchars($p['name'])?>"
                        data-description="<?=htmlspecialchars($p['description'])?>"
                        data-price="<?=htmlspecialchars($p['price'])?>"
                        data-quantity="<?=htmlspecialchars($p['quantity'])?>"
                        data-low="<?=htmlspecialchars($p['low_stock_threshold'])?>"
                        data-brand="<?=htmlspecialchars($p['brand'] ?? '')?>"
                        data-category="<?=htmlspecialchars($p['category'] ?? '')?>"
                        data-supplier="<?=htmlspecialchars($p['supplier'] ?? '')?>"><i
                            class="fas fa-edit"></i>Edit</a>
                <a href="products.php?delete=<?=$p['id']?>" class="delete-btn action-btn"
                    onclick="return confirm('Delete?')"> <i class="fas fa-trash"></i>Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <?php if ($total_products > 0): ?>
        <!-- Pagination container (styled after sales.php: info + links, preserving filter parameters) -->
        <div class="pagination-container">
            <div class="pagination-info">
                Showing <?=$start_record?> to <?=$end_record?> of <?=$total_products?> entries
            </div>
            <div class="pagination">
                <?php
                // Prepare current parameters without 'page' for base links (to preserve filters)
                $current_params = $_GET;
                unset($current_params['page']);
                $base_query = http_build_query($current_params);
                $separator = $base_query ? '&' : '?';
                ?>

                <!-- Previous button -->
                <?php if ($page > 1): ?>
                <a href="?<?= $base_query . $separator ?>page=<?= ($page - 1) ?>" class="prev">
                    <i class="fas fa-chevron-left"></i> Prev
                </a>
                <?php else: ?>
                <span class="prev disabled">
                    <i class="fas fa-chevron-left"></i> Prev
                </span>
                <?php endif; ?>

                <!-- Page numbers -->
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                // Show first page if not in range
                if ($start_page > 1): ?>
                <a href="?<?= $base_query . $separator ?>page=1">1</a>
                <?php if ($start_page > 2): ?>
                <span>...</span>
                <?php endif; ?>
                <?php endif; ?>
                
                <!-- Show page numbers in range -->
                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i == $page): ?>
                <span class="current"><?= $i ?></span>
                <?php else: ?>
                <a href="?<?= $base_query . $separator ?>page=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
                <?php endfor; ?>
                
                <!-- Show last page if not in range -->
                <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                <span>...</span>
                <?php endif; ?>
                <a href="?<?= $base_query . $separator ?>page=<?= $total_pages ?>"><?= $total_pages ?></a>
                <?php endif; ?>

                <!-- Next button -->
                <?php if ($page < $total_pages): ?>
                <a href="?<?= $base_query . $separator ?>page=<?= ($page + 1) ?>" class="next">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
                <?php else: ?>
                <span class="next disabled">
                    Next <i class="fas fa-chevron-right"></i>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
        <!-- Edit Product Modal -->
        <div id="editModal"
            style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
            <div class="modal-content-horizontal"
                style="display:grid; grid-template-columns: 2fr 1fr; gap:40px; max-width:820px; width:95vw; padding:36px 36px 28px 36px; border-radius:16px; background:#fff; box-shadow:0 8px 32px rgba(0,0,0,0.18); position:relative; align-items:start;">
                <button type="button" id="closeEditModal"
                    style="position:absolute; top:14px; right:18px; background:none; border:none; font-size:24px; color:#888;">&times;</button>
                <form method="post" id="editProductForm" enctype="multipart/form-data"
                    style="display:grid; grid-template-columns: 1fr 1fr; gap:18px 24px; width:100%; align-items:start; background:none; box-shadow:none; border:none; padding:0; margin:0;">
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
                        <input name="brand_new" id="edit_brand_new" placeholder="Enter new brand"
                            style="display:none; margin-top:6px;">
                    </label>
                    <label style="grid-column:2;">Category:
                        <select name="category" id="edit_category" onchange="toggleCategoryInputEdit(this)">
                            <option value="">Select category</option>
                            <?php foreach($categories as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__">Add new category...</option>
                        </select>
                        <input name="category_new" id="edit_category_new" placeholder="Enter new category"
                            style="display:none; margin-top:6px;">
                    </label>
                    <label style="grid-column:1/3;">Description:
                        <textarea name="description" id="edit_description"
                            placeholder="Enter product description"></textarea>
                    </label>
                    <label style="grid-column:1;">Quantity:
                        <input name="quantity" id="edit_quantity" type="number" min="0" required
                            placeholder="Enter quantity">
                    </label>
                    <label style="grid-column:2;">Price per unit:
                        <input name="price" id="edit_price" type="number" min="0" step="0.01" required
                            placeholder="Enter price">
                    </label>
                    <label style="grid-column:1;">Supplier:
                        <select name="supplier" id="edit_supplier" onchange="toggleSupplierInputEdit(this)">
                            <option value="">Select supplier</option>
                            <?php foreach($suppliers as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                            <option value="__addnew__">Add new supplier...</option>
                        </select>
                        <input name="supplier_new" id="edit_supplier_new" placeholder="Enter new supplier"
                            style="display:none; margin-top:6px;">
                    </label>
                    <label style="grid-column:2;">Low Stock Threshold:
                        <input name="low_stock_threshold" id="edit_low" value="5" type="number" min="0" required>
                    </label>
                    <button name="save_product" type="submit" style="margin-top:10px; grid-column:1/3;">Save
                        Changes</button>
                </form>
                <div class="modal-image-upload"
                    style="display:flex; flex-direction:column; align-items:center; justify-content:flex-start; gap:18px; min-width:180px; max-width:220px;">
                    <label style="width:100%;text-align:center;">Product Image:
                        <input name="image" id="edit_image" type="file" accept="image/*"
                            onchange="previewEditImage(event)">
                    </label>
                    <img id="editImagePreview" class="modal-image-preview"
                        src="https://via.placeholder.com/120x120?text=No+Image" alt="Preview">
                </div>
            </div>
        </div>
    </main>
     <div class="modal-overlay" id="profileModal" aria-hidden="true">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
            <button type="button" class="modal-close" id="profileModalClose" aria-label="Close profile information">
                <i class="fas fa-times"></i>
            </button>
            <h3 id="profileModalTitle">Profile information</h3>
            <div class="profile-info">
                <div class="profile-row">
                    <span class="profile-label">Name</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_name) ?></span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">Role</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_role) ?></span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">Date created</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_created) ?></span>
                </div>
            </div>
        </div>
    </div>
    <script>
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

        document.addEventListener('DOMContentLoaded', () => {
            const historyModal = document.getElementById('historyModal');
            const historyList = document.getElementById('historyList');
            const openHistoryButton = document.getElementById('openHistoryModal');
            const closeHistoryButton = document.getElementById('closeHistoryModal');

            openHistoryButton?.addEventListener('click', () => {
                if (!historyModal || !historyList) {
                    return;
                }

                historyModal.style.display = 'flex';
                historyList.innerHTML =
                    '<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>';

                fetch('products.php?history=1', {
                    cache: 'no-store',
                    headers: { 'Accept': 'text/html' }
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.text();
                    })
                    .then((html) => {
                        historyList.innerHTML = html;
                    })
                    .catch((error) => {
                        historyList.innerHTML = `
                            <div style="text-align:center;padding:20px;color:#dc3545;">
                                <i class="fas fa-exclamation-circle"></i> Error loading history: ${error.message}
                            </div>`;
                    });
            });

            closeHistoryButton?.addEventListener('click', () => {
                if (historyModal) {
                    historyModal.style.display = 'none';
                }
            });

            historyModal?.addEventListener('click', (event) => {
                if (event.target === historyModal) {
                    historyModal.style.display = 'none';
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && historyModal?.style.display === 'flex') {
                    historyModal.style.display = 'none';
                }
            });

            const profileButton = document.getElementById('profileTrigger');
            const profileModal = document.getElementById('profileModal');
            const profileModalClose = document.getElementById('profileModalClose');

            profileButton?.addEventListener('click', (event) => {
                event.preventDefault();
                document.getElementById('userDropdown')?.classList.remove('show');
                openProfileModal();
            });

            profileModalClose?.addEventListener('click', () => {
                closeProfileModal();
            });

            profileModal?.addEventListener('click', (event) => {
                if (event.target === profileModal) {
                    closeProfileModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && profileModal?.classList.contains('show')) {
                    closeProfileModal();
                }
            });

            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.querySelector('.mobile-toggle');

            document.addEventListener('click', (event) => {
                const userMenu = document.querySelector('.user-menu');
                if (userMenu && !userMenu.contains(event.target)) {
                    document.getElementById('userDropdown')?.classList.remove('show');
                }

                if (
                    window.innerWidth <= 768 &&
                    sidebar &&
                    mobileToggle &&
                    !sidebar.contains(event.target) &&
                    !mobileToggle.contains(event.target)
                ) {
                    sidebar.classList.remove('mobile-open');
                }
            });

            const editModal = document.getElementById('editModal');

            document.querySelectorAll('.edit-btn').forEach((btn) => {
                btn.addEventListener('click', (event) => {
                    event.preventDefault();
                    (document.getElementById('edit_id') ?? {}).value = btn.dataset.id ?? '';
                    (document.getElementById('edit_code') ?? {}).value = btn.dataset.code ?? '';
                    (document.getElementById('edit_name') ?? {}).value = btn.dataset.name ?? '';
                    (document.getElementById('edit_description') ?? {}).value = btn.dataset.description ?? '';
                    (document.getElementById('edit_price') ?? {}).value = btn.dataset.price ?? '';
                    (document.getElementById('edit_quantity') ?? {}).value = btn.dataset.quantity ?? '';
                    (document.getElementById('edit_low') ?? {}).value = btn.dataset.low ?? '';
                    setSelectWithFallback('edit_brand', 'edit_brand_new', btn.dataset.brand || '');
                    setSelectWithFallback('edit_category', 'edit_category_new', btn.dataset.category || '');
                    setSelectWithFallback('edit_supplier', 'edit_supplier_new', btn.dataset.supplier || '');
                    const preview = document.getElementById('editImagePreview');
                    if (preview) {
                        preview.src = 'https://via.placeholder.com/120x120?text=No+Image';
                    }
                    if (editModal) {
                        editModal.style.display = 'flex';
                    }
                });
            });

            document.getElementById('closeEditModal')?.addEventListener('click', () => {
                if (editModal) {
                    editModal.style.display = 'none';
                }
            });

            editModal?.addEventListener('click', (event) => {
                if (event.target === editModal) {
                    editModal.style.display = 'none';
                }
            });

            const addModal = document.getElementById('addModal');

            document.getElementById('openAddModal')?.addEventListener('click', () => {
                if (addModal) {
                    addModal.style.display = 'flex';
                }
                setSelectWithFallback('brandSelect', 'brandNewInput', '');
                setSelectWithFallback('categorySelect', 'categoryNewInput', '');
                setSelectWithFallback('supplierSelect', 'supplierNewInput', '');
            });

            document.getElementById('closeAddModal')?.addEventListener('click', () => {
                if (addModal) {
                    addModal.style.display = 'none';
                }
            });

            addModal?.addEventListener('click', (event) => {
                if (event.target === addModal) {
                    addModal.style.display = 'none';
                }
            });
        });
    </script>

   

    <script src="../assets/js/notifications.js"></script>

</body>

</html>
