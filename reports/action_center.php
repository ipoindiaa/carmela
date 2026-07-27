<?php
$pageTitle = 'Action Center';
$pageIcon = '<i class="ri-task-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$canReadOutside = Auth::hasBookAccess('outside_cars', 'read');
$canReadLoanCommission = Auth::hasBookAccess('car_profitability', 'read') || $canReadOutside;
if (!$canReadOutside && !$canReadLoanCommission) {
    setFlash('error', 'You do not have access to the Action Center.');
    redirect('../dashboard.php');
}
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

$items = [];
$addItem = static function (array $item) use (&$items): void {
    $items[] = array_merge([
        'type' => '', 'priority' => 'MEDIUM', 'car_id' => '', 'registration_no' => '',
        'title' => '', 'detail' => '', 'amount' => 0, 'date' => '', 'owner' => 'Accounts', 'link' => '#',
    ], $item);
};

if ($canReadOutside) {
    $outsideRows = $db->fetchAll(
        "SELECT c.id car_id,c.registration_no,c.make,c.model,ocd.accounting_model,ocd.source_entity_id,ocd.buyer_status,ocd.rto_status,
                ocd.settlement_status,ocd.agreement_status,ocd.physical_status,
                se.display_name source_name,ocs.id sale_id,ocs.sale_date,ocs.buyer_outstanding,ocs.source_entity_entitlement,
                buyer.name buyer_name,os.remaining_entity_payable,os.remaining_entity_receivable,
                COALESCE(sa.applied,0) source_applied,COALESCE(sm.advance_balance,0) source_advance,
                delivery.id delivery_id
         FROM cars c
         JOIN outside_car_deals ocd ON ocd.business_id=c.business_id AND ocd.car_id=c.id
         JOIN source_entities se ON se.business_id=c.business_id AND se.id=ocd.source_entity_id
         LEFT JOIN outside_car_sales ocs ON ocs.business_id=c.business_id AND ocs.car_id=c.id AND ocs.status<>'REVERSED'
         LEFT JOIN debtors_creditors buyer ON buyer.id=ocs.buyer_party_id AND buyer.business_id=c.business_id
         LEFT JOIN outside_car_settlements os ON os.business_id=c.business_id AND os.car_id=c.id AND os.status<>'REVERSED'
         LEFT JOIN (SELECT business_id,sale_id,SUM(CASE WHEN status='POSTED' AND allocation_kind IN ('ADVANCE_TO_PAYABLE','PAYMENT_TO_PAYABLE','SOURCE_EXPENSE_TO_PAYABLE') THEN amount ELSE 0 END) applied FROM outside_source_allocations GROUP BY business_id,sale_id) sa ON sa.business_id=c.business_id AND sa.sale_id=ocs.id
         LEFT JOIN (SELECT business_id,origin_car_id,GREATEST(0,SUM(CASE WHEN status='POSTED' THEN advance_created-advance_refunded-allocated_amount ELSE 0 END)) advance_balance FROM outside_source_movements GROUP BY business_id,origin_car_id) sm ON sm.business_id=c.business_id AND sm.origin_car_id=c.id
         LEFT JOIN outside_car_deliveries delivery ON delivery.business_id=c.business_id AND delivery.car_id=c.id
         WHERE c.business_id=? AND c.ownership_type='OUTSIDE' AND c.status<>'CANCELLED'
         ORDER BY COALESCE(ocs.sale_date,c.created_at)",
        [$businessId]
    );
    foreach ($outsideRows as $row) {
        $link = '../outside-cars/view.php?id=' . urlencode($row['car_id']);
        $date = $row['sale_date'] ?: '';
        $ageDays = $date ? max(0, (int) floor((time() - strtotime($date)) / 86400)) : 0;
        if ((float) ($row['buyer_outstanding'] ?? 0) > 0.009) {
            $addItem(['type'=>'BUYER_DUE','priority'=>$ageDays >= 7 ? 'HIGH' : 'MEDIUM','car_id'=>$row['car_id'],'registration_no'=>$row['registration_no'],'title'=>'Collect buyer balance','detail'=>($row['buyer_name'] ?: 'Buyer') . ' · ' . $ageDays . ' day(s) since sale','amount'=>(float)$row['buyer_outstanding'],'date'=>$date,'owner'=>'Sales / Accounts','link'=>$link . '#buyer']);
        }
        $agency=($row['accounting_model']??'LEGACY_ABCK')==='COMMISSION_AGENCY';
        $entityPayable=$agency
            ? max(0,(float)($row['source_entity_entitlement']??0)-(float)($row['source_applied']??0))
            : (float)($row['remaining_entity_payable']??0);
        if ($entityPayable > 0.009) {
            $addItem(['type'=>'ENTITY_PAYABLE','priority'=>'HIGH','car_id'=>$row['car_id'],'registration_no'=>$row['registration_no'],'title'=>'Source Entity payable','detail'=>$row['source_name'].' · payment may be made at any time','amount'=>$entityPayable,'date'=>$date,'owner'=>'Accounts','link'=>$link . '#source']);
        }
        if ($agency && (float)($row['source_advance']??0)>0.009) {
            $addItem(['type'=>'SOURCE_ADVANCE','priority'=>'HIGH','car_id'=>$row['car_id'],'registration_no'=>$row['registration_no'],'title'=>'Recover Source Advance','detail'=>$row['source_name'].' · unapplied recoverable asset','amount'=>(float)$row['source_advance'],'date'=>$date,'owner'=>'Accounts','link'=>$link . '#source']);
        } elseif (!$agency && (float) ($row['remaining_entity_receivable'] ?? 0) > 0.009) {
            $addItem(['type'=>'ENTITY_RECEIVABLE','priority'=>'HIGH','car_id'=>$row['car_id'],'registration_no'=>$row['registration_no'],'title'=>'Collect from Source Entity','detail'=>$row['source_name'],'amount'=>(float)$row['remaining_entity_receivable'],'date'=>$date,'owner'=>'Accounts','link'=>$link . '#source']);
        }
        if (!$agency && !empty($row['sale_id']) && in_array($row['settlement_status'], ['TERMS_PENDING','CALCULATION_PENDING','ADVANCE_PAID'], true)) {
            $addItem(['type'=>'SETTLEMENT','priority'=>'HIGH','car_id'=>$row['car_id'],'registration_no'=>$row['registration_no'],'title'=>'Approve deal settlement','detail'=>'Status: ' . str_replace('_',' ',strtolower($row['settlement_status'])),'date'=>$date,'owner'=>'Approver','link'=>$link . '#settlement']);
        }
        if (!empty($row['sale_id']) && $row['rto_status'] !== 'COMPLETED') {
            $addItem(['type'=>'RTO','priority'=>$row['rto_status']==='IN_PROGRESS'?'MEDIUM':'LOW','car_id'=>$row['car_id'],'registration_no'=>$row['registration_no'],'title'=>'Complete RTO process','detail'=>'Status: ' . str_replace('_',' ',strtolower($row['rto_status'])),'date'=>$date,'owner'=>'RTO Desk','link'=>$link . '#rto']);
        }
        if (!empty($row['sale_id']) && $row['agreement_status'] !== 'SIGNED') {
            $addItem(['type'=>'AGREEMENT','priority'=>'HIGH','car_id'=>$row['car_id'],'registration_no'=>$row['registration_no'],'title'=>$row['agreement_status']==='DRAFT'?'Generate agreement':'Obtain signed agreement','detail'=>'Status: ' . strtolower($row['agreement_status']),'date'=>$date,'owner'=>'Documentation','link'=>$link . '#agreement']);
        }
        if (!empty($row['sale_id']) && empty($row['delivery_id'])) {
            $deliveryPriority = $row['buyer_status'] === 'FULLY_PAID' && $row['agreement_status'] === 'SIGNED' ? 'HIGH' : 'LOW';
            $addItem(['type'=>'DELIVERY','priority'=>$deliveryPriority,'car_id'=>$row['car_id'],'registration_no'=>$row['registration_no'],'title'=>'Record vehicle delivery','detail'=>'Buyer: ' . str_replace('_',' ',strtolower($row['buyer_status'])) . ' · Agreement: ' . strtolower($row['agreement_status']),'date'=>$date,'owner'=>'Delivery Desk','link'=>$link . '#delivery']);
        }
    }
}

if ($canReadLoanCommission) {
    $commissions = $db->fetchAll(
        "SELECT clc.car_id,clc.approval_date,clc.commission_amount,clc.received_amount,clc.status,
                c.registration_no,financier.name financier_name
         FROM car_loan_commissions clc
         JOIN cars c ON c.id=clc.car_id AND c.business_id=clc.business_id
         JOIN debtors_creditors financier ON financier.id=clc.financier_party_id AND financier.business_id=clc.business_id
         WHERE clc.business_id=? AND clc.status IN ('PENDING','PARTIAL')
         ORDER BY clc.approval_date",
        [$businessId]
    );
    foreach ($commissions as $commission) {
        $pending = max(0, (float)$commission['commission_amount'] - (float)$commission['received_amount']);
        if ($pending <= 0.009) continue;
        $ageDays = max(0, (int) floor((time() - strtotime($commission['approval_date'])) / 86400));
        $addItem(['type'=>'LOAN_COMMISSION','priority'=>$ageDays>=15?'HIGH':'MEDIUM','car_id'=>$commission['car_id'],'registration_no'=>$commission['registration_no'],'title'=>'Collect loan commission','detail'=>$commission['financier_name'] . ' · ' . $ageDays . ' day(s) pending','amount'=>$pending,'date'=>$commission['approval_date'],'owner'=>'Finance Desk','link'=>'../cars/loan_commission.php?car_id=' . urlencode($commission['car_id'])]);
    }
}

$query = trim((string) get('q', ''));
$type = strtoupper(trim((string) get('type', '')));
$priority = strtoupper(trim((string) get('priority', '')));
$items = array_values(array_filter($items, static function ($item) use ($query, $type, $priority) {
    $haystack = strtolower(implode(' ', [$item['registration_no'],$item['title'],$item['detail'],$item['owner']]));
    return ($query === '' || str_contains($haystack, strtolower($query)))
        && ($type === '' || $item['type'] === $type)
        && ($priority === '' || $item['priority'] === $priority);
}));
$priorityOrder = ['HIGH'=>1,'MEDIUM'=>2,'LOW'=>3];
usort($items, static fn($a,$b) => ($priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']]) ?: strcmp($a['date'],$b['date']));
$collectTotal = array_sum(array_map(static fn($item) => in_array($item['type'],['BUYER_DUE','ENTITY_RECEIVABLE','SOURCE_ADVANCE','LOAN_COMMISSION'],true)?(float)$item['amount']:0,$items));
$payTotal = array_sum(array_map(static fn($item) => $item['type']==='ENTITY_PAYABLE'?(float)$item['amount']:0,$items));
$highCount = count(array_filter($items,static fn($item)=>$item['priority']==='HIGH'));
$typeLabels = ['BUYER_DUE'=>'Buyer Collection','ENTITY_PAYABLE'=>'Entity Payment','ENTITY_RECEIVABLE'=>'Entity Collection','SOURCE_ADVANCE'=>'Source Advance Recovery','SETTLEMENT'=>'Legacy Settlement','RTO'=>'RTO','AGREEMENT'=>'Agreement','DELIVERY'=>'Delivery','LOAN_COMMISSION'=>'Loan Commission'];
?>

<div class="page-header"><div><h1><i class="ri-task-line"></i> Action Center</h1><p class="page-subtitle">One accountable queue for pending money, approvals, documents, RTO, and delivery work.</p></div></div>
<div class="stats-grid"><div class="stat-card"><div class="stat-value"><?= count($items) ?></div><div class="stat-label">Filtered Actions</div></div><div class="stat-card"><div class="stat-value flow-out"><?= $highCount ?></div><div class="stat-label">High Priority</div></div><div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($collectTotal) ?></div><div class="stat-label">Money To Collect</div></div><div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($payTotal) ?></div><div class="stat-label">Money To Pay</div></div></div>

<div class="filter-bar"><form method="get"><div><label class="form-label">Search queue</label><input type="search" name="q" class="form-control" value="<?= clean($query) ?>" placeholder="Car, entity, financier, action"></div><div><label class="form-label">Action Type</label><select name="type" class="form-control"><option value="">All actions</option><?php foreach($typeLabels as $value=>$label): ?><option value="<?= clean($value) ?>" <?= $type===$value?'selected':'' ?>><?= clean($label) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Priority</label><select name="priority" class="form-control"><option value="">All priorities</option><?php foreach(['HIGH','MEDIUM','LOW'] as $value): ?><option value="<?= $value ?>" <?= $priority===$value?'selected':'' ?>><?= clean(ucfirst(strtolower($value))) ?></option><?php endforeach; ?></select></div><button class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button><?php if($query!==''||$type!==''||$priority!==''): ?><a href="action_center.php" class="btn btn-ghost btn-sm">Clear all</a><?php endif; ?></form></div>

<div class="table-container table-container-fill"><table><thead><tr><th>Priority</th><th>Car</th><th>Required Action</th><th>Accountable Desk</th><th>Date / Age</th><th class="text-right">Amount</th><th></th></tr></thead><tbody>
<?php if(!$items): ?><tr><td colspan="7"><div class="table-filter-empty-state"><i class="ri-checkbox-circle-line"></i><strong>No matching pending actions</strong><span>Clear filters to check the full queue.</span></div></td></tr><?php endif; ?>
<?php foreach($items as $item): ?><tr><td><span class="badge <?= $item['priority']==='HIGH'?'badge-red':($item['priority']==='MEDIUM'?'badge-yellow':'badge-gray') ?>"><?= clean(ucfirst(strtolower($item['priority']))) ?></span></td><td><a class="text-bold" href="<?= clean($item['link']) ?>"><?= clean(formatRegistrationNo($item['registration_no'])) ?></a><div class="table-secondary"><?= clean($typeLabels[$item['type']] ?? $item['type']) ?></div></td><td><strong><?= clean($item['title']) ?></strong><div class="table-secondary"><?= clean($item['detail']) ?></div></td><td><?= clean($item['owner']) ?></td><td><?= $item['date']?formatDate($item['date']):'-' ?></td><td class="text-right amount <?= in_array($item['type'],['BUYER_DUE','ENTITY_RECEIVABLE','SOURCE_ADVANCE','LOAN_COMMISSION'],true)?'flow-in':($item['type']==='ENTITY_PAYABLE'?'flow-out':'') ?>"><?= (float)$item['amount']>0.009?formatAmount($item['amount']):'-' ?></td><td><a href="<?= clean($item['link']) ?>" class="btn btn-sm btn-outline">Open <i class="ri-arrow-right-line"></i></a></td></tr><?php endforeach; ?>
</tbody></table></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
