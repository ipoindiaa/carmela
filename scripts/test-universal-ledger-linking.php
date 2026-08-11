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

    $purchaseId = $engine->carPurchase($carId, 450000, $date, $cash['id'], 'ULL purchase with paid and payable seller amounts', [], 'ULL Seller ' . $suffix, 300000);
    $car = $db->fetch("SELECT seller_party_id FROM cars WHERE id = ?", [$carId]);
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

    $saleId = $engine->carSale($carId, 575000, $date, $cash['id'], 'ULL sale with buyer receivable', 'ULL Buyer ' . $suffix, 200000);
    $timeline = $engine->getCarTimeline($carId);
    $saleTimeline = array_values(array_filter($timeline, static fn($row) => $row['entry_id'] === $saleId));
    assertUll(count($saleTimeline) === 1 && floatval($saleTimeline[0]['cash_in_amount']) === 200000.0, 'Car timeline keeps the ₹2,00,000 actual sale receipt distinct from the buyer receivable');

    $pnl = $engine->getProfitAndLoss($date, $date);
    $cogs = array_values(array_filter($pnl['expenses'], static fn($row) => ($row['code'] ?? '') === 'COGS'));
    assertUll(count($cogs) === 1 && floatval($cogs[0]['amount']) === 450000.0, 'Profit and Loss includes the full car cost as Cost of Cars Sold');
    assertUll(abs(floatval($pnl['net_profit']) - 125000.0) < 0.01, 'Profit and Loss reports sale revenue less Cost of Cars Sold');

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
