<?php
$pageTitle = 'Car Detail';
$pageIcon = '<i class="ri-car-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';

$id = get('id');
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

$car = $db->fetch("SELECT c.*, a.current_balance as total_cost FROM cars c LEFT JOIN accounts a ON a.id = c.account_id WHERE c.id = ? AND c.business_id = ?", [$id, $businessId]);
if (!$car) { setFlash('error', 'Car not found.'); redirect('list.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $action = post('action');
        if ($action === 'upload_car_images') {
            $imageType = strtoupper(post('image_type', 'SELLER')) === 'BUYER' ? 'BUYER' : 'SELLER';
            $count = uploadEntityAttachments($businessId, 'CAR', $id, $imageType, 'car_images', Auth::user('user_id'), 'images');
            setFlash('success', $count > 0 ? "$count image uploaded." : 'No image selected.');
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
$settlements = $profitability['settlements'];
$buyerImages = fetchEntityAttachments($businessId, 'CAR', $id, 'BUYER');
$sellerImages = fetchEntityAttachments($businessId, 'CAR', $id, 'SELLER');

$buyerParty = !empty($car['buyer_party_id']) ? $db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$car['buyer_party_id'], $businessId]) : null;
$sellerParty = !empty($car['seller_party_id']) ? $db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$car['seller_party_id'], $businessId]) : null;
$buyerOutstanding = $buyerParty ? $engine->getPartyOutstandingAmount($buyerParty['id']) : 0.0;
$sellerOutstanding = $sellerParty ? $engine->getPartyOutstandingAmount($sellerParty['id']) : 0.0;
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
$rtoSpent = array_sum(array_map(static fn($row) => (float) $row['expense_amount'], $rtoRecords));
$rtoRecovered = array_sum(array_map(static fn($row) => (float) $row['recovered_amount'], $rtoRecords));
$rtoPending = array_sum(array_map(static fn($row) => !empty($row['is_recoverable']) ? max(0, (float) $row['expense_amount'] - (float) $row['recovered_amount']) : 0, $rtoRecords));
$keyEvents = $db->fetchAll(
    "SELECT ske.*, u.full_name FROM car_second_key_events ske LEFT JOIN users u ON u.id = ske.created_by WHERE ske.business_id = ? AND ske.car_id = ? ORDER BY ske.event_date DESC, ske.created_at DESC",
    [$businessId, $id]
);

// Car ledger entries
$ledger = $db->fetchAll(
    "SELECT je.id as entry_id, je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status = 'POSTED' ORDER BY je.entry_date DESC, je.created_at DESC", [$car['account_id']]);

// Partner contributions
$contributions = $db->fetchAll(
    "SELECT cpc.*, p.name as partner_name
     FROM car_partner_contributions cpc
     JOIN partners p ON p.id = cpc.partner_id
     WHERE cpc.car_id = ?
     ORDER BY cpc.contribution_date DESC, cpc.created_at DESC", [$id]);
?>

<div class="page-header">
    <h1><i class="ri-car-line"></i> <?= clean(formatRegistrationNo($car['registration_no'])) ?></h1>
    <div style="display: flex; gap: 10px;">
        <?php if ($buyerOutstanding > 0 && $buyerParty): ?>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'LOAN_RECEIVED', 'party_id' => $buyerParty['id'], 'car_id' => $car['id'], 'amount' => round($buyerOutstanding), 'narration' => 'Receive pending car payment - ' . $car['registration_no']]) ?>" class="btn btn-success btn-sm"><i class="ri-arrow-down-circle-line"></i> Receive Pending</a>
        <?php endif; ?>
        <?php if ($sellerOutstanding > 0 && $sellerParty): ?>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'LOAN_REPAID', 'party_id' => $sellerParty['id'], 'car_id' => $car['id'], 'amount' => round($sellerOutstanding), 'narration' => 'Pay seller balance - ' . $car['registration_no']]) ?>" class="btn btn-outline btn-sm"><i class="ri-arrow-up-circle-line"></i> Pay Seller</a>
        <?php endif; ?>
        <?php if ($car['status'] === 'IN_STOCK'): ?>
            <a href="../transactions/new.php?type=CAR_EXPENSE&car_id=<?= $car['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-tools-line"></i> Add Expense</a>
            <a href="../transactions/new.php?<?= http_build_query(['type' => 'CAR_SALE', 'car_id' => $car['id']]) ?>" class="btn btn-success btn-sm"><i class="ri-money-rupee-circle-line"></i> Sell Car</a>
        <?php endif; ?>
        <a href="../rto/list.php?car_id=<?= clean($car['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-file-shield-2-line"></i> RTO</a>
        <a href="list.php" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<!-- Car Summary Cards -->
<div class="stats-grid">
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

<div class="stats-grid compact-operational-grid">
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($buyerOutstanding) ?></div><div class="stat-label">Sale Pending</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($sellerOutstanding) ?></div><div class="stat-label">Purchase Pending</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($rtoSpent) ?></div><div class="stat-label">RTO Spent</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($rtoRecovered) ?></div><div class="stat-label">RTO Recovered</div></div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3><i class="ri-image-add-line"></i> Car Images</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="attachment-upload-panel">
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

        <div class="attachment-columns">
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

<div class="grid-2">
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

    <!-- Partner Contributions -->
    <div class="card">
        <div class="card-header"><h3><i class="ri-group-line"></i> Partner Funding & Shares</h3></div>
        <div class="card-body">
            <?php if (empty($contributions)): ?>
                <p class="text-muted">No partner contributions. Fully business-funded.</p>
            <?php else: ?>
                <table style="width: 100%;">
                    <thead><tr><th>Partner</th><th class="text-right">Amount</th><th class="text-right">Funding %</th><th class="text-right">Profit Share %</th><th>Date / Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($contributions as $c): ?>
                        <tr>
                            <td><?= clean($c['partner_name']) ?></td>
                            <td class="text-right amount"><?= formatAmount($c['amount']) ?></td>
                            <td class="text-right"><?= formatPlainNumber($c['funding_pct']) ?>%</td>
                            <td class="text-right"><?= formatPlainNumber($c['profit_share_pct']) ?>%</td>
                            <td><?= renderDateTimeStack($c['contribution_date'], $c['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-header"><h3><i class="ri-scales-2-line"></i> Partner Settlement Status</h3></div>
    <div class="card-body" style="padding: 0;">
        <table>
            <thead><tr><th>Partner</th><th class="text-right">Funding %</th><th class="text-right">Profit Share %</th><th class="text-right">Outstanding</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($partnerships)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding: 24px;">No partner participation on this car.</td></tr>
                <?php else: ?>
                    <?php foreach ($partnerships as $partnership): ?>
                        <?php
                        $partnerSettlement = array_values(array_filter($settlements, static fn($row) => $row['partner_id'] === $partnership['partner_id']));
                        $pending = array_sum(array_map(static fn($row) => (float) $row['outstanding_amount'], $partnerSettlement));
                        $status = empty($partnerSettlement) ? 'Not distributed yet' : implode(', ', array_unique(array_column($partnerSettlement, 'status')));
                        ?>
                        <tr>
                            <td><?= clean($partnership['partner_name']) ?></td>
                            <td class="text-right"><?= formatPlainNumber($partnership['funding_pct']) ?>%</td>
                            <td class="text-right"><?= formatPlainNumber($partnership['profit_share_pct']) ?>%</td>
                            <td class="text-right amount"><?= formatAmount($pending) ?></td>
                            <td><?= clean($status) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid-2" style="margin-top:24px;">
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
    <div class="card-header"><h3><i class="ri-file-shield-2-line"></i> RTO Records</h3><a href="../rto/list.php?car_id=<?= clean($car['id']) ?>" class="btn btn-sm btn-outline">Open RTO Book</a></div>
    <div class="card-body" style="padding:0;">
        <table><thead><tr><th>Type</th><th>Status</th><th>Agent</th><th class="text-right">Spent</th><th class="text-right">Recovered</th><th class="text-right">Pending</th></tr></thead><tbody>
            <?php if (empty($rtoRecords)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:24px;">No RTO work for this car.</td></tr><?php else: ?>
            <?php foreach ($rtoRecords as $rto): $pending = !empty($rto['is_recoverable']) ? max(0, (float)$rto['expense_amount'] - (float)$rto['recovered_amount']) : 0; ?><tr>
                <td><?= clean($rto['rto_type']) ?></td><td><span class="badge badge-blue"><?= clean($rto['status']) ?></span></td><td><?= clean($rto['agent_name'] ?: '-') ?></td><td class="text-right amount flow-out"><?= formatAmount($rto['expense_amount']) ?></td><td class="text-right amount flow-in"><?= formatAmount($rto['recovered_amount']) ?></td><td class="text-right amount <?= $pending > 0 ? 'flow-out' : 'flow-neutral' ?>"><?= formatAmount($pending) ?></td>
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

<!-- Car Ledger -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header"><h3><i class="ri-book-2-line"></i> Car Ledger</h3></div>
    <div class="card-body" style="padding: 0;">
        <table>
            <thead><tr><th>Date / Time</th><th>Ref</th><th>Type</th><th>Narration</th><th class="text-right debit-amount">Debit</th><th class="text-right credit-amount">Credit</th></tr></thead>
            <tbody>
                <?php if (empty($ledger)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding: 30px;">No ledger entries</td></tr>
                <?php else: ?>
                    <?php foreach ($ledger as $l): ?>
                    <tr>
                        <td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td>
                        <td><a href="../transactions/view.php?id=<?= $l['entry_id'] ?>"><?= $l['reference_no'] ?></a></td>
                        <td><span class="badge badge-blue" style="font-size: 10px;"><?= TXN_TYPES[$l['transaction_type']] ?? $l['transaction_type'] ?></span></td>
                        <td><?= clean(mb_substr($l['narration'] ?? '', 0, 50)) ?></td>
                        <td class="text-right amount debit-amount"><?= $l['entry_type'] === 'DR' ? formatAmount($l['amount']) : '' ?></td>
                        <td class="text-right amount credit-amount"><?= $l['entry_type'] === 'CR' ? formatAmount($l['amount']) : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
