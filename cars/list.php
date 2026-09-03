<?php
$pageTitle = 'Cars';
$pageIcon = '<i class="ri-car-line"></i>';
$isLazyRequest = ($_GET['lazy'] ?? '') === '1';
if ($isLazyRequest) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/accounting_engine.php';
    Auth::check();
    $db = Database::getInstance();
} else {
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/accounting_engine.php';
}

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
Auth::requireEntityAccess('car', 'read');

$filter = get('status', '');
if (!in_array($filter, ['', 'IN_STOCK', 'SOLD', 'PENDING_PAYMENT', 'CANCELLED'], true)) {
    $filter = '';
}
$search = trim((string) get('q', ''));
$page = max(1, intval(get('page', 1)));
$perPage = 24;

function carsListUrl($page, $filter, $search, $lazy = false) {
    $query = ['page' => $page];
    if ($filter !== '') $query['status'] = $filter;
    if ($search !== '') $query['q'] = $search;
    if ($lazy) $query['lazy'] = 1;
    return 'list.php?' . http_build_query($query);
}

function renderCarRows($cars, $engine) {
    ob_start();
    ?>
    <?php if (empty($cars)): ?>
        <tr><td colspan="10" class="text-center text-muted empty-table-cell">No cars found.<?php if (Auth::hasEntityAccess('car', 'write')): ?> <a href="add.php">Add your first car</a><?php endif; ?></td></tr>
        <?php else: ?>
        <?php foreach ($cars as $car):
            $carProfitability = $engine->getCarProfitability($car['id']);
            $carPending = $engine->getCarPendingAmounts($car['id']);
            $totalCost = max(0, (float) ($carProfitability['total_cost'] ?? $car['purchase_price']));
            $referenceSellingPrice = max(0, (float) ($car['expected_sale_price'] ?? 0));
            $totalSaleRealisation = (float) ($carProfitability['total_sale_realisation'] ?? 0);
            $profit = in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true) ? (float) ($carProfitability['profit'] ?? 0) : null;
            $buyerOutstanding = (float) ($carPending['sale_pending'] ?? 0);
            $sellerOutstanding = (float) ($carPending['purchase_pending'] ?? 0);
            $dealerOutstanding = (float) ($carPending['dealer_pending'] ?? 0);
            $rtoPending = (float) ($car['rto_pending'] ?? 0);
        ?>
        <tr>
            <td><a href="view.php?id=<?= $car['id'] ?>" class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a></td>
            <td><?= clean($car['make'] . ' ' . $car['model']) ?></td>
            <td><?= $car['year'] ?: '-' ?></td>
            <td><?= clean($car['partner_names'] ?: '-') ?></td>
            <td class="text-right amount flow-out"><?= formatAmount($totalCost) ?></td>
            <td class="text-right amount">
                <?php if ($referenceSellingPrice > 0): ?>
                    <?= formatAmount($referenceSellingPrice) ?>
                    <div class="table-secondary">Reference only</div>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="text-right amount flow-in">
                <?php if ($car['sale_price']): ?>
                    <?= formatAmount($totalSaleRealisation) ?>
                    <?php if (!empty($carProfitability['sale_commission_amount'])): ?><div class="table-secondary flow-in">+ Comm <?= formatAmount($carProfitability['sale_commission_amount']) ?></div><?php endif; ?>
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
            <td class="text-right amount <?= $profit !== null ? ($profit >= 0 ? 'positive' : 'negative') : '' ?>">
                <?= $profit !== null ? formatAmount($profit, true) : '-' ?>
            </td>
            <td class="text-center">
                <?php $statusBadges = ['IN_STOCK' => 'badge-blue', 'SOLD' => 'badge-green', 'PENDING_PAYMENT' => 'badge-yellow', 'CANCELLED' => 'badge-gray']; ?>
                <span class="badge <?= $statusBadges[$car['status']] ?? 'badge-gray' ?>"><?= CAR_STATUS[$car['status']] ?></span>
                <div class="compact-pending-stack">
                    <?php if ($buyerOutstanding > 0): ?><span class="mini-pill mini-pill-in">Sale pending <?= formatAmount($buyerOutstanding) ?></span><?php endif; ?>
                    <?php if ($sellerOutstanding > 0): ?><span class="mini-pill mini-pill-out">Owner pending <?= formatAmount($sellerOutstanding) ?></span><?php endif; ?>
                    <?php if ($dealerOutstanding > 0): ?><span class="mini-pill mini-pill-out">Dealer pending <?= formatAmount($dealerOutstanding) ?></span><?php endif; ?>
                    <?php if ($rtoPending > 0): ?><span class="mini-pill mini-pill-warn">RTO pending <?= formatAmount($rtoPending) ?></span><?php endif; ?>
                </div>
            </td>
            <td class="text-center">
                <div class="table-action-stack">
                    <a href="view.php?id=<?= $car['id'] ?>" class="btn btn-sm btn-outline" title="View car" aria-label="View car"><i class="ri-eye-line"></i></a>
                    <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?><a href="view.php?id=<?= $car['id'] ?>&amp;edit=1" class="btn btn-sm btn-outline" title="Edit"><i class="ri-edit-line"></i></a><?php endif; ?>
                    <a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= $car['id'] ?>" class="btn btn-sm btn-outline" title="Change history"><i class="ri-history-line"></i></a>
                    <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'delete')): ?><a href="../delete_record.php?entity_type=car&amp;id=<?= clean($car['id']) ?>" class="btn btn-sm btn-outline text-red" title="Delete"><i class="ri-delete-bin-line"></i></a><?php endif; ?>
                    <?php if ($buyerOutstanding > 0 && !empty($carPending['buyer_party_id'])): ?>
                        <a href="../transactions/new.php?<?= http_build_query(['type' => 'LOAN_RECEIVED', 'party_id' => $carPending['buyer_party_id'], 'car_id' => $car['id'], 'amount' => round($buyerOutstanding), 'narration' => 'Receive pending car payment - ' . $car['registration_no']]) ?>" class="btn btn-sm btn-success">Receive</a>
                    <?php endif; ?>
                    <a href="purchase_payment.php?id=<?= clean($car['id']) ?>" class="btn btn-sm <?= $sellerOutstanding > 0.009 ? 'btn-primary' : 'btn-outline' ?>" title="Open purchase payments">Purchase Payments</a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return trim(ob_get_clean());
}

$where = "WHERE c.business_id = ? AND COALESCE(c.ownership_type, 'OWNED') = 'OWNED'";
$params = [$businessId];
if ($filter) {
    $where .= " AND c.status = ?";
    $params[] = $filter;
} else {
    $where .= " AND c.status <> 'CANCELLED'";
}
if ($search !== '') {
    $where .= " AND (
        UPPER(REPLACE(REPLACE(c.registration_no, '-', ''), ' ', '')) LIKE ?
        OR c.registration_no LIKE ?
        OR c.make LIKE ?
        OR c.model LIKE ?
        OR c.year LIKE ?
        OR c.status LIKE ?
        OR EXISTS (
            SELECT 1
            FROM car_partnerships cps
            JOIN partners ps ON ps.id = cps.partner_id
            WHERE cps.business_id = c.business_id
              AND cps.car_id = c.id
              AND cps.status = 'ACTIVE'
              AND ps.name LIKE ?
        )
    )";
    $normalizedSearch = '%' . strtoupper(preg_replace('/[^A-Z0-9]/i', '', $search)) . '%';
    $needle = '%' . $search . '%';
    array_push($params, $normalizedSearch, $needle, $needle, $needle, $needle, $needle, $needle);
}

$total = $db->fetch("SELECT COUNT(*) as cnt FROM cars c $where", $params);
$pagination = paginate($total['cnt'], $perPage, $page);

$cars = $db->fetchAll(
    "SELECT c.*, partner_rollup.partner_names,
            COALESCE(rto.rto_pending, 0) AS rto_pending
     FROM cars c
     LEFT JOIN (
        SELECT cp.car_id, GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS partner_names
        FROM car_partnerships cp
        JOIN partners p ON p.id = cp.partner_id
        WHERE cp.business_id = ? AND cp.status = 'ACTIVE'
        GROUP BY cp.car_id
     ) partner_rollup ON partner_rollup.car_id = c.id
     LEFT JOIN (
        SELECT car_id, SUM(GREATEST(expense_amount - recovered_amount, 0)) AS rto_pending
        FROM rto_records
        WHERE business_id = ? AND is_recoverable = 1 AND status <> 'CANCELLED'
        GROUP BY car_id
     ) rto ON rto.car_id = c.id
     $where ORDER BY c.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge([$businessId, $businessId], $params, [$perPage, $pagination['offset']])
);

if ($isLazyRequest) {
    header('Content-Type: application/json');
    $nextPage = $page < $pagination['total_pages'] ? $page + 1 : null;
    echo json_encode([
        'html' => renderCarRows($cars, $engine),
        'next_url' => $nextPage ? carsListUrl($nextPage, $filter, $search, true) : '',
    ]);
    exit;
}

$nextUrl = $page < $pagination['total_pages'] ? carsListUrl($page + 1, $filter, $search, true) : '';
?>

<div class="page-header">
    <h1><i class="ri-car-line"></i> Cars Inventory</h1>
    <?php if (Auth::hasEntityAccess('car', 'write')): ?><a href="add.php" class="btn btn-primary"><i class="ri-add-line"></i> Add Car</a><?php endif; ?>
</div>

<div class="filter-bar">
    <form method="GET" class="filter-form">
        <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= clean($filter) ?>"><?php endif; ?>
        <div class="filter-main-field filter-main-field-wide">
            <label class="form-label">Search Cars</label>
            <input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Number, make, model, year, or status">
        </div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <a href="list.php<?= $filter !== '' ? '?status=' . urlencode($filter) : '' ?>" class="btn btn-outline btn-sm">Clear Search</a>
    </form>
</div>

<div class="filter-bar filter-bar-chipset">
    <a href="list.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="btn btn-sm <?= !$filter ? 'btn-primary' : 'btn-outline' ?>">All</a>
    <a href="list.php?<?= http_build_query(array_filter(['status' => 'IN_STOCK', 'q' => $search])) ?>" class="btn btn-sm <?= $filter === 'IN_STOCK' ? 'btn-primary' : 'btn-outline' ?>">In Stock</a>
    <a href="list.php?<?= http_build_query(array_filter(['status' => 'SOLD', 'q' => $search])) ?>" class="btn btn-sm <?= $filter === 'SOLD' ? 'btn-primary' : 'btn-outline' ?>">Sold</a>
    <a href="list.php?<?= http_build_query(array_filter(['status' => 'PENDING_PAYMENT', 'q' => $search])) ?>" class="btn btn-sm <?= $filter === 'PENDING_PAYMENT' ? 'btn-primary' : 'btn-outline' ?>">Pending</a>
    <a href="list.php?<?= http_build_query(array_filter(['status' => 'CANCELLED', 'q' => $search])) ?>" class="btn btn-sm <?= $filter === 'CANCELLED' ? 'btn-primary' : 'btn-outline' ?>"><i class="ri-delete-bin-line"></i> Deleted Records</a>
</div>

<div class="table-container table-container-fill" data-lazy-list data-next-url="<?= clean($nextUrl) ?>">
    <table>
        <thead>
            <tr>
                <th>Reg. No.</th>
                <th>Make / Model</th>
                <th>Year</th>
                <th>Partners</th>
                <th class="text-right">Total Cost</th>
                <th class="text-right">Reference Selling Price</th>
                <th class="text-right">Sale Price</th>
                <th class="text-right">Profit/Loss</th>
                <th class="text-center">Status</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?= renderCarRows($cars, $engine) ?>
        </tbody>
    </table>
    <?php if ($nextUrl): ?>
        <div class="lazy-list-footer" data-lazy-sentinel>
            <span data-lazy-status>More cars will load as you scroll.</span>
        </div>
    <?php endif; ?>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
<div class="pagination no-js-pagination">
    <?php if ($page > 1): ?><a href="<?= clean(carsListUrl($page - 1, $filter, $search)) ?>">← Prev</a><?php endif; ?>
    <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
        <a href="<?= clean(carsListUrl($i, $filter, $search)) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pagination['total_pages']): ?><a href="<?= clean(carsListUrl($page + 1, $filter, $search)) ?>">Next →</a><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
