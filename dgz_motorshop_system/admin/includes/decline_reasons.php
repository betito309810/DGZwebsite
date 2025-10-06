<?php
/**
 * Helper functions for managing reusable decline reasons.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';

if (!function_exists('ensureOrderDeclineSchema')) {
    /**
     * Ensure the supporting table/columns for decline reasons exist.
     */
    function ensureOrderDeclineSchema(PDO $pdo): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS order_decline_reasons (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    label VARCHAR(255) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e) {
            error_log('Unable to ensure order_decline_reasons table: ' . $e->getMessage());
        }

        try {
            $hasReasonId = $pdo->query("SHOW COLUMNS FROM orders LIKE 'decline_reason_id'");
            if ($hasReasonId === false || $hasReasonId->fetch() === false) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN decline_reason_id INT NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log('Unable to ensure orders.decline_reason_id column: ' . $e->getMessage());
        }

        try {
            $hasReasonNote = $pdo->query("SHOW COLUMNS FROM orders LIKE 'decline_reason_note'");
            if ($hasReasonNote === false || $hasReasonNote->fetch() === false) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN decline_reason_note TEXT NULL");
            }
        } catch (Throwable $e) {
            error_log('Unable to ensure orders.decline_reason_note column: ' . $e->getMessage());
        }

        $ensured = true;
    }
}

if (!function_exists('fetchOrderDeclineReasons')) {
    /**
     * Fetch all active decline reasons sorted alphabetically.
     */
    function fetchOrderDeclineReasons(PDO $pdo): array
    {
        ensureOrderDeclineSchema($pdo);

        try {
            $stmt = $pdo->query(
                'SELECT id, label FROM order_decline_reasons ORDER BY label ASC'
            );

            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            error_log('Unable to load decline reasons: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('createOrderDeclineReason')) {
    /**
     * Insert a new decline reason entry.
     */
    function createOrderDeclineReason(PDO $pdo, string $label): ?array
    {
        ensureOrderDeclineSchema($pdo);

        $trimmed = trim($label);
        if ($trimmed === '') {
            return null;
        }

        try {
            $existing = findOrderDeclineReasonByLabel($pdo, $trimmed);
            if ($existing !== null) {
                return [
                    'id' => (int) ($existing['id'] ?? 0),
                    'label' => $existing['label'] ?? $trimmed,
                ];
            }

            $insert = $pdo->prepare(
                'INSERT INTO order_decline_reasons (label) VALUES (?)'
            );
            $insert->execute([$trimmed]);

            $id = (int) $pdo->lastInsertId();

            return [
                'id' => $id,
                'label' => $trimmed,
            ];
        } catch (Throwable $e) {
            error_log('Unable to create decline reason: ' . $e->getMessage());

            return null;
        }
    }
}

if (!function_exists('updateOrderDeclineReason')) {
    /**
     * Update an existing decline reason label.
     */
    function updateOrderDeclineReason(PDO $pdo, int $reasonId, string $label): bool
    {
        ensureOrderDeclineSchema($pdo);

        $trimmed = trim($label);
        if ($reasonId <= 0 || $trimmed === '') {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                'UPDATE order_decline_reasons SET label = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );

            return $stmt->execute([$trimmed, $reasonId]);
        } catch (Throwable $e) {
            error_log('Unable to update decline reason: ' . $e->getMessage());

            return false;
        }
    }
}

if (!function_exists('findOrderDeclineReason')) {
    /**
     * Retrieve a single decline reason by id.
     */
    function findOrderDeclineReason(PDO $pdo, int $reasonId): ?array
    {
        ensureOrderDeclineSchema($pdo);

        if ($reasonId <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT id, label, is_active FROM order_decline_reasons WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$reasonId]);

            $row = $stmt->fetch();

            return $row ?: null;
        } catch (Throwable $e) {
            error_log('Unable to find decline reason: ' . $e->getMessage());

            return null;
        }
    }
}

if (!function_exists('findOrderDeclineReasonByLabel')) {
    /**
     * Locate a decline reason using its label (case-insensitive).
     */
    function findOrderDeclineReasonByLabel(PDO $pdo, string $label): ?array
    {
        ensureOrderDeclineSchema($pdo);

        $trimmed = trim($label);
        if ($trimmed === '') {
            return null;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT id, label, is_active FROM order_decline_reasons WHERE LOWER(label) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([$trimmed]);

            $row = $stmt->fetch();

            return $row ?: null;
        } catch (Throwable $e) {
            error_log('Unable to find decline reason by label: ' . $e->getMessage());

            return null;
        }
    }
}

if (!function_exists('deleteOrderDeclineReason')) {
    /**
     * Permanently delete a decline reason and clear references from orders.
     */
    function deleteOrderDeclineReason(PDO $pdo, int $reasonId): bool
    {
        ensureOrderDeclineSchema($pdo);

        if ($reasonId <= 0) {
            return false;
        }

        try {
            $pdo->beginTransaction();

            $clearStmt = $pdo->prepare('UPDATE orders SET decline_reason_id = NULL WHERE decline_reason_id = ?');
            $clearStmt->execute([$reasonId]);

            $deleteStmt = $pdo->prepare('DELETE FROM order_decline_reasons WHERE id = ?');
            $deleteStmt->execute([$reasonId]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Unable to delete decline reason: ' . $e->getMessage());

            return false;
        }
    }
}
