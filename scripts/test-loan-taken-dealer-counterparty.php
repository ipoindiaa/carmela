<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) {
    exit("Refusing to run outside a testing database.\n");
}

function assertLoanTakenDealer($condition, $message) {
    if (!$condition) throw new RuntimeException("FAIL: $message");
    echo "PASS: $message\n";
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $business ? $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]) : null;
$cash = $business ? $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND is_active = 1 LIMIT 1", [$business['id']]) : null;
assertLoanTakenDealer($business && $user && $cash, 'Testing business, user, and cash account exist');

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
$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 8));
$lenderName = 'Dealer Lender ' . $suffix;
$phone = str_pad((string) (abs(crc32($suffix)) % 10000000000), 10, '0', STR_PAD_LEFT);

$db->beginTransaction();
try {
    $lenderId = $engine->getOrCreateParty($lenderName, 'DEALER', $phone);
    $entryId = $engine->loanTaken($lenderName, 275000, date('Y-m-d'), $cash['id'], 'Dealer lender regression', $lenderId);
    $entry = $db->fetch("SELECT transaction_type, party_id, entry_amount FROM journal_entries WHERE id = ? AND business_id = ?", [$entryId, $business['id']]);
    $lines = $db->fetchAll("SELECT account_id, entry_type, amount FROM journal_lines WHERE journal_entry_id = ?", [$entryId]);

    assertLoanTakenDealer(
        ($entry['transaction_type'] ?? '') === 'LOAN_TAKEN'
            && ($entry['party_id'] ?? '') === $lenderId
            && abs(floatval($entry['entry_amount'] ?? 0) - 275000) < 0.01,
        'Borrowed Money accepts and links a selected Dealer company'
    );
    $cashDebit = 0.0;
    $lenderCredit = 0.0;
    foreach ($lines as $line) {
        if ($line['account_id'] === $cash['id'] && $line['entry_type'] === 'DR') $cashDebit += floatval($line['amount']);
        if ($line['entry_type'] === 'CR') $lenderCredit += floatval($line['amount']);
    }
    assertLoanTakenDealer(
        abs($cashDebit - 275000) < 0.01 && abs($lenderCredit - 275000) < 0.01,
        'The loan posts equal Cash debit and lender payable credit'
    );

    $db->rollBack();
    echo "Dealer lender validation regression completed and rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
