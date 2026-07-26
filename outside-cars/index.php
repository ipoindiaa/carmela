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
$rtoStatus = strtoupper(trim((string) get('rto_status', '')));
$physicalStatus = strtoupper(trim((string) get('physical_status', '')));
$accountingModel = strtoupper(trim((string) get('accounting_model', '')));
$sourceEntityId = trim((string) get('source_entity_id', ''));
$commissionType = strtoupper(trim((string) get('commission_type', '')));
$from = trim((string) get('from', ''));
$to = trim((string) get('to', ''));
if (!in_array($view, ['ACTIVE','BUYER_DUE','SOURCE_ADVANCE','LEGACY','ALL'], true)) $view = 'ACTIVE';
$where = "c.business_id = ? AND c.ownership_type IN ('OUTSIDE','COMMISSION')";
$params = [$businessId];
if ($view === 'ACTIVE') $where .= " AND c.status <> 'CANCELLED'";
elseif ($view === 'BUYER_DUE') $where .= " AND c.ownership_type='OUTSIDE' AND COALESCE(ocs.buyer_outstanding,0)>0.009";
elseif ($view === 'SOURCE_ADVANCE') $where .= " AND c.ownership_type='OUTSIDE' AND COALESCE(esa.advance_balance,0)>0.009";
elseif ($view === 'LEGACY') $where .= " AND c.ownership_type = 'COMMISSION'";
if ($search !== '') {
    $where .= " AND (c.registration_no LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR COALESCE(se.display_name,legacy.name) LIKE ? OR c.buyer_name LIKE ?)";
    $needle = '%' . $search . '%';
    array_push($params,$needle,$needle,$needle,$needle,$needle);
}
if ($buyerStatus !== '') { $where .= ' AND ocd.buyer_status = ?'; $params[] = $buyerStatus; }
if ($rtoStatus !== '') { $where .= ' AND ocd.rto_status = ?'; $params[] = $rtoStatus; }
if ($physicalStatus !== '') { $where .= ' AND ocd.physical_status = ?'; $params[] = $physicalStatus; }
if ($accountingModel !== '') { $where .= ' AND ocd.accounting_model = ?'; $params[] = $accountingModel; }
if ($sourceEntityId !== '') { $where .= ' AND ocd.source_entity_id = ?'; $params[] = $sourceEntityId; }
if ($commissionType !== '') { $where .= ' AND ocd.commission_type = ?'; $params[] = $commissionType; }
if ($from !== '') { $where .= ' AND c.purchase_date >= ?'; $params[] = $from; }
if ($to !== '') { $where .= ' AND c.purchase_date <= ?'; $params[] = $to; }
$cars = $db->fetchAll(
    "SELECT c.*, ocd.physical_status, ocd.buyer_status, ocd.rto_status, ocd.settlement_status,
            ocd.agreement_status,ocd.accounting_model,ocd.source_entity_id,ocd.commission_type,ocd.commission_value,
            se.display_name AS source_name,
            se.party_id AS source_party_id, legacy.name AS legacy_owner_name,
            ocs.buyer_total,ocs.buyer_outstanding,ocs.separate_commission,ocs.source_entity_entitlement,
            GREATEST(0,COALESCE(ocs.source_entity_entitlement,0)-COALESCE(ca.applied,0)) car_entity_due,
            COALESCE(esa.advance_balance,0) entity_advance,
            COALESCE(ep.entity_payable,0) entity_total_payable
     FROM cars c
     LEFT JOIN outside_car_deals ocd ON ocd.business_id=c.business_id AND ocd.car_id=c.id
     LEFT JOIN source_entities se ON se.business_id=c.business_id AND se.id=ocd.source_entity_id
     LEFT JOIN debtors_creditors legacy ON legacy.id=c.commission_owner_party_id AND legacy.business_id=c.business_id
     LEFT JOIN outside_car_sales ocs ON ocs.business_id=c.business_id AND ocs.car_id=c.id AND ocs.status <> 'REVERSED'
     LEFT JOIN (SELECT business_id,sale_id,SUM(CASE WHEN status='POSTED' AND allocation_kind IN ('ADVANCE_TO_PAYABLE','PAYMENT_TO_PAYABLE','SOURCE_EXPENSE_TO_PAYABLE') THEN amount ELSE 0 END) applied FROM outside_source_allocations GROUP BY business_id,sale_id) ca ON ca.business_id=c.business_id AND ca.sale_id=ocs.id
     LEFT JOIN (SELECT business_id,source_entity_id,GREATEST(0,SUM(advance_created-advance_refunded-allocated_amount)) advance_balance FROM outside_source_movements WHERE status='POSTED' GROUP BY business_id,source_entity_id) esa ON esa.business_id=c.business_id AND esa.source_entity_id=ocd.source_entity_id
     LEFT JOIN (SELECT s.business_id,s.source_entity_id,GREATEST(0,SUM(s.source_entity_entitlement-COALESCE(x.applied,0))) entity_payable FROM outside_car_sales s LEFT JOIN (SELECT business_id,sale_id,SUM(CASE WHEN status='POSTED' AND allocation_kind IN ('ADVANCE_TO_PAYABLE','PAYMENT_TO_PAYABLE','SOURCE_EXPENSE_TO_PAYABLE') THEN amount ELSE 0 END) applied FROM outside_source_allocations GROUP BY business_id,sale_id) x ON x.business_id=s.business_id AND x.sale_id=s.id WHERE s.status='POSTED' GROUP BY s.business_id,s.source_entity_id) ep ON ep.business_id=c.business_id AND ep.source_entity_id=ocd.source_entity_id
     WHERE $where ORDER BY c.created_at DESC",
    $params
);
$uniqueEntities=[];
foreach($cars as $car){
    if(!empty($car['source_entity_id']))$uniqueEntities[$car['source_entity_id']]=['payable'=>(float)($car['entity_total_payable']??0),'advance'=>(float)($car['entity_advance']??0)];
}
$summary = [
    'outside_count' => count(array_filter($cars, static fn($car) => $car['ownership_type'] === 'OUTSIDE')),
    'legacy_count' => count(array_filter($cars, static fn($car) => $car['ownership_type'] === 'COMMISSION')),
    'buyer_due' => array_sum(array_map(static fn($car) => $car['ownership_type'] === 'OUTSIDE' ? (float) ($car['buyer_outstanding'] ?? 0) : 0, $cars)),
    'entity_due' => array_sum(array_column($uniqueEntities,'payable')),
    'entity_advance' => array_sum(array_column($uniqueEntities,'advance')),
    'tiranga_income' => array_sum(array_map(static fn($car) => $car['ownership_type'] === 'OUTSIDE' ? (float) ($car['separate_commission'] ?? 0) : 0, $cars)),
];
$sourceEntities=$db->fetchAll("SELECT id,display_name FROM source_entities WHERE business_id=? AND is_active=1 ORDER BY display_name",[$businessId]);
$outsideFilterQuery = ['view'=>$view,'q'=>$search,'buyer_status'=>$buyerStatus,'rto_status'=>$rtoStatus,'physical_status'=>$physicalStatus,'accounting_model'=>$accountingModel,'source_entity_id'=>$sourceEntityId,'commission_type'=>$commissionType,'from'=>$from,'to'=>$to];
?>

<div class="page-header">
    <div><h1><i class="ri-hand-coin-line"></i> Outside Cars</h1><p class="page-subtitle">Source Entity-owned vehicles sold on commission with account-wise buyer, owner, RTO, expense, and advance tracking.</p></div>
    <?php if (Auth::hasBookAccess('outside_cars','write')): ?><a href="create.php" class="btn btn-primary"><i class="ri-add-line"></i> Add Outside Car</a><?php endif; ?>
</div>

<div class="stats-grid outside-stats-grid">
    <div class="stat-card"><div class="stat-value"><?= (int)($summary['outside_count'] ?? 0) ?></div><div class="stat-label">Outside Cars</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($summary['buyer_due'] ?? 0) ?></div><div class="stat-label">Buyer Outstanding</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($summary['entity_due'] ?? 0) ?></div><div class="stat-label">Source Entity Payable</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($summary['entity_advance'] ?? 0) ?></div><div class="stat-label">Source Advance Recoverable</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($summary['tiranga_income'] ?? 0) ?></div><div class="stat-label">Tiranga Accounting Income</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int)($summary['legacy_count'] ?? 0) ?></div><div class="stat-label">Legacy Commission Cars</div></div>
</div>

<div class="filter-bar">
    <form method="get" class="compact-filter-form">
        <div class="filter-main-field"><label class="form-label">Find car or entity</label><input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Registration, Source Entity, buyer, make"></div>
        <input type="hidden" name="view" value="<?= clean($view) ?>">
        <div><label class="form-label">Buyer</label><select name="buyer_status" class="form-control"><option value="">All</option><?php foreach(['NO_BUYER','PARTLY_PAID','FULLY_PAID','REFUNDED'] as $value): ?><option value="<?= $value ?>" <?= $buyerStatus===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Source Entity</label><select name="source_entity_id" class="form-control searchable-select"><option value="">All</option><?php foreach($sourceEntities as $entity): ?><option value="<?= clean($entity['id']) ?>" <?= $sourceEntityId===$entity['id']?'selected':'' ?>><?= clean($entity['display_name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Model</label><select name="accounting_model" class="form-control"><option value="">All</option><option value="COMMISSION_AGENCY" <?= $accountingModel==='COMMISSION_AGENCY'?'selected':'' ?>>Commission Agency</option><option value="LEGACY_ABCK" <?= $accountingModel==='LEGACY_ABCK'?'selected':'' ?>>Legacy A/B/C/K</option></select></div>
        <div><label class="form-label">Commission</label><select name="commission_type" class="form-control"><option value="">All</option><option value="FIXED" <?= $commissionType==='FIXED'?'selected':'' ?>>Fixed</option><option value="PERCENT" <?= $commissionType==='PERCENT'?'selected':'' ?>>Percentage</option></select></div>
        <div><label class="form-label">RTO</label><select name="rto_status" class="form-control"><option value="">All</option><?php foreach(['NOT_STARTED','IN_PROGRESS','COMPLETED'] as $value): ?><option value="<?= $value ?>" <?= $rtoStatus===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Physical</label><select name="physical_status" class="form-control"><option value="">All</option><?php foreach(['RECEIVED','WITH_TIRANGA','RESERVED','DELIVERED'] as $value): ?><option value="<?= $value ?>" <?= $physicalStatus===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Received From / To</label><div style="display:flex;gap:8px"><input type="date" name="from" class="form-control" value="<?= clean($from) ?>"><input type="date" name="to" class="form-control" value="<?= clean($to) ?>"></div></div>
        <button class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button>
        <?php if($search!==''||$buyerStatus!==''||$rtoStatus!==''||$physicalStatus!==''||$accountingModel!==''||$sourceEntityId!==''||$commissionType!==''||$from!==''||$to!==''): ?><a href="?view=<?= urlencode($view) ?>" class="btn btn-ghost btn-sm">Clear filters</a><?php endif; ?>
    </form>
    <div class="filter-chip-row"><?php foreach (['ACTIVE'=>'Active','BUYER_DUE'=>'Buyer Due','SOURCE_ADVANCE'=>'Advance Recovery','LEGACY'=>'Legacy','ALL'=>'All'] as $key=>$label): $viewQuery=$outsideFilterQuery;$viewQuery['view']=$key; ?><a class="btn btn-sm <?= $view===$key?'btn-primary':'btn-outline' ?>" href="?<?= clean(http_build_query(array_filter($viewQuery,static fn($value)=>$value!==''))) ?>"><?= clean($label) ?></a><?php endforeach; ?></div>
</div>

<div class="table-container table-container-fill"><table data-no-quick-filter><thead><tr><th>Vehicle</th><th>Source Entity</th><th>Commission</th><th>Buyer</th><th class="text-right">Buyer Due</th><th class="text-right">Car Entity Due</th><th class="text-right">Entity Advance</th><th>Workflow</th><th></th></tr></thead><tbody>
<?php if (!$cars): ?><tr><td colspan="9" class="text-center text-muted" style="padding:40px">No Outside Cars found.</td></tr><?php endif; ?>
<?php foreach ($cars as $car): $legacy=$car['ownership_type']==='COMMISSION'; ?>
<tr>
    <td><a class="text-bold" href="<?= $legacy?'../cars/commission_view.php':'view.php' ?>?id=<?= urlencode($car['id']) ?>"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><div class="table-secondary"><?= clean(trim($car['make'].' '.$car['model'])) ?: '-' ?></div><?php if($legacy): ?><span class="badge badge-yellow">Legacy Commission Car</span><?php endif; ?></td>
    <td><?= clean($car['source_name'] ?: $car['legacy_owner_name'] ?: '-') ?><?php if(!$legacy && $car['source_party_id']): ?><div><a class="table-secondary" href="source_statement.php?id=<?= urlencode($car['source_entity_id']) ?>">Outside Car statement</a></div><?php endif; ?></td>
    <td class="amount"><?= $legacy?'-':($car['commission_type']==='PERCENT'?clean($car['commission_value']).'%':formatAmount($car['commission_value'])) ?></td>
    <td><?= clean($car['buyer_name'] ?: '-') ?></td>
    <td class="text-right amount flow-out"><?= !$legacy && $car['buyer_outstanding']>0 ? formatAmount($car['buyer_outstanding']) : '-' ?></td>
    <td class="text-right amount flow-out"><?= !$legacy && $car['car_entity_due']>0 ? formatAmount($car['car_entity_due']) : '-' ?></td>
    <td class="text-right amount flow-in"><?= !$legacy && $car['entity_advance']>0 ? formatAmount($car['entity_advance']) : '-' ?></td>
    <td><?php if($legacy): ?><span class="badge badge-yellow">Historical</span><?php else: ?><span class="badge badge-blue">Commission Agency</span><div class="table-secondary"><?= clean(str_replace('_',' ',$car['buyer_status'] ?: 'NO BUYER')) ?> · <?= clean(str_replace('_',' ',$car['physical_status'] ?: 'RECEIVED')) ?></div><?php endif; ?></td>
    <td><a class="btn btn-sm btn-outline" href="<?= $legacy?'../cars/commission_view.php':'view.php' ?>?id=<?= urlencode($car['id']) ?>"><i class="ri-eye-line"></i></a></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
