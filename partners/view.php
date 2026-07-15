<?php
$pageTitle = 'Partner Statement';
$pageIcon = '<i class="ri-group-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
Auth::requireEntityAccess('partner', 'read');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$id = get('id');
$partner = $db->fetch("SELECT p.*, a.current_balance as capital_balance FROM partners p LEFT JOIN accounts a ON a.id = p.capital_account_id WHERE p.id = ? AND p.business_id = ?", [$id, $businessId]);
if (!$partner) { setFlash('error', 'Partner not found.'); redirect('list.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'update') {
    Auth::requireEntityAccess('partner', 'write');
    verifyCsrf();
    try {
        $name = trim((string) post('name'));
        if ($name === '') throw new Exception('Partner name is required.');
        $partnerType = strtoupper((string) post('partner_type', 'MAIN'));
        if (!in_array($partnerType, ['MAIN', 'CARWISE'], true)) throw new Exception('Invalid partner type.');
        $phone = validatePhoneNumber(post('phone'), 'Phone number');
        $email = validateEmailAddress(post('email'), 'Email');
        $share = round(parseDecimalInput(post('profit_share_pct', 0)), 2);
        if ($share < 0 || $share > 100) throw new Exception('Profit share must be between 0 and 100.');

        $db->query(
            "UPDATE partners SET name = ?, partner_type = ?, phone = ?, email = ?, pan = ?, profit_share_pct = ?, joined_date = ?, is_active = ? WHERE id = ? AND business_id = ?",
            [$name, $partnerType, $phone, $email, strtoupper(trim((string) post('pan'))), $share, post('joined_date'), post('is_active', '0') === '1' ? 1 : 0, $id, $businessId]
        );
        if (!empty($partner['capital_account_id'])) {
            $oldCapitalAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$partner['capital_account_id'], $businessId]);
            $db->query("UPDATE accounts SET name = ? WHERE id = ? AND business_id = ?", ["$name - Capital A/c", $partner['capital_account_id'], $businessId]);
            $newCapitalAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$partner['capital_account_id'], $businessId]);
            Auth::auditUpdate('account', $partner['capital_account_id'], $oldCapitalAccount ?: [], $newCapitalAccount ?: [], 'Partner capital account renamed', 'partners');
        }
        if (!empty($partner['current_account_id'])) {
            $oldCurrentAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$partner['current_account_id'], $businessId]);
            $db->query("UPDATE accounts SET name = ? WHERE id = ? AND business_id = ?", ["$name - Current A/c", $partner['current_account_id'], $businessId]);
            $newCurrentAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$partner['current_account_id'], $businessId]);
            Auth::auditUpdate('account', $partner['current_account_id'], $oldCurrentAccount ?: [], $newCurrentAccount ?: [], 'Partner current account renamed', 'partners');
        }
        $updated = $db->fetch("SELECT * FROM partners WHERE id = ? AND business_id = ?", [$id, $businessId]);
        Auth::auditUpdate('partner', $id, $partner, $updated ?: [], "Partner $name updated", 'partners');
        setFlash('success', 'Partner details updated.');
        redirect("view.php?id=$id");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        redirect("view.php?id=$id&edit=1");
    }
}
$position = $engine->getPartnerPosition($id);

$capitalLedger = $db->fetchAll(
    "SELECT je.id AS entry_id, je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status = 'POSTED' ORDER BY je.entry_date DESC, je.created_at DESC", [$partner['capital_account_id']]);

$currentLedger = $db->fetchAll(
    "SELECT je.id AS entry_id, je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status = 'POSTED' ORDER BY je.entry_date DESC, je.created_at DESC", [$partner['current_account_id']]);

$portfolioCars = $db->fetchAll(
    "SELECT cp.*, c.registration_no, c.make, c.model, c.status AS car_status, c.purchase_date, c.sold_date,
            COALESCE(ps.payable_outstanding, 0) AS payable_outstanding,
            COALESCE(ps.receivable_outstanding, 0) AS receivable_outstanding
     FROM car_partnerships cp
     JOIN cars c ON c.id = cp.car_id AND c.business_id = cp.business_id
     LEFT JOIN (
        SELECT car_id, partner_id,
               SUM(CASE WHEN direction = 'PAYABLE' THEN outstanding_amount ELSE 0 END) AS payable_outstanding,
               SUM(CASE WHEN direction = 'RECEIVABLE' THEN outstanding_amount ELSE 0 END) AS receivable_outstanding
        FROM partner_profit_settlements
        WHERE business_id = ? AND status IN ('PENDING', 'PARTIAL')
        GROUP BY car_id, partner_id
     ) ps ON ps.car_id = cp.car_id AND ps.partner_id = cp.partner_id
     WHERE cp.business_id = ? AND cp.partner_id = ? AND cp.status = 'ACTIVE'
     ORDER BY FIELD(c.status, 'IN_STOCK', 'PENDING_PAYMENT', 'SOLD', 'CANCELLED'), c.purchase_date DESC, c.created_at DESC",
    [$businessId, $businessId, $id]
);
$totalInvested = $db->fetch(
    "SELECT COALESCE(SUM(jl.amount), 0) AS total
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND jl.entry_type = 'CR'
       AND je.business_id = ? AND je.status = 'POSTED' AND je.is_reversal = 0
       AND je.transaction_type = 'PARTNER_INVEST'",
    [$partner['capital_account_id'], $businessId]
);
$totalWithdrawn = $db->fetch(
    "SELECT COALESCE(SUM(jl.amount), 0) AS total
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND jl.entry_type = 'DR'
       AND je.business_id = ? AND je.status = 'POSTED' AND je.is_reversal = 0
       AND je.transaction_type = 'PARTNER_WITHDRAW'",
    [$partner['capital_account_id'], $businessId]
);
$capitalBalance = floatval($position['capital_balance'] ?? 0);
$currentBalance = floatval($position['current_balance'] ?? 0);
$capitalLabel = abs($capitalBalance) < 0.01 ? 'No Capital Balance' : ($capitalBalance > 0 ? 'Partner Capital Credit' : 'Capital Overdrawn');
$currentLabel = abs($currentBalance) < 0.01 ? 'Current Account Settled' : ($currentBalance > 0 ? 'Business Owes Partner' : 'Partner Owes Business');
$netPosition = round($capitalBalance + $currentBalance, 2);
$netPositionLabel = abs($netPosition) < 0.01 ? 'Overall Balance Settled' : ($netPosition > 0 ? 'Total Business Owes' : 'Total Partner Owes');
$partnerTypeLabel = ($partner['partner_type'] ?? 'MAIN') === 'CARWISE' ? 'Car-wise Partner' : 'Main Partner';
$backType = ($partner['partner_type'] ?? 'MAIN') === 'CARWISE' ? 'CARWISE' : 'MAIN';
?>

<div class="page-header">
    <h1><i class="ri-group-line"></i> <?= clean($partner['name']) ?> <span class="badge badge-purple"><?= clean($partnerTypeLabel) ?></span></h1>
    <div class="page-actions">
        <?php if (Auth::hasEntityAccess('partner', 'write')): ?><a href="view.php?id=<?= $partner['id'] ?>&amp;edit=1" class="btn btn-outline btn-sm"><i class="ri-edit-line"></i> Edit</a><?php endif; ?>
        <a href="../reports/change_history.php?entity_type=partner&amp;entity_id=<?= $partner['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
        <?php if (Auth::isAdmin()): ?><a href="../settings/opening_balances.php?account_id=<?= $partner['capital_account_id'] ?>" class="btn btn-outline btn-sm"><i class="ri-scales-3-line"></i> Opening Capital</a><?php endif; ?>
        <a href="list.php?type=<?= clean($backType) ?>" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<?php if (get('edit') === '1' && Auth::hasEntityAccess('partner', 'write')): ?>
<div class="card">
    <div class="card-header"><h3><i class="ri-edit-line"></i> Edit Partner</h3></div>
    <div class="card-body">
        <form method="POST" data-confirm-submit="Save these partner changes? The field-level changes will be recorded.">
            <?= csrfField() ?><input type="hidden" name="action" value="update">
            <div class="form-row-3">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" value="<?= clean($partner['name']) ?>" required></div>
                <div class="form-group"><label class="form-label">Partner Type *</label><select name="partner_type" class="form-control"><option value="MAIN" <?= $partner['partner_type'] === 'MAIN' ? 'selected' : '' ?>>Main Partner</option><option value="CARWISE" <?= $partner['partner_type'] === 'CARWISE' ? 'selected' : '' ?>>Car-wise Partner</option></select></div>
                <div class="form-group"><label class="form-label">Status</label><select name="is_active" class="form-control"><option value="1" <?= $partner['is_active'] ? 'selected' : '' ?>>Active</option><option value="0" <?= !$partner['is_active'] ? 'selected' : '' ?>>Inactive</option></select></div>
            </div>
            <div class="form-row-3">
                <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= clean($partner['phone']) ?>" inputmode="numeric" pattern="[0-9]{10}" maxlength="10"></div>
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= clean($partner['email']) ?>"></div>
                <div class="form-group"><label class="form-label">PAN</label><input type="text" name="pan" class="form-control" value="<?= clean($partner['pan']) ?>" maxlength="10"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Default Car Profit Share %</label><input type="number" name="profit_share_pct" class="form-control" value="<?= clean($partner['profit_share_pct']) ?>" step="0.01" min="0" max="100"><div class="form-hint">Used only when a car-specific share is left blank.</div></div>
                <div class="form-group"><label class="form-label">Joined Date *</label><input type="date" name="joined_date" class="form-control" value="<?= clean($partner['joined_date']) ?>" required></div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Update Partner</button>
            <a href="view.php?id=<?= $partner['id'] ?>" class="btn btn-outline">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="stats-grid partner-portfolio-stats">
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($totalInvested['total']) ?></div><div class="stat-label">Capital Added</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($totalWithdrawn['total']) ?></div><div class="stat-label">Money Taken From Business</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($position['committed_funding'] ?? 0) ?></div><div class="stat-label">Funding in Active Cars</div></div>
    <div class="stat-card"><div class="stat-value <?= signedAmountColorClass($capitalBalance, 'in') ?>"><?= formatAmount($capitalBalance, true) ?></div><div class="stat-label"><?= clean($capitalLabel) ?></div></div>
    <div class="stat-card"><div class="stat-value <?= signedAmountColorClass($currentBalance, 'out') ?>"><?= formatAmount($currentBalance, true) ?></div><div class="stat-label"><?= clean($currentLabel) ?></div></div>
    <div class="stat-card"><div class="stat-value <?= signedAmountColorClass($netPosition, 'out') ?>"><?= formatAmount(abs($netPosition)) ?></div><div class="stat-label"><?= clean($netPositionLabel) ?></div></div>
</div>

<div class="card partner-portfolio-card">
    <div class="card-header">
        <div><h3><i class="ri-car-line"></i> Car Portfolio</h3><div class="card-header-note">Live funding, ownership, and unsettled profit or loss for every assigned car.</div></div>
    </div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline partner-portfolio-table">
            <table><thead><tr><th>Car</th><th>Vehicle</th><th>Status</th><th class="text-right">Invested</th><th class="text-right">Funding %</th><th class="text-right">Profit Share %</th><th>Live Settlement</th><th>Purchase Date</th><th class="text-center">Action</th></tr></thead><tbody>
            <?php foreach ($portfolioCars as $portfolioCar): ?>
                <?php $statusBadges = ['IN_STOCK'=>'badge-blue','SOLD'=>'badge-green','PENDING_PAYMENT'=>'badge-yellow','CANCELLED'=>'badge-gray']; ?>
                <tr>
                    <td><a href="../cars/view.php?id=<?= clean($portfolioCar['car_id']) ?>" class="text-bold"><?= clean(formatRegistrationNo($portfolioCar['registration_no'])) ?></a></td>
                    <td><?= clean(trim(($portfolioCar['make'] ?? '') . ' ' . ($portfolioCar['model'] ?? '')) ?: '-') ?></td>
                    <td><span class="badge <?= $statusBadges[$portfolioCar['car_status']] ?? 'badge-gray' ?>"><?= clean(CAR_STATUS[$portfolioCar['car_status']] ?? $portfolioCar['car_status']) ?></span></td>
                    <td class="text-right amount"><?= formatAmount($portfolioCar['funding_amount']) ?></td>
                    <td class="text-right"><?= formatPlainNumber($portfolioCar['funding_pct']) ?>%</td>
                    <td class="text-right"><?= formatPlainNumber($portfolioCar['profit_share_pct']) ?>%</td>
                    <td>
                        <?php if (floatval($portfolioCar['payable_outstanding']) > 0): ?><span class="mini-pill mini-pill-in">Business owes <?= formatAmount($portfolioCar['payable_outstanding']) ?></span>
                        <?php elseif (floatval($portfolioCar['receivable_outstanding']) > 0): ?><span class="mini-pill mini-pill-out">Partner owes <?= formatAmount($portfolioCar['receivable_outstanding']) ?></span>
                        <?php else: ?><span class="text-muted">No pending settlement</span><?php endif; ?>
                    </td>
                    <td><?= formatDate($portfolioCar['purchase_date']) ?></td>
                    <td class="text-center"><a href="../cars/view.php?id=<?= clean($portfolioCar['car_id']) ?>" class="btn btn-outline btn-sm" title="View car"><i class="ri-eye-line"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($portfolioCars)): ?><tr><td colspan="9" class="text-center text-muted empty-table-cell">No cars are assigned to this partner.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>
</div>

<div class="grid-2 partner-ledger-grid">
    <div class="card">
        <div class="card-header"><div><h3>Capital Account</h3><div class="card-header-note">Money added by or taken by the partner.</div></div></div>
        <div class="card-body card-body-flush">
            <div class="table-container table-container-inline partner-ledger-table"><table><thead><tr><th>Date / Time</th><th>Ref</th><th>Narration</th><th class="text-right flow-out">Taken / Reduced</th><th class="text-right flow-in">Added</th></tr></thead>
                <tbody>
                <?php foreach ($capitalLedger as $l): ?>
                <tr><td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td><td><a href="../transactions/view.php?id=<?= clean($l['entry_id']) ?>"><?= clean($l['reference_no']) ?></a></td><td><?= clean(mb_substr($l['narration']??'',0,54)) ?></td>
                    <td class="text-right amount flow-out"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                    <td class="text-right amount flow-in"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($capitalLedger)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No entries</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><div><h3>Current Account</h3><div class="card-header-note">Profit, loss, and settlement activity with the partner.</div></div></div>
        <div class="card-body card-body-flush">
            <div class="table-container table-container-inline partner-ledger-table"><table><thead><tr><th>Date / Time</th><th>Ref</th><th>Narration</th><th class="text-right flow-out">Reduces Partner Balance</th><th class="text-right flow-in">Increases Partner Balance</th></tr></thead>
                <tbody>
                <?php foreach ($currentLedger as $l): ?>
                <tr><td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td><td><a href="../transactions/view.php?id=<?= clean($l['entry_id']) ?>"><?= clean($l['reference_no']) ?></a></td><td><?= clean(mb_substr($l['narration']??'',0,54)) ?></td>
                    <td class="text-right amount flow-out"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                    <td class="text-right amount flow-in"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($currentLedger)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No entries</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
