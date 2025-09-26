<?php
// Shared inventory notification helpers

if (!function_exists('loadInventoryNotifications')) {
    /**
     * Ensure the notification table and columns exist.
     */
    function ensureInventoryNotificationSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('active','resolved') DEFAULT 'active',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            quantity_at_event INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL DEFAULT NULL,
            CONSTRAINT fk_inventory_notifications_product
                FOREIGN KEY (product_id) REFERENCES products(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columnCheck = $pdo->query("SHOW COLUMNS FROM inventory_notifications LIKE 'is_read'");
        if ($columnCheck && !$columnCheck->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE inventory_notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        }

        $tableSql = $pdo->query('SHOW CREATE TABLE inventory_notifications');
        if ($tableSql) {
            $definitionRow = $tableSql->fetch(PDO::FETCH_ASSOC);
            $definition = $definitionRow && isset($definitionRow['Create Table'])
                ? strtolower((string) $definitionRow['Create Table'])
                : '';

            if ($definition && preg_match('/`created_at`\s+([^,]+)/', $definition, $match)) {
                $createdClause = $match[1];
                $hasOnUpdate = strpos($createdClause, 'on update') !== false;
                $isTimestamp = strpos($createdClause, 'timestamp') !== false && strpos($createdClause, 'datetime') === false;

                if ($hasOnUpdate || $isTimestamp) {
                    // Fix: keep notification timestamps stable so "time ago" stops resetting after marking as read.
                    $pdo->exec("ALTER TABLE inventory_notifications MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                }
            }
        }

        $resolvedColumn = $pdo->query("SHOW COLUMNS FROM inventory_notifications LIKE 'resolved_at'");
        if ($resolvedColumn) {
            $resolvedInfo = $resolvedColumn->fetch(PDO::FETCH_ASSOC);
            if ($resolvedInfo && stripos((string) ($resolvedInfo['Type'] ?? ''), 'timestamp') !== false) {
                $pdo->exec("ALTER TABLE inventory_notifications MODIFY resolved_at DATETIME NULL DEFAULT NULL");
            }
        }
    }

    /**
     * Insert new low stock notifications and resolve restocked ones.
     */
    function refreshInventoryNotifications(PDO $pdo): void
    {
        $lowStock = $pdo->query("SELECT id, name, quantity, low_stock_threshold FROM products WHERE quantity <= low_stock_threshold")
                         ->fetchAll(PDO::FETCH_ASSOC);

        $checkStmt = $pdo->prepare("SELECT id FROM inventory_notifications WHERE product_id = ? AND status = 'active' LIMIT 1");
        $createStmt = $pdo->prepare('INSERT INTO inventory_notifications (product_id, title, message, quantity_at_event, is_read) VALUES (?, ?, ?, ?, 0)');

        foreach ($lowStock as $item) {
            $checkStmt->execute([$item['id']]);
            if (!$checkStmt->fetchColumn()) {
                $title = $item['name'] . ' is low on stock';
                $message = 'Only ' . intval($item['quantity']) . ' left (minimum ' . intval($item['low_stock_threshold']) . ').';
                $createStmt->execute([
                    $item['id'],
                    $title,
                    $message,
                    intval($item['quantity'])
                ]);
            }
        }

        $activeRecords = $pdo->query("SELECT n.id, p.quantity, p.low_stock_threshold FROM inventory_notifications n LEFT JOIN products p ON p.id = n.product_id WHERE n.status = 'active'")
                              ->fetchAll(PDO::FETCH_ASSOC);
        $resolveStmt = $pdo->prepare("UPDATE inventory_notifications SET status = 'resolved', resolved_at = IF(resolved_at IS NULL, NOW(), resolved_at) WHERE id = ? AND status = 'active'");

        foreach ($activeRecords as $record) {
            $quantity = isset($record['quantity']) ? (int) $record['quantity'] : null;
            $threshold = isset($record['low_stock_threshold']) ? (int) $record['low_stock_threshold'] : null;

            if ($quantity === null || ($threshold !== null && $quantity > $threshold)) {
                $resolveStmt->execute([$record['id']]);
            }
        }
    }

    /**
     * Fetch notifications and active count for display.
     */
    function loadInventoryNotifications(PDO $pdo): array
    {
        ensureInventoryNotificationSchema($pdo);
        refreshInventoryNotifications($pdo);

        $notifications = $pdo->query("SELECT n.*, p.name AS product_name FROM inventory_notifications n LEFT JOIN products p ON p.id = n.product_id ORDER BY n.created_at DESC LIMIT 10")
                             ->fetchAll(PDO::FETCH_ASSOC);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM inventory_notifications WHERE status = 'active' AND is_read = 0")
                           ->fetchColumn();

        return [
            'notifications' => $notifications,
            'active_count' => $count,
        ];
    }

    if (!function_exists('format_time_ago')) {
        /**
         * Helper: present notification timestamps as human friendly "time ago" text.
         */
        function format_time_ago(?string $datetime): string
        {
            if (!$datetime) {
                return '';
            }

            try {
                $timezone = new DateTimeZone(date_default_timezone_get());
                $now = new DateTimeImmutable('now', $timezone);
                $then = new DateTimeImmutable($datetime, $timezone);
            } catch (Exception $exception) {
                return '';
            }

            $diffSeconds = abs($now->getTimestamp() - $then->getTimestamp());

            if ($diffSeconds < 60) {
                return 'Just now';
            }

            $minutes = floor($diffSeconds / 60);
            if ($minutes < 60) {
                return $minutes === 1 ? '1 minute ago' : $minutes . ' minutes ago';
            }

            $hours = floor($diffSeconds / 3600);
            if ($hours < 24) {
                return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
            }

            $days = floor($diffSeconds / 86400);
            if ($days < 7) {
                return $days === 1 ? '1 day ago' : $days . ' days ago';
            }

            $weeks = floor($diffSeconds / 604800);
            if ($weeks < 4) {
                return $weeks === 1 ? '1 week ago' : $weeks . ' weeks ago';
            }

            return $then->format('M j, Y');
        }
    }
}
