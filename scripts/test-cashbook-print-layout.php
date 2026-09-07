<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertCashBookPrint($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$root = dirname(__DIR__);
$report = file_get_contents($root . '/reports/cashbook.php');
$css = file_get_contents($root . '/assets/css/ui-polish.css');
assertCashBookPrint($report !== false && $css !== false, 'Cash Book report and print stylesheet are readable');
assertCashBookPrint(str_contains($report, 'cashbook-report-table'), 'Cash Book table has a dedicated print target');
assertCashBookPrint(str_contains($report, 'print-report-heading'), 'Cash Book includes repeatable report metadata in the table head');
assertCashBookPrint(str_contains($css, '.cashbook-report-table thead { display: table-header-group !important; }'), 'Cash Book repeats table headings on each printed page');
assertCashBookPrint(str_contains($css, '.cashbook-report-shell') && str_contains($css, 'break-inside: auto !important;'), 'Cash Book table shell can paginate without a blank first page');
assertCashBookPrint(str_contains($css, '@page { size: landscape; margin: 10mm; }'), 'Cash Book print layout uses a readable landscape page');
echo "Cash Book print layout checks completed.\n";
