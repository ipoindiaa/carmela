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
if (($car['ownership_type'] ?? 'OWNED') === 'COMMISSION') { redirect('commission_view.php?id=' . urlencode($id)); }
if (($car['ownership_type'] ?? 'OWNED') === 'OUTSIDE') { redirect('../outside-cars/view.php?id=' . urlencode($id)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    Auth::requireEntityAccess('car', $action === 'delete_car_image' ? 'delete' : 'write');
    verifyCsrf();
    try {
        if ($car['status'] === 'CANCELLED') throw new Exception('Deleted cars are read-only. Their history remains available.');
        if ($action === 'update_details') {
            $year = intval(post('year')) ?: null;
            if ($year && ($year < 1900 || $year > intval(date('Y')) + 1)) throw new Exception('Enter a valid vehicle year.');
            $expectedSalePrice = parseDecimalInput(post('expected_sale_price', '0'));
            $oldDetails = array_intersect_key($car, array_flip(['make', 'model', 'year', 'color', 'has_second_key', 'expected_sale_price', 'notes']));
            $db->query(
                "UPDATE cars SET make = ?, model = ?, year = ?, color = ?, has_second_key = ?, expected_sale_price = ?, partner_id = NULL, notes = ? WHERE id = ? AND business_id = ?",
                [post('make'), post('model'), $year, post('color'), post('has_second_key') === '1' ? 1 : 0, $expectedSalePrice, post('notes'), $id, $businessId]
            );
            $updatedCar = $db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$id, $businessId]);
            $newDetails = array_intersect_key($updatedCar ?: [], array_flip(['make', 'model', 'year', 'color', 'has_second_key', 'expected_sale_price', 'notes']));
            Auth::auditUpdate('car', $id, $oldDetails, $newDetails, 'Car details updated', 'cars');
            setFlash('success', 'Car details updated.');
        } elseif ($action === 'correct_partner_funding') {
            $partnerIds = array_values((array) ($_POST['partner_ids'] ?? []));
            $amounts = array_values((array) ($_POST['partner_amounts'] ?? []));
            $shares = array_values((array) ($_POST['partner_profit_share_pcts'] ?? []));
            $_SESSION['car_partner_funding_draft'][$id] = [
                'partner_ids' => $partnerIds,
                'amounts' => $amounts,
                'shares' => $shares,
                'correction_date' => post('correction_date'),
                'correction_reason' => post('correction_reason'),
            ];
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
            unset($_SESSION['car_partner_funding_draft'][$id]);
            setFlash('success', 'Partner funding updated. Financial corrections and field changes are preserved in History.');
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
        } elseif ($action === 'forfeit_token') {
            $engine->forfeitCarToken(post('token_id'), post('forfeit_date'), post('forfeit_reason'));
            setFlash('success', 'Token forfeited. It is now profit of this car.');
        } elseif ($action === 'refund_token') {
            $engine->refundCarToken(post('token_id'), post('refund_amount'), post('refund_date'), post('payment_account'), post('refund_reason'));
            setFlash('success', 'Token refunded.');
        }
        redirect($action === 'correct_partner_funding' ? "view.php?id=$id&edit_funding=1#car-partner-terms" : "view.php?id=$id");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        redirect($action === 'correct_partner_funding' ? "view.php?id=$id&edit_funding=1#car-partner-terms" : "view.php?id=$id");
    }
}

$profitability = $engine->getCarProfitability($id);
$profit = $profitability['status'] === 'SOLD' ? $profitability['profit'] : null;
$expenses = $profitability['total_expenses'];
$carTotalCost = $profitability['total_cost'] ?? $car['purchase_price'];
$partnerships = $profitability['partnerships'];
$partners = $db->fetchAll("SELECT id, name, partner_type FROM partners WHERE business_id = ? AND is_active = 1 ORDER BY name", [$businessId]);
$paymentAccounts = $db->fetchAll("SELECT id, name, code FROM accounts WHERE business_id = ? AND entity_type IN ('CASH','BANK') AND entity_id IS NULL AND is_active = 1 ORDER BY FIELD(entity_type, 'CASH', 'BANK'), name", [$businessId]);
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
     WHERE je.business_id = ? AND je.status IN ('POSTED','REVERSED') AND je.car_id = ?
     ORDER BY je.entry_date DESC, je.created_at DESC LIMIT 12",
    [$buyerParty['account_id'], $businessId, $id]
) : [];
$sellerHistory = $sellerParty ? $db->fetchAll(
    "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.transaction_type, je.narration, jl.amount, jl.entry_type,
            payment_account.name AS payment_account_name, payment_account.code AS payment_account_code
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.account_id = ?
     LEFT JOIN journal_lines payment_line ON payment_line.journal_entry_id = je.id
         AND payment_line.entry_type = 'CR'
         AND payment_line.account_id IN (
             SELECT id FROM accounts WHERE business_id = ? AND entity_type IN ('CASH', 'BANK')
         )
     LEFT JOIN accounts payment_account ON payment_account.id = payment_line.account_id
     WHERE je.business_id = ? AND je.status IN ('POSTED','REVERSED') AND je.car_id = ?
     ORDER BY je.entry_date DESC, je.created_at DESC",
    [$sellerParty['account_id'], $businessId, $businessId, $id]
) : [];
$sellerPaymentTotalRow = $sellerParty ? $db->fetch(
    "SELECT COALESCE(SUM(jl.amount), 0) AS payment_total
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     WHERE je.business_id = ? AND je.status = 'POSTED' AND je.car_id = ?
       AND jl.account_id = ? AND jl.entry_type = 'DR'",
    [$businessId, $id, $sellerParty['account_id']]
) : null;
$sellerPaymentsTotal = (float) ($sellerPaymentTotalRow['payment_total'] ?? 0);
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

$carSummaryValue = 'In Stock';
$carSummaryLabel = 'Sale Price: N/A';
$carSummaryClass = 'green';
if ($car['status'] === 'SOLD') {
    $carSummaryValue = formatAmount($profit ?? 0, true);
    $carSummaryLabel = 'Profit / Loss';
    $carSummaryClass = ($profit ?? 0) >= 0 ? 'green' : 'red';
} elseif ($car['status'] === 'PENDING_PAYMENT') {
    $carSummaryValue = formatAmount((float) ($car['sale_price'] ?? 0));
    $carSummaryLabel = 'Sale Agreed - Payment Pending';
    $carSummaryClass = 'amber';
} elseif ($car['status'] === 'CANCELLED') {
    $carSummaryValue = 'Cancelled';
    $carSummaryLabel = 'Purchase Reversed';
    $carSummaryClass = 'neutral';
}

// Includes direct car entries and the car's exact allocation from multi-account bills.
$ledger = $engine->getCarTimeline($id);

// Current per-car partner terms, including partners with profit share but no cash contribution.
$contributions = $db->fetchAll(
    "SELECT cp.*, cp.funding_amount AS amount, c.purchase_date AS contribution_date, p.name AS partner_name
     FROM car_partnerships cp
     JOIN partners p ON p.id = cp.partner_id
     JOIN cars c ON c.id = cp.car_id
     WHERE cp.business_id = ? AND cp.car_id = ? AND cp.status = 'ACTIVE'
     ORDER BY cp.created_at", [$businessId, $id]);
$totalPartnerFunding = round(array_sum(array_map(static fn($row) => floatval($row['amount']), $contributions)), 2);
$partnerFundingDraft = $_SESSION['car_partner_funding_draft'][$id] ?? null;
unset($_SESSION['car_partner_funding_draft'][$id]);
?>

<div class="page-header">
    <h1><i class="ri-car-line"></i> <?= clean(formatRegistrationNo($car['registration_no'])) ?></h1>
    <div class="page-actions car-detail-actions">
        <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?><a href="view.php?id=<?= $car['id'] ?>&amp;edit=1" class="btn btn-outline btn-sm"><i class="ri-edit-line"></i> Edit</a><?php endif; ?>
        <a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= $car['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
        <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'delete')): ?><a href="../delete_record.php?entity_type=car&amp;id=<?= clean($car['id']) ?>" class="btn btn-danger btn-sm"><i class="ri-delete-bin-line"></i> Delete</a><?php endif; ?>
        <?php if ($buyerOutstanding > 0 && !empty($carPending['buyer_party_id'])): ?>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'LOAN_RECEIVED', 'party_id' => $carPending['buyer_party_id'], 'car_id' => $car['id'], 'amount' => round($buyerOutstanding), 'narration' => 'Car payment clearing - ' . $car['registration_no']]) ?>" class="btn btn-success btn-sm"><i class="ri-arrow-down-circle-line"></i> Receive Pending</a>
        <?php endif; ?>
        <?php if ($sellerParty): ?>
            <a href="purchase_payment.php?id=<?= clean($car['id']) ?>" class="btn <?= $sellerOutstanding > 0.009 ? 'btn-primary' : 'btn-outline' ?> btn-sm"><i class="ri-hand-coin-line"></i> Purchase Payments<?= $sellerOutstanding > 0.009 ? ' · ' . formatAmount($sellerOutstanding) : '' ?></a>
        <?php endif; ?>
        <?php if ($car['status'] === 'IN_STOCK'): ?>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_TOKEN_RECEIVED', 'car_id' => $car['id'], 'narration' => 'Token received for ' . $car['registration_no']]) ?>" class="btn btn-outline btn-sm"><i class="ri-hand-coin-line"></i> Receive Token</a>
            <a href="../transactions/new.php?type=CAR_EXPENSE&car_id=<?= $car['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-tools-line"></i> Add Expense</a>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_SALE', 'car_id' => $car['id']]) ?>" class="btn btn-success btn-sm"><i class="ri-money-rupee-circle-line"></i> Sell Car</a>
        <?php endif; ?>
        <?php if (in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true)): ?><a href="#payment-history" class="btn btn-outline btn-sm"><i class="ri-wallet-3-line"></i> Payment History</a><?php endif; ?>
        <?php if (!empty($car['buyer_party_id'])): ?><a href="loan_commission.php?car_id=<?= urlencode($car['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-bank-card-line"></i> Loan Commission</a><?php endif; ?>
        <a href="../rto/list.php?car_id=<?= clean($car['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-file-shield-2-line"></i> RTO</a>
        <a href="list.php<?= $car['status'] === 'CANCELLED' ? '?status=CANCELLED' : '' ?>" class="btn btn-outline btn-sm"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<?php if (get('edit') === '1' && $car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?>
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
            <div class="form-row">
                <div class="form-group"><label class="form-label">Expected Selling Value (₹)</label><input type="text" name="expected_sale_price" class="form-control currency-input" value="<?= clean($car['expected_sale_price'] ?? 0) ?>"><div class="form-hint">Get a warning alert if this car sells below this value.</div></div>
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
        <div class="stat-header"><div class="stat-icon stat-icon-blue"><i class="ri-shopping-cart-line"></i></div></div>
        <div class="stat-value flow-out"><?= formatAmount($car['purchase_price']) ?></div>
        <div class="stat-label">Purchase Price</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon stat-icon-amber"><i class="ri-tools-line"></i></div></div>
        <div class="stat-value flow-out"><?= formatAmount(max(0, $expenses)) ?></div>
        <div class="stat-label">Total Expenses</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon stat-icon-purple"><i class="ri-calculator-line"></i></div></div>
        <div class="stat-value flow-out"><?= formatAmount($carTotalCost) ?></div>
        <div class="stat-label">Total Cost</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon stat-icon-<?= clean($carSummaryClass) ?>"><i class="ri-line-chart-line"></i></div></div>
        <div class="stat-value text-<?= clean($carSummaryClass) ?>"><?= $carSummaryValue ?></div>
        <div class="stat-label"><?= $carSummaryLabel ?></div>
    </div>
</div>

<div class="stats-grid compact-operational-grid car-detail-pending-grid">
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($buyerOutstanding) ?></div><div class="stat-label">Sale Pending</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($sellerOutstanding) ?></div><div class="stat-label">Purchase Pending</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($rtoSpent) ?></div><div class="stat-label">RTO Spent</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($rtoRecovered) ?></div><div class="stat-label">RTO Recovered</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($profitability['loan_commission_income'] ?? 0) ?></div><div class="stat-label">Loan Commission Income</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($tokenSummary['available']) ?></div><div class="stat-label">Token Held</div></div>
</div>

<div class="grid-2 car-detail-main-grid">
    <!-- Car Details -->
    <div class="card">
        <div class="card-header"><h3><i class="ri-car-line"></i> Car Details</h3></div>
        <div class="card-body">
            <div class="table-container table-container-inline table-columns-compact">
            <table class="detail-table">
                <tr><td class="text-muted">Registration</td><td class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></td></tr>
                <tr><td class="text-muted">Make / Model</td><td><?= clean($car['make'] . ' ' . $car['model']) ?></td></tr>
                <tr><td class="text-muted">Year</td><td><?= $car['year'] ?: '-' ?></td></tr>
                <tr><td class="text-muted">Color</td><td><?= clean($car['color'] ?: '-') ?></td></tr>
                <tr><td class="text-muted">Purchase Date</td><td><?= formatDate($car['purchase_date']) ?></td></tr>
                <tr><td class="text-muted">Status</td><td>
                    <?php $sb = ['IN_STOCK'=>'badge-blue','SOLD'=>'badge-green','PENDING_PAYMENT'=>'badge-yellow','CANCELLED'=>'badge-gray']; ?>
                    <span class="badge <?= $sb[$car['status']] ?? 'badge-gray' ?>"><?= CAR_STATUS[$car['status']] ?></span>
                </td></tr>
                <?php if ($car['status'] === 'CANCELLED'): ?><tr><td class="text-muted">Correction Status</td><td>Purchase cancelled and archived for correction.</td></tr><?php endif; ?>
                <?php if ($car['sold_date']): ?><tr><td class="text-muted">Sold Date</td><td><?= formatDate($car['sold_date']) ?></td></tr><?php endif; ?>
                <?php if ($car['sale_price']): ?><tr><td class="text-muted">Sale Price</td><td class="amount flow-in"><?= formatAmount($car['sale_price']) ?><?php if (!empty($car['expected_sale_price']) && $car['sale_price'] < $car['expected_sale_price']): ?> <span class="badge badge-yellow">Sold below expected</span><?php endif; ?></td></tr><?php endif; ?>
                <?php if (!empty($car['expected_sale_price'])): ?><tr><td class="text-muted">Expected Sale Value</td><td class="amount"><?= formatAmount($car['expected_sale_price']) ?></td></tr><?php endif; ?>
                <?php if (!empty($car['sale_commission_amount'])): ?><tr><td class="text-muted">Commission Income</td><td class="amount flow-in"><?= formatAmount($car['sale_commission_amount']) ?></td></tr><?php endif; ?>
                <?php if (!empty($car['sale_price']) || !empty($car['sale_commission_amount'])): ?><tr><td class="text-muted">Total Buyer Amount</td><td class="amount text-bold flow-in"><?= formatAmount((float) ($car['sale_price'] ?? 0) + (float) ($car['sale_commission_amount'] ?? 0)) ?></td></tr><?php endif; ?>
                <?php if ($car['buyer_name']): ?><tr><td class="text-muted">Buyer</td><td><?= clean($car['buyer_name']) ?></td></tr><?php endif; ?>
                <?php if ($buyerParty): ?><tr><td class="text-muted">Buyer Outstanding</td><td class="amount flow-in"><?= formatAmount($buyerOutstanding) ?></td></tr><?php endif; ?>
                <?php if ($sellerParty): ?><tr><td class="text-muted">Seller (Source)</td><td><a href="../parties/view.php?id=<?= urlencode($sellerParty['id']) ?>" class="text-bold"><?= clean($sellerParty['name']) ?></a><?php if ($sellerOutstanding > 0.009): ?> <span class="text-muted">· Purchase balance <?= formatAmount($sellerOutstanding) ?></span><?php endif; ?> <a href="purchase_payment.php?id=<?= clean($car['id']) ?>" class="btn btn-outline btn-sm">Purchase Payments</a></td></tr><?php endif; ?>
                <tr><td class="text-muted">Second Key</td><td><span class="badge <?= !empty($car['has_second_key']) ? 'badge-green' : 'badge-gray' ?>"><?= !empty($car['has_second_key']) ? 'Yes' : 'No' ?></span></td></tr>
            </table>
            </div>
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
            <?php
                $fundingRows = $contributions ?: [['partner_id' => '', 'amount' => '', 'profit_share_pct' => '']];
                if (is_array($partnerFundingDraft)) {
                    $draftRowCount = max(count($partnerFundingDraft['partner_ids'] ?? []), count($partnerFundingDraft['amounts'] ?? []), count($partnerFundingDraft['shares'] ?? []));
                    $fundingRows = [];
                    for ($draftIndex = 0; $draftIndex < $draftRowCount; $draftIndex++) {
                        $fundingRows[] = [
                            'partner_id' => $partnerFundingDraft['partner_ids'][$draftIndex] ?? '',
                            'amount' => $partnerFundingDraft['amounts'][$draftIndex] ?? '',
                            'profit_share_pct' => $partnerFundingDraft['shares'][$draftIndex] ?? '',
                        ];
                    }
                    if (empty($fundingRows)) $fundingRows = [['partner_id' => '', 'amount' => '', 'profit_share_pct' => '']];
                }
            ?>
            <div class="card-body partner-funding-editor">
                <div class="correction-notice compact-correction-notice">
                    <i class="ri-shield-check-line"></i>
                    <div><strong>Fixed funding total: <?= formatAmount($totalPartnerFunding) ?></strong><span>Reallocate exactly this amount between any active partners. Separate capital changes belong in Partner Added Money or Partner Took Money.</span></div>
                </div>
                <form method="POST" id="partner-funding-correction-form" data-fixed-total="<?= clean(number_format($totalPartnerFunding, 2, '.', '')) ?>" data-confirm-submit="Save these partner funding changes? Financial reallocations will reverse the old entries and preserve them in History.">
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
                    <div class="form-hint field-status" id="partner-funding-total-status" aria-live="polite"></div>
                    <div class="form-row partner-correction-meta">
                        <div class="form-group"><label class="form-label">Correction Date *</label><input type="date" name="correction_date" class="form-control" value="<?= clean($partnerFundingDraft['correction_date'] ?? date('Y-m-d')) ?>" required></div>
                        <div class="form-group"><label class="form-label">Reason for Change *</label><input type="text" name="correction_reason" class="form-control" value="<?= clean($partnerFundingDraft['correction_reason'] ?? '') ?>" minlength="5" maxlength="500" placeholder="Why are these terms changing?" required></div>
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
    updateFundingEditTotal();
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
        updateFundingEditTotal();
        return;
    }
    button.closest('.partner-funding-edit-row')?.remove();
    updateFundingEditTotal();
}

function parseFundingEditAmount(value) {
    const normalized = String(value || '').replace(/[^0-9.-]/g, '');
    const amount = Number(normalized);
    return Number.isFinite(amount) ? amount : 0;
}
function formatFundingEditAmount(value) {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(value);
}
function updateFundingEditTotal() {
    const form = document.getElementById('partner-funding-correction-form');
    const status = document.getElementById('partner-funding-total-status');
    if (!form || !status) return true;
    const fixedTotal = Number(form.dataset.fixedTotal || 0);
    const allocated = Array.from(form.querySelectorAll('input[name="partner_amounts[]"]'))
        .reduce((sum, input) => sum + parseFundingEditAmount(input.value), 0);
    const difference = Math.round((fixedTotal - allocated) * 100) / 100;
    if (Math.abs(difference) < 0.01) {
        status.className = 'form-hint text-green';
        status.textContent = `Allocated ${formatFundingEditAmount(allocated)} of ${formatFundingEditAmount(fixedTotal)}. Ready to save.`;
        return true;
    }
    status.className = 'form-hint text-red';
    status.textContent = difference > 0
        ? `${formatFundingEditAmount(difference)} remains to allocate. Total must stay ${formatFundingEditAmount(fixedTotal)}.`
        : `${formatFundingEditAmount(Math.abs(difference))} is over the fixed total. Total must stay ${formatFundingEditAmount(fixedTotal)}.`;
    return false;
}
document.getElementById('partner-funding-editor-rows')?.addEventListener('input', function(event) {
    if (event.target.matches('input[name="partner_amounts[]"]')) updateFundingEditTotal();
});
document.getElementById('partner-funding-correction-form')?.addEventListener('submit', function(event) {
    if (!updateFundingEditTotal()) {
        event.preventDefault();
        document.getElementById('partner-funding-total-status')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
updateFundingEditTotal();
</script>
<?php endif; ?>

<div class="card car-images-card">
    <div class="card-header">
        <h3><i class="ri-attachment-2"></i> Car Files</h3>
    </div>
    <div class="card-body">
        <?php if (Auth::hasEntityAccess('car', 'write')): ?>
        <form method="POST" enctype="multipart/form-data" class="attachment-upload-panel car-images-upload-panel">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_car_images">
            <div class="form-group">
                <label class="form-label">File Source</label>
                <select name="image_type" class="form-control searchable-select">
                    <option value="SELLER">From Seller</option>
                    <option value="BUYER">From Buyer</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Upload Files</label>
                <input type="file" name="car_images[]" class="form-control" accept="<?= clean(attachmentAcceptAttribute('documents')) ?>" multiple>
                <div class="form-hint">Photos, RC, delivery proofs, PDF, Office documents, text/CSV, or archives. Maximum 10 MB each.</div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-upload-cloud-2-line"></i> Upload</button>
        </form>
        <?php endif; ?>

        <div class="attachment-columns car-images-columns">
            <?php foreach ([['title' => 'From Seller', 'items' => $sellerImages], ['title' => 'From Buyer', 'items' => $buyerImages]] as $group): ?>
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
                                        <button type="button" class="btn btn-sm btn-outline" data-share-url="<?= clean($shareUrl) ?>" data-share-title="<?= clean($attachment['original_name']) ?>"><i class="ri-share-forward-line"></i> Share</button>
                                        <?php if (Auth::hasEntityAccess('car', 'delete')): ?><form method="POST" data-confirm-submit="Delete this file? The deletion will be recorded in History." class="inline-form">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_car_image">
                                            <input type="hidden" name="attachment_id" value="<?= clean($attachment['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline text-red"><i class="ri-delete-bin-line"></i> Delete</button>
                                        </form><?php endif; ?>
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

<div class="grid-2 car-support-grid">
    <?php if (in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true) || $sellerParty): ?>
    <div class="card" id="payment-history">
        <div class="card-header"><h3><i class="ri-wallet-3-line"></i> Payment History</h3></div>
        <div class="card-body">
            <?php if (in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true)): ?>
            <h4 class="attachment-group-title">Buyer Receivable &amp; Collection History</h4>
            <?php if (empty($buyerHistory)): ?><p class="text-muted">No buyer outstanding history.</p><?php else: ?>
            <table class="table-compact"><thead><tr><th>Date / Time</th><th>Ref</th><th>Event</th><th class="text-right">Amount</th></tr></thead><tbody>
                <?php foreach ($buyerHistory as $row): ?><?php $buyerEvent = $row['transaction_type'] === 'CAR_SALE' && $row['entry_type'] === 'DR' ? 'Buyer receivable created' : ($row['transaction_type'] === 'LOAN_RECEIVED' && $row['entry_type'] === 'CR' ? 'Cash / bank collection' : 'Buyer ledger movement'); ?><tr><td><?= renderDateTimeStack($row['entry_date'], $row['created_at']) ?></td><td><a href="../transactions/view.php?id=<?= $row['id'] ?>"><?= clean($row['reference_no']) ?></a></td><td><?= clean($buyerEvent) ?></td><td class="text-right amount <?= $row['entry_type'] === 'DR' ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($row['amount']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
            <?php endif; ?>
            <div class="detail-subsection">
                <h4 class="attachment-group-title">Seller Payable &amp; Payment History</h4>
                <div class="form-hint">Paid to seller: <strong class="amount flow-in"><?= formatAmount($sellerPaymentsTotal) ?></strong> &nbsp;•&nbsp; Purchase balance pending: <strong class="amount <?= $sellerOutstanding > 0.009 ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($sellerOutstanding) ?></strong></div>
            </div>
            <?php if (empty($sellerHistory)): ?><p class="text-muted">No seller payable history.</p><?php else: ?>
            <table class="table-compact"><thead><tr><th>Date / Time</th><th>Ref</th><th>Event</th><th>Paid From</th><th class="text-right">Amount</th></tr></thead><tbody>
                <?php foreach ($sellerHistory as $row): ?><?php
                    $isSellerPayment = $row['entry_type'] === 'DR';
                    $sellerEvent = $row['transaction_type'] === 'CAR_PURCHASE' && $row['entry_type'] === 'CR' ? 'Seller payable created' : ($isSellerPayment ? 'Cash / bank payment to seller' : 'Seller ledger movement');
                    $paymentSource = $isSellerPayment && !empty($row['payment_account_name']) ? trim($row['payment_account_name'] . (!empty($row['payment_account_code']) ? ' (' . $row['payment_account_code'] . ')' : '')) : '—';
                ?><tr><td><?= renderDateTimeStack($row['entry_date'], $row['created_at']) ?></td><td><a href="../transactions/view.php?id=<?= $row['id'] ?>"><?= clean($row['reference_no']) ?></a></td><td><?= clean($sellerEvent) ?></td><td><?= clean($paymentSource) ?></td><td class="text-right amount <?= $row['entry_type'] === 'CR' ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($row['amount']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-header"><h3><i class="ri-key-2-line"></i> Second Key</h3></div>
        <div class="card-body">
            <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?><form method="POST" class="inline-entry-form">
                <?= csrfField() ?><input type="hidden" name="action" value="second_key_event">
                <select name="event_type" class="form-control"><option value="RECEIVED">Second Key Received</option><option value="GIVEN">Second Key Given</option></select>
                <input type="date" name="event_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                <input type="text" name="narration" class="form-control" placeholder="Narration">
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
            </form><?php endif; ?>
            <?php if (empty($keyEvents)): ?><p class="text-muted detail-subsection">No key movement recorded.</p><?php else: ?>
            <table class="table-compact detail-subsection"><thead><tr><th>Date / Time</th><th>Event</th><th>Narration</th><th class="text-center">Action</th></tr></thead><tbody>
                <?php foreach ($keyEvents as $event): ?><tr><td><?= renderDateTimeStack($event['event_date'], $event['created_at']) ?></td><td><span class="badge <?= $event['event_type'] === 'RECEIVED' ? 'badge-green' : 'badge-yellow' ?>"><?= clean($event['event_type']) ?></span></td><td><?= clean($event['narration'] ?: '-') ?></td><td class="text-center"><?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'delete')): ?><a href="../delete_record.php?entity_type=second_key_event&amp;id=<?= clean($event['id']) ?>" class="btn btn-sm btn-outline text-red" title="Delete second key event"><i class="ri-delete-bin-line"></i></a><?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div><h3><i class="ri-hand-coin-line"></i> Buyer Token History</h3><div class="card-header-note">Advances received for this car and how they were adjusted.</div></div>
        <?php if ($car['status'] === 'IN_STOCK'): ?><a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_TOKEN_RECEIVED', 'car_id' => $car['id'], 'narration' => 'Token received for ' . $car['registration_no']]) ?>" class="btn btn-outline btn-sm"><i class="ri-add-line"></i> Receive Token</a><?php endif; ?>
    </div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Date</th><th>Buyer</th><th>Receipt</th><th class="text-right">Received</th><th class="text-right">Adjusted</th><th class="text-right">Available</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($tokenSummary['rows'] as $token): ?>
                    <?php $tokenStatus = strtoupper((string) $token['status']); ?>
                    <tr>
                        <td><?= formatDate($token['received_date']) ?></td>
                        <td><a href="../parties/view.php?id=<?= urlencode($token['party_id']) ?>" class="text-bold"><?= clean($token['party_name']) ?></a></td>
                        <td><a href="../transactions/view.php?id=<?= urlencode($token['journal_entry_id']) ?>"><?= clean($token['reference_no'] ?: 'View') ?></a></td>
                        <td class="text-right amount flow-in"><?= formatAmount($token['amount']) ?></td>
                        <td class="text-right amount"><?= formatAmount($token['applied_amount']) ?></td>
                        <td class="text-right amount"><?= formatAmount(max(0, floatval($token['amount']) - floatval($token['applied_amount']))) ?></td>
                        <td><span class="badge <?= $tokenStatus === 'APPLIED' ? 'badge-green' : ($tokenStatus === 'FORFEITED' ? 'badge-yellow' : ($tokenStatus === 'REFUNDED' || $tokenStatus === 'REVERSED' ? 'badge-red' : 'badge-blue')) ?>"><?= clean($token['status']) ?></span></td>
                        <td class="text-nowrap">
                            <?php if (in_array($tokenStatus, ['OPEN', 'PARTIAL'], true) && (floatval($token['amount']) - floatval($token['applied_amount']) > 0.009)): ?>
                                <form method="POST" class="inline-form token-action-form" data-confirm-submit="Forfeit this token? It will become profit of this car. A buyer who later returns can still get it refunded here.">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="forfeit_token">
                                    <input type="hidden" name="token_id" value="<?= clean($token['id']) ?>">
                                    <input type="date" name="forfeit_date" value="<?= date('Y-m-d') ?>" required class="form-control token-action-date">
                                    <input type="text" name="forfeit_reason" placeholder="Reason (who, why)" required class="form-control token-action-reason">
                                    <button class="btn btn-outline btn-sm" type="submit"><i class="ri-hand-coin-line"></i> Forfeit → Profit</button>
                                </form>
                            <?php elseif ($tokenStatus === 'FORFEITED'): ?>
                                <form method="POST" class="inline-form token-action-form" data-confirm-submit="Refund this forfeited token? This reduces this car's profit.">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="refund_token">
                                    <input type="hidden" name="token_id" value="<?= clean($token['id']) ?>">
                                    <input type="hidden" name="refund_amount" value="<?= clean($token['amount']) ?>">
                                    <select name="payment_account" class="form-control token-action-account" required>
                                        <?php foreach ($paymentAccounts as $pa): ?><option value="<?= clean($pa['id']) ?>"><?= clean($pa['name']) ?></option><?php endforeach; ?>
                                    </select>
                                    <input type="date" name="refund_date" value="<?= date('Y-m-d') ?>" required class="form-control token-action-date">
                                    <input type="text" name="refund_reason" placeholder="Reason (who, why)" required class="form-control token-action-reason">
                                    <button class="btn btn-outline btn-sm" type="submit"><i class="ri-refund-2-line"></i> Refund</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tokenSummary['rows'])): ?><tr><td colspan="8" class="text-center text-muted empty-table-cell">No token received for this car.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-file-shield-2-line"></i> RTO Money History</h3><a href="../rto/list.php?car_id=<?= clean($car['id']) ?>" class="btn btn-sm btn-outline">Open RTO Book</a></div>
    <div class="card-body card-body-flush">
        <table><thead><tr><th>RTO Narration</th><th>Buyer / Agent</th><th>Money Type</th><th class="text-right">Received</th><th class="text-right">Spent</th></tr></thead><tbody>
            <?php if (empty($rtoHistory)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No RTO money history for this car.</td></tr><?php else: ?>
            <?php foreach ($rtoHistory as $rto): ?><tr>
                <td><?= clean($rto['rto_type']) ?></td><td><?= clean($rto['party_name'] ?: '-') ?><div class="text-muted"><?= clean($rto['agent_name'] ?: '-') ?></div></td><td><span class="badge <?= ($rto['money_type'] === 'RECEIVE') ? 'badge-green' : 'badge-red' ?>"><?= $rto['money_type'] === 'RECEIVE' ? 'Money In' : 'Money Out' ?></span></td><td class="text-right amount flow-in"><?= formatAmount($rto['received_amount']) ?></td><td class="text-right amount flow-out"><?= formatAmount($rto['spent_amount']) ?></td>
            </tr><?php endforeach; ?><?php endif; ?>
        </tbody></table>
    </div>
</div>

<?php if (in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true)): ?>
<div class="card">
    <div class="card-header"><h3><i class="ri-arrow-go-back-line"></i> Return Car</h3></div>
    <div class="card-body">
        <form method="POST" class="inline-entry-form" onsubmit="return confirm('Return this car and reverse sale entry?');">
            <?= csrfField() ?><input type="hidden" name="action" value="return_car">
            <input type="text" name="return_reason" class="form-control" placeholder="Reason for return" required>
            <button type="submit" class="btn btn-outline btn-sm"><i class="ri-arrow-go-back-line"></i> Return Car</button>
        </form>
        <div class="form-hint">If later buyer receipts exist, system blocks return until those entries are reversed first.</div>
    </div>
</div>
<?php endif; ?>

<!-- Car Timeline -->
<div class="card">
    <div class="card-header"><h3><i class="ri-book-2-line"></i> Car Timeline</h3></div>
    <div class="card-body card-body-flush">
        <div class="table-container">
        <table>
            <thead><tr><th>Date / Time</th><th>Ref</th><th>Type</th><th>Source / Narration</th><th>Status</th><th class="text-right flow-in">Money In</th><th class="text-right flow-out">Money Out</th></tr></thead>
            <tbody>
                <?php if (empty($ledger)): ?>
                    <tr><td colspan="7" class="text-center text-muted empty-table-cell">No ledger entries</td></tr>
                <?php else: ?>
                    <?php foreach ($ledger as $l):
                        $displayMoneyIn = '';
                        $displayMoneyOut = '';
                        $flow = transactionBusinessFlow($l['transaction_type'], $l);
                        $eventAmount = round(floatval($l['entry_amount'] ?? 0), 2);

                        $cashFlowOnly = in_array($l['transaction_type'], ['CAR_SALE', 'CAR_PURCHASE', 'CAR_TOKEN_RECEIVED', 'LOAN_RECEIVED', 'LOAN_REPAID', 'CAR_EXPENSE', 'RTO_EXPENSE', 'RTO_RECOVERY'], true);
                        if ($cashFlowOnly && (floatval($l['cash_in_amount'] ?? 0) > 0 || floatval($l['cash_out_amount'] ?? 0) > 0)) {
                            $eventAmount = max(floatval($l['cash_in_amount'] ?? 0), floatval($l['cash_out_amount'] ?? 0));
                            $flow = floatval($l['cash_in_amount'] ?? 0) > 0 ? 'in' : 'out';
                        } elseif (!empty($l['voucher_id']) && floatval($l['voucher_allocation_amount'] ?? 0) > 0) {
                            $eventAmount = round(floatval($l['voucher_allocation_amount']), 2);
                            $flow = ($l['voucher_primary_entry_type'] ?? 'CR') === 'DR' ? 'in' : 'out';
                        } elseif ($eventAmount <= 0) {
                            $eventAmount = max(
                                floatval($l['cash_in_amount'] ?? 0),
                                floatval($l['cash_out_amount'] ?? 0),
                                floatval($l['car_line_amount'] ?? 0)
                            );
                        }

                        if (!empty($l['is_reversal'])) {
                            $flow = $flow === 'in' ? 'out' : ($flow === 'out' ? 'in' : $flow);
                        }

                        if ($flow === 'in' && $eventAmount > 0) {
                            $displayMoneyIn = formatAmount($eventAmount);
                        } elseif ($flow === 'out' && $eventAmount > 0) {
                            $displayMoneyOut = formatAmount($eventAmount);
                        } elseif (floatval($l['cash_in_amount'] ?? 0) > 0) {
                            $displayMoneyIn = formatAmount($l['cash_in_amount']);
                        } elseif (floatval($l['cash_out_amount'] ?? 0) > 0) {
                            $displayMoneyOut = formatAmount($l['cash_out_amount']);
                        } elseif (!empty($l['car_line_type']) && $eventAmount > 0) {
                            if ($l['car_line_type'] === 'CR') {
                                $displayMoneyIn = formatAmount($eventAmount);
                            } else {
                                $displayMoneyOut = formatAmount($eventAmount);
                            }
                        }
                    ?>
                    <tr>
                        <td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td>
                        <td><a href="../transactions/view.php?id=<?= urlencode($l['entry_id']) ?>"><?= clean($l['reference_no']) ?></a></td>
                        <td><span class="badge badge-blue"><?= clean(transactionTypeLabel($l['transaction_type'], $l)) ?></span></td>
                        <td>
                            <div><?= clean(mb_substr($l['narration'] ?? '', 0, 90)) ?></div>
                            <?php if (!empty($l['voucher_id'])): ?>
                                <div class="table-note">
                                    <i class="ri-bill-line"></i>
                                    <strong><?= clean($l['voucher_reference_no']) ?></strong>
                                    &middot; This car: <?= formatAmount($l['voucher_allocation_amount']) ?>
                                    of <?= formatAmount($l['voucher_total']) ?> bill
                                    <?php if (intval($l['allocation_line_count'] ?? 0) > 1): ?>
                                        &middot; <?= intval($l['allocation_line_count']) ?> lines
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($l['voucher_allocation_note'])): ?>
                                    <div class="table-note table-note-compact"><?= clean($l['voucher_allocation_note']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
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
