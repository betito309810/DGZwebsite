<?php
/**
 * Shared helpers for fetching stock receipt data and formatting values.
 */

if (!function_exists('tableHasColumn')) {
    /**
     * Quickly determine whether a table contains a specific column. Result is cached per request.
     */
    function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower($table) . ':' . strtolower($column);

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column
                 LIMIT 1'
            );
            if ($stmt && $stmt->execute([
                ':table' => $table,
                ':column' => $column,
            ])) {
                $cache[$key] = $stmt->fetchColumn() !== false;
            } else {
                $cache[$key] = false;
            }
        } catch (Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}

if (!function_exists('loadStockReceiptWithItems')) {
    /**
     * Load a single stock receipt with its header, items, attachments, and audit log.
     */
    function loadStockReceiptWithItems(PDO $pdo, int $receiptId): ?array
    {
        $headerSql = '
            SELECT
                sr.*,
                creator.name AS created_by_name,
                updater.name AS updated_by_name,
                receiver.name AS received_by_name,
                poster.name AS posted_by_name
            FROM stock_receipts sr
            LEFT JOIN users creator ON creator.id = sr.created_by_user_id
            LEFT JOIN users updater ON updater.id = sr.updated_by_user_id
            LEFT JOIN users receiver ON receiver.id = sr.received_by_user_id
            LEFT JOIN users poster ON poster.id = sr.posted_by_user_id
            WHERE sr.id = :receipt_id
        ';
        $stmt = $pdo->prepare($headerSql);
        $stmt->execute([':receipt_id' => $receiptId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            return null;
        }

        $hasVariantId = tableHasColumn($pdo, 'stock_receipt_items', 'variant_id');
        $itemsSelectExtras = '';
        $itemsJoins = '';

        if ($hasVariantId) {
            $itemsSelectExtras .= ', pv.label AS variant_current_label';
            $itemsJoins .= ' LEFT JOIN product_variants pv ON pv.id = sri.variant_id';
        }

        $itemsSql = '
            SELECT
                sri.*, p.name AS product_name, p.code AS product_code' . $itemsSelectExtras . '
            FROM stock_receipt_items sri
            LEFT JOIN products p ON p.id = sri.product_id' . $itemsJoins . '
            WHERE sri.receipt_id = :receipt_id
            ORDER BY sri.id ASC
        ';
        $stmt = $pdo->prepare($itemsSql);
        $stmt->execute([':receipt_id' => $receiptId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($hasVariantId && !empty($items)) {
            foreach ($items as &$itemRow) {
                if (!empty($itemRow['variant_id']) && empty($itemRow['variant_label']) && !empty($itemRow['variant_current_label'])) {
                    $itemRow['variant_label'] = $itemRow['variant_current_label'];
                }
                if (array_key_exists('variant_current_label', $itemRow)) {
                    unset($itemRow['variant_current_label']);
                }
            }
            unset($itemRow);
        }

        $filesSql = '
            SELECT id, file_path, original_name, mime_type, created_at
            FROM stock_receipt_files
            WHERE receipt_id = :receipt_id
            ORDER BY created_at ASC
        ';
        $stmt = $pdo->prepare($filesSql);
        $stmt->execute([':receipt_id' => $receiptId]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $auditSql = '
            SELECT log.*, users.name AS action_by_name
            FROM stock_receipt_audit_log log
            LEFT JOIN users ON users.id = log.action_by_user_id
            WHERE log.receipt_id = :receipt_id
            ORDER BY log.action_at ASC
        ';
        $stmt = $pdo->prepare($auditSql);
        $stmt->execute([':receipt_id' => $receiptId]);
        $audit = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'header' => $header,
            'items' => $items,
            'attachments' => $attachments,
            'audit' => $audit,
        ];
    }
}

if (!function_exists('loadStockReceiptByCode')) {
    /**
     * Fetch a stock receipt using its unique receipt code for quick lookups.
     */
    function loadStockReceiptByCode(PDO $pdo, string $receiptCode): ?array
    {
        $stmt = $pdo->prepare('SELECT id FROM stock_receipts WHERE receipt_code = :code');
        $stmt->execute([':code' => $receiptCode]);
        $receiptId = (int)$stmt->fetchColumn();
        return $receiptId > 0 ? loadStockReceiptWithItems($pdo, $receiptId) : null;
    }
}

if (!function_exists('formatStockReceiptStatus')) {
    /**
     * Convert status codes into readable labels for UI.
     */
    function formatStockReceiptStatus(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'with_discrepancy' => 'With Discrepancy',
            'posted' => 'Posted',
            default => ucfirst($status),
        };
    }
}

if (!function_exists('formatStockReceiptDateTime')) {
    /**
     * Format a timestamp into a friendly string; returns empty when null.
     */
    function formatStockReceiptDateTime(?string $datetime): string
    {
        if (empty($datetime)) {
            return '';
        }
        $timestamp = strtotime($datetime);
        return $timestamp ? date('M d, Y g:i A', $timestamp) : '';
    }
}
