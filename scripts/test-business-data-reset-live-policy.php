<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/business_data_reset.php';

if (APP_ENV !== 'production' || stripos(DB_NAME, 'test') === false) {
    exit("Refusing anything except a production-mode database whose name contains test.\n");
}

function assertLiveCleanupPolicy($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$admin = $business ? $db->fetch(
    "SELECT * FROM users WHERE business_id = ? AND role = 'ADMIN' ORDER BY created_at LIMIT 1",
    [$business['id']]
) : null;
assertLiveCleanupPolicy($business && $admin, 'Production-mode isolated business and administrator exist');

Auth::init();
$_SESSION['user_id'] = $admin['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $admin['username'];
$_SESSION['full_name'] = $admin['full_name'];
$_SESSION['role'] = $admin['role'];
$_SESSION['business_name'] = $business['name'];

$fixtureId = Database::uuid();
Auth::auditLog(
    'SETTING_CHANGE',
    'live_cleanup_policy_fixture',
    $fixtureId,
    'Live cleanup policy fixture: this audit event must survive the full reset.',
    null,
    ['fixture' => true],
    'settings'
);
$auditBefore = (int) $db->fetch(
    "SELECT COUNT(*) AS count_value FROM audit_log WHERE business_id = ?",
    [$business['id']]
)['count_value'];
assertLiveCleanupPolicy($auditBefore >= 1, 'Existing audit event is present before the live cleanup');

$service = new BusinessDataResetService($business['id'], $admin['id']);
$result = $service->clear(
    BusinessDataResetService::SCOPE_ALL_BUSINESS_DATA,
    'Testing@123',
    'CLEAR EVERYTHING'
);

assertLiveCleanupPolicy(!APP_IS_TESTING, 'This check executed with live application policy enabled');
assertLiveCleanupPolicy(!empty($result['audit_history_retained']), 'Live full cleanup reports that audit history was retained');
assertLiveCleanupPolicy((int) $db->fetch(
    "SELECT COUNT(*) AS count_value
     FROM audit_log
     WHERE business_id = ? AND entity_type = 'live_cleanup_policy_fixture' AND entity_id = ?",
    [$business['id'], $fixtureId]
)['count_value'] === 1, 'Existing audit history survives the live full cleanup');
assertLiveCleanupPolicy((int) $db->fetch(
    "SELECT COUNT(*) AS count_value
     FROM audit_log
     WHERE business_id = ? AND entity_type = 'business_data_cleanup'",
    [$business['id']]
)['count_value'] >= 1, 'Live full cleanup appends its own audit event');
assertLiveCleanupPolicy((int) $db->fetch(
    "SELECT COUNT(*) AS count_value FROM users WHERE business_id = ?",
    [$business['id']]
)['count_value'] === 1, 'Live full cleanup keeps user logins');
assertLiveCleanupPolicy((int) $db->fetch(
    "SELECT COUNT(*) AS count_value FROM accounts WHERE business_id = ?",
    [$business['id']]
)['count_value'] === 18, 'Live full cleanup rebuilds the default accounts');

echo "Live cleanup policy checks completed.\n";
