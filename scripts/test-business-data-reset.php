<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/business_data_reset.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) {
    exit("Refusing non-testing database.\n");
}

function assertResetTest($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
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

$secondaryUserId = Database::uuid();
$db->insert('users', [
    'id' => $secondaryUserId,
    'business_id' => $business['id'],
    'username' => 'reset.operator.' . substr(str_replace('-', '', $secondaryUserId), 0, 8),
    'password_hash' => Auth::hashPassword('Operator@123'),
    'full_name' => 'Reset Test Operator',
    'email' => 'reset-operator@example.test',
    'role' => ROLE_OPERATOR,
]);

$foreignBusinessId = Database::uuid();
$db->insert('businesses', ['id' => $foreignBusinessId, 'name' => 'Reset Isolation Business']);
$foreignUserId = Database::uuid();
$db->insert('users', [
    'id' => $foreignUserId,
    'business_id' => $foreignBusinessId,
    'username' => 'reset.foreign.' . substr(str_replace('-', '', $foreignUserId), 0, 8),
    'password_hash' => Auth::hashPassword('Foreign@123'),
    'full_name' => 'Foreign Admin',
    'email' => 'foreign-reset@example.test',
    'role' => ROLE_ADMIN,
]);
$foreignAccountId = Database::uuid();
$db->insert('accounts', [
    'id' => $foreignAccountId,
    'business_id' => $foreignBusinessId,
    'code' => 'FOREIGN-001',
    'name' => 'Foreign Account',
    'group_name' => 'ASSET',
    'sub_group' => 'Current Assets',
    'entity_type' => 'CASH',
]);

$testAccountId = Database::uuid();
$db->insert('accounts', [
    'id' => $testAccountId,
    'business_id' => $business['id'],
    'code' => 'RESET-TEST',
    'name' => 'Reset Test Account',
    'group_name' => 'ASSET',
    'sub_group' => 'Current Assets',
    'entity_type' => 'GENERAL',
]);
$entryId = Database::uuid();
$db->insert('journal_entries', [
    'id' => $entryId,
    'business_id' => $business['id'],
    'reference_no' => 'RESET-TEST-' . time(),
    'transaction_type' => 'GENERAL_EXPENSE',
    'entry_date' => date('Y-m-d'),
    'narration' => 'Database reset test row',
    'entry_amount' => 25,
    'created_by' => $admin['id'],
    'financial_year' => getCurrentFY(),
]);
$db->insert('journal_lines', [
    'id' => Database::uuid(),
    'journal_entry_id' => $entryId,
    'account_id' => $testAccountId,
    'amount' => 25,
    'entry_type' => 'DR',
]);

$attachmentId = Database::uuid();
$businessFolder = preg_replace('/[^a-zA-Z0-9-]/', '', $business['id']);
$attachmentDir = dirname(__DIR__) . '/uploads/attachments/' . $businessFolder;
if (!is_dir($attachmentDir) && !mkdir($attachmentDir, 0755, true) && !is_dir($attachmentDir)) {
    throw new RuntimeException('Could not create reset test attachment directory.');
}
$attachmentPath = $attachmentDir . '/reset-test.txt';
file_put_contents($attachmentPath, 'reset test');
$db->insert('attachments', [
    'id' => $attachmentId,
    'business_id' => $business['id'],
    'entity_type' => 'TEST',
    'entity_id' => $entryId,
    'attachment_type' => 'TEST',
    'original_name' => 'reset-test.txt',
    'stored_name' => 'reset-test.txt',
    'relative_path' => 'uploads/attachments/' . $businessFolder . '/reset-test.txt',
    'mime_type' => 'text/plain',
    'file_size' => 10,
    'uploaded_by' => $admin['id'],
]);

$service = new BusinessDataResetService($business['id'], $admin['id']);
$confirmationBlocked = false;
try {
    $service->reset('Testing@123', 'clear');
} catch (Throwable $e) {
    $confirmationBlocked = str_contains($e->getMessage(), 'CLEAR exactly');
}
assertResetTest($confirmationBlocked, 'Exact CLEAR confirmation is required');
assertResetTest((bool) $db->fetch("SELECT id FROM journal_entries WHERE id = ?", [$entryId]), 'Invalid confirmation changes no data');

$wrongPasswordBlocked = false;
try {
    $service->reset('wrong-password', 'CLEAR');
} catch (Throwable $e) {
    $wrongPasswordBlocked = str_contains($e->getMessage(), 'incorrect');
}
assertResetTest($wrongPasswordBlocked, 'Incorrect password blocks the reset');
assertResetTest((bool) $db->fetch("SELECT id FROM journal_entries WHERE id = ?", [$entryId]), 'Blocked reset changes no data');

$result = $service->reset('Testing@123', 'CLEAR');
assertResetTest(($result['deleted_rows'] ?? 0) > 0, 'Reset reports deleted database rows');
assertResetTest(!$db->fetch("SELECT id FROM journal_entries WHERE business_id = ? LIMIT 1", [$business['id']]), 'Journal entries are cleared');
assertResetTest(!$db->fetch("SELECT id FROM journal_lines WHERE journal_entry_id = ? LIMIT 1", [$entryId]), 'Journal lines are cleared');
assertResetTest(!$db->fetch("SELECT id FROM attachments WHERE business_id = ? LIMIT 1", [$business['id']]), 'Attachment records are cleared');
assertResetTest(!is_dir($attachmentDir), 'Business attachment files are cleared');
assertResetTest((int) $db->fetch("SELECT COUNT(*) AS cnt FROM users WHERE business_id = ?", [$business['id']])['cnt'] === 2, 'Business users are preserved');
$defaultAccountCount = (int) $db->fetch("SELECT COUNT(*) AS cnt FROM accounts WHERE business_id = ?", [$business['id']])['cnt'];
assertResetTest($defaultAccountCount === 14, 'Clean default accounts are recreated');
$canonicalExpenseCount = (int) $db->fetch(
    "SELECT COUNT(*) AS cnt FROM accounts WHERE business_id = ? AND code IN ('GEN-EXP', 'CAR-REPAIR', 'RTO-EXP')",
    [$business['id']]
)['cnt'];
assertResetTest($canonicalExpenseCount === 3, 'Clean reset recreates every canonical predefined expense account');
assertResetTest((int) $db->fetch("SELECT COUNT(*) AS cnt FROM financial_years WHERE business_id = ?", [$business['id']])['cnt'] === 1, 'Current financial year is recreated');
assertResetTest((int) $db->fetch("SELECT COUNT(*) AS cnt FROM audit_log WHERE business_id = ?", [$business['id']])['cnt'] === 1, 'One reset security event remains in audit history');
assertResetTest((bool) $db->fetch("SELECT id FROM accounts WHERE id = ? AND business_id = ?", [$foreignAccountId, $foreignBusinessId]), 'Other businesses are untouched');

echo "Business data reset checks completed.\n";
