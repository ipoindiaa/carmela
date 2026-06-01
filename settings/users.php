<?php
$pageTitle = 'User Management';
$pageIcon = '<i class="ri-user-settings-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();
$businessId = Auth::user('business_id');

function summarizeBookPermissions($permissions) {
    $readCount = 0;
    $writeCount = 0;

    foreach ($permissions as $permission) {
        if (!empty($permission['read']) || !empty($permission['write'])) {
            $readCount++;
        }
        if (!empty($permission['write'])) {
            $writeCount++;
        }
    }

    if ($readCount === 0 && $writeCount === 0) {
        return 'No books assigned';
    }

    return "Read: $readCount, Write: $writeCount";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'add') {
        $password = post('password');
        $email = trim(post('user_email'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
            redirect('users.php');
        }

        if (strlen($password) < 6) {
            setFlash('error', 'Password must be at least 6 characters.');
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
            $userId = Database::uuid();
            $generatedUsername = Auth::generateUsername($email, post('full_name'));

            $db->insert('users', [
                'id' => $userId,
                'business_id' => $businessId,
                'username' => $generatedUsername,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'full_name' => post('full_name'),
                'email' => $email,
                'role' => post('role'),
            ]);

            if (post('role') !== ROLE_ADMIN) {
                Auth::saveBookPermissions($userId, $businessId, $_POST['permissions'] ?? []);
            }

            Auth::auditLog('CREATE', 'user', $userId, 'User created: ' . $email);
            setFlash('success', 'User created successfully.');
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
    } elseif ($action === 'toggle') {
        $userId = post('user_id');

        if ($userId === Auth::user('user_id')) {
            setFlash('error', 'You cannot disable your own account.');
            redirect('users.php');
        }

        $db->query(
            "UPDATE users SET is_active = NOT is_active WHERE id = ? AND business_id = ?",
            [$userId, $businessId]
        );
        setFlash('success', 'User status updated.');
    } elseif ($action === 'reset_password') {
        $userId = post('user_id');
        $newPass = post('new_password');

        if (strlen($newPass) < 6) {
            setFlash('error', 'Password must be at least 6 characters.');
            redirect('users.php');
        }

        $db->query(
            "UPDATE users SET password_hash = ? WHERE id = ? AND business_id = ?",
            [password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]), $userId, $businessId]
        );
        Auth::auditLog('UPDATE', 'user', $userId, 'Password reset');
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
            Auth::saveBookPermissions($userId, $businessId, $_POST['permissions'] ?? []);
            Auth::auditLog('UPDATE', 'user', $userId, 'Updated book permissions for ' . $user['full_name']);
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
            Admins create each user with an email and password. Read access lets the user view a book, and write access lets them post entries through that book where applicable.
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
                <th>Status</th>
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
                <td><span class="badge <?= $u['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
                <td><?= $u['last_login'] ? formatDate($u['last_login'], 'd M, H:i') : 'Never' ?></td>
                <td class="text-center" style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
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
                    <form method="POST" style="display:inline;">
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

<div class="modal-overlay" id="add-user">
    <div class="modal" style="max-width: 900px;">
        <div class="modal-header"><h3>Add User</h3><button class="modal-close" onclick="closeModal('add-user')">×</button></div>
        <div class="modal-body">
            <form method="POST">
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
                    <input type="password" name="password" class="form-control" required minlength="6">
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
            <form method="POST">
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
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset-uid">
                <div class="form-group">
                    <label class="form-label">New Password *</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
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

document.getElementById('new-user-role')?.addEventListener('change', function () {
    const permissionsBlock = document.getElementById('new-user-permissions');
    if (permissionsBlock) {
        permissionsBlock.style.display = this.value === 'ADMIN' ? 'none' : 'block';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
