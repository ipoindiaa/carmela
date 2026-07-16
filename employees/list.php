<?php
$pageTitle = 'Employees';
$pageIcon = '<i class="ri-user-star-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
Auth::requireEntityAccess('employee', 'read');
$search = trim((string) get('q', ''));
$showDeleted = get('show', '') === 'deleted';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'add') {
    Auth::requireEntityAccess('employee', 'write');
    verifyCsrf();
    try {
        $engine = new AccountingEngine($businessId, Auth::user('user_id'));
        $empId = Database::uuid();
        $name = trim((string) post('name'));
        $phone = validatePhoneNumber(post('phone'), 'Phone number');
        $email = validateEmailAddress(post('email'), 'Email');
        $emergencyPhone = validatePhoneNumber(post('emergency_contact_phone'), 'Emergency contact phone');
        $accountSuffix = strtoupper(substr(str_replace('-', '', $empId), 0, 7));
        $advAccId = $engine->createAccount('ADV-' . $accountSuffix, "$name - Advance A/c", 'ASSET', 'Current Assets', 'EMPLOYEE', $empId);
        
        $db->insert('employees', [
            'id' => $empId, 'business_id' => $businessId, 'name' => $name,
            'phone' => $phone, 'role' => post('role'),
            'email' => $email,
            'monthly_salary' => parseDecimalInput(post('monthly_salary', 0)),
            'advance_account_id' => $advAccId, 'join_date' => post('join_date'),
            'address' => post('address'),
            'emergency_contact_name' => post('emergency_contact_name'),
            'emergency_contact_phone' => $emergencyPhone,
            'notes' => post('notes'),
        ]);
        $createdEmployee = $db->fetch("SELECT * FROM employees WHERE id = ? AND business_id = ?", [$empId, $businessId]);
        Auth::auditCreate('employee', $empId, $createdEmployee ?: ['name' => $name], "Employee $name added", 'employees');
        setFlash('success', "Employee $name added!");
        redirect('list.php');
    } catch (Exception $e) { setFlash('error', $e->getMessage()); }
}

$employeeWhere = "e.business_id = ? AND e.is_active = ?";
$employeeParams = [$businessId, $showDeleted ? 0 : 1];
if ($search !== '') {
    $employeeWhere .= " AND (
        e.name LIKE ?
        OR e.role LIKE ?
        OR e.phone LIKE ?
        OR e.email LIKE ?
        OR e.monthly_salary LIKE ?
        OR e.join_date LIKE ?
        OR CASE WHEN e.is_active = 1 THEN 'Active' ELSE 'Left' END LIKE ?
    )";
    $needle = '%' . $search . '%';
    array_push($employeeParams, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
}

$employees = $db->fetchAll(
    "SELECT e.*, a.current_balance as advance_balance, a.current_balance_type as advance_balance_type
     FROM employees e
     LEFT JOIN accounts a ON a.id = e.advance_account_id
     WHERE {$employeeWhere}
     ORDER BY e.created_at DESC, e.name",
    $employeeParams
);
?>

<div class="page-header">
    <h1><i class="ri-user-star-line"></i> Employees</h1>
    <?php if (Auth::hasEntityAccess('employee', 'write')): ?><button onclick="openModal('add-employee')" class="btn btn-primary"><i class="ri-add-line"></i> Add Employee</button><?php endif; ?>
</div>

<div class="filter-bar">
    <form method="GET">
        <?php if ($showDeleted): ?><input type="hidden" name="show" value="deleted"><?php endif; ?>
        <div class="filter-main-field">
            <label class="form-label">Search Employee</label>
            <input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Name, role, phone, salary, date, or status">
        </div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <?php if ($search !== ''): ?><a href="list.php<?= $showDeleted ? '?show=deleted' : '' ?>" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
        <a href="list.php<?= $showDeleted ? '' : '?show=deleted' ?>" class="btn btn-outline btn-sm"><i class="<?= $showDeleted ? 'ri-arrow-left-line' : 'ri-delete-bin-line' ?>"></i> <?= $showDeleted ? 'Active Employees' : 'Deleted Records' ?></a>
    </form>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Name</th><th>Role</th><th>Phone</th><th class="text-right">Monthly Salary</th><th class="text-right">Advance Outstanding</th><th>Joined / Time</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr></thead>
        <tbody>
            <?php if (empty($employees)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding: 40px;">No employees yet</td></tr>
            <?php else: ?>
                <?php foreach ($employees as $e): ?>
                <?php $advanceOutstanding = (($e['advance_balance_type'] ?? 'DR') === 'DR') ? abs((float) ($e['advance_balance'] ?? 0)) : 0; ?>
                <tr>
                    <td class="text-bold"><?= clean($e['name']) ?></td>
                    <td><?= clean($e['role'] ?: '-') ?></td>
                    <td><?= clean($e['phone'] ?: '-') ?></td>
                    <td class="text-right amount flow-out"><?= formatAmount($e['monthly_salary']) ?></td>
                    <td class="text-right amount <?= $advanceOutstanding > 0 ? 'flow-in' : 'flow-neutral' ?>"><?= formatAmount($advanceOutstanding) ?></td>
                    <td><?= renderDateTimeStack($e['join_date'], $e['created_at']) ?></td>
                    <td class="text-center"><span class="badge <?= $e['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $e['is_active'] ? 'Active' : 'Left' ?></span></td>
                    <td class="text-center">
                        <a href="view.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline" title="View"><i class="ri-eye-line"></i></a>
                        <?php if (Auth::hasEntityAccess('employee', 'write')): ?><a href="view.php?id=<?= $e['id'] ?>&amp;edit=1" class="btn btn-sm btn-outline" title="<?= !empty($e['is_active']) ? 'Edit' : 'Restore' ?>"><i class="<?= !empty($e['is_active']) ? 'ri-edit-line' : 'ri-restart-line' ?>"></i></a><?php endif; ?>
                        <a href="../reports/change_history.php?entity_type=employee&amp;entity_id=<?= $e['id'] ?>" class="btn btn-sm btn-outline" title="Change history"><i class="ri-history-line"></i></a>
                        <?php if (!empty($e['is_active']) && Auth::hasEntityAccess('employee', 'delete')): ?><a href="../delete_record.php?entity_type=employee&amp;id=<?= clean($e['id']) ?>" class="btn btn-sm btn-outline text-red" title="Delete"><i class="ri-delete-bin-line"></i></a><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="modal-overlay" id="add-employee">
    <div class="modal">
        <div class="modal-header"><h3>Add Employee</h3><button class="modal-close" onclick="closeModal('add-employee')">×</button></div>
        <div class="modal-body">
            <form method="POST" data-confirm-submit="Add this employee with the entered salary and contact details?">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Role</label><input type="text" name="role" class="form-control" placeholder="e.g., Driver, Mechanic"></div>
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="10 digit phone"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" placeholder="name@example.com"></div>
                    <div class="form-group"><label class="form-label">Emergency Contact Name</label><input type="text" name="emergency_contact_name" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Emergency Contact Phone</label><input type="text" name="emergency_contact_phone" class="form-control" inputmode="numeric" pattern="[0-9]{10}" maxlength="10"></div>
                    <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Monthly Salary (₹)</label><input type="number" name="monthly_salary" class="form-control" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Join Date *</label><input type="date" name="join_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ri-save-line"></i> Add Employee</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
