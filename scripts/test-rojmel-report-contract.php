<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$source = file_get_contents(__DIR__ . '/../transactions/list.php');
if ($source === false) throw new RuntimeException('Could not read All Entries source.');

function assertRojmelContract($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

assertRojmelContract(strpos($source, "get('view', 'entries') === 'rojmel'") !== false, 'All Entries exposes a dedicated Daily Rojmel mode');
assertRojmelContract(strpos($source, "entity_type IN ('CASH','BANK','GST')") !== false, 'Rojmel is scoped to Cash, Bank, and GST money books');
assertRojmelContract(strpos($source, "je.status IN ('POSTED','REVERSED')") !== false, 'Rojmel excludes drafts and derives balances from posted journal history');
assertRojmelContract(strpos($source, '$runningBalance + $credit - $debit') !== false, 'Rojmel applies Closing = Opening + Credit - Debit');
assertRojmelContract(strpos($source, 'Opening Balance') !== false && strpos($source, 'Daily Total · Closing Balance') !== false, 'Each date displays opening and closing balance information');
assertRojmelContract(strpos($source, "get('export', '') === 'csv'") !== false, 'Rojmel supports CSV download');
assertRojmelContract(strpos($source, 'Set Opening Balance') !== false, 'First opening balance remains controlled through Opening Balances');

echo "Rojmel report contract checks completed.\n";
