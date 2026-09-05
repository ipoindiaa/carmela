<?php
$pageTitle = 'Car Inventory';
$pageIcon = '<i class="ri-parking-box-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('car_profitability', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$asOnDate = trim((string) get('as_on', date('Y-m-d')));
$parsedAsOnDate = DateTime::createFromFormat('Y-m-d', $asOnDate);
if (!$parsedAsOnDate || $parsedAsOnDate->format('Y-m-d') !== $asOnDate) {
    $asOnDate = date('Y-m-d');
}
$search = trim((string) get('q', ''));
$status = strtoupper(trim((string) get('status', 'INVENTORY')));
$allowedStatuses = ['INVENTORY', 'IN_STOCK', 'PENDING_PAYMENT', 'SOLD', 'CANCELLED', 'ALL'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'INVENTORY';
}

$where = "c.business_id = ? AND COALESCE(c.ownership_type, 'OWNED') = 'OWNED' AND c.purchase_date <= ?";
$params = [$businessId, $asOnDate];
if (!in_array($status, ['INVENTORY', 'ALL'], true)) {
    $where .= ' AND c.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $needle = '%' . $search . '%';
    $where .= ' AND (c.registration_no LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR c.color LIKE ?)';
    array_push($params, $needle, $needle, $needle, $needle);
}

$cars = $db->fetchAll(
    "SELECT c.*, a.name AS account_name, a.code AS account_code
     FROM cars c
     JOIN accounts a ON a.id = c.account_id AND a.business_id = c.business_id
     WHERE $where
     ORDER BY c.purchase_date, c.created_at",
    $params
);

$trialBalance = $engine->getTrialBalance($asOnDate);
$accountBalances = [];
foreach ($trialBalance as $account) {
    $accountBalances[$account['id']] = ($account['balance_type'] ?? 'DR') === 'DR'
        ? floatval($account['balance_amount'])
        : -floatval($account['balance_amount']);
}

$inventoryTotal = 0.0;
foreach ($cars as &$car) {
    $car['inventory_balance'] = round(floatval($accountBalances[$car['account_id']] ?? 0), 2);
    $inventoryTotal += $car['inventory_balance'];
}
unset($car);
if ($status === 'INVENTORY') {
    $cars = array_values(array_filter($cars, static fn($car) => abs(floatval($car['inventory_balance'] ?? 0)) >= 0.005));
    $inventoryTotal = array_sum(array_column($cars, 'inventory_balance'));
}
$carCount = count($cars);
$averageCost = $carCount > 0 ? $inventoryTotal / $carCount : 0;
$clearUrl = 'car_inventory.php';
?>

<div class="page-header">
    <div>
        <h1><i class="ri-parking-box-line"></i> Car Inventory</h1>
        <p class="page-subtitle">Car-wise inventory ledger balances, separated from the Balance Sheet detail.</p>
    </div>
    <button type="button" onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="get">
        <div><label class="form-label">As On</label><input type="date" name="as_on" class="form-control" value="<?= clean($asOnDate) ?>"></div>
        <div><label class="form-label">Car</label><input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Registration, make, model, color"></div>
        <div><label class="form-label">Inventory View</label><select name="status" class="form-control">
            <?php foreach (['INVENTORY' => 'Has Inventory Balance', 'IN_STOCK' => 'Currently In Stock', 'PENDING_PAYMENT' => 'Payment Pending', 'SOLD' => 'Sold', 'CANCELLED' => 'Cancelled', 'ALL' => 'All Statuses'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select></div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button>
        <?php if ($search !== '' || $status !== 'INVENTORY' || $asOnDate !== date('Y-m-d')): ?><a href="<?= $clearUrl ?>" class="btn btn-ghost btn-sm">Clear all</a><?php endif; ?>
    </form>
</div>

<div class="stats-grid compact-operational-grid">
    <div class="stat-card"><div class="stat-value"><?= $carCount ?></div><div class="stat-label">Cars Shown</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($inventoryTotal, true) ?></div><div class="stat-label">Inventory Total</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatAmount($averageCost, true) ?></div><div class="stat-label">Average Ledger Cost</div></div>
    <div class="stat-card"><div class="stat-value"><?= clean(formatDate($asOnDate)) ?></div><div class="stat-label">Balance Date</div></div>
</div>

<div class="alert alert-info">
    <i class="ri-scales-3-line"></i>
    <div><strong>This report is account-wise.</strong><span>Inventory Total is the net debit balance of the selected cars' individual asset ledgers as on the chosen date. Use the ledger link to audit every entry.</span></div>
</div>

<div class="table-container table-container-fill table-container-fit">
    <table>
        <thead><tr><th>Car</th><th>Purchase Date</th><th>Status</th><th>Inventory Account</th><th class="text-right">Purchase Amount</th><th class="text-right">Ledger Balance</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cars as $car): ?>
            <tr>
                <td><a href="../cars/view.php?id=<?= urlencode($car['id']) ?>" class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><div class="table-secondary"><?= clean(trim(($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))) ?></div></td>
                <td><?= formatDate($car['purchase_date']) ?></td>
                <td><span class="badge <?= $car['status'] === 'IN_STOCK' ? 'badge-blue' : ($car['status'] === 'SOLD' ? 'badge-green' : ($car['status'] === 'PENDING_PAYMENT' ? 'badge-yellow' : 'badge-gray')) ?>"><?= clean(CAR_STATUS[$car['status']] ?? str_replace('_', ' ', $car['status'])) ?></span></td>
                <td><?= clean($car['account_name']) ?><div class="table-secondary"><?= clean($car['account_code']) ?></div></td>
                <td class="text-right amount"><?= formatAmount($car['purchase_price']) ?></td>
                <td class="text-right amount <?= $car['inventory_balance'] >= 0 ? 'flow-in' : 'flow-out' ?>"><?= formatAmount($car['inventory_balance'], true) ?></td>
                <td><a href="ledger.php?<?= clean(http_build_query(['account_id' => $car['account_id'], 'from' => '2000-01-01', 'to' => $asOnDate])) ?>" class="btn btn-outline btn-sm">Ledger</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$cars): ?><tr><td colspan="7" class="text-center text-muted empty-table-cell">No cars match these inventory filters.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="5">Filtered Inventory Total</td><td class="text-right amount"><?= formatAmount($inventoryTotal, true) ?></td><td></td></tr></tfoot>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
