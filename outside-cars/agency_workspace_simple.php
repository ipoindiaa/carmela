<?php
// Included by view.php; the loader finishes with includes/footer.php and the shared searchable-select enhancer.
$entryAction=strtolower(trim((string)get('entry','')));
if($actionError&&$submittedAction!=='')$entryAction=$submittedAction;
$allowedEntryActions=['details','sale','buyer_payment','source_movement','expense','rto'];
if(!in_array($entryAction,$allowedEntryActions,true))$entryAction='';
$detailsReady=floatval($car['commission_value'])>0;
$sourceCarPayable=floatval($f['source_car_position']['payable']??0);
$sourceCarAdvance=floatval($f['source_car_position']['advance']??0);
$sourceBalanceLabel=$sourceCarAdvance>0.009?'To Recover':($sourceCarPayable>0.009?'To Pay':'Clear');
$sourceBalanceAmount=$sourceCarAdvance>0.009?$sourceCarAdvance:$sourceCarPayable;
$carDescription=trim(implode(' ',array_filter([$car['make'],$car['model'],$car['year']])));
$accountEntryCount=count($entries);
?>
<div class="page-header">
    <div>
        <h1><i class="ri-car-line"></i> <?= clean(formatRegistrationNo($car['registration_no'])) ?></h1>
        <p class="page-subtitle">
            <?= clean($carDescription?:'Vehicle details pending') ?> ·
            Source Entity:
            <a href="source_statement.php?id=<?= urlencode($car['source_entity_id']) ?>"><?= clean($car['source_entity_name']) ?></a>
            · <span class="badge badge-blue">Outside Car</span>
        </p>
    </div>
    <div class="page-actions car-detail-actions">
        <?php if($canWrite): ?><a href="?id=<?= urlencode($carId) ?>&amp;entry=details" class="btn btn-outline btn-sm"><i class="ri-edit-line"></i> Car Details</a><?php endif; ?>
        <a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= urlencode($carId) ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
        <a href="index.php" class="btn btn-outline btn-sm"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<?php if($actionError): ?><div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($actionError) ?></div><?php endif; ?>

<div class="stats-grid car-detail-stats-grid outside-simple-stats">
    <div class="stat-card"><div class="stat-value"><?= $sale?formatAmount($sale['buyer_total']):'Not Sold' ?></div><div class="stat-label">Customer Total</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($f['buyer_collected']) ?></div><div class="stat-label">Customer Paid</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($sale['buyer_outstanding']??0) ?></div><div class="stat-label">Customer Due</div></div>
    <div class="stat-card"><div class="stat-value <?= $sourceCarAdvance>0.009?'flow-in':'flow-out' ?>"><?= formatAmount($sourceBalanceAmount) ?></div><div class="stat-label">Source Entity · <?= clean($sourceBalanceLabel) ?></div></div>
</div>

<div class="card outside-simple-actions">
    <div class="card-header">
        <div><h3><i class="ri-add-circle-line"></i> Add Entry</h3><div class="card-header-note">Choose one action. Only that form will open.</div></div>
    </div>
    <div class="card-body">
        <div class="outside-action-grid">
            <?php if(!$sale): ?>
                <a class="outside-action <?= !$detailsReady?'is-primary':'' ?>" href="?id=<?= urlencode($carId) ?>&amp;entry=<?= $detailsReady?'sale':'details' ?>"><i class="<?= $detailsReady?'ri-money-rupee-circle-line':'ri-edit-line' ?>"></i><span><strong><?= $detailsReady?'Sell Car':'Add Car Details' ?></strong><small><?= $detailsReady?'Record buyer, price and first receipt':'Set vehicle and commission before sale' ?></small></span></a>
            <?php elseif(floatval($sale['buyer_outstanding'])>0.009): ?>
                <a class="outside-action is-primary" href="?id=<?= urlencode($carId) ?>&amp;entry=buyer_payment"><i class="ri-arrow-down-circle-line"></i><span><strong>Receive Customer Money</strong><small>Due <?= formatAmount($sale['buyer_outstanding']) ?></small></span></a>
            <?php endif; ?>
            <a class="outside-action" href="?id=<?= urlencode($carId) ?>&amp;entry=source_movement"><i class="ri-hand-coin-line"></i><span><strong>Pay / Receive Owner</strong><small>Payment, advance or refund</small></span></a>
            <a class="outside-action" href="?id=<?= urlencode($carId) ?>&amp;entry=expense"><i class="ri-tools-line"></i><span><strong>Add Expense</strong><small>Select who bears it</small></span></a>
            <?php if($sale): ?><a class="outside-action" href="?id=<?= urlencode($carId) ?>&amp;entry=rto"><i class="ri-file-shield-2-line"></i><span><strong>RTO Expense</strong><small>Income <?= formatAmount($f['rto_income']) ?> · Spent <?= formatAmount($f['rto_expense']) ?></small></span></a><?php endif; ?>
            <?php if($sale): ?><a class="outside-action" href="../cars/loan_commission.php?car_id=<?= urlencode($carId) ?>"><i class="ri-bank-card-line"></i><span><strong>Loan Commission</strong><small>Only when customer uses finance</small></span></a><?php endif; ?>
            <a class="outside-action" href="?id=<?= urlencode($carId) ?>&amp;mode=advanced"><i class="ri-more-2-fill"></i><span><strong>More</strong><small>Refund, agreement, delivery and reversals</small></span></a>
        </div>
    </div>
</div>

<?php if($entryAction!==''): ?>
<div class="card outside-entry-card" id="entry-form">
    <div class="card-header">
        <h3>
            <?php if($entryAction==='details'): ?><i class="ri-edit-line"></i> Car Details
            <?php elseif($entryAction==='sale'): ?><i class="ri-money-rupee-circle-line"></i> Sell Outside Car
            <?php elseif($entryAction==='buyer_payment'): ?><i class="ri-arrow-down-circle-line"></i> Receive Customer Money
            <?php elseif($entryAction==='source_movement'): ?><i class="ri-hand-coin-line"></i> Source Entity Money
            <?php elseif($entryAction==='expense'): ?><i class="ri-tools-line"></i> Add Expense
            <?php else: ?><i class="ri-file-shield-2-line"></i> RTO Expense<?php endif; ?>
        </h3>
        <a href="?id=<?= urlencode($carId) ?>" class="btn btn-ghost btn-sm"><i class="ri-close-line"></i> Close</a>
    </div>
    <div class="card-body">
        <?php if($entryAction==='details'): ?>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="update_details">
                <div class="form-row-3">
                    <div class="form-group"><label class="form-label">Make</label><input name="make" class="form-control" value="<?= clean($draft('update_details','make',$car['make'])) ?>"></div>
                    <div class="form-group"><label class="form-label">Model</label><input name="model" class="form-control" value="<?= clean($draft('update_details','model',$car['model'])) ?>"></div>
                    <div class="form-group"><label class="form-label">Year</label><input type="number" name="year" class="form-control" min="1990" max="<?= date('Y')+1 ?>" value="<?= clean($draft('update_details','year',$car['year'])) ?>"></div>
                </div>
                <div class="form-row-3">
                    <div class="form-group"><label class="form-label">Color</label><input name="color" class="form-control" value="<?= clean($draft('update_details','color',$car['color'])) ?>"></div>
                    <div class="form-group"><label class="form-label">Second Key</label><select name="has_second_key" class="form-control"><option value="0" <?= $draft('update_details','has_second_key',empty($car['has_second_key'])?'0':'1')==='0'?'selected':'' ?>>No</option><option value="1" <?= $draft('update_details','has_second_key',empty($car['has_second_key'])?'0':'1')==='1'?'selected':'' ?>>Yes</option></select></div>
                    <div class="form-group"><label class="form-label">Expected Customer Price</label><input name="expected_sale_value" class="form-control currency-input" value="<?= clean($draft('update_details','expected_sale_value',$car['expected_sale_value'])) ?>" <?= $sale?'readonly':'' ?>></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Commission Type *</label><select name="commission_type" class="form-control" <?= $sale?'disabled':'' ?>><option value="FIXED" <?= $draft('update_details','commission_type',$car['commission_type'])==='FIXED'?'selected':'' ?>>Fixed Amount</option><option value="PERCENT" <?= $draft('update_details','commission_type',$car['commission_type'])==='PERCENT'?'selected':'' ?>>Percentage of Customer Price</option></select><?php if($sale): ?><input type="hidden" name="commission_type" value="<?= clean($car['commission_type']) ?>"><?php endif; ?></div>
                    <div class="form-group"><label class="form-label">Commission Value *</label><input name="commission_value" class="form-control currency-input" value="<?= clean($draft('update_details','commission_value',$car['commission_value'])) ?>" min="0" <?= $sale?'readonly':'' ?>><div class="form-hint">Commission is included in the customer price and deducted from the Source Entity amount.</div></div>
                </div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= clean($draft('update_details','notes',$car['notes'])) ?></textarea></div>
                <button class="btn btn-primary"><i class="ri-save-line"></i> Save Details</button>
            </form>
        <?php elseif($entryAction==='sale'): ?>
            <?php if(!$detailsReady): ?><div class="alert alert-warning"><i class="ri-information-line"></i> Add vehicle and commission details before recording the sale. <a href="?id=<?= urlencode($carId) ?>&amp;entry=details">Add details</a></div>
            <?php elseif(!$sale): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="sale">
                <div class="form-row-3"><div class="form-group"><label class="form-label">Buyer</label><select name="buyer_party_id" class="form-control searchable-select"><option value="">Create buyer below</option><?php foreach($buyers as $buyer): ?><option value="<?= clean($buyer['id']) ?>" <?= $draft('sale','buyer_party_id')===$buyer['id']?'selected':'' ?>><?= clean($buyer['name']) ?><?= $buyer['phone']?' · '.clean($buyer['phone']):'' ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">New Buyer Name</label><input name="new_buyer_name" class="form-control" value="<?= clean($draft('sale','new_buyer_name')) ?>"></div><div class="form-group"><label class="form-label">Buyer Phone</label><input name="new_buyer_phone" class="form-control" value="<?= clean($draft('sale','new_buyer_phone')) ?>"></div></div>
                <div class="form-row-3"><div class="form-group"><label class="form-label">Customer Vehicle Price *</label><input name="vehicle_sale_price" class="form-control currency-input" value="<?= clean($draft('sale','vehicle_sale_price',$car['expected_sale_value']?:'')) ?>" required><div class="form-hint">Includes Tiranga commission.</div></div><div class="form-group"><label class="form-label">Discount</label><input name="discount_amount" class="form-control currency-input" value="<?= clean($draft('sale','discount_amount','0')) ?>"></div><div class="form-group"><label class="form-label">RTO Charge</label><input name="buyer_rto_charge" class="form-control currency-input" value="<?= clean($draft('sale','buyer_rto_charge','0')) ?>"></div></div>
                <div class="form-row-3"><div class="form-group"><label class="form-label">Sale Date *</label><input type="date" name="sale_date" class="form-control" value="<?= clean($draft('sale','sale_date',date('Y-m-d'))) ?>" required></div><div class="form-group"><label class="form-label">Received Now</label><input name="amount_received_now" class="form-control currency-input" value="<?= clean($draft('sale','amount_received_now','0')) ?>"></div><div class="form-group"><label class="form-label">Receive Into</label><select name="receiving_account" class="form-control searchable-select"><option value="">Only required when receiving now</option><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('sale','receiving_account')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select></div></div>
                <input name="narration" class="form-control" value="<?= clean($draft('sale','narration','Outside Car sale - '.$car['registration_no'])) ?>"><button class="btn btn-primary" style="margin-top:12px">Record Sale</button>
            </form><?php endif; ?>
        <?php elseif($entryAction==='buyer_payment'&&$sale): ?>
            <div class="alert alert-info"><i class="ri-information-line"></i> Customer balance: <strong><?= formatAmount($sale['buyer_outstanding']) ?></strong></div>
            <form method="post" class="inline-action-form"><?= csrfField() ?><input type="hidden" name="action" value="buyer_payment"><input type="date" name="payment_date" class="form-control" value="<?= clean($draft('buyer_payment','payment_date',date('Y-m-d'))) ?>" required><input name="amount" class="form-control currency-input" value="<?= clean($draft('buyer_payment','amount',$sale['buyer_outstanding'])) ?>" required><select name="account_id" class="form-control searchable-select" required><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('buyer_payment','account_id')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select><input name="narration" class="form-control" value="<?= clean($draft('buyer_payment','narration','Customer payment - '.$car['registration_no'])) ?>"><button class="btn btn-primary">Receive</button></form>
        <?php elseif($entryAction==='source_movement'): ?>
            <div class="alert alert-info"><i class="ri-information-line"></i> Source Entity payable: <strong><?= formatAmount($f['source_entity_position']['payable']) ?></strong> · Recoverable advance: <strong><?= formatAmount($f['source_entity_position']['advance']) ?></strong>. Paying above the payable automatically becomes an amount recoverable from the Source Entity.</div>
            <form method="post" class="inline-action-form"><?= csrfField() ?><input type="hidden" name="action" value="source_movement"><select name="movement_kind" class="form-control"><option value="PAY_OR_ADVANCE" <?= $draft('source_movement','movement_kind','PAY_OR_ADVANCE')==='PAY_OR_ADVANCE'?'selected':'' ?>>Pay / Advance Source Entity</option><option value="SOURCE_REFUND" <?= $draft('source_movement','movement_kind')==='SOURCE_REFUND'?'selected':'' ?>>Receive Refund from Source Entity</option></select><input type="date" name="movement_date" class="form-control" value="<?= clean($draft('source_movement','movement_date',date('Y-m-d'))) ?>" required><input name="amount" class="form-control currency-input" value="<?= clean($draft('source_movement','amount')) ?>" placeholder="Amount" required><select name="account_id" class="form-control searchable-select" required><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('source_movement','account_id')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select><input name="narration" class="form-control" value="<?= clean($draft('source_movement','narration')) ?>" placeholder="Narration"><button class="btn btn-primary">Post</button></form>
        <?php elseif($entryAction==='expense'): ?>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="expense">
                <div class="form-row-3"><div class="form-group"><label class="form-label">Date *</label><input type="date" name="expense_date" class="form-control" value="<?= clean($draft('expense','expense_date',date('Y-m-d'))) ?>" required></div><div class="form-group"><label class="form-label">Expense *</label><input name="category" class="form-control" value="<?= clean($draft('expense','category')) ?>" placeholder="Repair, cleaning, fuel..." required></div><div class="form-group"><label class="form-label">Paid For *</label><select name="responsibility" id="outside-expense-bearer" class="form-control" required><?php foreach(['SOURCE_ENTITY'=>'Source Entity','BUYER'=>'Buyer','TIRANGA'=>'Tiranga'] as $value=>$label): ?><option value="<?= $value ?>" <?= $draft('expense','responsibility','SOURCE_ENTITY')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><div class="form-hint" id="outside-expense-bearer-hint"></div></div></div>
                <div class="form-row-3"><div class="form-group"><label class="form-label">Amount *</label><input name="amount" class="form-control currency-input" value="<?= clean($draft('expense','amount')) ?>" required></div><div class="form-group"><label class="form-label">Pay From *</label><select name="payment_account" class="form-control searchable-select" required><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('expense','payment_account')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Vendor / Voucher</label><div style="display:flex;gap:8px"><input name="vendor_name" class="form-control" value="<?= clean($draft('expense','vendor_name')) ?>" placeholder="Vendor"><input name="voucher_no" class="form-control" value="<?= clean($draft('expense','voucher_no')) ?>" placeholder="Voucher"></div></div></div>
                <div class="form-row" data-tiranga-expense-field><div class="form-group"><label class="form-label">Tiranga Expense Ledger *</label><select name="expense_account_id" class="form-control searchable-select"><option value="">Select ledger</option><?php foreach($expenseAccounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('expense','expense_account_id')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Eligible GST Input</label><input name="gst_amount" class="form-control currency-input" value="<?= clean($draft('expense','gst_amount','0')) ?>"></div></div>
                <input name="narration" class="form-control" value="<?= clean($draft('expense','narration')) ?>" placeholder="Narration"><button class="btn btn-primary" style="margin-top:12px">Record Expense</button>
            </form>
        <?php elseif($entryAction==='rto'&&$sale): ?>
            <div class="alert alert-info"><i class="ri-information-line"></i> RTO received from customer: <strong><?= formatAmount($f['rto_income']) ?></strong> · RTO spent: <strong><?= formatAmount($f['rto_expense']) ?></strong></div>
            <form method="post" class="inline-action-form"><?= csrfField() ?><input type="hidden" name="action" value="rto"><input type="date" name="movement_date" class="form-control" value="<?= clean($draft('rto','movement_date',date('Y-m-d'))) ?>" required><input name="amount" class="form-control currency-input" value="<?= clean($draft('rto','amount')) ?>" placeholder="RTO expense" required><input name="gst_amount" class="form-control currency-input" value="<?= clean($draft('rto','gst_amount','0')) ?>" placeholder="Eligible GST"><select name="account_id" class="form-control searchable-select" required><?php foreach($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $draft('rto','account_id')===$account['id']?'selected':'' ?>><?= clean($account['name']) ?></option><?php endforeach; ?></select><input name="narration" class="form-control" value="<?= clean($draft('rto','narration')) ?>" placeholder="RTO file / voucher"><button class="btn btn-primary">Record RTO Expense</button></form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card outside-car-account">
    <div class="card-header">
        <div><h3><i class="ri-book-open-line"></i> Car Account</h3><div class="card-header-note">Every financial entry for this car appears here and opens its balanced journal voucher.</div></div>
        <div class="card-header-actions"><span class="badge badge-blue"><?= $accountEntryCount ?> <?= $accountEntryCount===1?'entry':'entries' ?></span><a href="sheet.php?id=<?= urlencode($carId) ?>" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</a></div>
    </div>
    <?php if(!$entries): ?>
        <div class="empty-state"><i class="ri-book-open-line"></i><h3>No account entries yet</h3><p>Registration itself does not move money. The first sale, owner payment, expense, or RTO entry will start this car account automatically.</p></div>
    <?php else: ?>
        <div class="table-container"><table><thead><tr><th>Date</th><th>Entry</th><th>Details</th><th>Cash / Bank</th><th>Flow</th><th class="text-right">Amount</th><th>Entered By</th></tr></thead><tbody>
        <?php foreach($entries as $entry):
            $flow=transactionBusinessFlow($entry['transaction_type'],$entry);
        ?><tr>
            <td><?= renderDateTimeStack($entry['entry_date'],$entry['created_at']) ?></td>
            <td><a class="text-bold" href="../transactions/view.php?id=<?= urlencode($entry['id']) ?>"><?= clean($entry['reference_no']) ?></a><div class="table-secondary"><?= clean(transactionTypeLabel($entry['transaction_type'],$entry)) ?></div></td>
            <td><?= clean($entry['narration']) ?><?php if($entry['status']!=='POSTED'): ?><div><span class="badge badge-yellow"><?= clean($entry['status']) ?></span></div><?php endif; ?></td>
            <td><?= clean($entry['gateway_accounts']?:'Account adjustment') ?></td>
            <td><span class="transaction-context-chip <?= clean(flowColorClass($flow)) ?>"><?= clean($flow==='in'?'Money In':($flow==='out'?'Money Out':'Account Transfer')) ?></span></td>
            <td class="text-right amount <?= clean(flowColorClass($flow)) ?>"><?= formatAmount($entry['entry_amount']) ?></td>
            <td><?= clean($entry['created_by_name']?:'Unknown') ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<div class="card outside-simple-details">
    <div class="card-header"><h3><i class="ri-information-line"></i> Car Information</h3><a href="?id=<?= urlencode($carId) ?>&amp;mode=advanced" class="btn btn-ghost btn-sm">Detailed account tools</a></div>
    <div class="card-body">
        <div class="outside-info-grid">
            <div><span>Source Entity</span><strong><a href="source_statement.php?id=<?= urlencode($car['source_entity_id']) ?>"><?= clean($car['source_entity_name']) ?></a></strong></div>
            <div><span>Commission</span><strong><?= floatval($car['commission_value'])>0?clean($commissionLabel):'Not set' ?></strong></div>
            <div><span>Received</span><strong><?= formatDate($car['purchase_date']) ?></strong></div>
            <div><span>Status</span><strong><?= clean(str_replace('_',' ',$car['buyer_status'])) ?></strong></div>
        </div>
    </div>
</div>

<?php if($entryAction==='expense'): ?>
<script>
(() => {
    const bearer = document.getElementById('outside-expense-bearer');
    if (!bearer) return;
    const hint = document.getElementById('outside-expense-bearer-hint');
    const apply = () => {
        const tiranga = bearer.value === 'TIRANGA';
        document.querySelectorAll('[data-tiranga-expense-field]').forEach((group) => {
            group.hidden = !tiranga;
            group.querySelectorAll('input, select').forEach((field) => {
                field.disabled = !tiranga;
                if (field.name === 'expense_account_id') field.required = tiranga;
            });
        });
        hint.textContent = bearer.value === 'SOURCE_ENTITY'
            ? 'Deduct from owner balance.'
            : bearer.value === 'BUYER' ? 'Add to customer balance.' : 'Tiranga business expense.';
    };
    bearer.addEventListener('change', apply);
    apply();
})();
</script>
<?php endif; ?>
