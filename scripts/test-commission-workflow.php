<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertCommission($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]);
Auth::init();
$_SESSION['user_id'] = $user['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['business_name'] = $business['name'];

$engine = new AccountingEngine($business['id'], $user['id']);
$cash = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'CASH-001'", [$business['id']]);
$commissionAccount = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'SALE-COMM'", [$business['id']]);
$revenueAccount = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'CAR-REV'", [$business['id']]);
$commissionBefore = floatval($commissionAccount['current_balance']);
$revenueBefore = floatval($revenueAccount['current_balance']);

$ownerId = $engine->getOrCreateParty('Commission Test Owner', 'SELLER', '9876500001');
$buyerId = $engine->getOrCreateParty('Commission Test Buyer', 'BUYER', '9876500002');
$owner = $db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$ownerId]);

$carOne = $engine->createCommissionCar([
    'registration_no' => 'GJ99CM0001', 'received_date' => date('Y-m-d'), 'make' => 'Test', 'model' => 'Direct',
    'owner_party_id' => $ownerId, 'expected_sale_price' => 500000, 'expected_commission_amount' => 40000,
]);
$engine->commissionCarSale($carOne, 500000, 40000, date('Y-m-d'), $cash['id'], 'COMMISSION_ONLY', 'Commission-only regression sale', $buyerId);

$commissionAccount = $db->fetch("SELECT * FROM accounts WHERE id = ?", [$commissionAccount['id']]);
$revenueAccount = $db->fetch("SELECT * FROM accounts WHERE id = ?", [$revenueAccount['id']]);
$settlementOne = $engine->getCommissionSettlement($carOne);
assertCommission(abs(floatval($commissionAccount['current_balance']) - ($commissionBefore + 40000)) < 0.01, 'Only ₹40,000 commission entered income on ₹5,00,000 memorandum sale');
assertCommission(abs(floatval($revenueAccount['current_balance']) - $revenueBefore) < 0.01, 'Gross memorandum value did not enter Car Sales Revenue');
assertCommission($settlementOne['status'] === 'NOT_APPLICABLE' && floatval($settlementOne['paid_to_owner_amount']) === 0.0, 'Direct-to-owner sale created no owner payable');

$carTwo = $engine->createCommissionCar([
    'registration_no' => 'GJ99CM0002', 'received_date' => date('Y-m-d'), 'make' => 'Test', 'model' => 'Handled',
    'owner_party_id' => $ownerId, 'expected_sale_price' => 600000, 'expected_commission_amount' => 50000,
]);
$saleEntry = $engine->commissionCarSale($carTwo, 600000, 50000, date('Y-m-d'), $cash['id'], 'FULL_AMOUNT', 'Full collection regression sale', $buyerId);
$settlementTwo = $engine->getCommissionSettlement($carTwo);
$ownerAccount = $db->fetch("SELECT * FROM accounts WHERE id = ?", [$owner['account_id']]);
assertCommission(abs(floatval($ownerAccount['current_balance']) - 550000) < 0.01 && $ownerAccount['current_balance_type'] === 'CR', 'Full buyer collection created ₹5,50,000 payable to owner');
assertCommission($settlementTwo['status'] === 'PENDING' && floatval($settlementTwo['owner_amount']) === 550000.0, 'Owner settlement started pending at the correct amount');

$genericPaymentBlocked = false;
try { $engine->loanRepaid($ownerId, 1000, date('Y-m-d'), $cash['id'], 'Incorrect generic owner payment'); } catch (Throwable $e) { $genericPaymentBlocked = str_contains($e->getMessage(), 'Commission Cars'); }
assertCommission($genericPaymentBlocked, 'Generic creditor payment cannot bypass per-car owner settlement history');

$paymentEntry = $engine->payCommissionCarOwner($carTwo, 200000, date('Y-m-d'), $cash['id'], 'Partial owner payment regression');
$settlementTwo = $engine->getCommissionSettlement($carTwo);
assertCommission(floatval($settlementTwo['paid_to_owner_amount']) === 200000.0 && $settlementTwo['status'] === 'PARTIAL', 'Partial owner payment updated per-car settlement');

$guarded = false;
try { $engine->reverseEntry($saleEntry, 'Regression reversal guard'); } catch (Throwable $e) { $guarded = str_contains($e->getMessage(), 'owner payments'); }
assertCommission($guarded, 'Sale reversal is blocked while owner payments exist');

$engine->reverseEntry($paymentEntry, 'Regression owner payment reversal');
$settlementTwo = $engine->getCommissionSettlement($carTwo);
assertCommission(floatval($settlementTwo['paid_to_owner_amount']) === 0.0 && $settlementTwo['status'] === 'PENDING', 'Owner payment reversal restored the payable');

$engine->reverseEntry($saleEntry, 'Regression commission sale reversal');
$reversedCar = $db->fetch("SELECT * FROM cars WHERE id = ?", [$carTwo]);
$reversedSettlement = $db->fetch("SELECT * FROM commission_car_settlements WHERE id = ?", [$settlementTwo['id']]);
assertCommission($reversedCar['status'] === 'IN_STOCK' && $reversedSettlement['status'] === 'REVERSED', 'Sale reversal returned commission car to stock and closed settlement');

echo "Commission workflow regression checks completed.\n";
