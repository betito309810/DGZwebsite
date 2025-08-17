<?php
require '../config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
if(isset($_GET['delete'])){ $pdo->prepare('DELETE FROM products WHERE id=?')->execute([intval($_GET['delete'])]); header('Location: products.php'); exit; }
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_product'])){
    $name = $_POST['name']; $code = $_POST['code']; $desc = $_POST['description']; $price = floatval($_POST['price']); $qty = intval($_POST['quantity']); $low = intval($_POST['low_stock_threshold']);
    $id = intval($_POST['id']);
    if($id>0){
        $pdo->prepare('UPDATE products SET code=?,name=?,description=?,price=?,quantity=?,low_stock_threshold=? WHERE id=?')->execute([$code,$name,$desc,$price,$qty,$low,$id]);
    } else {
        $pdo->prepare('INSERT INTO products (code,name,description,price,quantity,low_stock_threshold) VALUES (?,?,?,?,?,?)')->execute([$code,$name,$desc,$price,$qty,$low]);
    }
    header('Location: products.php'); exit;
}
$products = $pdo->query('SELECT * FROM products')->fetchAll();
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/products.css">

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
        <button id="openAddModal" class="add-btn" type="button">
    <i class="fas fa-plus"></i> Add Product
</button>
<!-- Add Product Modal -->
<div id="addModal"
    style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
    <div
        style="background:#fff; border-radius:10px; max-width:400px; width:95%; margin:auto; padding:24px; position:relative;">
        <button type="button" id="closeAddModal"
            style="position:absolute; top:10px; right:10px; background:none; border:none; font-size:20px; color:#888;">&times;</button>
        <h3>Add Product</h3>
        <form method="post">
            <input type="hidden" name="id" value="0">
            <label>Code: <input name="code" required></label><br>
            <label>Name: <input name="name" required></label><br>
            <label>Description: <textarea name="description"></textarea></label><br>
            <label>Price: <input name="price" required></label><br>
            <label>Quantity: <input name="quantity" required></label><br>
            <label>Low stock threshold: <input name="low_stock_threshold" value="5" required></label><br>
            <button name="save_product" type="submit">Add</button>
        </form>
    </div>
</div>
        <h3>All Products</h3>
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
        </table>
        <!-- Edit Product Modal -->
        <div id="editModal"
            style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
            <div
                style="background:#fff; border-radius:10px; max-width:400px; width:95%; margin:auto; padding:24px; position:relative;">
                <button type="button" id="closeEditModal"
                    style="position:absolute; top:10px; right:10px; background:none; border:none; font-size:20px; color:#888;">&times;</button>
                <h3>Edit Product</h3>
                <form method="post" id="editProductForm">
                    <input type="hidden" name="id" id="edit_id">
                    <label>Code: <input name="code" id="edit_code" required></label><br>
                    <label>Name: <input name="name" id="edit_name" required></label><br>
                    <label>Description: <textarea name="description" id="edit_description"></textarea></label><br>
                    <label>Price: <input name="price" id="edit_price" required></label><br>
                    <label>Quantity: <input name="quantity" id="edit_quantity" required></label><br>
                    <label>Low stock threshold: <input name="low_stock_threshold" id="edit_low" required></label><br>
                    <button name="save_product" type="submit">Save Changes</button>
                </form>
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
                    document.getElementById('editModal').style.display = 'flex';
                });
            });
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