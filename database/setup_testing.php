<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) {
    exit("Refusing to initialize a database outside testing mode.\n");
}

$db = Database::getInstance();
$existingBusiness = $db->fetch('SELECT id FROM businesses LIMIT 1');
if ($existingBusiness) {
    exit("Testing database is already initialized. Reset it before running setup again.\n");
}

$adminEmail = trim((string) (getenv('TEST_ADMIN_EMAIL') ?: 'tester@tirangacarworld.test'));
$adminPassword = (string) (getenv('TEST_ADMIN_PASSWORD') ?: 'Testing@123');
$businessId = Database::uuid();
$userId = Database::uuid();

$db->insert('businesses', [
    'id' => $businessId,
    'name' => 'Tiranga Car World - TEST',
    'address' => 'Isolated local testing environment',
    'phone' => '9999999999',
    'email' => $adminEmail,
    'fy_start_month' => 4,
]);

$db->insert('users', [
    'id' => $userId,
    'business_id' => $businessId,
    'username' => $adminEmail,
    'password_hash' => password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 12]),
    'full_name' => 'Testing Administrator',
    'email' => $adminEmail,
    'role' => ROLE_ADMIN,
    'is_active' => 1,
]);

Auth::init();
$_SESSION['user_id'] = $userId;
$_SESSION['business_id'] = $businessId;
$_SESSION['username'] = $adminEmail;
$_SESSION['full_name'] = 'Testing Administrator';
$_SESSION['role'] = ROLE_ADMIN;
$_SESSION['business_name'] = 'Tiranga Car World - TEST';

$engine = new AccountingEngine($businessId, $userId);
$engine->setupDefaultAccounts();

$fy = getCurrentFY();
$db->insert('financial_years', [
    'id' => Database::uuid(),
    'business_id' => $businessId,
    'year_label' => getFYLabel($fy),
    'start_date' => $fy . '-04-01',
    'end_date' => ($fy + 1) . '-03-31',
    'is_active' => 1,
]);

echo "Testing business and administrator created.\n";

if (in_array('--with-demo', $argv, true)) {
    include __DIR__ . '/seed.php';
}

echo "\nTesting database is ready.\n";
echo "Login: {$adminEmail}\n";
echo "Password: {$adminPassword}\n";
