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

if (!function_exists('pdoErrorMatchesState')) {
    /**
     * Check whether a PDO exception matches any of the provided SQLSTATE codes.
     *
     * @param PDOException $exception
     * @param string[]|string $states
     */
    function pdoErrorMatchesState(PDOException $exception, $states): bool
    {
        $targetStates = is_array($states) ? $states : [$states];
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        if (!is_string($sqlState) || $sqlState === '') {
            return false;
        }

        foreach ($targetStates as $state) {
            if (strtoupper((string) $state) === strtoupper($sqlState)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('pdoErrorIndicatesMissingSchema')) {
    /**
     * Helper to detect missing-table or missing-column SQLSTATE values.
     */
    function pdoErrorIndicatesMissingSchema(PDOException $exception): bool
    {
        return pdoErrorMatchesState($exception, ['42S02', '42S22']);
    }
}

if (!function_exists('pdoErrorIndicatesConstraintFailure')) {
    /**
     * Helper to detect integrity constraint violations (e.g. foreign key failures).
     */
    function pdoErrorIndicatesConstraintFailure(PDOException $exception): bool
    {
        return pdoErrorMatchesState($exception, ['23000']);
    }
}

if (!function_exists('columnAllowsNull')) {
    /**
     * Determine whether a table column accepts NULL assignments.
     */
    function columnAllowsNull(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower($table) . '.' . strtolower($column);

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT IS_NULLABLE
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1'
            );

            if ($stmt && $stmt->execute([$table, $column])) {
                $value = $stmt->fetchColumn();
                $cache[$key] = strtoupper((string) $value) === 'YES';
                return $cache[$key];
            }
        } catch (Throwable $error) {
            error_log('columnAllowsNull lookup failed: ' . $error->getMessage());
        }

        $cache[$key] = false;
        return false;
    }
}

if (!function_exists('fetchUserForeignKeyReferences')) {
    /**
     * Inspect INFORMATION_SCHEMA to identify tables that reference users.id.
     */
    function fetchUserForeignKeyReferences(PDO $pdo): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        try {
            $sql = "
                SELECT
                    kcu.TABLE_NAME,
                    kcu.COLUMN_NAME,
                    rc.DELETE_RULE
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS kcu
                JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS AS rc
                  ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                 AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                WHERE kcu.TABLE_SCHEMA = DATABASE()
                  AND kcu.REFERENCED_TABLE_SCHEMA = DATABASE()
                  AND kcu.REFERENCED_TABLE_NAME = 'users'
                  AND kcu.REFERENCED_COLUMN_NAME = 'id'
            ";

            $stmt = $pdo->query($sql);
            $cache = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $error) {
            error_log('Unable to inspect user foreign keys: ' . $error->getMessage());
            $cache = [];
        }

        return $cache;
    }
}

if (!function_exists('releaseUserForeignKeyReferences')) {
    /**
     * Clear or delete referencing rows for tables that block user deletion.
     */
    function releaseUserForeignKeyReferences(PDO $pdo, int $userId, array $skipTables = []): void
    {
        if ($userId <= 0) {
            return;
        }

        $skipLookup = [];
        foreach ($skipTables as $tableName) {
            $skipLookup[strtolower($tableName)] = true;
        }

        $references = fetchUserForeignKeyReferences($pdo);
        if (empty($references)) {
            return;
        }

        foreach ($references as $reference) {
            $table = (string) ($reference['TABLE_NAME'] ?? '');
            $column = (string) ($reference['COLUMN_NAME'] ?? '');
            $deleteRule = strtoupper((string) ($reference['DELETE_RULE'] ?? ''));

            if ($table === '' || $column === '') {
                continue;
            }

            if (isset($skipLookup[strtolower($table)])) {
                continue;
            }

            if (in_array($deleteRule, ['CASCADE', 'SET NULL'], true)) {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                error_log(sprintf('Skipping cleanup for %s.%s due to unsafe identifier.', $table, $column));
                continue;
            }

            $qualifiedTable = '`' . $table . '`';
            $qualifiedColumn = '`' . $column . '`';

            $resolved = false;

            if (columnAllowsNull($pdo, $table, $column)) {
                try {
                    $update = $pdo->prepare("UPDATE $qualifiedTable SET $qualifiedColumn = NULL WHERE $qualifiedColumn = ?");
                    if ($update) {
                        $update->execute([$userId]);
                        $resolved = true;
                    }
                } catch (PDOException $updateError) {
                    if (pdoErrorIndicatesMissingSchema($updateError)) {
                        continue;
                    }

                    if (!pdoErrorIndicatesConstraintFailure($updateError)) {
                        throw $updateError;
                    }
                    // Otherwise fall through to delete attempt.
                }
            }

            if ($resolved) {
                continue;
            }

            try {
                $delete = $pdo->prepare("DELETE FROM $qualifiedTable WHERE $qualifiedColumn = ?");
                if ($delete) {
                    $delete->execute([$userId]);
                }
            } catch (PDOException $deleteError) {
                if (pdoErrorIndicatesMissingSchema($deleteError)) {
                    continue;
                }

                throw $deleteError;
            }
        }
    }
}


if (!isset($role)) {
    $role = $_SESSION['role'] ?? '';
}

$userManagementSuccess = $userManagementSuccess ?? null;
$userManagementError = $userManagementError ?? null;
$userManagementUsers = $userManagementUsers ?? [];

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user_status'])) {
        $userId = filter_input(INPUT_POST, 'toggle_user_id', FILTER_VALIDATE_INT);
        $action = $_POST['toggle_action'] ?? '';

        if (!$userId) {
            $userManagementError = 'Invalid user selection.';
        } elseif (!in_array($action, ['activate', 'deactivate'], true)) {
            $userManagementError = 'Unknown account action requested.';
        } elseif ((int) $_SESSION['user_id'] === $userId) {
            $userManagementError = 'You cannot change the status of your own account.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT role, deleted_at FROM users WHERE id = ? FOR UPDATE');
                $stmt->execute([$userId]);
                $userToToggle = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$userToToggle) {
                    $pdo->rollBack();
                    $userManagementError = 'The selected user no longer exists.';
                } elseif ($userToToggle['role'] !== 'staff') {
                    $pdo->rollBack();
                    $userManagementError = 'Only staff accounts can be activated or deactivated.';
                } elseif ($action === 'deactivate' && !empty($userToToggle['deleted_at'])) {
                    $pdo->rollBack();
                    $userManagementError = 'The staff account is already deactivated.';
                } elseif ($action === 'activate' && empty($userToToggle['deleted_at'])) {
                    $pdo->rollBack();
                    $userManagementError = 'The staff account is already active.';
                } else {
                    if ($action === 'deactivate') {
                        $toggleStmt = $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ?');
                        $toggleStmt->execute([$userId]);
                    } else {
                        $toggleStmt = $pdo->prepare('UPDATE users SET deleted_at = NULL WHERE id = ?');
                        $toggleStmt->execute([$userId]);
                    }
                    $pdo->commit();

                    $userManagementSuccess = ($action === 'deactivate')
                        ? 'Staff account deactivated successfully.'
                        : 'Staff account reactivated successfully.';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $userManagementError = 'Failed to update staff account status: ' . $e->getMessage();
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
            $foreignKeysTemporarilyDisabled = false;

            try {
                $pdo->beginTransaction();

                try {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    $foreignKeysTemporarilyDisabled = true;
                } catch (Throwable $toggleError) {
                    error_log('Unable to disable foreign key checks prior to user delete: ' . $toggleError->getMessage());
                }

                $stmt = $pdo->prepare('SELECT id, role, name FROM users WHERE id = ? FOR UPDATE');
                $stmt->execute([$userId]);
                $userToDelete = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$userToDelete) {
                    $pdo->rollBack();
                    $userManagementError = 'The selected user no longer exists.';
                } elseif (($userToDelete['role'] ?? '') !== 'staff') {
                    $pdo->rollBack();
                    $userManagementError = 'Only staff accounts can be deleted permanently.';
                } else {
                    $cleanupStatements = [
                        'password_resets' => 'DELETE FROM password_resets WHERE user_id = ?',
                    ];

                    foreach ($cleanupStatements as $tableName => $sql) {
                        try {
                            $cleanupStmt = $pdo->prepare($sql);
                            if ($cleanupStmt) {
                                $cleanupStmt->execute([$userId]);
                            }
                        } catch (PDOException $cleanupError) {
                            if (pdoErrorIndicatesMissingSchema($cleanupError)) {
                                continue;
                            }

                            throw $cleanupError;
                        }
                    }

                    releaseUserForeignKeyReferences($pdo, $userId, array_keys($cleanupStatements));

                    $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                    $deleteStmt->execute([$userId]);

                    $pdo->commit();

                    $deletedName = trim((string) ($userToDelete['name'] ?? ''));
                    $userManagementSuccess = $deletedName !== ''
                        ? sprintf('Staff account "%s" deleted permanently.', $deletedName)
                        : 'Staff account deleted permanently.';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('User delete failed: ' . $e->getMessage());
                $userManagementError = 'Failed to delete staff account. Please try again.';
            } finally {
                if (!empty($foreignKeysTemporarilyDisabled)) {
                    try {
                        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    } catch (Throwable $toggleError) {
                        error_log('Unable to re-enable foreign key checks after user delete: ' . $toggleError->getMessage());
                    }
                }
            }
        }
    }

    $userManagementUsers = $pdo
        ->query('SELECT id, name, email, contact_number, role, created_at, deleted_at FROM users ORDER BY created_at DESC')
        ->fetchAll(PDO::FETCH_ASSOC);
}
