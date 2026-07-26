<?php
// Included by view.php; that loader closes this workspace with includes/footer.php and the shared selector enhancer.
$actionError='';
$submittedAction=$_SERVER['REQUEST_METHOD']==='POST'?post('action'):'';
$draft=static function($action,$key,$default='')use($submittedAction){
    return $submittedAction===$action?post($key,$default):$default;
};
if($_SERVER['REQUEST_METHOD']==='POST'){
    verifyCsrf();
    $action=post('action');
    try{
        if(!$canWrite)throw new Exception('Outside Cars write permission is required.');
        $accountAction=in_array($action,['expense','sale','buyer_payment','buyer_refund','source_movement','rto'],true);
        $selectedAccount=post($action==='sale'?'receiving_account':($action==='expense'?'payment_account':'account_id'));
        $accountRequired=!($action==='sale'&&parseDecimalInput(post('amount_received_now'))<=0);
        if($accountAction&&$accountRequired&&!in_array($selectedAccount,$accountIds,true)){
            throw new Exception('Select an accessible Cash, Bank, or GST Bank account.');
        }
        if($action==='expense'){
            $engine->recordOutsideCarExpense($carId,[
                'amount'=>parseDecimalInput(post('amount')),
                'gst_amount'=>parseDecimalInput(post('gst_amount')),
                'expense_date'=>post('expense_date'),
                'category'=>post('category'),
                'vendor_name'=>post('vendor_name'),
                'voucher_no'=>post('voucher_no'),
                'responsibility'=>post('responsibility'),
                'expense_account_id'=>post('expense_account_id'),
                'payment_account'=>post('payment_account'),
                'narration'=>post('narration'),
            ]);
        }elseif($action==='sale'){
            $engine->recordOutsideCarSale($carId,[
                'sale_date'=>post('sale_date'),
                'vehicle_sale_price'=>parseDecimalInput(post('vehicle_sale_price')),
                'discount_amount'=>parseDecimalInput(post('discount_amount')),
                'buyer_rto_charge'=>parseDecimalInput(post('buyer_rto_charge')),
                'buyer_party_id'=>post('buyer_party_id'),
                'buyer_name'=>post('new_buyer_name'),
                'buyer_phone'=>post('new_buyer_phone'),
                'amount_received_now'=>post('amount_received_now'),
                'receiving_account'=>post('receiving_account'),
                'narration'=>post('narration'),
            ]);
        }elseif($action==='buyer_payment'){
            $engine->recordOutsideBuyerPayment($carId,parseDecimalInput(post('amount')),post('payment_date'),post('account_id'),post('narration'));
        }elseif($action==='buyer_refund'){
            $engine->recordOutsideBuyerRefund($carId,parseDecimalInput(post('amount')),post('payment_date'),post('account_id'),post('narration'));
        }elseif($action==='buyer_bad_debt'){
            $engine->recordOutsideBuyerBadDebt($carId,parseDecimalInput(post('amount')),post('payment_date'),post('narration'));
        }elseif($action==='cancel_sale'){
            $engine->cancelOutsideAgencySale($carId,post('cancellation_reason'));
        }elseif($action==='source_movement'){
            $engine->recordOutsideSourceMovement($carId,parseDecimalInput(post('amount')),post('movement_date'),post('account_id'),post('movement_kind'),post('narration'));
        }elseif($action==='rto'){
            $engine->recordOutsideRtoMovement($carId,'PAY',parseDecimalInput(post('amount')),post('movement_date'),post('account_id'),post('narration'),parseDecimalInput(post('gst_amount')));
        }elseif($action==='complete_rto'){
            $engine->completeOutsideRto($carId,post('completion_reason'));
        }elseif($action==='agreement'){
            $engine->generateOutsideCarAgreement($carId,[
                'buyer_address'=>post('buyer_address'),
                'delivery_terms'=>post('delivery_terms'),
                'witness_1'=>post('witness_1'),
                'witness_2'=>post('witness_2'),
            ]);
        }elseif($action==='sign_agreement'){
            $agreementId=post('agreement_id');
            $count=uploadEntityAttachments($businessId,'OUTSIDE_CAR_AGREEMENT',$agreementId,'SIGNED_COPY','signed_files',Auth::user('user_id'),'documents');
            if($count<1)throw new Exception('Attach at least one signed scan or photo.');
            $engine->markOutsideCarAgreementSigned($agreementId);
        }elseif($action==='delivery'){
            $engine->recordOutsideCarDelivery($carId,[
                'delivery_date'=>post('delivery_date'),
                'delivery_time'=>post('delivery_time'),
                'odometer'=>post('odometer'),
                'fuel_level'=>post('fuel_level'),
                'keys_handed_over'=>post('keys_handed_over'),
                'documents_handed_over'=>post('documents_handed_over'),
                'receiver_name'=>post('receiver_name'),
                'override_used'=>post('override_used')==='1',
                'override_reason'=>post('override_reason'),
                'promised_payment_date'=>post('promised_payment_date'),
            ]);
        }else{
            throw new Exception('Unknown Outside Car action.');
        }
        setFlash('success','Outside Car entry posted to the linked accounts and audit history.');
        redirect('view.php?id='.urlencode($carId).'#'.urlencode($action));
    }catch(Throwable $e){
        $actionError=$e->getMessage();
    }
}

$f=$engine->getOutsideCarFinancials($carId);
$car=$f['car'];
$sale=$f['sale'];
$expenseAccounts=$db->fetchAll(
    "SELECT id,name,code FROM accounts
     WHERE business_id=? AND group_name='EXPENSE' AND is_active=1
     ORDER BY name",
    [$businessId]
);
$expenses=$db->fetchAll(
    "SELECT e.*,je.reference_no,u.full_name created_by_name,a.name expense_account_name,p.name payment_account_name
     FROM outside_car_expenses e
     LEFT JOIN journal_entries je ON je.id=e.journal_entry_id
     LEFT JOIN users u ON u.id=e.created_by AND u.business_id=e.business_id
     LEFT JOIN accounts a ON a.id=e.expense_account_id AND a.business_id=e.business_id
     LEFT JOIN accounts p ON p.id=e.payment_account_id AND p.business_id=e.business_id
     WHERE e.business_id=? AND e.car_id=? ORDER BY e.expense_date DESC,e.created_at DESC",
    [$businessId,$carId]
);
$buyerPayments=$db->fetchAll(
    "SELECT p.*,je.reference_no,u.full_name created_by_name
     FROM outside_car_buyer_payments p
     LEFT JOIN journal_entries je ON je.id=p.journal_entry_id
     LEFT JOIN users u ON u.id=p.created_by AND u.business_id=p.business_id
     WHERE p.business_id=? AND p.car_id=? ORDER BY p.payment_date DESC,p.created_at DESC",
    [$businessId,$carId]
);
$sourceMovements=$db->fetchAll(
    "SELECT m.*,je.reference_no,u.full_name created_by_name,a.name gateway_name
     FROM outside_source_movements m
     LEFT JOIN journal_entries je ON je.id=m.journal_entry_id
     LEFT JOIN users u ON u.id=m.created_by AND u.business_id=m.business_id
     LEFT JOIN accounts a ON a.id=m.gateway_account_id AND a.business_id=m.business_id
     WHERE m.business_id=? AND m.source_entity_id=? AND m.status='POSTED'
     ORDER BY m.movement_date DESC,m.created_at DESC",
    [$businessId,$car['source_entity_id']]
);
$sourceAllocations=$db->fetchAll(
    "SELECT a.*,oc.registration_no origin_registration,tc.registration_no target_registration,je.reference_no
     FROM outside_source_allocations a
     JOIN cars oc ON oc.id=a.origin_car_id AND oc.business_id=a.business_id
     JOIN cars tc ON tc.id=a.target_car_id AND tc.business_id=a.business_id
     LEFT JOIN journal_entries je ON je.id=a.journal_entry_id
     WHERE a.business_id=? AND a.source_entity_id=? AND a.status='POSTED'
       AND (a.origin_car_id=? OR a.target_car_id=?)
     ORDER BY a.allocation_date DESC,a.created_at DESC",
    [$businessId,$car['source_entity_id'],$carId,$carId]
);
$rtoMoves=$db->fetchAll(
    "SELECT r.*,je.reference_no,u.full_name created_by_name
     FROM outside_car_rto_movements r
     LEFT JOIN journal_entries je ON je.id=r.journal_entry_id
     LEFT JOIN users u ON u.id=r.created_by AND u.business_id=r.business_id
     WHERE r.business_id=? AND r.car_id=? AND r.status='POSTED'
     ORDER BY r.movement_date DESC,r.created_at DESC",
    [$businessId,$carId]
);
$agreements=$db->fetchAll(
    "SELECT a.*,u.full_name created_by_name
     FROM outside_car_agreements a
     LEFT JOIN users u ON u.id=a.created_by AND u.business_id=a.business_id
     WHERE a.business_id=? AND a.car_id=? ORDER BY a.version_no DESC",
    [$businessId,$carId]
);
$delivery=$db->fetch(
    "SELECT d.*,u.full_name recorded_by_name
     FROM outside_car_deliveries d
     LEFT JOIN users u ON u.id=d.recorded_by AND u.business_id=d.business_id
     WHERE d.business_id=? AND d.car_id=?",
    [$businessId,$carId]
);
$entries=$db->fetchAll(
    "SELECT je.id,je.reference_no,je.entry_date,je.created_at,je.narration,je.transaction_type,
            je.entry_type_id,je.entry_amount,je.status,je.is_reversal,u.full_name created_by_name
     FROM journal_entries je
     LEFT JOIN users u ON u.id=je.created_by AND u.business_id=je.business_id
     WHERE je.business_id=? AND je.car_id=?
     ORDER BY je.entry_date DESC,je.created_at DESC",
    [$businessId,$carId]
);
$buyerNetPaid=$sale?round(floatval($sale['buyer_total'])-floatval($sale['buyer_outstanding']),2):0;
$latestAgreement=$agreements[0]??null;
$commissionLabel=$car['commission_type']==='PERCENT'
    ? rtrim(rtrim(number_format(floatval($car['commission_value']),4,'.',''),'0'),'.').'% of C'
    : formatAmount($car['commission_value']);
?>
<div class="page-header"><div><h1><i class="ri-car-line"></i> <?= clean(formatRegistrationNo($car['registration_no'])) ?></h1><p class="page-subtitle"><?= clean(trim($car['make'].' '.$car['model'])) ?> · Source Entity: <a href="source_statement.php?id=<?= urlencode($car['source_entity_id']) ?>"><?= clean($car['source_entity_name']) ?></a> · <span class="badge badge-blue">Commission Agency</span></p></div><div class="page-actions"><?php if($sale): ?><a href="../cars/loan_commission.php?car_id=<?= urlencode($carId) ?>" class="btn btn-outline"><i class="ri-bank-card-line"></i> Loan Commission</a><?php endif; ?><a href="sheet.php?id=<?= urlencode($carId) ?>" class="btn btn-outline"><i class="ri-printer-line"></i> Deal Statement</a><a href="index.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back</a></div></div>
<?php if($actionError): ?><div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($actionError) ?></div><?php endif; ?>
<div class="outside-status-strip"><span><b>Physical</b> <?= clean(str_replace('_',' ',$car['physical_status'])) ?></span><span><b>Buyer</b> <?= clean(str_replace('_',' ',$car['buyer_status'])) ?></span><span><b>RTO</b> <?= clean(str_replace('_',' ',$car['rto_status'])) ?></span><span><b>Agreement</b> <?= clean(str_replace('_',' ',$car['agreement_status'])) ?></span></div>
<nav class="outside-workspace-nav" aria-label="Outside Car sections"><?php foreach(['overview'=>'Overview','buyer'=>'Buyer & Payments','expenses'=>'Expenses','source'=>'Source Entity Account','rto'=>'RTO','agreement'=>'Agreement','delivery'=>'Delivery','audit'=>'Audit'] as $anchor=>$label): ?><a href="#<?= $anchor ?>"><?= clean($label) ?></a><?php endforeach; ?></nav>

<section id="overview" class="card"><div class="card-header"><h3><i class="ri-dashboard-line"></i> Account Overview</h3></div><div class="card-body">
<div class="stats-grid outside-calc-stats"><div class="stat-card"><div class="stat-value"><?= $sale?formatAmount($sale['net_vehicle_value']):'Pending' ?></div><div class="stat-label">C · Final Vehicle Price</div></div><div class="stat-card"><div class="stat-value flow-in"><?= $sale?formatAmount($f['commission_income']):clean($commissionLabel) ?></div><div class="stat-label">K · Tiranga Commission (included in C)</div></div><div class="stat-card"><div class="stat-value"><?= $sale?formatAmount($sale['source_entity_entitlement']):'Pending' ?></div><div class="stat-label">Source Entity Entitlement</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($sale['buyer_outstanding']??0) ?></div><div class="stat-label">Buyer Outstanding</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($f['funds_deployed']) ?></div><div class="stat-label">Tiranga Funds Deployed</div></div></div>
<div class="alert alert-info"><i class="ri-links-line"></i> Vehicle principal is pass-through money. Only commission, RTO income, loan commission, and explicit Tiranga income enter the company P&amp;L.</div>
</div></section>

<section id="buyer" class="card"><div class="card-header"><h3><i class="ri-user-received-line"></i> Buyer &amp; Payments</h3></div><div class="card-body">
<?php if(!$sale&&$canWrite): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="sale">
<div class="form-row-3"><div class="form-group"><label class="form-label">Buyer</label><select name="buyer_party_id" class="form-control searchable-select"><option value="">Create buyer below</option><?php foreach($buyers as $buyer): ?><option value="<?= clean($buyer['id']) ?>" <?= $draft('sale','buyer_party_id')===$buyer['id']?'selected':'' ?>><?= clean($buyer['name']) ?><?= $buyer['phone']?' · '.clean($buyer['phone']):'' ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">New Buyer Name</label><input name="new_buyer_name" class="form-control" value="<?= clean($draft('sale','new_buyer_name')) ?>"></div><div class="form-group"><label class="form-label">New Buyer Phone</label><input name="new_buyer_phone" class="form-control" value="<?= clean($draft('sale','new_buyer_phone')) ?>"></div></div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Customer Vehicle Price *</label><input name="vehicle_sale_price" class="form-control currency-input" value="<?= clean($draft('sale','vehicle_sale_price')) ?>" required><div class="form-hint">Commission is already included in this price.</div></div><div class="form-group"><label class="form-label">Discount</label><input name="discount_amount" class="form-control currency-input" value="<?= clean($draft('sale','discount_amount','0')) ?>"></div><div class="form-group"><label class="form-label">Buyer RTO Charge</label><input name="buyer_rto_charge" class="form-control currency-input" value="<?= clean($draft('sale','buyer_rto_charge','0')) ?>"></div></div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Sale Date *</label><input type="date" name="sale_date" class="form-control" value="<?= clean($draft('sale','sale_date',date('Y-m-d'))) ?>" required></div><div class="form-group"><label class="form-label">Amount Received Now</label><input name="amount_received_now" class="form-control currency-input" value="<?= clean($draft('sale','amount_received_now','0')) ?>"></div><div class="form-group"><label class="form-label">Receive Into</label><select name="receiving_account" class="form-control searchable-select"><option value="">Required only when receiving now</option><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('sale','receiving_account')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select></div></div>
<input name="narration" class="form-control" value="<?= clean($draft('sale','narration','Outside Car commission sale - '.$car['registration_no'])) ?>"><button class="btn btn-primary" style="margin-top:12px"><i class="ri-money-rupee-circle-line"></i> Record Sale &amp; Entitlement</button></form>
<?php elseif($sale): ?><div class="stats-grid outside-calc-stats"><div class="stat-card"><div class="stat-value"><?= formatAmount($sale['buyer_total']) ?></div><div class="stat-label">Buyer Total (C + RTO + buyer-borne expenses)</div></div><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($f['buyer_collected']) ?></div><div class="stat-label">Collected</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($sale['buyer_outstanding']) ?></div><div class="stat-label">Outstanding</div></div></div>
<?php if($canWrite&&$sale['buyer_outstanding']>0.009): ?><form method="post" class="inline-action-form"><?= csrfField() ?><input type="hidden" name="action" value="buyer_payment"><input type="date" name="payment_date" class="form-control" value="<?= clean($draft('buyer_payment','payment_date',date('Y-m-d'))) ?>" required><input name="amount" class="form-control currency-input" value="<?= clean($draft('buyer_payment','amount',$sale['buyer_outstanding'])) ?>" required><select name="account_id" class="form-control searchable-select" required><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('buyer_payment','account_id')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select><input name="narration" class="form-control" value="<?= clean($draft('buyer_payment','narration','Buyer installment - '.$car['registration_no'])) ?>"><button class="btn btn-primary">Receive Installment</button></form><?php endif; ?>
<?php if($canWrite&&$buyerNetPaid>0.009): ?><form method="post" class="inline-action-form" data-confirm-submit="Refund this buyer amount and reopen the buyer outstanding?"><?= csrfField() ?><input type="hidden" name="action" value="buyer_refund"><input type="date" name="payment_date" class="form-control" value="<?= clean($draft('buyer_refund','payment_date',date('Y-m-d'))) ?>" required><input name="amount" class="form-control currency-input" value="<?= clean($draft('buyer_refund','amount')) ?>" placeholder="Refund amount" max="<?= clean($buyerNetPaid) ?>" required><select name="account_id" class="form-control searchable-select" required><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('buyer_refund','account_id')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select><input name="narration" class="form-control" value="<?= clean($draft('buyer_refund','narration','Buyer refund - '.$car['registration_no'])) ?>"><button class="btn btn-outline">Refund Buyer</button></form><?php endif; ?>
<?php if($canWrite&&$sale['buyer_outstanding']>0.009): ?><form method="post" class="inline-action-form" data-confirm-submit="Write off this buyer balance as a car-wise bad debt? This posts a business loss."><?= csrfField() ?><input type="hidden" name="action" value="buyer_bad_debt"><input type="date" name="payment_date" class="form-control" value="<?= clean($draft('buyer_bad_debt','payment_date',date('Y-m-d'))) ?>" required><input name="amount" class="form-control currency-input" value="<?= clean($draft('buyer_bad_debt','amount')) ?>" placeholder="Write-off amount" max="<?= clean($sale['buyer_outstanding']) ?>" required><input name="narration" class="form-control" value="<?= clean($draft('buyer_bad_debt','narration')) ?>" placeholder="Detailed authorization reason" required minlength="10"><button class="btn btn-outline text-red">Write Off Bad Debt</button></form><?php endif; ?>
<?php if($canWrite): ?><form method="post" class="inline-action-form" data-confirm-submit="Cancel this sale? Sale-time buyer money is reversed through its original accounts, and paid owner money remains recoverable from the Source Entity."><?= csrfField() ?><input type="hidden" name="action" value="cancel_sale"><input name="cancellation_reason" class="form-control" value="<?= clean($draft('cancel_sale','cancellation_reason')) ?>" placeholder="Detailed cancellation reason" required minlength="10"><button class="btn btn-outline text-red">Cancel Sale</button></form><?php endif; ?>
<div class="table-container"><table><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th class="text-right">Amount</th><th>Entered By</th></tr></thead><tbody><?php if(!$buyerPayments): ?><tr><td colspan="5" class="text-center text-muted">No installments or refunds after the sale.</td></tr><?php endif; ?><?php foreach($buyerPayments as $p): ?><tr><td><?= formatDate($p['payment_date']) ?></td><td><?= clean(($p['payment_kind']??'RECEIPT')==='REFUND'?'Refund':'Receipt') ?></td><td><a href="../transactions/view.php?id=<?= urlencode($p['journal_entry_id']) ?>"><?= clean($p['reference_no']) ?></a></td><td class="text-right amount"><?= ($p['payment_kind']??'RECEIPT')==='REFUND'?'− ':'+ ' ?><?= formatAmount($p['amount']) ?></td><td><?= clean($p['created_by_name']?:'Unknown') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></section>

<section id="expenses" class="card"><div class="card-header"><h3><i class="ri-tools-line"></i> Outside Car Expenses</h3></div><div class="card-body">
<?php if($canWrite): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="expense">
<div class="form-row-3"><div class="form-group"><label class="form-label">Date *</label><input type="date" name="expense_date" class="form-control" value="<?= clean($draft('expense','expense_date',date('Y-m-d'))) ?>" required></div><div class="form-group"><label class="form-label">Expense Category *</label><input name="category" class="form-control" value="<?= clean($draft('expense','category')) ?>" placeholder="Repair, cleaning, fuel..." required></div><div class="form-group"><label class="form-label">Vendor</label><input name="vendor_name" class="form-control" value="<?= clean($draft('expense','vendor_name')) ?>"></div></div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Gross Amount *</label><input name="amount" class="form-control currency-input" value="<?= clean($draft('expense','amount')) ?>" required></div><div class="form-group" data-tiranga-expense-field><label class="form-label">Eligible GST Input</label><input name="gst_amount" class="form-control currency-input" value="<?= clean($draft('expense','gst_amount','0')) ?>"></div><div class="form-group"><label class="form-label">Mandatory Bearer *</label><select name="responsibility" id="outside-expense-bearer" class="form-control" required><?php foreach(['SOURCE_ENTITY'=>'Source Entity','BUYER'=>'Buyer','TIRANGA'=>'Tiranga'] as $value=>$label): ?><option value="<?= $value ?>" <?= $draft('expense','responsibility','SOURCE_ENTITY')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><div class="form-hint" id="outside-expense-bearer-hint"></div></div></div>
<div class="form-row-3"><div class="form-group" data-tiranga-expense-field><label class="form-label">Expense Ledger *</label><select name="expense_account_id" class="form-control searchable-select"><option value="">Select Tiranga expense ledger</option><?php foreach($expenseAccounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('expense','expense_account_id')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Pay From *</label><select name="payment_account" class="form-control searchable-select" required><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('expense','payment_account')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Voucher / Bill No.</label><input name="voucher_no" class="form-control" value="<?= clean($draft('expense','voucher_no')) ?>"></div></div>
<input name="narration" class="form-control" value="<?= clean($draft('expense','narration')) ?>" placeholder="Narration and supporting reference"><button class="btn btn-primary" style="margin-top:12px">Record Expense</button></form><?php endif; ?>
<div class="table-container"><table><thead><tr><th>Date</th><th>Category / Vendor</th><th>Bearer</th><th>Ledger / Paid From</th><th class="text-right">GST</th><th class="text-right">Amount</th><th>Journal</th></tr></thead><tbody><?php if(!$expenses): ?><tr><td colspan="7" class="text-center text-muted">No Outside Car expenses recorded.</td></tr><?php endif; ?><?php foreach($expenses as $e): ?><tr><td><?= formatDate($e['expense_date']) ?></td><td><?= clean($e['category']) ?><div class="table-secondary"><?= clean($e['vendor_name']?:$e['voucher_no']) ?></div></td><td><?= clean(str_replace('_',' ',$e['responsibility'])) ?></td><td><?= clean($e['expense_account_name']?:'Party account') ?><div class="table-secondary"><?= clean($e['payment_account_name']) ?></div></td><td class="text-right"><?= formatAmount($e['gst_amount']) ?></td><td class="text-right amount"><?= formatAmount($e['actual_amount']) ?></td><td><a href="../transactions/view.php?id=<?= urlencode($e['journal_entry_id']) ?>"><?= clean($e['reference_no']) ?></a></td></tr><?php endforeach; ?></tbody></table></div>
</div></section>

<section id="source" class="card"><div class="card-header"><h3><i class="ri-building-2-line"></i> Source Entity Account</h3><div><a href="source_statement.php?id=<?= urlencode($car['source_entity_id']) ?>" class="btn btn-sm btn-outline">Outside Car Statement</a> <a href="../parties/view.php?id=<?= urlencode($car['source_party_id']) ?>" class="btn btn-sm btn-outline">Current Account</a></div></div><div class="card-body">
<div class="stats-grid outside-calc-stats"><div class="stat-card"><div class="stat-value"><?= formatAmount($f['source_car_position']['entitlement']) ?></div><div class="stat-label">This Car Entitlement</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($f['source_entity_position']['payable']) ?></div><div class="stat-label">Total Entity Payable · All Cars</div></div><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($f['source_entity_position']['advance']) ?></div><div class="stat-label">Recoverable Source Advance</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($f['funds_deployed']) ?></div><div class="stat-label">Tiranga Funds Deployed · This Car</div></div></div>
<?php if($canWrite): ?><div class="alert alert-warning"><i class="ri-alert-line"></i> There is no payment cap or buyer-payment prerequisite. Any amount above the entity’s current payable is posted as a recoverable Source Advance and will be auto-applied FIFO to future entitlements.</div><form method="post" class="inline-action-form"><?= csrfField() ?><input type="hidden" name="action" value="source_movement"><select name="movement_kind" class="form-control"><option value="PAY_OR_ADVANCE" <?= $draft('source_movement','movement_kind','PAY_OR_ADVANCE')==='PAY_OR_ADVANCE'?'selected':'' ?>>Pay / Advance Source Entity</option><option value="SOURCE_REFUND" <?= $draft('source_movement','movement_kind')==='SOURCE_REFUND'?'selected':'' ?>>Receive Refund from Source Entity</option></select><input type="date" name="movement_date" class="form-control" value="<?= clean($draft('source_movement','movement_date',date('Y-m-d'))) ?>" required><input name="amount" class="form-control currency-input" value="<?= clean($draft('source_movement','amount')) ?>" placeholder="Amount" required><select name="account_id" class="form-control searchable-select" required><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('source_movement','account_id')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select><input name="narration" class="form-control" value="<?= clean($draft('source_movement','narration')) ?>" placeholder="Narration"><button class="btn btn-primary">Post</button></form><?php endif; ?>
<h4>Entity Movements Across All Outside Cars</h4><div class="table-container"><table><thead><tr><th>Date</th><th>Car / Type</th><th class="text-right">Payable</th><th class="text-right">Advance</th><th class="text-right">Refund</th><th>Account / Journal</th></tr></thead><tbody><?php if(!$sourceMovements): ?><tr><td colspan="6" class="text-center text-muted">No Source Entity money movement recorded.</td></tr><?php endif; ?><?php foreach($sourceMovements as $m): ?><tr><td><?= formatDate($m['movement_date']) ?></td><td><a href="view.php?id=<?= urlencode($m['origin_car_id']) ?>"><?= clean($m['origin_car_id']===$carId?$car['registration_no']:'Other car') ?></a><div class="table-secondary"><?= clean(str_replace('_',' ',$m['movement_kind'])) ?></div></td><td class="text-right"><?= formatAmount($m['payable_applied']) ?></td><td class="text-right"><?= formatAmount($m['advance_created']-$m['advance_refunded']-$m['allocated_amount']) ?></td><td class="text-right"><?= $m['movement_kind']==='SOURCE_REFUND'?formatAmount($m['amount']):'-' ?></td><td><?= clean($m['gateway_name']) ?><div><a href="../transactions/view.php?id=<?= urlencode($m['journal_entry_id']) ?>"><?= clean($m['reference_no']) ?></a></div></td></tr><?php endforeach; ?></tbody></table></div>
<h4>Automatic Allocation History</h4><div class="table-container"><table><thead><tr><th>Date</th><th>Type</th><th>Origin Car</th><th>Adjusted Car</th><th class="text-right">Amount</th><th>Journal</th></tr></thead><tbody><?php if(!$sourceAllocations): ?><tr><td colspan="6" class="text-center text-muted">No cross-car or advance allocations yet.</td></tr><?php endif; ?><?php foreach($sourceAllocations as $a): ?><tr><td><?= formatDate($a['allocation_date']) ?></td><td><?= clean(str_replace('_',' ',$a['allocation_kind'])) ?></td><td><a href="view.php?id=<?= urlencode($a['origin_car_id']) ?>"><?= clean($a['origin_registration']) ?></a></td><td><a href="view.php?id=<?= urlencode($a['target_car_id']) ?>"><?= clean($a['target_registration']) ?></a></td><td class="text-right amount"><?= formatAmount($a['amount']) ?></td><td><a href="../transactions/view.php?id=<?= urlencode($a['journal_entry_id']) ?>"><?= clean($a['reference_no']) ?></a></td></tr><?php endforeach; ?></tbody></table></div>
</div></section>

<section id="rto" class="card"><div class="card-header"><h3><i class="ri-file-shield-2-line"></i> RTO Income &amp; Expense</h3></div><div class="card-body">
<div class="stats-grid outside-calc-stats"><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($f['rto_income']) ?></div><div class="stat-label">RTO Income</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($f['rto_expense']) ?></div><div class="stat-label">RTO Expense</div></div><div class="stat-card"><div class="stat-value"><?= formatAmount($f['rto_net']) ?></div><div class="stat-label">RTO Net Result</div></div></div>
<?php if($canWrite): ?><form method="post" class="inline-action-form"><?= csrfField() ?><input type="hidden" name="action" value="rto"><input type="date" name="movement_date" class="form-control" value="<?= clean($draft('rto','movement_date',date('Y-m-d'))) ?>" required><input name="amount" class="form-control currency-input" value="<?= clean($draft('rto','amount')) ?>" placeholder="Gross RTO expense" required><input name="gst_amount" class="form-control currency-input" value="<?= clean($draft('rto','gst_amount','0')) ?>" placeholder="Eligible GST"><select name="account_id" class="form-control searchable-select" required><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('rto','account_id')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select><input name="narration" class="form-control" value="<?= clean($draft('rto','narration')) ?>" placeholder="RTO file / voucher reference"><button class="btn btn-primary">Record RTO Expense</button></form><?php if($car['rto_status']!=='COMPLETED'): ?><form method="post" class="inline-action-form"><?= csrfField() ?><input type="hidden" name="action" value="complete_rto"><input name="completion_reason" class="form-control" value="<?= clean($draft('complete_rto','completion_reason')) ?>" placeholder="Completion file / reason" required><button class="btn btn-outline">Mark RTO Completed</button></form><?php endif; ?><?php endif; ?>
<div class="table-container"><table><thead><tr><th>Date</th><th>Reference</th><th class="text-right">Expense</th><th>Entered By</th></tr></thead><tbody><?php if(!$rtoMoves): ?><tr><td colspan="4" class="text-center text-muted">No RTO expense recorded.</td></tr><?php endif; ?><?php foreach($rtoMoves as $r): ?><tr><td><?= formatDate($r['movement_date']) ?></td><td><a href="../transactions/view.php?id=<?= urlencode($r['journal_entry_id']) ?>"><?= clean($r['reference_no']) ?></a><div class="table-secondary"><?= clean($r['narration']) ?></div></td><td class="text-right amount"><?= formatAmount($r['amount']) ?></td><td><?= clean($r['created_by_name']?:'Unknown') ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></section>

<section id="agreement" class="card"><div class="card-header"><h3><i class="ri-file-text-line"></i> Agreement</h3></div><div class="card-body"><div class="alert alert-warning"><i class="ri-scales-line"></i> The Gujarati/English template is configurable and requires local legal review.</div>
<?php if($sale&&$canWrite): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="agreement"><div class="form-row"><div class="form-group"><label class="form-label">Buyer Address</label><textarea name="buyer_address" class="form-control"><?= clean($draft('agreement','buyer_address')) ?></textarea></div><div class="form-group"><label class="form-label">Delivery Terms</label><textarea name="delivery_terms" class="form-control"><?= clean($draft('agreement','delivery_terms','Payment and delivery terms as agreed with the buyer.')) ?></textarea></div></div><div class="form-row"><div class="form-group"><label class="form-label">Witness 1</label><input name="witness_1" class="form-control" value="<?= clean($draft('agreement','witness_1')) ?>"></div><div class="form-group"><label class="form-label">Witness 2</label><input name="witness_2" class="form-control" value="<?= clean($draft('agreement','witness_2')) ?>"></div></div><button class="btn btn-primary"><i class="ri-file-pdf-2-line"></i> <?= $agreements?'Create Amendment Version':'Generate Agreement PDF' ?></button></form><?php endif; ?>
<div class="table-container"><table><thead><tr><th>Version</th><th>Status</th><th>Hash</th><th>Created By</th><th>Files</th></tr></thead><tbody><?php if(!$agreements): ?><tr><td colspan="5" class="text-center text-muted">No agreement generated.</td></tr><?php endif; ?><?php foreach($agreements as $a): $signed=fetchEntityAttachments($businessId,'OUTSIDE_CAR_AGREEMENT',$a['id'],'SIGNED_COPY'); ?><tr><td>v<?= (int)$a['version_no'] ?></td><td><span class="badge <?= $a['status']==='SIGNED'?'badge-green':'badge-blue' ?>"><?= clean($a['status']) ?></span></td><td><code title="<?= clean($a['snapshot_hash']) ?>"><?= clean(substr($a['snapshot_hash'],0,16)) ?>…</code></td><td><?= clean($a['created_by_name']?:'Unknown') ?></td><td><a class="btn btn-sm btn-outline" target="_blank" href="../<?= clean($a['pdf_path']) ?>">PDF</a><?php foreach($signed as $scan): ?> <a class="btn btn-sm btn-outline" target="_blank" href="<?= clean(attachmentUrl($scan)) ?>">Signed copy</a><?php endforeach; ?><?php if($canWrite&&$a['status']!=='SIGNED'): ?><form method="post" enctype="multipart/form-data" class="inline-upload-form"><?= csrfField() ?><input type="hidden" name="action" value="sign_agreement"><input type="hidden" name="agreement_id" value="<?= clean($a['id']) ?>"><input type="file" name="signed_files[]" multiple required accept="<?= clean(attachmentAcceptAttribute('documents')) ?>"><button class="btn btn-sm btn-primary">Attach &amp; Mark Signed</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></section>

<section id="delivery" class="card"><div class="card-header"><h3><i class="ri-key-2-line"></i> Delivery</h3></div><div class="card-body">
<?php if($delivery): ?><div class="alert alert-success"><i class="ri-checkbox-circle-line"></i> Delivered to <strong><?= clean($delivery['receiver_name']) ?></strong> on <?= formatDate($delivery['delivery_date']) ?><?= $delivery['override_used']?' with authorized override':'' ?>.</div>
<?php elseif($sale&&$canWrite): ?><form method="post" data-confirm-submit="Record final vehicle delivery?"><?= csrfField() ?><input type="hidden" name="action" value="delivery"><div class="form-row-3"><div class="form-group"><label class="form-label">Delivery Date / Time</label><div style="display:flex;gap:8px"><input type="date" name="delivery_date" class="form-control" value="<?= clean($draft('delivery','delivery_date',date('Y-m-d'))) ?>" required><input type="time" name="delivery_time" class="form-control" value="<?= clean($draft('delivery','delivery_time',date('H:i'))) ?>"></div></div><div class="form-group"><label class="form-label">Receiver Name *</label><input name="receiver_name" class="form-control" value="<?= clean($draft('delivery','receiver_name',$sale['buyer_name'])) ?>" required></div><div class="form-group"><label class="form-label">Odometer / Fuel</label><div style="display:flex;gap:8px"><input type="number" name="odometer" class="form-control" value="<?= clean($draft('delivery','odometer')) ?>"><input name="fuel_level" class="form-control" value="<?= clean($draft('delivery','fuel_level')) ?>" placeholder="¼, ½, full"></div></div></div><div class="form-row-3"><div class="form-group"><label class="form-label">Keys Handed Over</label><input type="number" name="keys_handed_over" class="form-control" value="<?= clean($draft('delivery','keys_handed_over',$car['has_second_key']?2:1)) ?>" min="1" max="3"></div><div class="form-group"><label class="form-label">Documents Handed Over</label><input name="documents_handed_over" class="form-control" value="<?= clean($draft('delivery','documents_handed_over')) ?>"></div><div class="form-group"><label class="form-label">Override?</label><select name="override_used" class="form-control"><option value="0" <?= $draft('delivery','override_used','0')==='0'?'selected':'' ?>>No</option><option value="1" <?= $draft('delivery','override_used')==='1'?'selected':'' ?>>Yes — authorize exception</option></select></div></div><div class="form-row"><div class="form-group"><label class="form-label">Override Reason</label><input name="override_reason" class="form-control" value="<?= clean($draft('delivery','override_reason')) ?>"></div><div class="form-group"><label class="form-label">Promised Balance Date</label><input type="date" name="promised_payment_date" class="form-control" value="<?= clean($draft('delivery','promised_payment_date')) ?>"></div></div><button class="btn btn-primary"><i class="ri-key-2-line"></i> Record Delivery</button></form><?php endif; ?>
</div></section>

<section id="audit" class="card"><div class="card-header"><h3><i class="ri-history-line"></i> Audit &amp; Reversal History</h3><a class="btn btn-sm btn-outline" href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= urlencode($carId) ?>">Full Change History</a></div><div class="table-container"><table><thead><tr><th>Date</th><th>Reference</th><th>Entry Type</th><th>Narration</th><th class="text-right">Amount</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($entries as $entry): ?><tr><td><?= renderDateTimeStack($entry['entry_date'],$entry['created_at']) ?></td><td><a href="../transactions/view.php?id=<?= urlencode($entry['id']) ?>"><?= clean($entry['reference_no']) ?></a></td><td><?= clean(transactionTypeLabel($entry['transaction_type'],$entry)) ?></td><td><?= clean($entry['narration']) ?></td><td class="text-right amount"><?= formatAmount($entry['entry_amount']) ?></td><td><?= clean($entry['status']) ?></td><td><?php if($entry['status']==='POSTED'&&!$entry['is_reversal']&&Auth::canAccessTransactionEntry($entry['id'],$businessId,'delete')): ?><a class="btn btn-sm btn-outline text-red" href="../transactions/reverse.php?id=<?= urlencode($entry['id']) ?>">Reverse</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<script>
(() => {
    const bearer = document.getElementById('outside-expense-bearer');
    if (!bearer) return;
    const hint = document.getElementById('outside-expense-bearer-hint');
    const applyBearer = () => {
        const isTiranga = bearer.value === 'TIRANGA';
        document.querySelectorAll('[data-tiranga-expense-field]').forEach((group) => {
            group.hidden = !isTiranga;
            group.querySelectorAll('input, select').forEach((field) => {
                field.disabled = !isTiranga;
                if (field.name === 'expense_account_id') field.required = isTiranga;
            });
        });
        hint.textContent = bearer.value === 'SOURCE_ENTITY'
            ? 'Reduces Source Entity payable first; any excess becomes recoverable Source Advance.'
            : bearer.value === 'BUYER'
                ? 'Adds the expense to this car buyer outstanding.'
                : 'Posts to the selected Tiranga expense ledger with eligible GST Input.';
    };
    bearer.addEventListener('change', applyBearer);
    applyBearer();
})();
</script>
