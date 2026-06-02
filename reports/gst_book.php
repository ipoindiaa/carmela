<?php
$pageTitle = 'GST Book';
$pageIcon = '<i class="ri-file-list-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('gst_book', 'read');

$businessId = Auth::user('business_id');
$dateFrom = get('from', getCurrentFY() . '-04-01');
$dateTo = get('to', date('Y-m-d'));

$gstBanks = $db->fetchAll(
    "SELECT * FROM accounts WHERE business_id = ? AND entity_type = 'GST' AND entity_id IS NULL AND is_active = 1 ORDER BY code, name",
    [$businessId]
);
$selectedGstBankId = get('account_id', $gstBanks[0]['id'] ?? '');
$gstBank = null;
foreach ($gstBanks as $account) {
    if ($account['id'] === $selectedGstBankId) {
        $gstBank = $account;
        break;
    }
}
$gstPayable = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'GST-PAY'", [$businessId]);
$gstInput = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'GST-RCV'", [$businessId]);

$getAsOnBalance = static function ($account) use ($db, $businessId, $dateTo) {
    if (!$account || empty($account['id'])) {
        return ['amount' => 0.0, 'type' => 'DR', 'signed' => 0.0];
    }

    $posted = $db->fetch(
        "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS dr_total,
                COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS cr_total
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE je.business_id = ?
           AND je.status = 'POSTED'
           AND jl.account_id = ?
           AND je.entry_date <= ?",
        [$businessId, $account['id'], $dateTo]
    );

    $signedOpening = signedBalanceValue($account['opening_balance'] ?? 0, $account['opening_balance_type'] ?? 'DR');
    $signed = round($signedOpening + floatval($posted['dr_total'] ?? 0) - floatval($posted['cr_total'] ?? 0), 2);

    return [
        'amount' => abs($signed),
        'type' => $signed >= 0 ? 'DR' : 'CR',
        'signed' => $signed,
    ];
};

$gstBankAsOn = $getAsOnBalance($gstBank);
$gstPayableAsOn = $getAsOnBalance($gstPayable);
$gstInputAsOn = $getAsOnBalance($gstInput);

$entries = [];
if ($gstBank) {
    $entries = $db->fetchAll(
        "SELECT je.entry_date, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = ?
           AND je.status = 'POSTED'
           AND je.entry_date BETWEEN ? AND ?
         ORDER BY je.entry_date, je.created_at",
        [$gstBank['id'], $dateFrom, $dateTo]
    );
}

$gstPaid = $db->fetch(
    "SELECT COALESCE(SUM(CASE WHEN jl.account_id = ? AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS total
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE je.business_id = ?
       AND je.status = 'POSTED'
       AND je.transaction_type = 'GST_PAYMENT'
       AND je.entry_date BETWEEN ? AND ?",
    [$gstPayable['id'] ?? '', $businessId, $dateFrom, $dateTo]
);

$gstInputClaimed = $db->fetch(
    "SELECT COALESCE(SUM(CASE WHEN jl.account_id = ? AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS total
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE je.business_id = ?
       AND je.status = 'POSTED'
       AND je.entry_date BETWEEN ? AND ?",
    [$gstInput['id'] ?? '', $businessId, $dateFrom, $dateTo]
);

$gstOutputCollected = $db->fetch(
    "SELECT COALESCE(SUM(CASE WHEN jl.account_id = ? AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS total
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE je.business_id = ?
       AND je.status = 'POSTED'
       AND je.entry_date BETWEEN ? AND ?",
    [$gstPayable['id'] ?? '', $businessId, $dateFrom, $dateTo]
);

$gstInputUtilized = $db->fetch(
    "SELECT COALESCE(SUM(CASE WHEN jl.account_id = ? AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS total
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE je.business_id = ?
       AND je.status = 'POSTED'
       AND je.transaction_type = 'GST_UTILIZATION'
       AND je.entry_date BETWEEN ? AND ?",
    [$gstInput['id'] ?? '', $businessId, $dateFrom, $dateTo]
);

$gstRegister = $db->fetchAll(
    "SELECT je.id,
            je.entry_date,
            je.reference_no,
            je.transaction_type,
            je.narration,
            COALESCE(SUM(CASE WHEN jl.account_id = ? AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS input_gst,
            COALESCE(SUM(CASE WHEN jl.account_id = ? AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS output_gst,
            COALESCE(SUM(CASE WHEN jl.account_id = ? AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS input_utilized,
            COALESCE(SUM(CASE WHEN jl.account_id = ? AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS gst_paid
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     WHERE je.business_id = ?
       AND je.status = 'POSTED'
       AND je.entry_date BETWEEN ? AND ?
       AND jl.account_id IN (?, ?)
     GROUP BY je.id, je.entry_date, je.reference_no, je.transaction_type, je.narration
     HAVING input_gst > 0.009 OR output_gst > 0.009 OR input_utilized > 0.009 OR gst_paid > 0.009
     ORDER BY je.entry_date DESC, je.created_at DESC",
    [
        $gstInput['id'] ?? '',
        $gstPayable['id'] ?? '',
        $gstInput['id'] ?? '',
        $gstPayable['id'] ?? '',
        $businessId,
        $dateFrom,
        $dateTo,
        $gstInput['id'] ?? '',
        $gstPayable['id'] ?? '',
    ]
);

$netGstMovement = round(floatval($gstOutputCollected['total'] ?? 0) - floatval($gstInputClaimed['total'] ?? 0), 2);
$netPositionAsOn = round(abs(min(0, $gstPayableAsOn['signed'])) - max(0, $gstInputAsOn['signed']), 2);
?>

<div class="page-header">
    <h1><i class="ri-file-list-2-line"></i> GST Book</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex;gap:12px;align-items:end;">
        <?php if (count($gstBanks) > 1): ?>
            <div>
                <label class="form-label">GST Bank</label>
                <select name="account_id" class="form-control searchable-select">
                    <?php foreach ($gstBanks as $account): ?>
                        <option value="<?= clean($account['id']) ?>" <?= ($gstBank['id'] ?? '') === $account['id'] ? 'selected' : '' ?>><?= clean($account['name']) ?> (<?= clean($account['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= clean($dateFrom) ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= clean($dateTo) ?>"></div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Apply</button>
    </form>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
    <div class="stat-card">
        <div class="stat-value <?= ($gstBankAsOn['type'] ?? 'DR') === 'DR' ? 'debit-amount' : 'credit-amount' ?>">
            <?= formatAmount($gstBankAsOn['amount'] ?? 0) ?>
        </div>
        <div class="stat-label"><?= clean($gstBank['name'] ?? 'GST Bank') ?> Balance As On Date</div>
    </div>
    <div class="stat-card">
        <div class="stat-value <?= ($gstPayableAsOn['type'] ?? 'CR') === 'CR' ? 'credit-amount' : 'debit-amount' ?>">
            <?= formatAmount($gstPayableAsOn['amount'] ?? 0) ?>
        </div>
        <div class="stat-label">GST Payable Balance As On Date</div>
    </div>
    <div class="stat-card">
        <div class="stat-value <?= ($gstInputAsOn['type'] ?? 'DR') === 'DR' ? 'debit-amount' : 'credit-amount' ?>">
            <?= formatAmount($gstInputAsOn['amount'] ?? 0) ?>
        </div>
        <div class="stat-label">GST Input Credit As On Date</div>
    </div>
    <div class="stat-card">
        <div class="stat-value <?= $netPositionAsOn >= 0 ? 'credit-amount' : 'debit-amount' ?>"><?= formatAmount(abs($netPositionAsOn)) ?></div>
        <div class="stat-label"><?= $netPositionAsOn >= 0 ? 'Net GST Payable As On Date' : 'Net GST Credit As On Date' ?></div>
    </div>
</div>

<?php if (!$gstBank): ?>
    <div class="alert alert-info"><i class="ri-information-line"></i> No active GST bank account found. Add one from Account Settings.</div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 12px;">
    <div class="stat-card">
        <div class="stat-value debit-amount"><?= formatAmount($gstInputClaimed['total'] ?? 0) ?></div>
        <div class="stat-label">Input GST Claimed</div>
    </div>
    <div class="stat-card">
        <div class="stat-value credit-amount"><?= formatAmount($gstOutputCollected['total'] ?? 0) ?></div>
        <div class="stat-label">Output GST Collected</div>
    </div>
    <div class="stat-card">
        <div class="stat-value debit-amount"><?= formatAmount($gstInputUtilized['total'] ?? 0) ?></div>
        <div class="stat-label">Input GST Utilized</div>
    </div>
    <div class="stat-card">
        <div class="stat-value credit-amount"><?= formatAmount($gstPaid['total'] ?? 0) ?></div>
        <div class="stat-label">GST Paid In Range</div>
    </div>
</div>

<div class="card" style="margin-bottom: 16px;">
    <div class="card-body">
        <div class="text-muted" style="font-size: 13px;">
            This view now uses posted journal lines up to the selected date for headline balances, so the cards and the register stay in sync for historical review.
            Input GST utilization is shown separately from GST payment so operators can see whether liability was settled by credit or by cash.
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 16px;">
    <div class="card-body" style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div>
            <div class="text-muted" style="font-size:13px;">Net GST created in this period</div>
            <div class="amount <?= $netGstMovement >= 0 ? 'credit-amount' : 'debit-amount' ?>" style="font-size: 22px; font-weight: 800; margin-top: 4px;"><?= formatAmount(abs($netGstMovement)) ?></div>
        </div>
        <div class="text-muted" style="font-size:13px;max-width:520px;">
            `Output GST Collected - Input GST Claimed` shows how much fresh GST liability or fresh input credit was created during this date range before any utilization or payment.
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 16px;">
    <div class="card-header"><h3><i class="ri-receipt-line"></i> GST Source Register</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Date</th><th>Ref</th><th>Type</th><th>Narration</th><th class="text-right debit-amount">Input GST</th><th class="text-right credit-amount">Output GST</th><th class="text-right debit-amount">Input Utilized</th><th class="text-right credit-amount">GST Paid</th></tr></thead>
                <tbody>
                <?php foreach ($gstRegister as $row): ?>
                    <tr>
                        <td><?= formatDate($row['entry_date']) ?></td>
                        <td><?= clean($row['reference_no']) ?></td>
                        <td><span class="badge badge-blue" style="font-size:10px;"><?= TXN_TYPES[$row['transaction_type']] ?? $row['transaction_type'] ?></span></td>
                        <td><?= clean(mb_substr($row['narration'] ?? '', 0, 60)) ?></td>
                        <td class="text-right amount debit-amount"><?= (float) $row['input_gst'] > 0 ? formatAmount($row['input_gst']) : '' ?></td>
                        <td class="text-right amount credit-amount"><?= (float) $row['output_gst'] > 0 ? formatAmount($row['output_gst']) : '' ?></td>
                        <td class="text-right amount debit-amount"><?= (float) $row['input_utilized'] > 0 ? formatAmount($row['input_utilized']) : '' ?></td>
                        <td class="text-right amount credit-amount"><?= (float) $row['gst_paid'] > 0 ? formatAmount($row['gst_paid']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($gstRegister)): ?><tr><td colspan="8" class="text-center text-muted" style="padding: 28px;">No GST source entries found in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Date</th><th>Ref</th><th>Type</th><th>Narration</th><th class="text-right debit-amount">Receipt (Dr)</th><th class="text-right credit-amount">Payment (Cr)</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php $bal = signedBalanceValue($gstBank['opening_balance'] ?? 0, $gstBank['opening_balance_type'] ?? 'DR'); $totalDr = 0; $totalCr = 0; ?>
        <?php foreach ($entries as $e): ?>
            <?php if ($e['entry_type'] === 'DR') { $bal += $e['amount']; $totalDr += $e['amount']; } else { $bal -= $e['amount']; $totalCr += $e['amount']; } ?>
            <tr>
                <td><?= formatDate($e['entry_date']) ?></td>
                <td><?= clean($e['reference_no']) ?></td>
                <td><span class="badge badge-blue" style="font-size:10px;"><?= TXN_TYPES[$e['transaction_type']] ?? $e['transaction_type'] ?></span></td>
                <td><?= clean(mb_substr($e['narration'] ?? '', 0, 60)) ?></td>
                <td class="text-right amount debit-amount"><?= $e['entry_type'] === 'DR' ? formatAmount($e['amount']) : '' ?></td>
                <td class="text-right amount credit-amount"><?= $e['entry_type'] === 'CR' ? formatAmount($e['amount']) : '' ?></td>
                <td class="text-right amount <?= $bal >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($bal) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?><tr><td colspan="7" class="text-center text-muted" style="padding: 40px;">No GST bank entries found in this period.</td></tr><?php endif; ?>
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
