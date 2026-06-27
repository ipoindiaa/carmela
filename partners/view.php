<?php
$pageTitle = 'Partner Statement';
$pageIcon = '<i class="ri-group-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$id = get('id');
$partner = $db->fetch("SELECT p.*, a.current_balance as capital_balance FROM partners p LEFT JOIN accounts a ON a.id = p.capital_account_id WHERE p.id = ? AND p.business_id = ?", [$id, $businessId]);
if (!$partner) { setFlash('error', 'Partner not found.'); redirect('list.php'); }
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
    <h1><i class="ri-group-line"></i> <?= clean($partner['name']) ?> <span class="badge badge-purple" style="vertical-align:middle;"><?= clean($partnerTypeLabel) ?></span></h1>
    <a href="list.php?type=<?= clean($backType) ?>" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(3,1fr);">
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($totalInvested['total']) ?></div><div class="stat-label">Total Invested</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($totalWithdrawn['total']) ?></div><div class="stat-label">Total Withdrawn</div></div>
    <div class="stat-card"><div class="stat-value <?= signedAmountColorClass($capitalBalance, 'in') ?>"><?= formatAmount($capitalBalance, true) ?></div><div class="stat-label"><?= clean($capitalLabel) ?></div></div>
    <div class="stat-card"><div class="stat-value <?= signedAmountColorClass($currentBalance, 'out') ?>"><?= formatAmount($currentBalance, true) ?></div><div class="stat-label"><?= clean($currentLabel) ?></div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($position['committed_funding'] ?? 0) ?></div><div class="stat-label">Committed Funding</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Capital Account Ledger</h3></div>
        <div class="card-body" style="padding:0;">
            <table><thead><tr><th>Date / Time</th><th>Ref</th><th>Narration</th><th class="text-right debit-amount">Dr</th><th class="text-right credit-amount">Cr</th></tr></thead>
                <tbody>
                <?php foreach ($capitalLedger as $l): ?>
                <tr><td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td><td><?= $l['reference_no'] ?></td><td><?= clean(mb_substr($l['narration']??'',0,40)) ?></td>
                    <td class="text-right amount debit-amount"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                    <td class="text-right amount credit-amount"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($capitalLedger)): ?><tr><td colspan="5" class="text-center text-muted" style="padding: 30px;">No entries</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Car Contributions</h3></div>
        <div class="card-body" style="padding:0;">
            <table><thead><tr><th>Car</th><th class="text-right">Amount</th><th class="text-right">Funding %</th><th class="text-right">Profit Share %</th><th>Date / Time</th></tr></thead>
                <tbody>
                <?php foreach ($carContribs as $c): ?>
                <tr><td><a href="../cars/view.php?id=<?= $c['car_id'] ?>"><?= clean(formatRegistrationNo($c['registration_no'])) ?></a></td><td class="text-right amount"><?= formatAmount($c['amount']) ?></td><td class="text-right"><?= formatPlainNumber($c['funding_pct']) ?>%</td><td class="text-right"><?= formatPlainNumber($c['profit_share_pct']) ?>%</td><td><?= renderDateTimeStack($c['contribution_date'], $c['created_at']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($carContribs)): ?><tr><td colspan="5" class="text-center text-muted" style="padding: 30px;">No contributions</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-top:24px;">
    <div class="card-header"><h3>Current Account Ledger</h3></div>
    <div class="card-body" style="padding:0;">
        <table><thead><tr><th>Date / Time</th><th>Ref</th><th>Narration</th><th class="text-right debit-amount">Dr</th><th class="text-right credit-amount">Cr</th></tr></thead>
            <tbody>
            <?php foreach ($currentLedger as $l): ?>
            <tr><td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td><td><?= $l['reference_no'] ?></td><td><?= clean(mb_substr($l['narration']??'',0,40)) ?></td>
                <td class="text-right amount debit-amount"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                <td class="text-right amount credit-amount"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($currentLedger)): ?><tr><td colspan="5" class="text-center text-muted" style="padding: 30px;">No entries</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
