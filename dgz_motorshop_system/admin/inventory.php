<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
$role = $_SESSION['role'] ?? '';


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
    <link rel="stylesheet" href="../assets/css/inventory.css">
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .submit-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

        .restock-request {
            margin: 20px 0;
            padding: 20px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .restock-request h3 {
            margin-top: 0;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.2rem;
        }

        .restock-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
           
        </div>

        <div id="restockRequestForm" class="restock-request hidden">
            <h3><i class="fas fa-clipboard-list"></i> Submit Restock Request</h3>
            <form>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="restock_product">Product</label>
                        <select id="restock_product" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                    (Current: <?php echo $product['quantity']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="restock_quantity">Requested Quantity</label>
                        <input type="number" id="restock_quantity" min="1" placeholder="Enter quantity" required>
                    </div>
                    <div class="form-group">
                        <label for="restock_priority">Priority Level</label>
                        <select id="restock_priority" required>
                            <option value="">Select Priority</option>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="restock_needed_by">Needed By</label>
                        <input type="date" id="restock_needed_by">
                    </div>
                </div>
                <div class="form-group">
                    <label for="restock_notes">Reason / Notes</label>
                    <textarea id="restock_notes" placeholder="Provide additional details for the restock request..."></textarea>
                </div>
                <div class="restock-actions">
                    <button type="submit" class="submit-btn">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                    <button type="button" class="btn-secondary" onclick="toggleRestockForm()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
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
        });
    </script>
</body>
</html>