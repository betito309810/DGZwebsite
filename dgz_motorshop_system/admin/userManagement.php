<?php
require __DIR__ . '/../config/config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$role = $_SESSION['role'] ?? '';

if ($role !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $newRole = $_POST['role'] ?? 'staff';
    $newRole = in_array($newRole, ['admin', 'staff'], true) ? $newRole : 'staff';

    if ($name === '') {
        $errorMessage = 'Name is required.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'A valid email is required.';
    } elseif ($password === '') {
        $errorMessage = 'Password is required.';
    } elseif ($password !== $confirmPassword) {
        $errorMessage = 'Passwords do not match.';
    } else {
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password, contact_number, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([
                $name,
                $email,
                $hashedPassword,
                $contact !== '' ? $contact : null,
                $newRole
            ]);
            $successMessage = 'New user account created successfully.';
        } catch (Exception $e) {
            $errorMessage = 'Failed to add user: ' . $e->getMessage();
        }
    }
}

$users = $pdo->query('SELECT id, name, email, contact_number, role, created_at FROM users ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>User Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard/dashboard.css">
    <link rel="stylesheet" href="../assets/css/users/userManagement.css">
</head>

<body>
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
                <a href="pos.php" class="nav-link">
                    <i class="fas fa-cash-register nav-icon"></i>
                    POS
                </a>
            </div>
            <div class="nav-item">
                <a href="inventory.php" class="nav-link">
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

    <main class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>User Management</h2>
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

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <div class="page-toolbar">
            <button type="button" class="secondary-action" id="backButton">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button id="toggleAddUser" class="primary-action" type="button">
                <i class="fas fa-user-plus"></i> Add New User
            </button>
        </div>

        <div class="content-grid">
        <section id="addUserSection" class="card user-card hidden">
            <h3><i class="fas fa-id-card"></i> New User Details</h3>
            <form method="post" class="user-form">
                <input type="hidden" name="add_user" value="1">
                <div class="form-row">
                    <label for="user_name">Name</label>
                    <input type="text" id="user_name" name="name" required>
                </div>
                <div class="form-row">
                    <label for="user_contact">Contact Number</label>
                    <input type="tel" id="user_contact" name="contact_number" placeholder="Optional">
                </div>
                <div class="form-row">
                    <label for="user_email">Email</label>
                    <input type="email" id="user_email" name="email" required>
                </div>
                <div class="form-row">
                    <label for="user_password">Password</label>
                    <input type="password" id="user_password" name="password" required>
                </div>
                <div class="form-row">
                    <label for="user_password_confirm">Confirm Password</label>
                    <input type="password" id="user_password_confirm" name="confirm_password" required>
                </div>
                <div class="form-row">
                    <label for="user_role">Role</label>
                    <select id="user_role" name="role" required>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="primary-action">
                        <i class="fas fa-save"></i> Save User
                    </button>
                    <button type="button" class="secondary-action" id="cancelAddUser">Cancel</button>
                </div>
            </form>
            <p class="form-hint">Email and password can be assigned later by editing the user profile.</p>
        </section>

        <section class="card user-list">
            <h3><i class="fas fa-users"></i> Registered Users</h3>
            <div class="table-wrapper">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Contact Number</th>
                            <th>Role</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo (int) $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($user['contact_number'] ?? '—'); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo htmlspecialchars($user['role']); ?>">
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y H:i', strtotime($user['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="empty-row">No users found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        </div>
    </main>

    <script>
        function toggleDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        }

        document.addEventListener('click', function (event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');
            if (userMenu && dropdown && !userMenu.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        document.addEventListener('click', function (event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-toggle');
            if (window.innerWidth <= 768 && sidebar && toggle &&
                !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('toggleAddUser');
            const cancelBtn = document.getElementById('cancelAddUser');
            const section = document.getElementById('addUserSection');
            const backButton = document.getElementById('backButton');

            function toggleSection() {
                section.classList.toggle('hidden');
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', toggleSection);
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', toggleSection);
            }

            if (backButton) {
                backButton.addEventListener('click', function () {
                    window.history.back();
                });
            }
        });
    </script>
</body>

</html>
