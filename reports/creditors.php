<?php
$pageTitle = 'Creditors';
$pageIcon = '<i class="ri-hand-coin-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('creditors_report', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$creditors = $engine->getCreditorOutstandingReport();
$search = trim((string) get('q',''));
$typeFilter = strtoupper(trim((string) get('type','')));
$ageFilter = trim((string) get('age',''));
$today = strtotime(date('Y-m-d'));
$creditors = array_values(array_filter($creditors, function($row) use ($search,$typeFilter,$ageFilter,$today) {
    $haystack = strtolower(implode(' ',[$row['name']??'',$row['phone']??'',$row['email']??'',$row['type']??'']));
    if ($search!=='' && !str_contains($haystack,strtolower($search))) return false;
    if ($typeFilter!=='' && strtoupper((string)($row['type']??''))!==$typeFilter) return false;
    $days = !empty($row['oldest_open_date']) ? max(0,(int)floor(($today-strtotime($row['oldest_open_date']))/86400)) : 0;
    return $ageFilter==='' || ($ageFilter==='0_30'&&$days<=30) || ($ageFilter==='31_60'&&$days>=31&&$days<=60) || ($ageFilter==='61_PLUS'&&$days>=61);
}));
$totalOutstanding = 0;
foreach ($creditors as $creditor) {
    $totalOutstanding += floatval($creditor['outstanding'] ?? 0);
}
?>

<div class="page-header">
    <h1><i class="ri-hand-coin-line"></i> Creditors</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar"><form method="get"><div><label class="form-label">Creditor</label><input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Name, phone, email"></div><div><label class="form-label">Type</label><select name="type" class="form-control"><option value="">All types</option><option value="CREDITOR" <?= $typeFilter==='CREDITOR'?'selected':'' ?>>Creditor</option><option value="SELLER" <?= $typeFilter==='SELLER'?'selected':'' ?>>Seller</option></select></div><div><label class="form-label">Oldest pending</label><select name="age" class="form-control"><option value="">Any age</option><option value="0_30" <?= $ageFilter==='0_30'?'selected':'' ?>>0–30 days</option><option value="31_60" <?= $ageFilter==='31_60'?'selected':'' ?>>31–60 days</option><option value="61_PLUS" <?= $ageFilter==='61_PLUS'?'selected':'' ?>>61+ days</option></select></div><button class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button><?php if($search!==''||$typeFilter!==''||$ageFilter!==''): ?><a href="creditors.php" class="btn btn-ghost btn-sm">Clear all</a><?php endif; ?></form></div>

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
                        <td class="text-center"><a href="../parties/view.php?id=<?= $creditor['id'] ?>" class="btn btn-sm btn-outline" title="View creditor" aria-label="View creditor"><i class="ri-eye-line"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
