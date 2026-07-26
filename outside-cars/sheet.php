<?php
$pageTitle='Outside Car A/B/C/K Sheet';
$pageIcon='<i class="ri-printer-line"></i>';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/accounting_engine.php';
$businessId=Auth::user('business_id');Auth::requireBookAccess('outside_cars','read');
$engine=new AccountingEngine($businessId,Auth::user('user_id'));$carId=get('id');
try{$f=$engine->getOutsideCarFinancials($carId);}catch(Throwable $e){setFlash('error',$e->getMessage());redirect('index.php');}
$car=$f['car'];$sale=$f['sale'];$s=$f['settlement'];
if(($car['accounting_model']??'LEGACY_ABCK')==='COMMISSION_AGENCY'){
    require __DIR__.'/agency_sheet.php';
    require_once __DIR__.'/../includes/footer.php';
    exit;
}
$expenses=$db->fetchAll("SELECT expense_date,category,responsibility,actual_amount,approved_recoverable_amount FROM outside_car_expenses WHERE business_id=? AND car_id=? AND status='POSTED' ORDER BY expense_date,created_at",[$businessId,$carId]);
$payments=$db->fetchAll("SELECT payment_date,amount FROM outside_car_buyer_payments WHERE business_id=? AND car_id=? AND status='POSTED' ORDER BY payment_date,created_at",[$businessId,$carId]);
$exact=$sale?round($sale['net_vehicle_value']-$car['source_base_value']-($f['expense']['approved']??0),2):0;
?>
<div class="page-header no-print"><div><h1>A/B/C/K Deal Sheet</h1><p class="page-subtitle">Traditional layout with corrected accounting labels.</p></div><div><button class="btn btn-primary" onclick="printPage()"><i class="ri-printer-line"></i> Print</button> <a href="view.php?id=<?= urlencode($carId) ?>" class="btn btn-outline">Back</a></div></div>
<div class="traditional-deal-sheet">
<header><div><h2><?= clean(formatRegistrationNo($car['registration_no'])) ?></h2><p><?= clean(trim($car['make'].' '.$car['model'])) ?> · <?= clean($car['year']) ?> · <?= clean($car['color']) ?></p></div><div><b>Source Entity</b><br><?= clean($car['source_entity_name']) ?><br><?= clean($car['source_phone']) ?></div></header>
<div class="deal-two-col"><section><h3>Vehicle / Deal</h3><dl><dt>Received</dt><dd><?= formatDate($car['purchase_date']) ?></dd><dt>Chassis</dt><dd><?= clean($car['chassis_no']?:'-') ?></dd><dt>Engine</dt><dd><?= clean($car['engine_no']?:'-') ?></dd><dt>A: Source Base Value</dt><dd><?= formatAmount($car['source_base_value']) ?></dd><dt>C: Vehicle Selling Price</dt><dd><?= $sale?formatAmount($sale['vehicle_sale_price']):'Pending' ?></dd><dt>K: Separate Commission</dt><dd><?= $sale?formatAmount($sale['separate_commission']):formatAmount($car['commission_value']) ?></dd></dl><h3>B1 / B2 Expense Detail</h3><table><thead><tr><th>Date</th><th>Expense</th><th>Bearer</th><th>B1 Actual</th><th>B2 Recovery</th></tr></thead><tbody><?php foreach($expenses as $e): ?><tr><td><?= formatDate($e['expense_date']) ?></td><td><?= clean($e['category']) ?></td><td><?= clean(str_replace('_',' ',$e['responsibility'])) ?></td><td><?= formatAmount($e['actual_amount']) ?></td><td><?= formatAmount($e['approved_recoverable_amount']) ?></td></tr><?php endforeach; ?><tr class="total"><td colspan="3">Total</td><td><?= formatAmount($f['expense']['actual']??0) ?></td><td><?= formatAmount($f['expense']['approved']??0) ?></td></tr></tbody></table></section>
<section><h3>Buyer Payments</h3><?php if($sale): ?><dl><dt>Buyer</dt><dd><?= clean($sale['buyer_name']) ?></dd><dt>Buyer Total</dt><dd><?= formatAmount($sale['buyer_total']) ?></dd><dt>Token Applied</dt><dd><?= formatAmount($sale['token_applied']) ?></dd><dt>Received at Sale</dt><dd><?= formatAmount($sale['received_at_sale']) ?></dd><?php foreach($payments as $p): ?><dt><?= formatDate($p['payment_date']) ?></dt><dd><?= formatAmount($p['amount']) ?></dd><?php endforeach; ?><dt>Buyer Outstanding</dt><dd><?= formatAmount($sale['buyer_outstanding']) ?></dd></dl><?php else: ?><p>Sale not recorded.</p><?php endif; ?><h3>Settlement Calculation</h3><dl><dt>Exact Margin: C − A − B2</dt><dd><?= formatAmount($exact) ?></dd><dt>Tiranga Share</dt><dd><?= $s?formatAmount($s['tiranga_share']):'Pending' ?></dd><dt>Source Entity Share</dt><dd><?= $s?formatAmount($s['entity_share']):'Pending' ?></dd><dt>Tiranga Accounting Income</dt><dd><?= $s?formatAmount($s['tiranga_income']):'Pending' ?></dd><dt class="emphasis">Tiranga Settlement Entitlement</dt><dd class="emphasis"><?= $s?formatAmount($s['tiranga_entitlement']):'Pending' ?></dd><dt>Entity Gross Entitlement</dt><dd><?= $s?formatAmount($s['entity_gross_entitlement']):'Pending' ?></dd><dt>Advances Applied</dt><dd><?= $s?formatAmount($s['advances_applied']):'Pending' ?></dd><dt>Entity Payable</dt><dd><?= $s?formatAmount($s['remaining_entity_payable']):'Pending' ?></dd><dt>Entity Receivable</dt><dd><?= $s?formatAmount($s['remaining_entity_receivable']):'Pending' ?></dd></dl></section></div>
<footer><div>Source Entity Signature</div><div>Tiranga Approval</div><div>Buyer Signature</div></footer></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
