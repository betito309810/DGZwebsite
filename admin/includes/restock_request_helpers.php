<?php
/**
 * Helpers shared between inventory-related pages for managing restock requests.
 */

if (!function_exists('restockTableHasColumn')) {
    /**
     * Check whether the given table already contains the specified column.
     */
    function restockTableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column
                 LIMIT 1'
            );

            if (!$stmt) {
                return false;
            }

            if ($stmt->execute([
                ':table' => $table,
                ':column' => $column,
            ])) {
                return $stmt->fetchColumn() !== false;
            }
        } catch (Throwable $e) {
            error_log('restockTableHasColumn failed: ' . $e->getMessage());
        }

        return false;
    }
}

if (!function_exists('ensureRestockVariantColumns')) {
    /**
     * Add variant tracking columns to restock_requests when they are missing.
     */
    function ensureRestockVariantColumns(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'restock_requests'");
            if (!$tableCheck || $tableCheck->fetchColumn() === false) {
                return;
            }

            if (!restockTableHasColumn($pdo, 'restock_requests', 'variant_id')) {
                $pdo->exec('ALTER TABLE restock_requests ADD COLUMN variant_id INT NULL AFTER product_id');
            }

            if (!restockTableHasColumn($pdo, 'restock_requests', 'variant_label')) {
                $pdo->exec('ALTER TABLE restock_requests ADD COLUMN variant_label VARCHAR(255) NULL AFTER variant_id');
            }
        } catch (Throwable $e) {
            error_log('Unable to ensure restock variant columns: ' . $e->getMessage());
        }
    }
}
