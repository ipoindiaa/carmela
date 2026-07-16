<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/record_deletion.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertDeletion($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

function deletionTrialBalanceTotals($engine) {
    $debit = 0.0;
    $credit = 0.0;
    foreach ($engine->getTrialBalance() as $row) {
        if ($row['balance_type'] === 'DR') $debit += floatval($row['balance_amount']);
        else $credit += floatval($row['balance_amount']);
    }
    return [round($debit, 2), round($credit, 2)];
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? AND role = ? AND is_active = 1 ORDER BY created_at LIMIT 1", [$business['id'] ?? '', ROLE_ADMIN]);
assertDeletion($business && $user, 'Testing business and active administrator exist');

Auth::init();
$_SESSION['user_id'] = $user['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['business_name'] = $business['name'];

$engine = new AccountingEngine($business['id'], $user['id']);
$deletion = new RecordDeletionService($business['id'], $user['id']);
$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 7));
$cash = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'CASH-001' AND is_active = 1", [$business['id']]);
assertDeletion($cash, 'Active cash account exists for financial deletion checks');

$db->beginTransaction();
try {
    $reasonBlocked = false;
    try {
        $deletion->delete('opening_balance', $cash['id'], 'bad');
    } catch (Throwable $e) {
        $reasonBlocked = str_contains($e->getMessage(), 'at least 5');
    }
    assertDeletion($reasonBlocked, 'Every deletion requires a meaningful reason');

    $openingAccountId = $engine->createAccount('DEL-OB-' . $suffix, 'Deletion Opening Test', 'ASSET', 'Current Assets', 'GENERAL');
    $openingEntryId = $engine->setOpeningBalance($openingAccountId, 500, 'DR', date('Y-m-d'), 'Deletion regression');
    $deletion->delete('opening_balance', $openingAccountId, 'Mistaken opening amount');
    $openingAccount = $db->fetch("SELECT * FROM accounts WHERE id = ?", [$openingAccountId]);
    $openingEntry = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$openingEntryId]);
    assertDeletion(floatval($openingAccount['opening_balance']) === 0.0 && empty($openingAccount['opening_entry_id']), 'Opening balance is cleared from the active account');
    assertDeletion($openingEntry['status'] === 'REVERSED', 'Deleted opening balance remains as a reversal trail');
    $reversalOfReversalBlocked = false;
    try {
        $engine->reverseEntry($openingEntry['reversed_by'], 'Must not reverse immutable correction history');
    } catch (Throwable $e) {
        $reversalOfReversalBlocked = str_contains($e->getMessage(), 'permanent correction history');
    }
    assertDeletion($reversalOfReversalBlocked, 'A generated reversal cannot itself be deleted or reversed');

    $extraCashId = $engine->createAccount('DEL-CASH-' . $suffix, 'Mistaken Cash Counter', 'ASSET', 'Cash in Hand', 'CASH');
    $deletion->delete('account', $extraCashId, 'Duplicate cash counter');
    assertDeletion(!$db->fetch("SELECT id FROM accounts WHERE id = ?", [$extraCashId]), 'Unused cash or bank account can be removed safely');

    $partnerId = $engine->createPartner('Deletion Partner ' . $suffix, 'MAIN', '', '', '', 0, date('Y-m-d'), 'partners');
    $partnerBefore = $db->fetch("SELECT * FROM partners WHERE id = ?", [$partnerId]);
    $deletion->delete('partner', $partnerId, 'Added duplicate partner');
    $partnerAfter = $db->fetch("SELECT * FROM partners WHERE id = ?", [$partnerId]);
    assertDeletion(intval($partnerAfter['is_active']) === 0, 'Deleted partner leaves active records without breaking references');
    assertDeletion(intval($db->fetch("SELECT is_active FROM accounts WHERE id = ?", [$partnerBefore['capital_account_id']])['is_active']) === 0, 'Deleted partner accounts cannot be selected in new entries');

    $employeeId = Database::uuid();
    $employeeAccountId = $engine->createAccount('DEL-ADV-' . $suffix, 'Deletion Employee Advance', 'ASSET', 'Current Assets', 'EMPLOYEE', $employeeId);
    $db->insert('employees', [
        'id' => $employeeId,
        'business_id' => $business['id'],
        'name' => 'Deletion Employee ' . $suffix,
        'advance_account_id' => $employeeAccountId,
        'join_date' => date('Y-m-d'),
    ]);
    $deletion->delete('employee', $employeeId, 'Employee added by mistake');
    $employeeAfter = $db->fetch("SELECT * FROM employees WHERE id = ?", [$employeeId]);
    assertDeletion(intval($employeeAfter['is_active']) === 0 && !empty($employeeAfter['exit_date']), 'Deleted employee is archived with an exit date');

    $partyId = $engine->getOrCreateParty('Deletion Party ' . $suffix, 'DEBTOR');
    $partyBefore = $db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);
    $deletion->delete('party', $partyId, 'Duplicate customer record');
    $partyAfter = $db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);
    assertDeletion(intval($partyAfter['is_active']) === 0, 'Deleted debtor or creditor leaves active records');
    assertDeletion(intval($db->fetch("SELECT is_active FROM accounts WHERE id = ?", [$partyBefore['account_id']])['is_active']) === 0, 'Deleted party ledger cannot be selected in new entries');

    $targetUserId = Database::uuid();
    $db->insert('users', [
        'id' => $targetUserId,
        'business_id' => $business['id'],
        'username' => 'delete.' . strtolower($suffix),
        'password_hash' => Auth::hashPassword('Temporary123!'),
        'full_name' => 'Mistaken User ' . $suffix,
        'email' => 'delete.' . strtolower($suffix) . '@example.test',
        'role' => ROLE_OPERATOR,
    ]);
    $deletion->delete('user', $targetUserId, 'User account created by mistake');
    assertDeletion(intval($db->fetch("SELECT is_active FROM users WHERE id = ?", [$targetUserId])['is_active']) === 0, 'Deleted user loses login access immediately');

    $financialYearId = Database::uuid();
    $db->insert('financial_years', [
        'id' => $financialYearId,
        'business_id' => $business['id'],
        'year_label' => '2098-99-' . $suffix,
        'start_date' => '2098-04-01',
        'end_date' => '2099-03-31',
        'is_active' => 0,
    ]);
    $deletion->delete('financial_year', $financialYearId, 'Wrong financial year dates');
    assertDeletion(!$db->fetch("SELECT id FROM financial_years WHERE id = ?", [$financialYearId]), 'Unused inactive financial year can be removed');

    $carId = Database::uuid();
    $carAccountId = $engine->createAccount('DEL-CAR-' . $suffix, 'Deletion Car Purchase', 'ASSET', 'Vehicle Inventory', 'CAR', $carId);
    $db->insert('cars', [
        'id' => $carId,
        'business_id' => $business['id'],
        'registration_no' => 'DEL' . $suffix,
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 100,
        'account_id' => $carAccountId,
    ]);
    $purchaseEntryId = $engine->carPurchase($carId, 100, date('Y-m-d'), $cash['id'], 'Deletion car purchase test');
    $deletion->delete('car', $carId, 'Car purchase entered by mistake');
    $deletedCar = $db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
    $deletedPurchase = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$purchaseEntryId]);
    assertDeletion($deletedCar['status'] === 'CANCELLED' && str_starts_with($deletedCar['registration_no'], 'VOID-'), 'Deleted purchased car moves to cancelled history');
    assertDeletion($deletedPurchase['status'] === 'REVERSED', 'Car purchase deletion creates a financial reversal');

    $rtoCarId = Database::uuid();
    $rtoCarAccountId = $engine->createAccount('DEL-RTOCAR-' . $suffix, 'RTO Deletion Car', 'ASSET', 'Vehicle Inventory', 'CAR', $rtoCarId);
    $db->insert('cars', [
        'id' => $rtoCarId,
        'business_id' => $business['id'],
        'registration_no' => 'RTO' . $suffix,
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 0,
        'account_id' => $rtoCarAccountId,
    ]);
    $rtoId = Database::uuid();
    $db->insert('rto_records', [
        'id' => $rtoId,
        'business_id' => $business['id'],
        'car_id' => $rtoCarId,
        'rto_type' => 'Deletion Test Transfer',
        'created_by' => $user['id'],
    ]);
    $rtoExpenseEntry = $engine->rtoExpense($rtoId, $rtoCarId, 120, date('Y-m-d'), $cash['id'], 'Deletion RTO expense');
    $rtoRecoveryEntry = $engine->rtoRecovery($rtoId, 40, date('Y-m-d'), $cash['id'], 'Deletion RTO recovery');
    $deletion->delete('rto_record', $rtoId, 'RTO work entered for wrong car');
    $deletedRto = $db->fetch("SELECT * FROM rto_records WHERE id = ?", [$rtoId]);
    assertDeletion($deletedRto['status'] === 'CANCELLED' && floatval($deletedRto['expense_amount']) === 0.0 && floatval($deletedRto['recovered_amount']) === 0.0, 'Deleted RTO record clears active operational totals');
    assertDeletion($db->fetch("SELECT status FROM journal_entries WHERE id = ?", [$rtoExpenseEntry])['status'] === 'REVERSED', 'RTO expense is reversed during deletion');
    assertDeletion($db->fetch("SELECT status FROM journal_entries WHERE id = ?", [$rtoRecoveryEntry])['status'] === 'REVERSED', 'RTO recovery is reversed during deletion');

    $keyEventId = Database::uuid();
    $db->insert('car_second_key_events', [
        'id' => $keyEventId,
        'business_id' => $business['id'],
        'car_id' => $rtoCarId,
        'event_type' => 'RECEIVED',
        'event_date' => date('Y-m-d'),
        'narration' => 'Mistaken second key movement',
        'created_by' => $user['id'],
    ]);
    $db->query("UPDATE cars SET has_second_key = 1 WHERE id = ?", [$rtoCarId]);
    $deletion->delete('second_key_event', $keyEventId, 'Second key event entered by mistake');
    assertDeletion(!$db->fetch("SELECT id FROM car_second_key_events WHERE id = ?", [$keyEventId]), 'Mistaken second key event can be deleted');
    assertDeletion(intval($db->fetch("SELECT has_second_key FROM cars WHERE id = ?", [$rtoCarId])['has_second_key']) === 0, 'Current key status is recalculated after deleting its latest event');

    $auditTypes = ['opening_balance', 'account', 'partner', 'employee', 'party', 'user', 'financial_year', 'car', 'rto_record'];
    foreach ($auditTypes as $entityType) {
        $audit = $db->fetch("SELECT COUNT(*) AS cnt FROM audit_log WHERE business_id = ? AND entity_type = ? AND action = 'DELETE'", [$business['id'], $entityType]);
        assertDeletion(intval($audit['cnt']) > 0, "Deletion audit exists for {$entityType}");
    }

    [$debit, $credit] = deletionTrialBalanceTotals($engine);
    assertDeletion(abs($debit - $credit) < 0.01, 'Trial balance remains equal after all deletion reversals');

    $db->rollBack();
    echo "Record deletion regression checks completed and test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
