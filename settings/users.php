<?php
$pageTitle = 'User Management';
$pageIcon = '<i class="ri-user-settings-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();
$businessId = Auth::user('business_id');

function summarizeBookPermissions($permissions) {
    $readCount = 0;
    $writeCount = 0;
    $deleteCount = 0;

    foreach ($permissions as $permission) {
        if (!empty($permission['read']) || !empty($permission['write'])) {
            $readCount++;
        }
        if (!empty($permission['write'])) {
            $writeCount++;
        }
        if (!empty($permission['delete'])) {
            $deleteCount++;
        }
    }

    if ($readCount === 0 && $writeCount === 0 && $deleteCount === 0) {
        return 'No books assigned';
    }

    return "Read: $readCount, Write: $writeCount, Delete: $deleteCount";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'add') {
        $password = post('password');
        $passwordConfirmation = post('password_confirmation');
        $email = trim(post('user_email'));
        $fullName = trim((string) post('full_name'));
        $role = strtoupper((string) post('role'));

        if ($fullName === '') {
            setFlash('error', 'Full name is required.');
            redirect('users.php');
        }

        if (!in_array($role, [ROLE_ADMIN, ROLE_PARTNER, ROLE_ACCOUNTANT, ROLE_OPERATOR], true)) {
            setFlash('error', 'Select a valid role.');
            redirect('users.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
            redirect('users.php');
        }

        if (strlen($password) < 8) {
            setFlash('error', 'Password must be at least 8 characters.');
            redirect('users.php');
        }
        if (!hash_equals($password, $passwordConfirmation)) {
            setFlash('error', 'Password confirmation does not match.');
            redirect('users.php');
        }

        $existingEmail = $db->fetch(
            "SELECT id FROM users WHERE business_id = ? AND email = ? LIMIT 1",
            [$businessId, $email]
        );
        if ($existingEmail) {
            setFlash('error', 'That email is already in use for this business.');
            redirect('users.php');
        }

        try {
            $db->beginTransaction();
            $userId = Database::uuid();
            $generatedUsername = Auth::generateUsername($email, $fullName);

            $db->insert('users', [
                'id' => $userId,
                'business_id' => $businessId,
                'username' => $generatedUsername,
                'password_hash' => Auth::hashPassword($password),
                'full_name' => $fullName,
                'email' => $email,
                'role' => $role,
            ]);

            if ($role !== ROLE_ADMIN) {
                Auth::saveBookPermissions($userId, $businessId, $_POST['permissions'] ?? []);
            }

            $createdUser = $db->fetch("SELECT id, full_name, email, role, is_active FROM users WHERE id = ? AND business_id = ?", [$userId, $businessId]);
            Auth::auditCreate('user', $userId, $createdUser ?: ['full_name' => $fullName, 'email' => $email, 'role' => $role], 'User created: ' . $email, 'users');
            $db->commit();
            setFlash('success', 'User created successfully.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('error', $e->getMessage());
        }
    } elseif ($action === 'toggle') {
        $userId = post('user_id');

        if ($userId === Auth::user('user_id')) {
            setFlash('error', 'You cannot disable your own account.');
            redirect('users.php');
        }

        $oldUser = $db->fetch("SELECT id, full_name, email, role, is_active FROM users WHERE id = ? AND business_id = ?", [$userId, $businessId]);
        if (!$oldUser) { setFlash('error', 'User not found.'); redirect('users.php'); }
        $db->query(
            "UPDATE users SET is_active = NOT is_active WHERE id = ? AND business_id = ?",
            [$userId, $businessId]
        );
        $newUser = $db->fetch("SELECT id, full_name, email, role, is_active FROM users WHERE id = ? AND business_id = ?", [$userId, $businessId]);
        Auth::auditUpdate('user', $userId, $oldUser, $newUser ?: [], 'User status updated', 'users');
        setFlash('success', 'User status updated.');
    } elseif ($action === 'update_profile') {
        $userId = post('user_id');
        $oldUser = $db->fetch("SELECT id, full_name, email, role, is_active FROM users WHERE id = ? AND business_id = ?", [$userId, $businessId]);
        if (!$oldUser) { setFlash('error', 'User not found.'); redirect('users.php'); }
        $email = trim((string) post('user_email'));
        $fullName = trim((string) post('full_name'));
        $role = strtoupper((string) post('role'));
        if ($fullName === '') { setFlash('error', 'Full name is required.'); redirect('users.php'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { setFlash('error', 'Enter a valid email.'); redirect('users.php'); }
        if (!in_array($role, [ROLE_ADMIN, ROLE_PARTNER, ROLE_ACCOUNTANT, ROLE_OPERATOR], true)) { setFlash('error', 'Invalid role.'); redirect('users.php'); }
        if ($userId === Auth::user('user_id') && $role !== $oldUser['role']) { setFlash('error', 'You cannot change your own role.'); redirect('users.php'); }
        $duplicate = $db->fetch("SELECT id FROM users WHERE business_id = ? AND email = ? AND id <> ?", [$businessId, $email, $userId]);
        if ($duplicate) { setFlash('error', 'That email is already in use.'); redirect('users.php'); }
        $db->query("UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ? AND business_id = ?", [$fullName, $email, $role, $userId, $businessId]);
        $newUser = $db->fetch("SELECT id, full_name, email, role, is_active FROM users WHERE id = ? AND business_id = ?", [$userId, $businessId]);
        Auth::auditUpdate('user', $userId, $oldUser, $newUser ?: [], 'User details updated', 'users');
        setFlash('success', 'User details updated.');
    } elseif ($action === 'reset_password') {
        $userId = post('user_id');
        $newPass = post('new_password');
        $newPassConfirmation = post('new_password_confirmation');

        if (strlen($newPass) < 8) {
            setFlash('error', 'Password must be at least 8 characters.');
            redirect('users.php');
        }
        if (!hash_equals($newPass, $newPassConfirmation)) {
            setFlash('error', 'Password confirmation does not match.');
            redirect('users.php');
        }

        $targetUser = $db->fetch("SELECT id FROM users WHERE id = ? AND business_id = ?", [$userId, $businessId]);
        if (!$targetUser) { setFlash('error', 'User not found.'); redirect('users.php'); }

        $db->query(
            "UPDATE users SET password_hash = ? WHERE id = ? AND business_id = ?",
            [Auth::hashPassword($newPass), $userId, $businessId]
        );
        Auth::auditLog('UPDATE', 'user', $userId, 'Password reset', ['password' => 'set'], ['password' => 'changed'], 'users');
        setFlash('success', 'Password reset successfully.');
    } elseif ($action === 'save_permissions') {
        $userId = post('user_id');
        $user = $db->fetch(
            "SELECT id, full_name, role FROM users WHERE id = ? AND business_id = ?",
            [$userId, $businessId]
        );

        if (!$user) {
            setFlash('error', 'User not found.');
            redirect('users.php');
        }

        if ($user['role'] === ROLE_ADMIN) {
            setFlash('warning', 'Admins always have full access.');
            redirect('users.php');
        }

        try {
            $oldPermissions = Auth::getBookPermissions($userId, $businessId, $user['role']);
            Auth::saveBookPermissions($userId, $businessId, $_POST['permissions'] ?? []);
            $newPermissions = Auth::getBookPermissions($userId, $businessId, $user['role']);
            Auth::auditUpdate('user', $userId, ['book_permissions' => $oldPermissions], ['book_permissions' => $newPermissions], 'Updated book permissions for ' . $user['full_name'], 'users');
            setFlash('success', 'Book permissions updated.');
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
    }

    redirect('users.php');
}

$users = $db->fetchAll(
    "SELECT * FROM users WHERE business_id = ? ORDER BY role, full_name",
    [$businessId]
);

$userPermissionMap = [];
foreach ($users as $user) {
    $userPermissionMap[$user['id']] = Auth::getBookPermissions($user['id'], $businessId, $user['role']);
}
?>

<div class="page-header">
    <h1><i class="ri-user-settings-line"></i> User Management</h1>
    <button onclick="openModal('add-user')" class="btn btn-primary"><i class="ri-add-line"></i> Add User</button>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <div class="text-muted" style="font-size: 13px;">
            Admins create each user with an email and password. Read access lets the user view a book, write access lets them post entries, and delete access lets them reverse wrong entries in that book safely.
        </div>
    </div>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Username</th>
                <th>Role</th>
                <th>Books Access</th>
                <th class="text-center">Status</th>
                <th>Last Login</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <?php $permissionSummary = $u['role'] === ROLE_ADMIN ? 'Full access' : summarizeBookPermissions($userPermissionMap[$u['id']] ?? []); ?>
            <tr>
                <td class="text-bold"><?= clean($u['full_name']) ?></td>
                <td><?= clean($u['email'] ?: '-') ?></td>
                <td class="text-muted"><?= clean($u['username']) ?></td>
                <td><span class="badge <?= $u['role'] === ROLE_ADMIN ? 'badge-purple' : 'badge-blue' ?>"><?= $u['role'] ?></span></td>
                <td><?= clean($permissionSummary) ?></td>
                <td class="text-center"><span class="badge <?= $u['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
                <td><?= $u['last_login'] ? formatDate($u['last_login'], 'd M, H:i') : 'Never' ?></td>
                <td class="text-center" style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                    <?php $editableUser = ['id' => $u['id'], 'full_name' => $u['full_name'], 'email' => $u['email'], 'role' => $u['role']]; ?>
                    <button type="button" class="btn btn-sm btn-outline" title="Edit user" onclick='openUserEditModal(<?= json_encode($editableUser, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="ri-edit-line"></i></button>
                    <a href="../reports/change_history.php?entity_type=user&amp;entity_id=<?= clean($u['id']) ?>" class="btn btn-sm btn-outline" title="Change history"><i class="ri-history-line"></i></a>
                    <?php if ($u['role'] !== ROLE_ADMIN): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline"
                            onclick='openAccessModal("<?= $u["id"] ?>", <?= json_encode($u["full_name"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                            title="Edit Book Access"
                        >
                            <i class="ri-book-open-line"></i>
                        </button>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;" data-confirm-submit="Change this user's active status?">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline" data-confirm="Toggle this user's status?">
                            <?= $u['is_active'] ? '<i class="ri-forbid-line"></i>' : '<i class="ri-check-line"></i>' ?>
                        </button>
                    </form>
                    <button class="btn btn-sm btn-outline" onclick="document.getElementById('reset-uid').value='<?= $u['id'] ?>';openModal('reset-password')">
                        <i class="ri-lock-line"></i>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="modal-overlay" id="edit-user">
    <div class="modal">
        <div class="modal-header"><h3>Edit User</h3><button class="modal-close" onclick="closeModal('edit-user')">×</button></div>
        <div class="modal-body">
            <form method="POST" data-confirm-submit="Save these user details and role?">
                <?= csrfField() ?><input type="hidden" name="action" value="update_profile"><input type="hidden" name="user_id" id="edit-user-id">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" id="edit-user-name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Email *</label><input type="email" name="user_email" id="edit-user-email" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Role *</label><select name="role" id="edit-user-role" class="form-control"><option value="OPERATOR">Operator</option><option value="ACCOUNTANT">Accountant</option><option value="PARTNER">Partner</option><option value="ADMIN">Admin</option></select></div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ri-save-line"></i> Update User</button>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="add-user">
    <div class="modal" style="max-width: 900px;">
        <div class="modal-header"><h3>Add User</h3><button class="modal-close" onclick="closeModal('add-user')">×</button></div>
        <div class="modal-body">
            <form method="POST" data-confirm-submit="Create this user with the selected role and book permissions?">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">

                <div class="form-group">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="user_email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role *</label>
                        <select name="role" class="form-control" id="new-user-role">
                            <option value="OPERATOR">Operator</option>
                            <option value="ACCOUNTANT">Accountant</option>
                            <option value="PARTNER">Partner</option>
                            <option value="ADMIN">Admin</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password *</label>
                    <input type="password" name="password_confirmation" class="form-control" required minlength="8" autocomplete="new-password">
                </div>

                <div id="new-user-permissions">
                    <h4 style="margin: 20px 0 12px;">Book Access</h4>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Book</th>
                                    <th>Description</th>
                                    <th class="text-center">Read</th>
                                    <th class="text-center">Write</th>
                                    <th class="text-center">Delete</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (BOOK_PERMISSIONS as $bookKey => $book): ?>
                                    <tr>
                                        <td class="text-bold"><?= clean($book['label']) ?></td>
                                        <td class="text-muted"><?= clean($book['description']) ?></td>
                                        <td class="text-center">
                                            <input type="checkbox" name="permissions[<?= $bookKey ?>][read]" value="1">
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" name="permissions[<?= $bookKey ?>][write]" value="1">
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" name="permissions[<?= $bookKey ?>][delete]" value="1">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block"><i class="ri-save-line"></i> Create User</button>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="edit-access">
    <div class="modal" style="max-width: 900px;">
        <div class="modal-header">
            <h3 id="access-modal-title">Edit Book Access</h3>
            <button class="modal-close" onclick="closeModal('edit-access')">×</button>
        </div>
        <div class="modal-body">
            <form method="POST" data-confirm-submit="Save these book permissions?">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_permissions">
                <input type="hidden" name="user_id" id="access-user-id">

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Book</th>
                                <th>Description</th>
                                <th class="text-center">Read</th>
                                <th class="text-center">Write</th>
                                <th class="text-center">Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (BOOK_PERMISSIONS as $bookKey => $book): ?>
                                <tr>
                                    <td class="text-bold"><?= clean($book['label']) ?></td>
                                    <td class="text-muted"><?= clean($book['description']) ?></td>
                                    <td class="text-center">
                                        <input type="checkbox" data-book-key="<?= $bookKey ?>" data-access-type="read" name="permissions[<?= $bookKey ?>][read]" value="1">
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" data-book-key="<?= $bookKey ?>" data-access-type="write" name="permissions[<?= $bookKey ?>][write]" value="1">
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" data-book-key="<?= $bookKey ?>" data-access-type="delete" name="permissions[<?= $bookKey ?>][delete]" value="1">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn btn-primary btn-block"><i class="ri-save-line"></i> Save Book Access</button>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="reset-password">
    <div class="modal">
        <div class="modal-header"><h3>Reset Password</h3><button class="modal-close" onclick="closeModal('reset-password')">×</button></div>
        <div class="modal-body">
            <form method="POST" data-confirm-submit="Reset this user's password?">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset-uid">
                <div class="form-group">
                    <label class="form-label">New Password *</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password *</label>
                    <input type="password" name="new_password_confirmation" class="form-control" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ri-lock-line"></i> Reset Password</button>
            </form>
        </div>
    </div>
</div>

<script>
const userBookPermissions = <?= json_encode($userPermissionMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function openAccessModal(userId, userName) {
    const permissionMap = userBookPermissions[userId] || {};
    document.getElementById('access-user-id').value = userId;
    document.getElementById('access-modal-title').textContent = 'Edit Book Access: ' + userName;

    document.querySelectorAll('#edit-access input[type="checkbox"][data-book-key]').forEach((checkbox) => {
        const bookKey = checkbox.getAttribute('data-book-key');
        const accessType = checkbox.getAttribute('data-access-type');
        checkbox.checked = !!(permissionMap[bookKey] && permissionMap[bookKey][accessType]);
    });

    openModal('edit-access');
}

function openUserEditModal(user) {
    document.getElementById('edit-user-id').value = user.id || '';
    document.getElementById('edit-user-name').value = user.full_name || '';
    document.getElementById('edit-user-email').value = user.email || '';
    document.getElementById('edit-user-role').value = user.role || 'OPERATOR';
    openModal('edit-user');
}

document.getElementById('new-user-role')?.addEventListener('change', function () {
    const permissionsBlock = document.getElementById('new-user-permissions');
    if (permissionsBlock) {
        const permissionsApply = this.value !== 'ADMIN';
        permissionsBlock.style.display = permissionsApply ? 'block' : 'none';
        if (typeof setConditionalControls === 'function') {
            setConditionalControls(permissionsBlock, permissionsApply);
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
