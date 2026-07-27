<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertEntryTaxonomy($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

function debitAccountCode(Database $db, $entryId) {
    $row = $db->fetch(
        "SELECT a.code
         FROM journal_lines jl
         JOIN accounts a ON a.id = jl.account_id
         WHERE jl.journal_entry_id = ? AND jl.entry_type = 'DR'
         ORDER BY jl.id
         LIMIT 1",
        [$entryId]
    );
    return $row['code'] ?? null;
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
    $suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 8));
    $carId = Database::uuid();
    $registration = 'GJ99TX' . substr($suffix, 0, 4);
    $carAccountId = $engine->createAccount('TX-CAR-' . $suffix, "Taxonomy Test - $registration", 'ASSET', 'Inventory', 'CAR', $carId);
    $db->insert('cars', [
        'id' => $carId,
        'business_id' => $business['id'],
        'registration_no' => $registration,
        'make' => 'Taxonomy',
        'model' => 'Regression',
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 0,
        'status' => 'IN_STOCK',
        'account_id' => $carAccountId,
    ]);

    $carExpenseId = $engine->carExpense($carId, 500, date('Y-m-d'), $cash['id'], 'Taxonomy car expense');
    assertEntryTaxonomy(debitAccountCode($db, $carExpenseId) === 'CAR-REPAIR', 'Car Repair posts to one canonical Car Repair account');

    $generalExpenseId = $engine->generalExpense(400, date('Y-m-d'), $cash['id'], 'Taxonomy office expense');
    assertEntryTaxonomy(debitAccountCode($db, $generalExpenseId) === 'GEN-EXP', 'Office / Business Expense posts to one canonical business expense account');

    $rtoId = Database::uuid();
    $db->insert('rto_records', [
        'id' => $rtoId,
        'business_id' => $business['id'],
        'car_id' => $carId,
        'rto_type' => 'Transfer Child Category',
        'status' => 'IN_PROGRESS',
        'is_recoverable' => 0,
        'created_by' => $user['id'],
    ]);
    $rtoExpenseId = $engine->rtoExpense($rtoId, $carId, 300, date('Y-m-d'), $cash['id'], 'Taxonomy RTO expense');
    assertEntryTaxonomy(debitAccountCode($db, $rtoExpenseId) === 'RTO-EXP', 'RTO Expense posts to one canonical RTO expense account');
    $rtoAllocation = $db->fetch(
        "SELECT COUNT(*) AS cnt FROM journal_entries
         WHERE business_id = ? AND car_id = ? AND transaction_type = 'RTO_EXPENSE'
           AND entry_type_id = ? AND status = 'POSTED'",
        [$business['id'], $carId, systemEntryTypeId('INTERNAL_ALLOCATION')]
    );
    assertEntryTaxonomy(intval($rtoAllocation['cnt'] ?? 0) === 0, 'New RTO expense remains in Profit & Loss instead of being cancelled into car inventory');
    $rtoProfitability = $engine->getCarProfitability($carId);
    assertEntryTaxonomy(
        abs(floatval($rtoProfitability['rto_expense'] ?? 0) - 300) < 0.01,
        'Car-wise profitability includes its journal-backed RTO expense'
    );

    foreach (['CAR-REPAIR', 'GEN-EXP', 'RTO-EXP'] as $code) {
        $count = $db->fetch("SELECT COUNT(*) AS cnt FROM accounts WHERE business_id = ? AND code = ?", [$business['id'], $code]);
        assertEntryTaxonomy(intval($count['cnt'] ?? 0) === 1, "$code exists only once");
    }

    $customAccountId = $engine->createAccount('TX-CUSTOM-' . $suffix, 'Taxonomy Custom Expense', 'EXPENSE', 'Daily Udhar Categories', 'GENERAL');
    $customEntryId = $engine->categoryEntry($customAccountId, 'out', 200, date('Y-m-d'), $cash['id'], 'Taxonomy custom expense');
    $customEntry = $db->fetch("SELECT entry_type_id FROM journal_entries WHERE id = ?", [$customEntryId]);
    assertEntryTaxonomy($customEntry['entry_type_id'] === customEntryTypeId($customAccountId), 'Custom entry type remains a separate top-level type with stable identity');
    assertEntryTaxonomy(debitAccountCode($db, $customEntryId) === 'TX-CUSTOM-' . $suffix, 'Custom entry posts directly to its own ledger account');

    $saleEntryId = $engine->carSale(
        $carId,
        2000,
        date('Y-m-d'),
        $cash['id'],
        '',
        'Narration Optional Buyer ' . $suffix,
        2000
    );
    $saleEntry = $db->fetch("SELECT narration FROM journal_entries WHERE id = ?", [$saleEntryId]);
    assertEntryTaxonomy(
        ($saleEntry['narration'] ?? '') === "Car sold - $registration",
        'Sold Car accepts blank narration and writes a transparent system narration'
    );

    $db->rollBack();
    echo "Entry taxonomy regression checks completed and test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
