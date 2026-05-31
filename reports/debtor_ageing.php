<?php
$pageTitle = 'Debtor Ageing';
$pageIcon = '<i class="ri-timer-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('debtor_ageing', 'read');
$businessId = Auth::user('business_id');

$debtors = $db->fetchAll(
    "SELECT dc.*, a.current_balance FROM debtors_creditors dc 
     JOIN accounts a ON a.id = dc.account_id 
     WHERE dc.business_id = ? AND dc.type IN ('DEBTOR','BUYER') AND a.current_balance > 0
     ORDER BY a.current_balance DESC", [$businessId]);

$grandTotal = 0;
?>

<div class="page-header">
    <h1><i class="ri-timer-line"></i> Debtor Ageing Report</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="table-container">
    <table>
        <thead><tr><th>Debtor</th><th>Type</th><th>Phone</th><th class="text-right">Outstanding</th><th>Bad Debt</th><th class="text-right">Since</th></tr></thead>
        <tbody>
        <?php foreach ($debtors as $d): $grandTotal += $d['current_balance'];
            $firstTxn = $db->fetch("SELECT MIN(je.entry_date) as first_date FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id WHERE jl.account_id = ? AND je.status='POSTED'", [$d['account_id']]);
            $daysPending = $firstTxn['first_date'] ? round((time() - strtotime($firstTxn['first_date'])) / 86400) : 0;
        ?>
        <tr>
            <td><a href="../parties/view.php?id=<?= $d['id'] ?>" class="text-bold"><?= clean($d['name']) ?></a></td>
            <td><span class="badge badge-blue"><?= $d['type'] ?></span></td>
            <td><?= clean($d['phone'] ?: '-') ?></td>
            <td class="text-right amount text-red"><?= formatAmount($d['current_balance']) ?></td>
            <td><?= $d['is_bad_debt'] ? '<span class="badge badge-red">Yes</span>' : '-' ?></td>
            <td class="text-right">
                <span class="badge <?= $daysPending > 90 ? 'badge-red' : ($daysPending > 30 ? 'badge-yellow' : 'badge-green') ?>">
                    <?= $daysPending ?> days
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($debtors)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:40px;">No outstanding debtors! 🎉</td></tr><?php endif; ?>
        <tr style="background:var(--bg-secondary);font-weight:700;"><td colspan="3">Total Outstanding</td><td class="text-right amount text-red"><?= formatAmount($grandTotal) ?></td><td colspan="2"></td></tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
