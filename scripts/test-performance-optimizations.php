<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertPerf($condition, $message) {
    if (!$condition) throw new RuntimeException("FAIL: {$message}");
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);

// 1. Check functions.php has isSchemaEnsured and markSchemaEnsured
require_once $root . '/includes/functions.php';
assertPerf(function_exists('isSchemaEnsured'), 'isSchemaEnsured function exists in functions.php');
assertPerf(function_exists('markSchemaEnsured'), 'markSchemaEnsured function exists in functions.php');

// Test lock file creation and checking
$testKey = 'test_component_' . uniqid();
$testVer = 'v1';
assertPerf(!isSchemaEnsured($testKey, $testVer), 'isSchemaEnsured returns false before marking');
markSchemaEnsured($testKey, $testVer);
assertPerf(isSchemaEnsured($testKey, $testVer), 'isSchemaEnsured returns true after marking');
// Cleanup test lock
$dbName = defined('DB_NAME') ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) DB_NAME) : 'default';
$testLock = $root . '/tmp/schema_' . $testKey . '_' . $dbName . '_' . $testVer . '.lock';
if (file_exists($testLock)) {
    unlink($testLock);
}

// 2. Check db.php does not have SELECT 1 in query()
$dbSource = file_get_contents($root . '/includes/db.php');
assertPerf(!str_contains($dbSource, "\$this->ensureConnection();\n        try {\n            \$stmt = \$this->pdo->prepare"), 'db.php query() does not run ensureConnection before every query');
assertPerf(!str_contains($dbSource, "\$this->pdo->query('SELECT 1');"), 'db.php does not execute SELECT 1 ping');

// 3. Check accounting_engine.php guards ensureAdvancedSchema
$engineSource = file_get_contents($root . '/includes/accounting_engine.php');
assertPerf(str_contains($engineSource, "isSchemaEnsured('engine'"), 'accounting_engine.php checks isSchemaEnsured before running migrations');
assertPerf(str_contains($engineSource, "markSchemaEnsured('engine'"), 'accounting_engine.php calls markSchemaEnsured after running migrations');
assertPerf(str_contains($engineSource, "add-index-perf-journal_entries"), 'accounting_engine.php defines performance index migration step');

// 4. Check auth.php guards ensurePermissionSchema and ensureAuditLogSchema
$authSource = file_get_contents($root . '/includes/auth.php');
assertPerf(str_contains($authSource, "isSchemaEnsured('permissions'"), 'auth.php checks isSchemaEnsured for permissions');
assertPerf(str_contains($authSource, "isSchemaEnsured('audit'"), 'auth.php checks isSchemaEnsured for audit');

// 5. Check attachments.php guards ensureAttachmentSchema
$attSource = file_get_contents($root . '/includes/attachments.php');
assertPerf(str_contains($attSource, "isSchemaEnsured('attachments'"), 'attachments.php checks isSchemaEnsured');

// 6. Check functions.php redirect closes session
$funcSource = file_get_contents($root . '/includes/functions.php');
assertPerf(str_contains($funcSource, 'session_write_close()'), 'redirect() releases session lock before redirecting');

// 7. Check transactions/list.php user filter query
$listSource = file_get_contents($root . '/transactions/list.php');
assertPerf(!str_contains($listSource, 'SELECT DISTINCT u.id, u.full_name FROM journal_entries'), 'transactions/list.php does not perform full scan of journal_entries for user dropdown');

// 8. Check schema.sql has performance composite indexes
$schemaSource = file_get_contents($root . '/database/schema.sql');
assertPerf(str_contains($schemaSource, 'idx_je_biz_date_created'), 'schema.sql defines idx_je_biz_date_created');
assertPerf(str_contains($schemaSource, 'idx_jl_entry_account'), 'schema.sql defines idx_jl_entry_account');
assertPerf(str_contains($schemaSource, 'idx_alerts_unread'), 'schema.sql defines idx_alerts_unread');

// 9. Check .htaccess compression and expires
$htaccess = file_get_contents($root . '/.htaccess');
assertPerf(str_contains($htaccess, 'mod_deflate.c'), '.htaccess enables Gzip compression');
assertPerf(str_contains($htaccess, 'mod_expires.c'), '.htaccess enables browser caching');
assertPerf(str_contains($htaccess, 'lock)$'), '.htaccess blocks web access to lock files');

// 10. Check .user.ini opcache
$userIni = file_get_contents($root . '/.user.ini');
assertPerf(str_contains($userIni, 'opcache.enable = 1'), '.user.ini enables OPcache');

echo "\nAll performance optimization contract checks passed successfully!\n";
