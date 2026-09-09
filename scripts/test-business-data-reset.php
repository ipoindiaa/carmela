<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/business_data_reset.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) {
    exit("Refusing non-testing database.\n");
}

function assertResetTest($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

function insertResetFixtureJournal($db, $businessId, $userId, $reference, $type, $date, $lines, array $extras = []) {
    $entryId = Database::uuid();
    $total = 0.0;
    foreach ($lines as $line) {
        $total += floatval($line['amount']);
    }
    $db->insert('journal_entries', array_merge([
        'id' => $entryId,
        'business_id' => $businessId,
        'entry_date' => $date,
        'reference_no' => $reference,
        'narration' => 'Scoped reset fixture ' . $reference,
        'transaction_type' => $type,
        'entry_amount' => $total / 2,
        'status' => 'POSTED',
        'created_by' => $userId,
        'financial_year' => getCurrentFY($date),
    ], $extras));
    foreach ($lines as $line) {
        $db->insert('journal_lines', [
            'id' => Database::uuid(),
            'journal_entry_id' => $entryId,
            'account_id' => $line['account_id'],
            'amount' => $line['amount'],
            'entry_type' => $line['entry_type'],
            'narration' => 'Fixture line',
            'source_voucher_line_id' => $line['source_voucher_line_id'] ?? null,
        ]);
    }
    return $entryId;
}

function insertResetFixtureAttachment($db, $businessId, $userId, $entityType, $entityId, $suffix) {
    $businessFolder = preg_replace('/[^a-zA-Z0-9-]/', '', $businessId);
    $attachmentDir = dirname(__DIR__) . '/uploads/attachments/' . $businessFolder;
    if (!is_dir($attachmentDir) && !mkdir($attachmentDir, 0755, true) && !is_dir($attachmentDir)) {
        throw new RuntimeException('Could not create scoped reset attachment directory.');
    }
    $filename = 'scoped-reset-' . $suffix . '.txt';
    $path = $attachmentDir . '/' . $filename;
    file_put_contents($path, 'scoped reset test');
    $db->insert('attachments', [
        'id' => Database::uuid(),
        'business_id' => $businessId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'attachment_type' => 'TEST',
        'original_name' => $filename,
        'stored_name' => $filename,
        'relative_path' => 'uploads/attachments/' . $businessFolder . '/' . $filename,
        'mime_type' => 'text/plain',
        'file_size' => filesize($path),
        'uploaded_by' => $userId,
    ]);
    return $path;
}

function insertResetFixtureCar($db, $businessId, $registration, $sellerPartyId) {
    $carId = Database::uuid();
    $accountId = Database::uuid();
    $db->insert('accounts', [
        'id' => $accountId,
        'business_id' => $businessId,
        'code' => 'CAR-' . strtoupper(substr(str_replace('-', '', $carId), 0, 8)),
        'name' => $registration . ' Inventory',
        'group_name' => 'ASSET',
        'sub_group' => 'Vehicle Inventory',
        'entity_type' => 'CAR',
        'entity_id' => $carId,
        'is_active' => 1,
    ]);
    $db->insert('cars', [
        'id' => $carId,
        'business_id' => $businessId,
        'registration_no' => $registration,
        'make' => 'Test',
        'model' => 'Scoped Reset',
        'year' => 2026,
        'purchase_date' => '2026-08-10',
        'purchase_price' => 5000,
        'purchase_paid_amount' => 5000,
        'purchase_amount_mode' => 'FIXED',
        'expected_sale_price' => 9000,
        'status' => 'SOLD',
        'account_id' => $accountId,
        'sold_date' => '2026-08-11',
        'sale_price' => 9000,
        'sale_commission_amount' => 250,
        'buyer_name' => 'Fixture Buyer',
        'buyer_contact' => '9999999999',
        'seller_party_id' => $sellerPartyId,
        'has_second_key' => 1,
    ]);
    return ['id' => $carId, 'account_id' => $accountId];
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$admin = $business ? $db->fetch("SELECT * FROM users WHERE business_id = ? AND role = 'ADMIN' ORDER BY created_at LIMIT 1", [$business['id']]) : null;
assertResetTest($business && $admin, 'Testing business and administrator exist');

Auth::init();
$_SESSION['user_id'] = $admin['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $admin['username'];
$_SESSION['full_name'] = $admin['full_name'];
$_SESSION['role'] = $admin['role'];
$_SESSION['business_name'] = $business['name'];

$engine = new AccountingEngine($business['id'], $admin['id']);
$cash = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'CASH-001'", [$business['id']]);
assertResetTest((bool) $cash, 'Default cash account exists');
$engine->setOpeningBalance($cash['id'], 1000, 'DR', '2026-04-01', 'Scoped cleanup test opening');
$cash = $db->fetch("SELECT * FROM accounts WHERE id = ?", [$cash['id']]);
$openingEntryId = $cash['opening_entry_id'];
assertResetTest((bool) $openingEntryId, 'Opening balance is journal-backed');

$customAccountId = Database::uuid();
$db->insert('accounts', [
    'id' => $customAccountId,
    'business_id' => $business['id'],
    'code' => 'KEEP-CUSTOM',
    'name' => 'Keep This Custom Option',
    'group_name' => 'EXPENSE',
    'sub_group' => 'Daily Udhar Categories',
    'entity_type' => 'GENERAL',
    'is_active' => 1,
]);
$categoryId = Database::uuid();
$db->insert('expense_categories', [
    'id' => $categoryId,
    'business_id' => $business['id'],
    'name' => 'Keep This Expense Category',
    'type' => 'GENERAL',
    'account_id' => $customAccountId,
    'is_active' => 1,
]);
$partyId = Database::uuid();
$db->insert('debtors_creditors', [
    'id' => $partyId,
    'business_id' => $business['id'],
    'name' => 'Keep Seller Party',
    'type' => 'SELLER',
    'is_active' => 1,
]);
$partnerId = Database::uuid();
$db->insert('partners', [
    'id' => $partnerId,
    'business_id' => $business['id'],
    'name' => 'Keep Partner',
    'partner_type' => 'MAIN',
    'joined_date' => '2026-04-01',
    'is_active' => 1,
]);
$employeeId = Database::uuid();
$db->insert('employees', [
    'id' => $employeeId,
    'business_id' => $business['id'],
    'name' => 'Keep Employee',
    'join_date' => '2026-04-01',
    'is_active' => 1,
]);

$car = insertResetFixtureCar($db, $business['id'], 'TEST-DAILY-001', $partyId);
$carEntryId = insertResetFixtureJournal($db, $business['id'], $admin['id'], 'RESET-DAILY-CAR', 'CAR_PURCHASE', '2026-08-10', [
    ['account_id' => $car['account_id'], 'amount' => 5000, 'entry_type' => 'DR'],
    ['account_id' => $cash['id'], 'amount' => 5000, 'entry_type' => 'CR'],
], ['car_id' => $car['id'], 'party_id' => $partyId]);
$generalEntryId = insertResetFixtureJournal($db, $business['id'], $admin['id'], 'RESET-DAILY-GEN', 'GENERAL_EXPENSE', '2026-08-10', [
    ['account_id' => $customAccountId, 'amount' => 200, 'entry_type' => 'DR'],
    ['account_id' => $cash['id'], 'amount' => 200, 'entry_type' => 'CR'],
]);

$voucherId = Database::uuid();
$voucherLineId = Database::uuid();
$db->insert('journal_vouchers', [
    'id' => $voucherId,
    'business_id' => $business['id'],
    'voucher_date' => '2026-08-10',
    'reference_no' => 'RESET-DAILY-JV',
    'voucher_type' => 'GENERAL_JV',
    'status' => 'POSTED',
    'primary_account_id' => $cash['id'],
    'primary_entry_type' => 'CR',
    'primary_amount' => 75,
    'created_by' => $admin['id'],
    'financial_year' => getCurrentFY('2026-08-10'),
]);
$db->insert('journal_voucher_lines', [
    'id' => $voucherLineId,
    'journal_voucher_id' => $voucherId,
    'account_id' => $customAccountId,
    'amount' => 75,
    'entry_type' => 'DR',
    'entity_type' => 'GENERAL',
]);

$db->insert('car_tokens', [
    'id' => Database::uuid(),
    'business_id' => $business['id'],
    'car_id' => $car['id'],
    'party_id' => $partyId,
    'journal_entry_id' => $carEntryId,
    'received_date' => '2026-08-10',
    'amount' => 500,
    'created_by' => $admin['id'],
]);
$rtoId = Database::uuid();
$db->insert('rto_records', [
    'id' => $rtoId,
    'business_id' => $business['id'],
    'car_id' => $car['id'],
    'rto_type' => 'Transfer',
    'expense_amount' => 100,
    'created_by' => $admin['id'],
]);
$db->insert('car_partner_contributions', [
    'id' => Database::uuid(),
    'car_id' => $car['id'],
    'partner_id' => $partnerId,
    'amount' => 500,
    'contribution_date' => '2026-08-10',
    'journal_entry_id' => $carEntryId,
]);
$db->insert('alerts', [
    'id' => Database::uuid(),
    'business_id' => $business['id'],
    'type' => 'CAR_AGING',
    'severity' => 'WARNING',
    'message' => 'Scoped reset alert fixture',
]);
$carAttachmentPath = insertResetFixtureAttachment($db, $business['id'], $admin['id'], 'CAR', $car['id'], 'daily-car');
$entryAttachmentPath = insertResetFixtureAttachment($db, $business['id'], $admin['id'], 'JOURNAL_ENTRY', $generalEntryId, 'daily-entry');

$service = new BusinessDataResetService($business['id'], $admin['id']);
$blocked = false;
try {
    $service->clear(BusinessDataResetService::SCOPE_DAILY_ENTRIES, 'Testing@123', 'clear entries');
} catch (Throwable $e) {
    $blocked = str_contains($e->getMessage(), 'CLEAR ENTRIES');
}
assertResetTest($blocked, 'Exact scoped confirmation phrase is required');
assertResetTest((bool) $db->fetch("SELECT id FROM journal_entries WHERE id = ?", [$generalEntryId]), 'Invalid confirmation changes no daily data');

$blocked = false;
try {
    $service->clear(BusinessDataResetService::SCOPE_DAILY_ENTRIES, 'wrong-password', 'CLEAR ENTRIES');
} catch (Throwable $e) {
    $blocked = str_contains($e->getMessage(), 'incorrect');
}
assertResetTest($blocked, 'Incorrect password blocks a scoped cleanup');
assertResetTest((bool) $db->fetch("SELECT id FROM cars WHERE id = ?", [$car['id']]), 'Blocked cleanup leaves cars unchanged');

$dailyResult = $service->clear(BusinessDataResetService::SCOPE_DAILY_ENTRIES, 'Testing@123', 'CLEAR ENTRIES');
assertResetTest(($dailyResult['deleted_rows'] ?? 0) > 0, 'Daily cleanup reports deleted rows');
assertResetTest(($dailyResult['scope'] ?? '') === BusinessDataResetService::SCOPE_DAILY_ENTRIES, 'Daily cleanup reports its selected scope');
assertResetTest((bool) $db->fetch("SELECT id FROM journal_entries WHERE id = ?", [$openingEntryId]), 'Current opening balance journal is preserved');
assertResetTest(!$db->fetch("SELECT id FROM journal_entries WHERE id = ?", [$generalEntryId]), 'Daily general journal is cleared');
assertResetTest(!$db->fetch("SELECT id FROM journal_entries WHERE id = ?", [$carEntryId]), 'Daily car journal is cleared');
assertResetTest(!$db->fetch("SELECT id FROM journal_vouchers WHERE id = ?", [$voucherId]), 'Daily split voucher is cleared');
assertResetTest(!$db->fetch("SELECT id FROM car_tokens WHERE business_id = ?", [$business['id']]), 'Daily token rows are cleared');
assertResetTest(!$db->fetch("SELECT id FROM rto_records WHERE business_id = ?", [$business['id']]), 'Daily RTO rows are cleared');
assertResetTest(!$db->fetch("SELECT id FROM car_partner_contributions WHERE car_id = ?", [$car['id']]), 'Daily car funding rows are cleared');

$retainedCar = $db->fetch("SELECT * FROM cars WHERE id = ?", [$car['id']]);
assertResetTest((bool) $retainedCar, 'Daily cleanup retains car identity');
assertResetTest(floatval($retainedCar['purchase_price']) === 0.0 && $retainedCar['purchase_amount_mode'] === 'PAYMENTS', 'Retained car money fields are reset for a new test');
assertResetTest($retainedCar['status'] === 'IN_STOCK' && $retainedCar['seller_party_id'] === $partyId, 'Retained car is returned to stock while owner/seller linkage stays');
assertResetTest((bool) $db->fetch("SELECT id FROM accounts WHERE id = ?", [$customAccountId]), 'Custom account is preserved');
assertResetTest((bool) $db->fetch("SELECT id FROM expense_categories WHERE id = ?", [$categoryId]), 'Custom expense category is preserved');
assertResetTest((bool) $db->fetch("SELECT id FROM debtors_creditors WHERE id = ?", [$partyId]), 'Saved party is preserved');
assertResetTest((bool) $db->fetch("SELECT id FROM partners WHERE id = ?", [$partnerId]), 'Saved partner is preserved');
assertResetTest((bool) $db->fetch("SELECT id FROM employees WHERE id = ?", [$employeeId]), 'Saved employee is preserved');
assertResetTest(is_file($carAttachmentPath), 'Car attachment is preserved with retained car data');
assertResetTest(!is_file($entryAttachmentPath), 'Deleted entry attachment file is removed');
$cashAfterDaily = $db->fetch("SELECT current_balance, current_balance_type FROM accounts WHERE id = ?", [$cash['id']]);
assertResetTest(floatval($cashAfterDaily['current_balance']) === 1000.0 && $cashAfterDaily['current_balance_type'] === 'DR', 'Account balances rebuild from retained opening journal');

// Seed a retained non-car entry and a car-linked split bill for the car scope.
$unrelatedEntryId = insertResetFixtureJournal($db, $business['id'], $admin['id'], 'RESET-KEEP-GEN', 'GENERAL_EXPENSE', '2026-08-12', [
    ['account_id' => $customAccountId, 'amount' => 123, 'entry_type' => 'DR'],
    ['account_id' => $cash['id'], 'amount' => 123, 'entry_type' => 'CR'],
]);
$newCarEntryId = insertResetFixtureJournal($db, $business['id'], $admin['id'], 'RESET-CAR-CLEAR', 'CAR_PURCHASE', '2026-08-12', [
    ['account_id' => $car['account_id'], 'amount' => 600, 'entry_type' => 'DR'],
    ['account_id' => $cash['id'], 'amount' => 600, 'entry_type' => 'CR'],
], ['car_id' => $car['id'], 'party_id' => $partyId]);
$carVoucherId = Database::uuid();
$carVoucherLineId = Database::uuid();
$db->insert('journal_vouchers', [
    'id' => $carVoucherId,
    'business_id' => $business['id'],
    'voucher_date' => '2026-08-12',
    'reference_no' => 'RESET-CAR-JV',
    'voucher_type' => 'GENERAL_JV',
    'status' => 'POSTED',
    'primary_account_id' => $cash['id'],
    'primary_entry_type' => 'CR',
    'primary_amount' => 50,
    'created_by' => $admin['id'],
    'financial_year' => getCurrentFY('2026-08-12'),
]);
$db->insert('journal_voucher_lines', [
    'id' => $carVoucherLineId,
    'journal_voucher_id' => $carVoucherId,
    'account_id' => $car['account_id'],
    'amount' => 50,
    'entry_type' => 'DR',
    'entity_type' => 'CAR',
    'entity_id' => $car['id'],
]);
$carVoucherEntryId = insertResetFixtureJournal($db, $business['id'], $admin['id'], 'RESET-CAR-JV-POST', 'JOURNAL_VOUCHER', '2026-08-12', [
    ['account_id' => $car['account_id'], 'amount' => 50, 'entry_type' => 'DR', 'source_voucher_line_id' => $carVoucherLineId],
    ['account_id' => $cash['id'], 'amount' => 50, 'entry_type' => 'CR'],
], ['car_id' => $car['id'], 'journal_voucher_id' => $carVoucherId]);
$db->query("UPDATE journal_vouchers SET posted_entry_id = ? WHERE id = ?", [$carVoucherEntryId, $carVoucherId]);
$carAttachmentPath = insertResetFixtureAttachment($db, $business['id'], $admin['id'], 'CAR', $car['id'], 'car-scope');

$carsResult = $service->clear(BusinessDataResetService::SCOPE_CARS_AND_LINKED_ENTRIES, 'Testing@123', 'CLEAR CARS');
assertResetTest(($carsResult['deleted_rows'] ?? 0) > 0, 'Car cleanup reports deleted rows');
assertResetTest(!$db->fetch("SELECT id FROM cars WHERE id = ?", [$car['id']]), 'Car cleanup removes vehicles');
assertResetTest(!$db->fetch("SELECT id FROM accounts WHERE id = ?", [$car['account_id']]), 'Car cleanup removes vehicle inventory accounts');
assertResetTest(!$db->fetch("SELECT id FROM journal_entries WHERE id = ?", [$newCarEntryId]), 'Car cleanup removes car purchase journal');
assertResetTest(!$db->fetch("SELECT id FROM journal_entries WHERE id = ?", [$carVoucherEntryId]), 'Car cleanup removes posted car split journal');
assertResetTest(!$db->fetch("SELECT id FROM journal_vouchers WHERE id = ?", [$carVoucherId]), 'Car cleanup removes complete car-linked split bill');
assertResetTest((bool) $db->fetch("SELECT id FROM journal_entries WHERE id = ?", [$unrelatedEntryId]), 'Car cleanup retains unrelated daily journal');
assertResetTest((bool) $db->fetch("SELECT id FROM accounts WHERE id = ?", [$customAccountId]), 'Car cleanup preserves custom account');
assertResetTest((bool) $db->fetch("SELECT id FROM expense_categories WHERE id = ?", [$categoryId]), 'Car cleanup preserves custom category');
assertResetTest((bool) $db->fetch("SELECT id FROM debtors_creditors WHERE id = ?", [$partyId]), 'Car cleanup preserves party master');
assertResetTest((bool) $db->fetch("SELECT id FROM partners WHERE id = ?", [$partnerId]), 'Car cleanup preserves partner master');
assertResetTest((bool) $db->fetch("SELECT id FROM employees WHERE id = ?", [$employeeId]), 'Car cleanup preserves employee master');
assertResetTest(!is_file($carAttachmentPath), 'Car attachment file is removed with the car');
$customAfterCars = $db->fetch("SELECT current_balance, current_balance_type FROM accounts WHERE id = ?", [$customAccountId]);
assertResetTest(floatval($customAfterCars['current_balance']) === 123.0 && $customAfterCars['current_balance_type'] === 'DR', 'Retained account balance excludes deleted car activity');
$scopedAudit = $db->fetch(
    "SELECT * FROM audit_log
     WHERE business_id = ? AND entity_type = 'testing_data_cleanup'
     ORDER BY created_at DESC LIMIT 1",
    [$business['id']]
);
assertResetTest((bool) $scopedAudit, 'Scoped cleanup leaves a security audit event');
assertResetTest(str_contains((string) ($scopedAudit['new_value'] ?? ''), BusinessDataResetService::SCOPE_CARS_AND_LINKED_ENTRIES), 'Scoped audit identifies the cleanup choice used');

// Full reset remains available only as the explicit final testing scope.
$fullAttachmentPath = insertResetFixtureAttachment($db, $business['id'], $admin['id'], 'JOURNAL_ENTRY', $unrelatedEntryId, 'full-reset');
$businessFolder = preg_replace('/[^a-zA-Z0-9-]/', '', $business['id']);
$agreementDir = dirname(__DIR__) . '/uploads/agreements/' . $businessFolder;
if (!is_dir($agreementDir) && !mkdir($agreementDir, 0755, true) && !is_dir($agreementDir)) {
    throw new RuntimeException('Could not create reset test agreement directory.');
}
file_put_contents($agreementDir . '/full-reset.pdf', '%PDF reset test');

$fullResult = $service->reset('Testing@123', 'CLEAR');
assertResetTest(($fullResult['deleted_rows'] ?? 0) > 0, 'Full reset reports deleted rows');
assertResetTest(!$db->fetch("SELECT id FROM journal_entries WHERE business_id = ? LIMIT 1", [$business['id']]), 'Full reset clears all journal entries');
assertResetTest(!$db->fetch("SELECT id FROM attachments WHERE business_id = ? LIMIT 1", [$business['id']]), 'Full reset clears all attachment records');
assertResetTest(!is_file($fullAttachmentPath), 'Full reset removes attachment files');
assertResetTest(!is_dir($agreementDir), 'Full reset removes agreement files');
assertResetTest((int) $db->fetch("SELECT COUNT(*) AS cnt FROM users WHERE business_id = ?", [$business['id']])['cnt'] === 1, 'Full reset preserves user logins');
$defaultAccountCount = (int) $db->fetch("SELECT COUNT(*) AS cnt FROM accounts WHERE business_id = ?", [$business['id']])['cnt'];
assertResetTest($defaultAccountCount === 18, 'Full reset recreates clean default accounts');
assertResetTest((int) $db->fetch("SELECT COUNT(*) AS cnt FROM financial_years WHERE business_id = ?", [$business['id']])['cnt'] === 1, 'Full reset recreates current financial year');
assertResetTest((int) $db->fetch("SELECT COUNT(*) AS cnt FROM audit_log WHERE business_id = ?", [$business['id']])['cnt'] === 1, 'Full reset leaves one security audit event');

echo "Scoped business data cleanup checks completed.\n";
