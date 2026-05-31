<?php
$pageTitle = 'Cars';
$pageIcon = '<i class="ri-car-line"></i>';
require_once __DIR__ . '/../includes/header.php';

$businessId = Auth::user('business_id');
$filter = get('status', '');

$where = "WHERE c.business_id = ?";
$params = [$businessId];
if ($filter) { $where .= " AND c.status = ?"; $params[] = $filter; }

$cars = $db->fetchAll(
    "SELECT c.*, a.current_balance as total_cost FROM cars c 
     LEFT JOIN accounts a ON a.id = c.account_id 
     $where ORDER BY c.created_at DESC", $params);
?>

<div class="page-header">
    <h1><i class="ri-car-line"></i> Cars Inventory</h1>
    <a href="add.php" class="btn btn-primary"><i class="ri-add-line"></i> Add Car</a>
</div>

<div class="filter-bar">
    <a href="list.php" class="btn btn-sm <?= !$filter ? 'btn-primary' : 'btn-outline' ?>">All</a>
    <a href="list.php?status=IN_STOCK" class="btn btn-sm <?= $filter === 'IN_STOCK' ? 'btn-primary' : 'btn-outline' ?>">In Stock</a>
    <a href="list.php?status=SOLD" class="btn btn-sm <?= $filter === 'SOLD' ? 'btn-primary' : 'btn-outline' ?>">Sold</a>
    <a href="list.php?status=PENDING_PAYMENT" class="btn btn-sm <?= $filter === 'PENDING_PAYMENT' ? 'btn-primary' : 'btn-outline' ?>">Pending</a>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Reg. No.</th>
                <th>Make / Model</th>
                <th>Year</th>
                <th>Purchase Date</th>
                <th class="text-right">Purchase Price</th>
                <th class="text-right">Total Cost</th>
                <th class="text-right">Sale Price</th>
                <th class="text-right">Profit/Loss</th>
                <th>Status</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($cars)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding: 40px;">No cars found. <a href="add.php">Add your first car</a></td></tr>
            <?php else: ?>
                <?php foreach ($cars as $car): 
                    $totalCost = $car['total_cost'] ?? $car['purchase_price'];
                    $profit = $car['status'] === 'SOLD' ? ($car['sale_price'] - $totalCost) : null;
                ?>
                <tr>
                    <td><a href="view.php?id=<?= $car['id'] ?>" class="text-bold"><?= clean($car['registration_no']) ?></a></td>
                    <td><?= clean($car['make'] . ' ' . $car['model']) ?></td>
                    <td><?= $car['year'] ?: '-' ?></td>
                    <td><?= formatDate($car['purchase_date']) ?></td>
                    <td class="text-right amount"><?= formatAmount($car['purchase_price']) ?></td>
                    <td class="text-right amount"><?= formatAmount($totalCost) ?></td>
                    <td class="text-right amount"><?= $car['sale_price'] ? formatAmount($car['sale_price']) : '-' ?></td>
                    <td class="text-right amount <?= $profit !== null ? ($profit >= 0 ? 'positive' : 'negative') : '' ?>">
                        <?= $profit !== null ? formatAmount($profit, true) : '-' ?>
                    </td>
                    <td>
                        <?php $statusBadges = ['IN_STOCK' => 'badge-blue', 'SOLD' => 'badge-green', 'PENDING_PAYMENT' => 'badge-yellow']; ?>
                        <span class="badge <?= $statusBadges[$car['status']] ?? 'badge-gray' ?>"><?= CAR_STATUS[$car['status']] ?></span>
                    </td>
                    <td class="text-center">
                        <a href="view.php?id=<?= $car['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
