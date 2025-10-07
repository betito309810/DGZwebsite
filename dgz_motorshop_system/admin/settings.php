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
    $stmt = $pdo->prepare('SELECT name, role, created_at FROM users WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();
} catch (Exception $e) {
    error_log('User lookup failed: ' . $e->getMessage());
}

if (!$current_user) {
    logoutDeactivatedUser('Your account is no longer active.');
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
            $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPassword, $user['password'])) {
                $errorMessage = 'Current password is incorrect.';
            } else {
                // Hash new password and update
                $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ? AND deleted_at IS NULL');
                $updateStmt->execute([$hashedNewPassword, $_SESSION['user_id']]);

                if ($updateStmt->rowCount() === 0) {
                    $errorMessage = 'Unable to change password because the account is inactive.';
                } else {
                    $successMessage = 'Password changed successfully.';
                    // Clear form fields after success
                    $_POST = [];
                }
            }
        } catch (Exception $e) {
            error_log('Password update failed: ' . $e->getMessage());
            $errorMessage = 'Failed to change password. Please try again.';
        }
    }
}

$userManagementSuccess = null;
$userManagementError = null;
$userManagementUsers = [];

if ($role === 'admin') {
    require __DIR__ . '/includes/user_management_data.php';
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
    <?php if ($role === 'admin'): ?>
    <link rel="stylesheet" href="../assets/css/users/userManagement.css">
    <?php endif; ?>
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

        <!-- Settings Content -->
        <div class="settings-content">
            <section class="settings-section card">
                <button type="button" class="settings-toggle" data-target="profilePanel" data-default-state="closed" aria-expanded="false">
                    <span class="label">
                        <i class="fas fa-user-circle"></i>
                        Profile
                    </span>
                    <i class="fas fa-chevron-down toggle-icon" aria-hidden="true"></i>
                </button>
                <div class="settings-panel settings-profile-details" id="profilePanel">
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
            </section>

            <section class="settings-section card">
                <button type="button" class="settings-toggle" data-target="passwordPanel" data-default-state="closed" aria-expanded="false">
                    <span class="label">
                        <i class="fas fa-key"></i>
                        Change Password
                    </span>
                    <i class="fas fa-chevron-down toggle-icon" aria-hidden="true"></i>
                </button>
                <div class="settings-panel" id="passwordPanel">
                    <?php if ($successMessage): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
                    <?php endif; ?>
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php endif; ?>
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
                </div>
            </section>

            <?php if ($role === 'admin'): ?>
            <section class="settings-section card">
                <button type="button" class="settings-toggle" data-target="userManagementPanel" data-default-state="closed" aria-expanded="false">
                    <span class="label">
                        <i class="fas fa-users-cog"></i>
                        User Management
                    </span>
                    <i class="fas fa-chevron-down toggle-icon" aria-hidden="true"></i>
                </button>
                <div class="settings-panel" id="userManagementPanel">
                    <?php
                        $showUserManagementBackButton = false;
                        include __DIR__ . '/partials/user_management_section.php';
                    ?>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </main>

    <script src="../assets/js/dashboard/userMenu.js"></script>
    <?php if ($role === 'admin'): ?>
    <script src="../assets/js/users/userManagement.js"></script>
    <?php endif; ?>
    <script src="../assets/js/notifications.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggleButtons = document.querySelectorAll('.settings-toggle');

            toggleButtons.forEach(function (button) {
                const targetId = button.dataset.target;
                const panel = document.getElementById(targetId);

                if (!panel) {
                    return;
                }

                const defaultState = button.dataset.defaultState === 'open';

                const openPanel = function () {
                    panel.classList.add('open');
                    button.setAttribute('aria-expanded', 'true');
                    panel.style.maxHeight = panel.scrollHeight + 'px';

                    const cleanup = function (event) {
                        if (event.propertyName === 'max-height') {
                            panel.style.maxHeight = 'none';
                            panel.removeEventListener('transitionend', cleanup);
                        }
                    };

                    panel.addEventListener('transitionend', cleanup);
                };

                const closePanel = function () {
                    panel.classList.remove('open');
                    button.setAttribute('aria-expanded', 'false');
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                    panel.offsetHeight;
                    panel.style.maxHeight = '0px';
                };

                if (defaultState) {
                    panel.classList.add('open');
                    button.setAttribute('aria-expanded', 'true');
                    panel.style.maxHeight = 'none';
                } else {
                    panel.classList.remove('open');
                    button.setAttribute('aria-expanded', 'false');
                    panel.style.maxHeight = '0px';
                }

                button.addEventListener('click', function () {
                    if (panel.classList.contains('open')) {
                        if (panel.style.maxHeight === 'none') {
                            panel.style.maxHeight = panel.scrollHeight + 'px';
                            panel.offsetHeight;
                        }
                        closePanel();
                    } else {
                        panel.style.maxHeight = '0px';
                        requestAnimationFrame(openPanel);
                    }
                });
            });
        });
    </script>
</body>

</html>
