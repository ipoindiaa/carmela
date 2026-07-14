<?php
$pageTitle = 'Bank Book';
$pageIcon = '<i class="ri-bank-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('bank_book', 'read');
$businessId = Auth::user('business_id');
$dateFrom = get('from', getCurrentFY() . '-04-01');
$dateTo = get('to', date('Y-m-d'));
$bankAccounts = $db->fetchAll(
    "SELECT * FROM accounts WHERE business_id = ? AND entity_type = 'BANK' AND entity_id IS NULL AND is_active = 1 ORDER BY code, name",
    [$businessId]
);
$selectedAccountId = get('account_id', $bankAccounts[0]['id'] ?? '');
$bankAccount = null;
foreach ($bankAccounts as $account) {
    if ($account['id'] === $selectedAccountId) {
        $bankAccount = $account;
        break;
    }
}

$bankBalanceAsOn = ['signed' => 0.0, 'type' => 'DR', 'amount' => 0.0];
$openingBalanceSigned = 0.0;
if ($bankAccount) {
    $priorMovement = $db->fetch(
        "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS dr_total,
                COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS cr_total
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = ?
           AND je.status = 'POSTED'
           AND je.entry_date < ?",
        [$bankAccount['id'], $dateFrom]
    );

    $asOnMovement = $db->fetch(
        "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS dr_total,
                COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS cr_total
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = ?
           AND je.status = 'POSTED'
           AND je.entry_date <= ?",
        [$bankAccount['id'], $dateTo]
    );

    $signedOpening = empty($bankAccount['opening_entry_id'])
        ? signedBalanceValue($bankAccount['opening_balance'] ?? 0, $bankAccount['opening_balance_type'] ?? 'DR')
        : 0.0;
    $openingBalanceSigned = round($signedOpening + floatval($priorMovement['dr_total'] ?? 0) - floatval($priorMovement['cr_total'] ?? 0), 2);
    $asOnSigned = round($signedOpening + floatval($asOnMovement['dr_total'] ?? 0) - floatval($asOnMovement['cr_total'] ?? 0), 2);
    $bankBalanceAsOn = [
        'signed' => $asOnSigned,
        'type' => $asOnSigned >= 0 ? 'DR' : 'CR',
        'amount' => abs($asOnSigned),
    ];
}

$entries = [];
if ($bankAccount) {
    $entries = $db->fetchAll(
        "SELECT je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
         FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = ? AND je.status = 'POSTED' AND je.entry_date BETWEEN ? AND ?
         ORDER BY je.entry_date, je.created_at",
        [$bankAccount['id'], $dateFrom, $dateTo]
    );
}

$displayEntries = [];
$bal = $openingBalanceSigned;
$totalDr = 0;
$totalCr = 0;
foreach ($entries as $entry) {
    if ($entry['entry_type'] === 'DR') {
        $bal += $entry['amount'];
        $totalDr += $entry['amount'];
    } else {
        $bal -= $entry['amount'];
        $totalCr += $entry['amount'];
    }
    $entry['running_balance'] = $bal;
    $displayEntries[] = $entry;
}
$displayEntries = array_reverse($displayEntries);
?>

<div class="page-header">
    <h1><i class="ri-bank-line"></i> Bank Book</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex;gap:12px;align-items:end;">
        <?php if (count($bankAccounts) > 1): ?>
            <div>
                <label class="form-label">Bank Account</label>
                <select name="account_id" class="form-control searchable-select">
                    <?php foreach ($bankAccounts as $account): ?>
                        <option value="<?= clean($account['id']) ?>" <?= ($bankAccount['id'] ?? '') === $account['id'] ? 'selected' : '' ?>><?= clean($account['name']) ?> (<?= clean($account['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= $dateFrom ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= $dateTo ?>"></div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Apply</button>
    </form>
</div>

<?php if (!$bankAccount): ?>
    <div class="alert alert-info"><i class="ri-information-line"></i> No active bank account found. Add one from Account Settings.</div>
<?php endif; ?>

<div class="card" style="margin-bottom: 16px;">
    <div class="card-body"><span class="text-muted">Balance As On <?= formatDate($dateTo) ?>:</span> <strong class="amount <?= ($bankBalanceAsOn['type'] ?? 'DR') === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($bankBalanceAsOn['amount'] ?? 0) ?> <?= $bankBalanceAsOn['type'] ?? 'DR' ?></strong></div>
</div>

<div class="table-container table-container-fill">
    <table class="table-total-room">
        <thead><tr><th>Date / Time</th><th>Ref</th><th>Type</th><th>Narration</th><th class="text-right debit-amount">Deposit (Dr)</th><th class="text-right credit-amount">Withdrawal (Cr)</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($displayEntries as $e): ?>
        <tr>
            <td><?= renderDateTimeStack($e['entry_date'], $e['created_at']) ?></td><td><?= $e['reference_no'] ?></td>
            <td><span class="badge badge-blue" style="font-size:10px;"><?= clean(transactionTypeLabel($e['transaction_type'], $e)) ?></span></td>
            <td><?= clean(mb_substr($e['narration']??'',0,50)) ?></td>
            <td class="text-right amount debit-amount"><?= $e['entry_type']==='DR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount credit-amount"><?= $e['entry_type']==='CR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount <?= $e['running_balance'] >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($e['running_balance']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">Total</td>
                <td class="text-right amount debit-amount"><?= formatAmount($totalDr) ?></td>
                <td class="text-right amount credit-amount"><?= formatAmount($totalCr) ?></td>
                <td class="text-right amount <?= $bal >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($bal) ?></td>
            </tr>
        </tfoot>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
