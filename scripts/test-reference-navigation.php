<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertReferenceNavigation($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

$root = dirname(__DIR__);
$checks = [
    'Cash Book' => [
        'file' => 'reports/cashbook.php',
        'id' => "je.id AS entry_id",
        'link' => "../transactions/view.php?id=<?= urlencode(\$e['entry_id']) ?>",
    ],
    'Bank Book' => [
        'file' => 'reports/bankbook.php',
        'id' => "je.id AS entry_id",
        'link' => "../transactions/view.php?id=<?= urlencode(\$e['entry_id']) ?>",
    ],
    'General Ledger' => [
        'file' => 'reports/ledger.php',
        'id' => "je.id AS entry_id",
        'link' => "../transactions/view.php?id=<?= urlencode(\$e['entry_id']) ?>",
    ],
    'Employee Advance Ledger' => [
        'file' => 'employees/view.php',
        'id' => "je.id AS entry_id",
        'link' => "../transactions/view.php?id=<?= urlencode(\$l['entry_id']) ?>",
    ],
    'Party Account Ledger' => [
        'file' => 'parties/view.php',
        'id' => "je.id AS entry_id",
        'link' => "../transactions/view.php?id=<?= urlencode(\$l['entry_id']) ?>",
    ],
    'Party Open Items' => [
        'file' => 'parties/view.php',
        'id' => "journal_entry_id",
        'link' => "../transactions/view.php?id=<?= urlencode(\$item['journal_entry_id']) ?>",
    ],
];

foreach ($checks as $surface => $check) {
    $source = file_get_contents($root . '/' . $check['file']);
    assertReferenceNavigation($source !== false, "$surface source is readable");
    assertReferenceNavigation(strpos($source, $check['id']) !== false, "$surface rows carry a journal entry ID");
    assertReferenceNavigation(strpos($source, $check['link']) !== false, "$surface reference opens transaction detail");
}

$accountNavigationChecks = [
    'Account ledger URL helper' => ['includes/functions.php', 'function accountLedgerUrl('],
    'Trial Balance account links' => ['reports/trial_balance.php', "accountLedgerUrl(\$a['id'], \$ledgerFromDate, \$asOnDate)"],
    'Profit and Loss account links' => ['reports/profit_loss.php', "accountLedgerUrl(\$item['id'], \$dateFrom, \$dateTo)"],
    'Transaction journal-line account links' => ['transactions/view.php', "accountLedgerUrl(\$line['account_id']"],
    'Split bill allocation account links' => ['transactions/view.php', "accountLedgerUrl(\$allocation['account_id']"],
    'Large Bill main account links' => ['reports/jv_register.php', "accountLedgerUrl(\$voucher['primary_account_id'], \$dateFrom, \$dateTo)"],
    'RTO transaction reference links' => ['rto/list.php', "clean(\$entry['reference_no'])"],
    'Creditor account links' => ['reports/creditors.php', "../parties/view.php?id=<?= urlencode(\$creditor['id']) ?>"],
    'Employee advance account links' => ['reports/employee_advances.php', "../employees/view.php?id=<?= urlencode(\$employee['id']) ?>"],
    'Partner account links' => ['reports/partner_accounts.php', "../partners/view.php?id=<?= urlencode(\$partner['id']) ?>"],
];

foreach ($accountNavigationChecks as $surface => [$file, $needle]) {
    $source = file_get_contents($root . '/' . $file);
    assertReferenceNavigation($source !== false, "$surface source is readable");
    assertReferenceNavigation(strpos($source, $needle) !== false, "$surface opens the matching account or entry detail");
}

$purchasePaymentChecks = [
    'Purchase payment screen' => ['cars/purchase_payment.php', 'Record Purchase Payment'],
    'Purchase payment menu screen' => ['cars/purchase_payments.php', 'Pay Pending Purchase Balance'],
    'Purchase payment car-scoped posting' => ['cars/purchase_payment.php', 'loanRepaid('],
    'Purchase payment history details' => ['cars/purchase_payment.php', 'Purchase Payment Details'],
    'Car detail purchase-payment menu' => ['cars/view.php', 'purchase_payment.php?id='],
    'Car list purchase-payment menu' => ['cars/list.php', 'purchase_payment.php?id='],
    'Car detail permanent purchase-payment menu' => ['cars/view.php', "<a href=\"purchase_payment.php?id="],
    'Car list permanent purchase-payment menu' => ['cars/list.php', "<a href=\"purchase_payment.php?id="],
    'Sidebar purchase-payment menu' => ['includes/header.php', 'Car Purchase Payments'],
];

foreach ($purchasePaymentChecks as $surface => [$file, $needle]) {
    $source = file_get_contents($root . '/' . $file);
    assertReferenceNavigation($source !== false, "$surface source is readable");
    assertReferenceNavigation(strpos($source, $needle) !== false, "$surface exposes the dedicated purchased-car payment workflow");
}

echo "Reference navigation checks completed.\n";
