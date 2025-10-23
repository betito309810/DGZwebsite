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
    function productsArchiveActiveCondition(PDO $pdo, string $alias = ''): string
    {
        if (!productsArchiveColumnExists($pdo)) {
            return '1=1';
        }

        $column = productsArchiveQualifiedColumn($alias);

        return sprintf('(%1$s = 0 OR %1$s IS NULL)', $column);
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
