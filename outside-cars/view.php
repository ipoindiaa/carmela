<?php
$pageTitle = 'Outside Car Detail';
$pageIcon = '<i class="ri-steering-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';

$id = get('id');
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
Auth::requireEntityAccess('car', 'read');

$car = $db->fetch(
    "SELECT c.*, owner.name AS owner_name, owner.phone AS owner_phone, owner.account_id AS owner_account_id,
            a.current_balance AS total_cost
     FROM cars c
     LEFT JOIN debtors_creditors owner ON owner.id = c.commission_owner_party_id
     LEFT JOIN accounts a ON a.id = c.account_id
     WHERE c.id = ? AND c.business_id = ? AND c.ownership_type = 'OUTSIDE'",
    [$id, $businessId]
);

if (!$car) {
    setFlash('error', 'Outside car not found.');
    redirect('list.php');
}

$primaryAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'write');
$paymentAccounts = array_merge($primaryAccountGroups['cash_book'] ?? [], $primaryAccountGroups['bank_book'] ?? []);
$paymentAccountIds = array_column($paymentAccounts, 'id');
$buyers = $db->fetchAll("SELECT id, name, phone FROM debtors_creditors WHERE business_id = ? AND is_active = 1 AND type IN ('BUYER','DEBTOR') ORDER BY name", [$businessId]);
$owners = $db->fetchAll("SELECT id, name, phone FROM debtors_creditors WHERE business_id = ? AND is_active = 1 AND type IN ('SELLER','CREDITOR') ORDER BY name", [$businessId]);

// Attachments
$buyerImages = fetchEntityAttachments($businessId, 'CAR', $id, 'BUYER');
$sellerImages = fetchEntityAttachments($businessId, 'CAR', $id, 'SELLER');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireEntityAccess('car', 'write');
    verifyCsrf();
    try {
        if ($car['status'] === 'CANCELLED') {
            throw new Exception('Deleted outside cars are read-only. History remains available.');
        }
        $action = post('action');

        if ($action === 'update_details') {
            $year = intval(post('year')) ?: null;
            if ($year && ($year < 1900 || $year > intval(date('Y')) + 1)) {
                throw new Exception('Enter a valid vehicle year.');
            }
            $ownerPartyId = post('commission_owner_party_id');
            $validOwner = $db->fetch("SELECT id FROM debtors_creditors WHERE id = ? AND business_id = ? AND is_active = 1 AND type IN ('SELLER','CREDITOR')", [$ownerPartyId, $businessId]);
            if (!$validOwner) throw new Exception('Select a valid source entity / owner.');

            if ($car['status'] !== 'IN_STOCK' && $ownerPartyId !== $car['commission_owner_party_id']) {
                throw new Exception('Source entity cannot be changed after car sale. Reverse the sale first.');
            }

            $oldDetails = array_intersect_key($car, array_flip(['make', 'model', 'year', 'color', 'has_second_key', 'commission_owner_party_id', 'notes']));
            $db->query(
                "UPDATE cars SET make = ?, model = ?, year = ?, color = ?, has_second_key = ?, commission_owner_party_id = ?, seller_party_id = ?, notes = ? WHERE id = ? AND business_id = ?",
                [post('make'), post('model'), $year, post('color'), post('has_second_key') === '1' ? 1 : 0, $ownerPartyId, $ownerPartyId, post('notes'), $id, $businessId]
            );
            $updatedCar = $db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$id, $businessId]);
            Auth::auditUpdate('car', $id, $oldDetails, array_intersect_key($updatedCar ?: [], array_flip(array_keys($oldDetails))), 'Outside car details updated', 'outside_cars');
            setFlash('success', 'Outside car details updated.');
        } elseif ($action === 'update_commission') {
            $commissionAmount = parseDecimalInput(post('expected_commission_amount'));
            $engine->updateOutsideCarCommission($id, $commissionAmount);
            setFlash('success', 'Commission amount updated to ' . formatAmount($commissionAmount) . '.');
        } elseif ($action === 'sell') {
            if (!in_array(post('payment_account'), $paymentAccountIds, true)) {
                throw new Exception('Select an accessible cash or bank account.');
            }
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
            setFlash('success', 'Outside car sale recorded. Commission posted as income. Entry: ' . $entryId);
        } elseif ($action === 'pay_owner') {
            if (!in_array(post('payment_account'), $paymentAccountIds, true)) {
                throw new Exception('Select an accessible cash or bank account.');
            }
            $entryId = $engine->payCommissionCarOwner($id, parseDecimalInput(post('owner_payment_amount')), post('payment_date'), post('payment_account'), post('narration'));
            setFlash('success', 'Owner payment recorded. Entry: ' . $entryId);
        } elseif ($action === 'upload_car_images') {
            $imageType = strtoupper(post('image_type', 'SELLER')) === 'BUYER' ? 'BUYER' : 'SELLER';
            $count = uploadEntityAttachments($businessId, 'CAR', $id, $imageType, 'car_images', Auth::user('user_id'), 'documents');
            setFlash('success', $count > 0 ? "$count file uploaded." : 'No file selected.');
        } elseif ($action === 'delete_car_image') {
            deleteAttachment($businessId, post('attachment_id'), 'CAR', $id);
            setFlash('success', 'Car file deleted.');
        } elseif ($action === 'return_car') {
            $engine->returnSoldCar($id, post('return_reason'));
            setFlash('success', 'Car return recorded. Car is back in stock.');
        } elseif ($action === 'second_key_event') {
            $engine->recordSecondKeyEvent($id, post('event_type'), post('event_date'), post('narration'));
            setFlash('success', 'Second key event saved.');
        }
        redirect("view.php?id=$id");
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect("view.php?id=$id" . (post('action') === 'update_details' ? '&edit=1' : ''));
    }
}

// Reload updated car data
$car = $db->fetch(
    "SELECT c.*, owner.name AS owner_name, owner.phone AS owner_phone, owner.account_id AS owner_account_id,
            a.current_balance AS total_cost
     FROM cars c
     LEFT JOIN debtors_creditors owner ON owner.id = c.commission_owner_party_id
     LEFT JOIN accounts a ON a.id = c.account_id
     WHERE c.id = ? AND c.business_id = ? AND c.ownership_type = 'OUTSIDE'",
    [$id, $businessId]
);

$profitability = $engine->getCarProfitability($id);
$expenses = $profitability['total_expenses'] ?? 0;
$settlement = $engine->getCommissionSettlement($id);
$ownerOutstanding = $settlement && $settlement['payment_handling'] === 'FULL_AMOUNT'
    ? max(0, floatval($settlement['owner_amount']) - floatval($settlement['paid_to_owner_amount'])) : 0;
$pending = $engine->getCarPendingAmounts($id);
$buyerOutstanding = floatval($pending['sale_pending'] ?? 0);
$tokenSummary = $engine->getCarTokenSummary($id);
$ledger = $engine->getCarTimeline($id);

$buyerParty = !empty($car['buyer_party_id']) ? $db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$car['buyer_party_id'], $businessId]) : null;
$sellerParty = !empty($car['seller_party_id']) ? $db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$car['seller_party_id'], $businessId]) : null;

$buyerHistory = $buyerParty ? $db->fetchAll(
    "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.transaction_type, je.narration, jl.amount, jl.entry_type
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.account_id = ?
     WHERE je.business_id = ? AND je.status IN ('POSTED','REVERSED') AND je.car_id = ?
     ORDER BY je.entry_date DESC, je.created_at DESC LIMIT 12",
    [$buyerParty['account_id'], $businessId, $id]
) : [];

$rtoRecords = $db->fetchAll("SELECT * FROM rto_records WHERE business_id = ? AND car_id = ? ORDER BY created_at DESC", [$businessId, $id]);
$rtoSpent = array_sum(array_map(static fn($row) => (float) $row['expense_amount'], $rtoRecords));
$rtoRecovered = array_sum(array_map(static fn($row) => (float) $row['recovered_amount'], $rtoRecords));

$keyEvents = $db->fetchAll(
    "SELECT ske.*, u.full_name FROM car_second_key_events ske LEFT JOIN users u ON u.id = ske.created_by WHERE ske.business_id = ? AND ske.car_id = ? ORDER BY ske.event_date DESC, ske.created_at DESC",
    [$businessId, $id]
);
?>

<div class="page-header">
    <div>
        <h1>
            <i class="ri-steering-2-line"></i> <?= clean(formatRegistrationNo($car['registration_no'])) ?>
            <span class="badge badge-purple" style="font-size: 13px; margin-left: 8px; vertical-align: middle;">Outside Car</span>
        </h1>
        <p class="page-subtitle">Sourced from external entity: <strong><?= clean($car['owner_name'] ?: 'External Party') ?></strong></p>
    </div>
    <div class="page-actions car-detail-actions">
        <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?>
            <a href="view.php?id=<?= $car['id'] ?>&amp;edit=1" class="btn btn-outline btn-sm"><i class="ri-edit-line"></i> Edit Car</a>
        <?php endif; ?>
        <a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= $car['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
        <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'delete')): ?>
            <a href="../delete_record.php?entity_type=car&amp;id=<?= clean($car['id']) ?>" class="btn btn-danger btn-sm"><i class="ri-delete-bin-line"></i> Delete</a>
        <?php endif; ?>
        <?php if ($buyerOutstanding > 0 && !empty($pending['buyer_party_id'])): ?>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'LOAN_RECEIVED', 'party_id' => $pending['buyer_party_id'], 'car_id' => $car['id'], 'amount' => round($buyerOutstanding), 'narration' => 'Outside car payment clearing - ' . $car['registration_no']]) ?>" class="btn btn-success btn-sm"><i class="ri-arrow-down-circle-line"></i> Receive Pending</a>
        <?php endif; ?>
        <?php if ($ownerOutstanding > 0): ?>
            <a href="#pay-owner-card" class="btn btn-primary btn-sm"><i class="ri-user-received-2-line"></i> Pay Owner</a>
        <?php endif; ?>
        <?php if ($car['status'] === 'IN_STOCK'): ?>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_TOKEN_RECEIVED', 'car_id' => $car['id'], 'narration' => 'Token received for outside car ' . $car['registration_no']]) ?>" class="btn btn-outline btn-sm"><i class="ri-hand-coin-line"></i> Receive Token</a>
            <a href="../transactions/new.php?type=CAR_EXPENSE&car_id=<?= $car['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-tools-line"></i> Add Expense</a>
            <a href="#record-sale-card" class="btn btn-success btn-sm"><i class="ri-money-rupee-circle-line"></i> Sell Car</a>
        <?php endif; ?>
        <?php if (!empty($car['buyer_party_id'])): ?>
            <a href="../cars/loan_commission.php?car_id=<?= urlencode($car['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-bank-card-line"></i> Loan Commission</a>
        <?php endif; ?>
        <a href="../rto/list.php?car_id=<?= clean($car['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-file-shield-2-line"></i> RTO</a>
        <a href="list.php<?= $car['status'] === 'CANCELLED' ? '?status=CANCELLED' : '' ?>" class="btn btn-outline btn-sm"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<div class="alert alert-info commission-accounting-note">
    <i class="ri-steering-2-line"></i>
    <div>
        <strong>Outside Car — Managed on Commission Basis</strong>
        <span>This car belongs to external entity <strong><?= clean($car['owner_name']) ?></strong>. Car repairs, RTO expenses, and buyer tokens are logged directly on this car. Commission will be recognized as business income upon sale.</span>
    </div>
</div>

<?php if (get('edit') === '1' && $car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?>
<div class="card car-edit-card" style="margin-bottom: 24px;">
    <div class="card-header"><h3><i class="ri-edit-line"></i> Edit Outside Car Details</h3></div>
    <div class="card-body">
        <form method="POST" data-confirm-submit="Save these outside car changes?">
            <?= csrfField() ?><input type="hidden" name="action" value="update_details">
            <div class="form-row-3">
                <div class="form-group"><label class="form-label">Make</label><input type="text" name="make" class="form-control" value="<?= clean($car['make']) ?>"></div>
                <div class="form-group"><label class="form-label">Model</label><input type="text" name="model" class="form-control" value="<?= clean($car['model']) ?>"></div>
                <div class="form-group"><label class="form-label">Year</label><input type="number" name="year" class="form-control" value="<?= clean($car['year']) ?>" min="1900" max="<?= date('Y') + 1 ?>"></div>
            </div>
            <div class="form-row-3">
                <div class="form-group"><label class="form-label">Color</label><input type="text" name="color" class="form-control" value="<?= clean($car['color']) ?>"></div>
                <div class="form-group"><label class="form-label">Second Key</label><select name="has_second_key" class="form-control"><option value="0" <?= empty($car['has_second_key']) ? 'selected' : '' ?>>No</option><option value="1" <?= !empty($car['has_second_key']) ? 'selected' : '' ?>>Yes</option></select></div>
                <div class="form-group">
                    <label class="form-label">Source Entity (Owner) *</label>
                    <select name="commission_owner_party_id" class="form-control searchable-select" <?= $car['status'] !== 'IN_STOCK' ? 'disabled' : '' ?>>
                        <?php foreach ($owners as $owner): ?>
                            <option value="<?= clean($owner['id']) ?>" <?= $owner['id'] === $car['commission_owner_party_id'] ? 'selected' : '' ?>><?= clean($owner['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($car['status'] !== 'IN_STOCK'): ?>
                        <input type="hidden" name="commission_owner_party_id" value="<?= clean($car['commission_owner_party_id']) ?>">
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= clean($car['notes']) ?></textarea></div>
            <div class="form-actions form-actions-start">
                <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Update Car</button>
                <a href="view.php?id=<?= $car['id'] ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="stats-grid car-detail-stats-grid">
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon" style="background: var(--accent-purple-glow); color: var(--accent-purple);"><i class="ri-user-shared-line"></i></div></div>
        <div class="stat-value"><?= clean($car['owner_name'] ?: 'External') ?></div>
        <div class="stat-label">Source Entity</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon" style="background: var(--accent-green-glow); color: var(--accent-green);"><i class="ri-percent-line"></i></div></div>
        <div class="stat-value flow-in">
            <?= ($settlement['commission_amount'] ?? $car['expected_commission_amount']) > 0 
                ? formatAmount($settlement['commission_amount'] ?? $car['expected_commission_amount']) 
                : 'Not set' ?>
        </div>
        <div class="stat-label"><?= $settlement ? 'Commission Income' : 'Agreed Commission' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon" style="background: var(--accent-yellow-glow); color: var(--accent-yellow);"><i class="ri-tools-line"></i></div></div>
        <div class="stat-value flow-out"><?= formatAmount($expenses) ?></div>
        <div class="stat-label">Total Expenses</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon" style="background: var(--accent-blue-glow); color: var(--accent-blue);"><i class="ri-line-chart-line"></i></div></div>
        <div class="stat-value">
            <span class="badge <?= $car['status'] === 'IN_STOCK' ? 'badge-blue' : ($car['status'] === 'SOLD' ? 'badge-green' : 'badge-yellow') ?>"><?= CAR_STATUS[$car['status']] ?></span>
        </div>
        <div class="stat-label">Vehicle Status</div>
    </div>
</div>

<div class="stats-grid compact-operational-grid car-detail-pending-grid">
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($buyerOutstanding) ?></div><div class="stat-label">Sale Pending</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($ownerOutstanding) ?></div><div class="stat-label">Payable to Owner</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($rtoSpent) ?></div><div class="stat-label">RTO Spent</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($rtoRecovered) ?></div><div class="stat-label">RTO Recovered</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($tokenSummary['available']) ?></div><div class="stat-label">Token Held</div></div>
</div>

<!-- Commission Management Section -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <div>
            <h3><i class="ri-percent-line"></i> Commission Terms</h3>
            <div class="card-header-note">Commission can be added or updated anytime before recording the car sale.</div>
        </div>
    </div>
    <div class="card-body">
        <?php if ($car['status'] === 'IN_STOCK' && Auth::hasEntityAccess('car', 'write')): ?>
            <form method="POST" class="inline-entry-form" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                <?= csrfField() ?><input type="hidden" name="action" value="update_commission">
                <div class="form-group" style="margin-bottom: 0; min-width: 250px;">
                    <label class="form-label">Our Agreed Commission (₹)</label>
                    <div class="input-group">
                        <span class="input-prefix">₹</span>
                        <input type="text" name="expected_commission_amount" class="form-control currency-input" value="<?= clean($car['expected_commission_amount'] ?: '') ?>" placeholder="Enter commission amount" inputmode="decimal" autocomplete="off" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Commission</button>
            </form>
        <?php else: ?>
            <div style="font-size: 16px;">
                Current Commission: <strong><?= $car['expected_commission_amount'] > 0 ? formatAmount($car['expected_commission_amount']) : 'Not set' ?></strong>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2 car-detail-main-grid">
    <!-- Car Details Card -->
    <div class="card">
        <div class="card-header"><h3><i class="ri-car-line"></i> Car Details</h3></div>
        <div class="card-body">
            <div class="table-container table-container-inline table-columns-compact">
            <table style="width: 100%;">
                <tr><td class="text-muted" style="padding: 8px 0; width: 40%;">Registration</td><td class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Make / Model</td><td><?= clean($car['make'] . ' ' . $car['model']) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Year</td><td><?= $car['year'] ?: '-' ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Color</td><td><?= clean($car['color'] ?: '-') ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Date Received</td><td><?= formatDate($car['purchase_date']) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Status</td><td><span class="badge <?= $car['status'] === 'IN_STOCK' ? 'badge-blue' : ($car['status'] === 'SOLD' ? 'badge-green' : 'badge-yellow') ?>"><?= CAR_STATUS[$car['status']] ?></span></td></tr>
                <?php if ($car['sold_date']): ?><tr><td class="text-muted" style="padding: 8px 0;">Sold Date</td><td><?= formatDate($car['sold_date']) ?></td></tr><?php endif; ?>
                <?php if ($car['sale_price']): ?><tr><td class="text-muted" style="padding: 8px 0;">Gross Sale Value</td><td class="amount flow-in"><?= formatAmount($car['sale_price']) ?> <small class="text-muted">(memo)</small></td></tr><?php endif; ?>
                <?php if (!empty($car['sale_commission_amount'])): ?><tr><td class="text-muted" style="padding: 8px 0;">Commission Income</td><td class="amount flow-in text-bold"><?= formatAmount($car['sale_commission_amount']) ?></td></tr><?php endif; ?>
                <?php if ($car['buyer_name']): ?><tr><td class="text-muted" style="padding: 8px 0;">Buyer</td><td><?= clean($car['buyer_name']) ?></td></tr><?php endif; ?>
                <tr><td class="text-muted" style="padding: 8px 0;">Second Key</td><td><span class="badge <?= !empty($car['has_second_key']) ? 'badge-green' : 'badge-gray' ?>"><?= !empty($car['has_second_key']) ? 'Yes' : 'No' ?></span></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Notes</td><td><?= clean($car['notes'] ?: '-') ?></td></tr>
            </table>
            </div>
        </div>
    </div>

    <!-- Source Entity Details Card -->
    <div class="card">
        <div class="card-header"><h3><i class="ri-user-shared-line"></i> Source Entity Account</h3></div>
        <div class="card-body">
            <div class="table-container table-container-inline table-columns-compact">
            <table style="width: 100%;">
                <tr>
                    <td class="text-muted" style="padding: 8px 0; width: 40%;">Entity Name</td>
                    <td class="text-bold">
                        <?php if (!empty($car['commission_owner_party_id'])): ?>
                            <a href="../parties/view.php?id=<?= urlencode($car['commission_owner_party_id']) ?>"><?= clean($car['owner_name']) ?></a>
                        <?php else: ?>
                            <?= clean($car['owner_name'] ?: 'Not assigned') ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Phone</td><td><?= clean($car['owner_phone'] ?: '-') ?></td></tr>
                <?php if (!empty($car['owner_account_id'])): ?>
                    <?php
                        $ownerAcc = $db->fetch("SELECT current_balance, current_balance_type FROM accounts WHERE id = ?", [$car['owner_account_id']]);
                    ?>
                    <?php if ($ownerAcc): ?>
                        <tr><td class="text-muted" style="padding: 8px 0;">Ledger Balance</td><td class="amount text-bold"><?= formatAmount($ownerAcc['current_balance']) ?> <?= clean($ownerAcc['current_balance_type']) ?></td></tr>
                    <?php endif; ?>
                <?php endif; ?>
                <tr><td class="text-muted" style="padding: 8px 0;">Owner Payable</td><td class="amount flow-out"><?= formatAmount($ownerOutstanding) ?></td></tr>
            </table>
            </div>
            <div style="margin-top: 16px;">
                <?php if (!empty($car['commission_owner_party_id'])): ?>
                    <a href="../parties/view.php?id=<?= urlencode($car['commission_owner_party_id']) ?>" class="btn btn-outline btn-sm"><i class="ri-book-read-line"></i> Open Entity Ledger</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($settlement): ?>
<div class="card" style="margin-top: 24px;">
    <div class="card-header"><h3><i class="ri-scales-3-line"></i> Sale &amp; Owner Settlement Summary</h3></div>
    <div class="card-body detail-list">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
            <div><span class="text-muted">Buyer:</span> <strong><?= clean($settlement['buyer_name']) ?></strong></div>
            <div><span class="text-muted">Handling:</span> <strong><?= $settlement['payment_handling'] === 'FULL_AMOUNT' ? 'Business collected full amount' : 'Owner collected sale amount directly' ?></strong></div>
            <div><span class="text-muted">Gross Sale:</span> <strong><?= formatAmount($settlement['gross_sale_amount']) ?></strong></div>
            <div><span class="text-muted">Our Commission Income:</span> <strong class="flow-in"><?= formatAmount($settlement['commission_amount']) ?></strong></div>
            <div><span class="text-muted">Owner Share:</span> <strong><?= formatAmount($settlement['owner_amount']) ?></strong></div>
            <div><span class="text-muted">Paid to Owner:</span> <strong><?= formatAmount($settlement['paid_to_owner_amount']) ?></strong></div>
            <div><span class="text-muted">Owner Payable:</span> <strong class="flow-out"><?= formatAmount($ownerOutstanding) ?></strong></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Record Sale Card (if IN_STOCK) -->
<?php if ($car['status'] === 'IN_STOCK' && Auth::hasEntityAccess('car', 'write')): ?>
<form method="POST" id="record-sale-card" class="card commission-sale-card" style="margin-top: 24px;" data-confirm-submit="Record sale for this outside car? Only our commission will be posted as income.">
    <?= csrfField() ?><input type="hidden" name="action" value="sell">
    <div class="card-header"><h3><i class="ri-money-rupee-circle-line"></i> Record Outside Car Sale</h3></div>
    <div class="card-body">
        <div class="form-row-3">
            <div class="form-group">
                <label class="form-label">Gross Sale Value (₹) *</label>
                <input name="gross_sale_amount" class="form-control currency-input" inputmode="decimal" placeholder="Total car price paid by buyer" required>
                <div class="form-hint">Memorandum value only — not recorded as business revenue.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Our Commission Income (₹) *</label>
                <input name="commission_amount" class="form-control currency-input" inputmode="decimal" value="<?= clean($car['expected_commission_amount'] ?: '') ?>" placeholder="Our earning" required>
                <div class="form-hint">Only this commission enters Profit &amp; Loss as income.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Sale Date *</label>
                <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <?php $buyerMode = $buyers ? 'existing' : 'new'; ?>
        <div class="exclusive-choice" data-exclusive-choice data-default-mode="<?= clean($buyerMode) ?>">
            <input type="hidden" name="buyer_mode" value="<?= clean($buyerMode) ?>" data-exclusive-mode data-keep-enabled="1">
            <div class="exclusive-choice-header" style="margin-bottom: 12px;">
                <div><strong>Buyer / Customer *</strong><span>Select customer ledger or add a new customer.</span></div>
                <div class="exclusive-choice-options" role="group" aria-label="Buyer source">
                    <?php if ($buyers): ?><button type="button" class="exclusive-choice-option" data-exclusive-option="existing"><i class="ri-search-line"></i> Select Existing</button><?php endif; ?>
                    <button type="button" class="exclusive-choice-option" data-exclusive-option="new"><i class="ri-user-add-line"></i> Add New</button>
                </div>
            </div>
            <?php if ($buyers): ?>
            <div data-exclusive-panel="existing">
                <div class="form-group">
                    <label class="form-label">Buyer / Customer *</label>
                    <select name="buyer_party_id" class="form-control searchable-select" required>
                        <option value="">Select buyer</option>
                        <?php foreach ($buyers as $buyer): ?>
                            <option value="<?= clean($buyer['id']) ?>" <?= !empty($tokenSummary['party_id']) && $tokenSummary['party_id'] === $buyer['id'] ? 'selected' : '' ?>><?= clean($buyer['name']) ?><?= $buyer['phone'] ? ' - ' . clean($buyer['phone']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>
            <div data-exclusive-panel="new">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Buyer Name *</label><input name="buyer_name" class="form-control" placeholder="Enter buyer name" required></div>
                    <div class="form-group"><label class="form-label">Phone</label><input name="buyer_phone" class="form-control" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="10-digit mobile number"></div>
                </div>
            </div>
        </div>

        <div class="form-group" style="margin-top: 16px;">
            <label class="form-label">How Buyer Payment Was Handled *</label>
            <select name="payment_handling" class="form-control">
                <option value="COMMISSION_ONLY">Owner received car sale amount directly; business received commission only</option>
                <option value="FULL_AMOUNT" <?= ($tokenSummary['available'] ?? 0) > 0 ? 'selected' : '' ?>>Business collected full sale amount from buyer; owner share is payable to entity</option>
            </select>
        </div>

        <div class="form-row-3">
            <div class="form-group">
                <label class="form-label">Receive Payment Into *</label>
                <select name="payment_account" class="form-control searchable-select" required>
                    <?php foreach ($paymentAccounts as $account): ?>
                        <option value="<?= clean($account['id']) ?>"><?= clean($account['name']) ?> · <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Amount Received Now (₹)</label>
                <input name="amount_received_now" class="form-control currency-input" inputmode="decimal" placeholder="Leave blank if full amount received">
                <div class="form-hint">Blank = full amount due received now.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Narration (Optional)</label>
                <input name="narration" class="form-control" placeholder="Optional" value="Outside car commission sale - <?= clean($car['registration_no']) ?>">
            </div>
        </div>

        <div class="form-actions form-actions-start"><button type="submit" class="btn btn-success btn-lg"><i class="ri-check-line"></i> Complete Sale &amp; Post Commission</button></div>
    </div>
</form>
<?php endif; ?>

<!-- Pay Vehicle Owner Card (if outstanding) -->
<?php if ($ownerOutstanding > 0.009 && Auth::hasEntityAccess('car', 'write')): ?>
<form method="POST" id="pay-owner-card" class="card commission-owner-payment-card" style="margin-top: 24px;" data-confirm-submit="Pay this amount to source entity <?= clean($car['owner_name']) ?>?">
    <?= csrfField() ?><input type="hidden" name="action" value="pay_owner">
    <div class="card-header"><h3><i class="ri-user-received-2-line"></i> Pay Source Entity (Owner)</h3></div>
    <div class="card-body">
        <div class="alert alert-warning"><i class="ri-information-line"></i> Outstanding payable to <strong><?= clean($car['owner_name']) ?></strong>: <strong><?= formatAmount($ownerOutstanding) ?></strong></div>
        <div class="form-row-3">
            <div class="form-group">
                <label class="form-label">Amount (₹) *</label>
                <input name="owner_payment_amount" class="form-control currency-input" value="<?= clean($ownerOutstanding) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Date *</label>
                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Pay From Account *</label>
                <select name="payment_account" class="form-control searchable-select" required>
                    <?php foreach ($paymentAccounts as $account): ?>
                        <option value="<?= clean($account['id']) ?>"><?= clean($account['name']) ?> · <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Narration</label>
            <input name="narration" class="form-control" value="Outside car owner payment - <?= clean($car['registration_no']) ?> - <?= clean($car['owner_name']) ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="ri-bank-card-line"></i> Pay Source Entity</button>
    </div>
</form>
<?php endif; ?>

<!-- File Attachments Card -->
<div class="card car-images-card" style="margin-top: 24px;">
    <div class="card-header">
        <h3><i class="ri-attachment-2"></i> Car Files &amp; Documents</h3>
    </div>
    <div class="card-body">
        <?php if (Auth::hasEntityAccess('car', 'write')): ?>
        <form method="POST" enctype="multipart/form-data" class="attachment-upload-panel car-images-upload-panel">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_car_images">
            <div class="form-group">
                <label class="form-label">File Source</label>
                <select name="image_type" class="form-control searchable-select">
                    <option value="SELLER">From Source Entity / Owner</option>
                    <option value="BUYER">From Buyer</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Upload Files</label>
                <input type="file" name="car_images[]" class="form-control" accept="<?= clean(attachmentAcceptAttribute('documents')) ?>" multiple>
                <div class="form-hint">Photos, RC copy, agreement, delivery proof, PDF, or Office documents. Max 10 MB each.</div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-upload-cloud-2-line"></i> Upload</button>
        </form>
        <?php endif; ?>

        <div class="attachment-columns car-images-columns">
            <?php foreach ([['title' => 'From Source Entity', 'items' => $sellerImages], ['title' => 'From Buyer', 'items' => $buyerImages]] as $group): ?>
                <div>
                    <h4 class="attachment-group-title"><?= clean($group['title']) ?></h4>
                    <?php if (empty($group['items'])): ?>
                        <div class="empty-state compact">No files uploaded.</div>
                    <?php else: ?>
                        <div class="attachment-grid">
                            <?php foreach ($group['items'] as $attachment): ?>
                                <?php $url = attachmentUrl($attachment); $shareUrl = attachmentUrl($attachment, true); $isImage = attachmentIsImage($attachment); ?>
                                <div class="attachment-card">
                                    <a href="<?= clean($url) ?>" target="_blank" rel="noopener" class="attachment-preview">
                                        <?php if ($isImage): ?>
                                            <img src="<?= clean($url) ?>" alt="<?= clean($attachment['original_name']) ?>">
                                        <?php else: ?>
                                            <div class="attachment-file-icon"><i class="<?= clean(attachmentIconClass($attachment)) ?>"></i><span><?= clean(attachmentTypeLabel($attachment)) ?></span></div>
                                        <?php endif; ?>
                                    </a>
                                    <div class="attachment-meta">
                                        <strong><?= clean($attachment['original_name']) ?></strong>
                                        <span><?= formatDate($attachment['created_at'], 'd M Y, h:i A') ?></span>
                                    </div>
                                    <div class="attachment-actions">
                                        <a href="<?= clean($url) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i> Open</a>
                                        <?php if (Auth::hasEntityAccess('car', 'delete')): ?>
                                        <form method="POST" data-confirm-submit="Delete this file?" style="display:inline-flex;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_car_image">
                                            <input type="hidden" name="attachment_id" value="<?= clean($attachment['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline text-red"><i class="ri-delete-bin-line"></i> Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Key Movements & Token History -->
<div class="grid-2 car-support-grid" style="margin-top:24px;">
    <!-- Second Key -->
    <div class="card">
        <div class="card-header"><h3><i class="ri-key-2-line"></i> Second Key Log</h3></div>
        <div class="card-body">
            <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?>
            <form method="POST" class="inline-entry-form">
                <?= csrfField() ?><input type="hidden" name="action" value="second_key_event">
                <select name="event_type" class="form-control"><option value="RECEIVED">Second Key Received</option><option value="GIVEN">Second Key Given</option></select>
                <input type="date" name="event_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                <input type="text" name="narration" class="form-control" placeholder="Notes">
                <button class="btn btn-primary btn-sm">Save</button>
            </form>
            <?php endif; ?>
            <?php if (empty($keyEvents)): ?>
                <p class="text-muted" style="margin-top:12px;">No key movement logged.</p>
            <?php else: ?>
                <table class="table-compact" style="margin-top:12px;">
                    <thead><tr><th>Date</th><th>Event</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($keyEvents as $event): ?>
                            <tr><td><?= formatDate($event['event_date']) ?></td><td><span class="badge <?= $event['event_type'] === 'RECEIVED' ? 'badge-green' : 'badge-yellow' ?>"><?= clean($event['event_type']) ?></span></td><td><?= clean($event['narration'] ?: '-') ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Buyer Token -->
    <div class="card">
        <div class="card-header">
            <h3><i class="ri-hand-coin-line"></i> Buyer Token</h3>
            <?php if ($car['status'] === 'IN_STOCK'): ?>
                <a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_TOKEN_RECEIVED', 'car_id' => $car['id'], 'narration' => 'Token received for outside car ' . $car['registration_no']]) ?>" class="btn btn-outline btn-sm"><i class="ri-add-line"></i> Receive Token</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($tokenSummary['rows'])): ?>
                <p class="text-muted">No token received for this car.</p>
            <?php else: ?>
                <table class="table-compact">
                    <thead><tr><th>Date</th><th>Buyer</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($tokenSummary['rows'] as $token): ?>
                            <tr>
                                <td><?= formatDate($token['received_date']) ?></td>
                                <td><?= clean($token['party_name']) ?></td>
                                <td class="text-right amount flow-in"><?= formatAmount($token['amount']) ?></td>
                                <td><span class="badge <?= $token['status'] === 'APPLIED' ? 'badge-green' : 'badge-blue' ?>"><?= clean($token['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Return Sold Car Card (if sold) -->
<?php if (in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true)): ?>
<div class="card" style="margin-top:24px;">
    <div class="card-header"><h3><i class="ri-arrow-go-back-line"></i> Return Outside Car</h3></div>
    <div class="card-body">
        <form method="POST" class="inline-entry-form" onsubmit="return confirm('Return this outside car and reverse sale entry?');">
            <?= csrfField() ?><input type="hidden" name="action" value="return_car">
            <input type="text" name="return_reason" class="form-control" placeholder="Reason for car return" required>
            <button class="btn btn-outline btn-sm"><i class="ri-arrow-go-back-line"></i> Return Car</button>
        </form>
        <div class="form-hint">Returns the car to IN_STOCK status and reverses sale accounting entry.</div>
    </div>
</div>
<?php endif; ?>

<!-- Car Timeline / Ledger -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <div>
            <h3><i class="ri-book-2-line"></i> Car Timeline &amp; Ledger Activity</h3>
            <div class="card-header-note">All expenses, RTO transactions, token receipts, and sale entries logged against this car.</div>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-container">
        <table>
            <thead><tr><th>Date / Time</th><th>Ref</th><th>Type</th><th>Narration</th><th>Status</th><th class="text-right flow-in">Money In</th><th class="text-right flow-out">Money Out</th></tr></thead>
            <tbody>
                <?php if (empty($ledger)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding: 30px;">No ledger entries for this car yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($ledger as $l):
                        $displayMoneyIn = '';
                        $displayMoneyOut = '';
                        $flow = transactionBusinessFlow($l['transaction_type'], $l);
                        $eventAmount = round(floatval($l['entry_amount'] ?? 0), 2);

                        if ($eventAmount <= 0) {
                            $eventAmount = max(
                                floatval($l['cash_in_amount'] ?? 0),
                                floatval($l['cash_out_amount'] ?? 0),
                                floatval($l['car_line_amount'] ?? 0)
                            );
                        }
                        if ($flow === 'in' && $eventAmount > 0) {
                            $displayMoneyIn = formatAmount($eventAmount);
                        } elseif ($flow === 'out' && $eventAmount > 0) {
                            $displayMoneyOut = formatAmount($eventAmount);
                        }
                    ?>
                    <tr>
                        <td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td>
                        <td><a href="../transactions/view.php?id=<?= urlencode($l['entry_id']) ?>"><?= clean($l['reference_no']) ?></a></td>
                        <td><span class="badge badge-blue" style="font-size: 10px;"><?= clean(transactionTypeLabel($l['transaction_type'], $l)) ?></span></td>
                        <td><?= clean($l['narration'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($l['is_reversal'])): ?>
                                <span class="badge badge-yellow">Reversal</span>
                            <?php elseif ($l['status'] === 'REVERSED'): ?>
                                <span class="badge badge-red">Reversed</span>
                            <?php else: ?>
                                <span class="badge badge-green">Posted</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right amount flow-in"><?= $displayMoneyIn ?></td>
                        <td class="text-right amount flow-out"><?= $displayMoneyOut ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
