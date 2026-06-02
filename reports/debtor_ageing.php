<?php
$pageTitle = 'Debtor Ageing';
$pageIcon = '<i class="ri-timer-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('debtor_ageing', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$debtors = $engine->getDebtorAgeingReport();

$grandTotal = 0;
?>

<div class="page-header">
    <h1><i class="ri-timer-line"></i> Debtor Ageing Report</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Debtor</th><th>Type</th><th>Phone</th><th class="text-right">Outstanding</th><th class="text-right">Open Items</th><th>Bad Debt</th><th>Oldest Open</th><th class="text-right">Since</th></tr></thead>
        <tbody>
        <?php foreach ($debtors as $d): $grandTotal += $d['outstanding'];
            $daysPending = (int) ($d['days_pending'] ?? 0);
        ?>
        <tr>
            <td><a href="../parties/view.php?id=<?= $d['id'] ?>" class="text-bold"><?= clean($d['name']) ?></a></td>
            <td><span class="badge badge-blue"><?= $d['type'] ?></span></td>
            <td><?= clean($d['phone'] ?: '-') ?></td>
            <td class="text-right amount text-red"><?= formatAmount($d['outstanding']) ?></td>
            <td class="text-right"><?= (int) ($d['open_item_count'] ?? 0) ?></td>
            <td><?= $d['is_bad_debt'] ? '<span class="badge badge-red">Yes</span>' : '-' ?></td>
            <td><?= $d['oldest_open_date'] ? formatDate($d['oldest_open_date']) : '-' ?></td>
            <td class="text-right">
                <span class="badge <?= $daysPending > 90 ? 'badge-red' : ($daysPending > 30 ? 'badge-yellow' : 'badge-green') ?>">
                    <?= $daysPending ?> days
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($debtors)): ?><tr><td colspan="8" class="text-center text-muted" style="padding:40px;">No outstanding debtors.</td></tr><?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total Outstanding</td>
                <td class="text-right amount text-red"><?= formatAmount($grandTotal) ?></td>
                <td colspan="4"></td>
            </tr>
        </tfoot>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
