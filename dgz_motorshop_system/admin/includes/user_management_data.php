<?php
/**
 * Shared user management form handling and data loading.
 *
 * Expects the following variables to be available in the scope where this file is included:
 *   - PDO $pdo: database connection
 *   - string $role: current authenticated user's role
 *
 * Provides the following variables:
 *   - ?string $userManagementSuccess: success message after performing an action
 *   - ?string $userManagementError: error message after performing an action
 *   - array $userManagementUsers: list of users for display
 */

if (!isset($pdo)) {
    throw new RuntimeException('User management data requires an initialized $pdo connection.');
}


if (!isset($role)) {
    $role = $_SESSION['role'] ?? '';
}

$userManagementSuccess = $userManagementSuccess ?? null;
$userManagementError = $userManagementError ?? null;
$userManagementUsers = $userManagementUsers ?? [];

if (!function_exists('findUserForeignKeyReferences')) {
    /**
     * Return a list of foreign key columns that currently reference the provided user id.
     */
    function findUserForeignKeyReferences(PDO $pdo, int $userId, ?bool &$lookupFailed = null): array
    {
        $lookupFailed = false;

        try {
            $stmt = $pdo->query(
                "SELECT TABLE_NAME, COLUMN_NAME, TABLE_SCHEMA\n                 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE\n                 WHERE REFERENCED_TABLE_SCHEMA = DATABASE()\n                   AND REFERENCED_TABLE_NAME = 'users'"
            );
            $references = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $lookupFailed = true;
            return [];
        }

        $blocking = [];

        foreach ($references as $reference) {
            $table = $reference['TABLE_NAME'] ?? '';
            $column = $reference['COLUMN_NAME'] ?? '';
            $schema = $reference['TABLE_SCHEMA'] ?? '';

            if ($table === '' || $column === '' || strcasecmp($table, 'users') === 0) {
                continue;
            }

            $tableIdentifier = '`' . str_replace('`', '``', $table) . '`';
            $columnIdentifier = '`' . str_replace('`', '``', $column) . '`';
            $schemaIdentifier = $schema !== '' ? ('`' . str_replace('`', '``', $schema) . '`.') : '';

            $sql = 'SELECT 1 FROM ' . $schemaIdentifier . $tableIdentifier . ' WHERE ' . $columnIdentifier . ' = ? LIMIT 1';

            try {
                $checkStmt = $pdo->prepare($sql);
                $checkStmt->execute([$userId]);

                if ($checkStmt->fetchColumn() !== false) {
                    $blocking[] = sprintf('%s.%s', $table, $column);
                }
            } catch (Throwable $e) {
                $lookupFailed = true;
                continue;
            }
        }

        return $blocking;
    }
}

if (!function_exists('formatUserForeignKeyReferenceLabels')) {
    /**
     * Provide human friendly labels for known foreign key references while still exposing
     * the underlying table/column pairing for any unexpected dependencies.
     */
    function formatUserForeignKeyReferenceLabels(array $references): array
    {
        if (empty($references)) {
            return [];
        }

        $labels = [
            'inventory_ledger.created_by_user_id' => 'Inventory ledger entries (created by)',
            'restock_request_history.noted_by' => 'Restock request history (noted by)',
            'restock_requests.requested_by' => 'Restock requests (requested by)',
            'stock_receipt_audit_log.action_by_user_id' => 'Stock receipt audit log (action by)',
            'stock_receipt_files.uploaded_by_user_id' => 'Stock receipt files (uploaded by)',
            'stock_receipts.created_by_user_id' => 'Stock receipts (created by)',
            'stock_receipts.updated_by_user_id' => 'Stock receipts (updated by)',
            'stock_receipts.received_by_user_id' => 'Stock receipts (received by)',
            'stock_receipts.posted_by_user_id' => 'Stock receipts (posted by)',
        ];

        $formatted = [];

        foreach ($references as $reference) {
            $referenceKey = strtolower($reference);

            if (isset($labels[$referenceKey])) {
                $formatted[] = $labels[$referenceKey];
                continue;
            }

            [$table, $column] = array_pad(explode('.', $reference, 2), 2, '');
            $tableLabel = $table !== '' ? ucwords(str_replace('_', ' ', $table)) : 'Unknown table';
            $columnLabel = $column !== '' ? strtolower(str_replace('_', ' ', $column)) : 'related data';

            $formatted[] = sprintf('%s (%s)', $tableLabel, $columnLabel);
        }

        return $formatted;
    }
}

if ($role === 'admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $newRole = $_POST['role'] ?? 'staff';
        $newRole = in_array($newRole, ['admin', 'staff'], true) ? $newRole : 'staff';

        if ($name === '') {
            $userManagementError = 'Name is required.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $userManagementError = 'A valid email is required.';
        } elseif ($password === '') {
            $userManagementError = 'Password is required.';
        } elseif ($password !== $confirmPassword) {
            $userManagementError = 'Passwords do not match.';
        } else {
            try {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'INSERT INTO users (name, email, password, contact_number, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([
                    $name,
                    $email,
                    $hashedPassword,
                    $contact !== '' ? $contact : null,
                    $newRole
                ]);
                $userManagementSuccess = 'New user account created successfully.';
            } catch (Exception $e) {
                $userManagementError = 'Failed to add user: ' . $e->getMessage();
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
        $userId = filter_input(INPUT_POST, 'delete_user_id', FILTER_VALIDATE_INT);

        if (!$userId) {
            $userManagementError = 'Invalid user selection.';
        } elseif ((int) $_SESSION['user_id'] === $userId) {
            $userManagementError = 'You cannot delete your own account.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT role, deleted_at FROM users WHERE id = ? FOR UPDATE');
                $stmt->execute([$userId]);
                $userToDelete = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$userToDelete) {
                    $pdo->rollBack();
                    $userManagementError = 'The selected user no longer exists.';
                } elseif ($userToDelete['role'] !== 'staff') {
                    $pdo->rollBack();
                    $userManagementError = 'Only staff accounts can be removed.';
                } elseif (!empty($userToDelete['deleted_at'])) {
                    $pdo->rollBack();
                    $userManagementError = 'This staff account is already deactivated.';
                } else {
                    $deleteStmt = $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
                    $deleteStmt->execute([$userId]);
                    $pdo->commit();
                    $userManagementSuccess = 'Staff account deactivated successfully.';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $userManagementError = 'Failed to deactivate staff account: ' . $e->getMessage();
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purge_user'])) {
        $userId = filter_input(INPUT_POST, 'purge_user_id', FILTER_VALIDATE_INT);

        if (!$userId) {
            $userManagementError = 'Invalid user selection.';
        } elseif ((int) $_SESSION['user_id'] === $userId) {
            $userManagementError = 'You cannot delete your own account.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT role, deleted_at FROM users WHERE id = ? FOR UPDATE');
                $stmt->execute([$userId]);
                $userToDelete = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$userToDelete) {
                    $pdo->rollBack();
                    $userManagementError = 'The selected user no longer exists.';
                } elseif ($userToDelete['role'] !== 'staff') {
                    $pdo->rollBack();
                    $userManagementError = 'Only staff accounts can be removed.';
                } elseif (empty($userToDelete['deleted_at'])) {
                    $pdo->rollBack();
                    $userManagementError = 'Deactivate this staff account before permanently deleting it.';
                } else {
                    $lookupFailed = false;
                    $blockingReferences = findUserForeignKeyReferences($pdo, $userId, $lookupFailed);

                    if (!$lookupFailed && !empty($blockingReferences)) {
                        $pdo->rollBack();
                        $friendlyReferences = formatUserForeignKeyReferenceLabels($blockingReferences);
                        $userManagementError = 'Cannot permanently delete this staff account because it is still referenced by other records ('
                            . implode(', ', $friendlyReferences)
                            . '). Reassign or anonymize those records before trying again.';
                    } else {
                        try {
                            $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = ? LIMIT 1');
                            $deleteStmt->execute([$userId]);

                            if ($deleteStmt->rowCount() < 1) {
                                $pdo->rollBack();
                                $userManagementError = 'The selected user no longer exists.';
                            } else {
                                $pdo->commit();
                                $userManagementSuccess = 'Staff account permanently removed.';
                            }
                        } catch (PDOException $deleteException) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            if ($deleteException->getCode() === '23000') {
                                $postFailureLookupFailed = false;
                                $blockingReferences = findUserForeignKeyReferences($pdo, $userId, $postFailureLookupFailed);

                                if (!empty($blockingReferences)) {
                                    $friendlyReferences = formatUserForeignKeyReferenceLabels($blockingReferences);
                                    $userManagementError = 'Cannot permanently delete this staff account because it is still referenced by other records ('
                                        . implode(', ', $friendlyReferences)
                                        . '). Reassign or anonymize those records before trying again.';
                                } else {
                                    $userManagementError = 'Cannot permanently delete this staff account because it is still referenced by other records. Reassign or anonymize those records before trying again.';
                                }
                            } else {
                                $userManagementError = 'Failed to permanently delete staff account: ' . $deleteException->getMessage();
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $userManagementError = 'Failed to permanently delete staff account: ' . $e->getMessage();
            }
        }
    }

    $activeUsers = $pdo
        ->query('SELECT id, name, email, contact_number, role, created_at, deleted_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC')
        ->fetchAll(PDO::FETCH_ASSOC);

    $inactiveUsers = $pdo
        ->query('SELECT id, name, email, contact_number, role, created_at, deleted_at FROM users WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, created_at DESC')
        ->fetchAll(PDO::FETCH_ASSOC);

    $userManagementUsers = array_merge($activeUsers, $inactiveUsers);
}


