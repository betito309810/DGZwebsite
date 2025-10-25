<?php
/**
 * Shared helpers that manage the optional products archive schema.
 */

if (!isset($GLOBALS['_DGZ_PRODUCTS_ARCHIVE_SCHEMA'])) {
    $GLOBALS['_DGZ_PRODUCTS_ARCHIVE_SCHEMA'] = [];
}

if (!function_exists('productsArchiveSchemaKey')) {
    function productsArchiveSchemaKey(PDO $pdo): string
    {
        if (function_exists('spl_object_id')) {
            return (string) spl_object_id($pdo);
        }

        return spl_object_hash($pdo);
    }
}

if (!function_exists('productsArchiveQualifiedColumn')) {
    function productsArchiveQualifiedColumn(string $alias = ''): string
    {
        $alias = trim($alias);

        if ($alias === '') {
            return 'is_archived';
        }

        if (substr($alias, -1) === '.') {
            $alias = substr($alias, 0, -1);
        }

        return rtrim($alias, '.') . '.is_archived';
    }
}

if (!function_exists('productsArchiveColumnExists')) {
    function productsArchiveColumnExists(PDO $pdo): bool
    {
        $key = productsArchiveSchemaKey($pdo);

        if (array_key_exists($key, $GLOBALS['_DGZ_PRODUCTS_ARCHIVE_SCHEMA'])) {
            return (bool) $GLOBALS['_DGZ_PRODUCTS_ARCHIVE_SCHEMA'][$key];
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_archived'");
            $exists = $stmt && $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            error_log('Unable to inspect products archive schema: ' . $exception->getMessage());
            $exists = false;
        }

        $GLOBALS['_DGZ_PRODUCTS_ARCHIVE_SCHEMA'][$key] = (bool) $exists;

        return (bool) $exists;
    }
}

if (!function_exists('productsArchiveEnsureSchema')) {
    function productsArchiveEnsureSchema(PDO $pdo): void
    {
        $key = productsArchiveSchemaKey($pdo);
        static $ensured = [];

        if (isset($ensured[$key])) {
            return;
        }
        $ensured[$key] = true;

        $hasArchiveFlag = productsArchiveColumnExists($pdo);

        if (!$hasArchiveFlag) {
            try {
                $pdo->exec("ALTER TABLE products ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity");
                $GLOBALS['_DGZ_PRODUCTS_ARCHIVE_SCHEMA'][$key] = true;
            } catch (PDOException $exception) {
                error_log('Unable to add products.is_archived column: ' . $exception->getMessage());
            }
        }

        try {
            $archivedAtStmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'archived_at'");
            $hasArchivedAt = $archivedAtStmt && $archivedAtStmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            error_log('Unable to inspect products.archived_at column: ' . $exception->getMessage());
            $hasArchivedAt = false;
        }

        if (!$hasArchivedAt) {
            try {
                $pdo->exec("ALTER TABLE products ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER is_archived");
            } catch (PDOException $exception) {
                error_log('Unable to add products.archived_at column: ' . $exception->getMessage());
            }
        }

        try {
            $indexStmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_archived'");
            $hasIndex = $indexStmt && $indexStmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            error_log('Unable to inspect products archive index: ' . $exception->getMessage());
            $hasIndex = false;
        }

        if (!$hasIndex) {
            try {
                $pdo->exec('CREATE INDEX idx_products_archived ON products (is_archived)');
            } catch (PDOException $exception) {
                $errorInfo = $exception->errorInfo ?? [];
                if (($errorInfo[1] ?? null) !== 1061) {
                    error_log('Unable to create products archive index: ' . $exception->getMessage());
                }
            }
        }
    }
}

if (!function_exists('productsArchiveActiveCondition')) {
    function productsArchiveActiveCondition(PDO $pdo, string $alias = '', bool $includeCatalogTaxonomy = false): string
    {
        $clauses = [];

        if (productsArchiveColumnExists($pdo)) {
            $column = productsArchiveQualifiedColumn($alias);
            $clauses[] = sprintf('(%1$s = 0 OR %1$s IS NULL)', $column);
        } else {
            $clauses[] = '1=1';
        }

        if ($includeCatalogTaxonomy) {
            $taxonomyClause = productsArchiveTaxonomyActiveCondition($pdo, $alias);
            if ($taxonomyClause !== '') {
                $clauses[] = $taxonomyClause;
            }
        }

        return implode(' AND ', $clauses);
    }
}

if (!function_exists('productsArchiveOnlyCondition')) {
    function productsArchiveOnlyCondition(PDO $pdo, string $alias = ''): string
    {
        if (!productsArchiveColumnExists($pdo)) {
            return '0=1';
        }

        $column = productsArchiveQualifiedColumn($alias);

        return sprintf('%s = 1', $column);
    }
}

if (!function_exists('productsArchiveTaxonomyActiveCondition')) {
    function productsArchiveTaxonomyActiveCondition(PDO $pdo, string $alias = ''): string
    {
        if (!function_exists('catalogTaxonomyFetchOptions')) {
            return '';
        }

        $archivedBrands = productsArchiveTaxonomyArchivedNames($pdo, 'brand');
        $archivedCategories = productsArchiveTaxonomyArchivedNames($pdo, 'category');

        if (empty($archivedBrands) && empty($archivedCategories)) {
            return '';
        }

        $alias = trim($alias);
        $prefix = $alias === '' ? '' : rtrim($alias, '.') . '.';

        $clauses = [];
        $buildClause = static function (array $values, string $column) use ($pdo, &$clauses): void {
            if (empty($values)) {
                return;
            }

            $normalizedColumn = sprintf('LOWER(TRIM(%s))', $column);
            $coalescedColumn = sprintf("COALESCE(%s, '')", $normalizedColumn);

            $quoted = [];
            foreach ($values as $value) {
                if (method_exists($pdo, 'quote')) {
                    $quotedValue = $pdo->quote($value);
                } else {
                    $quotedValue = "'" . str_replace("'", "''", $value) . "'";
                }

                if ($quotedValue === false) {
                    continue;
                }

                $quoted[] = $quotedValue;
            }

            if (empty($quoted)) {
                return;
            }

            $clauses[] = sprintf("(%s = '' OR %s NOT IN (%s))", $coalescedColumn, $normalizedColumn, implode(', ', $quoted));
        };

        $buildClause($archivedBrands, $prefix . 'brand');
        $buildClause($archivedCategories, $prefix . 'category');

        if (empty($clauses)) {
            return '';
        }

        return implode(' AND ', $clauses);
    }
}

if (!function_exists('productsArchiveTaxonomyArchivedFilter')) {
    function productsArchiveTaxonomyArchivedFilter(PDO $pdo, string $alias = ''): string
    {
        if (!function_exists('catalogTaxonomyFetchOptions')) {
            return '';
        }

        $archivedBrands = productsArchiveTaxonomyArchivedNames($pdo, 'brand');
        $archivedCategories = productsArchiveTaxonomyArchivedNames($pdo, 'category');

        if (empty($archivedBrands) && empty($archivedCategories)) {
            return '';
        }

        $alias = trim($alias);
        $prefix = $alias === '' ? '' : rtrim($alias, '.') . '.';

        $clauses = [];
        $buildClause = static function (array $values, string $column) use ($pdo, &$clauses): void {
            if (empty($values)) {
                return;
            }

            $normalizedColumn = sprintf('LOWER(TRIM(%s))', $column);
            $coalescedColumn = sprintf("COALESCE(%s, '')", $normalizedColumn);

            $quoted = [];
            foreach ($values as $value) {
                if (method_exists($pdo, 'quote')) {
                    $quotedValue = $pdo->quote($value);
                } else {
                    $quotedValue = "'" . str_replace("'", "''", $value) . "'";
                }

                if ($quotedValue === false) {
                    continue;
                }

                $quoted[] = $quotedValue;
            }

            if (empty($quoted)) {
                return;
            }

            $clauses[] = sprintf("(%s != '' AND %s IN (%s))", $coalescedColumn, $normalizedColumn, implode(', ', $quoted));
        };

        $buildClause($archivedBrands, $prefix . 'brand');
        $buildClause($archivedCategories, $prefix . 'category');

        if (empty($clauses)) {
            return '';
        }

        return implode(' OR ', $clauses);
    }
}

if (!function_exists('productsArchiveTaxonomyArchivedNames')) {
    function productsArchiveTaxonomyArchivedNames(PDO $pdo, string $type): array
    {
        static $cache = [];

        $normalizedType = strtolower(trim($type));
        if (!in_array($normalizedType, ['brand', 'category'], true)) {
            return [];
        }

        $cacheKey = productsArchiveSchemaKey($pdo) . ':taxonomy:' . $normalizedType;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        if (!function_exists('catalogTaxonomyFetchOptions')) {
            $cache[$cacheKey] = [];
            return [];
        }

        try {
            $rows = catalogTaxonomyFetchOptions($pdo, $normalizedType, true);
        } catch (Throwable $e) {
            $cache[$cacheKey] = [];
            return [];
        }

        $values = [];

        foreach ($rows as $row) {
            if ((int) ($row['is_archived'] ?? 0) !== 1) {
                continue;
            }

            $label = (string) ($row['name'] ?? '');
            if ($label === '') {
                continue;
            }

            if (function_exists('catalogTaxonomyNormaliseName')) {
                $normalized = catalogTaxonomyNormaliseName($label);
            } else {
                $normalized = strtolower(trim($label));
            }

            if ($normalized === '') {
                continue;
            }

            $values[$normalized] = true;

            $rawLower = strtolower(trim($label));
            if ($rawLower !== '') {
                $values[$rawLower] = true;
            }
        }

        $cache[$cacheKey] = array_keys($values);

        return $cache[$cacheKey];
    }
}
