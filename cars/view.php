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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'upload_car_images') {
    verifyCsrf();
    try {
        $imageType = strtoupper(post('image_type', 'SELLER')) === 'BUYER' ? 'BUYER' : 'SELLER';
        $count = uploadEntityAttachments($businessId, 'CAR', $id, $imageType, 'car_images', Auth::user('user_id'), 'images');
        setFlash('success', $count > 0 ? "$count image uploaded." : 'No image selected.');
        redirect("view.php?id=$id");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        redirect("view.php?id=$id");
    }
}

$profitability = $engine->getCarProfitability($id);
$profit = $profitability['status'] === 'SOLD' ? $profitability['profit'] : null;
$expenses = $profitability['total_expenses'];
$partnerships = $profitability['partnerships'];
$settlements = $profitability['settlements'];
$buyerImages = fetchEntityAttachments($businessId, 'CAR', $id, 'BUYER');
$sellerImages = fetchEntityAttachments($businessId, 'CAR', $id, 'SELLER');

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
        <?php if ($car['status'] === 'IN_STOCK'): ?>
            <a href="../transactions/new.php?type=CAR_EXPENSE&car_id=<?= $car['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-tools-line"></i> Add Expense</a>
            <a href="../transactions/new.php?type=CAR_SALE" class="btn btn-success btn-sm"><i class="ri-money-rupee-circle-line"></i> Sell Car</a>
        <?php endif; ?>
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
        <div class="stat-value flow-out"><?= formatAmount($car['total_cost']) ?></div>
        <div class="stat-label">Total Cost</div>
    </div>
    <div class="stat-card">
        <div class="stat-header"><div class="stat-icon" style="background: <?= ($profit ?? 0) >= 0 ? 'var(--accent-green-glow)' : 'var(--accent-red-glow)' ?>; color: <?= ($profit ?? 0) >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>;"><i class="ri-line-chart-line"></i></div></div>
        <div class="stat-value" style="color: <?= ($profit ?? 0) >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>;"><?= $profit !== null ? formatAmount($profit, true) : 'In Stock' ?></div>
        <div class="stat-label"><?= $car['status'] === 'SOLD' ? 'Profit / Loss' : 'Sale Price: N/A' ?></div>
    </div>
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
