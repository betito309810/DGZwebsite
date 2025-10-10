<?php
// Added: helper utilities for loading, normalising, and summarising product variant data.

if (!function_exists('fetchProductVariants')) {
    /**
     * Load all variants for a given product ordered by sort priority and creation date.
     */
    function fetchProductVariants(PDO $pdo, int $productId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, product_id, label, sku, price, quantity, is_default, sort_order, created_at, updated_at
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

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, product_id, label, sku, price, quantity, is_default, sort_order, created_at, updated_at
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
            $price = isset($variant['price']) ? (float) $variant['price'] : 0.0;
            $isDefault = !empty($variant['is_default']);

            $totalQuantity += max(0, $quantity);
            if ($fallbackPrice === null) {
                $fallbackPrice = $price;
            }
            if ($isDefault) {
                $defaultPrice = $price;
            }
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
            $normalised[] = [
                'id' => isset($raw['id']) ? (int) $raw['id'] : null,
                'label' => $label,
                'sku' => trim((string) ($raw['sku'] ?? '')) ?: null,
                'price' => isset($raw['price']) ? max(0, (float) $raw['price']) : 0.0,
                'quantity' => isset($raw['quantity']) ? max(0, (int) $raw['quantity']) : 0,
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
