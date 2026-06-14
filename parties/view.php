<?php
$pageTitle = 'Party Statement';
$pageIcon = '<i class="ri-contacts-book-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
$id = get('id');
$party = $db->fetch("SELECT dc.*, a.current_balance, a.current_balance_type FROM debtors_creditors dc LEFT JOIN accounts a ON a.id = dc.account_id WHERE dc.id = ? AND dc.business_id = ?", [$id, $businessId]);
if (!$party) { setFlash('error', 'Party not found.'); redirect('list.php'); }

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
    "SELECT je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status='POSTED' ORDER BY je.entry_date DESC, je.created_at DESC", [$party['account_id']]);
?>

<div class="page-header">
    <h1><i class="ri-contacts-book-line"></i> <?= clean($party['name']) ?></h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php if (Auth::isAdmin() && $debtorOutstanding > 0): ?>
            <a href="write_off.php?id=<?= $party['id'] ?>" class="btn btn-danger btn-sm"><i class="ri-close-circle-line"></i> Write Off Bad Debt</a>
        <?php endif; ?>
        <a href="list.php" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
    <div class="stat-card"><div class="stat-value"><?= $party['type'] ?></div><div class="stat-label">Type</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatAmount($openOutstanding) ?></div><div class="stat-label">Open Outstanding</div></div>
    <div class="stat-card"><div class="stat-value"><?= clean($party['phone'] ?: 'N/A') ?></div><div class="stat-label">Contact</div></div>
    <div class="stat-card"><div class="stat-value"><?= count($openItems) ?></div><div class="stat-label">Open Items</div></div>
</div>

<div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h3>Open Items</h3></div>
    <div class="card-body" style="padding:0;">
        <table>
            <thead><tr><th>Date / Time</th><th>Ref</th><th>Type</th><th>Narration</th><th class="text-right">Pending</th><th class="text-right">Age</th></tr></thead>
            <tbody>
            <?php foreach ($openItems as $item): $days = max(0, (int) floor((time() - strtotime($item['entry_date'])) / 86400)); ?>
                <tr>
                    <td><?= renderDateTimeStack($item['entry_date'], $item['created_at'] ?? null) ?></td>
                    <td><?= clean($item['reference_no']) ?></td>
                    <td><span class="badge badge-blue"><?= TXN_TYPES[$item['transaction_type']] ?? $item['transaction_type'] ?></span></td>
                    <td><?= clean(mb_substr($item['narration'] ?? '', 0, 60)) ?></td>
                    <td class="text-right amount <?= in_array($party['type'], ['DEBTOR', 'BUYER'], true) ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($item['outstanding_amount']) ?></td>
                    <td class="text-right"><?= $days ?> days</td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($openItems)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:30px;">No open items.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Account Ledger</h3></div>
    <div class="card-body" style="padding:0;">
        <table>
            <thead><tr><th>Date / Time</th><th>Ref</th><th>Narration</th><th class="text-right debit-amount">Dr</th><th class="text-right credit-amount">Cr</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $l): ?>
            <tr><td><?= renderDateTimeStack($l['entry_date'], $l['created_at']) ?></td><td><?= $l['reference_no'] ?></td><td><?= clean(mb_substr($l['narration']??'',0,50)) ?></td>
                <td class="text-right amount debit-amount"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                <td class="text-right amount credit-amount"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($ledger)): ?><tr><td colspan="5" class="text-center text-muted" style="padding:30px;">No entries</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
