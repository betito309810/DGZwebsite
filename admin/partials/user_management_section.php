<?php
/** @var ?string $userManagementSuccess */
/** @var ?string $userManagementError */
/** @var array $userManagementUsers */
/** @var bool $showUserManagementBackButton */

$showUserManagementBackButton = $showUserManagementBackButton ?? true;
?>
<?php
$userManagementFlashMessages = [];
if (!empty($userManagementSuccess)) {
    $userManagementFlashMessages[] = [
        'type' => 'success',
        'message' => (string) $userManagementSuccess,
    ];
}
if (!empty($userManagementError)) {
    $userManagementFlashMessages[] = [
        'type' => 'error',
        'message' => (string) $userManagementError,
    ];
}
?>
<?php if (!empty($userManagementFlashMessages)): ?>
    <div class="user-management-flash" data-user-management-flash hidden>
        <?php foreach ($userManagementFlashMessages as $flashMessage): ?>
            <div
                class="user-management-flash__message"
                data-user-toast-type="<?= htmlspecialchars($flashMessage['type'], ENT_QUOTES, 'UTF-8') ?>"
                data-user-toast-message="<?= htmlspecialchars($flashMessage['message'], ENT_QUOTES, 'UTF-8') ?>"
            ></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="user-management-wrapper">
    <div class="page-toolbar">
        <?php if ($showUserManagementBackButton): ?>
        <button type="button" class="secondary-action" id="backButton">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <?php endif; ?>
        <button id="toggleAddUser" class="primary-action" type="button">
            <i class="fas fa-user-plus"></i> Add New User
        </button>
    </div>

    <div class="content-grid">
        <section id="addUserSection" class="card user-card hidden">
            <h3><i class="fas fa-id-card"></i> New User Details</h3>
            <form method="post" class="user-form">
                <input type="hidden" name="add_user" value="1">
                <div class="form-row">
                    <label for="user_name">Name</label>
                    <input type="text" id="user_name" name="name" required>
                </div>
                <div class="form-row">
                    <label for="user_contact">Contact Number</label>
                    <input type="tel" id="user_contact" name="contact_number" placeholder="Optional">
                </div>
                <div class="form-row">
                    <label for="user_email">Email</label>
                    <input type="email" id="user_email" name="email" required>
                </div>
                <div class="form-row">
                    <label for="user_password">Password</label>
                    <input type="password" id="user_password" name="password" required>
                </div>
                <div class="form-row">
                    <label for="user_password_confirm">Confirm Password</label>
                    <input type="password" id="user_password_confirm" name="confirm_password" required>
                </div>
                <div class="form-row">
                    <label for="user_role">Role</label>
                    <select id="user_role" name="role" required>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="primary-action">
                        <i class="fas fa-save"></i> Save User
                    </button>
                    <button type="button" class="secondary-action" id="cancelAddUser">Cancel</button>
                </div>
            </form>
            <p class="form-hint">Email and password can be assigned later by editing the user profile.</p>
        </section>

        <section class="card user-list">
            <h3><i class="fas fa-users"></i> Registered Users</h3>
            <div class="table-wrapper">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Contact Number</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userManagementUsers as $user): ?>
                            <tr>
                                <td><?php echo (int) $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($user['contact_number'] ?? '—'); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo htmlspecialchars($user['role']); ?>">
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php $isDeactivated = !empty($user['deleted_at']); ?>
                                    <span class="status-badge status-<?php echo $isDeactivated ? 'inactive' : 'active'; ?>">
                                        <?php echo $isDeactivated ? 'Deactivated' : 'Active'; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y H:i', strtotime($user['created_at'])); ?></td>
                                <td class="table-actions">
                                    <?php if ($user['role'] === 'staff'): ?>
                                        <form
                                            method="post"
                                            class="inline-form"
                                            data-user-status-form
                                            data-user-status-action="<?php echo $isDeactivated ? 'activate' : 'deactivate'; ?>"
                                            data-user-status-name="<?php echo htmlspecialchars($user['name'] ?? 'this staff member'); ?>"
                                        >
                                            <input type="hidden" name="toggle_user_status" value="1">
                                            <input type="hidden" name="toggle_user_id" value="<?php echo (int) $user['id']; ?>">
                                            <input type="hidden" name="toggle_action" value="<?php echo $isDeactivated ? 'activate' : 'deactivate'; ?>">
                                            <button type="submit" class="<?php echo $isDeactivated ? 'primary-action' : 'danger-action'; ?>">
                                                <?php if ($isDeactivated): ?>
                                                    <i class="fas fa-user-check"></i> Activate
                                                <?php else: ?>
                                                    <i class="fas fa-user-slash"></i> Deactivate
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($userManagementUsers)): ?>
                            <tr>
                                <td colspan="8" class="empty-row">No users found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="user-status-confirm" data-user-confirm-overlay hidden>
    <div class="user-status-confirm__backdrop" data-user-confirm-dismiss></div>
    <div class="user-status-confirm__dialog" role="alertdialog" aria-modal="true" aria-labelledby="userStatusConfirmTitle" aria-describedby="userStatusConfirmMessage">
        <div class="user-status-confirm__body">
            <h2 id="userStatusConfirmTitle" class="user-status-confirm__title" data-user-confirm-title>Confirm action</h2>
            <p id="userStatusConfirmMessage" class="user-status-confirm__message" data-user-confirm-message></p>
        </div>
        <div class="user-status-confirm__actions">
            <button type="button" class="user-status-confirm__button user-status-confirm__button--cancel" data-user-confirm-cancel>Cancel</button>
            <button type="button" class="user-status-confirm__button user-status-confirm__button--confirm" data-user-confirm-accept>Continue</button>
        </div>
    </div>
</div>
