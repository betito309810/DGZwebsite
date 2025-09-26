<?php
require __DIR__ . '/../config/config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();

if ($role !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $userId = filter_input(INPUT_POST, 'delete_user_id', FILTER_VALIDATE_INT);

    if (!$userId) {
        $errorMessage = 'Invalid user selection.';
    } elseif ((int) $_SESSION['user_id'] === $userId) {
        $errorMessage = 'You cannot delete your own account.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $userToDelete = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userToDelete) {
                $pdo->rollBack();
                $errorMessage = 'The selected user no longer exists.';
            } elseif ($userToDelete['role'] !== 'staff') {
                $pdo->rollBack();
                $errorMessage = 'Only staff accounts can be removed.';
            } else {
                $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $deleteStmt->execute([$userId]);
                $pdo->commit();
                $successMessage = 'Staff account removed successfully.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = 'Failed to remove staff account: ' . $e->getMessage();
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
    <?php
        $activePage = 'userManagement.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>

    <main class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>User Management</h2>
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
                        <a href="login.php?logout=1" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
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
                            <th>Actions</th>
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
                                <td class="table-actions">
                                    <?php if ($user['role'] === 'staff'): ?>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Remove this staff account? This action cannot be undone.');">
                                            <input type="hidden" name="delete_user" value="1">
                                            <input type="hidden" name="delete_user_id" value="<?php echo (int) $user['id']; ?>">
                                            <button type="submit" class="danger-action">
                                                <i class="fas fa-user-minus"></i> Remove
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
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
        function toggleDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

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

        document.addEventListener('click', function (event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');
            if (userMenu && dropdown && !userMenu.contains(event.target)) {
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
    <script src="../assets/js/notifications.js"></script>
</body>

</html>
