<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
ob_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
if (!APP_IS_TESTING || stripos(DB_NAME, 'test') === false) exit("Refusing non-testing database.\n");
function checkIdentity($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$db = Database::getInstance();
$business = $db->fetch("SELECT * FROM businesses ORDER BY created_at LIMIT 1");
$user = $db->fetch("SELECT * FROM users WHERE business_id = ? AND role = 'ADMIN' LIMIT 1", [$business['id']]);
Auth::init();
$_SESSION = ['user_id' => $user['id'], 'business_id' => $business['id'], 'role' => $user['role'],
    'username' => $user['username'], 'full_name' => $user['full_name'], 'business_name' => $business['name']];
$engine = new AccountingEngine($business['id'], $user['id']);
$people = new PersonIdentity($business['id']);
$suffix = strtoupper(substr(str_replace('-', '', Database::uuid()), 0, 8));
$db->beginTransaction();
try {
    $partyId = $engine->getOrCreateParty('Raja Motors ' . $suffix, 'CREDITOR');
    $partnerId = $engine->createPartner('Raja Partner ' . $suffix, 'CARWISE');
    $cash = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND is_active = 1 LIMIT 1", [$business['id']]);
    $engine->loanTaken('Raja Motors ' . $suffix, 200000, date('Y-m-d'), $cash['id'], 'Identity test lender');
    $engine->partnerInvest($partnerId, 1000000, date('Y-m-d'), $cash['id'], 'Identity test investment');
    $snapshot = function () use ($db, $business, $engine) {
        return [
            $db->fetchAll("SELECT id, account_id, journal_entry_id, amount, entry_type FROM journal_lines WHERE journal_entry_id IN (SELECT id FROM journal_entries WHERE business_id = ?) ORDER BY id", [$business['id']]),
            $db->fetchAll("SELECT id, current_balance, current_balance_type FROM accounts WHERE business_id = ? ORDER BY id", [$business['id']]),
            $engine->getProfitAndLoss('2000-01-01', '2099-12-31'),
        ];
    };
    $before = $snapshot();
    $members = $people->preview('party', $partyId, 'partner', $partnerId);
    $personId = $people->merge('party', $partyId, 'partner', $partnerId, 'Raja Unified ' . $suffix, $people->fingerprint($members));
    $balances = $people->balanceSheet(date('Y-m-d'));
    $personBalances = array_values(array_filter($balances, static fn($row) => $row['key'] === 'person:' . $personId));
    checkIdentity(count($personBalances) === 1 && $personBalances[0]['credit'] === 1200000.0
        && $personBalances[0]['debit'] === 0.0 && count($personBalances[0]['accounts']) === 2,
        'Person balance sheet groups the creditor and partner capital once, retaining both account balances');
    $previousDate = date('Y-m-d', strtotime('-1 day'));
    $beforeBalances = array_filter($people->balanceSheet($previousDate), static fn($row) => $row['key'] === 'person:' . $personId);
    checkIdentity(!$beforeBalances, 'A historical report excludes future-dated transactions');
    checkIdentity($db->inTransaction(), 'Merge does not commit the caller transaction');
    checkIdentity($people->identityFor('party', $partyId)['id'] === $people->identityFor('partner', $partnerId)['id'], 'Party and partner resolve to one person');
    checkIdentity($before === $snapshot(), 'Merge leaves every journal line, account balance and P&L result unchanged');
    checkIdentity(count($people->members('partner', $partnerId)) === 2, 'Unified profile includes both original roles');
    $rows = $people->search('Raja Motors ' . $suffix);
    checkIdentity(count($rows) === 1 && $rows[0]['person_id'] === $personId, 'Old name finds the unified person');
    checkIdentity($engine->getOrCreateParty('Raja Motors ' . $suffix, 'CREDITOR') === $partyId, 'Old party name reuses the existing ledger rather than creating a duplicate');
    try {
        $people->preview('party', $partyId, 'partner', $partnerId);
        throw new RuntimeException('Repeated merge was accepted');
    } catch (Exception $e) {
        checkIdentity(str_contains($e->getMessage(), 'already belong'), 'Repeated merge is rejected');
    }
    $third = $engine->getOrCreateParty('Another Raja ' . $suffix, 'DEBTOR');
    $debitEntry = $engine->loanGiven('Another Raja ' . $suffix, 30000, date('Y-m-d'), $cash['id'], 'Identity test receivable', $third);
    $members = $people->preview('party', $third, 'partner', $partnerId);
    $db->query("UPDATE debtors_creditors SET phone = '9999999999' WHERE id = ?", [$third]);
    try {
        $people->merge('party', $third, 'partner', $partnerId, 'Changed ' . $suffix, $people->fingerprint($members));
        throw new RuntimeException('Stale preview was accepted');
    } catch (Exception $e) {
        checkIdentity(str_contains($e->getMessage(), 'changed after'), 'A stale preview cannot merge changed records');
    }
    $members = $people->preview('party', $third, 'partner', $partnerId);
    $people->merge('party', $third, 'partner', $partnerId, 'Raja Final ' . $suffix, $people->fingerprint($members));
    checkIdentity(count($people->members('party', $partyId)) === 3, 'Merging an existing identity brings all its roles together');
    $mixedBalances = array_values(array_filter($people->balanceSheet(date('Y-m-d')),
        fn($row) => $row['key'] === 'person:' . $people->identityFor('party', $partyId)['id']));
    checkIdentity($mixedBalances[0]['debit'] === 30000.0 && $mixedBalances[0]['credit'] === 1200000.0,
        'Person report does not offset debtor receivables against creditor or capital balances');
    $engine->reverseEntry($debitEntry, 'Identity report reversal check');
    $reversedBalances = array_values(array_filter($people->balanceSheet(date('Y-m-d')),
        fn($row) => $row['key'] === 'person:' . $people->identityFor('party', $partyId)['id']));
    checkIdentity($reversedBalances[0]['debit'] === 0.0 && $reversedBalances[0]['credit'] === 1200000.0,
        'Original and reversal journal lines cancel exactly once in the dated person report');
    checkIdentity($db->fetch("SELECT name FROM partners WHERE id = ?", [$partnerId])['name'] === 'Raja Final ' . $suffix, 'One chosen name propagates to every role');
    $render = function ($page, $query) {
        $_GET = $query;
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PHP_SELF'] = '/' . $page;
        ob_start();
        include __DIR__ . '/../' . $page;
        return ob_get_clean();
    };
    $html = $render('persons/view.php', ['kind' => 'party', 'id' => $partyId]);
    checkIdentity(str_contains($html, 'Combined Transaction History') && str_contains($html, 'Raja Final ' . $suffix)
        && str_contains($html, 'Capital A/c') && str_contains($html, 'CREDITOR'), 'Combined statement renders the chosen name and separate role ledgers');
    $mergeHtml = $render('persons/merge.php', ['kind' => 'partner', 'id' => $partnerId]);
    checkIdentity(str_contains($mergeHtml, 'Preview Merge') && str_contains($mergeHtml, 'csrf_token'),
        'Merge form renders a preview step with CSRF protection');
    $balanceHtml = $render('reports/person_balance_sheet.php', ['as_on' => date('Y-m-d'), 'person' => 'person:' . $people->identityFor('party', $partyId)['id']]);
    checkIdentity(str_contains($balanceHtml, 'Share Report') && str_contains($balanceHtml, 'Print / Save PDF')
        && str_contains($balanceHtml, 'person-share-data'), 'Generated individual balance report renders share and print options');
    $foreign = new PersonIdentity(Database::uuid());
    try {
        $foreign->members('party', $partyId);
        throw new RuntimeException('Cross-business record was accepted');
    } catch (Exception $e) {
        checkIdentity(str_contains($e->getMessage(), 'not found'), 'Cross-business access is rejected');
    }
    $role = $_SESSION['role'];
    $_SESSION['role'] = 'STAFF';
    try {
        $people->merge('party', $partyId, 'partner', $partnerId, 'Unauthorized', '');
        throw new RuntimeException('Non-admin merge was accepted');
    } catch (Exception $e) {
        checkIdentity(str_contains($e->getMessage(), 'administrator'), 'Only administrators can merge identities');
    } finally {
        $_SESSION['role'] = $role;
    }
    $db->rollBack();
    echo "Identity checks completed; all test data rolled back.\n";
    ob_end_flush();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
