<?php
$pageTitle = 'Car Loan Commission';
$pageIcon = '<i class="ri-bank-card-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$carId = trim((string) get('car_id', ''));
$car = $db->fetch("SELECT c.*,buyer.name buyer_name FROM cars c LEFT JOIN debtors_creditors buyer ON buyer.id=c.buyer_party_id WHERE c.id=? AND c.business_id=?",[$carId,$businessId]);
if (!$car) { setFlash('error','Car not found.'); redirect('list.php'); }
Auth::requireEntityAccess('car','read');
$canWrite = Auth::hasEntityAccess('car','write');
$engine = new AccountingEngine($businessId,Auth::user('user_id'));
$groups = Auth::getAccessiblePrimaryAccountList($businessId,'write');
$accounts = array_merge($groups['cash_book']??[],$groups['bank_book']??[]);
$accountIds = array_column($accounts,'id');
$financiers = $db->fetchAll("SELECT id,name,phone FROM debtors_creditors WHERE business_id=? AND is_active=1 AND type IN ('DEBTOR','BUYER') ORDER BY name",[$businessId]);
$error = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    try {
        if (!$canWrite) throw new Exception('Write permission is required.');
        $action = post('action');
        if ($action === 'create') {
            $receivedNow = parseDecimalInput(post('received_now'));
            if ($receivedNow > 0 && !in_array(post('receiving_account_id'),$accountIds,true)) throw new Exception('Select an accessible Cash or Bank account.');
            $engine->createCarLoanCommission($carId,[
                'financier_party_id'=>post('financier_party_id'),'financier_name'=>post('financier_name'),'financier_phone'=>post('financier_phone'),
                'loan_account_no'=>post('loan_account_no'),'approval_date'=>post('approval_date'),'loan_amount'=>post('loan_amount'),
                'commission_type'=>post('commission_type'),'commission_value'=>post('commission_value'),'received_now'=>$receivedNow,
                'receiving_account_id'=>post('receiving_account_id'),'notes'=>post('notes'),
            ]);
            setFlash('success','Car-wise loan commission recorded. Finance-company receivable and income journals are linked to this car.');
        } elseif ($action === 'receipt') {
            if (!in_array(post('receiving_account_id'),$accountIds,true)) throw new Exception('Select an accessible Cash or Bank account.');
            $engine->recordCarLoanCommissionReceipt(post('commission_id'),post('amount'),post('receipt_date'),post('receiving_account_id'),post('narration'));
            setFlash('success','Loan commission receipt recorded against the finance company.');
        } else throw new Exception('Unknown action.');
        redirect('loan_commission.php?car_id='.urlencode($carId));
    } catch (Throwable $e) { $error=$e->getMessage(); }
}

$cases = $engine->getCarLoanCommissions($carId);
$receipts = $db->fetchAll("SELECT r.*,je.reference_no FROM car_loan_commission_receipts r LEFT JOIN journal_entries je ON je.id=r.journal_entry_id WHERE r.business_id=? AND r.car_id=? ORDER BY r.receipt_date,r.created_at",[$businessId,$carId]);
$back = (($car['ownership_type']??'OWNED')==='COMMISSION'?'commission_view.php?id='.urlencode($carId):'view.php?id='.urlencode($carId));
?>
<div class="page-header"><div><h1><i class="ri-bank-card-line"></i> Loan Commission · <?= clean(formatRegistrationNo($car['registration_no'])) ?></h1><p class="page-subtitle">Customer: <?= clean($car['buyer_name']?:'Sale buyer not recorded') ?> · Loan principal is memorandum only; finance commission is business income.</p></div><a href="<?= clean($back) ?>" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back to Car</a></div>
<?php if($error): ?><div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($error) ?></div><?php endif; ?>
<?php if(empty($car['buyer_party_id'])): ?><div class="alert alert-warning">Complete the buyer sale first. Loan commission must be connected to the actual car buyer.</div><?php elseif($canWrite): ?>
<div class="card"><div class="card-header"><h3><i class="ri-add-circle-line"></i> Add Customer Loan &amp; Commission</h3></div><div class="card-body"><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create">
<div class="form-row-3"><div class="form-group"><label class="form-label">Finance Company</label><select name="financier_party_id" class="form-control searchable-select"><option value="">Create company below</option><?php foreach($financiers as $f): ?><option value="<?= clean($f['id']) ?>"><?= clean($f['name']) ?><?= $f['phone']?' · '.clean($f['phone']):'' ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">New Finance Company</label><input name="financier_name" class="form-control" placeholder="HDFC Bank, Bajaj Finance..."></div><div class="form-group"><label class="form-label">Company Phone</label><input name="financier_phone" class="form-control"></div></div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Customer Loan Amount *</label><input name="loan_amount" class="form-control currency-input" required><div class="form-hint">Tracking only—not business income.</div></div><div class="form-group"><label class="form-label">Loan Account / File No.</label><input name="loan_account_no" class="form-control"></div><div class="form-group"><label class="form-label">Approval Date *</label><input type="date" name="approval_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div></div>
<div class="form-row-3"><div class="form-group"><label class="form-label">Commission Method</label><select name="commission_type" class="form-control"><option value="FIXED">Fixed amount</option><option value="PERCENT">Percentage of customer loan</option></select></div><div class="form-group"><label class="form-label">Commission Amount / % *</label><input name="commission_value" class="form-control currency-input" required></div><div class="form-group"><label class="form-label">Received Now</label><input name="received_now" class="form-control currency-input" value="0"></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Receive Into</label><select name="receiving_account_id" class="form-control searchable-select"><option value="">Required only if received now</option><?php foreach($accounts as $a): ?><option value="<?= clean($a['id']) ?>"><?= clean($a['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Notes</label><input name="notes" class="form-control" placeholder="Loan approval / commission terms"></div></div><button class="btn btn-primary"><i class="ri-save-line"></i> Record Loan Commission</button>
</form></div></div>
<?php endif; ?>

<div class="card" style="margin-top:24px"><div class="card-header"><h3><i class="ri-money-rupee-circle-line"></i> Car-wise Loan Commission</h3></div><div class="card-body" style="padding:0"><div class="table-container"><table><thead><tr><th>Finance Company</th><th>Loan / File</th><th class="text-right">Customer Loan</th><th class="text-right">Commission</th><th class="text-right">Received</th><th class="text-right">Pending</th><th>Status</th><th>Accrual</th></tr></thead><tbody>
<?php foreach($cases as $case): $pending=max(0,floatval($case['commission_amount'])-floatval($case['received_amount'])); ?><tr><td><?= clean($case['financier_name']) ?></td><td><?= clean($case['loan_account_no']?:'-') ?></td><td class="text-right amount"><?= formatAmount($case['loan_amount']) ?></td><td class="text-right amount flow-in"><?= formatAmount($case['commission_amount']) ?></td><td class="text-right amount"><?= formatAmount($case['received_amount']) ?></td><td class="text-right amount flow-out"><?= formatAmount($pending) ?></td><td><span class="badge <?= $case['status']==='RECEIVED'?'badge-green':($case['status']==='REVERSED'?'badge-red':'badge-yellow') ?>"><?= clean($case['status']) ?></span></td><td><a href="../transactions/view.php?id=<?= urlencode($case['accrual_entry_id']) ?>"><?= clean($case['reference_no']) ?></a></td></tr>
<?php if($canWrite&&$pending>0.009&&$case['status']!=='REVERSED'): ?><tr><td colspan="8"><form method="post" class="inline-action-form"><?= csrfField() ?><input type="hidden" name="action" value="receipt"><input type="hidden" name="commission_id" value="<?= clean($case['id']) ?>"><input type="date" name="receipt_date" class="form-control" value="<?= date('Y-m-d') ?>" required><input name="amount" class="form-control currency-input" value="<?= clean($pending) ?>" required><select name="receiving_account_id" class="form-control searchable-select" required><?php foreach($accounts as $a): ?><option value="<?= clean($a['id']) ?>"><?= clean($a['name']) ?></option><?php endforeach; ?></select><input name="narration" class="form-control" value="Loan commission receipt - <?= clean($car['registration_no']) ?>"><button class="btn btn-primary">Receive Commission</button></form></td></tr><?php endif; ?>
<?php endforeach; ?><?php if(!$cases): ?><tr><td colspan="8" class="text-center text-muted" style="padding:28px">No customer loan commission recorded for this car.</td></tr><?php endif; ?></tbody></table></div></div></div>

<?php if($receipts): ?><div class="card" style="margin-top:24px"><div class="card-header"><h3>Receipt History</h3></div><div class="card-body" style="padding:0"><table><thead><tr><th>Date</th><th>Reference</th><th class="text-right">Amount</th><th>Status</th></tr></thead><tbody><?php foreach($receipts as $r): ?><tr><td><?= formatDate($r['receipt_date']) ?></td><td><a href="../transactions/view.php?id=<?= urlencode($r['journal_entry_id']) ?>"><?= clean($r['reference_no']) ?></a></td><td class="text-right amount flow-in"><?= formatAmount($r['amount']) ?></td><td><?= clean($r['status']) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
