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

echo "Reference navigation checks completed.\n";
