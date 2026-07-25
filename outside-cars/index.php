<?php
$pageTitle = 'Outside Cars';
$pageIcon = '<i class="ri-hand-coin-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
Auth::requireBookAccess('outside_cars', 'read');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$search = trim((string) get('q', ''));
$view = strtoupper(trim((string) get('view', 'ACTIVE')));
$buyerStatus = strtoupper(trim((string) get('buyer_status', '')));
$settlementStatus = strtoupper(trim((string) get('settlement_status', '')));
$rtoStatus = strtoupper(trim((string) get('rto_status', '')));
$physicalStatus = strtoupper(trim((string) get('physical_status', '')));
if (!in_array($view, ['ACTIVE','SETTLED','LEGACY','ALL'], true)) $view = 'ACTIVE';
$where = "c.business_id = ? AND c.ownership_type IN ('OUTSIDE','COMMISSION')";
$params = [$businessId];
if ($view === 'ACTIVE') $where .= " AND (c.ownership_type = 'COMMISSION' OR ocd.settlement_status NOT IN ('FULLY_SETTLED','REVERSED')) AND c.status <> 'CANCELLED'";
elseif ($view === 'SETTLED') $where .= " AND c.ownership_type = 'OUTSIDE' AND ocd.settlement_status = 'FULLY_SETTLED'";
elseif ($view === 'LEGACY') $where .= " AND c.ownership_type = 'COMMISSION'";
if ($search !== '') {
    $where .= " AND (c.registration_no LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR COALESCE(se.display_name,legacy.name) LIKE ? OR c.buyer_name LIKE ?)";
    $needle = '%' . $search . '%';
    array_push($params,$needle,$needle,$needle,$needle,$needle);
}
if ($buyerStatus !== '') { $where .= ' AND ocd.buyer_status = ?'; $params[] = $buyerStatus; }
if ($settlementStatus !== '') { $where .= ' AND ocd.settlement_status = ?'; $params[] = $settlementStatus; }
if ($rtoStatus !== '') { $where .= ' AND ocd.rto_status = ?'; $params[] = $rtoStatus; }
if ($physicalStatus !== '') { $where .= ' AND ocd.physical_status = ?'; $params[] = $physicalStatus; }
$cars = $db->fetchAll(
    "SELECT c.*, ocd.physical_status, ocd.buyer_status, ocd.rto_status, ocd.settlement_status,
            ocd.agreement_status, ocd.source_base_value, se.display_name AS source_name,
            se.party_id AS source_party_id, legacy.name AS legacy_owner_name,
            ocs.buyer_total, ocs.buyer_outstanding, os.tiranga_income, os.tiranga_entitlement,
            os.remaining_entity_payable
     FROM cars c
     LEFT JOIN outside_car_deals ocd ON ocd.business_id=c.business_id AND ocd.car_id=c.id
     LEFT JOIN source_entities se ON se.business_id=c.business_id AND se.id=ocd.source_entity_id
     LEFT JOIN debtors_creditors legacy ON legacy.id=c.commission_owner_party_id AND legacy.business_id=c.business_id
     LEFT JOIN outside_car_sales ocs ON ocs.business_id=c.business_id AND ocs.car_id=c.id AND ocs.status <> 'REVERSED'
     LEFT JOIN outside_car_settlements os ON os.business_id=c.business_id AND os.car_id=c.id AND os.status <> 'REVERSED'
     WHERE $where ORDER BY c.created_at DESC",
    $params
);
$summary = [
    'outside_count' => count(array_filter($cars, static fn($car) => $car['ownership_type'] === 'OUTSIDE')),
    'legacy_count' => count(array_filter($cars, static fn($car) => $car['ownership_type'] === 'COMMISSION')),
    'buyer_due' => array_sum(array_map(static fn($car) => $car['ownership_type'] === 'OUTSIDE' ? (float) ($car['buyer_outstanding'] ?? 0) : 0, $cars)),
    'entity_due' => array_sum(array_map(static fn($car) => $car['ownership_type'] === 'OUTSIDE' ? (float) ($car['remaining_entity_payable'] ?? 0) : 0, $cars)),
    'tiranga_income' => array_sum(array_map(static fn($car) => $car['ownership_type'] === 'OUTSIDE' ? (float) ($car['tiranga_income'] ?? 0) : 0, $cars)),
];
$outsideFilterQuery = ['view' => $view, 'q' => $search, 'buyer_status' => $buyerStatus, 'settlement_status' => $settlementStatus, 'rto_status' => $rtoStatus, 'physical_status' => $physicalStatus];
?>

<div class="page-header">
    <div><h1><i class="ri-hand-coin-line"></i> Outside Cars</h1><p class="page-subtitle">Entity-owned vehicles managed through A/B/C/K settlement and journal-backed accounts.</p></div>
    <?php if (Auth::hasBookAccess('outside_cars','write')): ?><a href="create.php" class="btn btn-primary"><i class="ri-add-line"></i> Add Outside Car</a><?php endif; ?>
</div>

<div class="stats-grid outside-stats-grid">
    <div class="stat-card"><div class="stat-value"><?= (int)($summary['outside_count'] ?? 0) ?></div><div class="stat-label">Outside Cars</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($summary['buyer_due'] ?? 0) ?></div><div class="stat-label">Buyer Outstanding</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($summary['entity_due'] ?? 0) ?></div><div class="stat-label">Source Entity Payable</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($summary['tiranga_income'] ?? 0) ?></div><div class="stat-label">Tiranga Accounting Income</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int)($summary['legacy_count'] ?? 0) ?></div><div class="stat-label">Legacy Commission Cars</div></div>
</div>

<div class="filter-bar">
    <form method="get" class="compact-filter-form">
        <div class="filter-main-field"><label class="form-label">Find car or entity</label><input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Registration, Source Entity, buyer, make"></div>
        <input type="hidden" name="view" value="<?= clean($view) ?>">
        <div><label class="form-label">Buyer</label><select name="buyer_status" class="form-control"><option value="">All</option><?php foreach(['NO_BUYER','PARTLY_PAID','FULLY_PAID','REFUNDED'] as $value): ?><option value="<?= $value ?>" <?= $buyerStatus===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Settlement</label><select name="settlement_status" class="form-control"><option value="">All</option><?php foreach(['TERMS_PENDING','CALCULATION_PENDING','ADVANCE_PAID','SETTLEMENT_APPROVED','PARTLY_SETTLED','FULLY_SETTLED'] as $value): ?><option value="<?= $value ?>" <?= $settlementStatus===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">RTO</label><select name="rto_status" class="form-control"><option value="">All</option><?php foreach(['NOT_STARTED','IN_PROGRESS','COMPLETED'] as $value): ?><option value="<?= $value ?>" <?= $rtoStatus===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Physical</label><select name="physical_status" class="form-control"><option value="">All</option><?php foreach(['RECEIVED','WITH_TIRANGA','RESERVED','DELIVERED'] as $value): ?><option value="<?= $value ?>" <?= $physicalStatus===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button>
        <?php if($search!==''||$buyerStatus!==''||$settlementStatus!==''||$rtoStatus!==''||$physicalStatus!==''): ?><a href="?view=<?= urlencode($view) ?>" class="btn btn-ghost btn-sm">Clear filters</a><?php endif; ?>
    </form>
    <div class="filter-chip-row"><?php foreach (['ACTIVE'=>'Active','SETTLED'=>'Settled','LEGACY'=>'Legacy','ALL'=>'All'] as $key=>$label): $viewQuery=$outsideFilterQuery;$viewQuery['view']=$key; ?><a class="btn btn-sm <?= $view===$key?'btn-primary':'btn-outline' ?>" href="?<?= clean(http_build_query(array_filter($viewQuery,static fn($value)=>$value!==''))) ?>"><?= clean($label) ?></a><?php endforeach; ?></div>
</div>

<div class="table-container table-container-fill"><table data-no-quick-filter><thead><tr><th>Vehicle</th><th>Source Entity</th><th>A: Base</th><th>Buyer</th><th class="text-right">Buyer Due</th><th class="text-right">Entity Due</th><th>Workflow</th><th></th></tr></thead><tbody>
<?php if (!$cars): ?><tr><td colspan="8" class="text-center text-muted" style="padding:40px">No Outside Cars found.</td></tr><?php endif; ?>
<?php foreach ($cars as $car): $legacy=$car['ownership_type']==='COMMISSION'; ?>
<tr>
    <td><a class="text-bold" href="<?= $legacy?'../cars/commission_view.php':'view.php' ?>?id=<?= urlencode($car['id']) ?>"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><div class="table-secondary"><?= clean(trim($car['make'].' '.$car['model'])) ?: '-' ?></div><?php if($legacy): ?><span class="badge badge-yellow">Legacy Commission Car</span><?php endif; ?></td>
    <td><?= clean($car['source_name'] ?: $car['legacy_owner_name'] ?: '-') ?><?php if(!$legacy && $car['source_party_id']): ?><div><a class="table-secondary" href="../parties/view.php?id=<?= urlencode($car['source_party_id']) ?>">Open statement</a></div><?php endif; ?></td>
    <td class="amount"><?= $legacy?'-':formatAmount($car['source_base_value']) ?></td>
    <td><?= clean($car['buyer_name'] ?: '-') ?></td>
    <td class="text-right amount flow-out"><?= !$legacy && $car['buyer_outstanding']>0 ? formatAmount($car['buyer_outstanding']) : '-' ?></td>
    <td class="text-right amount flow-out"><?= !$legacy && $car['remaining_entity_payable']>0 ? formatAmount($car['remaining_entity_payable']) : '-' ?></td>
    <td><?php if($legacy): ?><span class="badge badge-yellow">Historical</span><?php else: ?><span class="badge badge-blue"><?= clean(str_replace('_',' ',$car['settlement_status'] ?: 'PENDING')) ?></span><div class="table-secondary"><?= clean(str_replace('_',' ',$car['buyer_status'] ?: 'NO BUYER')) ?> · <?= clean(str_replace('_',' ',$car['physical_status'] ?: 'RECEIVED')) ?></div><?php endif; ?></td>
    <td><a class="btn btn-sm btn-outline" href="<?= $legacy?'../cars/commission_view.php':'view.php' ?>?id=<?= urlencode($car['id']) ?>"><i class="ri-eye-line"></i></a></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
