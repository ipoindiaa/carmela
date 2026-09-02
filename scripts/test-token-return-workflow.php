<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertTokenReturn(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$newEntrySource = file_get_contents($root . '/transactions/new.php');
$appJsSource = file_get_contents($root . '/assets/js/app.js');
$configSource = file_get_contents($root . '/config/app.php');
assertTokenReturn(
    str_contains($newEntrySource, 'value="TOKEN_REFUND"')
        && str_contains($newEntrySource, 'id="token-return-section"')
        && str_contains($newEntrySource, 'name="token_refund_car_id"')
        && str_contains($appJsSource, "'TOKEN_REFUND': ['token-return-section', 'buyer-identity-section']")
        && str_contains($configSource, "'TOKEN_REFUND' => ['label' => 'Return Car Token'") ,
    'Payments menu exposes Return Car Token with buyer and optional car controls'
);

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]);
$cash = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'CASH-001'", [$business['id']]);
assertTokenReturn($business && $user && $cash, 'Testing business, user, and cash account are available');

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
    $createCar = static function (string $prefix) use ($db, $engine, $business, $suffix, $date): string {
        $carId = Database::uuid();
        $registration = 'GJ99' . $prefix . random_int(1000, 9999);
        $accountId = $engine->createAccount('TOK-' . $prefix . '-' . $suffix, "Token Test Car - {$registration}", 'ASSET', 'Inventory', 'CAR', $carId);
        $db->insert('cars', [
            'id' => $carId,
            'business_id' => $business['id'],
            'registration_no' => $registration,
            'purchase_date' => $date,
            'purchase_price' => 0,
            'status' => 'IN_STOCK',
            'account_id' => $accountId,
        ]);
        return $carId;
    };

    $carA = $createCar('TR');
    $carB = $createCar('TS');
    $buyerId = $engine->getOrCreateParty('Token Return Buyer ' . $suffix, 'BUYER');

    $engine->receiveCarToken($carA, $buyerId, '', '', 6000, $date, $cash['id'], 'First token receipt');
    $engine->receiveCarToken($carB, $buyerId, '', '', 4000, $date, $cash['id'], 'Second token receipt');

    $acrossCarsRefundId = $engine->refundBuyerTokenBalance($buyerId, 7500, $date, $cash['id'], 'Buyer cancelled two bookings');
    $acrossCarsRefund = $db->fetch("SELECT car_id, party_id FROM journal_entries WHERE id = ?", [$acrossCarsRefundId]);
    $refundLines = $db->fetchAll("SELECT entry_type, amount FROM journal_lines WHERE journal_entry_id = ? ORDER BY entry_type", [$acrossCarsRefundId]);
    $refundAllocations = $db->fetchAll("SELECT amount FROM car_token_refunds WHERE journal_entry_id = ? ORDER BY amount", [$acrossCarsRefundId]);
    assertTokenReturn(empty($acrossCarsRefund['car_id']) && $acrossCarsRefund['party_id'] === $buyerId, 'Unselected-car token return stays linked to the buyer without a false car link');
    assertTokenReturn(count($refundLines) === 2 && floatval($refundLines[0]['amount']) === 7500.0 && floatval($refundLines[1]['amount']) === 7500.0, 'Token return posts one balanced ₹7,500 liability-clearance payment');
    assertTokenReturn(count($refundAllocations) === 2 && abs(array_sum(array_map(static fn($row) => floatval($row['amount']), $refundAllocations)) - 7500.0) < 0.01, 'Token return is allocated across the original buyer token receipts');
    $carAAvailable = $engine->getCarTokenAvailableForParty($carA, $buyerId);
    $carBAvailable = $engine->getCarTokenAvailableForParty($carB, $buyerId);
    assertTokenReturn(abs(($carAAvailable + $carBAvailable) - 2500.0) < 0.01 && (abs($carAAvailable) < 0.01 || abs($carBAvailable) < 0.01), 'Token allocation consumes complete receipts before leaving the correct buyer balance');

    $remainingCarId = $carAAvailable > 0.009 ? $carA : $carB;
    $carScopedRefundId = $engine->refundBuyerTokenBalance($buyerId, 1000, $date, $cash['id'], 'Partial return for selected car', $remainingCarId);
    $carScopedRefund = $db->fetch("SELECT car_id, party_id FROM journal_entries WHERE id = ?", [$carScopedRefundId]);
    $remainingCarSummary = $engine->getCarTokenSummary($remainingCarId);
    assertTokenReturn($carScopedRefund['car_id'] === $remainingCarId && $carScopedRefund['party_id'] === $buyerId, 'Selected-car token return is linked to both that car and buyer');
    assertTokenReturn(abs($remainingCarSummary['available'] - 1500.0) < 0.01, 'Car token summary separates returned value from the remaining available token');

    try {
        $engine->refundBuyerTokenBalance($buyerId, 1501, $date, $cash['id'], 'Over-refund attempt', $remainingCarId);
        throw new RuntimeException('Expected selected-car over-refund to be rejected.');
    } catch (Exception $expected) {
        assertTokenReturn(str_contains($expected->getMessage(), 'cannot exceed the available balance'), 'Token return blocks an amount above the buyer’s recorded open balance');
    }

    $db->rollBack();
    echo "Token return workflow regression checks completed and test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
