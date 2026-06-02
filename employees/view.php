<?php
$pageTitle = 'Employee Statement';
$pageIcon = '<i class="ri-user-star-line"></i>';
require_once __DIR__ . '/../includes/header.php';
$businessId = Auth::user('business_id');
$id = get('id');
$emp = $db->fetch("SELECT e.*, a.current_balance as advance_balance, a.current_balance_type as advance_balance_type FROM employees e LEFT JOIN accounts a ON a.id = e.advance_account_id WHERE e.id = ? AND e.business_id = ?", [$id, $businessId]);
if (!$emp) { setFlash('error', 'Employee not found.'); redirect('list.php'); }

$advanceOutstanding = (($emp['advance_balance_type'] ?? 'DR') === 'DR') ? abs((float) ($emp['advance_balance'] ?? 0)) : 0;

$salaryHistory = $db->fetchAll("SELECT * FROM salary_records WHERE employee_id = ? ORDER BY year DESC, month DESC", [$id]);
$advanceLedger = $db->fetchAll(
    "SELECT je.entry_date, je.reference_no, je.narration, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status='POSTED' ORDER BY je.entry_date DESC", [$emp['advance_account_id']]);
?>

<div class="page-header">
    <h1><i class="ri-user-star-line"></i> <?= clean($emp['name']) ?></h1>
    <div style="display:flex;gap:10px;">
        <a href="../transactions/new.php?type=SALARY_PAYMENT" class="btn btn-primary btn-sm"><i class="ri-money-rupee-circle-line"></i> Pay Salary</a>
        <a href="../transactions/new.php?type=EMPLOYEE_ADVANCE" class="btn btn-outline btn-sm"><i class="ri-hand-coin-line"></i> Give Advance</a>
        <?php if (Auth::isAdmin() && $advanceOutstanding > 0): ?>
            <a href="write_off.php?id=<?= $emp['id'] ?>" class="btn btn-danger btn-sm"><i class="ri-close-circle-line"></i> Write Off Advance</a>
        <?php endif; ?>
        <a href="list.php" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(3,1fr);">
    <div class="stat-card"><div class="stat-value"><?= formatAmount($emp['monthly_salary']) ?></div><div class="stat-label">Monthly Salary</div></div>
    <div class="stat-card"><div class="stat-value text-yellow"><?= formatAmount($advanceOutstanding) ?></div><div class="stat-label">Advance Outstanding</div></div>
    <div class="stat-card"><div class="stat-value"><?= clean($emp['role'] ?: 'N/A') ?></div><div class="stat-label">Role</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Salary History</h3></div>
        <div class="card-body" style="padding:0;">
            <table><thead><tr><th>Month</th><th class="text-right">Gross</th><th class="text-right">Advance Ded.</th><th class="text-right">Net Paid</th><th>Mode</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($salaryHistory as $s): ?>
            <tr><td><?= date('F', mktime(0,0,0,$s['month'],1)) ?> <?= $s['year'] ?></td><td class="text-right amount"><?= formatAmount($s['gross_salary']) ?></td>
                <td class="text-right amount text-yellow"><?= $s['advance_deducted'] > 0 ? formatAmount($s['advance_deducted']) : '-' ?></td>
                <td class="text-right amount text-green"><?= formatAmount($s['net_paid']) ?></td>
                <td><span class="badge badge-blue"><?= $s['payment_mode'] ?></span></td><td><?= formatDate($s['processed_date']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($salaryHistory)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:30px;">No salary records</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Advance Ledger</h3></div>
        <div class="card-body" style="padding:0;">
            <table><thead><tr><th>Date</th><th>Narration</th><th class="text-right debit-amount">Given</th><th class="text-right credit-amount">Recovered</th></tr></thead>
            <tbody>
            <?php foreach ($advanceLedger as $l): ?>
            <tr><td><?= formatDate($l['entry_date']) ?></td><td><?= clean(mb_substr($l['narration']??'',0,40)) ?></td>
                <td class="text-right amount debit-amount"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                <td class="text-right amount credit-amount"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($advanceLedger)): ?><tr><td colspan="4" class="text-center text-muted" style="padding: 30px;">No advance entries</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
