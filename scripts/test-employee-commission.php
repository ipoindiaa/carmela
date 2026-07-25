<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) {
    exit("Refusing non-testing database.\n");
}

function assertEmployeeCommission($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

function storedBalance($db, $accountId) {
    $account = $db->fetch(
        "SELECT current_balance, current_balance_type FROM accounts WHERE id = ?",
        [$accountId]
    );
    $amount = floatval($account['current_balance'] ?? 0);
    return ($account['current_balance_type'] ?? 'DR') === 'CR' ? -$amount : $amount;
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $business
    ? $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']])
    : null;
assertEmployeeCommission($business && $user, 'Testing business and user exist');

Auth::init();
$_SESSION['user_id'] = $user['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['business_name'] = $business['name'];

$engine = new AccountingEngine($business['id'], $user['id']);
$cash = $db->fetch(
    "SELECT * FROM accounts WHERE business_id = ? AND code = 'CASH-001'",
    [$business['id']]
);
assertEmployeeCommission((bool) $cash, 'Testing cash account exists');

$db->beginTransaction();
try {
    $db->update('accounts', [
        'current_balance' => 200000,
        'current_balance_type' => 'DR',
    ], 'id = ? AND business_id = ?', [$cash['id'], $business['id']]);

    $employeeId = Database::uuid();
    $advanceAccountId = $engine->createAccount(
        'EMP-ADV-' . substr(str_replace('-', '', $employeeId), 0, 6),
        'Commission Test Employee Advance',
        'ASSET',
        'Employee Advances',
        'EMPLOYEE',
        $employeeId
    );
    $db->update('accounts', [
        'current_balance' => 8000,
        'current_balance_type' => 'DR',
    ], 'id = ? AND business_id = ?', [$advanceAccountId, $business['id']]);
    $db->insert('employees', [
        'id' => $employeeId,
        'business_id' => $business['id'],
        'name' => 'Commission Test Employee',
        'role' => 'Sales',
        'monthly_salary' => 18000,
        'advance_account_id' => $advanceAccountId,
        'join_date' => date('Y-m-d'),
    ]);

    $carId = Database::uuid();
    $registration = 'GJ99CM' . random_int(1000, 9999);
    $carAccountId = $engine->createAccount(
        'CAR-COMM-' . substr($registration, -4),
        "Commission Test Car - {$registration}",
        'ASSET',
        'Inventory',
        'CAR',
        $carId
    );
    $db->insert('cars', [
        'id' => $carId,
        'business_id' => $business['id'],
        'registration_no' => $registration,
        'make' => 'Commission',
        'model' => 'Regression',
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 100000,
        'purchase_paid_amount' => 100000,
        'status' => 'SOLD',
        'sold_date' => date('Y-m-d'),
        'sale_price' => 125000,
        'account_id' => $carAccountId,
    ]);
    $engine->postJournalEntry('CAR_PURCHASE', date('Y-m-d'), 'Commission test car purchase', [
        ['account_id' => $carAccountId, 'amount' => 100000, 'type' => 'DR'],
        ['account_id' => $cash['id'], 'amount' => 100000, 'type' => 'CR'],
    ], ['car_id' => $carId]);

    $advanceBefore = storedBalance($db, $advanceAccountId);
    $salaryBefore = (int) $db->fetch(
        "SELECT COUNT(*) AS cnt FROM salary_records WHERE employee_id = ? AND business_id = ?",
        [$employeeId, $business['id']]
    )['cnt'];
    $carCostBefore = $engine->getCarTotalCost($carId);

    $commissionAmount = 4500;
    $entryId = $engine->employeeCommission(
        $employeeId,
        $commissionAmount,
        date('Y-m-d'),
        $cash['id'],
        'Commission for successful car sale',
        $carId
    );

    $entry = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$entryId]);
    assertEmployeeCommission(($entry['transaction_type'] ?? '') === 'EMPLOYEE_COMMISSION', 'Commission has its own transaction type');
    assertEmployeeCommission(($entry['entry_type_id'] ?? '') === systemEntryTypeId('EMPLOYEE_COMMISSION'), 'Commission has its own stable entry type identity');
    assertEmployeeCommission(($entry['employee_id'] ?? '') === $employeeId, 'Commission is linked to the employee');
    assertEmployeeCommission(($entry['car_id'] ?? '') === $carId, 'Sale commission can be linked to a sold car');

    $lines = $db->fetchAll(
        "SELECT jl.entry_type, jl.amount, a.code
         FROM journal_lines jl
         JOIN accounts a ON a.id = jl.account_id
         WHERE jl.journal_entry_id = ?
         ORDER BY jl.entry_type, a.code",
        [$entryId]
    );
    $debit = array_values(array_filter($lines, static fn($line) => $line['entry_type'] === 'DR'));
    $credit = array_values(array_filter($lines, static fn($line) => $line['entry_type'] === 'CR'));
    assertEmployeeCommission(count($lines) === 2, 'Commission creates one balanced debit and credit pair');
    assertEmployeeCommission(($debit[0]['code'] ?? '') === 'EMP-COMM', 'Commission debits the dedicated employee commission expense');
    assertEmployeeCommission(($credit[0]['code'] ?? '') === 'CASH-001', 'Commission credits the selected payment account');
    assertEmployeeCommission(abs(floatval($debit[0]['amount'] ?? 0) - $commissionAmount) < 0.01, 'Commission debit uses the requested amount');
    assertEmployeeCommission(abs(floatval($credit[0]['amount'] ?? 0) - $commissionAmount) < 0.01, 'Commission credit uses the requested amount');

    $salaryAfter = (int) $db->fetch(
        "SELECT COUNT(*) AS cnt FROM salary_records WHERE employee_id = ? AND business_id = ?",
        [$employeeId, $business['id']]
    )['cnt'];
    assertEmployeeCommission($salaryAfter === $salaryBefore, 'Commission does not create or change a salary record');
    assertEmployeeCommission(abs(storedBalance($db, $advanceAccountId) - $advanceBefore) < 0.01, 'Commission does not change the employee advance balance');
    assertEmployeeCommission(abs($engine->getCarTotalCost($carId) - ($carCostBefore + $commissionAmount)) < 0.01, 'Linked commission increases the selected car cost');

    $timeline = $engine->getCarTimeline($carId);
    assertEmployeeCommission(
        (bool) array_filter($timeline, static fn($row) => ($row['entry_id'] ?? '') === $entryId),
        'Linked commission appears in the car timeline'
    );

    $engine->reverseEntry($entryId, 'Commission regression reversal');
    assertEmployeeCommission(abs($engine->getCarTotalCost($carId) - $carCostBefore) < 0.01, 'Reversal removes the commission from the car cost');
    assertEmployeeCommission(abs(storedBalance($db, $advanceAccountId) - $advanceBefore) < 0.01, 'Commission reversal leaves the employee advance unchanged');

    $generalEntryId = $engine->employeeCommission(
        $employeeId,
        1000,
        date('Y-m-d'),
        $cash['id'],
        'General monthly sales incentive'
    );
    $generalEntry = $db->fetch("SELECT car_id FROM journal_entries WHERE id = ?", [$generalEntryId]);
    assertEmployeeCommission(empty($generalEntry['car_id']), 'General commission can be paid without selecting a car');
    assertEmployeeCommission(abs(storedBalance($db, $advanceAccountId) - $advanceBefore) < 0.01, 'General commission also stays separate from employee advances');

    $db->rollBack();
    echo "Employee commission regression checks completed and test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
