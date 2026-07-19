<?php
$pageTitle = 'Creditors';
$pageIcon = '<i class="ri-hand-coin-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('creditors_report', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$creditors = $engine->getCreditorOutstandingReport();
$totalOutstanding = 0;
foreach ($creditors as $creditor) {
    $totalOutstanding += floatval($creditor['outstanding'] ?? 0);
}
?>

<div class="page-header">
    <h1><i class="ri-hand-coin-line"></i> Creditors</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="stats-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
    <div class="stat-card"><div class="stat-value"><?= count($creditors) ?></div><div class="stat-label">Active Creditors</div></div>
    <div class="stat-card"><div class="stat-value text-yellow"><?= formatAmount($totalOutstanding) ?></div><div class="stat-label">Total Outstanding</div></div>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Name</th><th>Type</th><th>Phone</th><th>Email</th><th>Oldest Open</th><th class="text-right">Open Items</th><th class="text-right">Outstanding</th><th class="text-center">Action</th></tr></thead>
        <tbody>
            <?php if (empty($creditors)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding: 32px;">No creditors found.</td></tr>
            <?php else: ?>
                <?php foreach ($creditors as $creditor): ?>
                    <tr>
                        <td><a class="text-bold" href="../parties/view.php?id=<?= urlencode($creditor['id']) ?>"><?= clean($creditor['name']) ?></a></td>
                        <td><?= clean($creditor['type']) ?></td>
                        <td><?= clean($creditor['phone'] ?: '-') ?></td>
                        <td><?= clean($creditor['email'] ?: '-') ?></td>
                        <td><?= !empty($creditor['oldest_open_date']) ? formatDate($creditor['oldest_open_date']) : '-' ?></td>
                        <td class="text-right"><?= (int) ($creditor['open_item_count'] ?? 0) ?></td>
                        <td class="text-right amount"><?= formatAmount($creditor['outstanding']) ?></td>
                        <td class="text-center"><a href="../parties/view.php?id=<?= $creditor['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
