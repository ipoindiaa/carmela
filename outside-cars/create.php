<?php
$pageTitle = 'Add Outside Car';
$pageIcon = '<i class="ri-add-circle-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId=Auth::user('business_id');
Auth::requireBookAccess('outside_cars','write');
$engine=new AccountingEngine($businessId,Auth::user('user_id'));
$sources=$db->fetchAll("SELECT dc.id,dc.name,dc.phone,se.entity_kind FROM debtors_creditors dc LEFT JOIN source_entities se ON se.party_id=dc.id AND se.business_id=dc.business_id WHERE dc.business_id=? AND dc.is_active=1 AND dc.type IN ('CREDITOR','SELLER') ORDER BY dc.name",[$businessId]);
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verifyCsrf();
    $owns=!$db->inTransaction(); if($owns)$db->beginTransaction();
    try{
        $carId=$engine->createOutsideCar([
            'registration_no'=>post('registration_no'),'received_date'=>post('received_date'),'make'=>post('make'),'model'=>post('model'),'year'=>post('year'),'color'=>post('color'),
            'source_party_id'=>post('source_party_id'),'source_name'=>post('new_source_name'),'source_phone'=>post('new_source_phone'),'source_kind'=>post('source_kind'),'source_notes'=>post('source_notes'),
            'expected_sale_value'=>parseDecimalInput(post('expected_sale_value')),
            'commission_type'=>post('commission_type'),'commission_value'=>parseDecimalInput(post('commission_value')),
        ]);
        if($owns)$db->commit();
        setFlash('success','Outside Car created. No inventory purchase or ordinary sales revenue was posted.');redirect('view.php?id='.urlencode($carId));
    }catch(Throwable $e){if($owns&&$db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
?>
<div class="page-header"><div><h1><i class="ri-add-circle-line"></i> Add Outside Car</h1><p class="page-subtitle">Two simple steps. The car remains entity-owned and starts with no inventory journal or advance posting.</p></div><a href="index.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back</a></div>
<?php if($error): ?><div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($error) ?></div><?php endif; ?>
<form method="post" data-confirm-submit="Create this Outside Car with the entered vehicle and deal terms?">
<?= csrfField() ?>
<div class="outside-step card"><div class="card-header"><h3><span class="outside-step-number">1</span> Vehicle &amp; Source Entity</h3></div><div class="card-body">
<?php $sourceKind=post('source_kind','OTHER_CAR_MELA'); ?>
<div class="form-row-3"><div class="form-group"><label class="form-label">Registration No. *</label><input name="registration_no" class="form-control registration-input" value="<?= clean(post('registration_no')) ?>" placeholder="GJ05AA0001" required></div><div class="form-group"><label class="form-label">Received Date *</label><input type="date" name="received_date" class="form-control" value="<?= clean(post('received_date',date('Y-m-d'))) ?>" required></div><div class="form-group"><label class="form-label">Source Type *</label><select name="source_kind" class="form-control"><?php foreach(['OTHER_CAR_MELA'=>'Other Car Mela','DEALER'=>'Dealer','COMPANY'=>'Company','INDIVIDUAL'=>'Individual','BROKER'=>'Broker','OTHER'=>'Other'] as $value=>$label): ?><option value="<?= $value ?>" <?= $sourceKind===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div></div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Make</label><input name="make" class="form-control" value="<?= clean(post('make')) ?>"></div><div class="form-group"><label class="form-label">Model</label><input name="model" class="form-control" value="<?= clean(post('model')) ?>"></div><div class="form-group"><label class="form-label">Year / Color</label><div style="display:flex;gap:8px"><input type="number" name="year" class="form-control" min="1990" max="<?= date('Y')+1 ?>" value="<?= clean(post('year')) ?>"><input name="color" class="form-control" value="<?= clean(post('color')) ?>"></div></div></div>
<div class="form-group"><label class="form-label">Reuse Source Entity</label><select name="source_party_id" class="form-control searchable-select"><option value="">Create a new Source Entity below</option><?php foreach($sources as $source): ?><option value="<?= clean($source['id']) ?>" <?= post('source_party_id')===$source['id']?'selected':'' ?>><?= clean($source['name']) ?><?= $source['phone']?' · '.clean($source['phone']):'' ?></option><?php endforeach; ?></select><div class="form-hint">Every Source Entity uses its creditor current account; Partner Capital is never used.</div></div>
<div class="form-row"><div class="form-group"><label class="form-label">New Source Entity Name</label><input name="new_source_name" class="form-control" value="<?= clean(post('new_source_name')) ?>" placeholder="Only when not selecting above"></div><div class="form-group"><label class="form-label">Phone</label><input name="new_source_phone" class="form-control" value="<?= clean(post('new_source_phone')) ?>"></div></div>
</div></div>
<div class="outside-step card"><div class="card-header"><h3><span class="outside-step-number">2</span> Deal Terms</h3></div><div class="card-body">
<div class="alert alert-info"><i class="ri-calculator-line"></i> Tiranga does not buy this vehicle. Commission is included in the final customer vehicle price and the remaining principal belongs to the Source Entity.</div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Expected Customer Vehicle Price</label><input name="expected_sale_value" class="form-control currency-input" value="<?= clean(post('expected_sale_value')) ?>"><div class="form-hint">Planning value only; no journal is posted.</div></div><div class="form-group"><label class="form-label">Commission Type *</label><select name="commission_type" class="form-control" required><option value="FIXED" <?= post('commission_type','FIXED')==='FIXED'?'selected':'' ?>>Fixed amount</option><option value="PERCENT" <?= post('commission_type')==='PERCENT'?'selected':'' ?>>Percentage of final vehicle price</option></select></div><div class="form-group"><label class="form-label">Commission Value *</label><input name="commission_value" class="form-control currency-input" value="<?= clean(post('commission_value')) ?>" min="0.01" required><div class="form-hint">This amount is deducted from the Source Entity entitlement; it is not added again to the buyer total.</div></div></div>
</div></div>
<div class="form-actions"><button class="btn btn-primary"><i class="ri-save-line"></i> Create Outside Car</button><a href="index.php" class="btn btn-outline">Cancel</a></div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
