<?php
require __DIR__ . '/../config/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();
$notificationManageLink = 'inventory.php';

require_once __DIR__ . '/includes/inventory_notifications.php';
require_once __DIR__ . '/includes/stock_receipt_helpers.php';

$inventoryNotificationData = loadInventoryNotifications($pdo);
$inventoryNotifications = $inventoryNotificationData['notifications'];
$inventoryNotificationCount = $inventoryNotificationData['active_count'];

$currentUser = null;
try {
    $stmt = $pdo->prepare('SELECT id, name, role, created_at FROM users WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('User lookup failed: ' . $e->getMessage());
}

if (!$currentUser) {
    logoutDeactivatedUser('Your account is no longer active.');
}

$receiptId = isset($_GET['receipt']) ? (int)$_GET['receipt'] : 0;
$receiptCodeParam = isset($_GET['code']) ? trim((string)$_GET['code']) : '';

$receipt = null;
if ($receiptId > 0) {
    $receipt = loadStockReceiptWithItems($pdo, $receiptId);
} elseif ($receiptCodeParam !== '') {
    $receipt = loadStockReceiptByCode($pdo, $receiptCodeParam);
}

if (!$receipt) {
    http_response_code(404);
    $errorMessage = 'Stock-in document not found.';
}

$header = $receipt['header'] ?? [];
$items = $receipt['items'] ?? [];
$attachments = $receipt['attachments'] ?? [];
$auditLog = $receipt['audit'] ?? [];

$statusLabel = isset($header['status']) ? formatStockReceiptStatus($header['status']) : 'Unknown';
$totalItems = count($items);
$totalQtyReceived = array_reduce($items, fn($carry, $item) => $carry + (int)($item['qty_received'] ?? 0), 0);

$profile_name = $currentUser['name'] ?? 'N/A';
$profile_role = !empty($currentUser['role']) ? ucfirst($currentUser['role']) : 'N/A';
$profile_created = isset($currentUser['created_at']) ? formatStockReceiptDateTime($currentUser['created_at']) : 'N/A';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock-In Details - DGZ</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory/stockEntry.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php
        $activePage = 'inventory.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>
    <main class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Stock-In Details</h2>
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

        <div class="page-actions">
            <a href="stockEntry.php" class="btn-action back-btn">Back to Stock-In</a>
            <?php if ($receipt && $header['status'] === 'draft'): ?>
                <a href="stockEntry.php?receipt=<?= (int)$header['id'] ?>&mode=edit" class="btn-action btn-primary">Edit Draft</a>
            <?php endif; ?>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($_GET['posted'])): ?>
                <div class="alert alert-success">Stock-in document posted and inventory updated.</div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <?php if ($receipt): ?>
            <section class="panel" aria-labelledby="receiptSummaryTitle">
                <div class="panel-header">
                    <div>
                        <h3 id="receiptSummaryTitle">Receipt Summary</h3>
                        <p class="panel-subtitle">
                            Reference: <?= htmlspecialchars($header['receipt_code'] ?? '') ?>
                            <span class="panel-subtext">Status: <?= htmlspecialchars($statusLabel) ?></span>
                        </p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Supplier</label>
                        <div><?= htmlspecialchars($header['supplier_name'] ?? 'N/A') ?></div>
                    </div>
                    <div class="form-group">
                        <label>DR / Invoice No.</label>
                        <div><?= htmlspecialchars($header['document_number'] ?? 'N/A') ?></div>
                    </div>
                    <div class="form-group">
                        <label>Date Received</label>
                        <div><?= htmlspecialchars($header['date_received'] ?? 'N/A') ?></div>
                    </div>
                    <div class="form-group">
                        <label>Received By</label>
                        <div><?= htmlspecialchars($receipt['header']['received_by_name'] ?? 'Pending') ?></div>
                    </div>
                    <div class="form-group">
                        <label>Related To</label>
                        <div><?= htmlspecialchars($header['related_reference'] ?? '—') ?></div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <div><?= !empty($header['notes']) ? nl2br(htmlspecialchars($header['notes'])) : '—' ?></div>
                    </div>
                    <div class="form-group">
                        <label>Discrepancy Note</label>
                        <div><?= !empty($header['discrepancy_note']) ? nl2br(htmlspecialchars($header['discrepancy_note'])) : '—' ?></div>
                    </div>
                </div>
                <div class="form-grid" style="margin-top: 12px;">
                    <div class="form-group">
                        <label>Created By</label>
                        <div><?= htmlspecialchars($header['created_by_name'] ?? 'System') ?> · <?= htmlspecialchars(formatStockReceiptDateTime($header['created_at'] ?? null)) ?></div>
                    </div>
                    <div class="form-group">
                        <label>Last Updated By</label>
                        <div><?= htmlspecialchars($header['updated_by_name'] ?? 'System') ?> · <?= htmlspecialchars(formatStockReceiptDateTime($header['updated_at'] ?? null)) ?></div>
                    </div>
                    <div class="form-group">
                        <label>Posted At</label>
                        <div><?= htmlspecialchars(formatStockReceiptDateTime($header['posted_at'] ?? null) ?: '—') ?></div>
                    </div>
                </div>
            </section>

            <section class="panel" aria-labelledby="lineItemsTitle">
                <div class="panel-header">
                    <div>
                        <h3 id="lineItemsTitle">Line Items</h3>
                        <p class="panel-subtitle">Items: <?= (int)$totalItems ?> · Total Qty Received: <?= (int)$totalQtyReceived ?></p>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="line-items-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Expected</th>
                                <th>Received</th>
                                <th>Unit Cost</th>
                                <th>Expiry</th>
                                <th>Lot / Batch</th>
                                <th>Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $index => $item): ?>
                                <?php
                                    $difference = null;
                                    if ($item['qty_expected'] !== null) {
                                        $difference = (int)$item['qty_received'] - (int)$item['qty_expected'];
                                    }
                                ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <?= htmlspecialchars($item['product_name'] ?? 'Deleted product') ?>
                                        <?php if (!empty($item['product_code'])): ?>
                                            <span class="attachment-meta">Code: <?= htmlspecialchars($item['product_code']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $item['qty_expected'] !== null ? (int)$item['qty_expected'] : '—' ?></td>
                                    <td><?= (int)$item['qty_received'] ?></td>
                                    <td><?= $item['unit_cost'] !== null ? number_format((float)$item['unit_cost'], 2) : '—' ?></td>
                                    <td><?= !empty($item['expiry_date']) ? htmlspecialchars($item['expiry_date']) : '—' ?></td>
                                    <td><?= !empty($item['lot_number']) ? htmlspecialchars($item['lot_number']) : '—' ?></td>
                                    <td><?= $difference !== null ? ($difference === 0 ? 'Match' : $difference) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel" aria-labelledby="attachmentsTitle">
                <div class="panel-header">
                    <div>
                        <h3 id="attachmentsTitle">Attachments</h3>
                        <p class="panel-subtitle">Uploaded proof of delivery or invoice.</p>
                    </div>
                </div>
                <?php if (!empty($attachments)): ?>
                    <ul class="attachment-list">
                        <?php foreach ($attachments as $file): ?>
                            <li class="attachment-list-item">
                                <i class="fas fa-paperclip"></i>
                                <a href="../<?= htmlspecialchars($file['file_path']) ?>" target="_blank" rel="noopener">
                                    <?= htmlspecialchars($file['original_name']) ?>
                                </a>
                                <span class="attachment-meta">Uploaded <?= htmlspecialchars(formatStockReceiptDateTime($file['created_at'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="empty-state">No attachments uploaded.</p>
                <?php endif; ?>
            </section>

            <section class="panel" aria-labelledby="auditLogTitle">
                <div class="panel-header">
                    <div>
                        <h3 id="auditLogTitle">Audit Trail</h3>
                        <p class="panel-subtitle">All actions related to this stock-in document.</p>
                    </div>
                </div>
                <?php if (!empty($auditLog)): ?>
                    <div class="table-wrapper">
                        <table class="line-items-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Action</th>
                                    <th>User</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditLog as $log): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(formatStockReceiptDateTime($log['action_at'] ?? null)) ?></td>
                                        <td><?= htmlspecialchars(ucfirst($log['action'])) ?></td>
                                        <td><?= htmlspecialchars($log['action_by_name'] ?? 'System') ?></td>
                                        <td><?= !empty($log['details']) ? htmlspecialchars($log['details']) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty-state">No audit entries recorded yet.</p>
                <?php endif; ?>
            </section>
            <?php endif; ?>
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
</body>
</html>
