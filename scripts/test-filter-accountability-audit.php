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
foreach (['buyer_status','rto_status','physical_status','source_entity_id','accounting_model','commission_type','from','to'] as $control) {
    $assertContains('outside-cars/index.php', 'name="' . $control . '"', "Outside Cars is missing $control filter.");
}
$assertContains('outside-cars/index.php', '<table data-no-quick-filter>', 'Outside Cars must use only its server-side filter panel.');
$assertContains('rto/list.php', '$hasCaseFilter', 'RTO totals must respond to case filters.');
$assertContains('reports/audit_log.php', 'name="from_date"', 'Audit Log needs a date-range filter.');
$assertContains('reports/audit_log.php', 'name="user_id"', 'Audit Log needs a user filter.');
$assertContains('reports/action_center.php', 'Accountable Desk', 'Action Center must identify accountable desks.');
$assertContains('reports/action_center.php', 'Money To Collect', 'Action Center must expose collection exposure.');
$assertContains('includes/header.php', 'reports/action_center.php', 'Action Center must be discoverable from navigation.');
$assertNotContains('outside-cars/create.php', 'Initial Advance', 'Outside Car intake must not offer an initial advance.');
$assertNotContains('outside-cars/create.php', 'Documents &amp; Vehicle Identifiers', 'Outside Car intake must not require documents or vehicle identifiers.');
$assertNotContains('outside-cars/create.php', 'uploadEntityAttachments(', 'Outside Car intake must not upload intake documents.');
$assertNotContains('outside-cars/view.php', "'documents'=>'Documents'", 'Outside Car workspace must not show an intake Documents section.');
$assertContains('outside-cars/view.php', "\$groups['gst_book']??[]", 'Outside Car workspace must include accessible GST Bank accounts.');
$assertNotContains('outside-cars/agency_workspace.php', 'Approve Settlement', 'Commission-agency workspace must not show settlement approval.');
$assertNotContains('outside-cars/agency_workspace.php', 'Profit Share', 'Commission-agency workspace must not show profit sharing.');
$assertContains('outside-cars/agency_workspace.php', 'Recoverable Source Advance', 'Commission-agency workspace must warn and report recoverable Source Advances.');
$assertContains('reports/outside_cars.php', 'name="expense_bearer"', 'Outside Cars report needs an expense-bearer filter.');
$assertContains('reports/outside_cars.php', 'name="account_id"', 'Outside Cars report needs an account filter.');
$assertContains('reports/outside_cars.php', 'name="funds_state"', 'Outside Cars report needs a funds-deployed filter.');
foreach (['entity_state','agreement_state','delivery_state','loan_commission_state'] as $control) {
    $assertContains('reports/outside_cars.php', 'name="' . $control . '"', "Outside Cars report is missing $control filter.");
}

if ($failures) {
    fwrite(STDERR, "Filter/accountability audit failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Filter/accountability audit passed ($checks checks).\n";
