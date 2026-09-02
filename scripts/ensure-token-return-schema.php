<?php
/**
 * Safe deployment check for the token-return ledger schema.
 *
 * Constructing the accounting engine applies additive schema migrations only;
 * this script never creates or changes accounting vouchers.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$db = Database::getInstance();
$user = $db->fetch(
    'SELECT id, business_id FROM users WHERE business_id IS NOT NULL AND business_id <> \'\' ORDER BY created_at ASC LIMIT 1'
);
if (!$user || empty($user['business_id'])) {
    throw new RuntimeException('No business-linked user is available for the schema check.');
}

new AccountingEngine($user['business_id'], $user['id']);

$refundedColumn = $db->fetch(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'car_tokens' AND COLUMN_NAME = 'refunded_amount'"
);
$refundRegister = $db->fetch(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'car_token_refunds'"
);

if (!$refundedColumn || !$refundRegister) {
    throw new RuntimeException('Token return schema verification failed.');
}

echo "PASS: Token return schema is ready.\n";
