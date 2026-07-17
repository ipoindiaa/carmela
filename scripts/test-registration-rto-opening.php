<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertRegistrationRto($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]);
assertRegistrationRto($business && $user, 'Testing business and administrator exist');

Auth::init();
$_SESSION['user_id'] = $user['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['business_name'] = $business['name'];

$engine = new AccountingEngine($business['id'], $user['id']);
$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 4));
$registrationNo = 'GJ05RT' . str_pad((string) (hexdec($suffix) % 10000), 4, '0', STR_PAD_LEFT);

$db->beginTransaction();
try {
    $carId = Database::uuid();
    $db->insert('cars', [
        'id' => $carId,
        'business_id' => $business['id'],
        'registration_no' => substr($registrationNo, 0, 4) . '-' . substr($registrationNo, 4, 2) . '-' . substr($registrationNo, 6),
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 0,
        'ownership_type' => 'OWNED',
    ]);

    $foundCar = findCarByRegistrationNo($db, $business['id'], strtolower($registrationNo));
    assertRegistrationRto($foundCar && $foundCar['id'] === $carId, 'Duplicate lookup catches case and separator variations');
    assertRegistrationRto(normalizeRegistrationNo(' gj 05 rt 0001 ') === 'GJ05RT0001', 'Registration input normalization is stable');

    $openingAccount = $engine->getRtoOpeningAccount(true);
    assertRegistrationRto($openingAccount && $openingAccount['code'] === 'RTO-OPEN', 'Dedicated RTO opening account exists');
    $openingEntryId = $engine->setOpeningBalance($openingAccount['id'], 4200, 'DR', date('Y-m-d'), 'RTO brought forward');
    $summary = $engine->getRtoBookSummary();
    assertRegistrationRto(floatval($summary['opening']) === 4200.0, 'RTO summary includes the opening balance without a car');

    $openingEntry = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$openingEntryId]);
    $openingLines = $db->fetchAll("SELECT * FROM journal_lines WHERE journal_entry_id = ?", [$openingEntryId]);
    $debit = array_sum(array_map(static fn($line) => $line['entry_type'] === 'DR' ? floatval($line['amount']) : 0, $openingLines));
    $credit = array_sum(array_map(static fn($line) => $line['entry_type'] === 'CR' ? floatval($line['amount']) : 0, $openingLines));
    assertRegistrationRto($openingEntry['transaction_type'] === 'OPENING_BALANCE' && empty($openingEntry['car_id']), 'RTO opening entry has no car link');
    assertRegistrationRto(abs($debit - $credit) < 0.01, 'RTO opening entry remains double-entry balanced');

    $replacementEntryId = $engine->setOpeningBalance($openingAccount['id'], 4500, 'DR', date('Y-m-d'), 'Corrected RTO brought forward');
    $correctedSummary = $engine->getRtoBookSummary();
    $openingTrail = $db->fetch(
        "SELECT COUNT(DISTINCT je.id) AS cnt
         FROM journal_entries je
         JOIN journal_lines jl ON jl.journal_entry_id = je.id
         WHERE je.business_id = ? AND jl.account_id = ?",
        [$business['id'], $openingAccount['id']]
    );
    assertRegistrationRto($replacementEntryId !== $openingEntryId && floatval($correctedSummary['opening']) === 4500.0, 'Correcting the RTO opening balance replaces the active amount');
    assertRegistrationRto(intval($openingTrail['cnt']) >= 3, 'Original, reversal, and replacement RTO opening entries remain in history');

    $audit = $db->fetch(
        "SELECT COUNT(*) AS cnt FROM audit_log WHERE business_id = ? AND entity_type = 'account' AND entity_id = ? AND action = 'UPDATE'",
        [$business['id'], $openingAccount['id']]
    );
    assertRegistrationRto(intval($audit['cnt']) >= 2, 'Every RTO opening balance change creates an immutable audit update');

    $db->rollBack();
    echo "Registration and RTO opening regression checks completed; test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
