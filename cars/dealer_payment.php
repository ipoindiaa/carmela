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

$car = $db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $businessId]);
if (!$car) {
    setFlash('error', 'Car not found.');
    redirect('list.php');
}
if (($car['ownership_type'] ?? 'OWNED') === 'COMMISSION') {
    redirect('commission_view.php?id=' . urlencode($carId));
}

$primaryAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'write');
$paymentAccounts = array_merge($primaryAccountGroups['cash_book'] ?? [], $primaryAccountGroups['bank_book'] ?? []);
$paymentAccountIds = array_values(array_filter(array_map(
    static fn($account) => $account['id'] ?? null,
    $paymentAccounts
)));
$availableDealers = $db->fetchAll(
    "SELECT id, name, type, phone FROM debtors_creditors
     WHERE business_id = ? AND is_active = 1 AND type IN ('DEALER', 'CREDITOR', 'SELLER')
     ORDER BY name ASC",
    [$businessId]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireEntityAccess('car', 'write');
    verifyCsrf();
    try {
        if ($car['status'] === 'CANCELLED') {
            throw new Exception('Deleted cars are read-only. Their history remains available.');
        }
        $action = post('action');
        if ($action === 'link_dealer_commission') {
            $dealerPartyId = trim((string) post('dealer_party_id'));
            $dealerName = trim((string) post('dealer_name'));
            if ($dealerPartyId !== '' && $dealerName !== '') {
                throw new Exception('Choose an existing dealer / broker or add a new one, not both.');
            }
            if ($dealerPartyId === '' && $dealerName === '') {
                throw new Exception('Select the purchase dealer / broker for this car.');
            }
            if ($dealerPartyId === '') {
                Auth::requireEntityAccess('party', 'write');
            }
            $dealerPaymentAccount = trim((string) post('dealer_payment_account'));
            if ($dealerPaymentAccount !== '' && !in_array($dealerPaymentAccount, $paymentAccountIds, true)) {
                throw new Exception('Select an accessible cash or bank account.');
            }
            $engine->recordPurchaseDealerCommission($carId, [
                'party_id' => $dealerPartyId,
                'name' => $dealerName,
                'phone' => post('dealer_phone'),
                'commission' => post('dealer_commission', '0'),
                'paid_now' => post('dealer_paid_now', ''),
                'payment_account' => $dealerPaymentAccount,
            ], post('commission_date'), $dealerPaymentAccount ?: null, post('narration'));
            setFlash('success', 'Dealer commission recorded for ' . formatRegistrationNo($car['registration_no']) . '.');
            redirect('dealer_payment.php?id=' . urlencode($carId));
        }
        if ($action !== 'pay_dealer_commission') {
            throw new Exception('Unknown dealer payment action.');
        }
        if (!in_array(post('payment_account'), $paymentAccountIds, true)) {
            throw new Exception('Select an accessible cash or bank account.');
        }

        $entryId = $engine->payPurchaseDealerCommission(
            $carId,
            post('amount'),
            post('payment_date'),
            post('payment_account'),
            post('narration')
        );
        setFlash('success', 'Dealer commission payment recorded for ' . formatRegistrationNo($car['registration_no']) . '. Entry: ' . $entryId);
        redirect('dealer_payment.php?id=' . urlencode($carId));
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('dealer_payment.php?id=' . urlencode($carId));
    }
}

$settlement = $engine->getCarDealerSettlement($carId);
$dealerParty = $settlement['dealer'];
$dealerPending = $settlement['pending'];
$canWrite = $car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write');

$pageTitle = 'Dealer Commission';
$pageIcon = '<i class="ri-user-shared-line"></i>';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb"><a href="../dashboard.php">Home</a><span>/</span><a href="list.php">Cars</a><span>/</span><a href="view.php?id=<?= clean($carId) ?>"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><span>/</span><span>Dealer Commission</span></div>

<div class="page-header">
    <div>
        <h1><i class="ri-user-shared-line"></i> Purchase Dealer Commission</h1>
        <p class="page-subtitle"><?= clean(formatRegistrationNo($car['registration_no'])) ?><?= $dealerParty ? ' · Dealer: ' . clean($dealerParty['name']) : '' ?></p>
    </div>
    <div class="page-actions">
        <?php if ($dealerParty): ?><a href="../parties/dealer_ledger.php?id=<?= urlencode($dealerParty['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-book-2-line"></i> Dealer Ledger</a><?php endif; ?>
        <a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline btn-sm"><i class="ri-arrow-left-line"></i> Back to Car</a>
    </div>
</div>

<div class="alert alert-info">
    <i class="ri-information-line"></i>
    <div><strong>Dealer commission is a cost of this car.</strong><span>It is different from money paid to the vehicle owner, which only settles the purchase price. A sale broker, if any, is recorded separately at sale time.</span></div>
</div>

<?php if (!$dealerParty): ?>
<?php if ($canWrite): ?>
<form method="POST" class="card purchase-payment-form" data-confirm-submit="Link this dealer / broker and post the commission?">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="link_dealer_commission">
    <div class="card-header"><div><h3><i class="ri-user-add-line"></i> Link Purchase Dealer / Broker</h3><div class="card-header-note">Use this when the car was bought through a dealer or broker whose commission was not recorded at purchase time.</div></div></div>
    <div class="card-body">
        <div class="form-row-3">
            <div class="form-group"><label class="form-label" for="dealer_party_id">Existing Dealer / Broker</label><select id="dealer_party_id" name="dealer_party_id" class="form-control searchable-select"><option value="">Select existing dealer</option><?php foreach ($availableDealers as $dealer): ?><option value="<?= clean($dealer['id']) ?>"><?= clean($dealer['name']) ?> · <?= clean(ucfirst(strtolower($dealer['type']))) ?></option><?php endforeach; ?></select><div class="form-hint">Choose this or add a new dealer below.</div></div>
            <div class="form-group"><label class="form-label" for="dealer_name">New Dealer / Broker Name</label><input id="dealer_name" name="dealer_name" class="form-control" maxlength="150" placeholder="Enter only if the dealer is new"></div>
            <div class="form-group"><label class="form-label" for="dealer_phone">Dealer Phone</label><input id="dealer_phone" name="dealer_phone" class="form-control" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="Optional"></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label" for="dealer_commission">Dealer Commission (₹) *</label><div class="input-group"><span class="input-prefix">₹</span><input id="dealer_commission" name="dealer_commission" class="form-control currency-input" inputmode="decimal" required></div></div>
            <div class="form-group"><label class="form-label" for="dealer_paid_now">Paid Now (₹)</label><div class="input-group"><span class="input-prefix">₹</span><input id="dealer_paid_now" name="dealer_paid_now" class="form-control currency-input" inputmode="decimal" placeholder="0"></div><div class="form-hint">Cannot be more than the commission.</div></div>
            <div class="form-group"><label class="form-label" for="commission_date">Commission Date *</label><input id="commission_date" type="date" name="commission_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label" for="dealer_payment_account">Pay Dealer From</label><select id="dealer_payment_account" name="dealer_payment_account" class="form-control searchable-select"><option value="">Not paying now</option><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= ($account['entity_type'] ?? '') === 'CASH' ? '💵' : '🏦' ?> <?= clean($account['name']) ?> (<?= clean($account['code']) ?>) · <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label" for="narration">Narration (Optional)</label><input id="narration" name="narration" class="form-control" placeholder="e.g. Broker commission agreed at purchase"></div>
        </div>
        <div class="form-actions form-actions-start"><button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Record Dealer Commission</button><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline">Cancel</a></div>
    </div>
</form>
<?php else: ?>
<div class="card"><div class="card-body"><div class="empty-state"><i class="ri-user-search-line"></i><h3>No purchase dealer linked</h3><p>This car has no purchase dealer or broker recorded. Dealer names are never guessed from old entries, so it stays blank until someone records it.</p><div class="form-actions form-actions-center"><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-primary">Back to Car</a></div></div></div></div>
<?php endif; ?>
<?php else: ?>

<div class="stats-grid compact-operational-grid purchase-payment-summary-grid">
    <div class="stat-card"><div class="stat-value"><?= formatAmount($settlement['commission_total']) ?></div><div class="stat-label">Dealer Commission</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($settlement['paid_total']) ?></div><div class="stat-label">Commission Paid</div></div>
    <div class="stat-card"><div class="stat-value <?= $dealerPending > 0.009 ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($dealerPending) ?></div><div class="stat-label">Dealer Balance Pending</div></div>
</div>

<?php if ($dealerPending > 0.009 && $canWrite): ?>
<form method="POST" class="card purchase-payment-form" data-confirm-submit="Record this payment against the dealer commission of this car?">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="pay_dealer_commission">
    <div class="card-header"><div><h3><i class="ri-secure-payment-line"></i> Pay Pending Dealer Commission</h3><div class="card-header-note">This payment reduces only <?= clean(formatRegistrationNo($car['registration_no'])) ?>’s commission payable to <?= clean($dealerParty['name']) ?>.</div></div></div>
    <div class="card-body">
        <div class="alert alert-warning"><i class="ri-information-line"></i><div><strong>Balance pending: <?= formatAmount($dealerPending) ?></strong><span>Enter any instalment up to this amount. The dealer, car and ledger links are set automatically.</span></div></div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label" for="amount">Amount (₹) *</label><div class="input-group"><span class="input-prefix">₹</span><input id="amount" name="amount" class="form-control currency-input" inputmode="decimal" value="<?= clean($dealerPending) ?>" required></div><div class="form-hint">Maximum <?= formatAmount($dealerPending) ?></div></div>
            <div class="form-group"><label class="form-label" for="payment_date">Payment Date *</label><input id="payment_date" type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group"><label class="form-label" for="payment_account">Pay From *</label><select id="payment_account" name="payment_account" class="form-control searchable-select" required><option value="">Select Cash / Bank</option><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= ($account['entity_type'] ?? '') === 'CASH' ? '💵' : '🏦' ?> <?= clean($account['name']) ?> (<?= clean($account['code']) ?>) · <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label" for="pay-narration">Narration (Optional)</label><input id="pay-narration" name="narration" class="form-control" value="Dealer commission payment - <?= clean($car['registration_no']) ?>" placeholder="e.g. Balance broker commission paid by bank"></div>
        <div class="form-actions form-actions-start"><button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Record Dealer Payment</button><a href="view.php?id=<?= clean($carId) ?>" class="btn btn-outline">Cancel</a></div>
    </div>
</form>
<?php elseif ($dealerPending <= 0.009): ?>
<div class="alert alert-success"><i class="ri-checkbox-circle-line"></i><div><strong>Dealer commission is fully settled.</strong><span>No balance remains payable to this dealer for this car.</span></div></div>
<?php endif; ?>

<div class="card purchase-payment-history-card">
    <div class="card-header"><div><h3><i class="ri-file-list-3-line"></i> Dealer Commission Details</h3><div class="card-header-note">The commission payable created for this car and every payment made against it.</div></div></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Date / Time</th><th>Reference</th><th>What Happened</th><th>Cash / Bank</th><th class="text-right">Dealer Payable Created</th><th class="text-right">Paid to Dealer</th></tr></thead>
                <tbody>
                <?php foreach ($settlement['history'] as $row): ?><?php
                    $isPayment = $row['entry_type'] === 'DR';
                    $paymentSource = $isPayment && !empty($row['payment_account_name'])
                        ? trim($row['payment_account_name'] . (!empty($row['payment_account_code']) ? ' (' . $row['payment_account_code'] . ')' : '')) : '—';
                    $detail = $isPayment ? 'Commission paid to dealer' : 'Dealer commission payable created';
                ?><tr>
                    <td><?= renderDateTimeStack($row['entry_date'], $row['created_at']) ?></td>
                    <td><a href="../transactions/view.php?id=<?= clean($row['id']) ?>"><?= clean($row['reference_no']) ?></a></td>
                    <td><?= clean($detail) ?><?php if (!empty($row['narration'])): ?><div class="text-muted"><?= clean($row['narration']) ?></div><?php endif; ?></td>
                    <td><?= clean($paymentSource) ?></td>
                    <td class="text-right amount flow-out"><?= !$isPayment ? formatAmount($row['amount']) : '—' ?></td>
                    <td class="text-right amount flow-in"><?= $isPayment ? formatAmount($row['amount']) : '—' ?></td>
                </tr><?php endforeach; ?>
                <?php if (empty($settlement['history'])): ?><tr><td colspan="6" class="text-center text-muted empty-table-cell">No dealer commission has been recorded for this car.</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr><th colspan="4" class="text-right">Totals</th><th class="text-right amount flow-out"><?= formatAmount($settlement['commission_total']) ?></th><th class="text-right amount flow-in"><?= formatAmount($settlement['paid_total']) ?></th></tr></tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
