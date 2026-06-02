<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain');

try {
    require_once __DIR__ . '/includes/db.php';
    $db = Database::getInstance();

    // Find a user and business
    $user = $db->fetch("SELECT id, business_id, role FROM users LIMIT 1");
    if (!$user) {
        die("No users found in database\n");
    }

    session_start();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['business_id'] = $user['business_id'];
    $_SESSION['role'] = $user['role'];

    echo "Logged in as User ID: " . $user['id'] . " | Business ID: " . $user['business_id'] . "\n\n";

    // Let's test different kinds
    $kinds = ['account', 'car', 'partner', 'employee', 'debtor'];
    foreach ($kinds as $kind) {
        $_GET['kind'] = $kind;
        $_GET['q'] = '';
        
        echo "--- Kind: $kind ---\n";
        try {
            ob_start();
            // Use local variable output buffer to prevent output before header, though on cli/plain text it does not matter
            include __DIR__ . '/transactions/search_entities.php';
            $out = ob_get_clean();
            echo $out . "\n\n";
        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n\n";
        }
    }
} catch (Exception $e) {
    echo "Fatal outer error: " . $e->getMessage() . "\n";
}
