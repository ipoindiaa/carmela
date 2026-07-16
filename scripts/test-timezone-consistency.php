<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) {
    exit("Refusing to run outside testing mode.\n");
}

function assertTimezoneTest($condition, $message) {
    if (!$condition) {
        throw new RuntimeException("FAIL: $message");
    }
    echo "PASS: $message\n";
}

$db = Database::getInstance();
$clock = $db->fetch(
    "SELECT @@session.time_zone AS session_timezone,
            NOW() AS local_now,
            UTC_TIMESTAMP() AS utc_now,
            TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes"
);

assertTimezoneTest(date_default_timezone_get() === APP_TIMEZONE, 'PHP runtime uses Asia/Kolkata');
assertTimezoneTest($clock['session_timezone'] === APP_TIMEZONE_OFFSET, 'MySQL session uses the Indian UTC offset');
assertTimezoneTest((int) $clock['offset_minutes'] === 330, 'MySQL local time is 330 minutes ahead of UTC');

$phpNow = date('Y-m-d H:i');
$databaseNow = substr((string) $clock['local_now'], 0, 16);
assertTimezoneTest($databaseNow === $phpNow, 'PHP and MySQL return the same local date and time');
assertTimezoneTest(formatDate($clock['local_now'], 'Y-m-d H:i') === $phpNow, 'Shared date formatting preserves Indian local time');

$db->query('CREATE TEMPORARY TABLE timezone_regression (created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$db->query('INSERT INTO timezone_regression VALUES ()');
$stored = $db->fetch('SELECT created_at FROM timezone_regression LIMIT 1');
assertTimezoneTest(
    substr((string) ($stored['created_at'] ?? ''), 0, 16) === $phpNow,
    'New MySQL timestamps are returned in Indian local time'
);

echo "Timezone consistency regression checks completed.\n";
