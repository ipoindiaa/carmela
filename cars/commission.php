<?php
$pageTitle = 'Commission Cars';
$pageIcon = '<i class="ri-hand-coin-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
Auth::requireEntityAccess('car', 'read');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$status = strtoupper(trim((string) get('status', '')));
$search = trim((string) get('q', ''));
$where = "WHERE c.business_id = ? AND c.ownership_type = 'COMMISSION'";
$params = [$businessId];
if (in_array($status, ['IN_STOCK','SOLD','PENDING_PAYMENT','CANCELLED'], true)) { $where .= ' AND c.status = ?'; $params[] = $status; }
if ($search !== '') {
    $where .= " AND (c.registration_no LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR owner.name LIKE ? OR buyer.name LIKE ?)";
    $needle = '%' . $search . '%';
    array_push($params, $needle, $needle, $needle, $needle, $needle);
}

$cars = $db->fetchAll(
    "SELECT c.*, owner.name AS owner_name, buyer.name AS settlement_buyer_name,
            ccs.id AS settlement_id, ccs.payment_handling, ccs.owner_amount, ccs.paid_to_owner_amount,
            ccs.status AS settlement_status
     FROM cars c
     JOIN debtors_creditors owner ON owner.id = c.commission_owner_party_id
     LEFT JOIN commission_car_settlements ccs ON ccs.car_id = c.id AND ccs.business_id = c.business_id AND ccs.status <> 'REVERSED'
     LEFT JOIN debtors_creditors buyer ON buyer.id = ccs.buyer_party_id
     $where ORDER BY c.created_at DESC",
    $params
);
$summary = $db->fetch(
    "SELECT COUNT(*) AS total_cars,
            SUM(c.status = 'IN_STOCK') AS in_stock,
            SUM(c.status IN ('SOLD','PENDING_PAYMENT')) AS sold_count,
            COALESCE(SUM(CASE WHEN ccs.status <> 'REVERSED' THEN ccs.gross_sale_amount ELSE 0 END), 0) AS gross_sales,
            COALESCE(SUM(CASE WHEN ccs.status <> 'REVERSED' THEN ccs.commission_amount ELSE 0 END), 0) AS commission_income,
            COALESCE(SUM(CASE WHEN ccs.status IN ('PENDING','PARTIAL') THEN ccs.owner_amount - ccs.paid_to_owner_amount ELSE 0 END), 0) AS owner_payable
     FROM cars c
     LEFT JOIN commission_car_settlements ccs ON ccs.car_id = c.id AND ccs.business_id = c.business_id
     WHERE c.business_id = ? AND c.ownership_type = 'COMMISSION'",
    [$businessId]
);
?>

<div class="page-header">
    <div><h1><i class="ri-hand-coin-line"></i> Commission Cars</h1><p class="page-subtitle">Customer-owned cars sold for commission. Gross sale values never inflate business income.</p></div>
    <?php if (Auth::hasEntityAccess('car', 'write')): ?><a href="commission_add.php" class="btn btn-primary"><i class="ri-add-line"></i> Add Commission Car</a><?php endif; ?>
</div>

<div class="stats-grid commission-stats-grid">
    <div class="stat-card"><div class="stat-value"><?= (int) ($summary['in_stock'] ?? 0) ?></div><div class="stat-label">With Us for Sale</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int) ($summary['sold_count'] ?? 0) ?></div><div class="stat-label">Sold</div></div>
    <div class="stat-card"><div class="stat-value flow-neutral"><?= formatAmount($summary['gross_sales'] ?? 0) ?></div><div class="stat-label">Gross Value (Memo)</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($summary['commission_income'] ?? 0) ?></div><div class="stat-label">Commission Income</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($summary['owner_payable'] ?? 0) ?></div><div class="stat-label">Payable to Owners</div></div>
</div>

<div class="filter-bar commission-filter-bar">
    <form method="GET" class="compact-filter-form">
        <div class="filter-main-field"><label class="form-label">Find Commission Car</label><input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Registration, owner, make or buyer"></div>
        <?php if ($status): ?><input type="hidden" name="status" value="<?= clean($status) ?>"><?php endif; ?>
        <button class="btn btn-outline btn-sm" type="submit"><i class="ri-search-line"></i> Find</button>
        <?php if ($search): ?><a href="commission.php<?= $status ? '?status=' . urlencode($status) : '' ?>" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
    </form>
    <div class="filter-chip-row">
        <?php foreach (['' => 'All', 'IN_STOCK' => 'With Us', 'SOLD' => 'Sold', 'PENDING_PAYMENT' => 'Buyer Pending'] as $value => $label): ?>
            <a class="btn btn-sm <?= $status === $value ? 'btn-primary' : 'btn-outline' ?>" href="commission.php?<?= http_build_query(array_filter(['status' => $value, 'q' => $search])) ?>"><?= clean($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Car</th><th>Owner</th><th>Received</th><th class="text-right">Gross Sale (Memo)</th><th class="text-right">Commission Income</th><th class="text-right">Owner Payable</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
        <tbody>
        <?php if (!$cars): ?><tr><td colspan="8" class="text-center text-muted" style="padding:40px;">No commission cars found.</td></tr><?php endif; ?>
        <?php foreach ($cars as $car): $ownerDue = max(0, floatval($car['owner_amount'] ?? 0) - floatval($car['paid_to_owner_amount'] ?? 0)); ?>
            <tr>
                <td><a class="text-bold" href="commission_view.php?id=<?= clean($car['id']) ?>"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a><div class="table-secondary"><?= clean(trim($car['make'] . ' ' . $car['model'])) ?: '-' ?></div></td>
                <td><?= clean($car['owner_name']) ?></td>
                <td><?= renderDateTimeStack($car['purchase_date'], $car['created_at']) ?></td>
                <td class="text-right amount flow-neutral"><?= $car['sale_price'] ? formatAmount($car['sale_price']) : '-' ?></td>
                <td class="text-right amount flow-in"><?= $car['sale_commission_amount'] ? formatAmount($car['sale_commission_amount']) : '-' ?></td>
                <td class="text-right amount <?= $ownerDue > 0 ? 'flow-out' : '' ?>"><?= $ownerDue > 0 ? formatAmount($ownerDue) : '-' ?></td>
                <td><span class="badge <?= $car['status'] === 'IN_STOCK' ? 'badge-blue' : ($car['status'] === 'SOLD' ? 'badge-green' : 'badge-yellow') ?>"><?= clean(CAR_STATUS[$car['status']] ?? $car['status']) ?></span></td>
                <td class="text-center"><div class="table-action-stack"><a href="commission_view.php?id=<?= clean($car['id']) ?>" class="btn btn-sm btn-outline" title="View"><i class="ri-eye-line"></i></a><a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= clean($car['id']) ?>" class="btn btn-sm btn-outline" title="History"><i class="ri-history-line"></i></a></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
