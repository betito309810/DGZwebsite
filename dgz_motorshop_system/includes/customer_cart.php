<?php
declare(strict_types=1);

/**
 * Helpers for persisting authenticated customer carts between devices.
 */

if (!function_exists('customerCartNormalisePayloadItems')) {
    /**
     * Normalise cart payload items into a deduplicated list capped at 50 rows.
     *
     * @param array $items
     * @return array<int, array{product_id:int, variant_id:?int, quantity:int}>
     */
    function customerCartNormalisePayloadItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = isset($item['product_id']) ? (int) $item['product_id'] : (isset($item['id']) ? (int) $item['id'] : 0);
            if ($productId <= 0) {
                continue;
            }

            $variantRaw = $item['variant_id'] ?? ($item['variantId'] ?? null);
            $variantId = $variantRaw !== null ? (int) $variantRaw : null;
            if ($variantId !== null && $variantId <= 0) {
                $variantId = null;
            }

            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            if ($quantity <= 0) {
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
            if ($normalized[$key]['quantity'] > 999) {
                $normalized[$key]['quantity'] = 999;
            }
        }

        if ($normalized === []) {
            return [];
        }

        if (count($normalized) > 50) {
            $normalized = array_slice($normalized, 0, 50, true);
        }

        return array_values($normalized);
    }
}

if (!function_exists('customerCartFetchProducts')) {
    /**
     * Fetch product rows keyed by ID with optional archive awareness.
     */
    function customerCartFetchProducts(PDO $pdo, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $sql = 'SELECT id, name, price FROM products p WHERE p.id IN (' . $placeholders . ')';

        if (function_exists('productsArchiveActiveCondition')) {
            $activeClause = productsArchiveActiveCondition($pdo, 'p', true);
            if ($activeClause !== '') {
                $sql .= ' AND ' . $activeClause;
            }
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($productIds));

        $products = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $products[$id] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'price' => (float) ($row['price'] ?? 0),
            ];
        }

        return $products;
    }
}

if (!function_exists('customerCartFetchVariants')) {
    /**
     * Fetch variant rows keyed by ID.
     */
    function customerCartFetchVariants(PDO $pdo, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $stmt = $pdo->prepare('SELECT id, product_id, label, price FROM product_variants WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_values($variantIds));

        $variants = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $variants[$id] = [
                'product_id' => (int) ($row['product_id'] ?? 0),
                'label' => trim((string) ($row['label'] ?? '')),
                'price' => (float) ($row['price'] ?? 0),
            ];
        }

        return $variants;
    }
}

if (!function_exists('customerCartFetch')) {
    /**
     * Load the persisted cart for a given customer.
     */
    function customerCartFetch(PDO $pdo, int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        try {
            $stmt = $pdo->prepare('SELECT product_id, variant_id, quantity FROM customer_cart_items WHERE customer_id = ? ORDER BY id');
            $stmt->execute([$customerId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            error_log('Unable to load customer cart: ' . $exception->getMessage());
            return [];
        }

        if (!$rows) {
            return [];
        }

        $productIds = [];
        $variantIds = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $productIds[$productId] = $productId;

            if ($row['variant_id'] !== null) {
                $variantId = (int) $row['variant_id'];
                if ($variantId > 0) {
                    $variantIds[$variantId] = $variantId;
                }
            }
        }

        if ($productIds === []) {
            return [];
        }

        try {
            $products = customerCartFetchProducts($pdo, $productIds);
            $variants = $variantIds !== [] ? customerCartFetchVariants($pdo, $variantIds) : [];
        } catch (Throwable $exception) {
            error_log('Unable to hydrate customer cart items: ' . $exception->getMessage());
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0 || !isset($products[$productId])) {
                continue;
            }

            $quantity = (int) ($row['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $variantId = $row['variant_id'] !== null ? (int) $row['variant_id'] : null;
            $variant = null;
            if ($variantId !== null) {
                if (!isset($variants[$variantId])) {
                    continue;
                }
                $variant = $variants[$variantId];
                if ($variant['product_id'] !== $productId) {
                    continue;
                }
            }

            $product = $products[$productId];
            $productName = $product['name'] !== '' ? $product['name'] : 'Product';
            $basePrice = (float) ($product['price'] ?? 0);

            $variantLabel = '';
            $variantPrice = $basePrice;
            if ($variant !== null) {
                $variantLabel = $variant['label'] ?? '';
                $variantVariantPrice = (float) ($variant['price'] ?? 0);
                if ($variantVariantPrice > 0) {
                    $variantPrice = $variantVariantPrice;
                }
            }

            if ($basePrice <= 0) {
                $basePrice = $variantPrice;
            }

            $items[] = [
                'id' => $productId,
                'name' => $productName,
                'price' => $basePrice,
                'quantity' => min(999, $quantity),
                'variantId' => $variantId,
                'variantLabel' => $variantLabel,
                'variantPrice' => $variantPrice,
            ];
        }

        return $items;
    }
}

if (!function_exists('customerCartReplace')) {
    /**
     * Replace the persisted customer cart with the provided items.
     */
    function customerCartReplace(PDO $pdo, int $customerId, array $items): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $normalized = customerCartNormalisePayloadItems($items);

        if ($normalized === []) {
            try {
                $stmt = $pdo->prepare('DELETE FROM customer_cart_items WHERE customer_id = ?');
                $stmt->execute([$customerId]);
            } catch (Throwable $exception) {
                error_log('Unable to clear customer cart: ' . $exception->getMessage());
            }

            return [];
        }

        $productIds = [];
        $variantIds = [];
        foreach ($normalized as $item) {
            $productIds[$item['product_id']] = $item['product_id'];
            if ($item['variant_id'] !== null) {
                $variantIds[$item['variant_id']] = $item['variant_id'];
            }
        }

        try {
            $products = customerCartFetchProducts($pdo, $productIds);
            $variants = $variantIds !== [] ? customerCartFetchVariants($pdo, $variantIds) : [];
        } catch (Throwable $exception) {
            error_log('Unable to validate customer cart items: ' . $exception->getMessage());
            return customerCartFetch($pdo, $customerId);
        }

        $valid = [];
        foreach ($normalized as $item) {
            $productId = $item['product_id'];
            if (!isset($products[$productId])) {
                continue;
            }

            $variantId = $item['variant_id'];
            if ($variantId !== null) {
                if (!isset($variants[$variantId])) {
                    continue;
                }
                if ($variants[$variantId]['product_id'] !== $productId) {
                    continue;
                }
            }

            $valid[] = $item;
        }

        try {
            $pdo->beginTransaction();

            $deleteStmt = $pdo->prepare('DELETE FROM customer_cart_items WHERE customer_id = ?');
            $deleteStmt->execute([$customerId]);

            if ($valid !== []) {
                $insertStmt = $pdo->prepare('INSERT INTO customer_cart_items (customer_id, product_id, variant_id, quantity) VALUES (?, ?, ?, ?)');
                foreach ($valid as $item) {
                    $insertStmt->execute([
                        $customerId,
                        $item['product_id'],
                        $item['variant_id'],
                        $item['quantity'],
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Unable to save customer cart: ' . $exception->getMessage());

            return customerCartFetch($pdo, $customerId);
        }

        return customerCartFetch($pdo, $customerId);
    }
}
