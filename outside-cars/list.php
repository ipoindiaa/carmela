<?php
$pageTitle = 'Outside Cars';
$pageIcon = '<i class="ri-steering-2-line"></i>';
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

function outsideCarsListUrl($page, $filter, $search, $lazy = false) {
    $query = ['page' => $page];
    if ($filter !== '') $query['status'] = $filter;
    if ($search !== '') $query['q'] = $search;
    if ($lazy) $query['lazy'] = 1;
    return 'list.php?' . http_build_query($query);
}

function renderOutsideCarRows($cars, $engine) {
    ob_start();
    ?>
    <?php if (empty($cars)): ?>
        <tr><td colspan="10" class="text-center text-muted empty-table-cell">No outside cars found.<?php if (Auth::hasEntityAccess('car', 'write')): ?> <a href="add.php">Add an outside car</a><?php endif; ?></td></tr>
    <?php else: ?>
        <?php foreach ($cars as $car):
            $carProfitability = $engine->getCarProfitability($car['id']);
            $carPending = $engine->getCarPendingAmounts($car['id']);
            $expenses = max(0, (float) ($carProfitability['total_expenses'] ?? 0));
            $buyerOutstanding = (float) ($carPending['sale_pending'] ?? 0);
            $rtoPending = (float) ($car['rto_pending'] ?? 0);
            $commission = $car['status'] === 'SOLD' || $car['status'] === 'PENDING_PAYMENT' 
                ? (float) ($car['sale_commission_amount'] ?? 0) 
                : (float) ($car['expected_commission_amount'] ?? 0);
        ?>
        <tr>
            <td>
                <a href="view.php?id=<?= $car['id'] ?>" class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a>
                <div><span class="badge badge-purple">Outside Car</span></div>
            </td>
            <td><?= clean($car['make'] . ' ' . $car['model']) ?></td>
            <td><?= $car['year'] ?: '-' ?></td>
            <td>
                <?php if (!empty($car['owner_party_id'])): ?>
                    <a href="../parties/view.php?id=<?= urlencode($car['owner_party_id']) ?>"><?= clean($car['owner_name'] ?: 'Owner') ?></a>
                <?php else: ?>
                    <span class="text-muted"><?= clean($car['owner_name'] ?: '-') ?></span>
                <?php endif; ?>
            </td>
            <td><?= renderDateTimeStack($car['purchase_date'], $car['created_at']) ?></td>
            <td class="text-right amount flow-in">
                <?= $commission > 0 ? formatAmount($commission) : '<span class="text-muted">Not set</span>' ?>
            </td>
            <td class="text-right amount flow-out"><?= formatAmount($expenses) ?></td>
            <td class="text-center">
                <?php $statusBadges = ['IN_STOCK' => 'badge-blue', 'SOLD' => 'badge-green', 'PENDING_PAYMENT' => 'badge-yellow', 'CANCELLED' => 'badge-gray']; ?>
                <span class="badge <?= $statusBadges[$car['status']] ?? 'badge-gray' ?>"><?= CAR_STATUS[$car['status']] ?></span>
                <div class="compact-pending-stack">
                    <?php if ($buyerOutstanding > 0): ?><span class="mini-pill mini-pill-in">Sale pending <?= formatAmount($buyerOutstanding) ?></span><?php endif; ?>
                    <?php if ($rtoPending > 0): ?><span class="mini-pill mini-pill-warn">RTO pending <?= formatAmount($rtoPending) ?></span><?php endif; ?>
                </div>
            </td>
            <td class="text-center">
                <div class="table-action-stack">
                    <a href="view.php?id=<?= $car['id'] ?>" class="btn btn-sm btn-outline" title="View car" aria-label="View car"><i class="ri-eye-line"></i></a>
                    <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'write')): ?>
                        <a href="view.php?id=<?= $car['id'] ?>&amp;edit=1" class="btn btn-sm btn-outline" title="Edit"><i class="ri-edit-line"></i></a>
                    <?php endif; ?>
                    <a href="../reports/change_history.php?entity_type=car&amp;entity_id=<?= $car['id'] ?>" class="btn btn-sm btn-outline" title="Change history"><i class="ri-history-line"></i></a>
                    <?php if ($car['status'] !== 'CANCELLED' && Auth::hasEntityAccess('car', 'delete')): ?>
                        <a href="../delete_record.php?entity_type=car&amp;id=<?= clean($car['id']) ?>" class="btn btn-sm btn-outline text-red" title="Delete"><i class="ri-delete-bin-line"></i></a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return trim(ob_get_clean());
}

$where = "WHERE c.business_id = ? AND c.ownership_type = 'OUTSIDE'";
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
        OR owner.name LIKE ?
    )";
    $normalizedSearch = '%' . strtoupper(preg_replace('/[^A-Z0-9]/i', '', $search)) . '%';
    $needle = '%' . $search . '%';
    array_push($params, $normalizedSearch, $needle, $needle, $needle, $needle, $needle, $needle);
}

$total = $db->fetch("SELECT COUNT(*) as cnt FROM cars c LEFT JOIN debtors_creditors owner ON owner.id = c.commission_owner_party_id $where", $params);
$pagination = paginate($total['cnt'], $perPage, $page);

$cars = $db->fetchAll(
    "SELECT c.*, owner.name AS owner_name, owner.id AS owner_party_id,
            COALESCE(rto.rto_pending, 0) AS rto_pending
     FROM cars c
     LEFT JOIN debtors_creditors owner ON owner.id = c.commission_owner_party_id
     LEFT JOIN (
        SELECT car_id, SUM(GREATEST(expense_amount - recovered_amount, 0)) AS rto_pending
        FROM rto_records
        WHERE business_id = ? AND is_recoverable = 1 AND status <> 'CANCELLED'
        GROUP BY car_id
     ) rto ON rto.car_id = c.id
     $where ORDER BY c.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge([$businessId], $params, [$perPage, $pagination['offset']])
);

if ($isLazyRequest) {
    header('Content-Type: application/json');
    $nextPage = $page < $pagination['total_pages'] ? $page + 1 : null;
    echo json_encode([
        'html' => renderOutsideCarRows($cars, $engine),
        'next_url' => $nextPage ? outsideCarsListUrl($nextPage, $filter, $search, true) : '',
    ]);
    exit;
}

$nextUrl = $page < $pagination['total_pages'] ? outsideCarsListUrl($page + 1, $filter, $search, true) : '';
?>

<div class="page-header">
    <div>
        <h1><i class="ri-steering-2-line"></i> Outside Cars Inventory</h1>
        <p class="page-subtitle">Commission cars received from external entities</p>
    </div>
    <?php if (Auth::hasEntityAccess('car', 'write')): ?>
        <a href="add.php" class="btn btn-primary"><i class="ri-add-line"></i> Add Outside Car</a>
    <?php endif; ?>
</div>

<div class="filter-bar">
    <form method="GET" class="filter-form">
        <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= clean($filter) ?>"><?php endif; ?>
        <div class="filter-main-field filter-main-field-wide">
            <label class="form-label">Search Outside Cars</label>
            <input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Number, make, model, year, or source entity name">
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
                <th>Source Entity</th>
                <th>Date Received</th>
                <th class="text-right">Commission</th>
                <th class="text-right">Expenses</th>
                <th class="text-center">Status</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?= renderOutsideCarRows($cars, $engine) ?>
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
    <?php if ($page > 1): ?><a href="<?= clean(outsideCarsListUrl($page - 1, $filter, $search)) ?>">← Prev</a><?php endif; ?>
    <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
        <a href="<?= clean(outsideCarsListUrl($i, $filter, $search)) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pagination['total_pages']): ?><a href="<?= clean(outsideCarsListUrl($page + 1, $filter, $search)) ?>">Next →</a><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
