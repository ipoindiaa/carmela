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

assertCleanupUi(str_contains($settings, '$isTestingEnvironment = APP_IS_TESTING;'), 'Cleanup page knows whether it is TEST or live');
assertCleanupUi(str_contains($settings, '<?= clean($cleanupEnvironmentLabel) ?> Data Cleanup'), 'Cleanup controls label the active environment');
assertCleanupUi(str_contains($settings, 'name="action" value="clear_scoped_data"'), 'Cleanup form uses the scoped server action');
assertCleanupUi(str_contains($settings, 'name="cleanup_scope"'), 'Cleanup form submits an explicit scope');
assertCleanupUi(str_contains($settings, 'openScopedCleanupModal'), 'Cleanup cards open the generic scoped-confirmation modal');
assertCleanupUi(str_contains($service, "'label' => 'Clear Daily Entries'"), 'Daily-entry cleanup is available');
assertCleanupUi(str_contains($service, "'label' => 'Clear Cars & Car Entries'"), 'Car cleanup is available');
assertCleanupUi(str_contains($service, "'label' => 'Reset All Business Data'"), 'Full-reset cleanup remains explicitly available');
assertCleanupUi(str_contains($settings, 'These actions permanently change live data.'), 'Live UI clearly explains the permanent action');
assertCleanupUi(str_contains($settings, 'View Audit Log'), 'Cleanup page links administrators to the audit trail');
assertCleanupUi(str_contains($service, 'assertCleanupEnvironment'), 'Service validates its supported application environment');
assertCleanupUi(!str_contains($service, 'assertTestingEnvironment'), 'Service does not block the authorized live cleanup path');
assertCleanupUi(str_contains($service, 'business_data_cleanup'), 'Cleanup writes a dedicated audit event');
assertCleanupUi(str_contains($service, '$preserveAuditHistory'), 'Full cleanup preserves audit history in live');
assertCleanupUi(str_contains($service, "SCOPE_DAILY_ENTRIES = 'DAILY_ENTRIES'"), 'Service exposes daily-entry scope');
assertCleanupUi(str_contains($service, "SCOPE_CARS_AND_LINKED_ENTRIES = 'CARS_AND_LINKED_ENTRIES'"), 'Service exposes car scope');
assertCleanupUi(str_contains($service, "SCOPE_ALL_BUSINESS_DATA = 'ALL_BUSINESS_DATA'"), 'Service exposes explicit full-reset scope');

echo "Scoped cleanup UI contract checks completed.\n";
