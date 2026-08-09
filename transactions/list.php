<?php
$pageTitle = 'All Entries';
$pageIcon = '<i class="ri-exchange-line"></i>';
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
Auth::requireAnyBookAccess(array_merge(Auth::getPrimaryBookKeys(), ['jv_register']), 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';
new AccountingEngine($businessId, Auth::user('user_id'));
$accessibleAccountIds = Auth::getAccessiblePrimaryAccountIds($businessId, 'read');
$canReadJV = Auth::hasBookAccess('jv_register', 'read');
if (empty($accessibleAccountIds) && !$canReadJV) {
    setFlash('error', 'You do not have access to any transaction books.');
    redirect('../dashboard.php');
}
$accountPlaceholders = !empty($accessibleAccountIds) ? implode(',', array_fill(0, count($accessibleAccountIds), '?')) : '';

// Filters
$filterEntryTypeId = get('entry_type_id', '');
if ($filterEntryTypeId === '' && get('type', '') !== '') {
    $filterEntryTypeId = systemEntryTypeId(get('type'));
}
$filterQuery = trim((string) get('q', ''));
$filterFromDate = get('from_date', get('date', ''));
$filterToDate = get('to_date', '');
$filterStatus = get('status', '');
$filterUserId = get('user_id', '');
$filterMinAmount = trim((string) get('min_amount', ''));
$filterMaxAmount = trim((string) get('max_amount', ''));
$page = max(1, intval(get('page', 1)));
$perPage = 30;

function transactionsListUrl($page, array $filters, $lazy = false) {
    $query = ['page' => $page];
    foreach ($filters as $key => $value) {
        if ($value !== '' && $value !== null) $query[$key] = $value;
    }
    if ($lazy) $query['lazy'] = 1;
    return 'list.php?' . http_build_query($query);
}

$transactionFilters = [
    'q' => $filterQuery,
    'entry_type_id' => $filterEntryTypeId,
    'from_date' => $filterFromDate,
    'to_date' => $filterToDate,
    'status' => $filterStatus,
    'user_id' => $filterUserId,
    'min_amount' => $filterMinAmount,
    'max_amount' => $filterMaxAmount,
];

function transactionContextLabel($type, array $entry = []) {
    return match (transactionBusinessFlow($type, $entry)) {
        'in' => 'Receive / Jama',
        'out' => 'Payment / Expense',
        default => match ($type) {
            'CONTRA_TRANSFER' => 'Cash / Bank Transfer',
            'JOURNAL_VOUCHER' => 'Large Bill Split',
            default => 'Entry',
        },
    };
}

function renderTransactionRows($entries) {
    ob_start();
    ?>
    <?php if (empty($entries)): ?>
        <tr><td colspan="8" class="text-center text-muted empty-table-cell">No transactions found</td></tr>
    <?php else: ?>
        <?php foreach ($entries as $entry): ?>
        <tr>
            <td><a href="view.php?id=<?= $entry['id'] ?>" class="text-bold"><?= $entry['reference_no'] ?></a></td>
            <td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td>
            <td>
                <span class="badge badge-blue"><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?></span>
                <div class="transaction-context-chip <?= transactionFlowColorClass($entry['transaction_type'], $entry) ?>"><?= clean(transactionContextLabel($entry['transaction_type'], $entry)) ?></div>
            </td>
            <td class="narration-cell">
                <?php $fullNarration = trim((string) ($entry['narration'] ?? '')); ?>
                <span class="narration-tooltip" data-full-text="<?= clean($fullNarration) ?>" tabindex="0">
                    <?= clean(mb_strimwidth($fullNarration, 0, 58, '…')) ?>
                </span>
            </td>
            <td class="text-muted">
                <?php if ($entry['car_reg']): ?><i class="ri-car-line"></i> <?= formatRegistrationNo($entry['car_reg']) ?><?php endif; ?>
                <?php if ($entry['partner_name']): ?><i class="ri-user-line"></i> <?= clean($entry['partner_name']) ?><?php endif; ?>
                <?php if ($entry['employee_name']): ?><i class="ri-user-star-line"></i> <?= clean($entry['employee_name']) ?><?php endif; ?>
            </td>
            <td class="text-center">
                <?php $statusBadge = ['POSTED' => 'badge-green', 'REVERSED' => 'badge-red', 'DRAFT' => 'badge-gray']; ?>
                <span class="badge <?= $statusBadge[$entry['status']] ?? 'badge-gray' ?>"><?= $entry['status'] ?></span>
            </td>
            <td class="text-muted"><?= clean($entry['created_by_name']) ?></td>
            <td class="text-center">
                <a href="view.php?id=<?= $entry['id'] ?>" class="btn btn-sm btn-outline" title="View"><i class="ri-eye-line"></i></a>
                <?php if ($entry['status'] === 'POSTED' && empty($entry['is_reversal']) && Auth::canAccessTransactionEntry($entry['id'], Auth::user('business_id'), 'write')): ?>
                    <a href="edit.php?id=<?= $entry['id'] ?>" class="btn btn-sm btn-outline" title="Edit entry"><i class="ri-edit-line"></i></a>
                <?php endif; ?>
                <?php if ($entry['status'] === 'POSTED' && empty($entry['is_reversal']) && Auth::canAccessTransactionEntry($entry['id'], Auth::user('business_id'), 'delete')): ?>
                    <a href="reverse.php?id=<?= $entry['id'] ?>" class="btn btn-sm btn-outline text-red" title="Delete entry through reversal"><i class="ri-delete-bin-line"></i></a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return trim(ob_get_clean());
}

$where = "WHERE je.business_id = ?";
$params = [$businessId];

$accessScopes = [];
if (!empty($accessibleAccountIds)) {
    $accessScopes[] = "EXISTS (
        SELECT 1
        FROM journal_lines jl_filter
        WHERE jl_filter.journal_entry_id = je.id
          AND jl_filter.account_id IN ($accountPlaceholders)
    )";
    $params = array_merge($params, $accessibleAccountIds);
}
if ($canReadJV) {
    $accessScopes[] = "je.transaction_type = 'JOURNAL_VOUCHER'";
}
$where .= ' AND (' . implode(' OR ', $accessScopes) . ')';

if ($filterEntryTypeId) {
    $where .= " AND COALESCE(NULLIF(je.entry_type_id, ''), CONCAT('SYSTEM:', je.transaction_type)) = ?";
    $params[] = $filterEntryTypeId;
}
if ($filterQuery !== '') {
    $needle = '%' . $filterQuery . '%';
    $where .= " AND (
        je.reference_no LIKE ? OR je.narration LIKE ?
        OR EXISTS (SELECT 1 FROM cars search_car WHERE search_car.id = je.car_id AND search_car.business_id = je.business_id AND (search_car.registration_no LIKE ? OR search_car.make LIKE ? OR search_car.model LIKE ?))
        OR EXISTS (SELECT 1 FROM partners search_partner WHERE search_partner.id = je.partner_id AND search_partner.business_id = je.business_id AND search_partner.name LIKE ?)
        OR EXISTS (SELECT 1 FROM employees search_employee WHERE search_employee.id = je.employee_id AND search_employee.business_id = je.business_id AND search_employee.name LIKE ?)
        OR EXISTS (SELECT 1 FROM users search_user WHERE search_user.id = je.created_by AND search_user.business_id = je.business_id AND search_user.full_name LIKE ?)
    )";
    array_push($params, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
}
if ($filterFromDate) { $where .= " AND je.entry_date >= ?"; $params[] = $filterFromDate; }
if ($filterToDate) { $where .= " AND je.entry_date <= ?"; $params[] = $filterToDate; }
if ($filterStatus) { $where .= " AND je.status = ?"; $params[] = $filterStatus; }
if ($filterUserId) { $where .= " AND je.created_by = ?"; $params[] = $filterUserId; }
if ($filterMinAmount !== '' && is_numeric($filterMinAmount)) { $where .= " AND je.entry_amount >= ?"; $params[] = (float) $filterMinAmount; }
if ($filterMaxAmount !== '' && is_numeric($filterMaxAmount)) { $where .= " AND je.entry_amount <= ?"; $params[] = (float) $filterMaxAmount; }

$total = $db->fetch("SELECT COUNT(*) as cnt FROM journal_entries je $where", $params);
$pagination = paginate($total['cnt'], $perPage, $page);

$entries = $db->fetchAll(
    "SELECT je.*, u.full_name as created_by_name,
            c.registration_no as car_reg, c.ownership_type AS car_ownership_type,
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

if ($isLazyRequest) {
    header('Content-Type: application/json');
    $nextPage = $page < $pagination['total_pages'] ? $page + 1 : null;
    echo json_encode([
        'html' => renderTransactionRows($entries),
        'next_url' => $nextPage ? transactionsListUrl($nextPage, $transactionFilters, true) : '',
    ]);
    exit;
}

$nextUrl = $page < $pagination['total_pages']
    ? transactionsListUrl($page + 1, $transactionFilters, true)
    : '';

$transactionUsers = $db->fetchAll(
    "SELECT DISTINCT u.id, u.full_name
     FROM journal_entries je
     JOIN users u ON u.id = je.created_by AND u.business_id = je.business_id
     WHERE je.business_id = ?
     ORDER BY u.full_name",
    [$businessId]
);

$customEntryTypes = $db->fetchAll(
    "SELECT id, code, name, group_name, is_active
     FROM accounts
     WHERE business_id = ?
       AND entity_type = 'GENERAL'
       AND group_name IN ('INCOME','EXPENSE')
       AND sub_group IN ('Daily Jama Categories','Daily Udhar Categories')
       AND code NOT IN ('CAR-REV','SALE-COMM','PNL','GST-PAY','GST-RCV','BAD-DEBT','ADV-WOFF','SAL-EXP','OB-EQUITY')
     ORDER BY FIELD(group_name, 'INCOME', 'EXPENSE'), is_active DESC, name",
    [$businessId]
);
?>

<div class="page-header entries-page-header">
    <div class="entries-page-copy">
        <h1><i class="ri-exchange-line"></i> All Entries</h1>
        <div class="text-muted">Receive/Jama, Payments, cash-bank transfers, and split bills in latest-first order.</div>
    </div>
    <div class="entries-page-actions">
        <?php if (Auth::hasBookAccess('jv_register', 'write')): ?>
            <a href="new.php?type=JOURNAL_VOUCHER" class="btn btn-outline"><i class="ri-bill-line"></i> Large Bill Split</a>
        <?php endif; ?>
        <a href="new.php" class="btn btn-primary"><i class="ri-add-line"></i> New Entry</a>
    </div>
</div>

<div class="entry-menu-legend">
    <span><i class="ri-arrow-down-circle-line"></i> Receive / Jama</span>
    <span><i class="ri-arrow-up-circle-line"></i> Payment / Expense</span>
    <span><i class="ri-bank-card-line"></i> Cash / Bank</span>
    <span><i class="ri-bill-line"></i> Large Bill Split</span>
</div>

<div class="filter-bar entries-filter-bar">
    <form method="GET" class="entries-filter-form">
        <div class="entries-filter-field entries-filter-search">
            <label class="form-label">Search</label>
            <input type="search" name="q" class="form-control" value="<?= clean($filterQuery) ?>" placeholder="Ref, narration, car, partner, employee">
        </div>
        <div class="entries-filter-field entries-filter-type">
            <label class="form-label">Entry Type</label>
            <select name="entry_type_id" class="form-control" data-searchable="true">
                <option value="">All Types</option>
                <optgroup label="Predefined Types">
                    <?php foreach (ENTRY_TYPE_META as $key => $meta): ?>
                        <?php if (empty($meta['summary']) && empty($meta['selectable'])) continue; ?>
                        <option value="<?= clean(systemEntryTypeId($key)) ?>" <?= $filterEntryTypeId === systemEntryTypeId($key) ? 'selected' : '' ?>><?= clean($meta['label']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php if ($customEntryTypes): ?>
                    <optgroup label="Custom Types">
                        <?php foreach ($customEntryTypes as $customType): ?>
                            <option value="<?= clean(customEntryTypeId($customType['id'])) ?>" <?= $filterEntryTypeId === customEntryTypeId($customType['id']) ? 'selected' : '' ?>><?= clean($customType['name']) ?><?= empty($customType['is_active']) ? ' (Inactive)' : '' ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>
        <div class="entries-filter-field entries-filter-date">
            <label class="form-label">From</label>
            <input type="date" name="from_date" class="form-control" value="<?= clean($filterFromDate) ?>">
        </div>
        <div class="entries-filter-field entries-filter-date">
            <label class="form-label">To</label>
            <input type="date" name="to_date" class="form-control" value="<?= clean($filterToDate) ?>">
        </div>
        <div class="entries-filter-field entries-filter-status">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">All Status</option>
                <option value="POSTED" <?= $filterStatus === 'POSTED' ? 'selected' : '' ?>>Posted</option>
                <option value="REVERSED" <?= $filterStatus === 'REVERSED' ? 'selected' : '' ?>>Reversed</option>
            </select>
        </div>
        <div class="entries-filter-field entries-filter-user">
            <label class="form-label">Entered By</label>
            <select name="user_id" class="form-control" data-searchable="true">
                <option value="">All Users</option>
                <?php foreach ($transactionUsers as $transactionUser): ?>
                    <option value="<?= clean($transactionUser['id']) ?>" <?= $filterUserId === $transactionUser['id'] ? 'selected' : '' ?>><?= clean($transactionUser['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="entries-filter-field entries-filter-amount">
            <label class="form-label">Amount From</label>
            <input type="number" min="0" step="0.01" name="min_amount" class="form-control" value="<?= clean($filterMinAmount) ?>" placeholder="0">
        </div>
        <div class="entries-filter-field entries-filter-amount">
            <label class="form-label">Amount To</label>
            <input type="number" min="0" step="0.01" name="max_amount" class="form-control" value="<?= clean($filterMaxAmount) ?>" placeholder="Any">
        </div>
        <div class="entries-filter-actions">
            <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Filter</button>
            <a href="list.php" class="btn btn-outline btn-sm">Clear</a>
        </div>
    </form>
</div>

<div class="table-container table-container-fill" data-lazy-list data-next-url="<?= clean($nextUrl) ?>">
    <table>
        <thead>
            <tr>
                <th>Ref No.</th>
                <th>Date / Time</th>
                <th>Type</th>
                <th>Narration</th>
                <th>Related</th>
                <th class="text-center">Status</th>
                <th>By</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?= renderTransactionRows($entries) ?>
        </tbody>
    </table>
    <?php if ($nextUrl): ?>
        <div class="lazy-list-footer" data-lazy-sentinel>
            <span data-lazy-status>More rows will load as you scroll.</span>
        </div>
    <?php endif; ?>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
<div class="pagination no-js-pagination">
    <?php if ($page > 1): ?>
        <a href="<?= clean(transactionsListUrl($page - 1, $transactionFilters)) ?>">← Prev</a>
    <?php endif; ?>
    <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
        <a href="<?= clean(transactionsListUrl($i, $transactionFilters)) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pagination['total_pages']): ?>
        <a href="<?= clean(transactionsListUrl($page + 1, $transactionFilters)) ?>">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
