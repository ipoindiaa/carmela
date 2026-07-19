<?php
$pageTitle = 'Employee Statement';
$pageIcon = '<i class="ri-user-star-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
Auth::requireEntityAccess('employee', 'read');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$id = get('id');
$emp = $db->fetch("SELECT e.*, a.current_balance as advance_balance, a.current_balance_type as advance_balance_type FROM employees e LEFT JOIN accounts a ON a.id = e.advance_account_id WHERE e.id = ? AND e.business_id = ?", [$id, $businessId]);
if (!$emp) { setFlash('error', 'Employee not found.'); redirect('list.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'update') {
    Auth::requireEntityAccess('employee', 'write');
    verifyCsrf();
    try {
        $name = trim((string) post('name'));
        if ($name === '') throw new Exception('Employee name is required.');
        $phone = validatePhoneNumber(post('phone'), 'Phone number');
        $email = validateEmailAddress(post('email'), 'Email');
        $emergencyPhone = validatePhoneNumber(post('emergency_contact_phone'), 'Emergency contact phone');
        $isActive = post('is_active', '0') === '1' ? 1 : 0;
        $exitDate = trim((string) post('exit_date')) ?: null;
        if (!$isActive && !$exitDate) $exitDate = date('Y-m-d');
        if ($isActive) $exitDate = null;

        $db->query(
            "UPDATE employees SET name = ?, phone = ?, email = ?, role = ?, monthly_salary = ?, join_date = ?, exit_date = ?, address = ?, emergency_contact_name = ?, emergency_contact_phone = ?, notes = ?, is_active = ? WHERE id = ? AND business_id = ?",
            [$name, $phone, $email, post('role'), parseDecimalInput(post('monthly_salary', 0)), post('join_date'), $exitDate, post('address'), post('emergency_contact_name'), $emergencyPhone, post('notes'), $isActive, $id, $businessId]
        );
        if (!empty($emp['advance_account_id'])) {
            $oldAdvanceAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$emp['advance_account_id'], $businessId]);
            $db->query("UPDATE accounts SET name = ?, is_active = ? WHERE id = ? AND business_id = ?", ["$name - Advance A/c", $isActive, $emp['advance_account_id'], $businessId]);
            $newAdvanceAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$emp['advance_account_id'], $businessId]);
            Auth::auditUpdate('account', $emp['advance_account_id'], $oldAdvanceAccount ?: [], $newAdvanceAccount ?: [], 'Employee advance account renamed', 'employees');
        }
        $updated = $db->fetch("SELECT * FROM employees WHERE id = ? AND business_id = ?", [$id, $businessId]);
        Auth::auditUpdate('employee', $id, $emp, $updated ?: [], "Employee $name updated", 'employees');
        setFlash('success', 'Employee details updated.');
        redirect("view.php?id=$id");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        redirect("view.php?id=$id&edit=1");
    }
}

$advanceOutstanding = (($emp['advance_balance_type'] ?? 'DR') === 'DR') ? abs((float) ($emp['advance_balance'] ?? 0)) : 0;

$salaryHistory = $db->fetchAll("SELECT * FROM salary_records WHERE employee_id = ? ORDER BY processed_date DESC, created_at DESC", [$id]);
$advanceLedger = $db->fetchAll(
    "SELECT je.id AS entry_id, je.entry_date, je.created_at, je.reference_no, je.narration, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status IN ('POSTED','REVERSED') ORDER BY je.entry_date DESC, je.created_at DESC", [$emp['advance_account_id']]);
?>

<div class="page-header">
    <h1><i class="ri-user-star-line"></i> <?= clean($emp['name']) ?></h1>
    <div class="page-actions">
        <?php if (Auth::hasEntityAccess('employee', 'write')): ?><a href="view.php?id=<?= $emp['id'] ?>&amp;edit=1" class="btn btn-outline btn-sm"><i class="<?= !empty($emp['is_active']) ? 'ri-edit-line' : 'ri-restart-line' ?>"></i> <?= !empty($emp['is_active']) ? 'Edit' : 'Restore' ?></a><?php endif; ?>
        <a href="../reports/change_history.php?entity_type=employee&amp;entity_id=<?= $emp['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
        <?php if (!empty($emp['is_active']) && Auth::hasEntityAccess('employee', 'delete')): ?><a href="../delete_record.php?entity_type=employee&amp;id=<?= clean($emp['id']) ?>" class="btn btn-danger btn-sm"><i class="ri-delete-bin-line"></i> Delete</a><?php endif; ?>
        <?php if (!empty($emp['is_active']) && Auth::isAdmin()): ?><a href="../settings/opening_balances.php?account_id=<?= $emp['advance_account_id'] ?>" class="btn btn-outline btn-sm"><i class="ri-scales-3-line"></i> Opening Advance</a><?php endif; ?>
        <?php if (!empty($emp['is_active'])): ?><a href="../transactions/new.php?type=SALARY_PAYMENT" class="btn btn-primary btn-sm"><i class="ri-money-rupee-circle-line"></i> Pay Salary</a>
        <a href="../transactions/new.php?type=EMPLOYEE_ADVANCE" class="btn btn-outline btn-sm"><i class="ri-hand-coin-line"></i> Give Advance</a><?php endif; ?>
        <?php if (!empty($emp['is_active']) && Auth::isAdmin() && $advanceOutstanding > 0): ?>
            <a href="write_off.php?id=<?= $emp['id'] ?>" class="btn btn-danger btn-sm"><i class="ri-close-circle-line"></i> Write Off Advance</a>
        <?php endif; ?>
        <a href="list.php<?= empty($emp['is_active']) ? '?show=deleted' : '' ?>" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<?php if (get('edit') === '1' && Auth::hasEntityAccess('employee', 'write')): ?>
<div class="card">
    <div class="card-header"><h3><i class="ri-edit-line"></i> Edit Employee Details</h3></div>
    <div class="card-body">
        <form method="POST" data-confirm-submit="Save these employee changes? Salary, status, and contact details will be added to Change History.">
            <?= csrfField() ?><input type="hidden" name="action" value="update">
            <div class="form-row-3">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" value="<?= clean($emp['name']) ?>" required></div>
                <div class="form-group"><label class="form-label">Role</label><input type="text" name="role" class="form-control" value="<?= clean($emp['role']) ?>"></div>
                <div class="form-group"><label class="form-label">Monthly Salary</label><input type="text" name="monthly_salary" class="form-control currency-input" value="<?= clean($emp['monthly_salary']) ?>" inputmode="decimal"></div>
            </div>
            <div class="form-row-3">
                <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= clean($emp['phone']) ?>" inputmode="numeric" pattern="[0-9]{10}" maxlength="10"></div>
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= clean($emp['email'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Status</label><select name="is_active" class="form-control"><option value="1" <?= $emp['is_active'] ? 'selected' : '' ?>>Active</option><option value="0" <?= !$emp['is_active'] ? 'selected' : '' ?>>Left</option></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Join Date *</label><input type="date" name="join_date" class="form-control" value="<?= clean($emp['join_date']) ?>" required></div>
                <div class="form-group"><label class="form-label">Exit Date</label><input type="date" name="exit_date" class="form-control" value="<?= clean($emp['exit_date'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Emergency Contact Name</label><input type="text" name="emergency_contact_name" class="form-control" value="<?= clean($emp['emergency_contact_name'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Emergency Contact Phone</label><input type="text" name="emergency_contact_phone" class="form-control" value="<?= clean($emp['emergency_contact_phone'] ?? '') ?>" inputmode="numeric" pattern="[0-9]{10}" maxlength="10"></div>
            </div>
            <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= clean($emp['address'] ?? '') ?></textarea></div>
            <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= clean($emp['notes'] ?? '') ?></textarea></div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Update Employee</button>
            <a href="view.php?id=<?= $emp['id'] ?>" class="btn btn-outline">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3>Employee Details</h3></div>
    <div class="card-body">
        <div class="grid-2">
            <div><span class="text-muted">Phone</span><div class="text-bold"><?= clean($emp['phone'] ?: '-') ?></div></div>
            <div><span class="text-muted">Email</span><div class="text-bold"><?= clean($emp['email'] ?? '-') ?></div></div>
            <div><span class="text-muted">Join / Exit</span><div class="text-bold"><?= formatDate($emp['join_date']) ?><?= !empty($emp['exit_date']) ? ' / ' . formatDate($emp['exit_date']) : '' ?></div></div>
            <div><span class="text-muted">Emergency Contact</span><div class="text-bold"><?= clean(trim(($emp['emergency_contact_name'] ?? '') . ' ' . ($emp['emergency_contact_phone'] ?? '')) ?: '-') ?></div></div>
        </div>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($emp['monthly_salary']) ?></div><div class="stat-label">Monthly Salary</div></div>
    <div class="stat-card"><div class="stat-value <?= $advanceOutstanding > 0 ? 'flow-in' : 'flow-neutral' ?>"><?= formatAmount($advanceOutstanding) ?></div><div class="stat-label">Advance Outstanding</div></div>
    <div class="stat-card"><div class="stat-value"><?= clean($emp['role'] ?: 'N/A') ?></div><div class="stat-label">Role</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Salary History</h3></div>
        <div class="card-body card-body-flush table-container">
            <table><thead><tr><th>Month</th><th class="text-right">Gross</th><th class="text-right">Advance Ded.</th><th class="text-right">Net Paid</th><th>Mode</th><th>Date / Time</th></tr></thead>
            <tbody>
            <?php foreach ($salaryHistory as $s): ?>
            <tr><td><?= date('F', mktime(0,0,0,$s['month'],1)) ?> <?= $s['year'] ?></td><td class="text-right amount"><?= formatAmount($s['gross_salary']) ?></td>
                <td class="text-right amount flow-in"><?= $s['advance_deducted'] > 0 ? formatAmount($s['advance_deducted']) : '-' ?></td>
                <td class="text-right amount flow-out"><?= formatAmount($s['net_paid']) ?></td>
                <td><span class="badge badge-blue"><?= $s['payment_mode'] ?></span></td><td><?= renderDateTimeStack($s['processed_date'], $s['created_at']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($salaryHistory)): ?><tr><td colspan="6" class="text-center text-muted empty-table-cell">No salary records</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Advance Ledger</h3></div>
        <div class="card-body card-body-flush table-container">
            <table><thead><tr><th>Date / Time</th><th>Ref</th><th>Narration</th><th class="text-right debit-amount">Given</th><th class="text-right credit-amount">Recovered</th></tr></thead>
            <tbody>
            <?php foreach ($advanceLedger as $l): ?>
            <tr><td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td><td><a class="text-bold" href="../transactions/view.php?id=<?= urlencode($l['entry_id']) ?>"><?= clean($l['reference_no']) ?></a></td><td><?= clean(mb_substr($l['narration']??'',0,40)) ?></td>
                <td class="text-right amount debit-amount"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                <td class="text-right amount credit-amount"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($advanceLedger)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No advance entries</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
