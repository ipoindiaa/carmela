<?php
header('Content-Type: text/plain');
echo "Checking Database Settings:\n";
echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'not defined') . "\n";
echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'not defined') . "\n";
echo "DB_USER: " . (defined('DB_USER') ? DB_USER : 'not defined') . "\n";

$localFile = __DIR__ . '/config/database.local.php';
if (file_exists($localFile)) {
    echo "database.local.php exists!\n";
    $content = file_get_contents($localFile);
    echo "Content:\n" . $content . "\n";
} else {
    echo "database.local.php does NOT exist.\n";
}

// Try connecting
try {
    require_once __DIR__ . '/includes/db.php';
    $db = Database::getInstance();
    echo "DB Connection SUCCESS!\n";
} catch (Exception $e) {
    echo "DB Connection FAILED: " . $e->getMessage() . "\n";
}
