<?php
// Shared inventory notification helpers

if (!function_exists('loadInventoryNotifications')) {
    /**
     * Ensure the inventory notification table has the columns we expect.
     *
     * The old implementation mutated rows through ON UPDATE metadata which
     * caused timestamps to jump forward. We rebuild the schema guarantees here
     * so the new service always works with static creation dates.
     */
    function ensureInventoryNotificationSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('active','resolved') NOT NULL DEFAULT 'active',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            quantity_at_event INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL DEFAULT NULL,
            CONSTRAINT fk_inventory_notifications_product
                FOREIGN KEY (product_id) REFERENCES products(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $tableSql = $pdo->query('SHOW CREATE TABLE inventory_notifications');
        if ($tableSql) {
            $definitionRow = $tableSql->fetch(PDO::FETCH_ASSOC);
            $definition = strtolower((string) ($definitionRow['Create Table'] ?? ''));

            if ($definition && preg_match('/`created_at`\s+([^,]+)/', $definition, $match)) {
                $createdClause = $match[1];
                $hasOnUpdate = strpos($createdClause, 'on update') !== false;
                $isTimestamp = strpos($createdClause, 'timestamp') !== false && strpos($createdClause, 'datetime') === false;

                if ($hasOnUpdate || $isTimestamp) {
                    // Fix: freeze created_at so the new "time ago" builder never sees future timestamps.
                    $pdo->exec("ALTER TABLE inventory_notifications MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                }
            }

            if ($definition && preg_match('/`resolved_at`\s+([^,]+)/', $definition, $match)) {
                $resolvedClause = $match[1];
                $isTimestamp = strpos($resolvedClause, 'timestamp') !== false && strpos($resolvedClause, 'datetime') === false;

                if ($isTimestamp) {
                    $pdo->exec("ALTER TABLE inventory_notifications MODIFY resolved_at DATETIME NULL DEFAULT NULL");
                }
            }
        }
    }

    /**
     * Insert new low stock notifications and resolve restocked ones.
     *
     * This replaces the previous ad-hoc scripts so only one synchronisation
     * path creates and closes notifications.
     */
    function syncInventoryNotifications(PDO $pdo): void
    {
        $lowStock = $pdo->query(
            "SELECT id, name, quantity, low_stock_threshold FROM products "
            . "WHERE (is_archived = 0 OR is_archived IS NULL) AND quantity <= low_stock_threshold"
        )->fetchAll(PDO::FETCH_ASSOC);

        $checkStmt = $pdo->prepare(
            "SELECT id FROM inventory_notifications WHERE product_id = ? AND status = 'active' LIMIT 1"
        );
        $createStmt = $pdo->prepare(
            'INSERT INTO inventory_notifications (product_id, title, message, quantity_at_event, is_read, created_at) '
            . 'VALUES (?, ?, ?, ?, 0, NOW())'
        );

        foreach ($lowStock as $item) {
            $checkStmt->execute([$item['id']]);
            if (!$checkStmt->fetchColumn()) {
                $quantity = (int) ($item['quantity'] ?? 0);
                $threshold = (int) ($item['low_stock_threshold'] ?? 0);
                $title = ($item['name'] ?? 'Unknown item') . ' is low on stock';
                $message = 'Only ' . $quantity . ' left (minimum ' . $threshold . ').';

                $createStmt->execute([
                    $item['id'],
                    $title,
                    $message,
                    $quantity,
                ]);
            }
        }

        $activeRecords = $pdo->query(
            "SELECT n.id, p.quantity, p.low_stock_threshold, p.is_archived FROM inventory_notifications n "
            . "LEFT JOIN products p ON p.id = n.product_id WHERE n.status = 'active'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $resolveStmt = $pdo->prepare(
            "UPDATE inventory_notifications SET status = 'resolved', resolved_at = IF(resolved_at IS NULL, NOW(), resolved_at) "
            . "WHERE id = ? AND status = 'active'"
        );

        foreach ($activeRecords as $record) {
            $quantity = isset($record['quantity']) ? (int) $record['quantity'] : null;
            $threshold = isset($record['low_stock_threshold']) ? (int) $record['low_stock_threshold'] : null;

            $isArchived = isset($record['is_archived']) && (int) $record['is_archived'] === 1;

            if ($isArchived || $quantity === null || ($threshold !== null && $quantity > $threshold)) {
                $resolveStmt->execute([$record['id']]);
            }
        }
    }

    /**
     * Build the public notification payload with preformatted "time ago" strings.
     *
     * Computing the age in SQL (TIMESTAMPDIFF) removes PHP/DB timezone drift so
     * notifications no longer stick at "Just now" after being ignored for minutes.
     */
    function loadInventoryNotifications(PDO $pdo): array
    {
        ensureInventoryNotificationSchema($pdo);
        syncInventoryNotifications($pdo);

        $stmt = $pdo->query(
            "SELECT n.*, p.name AS product_name, "
            . "TIMESTAMPDIFF(SECOND, n.created_at, NOW()) AS seconds_ago "
            . "FROM inventory_notifications n "
            . "LEFT JOIN products p ON p.id = n.product_id "
            . "ORDER BY n.created_at DESC LIMIT 10"
        );

        $notifications = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $secondsAgo = isset($row['seconds_ago']) ? (int) $row['seconds_ago'] : null;
            $row['time_ago'] = format_inventory_notification_age($secondsAgo, $row['created_at'] ?? null);
            $notifications[] = $row;
        }

        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM inventory_notifications WHERE status = 'active' AND is_read = 0"
        )->fetchColumn();

        return [
            'notifications' => $notifications,
            'active_count' => $count,
        ];
    }

    if (!function_exists('format_inventory_notification_age')) {
        /**
         * Convert an elapsed-second value into a human-friendly label.
         */
        function format_inventory_notification_age(?int $secondsAgo, ?string $fallbackDate = null): string
        {
            if ($secondsAgo === null || $secondsAgo < 0) {
                if (!$fallbackDate) {
                    return '';
                }

                try {
                    $secondsAgo = (int) (time() - strtotime($fallbackDate));
                } catch (Exception $exception) {
                    return '';
                }
            }

            if ($secondsAgo < 60) {
                return 'Just now';
            }

            $minutes = (int) floor($secondsAgo / 60);
            if ($minutes < 60) {
                return $minutes === 1 ? '1 minute ago' : $minutes . ' minutes ago';
            }

            $hours = (int) floor($secondsAgo / 3600);
            if ($hours < 24) {
                return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
            }

            $days = (int) floor($secondsAgo / 86400);
            if ($days < 7) {
                return $days === 1 ? '1 day ago' : $days . ' days ago';
            }

            $weeks = (int) floor($secondsAgo / 604800);
            if ($weeks < 4) {
                return $weeks === 1 ? '1 week ago' : $weeks . ' weeks ago';
            }

            if (!$fallbackDate) {
                return '';
            }

            try {
                $date = new DateTimeImmutable($fallbackDate);
                return $date->format('M j, Y');
            } catch (Exception $exception) {
                return '';
            }
        }
    }
}
