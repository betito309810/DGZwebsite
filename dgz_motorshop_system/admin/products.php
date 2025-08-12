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
    <title>Products</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/dashboard.css">
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
                <a href="orders.php" class="nav-link">
                    <i class="fas fa-shopping-cart nav-icon"></i>
                    Orders
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
    <h3>Add / Edit Product</h3>
    <form method="post">
        <input type="hidden" name="id" value="0">
        <label>Code: <input name="code" required></label><br>
        <label>Name: <input name="name" required></label><br>
        <label>Description: <textarea name="description"></textarea></label><br>
        <label>Price: <input name="price" required></label><br>
        <label>Quantity: <input name="quantity" required></label><br>
        <label>Low stock threshold: <input name="low_stock_threshold" value="5" required></label><br>
        <button name="save_product" type="submit">Save</button>
    </form>
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
            <td><a href="products.php?delete=<?=$p['id']?>" onclick="return confirm('Delete?')">Delete</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
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
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');
            
            if (!userMenu.contains(event.target)) {
                dropdown.classList.remove('show');
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
    
</body>

</html>