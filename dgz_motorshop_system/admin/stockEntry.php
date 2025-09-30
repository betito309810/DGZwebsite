<?php
require __DIR__ . '/../config/config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();
$notificationManageLink = 'inventory.php';

require_once __DIR__ . '/includes/inventory_notifications.php';
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

// Handle stock entry submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock'])) {
    $product_id = $_POST['product_id'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $supplier = $_POST['supplier'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $purchase_price = isset($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : 0;
    
    if ($product_id && $quantity > 0) {
        try {
            // Start transaction
            $pdo->beginTransaction();
            // Update product quantity
            $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
            $stmt->execute([$quantity, $product_id]);
            // Record stock entry with the current user and purchase price
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

/**
 * Fetch all products for the product selection dropdown.
 * This will be used to populate the product options in the form.
 */
$products = $pdo->query("SELECT * FROM products ORDER BY name")->fetchAll();

/**
 * Fetch distinct categories from products table to populate the category filter dropdown.
 * Only non-empty categories are included.
 */
$categories = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

/**
 * Fetch distinct brands from products table to populate the brand filter dropdown.
 * Only non-empty brands are included.
 */
$brands = $pdo->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);

/**
 * Fetch distinct suppliers from products table to populate the supplier dropdown.
 * Only non-empty suppliers are included.
 */
$suppliers = $pdo->query("SELECT DISTINCT supplier FROM products WHERE supplier IS NOT NULL AND supplier != '' ORDER BY supplier")->fetchAll(PDO::FETCH_COLUMN);

// Get recent stock entries with user information
$recent_entries = $pdo->query("
    SELECT se.*, p.name as product_name, u.name as user_name 
    FROM stock_entries se 
    JOIN products p ON p.id = se.product_id 
    LEFT JOIN users u ON u.id = se.stock_in_by 
    ORDER BY se.created_at DESC 
    LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Entry - DGZ</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/inventory/stockEntry.css">
    <link rel="stylesheet" href="../assets/css/dashboard/dashboard.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
</head>
<body>
    <!-- Sidebar -->
    <?php
        $activePage = 'inventory.php';
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
                <h2>Stock Entry</h2>
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

        <div class="page-actions">
            <a href="inventory.php" class="btn-action back-btn">Back to Inventory</a>
        </div>

        <!-- Stock Entry Content -->
        <div class="dashboard-content">
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <!-- Stock Entry Form -->
            <div class="stock-entry-form">
                <h3 style="margin-bottom: 20px;">Add New Stock</h3>
                <form method="POST" action="">
                    <div class="form-grid">
                    <div class="form-group">
                        <label for="product_search">Search Product</label>
                        <input type="text" id="product_search" placeholder="Search products..." autocomplete="off">
                        <div id="autocomplete-list" class="autocomplete-items"></div>
                    </div>

                    <div class="form-group">
                        <label for="product_id">Product</label>
                        <select name="product_id" id="product_id" required>
                            <option value="">Select Product</option>
                            <?php
                            // Populate product dropdown with products from database
                            foreach ($products as $product) {
                                echo '<option value="' . htmlspecialchars($product['id']) . '">' . htmlspecialchars($product['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="category_filter">Filter by Category</label>
                        <select id="category_filter" name="category_filter">
                            <option value="">All Categories</option>
                            <?php
                            // Populate category filter dropdown with distinct categories from products
                            foreach ($categories as $category) {
                                echo '<option value="' . htmlspecialchars($category) . '">' . htmlspecialchars($category) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="brand_filter">Filter by Brand</label>
                        <select id="brand_filter" name="brand_filter">
                            <option value="">All Brands</option>
                            <?php
                            // Populate brand filter dropdown with distinct brands from products
                            foreach ($brands as $brand) {
                                echo '<option value="' . htmlspecialchars($brand) . '">' . htmlspecialchars($brand) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Removed duplicate search product input on the right as per user request -->

                    <div class="form-group">
                        <label for="quantity">Quantity to Add</label>
                        <input type="number" name="quantity" id="quantity" min="1" required>
                    </div>
                    <!-- Removed Purchased Price per Unit input as per user request -->
                    
                    <div class="form-group">
                        <label for="supplier">Supplier</label>
                        <select name="supplier" id="supplier" required>
                            <option value="">Select Supplier</option>
                            <?php
                            // Populate supplier dropdown with distinct suppliers from products
                            foreach ($suppliers as $supplier) {
                                echo '<option value="' . htmlspecialchars($supplier) . '">' . htmlspecialchars($supplier) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea name="notes" id="notes" placeholder="Enter any additional notes..."></textarea>
                    </div>
                    
                    <button type="submit" name="add_stock" class="submit-btn">
                        <i class="fas fa-plus"></i> Add Stock
                    </button>
                </form>
            </div>

            <!-- Recent Stock Entries -->
            <div class="recent-entries">
                <h3 style="margin-bottom: 20px;">Recent Stock Entries</h3>
                <table class="entries-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Quantity Added</th>
                            <!-- Removed Cost (per unit) column as per user request -->
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
                                <td><?php echo htmlspecialchars($entry['supplier']); ?></td>
                                <td><?php echo htmlspecialchars($entry['user_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($entry['notes']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_entries)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">No recent stock entries</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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

        const profileButton = document.getElementById('profileTrigger');
        const profileModal = document.getElementById('profileModal');
        const profileModalClose = document.getElementById('profileModalClose');

        function openProfileModal() {
            if (!profileModal) {
                return;
            }

            profileModal.classList.add('show');
            profileModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }

        function closeProfileModal() {
            if (!profileModal) {
                return;
            }

            profileModal.classList.remove('show');
            profileModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');

            if (!userMenu.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        profileButton?.addEventListener('click', function(event) {
            event.preventDefault();
            const dropdown = document.getElementById('userDropdown');
            dropdown?.classList.remove('show');
            openProfileModal();
        });

        profileModalClose?.addEventListener('click', function() {
            closeProfileModal();
        });

        profileModal?.addEventListener('click', function(event) {
            if (event.target === profileModal) {
                closeProfileModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && profileModal?.classList.contains('show')) {
                closeProfileModal();
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-toggle');

            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !toggle.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });
    </script>
    <script src="../assets/js/notifications.js"></script>
    <style>
        /* Styles for autocomplete items */
        .autocomplete-items {
            position: absolute;
            border: 1px solid #d4d4d4;
            border-bottom: none;
            border-top: none;
            z-index: 9999;
            /* position the autocomplete items to be the same width as the container: */
            top: 100%;
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
            background-color: white;
        }
        /* Ensure the parent container of autocomplete is positioned relative for correct absolute positioning */
        .form-group {
            position: relative;
        }

        .autocomplete-items div {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #d4d4d4;
        }

        .autocomplete-items div:hover {
            background-color: #e9e9e9;
        }
    </style>

    <script>
        // Pass all products data to JavaScript for client-side filtering and autocomplete
        const allProducts = <?php echo json_encode($products); ?>;

        // Get references to filter dropdowns, product dropdown, search input, and autocomplete list container
        const categoryFilter = document.getElementById('category_filter');
        const brandFilter = document.getElementById('brand_filter');
        const supplierFilter = document.getElementById('supplier');
        const productDropdown = document.getElementById('product_id');
        const productSearch = document.getElementById('product_search');
        const autocompleteList = document.getElementById('autocomplete-list');

        // Function to filter products based on selected filters
        function filterProducts() {
            const selectedCategory = categoryFilter.value;
            const selectedBrand = brandFilter.value;
            const selectedSupplier = supplierFilter.value;

            // Filter products based on selected filters
            return allProducts.filter(product => {
                const matchesCategory = selectedCategory === '' || product.category === selectedCategory;
                const matchesBrand = selectedBrand === '' || product.brand === selectedBrand;
                const matchesSupplier = selectedSupplier === '' || product.supplier === selectedSupplier;
                return matchesCategory && matchesBrand && matchesSupplier;
            });
        }

        // Function to show autocomplete suggestions based on search input and filters
        function showAutocomplete() {
            const searchText = productSearch.value.trim().toLowerCase();
            autocompleteList.innerHTML = '';

            if (!searchText) {
                return;
            }

            const filteredProducts = filterProducts().filter(product =>
                product.name.toLowerCase().includes(searchText)
            );

            filteredProducts.forEach(product => {
                const item = document.createElement('div');
                item.textContent = product.name;
                item.addEventListener('click', () => {
                    productSearch.value = product.name;
                    autocompleteList.innerHTML = '';
                    // Set the product dropdown to the selected product
                    productDropdown.value = product.id;
                });
                autocompleteList.appendChild(item);
            });
        }

        // Event listeners for filters to clear search and autocomplete and update product dropdown
        categoryFilter.addEventListener('change', () => {
            productSearch.value = '';
            autocompleteList.innerHTML = '';
            updateProductDropdown();
            updateBrandAndSupplierDropdowns();
        });
        // Flag to prevent infinite loop when updating dropdowns
        let isUpdatingDropdowns = false;

        brandFilter.addEventListener('change', () => {
            if (isUpdatingDropdowns) return;
            productSearch.value = '';
            autocompleteList.innerHTML = '';

            // Preserve selected product if it matches the selected brand and supplier
            const selectedProductId = productDropdown.value;
            const selectedBrand = brandFilter.value;
            const selectedSupplier = supplierFilter.value;
            const selectedProduct = allProducts.find(p => p.id == selectedProductId);

            isUpdatingDropdowns = true;
            if (selectedProduct && 
                (selectedBrand === '' || selectedProduct.brand === selectedBrand) &&
                (selectedSupplier === '' || selectedProduct.supplier === selectedSupplier)) {
                // Keep the selected product
                updateProductDropdown(true);
            } else {
                // Reset product selection
                productDropdown.value = '';
                updateProductDropdown(false);
            }
            updateBrandAndSupplierDropdowns();
            isUpdatingDropdowns = false;
        });

        supplierFilter.addEventListener('change', () => {
            if (isUpdatingDropdowns) return;
            productSearch.value = '';
            autocompleteList.innerHTML = '';

            // Preserve selected product if it matches the selected brand and supplier
            const selectedProductId = productDropdown.value;
            const selectedBrand = brandFilter.value;
            const selectedSupplier = supplierFilter.value;
            const selectedProduct = allProducts.find(p => p.id == selectedProductId);

            isUpdatingDropdowns = true;
            if (selectedProduct && 
                (selectedBrand === '' || selectedProduct.brand === selectedBrand) &&
                (selectedSupplier === '' || selectedProduct.supplier === selectedSupplier)) {
                // Keep the selected product
                updateProductDropdown(true);
            } else {
                // Reset product selection
                productDropdown.value = '';
                updateProductDropdown(false);
            }
            updateBrandAndSupplierDropdowns();
            isUpdatingDropdowns = false;
        });

        // Event listener for product dropdown change to update category, brand, and supplier
        productDropdown.addEventListener('change', () => {
            const selectedProductId = productDropdown.value;
            const selectedProduct = allProducts.find(p => p.id == selectedProductId);

            if (selectedProduct) {
                // Automatically fill category with only the selected product's category
                categoryFilter.innerHTML = '';
                if (selectedProduct.category) {
                    const option = document.createElement('option');
                    option.value = selectedProduct.category;
                    option.textContent = selectedProduct.category;
                    option.selected = true;
                    categoryFilter.appendChild(option);
                }

                // Filter brand and supplier dropdowns based on selected product
                updateBrandAndSupplierDropdowns(selectedProduct);
            } else {
                // Reset category dropdown to show all categories
                categoryFilter.innerHTML = '<option value="">All Categories</option>';
                <?php foreach ($categories as $category): ?>
                {
                    const option = document.createElement('option');
                    option.value = <?= json_encode($category) ?>;
                    option.textContent = <?= json_encode($category) ?>;
                    categoryFilter.appendChild(option);
                }
                <?php endforeach; ?>

                updateBrandAndSupplierDropdowns();
            }
        });

        // Event listener for search input to show autocomplete suggestions
        productSearch.addEventListener('input', showAutocomplete);

        // Function to update product dropdown based on filters (without search)
        // If keepSelected is true, preserve the current selected product if it exists in the filtered list
        function updateProductDropdown(keepSelected = false) {
            const filteredProducts = filterProducts();
            const currentSelected = productDropdown.value;
            productDropdown.innerHTML = '<option value="">Select Product</option>';
            filteredProducts.forEach(product => {
                const option = document.createElement('option');
                option.value = product.id;
                option.textContent = product.name;
                productDropdown.appendChild(option);
            });
            if (keepSelected && currentSelected) {
                const exists = filteredProducts.some(p => p.id == currentSelected);
                if (exists) {
                    productDropdown.value = currentSelected;
                }
            }
        }

        // Function to update brand and supplier dropdowns based on selected product or filters
        function updateBrandAndSupplierDropdowns(selectedProduct = null) {
            let brandsToShow = [];
            let suppliersToShow = [];

            if (selectedProduct) {
                // Show only the brand and supplier of the selected product
                brandsToShow = [selectedProduct.brand].filter(Boolean);
                suppliersToShow = [selectedProduct.supplier].filter(Boolean);
            } else {
                // Show all brands and suppliers based on current filters
                const filteredProducts = filterProducts();
                brandsToShow = [...new Set(filteredProducts.map(p => p.brand).filter(Boolean))];
                suppliersToShow = [...new Set(filteredProducts.map(p => p.supplier).filter(Boolean))];
            }

            // Update brand dropdown options
            const currentBrandValue = brandFilter.value;
            brandFilter.innerHTML = '<option value="">All Brands</option>';
            brandsToShow.forEach(brand => {
                const option = document.createElement('option');
                option.value = brand;
                option.textContent = brand;
                if (brand === currentBrandValue) {
                    option.selected = true;
                }
                brandFilter.appendChild(option);
            });

            // Update supplier dropdown options
            const currentSupplierValue = supplierFilter.value;
            supplierFilter.innerHTML = '<option value="">Select Supplier</option>';
            suppliersToShow.forEach(supplier => {
                const option = document.createElement('option');
                option.value = supplier;
                option.textContent = supplier;
                if (supplier === currentSupplierValue) {
                    option.selected = true;
                }
                supplierFilter.appendChild(option);
            });
        }

        // Initial population of product dropdown
        updateProductDropdown();

        // Close autocomplete list when clicking outside
        document.addEventListener('click', function (e) {
            if (e.target !== productSearch) {
                autocompleteList.innerHTML = '';
            }
        });
    </script>
</body>
</html>
