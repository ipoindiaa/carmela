<?php
$pageTitle = 'Cash Book';
$pageIcon = '<i class="ri-book-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('cash_book', 'read');
$businessId = Auth::user('business_id');
$dateFrom = get('from', getCurrentFY() . '-04-01');
$dateTo = get('to', date('Y-m-d'));
$reportDay = trim((string) get('day', ''));
if ($reportDay !== '') {
    $day = DateTime::createFromFormat('!Y-m-d', $reportDay);
    if ($day && $day->format('Y-m-d') === $reportDay) {
        // A day report is simply a one-day ledger period. Keeping the same
        // journal query means its opening and closing figures reconcile with
        // every other Cash Book date-range report.
        $dateFrom = $reportDay;
        $dateTo = $reportDay;
    } else {
        $reportDay = '';
    }
}
$cashAccounts = $db->fetchAll(
    "SELECT * FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND entity_id IS NULL AND is_active = 1 ORDER BY code, name",
    [$businessId]
);
$selectedAccountId = get('account_id', $cashAccounts[0]['id'] ?? '');
$cashAccount = null;
foreach ($cashAccounts as $account) {
    if ($account['id'] === $selectedAccountId) {
        $cashAccount = $account;
        break;
    }
}

$openingBalanceSigned = 0.0;
if ($cashAccount) {
    $priorMovement = $db->fetch(
        "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS dr_total,
                COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS cr_total
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = ?
           AND je.status IN ('POSTED','REVERSED')
           AND je.entry_date < ?",
        [$cashAccount['id'], $dateFrom]
    );

    $signedOpening = empty($cashAccount['opening_entry_id'])
        ? signedBalanceValue($cashAccount['opening_balance'] ?? 0, $cashAccount['opening_balance_type'] ?? 'DR')
        : 0.0;
    $openingBalanceSigned = round($signedOpening + floatval($priorMovement['dr_total'] ?? 0) - floatval($priorMovement['cr_total'] ?? 0), 2);
}

$entries = [];
if ($cashAccount) {
    $entries = $db->fetchAll(
        "SELECT je.id AS entry_id, je.business_id, je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, je.entry_type_id, je.entry_amount, jl.amount, jl.entry_type,
                c.registration_no AS car_registration_no, dc.name AS party_name
         FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
         LEFT JOIN cars c ON c.id = je.car_id AND c.business_id = je.business_id
         LEFT JOIN debtors_creditors dc ON dc.id = je.party_id AND dc.business_id = je.business_id
         WHERE jl.account_id = ? AND je.status IN ('POSTED','REVERSED') AND je.entry_date BETWEEN ? AND ?
         ORDER BY je.entry_date, je.created_at",
        [$cashAccount['id'], $dateFrom, $dateTo]
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
$openingBalanceType = $openingBalanceSigned >= 0 ? 'DR' : 'CR';
$closingBalanceType = $bal >= 0 ? 'DR' : 'CR';
?>

<div class="page-header">
    <h1><i class="ri-book-2-line"></i> Cash Book</h1>
    <button type="button" onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" class="filter-form">
        <?php if (count($cashAccounts) > 1): ?>
            <div>
                <label class="form-label">Cash Account</label>
                <select name="account_id" class="form-control searchable-select">
                    <?php foreach ($cashAccounts as $account): ?>
                        <option value="<?= clean($account['id']) ?>" <?= ($cashAccount['id'] ?? '') === $account['id'] ? 'selected' : '' ?>><?= clean($account['name']) ?> (<?= clean($account['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div>
            <label class="form-label">Daily Report</label>
            <input type="date" name="day" class="form-control" value="<?= clean($reportDay) ?>">
            <div class="form-hint">For one day, this overrides From and To.</div>
        </div>
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= $dateFrom ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= $dateTo ?>"></div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Apply</button>
    </form>
</div>

<?php if (!$cashAccount): ?>
    <div class="alert alert-info"><i class="ri-information-line"></i> No active cash account found. Add one from Account Settings.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body summary-strip">
        <div><span class="text-muted">Report Period:</span> <strong><?= formatDate($dateFrom) ?><?= $dateFrom !== $dateTo ? ' to ' . formatDate($dateTo) : '' ?></strong></div>
        <div><span class="text-muted">Opening Balance:</span> <strong class="amount <?= $openingBalanceType === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount(abs($openingBalanceSigned)) ?> <?= $openingBalanceType ?></strong></div>
        <div><span class="text-muted">Closing Balance:</span> <strong class="amount <?= $closingBalanceType === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount(abs($bal)) ?> <?= $closingBalanceType ?></strong></div>
    </div>
</div>

<div class="table-container table-container-fill">
    <table class="table-total-room">
        <thead><tr><th>Date / Time</th><th>Ref</th><th>Type</th><th>Car / Party</th><th>Narration</th><th class="text-right debit-amount">Receipt (Dr)</th><th class="text-right credit-amount">Payment (Cr)</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <tr class="table-summary-row">
            <td><?= formatDate($dateFrom) ?><div class="table-secondary">Start of report</div></td>
            <td colspan="4"><strong>Opening Balance</strong></td>
            <td></td><td></td>
            <td class="text-right amount <?= $openingBalanceType === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount(abs($openingBalanceSigned)) ?> <?= $openingBalanceType ?></td>
        </tr>
        <?php foreach ($displayEntries as $e): ?>
        <tr>
            <td><?= renderDateTimeStack($e['entry_date'], $e['created_at']) ?></td><td><a class="text-bold" href="../transactions/view.php?id=<?= urlencode($e['entry_id']) ?>"><?= clean($e['reference_no']) ?></a></td>
            <td><span class="badge badge-blue"><?= clean(transactionTypeLabel($e['transaction_type'], $e)) ?></span></td>
            <td><?= clean($e['car_registration_no'] ?: '-') ?><?= !empty($e['party_name']) ? '<div class="text-muted">' . clean($e['party_name']) . '</div>' : '' ?></td>
            <td><?= clean(mb_substr($e['narration']??'',0,50)) ?></td>
            <td class="text-right amount debit-amount"><?= $e['entry_type']==='DR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount credit-amount"><?= $e['entry_type']==='CR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount <?= $e['running_balance'] >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($e['running_balance']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($displayEntries)): ?><tr><td colspan="8" class="text-center text-muted empty-table-cell">No cash transactions for this report period.</td></tr><?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5"><strong>Closing Balance as at <?= formatDate($dateTo) ?></strong></td>
                <td class="text-right amount debit-amount"><?= formatAmount($totalDr) ?></td>
                <td class="text-right amount credit-amount"><?= formatAmount($totalCr) ?></td>
                <td class="text-right amount <?= $closingBalanceType === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount(abs($bal)) ?> <?= $closingBalanceType ?></td>
            </tr>
        </tfoot>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
