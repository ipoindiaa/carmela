<?php
$pageTitle = 'General Ledger';
$pageIcon = '<i class="ri-file-text-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('general_ledger', 'read');
$businessId = Auth::user('business_id');
$accountId = get('account_id', '');
$dateFrom = get('from', date('Y-m-01'));
$dateTo = get('to', date('Y-m-d'));

$accounts = $db->fetchAll("SELECT id, code, name, group_name FROM accounts WHERE business_id = ? ORDER BY group_name, code", [$businessId]);

$entries = [];
$selectedAccount = null;
if ($accountId) {
    $selectedAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $businessId]);
    if ($selectedAccount) {
        $entries = $db->fetchAll(
            "SELECT je.entry_date, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
             FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ? AND je.status = 'POSTED' AND je.entry_date BETWEEN ? AND ?
             ORDER BY je.entry_date, je.created_at", [$accountId, $dateFrom, $dateTo]);
    }
}
?>

<div class="page-header">
    <h1><i class="ri-file-text-line"></i> General Ledger</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
        <div style="min-width:250px;">
            <label class="form-label">Account</label>
            <select name="account_id" class="form-control" required>
                <option value="">— Select Account —</option>
                <?php $lastGrp = ''; foreach ($accounts as $a): 
                    if ($a['group_name'] !== $lastGrp) { if ($lastGrp) echo '</optgroup>'; $lastGrp = $a['group_name']; echo '<optgroup label="' . (ACCOUNT_GROUPS[$a['group_name']] ?? $a['group_name']) . '">'; }
                ?>
                    <option value="<?= $a['id'] ?>" <?= $accountId === $a['id'] ? 'selected' : '' ?>><?= $a['code'] ?> — <?= clean($a['name']) ?></option>
                <?php endforeach; if ($lastGrp) echo '</optgroup>'; ?>
            </select>
        </div>
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= $dateFrom ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= $dateTo ?>"></div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> View</button>
    </form>
</div>

<?php if ($selectedAccount): ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="display:flex;gap:40px;">
        <div><span class="text-muted">Account:</span> <strong><?= clean($selectedAccount['name']) ?></strong></div>
        <div><span class="text-muted">Balance:</span> <strong class="amount"><?= formatAmount($selectedAccount['current_balance']) ?> <?= $selectedAccount['current_balance_type'] ?></strong></div>
    </div>
</div>

<div class="table-container">
    <table>
        <thead><tr><th>Date</th><th>Ref</th><th>Type</th><th>Narration</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php $bal = 0; foreach ($entries as $e):
            if ($e['entry_type'] === 'DR') $bal += $e['amount']; else $bal -= $e['amount'];
        ?>
        <tr>
            <td><?= formatDate($e['entry_date']) ?></td><td><?= $e['reference_no'] ?></td>
            <td><span class="badge badge-blue" style="font-size:10px;"><?= TXN_TYPES[$e['transaction_type']] ?? $e['transaction_type'] ?></span></td>
            <td><?= clean(mb_substr($e['narration']??'',0,50)) ?></td>
            <td class="text-right amount"><?= $e['entry_type']==='DR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount"><?= $e['entry_type']==='CR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount"><?= formatAmount(abs($bal)) ?> <?= $bal >= 0 ? 'Dr' : 'Cr' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?><tr><td colspan="7" class="text-center text-muted" style="padding:30px;">No entries for this period</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
