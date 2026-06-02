<?php
$pageTitle = 'Cars';
$pageIcon = '<i class="ri-car-line"></i>';
$isLazyRequest = ($_GET['lazy'] ?? '') === '1';
if ($isLazyRequest) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';
    Auth::check();
    $db = Database::getInstance();
} else {
    require_once __DIR__ . '/../includes/header.php';
}

$businessId = Auth::user('business_id');
$filter = get('status', '');
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

function renderCarRows($cars) {
    ob_start();
    ?>
    <?php if (empty($cars)): ?>
        <tr><td colspan="10" class="text-center text-muted" style="padding: 40px;">No cars found. <a href="add.php">Add your first car</a></td></tr>
        <?php else: ?>
        <?php foreach ($cars as $car):
            $totalCost = $car['total_cost'] ?? $car['purchase_price'];
            $netSalePrice = max(0, (float) ($car['sale_price'] ?? 0) - (float) ($car['sale_gst_amount'] ?? 0));
            $profit = $car['status'] === 'SOLD' ? ($netSalePrice - $totalCost) : null;
        ?>
        <tr>
            <td><a href="view.php?id=<?= $car['id'] ?>" class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a></td>
            <td><?= clean($car['make'] . ' ' . $car['model']) ?></td>
            <td><?= $car['year'] ?: '-' ?></td>
            <td><?= formatDate($car['purchase_date']) ?></td>
            <td class="text-right amount"><?= formatAmount($car['purchase_price']) ?></td>
            <td class="text-right amount"><?= formatAmount($totalCost) ?></td>
            <td class="text-right amount"><?= $car['sale_price'] ? formatAmount($car['sale_price']) : '-' ?></td>
            <td class="text-right amount <?= $profit !== null ? ($profit >= 0 ? 'positive' : 'negative') : '' ?>">
                <?= $profit !== null ? formatAmount($profit, true) : '-' ?>
            </td>
            <td class="text-center">
                <?php $statusBadges = ['IN_STOCK' => 'badge-blue', 'SOLD' => 'badge-green', 'PENDING_PAYMENT' => 'badge-yellow', 'CANCELLED' => 'badge-gray']; ?>
                <span class="badge <?= $statusBadges[$car['status']] ?? 'badge-gray' ?>"><?= CAR_STATUS[$car['status']] ?></span>
            </td>
            <td class="text-center">
                <a href="view.php?id=<?= $car['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return trim(ob_get_clean());
}

$where = "WHERE c.business_id = ?";
$params = [$businessId];
if ($filter) { $where .= " AND c.status = ?"; $params[] = $filter; }
if ($search !== '') {
    $where .= " AND UPPER(REPLACE(REPLACE(c.registration_no, '-', ''), ' ', '')) LIKE ?";
    $params[] = '%' . strtoupper(preg_replace('/[^A-Z0-9]/i', '', $search)) . '%';
}

$total = $db->fetch("SELECT COUNT(*) as cnt FROM cars c $where", $params);
$pagination = paginate($total['cnt'], $perPage, $page);

$cars = $db->fetchAll(
    "SELECT c.*, a.current_balance as total_cost FROM cars c 
     LEFT JOIN accounts a ON a.id = c.account_id 
     $where ORDER BY c.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $pagination['offset']])
);

if ($isLazyRequest) {
    header('Content-Type: application/json');
    $nextPage = $page < $pagination['total_pages'] ? $page + 1 : null;
    echo json_encode([
        'html' => renderCarRows($cars),
        'next_url' => $nextPage ? carsListUrl($nextPage, $filter, $search, true) : '',
    ]);
    exit;
}

$nextUrl = $page < $pagination['total_pages'] ? carsListUrl($page + 1, $filter, $search, true) : '';
?>

<div class="page-header">
    <h1><i class="ri-car-line"></i> Cars Inventory</h1>
    <a href="add.php" class="btn btn-primary"><i class="ri-add-line"></i> Add Car</a>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;width:100%;">
        <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= clean($filter) ?>"><?php endif; ?>
        <div style="min-width:240px;flex:1 1 260px;">
            <label class="form-label">Search by car number</label>
            <input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Type GJ05AA0001">
        </div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <a href="list.php<?= $filter !== '' ? '?status=' . urlencode($filter) : '' ?>" class="btn btn-outline btn-sm">Clear Search</a>
    </form>
</div>

<div class="filter-bar">
    <a href="list.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="btn btn-sm <?= !$filter ? 'btn-primary' : 'btn-outline' ?>">All</a>
    <a href="list.php?<?= http_build_query(array_filter(['status' => 'IN_STOCK', 'q' => $search])) ?>" class="btn btn-sm <?= $filter === 'IN_STOCK' ? 'btn-primary' : 'btn-outline' ?>">In Stock</a>
    <a href="list.php?<?= http_build_query(array_filter(['status' => 'SOLD', 'q' => $search])) ?>" class="btn btn-sm <?= $filter === 'SOLD' ? 'btn-primary' : 'btn-outline' ?>">Sold</a>
    <a href="list.php?<?= http_build_query(array_filter(['status' => 'PENDING_PAYMENT', 'q' => $search])) ?>" class="btn btn-sm <?= $filter === 'PENDING_PAYMENT' ? 'btn-primary' : 'btn-outline' ?>">Pending</a>
    <a href="list.php?<?= http_build_query(array_filter(['status' => 'CANCELLED', 'q' => $search])) ?>" class="btn btn-sm <?= $filter === 'CANCELLED' ? 'btn-primary' : 'btn-outline' ?>">Cancelled</a>
</div>

<div class="table-container table-container-fill" data-lazy-list data-next-url="<?= clean($nextUrl) ?>">
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
                <th class="text-center">Status</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?= renderCarRows($cars) ?>
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
