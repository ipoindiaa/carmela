<?php
$pageTitle = 'Commission Car';
$pageIcon = '<i class="ri-hand-coin-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$id = get('id');
Auth::requireEntityAccess('car', 'read');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$car = $db->fetch(
    "SELECT c.*, owner.name AS owner_name, owner.phone AS owner_phone
     FROM cars c
     JOIN debtors_creditors owner ON owner.id = c.commission_owner_party_id
     WHERE c.id = ? AND c.business_id = ? AND c.ownership_type = 'COMMISSION'",
    [$id, $businessId]
);
if (!$car) { setFlash('error', 'Commission car not found.'); redirect('commission.php'); }

$primaryAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'write');
$paymentAccounts = array_merge($primaryAccountGroups['cash_book'] ?? [], $primaryAccountGroups['bank_book'] ?? []);
$paymentAccountIds = array_column($paymentAccounts, 'id');
$buyers = $db->fetchAll("SELECT id, name, phone FROM debtors_creditors WHERE business_id = ? AND is_active = 1 AND type IN ('BUYER','DEBTOR') ORDER BY name", [$businessId]);
$owners = $db->fetchAll("SELECT id, name, phone FROM debtors_creditors WHERE business_id = ? AND is_active = 1 AND type IN ('SELLER','CREDITOR') ORDER BY name", [$businessId]);
$token = $engine->getCarTokenSummary($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireEntityAccess('car', 'write');
    verifyCsrf();
    try {
        $action = post('action');
        if ($action === 'update_details') {
            $ownerPartyId = post('commission_owner_party_id');
            $validOwner = $db->fetch("SELECT id FROM debtors_creditors WHERE id = ? AND business_id = ? AND is_active = 1 AND type IN ('SELLER','CREDITOR')", [$ownerPartyId, $businessId]);
            if (!$validOwner) throw new Exception('Select a valid car owner.');
            if ($car['status'] !== 'IN_STOCK' && $ownerPartyId !== $car['commission_owner_party_id']) throw new Exception('Owner cannot be changed after sale. Reverse the sale first.');
            $expectedSale = parseDecimalInput(post('expected_sale_price'));
            $expectedCommission = parseDecimalInput(post('expected_commission_amount'));
            if ($expectedSale < 0 || $expectedCommission < 0 || ($expectedSale > 0 && $expectedCommission > $expectedSale)) throw new Exception('Enter valid expected sale and commission amounts.');
            $old = array_intersect_key($car, array_flip(['make','model','year','color','has_second_key','commission_owner_party_id','expected_sale_price','expected_commission_amount','notes']));
            $db->query(
                "UPDATE cars SET make = ?, model = ?, year = ?, color = ?, has_second_key = ?, commission_owner_party_id = ?, seller_party_id = ?, expected_sale_price = ?, expected_commission_amount = ?, notes = ? WHERE id = ? AND business_id = ?",
                [post('make'), post('model'), intval(post('year')) ?: null, post('color'), post('has_second_key') === '1' ? 1 : 0, $ownerPartyId, $ownerPartyId, $expectedSale, $expectedCommission, post('notes'), $id, $businessId]
            );
            $newCar = $db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$id, $businessId]);
            $new = array_intersect_key($newCar ?: [], array_flip(array_keys($old)));
            Auth::auditUpdate('car', $id, $old, $new, 'Commission car details and terms updated', 'commission_cars');
            setFlash('success', 'Commission car details updated. Changes are available in History.');
        } elseif ($action === 'sell') {
            if (!in_array(post('payment_account'), $paymentAccountIds, true)) throw new Exception('Select an accessible cash or bank account.');
            $buyerMode = in_array(post('buyer_mode'), ['existing', 'new'], true) ? post('buyer_mode') : ($buyers ? 'existing' : 'new');
            $buyerPartyId = $buyerMode === 'existing' ? trim((string) post('buyer_party_id')) : '';
            $buyerName = $buyerMode === 'new' ? trim((string) post('buyer_name')) : '';
            $buyerPhone = $buyerMode === 'new' ? trim((string) post('buyer_phone')) : '';
            if ($buyerMode === 'existing' && $buyerPartyId === '') throw new Exception('Select the buyer or customer.');
            if ($buyerMode === 'new' && $buyerName === '') throw new Exception('Enter the buyer or company name.');
            $receivedInput = trim((string) post('amount_received_now'));
            $entryId = $engine->commissionCarSale(
                $id,
                parseDecimalInput(post('gross_sale_amount')),
                parseDecimalInput(post('commission_amount')),
                post('sale_date'),
                post('payment_account'),
                post('payment_handling'),
                post('narration'),
                $buyerPartyId,
                $buyerName,
                $buyerPhone,
                $receivedInput === '' ? null : parseDecimalInput($receivedInput)
            );
            setFlash('success', 'Commission sale recorded. Only commission was posted as income. Entry: ' . $entryId);
        } elseif ($action === 'pay_owner') {
            if (!in_array(post('payment_account'), $paymentAccountIds, true)) throw new Exception('Select an accessible cash or bank account.');
            $entryId = $engine->payCommissionCarOwner($id, parseDecimalInput(post('owner_payment_amount')), post('payment_date'), post('payment_account'), post('narration'));
            setFlash('success', 'Owner payment recorded. Entry: ' . $entryId);
        }
        redirect('commission_view.php?id=' . $id);
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('commission_view.php?id=' . $id . (post('action') === 'update_details' ? '&edit=1' : ''));
    }
}

$settlement = $engine->getCommissionSettlement($id);
$ownerOutstanding = $settlement && $settlement['payment_handling'] === 'FULL_AMOUNT'
    ? max(0, floatval($settlement['owner_amount']) - floatval($settlement['paid_to_owner_amount'])) : 0;
$pending = $engine->getCarPendingAmounts($id);
$buyerOutstanding = floatval($pending['sale_pending'] ?? 0);
$entries = $db->fetchAll(
    "SELECT je.*, u.full_name AS created_by_name,
            COALESCE(SUM(CASE WHEN a.entity_type IN ('CASH','BANK') AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS money_in,
            COALESCE(SUM(CASE WHEN a.entity_type IN ('CASH','BANK') AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS money_out
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     JOIN accounts a ON a.id = jl.account_id
     LEFT JOIN users u ON u.id = je.created_by
     WHERE je.business_id = ? AND je.car_id = ?
     GROUP BY je.id, u.full_name ORDER BY je.entry_date DESC, je.created_at DESC",
    [$businessId, $id]
);
?>

<div class="page-header">
    <div><h1><i class="ri-hand-coin-line"></i> <?= clean(formatRegistrationNo($car['registration_no'])) ?></h1><p class="page-subtitle">Commission car owned by <?= clean($car['owner_name']) ?></p></div>
    <div class="page-actions">
        <?php if (Auth::hasEntityAccess('car', 'write')): ?><a href="commission_view.php?id=<?= clean($id) ?>&amp;edit=1" class="btn btn-outline btn-sm"><i class="ri-edit-line"></i> Edit</a><?php endif; ?>
        <a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= clean($id) ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
        <?php if ($settlement): ?><a href="../reports/change_history.php?entity_type=commission_car_settlement&amp;entity_id=<?= clean($settlement['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-file-history-line"></i> Settlement History</a><?php endif; ?>
        <?php if ($car['status'] === 'IN_STOCK'): ?><a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_TOKEN_RECEIVED', 'car_id' => $id, 'narration' => 'Token received for commission car ' . $car['registration_no']]) ?>" class="btn btn-outline btn-sm"><i class="ri-hand-coin-line"></i> Receive Token</a><?php endif; ?>
        <a href="commission.php" class="btn btn-outline btn-sm"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<div class="alert alert-info commission-accounting-note">
    <i class="ri-shield-check-line"></i><div><strong>Customer-owned vehicle</strong><span>Gross sale value is memorandum information. Only commission enters Profit &amp; Loss.</span></div>
</div>

<?php if (get('edit') === '1' && Auth::hasEntityAccess('car', 'write')): ?>
<form method="POST" class="card car-edit-card" data-confirm-submit="Save these commission car changes? Every changed field will be recorded in History.">
    <?= csrfField() ?><input type="hidden" name="action" value="update_details">
    <div class="card-header"><h3><i class="ri-edit-line"></i> Edit Commission Car</h3></div>
    <div class="card-body">
        <div class="form-row-3"><div class="form-group"><label class="form-label">Make</label><input name="make" class="form-control" value="<?= clean($car['make']) ?>"></div><div class="form-group"><label class="form-label">Model</label><input name="model" class="form-control" value="<?= clean($car['model']) ?>"></div><div class="form-group"><label class="form-label">Year</label><input type="number" name="year" class="form-control" value="<?= clean($car['year']) ?>"></div></div>
        <div class="form-row-3"><div class="form-group"><label class="form-label">Color</label><input name="color" class="form-control" value="<?= clean($car['color']) ?>"></div><div class="form-group"><label class="form-label">Second Key</label><select name="has_second_key" class="form-control"><option value="0" <?= !$car['has_second_key'] ? 'selected' : '' ?>>No</option><option value="1" <?= $car['has_second_key'] ? 'selected' : '' ?>>Yes</option></select></div><div class="form-group"><label class="form-label">Owner</label><select name="commission_owner_party_id" class="form-control searchable-select" <?= $car['status'] !== 'IN_STOCK' ? 'disabled' : '' ?>><?php foreach ($owners as $owner): ?><option value="<?= clean($owner['id']) ?>" <?= $owner['id'] === $car['commission_owner_party_id'] ? 'selected' : '' ?>><?= clean($owner['name']) ?></option><?php endforeach; ?></select><?php if ($car['status'] !== 'IN_STOCK'): ?><input type="hidden" name="commission_owner_party_id" value="<?= clean($car['commission_owner_party_id']) ?>"><?php endif; ?></div></div>
        <div class="form-row"><div class="form-group"><label class="form-label">Expected Selling Value (₹)</label><input name="expected_sale_price" class="form-control currency-input" value="<?= clean($car['expected_sale_price']) ?>"></div><div class="form-group"><label class="form-label">Expected Commission (₹)</label><input name="expected_commission_amount" class="form-control currency-input" value="<?= clean($car['expected_commission_amount']) ?>"></div></div>
        <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= clean($car['notes']) ?></textarea></div>
        <div class="form-actions form-actions-start"><button class="btn btn-primary"><i class="ri-save-line"></i> Update</button><a href="commission_view.php?id=<?= clean($id) ?>" class="btn btn-outline">Cancel</a></div>
    </div>
</form>
<?php endif; ?>

<div class="stats-grid commission-detail-stats">
    <div class="stat-card"><div class="stat-value"><?= clean($car['owner_name']) ?></div><div class="stat-label">Vehicle Owner</div></div>
    <div class="stat-card"><div class="stat-value flow-neutral"><?= formatAmount($settlement['gross_sale_amount'] ?? $car['expected_sale_price']) ?></div><div class="stat-label"><?= $settlement ? 'Gross Sale (Memo)' : 'Expected Sale (Memo)' ?></div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($settlement['commission_amount'] ?? $car['expected_commission_amount']) ?></div><div class="stat-label"><?= $settlement ? 'Commission Income' : 'Expected Commission' ?></div></div>
    <div class="stat-card"><div class="stat-value <?= $ownerOutstanding > 0 ? 'flow-out' : '' ?>"><?= formatAmount($ownerOutstanding) ?></div><div class="stat-label">Payable to Owner</div></div>
    <div class="stat-card"><div class="stat-value <?= $buyerOutstanding > 0 ? 'flow-in' : '' ?>"><?= formatAmount($buyerOutstanding) ?></div><div class="stat-label">Buyer Pending</div></div>
</div>

<div class="commission-detail-grid">
    <div class="card"><div class="card-header"><h3><i class="ri-car-line"></i> Car and Owner Details</h3></div><div class="card-body detail-list">
        <div><span>Vehicle</span><strong><?= clean(trim($car['make'] . ' ' . $car['model'])) ?: '-' ?><?= $car['year'] ? ' (' . clean($car['year']) . ')' : '' ?></strong></div>
        <div><span>Owner</span><strong><?= clean($car['owner_name']) ?><?= $car['owner_phone'] ? ' · ' . clean($car['owner_phone']) : '' ?></strong></div>
        <div><span>Received</span><strong><?= formatDate($car['purchase_date']) ?></strong></div>
        <div><span>Status</span><strong><span class="badge <?= $car['status'] === 'IN_STOCK' ? 'badge-blue' : ($car['status'] === 'SOLD' ? 'badge-green' : 'badge-yellow') ?>"><?= clean(CAR_STATUS[$car['status']] ?? $car['status']) ?></span></strong></div>
        <div><span>Available Buyer Token</span><strong><?= formatAmount($token['available'] ?? 0) ?><?= !empty($token['party_name']) ? ' · ' . clean($token['party_name']) : '' ?></strong></div>
        <div><span>Notes</span><strong><?= nl2br(clean($car['notes'] ?: '-')) ?></strong></div>
    </div></div>

    <?php if ($settlement): ?>
    <div class="card"><div class="card-header"><h3><i class="ri-scales-3-line"></i> Sale and Owner Settlement</h3></div><div class="card-body detail-list">
        <div><span>Buyer</span><strong><?= clean($settlement['buyer_name']) ?></strong></div>
        <div><span>Money Handling</span><strong><?= $settlement['payment_handling'] === 'FULL_AMOUNT' ? 'Business received full buyer amount' : 'Owner received sale amount directly' ?></strong></div>
        <div><span>Gross Sale</span><strong><?= formatAmount($settlement['gross_sale_amount']) ?> <small class="text-muted">memo only</small></strong></div>
        <div><span>Commission Income</span><strong class="flow-in"><?= formatAmount($settlement['commission_amount']) ?></strong></div>
        <div><span>Owner Share</span><strong><?= formatAmount($settlement['owner_amount']) ?></strong></div>
        <div><span>Paid / Payable</span><strong><?= formatAmount($settlement['paid_to_owner_amount']) ?> / <span class="flow-out"><?= formatAmount($ownerOutstanding) ?></span></strong></div>
    </div></div>
    <?php endif; ?>
</div>

<?php if ($car['status'] === 'IN_STOCK' && Auth::hasEntityAccess('car', 'write')): ?>
<form method="POST" class="card commission-sale-card" data-confirm-submit="Record this sale? Gross sale value will be stored for tracking and only commission will be posted as income.">
    <?= csrfField() ?><input type="hidden" name="action" value="sell">
    <div class="card-header"><h3><i class="ri-money-rupee-circle-line"></i> Record Commission Sale</h3></div>
    <div class="card-body">
        <div class="form-row-3"><div class="form-group"><label class="form-label">Gross Sale Value (₹) *</label><input name="gross_sale_amount" class="form-control currency-input" inputmode="decimal" value="<?= clean($car['expected_sale_price']) ?>" required><div class="form-hint">Memorandum value only, not business income.</div></div><div class="form-group"><label class="form-label">Our Commission (₹) *</label><input name="commission_amount" class="form-control currency-input" inputmode="decimal" value="<?= clean($car['expected_commission_amount']) ?>" required><div class="form-hint">Only this amount enters income.</div></div><div class="form-group"><label class="form-label">Sale Date *</label><input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div></div>
        <?php $buyerMode = $buyers ? 'existing' : 'new'; ?>
        <div class="exclusive-choice" data-exclusive-choice data-default-mode="<?= clean($buyerMode) ?>">
            <input type="hidden" name="buyer_mode" value="<?= clean($buyerMode) ?>" data-exclusive-mode data-keep-enabled="1">
            <div class="exclusive-choice-header">
                <div><strong>Buyer / Customer *</strong><span>Select the customer ledger or create it once.</span></div>
                <div class="exclusive-choice-options" role="group" aria-label="Buyer source">
                    <?php if ($buyers): ?><button type="button" class="exclusive-choice-option" data-exclusive-option="existing"><i class="ri-search-line"></i> Select Existing</button><?php endif; ?>
                    <button type="button" class="exclusive-choice-option" data-exclusive-option="new"><i class="ri-user-add-line"></i> Add New</button>
                </div>
            </div>
            <?php if ($buyers): ?>
            <div data-exclusive-panel="existing">
                <div class="form-group"><label class="form-label">Buyer / Customer *</label><select name="buyer_party_id" class="form-control searchable-select" required><option value="">Select buyer</option><?php foreach ($buyers as $buyer): ?><option value="<?= clean($buyer['id']) ?>" <?= !empty($token['party_id']) && $token['party_id'] === $buyer['id'] ? 'selected' : '' ?>><?= clean($buyer['name']) ?><?= $buyer['phone'] ? ' - ' . clean($buyer['phone']) : '' ?></option><?php endforeach; ?></select><?php if (($token['available'] ?? 0) > 0): ?><div class="form-hint">Buyer preselected from the open token for this car.</div><?php endif; ?></div>
            </div>
            <?php endif; ?>
            <div data-exclusive-panel="new">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Buyer / Company Name *</label><input name="buyer_name" class="form-control" placeholder="Enter buyer or company name" required></div>
                    <div class="form-group"><label class="form-label">Phone</label><input name="buyer_phone" class="form-control" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="10-digit mobile number"></div>
                </div>
            </div>
        </div>
        <div class="form-group"><label class="form-label">How Buyer Money Was Handled *</label><select name="payment_handling" class="form-control"><option value="COMMISSION_ONLY">Owner received car amount; business received commission only</option><option value="FULL_AMOUNT" <?= ($token['available'] ?? 0) > 0 ? 'selected' : '' ?>>Business received full sale amount; owner share is payable</option></select></div>
        <div class="form-row-3"><div class="form-group"><label class="form-label">Receive Into *</label><select name="payment_account" class="form-control searchable-select" required><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= clean($account['name']) ?> · <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Amount Received Now</label><input name="amount_received_now" class="form-control currency-input" inputmode="decimal" placeholder="Blank = full amount due"><div class="form-hint">For commission-only, this is commission received. For full handling, this is gross amount after token.</div></div><div class="form-group"><label class="form-label">Narration *</label><input name="narration" class="form-control" value="Commission sale - <?= clean($car['registration_no']) ?>" required></div></div>
        <div class="form-actions form-actions-start"><button class="btn btn-success"><i class="ri-check-line"></i> Record Sale</button></div>
    </div>
</form>
<?php endif; ?>

<?php if ($ownerOutstanding > 0.009 && Auth::hasEntityAccess('car', 'write')): ?>
<form method="POST" class="card commission-owner-payment-card" data-confirm-submit="Pay this amount to the commission car owner and update the owner payable?">
    <?= csrfField() ?><input type="hidden" name="action" value="pay_owner">
    <div class="card-header"><h3><i class="ri-user-received-2-line"></i> Pay Vehicle Owner</h3></div>
    <div class="card-body"><div class="alert alert-warning"><i class="ri-information-line"></i> Outstanding to <?= clean($settlement['owner_name']) ?>: <strong><?= formatAmount($ownerOutstanding) ?></strong></div><div class="form-row-3"><div class="form-group"><label class="form-label">Amount (₹) *</label><input name="owner_payment_amount" class="form-control currency-input" value="<?= clean($ownerOutstanding) ?>" required></div><div class="form-group"><label class="form-label">Payment Date *</label><input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div><div class="form-group"><label class="form-label">Pay From *</label><select name="payment_account" class="form-control searchable-select" required><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= clean($account['name']) ?> · <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option><?php endforeach; ?></select></div></div><div class="form-group"><label class="form-label">Narration</label><input name="narration" class="form-control" value="Owner settlement - <?= clean($car['registration_no']) ?> - <?= clean($settlement['owner_name']) ?>"></div><button class="btn btn-primary"><i class="ri-bank-card-line"></i> Pay Owner</button></div>
</form>
<?php endif; ?>

<div class="card"><div class="card-header"><h3><i class="ri-exchange-line"></i> Entries and Reversal History</h3></div><div class="table-container"><table><thead><tr><th>Date</th><th>Reference</th><th>Type</th><th>Narration</th><th class="text-right">Money In</th><th class="text-right">Money Out</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$entries): ?><tr><td colspan="8" class="text-center text-muted" style="padding:32px;">No financial entries yet.</td></tr><?php endif; ?><?php foreach ($entries as $entry): ?><tr><td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td><td><a href="../transactions/view.php?id=<?= clean($entry['id']) ?>"><?= clean($entry['reference_no']) ?></a></td><td><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?></td><td><?= clean($entry['narration']) ?></td><td class="text-right flow-in"><?= $entry['money_in'] > 0 ? formatAmount($entry['money_in']) : '-' ?></td><td class="text-right flow-out"><?= $entry['money_out'] > 0 ? formatAmount($entry['money_out']) : '-' ?></td><td><span class="badge <?= $entry['status'] === 'POSTED' ? 'badge-green' : 'badge-red' ?>"><?= clean($entry['status']) ?></span></td><td><?php if ($entry['status'] === 'POSTED' && empty($entry['is_reversal']) && Auth::canAccessTransactionEntry($entry['id'], $businessId, 'delete')): ?><a href="../transactions/reverse.php?id=<?= clean($entry['id']) ?>" class="btn btn-sm btn-outline"><i class="ri-arrow-go-back-line"></i> Reverse</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
