<?php
require __DIR__ . '/../config/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();
$userId = $_SESSION['user_id'];

require_once __DIR__ . '/includes/restock_request_helpers.php';
ensureRestockVariantColumns($pdo);
$collections = fetchRestockRequestCollections($pdo);
$requests = $collections['requests'];
$pendingRequests = $collections['pending'];
$historyEntries = $collections['history'];

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

// Cache the current user's name for audit history rows that remain after deletion.
$currentUserName = $current_user['name'] ?? null;

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

$flashMessage = $_SESSION['restock_flash'] ?? null;
$flashType = $_SESSION['restock_flash_type'] ?? null;
unset($_SESSION['restock_flash'], $_SESSION['restock_flash_type']);

// Handle approval / decline actions (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_action'], $_POST['request_id']) && $role === 'admin') {
    $action = $_POST['request_action'];
    $requestId = (int) $_POST['request_id'];

    if (!in_array($action, ['approve', 'decline'], true) || $requestId <= 0) {
        $_SESSION['restock_flash'] = 'Invalid action submitted.';
        $_SESSION['restock_flash_type'] = 'error';
        header('Location: stockRequests.php');
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status FROM restock_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new RuntimeException('Restock request not found.');
        }

        if ($request['status'] !== 'pending') {
            throw new RuntimeException('Only pending requests can be updated.');
        }

        $newStatus = $action === 'approve' ? 'approved' : 'declined';
        $update = $pdo->prepare('UPDATE restock_requests SET status = ?, reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW() WHERE id = ?');
        $update->execute([$newStatus, $userId, $currentUserName, $requestId]);

        $logStmt = $pdo->prepare('INSERT INTO restock_request_history (request_id, status, noted_by, noted_by_name) VALUES (?, ?, ?, ?)');
        $logStmt->execute([$requestId, $newStatus, $userId, $currentUserName]);

        $pdo->commit();

        $_SESSION['restock_flash'] = "Request successfully {$newStatus}.";
        $_SESSION['restock_flash_type'] = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['restock_flash'] = 'Unable to update request: ' . $e->getMessage();
        $_SESSION['restock_flash_type'] = 'error';
    }

    header('Location: stockRequests.php');
    exit;
}

$stockRequestBadgeCount = count($pendingRequests);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Requests</title>
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/style.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/dashboard/dashboard.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/inventory/stockRequests.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php
        $activePage = 'stockRequests.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>

    <main class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Stock Requests</h2>
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

        <section class="content-wrapper">
            <div class="page-heading">
                <h3><i class="fas fa-clipboard-list"></i> Restock Requests</h3>
                <p class="page-subtitle">Monitor incoming restock requests and track their status.</p>
            </div>

            <?php if ($flashMessage): ?>
                <div class="alert <?php echo $flashType === 'success' ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars($flashMessage); ?>
                </div>
            <?php endif; ?>

            <div class="requests-card">
                <?php if (empty($requests)): ?>
                    <p class="empty-state"><i class="fas fa-inbox"></i> No restock requests found.</p>
                <?php else: ?>
                    <div class="tab-controls">
                        <button type="button" class="tab-btn active" data-target="pending-tab">
                            <i class="fas fa-hourglass-half"></i> Pending Requests
                        </button>
                        <button type="button" class="tab-btn" data-target="status-tab">
                            <i class="fas fa-clipboard-check"></i> Status History
                        </button>
                    </div>

                    <div id="pending-tab" class="tab-panel active">
                        <p
                            class="empty-state"
                            data-restock-pending-empty
                            <?php echo empty($pendingRequests) ? '' : 'style="display:none;"'; ?>
                        >
                            <i class="fas fa-check-circle"></i> No pending restock requests.
                        </p>
                        <div
                            class="table-wrapper"
                            data-restock-pending-table
                            <?php echo empty($pendingRequests) ? 'style="display:none;"' : ''; ?>
                        >
                            <table class="requests-table">
                                <thead>
                                    <tr>
                                        <th>Submitted</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Brand</th>
                                        <th>Supplier</th>
                                        <th>Quantity</th>
                                        <th>Priority</th>
                                        <th>Requested By</th>
                                        <th>Notes</th>
                                        <?php if ($role === 'admin'): ?>
                                            <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody data-restock-pending-body>
                                    <?= renderRestockRequestRows($pendingRequests, $role); ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div id="status-tab" class="tab-panel">
                        <p
                            class="empty-state"
                            data-restock-history-empty
                            <?php echo empty($historyEntries) ? '' : 'style="display:none;"'; ?>
                        >
                            <i class="fas fa-inbox"></i> No request history logged yet.
                        </p>
                        <div
                            class="table-wrapper"
                            data-restock-history-table
                            <?php echo empty($historyEntries) ? 'style="display:none;"' : ''; ?>
                        >
                            <table class="requests-table">
                                <thead>
                                    <tr>
                                        <th>Logged At</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Brand</th>
                                        <th>Supplier</th>
                                        <th>Quantity</th>
                                        <th>Priority</th>
                                        <th>Requested By</th>
                                        <th>Status</th>
                                        <th>Logged By</th>
                                        <th>Approved / Declined By</th>
                                    </tr>
                                </thead>
                                <tbody data-restock-history-body>
                                    <?= renderRestockHistoryRows($historyEntries); ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
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
        <!-- User menu -->
        <script src="../dgz_motorshop_system/assets/js/dashboard/userMenu.js"></script>

    <!-- Tab controls -->
    <script src="../dgz_motorshop_system/assets/js/inventory/stockRequest.js"></script>
    <script src="../dgz_motorshop_system/assets/js/notifications.js"></script>
</body>
</html>
