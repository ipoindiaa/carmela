<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertIntegrity($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

function trialBalanceTotals($engine) {
    $debit = 0.0;
    $credit = 0.0;
    foreach ($engine->getTrialBalance() as $row) {
        if ($row['balance_type'] === 'DR') $debit += floatval($row['balance_amount']);
        else $credit += floatval($row['balance_amount']);
    }
    return [round($debit, 2), round($credit, 2)];
}

function accountBalanceDiscrepancies($db, $engine, $businessId) {
    $trialRows = [];
    foreach ($engine->getTrialBalance() as $row) $trialRows[$row['id']] = $row;
    $discrepancies = [];
    foreach ($db->fetchAll("SELECT * FROM accounts WHERE business_id = ? AND is_active = 1", [$businessId]) as $account) {
        $trial = $trialRows[$account['id']] ?? ['balance_amount' => 0, 'balance_type' => 'DR'];
        $storedSigned = signedBalanceValue($account['current_balance'], $account['current_balance_type']);
        $trialSigned = signedBalanceValue($trial['balance_amount'], $trial['balance_type']);
        $discrepancies[$account['code']] = round($storedSigned - $trialSigned, 2);
    }
    return $discrepancies;
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]);
assertIntegrity($business && $user, 'Testing business and administrator exist');

Auth::init();
$_SESSION['user_id'] = $user['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['business_name'] = $business['name'];

$engine = new AccountingEngine($business['id'], $user['id']);
$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 7));

$db->beginTransaction();
try {
    [$initialDr, $initialCr] = trialBalanceTotals($engine);
    assertIntegrity(abs($initialDr - $initialCr) < 0.01, 'Trial balance is balanced before integrity checks');
    $baselineDiscrepancies = accountBalanceDiscrepancies($db, $engine, $business['id']);

    $openingAccountId = $engine->createAccount(
        'TST-OB-' . $suffix,
        'Integrity Opening Balance',
        'ASSET',
        'Current Assets',
        'GENERAL'
    );
    $firstOpeningId = $engine->setOpeningBalance($openingAccountId, 1000, 'DR', date('Y-m-d'), 'Integrity test');
    assertIntegrity($db->inTransaction(), 'Opening balance respects an existing outer transaction');

    $targetRow = null;
    foreach ($engine->getTrialBalance() as $row) {
        if ($row['id'] === $openingAccountId) $targetRow = $row;
    }
    assertIntegrity(
        $targetRow && floatval($targetRow['balance_amount']) === 1000.0 && $targetRow['balance_type'] === 'DR',
        'Journal-backed opening balance appears exactly once in the trial balance'
    );
    [$openingDr, $openingCr] = trialBalanceTotals($engine);
    assertIntegrity(abs($openingDr - $openingCr) < 0.01, 'Opening balance and offset keep the trial balance balanced');

    $secondOpeningId = $engine->setOpeningBalance($openingAccountId, 1250, 'CR', date('Y-m-d'), 'Direction correction');
    $firstOpening = $db->fetch("SELECT status FROM journal_entries WHERE id = ?", [$firstOpeningId]);
    $openingAccount = $db->fetch("SELECT * FROM accounts WHERE id = ?", [$openingAccountId]);
    assertIntegrity(
        $firstOpening['status'] === 'REVERSED' && $openingAccount['opening_entry_id'] === $secondOpeningId,
        'Updating an opening balance reverses the prior entry and links the replacement'
    );
    $targetRow = null;
    foreach ($engine->getTrialBalance() as $row) {
        if ($row['id'] === $openingAccountId) $targetRow = $row;
    }
    assertIntegrity(
        $targetRow && floatval($targetRow['balance_amount']) === 1250.0 && $targetRow['balance_type'] === 'CR',
        'Corrected opening balance uses the new amount and direction without duplication'
    );

    $foreignBusinessId = Database::uuid();
    $db->insert('businesses', ['id' => $foreignBusinessId, 'name' => 'Foreign Integrity Business']);
    $foreignAccountId = Database::uuid();
    $db->insert('accounts', [
        'id' => $foreignAccountId,
        'business_id' => $foreignBusinessId,
        'code' => 'FOREIGN-' . $suffix,
        'name' => 'Foreign Account',
        'group_name' => 'ASSET',
        'sub_group' => 'Current Assets',
        'entity_type' => 'GENERAL',
    ]);
    $entryCountBefore = intval($db->fetch("SELECT COUNT(*) AS cnt FROM journal_entries WHERE business_id = ?", [$business['id']])['cnt']);
    $crossBusinessBlocked = false;
    try {
        $engine->postJournalEntry('GENERAL_EXPENSE', date('Y-m-d'), 'Cross-business account test', [
            ['account_id' => $openingAccountId, 'amount' => 100, 'type' => 'DR'],
            ['account_id' => $foreignAccountId, 'amount' => 100, 'type' => 'CR'],
        ]);
    } catch (Throwable $e) {
        $crossBusinessBlocked = str_contains($e->getMessage(), 'does not belong');
    }
    $entryCountAfter = intval($db->fetch("SELECT COUNT(*) AS cnt FROM journal_entries WHERE business_id = ?", [$business['id']])['cnt']);
    assertIntegrity($crossBusinessBlocked && $entryCountAfter === $entryCountBefore, 'Cross-business journal accounts are rejected before posting');

    $invalidLineTypeBlocked = false;
    try {
        $engine->postJournalEntry('GENERAL_EXPENSE', date('Y-m-d'), 'Invalid direction test', [
            ['account_id' => $openingAccountId, 'amount' => 100, 'type' => 'DR'],
            ['account_id' => $openingAccountId, 'amount' => 100, 'type' => 'XX'],
        ]);
    } catch (Throwable $e) {
        $invalidLineTypeBlocked = str_contains($e->getMessage(), 'DR or CR');
    }
    assertIntegrity($invalidLineTypeBlocked, 'Invalid journal line directions are rejected');

    $db->query('SAVEPOINT partner_integrity');
    $partnerId = $engine->createPartner('Rollback Partner ' . $suffix, 'CARWISE', '', '', '', 25, date('Y-m-d'));
    assertIntegrity($db->inTransaction(), 'Partner creation does not commit its caller transaction');
    $db->query('ROLLBACK TO SAVEPOINT partner_integrity');
    $rolledBackPartner = $db->fetch("SELECT id FROM partners WHERE id = ?", [$partnerId]);
    assertIntegrity(!$rolledBackPartner, 'A failed outer workflow can roll back partner creation and its accounts');

    $pendingCarId = Database::uuid();
    $pendingCarAccountId = $engine->createAccount(
        'TST-CAR-' . $suffix,
        'Pending Payment Car',
        'ASSET',
        'Vehicle Inventory',
        'CAR',
        $pendingCarId
    );
    $db->insert('cars', [
        'id' => $pendingCarId,
        'business_id' => $business['id'],
        'registration_no' => 'TST' . $suffix,
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 100000,
        'status' => 'PENDING_PAYMENT',
        'account_id' => $pendingCarAccountId,
    ]);
    $cash = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'CASH-001'", [$business['id']]);
    $soldExpenseBlocked = false;
    try {
        $engine->carExpense($pendingCarId, 500, date('Y-m-d'), $cash['id'], 'Repair', 'Should not post');
    } catch (Throwable $e) {
        $soldExpenseBlocked = str_contains($e->getMessage(), 'sold car');
    }
    assertIntegrity($soldExpenseBlocked, 'Cars already sold with pending payment cannot receive new expenses');

    $salary = $db->fetch("SELECT * FROM salary_records WHERE business_id = ? ORDER BY processed_date LIMIT 1", [$business['id']]);
    $employee = $db->fetch("SELECT * FROM employees WHERE id = ? AND business_id = ?", [$salary['employee_id'], $business['id']]);
    $duplicateSalaryBlocked = false;
    try {
        $engine->salaryPayment($employee['id'], max(1, floatval($salary['gross_salary'])), 0, date('Y-m-d'), $cash['id'], $salary['month'], $salary['year']);
    } catch (Throwable $e) {
        $duplicateSalaryBlocked = str_contains($e->getMessage(), 'already processed');
    }
    assertIntegrity($duplicateSalaryBlocked, 'Duplicate employee salary month remains blocked');

    $profitAndLossBefore = $engine->getProfitAndLoss('2000-01-01', date('Y-m-d'));
    $expenseBefore = round(array_sum(array_map(static fn($row) => floatval($row['amount']), $profitAndLossBefore['expenses'])), 2);
    $reversedExpenseId = $engine->generalExpense(375, date('Y-m-d'), $cash['id'], 'Integrity Reversal Expense', 'Reverse immediately');
    $engine->reverseEntry($reversedExpenseId, 'Integrity report reversal check');
    $profitAndLossAfter = $engine->getProfitAndLoss('2000-01-01', date('Y-m-d'));
    $expenseAfter = round(array_sum(array_map(static fn($row) => floatval($row['amount']), $profitAndLossAfter['expenses'])), 2);
    assertIntegrity(abs($expenseBefore - $expenseAfter) < 0.01, 'Reversed expense nets to zero in Profit and Loss');

    [$finalDr, $finalCr] = trialBalanceTotals($engine);
    assertIntegrity(abs($finalDr - $finalCr) < 0.01, 'Trial balance remains balanced after all integrity checks');

    $finalDiscrepancies = accountBalanceDiscrepancies($db, $engine, $business['id']);
    $mismatchedAccounts = [];
    foreach ($finalDiscrepancies as $code => $difference) {
        if (abs($difference - floatval($baselineDiscrepancies[$code] ?? 0)) > 0.01) $mismatchedAccounts[] = $code;
    }
    assertIntegrity(
        empty($mismatchedAccounts),
        'Corrections and reversals introduce no new stored-versus-journal balance discrepancies'
            . ($mismatchedAccounts ? ': ' . implode(', ', $mismatchedAccounts) : '')
    );

    $openingAudit = $db->fetch(
        "SELECT COUNT(*) AS cnt FROM audit_log WHERE business_id = ? AND entity_type = 'account' AND entity_id = ? AND action = 'UPDATE'",
        [$business['id'], $openingAccountId]
    );
    assertIntegrity(intval($openingAudit['cnt']) === 2, 'Every opening-balance change creates an immutable audit update');

    $db->rollBack();
    echo "System integrity regression checks completed and test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
