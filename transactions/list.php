<?php
$pageTitle = 'Transactions';
$pageIcon = '<i class="ri-exchange-line"></i>';
require_once __DIR__ . '/../includes/header.php';

$businessId = Auth::user('business_id');
Auth::requireAnyBookAccess(array_merge(Auth::getPrimaryBookKeys(), ['jv_register']), 'read');
$accessibleAccountIds = Auth::getAccessiblePrimaryAccountIds($businessId, 'read');
$canReadJV = Auth::hasBookAccess('jv_register', 'read');
if (empty($accessibleAccountIds) && !$canReadJV) {
    setFlash('error', 'You do not have access to any transaction books.');
    redirect('../dashboard.php');
}
$accountPlaceholders = !empty($accessibleAccountIds) ? implode(',', array_fill(0, count($accessibleAccountIds), '?')) : '';

// Filters
$filterType = get('type', '');
$filterDate = get('date', '');
$filterStatus = get('status', '');
$page = max(1, intval(get('page', 1)));
$perPage = 25;

$where = "WHERE je.business_id = ?";
$params = [$businessId];

if (!empty($accessibleAccountIds) && $canReadJV) {
    $where .= " AND (
        EXISTS (
            SELECT 1
            FROM journal_lines jl_filter
            WHERE jl_filter.journal_entry_id = je.id
              AND jl_filter.account_id IN ($accountPlaceholders)
        )
        OR je.journal_voucher_id IS NOT NULL
    )";
    $params = array_merge($params, $accessibleAccountIds);
} elseif (!empty($accessibleAccountIds)) {
    $where .= " AND EXISTS (
        SELECT 1
        FROM journal_lines jl_filter
        WHERE jl_filter.journal_entry_id = je.id
          AND jl_filter.account_id IN ($accountPlaceholders)
    )";
    $params = array_merge($params, $accessibleAccountIds);
} else {
    $where .= " AND je.journal_voucher_id IS NOT NULL";
}

if ($filterType) { $where .= " AND je.transaction_type = ?"; $params[] = $filterType; }
if ($filterDate) { $where .= " AND je.entry_date = ?"; $params[] = $filterDate; }
if ($filterStatus) { $where .= " AND je.status = ?"; $params[] = $filterStatus; }

$total = $db->fetch("SELECT COUNT(*) as cnt FROM journal_entries je $where", $params);
$pagination = paginate($total['cnt'], $perPage, $page);

$entries = $db->fetchAll(
    "SELECT je.*, u.full_name as created_by_name,
            c.registration_no as car_reg,
            p.name as partner_name,
            e.name as employee_name
     FROM journal_entries je
     LEFT JOIN users u ON u.id = je.created_by
     LEFT JOIN cars c ON c.id = je.car_id
     LEFT JOIN partners p ON p.id = je.partner_id
     LEFT JOIN employees e ON e.id = je.employee_id
     $where
     ORDER BY je.entry_date DESC, je.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $pagination['offset']])
);
?>

<div class="page-header">
    <h1><i class="ri-exchange-line"></i> Transactions</h1>
    <div style="display:flex; gap:12px;">
        <?php if (Auth::hasBookAccess('jv_register', 'write')): ?>
            <a href="jv.php" class="btn btn-outline"><i class="ri-file-edit-line"></i> JV Composer</a>
        <?php endif; ?>
        <a href="new.php" class="btn btn-primary"><i class="ri-add-line"></i> New Entry</a>
    </div>
</div>

<div class="filter-bar">
    <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: end;">
        <select name="type" class="form-control">
            <option value="">All Types</option>
            <?php foreach (TXN_TYPES as $key => $label): ?>
                <option value="<?= $key ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" class="form-control" value="<?= clean($filterDate) ?>" placeholder="Date">
        <select name="status" class="form-control">
            <option value="">All Status</option>
            <option value="POSTED" <?= $filterStatus === 'POSTED' ? 'selected' : '' ?>>Posted</option>
            <option value="REVERSED" <?= $filterStatus === 'REVERSED' ? 'selected' : '' ?>>Reversed</option>
        </select>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Filter</button>
        <a href="list.php" class="btn btn-outline btn-sm">Clear</a>
    </form>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Ref No.</th>
                <th>Date</th>
                <th>Type</th>
                <th>Narration</th>
                <th>Related</th>
                <th>Status</th>
                <th>By</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($entries)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding: 40px;">No transactions found</td></tr>
            <?php else: ?>
                <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><a href="view.php?id=<?= $entry['id'] ?>" class="text-bold"><?= $entry['reference_no'] ?></a></td>
                    <td><?= formatDate($entry['entry_date']) ?></td>
                    <td><span class="badge badge-blue"><?= TXN_TYPES[$entry['transaction_type']] ?? $entry['transaction_type'] ?></span></td>
                    <td style="max-width: 250px;"><?= clean(mb_substr($entry['narration'] ?? '', 0, 60)) ?></td>
                    <td class="text-muted">
                        <?php if ($entry['car_reg']): ?><i class="ri-car-line"></i> <?= $entry['car_reg'] ?><?php endif; ?>
                        <?php if ($entry['partner_name']): ?><i class="ri-user-line"></i> <?= clean($entry['partner_name']) ?><?php endif; ?>
                        <?php if ($entry['employee_name']): ?><i class="ri-user-star-line"></i> <?= clean($entry['employee_name']) ?><?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $statusBadge = ['POSTED' => 'badge-green', 'REVERSED' => 'badge-red', 'DRAFT' => 'badge-gray'];
                        ?>
                        <span class="badge <?= $statusBadge[$entry['status']] ?? 'badge-gray' ?>"><?= $entry['status'] ?></span>
                    </td>
                    <td class="text-muted"><?= clean($entry['created_by_name']) ?></td>
                    <td class="text-center">
                        <a href="view.php?id=<?= $entry['id'] ?>" class="btn btn-sm btn-outline" title="View"><i class="ri-eye-line"></i></a>
                        <?php if ($entry['status'] === 'POSTED' && Auth::isAdmin()): ?>
                            <a href="reverse.php?id=<?= $entry['id'] ?>" class="btn btn-sm btn-outline" title="Reverse" data-confirm="Are you sure you want to reverse this entry?"><i class="ri-arrow-go-back-line"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>&type=<?= $filterType ?>&date=<?= $filterDate ?>&status=<?= $filterStatus ?>">← Prev</a>
    <?php endif; ?>
    <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
        <a href="?page=<?= $i ?>&type=<?= $filterType ?>&date=<?= $filterDate ?>&status=<?= $filterStatus ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pagination['total_pages']): ?>
        <a href="?page=<?= $page + 1 ?>&type=<?= $filterType ?>&date=<?= $filterDate ?>&status=<?= $filterStatus ?>">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
