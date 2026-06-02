<?php
header('Content-Type: text/plain');
$logFile = __DIR__ . '/transactions/search_debug.log';
if (file_exists($logFile)) {
    echo file_get_contents($logFile);
} else {
    echo "Log file does not exist yet.";
}
