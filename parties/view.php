<?php
$pageTitle = 'Party Statement';
$pageIcon = '<i class="ri-contacts-book-line"></i>';
require_once __DIR__ . '/../includes/header.php';
$businessId = Auth::user('business_id');
$id = get('id');
$party = $db->fetch("SELECT dc.*, a.current_balance, a.current_balance_type FROM debtors_creditors dc LEFT JOIN accounts a ON a.id = dc.account_id WHERE dc.id = ? AND dc.business_id = ?", [$id, $businessId]);
if (!$party) { setFlash('error', 'Party not found.'); redirect('list.php'); }

$ledger = $db->fetchAll(
    "SELECT je.entry_date, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status='POSTED' ORDER BY je.entry_date", [$party['account_id']]);
?>

<div class="page-header">
    <h1><i class="ri-contacts-book-line"></i> <?= clean($party['name']) ?></h1>
    <a href="list.php" class="btn btn-outline btn-sm"><i class="ri-arrow-left-line"></i> Back</a>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(3,1fr);">
    <div class="stat-card"><div class="stat-value"><?= $party['type'] ?></div><div class="stat-label">Type</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatAmount($party['current_balance'] ?? 0) ?></div><div class="stat-label">Outstanding Balance</div></div>
    <div class="stat-card"><div class="stat-value"><?= clean($party['phone'] ?: 'N/A') ?></div><div class="stat-label">Contact</div></div>
</div>

<div class="card">
    <div class="card-header"><h3>Account Ledger</h3></div>
    <div class="card-body" style="padding:0;">
        <table>
            <thead><tr><th>Date</th><th>Ref</th><th>Narration</th><th class="text-right">Dr</th><th class="text-right">Cr</th></tr></thead>
            <tbody>
            <?php $runBal = 0; foreach ($ledger as $l): 
                $runBal += $l['entry_type'] === 'DR' ? $l['amount'] : -$l['amount'];
            ?>
            <tr><td><?= formatDate($l['entry_date']) ?></td><td><?= $l['reference_no'] ?></td><td><?= clean(mb_substr($l['narration']??'',0,50)) ?></td>
                <td class="text-right amount"><?= $l['entry_type']==='DR' ? formatAmount($l['amount']) : '' ?></td>
                <td class="text-right amount"><?= $l['entry_type']==='CR' ? formatAmount($l['amount']) : '' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($ledger)): ?><tr><td colspan="5" class="text-center text-muted" style="padding:30px;">No entries</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
