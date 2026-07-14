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
    "SELECT je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status = 'POSTED' ORDER BY je.entry_date DESC, je.created_at DESC", [$partner['capital_account_id']]);

$currentLedger = $db->fetchAll(
    "SELECT je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status = 'POSTED' ORDER BY je.entry_date DESC, je.created_at DESC", [$partner['current_account_id']]);

$carContribs = $db->fetchAll("SELECT cpc.*, c.registration_no FROM car_partner_contributions cpc JOIN cars c ON c.id = cpc.car_id WHERE cpc.partner_id = ? ORDER BY cpc.contribution_date DESC, cpc.created_at DESC", [$id]);
$linkedCars = $db->fetchAll("SELECT id, registration_no, make, model, status, purchase_date FROM cars WHERE business_id = ? AND partner_id = ? ORDER BY purchase_date DESC, created_at DESC", [$businessId, $id]);
$totalInvested = $db->fetch("SELECT COALESCE(SUM(jl.amount),0) as total FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id WHERE jl.account_id = ? AND jl.entry_type = 'CR' AND je.status='POSTED'", [$partner['capital_account_id']]);
$totalWithdrawn = $db->fetch("SELECT COALESCE(SUM(jl.amount),0) as total FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id WHERE jl.account_id = ? AND jl.entry_type = 'DR' AND je.status='POSTED'", [$partner['capital_account_id']]);
$capitalBalance = floatval($position['capital_balance'] ?? 0);
$currentBalance = floatval($position['current_balance'] ?? 0);
$capitalLabel = $capitalBalance >= 0 ? 'Partner Capital Credit' : 'Capital Overdrawn';
$currentLabel = $currentBalance >= 0 ? 'Business Owes Partner' : 'Partner Owes Business';
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
                <div class="form-group"><label class="form-label">Default Profit Share %</label><input type="number" name="profit_share_pct" class="form-control" value="<?= clean($partner['profit_share_pct']) ?>" step="0.01" min="0" max="100"></div>
                <div class="form-group"><label class="form-label">Joined Date *</label><input type="date" name="joined_date" class="form-control" value="<?= clean($partner['joined_date']) ?>" required></div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Update Partner</button>
            <a href="view.php?id=<?= $partner['id'] ?>" class="btn btn-outline">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($totalInvested['total']) ?></div><div class="stat-label">Total Invested</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($totalWithdrawn['total']) ?></div><div class="stat-label">Total Withdrawn</div></div>
    <div class="stat-card"><div class="stat-value <?= signedAmountColorClass($capitalBalance, 'in') ?>"><?= formatAmount($capitalBalance, true) ?></div><div class="stat-label"><?= clean($capitalLabel) ?></div></div>
    <div class="stat-card"><div class="stat-value <?= signedAmountColorClass($currentBalance, 'out') ?>"><?= formatAmount($currentBalance, true) ?></div><div class="stat-label"><?= clean($currentLabel) ?></div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($position['committed_funding'] ?? 0) ?></div><div class="stat-label">Committed Funding</div></div>
</div>

<div class="card">
    <div class="card-header"><h3>Directly Linked Cars</h3></div>
    <div class="card-body card-body-flush">
        <table><thead><tr><th>Car</th><th>Vehicle</th><th>Status</th><th>Purchase Date</th><th class="text-center">Action</th></tr></thead><tbody>
        <?php foreach ($linkedCars as $linkedCar): ?><tr><td class="text-bold"><?= clean(formatRegistrationNo($linkedCar['registration_no'])) ?></td><td><?= clean(trim(($linkedCar['make'] ?? '') . ' ' . ($linkedCar['model'] ?? '')) ?: '-') ?></td><td><span class="badge badge-blue"><?= clean($linkedCar['status']) ?></span></td><td><?= formatDate($linkedCar['purchase_date']) ?></td><td class="text-center"><a href="../cars/view.php?id=<?= $linkedCar['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-eye-line"></i></a></td></tr><?php endforeach; ?>
        <?php if (empty($linkedCars)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No cars directly linked to this partner.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Capital Account Ledger</h3></div>
        <div class="card-body card-body-flush">
            <table><thead><tr><th>Date / Time</th><th>Ref</th><th>Narration</th><th class="text-right debit-amount">Dr</th><th class="text-right credit-amount">Cr</th></tr></thead>
                <tbody>
                <?php foreach ($capitalLedger as $l): ?>
                <tr><td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td><td><?= $l['reference_no'] ?></td><td><?= clean(mb_substr($l['narration']??'',0,40)) ?></td>
                    <td class="text-right amount debit-amount"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                    <td class="text-right amount credit-amount"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($capitalLedger)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No entries</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Car Contributions</h3></div>
        <div class="card-body card-body-flush">
            <table><thead><tr><th>Car</th><th class="text-right">Amount</th><th class="text-right">Funding %</th><th class="text-right">Profit Share %</th><th>Date / Time</th></tr></thead>
                <tbody>
                <?php foreach ($carContribs as $c): ?>
                <tr><td><a href="../cars/view.php?id=<?= $c['car_id'] ?>"><?= clean(formatRegistrationNo($c['registration_no'])) ?></a></td><td class="text-right amount"><?= formatAmount($c['amount']) ?></td><td class="text-right"><?= formatPlainNumber($c['funding_pct']) ?>%</td><td class="text-right"><?= formatPlainNumber($c['profit_share_pct']) ?>%</td><td><?= renderDateTimeStack($c['contribution_date'], $c['created_at']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($carContribs)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No contributions</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Current Account Ledger</h3></div>
    <div class="card-body card-body-flush">
        <table><thead><tr><th>Date / Time</th><th>Ref</th><th>Narration</th><th class="text-right debit-amount">Dr</th><th class="text-right credit-amount">Cr</th></tr></thead>
            <tbody>
            <?php foreach ($currentLedger as $l): ?>
            <tr><td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td><td><?= $l['reference_no'] ?></td><td><?= clean(mb_substr($l['narration']??'',0,40)) ?></td>
                <td class="text-right amount debit-amount"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                <td class="text-right amount credit-amount"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($currentLedger)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No entries</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
