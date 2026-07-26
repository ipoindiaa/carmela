<?php
$pageTitle='Outside Cars Report';
$pageIcon='<i class="ri-bar-chart-box-line"></i>';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/accounting_engine.php';
$businessId=Auth::user('business_id');
Auth::requireBookAccess('outside_cars','read');
new AccountingEngine($businessId,Auth::user('user_id'));
$search=trim((string)get('q',''));
$modelFilter=strtoupper(trim((string)get('accounting_model','')));
$sourceFilter=trim((string)get('source_entity_id',''));
$buyerFilter=strtoupper(trim((string)get('buyer_status','')));
$rtoFilter=strtoupper(trim((string)get('rto_status','')));
$commissionFilter=strtoupper(trim((string)get('commission_type','')));
$bearerFilter=strtoupper(trim((string)get('expense_bearer','')));
$entityFilter=strtoupper(trim((string)get('entity_state','')));
$fundsFilter=strtoupper(trim((string)get('funds_state','')));
$agreementFilter=strtoupper(trim((string)get('agreement_state','')));
$deliveryFilter=strtoupper(trim((string)get('delivery_state','')));
$loanFilter=strtoupper(trim((string)get('loan_commission_state','')));
$accountFilter=trim((string)get('account_id',''));
$from=trim((string)get('from',''));
$to=trim((string)get('to',''));
$rows=$db->fetchAll(
    "SELECT c.id,c.registration_no,c.make,c.model,c.purchase_date,se.id source_entity_id,se.display_name source_name,
            d.accounting_model,d.physical_status,d.buyer_status,d.rto_status,d.agreement_status,d.commission_type,d.commission_value,
            s.id sale_id,s.sale_date,s.net_vehicle_value,s.separate_commission,s.source_entity_entitlement,
            s.buyer_rto_charge,s.buyer_total,s.buyer_outstanding,
            COALESCE(x.applied,0) source_applied,
            COALESCE(ex.total,0) expenses_total,COALESCE(ex.source_total,0) source_expenses,
            COALESCE(ex.buyer_total,0) buyer_expenses,COALESCE(ex.tiranga_total,0) tiranga_expenses,
            COALESCE(sm.owner_paid,0) owner_paid,COALESCE(sm.source_refunds,0) source_refunds,
            COALESCE(sm.source_advance,0) source_advance,
            COALESCE(bp.buyer_refunds,0) buyer_refunds,
            COALESCE(r.rto_paid,0) rto_expense,
            COALESCE(lc.earned,0) loan_commission,COALESCE(lc.received,0) loan_commission_received,
            (SELECT COUNT(*) FROM outside_car_agreements a WHERE a.business_id=c.business_id AND a.car_id=c.id) agreement_versions,
            (SELECT COUNT(*) FROM outside_car_deliveries dl WHERE dl.business_id=c.business_id AND dl.car_id=c.id) delivered
     FROM cars c
     JOIN outside_car_deals d ON d.business_id=c.business_id AND d.car_id=c.id
     JOIN source_entities se ON se.business_id=c.business_id AND se.id=d.source_entity_id
     LEFT JOIN outside_car_sales s ON s.business_id=c.business_id AND s.car_id=c.id AND s.status='POSTED'
     LEFT JOIN (SELECT business_id,sale_id,SUM(CASE WHEN status='POSTED' AND allocation_kind IN ('ADVANCE_TO_PAYABLE','PAYMENT_TO_PAYABLE','SOURCE_EXPENSE_TO_PAYABLE') THEN amount ELSE 0 END) applied FROM outside_source_allocations GROUP BY business_id,sale_id) x ON x.business_id=c.business_id AND x.sale_id=s.id
     LEFT JOIN (SELECT business_id,car_id,SUM(CASE WHEN status='POSTED' THEN actual_amount ELSE 0 END) total,
                       SUM(CASE WHEN status='POSTED' AND responsibility='SOURCE_ENTITY' THEN actual_amount ELSE 0 END) source_total,
                       SUM(CASE WHEN status='POSTED' AND responsibility='BUYER' THEN actual_amount ELSE 0 END) buyer_total,
                       SUM(CASE WHEN status='POSTED' AND responsibility='TIRANGA' THEN actual_amount ELSE 0 END) tiranga_total
                FROM outside_car_expenses GROUP BY business_id,car_id) ex ON ex.business_id=c.business_id AND ex.car_id=c.id
     LEFT JOIN (SELECT business_id,origin_car_id,
                       SUM(CASE WHEN status='POSTED' AND movement_kind='PAY_OR_ADVANCE' THEN amount ELSE 0 END) owner_paid,
                       SUM(CASE WHEN status='POSTED' AND movement_kind='SOURCE_REFUND' THEN amount ELSE 0 END) source_refunds,
                       SUM(CASE WHEN status='POSTED' THEN advance_created-advance_refunded-allocated_amount ELSE 0 END) source_advance
                FROM outside_source_movements GROUP BY business_id,origin_car_id) sm ON sm.business_id=c.business_id AND sm.origin_car_id=c.id
     LEFT JOIN (SELECT business_id,car_id,SUM(CASE WHEN status='POSTED' AND payment_kind='REFUND' THEN amount ELSE 0 END) buyer_refunds FROM outside_car_buyer_payments GROUP BY business_id,car_id) bp ON bp.business_id=c.business_id AND bp.car_id=c.id
     LEFT JOIN (SELECT business_id,car_id,SUM(CASE WHEN status='POSTED' AND movement_type='PAY' THEN amount ELSE 0 END) rto_paid FROM outside_car_rto_movements GROUP BY business_id,car_id) r ON r.business_id=c.business_id AND r.car_id=c.id
     LEFT JOIN (SELECT business_id,car_id,SUM(CASE WHEN status<>'REVERSED' THEN commission_amount ELSE 0 END) earned,SUM(CASE WHEN status<>'REVERSED' THEN received_amount ELSE 0 END) received FROM car_loan_commissions GROUP BY business_id,car_id) lc ON lc.business_id=c.business_id AND lc.car_id=c.id
     WHERE c.business_id=? AND c.ownership_type='OUTSIDE'
     ORDER BY c.created_at DESC",
    [$businessId]
);
$rows=array_map(static function($row){
    $row['source_payable']=max(0,floatval($row['source_entity_entitlement'])-floatval($row['source_applied']));
    $row['buyer_collected']=max(0,floatval($row['buyer_total'])-floatval($row['buyer_outstanding']));
    $row['rto_net']=floatval($row['buyer_rto_charge'])-floatval($row['rto_expense']);
    $row['funds_deployed']=round(floatval($row['owner_paid'])+floatval($row['expenses_total'])+floatval($row['rto_expense'])+floatval($row['buyer_refunds'])-floatval($row['buyer_collected'])-floatval($row['source_refunds']),2);
    return $row;
},$rows);
$rows=array_values(array_filter($rows,function($row)use($db,$businessId,$search,$modelFilter,$sourceFilter,$buyerFilter,$rtoFilter,$commissionFilter,$bearerFilter,$entityFilter,$fundsFilter,$agreementFilter,$deliveryFilter,$loanFilter,$accountFilter,$from,$to){
    $haystack=strtolower(implode(' ',[$row['registration_no'],$row['make'],$row['model'],$row['source_name']]));
    if($search!==''&&!str_contains($haystack,strtolower($search)))return false;
    if($modelFilter!==''&&$row['accounting_model']!==$modelFilter)return false;
    if($sourceFilter!==''&&$row['source_entity_id']!==$sourceFilter)return false;
    if($buyerFilter!==''&&$row['buyer_status']!==$buyerFilter)return false;
    if($rtoFilter!==''&&$row['rto_status']!==$rtoFilter)return false;
    if($commissionFilter!==''&&$row['commission_type']!==$commissionFilter)return false;
    if($from!==''&&$row['purchase_date']<$from)return false;
    if($to!==''&&$row['purchase_date']>$to)return false;
    if($bearerFilter!==''){
        $exists=$db->fetch("SELECT id FROM outside_car_expenses WHERE business_id=? AND car_id=? AND status='POSTED' AND responsibility=? LIMIT 1",[$businessId,$row['id'],$bearerFilter]);
        if(!$exists)return false;
    }
    if($accountFilter!==''){
        $exists=$db->fetch(
            "SELECT jl.id FROM journal_lines jl
             JOIN journal_entries je ON je.id=jl.journal_entry_id
             WHERE je.business_id=? AND je.car_id=? AND je.status='POSTED' AND jl.account_id=? LIMIT 1",
            [$businessId,$row['id'],$accountFilter]
        );
        if(!$exists)return false;
    }
    if($fundsFilter==='DEPLOYED'&&$row['funds_deployed']<=0.009)return false;
    if($fundsFilter==='SURPLUS'&&$row['funds_deployed']>=-0.009)return false;
    if($entityFilter==='PAYABLE'&&$row['source_payable']<=0.009)return false;
    if($entityFilter==='ADVANCE'&&$row['source_advance']<=0.009)return false;
    if($entityFilter==='CLEAR'&&($row['source_payable']>0.009||$row['source_advance']>0.009))return false;
    if($agreementFilter==='READY'&&$row['agreement_versions']<1)return false;
    if($agreementFilter==='PENDING'&&$row['agreement_versions']>0)return false;
    if($deliveryFilter==='DELIVERED'&&!$row['delivered'])return false;
    if($deliveryFilter==='PENDING'&&$row['delivered'])return false;
    if($loanFilter==='EARNED'&&$row['loan_commission']<=0.009)return false;
    if($loanFilter==='RECEIVABLE'&&floatval($row['loan_commission'])-floatval($row['loan_commission_received'])<=0.009)return false;
    if($loanFilter==='NONE'&&$row['loan_commission']>0.009)return false;
    return true;
}));
$total=static fn($key)=>array_sum(array_map(static fn($row)=>floatval($row[$key]??0),$rows));
$sources=$db->fetchAll("SELECT id,display_name FROM source_entities WHERE business_id=? AND is_active=1 ORDER BY display_name",[$businessId]);
$accounts=$db->fetchAll("SELECT id,name FROM accounts WHERE business_id=? AND is_active=1 ORDER BY name",[$businessId]);
?>
<div class="page-header"><div><h1><i class="ri-bar-chart-box-line"></i> Outside Cars Report</h1><p class="page-subtitle">Account-wise commission agency, buyer, Source Entity, expense, RTO, loan commission, and funds-deployed positions.</p></div><button onclick="printPage()" class="btn btn-outline"><i class="ri-printer-line"></i> Print</button></div>
<div class="filter-bar"><form method="get" class="compact-filter-form">
<div class="filter-main-field"><label class="form-label">Car / Entity</label><input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Registration, make, model, entity"></div>
<div><label class="form-label">Source Entity</label><select name="source_entity_id" class="form-control searchable-select"><option value="">All</option><?php foreach($sources as $source): ?><option value="<?= clean($source['id']) ?>" <?= $sourceFilter===$source['id']?'selected':'' ?>><?= clean($source['display_name']) ?></option><?php endforeach; ?></select></div>
<div><label class="form-label">Model</label><select name="accounting_model" class="form-control"><option value="">All</option><option value="COMMISSION_AGENCY" <?= $modelFilter==='COMMISSION_AGENCY'?'selected':'' ?>>Commission Agency</option><option value="LEGACY_ABCK" <?= $modelFilter==='LEGACY_ABCK'?'selected':'' ?>>Legacy A/B/C/K</option></select></div>
<div><label class="form-label">Commission</label><select name="commission_type" class="form-control"><option value="">All</option><option value="FIXED" <?= $commissionFilter==='FIXED'?'selected':'' ?>>Fixed</option><option value="PERCENT" <?= $commissionFilter==='PERCENT'?'selected':'' ?>>Percentage</option></select></div>
<div><label class="form-label">Buyer</label><select name="buyer_status" class="form-control"><option value="">All</option><?php foreach(['NO_BUYER','PARTLY_PAID','FULLY_PAID'] as $value): ?><option value="<?= $value ?>" <?= $buyerFilter===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
<div><label class="form-label">Source Balance</label><select name="entity_state" class="form-control"><option value="">All</option><option value="PAYABLE" <?= $entityFilter==='PAYABLE'?'selected':'' ?>>Payable</option><option value="ADVANCE" <?= $entityFilter==='ADVANCE'?'selected':'' ?>>Advance Recovery</option><option value="CLEAR" <?= $entityFilter==='CLEAR'?'selected':'' ?>>Clear</option></select></div>
<div><label class="form-label">Expense Bearer</label><select name="expense_bearer" class="form-control"><option value="">All</option><?php foreach(['SOURCE_ENTITY','BUYER','TIRANGA'] as $value): ?><option value="<?= $value ?>" <?= $bearerFilter===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
<div><label class="form-label">Account</label><select name="account_id" class="form-control searchable-select"><option value="">All journal accounts</option><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $accountFilter===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select></div>
<div><label class="form-label">RTO</label><select name="rto_status" class="form-control"><option value="">All</option><?php foreach(['NOT_STARTED','IN_PROGRESS','COMPLETED'] as $value): ?><option value="<?= $value ?>" <?= $rtoFilter===$value?'selected':'' ?>><?= clean(ucwords(strtolower(str_replace('_',' ',$value)))) ?></option><?php endforeach; ?></select></div>
<div><label class="form-label">Funds</label><select name="funds_state" class="form-control"><option value="">All</option><option value="DEPLOYED" <?= $fundsFilter==='DEPLOYED'?'selected':'' ?>>Tiranga Funds Deployed</option><option value="SURPLUS" <?= $fundsFilter==='SURPLUS'?'selected':'' ?>>Collections Surplus</option></select></div>
<div><label class="form-label">Agreement</label><select name="agreement_state" class="form-control"><option value="">All</option><option value="READY" <?= $agreementFilter==='READY'?'selected':'' ?>>Generated</option><option value="PENDING" <?= $agreementFilter==='PENDING'?'selected':'' ?>>Pending</option></select></div>
<div><label class="form-label">Delivery</label><select name="delivery_state" class="form-control"><option value="">All</option><option value="DELIVERED" <?= $deliveryFilter==='DELIVERED'?'selected':'' ?>>Delivered</option><option value="PENDING" <?= $deliveryFilter==='PENDING'?'selected':'' ?>>Pending</option></select></div>
<div><label class="form-label">Loan Commission</label><select name="loan_commission_state" class="form-control"><option value="">All</option><option value="EARNED" <?= $loanFilter==='EARNED'?'selected':'' ?>>Earned</option><option value="RECEIVABLE" <?= $loanFilter==='RECEIVABLE'?'selected':'' ?>>Receivable</option><option value="NONE" <?= $loanFilter==='NONE'?'selected':'' ?>>None</option></select></div>
<div><label class="form-label">Received From / To</label><div style="display:flex;gap:8px"><input type="date" name="from" class="form-control" value="<?= clean($from) ?>"><input type="date" name="to" class="form-control" value="<?= clean($to) ?>"></div></div>
<button class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button><a class="btn btn-ghost btn-sm" href="outside_cars.php">Clear</a>
</form></div>
<div class="stats-grid"><div class="stat-card"><div class="stat-value"><?= count($rows) ?></div><div class="stat-label">Outside Cars</div></div><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($total('buyer_collected')) ?></div><div class="stat-label">Buyer Collected</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($total('buyer_outstanding')) ?></div><div class="stat-label">Buyer Outstanding</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($total('source_payable')) ?></div><div class="stat-label">Source Entity Payable</div></div><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($total('source_advance')) ?></div><div class="stat-label">Source Advance</div></div><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($total('separate_commission')) ?></div><div class="stat-label">Commission Earned</div></div><div class="stat-card"><div class="stat-value"><?= formatAmount($total('rto_net')) ?></div><div class="stat-label">RTO Net</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($total('funds_deployed')) ?></div><div class="stat-label">Tiranga Funds Deployed</div></div><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($total('loan_commission')) ?></div><div class="stat-label">Loan Commission</div></div></div>
<div class="table-container table-container-fill"><table><thead><tr><th>Car / Entity</th><th>Vehicle / Commission</th><th>Buyer</th><th>Source Entity</th><th>Expenses by Bearer</th><th>RTO</th><th>Loan Commission</th><th>Workflow</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="8" class="text-center text-muted">No Outside Cars match these filters.</td></tr><?php endif; ?>
<?php foreach($rows as $row): ?><tr><td><a class="text-bold" href="../outside-cars/view.php?id=<?= urlencode($row['id']) ?>"><?= clean(formatRegistrationNo($row['registration_no'])) ?></a><div class="table-secondary"><a href="../outside-cars/source_statement.php?id=<?= urlencode($row['source_entity_id']) ?>"><?= clean($row['source_name']) ?></a></div><span class="badge <?= $row['accounting_model']==='COMMISSION_AGENCY'?'badge-blue':'badge-yellow' ?>"><?= clean($row['accounting_model']==='COMMISSION_AGENCY'?'Commission Agency':'Legacy Outside Deal') ?></span></td><td><div>C <?= formatAmount($row['net_vehicle_value']) ?></div><div class="flow-in">K <?= formatAmount($row['separate_commission']) ?></div></td><td><div>Collected <?= formatAmount($row['buyer_collected']) ?></div><div class="flow-out">Due <?= formatAmount($row['buyer_outstanding']) ?></div></td><td><div>Entitlement <?= formatAmount($row['source_entity_entitlement']) ?></div><div>Paid/Applied <?= formatAmount($row['source_applied']) ?></div><div class="flow-out">Payable <?= formatAmount($row['source_payable']) ?></div><div class="flow-in">Advance <?= formatAmount($row['source_advance']) ?></div><div>Funds deployed <?= formatAmount($row['funds_deployed']) ?></div></td><td><div>Source <?= formatAmount($row['source_expenses']) ?></div><div>Buyer <?= formatAmount($row['buyer_expenses']) ?></div><div>Tiranga <?= formatAmount($row['tiranga_expenses']) ?></div></td><td><div>Income <?= formatAmount($row['buyer_rto_charge']) ?></div><div>Expense <?= formatAmount($row['rto_expense']) ?></div><div>Net <?= formatAmount($row['rto_net']) ?></div></td><td><div>Earned <?= formatAmount($row['loan_commission']) ?></div><div>Received <?= formatAmount($row['loan_commission_received']) ?></div></td><td><span class="badge badge-blue"><?= clean(str_replace('_',' ',$row['buyer_status'])) ?></span><div><?= (int)$row['agreement_versions'] ?> agreement version(s)</div><div><?= $row['delivered']?'Delivered':'Not delivered' ?></div></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
