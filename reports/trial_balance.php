<?php
$pageTitle = 'Trial Balance';
$pageIcon = '<i class="ri-scales-3-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('trial_balance', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$asOnDate = get('as_on', date('Y-m-d'));
$accounts = $engine->getTrialBalance($asOnDate);

$totalDr = $totalCr = 0;
foreach ($accounts as $a) {
    if ($a['balance_type'] === 'DR') $totalDr += $a['balance_amount'];
    else $totalCr += $a['balance_amount'];
}
$balanced = abs($totalDr - $totalCr) < 0.01;
?>

<div class="page-header">
    <h1><i class="ri-scales-3-line"></i> Trial Balance</h1>
    <div style="display:flex;gap:10px;">
        <form method="GET" style="display:flex;gap:10px;align-items:center;">
            <input type="date" name="as_on" class="form-control" value="<?= clean($asOnDate) ?>">
            <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button>
        </form>
        <span class="badge <?= $balanced ? 'badge-green' : 'badge-red' ?>" style="font-size: 13px; padding: 8px 16px;">
            <?= $balanced ? '✓ Balanced' : '✗ IMBALANCED' ?>
        </span>
        <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
    </div>
</div>

<div class="table-container">
    <table>
        <thead><tr><th>Account Code</th><th>Account Name</th><th>Group</th><th class="text-right debit-amount">Debit (₹)</th><th class="text-right credit-amount">Credit (₹)</th></tr></thead>
        <tbody>
        <?php $lastGroup = ''; foreach ($accounts as $a): 
            if ($a['group_name'] !== $lastGroup) { $lastGroup = $a['group_name']; ?>
                <tr style="background: var(--bg-secondary);"><td colspan="5" style="font-weight: 700; color: var(--accent-blue);"><?= ACCOUNT_GROUPS[$a['group_name']] ?? $a['group_name'] ?></td></tr>
            <?php } ?>
            <tr>
                <td class="text-muted"><?= $a['code'] ?></td>
                <td><?= clean($a['name']) ?></td>
                <td class="text-muted"><?= $a['sub_group'] ?></td>
                <td class="text-right amount debit-amount"><?= $a['balance_type'] === 'DR' ? formatAmount($a['balance_amount']) : '' ?></td>
                <td class="text-right amount credit-amount"><?= $a['balance_type'] === 'CR' ? formatAmount($a['balance_amount']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        <tr style="background: var(--bg-secondary); font-weight: 700; font-size: 15px;">
            <td colspan="3">Total</td>
            <td class="text-right amount debit-amount"><?= formatAmount($totalDr) ?></td>
            <td class="text-right amount credit-amount"><?= formatAmount($totalCr) ?></td>
        </tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
