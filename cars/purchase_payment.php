<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

Auth::check();
$db = Database::getInstance();

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
$isPaymentBasedPurchase = strtoupper(trim((string) ($car['purchase_amount_mode'] ?? 'FIXED'))) === 'PAYMENTS';

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
$availableSellers = $db->fetchAll(
    "SELECT id, name, type, phone FROM debtors_creditors
     WHERE business_id = ? AND is_active = 1 AND type IN ('SELLER', 'CREDITOR')
     ORDER BY name ASC",
    [$businessId]
);
$historicalPaymentAccounts = !$sellerParty ? $db->fetchAll(
    "SELECT a.id, a.name, a.code, a.entity_type, COALESCE(SUM(jl.amount), 0) AS recorded_amount
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.entry_type = 'CR'
     JOIN accounts a ON a.id = jl.account_id AND a.business_id = je.business_id
     WHERE je.business_id = ? AND je.car_id = ?
       AND je.transaction_type = 'CAR_PURCHASE'
       AND je.status = 'POSTED' AND je.is_reversal = 0
       AND a.entity_type IN ('CASH', 'BANK')
     GROUP BY a.id, a.name, a.code, a.entity_type
     HAVING SUM(jl.amount) > 0
     ORDER BY a.entity_type, a.name",
    [$businessId, $carId]
) : [];
$historicalPaymentAccountIds = array_values(array_filter(array_map(
    static fn($account) => $account['id'] ?? null,
    $historicalPaymentAccounts
)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireEntityAccess('car', 'write');
    verifyCsrf();
    try {
        if ($car['status'] === 'CANCELLED') {
            throw new Exception('Deleted cars are read-only. Their history remains available.');
        }
        $action = post('action');
        if ($action === 'pay_unlinked_payment_based_purchase') {
            if (!$isPaymentBasedPurchase) {
                throw new Exception('This payment action is available only for a payment-based purchase amount.');
            }
            if (!in_array(post('payment_account'), $paymentAccountIds, true)) {
                throw new Exception('Select an accessible cash or bank account.');
            }
            $selectedSellerId = trim((string) post('seller_party_id'));
            $newSellerName = trim((string) post('new_seller_name'));
            if ($selectedSellerId !== '' && $newSellerName !== '') {
                throw new Exception('Choose an existing vehicle owner or add a new one, not both.');
            }
            if ($selectedSellerId === '' && $newSellerName === '') {
                throw new Exception('Select or add the vehicle owner before recording a purchase payment.');
            }
            if ($selectedSellerId !== '') {
                $seller = $engine->getVehicleOwnerParty($selectedSellerId);
            } else {
                Auth::requireEntityAccess('party', 'write');
                $sellerId = $engine->getOrCreateParty($newSellerName, 'SELLER', post('new_seller_phone'));
                $seller = $engine->getVehicleOwnerParty($sellerId);
            }
            $entryId = $engine->loanRepaid(
                $seller['id'],
                parseDecimalInput(post('amount')),
                post('payment_date'),
                post('payment_account'),
                post('narration'),
                $carId
            );
            setFlash('success', 'Purchase payment recorded and vehicle owner linked for ' . formatRegistrationNo($car['registration_no']) . '. Entry: ' . $entryId);
            redirect('purchase_payment.php?id=' . urlencode($carId));
        }
        if ($action === 'repair_purchase_record') {
            if ($sellerParty) {
                throw new Exception('This car already has a seller link. Use the normal purchase-payment form.');
            }
            if (post('confirm_historical_repair') !== '1') {
                throw new Exception('Confirm that the amount was recorded as paid earlier but is still due to the seller.');
            }
            if (!in_array(post('original_payment_account'), $historicalPaymentAccountIds, true)) {
                throw new Exception('Select the original cash or bank account that was incorrectly reduced.');
            }

            $selectedSellerId = trim((string) post('seller_party_id'));
            $newSellerName = trim((string) post('new_seller_name'));
            if ($selectedSellerId !== '' && $newSellerName !== '') {
                throw new Exception('Choose an existing seller or enter a new seller, not both.');
            }
            if ($selectedSellerId === '') {
                Auth::requireEntityAccess('party', 'write');
            }

            $entryId = $engine->repairHistoricalCarPurchasePayment(
                $carId,
                $selectedSellerId,
                parseDecimalInput(post('amount_still_payable')),
                post('original_payment_account'),
                post('correction_date'),
                post('correction_reason'),
                $newSellerName,
                post('new_seller_phone')
            );
            setFlash('success', 'Purchase record repaired for ' . formatRegistrationNo($car['registration_no']) . '. Seller payable created. Entry: ' . $entryId);
            redirect('purchase_payment.php?id=' . urlencode($carId));
        }
        if ($action !== 'pay_purchase_balance') {
            throw new Exception('Unknown purchase payment action.');
        }
        if (!$sellerParty) {
            throw new Exception('This bought car has no linked seller. Use Fix Historical Purchase Record before recording a purchase payment.');
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
       AND jl.account_id = ? AND jl.entry_type = 'DR'
       AND je.transaction_type IN ('CAR_PURCHASE', 'PURCHASE_PAYMENT_REPAIR', 'LOAN_REPAID')",
    [$businessId, $carId, $sellerParty['account_id']]
) : null;
$paidToSeller = (float) ($paymentTotalRow['payment_total'] ?? 0);
$partnerFundingRow = $db->fetch(
    "SELECT COALESCE(SUM(funding_amount), 0) AS total FROM car_partnerships WHERE business_id = ? AND car_id = ? AND status = 'ACTIVE'",
    [$businessId, $carId]
);
$sellerPurchaseAmount = round(max(0, floatval($car['purchase_price']) - floatval($partnerFundingRow['total'] ?? 0)), 2);
$sellerRefundDue = round(max(0, $paidToSeller - $sellerPurchaseAmount), 2);

$paymentHistory = $sellerParty ? $db->fetchAll(
    "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.transaction_type, je.narration,
            seller_line.amount, seller_line.entry_type, payment_account.name AS payment_account_name,
            payment_account.code AS payment_account_code
     FROM journal_entries je
     JOIN journal_lines seller_line
       ON seller_line.journal_entry_id = je.id AND seller_line.account_id = ?
     LEFT JOIN journal_lines payment_line
       ON payment_line.journal_entry_id = je.id
       AND payment_line.account_id IN (
           SELECT id FROM accounts WHERE business_id = ? AND entity_type IN ('CASH', 'BANK')
       )
       AND ((je.transaction_type = 'PURCHASE_PAYMENT_REPAIR' AND payment_line.entry_type = 'DR')
            OR (je.transaction_type <> 'PURCHASE_PAYMENT_REPAIR' AND payment_line.entry_type = 'CR'))
     LEFT JOIN accounts payment_account ON payment_account.id = payment_line.account_id
     WHERE je.business_id = ? AND je.car_id = ? AND je.status IN ('POSTED', 'REVERSED')
       AND je.transaction_type IN ('CAR_PURCHASE', 'PURCHASE_PAYMENT_REPAIR', 'PURCHASE_AMOUNT_CORRECTION', 'LOAN_REPAID')
     ORDER BY je.entry_date ASC, je.created_at ASC",
    [$sellerParty['account_id'], $businessId, $businessId, $carId]
) : [];

$pageTitle = 'Purchase Payments';
$pageIcon = '<i class="ri-hand-coin-line"></i>';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb"><a href="../dashboard.php">Home</a><span>/</span><a href="purchase_payments.php">Car Purchase Payments</a><span>/</span><a href="view.php?id=<?= clean($carId) ?>"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><span>/</span><span>Purchase Payments</span></div>

<div class="page-header">
    <div>
        <h1><i class="ri-hand-coin-line"></i> Purchase Payments</h1>
        <p class="page-subtitle"><?= clean(formatRegistrationNo($car['registration_no'])) ?><?= $sellerParty ? ' · Seller: ' . clean($sellerParty['name']) : '' ?></p>
    </div>
    <div class="page-actions"><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline btn-sm"><i class="ri-arrow-left-line"></i> Back to Car</a></div>
</div>

<?php if (!$sellerParty): ?>
<?php if ($isPaymentBasedPurchase && $car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?>
<div class="alert alert-info"><i class="ri-information-line"></i><div><strong>Link the owner with the first payment.</strong><span>This car has no fixed purchase price. Select or add the vehicle owner, then record the amount actually paid; it becomes this car’s purchase amount.</span></div></div>
<form method="POST" class="card purchase-payment-form" data-confirm-submit="Link this owner and record the car purchase payment?">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="pay_unlinked_payment_based_purchase">
    <div class="card-header"><div><h3><i class="ri-hand-coin-line"></i> Add First Purchase Payment</h3><div class="card-header-note">The owner, car, payment account and purchase amount are all linked automatically.</div></div></div>
    <div class="card-body">
        <div class="form-row-3">
            <div class="form-group"><label class="form-label" for="seller_party_id">Existing Vehicle Owner</label><select id="seller_party_id" name="seller_party_id" class="form-control searchable-select"><option value="">Add a new owner below</option><?php foreach ($availableSellers as $seller): ?><option value="<?= clean($seller['id']) ?>"><?= clean($seller['name']) ?> · <?= clean(ucfirst(strtolower($seller['type']))) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label" for="new_seller_name">New Vehicle Owner</label><input id="new_seller_name" name="new_seller_name" class="form-control" maxlength="150" placeholder="Full name or company name"></div>
            <div class="form-group"><label class="form-label" for="new_seller_phone">Phone</label><input id="new_seller_phone" name="new_seller_phone" class="form-control" inputmode="tel" maxlength="20" placeholder="Optional"></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label" for="amount">Amount (₹) *</label><input id="amount" name="amount" class="form-control currency-input" inputmode="decimal" required><div class="form-hint">Added to this car’s purchase amount.</div></div>
            <div class="form-group"><label class="form-label" for="payment_date">Payment Date *</label><input id="payment_date" type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group"><label class="form-label" for="payment_account">Pay From *</label><select id="payment_account" name="payment_account" class="form-control searchable-select" required><option value="">Select Cash / Bank</option><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= ($account['entity_type'] ?? '') === 'CASH' ? '💵' : '🏦' ?> <?= clean($account['name']) ?> (<?= clean($account['code']) ?>) · <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label" for="narration">Narration (Optional)</label><input id="narration" name="narration" class="form-control" value="Car purchase payment - <?= clean($car['registration_no']) ?>"></div>
        <div class="form-actions form-actions-start"><button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Record Purchase Payment</button><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline">Cancel</a></div>
    </div>
</form>
<?php else: ?>
<div class="alert alert-warning"><i class="ri-information-line"></i><div><strong>Fix this historical purchase before making a payment.</strong><span>The earlier entry has no seller payable. This guided correction restores the amount that was wrongly reduced from Cash/Bank and links the balance to the correct seller and car.</span></div></div>

<?php if (!empty($historicalPaymentAccounts) && $car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?>
<form method="POST" class="card purchase-payment-form" data-confirm-submit="Repair this historical purchase record and create the seller payable?">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="repair_purchase_record">
    <div class="card-header"><div><h3><i class="ri-tools-line"></i> Fix Historical Purchase Record</h3><div class="card-header-note">Use this only when the original car purchase was entered as fully paid, but some amount is actually still owed to the seller.</div></div></div>
    <div class="card-body">
        <div class="form-row-3">
            <div class="form-group"><label class="form-label" for="seller_party_id">Existing Seller / Creditor</label><select id="seller_party_id" name="seller_party_id" class="form-control searchable-select"><option value="">Select existing seller</option><?php foreach ($availableSellers as $seller): ?><option value="<?= clean($seller['id']) ?>"><?= clean($seller['name']) ?> · <?= clean(ucfirst(strtolower($seller['type']))) ?></option><?php endforeach; ?></select><div class="form-hint">Choose this or add a new seller below.</div></div>
            <div class="form-group"><label class="form-label" for="new_seller_name">New Seller Name</label><input id="new_seller_name" name="new_seller_name" class="form-control" maxlength="150" placeholder="Enter only if seller is new"></div>
            <div class="form-group"><label class="form-label" for="new_seller_phone">New Seller Phone</label><input id="new_seller_phone" name="new_seller_phone" class="form-control" inputmode="tel" maxlength="20" placeholder="Optional"></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label" for="amount_still_payable">Amount Still Payable (₹) *</label><input id="amount_still_payable" name="amount_still_payable" class="form-control currency-input" inputmode="decimal" required><div class="form-hint">Enter only the amount that was recorded as paid but is still due.</div></div>
            <div class="form-group"><label class="form-label" for="original_payment_account">Original Cash / Bank Entry *</label><select id="original_payment_account" name="original_payment_account" class="form-control" required><option value="">Select original account</option><?php foreach ($historicalPaymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= ($account['entity_type'] ?? '') === 'CASH' ? '💵' : '🏦' ?> <?= clean($account['name']) ?> (<?= clean($account['code']) ?>) · originally recorded <?= formatAmount($account['recorded_amount']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label" for="correction_date">Correction Date *</label><input id="correction_date" type="date" name="correction_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
        </div>
        <div class="form-group"><label class="form-label" for="correction_reason">Why is this amount still payable? *</label><textarea id="correction_reason" name="correction_reason" class="form-control" rows="3" maxlength="500" required placeholder="e.g. Purchase was entered as fully paid; ₹55,000 remains due to the seller."></textarea><div class="form-hint">This explanation is saved in the accounting audit trail.</div></div>
        <label class="check-row"><input type="checkbox" name="confirm_historical_repair" value="1" required> <span>I confirm this amount was recorded as paid earlier, but is still due to the seller. I understand that Cash/Bank will be restored and a seller payable will be created.</span></label>
        <div class="form-actions form-actions-start"><button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Repair and Create Seller Payable</button><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline">Cancel</a></div>
    </div>
</form>
<?php else: ?>
<div class="card"><div class="card-body"><div class="empty-state"><i class="ri-file-search-line"></i><h3>Original payment source is not available</h3><p>No Cash/Bank payment was found on this car’s original purchase entry, so the system cannot safely guess a correction. Review the original entry and its audit trail before changing it.</p><div class="form-actions form-actions-center"><a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= clean($carId) ?>" class="btn btn-primary">Open Car History</a><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline">Back to Car</a></div></div></div></div>
<?php endif; ?>
<?php endif; ?>
<?php else: ?>
<div class="stats-grid compact-operational-grid purchase-payment-summary-grid">
    <div class="stat-card"><div class="stat-value"><?= formatAmount($sellerPurchaseAmount) ?></div><div class="stat-label"><?= $isPaymentBasedPurchase ? 'Purchase Amount to Date' : 'Seller Purchase Amount' ?></div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($paidToSeller) ?></div><div class="stat-label">Paid to Seller</div></div>
    <div class="stat-card"><div class="stat-value <?= $purchasePending > 0.009 ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($purchasePending) ?></div><div class="stat-label"><?= $isPaymentBasedPurchase ? 'Fixed Balance Pending' : 'Purchase Balance Pending' ?></div></div>
</div>

<?php if ($sellerRefundDue > 0.009): ?>
<div class="alert alert-info"><i class="ri-information-line"></i><div><strong>Owner refund / advance due: <?= formatAmount($sellerRefundDue) ?></strong><span>The corrected purchase amount is lower than the amount already paid. This is a recoverable owner balance; it is not another purchase payment.</span></div></div>
<?php endif; ?>

<?php if (($isPaymentBasedPurchase || $purchasePending > 0.009) && $car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?>
<form method="POST" class="card purchase-payment-form" data-confirm-submit="Record this payment against this car's purchase amount?">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="pay_purchase_balance">
    <div class="card-header"><div><h3><i class="ri-money-rupee-circle-line"></i> <?= $isPaymentBasedPurchase ? 'Add Car Purchase Payment' : 'Pay Pending Purchase Balance' ?></h3><div class="card-header-note"><?= $isPaymentBasedPurchase ? 'This payment adds to ' . clean(formatRegistrationNo($car['registration_no'])) . '’s purchase amount and is recorded in ' . clean($sellerParty['name']) . '’s ledger.' : 'This payment will reduce only ' . clean(formatRegistrationNo($car['registration_no'])) . '’s payable to ' . clean($sellerParty['name']) . '.' ?></div></div></div>
    <div class="card-body">
        <?php if ($isPaymentBasedPurchase): ?>
        <div class="alert alert-info"><i class="ri-information-line"></i><div><strong>No final purchase price is required.</strong><span>Enter the amount actually paid to the owner. It is added to this car’s purchase amount automatically; there is no artificial pending-balance limit.</span></div></div>
        <?php else: ?>
        <div class="alert alert-warning"><i class="ri-information-line"></i><div><strong>Balance pending: <?= formatAmount($purchasePending) ?></strong><span>Enter any instalment up to this amount. The seller, car and ledger links are set automatically.</span></div></div>
        <?php endif; ?>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label" for="amount">Amount (₹) *</label><input id="amount" name="amount" class="form-control currency-input" inputmode="decimal" value="<?= $isPaymentBasedPurchase ? '' : clean($purchasePending) ?>" required><div class="form-hint"><?= $isPaymentBasedPurchase ? 'This amount will be added to the purchase amount.' : 'Maximum ' . formatAmount($purchasePending) ?></div></div>
            <div class="form-group"><label class="form-label" for="payment_date">Payment Date *</label><input id="payment_date" type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group"><label class="form-label" for="payment_account">Pay From *</label><select id="payment_account" name="payment_account" class="form-control searchable-select" required><option value="">Select Cash / Bank</option><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= ($account['entity_type'] ?? '') === 'CASH' ? '💵' : '🏦' ?> <?= clean($account['name']) ?> (<?= clean($account['code']) ?>) · <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label" for="narration">Narration (Optional)</label><input id="narration" name="narration" class="form-control" value="<?= $isPaymentBasedPurchase ? 'Car purchase payment - ' : 'Purchase balance payment - ' ?><?= clean($car['registration_no']) ?>" placeholder="e.g. Second instalment paid by bank"></div>
        <div class="form-actions form-actions-start"><button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Record Purchase Payment</button><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline">Cancel</a></div>
    </div>
</form>
<?php elseif (!$isPaymentBasedPurchase && $purchasePending <= 0.009): ?>
<div class="alert alert-success"><i class="ri-checkbox-circle-line"></i><div><strong>Purchase payment is complete.</strong><span>No balance remains payable to this seller for this car.</span></div></div>
<?php endif; ?>

<div class="card purchase-payment-history-card">
    <div class="card-header"><div><h3><i class="ri-file-list-3-line"></i> Purchase Payment Details</h3><div class="card-header-note">The original seller payable and every payment made for this car.</div></div></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Date / Time</th><th>Reference</th><th>Details</th><th>Paid From</th><th class="text-right">Payable</th><th class="text-right">Paid</th><th class="text-right">Correction</th></tr></thead>
                <tbody>
                <?php foreach ($paymentHistory as $row): ?><?php
                    $isRepair = $row['transaction_type'] === 'PURCHASE_PAYMENT_REPAIR';
                    $isAmountCorrection = $row['transaction_type'] === 'PURCHASE_AMOUNT_CORRECTION';
                    $isPayment = $row['entry_type'] === 'DR' && !$isAmountCorrection;
                    $paymentSource = ($isPayment || $isRepair) && !empty($row['payment_account_name'])
                        ? trim($row['payment_account_name'] . (!empty($row['payment_account_code']) ? ' (' . $row['payment_account_code'] . ')' : '')) : '—';
                    $detail = $isAmountCorrection
                        ? ($row['entry_type'] === 'DR' ? 'Purchase amount reduced — seller refund / advance due' : 'Purchase amount increased — additional seller payable')
                        : ($row['transaction_type'] === 'CAR_PURCHASE' && !$isPayment ? 'Purchase payable created' : ($isRepair ? ($isPayment ? 'Historical payment reconstructed' : 'Historical payable reconstructed') : ($isPayment ? 'Payment to seller' : 'Seller ledger movement')));
                ?><tr>
                    <td><?= renderDateTimeStack($row['entry_date'], $row['created_at']) ?></td>
                    <td><a href="../transactions/view.php?id=<?= clean($row['id']) ?>"><?= clean($row['reference_no']) ?></a></td>
                    <td><?= clean($detail) ?><?php if (!empty($row['narration'])): ?><div class="text-muted"><?= clean($row['narration']) ?></div><?php endif; ?></td>
                    <td><?= $isRepair && $paymentSource !== '—' ? 'Cash/Bank restored: ' . clean($paymentSource) : clean($paymentSource) ?></td>
                    <td class="text-right amount flow-out"><?= !$isPayment && !$isAmountCorrection ? formatAmount($row['amount']) : '—' ?></td>
                    <td class="text-right amount flow-in"><?= $isPayment ? formatAmount($row['amount']) : '—' ?></td>
                    <td class="text-right amount <?= $isAmountCorrection && $row['entry_type'] === 'DR' ? 'flow-in' : 'flow-out' ?>"><?= $isAmountCorrection ? ($row['entry_type'] === 'DR' ? '− ' : '+ ') . formatAmount($row['amount']) : '—' ?></td>
                </tr><?php endforeach; ?>
                <?php if (empty($paymentHistory)): ?><tr><td colspan="7" class="text-center text-muted empty-table-cell">No purchase payable or payment has been recorded for this car.</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr><th colspan="4" class="text-right">Totals</th><th class="text-right amount flow-out"><?= formatAmount($sellerPurchaseAmount) ?></th><th class="text-right amount flow-in"><?= formatAmount($paidToSeller) ?></th><th></th></tr></tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
