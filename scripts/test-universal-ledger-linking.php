<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertUll($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]);
$cash = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'CASH-001'", [$business['id']]);
assertUll($business && $user && $cash, 'Testing business, user, and cash account are available');

Auth::init();
$_SESSION = [
    'user_id' => $user['id'],
    'business_id' => $business['id'],
    'username' => $user['username'],
    'full_name' => $user['full_name'],
    'role' => $user['role'],
    'business_name' => $business['name'],
];

$engine = new AccountingEngine($business['id'], $user['id']);
$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 7));
$date = date('Y-m-d');

$db->beginTransaction();
try {
    $equityId = $engine->createAccount('ULL-EQ-' . $suffix, 'ULL Test Funding', 'EQUITY', 'Owner Capital', 'GENERAL');
    $engine->postJournalEntry('OPENING_BALANCE', $date, 'ULL test working capital', [
        ['account_id' => $cash['id'], 'amount' => 1000000, 'type' => 'DR'],
        ['account_id' => $equityId, 'amount' => 1000000, 'type' => 'CR'],
    ]);

    $carId = Database::uuid();
    $registration = 'GJ99UL' . random_int(1000, 9999);
    $carAccountId = $engine->createAccount('ULL-CAR-' . $suffix, "ULL Car - $registration", 'ASSET', 'Inventory', 'CAR', $carId);
    $db->insert('cars', [
        'id' => $carId,
        'business_id' => $business['id'],
        'registration_no' => $registration,
        'make' => 'ULL',
        'model' => 'Regression',
        'purchase_date' => $date,
        'purchase_price' => 450000,
        'status' => 'IN_STOCK',
        'account_id' => $carAccountId,
    ]);

    $purchaseId = $engine->carPurchase($carId, 450000, $date, $cash['id'], 'ULL purchase with paid and payable seller amounts', [], 'ULL Seller ' . $suffix, 300000, [
        'name' => 'ULL Purchase Dealer ' . $suffix,
        'commission' => 15000,
        'paid_now' => 5000,
        'payment_account' => $cash['id'],
    ]);
    $car = $db->fetch("SELECT seller_party_id, purchase_dealer_party_id FROM cars WHERE id = ?", [$carId]);
    $seller = $db->fetch("SELECT account_id FROM debtors_creditors WHERE id = ?", [$car['seller_party_id']]);
    $sellerLines = $db->fetchAll(
        "SELECT entry_type, amount FROM journal_lines WHERE journal_entry_id = ? AND account_id = ? ORDER BY CASE WHEN entry_type = 'DR' THEN 0 ELSE 1 END, amount",
        [$purchaseId, $seller['account_id']]
    );
    assertUll(count($sellerLines) === 2, 'Purchase posts both seller liability and immediate seller payment to the source ledger');
    assertUll(
        floatval($sellerLines[0]['amount']) === 300000.0 && floatval($sellerLines[1]['amount']) === 450000.0,
        'Seller ledger preserves the ₹3,00,000 payment and ₹4,50,000 purchase obligation'
    );
    $purchase = $db->fetch("SELECT car_id, party_id FROM journal_entries WHERE id = ?", [$purchaseId]);
    assertUll($purchase['car_id'] === $carId && $purchase['party_id'] === $car['seller_party_id'], 'Purchase journal carries both car and seller dimensions');

    $dealer = $db->fetch("SELECT account_id FROM debtors_creditors WHERE id = ?", [$car['purchase_dealer_party_id']]);
    $dealerSettlement = $engine->getCarDealerSettlement($carId);
    assertUll($car['purchase_dealer_party_id'] !== null && $dealerSettlement['dealer']['id'] === $car['purchase_dealer_party_id'], 'Purchase dealer is linked to the car as a separate party from the owner');
    assertUll(
        abs(floatval($dealerSettlement['commission_total']) - 15000.0) < 0.01
        && abs(floatval($dealerSettlement['paid_total']) - 5000.0) < 0.01
        && abs(floatval($dealerSettlement['pending']) - 10000.0) < 0.01,
        'Dealer commission tracks the payable, immediate payment, and remaining balance separately from the purchase price'
    );
    $dealerPayableLine = $db->fetch(
        "SELECT amount FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE je.business_id = ? AND je.car_id = ? AND jl.account_id = ? AND je.transaction_type = 'DEALER_COMMISSION' AND jl.entry_type = 'CR'
         LIMIT 1",
        [$business['id'], $carId, $dealer['account_id']]
    );
    assertUll(abs(floatval($dealerPayableLine['amount'] ?? 0) - 15000.0) < 0.01, 'Dealer ledger receives only the commission payable, never the owner purchase amount');

    // Simulate a legacy car whose journal has the seller payable but whose
    // seller link was never backfilled. The first installment must repair only
    // that missing relationship, then every later installment stays available.
    $db->query("UPDATE cars SET seller_party_id = NULL WHERE id = ?", [$carId]);
    $firstSellerClearId = $engine->loanRepaid($car['seller_party_id'], 50000, $date, $cash['id'], 'ULL first purchase balance payment', $carId);
    $firstPurchasePending = $engine->getCarPendingAmounts($carId);
    $firstSellerClear = $db->fetch("SELECT car_id, party_id FROM journal_entries WHERE id = ?", [$firstSellerClearId]);
    $relinkedCar = $db->fetch("SELECT seller_party_id FROM cars WHERE id = ?", [$carId]);
    assertUll(abs(floatval($firstPurchasePending['purchase_pending']) - 100000.0) < 0.01, 'First partial purchase payment leaves the correct remaining amount on the bought car');
    assertUll($firstSellerClear['car_id'] === $carId && $firstSellerClear['party_id'] === $car['seller_party_id'] && $relinkedCar['seller_party_id'] === $car['seller_party_id'], 'First purchase installment stays linked to and repairs the car seller relationship');

    $engine->loanRepaid($car['seller_party_id'], 25000, $date, $cash['id'], 'ULL second purchase balance payment', $carId);
    $secondPurchasePending = $engine->getCarPendingAmounts($carId);
    assertUll(abs(floatval($secondPurchasePending['purchase_pending']) - 75000.0) < 0.01, 'Second purchase installment remains available against the same car');
    $engine->loanRepaid($car['seller_party_id'], 75000, $date, $cash['id'], 'ULL final purchase balance payment', $carId);
    $finalPurchasePending = $engine->getCarPendingAmounts($carId);
    assertUll($finalPurchasePending['purchase_pending'] < 0.01, 'Final purchase balance payment clears the bought car payable');

    $dealerPaymentId = $engine->payPurchaseDealerCommission($carId, 10000, $date, $cash['id'], 'ULL final dealer commission payment');
    $finalDealerPending = $engine->getCarPendingAmounts($carId);
    $dealerPayment = $db->fetch("SELECT car_id, party_id FROM journal_entries WHERE id = ?", [$dealerPaymentId]);
    $costAfterDealerCommission = $engine->getCarProfitability($carId);
    assertUll($finalDealerPending['dealer_pending'] < 0.01, 'Final dealer commission payment clears only the dealer payable for this car');
    assertUll($dealerPayment['car_id'] === $carId && $dealerPayment['party_id'] === $car['purchase_dealer_party_id'], 'Dealer commission payment stays linked to its car and dealer ledger');
    assertUll(abs(floatval($costAfterDealerCommission['total_cost']) - 465000.0) < 0.01, 'Dealer commission is capitalized into the bought car total cost once, not treated as an owner payment');

    $profitabilityBeforeReferencePrice = $engine->getCarProfitability($carId);
    $db->query("UPDATE cars SET expected_sale_price = ? WHERE id = ?", [9999999, $carId]);
    $profitabilityAfterReferencePrice = $engine->getCarProfitability($carId);
    assertUll(
        abs(floatval($profitabilityBeforeReferencePrice['total_cost']) - floatval($profitabilityAfterReferencePrice['total_cost'])) < 0.01
        && abs(floatval($profitabilityBeforeReferencePrice['profit']) - floatval($profitabilityAfterReferencePrice['profit'])) < 0.01,
        'Reference selling price is excluded from car cost and profit calculations'
    );

    $increaseCorrectionId = $engine->correctCarPurchaseAmount($carId, 475000, $date, 'Purchase deal was entered ₹25,000 too low.');
    $increasedCar = $db->fetch("SELECT purchase_price FROM cars WHERE id = ?", [$carId]);
    $increasedPending = $engine->getCarPendingAmounts($carId);
    $increaseLines = $db->fetchAll("SELECT account_id, entry_type, amount FROM journal_lines WHERE journal_entry_id = ? ORDER BY entry_type", [$increaseCorrectionId]);
    assertUll(abs(floatval($increasedCar['purchase_price']) - 475000.0) < 0.01 && abs(floatval($increasedPending['purchase_pending']) - 25000.0) < 0.01, 'Increasing a car purchase amount updates the car price and creates only the additional seller payable');
    assertUll(count($increaseLines) === 2 && in_array('DR', array_column($increaseLines, 'entry_type'), true) && in_array('CR', array_column($increaseLines, 'entry_type'), true), 'Purchase amount increase is a balanced car-and-seller journal correction with no cash line');
    $engine->loanRepaid($car['seller_party_id'], 25000, $date, $cash['id'], 'ULL corrected purchase balance payment', $carId);

    $decreaseCorrectionId = $engine->correctCarPurchaseAmount($carId, 450000, $date, 'Purchase deal was entered ₹25,000 too high.');
    $decreasedCar = $db->fetch("SELECT purchase_price FROM cars WHERE id = ?", [$carId]);
    $decreasedPending = $engine->getCarPendingAmounts($carId);
    $decreaseLines = $db->fetchAll("SELECT account_id, entry_type, amount FROM journal_lines WHERE journal_entry_id = ? ORDER BY entry_type", [$decreaseCorrectionId]);
    $costAfterPurchaseCorrection = $engine->getCarProfitability($carId);
    assertUll(abs(floatval($decreasedCar['purchase_price']) - 450000.0) < 0.01 && $decreasedPending['purchase_pending'] < 0.01, 'Reducing a fully paid purchase value records the seller refund / advance without creating a false payable');
    assertUll(count($decreaseLines) === 2 && in_array('DR', array_column($decreaseLines, 'entry_type'), true) && in_array('CR', array_column($decreaseLines, 'entry_type'), true), 'Purchase amount decrease is a balanced seller-and-car journal correction with no cash line');
    assertUll(abs(floatval($costAfterPurchaseCorrection['total_cost']) - 465000.0) < 0.01, 'Car profitability uses the corrected signed vehicle cost after purchase amount changes');

    $paymentBasedCarId = Database::uuid();
    $paymentBasedRegistration = 'GJ99PB' . random_int(1000, 9999);
    $paymentBasedCarAccountId = $engine->createAccount('ULL-PB-' . $suffix, "ULL Payment-Based Car - $paymentBasedRegistration", 'ASSET', 'Inventory', 'CAR', $paymentBasedCarId);
    $db->insert('cars', [
        'id' => $paymentBasedCarId,
        'business_id' => $business['id'],
        'registration_no' => $paymentBasedRegistration,
        'make' => 'ULL',
        'model' => 'Payment based purchase',
        'purchase_date' => $date,
        'purchase_price' => 30000,
        'purchase_paid_amount' => 30000,
        'purchase_amount_mode' => 'PAYMENTS',
        'status' => 'IN_STOCK',
        'account_id' => $paymentBasedCarAccountId,
    ]);
    $engine->carPurchase($paymentBasedCarId, 30000, $date, $cash['id'], 'ULL first payment-based car purchase', [], 'ULL Payment-Based Seller ' . $suffix, 30000);
    $paymentBasedCar = $db->fetch("SELECT * FROM cars WHERE id = ?", [$paymentBasedCarId]);
    $paymentBasedSellerId = $paymentBasedCar['seller_party_id'];
    $paymentBasedEntryId = $engine->loanRepaid($paymentBasedSellerId, 12000, $date, $cash['id'], 'ULL second payment increases purchase amount', $paymentBasedCarId);
    $paymentBasedCar = $db->fetch("SELECT purchase_price, purchase_paid_amount, seller_party_id FROM cars WHERE id = ?", [$paymentBasedCarId]);
    $paymentBasedPending = $engine->getCarPendingAmounts($paymentBasedCarId);
    $paymentBasedLines = $db->fetchAll("SELECT entry_type, amount FROM journal_lines WHERE journal_entry_id = ? ORDER BY entry_type, amount", [$paymentBasedEntryId]);
    assertUll(
        abs(floatval($paymentBasedCar['purchase_price']) - 42000.0) < 0.01
        && abs(floatval($paymentBasedCar['purchase_paid_amount']) - 42000.0) < 0.01
        && $paymentBasedCar['seller_party_id'] === $paymentBasedSellerId,
        'A later car purchase payment increases the payment-based purchase amount instead of requiring a fixed pending balance'
    );
    assertUll($paymentBasedPending['purchase_pending'] < 0.01 && count($paymentBasedLines) === 4, 'Payment-based purchase payment keeps the seller ledger settled and posts a four-line balanced voucher');
    $engine->reverseEntry($paymentBasedEntryId, 'ULL reverse payment-based purchase payment', $date);
    $paymentBasedCarAfterReversal = $db->fetch("SELECT purchase_price, purchase_paid_amount FROM cars WHERE id = ?", [$paymentBasedCarId]);
    assertUll(
        abs(floatval($paymentBasedCarAfterReversal['purchase_price']) - 30000.0) < 0.01
        && abs(floatval($paymentBasedCarAfterReversal['purchase_paid_amount']) - 30000.0) < 0.01,
        'Reversing a payment-based purchase payment removes it from the car purchase amount'
    );
    try {
        $engine->correctCarPurchaseAmount($paymentBasedCarId, 50000, $date, 'This must use another payment instead.');
        throw new RuntimeException('Expected payment-based purchase amount correction to be blocked.');
    } catch (Exception $expected) {
        assertUll(str_contains($expected->getMessage(), 'calculated from recorded owner payments'), 'Payment-based cars direct operators to Add Purchase Payment instead of a fixed-price correction');
    }

    $legacyCarId = Database::uuid();
    $legacyRegistration = 'GJ99LH' . random_int(1000, 9999);
    $legacyCarAccountId = $engine->createAccount('ULL-LEG-' . $suffix, "ULL Legacy Car - $legacyRegistration", 'ASSET', 'Inventory', 'CAR', $legacyCarId);
    $db->insert('cars', [
        'id' => $legacyCarId,
        'business_id' => $business['id'],
        'registration_no' => $legacyRegistration,
        'make' => 'ULL',
        'model' => 'Legacy repair',
        'purchase_date' => $date,
        'purchase_price' => 105000,
        'purchase_paid_amount' => 105000,
        'status' => 'IN_STOCK',
        'account_id' => $legacyCarAccountId,
    ]);
    $engine->postJournalEntry('CAR_PURCHASE', $date, 'Legacy purchase incorrectly entered as fully paid', [
        ['account_id' => $legacyCarAccountId, 'amount' => 105000, 'type' => 'DR'],
        ['account_id' => $cash['id'], 'amount' => 105000, 'type' => 'CR'],
    ], ['car_id' => $legacyCarId]);
    $legacySellerId = $engine->getOrCreateParty('ULL Legacy Seller ' . $suffix, 'SELLER');
    $repairId = $engine->repairHistoricalCarPurchasePayment($legacyCarId, $legacySellerId, 55000, $cash['id'], $date, '₹55,000 of the original purchase was not actually paid.');
    $legacyCar = $db->fetch("SELECT seller_party_id, purchase_paid_amount FROM cars WHERE id = ?", [$legacyCarId]);
    $legacyPending = $engine->getCarPendingAmounts($legacyCarId);
    $legacySeller = $db->fetch("SELECT account_id FROM debtors_creditors WHERE id = ?", [$legacySellerId]);
    $legacyRepairLines = $db->fetchAll(
        "SELECT entry_type, amount FROM journal_lines WHERE journal_entry_id = ? AND account_id = ? ORDER BY entry_type, amount",
        [$repairId, $legacySeller['account_id']]
    );
    assertUll($legacyCar['seller_party_id'] === $legacySellerId && abs(floatval($legacyCar['purchase_paid_amount']) - 50000.0) < 0.01, 'Historical repair links the seller and preserves only the amount actually paid at purchase');
    assertUll(abs(floatval($legacyPending['purchase_pending']) - 55000.0) < 0.01, 'Historical repair creates the correct car-linked seller balance pending');
    $legacyRepairPayable = array_values(array_filter($legacyRepairLines, static fn($line) => $line['entry_type'] === 'CR'));
    $legacyRepairPaid = array_values(array_filter($legacyRepairLines, static fn($line) => $line['entry_type'] === 'DR'));
    assertUll(count($legacyRepairPayable) === 1 && count($legacyRepairPaid) === 1 && floatval($legacyRepairPayable[0]['amount']) === 105000.0 && floatval($legacyRepairPaid[0]['amount']) === 50000.0, 'Historical repair reconstructs both the seller payable and the original direct seller payment');

    $saleId = $engine->carSale($carId, 575000, $date, $cash['id'], 'ULL sale with buyer receivable', 'ULL Buyer ' . $suffix, 200000);
    $timeline = $engine->getCarTimeline($carId);
    $saleTimeline = array_values(array_filter($timeline, static fn($row) => $row['entry_id'] === $saleId));
    assertUll(count($saleTimeline) === 1 && floatval($saleTimeline[0]['cash_in_amount']) === 200000.0, 'Car timeline keeps the ₹2,00,000 actual sale receipt distinct from the buyer receivable');

    $pnl = $engine->getProfitAndLoss($date, $date);
    $cogs = array_values(array_filter($pnl['expenses'], static fn($row) => ($row['code'] ?? '') === 'COGS'));
    assertUll(count($cogs) === 1 && floatval($cogs[0]['amount']) === 465000.0, 'Profit and Loss includes the purchase price plus dealer commission as Cost of Cars Sold');
    assertUll(abs(floatval($pnl['net_profit']) - 110000.0) < 0.01, 'Profit and Loss reports sale revenue less the complete car cost');

    $engine->reverseEntry($saleId, 'ULL regression reversal', $date);
    $reversedPnl = $engine->getProfitAndLoss($date, $date);
    $reversedCogs = array_values(array_filter($reversedPnl['expenses'], static fn($row) => ($row['code'] ?? '') === 'COGS'));
    assertUll(empty($reversedCogs) && abs(floatval($reversedPnl['net_profit'])) < 0.01, 'Reversing the sale also removes its Cost of Cars Sold from Profit and Loss');

    $db->rollBack();
    echo "Universal ledger linking regression checks completed and test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
