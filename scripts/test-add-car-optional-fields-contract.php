<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function assertOptionalCarFieldContract(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$addCar = file_get_contents($root . '/cars/add.php');
$engine = file_get_contents($root . '/includes/accounting_engine.php');

assertOptionalCarFieldContract(
    str_contains($addCar, '$purchaseDate = trim((string) post(\'purchase_date\'));')
        && str_contains($addCar, "throw new Exception('Purchase date is required.');")
        && str_contains($addCar, 'name="purchase_date" class="form-control" value="<?= clean(post(\'purchase_date\') ?: date(\'Y-m-d\')) ?>" required'),
    'Purchase date is required in both the browser form and the server handler'
);
assertOptionalCarFieldContract(
    str_contains($addCar, 'Purchase Price (₹) <span class="section-optional">(Optional)</span>')
        && str_contains($addCar, 'placeholder="Leave blank if not known"')
        && !str_contains($addCar, 'name="purchase_price" class="form-control currency-input" placeholder="Leave blank if not known" inputmode="decimal" autocomplete="off" required'),
    'Purchase price is visibly optional and has no browser required rule'
);
assertOptionalCarFieldContract(
    str_contains($addCar, 'A blank price is saved as ₹0. Add the confirmed amount later using Correct Purchase Amount.')
        && str_contains($addCar, '$purchasePrice = parseDecimalInput(post(\'purchase_price\'));'),
    'A blank purchase price is saved as zero for later controlled correction'
);
assertOptionalCarFieldContract(
    !str_contains($addCar, 'throw new Exception(\'Select the vehicle owner / seller for this purchase.\');')
        && str_contains($addCar, 'if ($sellerPartyId === \'\' && $sellerName !== \'\') {')
        && str_contains($addCar, 'No owner / seller recorded yet'),
    'A fully paid car can be added before its owner or seller is known'
);
assertOptionalCarFieldContract(
    str_contains($addCar, 'An owner is required only if a purchase balance remains pending.')
        && str_contains($engine, 'Seller name is required when purchase payment is pending.'),
    'Pending purchase payments still require a seller ledger for traceable settlement'
);

echo "Add-car optional-field contract completed.\n";
