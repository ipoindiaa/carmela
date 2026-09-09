<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertCleanupUi($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$settings = file_get_contents(__DIR__ . '/../settings/accounts.php');
$service = file_get_contents(__DIR__ . '/../includes/business_data_reset.php');
if ($settings === false || $service === false) {
    throw new RuntimeException('Could not read scoped cleanup source files.');
}

assertCleanupUi(str_contains($settings, "if (APP_IS_TESTING)"), 'Cleanup controls render only in the testing environment');
assertCleanupUi(str_contains($settings, 'name="action" value="clear_test_data"'), 'Cleanup form uses the scoped server action');
assertCleanupUi(str_contains($settings, 'name="cleanup_scope"'), 'Cleanup form submits an explicit scope');
assertCleanupUi(str_contains($service, "'label' => 'Clear Daily Entries'"), 'Daily-entry cleanup is available');
assertCleanupUi(str_contains($service, "'label' => 'Clear Cars & Car Entries'"), 'Car cleanup is available');
assertCleanupUi(str_contains($service, "'label' => 'Erase Everything'"), 'Full-reset cleanup remains explicitly available');
assertCleanupUi(str_contains($settings, 'Bulk cleanup is disabled in the live system'), 'Live UI explains the safe individual-record path');
assertCleanupUi(str_contains($service, 'assertTestingEnvironment'), 'Service enforces the testing-only server guard');
assertCleanupUi(str_contains($service, "SCOPE_DAILY_ENTRIES = 'DAILY_ENTRIES'"), 'Service exposes daily-entry scope');
assertCleanupUi(str_contains($service, "SCOPE_CARS_AND_LINKED_ENTRIES = 'CARS_AND_LINKED_ENTRIES'"), 'Service exposes car scope');
assertCleanupUi(str_contains($service, "SCOPE_ALL_BUSINESS_DATA = 'ALL_BUSINESS_DATA'"), 'Service exposes explicit full-reset scope');

echo "Scoped cleanup UI contract checks completed.\n";
