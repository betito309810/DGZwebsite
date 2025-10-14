<?php
$inventoryNotifications = $inventoryNotifications ?? [];
$inventoryNotificationCount = $inventoryNotificationCount ?? 0;
$notificationManageLink = $notificationManageLink ?? 'inventory.php';

$hasUnreadNotifications = false;
foreach ($inventoryNotifications as $note) {
    if (empty($note['is_read'])) {
        $hasUnreadNotifications = true;
        break;
    }
}
?>
<div class="notif-menu">
    <button
        class="notif-bell"
        id="notifBell"
        aria-label="Notifications"
        aria-haspopup="dialog"
        aria-expanded="false"
    >
        <i class="fas fa-bell"></i>
        <?php if (!empty($inventoryNotificationCount)) : ?>
        <span class="badge" aria-hidden="true"><?= htmlspecialchars($inventoryNotificationCount) ?></span>
        <?php endif; ?>
    </button>
</div>

<div
    class="notif-modal"
    id="notifModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="notifModalTitle"
    aria-hidden="true"
>
    <div class="notif-modal__overlay" data-modal-close></div>
    <div class="notif-modal__dialog">
        <div class="notif-modal__header">
            <div class="notif-modal__title" id="notifModalTitle">
                <i class="fas fa-bell" aria-hidden="true"></i>
                <span>Notifications</span>
            </div>
            <div class="notif-modal__actions">
                <?php if (!empty($inventoryNotifications)) : ?>
                <button
                    type="button"
                    class="notif-mark-all<?= $hasUnreadNotifications ? '' : ' is-disabled' ?>"
                    id="notifMarkAll"
                    <?= $hasUnreadNotifications ? '' : 'disabled' ?>
                    aria-disabled="<?= $hasUnreadNotifications ? 'false' : 'true' ?>"
                    data-loading-label="Marking..."
                    data-empty-label="All caught up"
                >
                    Mark all as read
                </button>
                <?php endif; ?>
                <button type="button" class="notif-modal__close" id="notifModalClose" aria-label="Close notifications">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="notif-modal__body">
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
                    <span class="notif-time"><?= htmlspecialchars($note['time_ago'] ?? '') ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <div class="notif-modal__footer">
            <a href="<?= htmlspecialchars($notificationManageLink) ?>" class="notif-link">
                <i class="fas fa-arrow-right"></i>
                Manage inventory
            </a>
        </div>
    </div>
</div>
