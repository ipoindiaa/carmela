<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

Auth::check();
$db = Database::getInstance();
$businessId = Auth::user('business_id');
Auth::requireEntityAccess('car', 'read');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

$cars = $db->fetchAll(
    "SELECT c.*, seller.name AS seller_name
     FROM cars c
     LEFT JOIN debtors_creditors seller ON seller.id = c.seller_party_id AND seller.business_id = c.business_id
     WHERE c.business_id = ?
       AND COALESCE(c.ownership_type, 'OWNED') = 'OWNED'
       AND c.status <> 'CANCELLED'
     ORDER BY c.purchase_date DESC, c.created_at DESC",
    [$businessId]
);

$pendingCars = [];
$settledCars = [];
$sellerMissingCars = [];
foreach ($cars as $car) {
    $pending = $engine->getCarPendingAmounts($car['id']);
    $car['purchase_pending'] = max(0, (float) ($pending['purchase_pending'] ?? 0));
    if (empty($car['seller_party_id'])) {
        $sellerMissingCars[] = $car;
    } elseif ($car['purchase_pending'] > 0.009) {
        $pendingCars[] = $car;
    } else {
        $settledCars[] = $car;
    }
}

$pageTitle = 'Car Purchase Payments';
$pageIcon = '<i class="ri-hand-coin-line"></i>';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb"><a href="../dashboard.php">Home</a><span>/</span><span>Car Purchase Payments</span></div>

<div class="page-header">
    <div>
        <h1><i class="ri-hand-coin-line"></i> Car Purchase Payments</h1>
        <p class="page-subtitle">Select a bought car, then record a full or part payment to its seller.</p>
    </div>
    <div class="page-actions"><a href="add.php" class="btn btn-outline btn-sm"><i class="ri-add-line"></i> Add Bought Car</a></div>
</div>

<div class="alert alert-info"><i class="ri-information-line"></i><div><strong>How to use this screen</strong><span>Choose a car with a pending balance, select Cash or Bank, enter the instalment amount, and save. The payment is linked automatically to both that car and its seller ledger.</span></div></div>

<div class="stats-grid compact-operational-grid purchase-payment-summary-grid">
    <div class="stat-card"><div class="stat-value flow-out"><?= count($pendingCars) ?></div><div class="stat-label">Cars With Purchase Pending</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= count($settledCars) ?></div><div class="stat-label">Purchase Payments Complete</div></div>
    <div class="stat-card"><div class="stat-value <?= $sellerMissingCars ? 'flow-out' : 'flow-in' ?>"><?= count($sellerMissingCars) ?></div><div class="stat-label">Cars Missing Seller Link</div></div>
</div>

<div class="card">
    <div class="card-header"><div><h3><i class="ri-money-rupee-circle-line"></i> Pay Pending Purchase Balance</h3><div class="card-header-note">Only these cars have an amount still payable to their seller.</div></div></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Car</th><th>Seller</th><th>Purchase Date</th><th class="text-right">Purchase Pending</th><th class="text-center">Action</th></tr></thead>
                <tbody>
                <?php foreach ($pendingCars as $car): ?><tr>
                    <td><a href="view.php?id=<?= clean($car['id']) ?>" class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><div class="text-muted"><?= clean(trim($car['make'] . ' ' . $car['model'])) ?></div></td>
                    <td><?= clean($car['seller_name']) ?></td>
                    <td><?= formatDate($car['purchase_date']) ?></td>
                    <td class="text-right amount flow-out"><?= formatAmount($car['purchase_pending']) ?></td>
                    <td class="text-center"><a href="purchase_payment.php?id=<?= clean($car['id']) ?>" class="btn btn-primary btn-sm"><i class="ri-hand-coin-line"></i> Pay Now</a></td>
                </tr><?php endforeach; ?>
                <?php if (empty($pendingCars)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No bought car currently has a purchase payment pending.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card detail-subsection">
    <div class="card-header"><div><h3><i class="ri-file-list-3-line"></i> Payment Details for Other Bought Cars</h3><div class="card-header-note">Open a car to view its seller-payment history, even when its balance is already complete.</div></div></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Car</th><th>Seller</th><th>Purchase Date</th><th class="text-right">Balance</th><th class="text-center">Action</th></tr></thead>
                <tbody>
                <?php foreach ($settledCars as $car): ?><tr>
                    <td><a href="view.php?id=<?= clean($car['id']) ?>" class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><div class="text-muted"><?= clean(trim($car['make'] . ' ' . $car['model'])) ?></div></td>
                    <td><?= clean($car['seller_name']) ?></td>
                    <td><?= formatDate($car['purchase_date']) ?></td>
                    <td class="text-right amount flow-in">Complete</td>
                    <td class="text-center"><a href="purchase_payment.php?id=<?= clean($car['id']) ?>" class="btn btn-outline btn-sm">View Details</a></td>
                </tr><?php endforeach; ?>
                <?php if (empty($settledCars)): ?><tr><td colspan="5" class="text-center text-muted empty-table-cell">No completed seller-payment history yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($sellerMissingCars)): ?>
<div class="card detail-subsection">
    <div class="card-header"><div><h3><i class="ri-link-unlink"></i> Seller Link Needed Before Payment</h3><div class="card-header-note">These older car records have no seller ledger. Do not post their payment as a general expense or loan payment.</div></div></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead><tr><th>Car</th><th>Purchase Date</th><th class="text-right">Purchase Price</th><th class="text-center">Action</th></tr></thead>
                <tbody>
                <?php foreach ($sellerMissingCars as $car): ?><tr>
                    <td><a href="view.php?id=<?= clean($car['id']) ?>" class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><div class="text-muted"><?= clean(trim($car['make'] . ' ' . $car['model'])) ?></div></td>
                    <td><?= formatDate($car['purchase_date']) ?></td>
                    <td class="text-right amount"><?= formatAmount($car['purchase_price']) ?></td>
                    <td class="text-center"><a href="view.php?id=<?= clean($car['id']) ?>" class="btn btn-outline btn-sm">Open Car</a></td>
                </tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
