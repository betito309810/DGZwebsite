<?php
// Shared inventory notification helpers

require_once __DIR__ . '/../../dgz_motorshop_system/includes/product_variants.php';

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
            variant_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('active','resolved') NOT NULL DEFAULT 'active',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            quantity_at_event INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL DEFAULT NULL,
            CONSTRAINT fk_inventory_notifications_product
                FOREIGN KEY (product_id) REFERENCES products(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_inventory_notifications_variant
                FOREIGN KEY (variant_id) REFERENCES product_variants(id)
                ON DELETE SET NULL,
            INDEX idx_inventory_notifications_variant (variant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $variantColumnStmt = $pdo->query("SHOW COLUMNS FROM inventory_notifications LIKE 'variant_id'");
            $hasVariantColumn = $variantColumnStmt && $variantColumnStmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            error_log('Unable to inspect inventory_notifications.variant_id column: ' . $exception->getMessage());
            $hasVariantColumn = false;
        }

        if (!$hasVariantColumn) {
            try {
                $pdo->exec('ALTER TABLE inventory_notifications ADD COLUMN variant_id INT NULL DEFAULT NULL AFTER product_id');
            } catch (PDOException $exception) {
                $errorInfo = $exception->errorInfo ?? [];
                if (($errorInfo[1] ?? null) !== 1060) {
                    error_log('Unable to add inventory_notifications.variant_id column: ' . $exception->getMessage());
                }
            }
        }

        $tableSql = $pdo->query('SHOW CREATE TABLE inventory_notifications');
        $definition = '';
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

        if (strpos($definition, 'fk_inventory_notifications_variant') === false) {
            try {
                $pdo->exec('ALTER TABLE inventory_notifications ADD CONSTRAINT fk_inventory_notifications_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL');
            } catch (PDOException $exception) {
                $errorInfo = $exception->errorInfo ?? [];
                if (($errorInfo[1] ?? null) !== 1826) { // ignore duplicate foreign key errors
                    error_log('Unable to add inventory_notifications.variant_id foreign key: ' . $exception->getMessage());
                }
            }
        }

        if (strpos($definition, 'idx_inventory_notifications_variant') === false) {
            try {
                $pdo->exec('CREATE INDEX idx_inventory_notifications_variant ON inventory_notifications (variant_id)');
            } catch (PDOException $exception) {
                $errorInfo = $exception->errorInfo ?? [];
                if (($errorInfo[1] ?? null) !== 1061) { // duplicate index
                    error_log('Unable to create inventory_notifications.variant_id index: ' . $exception->getMessage());
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
        ensureInventoryNotificationSchema($pdo);
        ensureProductVariantSchema($pdo);

        $activeCondition = productsArchiveActiveCondition($pdo, 'p');

        $lowStockProducts = $pdo->query(
            "SELECT p.id, p.name, p.quantity, p.low_stock_threshold FROM products p "
            . "WHERE $activeCondition AND p.quantity <= p.low_stock_threshold"
        )->fetchAll(PDO::FETCH_ASSOC);

        $checkProductStmt = $pdo->prepare(
            "SELECT id FROM inventory_notifications WHERE product_id = ? AND variant_id IS NULL AND status = 'active' LIMIT 1"
        );
        $createStmt = $pdo->prepare(
            'INSERT INTO inventory_notifications (product_id, variant_id, title, message, quantity_at_event, is_read, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, 0, NOW())'
        );

        foreach ($lowStockProducts as $item) {
            $checkProductStmt->execute([$item['id']]);
            if (!$checkProductStmt->fetchColumn()) {
                $quantity = (int) ($item['quantity'] ?? 0);
                $threshold = (int) ($item['low_stock_threshold'] ?? 0);
                $title = ($item['name'] ?? 'Unknown item') . ' is low on stock';
                $message = 'Only ' . $quantity . ' left (minimum ' . $threshold . ').';

                $createStmt->execute([
                    $item['id'],
                    null,
                    $title,
                    $message,
                    $quantity,
                ]);
            }
        }

        $variantLowStock = $pdo->query(
            "SELECT pv.id AS variant_id, pv.product_id, pv.label, pv.variant_code, pv.quantity, pv.low_stock_threshold, p.name AS product_name "
            . "FROM product_variants pv "
            . "INNER JOIN products p ON p.id = pv.product_id "
            . "WHERE pv.low_stock_threshold IS NOT NULL AND pv.low_stock_threshold >= 0 "
            . "AND pv.quantity <= pv.low_stock_threshold AND $activeCondition"
        )->fetchAll(PDO::FETCH_ASSOC);

        $checkVariantStmt = $pdo->prepare(
            "SELECT id FROM inventory_notifications WHERE variant_id = ? AND status = 'active' LIMIT 1"
        );

        foreach ($variantLowStock as $variant) {
            $variantId = isset($variant['variant_id']) ? (int) $variant['variant_id'] : 0;
            if ($variantId <= 0) {
                continue;
            }

            $checkVariantStmt->execute([$variantId]);
            if ($checkVariantStmt->fetchColumn()) {
                continue;
            }

            $quantity = (int) ($variant['quantity'] ?? 0);
            $threshold = (int) ($variant['low_stock_threshold'] ?? 0);
            $label = trim((string) ($variant['label'] ?? 'Variant')) ?: 'Variant';
            $productName = trim((string) ($variant['product_name'] ?? 'Unknown product')) ?: 'Unknown product';
            $code = trim((string) ($variant['variant_code'] ?? ''));
            $codeSuffix = $code !== '' ? ' (Code: ' . $code . ')' : '';

            $title = sprintf('Variant low stock: %s – %s', $productName, $label);
            $message = sprintf('Only %d left for %s%s (minimum %d).', $quantity, $label, $codeSuffix, $threshold);

            $createStmt->execute([
                $variant['product_id'] ?? null,
                $variantId,
                $title,
                $message,
                $quantity,
            ]);
        }

        $archiveColumnExists = productsArchiveColumnExists($pdo);
        $archiveSelect = $archiveColumnExists ? 'p.is_archived AS is_archived' : 'NULL AS is_archived';
        $activeRecords = $pdo->query(
            "SELECT n.id, n.product_id, n.variant_id, "
            . "p.quantity AS product_quantity, p.low_stock_threshold AS product_low_stock_threshold, " . $archiveSelect
            . ", pv.quantity AS variant_quantity, pv.low_stock_threshold AS variant_low_stock_threshold "
            . "FROM inventory_notifications n "
            . "LEFT JOIN products p ON p.id = n.product_id "
            . "LEFT JOIN product_variants pv ON pv.id = n.variant_id "
            . "WHERE n.status = 'active'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $resolveStmt = $pdo->prepare(
            "UPDATE inventory_notifications SET status = 'resolved', resolved_at = IF(resolved_at IS NULL, NOW(), resolved_at) "
            . "WHERE id = ? AND status = 'active'"
        );

        foreach ($activeRecords as $record) {
            $isArchived = isset($record['is_archived']) && (int) $record['is_archived'] === 1;
            $variantId = isset($record['variant_id']) ? (int) $record['variant_id'] : null;

            if ($variantId) {
                $variantQuantity = isset($record['variant_quantity']) ? (int) $record['variant_quantity'] : null;
                $variantThreshold = isset($record['variant_low_stock_threshold']) ? (int) $record['variant_low_stock_threshold'] : null;
                $productQuantity = isset($record['product_quantity']) ? (int) $record['product_quantity'] : null;

                if (
                    $variantQuantity === null
                    || $productQuantity === null
                    || $isArchived
                    || $variantThreshold === null
                    || ($variantQuantity !== null && $variantThreshold !== null && $variantQuantity > $variantThreshold)
                ) {
                    $resolveStmt->execute([$record['id']]);
                }

                continue;
            }

            $quantity = isset($record['product_quantity']) ? (int) $record['product_quantity'] : null;
            $threshold = isset($record['product_low_stock_threshold']) ? (int) $record['product_low_stock_threshold'] : null;

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
        ensureProductVariantSchema($pdo);
        syncInventoryNotifications($pdo);

        $stmt = $pdo->query(
            "SELECT n.*, p.name AS product_name, pv.label AS variant_label, pv.variant_code AS variant_code, pv.low_stock_threshold AS variant_low_stock_threshold, "
            . "TIMESTAMPDIFF(SECOND, n.created_at, NOW()) AS seconds_ago "
            . "FROM inventory_notifications n "
            . "LEFT JOIN products p ON p.id = n.product_id "
            . "LEFT JOIN product_variants pv ON pv.id = n.variant_id "
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
