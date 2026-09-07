<?php
$pageTitle = 'RTO Day Book';
$pageIcon = '<i class="ri-book-2-line"></i>';
$isRtoDayBookExport = ($_GET['export'] ?? '') === 'csv';
if ($isRtoDayBookExport) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';
    Auth::check();
    $db = Database::getInstance();
} else {
    require_once __DIR__ . '/../includes/header.php';
}
require_once __DIR__ . '/../includes/accounting_engine.php';

Auth::requireBookAccess('rto_book', 'read');
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$rtoOpeningAccount = $engine->getRtoOpeningAccount(false);

$validReportDate = static function ($value, $fallback) {
    $value = trim((string) $value);
    if ($value === '') return $fallback;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
};

$fromDate = $validReportDate(get('from_date', ''), getCurrentFY() . '-04-01');
$toDate = $validReportDate(get('to_date', ''), date('Y-m-d'));
if ($toDate < $fromDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

// This report deliberately reads the RTO journal accounts. It includes the
// original and reversal journal lines, so every daily balance stays tied to
// the same double-entry source used by the RTO Book and financial reports.
$rtoAccountScope = "a.code IN ('RTO-EXP', 'RTO-REC')";
$rtoAccountParams = [];
if (!empty($rtoOpeningAccount['id'])) {
    $rtoAccountScope = "($rtoAccountScope OR a.id = ?)";
    $rtoAccountParams[] = $rtoOpeningAccount['id'];
}

$receivedExpression = "CASE
    WHEN a.code = 'RTO-OPEN' AND jl.entry_type = 'DR' THEN jl.amount
    WHEN a.code IN ('RTO-EXP', 'RTO-REC') AND jl.entry_type = 'CR' THEN jl.amount
    ELSE 0
END";
$paidExpression = "CASE
    WHEN a.code = 'RTO-OPEN' AND jl.entry_type = 'CR' THEN jl.amount
    WHEN a.code IN ('RTO-EXP', 'RTO-REC') AND jl.entry_type = 'DR' THEN jl.amount
    ELSE 0
END";

$openingBalance = 0.0;
if ($rtoOpeningAccount && empty($rtoOpeningAccount['opening_entry_id'])) {
    $openingDate = $rtoOpeningAccount['opening_balance_date'] ?? null;
    if (!$openingDate || $openingDate <= $fromDate) {
        $openingBalance = signedBalanceValue(
            $rtoOpeningAccount['opening_balance'] ?? 0,
            $rtoOpeningAccount['opening_balance_type'] ?? 'DR'
        );
    }
}

$priorMovement = $db->fetch(
    "SELECT COALESCE(SUM($receivedExpression), 0) AS received,
            COALESCE(SUM($paidExpression), 0) AS paid
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     JOIN accounts a ON a.id = jl.account_id
     WHERE je.business_id = ?
       AND je.status IN ('POSTED', 'REVERSED')
       AND je.entry_date < ?
       AND $rtoAccountScope",
    array_merge([$businessId, $fromDate], $rtoAccountParams)
);
$openingBalance = round(
    $openingBalance + floatval($priorMovement['received'] ?? 0) - floatval($priorMovement['paid'] ?? 0),
    2
);

$rtoRows = $db->fetchAll(
    "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type,
            original.transaction_type AS source_transaction_type,
            je.car_id, c.registration_no AS car_reg,
            COALESCE(expense_record.party_name, recovery_record.party_name) AS party_name,
            COALESCE(expense_record.agent_name, recovery_record.agent_name) AS agent_name,
            COALESCE(SUM($paidExpression), 0) AS money_paid,
            COALESCE(SUM($receivedExpression), 0) AS money_received
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     JOIN accounts a ON a.id = jl.account_id
     LEFT JOIN journal_entries original ON original.id = je.original_entry_id AND original.business_id = je.business_id
     LEFT JOIN cars c ON c.id = je.car_id AND c.business_id = je.business_id
     LEFT JOIN rto_records expense_record
        ON expense_record.business_id = je.business_id
       AND expense_record.expense_entry_id = COALESCE(je.original_entry_id, je.id)
     LEFT JOIN rto_recoveries recovery
        ON recovery.business_id = je.business_id
       AND recovery.journal_entry_id = COALESCE(je.original_entry_id, je.id)
     LEFT JOIN rto_records recovery_record
        ON recovery_record.id = recovery.rto_record_id AND recovery_record.business_id = je.business_id
     WHERE je.business_id = ?
       AND je.status IN ('POSTED', 'REVERSED')
       AND je.entry_date BETWEEN ? AND ?
       AND $rtoAccountScope
     GROUP BY je.id
     HAVING ABS(money_paid) > 0.009 OR ABS(money_received) > 0.009
     ORDER BY je.entry_date ASC, je.created_at ASC, je.reference_no ASC",
    array_merge([$businessId, $fromDate, $toDate], $rtoAccountParams)
);

$rowsByDate = [];
foreach ($rtoRows as $row) {
    $rowsByDate[$row['entry_date']][] = $row;
}

$rtoDays = [];
$totalDebit = 0.0;
$totalCredit = 0.0;
$runningBalance = $openingBalance;
$cursor = new DateTime($fromDate);
$lastDate = new DateTime($toDate);
while ($cursor <= $lastDate) {
    $dateKey = $cursor->format('Y-m-d');
    $dayOpening = $runningBalance;
    $dayDebit = 0.0;
    $dayCredit = 0.0;
    $dayEntries = [];
    foreach ($rowsByDate[$dateKey] ?? [] as $row) {
        $debit = round(floatval($row['money_paid']), 2);
        $credit = round(floatval($row['money_received']), 2);
        $runningBalance = round($runningBalance + $credit - $debit, 2);
        $dayDebit += $debit;
        $dayCredit += $credit;
        $row['debit_amount'] = $debit;
        $row['credit_amount'] = $credit;
        $row['running_balance'] = $runningBalance;
        $dayEntries[] = $row;
    }
    $totalDebit += $dayDebit;
    $totalCredit += $dayCredit;
    $rtoDays[] = [
        'date' => $dateKey,
        'opening' => $dayOpening,
        'entries' => $dayEntries,
        'total_debit' => $dayDebit,
        'total_credit' => $dayCredit,
        'closing' => $runningBalance,
    ];
    $cursor->modify('+1 day');
}
$closingBalance = $runningBalance;

$formatRtoBalance = static function ($amount) {
    $amount = round(floatval($amount), 2);
    return formatAmount(abs($amount)) . ' ' . ($amount >= 0 ? 'DR' : 'CR');
};
$rtoDayBookTypeLabel = static function (array $entry) {
    if (($entry['transaction_type'] ?? '') === 'REVERSAL' && !empty($entry['source_transaction_type'])) {
        return transactionTypeLabel($entry['source_transaction_type']) . ' Reversal';
    }
    if (($entry['transaction_type'] ?? '') === 'OPENING_BALANCE') return 'RTO Opening Balance';
    return transactionTypeLabel($entry['transaction_type'], $entry);
};

if ($isRtoDayBookExport) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rto-day-book-' . $fromDate . '-to-' . $toDate . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Opening Balance', 'Reference', 'Type', 'Narration', 'Related', 'Debit (RTO Paid)', 'Credit (RTO Received)', 'Closing Balance']);
    foreach ($rtoDays as $day) {
        if (empty($day['entries'])) {
            fputcsv($output, [$day['date'], $day['opening'], '', 'No RTO movement', '', '', 0, 0, $day['closing']]);
            continue;
        }
        foreach ($day['entries'] as $index => $entry) {
            $related = trim(($entry['car_reg'] ? formatRegistrationNo($entry['car_reg']) : '') . ' ' . ($entry['party_name'] ?: $entry['agent_name'] ?: ''));
            fputcsv($output, [
                $day['date'],
                $index === 0 ? $day['opening'] : '',
                $entry['reference_no'],
                $rtoDayBookTypeLabel($entry),
                $entry['narration'],
                $related,
                $entry['debit_amount'],
                $entry['credit_amount'],
                $index === count($day['entries']) - 1 ? $day['closing'] : '',
            ]);
        }
        fputcsv($output, [$day['date'], '', 'DAY TOTAL', '', '', '', $day['total_debit'], $day['total_credit'], $day['closing']]);
    }
    fputcsv($output, ['REPORT TOTAL', $openingBalance, '', '', '', '', $totalDebit, $totalCredit, $closingBalance]);
    fclose($output);
    exit;
}
?>

<div class="page-header entries-page-header">
    <div>
        <h1><i class="ri-book-2-line"></i> RTO Day Book</h1>
        <div class="text-muted">Date-wise RTO receipts, payments, and carry-forward balance.</div>
    </div>
    <div class="page-actions">
        <a href="list.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> RTO Book</a>
        <?php if (Auth::isAdmin() && $rtoOpeningAccount): ?><a href="../settings/opening_balances.php?account_id=<?= clean($rtoOpeningAccount['id']) ?>&amp;return=rto" class="btn btn-outline"><i class="ri-scales-3-line"></i> RTO Opening Balance</a><?php endif; ?>
    </div>
</div>

<div class="filter-bar entries-filter-bar">
    <form method="GET" class="entries-filter-form">
        <div class="entries-filter-field entries-filter-date">
            <label class="form-label">From</label>
            <input type="date" name="from_date" class="form-control" value="<?= clean($fromDate) ?>">
        </div>
        <div class="entries-filter-field entries-filter-date">
            <label class="form-label">To</label>
            <input type="date" name="to_date" class="form-control" value="<?= clean($toDate) ?>">
        </div>
        <div class="entries-filter-actions">
            <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Generate Report</button>
            <a href="day_book.php" class="btn btn-outline btn-sm">Clear</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-body summary-strip">
        <div><span class="text-muted">RTO Book:</span> <strong>RTO receipts and payments</strong></div>
        <div><span class="text-muted">Opening:</span> <strong class="amount <?= $openingBalance >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRtoBalance($openingBalance) ?></strong></div>
        <div><span class="text-muted">Total Debit / Paid:</span> <strong class="amount credit-amount"><?= formatAmount($totalDebit) ?></strong></div>
        <div><span class="text-muted">Total Credit / Received:</span> <strong class="amount debit-amount"><?= formatAmount($totalCredit) ?></strong></div>
        <div><span class="text-muted">Closing:</span> <strong class="amount <?= $closingBalance >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRtoBalance($closingBalance) ?></strong></div>
    </div>
</div>

<div class="alert alert-info">
    <i class="ri-information-line"></i>
    <div><strong>How this RTO Day Book works</strong><span>Closing Balance = Opening Balance + Credit (RTO received) − Debit (RTO paid). Each date carries its closing balance into the next date. The report is built from posted RTO journal lines, including reversals, so it stays matched to the RTO Book and financial reports.</span></div>
</div>

<?php $exportUrl = 'day_book.php?' . http_build_query(['from_date' => $fromDate, 'to_date' => $toDate, 'export' => 'csv']); ?>
<div class="page-actions report-actions">
    <a href="<?= clean($exportUrl) ?>" class="btn btn-outline btn-sm"><i class="ri-download-2-line"></i> Download CSV</a>
    <button type="button" onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="table-container table-container-fill">
    <table class="table-total-room">
        <thead><tr><th>Date / Time</th><th>Reference</th><th>Type</th><th>Narration</th><th>Related</th><th class="text-right credit-amount">Debit / Paid</th><th class="text-right debit-amount">Credit / Received</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($rtoDays as $day): ?>
            <tr class="table-group-row"><td colspan="8"><strong><?= formatDate($day['date']) ?></strong> <span class="text-muted">Opening Balance: <?= $formatRtoBalance($day['opening']) ?></span></td></tr>
            <?php foreach ($day['entries'] as $entry): ?>
            <tr>
                <td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td>
                <td><a href="../transactions/view.php?id=<?= clean($entry['id']) ?>" class="text-bold"><?= clean($entry['reference_no']) ?></a></td>
                <td><span class="badge badge-blue"><?= clean($rtoDayBookTypeLabel($entry)) ?></span></td>
                <td class="narration-cell"><?= clean(mb_strimwidth((string) ($entry['narration'] ?? ''), 0, 58, '…')) ?></td>
                <td class="text-muted">
                    <?php if (!empty($entry['car_reg'])): ?><i class="ri-car-line"></i> <?= clean(formatRegistrationNo($entry['car_reg'])) ?><?php endif; ?>
                    <?php if (!empty($entry['party_name'])): ?><div><?= clean($entry['party_name']) ?></div><?php endif; ?>
                    <?php if (!empty($entry['agent_name'])): ?><div><?= clean($entry['agent_name']) ?></div><?php endif; ?>
                </td>
                <td class="text-right amount credit-amount"><?= $entry['debit_amount'] > 0.009 ? formatAmount($entry['debit_amount']) : '—' ?></td>
                <td class="text-right amount debit-amount"><?= $entry['credit_amount'] > 0.009 ? formatAmount($entry['credit_amount']) : '—' ?></td>
                <td class="text-right amount <?= $entry['running_balance'] >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRtoBalance($entry['running_balance']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($day['entries'])): ?><tr><td><?= formatDate($day['date']) ?></td><td colspan="6" class="text-muted">No RTO movement</td><td class="text-right amount <?= $day['closing'] >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRtoBalance($day['closing']) ?></td></tr><?php endif; ?>
            <tr class="table-summary-row"><td colspan="5">Daily Total · Closing Balance</td><td class="text-right amount credit-amount"><?= formatAmount($day['total_debit']) ?></td><td class="text-right amount debit-amount"><?= formatAmount($day['total_credit']) ?></td><td class="text-right amount <?= $day['closing'] >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRtoBalance($day['closing']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="5"><strong>Report Total · Closing Balance as at <?= formatDate($toDate) ?></strong></td><td class="text-right amount credit-amount"><?= formatAmount($totalDebit) ?></td><td class="text-right amount debit-amount"><?= formatAmount($totalCredit) ?></td><td class="text-right amount <?= $closingBalance >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRtoBalance($closingBalance) ?></td></tr></tfoot>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
