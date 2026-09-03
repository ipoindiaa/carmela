<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertCarsListContract($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$source = file_get_contents(dirname(__DIR__) . '/cars/list.php');

assertCarsListContract(
    str_contains($source, "\$totalCost = max(0, (float) (\$carProfitability['total_cost'] ?? \$car['purchase_price']))"),
    'Cars list uses the accounting engine total cost as its single cost value'
);
assertCarsListContract(
    str_contains($source, '<th class="text-right">Total Cost</th>'),
    'Cars list shows a Total Cost column'
);
assertCarsListContract(
    !str_contains($source, '<th>Purchase Date / Time</th>')
        && !str_contains($source, '<th class="text-right">Purchase Price</th>')
        && !str_contains($source, '<th class="text-right">Extra Cost</th>'),
    'Cars list no longer shows Purchase Date/Time, Purchase Price, or Extra Cost columns'
);
assertCarsListContract(
    str_contains($source, '<tr><td colspan="9" class="text-center text-muted empty-table-cell">'),
    'Cars list empty state matches the revised column count'
);

echo "Cars list Total Cost contract completed.\n";
