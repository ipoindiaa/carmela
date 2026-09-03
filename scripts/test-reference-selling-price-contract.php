<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertReferencePriceContract($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$add = file_get_contents($root . '/cars/add.php');
$list = file_get_contents($root . '/cars/list.php');
$view = file_get_contents($root . '/cars/view.php');

assertReferencePriceContract(
    str_contains($add, 'Reference Selling Price (₹)')
        && str_contains($view, 'Reference Selling Price (₹)')
        && str_contains($view, 'name="expected_sale_price"'),
    'Reference selling price is available while adding and editing a car'
);
assertReferencePriceContract(
    str_contains($list, '<th class="text-right">Reference Selling Price</th>')
        && str_contains($list, 'Reference only'),
    'Cars menu gives each reference selling price a quick visible display'
);
assertReferencePriceContract(
    str_contains($view, '<div class="stat-label">Reference Selling Price</div>')
        && str_contains($view, 'Reference only — not part of calculations'),
    'Car page clearly labels the value as a non-accounting reference'
);

echo "Reference selling price UI contract completed.\n";
