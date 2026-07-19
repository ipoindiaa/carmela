<?php
$pageTitle = 'Party Statement';
$pageIcon = '<i class="ri-contacts-book-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
Auth::requireEntityAccess('party', 'read');
$id = get('id');
$party = $db->fetch("SELECT dc.*, a.current_balance, a.current_balance_type FROM debtors_creditors dc LEFT JOIN accounts a ON a.id = dc.account_id WHERE dc.id = ? AND dc.business_id = ?", [$id, $businessId]);
if (!$party) { setFlash('error', 'Party not found.'); redirect('list.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'update') {
    Auth::requireEntityAccess('party', 'write');
    verifyCsrf();
    try {
        $name = trim((string) post('name'));
        $type = strtoupper((string) post('type'));
        if ($name === '') throw new Exception('Party name is required.');
        if (!in_array($type, ['DEBTOR', 'CREDITOR', 'BUYER', 'SELLER'], true)) throw new Exception('Invalid party type.');
        $phone = validatePhoneNumber(post('phone'), 'Phone number');
        $email = validateEmailAddress(post('email'), 'Email');
        $isActive = post('is_active', '0') === '1' ? 1 : 0;
        if ($type !== $party['type']) {
            $usage = $db->fetch("SELECT COUNT(*) AS cnt FROM journal_lines WHERE account_id = ?", [$party['account_id']]);
            if (($usage['cnt'] ?? 0) > 0) throw new Exception('Party type cannot be changed after ledger activity. Create a new party or reverse the connected entries first.');
        }
        $db->query(
            "UPDATE debtors_creditors SET name = ?, type = ?, phone = ?, email = ?, address = ?, pan_gstin = ?, is_active = ? WHERE id = ? AND business_id = ?",
            [$name, $type, $phone, $email, post('address'), strtoupper(trim((string) post('pan_gstin'))), $isActive, $id, $businessId]
        );
        $isReceivable = in_array($type, ['DEBTOR', 'BUYER'], true);
        $oldPartyAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$party['account_id'], $businessId]);
        $db->query(
            "UPDATE accounts SET name = ?, group_name = ?, sub_group = ?, entity_type = ?, is_active = ? WHERE id = ? AND business_id = ?",
            ["$name ($type)", $isReceivable ? 'ASSET' : 'LIABILITY', $isReceivable ? 'Sundry Debtors' : 'Sundry Creditors', $isReceivable ? 'DEBTOR' : 'CREDITOR', $isActive, $party['account_id'], $businessId]
        );
        $newPartyAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$party['account_id'], $businessId]);
        Auth::auditUpdate('account', $party['account_id'], $oldPartyAccount ?: [], $newPartyAccount ?: [], 'Party ledger account updated', 'parties');
        $updated = $db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$id, $businessId]);
        Auth::auditUpdate('party', $id, $party, $updated ?: [], "Party $name updated", 'parties');
        setFlash('success', 'Party details updated.');
        redirect("view.php?id=$id");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        redirect("view.php?id=$id&edit=1");
    }
}

$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$openItems = $engine->getPartyOpenItems($party['id']);
usort($openItems, static function ($left, $right) {
    return strtotime(($right['entry_date'] ?? '') . ' ' . ($right['created_at'] ?? '')) <=> strtotime(($left['entry_date'] ?? '') . ' ' . ($left['created_at'] ?? ''));
});
$openOutstanding = round(array_sum(array_column($openItems, 'outstanding_amount')), 2);

$debtorOutstanding = in_array($party['type'], ['DEBTOR', 'BUYER'], true) && ($party['current_balance_type'] ?? 'DR') === 'DR'
    ? abs((float) ($party['current_balance'] ?? 0))
    : 0;

$ledger = $db->fetchAll(
    "SELECT je.id AS entry_id, je.business_id, je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, je.entry_type_id, je.entry_amount, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status IN ('POSTED','REVERSED') ORDER BY je.entry_date DESC, je.created_at DESC", [$party['account_id']]);
$partyTokens = $db->fetchAll(
    "SELECT ct.*, c.registration_no, je.reference_no
     FROM car_tokens ct
     JOIN cars c ON c.id = ct.car_id
     LEFT JOIN journal_entries je ON je.id = ct.journal_entry_id
     WHERE ct.business_id = ? AND ct.party_id = ?
     ORDER BY ct.received_date DESC, ct.created_at DESC",
    [$businessId, $id]
);
$tokenAvailable = round(array_sum(array_map(static function ($token) {
    return $token['status'] === 'REVERSED' ? 0 : max(0, floatval($token['amount']) - floatval($token['applied_amount']));
}, $partyTokens)), 2);
?>

<div class="page-header">
    <h1><i class="ri-contacts-book-line"></i> <?= clean($party['name']) ?></h1>
    <div class="page-actions">
        <?php if (Auth::hasEntityAccess('party', 'write')): ?><a href="view.php?id=<?= $party['id'] ?>&amp;edit=1" class="btn btn-outline btn-sm"><i class="<?= !empty($party['is_active']) ? 'ri-edit-line' : 'ri-restart-line' ?>"></i> <?= !empty($party['is_active']) ? 'Edit' : 'Restore' ?></a><?php endif; ?>
        <?php if (!empty($party['is_active']) && Auth::isAdmin()): ?><a href="../settings/opening_balances.php?account_id=<?= $party['account_id'] ?>" class="btn btn-outline btn-sm"><i class="ri-scales-3-line"></i> Opening Balance</a><?php endif; ?>
        <a href="../reports/change_history.php?entity_type=party&amp;entity_id=<?= $party['id'] ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
        <?php if (!empty($party['is_active']) && Auth::hasEntityAccess('party', 'delete')): ?><a href="../delete_record.php?entity_type=party&amp;id=<?= clean($party['id']) ?>" class="btn btn-danger btn-sm"><i class="ri-delete-bin-line"></i> Delete</a><?php endif; ?>
        <?php if (!empty($party['is_active']) && Auth::isAdmin() && $debtorOutstanding > 0): ?>
            <a href="write_off.php?id=<?= $party['id'] ?>" class="btn btn-danger btn-sm"><i class="ri-close-circle-line"></i> Write Off Bad Debt</a>
        <?php endif; ?>
        <a href="list.php<?= empty($party['is_active']) ? '?show=deleted' : '' ?>" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<?php if (get('edit') === '1' && Auth::hasEntityAccess('party', 'write')): ?>
<div class="card">
    <div class="card-header"><h3><i class="ri-edit-line"></i> Edit Party</h3></div>
    <div class="card-body">
        <form method="POST" data-confirm-submit="Save these party changes? The changes will be added to the audit log.">
            <?= csrfField() ?><input type="hidden" name="action" value="update">
            <div class="form-row-3">
                <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" value="<?= clean($party['name']) ?>" required></div>
                <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-control"><?php foreach (['DEBTOR','CREDITOR','BUYER','SELLER'] as $type): ?><option value="<?= $type ?>" <?= $party['type'] === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Status</label><select name="is_active" class="form-control"><option value="1" <?= $party['is_active'] ? 'selected' : '' ?>>Active</option><option value="0" <?= !$party['is_active'] ? 'selected' : '' ?>>Inactive</option></select></div>
            </div>
            <div class="form-row-3">
                <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= clean($party['phone']) ?>" inputmode="numeric" pattern="[0-9]{10}" maxlength="10"></div>
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= clean($party['email']) ?>"></div>
                <div class="form-group"><label class="form-label">PAN / GSTIN</label><input type="text" name="pan_gstin" class="form-control" value="<?= clean($party['pan_gstin']) ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= clean($party['address']) ?></textarea></div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Update Party</button>
            <a href="view.php?id=<?= $party['id'] ?>" class="btn btn-outline">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?= $party['type'] ?></div><div class="stat-label">Type</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatAmount($openOutstanding) ?></div><div class="stat-label">Open Outstanding</div></div>
    <div class="stat-card"><div class="stat-value"><?= clean($party['phone'] ?: 'N/A') ?></div><div class="stat-label">Contact</div></div>
    <div class="stat-card"><div class="stat-value"><?= count($openItems) ?></div><div class="stat-label">Open Items</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($tokenAvailable) ?></div><div class="stat-label">Car Token Held</div></div>
</div>

<?php if (!empty($partyTokens)): ?>
<div class="card">
    <div class="card-header"><h3><i class="ri-hand-coin-line"></i> Car Tokens</h3></div>
    <div class="card-body card-body-flush table-container">
        <table>
            <thead><tr><th>Date</th><th>Car</th><th>Receipt</th><th class="text-right">Received</th><th class="text-right">Available</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($partyTokens as $token): ?><tr>
                <td><?= formatDate($token['received_date']) ?></td>
                <td><a href="../cars/view.php?id=<?= urlencode($token['car_id']) ?>"><?= clean(formatRegistrationNo($token['registration_no'])) ?></a></td>
                <td><a href="../transactions/view.php?id=<?= urlencode($token['journal_entry_id']) ?>"><?= clean($token['reference_no'] ?: 'View') ?></a></td>
                <td class="text-right amount flow-in"><?= formatAmount($token['amount']) ?></td>
                <td class="text-right amount"><?= formatAmount(max(0, floatval($token['amount']) - floatval($token['applied_amount']))) ?></td>
                <td><span class="badge <?= $token['status'] === 'APPLIED' ? 'badge-green' : ($token['status'] === 'REVERSED' ? 'badge-red' : 'badge-blue') ?>"><?= clean($token['status']) ?></span></td>
            </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3>Open Items</h3></div>
    <div class="card-body card-body-flush table-container">
        <table>
            <thead><tr><th>Date / Time</th><th>Ref</th><th>Type</th><th>Narration</th><th class="text-right">Pending</th><th class="text-right">Age</th></tr></thead>
            <tbody>
            <?php foreach ($openItems as $item): $days = max(0, (int) floor((time() - strtotime($item['entry_date'])) / 86400)); ?>
                <tr>
                    <td><?= renderDateTimeStack($item['entry_date'], $item['created_at'] ?? null) ?></td>
                    <td><a class="text-bold" href="../transactions/view.php?id=<?= urlencode($item['journal_entry_id']) ?>"><?= clean($item['reference_no']) ?></a></td>
                    <td><span class="badge badge-blue"><?= clean(transactionTypeLabel($item['transaction_type'], $item)) ?></span></td>
                    <td><?= clean(mb_substr($item['narration'] ?? '', 0, 60)) ?></td>
                    <td class="text-right amount <?= in_array($party['type'], ['DEBTOR', 'BUYER'], true) ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($item['outstanding_amount']) ?></td>
                    <td class="text-right"><?= $days ?> days</td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($openItems)): ?><tr><td colspan="6" class="text-center text-muted empty-table-cell">No open items.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Account Ledger</h3></div>
    <div class="card-body card-body-flush table-container">
        <table>
            <thead><tr><th>Date / Time</th><th>Ref</th><th>Narration</th><th class="text-right debit-amount">Dr</th><th class="text-right credit-amount">Cr</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $l): ?>
            <tr><td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td><td><a class="text-bold" href="../transactions/view.php?id=<?= urlencode($l['entry_id']) ?>"><?= clean($l['reference_no']) ?></a></td><td><?= clean(mb_substr($l['narration']??'',0,50)) ?></td>
                <td class="text-right amount debit-amount"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                <td class="text-right amount credit-amount"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($ledger)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No entries</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
