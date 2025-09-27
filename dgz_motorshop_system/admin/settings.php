<?php
require __DIR__ . '/../config/config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $pdo = db();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

$role = $_SESSION['role'] ?? '';
enforceStaffAccess();

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

$successMessage = null;
$errorMessage = null;

// Handle password change form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmNewPassword = $_POST['confirm_new_password'] ?? '';

    // Validation
    if (empty($currentPassword)) {
        $errorMessage = 'Current password is required.';
    } elseif (empty($newPassword)) {
        $errorMessage = 'New password is required.';
    } elseif (strlen($newPassword) < 8) {
        $errorMessage = 'New password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmNewPassword) {
        $errorMessage = 'New password and confirmation do not match.';
    } else {
        try {
            // Fetch current hashed password
            $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPassword, $user['password'])) {
                $errorMessage = 'Current password is incorrect.';
            } else {
                // Hash new password and update
                $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $updateStmt->execute([$hashedNewPassword, $_SESSION['user_id']]);
                $successMessage = 'Password changed successfully.';
                // Clear form fields after success
                $_POST = [];
            }
        } catch (Exception $e) {
            error_log('Password update failed: ' . $e->getMessage());
            $errorMessage = 'Failed to change password. Please try again.';
        }
    }
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard/dashboard.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <!-- Sidebar -->
    <?php
        $activePage = 'settings.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <!-- Notification Bell and User Menu -->
        <header class="header">

            <!-- Avatar and Dropdown -->
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Settings</h2>
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

        <!-- Settings Content -->
        <div class="settings-content">
            <?php if ($successMessage): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if ($errorMessage): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Change Password Section -->
            <section class="card settings-card">
                <h3><i class="fas fa-key"></i> Change Password</h3>
                <form method="post" class="password-form">
                    <input type="hidden" name="change_password" value="1">
                    <div class="form-row">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="form-row">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="form-row">
                        <label for="confirm_new_password">Confirm New Password</label>
                        <input type="password" id="confirm_new_password" name="confirm_new_password" required autocomplete="new-password">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="primary-action">
                            <i class="fas fa-save"></i> Change Password
                        </button>
                    </div>
                </form>
                <p class="form-hint">Enter your current password for verification. New password must be at least 8 characters long.</p>
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

    <script src="../assets/js/notifications.js"></script>
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

        document.addEventListener('DOMContentLoaded', function() {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');
            const profileButton = document.getElementById('profileTrigger');
            const profileModal = document.getElementById('profileModal');
            const profileModalClose = document.getElementById('profileModalClose');

            document.addEventListener('click', function(event) {
                if (userMenu && dropdown && !userMenu.contains(event.target)) {
                    dropdown.classList.remove('show');
                }

                const sidebar = document.getElementById('sidebar');
                const toggle = document.querySelector('.mobile-toggle');

                if (window.innerWidth <= 768 &&
                    sidebar && toggle &&
                    !sidebar.contains(event.target) &&
                    !toggle.contains(event.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            });

            if (profileButton && profileModal) {
                const openProfileModal = function() {
                    profileModal.classList.add('show');
                    profileModal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('modal-open');
                };

                const closeProfileModal = function() {
                    profileModal.classList.remove('show');
                    profileModal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('modal-open');
                };

                profileButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    if (dropdown) {
                        dropdown.classList.remove('show');
                    }
                    openProfileModal();
                });

                if (profileModalClose) {
                    profileModalClose.addEventListener('click', function() {
                        closeProfileModal();
                    });
                }

                profileModal.addEventListener('click', function(event) {
                    if (event.target === profileModal) {
                        closeProfileModal();
                    }
                });

                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape' && profileModal.classList.contains('show')) {
                        closeProfileModal();
                    }
                });
            }
        });
    </script>
