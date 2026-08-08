<?php
$pageTitle = 'Action Center';
$pageIcon = '<i class="ri-task-line"></i>';
require_once __DIR__ . '/../includes/header.php';

$businessId = Auth::user('business_id');
Auth::requireBookAccess('car_profitability', 'read');
$items = [];

$commissions = $db->fetchAll(
    "SELECT clc.car_id,clc.approval_date,clc.commission_amount,clc.received_amount,c.registration_no,financier.name financier_name
     FROM car_loan_commissions clc
     JOIN cars c ON c.id=clc.car_id AND c.business_id=clc.business_id
     JOIN debtors_creditors financier ON financier.id=clc.financier_party_id AND financier.business_id=clc.business_id
     WHERE clc.business_id=? AND clc.status IN ('PENDING','PARTIAL')
     ORDER BY clc.approval_date",
    [$businessId]
);
foreach ($commissions as $commission) {
    $pending = max(0, (float) $commission['commission_amount'] - (float) $commission['received_amount']);
    if ($pending <= 0.009) continue;
    $ageDays = max(0, (int) floor((time() - strtotime($commission['approval_date'])) / 86400));
    $items[] = [
        'priority' => $ageDays >= 15 ? 'HIGH' : 'MEDIUM',
        'registration_no' => $commission['registration_no'],
        'detail' => $commission['financier_name'] . ' · ' . $ageDays . ' day(s) pending',
        'amount' => $pending,
        'date' => $commission['approval_date'],
        'link' => '../cars/loan_commission.php?car_id=' . urlencode($commission['car_id']),
    ];
}

$query = trim((string) get('q', ''));
$priority = strtoupper(trim((string) get('priority', '')));
$items = array_values(array_filter($items, static function ($item) use ($query, $priority) {
    $haystack = strtolower(implode(' ', [$item['registration_no'], $item['detail']]));
    return ($query === '' || str_contains($haystack, strtolower($query)))
        && ($priority === '' || $item['priority'] === $priority);
}));
usort($items, static fn($first, $second) => strcmp($first['priority'], $second['priority']) ?: strcmp($first['date'], $second['date']));
$collectTotal = array_sum(array_column($items, 'amount'));
$highCount = count(array_filter($items, static fn($item) => $item['priority'] === 'HIGH'));
?>
<div class="page-header"><div><h1><i class="ri-task-line"></i> Action Center</h1><p class="page-subtitle">One accountable queue for pending loan commission collections.</p></div></div>
<div class="stats-grid"><div class="stat-card"><div class="stat-value"><?= count($items) ?></div><div class="stat-label">Filtered Actions</div></div><div class="stat-card"><div class="stat-value flow-out"><?= $highCount ?></div><div class="stat-label">High Priority</div></div><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($collectTotal) ?></div><div class="stat-label">Money To Collect</div></div></div>
<div class="filter-bar"><form method="get"><div><label class="form-label">Search queue</label><input type="search" name="q" class="form-control" value="<?= clean($query) ?>" placeholder="Car or financier"></div><div><label class="form-label">Priority</label><select name="priority" class="form-control"><option value="">All priorities</option><?php foreach(['HIGH','MEDIUM'] as $value): ?><option value="<?= $value ?>" <?= $priority===$value?'selected':'' ?>><?= clean(ucfirst(strtolower($value))) ?></option><?php endforeach; ?></select></div><button class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button></form></div>
<div class="table-container table-container-fill"><table><thead><tr><th>Priority</th><th>Car</th><th>Required Action</th><th>Accountable Desk</th><th>Date / Age</th><th class="text-right">Amount</th><th></th></tr></thead><tbody>
<?php if(!$items): ?><tr><td colspan="7"><div class="table-filter-empty-state"><i class="ri-checkbox-circle-line"></i><strong>No matching pending actions</strong></div></td></tr><?php endif; ?>
<?php foreach($items as $item): ?><tr><td><span class="badge <?= $item['priority']==='HIGH'?'badge-red':'badge-yellow' ?>"><?= clean(ucfirst(strtolower($item['priority']))) ?></span></td><td><a class="text-bold" href="<?= clean($item['link']) ?>"><?= clean(formatRegistrationNo($item['registration_no'])) ?></a><div class="table-secondary">Loan Commission</div></td><td><strong>Collect loan commission</strong><div class="table-secondary"><?= clean($item['detail']) ?></div></td><td>Finance Desk</td><td><?= formatDate($item['date']) ?></td><td class="text-right amount flow-in"><?= formatAmount($item['amount']) ?></td><td><a href="<?= clean($item['link']) ?>" class="btn btn-sm btn-outline">Open <i class="ri-arrow-right-line"></i></a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
