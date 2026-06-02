<?php
session_start();
$_SESSION['user_id'] = 'e6e78403-e46c-4949-822d-4be7173641d6';
$_SESSION['business_id'] = '97f68b18-cbf9-48b8-bf09-60d385d449c4';
$_SESSION['role'] = 'ADMIN';
$_SESSION['full_name'] = 'Harshil Vekariya';
$_SESSION['username'] = 'test';

echo "Session ID: " . session_id() . "\n";
