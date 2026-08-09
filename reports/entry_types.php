<?php
$pageTitle = 'Income & Expense Types';
$pageIcon = '<i class="ri-layout-grid-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
Auth::requireAnyBookAccess(['profit_loss', 'general_ledger'], 'read');
new AccountingEngine($businessId, Auth::user('user_id'));

$fromDate = trim((string) get('from', getCurrentFY() . '-04-01'));
$toDate = trim((string) get('to', date('Y-m-d')));
$filterEntryTypeId = trim((string) get('entry_type_id', ''));
$filterCategory = trim((string) get('category', ''));
$filterAccountId = trim((string) get('account_id', ''));
$filterStatus = strtoupper(trim((string) get('status', 'POSTED')));
if (!in_array($filterStatus, ['', 'POSTED', 'REVERSED', 'DRAFT'], true)) $filterStatus = 'POSTED';
if ($fromDate === '' || $toDate === '' || $fromDate > $toDate) {
    $fromDate = getCurrentFY() . '-04-01';
    $toDate = date('Y-m-d');
}

$customAccounts = $db->fetchAll(
    "SELECT id, code, name, group_name, sub_group, is_active
     FROM accounts
     WHERE business_id = ?
       AND entity_type = 'GENERAL'
       AND group_name IN ('INCOME','EXPENSE')
       AND sub_group IN ('Daily Jama Categories','Daily Udhar Categories')
       AND code NOT IN ('CAR-REV','SALE-COMM','PNL','GST-PAY','GST-RCV','BAD-DEBT','ADV-WOFF','SAL-EXP','OB-EQUITY')
     ORDER BY FIELD(group_name, 'INCOME', 'EXPENSE'), is_active DESC, name",
    [$businessId]
);

$entryTypes = [];
foreach (ENTRY_TYPE_META as $code => $meta) {
    if (empty($meta['summary']) || !in_array($meta['flow'], ['in', 'out'], true)) continue;
    $id = systemEntryTypeId($code);
    $entryTypes[$id] = array_merge($meta, [
        'id' => $id,
        'code' => $code,
        'source' => 'Predefined',
        'active' => true,
    ]);
}
foreach ($customAccounts as $account) {
    $flow = $account['group_name'] === 'INCOME' ? 'in' : 'out';
    $id = customEntryTypeId($account['id']);
    $entryTypes[$id] = [
        'id' => $id,
        'code' => $account['code'],
        'label' => $account['name'],
        'flow' => $flow,
        'category' => 'Custom',
        'icon' => $flow === 'in' ? 'ri-arrow-down-circle-line' : 'ri-arrow-up-circle-line',
        'description' => trim((string) ($account['sub_group'] ?: 'Custom entry category')),
        'source' => 'Custom',
        'active' => !empty($account['is_active']),
        'account_id' => $account['id'],
    ];
}

uasort($entryTypes, static function ($a, $b) {
    return [$a['flow'], $a['category'], $a['label']] <=> [$b['flow'], $b['category'], $b['label']];
});

$accounts = $db->fetchAll(
    "SELECT id, code, name, group_name, entity_type, is_active
     FROM accounts
     WHERE business_id = ?
     ORDER BY is_active DESC, FIELD(entity_type, 'CASH', 'BANK', 'GENERAL'), group_name, name",
    [$businessId]
);

$where = "WHERE je.business_id = ?
          AND je.entry_date BETWEEN ? AND ?
          AND je.is_reversal = 0
          AND COALESCE(NULLIF(je.entry_type_id, ''), CONCAT('SYSTEM:', je.transaction_type)) <> ?";
$params = [$businessId, $fromDate, $toDate, systemEntryTypeId('INTERNAL_ALLOCATION')];
if ($filterStatus !== '') {
    $where .= " AND je.status = ?";
    $params[] = $filterStatus;
}
if ($filterAccountId !== '') {
    $where .= " AND EXISTS (
        SELECT 1 FROM journal_lines account_filter
        WHERE account_filter.journal_entry_id = je.id AND account_filter.account_id = ?
    )";
    $params[] = $filterAccountId;
}

$summaryRows = $db->fetchAll(
    "SELECT COALESCE(NULLIF(je.entry_type_id, ''), CONCAT('SYSTEM:', je.transaction_type)) AS resolved_entry_type_id,
            COUNT(*) AS transaction_count,
            COALESCE(SUM(je.entry_amount), 0) AS total_amount,
            MAX(je.entry_date) AS last_entry_date
     FROM journal_entries je
     $where
     GROUP BY resolved_entry_type_id",
    $params
);
$statsByType = [];
foreach ($summaryRows as $row) $statsByType[$row['resolved_entry_type_id']] = $row;

$categories = [];
foreach ($entryTypes as $meta) $categories[$meta['category']] = true;
$categories = array_keys($categories);
sort($categories);

$visibleTypes = array_filter($entryTypes, static function ($meta) use ($filterEntryTypeId, $filterCategory) {
    if ($filterEntryTypeId !== '' && $meta['id'] !== $filterEntryTypeId) return false;
    if ($filterCategory !== '' && $meta['category'] !== $filterCategory) return false;
    return true;
});

$visibleTotal = 0.0;
$visibleCount = 0;
foreach ($visibleTypes as $meta) {
    $stats = $statsByType[$meta['id']] ?? [];
    $visibleTotal += floatval($stats['total_amount'] ?? 0);
    $visibleCount += intval($stats['transaction_count'] ?? 0);
}

$selectedType = $filterEntryTypeId !== '' ? ($entryTypes[$filterEntryTypeId] ?? null) : null;
$detailRows = [];
$detailTotal = 0;
$detailPagination = null;
if ($selectedType) {
    $detailWhere = $where . " AND COALESCE(NULLIF(je.entry_type_id, ''), CONCAT('SYSTEM:', je.transaction_type)) = ?";
    $detailParams = array_merge($params, [$selectedType['id']]);
    $detailTotalRow = $db->fetch("SELECT COUNT(*) AS cnt FROM journal_entries je $detailWhere", $detailParams);
    $detailTotal = intval($detailTotalRow['cnt'] ?? 0);
    $detailPage = max(1, intval(get('page', 1)));
    $detailPagination = paginate($detailTotal, 40, $detailPage);
    $detailRows = $db->fetchAll(
        "SELECT je.*, u.full_name AS created_by_name,
                c.registration_no AS car_reg, p.name AS partner_name,
                e.name AS employee_name, dc.name AS party_name,
                (SELECT GROUP_CONCAT(DISTINCT CONCAT(a.name, ' (', a.code, ')') ORDER BY a.name SEPARATOR ', ')
                 FROM journal_lines jl_accounts
                 JOIN accounts a ON a.id = jl_accounts.account_id
                 WHERE jl_accounts.journal_entry_id = je.id
                   AND a.entity_type IN ('CASH','BANK')) AS primary_accounts
         FROM journal_entries je
         LEFT JOIN users u ON u.id = je.created_by
         LEFT JOIN cars c ON c.id = je.car_id
         LEFT JOIN partners p ON p.id = je.partner_id
         LEFT JOIN employees e ON e.id = je.employee_id
         LEFT JOIN debtors_creditors dc ON dc.id = je.party_id
         $detailWhere
         ORDER BY je.entry_date DESC, je.created_at DESC
         LIMIT ? OFFSET ?",
        array_merge($detailParams, [40, $detailPagination['offset']])
    );
}

function entryTypeSummaryUrl(array $changes = []) {
    $query = $_GET;
    unset($query['page']);
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return 'entry_types.php' . ($query ? '?' . http_build_query($query) : '');
}
?>

<div class="page-header entry-type-page-header">
    <div>
        <h1><i class="ri-layout-grid-line"></i> Income &amp; Expense Types</h1>
        <p class="text-muted">One consistent view of every predefined and custom money-in or money-out type.</p>
    </div>
    <?php if (Auth::isAdmin()): ?>
        <a href="../settings/categories.php" class="btn btn-outline"><i class="ri-settings-3-line"></i> Manage Custom Types</a>
    <?php endif; ?>
</div>

<div class="entry-type-overview">
    <div><span>Types shown</span><strong><?= count($visibleTypes) ?></strong></div>
    <div><span>Transactions</span><strong><?= formatPlainNumber($visibleCount) ?></strong></div>
    <div><span>Total activity</span><strong><?= formatAmount($visibleTotal) ?></strong></div>
    <div><span>Period</span><strong><?= clean(formatDate($fromDate, 'd M Y')) ?> – <?= clean(formatDate($toDate, 'd M Y')) ?></strong></div>
</div>

<div class="filter-bar entry-type-filter-bar">
    <form method="GET" class="entry-type-filter-form">
        <div class="form-group"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= clean($fromDate) ?>"></div>
        <div class="form-group"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= clean($toDate) ?>"></div>
        <div class="form-group">
            <label class="form-label">Entry Type</label>
            <select name="entry_type_id" class="form-control" data-searchable="true">
                <option value="">All entry types</option>
                <optgroup label="Predefined">
                    <?php foreach ($entryTypes as $type): if ($type['source'] !== 'Predefined') continue; ?>
                        <option value="<?= clean($type['id']) ?>" <?= $filterEntryTypeId === $type['id'] ? 'selected' : '' ?>><?= clean($type['label']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Custom">
                    <?php foreach ($entryTypes as $type): if ($type['source'] !== 'Custom') continue; ?>
                        <option value="<?= clean($type['id']) ?>" <?= $filterEntryTypeId === $type['id'] ? 'selected' : '' ?>><?= clean($type['label']) ?><?= empty($type['active']) ? ' (Inactive)' : '' ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Module</label><select name="category" class="form-control"><option value="">All modules</option><?php foreach ($categories as $category): ?><option value="<?= clean($category) ?>" <?= $filterCategory === $category ? 'selected' : '' ?>><?= clean($category) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Account</label><select name="account_id" class="form-control" data-searchable="true"><option value="">All accounts</option><?php foreach ($accounts as $account): ?><option value="<?= clean($account['id']) ?>" <?= $filterAccountId === $account['id'] ? 'selected' : '' ?>><?= clean($account['name']) ?> (<?= clean($account['code']) ?>)</option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Status</label><select name="status" class="form-control"><option value="">All statuses</option><option value="POSTED" <?= $filterStatus === 'POSTED' ? 'selected' : '' ?>>Posted</option><option value="REVERSED" <?= $filterStatus === 'REVERSED' ? 'selected' : '' ?>>Reversed</option><option value="DRAFT" <?= $filterStatus === 'DRAFT' ? 'selected' : '' ?>>Draft</option></select></div>
        <div class="entry-type-filter-actions"><button class="btn btn-primary" type="submit"><i class="ri-filter-3-line"></i> Apply</button><a href="entry_types.php" class="btn btn-outline">Clear</a></div>
    </form>
</div>

<div class="entry-type-card-grid">
    <?php foreach ($visibleTypes as $type): $stats = $statsByType[$type['id']] ?? []; $isSelected = $filterEntryTypeId === $type['id']; ?>
        <a href="<?= clean(entryTypeSummaryUrl(['entry_type_id' => $type['id']])) ?>#entry-type-details" class="entry-type-card <?= $type['flow'] === 'in' ? 'is-income' : 'is-expense' ?> <?= $isSelected ? 'is-selected' : '' ?>">
            <div class="entry-type-card-top">
                <span class="entry-type-card-icon"><i class="<?= clean($type['icon']) ?>"></i></span>
                <span class="entry-type-source"><?= clean($type['source']) ?></span>
                <?php if (empty($type['active'])): ?><span class="badge badge-gray">Inactive</span><?php endif; ?>
            </div>
            <h3><?= clean($type['label']) ?></h3>
            <p><?= clean($type['description']) ?></p>
            <div class="entry-type-card-amount"><?= formatAmount($stats['total_amount'] ?? 0) ?></div>
            <div class="entry-type-card-stats">
                <span><strong><?= intval($stats['transaction_count'] ?? 0) ?></strong> transactions</span>
                <span><?= !empty($stats['last_entry_date']) ? 'Last ' . clean(formatDate($stats['last_entry_date'], 'd M Y')) : 'No activity' ?></span>
            </div>
            <div class="entry-type-card-foot"><span><?= clean($type['category']) ?> · <?= $type['flow'] === 'in' ? 'Money In' : 'Money Out' ?></span><i class="ri-arrow-right-line"></i></div>
        </a>
    <?php endforeach; ?>
    <?php if (!$visibleTypes): ?><div class="empty-state entry-type-empty"><div class="empty-icon"><i class="ri-filter-off-line"></i></div><h3>No matching entry types</h3><p>Clear or change the filters to view entry types.</p></div><?php endif; ?>
</div>

<?php if ($selectedType): ?>
<section class="card entry-type-detail" id="entry-type-details">
    <div class="card-header">
        <div><h3><i class="<?= clean($selectedType['icon']) ?>"></i> <?= clean($selectedType['label']) ?></h3><div class="text-muted"><?= formatPlainNumber($detailTotal) ?> matching transactions for the selected period and filters.</div></div>
        <a href="<?= clean(entryTypeSummaryUrl(['entry_type_id' => null])) ?>" class="btn btn-outline btn-sm"><i class="ri-close-line"></i> Close Details</a>
    </div>
    <div class="table-container entry-type-detail-table">
        <table>
            <thead><tr><th>Date / Time</th><th>Reference</th><th>Narration</th><th>Account</th><th>Related To</th><th class="text-right">Amount</th><th>Status</th><th>By</th><th class="text-center">Actions</th></tr></thead>
            <tbody>
                <?php foreach ($detailRows as $entry): ?>
                    <?php $related = $entry['car_reg'] ?: ($entry['partner_name'] ?: ($entry['employee_name'] ?: ($entry['party_name'] ?: '-'))); ?>
                    <tr>
                        <td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td>
                        <td><a class="text-bold" href="../transactions/view.php?id=<?= urlencode($entry['id']) ?>"><?= clean($entry['reference_no']) ?></a></td>
                        <td><span class="narration-tooltip" data-full-text="<?= clean($entry['narration']) ?>" tabindex="0"><?= clean(mb_strimwidth((string) $entry['narration'], 0, 70, '…')) ?></span></td>
                        <td><?= clean($entry['primary_accounts'] ?: '-') ?></td>
                        <td><?= clean($related) ?></td>
                        <td class="text-right amount <?= $selectedType['flow'] === 'in' ? 'flow-in' : 'flow-out' ?>"><?= formatAmount($entry['entry_amount']) ?></td>
                        <td><span class="badge <?= $entry['status'] === 'POSTED' ? 'badge-green' : ($entry['status'] === 'REVERSED' ? 'badge-red' : 'badge-gray') ?>"><?= clean($entry['status']) ?></span></td>
                        <td><?= clean($entry['created_by_name'] ?: 'System') ?></td>
                        <td class="text-center"><a href="../transactions/view.php?id=<?= urlencode($entry['id']) ?>" class="btn btn-outline btn-sm" title="View"><i class="ri-eye-line"></i></a><?php if ($entry['status'] === 'POSTED' && Auth::canAccessTransactionEntry($entry['id'], $businessId, 'write')): ?> <a href="../transactions/edit.php?id=<?= urlencode($entry['id']) ?>" class="btn btn-outline btn-sm" title="Edit"><i class="ri-edit-line"></i></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$detailRows): ?><tr><td colspan="9" class="text-center text-muted empty-table-cell">No transactions match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($detailPagination && $detailPagination['total_pages'] > 1): ?>
        <div class="pagination"><?php for ($pageNo = 1; $pageNo <= $detailPagination['total_pages']; $pageNo++): ?><a class="btn btn-sm <?= $pageNo === $detailPagination['current_page'] ? 'btn-primary' : 'btn-outline' ?>" href="<?= clean(entryTypeSummaryUrl(['page' => $pageNo])) ?>#entry-type-details"><?= $pageNo ?></a><?php endfor; ?></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
