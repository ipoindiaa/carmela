<?php
$pageTitle = 'Person Statement';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
new AccountingEngine($businessId, Auth::user('user_id'));
$people = new PersonIdentity($businessId);
$kind = get('kind');
$id = get('id');
try {
    $members = $people->members($kind, $id);
    foreach ($members as $member) Auth::requireEntityAccess($member['kind'], 'read');
} catch (Throwable $e) {
    http_response_code(404); exit('Person not found or unavailable.');
}
$person = $people->identityFor($kind, $id);
$accounts = [];
$partnerIds = [];
$aliases = [];
foreach ($members as $member) {
    if ($member['kind'] === 'partner') $partnerIds[] = $member['id'];
    $aliasRow = $db->fetch("SELECT aliases FROM person_identity_members WHERE business_id = ? AND kind = ? AND record_id = ?", [$businessId, $member['kind'], $member['id']]);
    if ($aliasRow) $aliases = array_merge($aliases, json_decode($aliasRow['aliases'], true));
    foreach (array_filter([$member['account_id'] ?? null, $member['capital_account_id'] ?? null, $member['current_account_id'] ?? null]) as $accountId) {
        $accounts[$accountId] = $member;
    }
}
$cars = [];
if ($partnerIds) {
    $placeholders = implode(',', array_fill(0, count($partnerIds), '?'));
    $cars = $db->fetchAll(
        "SELECT c.id, c.registration_no, c.status, SUM(cp.funding_amount) AS funding_amount,
                SUM(cp.profit_share_pct) AS profit_share_pct
         FROM car_partnerships cp JOIN cars c ON c.id = cp.car_id AND c.business_id = cp.business_id
         WHERE cp.business_id = ? AND cp.partner_id IN ($placeholders) AND cp.status = 'ACTIVE'
         GROUP BY c.id, c.registration_no, c.status ORDER BY c.registration_no",
        array_merge([$businessId], $partnerIds)
    );
}
$ledger = [];
if ($accounts) {
    $placeholders = implode(',', array_fill(0, count($accounts), '?'));
    $ledger = $db->fetchAll(
        "SELECT j.id, j.reference_no, j.entry_date, j.created_at, j.narration, j.transaction_type,
                l.id AS line_id, l.entry_type, l.amount, a.name AS account_name
         FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id
         JOIN accounts a ON a.id = l.account_id AND a.business_id = j.business_id
         WHERE j.business_id = ? AND l.account_id IN ($placeholders) AND j.status IN ('POSTED','REVERSED')
         ORDER BY j.entry_date DESC, j.created_at DESC, j.id, l.id",
        array_merge([$businessId], array_keys($accounts))
    );
}
?>
<div class="page-header"><h1><?= clean($person['name'] ?? $members[0]['name']) ?> · Person Statement</h1>
    <?php if (Auth::isAdmin()): ?><a class="btn btn-outline" href="merge.php?<?= clean(http_build_query(['kind' => $kind, 'id' => $id])) ?>">Merge Another Record</a><?php endif; ?>
</div>
<p>One person, with separate capital, profit and trading accounts. Balances below are not automatically offset.</p>
<?php if ($aliases): ?><p class="text-muted">Previous names: <?= clean(implode(', ', array_unique($aliases))) ?></p><?php endif; ?>
<div class="card"><div class="card-header"><h3>Roles and Accounts</h3></div><div class="card-body table-container">
<table data-static-table="1"><thead><tr><th>Role</th><th>Contact</th><th>Account</th><th class="text-right">Balance</th><th>Open Role Statement</th></tr></thead><tbody>
<?php foreach ($accounts as $accountId => $member):
    $account = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $businessId]); ?>
    <tr><td><?= clean($member['role']) ?></td><td><?= clean($member['phone'] ?: '—') ?> <?= clean($member['email'] ?? '') ?></td>
        <td><?= clean($account['name']) ?> <small><?= clean($account['code']) ?></small></td>
        <td class="text-right amount"><?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></td>
        <td><a class="btn btn-outline btn-sm" href="<?= APP_URL . ($member['kind'] === 'party' ? 'parties' : 'partners') ?>/view.php?id=<?= clean($member['id']) ?>">Statement / Payment</a></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php if ($cars): ?>
<div class="card"><div class="card-header"><h3>Combined Partner Car Portfolio</h3></div><div class="card-body table-container">
<table data-static-table="1"><thead><tr><th>Car</th><th>Status</th><th class="text-right">Invested</th><th class="text-right">Profit Share</th></tr></thead><tbody>
<?php foreach ($cars as $car): ?><tr><td><?= clean(formatRegistrationNo($car['registration_no'])) ?></td><td><?= clean($car['status']) ?></td><td class="text-right amount"><?= formatAmount($car['funding_amount']) ?></td><td class="text-right"><?= formatPlainNumber($car['profit_share_pct']) ?>%</td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<div class="card"><div class="card-header"><h3>Combined Transaction History</h3></div><div class="card-body table-container">
<table data-static-table="1"><thead><tr><th>Date</th><th>Reference</th><th>Account</th><th>Description</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead><tbody>
<?php foreach ($ledger as $line): ?>
<tr><td><?= formatDate($line['entry_date']) ?></td><td><?= clean($line['reference_no']) ?></td><td><?= clean($line['account_name']) ?></td><td><?= clean($line['narration']) ?></td>
<td class="text-right amount"><?= $line['entry_type'] === 'DR' ? formatAmount($line['amount']) : '—' ?></td>
<td class="text-right amount"><?= $line['entry_type'] === 'CR' ? formatAmount($line['amount']) : '—' ?></td></tr>
<?php endforeach; ?>
<?php if (!$ledger): ?><tr><td colspan="6">No transactions.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
