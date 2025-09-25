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

require_once __DIR__ . '/includes/inventory_notifications.php';
$notificationManageLink = 'inventory.php';
$inventoryNotificationData = loadInventoryNotifications($pdo);
$inventoryNotifications = $inventoryNotificationData['notifications'];
$inventoryNotificationCount = $inventoryNotificationData['active_count'];

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
        $update = $pdo->prepare('UPDATE restock_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
        $update->execute([$newStatus, $userId, $requestId]);

        $logStmt = $pdo->prepare('INSERT INTO restock_request_history (request_id, status, noted_by) VALUES (?, ?, ?)');
        $logStmt->execute([$requestId, $newStatus, $userId]);

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

// Fetch restock requests with product and user details
$stmt = $pdo->query('
    SELECT rr.*, p.name AS product_name, p.code AS product_code,
           requester.name AS requester_name,
           reviewer.name AS reviewer_name
    FROM restock_requests rr
    LEFT JOIN products p ON p.id = rr.product_id
    LEFT JOIN users requester ON requester.id = rr.requested_by
    LEFT JOIN users reviewer ON reviewer.id = rr.reviewed_by
    ORDER BY rr.created_at DESC
');
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pendingRequests = array_values(array_filter($requests, function ($row) {
    return strtolower($row['status'] ?? 'pending') === 'pending';
}));
$resolvedRequests = array_values(array_filter($requests, function ($row) {
    return strtolower($row['status'] ?? 'pending') !== 'pending';
}));

$historyEntries = $pdo->query('
    SELECT h.*, rr.quantity_requested AS request_quantity,
           rr.priority_level AS request_priority,
           rr.needed_by AS request_needed_by,
           rr.category AS request_category,
           rr.brand AS request_brand,
           rr.supplier AS request_supplier,
           p.name AS product_name, p.code AS product_code,
           requester.name AS requester_name,
           status_user.name AS status_user_name,
           reviewer.name AS reviewer_name
    FROM restock_request_history h
    JOIN restock_requests rr ON rr.id = h.request_id
    LEFT JOIN products p ON p.id = rr.product_id
    LEFT JOIN users requester ON requester.id = rr.requested_by
    LEFT JOIN users status_user ON status_user.id = h.noted_by
    LEFT JOIN users reviewer ON reviewer.id = rr.reviewed_by
    ORDER BY h.created_at DESC
')->fetchAll(PDO::FETCH_ASSOC);

// Helper to format priority badge classes
function getPriorityClass(string $priority): string
{
    switch ($priority) {
        case 'high':
            return 'badge-high';
        case 'medium':
            return 'badge-medium';
        default:
            return 'badge-low';
    }
}

function getStatusClass(string $status): string
{
    switch ($status) {
        case 'approved':
            return 'status-approved';
        case 'denied':
        case 'declined':
            return 'status-denied';
        case 'fulfilled':
            return 'status-fulfilled';
        default:
            return 'status-pending';
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Requests</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory/stockRequests.css">
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
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user-cog"></i> Profile
                        </a>
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
                        <?php if (empty($pendingRequests)): ?>
                            <p class="empty-state"><i class="fas fa-check-circle"></i> No pending restock requests.</p>
                        <?php else: ?>
                            <div class="table-wrapper">
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
                                            <th>Needed By</th>
                                            <th>Requested By</th>
                                            <th>Notes</th>
                                            <?php if ($role === 'admin'): ?>
                                                <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingRequests as $request): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y H:i', strtotime($request['created_at'])); ?></td>
                                                <td>
                                                    <div class="product-cell">
                                                        <span class="product-name"><?php echo htmlspecialchars($request['product_name'] ?? 'Product removed'); ?></span>
                                                        <?php if (!empty($request['product_code'])): ?>
                                                            <span class="product-code">Code: <?php echo htmlspecialchars($request['product_code']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($request['category'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($request['brand'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($request['supplier'] ?? ''); ?></td>
                                                <td><?php echo (int) $request['quantity_requested']; ?></td>
                                                <td>
                                                    <?php $priority = strtolower($request['priority_level'] ?? ''); ?>
                                                    <span class="priority-badge <?php echo getPriorityClass($priority); ?>"><?php echo ucfirst($priority); ?></span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($request['needed_by'])): ?>
                                                        <?php echo date('M d, Y', strtotime($request['needed_by'])); ?>
                                                    <?php else: ?>
                                                        <span class="muted">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($request['requester_name'] ?? 'Unknown'); ?></td>
                                                <td class="notes-cell">
                                                    <?php if (!empty($request['notes'])): ?>
                                                        <?php echo nl2br(htmlspecialchars($request['notes'])); ?>
                                                    <?php else: ?>
                                                        <span class="muted">No notes</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($role === 'admin'): ?>
                                                    <td class="action-cell">
                                                        <form method="post" class="inline-form">
                                                            <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                                            <input type="hidden" name="request_action" value="approve">
                                                            <button type="submit" class="btn-approve">
                                                                <i class="fas fa-check"></i> Approve
                                                            </button>
                                                        </form>
                                                        <form method="post" class="inline-form">
                                                            <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                                            <input type="hidden" name="request_action" value="decline">
                                                            <button type="submit" class="btn-decline">
                                                                <i class="fas fa-times"></i> Decline
                                                            </button>
                                                        </form>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div id="status-tab" class="tab-panel">
                        <?php if (empty($historyEntries)): ?>
                            <p class="empty-state"><i class="fas fa-inbox"></i> No request history logged yet.</p>
                        <?php else: ?>
                            <div class="table-wrapper">
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
                                            <th>Needed By</th>
                                            <th>Requested By</th>
                                            <th>Status</th>
                                            <th>Logged By</th>
                                            <th>Approved / Declined By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historyEntries as $entry): ?>
                                            <?php $status = strtolower($entry['status'] ?? 'pending'); ?>
                                            <tr>
                                                <td><?php echo date('M d, Y H:i', strtotime($entry['created_at'])); ?></td>
                                                <td>
                                                    <div class="product-cell">
                                                        <span class="product-name"><?php echo htmlspecialchars($entry['product_name'] ?? 'Product removed'); ?></span>
                                                        <?php if (!empty($entry['product_code'])): ?>
                                                            <span class="product-code">Code: <?php echo htmlspecialchars($entry['product_code']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($entry['request_category'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($entry['request_brand'] ?? ''); ?></td>
                                                <td><?php echo htmlspecialchars($entry['request_supplier'] ?? ''); ?></td>
                                                <td><?php echo (int) $entry['request_quantity']; ?></td>
                                                <td>
                                                    <?php $priority = strtolower($entry['request_priority'] ?? ''); ?>
                                                    <span class="priority-badge <?php echo getPriorityClass($priority); ?>"><?php echo ucfirst($priority); ?></span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($entry['request_needed_by'])): ?>
                                                        <?php echo date('M d, Y', strtotime($entry['request_needed_by'])); ?>
                                                    <?php else: ?>
                                                        <span class="muted">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($entry['requester_name'] ?? 'Unknown'); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo getStatusClass($status); ?>"><?php echo ucfirst($status); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($entry['status_user_name'] ?? 'System'); ?></td>
                                                <td><?php echo htmlspecialchars($entry['reviewer_name'] ?? ($entry['status'] === 'pending' ? '—' : 'Unknown')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
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
            if (!userMenu.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        document.addEventListener('click', function (event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-toggle');
            if (window.innerWidth <= 768 &&
                !sidebar.contains(event.target) &&
                !toggle.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 4000);
            });

            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabPanels = document.querySelectorAll('.tab-panel');

            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const targetId = button.getAttribute('data-target');

                    tabButtons.forEach(btn => btn.classList.toggle('active', btn === button));
                    tabPanels.forEach(panel => {
                        panel.classList.toggle('active', panel.id === targetId);
                    });
                });
            });
        });
    </script>
    <script src="../assets/js/notifications.js"></script>
</body>
</html>
