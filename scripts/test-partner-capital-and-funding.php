<?php

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function assertPartnerCapital($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? ORDER BY created_at LIMIT 1", [$business['id']]);
$cash = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND is_active = 1 LIMIT 1", [$business['id']]);
assertPartnerCapital($business && $user && $cash, 'Testing business, administrator, and Cash account exist');

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
$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 7));
$phoneSuffix = str_pad((string) (abs(crc32($suffix)) % 1000000), 6, '0', STR_PAD_LEFT);

$db->beginTransaction();
try {
    $mainPartnerId = $engine->createPartner('Main Capital ' . $suffix, 'MAIN', '9911' . $phoneSuffix, '', '', 50, date('Y-m-d'));
    $carWisePartnerId = $engine->createPartner('Car-wise Capital ' . $suffix, 'CARWISE', '9922' . $phoneSuffix, '', '', 50, date('Y-m-d'));

    $activePartners = $db->fetchAll(
        "SELECT id FROM partners WHERE business_id = ? AND is_active = 1 AND id IN (?, ?) ORDER BY name",
        [$business['id'], $mainPartnerId, $carWisePartnerId]
    );
    assertPartnerCapital(count($activePartners) === 2, 'Both Main and Car-wise active partners are available to the selector');

    $investId = $engine->partnerInvest($carWisePartnerId, 10000, date('Y-m-d'), $cash['id'], 'Car-wise partner capital added');
    $investLine = $db->fetch(
        "SELECT jl.entry_type, jl.amount
         FROM journal_lines jl JOIN partners p ON p.capital_account_id = jl.account_id
         WHERE jl.journal_entry_id = ? AND p.id = ?",
        [$investId, $carWisePartnerId]
    );
    assertPartnerCapital(($investLine['entry_type'] ?? '') === 'CR' && floatval($investLine['amount'] ?? 0) === 10000.0, 'Partner Added Money posts to the selected Car-wise partner capital account');

    $withdrawId = $engine->partnerWithdraw($carWisePartnerId, 2500, date('Y-m-d'), $cash['id'], 'Car-wise partner capital taken');
    $withdrawLine = $db->fetch(
        "SELECT jl.entry_type, jl.amount
         FROM journal_lines jl JOIN partners p ON p.capital_account_id = jl.account_id
         WHERE jl.journal_entry_id = ? AND p.id = ?",
        [$withdrawId, $carWisePartnerId]
    );
    assertPartnerCapital(($withdrawLine['entry_type'] ?? '') === 'DR' && floatval($withdrawLine['amount'] ?? 0) === 2500.0, 'Partner Took Money posts to the selected Car-wise partner capital account');

    $creditorPartnerName = 'Creditor Partner ' . $suffix;
    $creditorPartnerId = $engine->createPartner($creditorPartnerName, 'MAIN', '9933' . $phoneSuffix, '', '', 0, date('Y-m-d'));
    $engine->loanTaken($creditorPartnerName, 300000, date('Y-m-d'), $cash['id'], 'Partner creditor funding');
    $creditorParty = $db->fetch(
        "SELECT * FROM debtors_creditors WHERE business_id = ? AND name = ? AND type = 'CREDITOR'",
        [$business['id'], $creditorPartnerName]
    );
    $creditorWithdrawId = $engine->partnerWithdraw($creditorPartnerId, 50000, date('Y-m-d'), $cash['id'], 'Settle partner creditor payable');
    $creditorSettlementLine = $db->fetch(
        "SELECT entry_type, amount FROM journal_lines WHERE journal_entry_id = ? AND account_id = ?",
        [$creditorWithdrawId, $creditorParty['account_id']]
    );
    $creditorCapitalLine = $db->fetch(
        "SELECT id FROM journal_lines WHERE journal_entry_id = ? AND account_id = ?",
        [$creditorWithdrawId, $db->fetch("SELECT capital_account_id FROM partners WHERE id = ?", [$creditorPartnerId])['capital_account_id']]
    );
    assertPartnerCapital(
        ($creditorSettlementLine['entry_type'] ?? '') === 'DR' && floatval($creditorSettlementLine['amount'] ?? 0) === 50000.0 && !$creditorCapitalLine,
        'Partner withdrawal settles the matching creditor payable without making zero capital negative'
    );
    assertPartnerCapital(
        abs($engine->getPartyOutstandingAmount($creditorParty['id']) - 250000.0) < 0.01,
        'Creditor payable reduces by the partner payment amount'
    );

    $carId = Database::uuid();
    $registration = 'GJ99PF' . substr($suffix, 0, 4);
    $carAccountId = $engine->createAccount('PF-CAR-' . $suffix, 'Partner funding test - ' . $registration, 'ASSET', 'Inventory', 'CAR', $carId);
    $db->insert('cars', [
        'id' => $carId,
        'business_id' => $business['id'],
        'registration_no' => $registration,
        'make' => 'Funding',
        'model' => 'Regression',
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => 50000,
        'status' => 'IN_STOCK',
        'account_id' => $carAccountId,
    ]);
    $engine->carPurchase($carId, 50000, date('Y-m-d'), $cash['id'], 'Initial ₹50,000 partner funding', [[
        'partner_id' => $mainPartnerId,
        'amount' => 50000,
        'profit_share_pct' => 50,
    ]], 0, null, 0);

    $engine->correctCarPartnerFunding($carId, [
        ['partner_id' => $mainPartnerId, 'amount' => 30000, 'profit_share_pct' => 50],
        ['partner_id' => $carWisePartnerId, 'amount' => 20000, 'profit_share_pct' => 50],
    ], date('Y-m-d'), 'Reallocate the fixed partner funding total');
    $funding = $db->fetchAll("SELECT partner_id, funding_amount FROM car_partnerships WHERE business_id = ? AND car_id = ? AND status = 'ACTIVE'", [$business['id'], $carId]);
    $fundingTotal = array_sum(array_map(static fn($row) => floatval($row['funding_amount']), $funding));
    assertPartnerCapital(count($funding) === 2 && abs($fundingTotal - 50000) < 0.01, '₹50,000 car funding reallocates across partners without changing the total');

    $blocked = false;
    try {
        $engine->correctCarPartnerFunding($carId, [
            ['partner_id' => $mainPartnerId, 'amount' => 35000, 'profit_share_pct' => 50],
            ['partner_id' => $carWisePartnerId, 'amount' => 20000, 'profit_share_pct' => 50],
        ], date('Y-m-d'), 'Attempt to change the fixed funding total');
    } catch (Throwable $e) {
        $blocked = str_contains($e->getMessage(), 'total partner funding must remain');
    }
    assertPartnerCapital($blocked, 'A car funding total other than ₹50,000 remains blocked');

    $trialBalance = $engine->getTrialBalance();
    $dr = 0; $cr = 0;
    foreach ($trialBalance as $row) {
        if (($row['balance_type'] ?? '') === 'DR') $dr += floatval($row['balance_amount'] ?? 0);
        else $cr += floatval($row['balance_amount'] ?? 0);
    }
    assertPartnerCapital(abs($dr - $cr) < 0.01, 'Trial Balance remains balanced after partner capital and funding reallocation');

    $db->rollBack();
    echo "Partner capital and funding regression completed and rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
