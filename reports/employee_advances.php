<?php
$pageTitle = 'Employee Advances';
$pageIcon = '<i class="ri-user-star-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('employee_advances', 'read');

$businessId = Auth::user('business_id');
$search = trim((string) get('q', ''));
$employeeWhere = "WHERE e.business_id = ?";
$employeeParams = [$businessId];
if ($search !== '') {
    $employeeWhere .= " AND (e.name LIKE ? OR e.role LIKE ?)";
    $employeeParams[] = '%' . $search . '%';
    $employeeParams[] = '%' . $search . '%';
}
$employees = $db->fetchAll(
    "SELECT e.*, a.current_balance, a.current_balance_type
     FROM employees e
     LEFT JOIN accounts a ON a.id = e.advance_account_id
     $employeeWhere
     ORDER BY e.is_active DESC, e.name",
    $employeeParams
);
?>

<div class="page-header">
    <h1><i class="ri-user-star-line"></i> Employee Advances</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;width:100%;">
        <div style="min-width:240px;flex:1 1 280px;">
            <label class="form-label">Search employee</label>
            <input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Type employee name or role">
        </div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <a href="employee_advances.php" class="btn btn-outline btn-sm">Clear</a>
    </form>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Employee</th><th>Role</th><th class="text-right">Monthly Salary</th><th class="text-right">Advance Outstanding</th><th>Status</th><th class="text-center">Action</th></tr></thead>
        <tbody>
            <?php if (empty($employees)): ?>
                <tr><td colspan="6" class="text-center text-muted" style="padding: 32px;">No employees found.</td></tr>
            <?php else: ?>
                <?php foreach ($employees as $employee): ?>
                    <?php $advanceOutstanding = (($employee['current_balance_type'] ?? 'DR') === 'DR') ? abs((float) ($employee['current_balance'] ?? 0)) : 0; ?>
                    <tr>
                        <td class="text-bold"><?= clean($employee['name']) ?></td>
                        <td><?= clean($employee['role'] ?: '-') ?></td>
                        <td class="text-right amount flow-out"><?= formatAmount($employee['monthly_salary']) ?></td>
                        <td class="text-right amount <?= $advanceOutstanding > 0 ? 'flow-in' : 'flow-neutral' ?>"><?= formatAmount($advanceOutstanding) ?></td>
                        <td><span class="badge <?= $employee['is_active'] ? 'badge-green' : 'badge-gray' ?>"><?= $employee['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td class="text-center"><a href="../employees/view.php?id=<?= $employee['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
