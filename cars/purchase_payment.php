<?php
$pageTitle = 'Purchase Payments';
$pageIcon = '<i class="ri-hand-coin-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$carId = get('id');
Auth::requireEntityAccess('car', 'read');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$engine->syncCarPartyLinks($carId);

$car = $db->fetch(
    "SELECT * FROM cars WHERE id = ? AND business_id = ?",
    [$carId, $businessId]
);
if (!$car) {
    setFlash('error', 'Car not found.');
    redirect('list.php');
}
if (($car['ownership_type'] ?? 'OWNED') === 'COMMISSION') {
    redirect('commission_view.php?id=' . urlencode($carId));
}

$sellerParty = !empty($car['seller_party_id']) ? $db->fetch(
    "SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?",
    [$car['seller_party_id'], $businessId]
) : null;

$primaryAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'write');
$paymentAccounts = array_merge($primaryAccountGroups['cash_book'] ?? [], $primaryAccountGroups['bank_book'] ?? []);
$paymentAccountIds = array_values(array_filter(array_map(
    static fn($account) => $account['id'] ?? null,
    $paymentAccounts
)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireEntityAccess('car', 'write');
    verifyCsrf();
    try {
        if ($car['status'] === 'CANCELLED') {
            throw new Exception('Deleted cars are read-only. Their history remains available.');
        }
        if (!$sellerParty) {
            throw new Exception('This bought car has no linked seller. Add the seller through a correction before recording a purchase payment.');
        }
        if (post('action') !== 'pay_purchase_balance') {
            throw new Exception('Unknown purchase payment action.');
        }
        if (!in_array(post('payment_account'), $paymentAccountIds, true)) {
            throw new Exception('Select an accessible cash or bank account.');
        }

        $entryId = $engine->loanRepaid(
            $sellerParty['id'],
            parseDecimalInput(post('amount')),
            post('payment_date'),
            post('payment_account'),
            post('narration'),
            $carId
        );
        setFlash('success', 'Purchase payment recorded for ' . formatRegistrationNo($car['registration_no']) . '. Entry: ' . $entryId);
        redirect('purchase_payment.php?id=' . urlencode($carId));
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('purchase_payment.php?id=' . urlencode($carId));
    }
}

$carPending = $engine->getCarPendingAmounts($carId);
$purchasePending = max(0, (float) ($carPending['purchase_pending'] ?? 0));
$paymentTotalRow = $sellerParty ? $db->fetch(
    "SELECT COALESCE(SUM(jl.amount), 0) AS payment_total
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     WHERE je.business_id = ? AND je.status = 'POSTED' AND je.car_id = ?
       AND jl.account_id = ? AND jl.entry_type = 'DR'",
    [$businessId, $carId, $sellerParty['account_id']]
) : null;
$paidToSeller = (float) ($paymentTotalRow['payment_total'] ?? 0);
$sellerPurchaseAmount = $paidToSeller + $purchasePending;

$paymentHistory = $sellerParty ? $db->fetchAll(
    "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.transaction_type, je.narration,
            seller_line.amount, seller_line.entry_type, payment_account.name AS payment_account_name,
            payment_account.code AS payment_account_code
     FROM journal_entries je
     JOIN journal_lines seller_line
       ON seller_line.journal_entry_id = je.id AND seller_line.account_id = ?
     LEFT JOIN journal_lines payment_line
       ON payment_line.journal_entry_id = je.id AND payment_line.entry_type = 'CR'
       AND payment_line.account_id IN (
           SELECT id FROM accounts WHERE business_id = ? AND entity_type IN ('CASH', 'BANK')
       )
     LEFT JOIN accounts payment_account ON payment_account.id = payment_line.account_id
     WHERE je.business_id = ? AND je.car_id = ? AND je.status IN ('POSTED', 'REVERSED')
       AND je.transaction_type IN ('CAR_PURCHASE', 'LOAN_REPAID')
     ORDER BY je.entry_date ASC, je.created_at ASC",
    [$sellerParty['account_id'], $businessId, $businessId, $carId]
) : [];
?>

<div class="breadcrumb"><a href="../dashboard.php">Home</a><span>/</span><a href="list.php">Cars</a><span>/</span><a href="view.php?id=<?= clean($carId) ?>"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><span>/</span><span>Purchase Payments</span></div>

<div class="page-header">
    <div>
        <h1><i class="ri-hand-coin-line"></i> Purchase Payments</h1>
        <p class="page-subtitle"><?= clean(formatRegistrationNo($car['registration_no'])) ?><?= $sellerParty ? ' · Seller: ' . clean($sellerParty['name']) : '' ?></p>
    </div>
    <div class="page-actions"><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline btn-sm"><i class="ri-arrow-left-line"></i> Back to Car</a></div>
</div>

<?php if (!$sellerParty): ?>
<div class="alert alert-warning"><i class="ri-information-line"></i><div><strong>No seller is linked to this car.</strong><span>Purchase payments need a source seller so the car and seller ledger stay matched.</span></div></div>
<?php else: ?>
<div class="stats-grid compact-operational-grid purchase-payment-summary-grid">
    <div class="stat-card"><div class="stat-value"><?= formatAmount($sellerPurchaseAmount) ?></div><div class="stat-label">Seller Purchase Amount</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($paidToSeller) ?></div><div class="stat-label">Paid to Seller</div></div>
    <div class="stat-card"><div class="stat-value <?= $purchasePending > 0.009 ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($purchasePending) ?></div><div class="stat-label">Purchase Balance Pending</div></div>
</div>

<?php if ($purchasePending > 0.009 && $car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?>
<form method="POST" class="card purchase-payment-form" data-confirm-submit="Record this payment against the purchase balance of this car?">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="pay_purchase_balance">
    <div class="card-header"><div><h3><i class="ri-money-rupee-circle-line"></i> Pay Pending Purchase Balance</h3><div class="card-header-note">This payment will reduce only <?= clean(formatRegistrationNo($car['registration_no'])) ?>’s payable to <?= clean($sellerParty['name']) ?>.</div></div></div>
    <div class="card-body">
        <div class="alert alert-warning"><i class="ri-information-line"></i><div><strong>Balance pending: <?= formatAmount($purchasePending) ?></strong><span>Enter any instalment up to this amount. The seller, car and ledger links are set automatically.</span></div></div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label" for="amount">Amount (₹) *</label><input id="amount" name="amount" class="form-control currency-input" inputmode="decimal" value="<?= clean($purchasePending) ?>" required><div class="form-hint">Maximum <?= formatAmount($purchasePending) ?></div></div>
            <div class="form-group"><label class="form-label" for="payment_date">Payment Date *</label><input id="payment_date" type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group"><label class="form-label" for="payment_account">Pay From *</label><select id="payment_account" name="payment_account" class="form-control searchable-select" required><option value="">Select Cash / Bank</option><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= ($account['entity_type'] ?? '') === 'CASH' ? '💵' : '🏦' ?> <?= clean($account['name']) ?> (<?= clean($account['code']) ?>) · <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label" for="narration">Narration (Optional)</label><input id="narration" name="narration" class="form-control" value="Purchase balance payment - <?= clean($car['registration_no']) ?>" placeholder="e.g. Second instalment paid by bank"></div>
        <div class="form-actions form-actions-start"><button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Record Purchase Payment</button><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline">Cancel</a></div>
    </div>
</form>
<?php elseif ($purchasePending <= 0.009): ?>
<div class="alert alert-success"><i class="ri-checkbox-circle-line"></i><div><strong>Purchase payment is complete.</strong><span>No balance remains payable to this seller for this car.</span></div></div>
<?php endif; ?>

<div class="card purchase-payment-history-card">
    <div class="card-header"><div><h3><i class="ri-file-list-3-line"></i> Purchase Payment Details</h3><div class="card-header-note">The original seller payable and every payment made for this car.</div></div></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Date / Time</th><th>Reference</th><th>Details</th><th>Paid From</th><th class="text-right">Payable</th><th class="text-right">Paid</th></tr></thead>
                <tbody>
                <?php foreach ($paymentHistory as $row): ?><?php
                    $isPayment = $row['entry_type'] === 'DR';
                    $paymentSource = $isPayment && !empty($row['payment_account_name'])
                        ? trim($row['payment_account_name'] . (!empty($row['payment_account_code']) ? ' (' . $row['payment_account_code'] . ')' : '')) : '—';
                    $detail = $row['transaction_type'] === 'CAR_PURCHASE' && !$isPayment ? 'Purchase payable created' : ($isPayment ? 'Payment to seller' : 'Seller ledger movement');
                ?><tr>
                    <td><?= renderDateTimeStack($row['entry_date'], $row['created_at']) ?></td>
                    <td><a href="../transactions/view.php?id=<?= clean($row['id']) ?>"><?= clean($row['reference_no']) ?></a></td>
                    <td><?= clean($detail) ?><?php if (!empty($row['narration'])): ?><div class="text-muted"><?= clean($row['narration']) ?></div><?php endif; ?></td>
                    <td><?= clean($paymentSource) ?></td>
                    <td class="text-right amount flow-out"><?= !$isPayment ? formatAmount($row['amount']) : '—' ?></td>
                    <td class="text-right amount flow-in"><?= $isPayment ? formatAmount($row['amount']) : '—' ?></td>
                </tr><?php endforeach; ?>
                <?php if (empty($paymentHistory)): ?><tr><td colspan="6" class="text-center text-muted empty-table-cell">No purchase payable or payment has been recorded for this car.</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr><th colspan="4" class="text-right">Totals</th><th class="text-right amount flow-out"><?= formatAmount($sellerPurchaseAmount) ?></th><th class="text-right amount flow-in"><?= formatAmount($paidToSeller) ?></th></tr></tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
