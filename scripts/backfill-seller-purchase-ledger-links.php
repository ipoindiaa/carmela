<?php
/**
 * Repair historical CAR_PURCHASE entries created before immediate seller
 * settlements were posted through the seller ledger.
 *
 * Dry run:
 *   APP_ENV=testing php scripts/backfill-seller-purchase-ledger-links.php --business-id=<uuid>
 * Apply (requires an explicit business and confirmation):
 *   APP_ENV=testing php scripts/backfill-seller-purchase-ledger-links.php --business-id=<uuid> --apply --confirm=REPAIR_SELLER_LEDGER
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$options = getopt('', ['business-id:', 'apply', 'confirm:']);
$businessId = trim((string) ($options['business-id'] ?? ''));
$apply = array_key_exists('apply', $options);
$confirmation = trim((string) ($options['confirm'] ?? ''));

if ($businessId === '') exit("A specific --business-id is required.\n");
if ($apply && $confirmation !== 'REPAIR_SELLER_LEDGER') {
    exit("Apply refused. Re-run with --apply --confirm=REPAIR_SELLER_LEDGER after reviewing the dry run.\n");
}

$db = Database::getInstance();
$business = $db->fetch("SELECT id, name FROM businesses WHERE id = ?", [$businessId]);
if (!$business) exit("Business not found.\n");

$entries = $db->fetchAll(
    "SELECT je.id, je.reference_no, je.entry_date, je.car_id, je.party_id,
            c.registration_no, c.seller_party_id,
            dc.account_id AS seller_account_id,
            COALESCE(SUM(CASE WHEN car_line.entry_type = 'DR' THEN car_line.amount ELSE 0 END), 0) AS car_debit,
            COALESCE(SUM(CASE WHEN seller_line.entry_type = 'CR' THEN seller_line.amount ELSE 0 END), 0) AS seller_credit,
            COALESCE(SUM(CASE WHEN payment_account.entity_type IN ('CASH','BANK') AND payment_line.entry_type = 'CR' THEN payment_line.amount ELSE 0 END), 0) AS cash_bank_paid
     FROM journal_entries je
     JOIN cars c ON c.id = je.car_id AND c.business_id = je.business_id
     JOIN debtors_creditors dc ON dc.id = c.seller_party_id AND dc.business_id = je.business_id
     JOIN journal_lines car_line ON car_line.journal_entry_id = je.id
     JOIN accounts car_account ON car_account.id = car_line.account_id AND car_account.id = c.account_id
     LEFT JOIN journal_lines seller_line ON seller_line.journal_entry_id = je.id AND seller_line.account_id = dc.account_id
     LEFT JOIN journal_lines payment_line ON payment_line.journal_entry_id = je.id
     LEFT JOIN accounts payment_account ON payment_account.id = payment_line.account_id
     WHERE je.business_id = ?
       AND je.transaction_type = 'CAR_PURCHASE'
       AND je.status = 'POSTED'
       AND je.is_reversal = 0
     GROUP BY je.id, je.reference_no, je.entry_date, je.car_id, je.party_id,
              c.registration_no, c.seller_party_id, dc.account_id
     HAVING car_debit > seller_credit + 0.009
        AND cash_bank_paid > 0.009
     ORDER BY je.entry_date, je.created_at, je.id",
    [$businessId]
);

$repairs = [];
foreach ($entries as $entry) {
    $missingLiability = round(floatval($entry['car_debit']) - floatval($entry['seller_credit']), 2);
    $cashBankPaid = round(floatval($entry['cash_bank_paid']), 2);
    if ($missingLiability <= 0.009 || $cashBankPaid + 0.009 < $missingLiability) {
        continue;
    }
    $repairs[] = $entry + ['amount' => $missingLiability];
}

echo ($apply ? 'APPLY' : 'DRY RUN') . " for {$business['name']}: " . count($repairs) . " purchase ledger repair(s).\n";
foreach ($repairs as $repair) {
    echo "- {$repair['reference_no']} {$repair['registration_no']}: seller payable/payment " . formatAmount($repair['amount']) . "\n";
}

if (!$apply || !$repairs) {
    exit($apply ? "No changes required.\n" : "No changes made.\n");
}

$db->beginTransaction();
try {
    foreach ($repairs as $repair) {
        // These two source-ledger lines net to zero, so this repairs the
        // relationship projection without changing cash, inventory, payables,
        // or the trial balance. The original journal reference/date are retained.
        $db->insert('journal_lines', [
            'id' => Database::uuid(),
            'journal_entry_id' => $repair['id'],
            'account_id' => $repair['seller_account_id'],
            'amount' => $repair['amount'],
            'entry_type' => 'CR',
            'narration' => 'Historical seller payable reconstruction',
        ]);
        $db->insert('journal_lines', [
            'id' => Database::uuid(),
            'journal_entry_id' => $repair['id'],
            'account_id' => $repair['seller_account_id'],
            'amount' => $repair['amount'],
            'entry_type' => 'DR',
            'narration' => 'Historical immediate seller payment reconstruction',
        ]);
        $db->query(
            "UPDATE journal_entries SET party_id = COALESCE(party_id, ?) WHERE id = ? AND business_id = ?",
            [$repair['seller_party_id'], $repair['id'], $businessId]
        );
    }
    $db->commit();
    echo "Completed. Re-run without --apply to confirm there are no remaining candidates.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
