<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
ob_start(); // Keep lookup endpoint headers writable while this CLI test emits assertions.

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertOutsidePicker(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

function loadCarPicker(string $root, array $params): array {
    $previousGet = $_GET;
    $_GET = $params;
    ob_start();
    require $root . '/transactions/search_entities.php';
    $json = ob_get_clean();
    $_GET = $previousGet;
    $payload = json_decode($json, true);
    if (!is_array($payload)) throw new RuntimeException('Car picker did not return JSON.');
    return $payload['results'] ?? [];
}

$root = dirname(__DIR__);
$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]);
assertOutsidePicker($business && $user, 'Testing business and user are available');

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
$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 4));
$registration = 'GJ99OC' . str_pad((string) (hexdec($suffix) % 10000), 4, '0', STR_PAD_LEFT);

$db->beginTransaction();
try {
    $ownerId = $engine->getOrCreateParty('Outside Picker Owner ' . $suffix, 'SELLER');
    $outsideCarId = $engine->createOutsideCar([
        'registration_no' => $registration,
        'received_date' => date('Y-m-d'),
        'owner_party_id' => $ownerId,
        'make' => 'Outside',
        'model' => 'Picker Test',
    ]);
    $soldCarId = Database::uuid();
    $soldRegistration = 'GJ99SC' . str_pad((string) ((hexdec($suffix) + 1) % 10000), 4, '0', STR_PAD_LEFT);
    $soldCarAccountId = $engine->createAccount('OUT-SOLD-' . $suffix, "Sold Picker Car - $soldRegistration", 'ASSET', 'Inventory', 'CAR', $soldCarId);
    $db->insert('cars', [
        'id' => $soldCarId,
        'business_id' => $business['id'],
        'registration_no' => $soldRegistration,
        'make' => 'Sold',
        'model' => 'Picker Test',
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 0,
        'status' => 'SOLD',
        'account_id' => $soldCarAccountId,
    ]);

    $rtoResults = loadCarPicker($root, ['kind' => 'rto_car', 'q' => $registration, 'context' => 'RTO_RECOVERY']);
    $expenseResults = loadCarPicker($root, ['kind' => 'car', 'q' => $registration, 'context' => 'CAR_EXPENSE']);
    $paymentResults = loadCarPicker($root, ['kind' => 'payment_car', 'q' => $registration, 'context' => 'LOAN_REPAID']);
    $saleResults = loadCarPicker($root, ['kind' => 'car', 'q' => $registration, 'context' => 'CAR_SALE']);
    $find = static fn(array $items) => array_values(array_filter($items, static fn($item) => ($item['id'] ?? '') === $outsideCarId));

    $rtoMatch = $find($rtoResults);
    $expenseMatch = $find($expenseResults);
    $paymentMatch = $find($paymentResults);
    assertOutsidePicker(count($rtoMatch) === 1 && str_contains($rtoMatch[0]['label'] ?? '', 'Outside Car'), 'RTO car picker includes and labels outside cars');
    assertOutsidePicker(count($expenseMatch) === 1 && str_contains($expenseMatch[0]['meta'] ?? '', 'Outside Car'), 'Car expense picker includes and labels outside cars');
    assertOutsidePicker(count($paymentMatch) === 1 && str_contains($paymentMatch[0]['label'] ?? '', 'Outside Car'), 'Payment car picker includes and labels outside cars');
    $outsideSaleMatch = $find($saleResults);
    assertOutsidePicker(count($outsideSaleMatch) === 1 && ($outsideSaleMatch[0]['selectable'] ?? true) === false && str_contains($outsideSaleMatch[0]['meta'] ?? '', 'Unavailable for a new sale'), 'Outside cars remain visible but are blocked from an owned-inventory sale');

    $soldRtoResults = loadCarPicker($root, ['kind' => 'rto_car', 'q' => $soldRegistration, 'context' => 'RTO_RECOVERY']);
    $soldExpenseResults = loadCarPicker($root, ['kind' => 'car', 'q' => $soldRegistration, 'context' => 'CAR_EXPENSE']);
    $soldSaleResults = loadCarPicker($root, ['kind' => 'car', 'q' => $soldRegistration, 'context' => 'CAR_SALE']);
    $findSold = static fn(array $items) => array_values(array_filter($items, static fn($item) => ($item['id'] ?? '') === $soldCarId));
    $soldRtoMatch = $findSold($soldRtoResults);
    $soldExpenseMatch = $findSold($soldExpenseResults);
    $soldSaleMatch = $findSold($soldSaleResults);
    assertOutsidePicker(count($soldRtoMatch) === 1 && ($soldRtoMatch[0]['selectable'] ?? false) === true, 'Sold cars remain visible and selectable for RTO entries');
    assertOutsidePicker(count($soldExpenseMatch) === 1 && ($soldExpenseMatch[0]['selectable'] ?? true) === false && str_contains($soldExpenseMatch[0]['meta'] ?? '', 'Unavailable for a new expense'), 'Sold cars remain visible but are blocked for new car expenses');
    assertOutsidePicker(count($soldSaleMatch) === 1 && ($soldSaleMatch[0]['selectable'] ?? true) === false && str_contains($soldSaleMatch[0]['meta'] ?? '', 'Unavailable for a new sale'), 'Sold cars remain visible but are blocked from a duplicate sale');

    $db->rollBack();
    echo "New Entry outside-car picker checks completed and test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
