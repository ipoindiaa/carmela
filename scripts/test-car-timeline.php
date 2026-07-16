<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertCarTimeline($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]);
$cash = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'CASH-001'", [$business['id']]);
$engine = new AccountingEngine($business['id'], $user['id']);

Auth::init();
$_SESSION['user_id'] = $user['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['business_name'] = $business['name'];

$db->beginTransaction();
try {
    $carId = Database::uuid();
    $registration = 'GJ99TL' . random_int(1000, 9999);
    $carAccountId = $engine->createAccount('TL-' . substr($registration, -6), "Timeline Test - $registration", 'ASSET', 'Inventory', 'CAR', $carId);
    $db->insert('cars', [
        'id' => $carId,
        'business_id' => $business['id'],
        'registration_no' => $registration,
        'make' => 'Timeline',
        'model' => 'Regression',
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 0,
        'status' => 'IN_STOCK',
        'account_id' => $carAccountId,
    ]);

    $sourceEntryId = $engine->carExpense($carId, 1000, date('Y-m-d'), $cash['id'], 'Timeline painting expense');
    $sourceDebit = $db->fetch(
        "SELECT a.code
         FROM journal_lines jl
         JOIN accounts a ON a.id = jl.account_id
         WHERE jl.journal_entry_id = ? AND jl.entry_type = 'DR'
         ORDER BY jl.id
         LIMIT 1",
        [$sourceEntryId]
    );
    assertCarTimeline(($sourceDebit['code'] ?? '') === 'CAR-REPAIR', 'Car expense uses the canonical Car Repair account without a nested category');
    $storedEntries = $db->fetchAll(
        "SELECT id, entry_type_id FROM journal_entries WHERE business_id = ? AND car_id = ? ORDER BY created_at, reference_no",
        [$business['id'], $carId]
    );
    assertCarTimeline(count($storedEntries) === 2, 'Car expense keeps its balanced source and internal allocation postings');
    assertCarTimeline(
        count(array_filter($storedEntries, static fn($row) => $row['entry_type_id'] === systemEntryTypeId('INTERNAL_ALLOCATION'))) === 1,
        'One supporting internal allocation is stored for car costing'
    );

    $timeline = $engine->getCarTimeline($carId);
    assertCarTimeline(count($timeline) === 1, 'Car timeline shows one row for one expense event');
    assertCarTimeline($timeline[0]['entry_id'] === $sourceEntryId, 'Car timeline links to the owner-facing source transaction');
    assertCarTimeline($timeline[0]['entry_type_id'] !== systemEntryTypeId('INTERNAL_ALLOCATION'), 'Internal allocation is hidden from the car timeline');

    $sourceLines = $db->fetchAll("SELECT id FROM journal_lines WHERE journal_entry_id = ?", [$sourceEntryId]);
    assertCarTimeline(count($sourceLines) === 2, 'Transaction detail retains the complete debit and credit lines');

    $engine->reverseEntry($sourceEntryId, 'Timeline reversal regression');
    $timelineAfterReversal = $engine->getCarTimeline($carId);
    $internalRows = array_filter(
        $timelineAfterReversal,
        static fn($row) => $row['entry_type_id'] === systemEntryTypeId('INTERNAL_ALLOCATION')
            || str_starts_with((string) ($row['narration'] ?? ''), 'REVERSAL: Linked reversal')
    );
    assertCarTimeline(empty($internalRows), 'Supporting allocation and its reversal stay hidden from the timeline');
    assertCarTimeline(count($timelineAfterReversal) === 2, 'Timeline keeps the original business event and its owner-facing reversal');

    $db->rollBack();
    echo "Car timeline regression checks completed and test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
