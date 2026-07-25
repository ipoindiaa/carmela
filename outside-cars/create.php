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
            'source_base_value'=>parseDecimalInput(post('source_base_value')),'expected_sale_value'=>parseDecimalInput(post('expected_sale_value')),'deal_type'=>post('deal_type'),
            'tiranga_profit_pct'=>parseDecimalInput(post('tiranga_profit_pct')),'entity_profit_pct'=>parseDecimalInput(post('entity_profit_pct')),'tiranga_loss_pct'=>parseDecimalInput(post('tiranga_loss_pct')),'entity_loss_pct'=>parseDecimalInput(post('entity_loss_pct')),
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
<div class="form-row-3"><div class="form-group"><label class="form-label">Registration No. *</label><input name="registration_no" class="form-control registration-input" value="<?= clean(post('registration_no')) ?>" placeholder="GJ05AA0001" required></div><div class="form-group"><label class="form-label">Received Date *</label><input type="date" name="received_date" class="form-control" value="<?= clean(post('received_date',date('Y-m-d'))) ?>" required></div><div class="form-group"><label class="form-label">Source Type *</label><select name="source_kind" class="form-control"><option value="OTHER_CAR_MELA">Other Car Mela</option><option value="DEALER">Dealer</option><option value="COMPANY">Company</option><option value="INDIVIDUAL">Individual</option><option value="BROKER">Broker</option><option value="OTHER">Other</option></select></div></div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Make</label><input name="make" class="form-control" value="<?= clean(post('make')) ?>"></div><div class="form-group"><label class="form-label">Model</label><input name="model" class="form-control" value="<?= clean(post('model')) ?>"></div><div class="form-group"><label class="form-label">Year / Color</label><div style="display:flex;gap:8px"><input type="number" name="year" class="form-control" min="1990" max="<?= date('Y')+1 ?>" value="<?= clean(post('year')) ?>"><input name="color" class="form-control" value="<?= clean(post('color')) ?>"></div></div></div>
<div class="form-group"><label class="form-label">Reuse Source Entity</label><select name="source_party_id" class="form-control searchable-select"><option value="">Create a new Source Entity below</option><?php foreach($sources as $source): ?><option value="<?= clean($source['id']) ?>" <?= post('source_party_id')===$source['id']?'selected':'' ?>><?= clean($source['name']) ?><?= $source['phone']?' · '.clean($source['phone']):'' ?></option><?php endforeach; ?></select><div class="form-hint">Every Source Entity uses its creditor current account; Partner Capital is never used.</div></div>
<div class="form-row"><div class="form-group"><label class="form-label">New Source Entity Name</label><input name="new_source_name" class="form-control" value="<?= clean(post('new_source_name')) ?>" placeholder="Only when not selecting above"></div><div class="form-group"><label class="form-label">Phone</label><input name="new_source_phone" class="form-control" value="<?= clean(post('new_source_phone')) ?>"></div></div>
</div></div>
<div class="outside-step card"><div class="card-header"><h3><span class="outside-step-number">2</span> Deal Terms</h3></div><div class="card-body">
<div class="alert alert-info"><i class="ri-calculator-line"></i> A is Source Base Value. K is separate Tiranga commission and is excluded from profit sharing.</div>
<?php $dealType=post('deal_type','HYBRID'); ?>
<div class="form-row-3"><div class="form-group"><label class="form-label">A: Source Base Value *</label><input name="source_base_value" class="form-control currency-input" value="<?= clean(post('source_base_value')) ?>" required></div><div class="form-group"><label class="form-label">Expected Selling Value</label><input name="expected_sale_value" class="form-control currency-input" value="<?= clean(post('expected_sale_value')) ?>"></div><div class="form-group"><label class="form-label">Deal Type</label><select name="deal_type" class="form-control" id="outside-deal-type"><option value="PROFIT_SHARE" <?= $dealType==='PROFIT_SHARE'?'selected':'' ?>>Profit Share</option><option value="FIXED_COMMISSION" <?= $dealType==='FIXED_COMMISSION'?'selected':'' ?>>Fixed Commission</option><option value="HYBRID" <?= $dealType==='HYBRID'?'selected':'' ?>>Hybrid</option></select><div class="form-hint" id="outside-deal-type-hint"></div></div></div>
<div data-deal-section="share" <?= $dealType==='FIXED_COMMISSION'?'hidden':'' ?>><div class="form-row"><div class="form-group"><label class="form-label">Profit Share: Tiranga % / Entity %</label><div style="display:flex;gap:8px"><input name="tiranga_profit_pct" class="form-control" value="<?= clean(post('tiranga_profit_pct','50')) ?>" data-deal-required="share"><input name="entity_profit_pct" class="form-control" value="<?= clean(post('entity_profit_pct','50')) ?>" data-deal-required="share"></div></div><div class="form-group"><label class="form-label">Loss Share: Tiranga % / Entity %</label><div style="display:flex;gap:8px"><input name="tiranga_loss_pct" class="form-control" value="<?= clean(post('tiranga_loss_pct','50')) ?>" data-deal-required="share"><input name="entity_loss_pct" class="form-control" value="<?= clean(post('entity_loss_pct','50')) ?>" data-deal-required="share"></div></div></div></div>
<div data-deal-section="commission" <?= $dealType==='PROFIT_SHARE'?'hidden':'' ?>><div class="form-row"><div class="form-group"><label class="form-label">K Commission Type</label><select name="commission_type" class="form-control" data-deal-required="commission"><option value="FIXED" <?= post('commission_type','FIXED')==='FIXED'?'selected':'' ?>>Fixed amount</option><option value="PERCENT" <?= post('commission_type')==='PERCENT'?'selected':'' ?>>Percentage of vehicle selling price</option></select></div><div class="form-group"><label class="form-label">K Commission Value</label><input name="commission_value" class="form-control currency-input" value="<?= clean(post('commission_value','0')) ?>" min="0.01" data-deal-required="commission"><div class="form-hint">This is Tiranga's separate service fee, outside the profit share.</div></div></div></div>
</div></div>
<div class="form-actions"><button class="btn btn-primary"><i class="ri-save-line"></i> Create Outside Car</button><a href="index.php" class="btn btn-outline">Cancel</a></div>
</form>
<script>
(() => {
    const dealType = document.getElementById('outside-deal-type');
    if (!dealType) return;
    const sections = { share: document.querySelector('[data-deal-section="share"]'), commission: document.querySelector('[data-deal-section="commission"]') };
    const hint = document.getElementById('outside-deal-type-hint');
    const applyDealType = () => {
        const isShare = dealType.value !== 'FIXED_COMMISSION';
        const isCommission = dealType.value !== 'PROFIT_SHARE';
        [['share', isShare], ['commission', isCommission]].forEach(([name, visible]) => {
            const section = sections[name];
            section.hidden = !visible;
            section.querySelectorAll('input, select').forEach((field) => {
                field.disabled = !visible;
                if (field.dataset.dealRequired) field.required = visible;
            });
        });
        hint.textContent = dealType.value === 'PROFIT_SHARE'
            ? 'Only profit and loss sharing applies.'
            : dealType.value === 'FIXED_COMMISSION'
                ? 'Only the agreed K commission applies; the Source Entity receives the remaining margin and loss.'
                : 'Profit/loss sharing and a separate K commission both apply.';
    };
    dealType.addEventListener('change', applyDealType);
    applyDealType();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
