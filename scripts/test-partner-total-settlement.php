<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");
function checkTotalSettlement($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? AND role = 'ADMIN' LIMIT 1", [$business['id']]);
Auth::init();
$_SESSION = ['user_id' => $user['id'], 'business_id' => $business['id'], 'role' => $user['role'],
    'username' => $user['username'], 'full_name' => $user['full_name'], 'business_name' => $business['name']];
$engine = new AccountingEngine($business['id'], $user['id']);
$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 8));
$date = date('Y-m-d');
$cash = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND is_active = 1 LIMIT 1", [$business['id']]);
$db->beginTransaction();
try {
    $engine->loanTaken('Total Settlement Test Lender ' . $suffix, 7000000, $date, $cash['id'], 'Test-only operating cash');
    $partnerId = $engine->createPartner('Total Settlement Partner ' . $suffix, 'CARWISE');
    $carId = Database::uuid();
    $carAccount = $engine->createAccount('TST-CAR-' . $suffix, 'Total Settlement Car', 'ASSET', 'Inventory', 'CAR', $carId);
    $db->insert('cars', ['id' => $carId, 'business_id' => $business['id'], 'registration_no' => 'GJ99TS' . random_int(1000, 9999),
        'purchase_price' => 3400000, 'purchase_paid_amount' => 1700000, 'purchase_amount_mode' => 'FIXED',
        'purchase_date' => $date, 'status' => 'IN_STOCK', 'account_id' => $carAccount]);
    $engine->carPurchase($carId, 3400000, $date, $cash['id'], '34 lakh equal funding', [
        ['partner_id' => $partnerId, 'amount' => 1700000, 'profit_share_pct' => 50],
    ], 'Test Car Seller ' . $suffix, 1700000);
    try {
        $engine->partnerSettlement($partnerId, 1700000, $date, $cash['id'], 'PAY_TOTAL', 'Should protect stock funding');
        throw new RuntimeException('Stock funding was released');
    } catch (Exception $e) {
        checkTotalSettlement(str_contains($e->getMessage(), 'cannot exceed available'), 'Unsold-car funding cannot be repaid through total settlement');
    }
    $engine->carSale($carId, 3500000, $date, $cash['id'], '35 lakh sale', 'Test Buyer ' . $suffix, 3500000);
    $position = $engine->getPartnerPosition($partnerId);
    checkTotalSettlement($position['capital_balance'] === 1700000.0 && $position['pending_payable'] === 50000.0,
        'The 34 lakh purchase / 35 lakh sale creates 17 lakh capital and 50 thousand partner profit');
    $beforePnl = $engine->getProfitAndLoss($date, $date);
    $entryId = $engine->partnerSettlement($partnerId, 1750000, $date, $cash['id'], 'PAY_TOTAL', 'Full investment and profit paid');
    $partner = $db->fetch("SELECT * FROM partners WHERE id = ?", [$partnerId]);
    $lines = $db->fetchAll("SELECT * FROM journal_lines WHERE journal_entry_id = ?", [$entryId]);
    $capitalLine = array_values(array_filter($lines, fn($line) => $line['account_id'] === $partner['capital_account_id']));
    $profitLine = array_values(array_filter($lines, fn($line) => $line['account_id'] === $partner['current_account_id']));
    $cashLine = array_values(array_filter($lines, fn($line) => $line['account_id'] === $cash['id']));
    checkTotalSettlement(count($lines) === 3 && (float) $capitalLine[0]['amount'] === 1700000.0
        && $capitalLine[0]['entry_type'] === 'DR' && (float) $profitLine[0]['amount'] === 50000.0
        && $profitLine[0]['entry_type'] === 'DR' && (float) $cashLine[0]['amount'] === 1750000.0
        && $cashLine[0]['entry_type'] === 'CR', 'One balanced payment debits capital 17 lakh, current profit 50 thousand and credits cash 17.5 lakh');
    $position = $engine->getPartnerPosition($partnerId);
    checkTotalSettlement($position['capital_balance'] === 0.0 && $position['current_balance'] === 0.0 && $position['pending_payable'] === 0.0,
        'Full settlement clears both partner balances and the profit register');
    checkTotalSettlement($engine->getProfitAndLoss($date, $date) === $beforePnl, 'Capital repayment and profit payout do not change any P&L view');
    try {
        $engine->partnerSettlement($partnerId, 1, $date, $cash['id'], 'PAY_TOTAL', 'Duplicate payout blocked');
        throw new RuntimeException('Duplicate payout was accepted');
    } catch (Exception $e) {
        checkTotalSettlement(str_contains($e->getMessage(), 'cannot exceed available'), 'A second payout cannot exceed the remaining balances');
    }
    $entryId = $engine->correctEntry($entryId, $date, 'Correct full settlement', [], '', 1700000);
    $corrected = $engine->getPartnerPosition($partnerId);
    checkTotalSettlement($corrected['capital_balance'] === 50000.0 && $corrected['pending_payable'] === 0.0,
        'Edited settlement reapplies profit first and updates the capital remainder');
    $engine->reverseEntry($entryId, 'Undo corrected settlement');
    $position = $engine->getPartnerPosition($partnerId);
    checkTotalSettlement($position['capital_balance'] === 1700000.0 && $position['current_balance'] === 50000.0 && $position['pending_payable'] === 50000.0,
        'Reversal restores capital, profit and the profit application register');
    $partial = $engine->partnerSettlement($partnerId, 500000, $date, $cash['id'], 'PAY_TOTAL', 'Partial combined payment');
    $position = $engine->getPartnerPosition($partnerId);
    checkTotalSettlement($position['capital_balance'] === 1250000.0 && $position['current_balance'] === 0.0 && $position['pending_payable'] === 0.0,
        'Partial payment settles profit first and repays only the remaining payment portion as capital');
    $remaining = $engine->partnerSettlement($partnerId, 1250000, $date, $cash['id'], 'PAY_TOTAL', 'Remaining capital');
    checkTotalSettlement($engine->getPartnerPosition($partnerId)['capital_balance'] === 0.0, 'A later capital-only installment completes the settlement');
    $engine->reverseEntry($remaining, 'Undo capital-only installment');
    checkTotalSettlement($engine->getPartnerPosition($partnerId)['capital_balance'] === 1250000.0, 'Capital-only installment reverses through the normal withdrawal path');
    $engine->reverseEntry($partial, 'Undo partial settlement');
    checkTotalSettlement($engine->getPartnerPosition($partnerId)['pending_payable'] === 50000.0, 'Partial settlement reversal restores its profit allocation');
    $db->rollBack();
    echo "Total settlement checks completed; test data rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
