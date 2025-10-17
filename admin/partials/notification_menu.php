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

    <div
        class="notif-dropdown"
        id="notifDropdown"
        role="dialog"
        aria-labelledby="notifDropdownTitle"
        aria-hidden="true"
    >
        <div class="notif-dropdown__header">
            <div class="notif-dropdown__title" id="notifDropdownTitle">
                <i class="fas fa-bell" aria-hidden="true"></i>
                <span>Notifications</span>
            </div>
            <button type="button" class="notif-dropdown__close" id="notifDropdownClose" aria-label="Close notifications">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <div class="notif-dropdown__body">
            <?php if (empty($inventoryNotifications)) : ?>
            <div class="notif-empty">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
                <p>No notifications yet.</p>
            </div>
            <?php else : ?>
            <ul class="notif-list">
                <?php foreach ($inventoryNotifications as $note) : ?>
                <?php
                    $noteTitle = htmlspecialchars($note['title'] ?? '', ENT_QUOTES, 'UTF-8');
                    $noteMessage = htmlspecialchars($note['message'] ?? '', ENT_QUOTES, 'UTF-8');
                    $noteProduct = htmlspecialchars($note['product_name'] ?? '', ENT_QUOTES, 'UTF-8');
                    $noteTime = htmlspecialchars($note['time_ago'] ?? '', ENT_QUOTES, 'UTF-8');
                    $noteStatus = htmlspecialchars($note['status'] ?? '', ENT_QUOTES, 'UTF-8');
                ?>
                <li
                    class="notif-item <?= $note['status'] === 'resolved' ? 'resolved' : ($note['is_read'] ? 'active read' : 'active unread') ?>"
                    data-note-id="<?= (int) ($note['id'] ?? 0) ?>"
                    data-note-title="<?= $noteTitle ?>"
                    data-note-message="<?= $noteMessage ?>"
                    data-note-product="<?= $noteProduct ?>"
                    data-note-time="<?= $noteTime ?>"
                    data-note-status="<?= $noteStatus ?>"
                    role="button"
                    tabindex="0"
                    aria-haspopup="dialog"
                    aria-label="View notification: <?= $noteTitle ?>"
                >
                    <div class="notif-row">
                        <span class="notif-title">
                            <?= $noteTitle ?>
                        </span>
                        <?php if ($note['status'] === 'resolved') : ?>
                        <span class="notif-status">Resolved</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($note['message'])) : ?>
                    <p class="notif-message"><?= $noteMessage ?></p>
                    <?php endif; ?>
                    <?php if (!empty($note['product_name'])) : ?>
                    <span class="notif-product"><?= $noteProduct ?></span>
                    <?php endif; ?>
                    <span class="notif-time"><?= $noteTime ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <div class="notif-dropdown__footer">
            <a href="<?= htmlspecialchars($notificationManageLink, ENT_QUOTES, 'UTF-8') ?>" class="notif-link">
                <i class="fas fa-arrow-right"></i>
                Manage inventory
            </a>
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
        </div>
    </div>
</div>

<div
    class="notif-detail-modal"
    id="notifDetailModal"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-labelledby="notifDetailTitle"
>
    <div class="notif-detail-modal__overlay" data-detail-close></div>
    <div class="notif-detail-modal__dialog">
        <button type="button" class="notif-detail-modal__close" id="notifDetailClose" aria-label="Close notification details">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
        <div class="notif-detail-modal__content">
            <div class="notif-detail-modal__icon">
                <i class="fas fa-bell" aria-hidden="true"></i>
            </div>
            <div class="notif-detail-modal__header">
                <h2 id="notifDetailTitle">Notification</h2>
                <span class="notif-detail-status" id="notifDetailStatus"></span>
                <span class="notif-detail-time" id="notifDetailTime"></span>
            </div>
            <div class="notif-detail-message" id="notifDetailMessage"></div>
            <div class="notif-detail-product" id="notifDetailProduct"></div>
        </div>
    </div>
</div>

<script src="../dgz_motorshop_system/assets/js/admin/sidebarCountsLive.js" defer></script>
