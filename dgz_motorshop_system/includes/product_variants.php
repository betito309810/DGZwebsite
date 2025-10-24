<?php
// Added: helper utilities for loading, normalising, and summarising product variant data.

if (!function_exists('ensureProductVariantSchema')) {
    /**
     * Ensure optional variant columns exist for legacy databases.
     */
    function ensureProductVariantSchema(PDO $pdo): void
    {
        static $checked = [];

        $key = function_exists('spl_object_id')
            ? (string) spl_object_id($pdo)
            : spl_object_hash($pdo);

        if (isset($checked[$key])) {
            return;
        }

        $checked[$key] = true;

        try {
            $columnsStmt = $pdo->query('SHOW COLUMNS FROM product_variants');
        } catch (PDOException $exception) {
            error_log('Unable to inspect product_variants schema: ' . $exception->getMessage());
            return;
        }

        if (!$columnsStmt) {
            return;
        }

        $columns = [];
        while ($column = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
            $name = strtolower((string) ($column['Field'] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        if (!isset($columns['variant_code'])) {
            try {
                $pdo->exec('ALTER TABLE product_variants ADD COLUMN variant_code VARCHAR(100) DEFAULT NULL AFTER sku');
            } catch (PDOException $exception) {
                $errorInfo = $exception->errorInfo ?? [];
                if (($errorInfo[1] ?? null) !== 1060) { // 1060 = duplicate column
                    error_log('Unable to add product_variants.variant_code: ' . $exception->getMessage());
                }
            }
        }

        if (!isset($columns['low_stock_threshold'])) {
            try {
                $pdo->exec('ALTER TABLE product_variants ADD COLUMN low_stock_threshold INT DEFAULT NULL AFTER quantity');
            } catch (PDOException $exception) {
                $errorInfo = $exception->errorInfo ?? [];
                if (($errorInfo[1] ?? null) !== 1060) {
                    error_log('Unable to add product_variants.low_stock_threshold: ' . $exception->getMessage());
                }
            }
        }

        backfillVariantMetadata($pdo);
    }
}

if (!function_exists('calculateVariantLowStockThreshold')) {
    /**
     * Compute the automatic low-stock threshold for a given on-hand quantity.
     */
    function calculateVariantLowStockThreshold(int $quantity): int
    {
        if ($quantity <= 0) {
            return 0;
        }

        $threshold = (int) ceil($quantity * 0.2);
        if ($threshold < 1) {
            $threshold = 1;
        }
        if ($threshold > 9999) {
            $threshold = 9999;
        }

        return $threshold;
    }
}

if (!function_exists('normaliseVariantCodePrefix')) {
    /**
     * Build a stable, sanitised prefix from the parent product code.
     */
    function normaliseVariantCodePrefix(int $productId, ?string $rawCode): string
    {
        $code = trim((string) ($rawCode ?? ''));
        if ($code === '') {
            $code = 'PROD-' . str_pad((string) $productId, 4, '0', STR_PAD_LEFT);
        }

        $upper = strtoupper($code);
        $sanitised = preg_replace('/[^A-Z0-9]+/', '-', $upper);
        $sanitised = $sanitised !== null ? trim($sanitised, '-') : '';

        if ($sanitised === '') {
            $sanitised = 'PROD-' . $productId;
        }

        return $sanitised;
    }
}

if (!function_exists('generateAutomaticVariantCode')) {
    /**
     * Generate a unique variant code using the product prefix and variant label.
     */
    function generateAutomaticVariantCode(string $prefix, string $label, int $fallbackIndex, array &$taken): string
    {
        $labelSlug = strtoupper(trim($label));
        $labelSlug = preg_replace('/[^A-Z0-9]+/', '-', $labelSlug);
        $labelSlug = $labelSlug !== null ? trim($labelSlug, '-') : '';

        if ($labelSlug === '') {
            $labelSlug = 'VAR-' . str_pad((string) max(1, $fallbackIndex), 2, '0', STR_PAD_LEFT);
        }

        $base = $prefix !== '' ? $prefix . '-' . $labelSlug : $labelSlug;
        $base = preg_replace('/-+/', '-', $base ?? '');
        $base = $base !== null ? trim($base, '-') : '';
        if ($base === '') {
            $base = 'VAR-' . str_pad((string) max(1, $fallbackIndex), 2, '0', STR_PAD_LEFT);
        }

        $baseCandidate = function_exists('mb_substr') ? mb_substr($base, 0, 100) : substr($base, 0, 100);
        $baseCandidate = $baseCandidate !== '' ? rtrim($baseCandidate, '-') : $baseCandidate;
        if ($baseCandidate === '') {
            $baseCandidate = 'VAR-' . str_pad((string) max(1, $fallbackIndex), 2, '0', STR_PAD_LEFT);
        }

        $candidate = $baseCandidate;
        $normalised = strtolower($candidate);
        $suffix = 2;
        while (isset($taken[$normalised])) {
            $suffixPart = '-' . $suffix;
            $maxLength = 100 - strlen($suffixPart);
            $truncated = $baseCandidate;
            if (strlen($truncated) > $maxLength) {
                $slice = substr($baseCandidate, 0, $maxLength);
                $truncated = rtrim($slice, '-');
                if ($truncated === '') {
                    $truncated = $slice;
                }
            }
            $candidate = $truncated . $suffixPart;
            $normalised = strtolower($candidate);
            $suffix++;
        }

        $taken[$normalised] = true;

        return $candidate;
    }
}

if (!function_exists('backfillVariantMetadata')) {
    /**
     * Populate missing variant codes and thresholds for legacy rows.
     */
    function backfillVariantMetadata(PDO $pdo): void
    {
        static $backfilled = [];

        $key = function_exists('spl_object_id')
            ? (string) spl_object_id($pdo)
            : spl_object_hash($pdo);

        if (isset($backfilled[$key])) {
            return;
        }

        $backfilled[$key] = true;

        try {
            $stmt = $pdo->query(
                'SELECT pv.id, pv.product_id, pv.label, pv.variant_code, pv.quantity, pv.low_stock_threshold, p.code AS product_code '
                . 'FROM product_variants pv '
                . 'LEFT JOIN products p ON p.id = pv.product_id '
                . 'ORDER BY pv.product_id ASC, pv.id ASC'
            );
        } catch (PDOException $exception) {
            error_log('Unable to backfill product variant metadata: ' . $exception->getMessage());
            return;
        }

        if (!$stmt) {
            return;
        }

        $updates = [];
        $takenCodes = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $variantId = isset($row['id']) ? (int) $row['id'] : 0;
            $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            if ($variantId <= 0 || $productId <= 0) {
                continue;
            }

            if (!isset($takenCodes[$productId])) {
                $takenCodes[$productId] = [];
            }

            $currentCode = isset($row['variant_code']) ? trim((string) $row['variant_code']) : '';
            $lowerCode = strtolower($currentCode);
            $duplicateDetected = false;
            if ($currentCode !== '') {
                if (isset($takenCodes[$productId][$lowerCode])) {
                    $duplicateDetected = true;
                } else {
                    $takenCodes[$productId][$lowerCode] = true;
                }
            }

            $quantity = isset($row['quantity']) ? (int) $row['quantity'] : 0;
            $expectedThreshold = calculateVariantLowStockThreshold($quantity);
            $currentThresholdRaw = $row['low_stock_threshold'] ?? null;
            $currentThreshold = $currentThresholdRaw !== null ? (int) $currentThresholdRaw : null;

            $needsCodeUpdate = $currentCode === '' || $duplicateDetected;
            $needsThresholdUpdate = $currentThreshold !== $expectedThreshold;

            if ($needsCodeUpdate) {
                $prefix = normaliseVariantCodePrefix($productId, $row['product_code'] ?? null);
                $currentCode = generateAutomaticVariantCode(
                    $prefix,
                    (string) ($row['label'] ?? ''),
                    count($takenCodes[$productId]) + 1,
                    $takenCodes[$productId]
                );
            }

            if ($needsCodeUpdate || $needsThresholdUpdate) {
                $updates[] = [
                    'id' => $variantId,
                    'variant_code' => $currentCode,
                    'threshold' => $expectedThreshold,
                ];
            }
        }

        if (empty($updates)) {
            return;
        }

        $updateStmt = $pdo->prepare('UPDATE product_variants SET variant_code = ?, low_stock_threshold = ? WHERE id = ?');
        foreach ($updates as $update) {
            try {
                $updateStmt->execute([
                    $update['variant_code'],
                    $update['threshold'],
                    $update['id'],
                ]);
            } catch (PDOException $exception) {
                error_log('Unable to update product variant metadata: ' . $exception->getMessage());
            }
        }
    }
}

if (!function_exists('fetchProductVariants')) {
    /**
     * Load all variants for a given product ordered by sort priority and creation date.
     */
    function fetchProductVariants(PDO $pdo, int $productId): array
    {
        ensureProductVariantSchema($pdo);

        $stmt = $pdo->prepare(
            'SELECT id, product_id, label, sku, variant_code, price, quantity, low_stock_threshold, is_default, sort_order, created_at, updated_at
             FROM product_variants
             WHERE product_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$productId]);
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($variants as &$variant) {
            $variant['id'] = (int) ($variant['id'] ?? 0);
            $variant['product_id'] = (int) ($variant['product_id'] ?? 0);
            $variant['price'] = (float) ($variant['price'] ?? 0);
            $variant['quantity'] = (int) ($variant['quantity'] ?? 0);
            $variant['is_default'] = (int) ($variant['is_default'] ?? 0);
            $variant['sort_order'] = (int) ($variant['sort_order'] ?? 0);
            $code = isset($variant['variant_code']) ? trim((string) $variant['variant_code']) : '';
            $variant['variant_code'] = $code !== '' ? $code : '';

            $thresholdRaw = $variant['low_stock_threshold'] ?? null;
            if ($thresholdRaw === null || $thresholdRaw === '') {
                $threshold = calculateVariantLowStockThreshold($variant['quantity']);
            } else {
                $threshold = (int) $thresholdRaw;
                if ($threshold < 0) {
                    $threshold = 0;
                }
            }
            $variant['low_stock_threshold'] = $threshold;
        }
        unset($variant);

        return $variants;
    }
}

if (!function_exists('fetchVariantsForProducts')) {
    /**
     * Bulk load variants for multiple product ids and return them keyed by product id.
     */
    function fetchVariantsForProducts(PDO $pdo, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) {
            return [];
        }

        ensureProductVariantSchema($pdo);

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, product_id, label, sku, variant_code, price, quantity, low_stock_threshold, is_default, sort_order, created_at, updated_at
             FROM product_variants
             WHERE product_id IN ($placeholders)
             ORDER BY product_id ASC, sort_order ASC, id ASC"
        );
        $stmt->execute($productIds);

        $variantsByProduct = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int) ($row['product_id'] ?? 0);
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['price'] = (float) ($row['price'] ?? 0);
            $row['quantity'] = (int) ($row['quantity'] ?? 0);
            $row['is_default'] = (int) ($row['is_default'] ?? 0);
            $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
            $code = isset($row['variant_code']) ? trim((string) $row['variant_code']) : '';
            $row['variant_code'] = $code !== '' ? $code : '';

            $thresholdRaw = $row['low_stock_threshold'] ?? null;
            if ($thresholdRaw === null || $thresholdRaw === '') {
                $threshold = calculateVariantLowStockThreshold($row['quantity']);
            } else {
                $threshold = (int) $thresholdRaw;
                if ($threshold < 0) {
                    $threshold = 0;
                }
            }
            $row['low_stock_threshold'] = $threshold;
            $variantsByProduct[$productId][] = $row;
        }

        return $variantsByProduct;
    }
}

if (!function_exists('summariseVariantStock')) {
    /**
     * Summarise total quantity and default price from a variant collection.
     */
    function summariseVariantStock(array $variants): array
    {
        if (empty($variants)) {
            return [];
        }

        $totalQuantity = 0;
        $defaultPrice = null;
        $fallbackPrice = null;

        foreach ($variants as $variant) {
            $quantity = isset($variant['quantity']) ? (int) $variant['quantity'] : 0;
            if ($quantity < 0) {
                $quantity = 0;
            } elseif ($quantity > 9999) {
                $quantity = 9999;
            }
            $price = isset($variant['price']) ? (float) $variant['price'] : 0.0;
            $isDefault = !empty($variant['is_default']);

            $totalQuantity += $quantity;
            if ($fallbackPrice === null) {
                $fallbackPrice = $price;
            }
            if ($isDefault) {
                $defaultPrice = $price;
            }
        }

        if ($totalQuantity > 9999) {
            $totalQuantity = 9999;
        }

        if ($defaultPrice === null) {
            $defaultPrice = $fallbackPrice ?? 0.0;
        }

        return [
            'quantity' => $totalQuantity,
            'price' => $defaultPrice,
        ];
    }
}

if (!function_exists('normaliseVariantPayload')) {
    /**
     * Decode and sanitise the JSON payload posted from the variant editor UI.
     */
    function normaliseVariantPayload(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $normalised = [];
        $sortOrder = 0;
        foreach ($data as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $label = trim((string) ($raw['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $sortOrder++;
            $quantity = isset($raw['quantity']) ? (int) $raw['quantity'] : 0;
            if ($quantity < 0) {
                $quantity = 0;
            } elseif ($quantity > 9999) {
                $quantity = 9999;
            }

            $normalised[] = [
                'id' => isset($raw['id']) ? (int) $raw['id'] : null,
                'label' => $label,
                'sku' => trim((string) ($raw['sku'] ?? '')) ?: null,
                'price' => isset($raw['price']) ? max(0, (float) $raw['price']) : 0.0,
                'quantity' => $quantity,
                'is_default' => !empty($raw['is_default']) ? 1 : 0,
                'sort_order' => $sortOrder,
            ];
        }

        return $normalised;
    }
}

if (!function_exists('atLeastOneVariantIsDefault')) {
    /**
     * Ensure there is exactly one variant flagged as the default option.
     */
    function atLeastOneVariantIsDefault(array &$variants): void
    {
        $foundDefault = false;

        foreach ($variants as $index => &$variant) {
            $isDefault = !empty($variant['is_default']);
            if ($isDefault && !$foundDefault) {
                $variant['is_default'] = 1;
                $foundDefault = true;
            } else {
                $variant['is_default'] = 0;
            }
        }
        unset($variant);

        if (!$foundDefault && !empty($variants)) {
            $variants[0]['is_default'] = 1;
        }
    }
}

if (!function_exists('findDefaultVariantId')) {
    /**
     * Resolve the default variant for a product or fall back to the first configured entry.
     */
    function findDefaultVariantId(PDO $pdo, int $productId): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM product_variants
             WHERE product_id = ?
             ORDER BY is_default DESC, sort_order ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([$productId]);
        $variantId = $stmt->fetchColumn();

        if ($variantId === false || $variantId === null) {
            return null;
        }

        return (int) $variantId;
    }
}

if (!function_exists('adjustVariantQuantity')) {
    /**
     * Increment or decrement a specific variant's on-hand count.
     */
    function adjustVariantQuantity(PDO $pdo, int $variantId, int $quantityChange): void
    {
        if ($quantityChange === 0 || $variantId <= 0) {
            return;
        }

        $update = $pdo->prepare(
            'UPDATE product_variants '
            . 'SET quantity = quantity + :delta, '
            . 'low_stock_threshold = CASE '
            . '    WHEN (quantity + :delta) <= 0 THEN 0 '
            . '    ELSE LEAST(9999, GREATEST(1, CEIL((quantity + :delta) * 0.2))) '
            . 'END '
            . 'WHERE id = :variant_id'
        );
        $update->execute([
            ':delta' => $quantityChange,
            ':variant_id' => $variantId,
        ]);
    }
}

if (!function_exists('adjustDefaultVariantQuantity')) {
    /**
     * Mirror product-level inventory adjustments onto the default variant when present.
     */
    function adjustDefaultVariantQuantity(PDO $pdo, int $productId, int $quantityChange): void
    {
        if ($quantityChange === 0) {
            return;
        }

        $variantId = findDefaultVariantId($pdo, $productId);
        if ($variantId === null) {
            return;
        }

        adjustVariantQuantity($pdo, $variantId, $quantityChange);
    }
}
