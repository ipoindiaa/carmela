<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

$assertContains = static function (string $file, string $needle, string $message) use ($root, &$failures, &$checks): void {
    $checks++;
    $content = file_get_contents($root . '/' . $file);
    if ($content === false || !str_contains($content, $needle)) $failures[] = $message . " [$file]";
};
$assertNotContains = static function (string $file, string $needle, string $message) use ($root, &$failures, &$checks): void {
    $checks++;
    $content = file_get_contents($root . '/' . $file);
    if ($content !== false && str_contains($content, $needle)) $failures[] = $message . " [$file]";
};

$assertContains('assets/js/app.js', "table.closest('[data-lazy-list]')", 'Quick visible-row filters must skip lazy server-backed lists.');
$assertNotContains('cars/list.php', 'syncCarPartyLinks(', 'Cars list must remain read-only on GET.');
$assertContains('transactions/list.php', "je.transaction_type = 'JOURNAL_VOUCHER'", 'JV-only readers need an explicit authorized scope.');
foreach (['name="q"','name="from_date"','name="to_date"','name="status"','name="user_id"','name="min_amount"','name="max_amount"'] as $control) {
    $assertContains('transactions/list.php', $control, "All Entries is missing filter control $control.");
}
$assertContains('rto/list.php', '$hasCaseFilter', 'RTO totals must respond to case filters.');
$assertContains('rto/list.php', 'name="date"', 'RTO Book needs a specific-day filter.');
$assertContains('rto/list.php', 'P&amp;L RTO Net', 'RTO Book must show the journal-derived RTO contribution to profit.');
$assertNotContains('rto/list.php', 'RTO Work', 'RTO Book must use RTO Narration instead of RTO Work.');
$assertContains('rto/list.php', "\$rtoDraftValue", 'Failed RTO validation must retain submitted fields and account selection.');
$assertContains('includes/header.php', 'reports/car_inventory.php', 'Car Inventory must have its own discoverable menu.');
$assertContains('reports/car_inventory.php', 'Inventory Total', 'Car Inventory report must show the filtered inventory total.');
$assertContains('reports/balance_sheet.php', 'Vehicle Inventory (Consolidated)', 'Balance Sheet must replace car-wise inventory clutter with one accounting-safe summary.');
$assertContains('transactions/new.php', "narrationInput.required = !isCarSale", 'Sold Car narration must be optional without weakening other entry types.');
$assertContains('cars/view.php', 'href="#payment-history"', 'Sold cars need a direct Payment History action.');
$assertContains('reports/audit_log.php', 'name="from_date"', 'Audit Log needs a date-range filter.');
$assertContains('reports/audit_log.php', 'name="user_id"', 'Audit Log needs a user filter.');
$assertContains('reports/action_center.php', 'Accountable Desk', 'Action Center must identify accountable desks.');
$assertContains('reports/action_center.php', 'Money To Collect', 'Action Center must expose collection exposure.');
$assertContains('includes/header.php', 'reports/action_center.php', 'Action Center must be discoverable from navigation.');
if ($failures) {
    fwrite(STDERR, "Filter/accountability audit failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Filter/accountability audit passed ($checks checks).\n";
