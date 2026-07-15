<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertSplitBill($condition, $message) {
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
$misc = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND code = 'MISC-EXP'", [$business['id']]);
assertSplitBill($cash && $misc, 'Required test accounts exist');

$registrationNo = 'GJ09JK1234';
$car = $db->fetch("SELECT * FROM cars WHERE business_id = ? AND registration_no = ?", [$business['id'], $registrationNo]);
if ($car) {
    $carId = $car['id'];
    $carAccountId = $car['account_id'];
} else {
    $carId = Database::uuid();
    $carAccountId = $engine->createAccount('CAR-GJ09JK1234', "Car A/c - {$registrationNo}", 'ASSET', 'Vehicle Inventory', 'CAR', $carId);
    $db->insert('cars', [
        'id' => $carId,
        'business_id' => $business['id'],
        'registration_no' => $registrationNo,
        'make' => 'Test',
        'model' => 'Split Bill',
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 0,
        'status' => 'IN_STOCK',
        'account_id' => $carAccountId,
    ]);
}

$openingEntryId = $engine->setOpeningBalance($carAccountId, 10000, 'DR', date('Y-m-d'), 'Car inventory brought forward');
$openingEntry = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$openingEntryId]);
assertSplitBill($openingEntry['car_id'] === $carId, 'A direct posting to one car account automatically links that car');

$costBefore = $engine->getCarTotalCost($carId);
$voucherId = $engine->saveJournalVoucher(
    date('Y-m-d'),
    'Garage master bill July',
    $cash['id'],
    'CR',
    100000,
    [
        ['account_id' => $carAccountId, 'amount' => 80000, 'narration' => 'Denting and paint for GJ09JK1234'],
        ['account_id' => $misc['id'], 'amount' => 20000, 'narration' => 'Workshop common supplies'],
    ],
    'SPLIT_BILL',
    'POSTED'
);

$voucher = $db->fetch("SELECT * FROM journal_vouchers WHERE id = ?", [$voucherId]);
$voucherLines = $db->fetchAll("SELECT * FROM journal_voucher_lines WHERE journal_voucher_id = ? ORDER BY amount DESC", [$voucherId]);
$carVoucherLine = $db->fetch(
    "SELECT * FROM journal_voucher_lines WHERE journal_voucher_id = ? AND entity_type = 'CAR' AND entity_id = ?",
    [$voucherId, $carId]
);
assertSplitBill($voucher['status'] === 'POSTED' && count($voucherLines) === 2, 'Large bill posted with two preserved allocation lines');
assertSplitBill($carVoucherLine && floatval($carVoucherLine['amount']) === 80000.0, 'Car allocation stores the exact car and ₹80,000 amount');

$postedEntry = $db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$voucher['posted_entry_id']]);
$totals = $db->fetch(
    "SELECT SUM(CASE WHEN entry_type = 'DR' THEN amount ELSE 0 END) AS total_dr,
            SUM(CASE WHEN entry_type = 'CR' THEN amount ELSE 0 END) AS total_cr
     FROM journal_lines WHERE journal_entry_id = ?",
    [$postedEntry['id']]
);
$postedCarLine = $db->fetch(
    "SELECT * FROM journal_lines WHERE journal_entry_id = ? AND account_id = ?",
    [$postedEntry['id'], $carAccountId]
);
assertSplitBill(abs(floatval($totals['total_dr']) - 100000) < 0.01 && abs(floatval($totals['total_cr']) - 100000) < 0.01, 'Split bill journal remains balanced at ₹1,00,000');
assertSplitBill($postedCarLine['source_voucher_line_id'] === $carVoucherLine['id'], 'Posted car journal line retains its exact source bill line');
assertSplitBill(abs($engine->getCarTotalCost($carId) - ($costBefore + 80000)) < 0.01, 'Only the ₹80,000 allocation increases this car total cost');

$timeline = $engine->getCarTimeline($carId);
$timelineRow = array_values(array_filter($timeline, static fn($row) => $row['entry_id'] === $postedEntry['id']))[0] ?? null;
assertSplitBill(
    $timelineRow
    && $timelineRow['voucher_reference_no'] === $voucher['reference_no']
    && floatval($timelineRow['voucher_allocation_amount']) === 80000.0
    && floatval($timelineRow['voucher_total']) === 100000.0,
    'Car timeline identifies the source bill, full bill total, and this car allocation'
);

$register = $engine->getJournalVoucherRegister(date('Y-m-d'), date('Y-m-d'));
$registerRow = array_values(array_filter($register, static fn($row) => $row['id'] === $voucherId))[0] ?? null;
assertSplitBill($registerRow && str_contains($registerRow['car_allocations'], $registrationNo . ':::80000.00'), 'Large Bill Register exposes the linked car and allocated amount');

$reversalId = $engine->reverseEntry($postedEntry['id'], 'Split bill linkage regression reversal');
$reversedVoucher = $db->fetch("SELECT * FROM journal_vouchers WHERE id = ?", [$voucherId]);
$reversalCarLine = $db->fetch(
    "SELECT * FROM journal_lines WHERE journal_entry_id = ? AND account_id = ?",
    [$reversalId, $carAccountId]
);
assertSplitBill($reversedVoucher['status'] === 'REVERSED', 'Reversing the daily entry also reverses the source large bill');
assertSplitBill($reversalCarLine['source_voucher_line_id'] === $carVoucherLine['id'], 'Reversal keeps the same source bill allocation identity');
assertSplitBill(abs($engine->getCarTotalCost($carId) - $costBefore) < 0.01, 'Reversal removes the ₹80,000 from active car cost without deleting history');

$reversedTimeline = $engine->getCarTimeline($carId);
$originalHistory = array_values(array_filter($reversedTimeline, static fn($row) => $row['entry_id'] === $postedEntry['id']))[0] ?? null;
$reversalHistory = array_values(array_filter($reversedTimeline, static fn($row) => $row['entry_id'] === $reversalId))[0] ?? null;
assertSplitBill($originalHistory && $originalHistory['status'] === 'REVERSED' && $reversalHistory && intval($reversalHistory['is_reversal']) === 1, 'Car timeline keeps both original and reversal rows');

$auditCount = $db->fetch(
    "SELECT COUNT(*) AS cnt FROM audit_log WHERE business_id = ? AND entity_type = 'journal_voucher' AND entity_id = ?",
    [$business['id'], $voucherId]
);
assertSplitBill(intval($auditCount['cnt']) >= 3, 'Voucher create, post, and reverse changes are audit logged');

echo "Split bill car-linkage regression checks completed.\n";
