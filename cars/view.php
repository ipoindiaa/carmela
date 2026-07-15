<?php
$pageTitle = 'Car Detail';
$pageIcon = '<i class="ri-car-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';

$id = get('id');
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
Auth::requireEntityAccess('car', 'read');
$engine->syncCarPartyLinks($id);

$car = $db->fetch("SELECT c.*, a.current_balance as total_cost FROM cars c LEFT JOIN accounts a ON a.id = c.account_id WHERE c.id = ? AND c.business_id = ?", [$id, $businessId]);
if (!$car) { setFlash('error', 'Car not found.'); redirect('list.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireEntityAccess('car', 'write');
    verifyCsrf();
    try {
        $action = post('action');
        if ($action === 'update_details') {
            $year = intval(post('year')) ?: null;
            if ($year && ($year < 1900 || $year > intval(date('Y')) + 1)) throw new Exception('Enter a valid vehicle year.');
            $oldDetails = array_intersect_key($car, array_flip(['make', 'model', 'year', 'color', 'has_second_key', 'notes']));
            $db->query(
                "UPDATE cars SET make = ?, model = ?, year = ?, color = ?, has_second_key = ?, partner_id = NULL, notes = ? WHERE id = ? AND business_id = ?",
                [post('make'), post('model'), $year, post('color'), post('has_second_key') === '1' ? 1 : 0, post('notes'), $id, $businessId]
            );
            $updatedCar = $db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$id, $businessId]);
            $newDetails = array_intersect_key($updatedCar ?: [], array_flip(['make', 'model', 'year', 'color', 'has_second_key', 'notes']));
            Auth::auditUpdate('car', $id, $oldDetails, $newDetails, 'Car details updated', 'cars');
            setFlash('success', 'Car details updated.');
        } elseif ($action === 'correct_partner_funding') {
            $partnerIds = array_values((array) ($_POST['partner_ids'] ?? []));
            $amounts = array_values((array) ($_POST['partner_amounts'] ?? []));
            $shares = array_values((array) ($_POST['partner_profit_share_pcts'] ?? []));
            $partnerFunding = [];
            $seen = [];
            $rowCount = max(count($partnerIds), count($amounts), count($shares));
            for ($index = 0; $index < $rowCount; $index++) {
                $partnerId = trim((string) ($partnerIds[$index] ?? ''));
                $amountInput = trim((string) ($amounts[$index] ?? ''));
                $shareInput = trim((string) ($shares[$index] ?? ''));
                if ($partnerId === '') {
                    if ($amountInput !== '' || $shareInput !== '') throw new Exception('Select a partner for every funding row.');
                    continue;
                }
                if (isset($seen[$partnerId])) throw new Exception('Each partner can appear only once.');
                $seen[$partnerId] = true;
                $partnerFunding[] = [
                    'partner_id' => $partnerId,
                    'amount' => $amountInput === '' ? 0 : parseDecimalInput($amountInput),
                    'profit_share_pct' => $shareInput,
                ];
            }
            $engine->correctCarPartnerFunding($id, $partnerFunding, post('correction_date'), post('correction_reason'));
            setFlash('success', 'Partner funding updated. Financial corrections and field changes are preserved in History.');
        } elseif ($action === 'upload_car_images') {
            $imageType = strtoupper(post('image_type', 'SELLER')) === 'BUYER' ? 'BUYER' : 'SELLER';
            $count = uploadEntityAttachments($businessId, 'CAR', $id, $imageType, 'car_images', Auth::user('user_id'), 'images');
            setFlash('success', $count > 0 ? "$count image uploaded." : 'No image selected.');
        } elseif ($action === 'delete_car_image') {
            deleteAttachment($businessId, post('attachment_id'), 'CAR', $id);
            setFlash('success', 'Car image deleted.');
        } elseif ($action === 'return_car') {
            $engine->returnSoldCar($id, post('return_reason'));
            setFlash('success', 'Car return recorded. Car is back in stock.');
        } elseif ($action === 'second_key_event') {
            $engine->recordSecondKeyEvent($id, post('event_type'), post('event_date'), post('narration'));
            setFlash('success', 'Second key event saved.');
        }
        redirect("view.php?id=$id");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        redirect("view.php?id=$id");
    }
}

$profitability = $engine->getCarProfitability($id);
$profit = $profitability['status'] === 'SOLD' ? $profitability['profit'] : null;
$expenses = $profitability['total_expenses'];
$carTotalCost = $profitability['total_cost'] ?? $car['purchase_price'];
$partnerships = $profitability['partnerships'];
$partners = $db->fetchAll("SELECT id, name, partner_type FROM partners WHERE business_id = ? AND is_active = 1 ORDER BY name", [$businessId]);
$buyerImages = fetchEntityAttachments($businessId, 'CAR', $id, 'BUYER');
$sellerImages = fetchEntityAttachments($businessId, 'CAR', $id, 'SELLER');

$buyerParty = !empty($car['buyer_party_id']) ? $db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$car['buyer_party_id'], $businessId]) : null;
$sellerParty = !empty($car['seller_party_id']) ? $db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$car['seller_party_id'], $businessId]) : null;
$carPending = $engine->getCarPendingAmounts($id);
$buyerOutstanding = (float) ($carPending['sale_pending'] ?? 0);
$sellerOutstanding = (float) ($carPending['purchase_pending'] ?? 0);
$buyerHistory = $buyerParty ? $db->fetchAll(
    "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.transaction_type, je.narration, jl.amount, jl.entry_type
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.account_id = ?
     WHERE je.business_id = ? AND je.status = 'POSTED' AND je.car_id = ?
     ORDER BY je.entry_date DESC, je.created_at DESC LIMIT 12",
    [$buyerParty['account_id'], $businessId, $id]
) : [];
$sellerHistory = $sellerParty ? $db->fetchAll(
    "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.transaction_type, je.narration, jl.amount, jl.entry_type
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.account_id = ?
     WHERE je.business_id = ? AND je.status = 'POSTED' AND je.car_id = ?
     ORDER BY je.entry_date DESC, je.created_at DESC LIMIT 12",
    [$sellerParty['account_id'], $businessId, $id]
) : [];
$rtoRecords = $db->fetchAll(
    "SELECT * FROM rto_records WHERE business_id = ? AND car_id = ? ORDER BY created_at DESC",
    [$businessId, $id]
);
$rtoHistory = $db->fetchAll(
    "(SELECT
        r.id AS record_id,
        je.id AS entry_id,
        je.entry_date,
        je.created_at,
        r.rto_type,
        r.party_name,
        r.agent_name,
        'EXPENSE' AS money_type,
        0 AS received_amount,
        r.expense_amount AS spent_amount
      FROM rto_records r
      JOIN journal_entries je ON je.id = r.expense_entry_id
      WHERE r.business_id = ? AND r.car_id = ? AND r.expense_entry_id IS NOT NULL)
     UNION ALL
     (SELECT
        r.id AS record_id,
        rr.journal_entry_id AS entry_id,
        rr.received_date AS entry_date,
        je.created_at,
        r.rto_type,
        r.party_name,
        r.agent_name,
        'RECEIVE' AS money_type,
        rr.amount AS received_amount,
        0 AS spent_amount
      FROM rto_recoveries rr
      JOIN rto_records r ON r.id = rr.rto_record_id
      LEFT JOIN journal_entries je ON je.id = rr.journal_entry_id
      WHERE r.business_id = ? AND r.car_id = ?)
     ORDER BY entry_date DESC, created_at DESC",
    [$businessId, $id, $businessId, $id]
);
$rtoSpent = array_sum(array_map(static fn($row) => (float) $row['expense_amount'], $rtoRecords));
$rtoRecovered = array_sum(array_map(static fn($row) => (float) $row['recovered_amount'], $rtoRecords));
$rtoPending = max(0, $rtoSpent - $rtoRecovered);
$keyEvents = $db->fetchAll(
    "SELECT ske.*, u.full_name FROM car_second_key_events ske LEFT JOIN users u ON u.id = ske.created_by WHERE ske.business_id = ? AND ske.car_id = ? ORDER BY ske.event_date DESC, ske.created_at DESC",
    [$businessId, $id]
);
$tokenSummary = $engine->getCarTokenSummary($id);

// Full car timeline, including payment-clearing entries linked to this car.
$ledger = $db->fetchAll(
    "SELECT
        je.id AS entry_id,
        je.entry_date,
        je.created_at,
        je.reference_no,
        je.narration,
        je.transaction_type,
        MAX(CASE WHEN jl.account_id = ? THEN jl.amount END) AS car_line_amount,
        MAX(CASE WHEN jl.account_id = ? THEN jl.entry_type END) AS car_line_type,
        COALESCE(SUM(CASE WHEN a.entity_type IN ('CASH','BANK') AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS cash_in_amount,
        COALESCE(SUM(CASE WHEN a.entity_type IN ('CASH','BANK') AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS cash_out_amount
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     JOIN accounts a ON a.id = jl.account_id
     WHERE je.business_id = ?
       AND je.status = 'POSTED'
       AND je.car_id = ?
     GROUP BY je.id, je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type
     ORDER BY je.entry_date DESC, je.created_at DESC",
    [$car['account_id'], $car['account_id'], $businessId, $id]
);

// Current per-car partner terms, including partners with profit share but no cash contribution.
$contributions = $db->fetchAll(
    "SELECT cp.*, cp.funding_amount AS amount, c.purchase_date AS contribution_date, p.name AS partner_name
     FROM car_partnerships cp
     JOIN partners p ON p.id = cp.partner_id
     JOIN cars c ON c.id = cp.car_id
     WHERE cp.business_id = ? AND cp.car_id = ? AND cp.status = 'ACTIVE'
     ORDER BY cp.created_at", [$businessId, $id]);
$totalPartnerFunding = round(array_sum(array_map(static fn($row) => floatval($row['amount']), $contributions)), 2);
?>

<div class="page-header">
    <h1><i class="ri-car-line"></i> <?= clean(formatRegistrationNo($car['registration_no'])) ?></h1>
    <div class="page-actions car-detail-actions">
        <?php if (Auth::hasEntityAccess('car', 'write')): ?><a href="view.php?id=<?= $car['id'] ?>&amp;edit=1" class="btn btn-outline btn-sm"><i class="ri-edit-line"></i> Edit</a><?php endif; ?>
        <a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= $car['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
        <?php if ($buyerOutstanding > 0 && !empty($carPending['buyer_party_id'])): ?>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'LOAN_RECEIVED', 'party_id' => $carPending['buyer_party_id'], 'car_id' => $car['id'], 'amount' => round($buyerOutstanding), 'narration' => 'Car payment clearing - ' . $car['registration_no']]) ?>" class="btn btn-success btn-sm"><i class="ri-arrow-down-circle-line"></i> Receive Pending</a>
        <?php endif; ?>
        <?php if ($sellerOutstanding > 0 && !empty($carPending['seller_party_id'])): ?>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'LOAN_REPAID', 'party_id' => $carPending['seller_party_id'], 'car_id' => $car['id'], 'amount' => round($sellerOutstanding), 'narration' => 'Seller payment clearing - ' . $car['registration_no']]) ?>" class="btn btn-outline btn-sm"><i class="ri-arrow-up-circle-line"></i> Pay Seller</a>
        <?php endif; ?>
        <?php if ($car['status'] === 'IN_STOCK'): ?>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_TOKEN_RECEIVED', 'car_id' => $car['id'], 'narration' => 'Token received for ' . $car['registration_no']]) ?>" class="btn btn-outline btn-sm"><i class="ri-hand-coin-line"></i> Receive Token</a>
            <a href="../transactions/new.php?type=CAR_EXPENSE&car_id=<?= $car['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-tools-line"></i> Add Expense</a>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_SALE', 'car_id' => $car['id']]) ?>" class="btn btn-success btn-sm"><i class="ri-money-rupee-circle-line"></i> Sell Car</a>
        <?php endif; ?>
        <a href="../rto/list.php?car_id=<?= clean($car['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-file-shield-2-line"></i> RTO</a>
        <a href="list.php" class="btn btn-outline btn-sm"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<?php if (get('edit') === '1' && Auth::hasEntityAccess('car', 'write')): ?>
<div class="card car-edit-card">
    <div class="card-header"><h3><i class="ri-edit-line"></i> Edit Car Details</h3></div>
    <div class="card-body">
        <form method="POST" data-confirm-submit="Save these car detail changes? Every changed field will be recorded in History.">
            <?= csrfField() ?><input type="hidden" name="action" value="update_details">
            <div class="alert alert-info"><i class="ri-information-line"></i> Registration, purchase amount, purchase date, sale amounts, and status come from accounting entries and remain read-only. Use reversal for financial corrections.</div>
            <div class="form-row-3">
                <div class="form-group"><label class="form-label">Make</label><input type="text" name="make" class="form-control" value="<?= clean($car['make']) ?>"></div>
                <div class="form-group"><label class="form-label">Model</label><input type="text" name="model" class="form-control" value="<?= clean($car['model']) ?>"></div>
                <div class="form-group"><label class="form-label">Year</label><input type="number" name="year" class="form-control" value="<?= clean($car['year']) ?>" min="1900" max="<?= date('Y') + 1 ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Color</label><input type="text" name="color" class="form-control" value="<?= clean($car['color']) ?>"></div>
                <div class="form-group"><label class="form-label">Second Key</label><select name="has_second_key" class="form-control"><option value="0" <?= empty($car['has_second_key']) ? 'selected' : '' ?>>No</option><option value="1" <?= !empty($car['has_second_key']) ? 'selected' : '' ?>>Yes</option></select></div>
            </div>
            <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= clean($car['notes']) ?></textarea></div>
            <div class="form-actions form-actions-start"><button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Update Car</button><a href="view.php?id=<?= $car['id'] ?>" class="btn btn-outline">Cancel</a></div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Car Summary Cards -->
<div class="stats-grid car-detail-stats-grid">
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon" style="background: var(--accent-blue-glow); color: var(--accent-blue);"><i class="ri-shopping-cart-line"></i></div></div>
        <div class="stat-value flow-out"><?= formatAmount($car['purchase_price']) ?></div>
        <div class="stat-label">Purchase Price</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon" style="background: var(--accent-yellow-glow); color: var(--accent-yellow);"><i class="ri-tools-line"></i></div></div>
        <div class="stat-value flow-out"><?= formatAmount(max(0, $expenses)) ?></div>
        <div class="stat-label">Total Expenses</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon" style="background: var(--accent-purple-glow); color: var(--accent-purple);"><i class="ri-calculator-line"></i></div></div>
        <div class="stat-value flow-out"><?= formatAmount($carTotalCost) ?></div>
        <div class="stat-label">Total Cost</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon" style="background: <?= ($profit ?? 0) >= 0 ? 'var(--accent-green-glow)' : 'var(--accent-red-glow)' ?>; color: <?= ($profit ?? 0) >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>;"><i class="ri-line-chart-line"></i></div></div>
        <div class="stat-value" style="color: <?= ($profit ?? 0) >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>;"><?= $profit !== null ? formatAmount($profit, true) : 'In Stock' ?></div>
        <div class="stat-label"><?= $car['status'] === 'SOLD' ? 'Profit / Loss' : 'Sale Price: N/A' ?></div>
    </div>
</div>

<div class="stats-grid compact-operational-grid car-detail-pending-grid">
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($buyerOutstanding) ?></div><div class="stat-label">Sale Pending</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($sellerOutstanding) ?></div><div class="stat-label">Purchase Pending</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($rtoSpent) ?></div><div class="stat-label">RTO Spent</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($rtoRecovered) ?></div><div class="stat-label">RTO Recovered</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($tokenSummary['available']) ?></div><div class="stat-label">Token Held</div></div>
</div>

<div class="grid-2 car-detail-main-grid">
    <!-- Car Details -->
    <div class="card">
        <div class="card-header"><h3><i class="ri-car-line"></i> Car Details</h3></div>
        <div class="card-body">
            <table style="width: 100%;">
                <tr><td class="text-muted" style="padding: 8px 0; width: 40%;">Registration</td><td class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Make / Model</td><td><?= clean($car['make'] . ' ' . $car['model']) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Year</td><td><?= $car['year'] ?: '-' ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Color</td><td><?= clean($car['color'] ?: '-') ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Purchase Date</td><td><?= formatDate($car['purchase_date']) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Status</td><td>
                    <?php $sb = ['IN_STOCK'=>'badge-blue','SOLD'=>'badge-green','PENDING_PAYMENT'=>'badge-yellow','CANCELLED'=>'badge-gray']; ?>
                    <span class="badge <?= $sb[$car['status']] ?? 'badge-gray' ?>"><?= CAR_STATUS[$car['status']] ?></span>
                </td></tr>
                <?php if ($car['status'] === 'CANCELLED'): ?><tr><td class="text-muted" style="padding: 8px 0;">Correction Status</td><td>Purchase cancelled and archived for correction.</td></tr><?php endif; ?>
                <?php if ($car['sold_date']): ?><tr><td class="text-muted" style="padding: 8px 0;">Sold Date</td><td><?= formatDate($car['sold_date']) ?></td></tr><?php endif; ?>
                <?php if ($car['sale_price']): ?><tr><td class="text-muted" style="padding: 8px 0;">Sale Price</td><td class="amount flow-in"><?= formatAmount($car['sale_price']) ?></td></tr><?php endif; ?>
                <?php if (!empty($car['sale_commission_amount'])): ?><tr><td class="text-muted" style="padding: 8px 0;">Commission Income</td><td class="amount flow-in"><?= formatAmount($car['sale_commission_amount']) ?></td></tr><?php endif; ?>
                <?php if (!empty($car['sale_price']) || !empty($car['sale_commission_amount'])): ?><tr><td class="text-muted" style="padding: 8px 0;">Total Buyer Amount</td><td class="amount text-bold flow-in"><?= formatAmount((float) ($car['sale_price'] ?? 0) + (float) ($car['sale_commission_amount'] ?? 0)) ?></td></tr><?php endif; ?>
                <?php if ($car['buyer_name']): ?><tr><td class="text-muted" style="padding: 8px 0;">Buyer</td><td><?= clean($car['buyer_name']) ?></td></tr><?php endif; ?>
                <?php if ($buyerParty): ?><tr><td class="text-muted" style="padding: 8px 0;">Buyer Outstanding</td><td class="amount flow-in"><?= formatAmount($buyerOutstanding) ?></td></tr><?php endif; ?>
                <?php if ($sellerParty): ?><tr><td class="text-muted" style="padding: 8px 0;">Seller Payable</td><td class="amount flow-out"><?= formatAmount($sellerOutstanding) ?></td></tr><?php endif; ?>
                <tr><td class="text-muted" style="padding: 8px 0;">Second Key</td><td><span class="badge <?= !empty($car['has_second_key']) ? 'badge-green' : 'badge-gray' ?>"><?= !empty($car['has_second_key']) ? 'Yes' : 'No' ?></span></td></tr>
            </table>
        </div>
    </div>

    <!-- Partner Funding -->
    <div class="card" id="car-partner-terms">
        <div class="card-header">
            <div><h3><i class="ri-group-line"></i> Partner Funding</h3><div class="card-header-note">Partners, invested amount, and profit ownership for this car.</div></div>
            <div class="card-header-actions">
                <a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= clean($car['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
                <?php if ($car['status'] === 'IN_STOCK' && Auth::hasEntityAccess('car', 'write') && get('edit_funding') !== '1'): ?>
                    <a href="view.php?id=<?= clean($car['id']) ?>&amp;edit_funding=1#car-partner-terms" class="btn btn-outline btn-sm"><i class="ri-edit-line"></i> Edit Funding</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if (get('edit_funding') === '1' && $car['status'] === 'IN_STOCK' && Auth::hasEntityAccess('car', 'write')): ?>
            <?php $fundingRows = $contributions ?: [['partner_id' => '', 'amount' => '', 'profit_share_pct' => '']]; ?>
            <div class="card-body partner-funding-editor">
                <div class="correction-notice compact-correction-notice">
                    <i class="ri-shield-check-line"></i>
                    <div><strong>Posted funding total: <?= formatAmount($totalPartnerFunding) ?></strong><span>Reallocate this total between partners. Amount changes create reversing and replacement entries; profit-share-only changes update terms without changing the ledger.</span></div>
                </div>
                <form method="POST" data-confirm-submit="Save these partner funding changes? Financial reallocations will reverse the old entries and preserve them in History.">
                    <?= csrfField() ?><input type="hidden" name="action" value="correct_partner_funding">
                    <div id="partner-funding-editor-rows">
                        <?php foreach ($fundingRows as $fundingRow): ?>
                            <div class="form-row partner-row car-partner-row partner-funding-edit-row">
                                <div class="form-group partner-select-group">
                                    <label class="form-label">Partner *</label>
                                    <select name="partner_ids[]" class="form-control" data-search-placeholder="Search partners">
                                        <option value="">Select partner</option>
                                        <?php foreach ($partners as $partner): ?><option value="<?= clean($partner['id']) ?>" <?= ($fundingRow['partner_id'] ?? '') === $partner['id'] ? 'selected' : '' ?>><?= clean($partner['name']) ?> (<?= $partner['partner_type'] === 'CARWISE' ? 'Car-wise' : 'Main' ?>)</option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Funding Amount</label>
                                    <div class="input-group"><span class="input-prefix">₹</span><input type="text" name="partner_amounts[]" class="form-control currency-input" value="<?= clean($fundingRow['amount'] ?? '') ?>" inputmode="decimal" autocomplete="off"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Profit Share %</label>
                                    <input type="number" name="partner_profit_share_pcts[]" class="form-control" value="<?= clean($fundingRow['profit_share_pct'] ?? '') ?>" min="0" max="100" step="0.01">
                                </div>
                                <div class="form-group partner-row-action"><button type="button" class="btn btn-outline btn-icon" title="Remove partner" aria-label="Remove partner" onclick="removeFundingEditRow(this)"><i class="ri-delete-bin-line"></i></button></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm partner-add-row" onclick="addFundingEditRow()"><i class="ri-add-line"></i> Add Partner</button>
                    <div class="form-row partner-correction-meta">
                        <div class="form-group"><label class="form-label">Correction Date *</label><input type="date" name="correction_date" class="form-control" value="<?= clean(date('Y-m-d')) ?>" required></div>
                        <div class="form-group"><label class="form-label">Reason for Change *</label><input type="text" name="correction_reason" class="form-control" minlength="5" maxlength="500" placeholder="Why are these terms changing?" required></div>
                    </div>
                    <div class="form-actions form-actions-start"><button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Update Partner Funding</button><a href="view.php?id=<?= clean($car['id']) ?>#car-partner-terms" class="btn btn-outline">Cancel</a></div>
                </form>
            </div>
        <?php else: ?>
            <div class="card-body card-body-flush">
                <div class="table-container table-container-inline partner-funding-table">
                    <table>
                        <thead><tr><th>Partner</th><th class="text-right">Invested</th><th class="text-right">Funding %</th><th class="text-right">Profit Share %</th><th>Terms Added</th></tr></thead>
                        <tbody>
                        <?php foreach ($contributions as $c): ?>
                            <tr>
                                <td><a href="../partners/view.php?id=<?= clean($c['partner_id']) ?>" class="text-bold"><?= clean($c['partner_name']) ?></a></td>
                                <td class="text-right amount"><?= formatAmount($c['amount']) ?></td>
                                <td class="text-right"><?= formatPlainNumber($c['funding_pct']) ?>%</td>
                                <td class="text-right"><?= formatPlainNumber($c['profit_share_pct']) ?>%</td>
                                <td><?= renderDateTimeStack($c['contribution_date'], $c['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($contributions)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">Fully business-funded. No partner terms are assigned.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (get('edit_funding') === '1' && $car['status'] === 'IN_STOCK' && Auth::hasEntityAccess('car', 'write')): ?>
<script>
function resetFundingSelect(row) {
    row.querySelectorAll('.custom-select').forEach((wrapper) => {
        const select = wrapper.querySelector('select');
        if (!select) return;
        select.classList.remove('custom-select-native');
        select.removeAttribute('data-select-enhanced');
        select.removeAttribute('tabindex');
        wrapper.replaceWith(select);
    });
}
function addFundingEditRow() {
    const container = document.getElementById('partner-funding-editor-rows');
    const source = container?.querySelector('.partner-funding-edit-row');
    if (!container || !source) return;
    const row = source.cloneNode(true);
    resetFundingSelect(row);
    row.querySelectorAll('input').forEach((input) => input.value = '');
    row.querySelectorAll('select').forEach((select) => select.selectedIndex = 0);
    container.appendChild(row);
    if (typeof initCurrencyInputs === 'function') initCurrencyInputs(row);
    if (typeof enhanceSelects === 'function') enhanceSelects(row);
}
function removeFundingEditRow(button) {
    const container = document.getElementById('partner-funding-editor-rows');
    const rows = container?.querySelectorAll('.partner-funding-edit-row') || [];
    if (rows.length === 1) {
        const row = rows[0];
        const select = row.querySelector('select');
        if (select) {
            select.value = '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        row.querySelectorAll('input').forEach((input) => input.value = '');
        return;
    }
    button.closest('.partner-funding-edit-row')?.remove();
}
</script>
<?php endif; ?>

<div class="card car-images-card" style="margin-top: 24px;">
    <div class="card-header">
        <h3><i class="ri-image-add-line"></i> Car Images</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="attachment-upload-panel car-images-upload-panel">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_car_images">
            <div class="form-group">
                <label class="form-label">Image Type</label>
                <select name="image_type" class="form-control searchable-select">
                    <option value="SELLER">From Seller</option>
                    <option value="BUYER">From Buyer</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Upload Images</label>
                <input type="file" name="car_images[]" class="form-control" accept="image/*" multiple>
                <div class="form-hint">Upload RC, delivery, car condition, or party photos. Each image can be opened or shared on mobile.</div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-upload-cloud-2-line"></i> Upload</button>
        </form>

        <div class="attachment-columns car-images-columns">
            <?php foreach ([['title' => 'From Seller', 'items' => $sellerImages], ['title' => 'From Buyer', 'items' => $buyerImages]] as $group): ?>
                <div>
                    <h4 class="attachment-group-title"><?= clean($group['title']) ?></h4>
                    <?php if (empty($group['items'])): ?>
                        <div class="empty-state compact">No images uploaded.</div>
                    <?php else: ?>
                        <div class="attachment-grid">
                            <?php foreach ($group['items'] as $attachment): ?>
                                <?php $url = attachmentUrl($attachment); $shareUrl = attachmentUrl($attachment, true); ?>
                                <div class="attachment-card">
                                    <a href="<?= clean($url) ?>" target="_blank" rel="noopener">
                                        <img src="<?= clean($url) ?>" alt="<?= clean($attachment['original_name']) ?>">
                                    </a>
                                    <div class="attachment-meta">
                                        <strong><?= clean($attachment['original_name']) ?></strong>
                                        <span><?= formatDate($attachment['created_at'], 'd M Y, h:i A') ?></span>
                                    </div>
                                    <div class="attachment-actions">
                                        <a href="<?= clean($url) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i> Open</a>
                                        <button type="button" class="btn btn-sm btn-outline" data-share-url="<?= clean($shareUrl) ?>" data-share-title="<?= clean($attachment['original_name']) ?>"><i class="ri-share-forward-line"></i> Share</button>
                                        <form method="POST" onsubmit="return confirm('Delete this image?');" style="display:inline-flex;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_car_image">
                                            <input type="hidden" name="attachment_id" value="<?= clean($attachment['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline text-red"><i class="ri-delete-bin-line"></i> Delete</button>
                                        </form>
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

<div class="grid-2 car-support-grid" style="margin-top:24px;">
    <div class="card">
        <div class="card-header"><h3><i class="ri-wallet-3-line"></i> Buyer / Seller Payment History</h3></div>
        <div class="card-body">
            <h4 class="attachment-group-title">Buyer Receipts</h4>
            <?php if (empty($buyerHistory)): ?><p class="text-muted">No buyer outstanding history.</p><?php else: ?>
            <table class="table-compact"><thead><tr><th>Date / Time</th><th>Ref</th><th class="text-right">Amount</th></tr></thead><tbody>
                <?php foreach ($buyerHistory as $row): ?><tr><td><?= renderDateTimeStack($row['entry_date'], $row['created_at']) ?></td><td><a href="../transactions/view.php?id=<?= $row['id'] ?>"><?= clean($row['reference_no']) ?></a></td><td class="text-right amount <?= $row['entry_type'] === 'DR' ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($row['amount']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
            <h4 class="attachment-group-title" style="margin-top:16px;">Seller Payments</h4>
            <?php if (empty($sellerHistory)): ?><p class="text-muted">No seller payable history.</p><?php else: ?>
            <table class="table-compact"><thead><tr><th>Date / Time</th><th>Ref</th><th class="text-right">Amount</th></tr></thead><tbody>
                <?php foreach ($sellerHistory as $row): ?><tr><td><?= renderDateTimeStack($row['entry_date'], $row['created_at']) ?></td><td><a href="../transactions/view.php?id=<?= $row['id'] ?>"><?= clean($row['reference_no']) ?></a></td><td class="text-right amount <?= $row['entry_type'] === 'CR' ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($row['amount']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3><i class="ri-key-2-line"></i> Second Key</h3></div>
        <div class="card-body">
            <form method="POST" class="inline-entry-form">
                <?= csrfField() ?><input type="hidden" name="action" value="second_key_event">
                <select name="event_type" class="form-control"><option value="RECEIVED">Second Key Received</option><option value="GIVEN">Second Key Given</option></select>
                <input type="date" name="event_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                <input type="text" name="narration" class="form-control" placeholder="Narration">
                <button class="btn btn-primary btn-sm">Save</button>
            </form>
            <?php if (empty($keyEvents)): ?><p class="text-muted" style="margin-top:12px;">No key movement recorded.</p><?php else: ?>
            <table class="table-compact" style="margin-top:12px;"><thead><tr><th>Date / Time</th><th>Event</th><th>Narration</th></tr></thead><tbody>
                <?php foreach ($keyEvents as $event): ?><tr><td><?= renderDateTimeStack($event['event_date'], $event['created_at']) ?></td><td><span class="badge <?= $event['event_type'] === 'RECEIVED' ? 'badge-green' : 'badge-yellow' ?>"><?= clean($event['event_type']) ?></span></td><td><?= clean($event['narration'] ?: '-') ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:24px;">
    <div class="card-header">
        <div><h3><i class="ri-hand-coin-line"></i> Buyer Token History</h3><div class="card-header-note">Advances received for this car and how they were adjusted.</div></div>
        <?php if ($car['status'] === 'IN_STOCK'): ?><a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_TOKEN_RECEIVED', 'car_id' => $car['id'], 'narration' => 'Token received for ' . $car['registration_no']]) ?>" class="btn btn-outline btn-sm"><i class="ri-add-line"></i> Receive Token</a><?php endif; ?>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Date</th><th>Buyer</th><th>Receipt</th><th class="text-right">Received</th><th class="text-right">Adjusted</th><th class="text-right">Available</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($tokenSummary['rows'] as $token): ?>
                    <tr>
                        <td><?= formatDate($token['received_date']) ?></td>
                        <td><a href="../parties/view.php?id=<?= urlencode($token['party_id']) ?>" class="text-bold"><?= clean($token['party_name']) ?></a></td>
                        <td><a href="../transactions/view.php?id=<?= urlencode($token['journal_entry_id']) ?>"><?= clean($token['reference_no'] ?: 'View') ?></a></td>
                        <td class="text-right amount flow-in"><?= formatAmount($token['amount']) ?></td>
                        <td class="text-right amount"><?= formatAmount($token['applied_amount']) ?></td>
                        <td class="text-right amount"><?= formatAmount(max(0, floatval($token['amount']) - floatval($token['applied_amount']))) ?></td>
                        <td><span class="badge <?= $token['status'] === 'APPLIED' ? 'badge-green' : ($token['status'] === 'REVERSED' ? 'badge-red' : 'badge-blue') ?>"><?= clean($token['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tokenSummary['rows'])): ?><tr><td colspan="7" class="text-center text-muted empty-table-cell">No token received for this car.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-top:24px;">
    <div class="card-header"><h3><i class="ri-file-shield-2-line"></i> RTO Money History</h3><a href="../rto/list.php?car_id=<?= clean($car['id']) ?>" class="btn btn-sm btn-outline">Open RTO Book</a></div>
    <div class="card-body" style="padding:0;">
        <table><thead><tr><th>Work</th><th>Buyer / Agent</th><th>Money Type</th><th class="text-right">Received</th><th class="text-right">Spent</th></tr></thead><tbody>
            <?php if (empty($rtoHistory)): ?><tr><td colspan="5" class="text-center text-muted" style="padding:24px;">No RTO money history for this car.</td></tr><?php else: ?>
            <?php foreach ($rtoHistory as $rto): ?><tr>
                <td><?= clean($rto['rto_type']) ?></td><td><?= clean($rto['party_name'] ?: '-') ?><div class="text-muted"><?= clean($rto['agent_name'] ?: '-') ?></div></td><td><span class="badge <?= ($rto['money_type'] === 'RECEIVE') ? 'badge-green' : 'badge-red' ?>"><?= $rto['money_type'] === 'RECEIVE' ? 'Money In' : 'Money Out' ?></span></td><td class="text-right amount flow-in"><?= formatAmount($rto['received_amount']) ?></td><td class="text-right amount flow-out"><?= formatAmount($rto['spent_amount']) ?></td>
            </tr><?php endforeach; ?><?php endif; ?>
        </tbody></table>
    </div>
</div>

<?php if (in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true)): ?>
<div class="card" style="margin-top:24px;">
    <div class="card-header"><h3><i class="ri-arrow-go-back-line"></i> Return Car</h3></div>
    <div class="card-body">
        <form method="POST" class="inline-entry-form" onsubmit="return confirm('Return this car and reverse sale entry?');">
            <?= csrfField() ?><input type="hidden" name="action" value="return_car">
            <input type="text" name="return_reason" class="form-control" placeholder="Reason for return" required>
            <button class="btn btn-outline btn-sm"><i class="ri-arrow-go-back-line"></i> Return Car</button>
        </form>
        <div class="form-hint">If later buyer receipts exist, system blocks return until those entries are reversed first.</div>
    </div>
</div>
<?php endif; ?>

<!-- Car Timeline -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header"><h3><i class="ri-book-2-line"></i> Car Timeline</h3></div>
    <div class="card-body" style="padding: 0;">
        <table>
            <thead><tr><th>Date / Time</th><th>Ref</th><th>Type</th><th>Narration</th><th class="text-right debit-amount">Money In / Debit</th><th class="text-right credit-amount">Money Out / Credit</th></tr></thead>
            <tbody>
                <?php if (empty($ledger)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding: 30px;">No ledger entries</td></tr>
                <?php else: ?>
                    <?php foreach ($ledger as $l):
                        $displayDebit = '';
                        $displayCredit = '';
                        if (!empty($l['car_line_type'])) {
                            if ($l['car_line_type'] === 'DR') {
                                $displayDebit = formatAmount($l['car_line_amount']);
                            } else {
                                $displayCredit = formatAmount($l['car_line_amount']);
                            }
                        } elseif (in_array($l['transaction_type'], ['LOAN_RECEIVED', 'RTO_RECOVERY'], true) && (float) $l['cash_in_amount'] > 0) {
                            $displayDebit = formatAmount($l['cash_in_amount']);
                        } elseif (in_array($l['transaction_type'], ['LOAN_REPAID'], true) && (float) $l['cash_out_amount'] > 0) {
                            $displayCredit = formatAmount($l['cash_out_amount']);
                        } elseif ((float) $l['cash_in_amount'] > 0) {
                            $displayDebit = formatAmount($l['cash_in_amount']);
                        } elseif ((float) $l['cash_out_amount'] > 0) {
                            $displayCredit = formatAmount($l['cash_out_amount']);
                        }
                    ?>
                    <tr>
                        <td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td>
                        <td><a href="../transactions/view.php?id=<?= $l['entry_id'] ?>"><?= $l['reference_no'] ?></a></td>
                        <td><span class="badge badge-blue" style="font-size: 10px;"><?= clean(transactionTypeLabel($l['transaction_type'], $l)) ?></span></td>
                        <td><?= clean(mb_substr($l['narration'] ?? '', 0, 50)) ?></td>
                        <td class="text-right amount debit-amount"><?= $displayDebit ?></td>
                        <td class="text-right amount credit-amount"><?= $displayCredit ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
