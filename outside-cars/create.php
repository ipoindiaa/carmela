<?php
$pageTitle = 'Add Outside Car';
$pageIcon = '<i class="ri-add-circle-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';
$businessId=Auth::user('business_id');
Auth::requireBookAccess('outside_cars','write');
$engine=new AccountingEngine($businessId,Auth::user('user_id'));
$sources=$db->fetchAll("SELECT dc.id,dc.name,dc.phone,se.entity_kind FROM debtors_creditors dc LEFT JOIN source_entities se ON se.party_id=dc.id AND se.business_id=dc.business_id WHERE dc.business_id=? AND dc.is_active=1 AND dc.type IN ('CREDITOR','SELLER') ORDER BY dc.name",[$businessId]);
$groups=Auth::getAccessiblePrimaryAccountList($businessId,'write');
$accounts=array_merge($groups['cash_book']??[],$groups['bank_book']??[]);
$accountIds=array_column($accounts,'id');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verifyCsrf();
    $owns=!$db->inTransaction(); if($owns)$db->beginTransaction();
    try{
        $initial=trim((string)post('initial_advance',''))===''?0:parseDecimalInput(post('initial_advance'));
        if($initial>0&&!in_array(post('advance_account'),$accountIds,true)) throw new Exception('Select an accessible Cash or Bank account for the initial advance.');
        $carId=$engine->createOutsideCar([
            'registration_no'=>post('registration_no'),'received_date'=>post('received_date'),'make'=>post('make'),'model'=>post('model'),'year'=>post('year'),'color'=>post('color'),'has_second_key'=>post('has_second_key')==='1',
            'source_party_id'=>post('source_party_id'),'source_name'=>post('new_source_name'),'source_phone'=>post('new_source_phone'),'source_kind'=>post('source_kind'),'source_notes'=>post('source_notes'),
            'source_base_value'=>parseDecimalInput(post('source_base_value')),'expected_sale_value'=>parseDecimalInput(post('expected_sale_value')),'deal_type'=>post('deal_type'),
            'tiranga_profit_pct'=>parseDecimalInput(post('tiranga_profit_pct')),'entity_profit_pct'=>parseDecimalInput(post('entity_profit_pct')),'tiranga_loss_pct'=>parseDecimalInput(post('tiranga_loss_pct')),'entity_loss_pct'=>parseDecimalInput(post('entity_loss_pct')),
            'commission_type'=>post('commission_type'),'commission_value'=>parseDecimalInput(post('commission_value')),'chassis_no'=>post('chassis_no'),'engine_no'=>post('engine_no'),'insurance_details'=>post('insurance_details'),'hypothecation_details'=>post('hypothecation_details'),'second_key_details'=>post('second_key_details'),'notes'=>post('notes'),
        ]);
        if($initial>0)$engine->recordOutsideEntityAdvance($carId,$initial,post('received_date'),post('advance_account'),'PAID_TO_ENTITY','Initial Outside Car base advance');
        if($owns)$db->commit();
        try{uploadEntityAttachments($businessId,'OUTSIDE_CAR',$carId,'INTAKE_DOCUMENT','documents',Auth::user('user_id'),'documents');}catch(Throwable $uploadError){setFlash('warning','Outside Car created, but document upload failed: '.$uploadError->getMessage());redirect('view.php?id='.urlencode($carId));}
        setFlash('success','Outside Car created. No inventory purchase or ordinary sales revenue was posted.');redirect('view.php?id='.urlencode($carId));
    }catch(Throwable $e){if($owns&&$db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
?>
<div class="page-header"><div><h1><i class="ri-add-circle-line"></i> Add Outside Car</h1><p class="page-subtitle">Four connected steps. The car remains entity-owned and starts with no inventory journal.</p></div><a href="index.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back</a></div>
<?php if($error): ?><div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" data-confirm-submit="Create this Outside Car with the entered deal terms and initial advance?">
<?= csrfField() ?>
<div class="outside-step card"><div class="card-header"><h3><span class="outside-step-number">1</span> Vehicle &amp; Source Entity</h3></div><div class="card-body">
<div class="form-row-3"><div class="form-group"><label class="form-label">Registration No. *</label><input name="registration_no" class="form-control registration-input" value="<?= clean(post('registration_no')) ?>" placeholder="GJ05AA0001" required></div><div class="form-group"><label class="form-label">Received Date *</label><input type="date" name="received_date" class="form-control" value="<?= clean(post('received_date',date('Y-m-d'))) ?>" required></div><div class="form-group"><label class="form-label">Source Type *</label><select name="source_kind" class="form-control"><option value="OTHER_CAR_MELA">Other Car Mela</option><option value="DEALER">Dealer</option><option value="COMPANY">Company</option><option value="INDIVIDUAL">Individual</option><option value="BROKER">Broker</option><option value="OTHER">Other</option></select></div></div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Make</label><input name="make" class="form-control" value="<?= clean(post('make')) ?>"></div><div class="form-group"><label class="form-label">Model</label><input name="model" class="form-control" value="<?= clean(post('model')) ?>"></div><div class="form-group"><label class="form-label">Year / Color</label><div style="display:flex;gap:8px"><input type="number" name="year" class="form-control" min="1990" max="<?= date('Y')+1 ?>" value="<?= clean(post('year')) ?>"><input name="color" class="form-control" value="<?= clean(post('color')) ?>"></div></div></div>
<div class="form-group"><label class="form-label">Reuse Source Entity</label><select name="source_party_id" class="form-control searchable-select"><option value="">Create a new Source Entity below</option><?php foreach($sources as $source): ?><option value="<?= clean($source['id']) ?>" <?= post('source_party_id')===$source['id']?'selected':'' ?>><?= clean($source['name']) ?><?= $source['phone']?' · '.clean($source['phone']):'' ?></option><?php endforeach; ?></select><div class="form-hint">Every Source Entity uses its creditor current account; Partner Capital is never used.</div></div>
<div class="form-row"><div class="form-group"><label class="form-label">New Source Entity Name</label><input name="new_source_name" class="form-control" value="<?= clean(post('new_source_name')) ?>" placeholder="Only when not selecting above"></div><div class="form-group"><label class="form-label">Phone</label><input name="new_source_phone" class="form-control" value="<?= clean(post('new_source_phone')) ?>"></div></div>
</div></div>
<div class="outside-step card"><div class="card-header"><h3><span class="outside-step-number">2</span> Deal Terms</h3></div><div class="card-body">
<div class="alert alert-info"><i class="ri-calculator-line"></i> A is Source Base Value. K is separate Tiranga commission and is excluded from profit sharing.</div>
<div class="form-row-3"><div class="form-group"><label class="form-label">A: Source Base Value *</label><input name="source_base_value" class="form-control currency-input" value="<?= clean(post('source_base_value')) ?>" required></div><div class="form-group"><label class="form-label">Expected Selling Value</label><input name="expected_sale_value" class="form-control currency-input" value="<?= clean(post('expected_sale_value')) ?>"></div><div class="form-group"><label class="form-label">Deal Type</label><select name="deal_type" class="form-control"><option value="PROFIT_SHARE">Profit Share</option><option value="FIXED_COMMISSION">Fixed Commission</option><option value="HYBRID" selected>Hybrid</option></select></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Profit Share: Tiranga % / Entity %</label><div style="display:flex;gap:8px"><input name="tiranga_profit_pct" class="form-control" value="<?= clean(post('tiranga_profit_pct','50')) ?>" required><input name="entity_profit_pct" class="form-control" value="<?= clean(post('entity_profit_pct','50')) ?>" required></div></div><div class="form-group"><label class="form-label">Loss Share: Tiranga % / Entity %</label><div style="display:flex;gap:8px"><input name="tiranga_loss_pct" class="form-control" value="<?= clean(post('tiranga_loss_pct','50')) ?>" required><input name="entity_loss_pct" class="form-control" value="<?= clean(post('entity_loss_pct','50')) ?>" required></div></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">K Commission Type</label><select name="commission_type" class="form-control"><option value="FIXED">Fixed amount</option><option value="PERCENT">Percentage reference</option><option value="NONE">None</option></select></div><div class="form-group"><label class="form-label">K Commission Value</label><input name="commission_value" class="form-control currency-input" value="<?= clean(post('commission_value','0')) ?>"></div></div>
</div></div>
<div class="outside-step card"><div class="card-header"><h3><span class="outside-step-number">3</span> Initial Advance</h3></div><div class="card-body"><div class="form-row"><div class="form-group"><label class="form-label">Advance Paid to Entity</label><input name="initial_advance" class="form-control currency-input" value="<?= clean(post('initial_advance')) ?>" placeholder="Optional"></div><div class="form-group"><label class="form-label">Pay From</label><select name="advance_account" class="form-control searchable-select"><option value="">Select only if advance is entered</option><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= clean($account['name']) ?> · <?= formatAmount($account['current_balance']) ?></option><?php endforeach; ?></select></div></div><div class="form-hint">Posting: Dr Outside Car Entity Advances, Cr selected Cash/Bank.</div></div></div>
<div class="outside-step card"><div class="card-header"><h3><span class="outside-step-number">4</span> Documents &amp; Vehicle Identifiers</h3></div><div class="card-body">
<div class="form-row"><div class="form-group"><label class="form-label">Chassis No.</label><input name="chassis_no" class="form-control" value="<?= clean(post('chassis_no')) ?>"></div><div class="form-group"><label class="form-label">Engine No.</label><input name="engine_no" class="form-control" value="<?= clean(post('engine_no')) ?>"></div></div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Second Key</label><select name="has_second_key" class="form-control"><option value="0">No</option><option value="1">Yes</option></select><input name="second_key_details" class="form-control" placeholder="Key notes"></div><div class="form-group"><label class="form-label">Insurance</label><textarea name="insurance_details" class="form-control"><?= clean(post('insurance_details')) ?></textarea></div><div class="form-group"><label class="form-label">Hypothecation</label><textarea name="hypothecation_details" class="form-control"><?= clean(post('hypothecation_details')) ?></textarea></div></div>
<div class="form-group"><label class="form-label">RC, intake photos, authority letter, ID files</label><input type="file" name="documents[]" class="form-control" multiple accept="<?= clean(attachmentAcceptAttribute('documents')) ?>"></div><div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control"><?= clean(post('notes')) ?></textarea></div>
</div></div>
<div class="form-actions"><button class="btn btn-primary"><i class="ri-save-line"></i> Create Outside Car</button><a href="index.php" class="btn btn-outline">Cancel</a></div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
