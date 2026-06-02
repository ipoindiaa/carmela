<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/includes/db.php';
$db = Database::getInstance();
$users = $db->fetchAll("SELECT id, username, full_name, email, role, is_active FROM users");
print_r($users);
