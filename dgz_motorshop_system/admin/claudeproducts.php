<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Products - POS System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f5f7fa;
            color: #333;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: 250px;
            background: linear-gradient(180deg, #4a5568 0%, #2d3748 100%);
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
        }

        .logo i {
            margin-right: 10px;
            color: #60a5fa;
        }

        .nav-menu {
            padding: 20px 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .nav-link.active {
            border-right: 3px solid #60a5fa;
        }

        .nav-icon {
            margin-right: 12px;
            width: 20px;
        }

        /* Main Content */
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: white;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
        }

        .header-left h2 {
            color: #2d3748;
            font-size: 28px;
            font-weight: 600;
        }

        .mobile-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            margin-right: 15px;
            cursor: pointer;
        }

        .user-menu {
            position: relative;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: #60a5fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .user-avatar:hover {
            background: #3b82f6;
        }

        .dropdown-menu {
            position: absolute;
            top: 50px;
            right: 0;
            background: white;
            min-width: 180px;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            color: #4a5568;
            text-decoration: none;
            transition: background 0.3s ease;
        }

        .dropdown-item:hover {
            background: #f7fafc;
        }

        .dropdown-item i {
            margin-right: 10px;
            width: 16px;
        }

        .dropdown-item.logout {
            color: #e53e3e;
            border-top: 1px solid #e2e8f0;
        }

        /* Content Area */
        .content {
            padding: 30px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid;
        }

        .stat-card.total { border-left-color: #60a5fa; }
        .stat-card.low-stock { border-left-color: #f56565; }
        .stat-card.categories { border-left-color: #48bb78; }
        .stat-card.value { border-left-color: #ed8936; }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #718096;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 25px;
            gap: 20px;
        }

        .search-box {
            flex: 1;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: #60a5fa;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: #60a5fa;
            color: white;
        }

        .btn-primary:hover {
            background: #3b82f6;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: white;
            color: #4a5568;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            border-color: #cbd5e0;
            background: #f7fafc;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #e53e3e;
        }

        /* Product Form Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }

        .modal-header h3 {
            color: #2d3748;
            font-size: 20px;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            color: #a0aec0;
            cursor: pointer;
        }

        .close-btn:hover {
            color: #4a5568;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 500;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #60a5fa;
        }

        .form-group textarea {
            height: 80px;
            resize: vertical;
        }

        /* Products Table */
        .products-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .section-header {
            padding: 25px 30px;
            border-bottom: 2px solid #f1f5f9;
        }

        .section-header h3 {
            color: #2d3748;
            font-size: 18px;
            font-weight: 600;
        }

        .products-table {
            width: 100%;
            border-collapse: collapse;
        }

        .products-table th {
            background: #f8fafc;
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .products-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f5f9;
            color: #4a5568;
        }

        .products-table tr:hover {
            background: #f8fafc;
        }

        .product-code {
            font-family: 'Courier New', monospace;
            background: #e2e8f0;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }

        .stock-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .stock-status.in-stock {
            background: #c6f6d5;
            color: #22543d;
        }

        .stock-status.low-stock {
            background: #fed7d7;
            color: #742a2a;
        }

        .price {
            font-weight: 600;
            color: #2d3748;
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #a0aec0;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .empty-state h4 {
            margin-bottom: 10px;
            color: #4a5568;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-toggle {
                display: block;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .products-table {
                font-size: 12px;
            }

            .products-table th,
            .products-table td {
                padding: 10px 8px;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-store"></i>
                POS System
            </div>
        </div>
        <nav class="nav-menu">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-home nav-icon"></i>
                Dashboard
            </a>
            <a href="products.php" class="nav-link active">
                <i class="fas fa-box nav-icon"></i>
                Products
            </a>
            <a href="sales.php" class="nav-link">
                <i class="fas fa-chart-line nav-icon"></i>
                Sales
            </a>
            <a href="pos.php" class="nav-link">
                <i class="fas fa-cash-register nav-icon"></i>
                POS
            </a>
            <a href="orders.php" class="nav-link">
                <i class="fas fa-shopping-cart nav-icon"></i>
                Orders
            </a>
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
                <h2>Products</h2>
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

        <!-- Content -->
        <div class="content">
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number" id="totalProducts">0</div>
                    <div class="stat-label">Total Products</div>
                </div>
                <div class="stat-card low-stock">
                    <div class="stat-number" id="lowStockItems">0</div>
                    <div class="stat-label">Low Stock Items</div>
                </div>
                <div class="stat-card categories">
                    <div class="stat-number">₱0.00</div>
                    <div class="stat-label">Total Inventory Value</div>
                </div>
                <div class="stat-card value">
                    <div class="stat-number">0</div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search products..." id="searchInput">
                </div>
                <button class="btn btn-primary" onclick="openModal()">
                    <i class="fas fa-plus"></i>
                    Add Product
                </button>
                <button class="btn btn-secondary">
                    <i class="fas fa-download"></i>
                    Export
                </button>
            </div>

            <!-- Products Table -->
            <div class="products-section">
                <div class="section-header">
                    <h3>All Products</h3>
                </div>
                <table class="products-table" id="productsTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Product Name</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="productsTableBody">
                        <!-- Sample data - replace with PHP loop -->
                        <tr>
                            <td><span class="product-code">P001</span></td>
                            <td>Sample Product 1</td>
                            <td>25</td>
                            <td class="price">₱299.00</td>
                            <td><span class="stock-status in-stock">In Stock</span></td>
                            <td class="actions">
                                <button class="btn btn-secondary btn-sm" onclick="editProduct(1)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteProduct(1)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td><span class="product-code">P002</span></td>
                            <td>Sample Product 2</td>
                            <td>3</td>
                            <td class="price">₱150.00</td>
                            <td><span class="stock-status low-stock">Low Stock</span></td>
                            <td class="actions">
                                <button class="btn btn-secondary btn-sm" onclick="editProduct(2)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteProduct(2)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Product Form Modal -->
    <div class="modal" id="productModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add Product</h3>
                <button class="close-btn" onclick="closeModal()">×</button>
            </div>
            <form id="productForm" method="post">
                <input type="hidden" name="id" id="productId" value="0">
                
                <div class="form-group">
                    <label for="code">Product Code</label>
                    <input type="text" name="code" id="code" required>
                </div>
                
                <div class="form-group">
                    <label for="name">Product Name</label>
                    <input type="text" name="name" id="name" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" id="description"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="price">Price (₱)</label>
                    <input type="number" name="price" id="price" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label for="quantity">Quantity</label>
                    <input type="number" name="quantity" id="quantity" required>
                </div>
                
                <div class="form-group">
                    <label for="low_stock_threshold">Low Stock Threshold</label>
                    <input type="number" name="low_stock_threshold" id="low_stock_threshold" value="5" required>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 25px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="save_product" class="btn btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openModal(isEdit = false, productData = null) {
            const modal = document.getElementById('productModal');
            const modalTitle = document.getElementById('modalTitle');
            const form = document.getElementById('productForm');
            
            if (isEdit && productData) {
                modalTitle.textContent = 'Edit Product';
                document.getElementById('productId').value = productData.id;
                document.getElementById('code').value = productData.code;
                document.getElementById('name').value = productData.name;
                document.getElementById('description').value = productData.description;
                document.getElementById('price').value = productData.price;
                document.getElementById('quantity').value = productData.quantity;
                document.getElementById('low_stock_threshold').value = productData.low_stock_threshold;
            } else {
                modalTitle.textContent = 'Add Product';
                form.reset();
                document.getElementById('productId').value = '0';
                document.getElementById('low_stock_threshold').value = '5';
            }
            
            modal.classList.add('show');
        }

        function closeModal() {
            const modal = document.getElementById('productModal');
            modal.classList.remove('show');
        }

        function editProduct(id) {
            // In real implementation, fetch product data via AJAX
            const productData = {
                id: id,
                code: 'P00' + id,
                name: 'Sample Product ' + id,
                description: 'Sample description',
                price: id === 1 ? '299.00' : '150.00',
                quantity: id === 1 ? '25' : '3',
                low_stock_threshold: '5'
            };
            openModal(true, productData);
        }

        function deleteProduct(id) {
            if (confirm('Are you sure you want to delete this product?')) {
                // In real implementation, send delete request
                window.location.href = 'products.php?delete=' + id;
            }
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#productsTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Update stats (replace with real data)
        function updateStats() {
            document.getElementById('totalProducts').textContent = '2';
            document.getElementById('lowStockItems').textContent = '1';
        }

        // User menu functions
        function toggleDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');
            const modal = document.getElementById('productModal');
            
            if (!userMenu.contains(event.target)) {
                dropdown.classList.remove('show');
            }
            
            if (event.target === modal) {
                closeModal();
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

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateStats();
        });
    </script>
</body>
</html>