<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

Auth::check();
Auth::requireAdmin();

$businessId = Auth::user('business_id');
$employeeId = get('id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

$employee = $db->fetch(
    "SELECT e.*, a.current_balance, a.current_balance_type
     FROM employees e
     LEFT JOIN accounts a ON a.id = e.advance_account_id
     WHERE e.id = ? AND e.business_id = ?",
    [$employeeId, $businessId]
);

if (!$employee) {
    setFlash('error', 'Employee not found.');
    redirect('list.php');
}

$outstanding = (($employee['current_balance_type'] ?? 'DR') === 'DR') ? abs((float) ($employee['current_balance'] ?? 0)) : 0.0;
if ($outstanding <= 0.009) {
    setFlash('error', 'This employee does not currently have any advance outstanding.');
    redirect('view.php?id=' . urlencode($employeeId));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $date = post('entry_date');
    $amount = floatval(post('amount'));
    $narration = post('narration');

    try {
        $entryId = $engine->employeeAdvanceWriteOff($employeeId, $amount, $date, $narration);
        setFlash('success', 'Employee advance write-off posted successfully.');
        redirect('../transactions/view.php?id=' . urlencode($entryId));
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$pageTitle = 'Write Off Employee Advance';
$pageIcon = '<i class="ri-user-unfollow-line"></i>';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="ri-user-unfollow-line"></i> Write Off Employee Advance</h1>
    <a href="view.php?id=<?= $employee['id'] ?>" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
</div>

<div class="card" style="max-width: 760px;">
    <div class="card-body">
        <div class="alert alert-warning">
            <i class="ri-alert-line"></i>
            Use this only when the employee advance cannot realistically be recovered. The system will debit `Employee Advance Write-Off Expense` and credit the employee advance account.
        </div>

        <div class="stats-grid" style="grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 20px;">
            <div class="stat-card"><div class="stat-value"><?= clean($employee['name']) ?></div><div class="stat-label">Employee</div></div>
            <div class="stat-card"><div class="stat-value"><?= clean($employee['is_active'] ? 'Active' : 'Left') ?></div><div class="stat-label">Status</div></div>
            <div class="stat-card"><div class="stat-value text-yellow"><?= formatAmount($outstanding) ?></div><div class="stat-label">Advance Outstanding</div></div>
        </div>

        <form method="POST" data-confirm-submit="Post this employee advance write-off? It will create a financial entry and can only be corrected through reversal.">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Write-Off Amount (₹) *</label>
                    <input type="number" name="amount" class="form-control" value="<?= clean(number_format($outstanding, 2, '.', '')) ?>" min="0.01" max="<?= clean(number_format($outstanding, 2, '.', '')) ?>" step="0.01" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Reason / Narration *</label>
                <textarea name="narration" class="form-control" rows="3" required>Employee advance written off for <?= clean($employee['name']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-danger"><i class="ri-save-line"></i> Post Write-Off</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
