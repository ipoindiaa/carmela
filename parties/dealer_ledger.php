<?php
$pageTitle = 'Dealer / Broker Ledger';
$pageIcon = '<i class="ri-user-shared-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
Auth::requireEntityAccess('party', 'read');
$id = get('id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

$party = $db->fetch(
    "SELECT dc.*, a.current_balance, a.current_balance_type, a.code AS account_code, a.name AS account_name
     FROM debtors_creditors dc
     LEFT JOIN accounts a ON a.id = dc.account_id
     WHERE dc.id = ? AND dc.business_id = ?",
    [$id, $businessId]
);
if (!$party) { setFlash('error', 'Party not found.'); redirect('list.php'); }

$carSettlements = $engine->getDealerCarSettlements($id);
$commissionTotal = round(array_sum(array_column($carSettlements, 'commission_total')), 2);
$paidTotal = round(array_sum(array_column($carSettlements, 'paid_total')), 2);
$pendingTotal = round(array_sum(array_column($carSettlements, 'pending')), 2);

// One flat, car-wise movement list: every commission created and every payment.
$movements = [];
foreach ($carSettlements as $settlement) {
    foreach ($settlement['history'] as $row) {
        $row['car'] = $settlement['car'];
        $movements[] = $row;
    }
}
usort($movements, static function ($left, $right) {
    return strtotime(($right['entry_date'] ?? '') . ' ' . ($right['created_at'] ?? ''))
        <=> strtotime(($left['entry_date'] ?? '') . ' ' . ($left['created_at'] ?? ''));
});
?>

<div class="breadcrumb"><a href="../dashboard.php">Home</a><span>/</span><a href="list.php">Debtors &amp; Creditors</a><span>/</span><a href="view.php?id=<?= clean($id) ?>"><?= clean($party['name']) ?></a><span>/</span><span>Dealer Ledger</span></div>

<div class="page-header">
    <div>
        <h1><i class="ri-user-shared-line"></i> <?= clean($party['name']) ?></h1>
        <p class="page-subtitle">Purchase dealer / broker commission ledger<?= !empty($party['account_code']) ? ' · ' . clean($party['account_code']) : '' ?></p>
    </div>
    <div class="page-actions">
        <a href="view.php?id=<?= clean($id) ?>" class="btn btn-outline btn-sm"><i class="ri-file-list-3-line"></i> Full Statement</a>
        <?php if (!empty($party['account_id'])): ?><a href="<?= clean(accountLedgerUrl($party['account_id'])) ?>" class="btn btn-outline btn-sm"><i class="ri-book-2-line"></i> Account Ledger</a><?php endif; ?>
        <a href="list.php" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-profile-line"></i> Dealer Details</h3></div>
    <div class="card-body">
        <div class="table-container table-container-inline table-columns-compact">
            <table class="detail-table">
                <tr><td class="text-muted">Name</td><td class="text-bold"><?= clean($party['name']) ?></td></tr>
                <tr><td class="text-muted">Party Type</td><td><span class="badge badge-yellow"><?= clean(partyTypeLabel($party['type'])) ?></span></td></tr>
                <tr><td class="text-muted">Mobile</td><td><?= clean($party['phone'] ?: '—') ?></td></tr>
                <tr><td class="text-muted">PAN / GSTIN</td><td><?= clean($party['pan_gstin'] ?: '—') ?></td></tr>
                <tr><td class="text-muted">Address / Notes</td><td><?= clean($party['address'] ?: '—') ?></td></tr>
                <tr><td class="text-muted">Linked Payable Account</td><td><?php if (!empty($party['account_id'])): ?><a href="<?= clean(accountLedgerUrl($party['account_id'])) ?>"><?= clean($party['account_name']) ?> (<?= clean($party['account_code']) ?>)</a><?php else: ?>—<?php endif; ?></td></tr>
            </table>
        </div>
    </div>
</div>

<div class="stats-grid compact-operational-grid">
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($commissionTotal) ?></div><div class="stat-label">Total Commission Payable</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($paidTotal) ?></div><div class="stat-label">Total Paid</div></div>
    <div class="stat-card"><div class="stat-value <?= $pendingTotal > 0.009 ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($pendingTotal) ?></div><div class="stat-label">Pending Balance</div></div>
    <div class="stat-card"><div class="stat-value"><?= count($carSettlements) ?></div><div class="stat-label">Linked Cars</div></div>
</div>

<div class="card">
    <div class="card-header"><div><h3><i class="ri-car-line"></i> Car-wise Commission History</h3><div class="card-header-note">Every car bought through this dealer, with its own commission and settlement position.</div></div></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Car</th><th>Make / Model</th><th>Purchase Date</th><th class="text-right">Commission Created</th><th class="text-right">Commission Paid</th><th class="text-right">Pending Balance</th><th class="text-center">Action</th></tr></thead>
                <tbody>
                <?php foreach ($carSettlements as $settlement): $rowCar = $settlement['car']; ?>
                    <tr>
                        <td><a href="../cars/view.php?id=<?= urlencode($rowCar['id']) ?>" class="text-bold"><?= clean(formatRegistrationNo($rowCar['registration_no'])) ?></a></td>
                        <td><?= clean(trim(($rowCar['make'] ?? '') . ' ' . ($rowCar['model'] ?? '')) ?: '—') ?></td>
                        <td><?= formatDate($rowCar['purchase_date']) ?></td>
                        <td class="text-right amount flow-out"><?= formatAmount($settlement['commission_total']) ?></td>
                        <td class="text-right amount flow-in"><?= formatAmount($settlement['paid_total']) ?></td>
                        <td class="text-right amount <?= $settlement['pending'] > 0.009 ? 'flow-out' : '' ?>"><?= formatAmount($settlement['pending']) ?></td>
                        <td class="text-center"><a href="../cars/dealer_payment.php?id=<?= urlencode($rowCar['id']) ?>" class="btn btn-sm btn-outline"><i class="ri-secure-payment-line"></i> <?= $settlement['pending'] > 0.009 ? 'Pay' : 'View' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($carSettlements)): ?><tr><td colspan="7" class="text-center text-muted empty-table-cell">No car is linked to this dealer / broker yet.</td></tr><?php endif; ?>
                </tbody>
                <?php if (!empty($carSettlements)): ?>
                <tfoot><tr><th colspan="3" class="text-right">Totals</th><th class="text-right amount flow-out"><?= formatAmount($commissionTotal) ?></th><th class="text-right amount flow-in"><?= formatAmount($paidTotal) ?></th><th class="text-right amount"><?= formatAmount($pendingTotal) ?></th><th></th></tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><div><h3><i class="ri-exchange-funds-line"></i> Commission &amp; Payment Movements</h3><div class="card-header-note">Every dealer entry with its car, reference number, date, and cash/bank source.</div></div></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Date / Time</th><th>Reference</th><th>Car</th><th>What Happened</th><th>Cash / Bank</th><th class="text-right">Commission Created</th><th class="text-right">Paid to Dealer</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $row): ?><?php
                    $isPayment = $row['entry_type'] === 'DR';
                    $paymentSource = $isPayment && !empty($row['payment_account_name'])
                        ? trim($row['payment_account_name'] . (!empty($row['payment_account_code']) ? ' (' . $row['payment_account_code'] . ')' : '')) : '—';
                ?><tr>
                    <td><?= renderDateTimeStack($row['entry_date'], $row['created_at']) ?></td>
                    <td><a class="text-bold" href="../transactions/view.php?id=<?= urlencode($row['id']) ?>"><?= clean($row['reference_no']) ?></a></td>
                    <td><a href="../cars/view.php?id=<?= urlencode($row['car']['id']) ?>"><?= clean(formatRegistrationNo($row['car']['registration_no'])) ?></a></td>
                    <td><?= $isPayment ? 'Commission paid to dealer' : 'Dealer commission payable created' ?><?php if (!empty($row['narration'])): ?><div class="text-muted"><?= clean(mb_substr($row['narration'], 0, 70)) ?></div><?php endif; ?></td>
                    <td><?= clean($paymentSource) ?></td>
                    <td class="text-right amount flow-out"><?= !$isPayment ? formatAmount($row['amount']) : '—' ?></td>
                    <td class="text-right amount flow-in"><?= $isPayment ? formatAmount($row['amount']) : '—' ?></td>
                </tr><?php endforeach; ?>
                <?php if (empty($movements)): ?><tr><td colspan="7" class="text-center text-muted empty-table-cell">No dealer commission movement recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
