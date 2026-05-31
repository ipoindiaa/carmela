<?php
// Database Configuration
// Order of preference:
// 1. Environment variables
// 2. config/database.local.php (not committed)
// 3. Local development defaults

$dbDefaults = [
    'host' => '127.0.0.1',
    'name' => 'autobooks_pro',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
];

$dbOverrides = [];
$localConfigFile = __DIR__ . '/database.local.php';
if (file_exists($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        $dbOverrides = $localConfig;
    }
}

$dbHost = getenv('DB_HOST') ?: ($dbOverrides['host'] ?? $dbDefaults['host']);
$dbName = getenv('DB_NAME') ?: ($dbOverrides['name'] ?? $dbDefaults['name']);
$dbUser = getenv('DB_USER') ?: ($dbOverrides['user'] ?? $dbDefaults['user']);
$dbPass = getenv('DB_PASS');
if ($dbPass === false) {
    $dbPass = $dbOverrides['pass'] ?? $dbDefaults['pass'];
}
$dbCharset = getenv('DB_CHARSET') ?: ($dbOverrides['charset'] ?? $dbDefaults['charset']);

define('DB_HOST', $dbHost);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
define('DB_CHARSET', $dbCharset);
