<?php
/**
 * Read-only acquisition summary for the Sell Car screen.
 *
 * Selling a car must never re-ask for the owner or the purchase dealer, so this
 * endpoint only reports what was already recorded at buying time.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$db = Database::getInstance();
Auth::check();
Auth::requireEntityAccess('car', 'read');

header('Content-Type: application/json');

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$carId = trim((string) get('car_id', ''));

$car = $carId === '' ? null : $db->fetch(
    "SELECT c.*, seller.name AS seller_name, seller.id AS seller_id
     FROM cars c
     LEFT JOIN debtors_creditors seller ON seller.id = c.seller_party_id AND seller.business_id = c.business_id
     WHERE c.id = ? AND c.business_id = ?",
    [$carId, $businessId]
);

if (!$car) {
    echo json_encode(['found' => false]);
    exit;
}

$pending = $engine->getCarPendingAmounts($carId);
$dealerSettlement = $engine->getCarDealerSettlement($carId);
$dealer = $dealerSettlement['dealer'];

$paidAtPurchase = $db->fetch(
    "SELECT COALESCE(SUM(CASE
            WHEN je.transaction_type = 'CAR_PURCHASE' AND jl.entry_type = 'CR' THEN jl.amount
            WHEN je.transaction_type = 'PURCHASE_PAYMENT_REPAIR' AND jl.entry_type = 'DR' THEN -jl.amount
            ELSE 0
        END), 0) AS total
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     JOIN accounts payment_account ON payment_account.id = jl.account_id
     WHERE je.business_id = ? AND je.car_id = ?
       AND je.transaction_type IN ('CAR_PURCHASE', 'PURCHASE_PAYMENT_REPAIR')
       AND je.status = 'POSTED' AND je.is_reversal = 0
       AND payment_account.entity_type IN ('CASH', 'BANK')",
    [$businessId, $carId]
);
$paidLater = !empty($car['seller_party_id']) ? $db->fetch(
    "SELECT COALESCE(SUM(jl.amount), 0) AS total
     FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     JOIN debtors_creditors seller ON seller.account_id = jl.account_id AND seller.id = ?
     WHERE je.business_id = ? AND je.car_id = ?
       AND je.status = 'POSTED' AND je.is_reversal = 0
       AND je.transaction_type = 'LOAN_REPAID'
       AND jl.entry_type = 'DR'",
    [$car['seller_party_id'], $businessId, $carId]
) : null;

$ownerPaid = round(max(0, floatval($paidAtPurchase['total'] ?? 0)) + max(0, floatval($paidLater['total'] ?? 0)), 2);

echo json_encode([
    'found' => true,
    'registration_no' => formatRegistrationNo($car['registration_no']),
    'owner_name' => $car['seller_name'] ?: '',
    'owner_url' => $car['seller_id'] ? APP_URL . 'parties/view.php?id=' . urlencode($car['seller_id']) : '',
    'dealer_name' => $dealer['name'] ?? '',
    'dealer_url' => $dealer ? APP_URL . 'parties/dealer_ledger.php?id=' . urlencode($dealer['id']) : '',
    'purchase_price' => formatAmount($car['purchase_price']),
    'owner_paid' => formatAmount($ownerPaid),
    'owner_pending' => formatAmount($pending['purchase_pending'] ?? 0),
    'owner_pending_value' => round(floatval($pending['purchase_pending'] ?? 0), 2),
    'dealer_commission' => formatAmount($dealerSettlement['commission_total']),
    'dealer_pending' => formatAmount($dealerSettlement['pending']),
    'dealer_pending_value' => round(floatval($dealerSettlement['pending']), 2),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
