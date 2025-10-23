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
                $newUserId = (int) $pdo->lastInsertId();
                $creationMessage = sprintf('Created %s account for %s', $newRole, $name);
                if ($email !== '') {
                    $creationMessage .= ' (' . $email . ')';
                }

                recordSystemLog(
                    $pdo,
                    'user_created',
                    $creationMessage,
                    (int) ($_SESSION['user_id'] ?? 0)
                );
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

                $stmt = $pdo->prepare('SELECT role, deleted_at, name, email FROM users WHERE id = ? FOR UPDATE');
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
                        $toggleStmt = $pdo->prepare('UPDATE users SET deleted_at = NOW(), current_session_token = NULL WHERE id = ?');
                        $toggleStmt->execute([$userId]);
                    } else {
                        $toggleStmt = $pdo->prepare('UPDATE users SET deleted_at = NULL, current_session_token = NULL WHERE id = ?');
                        $toggleStmt->execute([$userId]);
                    }
                    $pdo->commit();

                    $userManagementSuccess = ($action === 'deactivate')
                        ? 'Staff account deactivated successfully.'
                        : 'Staff account reactivated successfully.';
                    $event = $action === 'deactivate' ? 'staff_deactivated' : 'staff_reactivated';
                    $message = $action === 'deactivate'
                        ? 'Staff account deactivated'
                        : 'Staff account reactivated';

                    $targetDetails = [];
                    if (!empty($userToToggle['name'])) {
                        $targetDetails[] = (string) $userToToggle['name'];
                    }
                    if (!empty($userToToggle['email'])) {
                        $targetDetails[] = (string) $userToToggle['email'];
                    }

                    $messageParts = [$message];
                    if ($targetDetails !== []) {
                        $messageParts[] = implode(' · ', $targetDetails);
                    }

                    $finalMessage = implode(': ', $messageParts);

                    recordSystemLog(
                        $pdo,
                        $event,
                        $finalMessage,
                        (int) ($_SESSION['user_id'] ?? 0)
                    );
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $userManagementError = 'Failed to update staff account status: ' . $e->getMessage();
            }
        }
    }

    $userManagementUsers = $pdo
        ->query('SELECT id, name, email, contact_number, role, created_at, deleted_at FROM users ORDER BY created_at DESC')
        ->fetchAll(PDO::FETCH_ASSOC);
}
