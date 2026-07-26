<?php
if(PHP_SAPI!=='cli')exit("CLI only.\n");
require_once __DIR__.'/../config/app.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/accounting_engine.php';
if(!APP_IS_TESTING||stripos(DB_NAME,'test')===false)exit("Refusing non-testing database.\n");
function outsideAssert($condition,$message){if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
$db=Database::getInstance();
$business=$db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user=$db->fetch("SELECT * FROM users WHERE business_id=? ORDER BY created_at LIMIT 1",[$business['id']]);
Auth::init();
$_SESSION=['user_id'=>$user['id'],'business_id'=>$business['id'],'username'=>$user['username'],'full_name'=>$user['full_name'],'role'=>$user['role'],'business_name'=>$business['name']];
$engine=new AccountingEngine($business['id'],$user['id']);
$cash=$db->fetch("SELECT id FROM accounts WHERE business_id=? AND entity_type='CASH' AND is_active=1 ORDER BY created_at LIMIT 1",[$business['id']]);
$expenseAccount=$db->fetch("SELECT id FROM accounts WHERE business_id=? AND group_name='EXPENSE' AND is_active=1 ORDER BY created_at LIMIT 1",[$business['id']]);
$suffix=strtoupper(substr(str_replace('-','',Database::uuid()),0,6));
$reg=static fn($state,$seed)=>$state.substr('ABCDEFGHIJKLMNOPQRSTUVWXYZ',abs(crc32($seed))%26,1).substr('0000'.strval((abs(crc32($seed.'n'))%9000)+1000),-4);
$db->beginTransaction();
try{
    $car1=$engine->createOutsideCar([
        'registration_no'=>$reg('GJ05A',$suffix.'one'),'received_date'=>date('Y-m-d'),'make'=>'Test','model'=>'Agency Fixed',
        'source_name'=>'Agency Source '.$suffix,'source_phone'=>'9876501234','source_kind'=>'OTHER_CAR_MELA',
        'expected_sale_value'=>500000,'commission_type'=>'FIXED','commission_value'=>20000,
    ]);
    $deal=$db->fetch("SELECT * FROM outside_car_deals WHERE car_id=?",[$car1]);
    outsideAssert($deal['accounting_model']==='COMMISSION_AGENCY'&&$deal['settlement_status']==='NOT_APPLICABLE','New Outside Car uses commission-agency model without settlement approval');
    $sourceId=$deal['source_entity_id'];

    $advanceEntry=$engine->recordOutsideSourceMovement($car1,700000,date('Y-m-d'),$cash['id'],'PAY_OR_ADVANCE','Owner payment before sale');
    $position=$engine->getOutsideSourcePosition($sourceId);
    outsideAssert(floatval($position['advance'])===700000.0&&floatval($position['payable'])===0.0,'Owner payment before sale becomes recoverable Source Advance without a cap');

    $sale1=$engine->recordOutsideCarSale($car1,[
        'sale_date'=>date('Y-m-d'),'vehicle_sale_price'=>500000,'discount_amount'=>0,'buyer_rto_charge'=>15000,
        'buyer_name'=>'Agency Buyer '.$suffix,'buyer_phone'=>'9876505678','amount_received_now'=>200000,
        'receiving_account'=>$cash['id'],'narration'=>'Fixed commission agency sale',
    ]);
    $saleRow=$db->fetch("SELECT * FROM outside_car_sales WHERE sale_entry_id=?",[$sale1]);
    outsideAssert(floatval($saleRow['buyer_total'])===515000.0,'Buyer total is C plus RTO; commission is not added again');
    outsideAssert(floatval($saleRow['separate_commission'])===20000.0&&floatval($saleRow['source_entity_entitlement'])===480000.0,'Fixed commission is included in C and Source Entity entitlement is C minus K');
    $saleAccounts=$db->fetchAll("SELECT a.code,jl.entry_type,jl.amount FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=?",[$sale1]);
    $byCode=[];foreach($saleAccounts as $line)$byCode[$line['code']]=[$line['entry_type'],floatval($line['amount'])];
    outsideAssert(!isset($byCode['CAR-REV'])&&($byCode['OUTCAR-COMM'][1]??0)===20000.0&&($byCode['RTO-REC'][1]??0)===15000.0,'Sale credits commission and RTO income while vehicle principal never enters ordinary sales revenue');
    $position=$engine->getOutsideSourcePosition($sourceId);
    outsideAssert(floatval($position['advance'])===220000.0&&floatval($position['payable'])===0.0,'Oldest Source Advance auto-applies to the first entitlement');

    $sourceParty=$db->fetch("SELECT party_id FROM source_entities WHERE id=?",[$sourceId]);
    $car2=$engine->createOutsideCar([
        'registration_no'=>$reg('GJ06B',$suffix.'two'),'received_date'=>date('Y-m-d'),'make'=>'Test','model'=>'Agency Cross Car',
        'source_party_id'=>$sourceParty['party_id'],'source_kind'=>'OTHER_CAR_MELA',
        'expected_sale_value'=>250000,'commission_type'=>'FIXED','commission_value'=>10000,
    ]);
    $sale2=$engine->recordOutsideCarSale($car2,[
        'sale_date'=>date('Y-m-d'),'vehicle_sale_price'=>250000,'discount_amount'=>0,'buyer_rto_charge'=>0,
        'buyer_name'=>'Second Buyer '.$suffix,'buyer_phone'=>'9876505679','amount_received_now'=>0,'receiving_account'=>'',
        'narration'=>'Second agency sale',
    ]);
    $position=$engine->getOutsideSourcePosition($sourceId);
    outsideAssert(floatval($position['advance'])===0.0&&floatval($position['payable'])===20000.0,'Excess advance nets FIFO against another car and leaves only the residual payable');
    $cross=$db->fetch("SELECT * FROM outside_source_allocations WHERE business_id=? AND origin_car_id=? AND target_car_id=? AND allocation_kind='ADVANCE_TO_PAYABLE' AND status='POSTED'",[$business['id'],$car1,$car2]);
    outsideAssert($cross&&floatval($cross['amount'])===220000.0,'Cross-car allocation preserves originating and adjusted car traceability');

    $mixedPayment=$engine->recordOutsideSourceMovement($car2,30000,date('Y-m-d'),$cash['id'],'PAY_OR_ADVANCE','Pay residual and create advance');
    $movement=$db->fetch("SELECT * FROM outside_source_movements WHERE journal_entry_id=?",[$mixedPayment]);
    outsideAssert(floatval($movement['payable_applied'])===20000.0&&floatval($movement['advance_created'])===10000.0,'One owner payment splits between payable and recoverable advance');
    $refund=$engine->recordOutsideSourceMovement($car2,10000,date('Y-m-d'),$cash['id'],'SOURCE_REFUND','Refund excess advance');
    $position=$engine->getOutsideSourcePosition($sourceId);
    outsideAssert(floatval($position['advance'])===0.0,'Source Entity refund clears the recoverable advance FIFO');
    $engine->reverseEntry($refund,'Restore refund lot for reversal regression');
    $position=$engine->getOutsideSourcePosition($sourceId);
    outsideAssert(floatval($position['advance'])===10000.0,'Source refund reversal restores the exact advance lot');

    $sourceExpense=$engine->recordOutsideCarExpense($car2,[
        'amount'=>5000,'gst_amount'=>0,'expense_date'=>date('Y-m-d'),'category'=>'Owner document expense',
        'vendor_name'=>'Test Vendor','voucher_no'=>'SRC-1','responsibility'=>'SOURCE_ENTITY',
        'payment_account'=>$cash['id'],'narration'=>'Paid for Source Entity',
    ]);
    $sourceExpenseMovement=$db->fetch("SELECT * FROM outside_source_movements WHERE journal_entry_id=?",[$sourceExpense]);
    outsideAssert(floatval($sourceExpenseMovement['advance_created'])===5000.0,'Source-borne expense above payable creates a recoverable Source Advance');

    $buyerBefore=$db->fetch("SELECT buyer_outstanding FROM outside_car_sales WHERE sale_entry_id=?",[$sale2]);
    $buyerExpense=$engine->recordOutsideCarExpense($car2,[
        'amount'=>3000,'gst_amount'=>0,'expense_date'=>date('Y-m-d'),'category'=>'Buyer accessory',
        'vendor_name'=>'Test Vendor','voucher_no'=>'BUY-1','responsibility'=>'BUYER',
        'payment_account'=>$cash['id'],'narration'=>'Buyer-borne expense',
    ]);
    $buyerAfter=$db->fetch("SELECT buyer_outstanding FROM outside_car_sales WHERE sale_entry_id=?",[$sale2]);
    outsideAssert(floatval($buyerAfter['buyer_outstanding'])-floatval($buyerBefore['buyer_outstanding'])===3000.0,'Buyer-borne expense increases this car buyer outstanding');

    $tirangaExpense=$engine->recordOutsideCarExpense($car2,[
        'amount'=>1180,'gst_amount'=>180,'expense_date'=>date('Y-m-d'),'category'=>'Tiranga office service',
        'vendor_name'=>'Test Vendor','voucher_no'=>'TCW-1','responsibility'=>'TIRANGA',
        'expense_account_id'=>$expenseAccount['id'],'payment_account'=>$cash['id'],'narration'=>'Tiranga-borne expense with GST',
    ]);
    $gstLine=$db->fetch("SELECT jl.amount FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=? AND a.code='GST-RCV'",[$tirangaExpense]);
    outsideAssert(floatval($gstLine['amount']??0)===180.0,'Tiranga-borne expense posts eligible GST Input separately');

    $rtoExpense=$engine->recordOutsideRtoMovement($car1,'PAY',12000,date('Y-m-d'),$cash['id'],'Outside RTO payment',0);
    $financial=$engine->getOutsideCarFinancials($car1);
    outsideAssert(floatval($financial['rto_income'])===15000.0&&floatval($financial['rto_expense'])===12000.0&&floatval($financial['rto_net'])===3000.0,'RTO income and expense remain separate in P&L and the car statement');
    $buyerPayment=$engine->recordOutsideBuyerPayment($car1,100000,date('Y-m-d'),$cash['id'],'Buyer installment after early owner payment');
    outsideAssert(!empty($buyerPayment),'Buyer installments remain independent of Source Entity payment timing');

    $car3=$engine->createOutsideCar([
        'registration_no'=>$reg('GJ07C',$suffix.'three'),'received_date'=>date('Y-m-d'),'make'=>'Test','model'=>'Agency Percent',
        'source_name'=>'Percent Source '.$suffix,'source_phone'=>'9876501111','source_kind'=>'DEALER',
        'expected_sale_value'=>600000,'commission_type'=>'PERCENT','commission_value'=>2,
    ]);
    $sale3=$engine->recordOutsideCarSale($car3,[
        'sale_date'=>date('Y-m-d'),'vehicle_sale_price'=>600000,'discount_amount'=>0,'buyer_rto_charge'=>0,
        'buyer_name'=>'Percent Buyer '.$suffix,'buyer_phone'=>'9876502222','amount_received_now'=>0,'receiving_account'=>'',
        'narration'=>'Percentage commission sale',
    ]);
    $percent=$db->fetch("SELECT separate_commission,source_entity_entitlement,buyer_total FROM outside_car_sales WHERE sale_entry_id=?",[$sale3]);
    outsideAssert(floatval($percent['separate_commission'])===12000.0&&floatval($percent['source_entity_entitlement'])===588000.0&&floatval($percent['buyer_total'])===600000.0,'Percentage commission uses C and remains included in buyer price');

    $blocked=false;
    try{$engine->reverseEntry($advanceEntry,'Must reverse allocations first');}catch(Throwable $e){$blocked=str_contains($e->getMessage(),'allocations');}
    outsideAssert($blocked,'Original Source Advance cannot reverse while cross-car allocations depend on it');

    $car4=$engine->createOutsideCar([
        'registration_no'=>$reg('GJ08D',$suffix.'four'),'received_date'=>date('Y-m-d'),'make'=>'Test','model'=>'Cancellation',
        'source_name'=>'Cancellation Source '.$suffix,'source_phone'=>'9876503333','source_kind'=>'INDIVIDUAL',
        'expected_sale_value'=>300000,'commission_type'=>'FIXED','commission_value'=>15000,
    ]);
    $sale4=$engine->recordOutsideCarSale($car4,[
        'sale_date'=>date('Y-m-d'),'vehicle_sale_price'=>300000,'discount_amount'=>0,'buyer_rto_charge'=>0,
        'buyer_name'=>'Cancellation Buyer '.$suffix,'buyer_phone'=>'9876504444','amount_received_now'=>0,'receiving_account'=>'',
        'narration'=>'Cancellation test sale',
    ]);
    $engine->recordOutsideSourceMovement($car4,100000,date('Y-m-d'),$cash['id'],'PAY_OR_ADVANCE','Owner paid before cancellation');
    $car4Deal=$db->fetch("SELECT source_entity_id FROM outside_car_deals WHERE car_id=?",[$car4]);
    $engine->cancelOutsideAgencySale($car4,'Buyer cancelled the transaction during testing');
    $cancelledSale=$db->fetch("SELECT status FROM outside_car_sales WHERE sale_entry_id=?",[$sale4]);
    $cancelPosition=$engine->getOutsideSourcePosition($car4Deal['source_entity_id']);
    outsideAssert($cancelledSale['status']==='REVERSED'&&floatval($cancelPosition['advance'])===100000.0,'Sale cancellation keeps already-paid owner money as recoverable Source Advance');

    $totals=$engine->getTrialBalance();$dr=0;$cr=0;
    foreach($totals as $row){if($row['balance_type']==='DR')$dr+=floatval($row['balance_amount']);else$cr+=floatval($row['balance_amount']);}
    outsideAssert(abs($dr-$cr)<0.01,'Trial Balance remains balanced across sale, advances, refunds, expenses, GST, RTO, and installments');
    $db->rollBack();
    echo "Outside Car commission-agency workflow regression completed and rolled back.\n";
}catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,"FAIL: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
