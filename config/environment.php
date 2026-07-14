<?php

$appEnvironment = getenv('APP_ENV');
if ($appEnvironment === false || trim((string) $appEnvironment) === '') {
    $environmentFile = __DIR__ . '/environment.local.php';
    if (file_exists($environmentFile)) {
        $environmentOverride = require $environmentFile;
        if (is_string($environmentOverride)) {
            $appEnvironment = $environmentOverride;
        } elseif (is_array($environmentOverride)) {
            $appEnvironment = $environmentOverride['environment'] ?? 'production';
        }
    }
}

$appEnvironment = strtolower(trim((string) ($appEnvironment ?: 'production')));
if (!in_array($appEnvironment, ['production', 'testing'], true)) {
    throw new RuntimeException('Unsupported application environment.');
}

if (!defined('APP_ENV')) {
    define('APP_ENV', $appEnvironment);
}
