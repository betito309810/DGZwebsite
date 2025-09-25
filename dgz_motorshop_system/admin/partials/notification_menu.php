<?php
$inventoryNotifications = $inventoryNotifications ?? [];
$inventoryNotificationCount = $inventoryNotificationCount ?? 0;
$notificationManageLink = $notificationManageLink ?? 'inventory.php';
?>
<div class="notif-menu">
    <button class="notif-bell" id="notifBell" aria-label="Notifications">
        <i class="fas fa-bell"></i>
        <?php if (!empty($inventoryNotificationCount)) : ?>
        <span class="badge"><?= htmlspecialchars($inventoryNotificationCount) ?></span>
        <?php endif; ?>
    </button>

    <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-head">
            <i class="fas fa-bell" aria-hidden="true"></i>
            Notifications
        </div>
        <?php if (empty($inventoryNotifications)) : ?>
        <div class="notif-empty">
            <i class="fas fa-check-circle" aria-hidden="true"></i>
            <p>No notifications yet.</p>
        </div>
        <?php else : ?>
        <ul class="notif-list">
            <?php foreach ($inventoryNotifications as $note) : ?>
            <li class="notif-item <?= $note['status'] === 'resolved' ? 'resolved' : ($note['is_read'] ? 'active read' : 'active unread') ?>">
                <div class="notif-row">
                    <span class="notif-title">
                        <?= htmlspecialchars($note['title']) ?>
                    </span>
                    <?php if ($note['status'] === 'resolved') : ?>
                    <span class="notif-status">Resolved</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($note['message'])) : ?>
                <p class="notif-message"><?= htmlspecialchars($note['message']) ?></p>
                <?php endif; ?>
                <?php if (!empty($note['product_name'])) : ?>
                <span class="notif-product"><?= htmlspecialchars($note['product_name']) ?></span>
                <?php endif; ?>
                <span class="notif-time"><?= htmlspecialchars(format_time_ago($note['created_at'])) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="notif-footer">
            <a href="<?= htmlspecialchars($notificationManageLink) ?>" class="notif-link">
                <i class="fas fa-arrow-right"></i>
                Manage inventory
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
