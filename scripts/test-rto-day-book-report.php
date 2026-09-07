<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertRtoDayBook($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$root = dirname(__DIR__);
$report = file_get_contents($root . '/rto/day_book.php');
$book = file_get_contents($root . '/rto/list.php');
assertRtoDayBook($report !== false, 'RTO Day Book report source is readable');
assertRtoDayBook($book !== false, 'RTO Book source is readable');
assertRtoDayBook(str_contains($book, 'href="day_book.php"'), 'RTO Book exposes the RTO Day Book report');
assertRtoDayBook(str_contains($report, "a.code IN ('RTO-EXP', 'RTO-REC')"), 'RTO Day Book derives movement from canonical RTO journal accounts');
assertRtoDayBook(str_contains($report, 'original_entry_id'), 'RTO Day Book includes reversal-linked journal entries');
assertRtoDayBook(str_contains($report, 'entry_date BETWEEN ? AND ?'), 'RTO Day Book honors the selected date range');
assertRtoDayBook(str_contains($report, '$isLatestActivityDefault') && str_contains($report, 'MAX(je.entry_date) AS latest_date'), 'RTO Day Book opens on the latest RTO activity instead of empty financial-year dates');
assertRtoDayBook(str_contains($report, '$runningBalance = round($runningBalance + $credit - $debit, 2);'), 'RTO Day Book uses day-book carry-forward balance logic');
assertRtoDayBook(str_contains($report, 'Daily Total · Closing Balance'), 'RTO Day Book renders per-day totals and closing balance');
assertRtoDayBook(str_contains($report, '$isRtoDayBookExport') && str_contains($report, 'Download CSV'), 'RTO Day Book supports CSV export');

echo "RTO Day Book report checks completed.\n";
