<?php
/** @var ?string $userManagementSuccess */
/** @var ?string $userManagementError */
/** @var array $userManagementUsers */
/** @var bool $showUserManagementBackButton */

$showUserManagementBackButton = $showUserManagementBackButton ?? true;
?>
<?php if (!empty($userManagementSuccess)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($userManagementSuccess); ?></div>
<?php endif; ?>
<?php if (!empty($userManagementError)): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($userManagementError); ?></div>
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
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userManagementUsers as $user): ?>
                        <tr class="<?php echo !empty($user['deleted_at']) ? 'user-row-inactive' : ''; ?>">
                                <td><?php echo (int) $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($user['contact_number'] ?? '—'); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo htmlspecialchars($user['role']); ?>">
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y H:i', strtotime($user['created_at'])); ?></td>
                                <td class="table-actions">
                                    <?php $isDeactivated = !empty($user['deleted_at']); ?>
                                    <?php if ($user['role'] === 'staff' && !$isDeactivated): ?>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Deactivate this staff account? They will no longer be able to sign in.');">
                                            <input type="hidden" name="delete_user" value="1">
                                            <input type="hidden" name="delete_user_id" value="<?php echo (int) $user['id']; ?>">
                                            <button type="submit" class="danger-action">
                                                <i class="fas fa-user-slash"></i> Deactivate
                                            </button>
                                        </form>
                                    <?php elseif ($user['role'] === 'staff' && $isDeactivated): ?>
                                        <span class="status-badge status-inactive">
                                            <i class="fas fa-user-slash"></i> Deactivated
                                        </span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($userManagementUsers)): ?>
                            <tr>
                                <td colspan="7" class="empty-row">No users found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
