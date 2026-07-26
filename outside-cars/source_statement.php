<?php
$pageTitle='Source Entity Outside Car Statement';
$pageIcon='<i class="ri-building-2-line"></i>';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/accounting_engine.php';
$businessId=Auth::user('business_id');
Auth::requireBookAccess('outside_cars','read');
$engine=new AccountingEngine($businessId,Auth::user('user_id'));
$sourceId=trim((string)get('id',''));
$source=$db->fetch(
    "SELECT se.*,dc.account_id,dc.phone
     FROM source_entities se
     JOIN debtors_creditors dc ON dc.id=se.party_id AND dc.business_id=se.business_id
     WHERE se.id=? AND se.business_id=?",
    [$sourceId,$businessId]
);
if(!$source){setFlash('error','Source Entity not found.');redirect('index.php');}
$cars=$db->fetchAll(
    "SELECT c.id,c.registration_no,c.make,c.model,d.accounting_model
     FROM outside_car_deals d JOIN cars c ON c.id=d.car_id AND c.business_id=d.business_id
     WHERE d.business_id=? AND d.source_entity_id=? ORDER BY c.purchase_date DESC,c.created_at DESC",
    [$businessId,$sourceId]
);
$carFilter=trim((string)get('car_id',''));
$typeFilter=strtoupper(trim((string)get('movement_kind','')));
$from=trim((string)get('from',''));
$to=trim((string)get('to',''));
$conditions=["m.business_id=?","m.source_entity_id=?","m.status='POSTED'"];
$params=[$businessId,$sourceId];
if($carFilter!==''){$conditions[]='m.origin_car_id=?';$params[]=$carFilter;}
if($typeFilter!==''){$conditions[]='m.movement_kind=?';$params[]=$typeFilter;}
if($from!==''){$conditions[]='m.movement_date>=?';$params[]=$from;}
if($to!==''){$conditions[]='m.movement_date<=?';$params[]=$to;}
$movements=$db->fetchAll(
    "SELECT m.*,c.registration_no,a.name gateway_name,je.reference_no,u.full_name created_by_name
     FROM outside_source_movements m
     JOIN cars c ON c.id=m.origin_car_id AND c.business_id=m.business_id
     LEFT JOIN accounts a ON a.id=m.gateway_account_id AND a.business_id=m.business_id
     LEFT JOIN journal_entries je ON je.id=m.journal_entry_id
     LEFT JOIN users u ON u.id=m.created_by AND u.business_id=m.business_id
     WHERE ".implode(' AND ',$conditions)."
     ORDER BY m.movement_date DESC,m.created_at DESC",
    $params
);
$allocConditions=["a.business_id=?","a.source_entity_id=?","a.status='POSTED'"];
$allocParams=[$businessId,$sourceId];
if($carFilter!==''){$allocConditions[]='(a.origin_car_id=? OR a.target_car_id=?)';$allocParams[]=$carFilter;$allocParams[]=$carFilter;}
if($from!==''){$allocConditions[]='a.allocation_date>=?';$allocParams[]=$from;}
if($to!==''){$allocConditions[]='a.allocation_date<=?';$allocParams[]=$to;}
$allocations=$db->fetchAll(
    "SELECT a.*,oc.registration_no origin_registration,tc.registration_no target_registration,je.reference_no
     FROM outside_source_allocations a
     JOIN cars oc ON oc.id=a.origin_car_id AND oc.business_id=a.business_id
     JOIN cars tc ON tc.id=a.target_car_id AND tc.business_id=a.business_id
     LEFT JOIN journal_entries je ON je.id=a.journal_entry_id
     WHERE ".implode(' AND ',$allocConditions)."
     ORDER BY a.allocation_date DESC,a.created_at DESC",
    $allocParams
);
$position=$engine->getOutsideSourcePosition($sourceId);
$carRows=[];
foreach($cars as $car){
    if(($car['accounting_model']??'LEGACY_ABCK')!=='COMMISSION_AGENCY')continue;
    $financial=$engine->getOutsideCarFinancials($car['id']);
    $carRows[]=['car'=>$car,'financial'=>$financial];
}
?>
<div class="page-header"><div><h1><i class="ri-building-2-line"></i> <?= clean($source['display_name']) ?></h1><p class="page-subtitle">Outside Car entitlement, payable, advances, refunds, allocations, and car-wise funds deployed.</p></div><div class="page-actions"><a href="../parties/view.php?id=<?= urlencode($source['party_id']) ?>" class="btn btn-outline">Current Account</a><a href="index.php" class="btn btn-outline">Outside Cars</a></div></div>
<div class="stats-grid"><div class="stat-card"><div class="stat-value"><?= formatAmount($position['entitlement']) ?></div><div class="stat-label">Total Entitlement</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($position['payable']) ?></div><div class="stat-label">Current Payable</div></div><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($position['advance']) ?></div><div class="stat-label">Recoverable Source Advance</div></div><div class="stat-card"><div class="stat-value"><?= formatAmount($position['allocated']) ?></div><div class="stat-label">Payments / Advances Applied</div></div></div>
<form method="get" class="filter-card"><input type="hidden" name="id" value="<?= clean($sourceId) ?>"><div class="form-row-3"><div class="form-group"><label class="form-label">Car</label><select name="car_id" class="form-control searchable-select"><option value="">All Outside Cars</option><?php foreach($cars as $car): ?><option value="<?= clean($car['id']) ?>" <?= $carFilter===$car['id']?'selected':'' ?>><?= clean(formatRegistrationNo($car['registration_no']).' · '.trim($car['make'].' '.$car['model'])) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Movement</label><select name="movement_kind" class="form-control"><option value="">All movements</option><?php foreach(['PAY_OR_ADVANCE'=>'Payment / Advance','SOURCE_REFUND'=>'Source Refund','SOURCE_EXPENSE'=>'Source-borne Expense'] as $value=>$label): ?><option value="<?= $value ?>" <?= $typeFilter===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Date Range</label><div style="display:flex;gap:8px"><input type="date" name="from" class="form-control" value="<?= clean($from) ?>"><input type="date" name="to" class="form-control" value="<?= clean($to) ?>"></div></div></div><div class="form-actions"><button class="btn btn-primary"><i class="ri-filter-3-line"></i> Apply</button><a href="source_statement.php?id=<?= urlencode($sourceId) ?>" class="btn btn-outline">Clear</a></div></form>
<section class="card"><div class="card-header"><h3>Car-wise Position</h3></div><div class="table-container"><table><thead><tr><th>Outside Car</th><th class="text-right">Entitlement</th><th class="text-right">Buyer Due</th><th class="text-right">Commission</th><th class="text-right">Funds Deployed</th></tr></thead><tbody><?php foreach($carRows as $row): $car=$row['car'];$financial=$row['financial']; ?><tr><td><a href="view.php?id=<?= urlencode($car['id']) ?>"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><div class="table-secondary"><?= clean(trim($car['make'].' '.$car['model'])) ?></div></td><td class="text-right"><?= formatAmount($financial['source_car_position']['entitlement']) ?></td><td class="text-right"><?= formatAmount($financial['sale']['buyer_outstanding']??0) ?></td><td class="text-right"><?= formatAmount($financial['commission_income']) ?></td><td class="text-right"><?= formatAmount($financial['funds_deployed']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card"><div class="card-header"><h3>Money Movements</h3></div><div class="table-container"><table><thead><tr><th>Date</th><th>Car / Type</th><th class="text-right">Amount</th><th class="text-right">Payable Applied</th><th class="text-right">Advance Balance</th><th>Account / Journal</th><th>Entered By</th></tr></thead><tbody><?php if(!$movements): ?><tr><td colspan="7" class="text-center text-muted">No movements match these filters.</td></tr><?php endif; ?><?php foreach($movements as $m): ?><tr><td><?= formatDate($m['movement_date']) ?></td><td><a href="view.php?id=<?= urlencode($m['origin_car_id']) ?>"><?= clean(formatRegistrationNo($m['registration_no'])) ?></a><div class="table-secondary"><?= clean(str_replace('_',' ',$m['movement_kind'])) ?></div></td><td class="text-right amount"><?= formatAmount($m['amount']) ?></td><td class="text-right"><?= formatAmount($m['payable_applied']) ?></td><td class="text-right"><?= formatAmount($m['advance_created']-$m['advance_refunded']-$m['allocated_amount']) ?></td><td><?= clean($m['gateway_name']) ?><div><a href="../transactions/view.php?id=<?= urlencode($m['journal_entry_id']) ?>"><?= clean($m['reference_no']) ?></a></div></td><td><?= clean($m['created_by_name']?:'Unknown') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card"><div class="card-header"><h3>FIFO Allocation Trail</h3></div><div class="table-container"><table><thead><tr><th>Date</th><th>Type</th><th>Origin Car</th><th>Adjusted Car</th><th class="text-right">Amount</th><th>Journal</th></tr></thead><tbody><?php if(!$allocations): ?><tr><td colspan="6" class="text-center text-muted">No allocations match these filters.</td></tr><?php endif; ?><?php foreach($allocations as $a): ?><tr><td><?= formatDate($a['allocation_date']) ?></td><td><?= clean(str_replace('_',' ',$a['allocation_kind'])) ?></td><td><a href="view.php?id=<?= urlencode($a['origin_car_id']) ?>"><?= clean($a['origin_registration']) ?></a></td><td><a href="view.php?id=<?= urlencode($a['target_car_id']) ?>"><?= clean($a['target_registration']) ?></a></td><td class="text-right amount"><?= formatAmount($a['amount']) ?></td><td><a href="../transactions/view.php?id=<?= urlencode($a['journal_entry_id']) ?>"><?= clean($a['reference_no']) ?></a></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
