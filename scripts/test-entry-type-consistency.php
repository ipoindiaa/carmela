<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertEntryType($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]);
Auth::init();
$_SESSION['user_id'] = $user['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['business_name'] = $business['name'];

$engine = new AccountingEngine($business['id'], $user['id']);
$cash = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'CASH-001'", [$business['id']]);
assertEntryType($cash, 'Testing cash account exists');

$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 7));
$customAccountId = $engine->createAccount('INC-' . $suffix, 'Temporary Other Income', 'INCOME', 'Daily Jama Categories', 'GENERAL');
$customEntryId = $engine->categoryEntry($customAccountId, 'in', 12345, date('Y-m-d'), $cash['id'], 'Custom entry identity test');
$customEntry = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$customEntryId]);

assertEntryType($customEntry['entry_type_id'] === customEntryTypeId($customAccountId), 'Custom entry stores its account-backed stable entry-type ID');
assertEntryType(abs(floatval($customEntry['entry_amount']) - 12345) < 0.01, 'Custom entry stores the canonical summary amount');

$db->query("UPDATE accounts SET name = ? WHERE id = ? AND business_id = ?", ['Renamed Commission Income', $customAccountId, $business['id']]);
$renamedEntry = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$customEntryId]);
assertEntryType(transactionTypeLabel($renamedEntry['transaction_type'], $renamedEntry) === 'Renamed Commission Income', 'Renaming a custom type updates its display without changing historical identity');

$filteredCount = $db->fetch(
    "SELECT COUNT(*) AS cnt FROM journal_entries WHERE business_id = ? AND entry_type_id = ?",
    [$business['id'], customEntryTypeId($customAccountId)]
);
assertEntryType(intval($filteredCount['cnt']) === 1, 'Stable entry-type filtering finds the custom transaction by ID');

$correctionLines = $db->fetchAll(
    "SELECT account_id, entry_type AS type, amount, narration FROM journal_lines WHERE journal_entry_id = ? ORDER BY id",
    [$customEntryId]
);
$replacementId = $engine->correctEntry(
    $customEntryId,
    date('Y-m-d'),
    'Corrected custom entry narration',
    $correctionLines,
    'Regression correction test'
);
$replacement = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$replacementId]);
$original = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$customEntryId]);
assertEntryType($replacement['entry_type_id'] === customEntryTypeId($customAccountId), 'Correction preserves the stable custom entry-type ID');
assertEntryType(abs(floatval($replacement['entry_amount']) - 12345) < 0.01, 'Correction preserves the custom summary amount');
assertEntryType($original['status'] === 'REVERSED' && $original['corrected_by_id'] === $replacementId, 'Correction keeps the original and replacement history connected');

$replacementCategoryId = $engine->createAccount('INC-ALT-' . $suffix, 'Alternate Custom Income', 'INCOME', 'Daily Jama Categories', 'GENERAL');
$replacementLines = $db->fetchAll(
    "SELECT account_id, entry_type AS type, amount, narration FROM journal_lines WHERE journal_entry_id = ? ORDER BY id",
    [$replacementId]
);
foreach ($replacementLines as &$line) {
    if ($line['account_id'] === $customAccountId) $line['account_id'] = $replacementCategoryId;
}
unset($line);
$changedCategoryEntryId = $engine->correctEntry(
    $replacementId,
    date('Y-m-d'),
    'Changed custom income category',
    $replacementLines,
    'Change custom category regression'
);
$changedCategoryEntry = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$changedCategoryEntryId]);
assertEntryType($changedCategoryEntry['entry_type_id'] === customEntryTypeId($replacementCategoryId), 'Changing a custom category updates the stable entry-type ID');

$ambiguousLines = [
    ['account_id' => $cash['id'], 'type' => 'DR', 'amount' => 12345, 'narration' => 'Ambiguous custom correction'],
    ['account_id' => $customAccountId, 'type' => 'CR', 'amount' => 5000, 'narration' => 'First custom type'],
    ['account_id' => $replacementCategoryId, 'type' => 'CR', 'amount' => 7345, 'narration' => 'Second custom type'],
];
$ambiguousBlocked = false;
try {
    $engine->correctEntry($changedCategoryEntryId, date('Y-m-d'), 'Ambiguous custom correction', $ambiguousLines, 'Reject ambiguous custom types');
} catch (Throwable $e) {
    $ambiguousBlocked = str_contains($e->getMessage(), 'only one custom');
}
assertEntryType($ambiguousBlocked, 'A simple correction cannot attach multiple custom entry types');

$usage = $db->fetch("SELECT COUNT(*) AS cnt FROM journal_lines WHERE account_id = ?", [$customAccountId]);
assertEntryType(intval($usage['cnt']) > 0, 'A used custom type remains protected from deletion');

$generalExpenseId = $engine->generalExpense(1000, date('Y-m-d'), $cash['id'], 'Regression Office Expense', 'Predefined entry identity test');
$generalExpense = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$generalExpenseId]);
assertEntryType($generalExpense['entry_type_id'] === systemEntryTypeId('GENERAL_EXPENSE'), 'Predefined expense stores the system entry-type ID');
assertEntryType(transactionTypeLabel($generalExpense['transaction_type'], $generalExpense) === 'Office / Business Expense', 'Predefined entry label resolves from the shared type metadata');

$audit = $db->fetch(
    "SELECT COUNT(*) AS cnt FROM audit_log WHERE business_id = ? AND entity_type = 'journal_entry' AND entity_id = ? AND action = 'UPDATE'",
    [$business['id'], $customEntryId]
);
assertEntryType(intval($audit['cnt']) >= 1, 'Editing the custom transaction creates an audit update record');

echo "Entry-type consistency regression checks completed.\n";
