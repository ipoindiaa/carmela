<?php
header('Content-Type: text/plain');
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__));
foreach ($it as $file) {
    if ($file->isDir()) continue;
    if (basename($file->getPathname()) === 'error_log' || substr($file->getPathname(), -4) === '.log') {
        echo "Found log: " . $file->getPathname() . " (Size: " . $file->getSize() . " bytes)\n";
        if ($file->getSize() < 50000) {
            echo "--- Content ---\n";
            echo file_get_contents($file->getPathname());
            echo "\n---------------\n\n";
        } else {
            echo "--- Content (Last 50 lines) ---\n";
            $lines = file($file->getPathname());
            echo implode("", array_slice($lines, -50));
            echo "\n---------------\n\n";
        }
    }
}
