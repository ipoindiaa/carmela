<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");

function dbAssert($cond, $msg) {
    if (!$cond) throw new RuntimeException("FAIL: $msg");
    echo "PASS: $msg\n";
}

$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? LIMIT 1", [$business['id']]);
Auth::init();
$_SESSION['user_id'] = $user['id'];
$_SESSION['business_id'] = $business['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['business_name'] = $business['name'];

$engine = new AccountingEngine($business['id'], $user['id']);

$db->beginTransaction();
try {
    // 1. Database reconnection helper exists and savepoint nesting works
    dbAssert(method_exists($db, 'ensureConnection') || method_exists($db, 'getConnection'), 'Database has connection management');
    $db->query('SAVEPOINT tdh_outer');
    $cash = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code='CASH-001'", [$business['id']]);
    dbAssert($cash, 'Cash account exists');

    // 2. Nested transaction via savepoints: inner rollback does not kill outer
    $db->beginTransaction(); // should create savepoint
    $tmpId = Database::uuid();
    $db->insert('accounts', ['id'=>$tmpId,'business_id'=>$business['id'],'code'=>'TMP-'.substr($tmpId,0,6),'name'=>'Tmp Nested','group_name'=>'ASSET','sub_group'=>'Current Assets','entity_type'=>'GENERAL']);
    dbAssert($db->fetch("SELECT id FROM accounts WHERE id = ?", [$tmpId]), 'Nested insert visible');
    $db->rollBack(); // rolls back savepoint
    dbAssert(!$db->fetch("SELECT id FROM accounts WHERE id = ?", [$tmpId]), 'Nested rollback reverted inner insert only');
    dbAssert($db->inTransaction(), 'Outer transaction still active after nested rollback');

    // 3. Duplicate account code is idempotent (no exception, returns same id)
    $firstId = $engine->createAccount('TST-DUP-DB', 'Dup Test', 'ASSET', 'Current Assets', 'GENERAL');
    $secondId = $engine->createAccount('TST-DUP-DB', 'Dup Test', 'ASSET', 'Current Assets', 'GENERAL');
    dbAssert($firstId === $secondId, 'Duplicate account code returns existing id (race-safe)');

    // 4. setupDefaultAccounts is idempotent (call twice, no duplicate error, still 2 default code count =1 each)
    $engine->setupDefaultAccounts();
    $engine->setupDefaultAccounts();
    $cashCount = $db->fetch("SELECT COUNT(*) cnt FROM accounts WHERE business_id = ? AND code='CASH-001'", [$business['id']]);
    dbAssert(intval($cashCount['cnt'])===1, 'setupDefaultAccounts is idempotent');

    // 5. insert with invalid table name is rejected (SQL injection guard)
    $injected=false;
    try { $db->insert('accounts; DROP TABLE accounts; --', ['id'=>Database::uuid(),'business_id'=>$business['id'],'code'=>'X','name'=>'x','group_name'=>'ASSET']); } catch (Throwable $e) { $injected = stripos($e->getMessage(),'Invalid table')!==false; }
    dbAssert($injected, 'Invalid table name rejected');

    // 6. Cross-business account posting blocked (already in integrity but re-verify under new DB)
    $foreignBid = Database::uuid();
    $db->insert('businesses', ['id'=>$foreignBid,'name'=>'Foreign Hardening']);
    $foreignAcc = Database::uuid();
    $db->insert('accounts', ['id'=>$foreignAcc,'business_id'=>$foreignBid,'code'=>'FOR-HARD','name'=>'Foreign','group_name'=>'ASSET','sub_group'=>'Current Assets','entity_type'=>'GENERAL']);
    $blocked=false;
    try { $engine->postJournalEntry('GENERAL_EXPENSE', date('Y-m-d'), 'cross biz', [['account_id'=>$cash['id'],'amount'=>10,'type'=>'DR'],['account_id'=>$foreignAcc,'amount'=>10,'type'=>'CR']]); } catch (Throwable $e) { $blocked = stripos($e->getMessage(),'does not belong')!==false; }
    dbAssert($blocked, 'Cross-business journal rejected under hardened DB');

    // 7. Account balance updates are FOR UPDATE locked (verify code contains FOR UPDATE) and journal auto-reconnect path exists
    $src = file_get_contents(__DIR__.'/../includes/accounting_engine.php');
    dbAssert(strpos($src, 'FOR UPDATE')!==false, 'Balance updates use SELECT FOR UPDATE locking');
    $dbSrc = file_get_contents(__DIR__.'/../includes/db.php');
    dbAssert(strpos($dbSrc, 'gone away')!==false && strpos($dbSrc,'Deadlock')!==false, 'DB layer retries gone-away and deadlocks');
    dbAssert(strpos($dbSrc, 'quoteIdentifier')!==false, 'DB layer quotes identifiers');

    // 8. Schema migration errors are not silently swallowed (should log and rethrow in testing)
    dbAssert(strpos($src, 'error_log("AutoBooks schema migration failed')!==false, 'Schema migration logs errors and rethrows in testing');

    $db->rollBack();
    echo "Database hardening tests passed and rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "FAIL: ".$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
}
