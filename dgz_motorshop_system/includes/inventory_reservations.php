<?php
declare(strict_types=1);

if (!function_exists('inventoryReservationsEnsureSchema')) {
    function inventoryReservationsEnsureSchema(PDO $pdo): void
    {
        static $ensured = [];

        $key = function_exists('spl_object_id') ? spl_object_id($pdo) : spl_object_hash($pdo);
        if (isset($ensured[$key])) {
            return;
        }

        $ensured[$key] = true;

        try {
            $pdo->query('SELECT 1 FROM inventory_reservations LIMIT 1');
            return;
        } catch (Throwable $exception) {
            // Table is missing; attempt to create it below.
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS inventory_reservations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    variant_id INT UNSIGNED DEFAULT NULL,
    quantity INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_inventory_reservation (order_id, product_id, variant_id),
    KEY idx_inventory_reservation_product (product_id),
    KEY idx_inventory_reservation_variant (variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        try {
            $pdo->exec($sql);
        } catch (Throwable $exception) {
            error_log('Unable to prepare inventory_reservations table: ' . $exception->getMessage());
        }
    }
}

if (!function_exists('inventoryReservationsFetchMap')) {
    /**
     * Fetch aggregated reserved quantities for the provided products and variants.
     *
     * @param array<int,int> $productIds
     * @param array<int,int> $variantIds
     * @return array{products: array<int,int>, variants: array<int,int>}
     */
    function inventoryReservationsFetchMap(PDO $pdo, array $productIds, array $variantIds = []): array
    {
        inventoryReservationsEnsureSchema($pdo);

        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn ($value) => $value > 0)));
        $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds), static fn ($value) => $value > 0)));

        $clauses = [];
        $params = [];

        if ($productIds !== []) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $clauses[] = 'product_id IN (' . $placeholders . ')';
            foreach ($productIds as $id) {
                $params[] = $id;
            }
        }

        if ($variantIds !== []) {
            $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
            $clauses[] = '(variant_id IS NOT NULL AND variant_id IN (' . $placeholders . '))';
            foreach ($variantIds as $id) {
                $params[] = $id;
            }
        }

        $sql = 'SELECT product_id, variant_id, SUM(quantity) AS total_quantity FROM inventory_reservations';
        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' OR ', $clauses);
        }
        $sql .= ' GROUP BY product_id, variant_id';

        $productTotals = [];
        $variantTotals = [];

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
                $variantId = isset($row['variant_id']) ? (int) $row['variant_id'] : 0;
                $quantity = isset($row['total_quantity']) ? (int) $row['total_quantity'] : 0;
                if ($quantity < 0) {
                    $quantity = 0;
                }
                if ($productId > 0 && $quantity > 0) {
                    if (!isset($productTotals[$productId])) {
                        $productTotals[$productId] = 0;
                    }
                    $productTotals[$productId] += $quantity;
                }
                if ($variantId > 0 && $quantity > 0) {
                    $variantTotals[$variantId] = $quantity;
                }
            }
        } catch (Throwable $exception) {
            error_log('Unable to load inventory reservations: ' . $exception->getMessage());
        }

        return [
            'products' => $productTotals,
            'variants' => $variantTotals,
        ];
    }
}

if (!function_exists('inventoryReservationsReplaceForOrder')) {
    /**
     * Replace the reservation set for an order with the provided items.
     *
     * @param array<int, array{product_id:int, variant_id:?int, quantity:int}> $items
     */
    function inventoryReservationsReplaceForOrder(PDO $pdo, int $orderId, array $items): void
    {
        if ($orderId <= 0) {
            return;
        }

        inventoryReservationsEnsureSchema($pdo);

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            $variantId = isset($item['variant_id']) && $item['variant_id'] !== null ? (int) $item['variant_id'] : null;
            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $key = $productId . ':' . ($variantId !== null ? $variantId : 'null');
            if (!isset($normalized[$key])) {
                $normalized[$key] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => 0,
                ];
            }

            $normalized[$key]['quantity'] += $quantity;
        }

        try {
            $delete = $pdo->prepare('DELETE FROM inventory_reservations WHERE order_id = ?');
            $delete->execute([$orderId]);
        } catch (Throwable $exception) {
            error_log('Unable to clear inventory reservations for order ' . $orderId . ': ' . $exception->getMessage());
            return;
        }

        if ($normalized === []) {
            return;
        }

        try {
            $insert = $pdo->prepare('INSERT INTO inventory_reservations (order_id, product_id, variant_id, quantity) VALUES (?, ?, ?, ?)');
            foreach ($normalized as $row) {
                $insert->execute([
                    $orderId,
                    $row['product_id'],
                    $row['variant_id'],
                    $row['quantity'],
                ]);
            }
        } catch (Throwable $exception) {
            error_log('Unable to create inventory reservations for order ' . $orderId . ': ' . $exception->getMessage());
        }
    }
}

if (!function_exists('inventoryReservationsGetForOrder')) {
    /**
     * Load reservation rows for the specified order.
     *
     * @return array<int, array{product_id:int, variant_id:?int, quantity:int}>
     */
    function inventoryReservationsGetForOrder(PDO $pdo, int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        inventoryReservationsEnsureSchema($pdo);

        try {
            $stmt = $pdo->prepare('SELECT product_id, variant_id, quantity FROM inventory_reservations WHERE order_id = ?');
            $stmt->execute([$orderId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            error_log('Unable to load inventory reservations for order ' . $orderId . ': ' . $exception->getMessage());
            return [];
        }

        if (!$rows) {
            return [];
        }

        $reservations = [];
        foreach ($rows as $row) {
            $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $variantId = isset($row['variant_id']) ? (int) $row['variant_id'] : 0;
            $quantity = isset($row['quantity']) ? (int) $row['quantity'] : 0;
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $reservations[] = [
                'product_id' => $productId,
                'variant_id' => $variantId > 0 ? $variantId : null,
                'quantity' => $quantity,
            ];
        }

        return $reservations;
    }
}

if (!function_exists('inventoryReservationsReleaseForOrder')) {
    function inventoryReservationsReleaseForOrder(PDO $pdo, int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        inventoryReservationsEnsureSchema($pdo);

        try {
            $stmt = $pdo->prepare('DELETE FROM inventory_reservations WHERE order_id = ?');
            $stmt->execute([$orderId]);
        } catch (Throwable $exception) {
            error_log('Unable to release inventory reservations for order ' . $orderId . ': ' . $exception->getMessage());
        }
    }
}

if (!function_exists('inventoryReservationsDeductForApproval')) {
    /**
     * Apply reserved quantities to real inventory when an order is approved.
     */
    function inventoryReservationsDeductForApproval(PDO $pdo, int $orderId): bool
    {
        $reservations = inventoryReservationsGetForOrder($pdo, $orderId);
        if ($reservations === []) {
            return true;
        }

        $productTotals = [];
        $variantTotals = [];
        foreach ($reservations as $row) {
            $productId = $row['product_id'];
            $variantId = $row['variant_id'];
            $quantity = $row['quantity'];
            if ($quantity <= 0) {
                continue;
            }

            if (!isset($productTotals[$productId])) {
                $productTotals[$productId] = 0;
            }
            $productTotals[$productId] += $quantity;

            if ($variantId !== null) {
                if (!isset($variantTotals[$variantId])) {
                    $variantTotals[$variantId] = 0;
                }
                $variantTotals[$variantId] += $quantity;
            }
        }

        try {
            if ($variantTotals !== []) {
                $variantStmt = $pdo->prepare(
                    'UPDATE product_variants '
                    . 'SET quantity = GREATEST(quantity - ?, 0), '
                    . 'low_stock_threshold = CASE '
                    . '    WHEN low_stock_threshold IS NULL THEN '
                    . '        CASE '
                    . '            WHEN (GREATEST(quantity - ?, 0)) <= 0 THEN 0 '
                    . '            ELSE LEAST(9999, GREATEST(1, CEIL(GREATEST(quantity - ?, 0) * 0.2))) '
                    . '        END '
                    . '    ELSE GREATEST(0, low_stock_threshold) '
                    . 'END '
                    . 'WHERE id = ?'
                );

                foreach ($variantTotals as $variantId => $qty) {
                    $variantStmt->execute([$qty, $qty, $qty, $variantId]);
                }
            }

            if ($productTotals !== []) {
                $productStmt = $pdo->prepare('UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ?');
                foreach ($productTotals as $productId => $qty) {
                    $productStmt->execute([$qty, $productId]);
                }
            }

            inventoryReservationsReleaseForOrder($pdo, $orderId);
        } catch (Throwable $exception) {
            error_log('Failed to deduct reserved inventory for order ' . $orderId . ': ' . $exception->getMessage());
            return false;
        }

        return true;
    }
}

if (!function_exists('inventoryReservationsResolveOrderItems')) {
    /**
     * Load order item quantities for backfilling reservations.
     *
     * @return array<int, array{product_id:int, variant_id:?int, quantity:int}>
     */
    function inventoryReservationsResolveOrderItems(PDO $pdo, int $orderId): array
    {
        $orderIdColumn = tableFindColumn($pdo, 'order_items', ['order_id', 'orderId', 'orderID', 'orderid']);
        $quantityColumn = tableFindColumn($pdo, 'order_items', ['quantity', 'qty', 'qty_ordered']);
        if ($orderIdColumn === null || $quantityColumn === null) {
            return [];
        }

        $productColumn = tableFindColumn($pdo, 'order_items', ['product_id', 'productId', 'productID', 'productid']);
        $variantColumn = tableFindColumn($pdo, 'order_items', ['variant_id', 'variantId', 'variantID', 'variantid']);

        $selectParts = [];
        $joins = [];

        if ($variantColumn !== null) {
            $joins[] = 'LEFT JOIN product_variants pv ON pv.id = oi.`' . $variantColumn . '`';
        }

        if ($productColumn !== null) {
            if ($variantColumn !== null) {
                $selectParts[] = 'COALESCE(oi.`' . $productColumn . '`, pv.product_id) AS product_id';
            } else {
                $selectParts[] = 'oi.`' . $productColumn . '` AS product_id';
            }
        } elseif ($variantColumn !== null) {
            $selectParts[] = 'pv.product_id AS product_id';
        } else {
            $selectParts[] = 'NULL AS product_id';
        }

        if ($variantColumn !== null) {
            $selectParts[] = 'oi.`' . $variantColumn . '` AS variant_id';
        } else {
            $selectParts[] = 'NULL AS variant_id';
        }

        $selectParts[] = 'oi.`' . $quantityColumn . '` AS quantity';

        $sql = 'SELECT ' . implode(', ', $selectParts)
            . ' FROM order_items oi '
            . implode(' ', $joins)
            . ' WHERE oi.`' . $orderIdColumn . '` = ?';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$orderId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            error_log('Unable to load order items for reservation backfill: ' . $exception->getMessage());
            return [];
        }

        if (!$rows) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $variantId = isset($row['variant_id']) ? (int) $row['variant_id'] : 0;
            $quantity = isset($row['quantity']) ? (int) $row['quantity'] : 0;
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $items[] = [
                'product_id' => $productId,
                'variant_id' => $variantId > 0 ? $variantId : null,
                'quantity' => $quantity,
            ];
        }

        return $items;
    }
}

if (!function_exists('inventoryReservationsBackfillPendingOrders')) {
    /**
     * Seed reservation rows for legacy pending orders so availability checks remain accurate.
     */
    function inventoryReservationsBackfillPendingOrders(PDO $pdo): array
    {
        static $completed = false;
        if ($completed) {
            return ['processed' => 0, 'created' => 0];
        }

        $completed = true;

        inventoryReservationsEnsureSchema($pdo);

        $statusColumn = ordersFindColumn($pdo, ['status', 'order_status']);
        $idColumn = ordersFindColumn($pdo, ['id', 'order_id']);
        if ($idColumn === null) {
            return ['processed' => 0, 'created' => 0];
        }

        $pendingStatuses = ['pending', 'payment_verification'];
        $ordersSql = 'SELECT `' . $idColumn . '` AS id';
        if ($statusColumn !== null) {
            $ordersSql .= ', `' . $statusColumn . '` AS status';
        } else {
            $ordersSql .= ', NULL AS status';
        }
        $ordersSql .= ' FROM orders';
        if ($statusColumn !== null) {
            $ordersSql .= ' WHERE LOWER(TRIM(`' . $statusColumn . '`)) IN ('
                . implode(',', array_fill(0, count($pendingStatuses), '?')) . ')';
        }

        try {
            $stmt = $pdo->prepare($ordersSql);
            if ($statusColumn !== null) {
                $stmt->execute($pendingStatuses);
            } else {
                $stmt->execute();
            }
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            error_log('Unable to scan pending orders for reservation backfill: ' . $exception->getMessage());
            return ['processed' => 0, 'created' => 0];
        }

        if (!$orders) {
            return ['processed' => 0, 'created' => 0];
        }

        $processed = 0;
        $created = 0;

        foreach ($orders as $orderRow) {
            $orderId = isset($orderRow['id']) ? (int) $orderRow['id'] : 0;
            if ($orderId <= 0) {
                continue;
            }

            $processed++;

            $existing = inventoryReservationsGetForOrder($pdo, $orderId);
            if ($existing !== []) {
                continue;
            }

            $items = inventoryReservationsResolveOrderItems($pdo, $orderId);
            if ($items === []) {
                continue;
            }

            try {
                $pdo->beginTransaction();
                inventoryReservationsReplaceForOrder($pdo, $orderId, $items);

                $productTotals = [];
                $variantTotals = [];
                foreach ($items as $row) {
                    $productId = $row['product_id'];
                    $variantId = $row['variant_id'];
                    $quantity = $row['quantity'];
                    if (!isset($productTotals[$productId])) {
                        $productTotals[$productId] = 0;
                    }
                    $productTotals[$productId] += $quantity;
                    if ($variantId !== null) {
                        if (!isset($variantTotals[$variantId])) {
                            $variantTotals[$variantId] = 0;
                        }
                        $variantTotals[$variantId] += $quantity;
                    }
                }

                if ($variantTotals !== []) {
                    $variantRestock = $pdo->prepare(
                        'UPDATE product_variants '
                        . 'SET quantity = quantity + ?, '
                        . 'low_stock_threshold = CASE '
                        . '    WHEN low_stock_threshold IS NULL THEN '
                        . '        CASE '
                        . '            WHEN (quantity + ?) <= 0 THEN 0 '
                        . '            ELSE LEAST(9999, GREATEST(1, CEIL((quantity + ?) * 0.2))) '
                        . '        END '
                        . '    ELSE GREATEST(0, low_stock_threshold) '
                        . 'END '
                        . 'WHERE id = ?'
                    );
                    foreach ($variantTotals as $variantId => $qty) {
                        $variantRestock->execute([$qty, $qty, $qty, $variantId]);
                    }
                }

                if ($productTotals !== []) {
                    $productRestock = $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?');
                    foreach ($productTotals as $productId => $qty) {
                        $productRestock->execute([$qty, $productId]);
                    }
                }

                $pdo->commit();
                $created++;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Unable to backfill reservations for order ' . $orderId . ': ' . $exception->getMessage());
            }
        }

        return ['processed' => $processed, 'created' => $created];
    }
}
