<?php
if(PHP_SAPI!=='cli')exit("CLI only.\n");
require_once __DIR__.'/../config/app.php';require_once __DIR__.'/../includes/db.php';require_once __DIR__.'/../includes/functions.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/accounting_engine.php';
if(!APP_IS_TESTING||stripos(DB_NAME,'test')===false)exit("Refusing non-testing database.\n");
function loanCommissionAssert($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$db=Database::getInstance();$business=$db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");$user=$db->fetch("SELECT * FROM users WHERE business_id=? ORDER BY created_at LIMIT 1",[$business['id']]);
Auth::init();$_SESSION=['user_id'=>$user['id'],'business_id'=>$business['id'],'username'=>$user['username'],'full_name'=>$user['full_name'],'role'=>$user['role'],'business_name'=>$business['name']];
$engine=new AccountingEngine($business['id'],$user['id']);$cash=$db->fetch("SELECT id FROM accounts WHERE business_id=? AND entity_type='CASH' AND is_active=1 LIMIT 1",[$business['id']]);
$car=$db->fetch("SELECT * FROM cars WHERE business_id=? AND ownership_type='OWNED' AND buyer_party_id IS NOT NULL AND status IN ('SOLD','PENDING_PAYMENT') LIMIT 1",[$business['id']]);
loanCommissionAssert($car&&$cash,'Sold test car and Cash account exist');
$before=$engine->getCarProfitability($car['id']);$suffix=strtoupper(substr(str_replace('-','',Database::uuid()),0,7));$db->beginTransaction();
try{
    $caseId=$engine->createCarLoanCommission($car['id'],['financier_name'=>'Test Finance '.$suffix,'financier_phone'=>'9876504321','loan_account_no'=>'LN-'.$suffix,'approval_date'=>date('Y-m-d'),'loan_amount'=>500000,'commission_type'=>'PERCENT','commission_value'=>2,'received_now'=>4000,'receiving_account_id'=>$cash['id'],'notes'=>'Approved car loan commission test']);
    $case=$db->fetch("SELECT * FROM car_loan_commissions WHERE id=?",[$caseId]);
    loanCommissionAssert(floatval($case['commission_amount'])===10000.0,'Percentage commission is calculated from customer loan amount');
    loanCommissionAssert(floatval($case['received_amount'])===4000.0&&$case['status']==='PARTIAL','Initial receipt leaves exact finance-company receivable pending');
    $incomeLine=$db->fetch("SELECT jl.amount,jl.entry_type FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=? AND a.code='LOAN-COMM'",[$case['accrual_entry_id']]);
    loanCommissionAssert(floatval($incomeLine['amount']??0)===10000.0&&$incomeLine['entry_type']==='CR','Only loan commission credits income; customer loan principal does not');
    $profitability=$engine->getCarProfitability($car['id']);
    loanCommissionAssert(floatval($profitability['loan_commission_income'])===10000.0&&abs(($profitability['profit']-$before['profit'])-10000)<0.01,'Car profitability includes its earned loan commission');
    $receiptId=$engine->recordCarLoanCommissionReceipt($caseId,6000,date('Y-m-d'),$cash['id'],'Final finance-company commission receipt');
    $case=$db->fetch("SELECT * FROM car_loan_commissions WHERE id=?",[$caseId]);loanCommissionAssert($case['status']==='RECEIVED'&&floatval($case['received_amount'])===10000.0,'Partial receipts settle the car-wise commission exactly');
    $blocked=false;try{$engine->reverseEntry($case['accrual_entry_id'],'Should be dependency blocked');}catch(Throwable $e){$blocked=str_contains($e->getMessage(),'receipts');}loanCommissionAssert($blocked,'Earned commission reversal is blocked until receipts are reversed');
    $initialReceipt=$db->fetch("SELECT journal_entry_id FROM car_loan_commission_receipts WHERE commission_id=? AND journal_entry_id<>? LIMIT 1",[$caseId,$receiptId]);
    $engine->reverseEntry($receiptId,'Loan commission test cleanup');$engine->reverseEntry($initialReceipt['journal_entry_id'],'Loan commission test cleanup');$engine->reverseEntry($case['accrual_entry_id'],'Loan commission test cleanup');
    $case=$db->fetch("SELECT status,received_amount FROM car_loan_commissions WHERE id=?",[$caseId]);loanCommissionAssert($case['status']==='REVERSED'&&floatval($case['received_amount'])===0.0,'Dependency-ordered reversal restores loan commission state');
    $tb=$engine->getTrialBalance();$dr=0;$cr=0;foreach($tb as $row){if($row['balance_type']==='DR')$dr+=floatval($row['balance_amount']);else$cr+=floatval($row['balance_amount']);}loanCommissionAssert(abs($dr-$cr)<0.01,'Trial Balance remains balanced');
    $db->rollBack();echo "Car loan commission workflow completed and rolled back.\n";
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,"FAIL: {$e->getMessage()}\n{$e->getTraceAsString()}\n");exit(1);}
