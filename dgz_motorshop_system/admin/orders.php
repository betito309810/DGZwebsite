<?php
require '../config.php';


// Ensure user is logged in
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product = $_POST['product_name'];
    $qty = (int)$_POST['quantity'];
    $type = $_POST['order_type'];
    $user_id = $_SESSION['user_id'];

    $stmt = $pdo->prepare("INSERT INTO orders (product_name, quantity, order_type, user_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$product, $qty, $type, $user_id]);
}

// Fetch all orders with user info
$sql = "SELECT o.*, u.username 
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        ORDER BY o.created_at DESC";
$orders = $pdo->query($sql)->fetchAll();
?>

<?php include '../dashboard.php'; ?> <!-- keep sidebar + header -->

<div class="container-fluid">

    <h1 class="h3 mb-4 text-gray-800">Orders Module</h1>

    <!-- Order Forms -->
    <div class="row">
        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header">Incoming Product Entry</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="order_type" value="incoming">
                        <div class="form-group">
                            <label>Product Name</label>
                            <input type="text" name="product_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Quantity</label>
                            <input type="number" name="quantity" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-success">Save Entry</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Restock Request -->
        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header">Restock Request</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="order_type" value="restock">
                        <div class="form-group">
                            <label>Product Name</label>
                            <input type="text" name="product_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Quantity</label>
                            <input type="number" name="quantity" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-warning">Request Restock</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Orders List -->
    <div class="card shadow mb-4">
        <div class="card-header">Orders & Requests</div>
        <div class="card-body">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Type</th>
                        <th>Requested By</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= $o['id'] ?></td>
                        <td><?= htmlspecialchars($o['product_name']) ?></td>
                        <td><?= $o['quantity'] ?></td>
                        <td>
                            <?= $o['order_type'] === 'incoming' 
                                ? "<span class='badge badge-success'>Incoming</span>" 
                                : "<span class='badge badge-warning'>Restock</span>" ?>
                        </td>
                        <td><?= htmlspecialchars($o['username']) ?></td>
                        <td><?= $o['created_at'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
