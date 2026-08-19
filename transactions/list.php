<?php
$pageTitle = 'All Entries';
$pageIcon = '<i class="ri-exchange-line"></i>';
$isLazyRequest = ($_GET['lazy'] ?? '') === '1';
$isRojmelExport = ($_GET['view'] ?? 'entries') === 'rojmel' && ($_GET['export'] ?? '') === 'csv';
if ($isLazyRequest || $isRojmelExport) {
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
$displayMode = get('view', 'entries') === 'rojmel' ? 'rojmel' : 'entries';
$filterAccountId = trim((string) get('account_id', ''));
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
    'view' => $displayMode,
    'account_id' => $filterAccountId,
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

// Rojmel is a Cash/Bank/GST movement report. It deliberately derives every
// figure from posted journal lines rather than keeping a second editable
// running balance. In this operator-facing report a debit means money paid
// out and a credit means money received; that is the inverse of the asset
// journal-line direction used internally.
$rojmelAccounts = [];
$rojmelDays = [];
$rojmelOpeningSigned = 0.0;
$rojmelTotalDebit = 0.0;
$rojmelTotalCredit = 0.0;
$rojmelClosingSigned = 0.0;
$rojmelFromDate = '';
$rojmelToDate = '';
$rojmelAccountLabel = 'All accessible Cash / Bank / GST books';

if ($displayMode === 'rojmel') {
    if (!empty($accessibleAccountIds)) {
        $rojmelAccounts = $db->fetchAll(
            "SELECT id, code, name, entity_type, opening_balance, opening_balance_type, opening_entry_id
             FROM accounts
             WHERE business_id = ?
               AND id IN ($accountPlaceholders)
               AND entity_type IN ('CASH','BANK','GST')
               AND entity_id IS NULL
               AND is_active = 1
             ORDER BY FIELD(entity_type, 'CASH', 'BANK', 'GST'), code, name",
            array_merge([$businessId], $accessibleAccountIds)
        );
    }
    $rojmelAccountIds = array_column($rojmelAccounts, 'id');
    if ($filterAccountId !== '' && in_array($filterAccountId, $rojmelAccountIds, true)) {
        $rojmelAccountIds = [$filterAccountId];
        foreach ($rojmelAccounts as $account) {
            if ($account['id'] === $filterAccountId) {
                $rojmelAccountLabel = $account['name'] . ' (' . $account['code'] . ')';
                break;
            }
        }
    } elseif ($filterAccountId !== '') {
        $filterAccountId = '';
        $transactionFilters['account_id'] = '';
    }

    $validReportDate = static function ($value, $fallback) {
        $value = trim((string) $value);
        if ($value === '') return $fallback;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
    };
    $rojmelFromDate = $validReportDate($filterFromDate, getCurrentFY() . '-04-01');
    $rojmelToDate = $validReportDate($filterToDate, date('Y-m-d'));
    if ($rojmelToDate < $rojmelFromDate) {
        [$rojmelFromDate, $rojmelToDate] = [$rojmelToDate, $rojmelFromDate];
    }

    if (!empty($rojmelAccountIds)) {
        $rojmelAccountPlaceholders = implode(',', array_fill(0, count($rojmelAccountIds), '?'));
        foreach ($rojmelAccounts as $account) {
            if (!in_array($account['id'], $rojmelAccountIds, true) || !empty($account['opening_entry_id'])) continue;
            $rojmelOpeningSigned += signedBalanceValue($account['opening_balance'] ?? 0, $account['opening_balance_type'] ?? 'DR');
        }
        $priorMovement = $db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS dr_total,
                    COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS cr_total
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id IN ($rojmelAccountPlaceholders)
               AND je.status IN ('POSTED','REVERSED')
               AND je.entry_date < ?",
            array_merge($rojmelAccountIds, [$rojmelFromDate])
        );
        $rojmelOpeningSigned = round($rojmelOpeningSigned + floatval($priorMovement['dr_total'] ?? 0) - floatval($priorMovement['cr_total'] ?? 0), 2);

        $rojmelRows = $db->fetchAll(
            "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, je.entry_type_id,
                    je.car_id, je.party_id, c.registration_no AS car_reg, dc.name AS party_name,
                    COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS money_received,
                    COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS money_paid
             FROM journal_entries je
             JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.account_id IN ($rojmelAccountPlaceholders)
             LEFT JOIN cars c ON c.id = je.car_id AND c.business_id = je.business_id
             LEFT JOIN debtors_creditors dc ON dc.id = je.party_id AND dc.business_id = je.business_id
             $where
               AND je.status IN ('POSTED','REVERSED')
             GROUP BY je.id
             ORDER BY je.entry_date ASC, je.created_at ASC",
            array_merge($rojmelAccountIds, $params)
        );

        $rowsByDate = [];
        foreach ($rojmelRows as $row) {
            $rowsByDate[$row['entry_date']][] = $row;
        }

        $runningBalance = $rojmelOpeningSigned;
        $cursor = new DateTime($rojmelFromDate);
        $lastDate = new DateTime($rojmelToDate);
        while ($cursor <= $lastDate) {
            $dateKey = $cursor->format('Y-m-d');
            $dayOpening = $runningBalance;
            $dayDebit = 0.0;
            $dayCredit = 0.0;
            $dayEntries = [];
            foreach ($rowsByDate[$dateKey] ?? [] as $row) {
                $debit = round(floatval($row['money_paid']), 2);
                $credit = round(floatval($row['money_received']), 2);
                $runningBalance = round($runningBalance + $credit - $debit, 2);
                $dayDebit += $debit;
                $dayCredit += $credit;
                $row['debit_amount'] = $debit;
                $row['credit_amount'] = $credit;
                $row['running_balance'] = $runningBalance;
                $dayEntries[] = $row;
            }
            $rojmelTotalDebit += $dayDebit;
            $rojmelTotalCredit += $dayCredit;
            $rojmelDays[] = [
                'date' => $dateKey,
                'opening' => $dayOpening,
                'entries' => $dayEntries,
                'total_debit' => $dayDebit,
                'total_credit' => $dayCredit,
                'closing' => $runningBalance,
            ];
            $cursor->modify('+1 day');
        }
        $rojmelClosingSigned = $runningBalance;
    }

    if (get('export', '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rojmel-' . $rojmelFromDate . '-to-' . $rojmelToDate . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Opening Balance', 'Reference', 'Type', 'Narration', 'Related', 'Debit (Money Out)', 'Credit (Money In)', 'Closing Balance']);
        foreach ($rojmelDays as $day) {
            if (empty($day['entries'])) {
                fputcsv($output, [$day['date'], $day['opening'], '', 'No money movement', '', '', 0, 0, $day['closing']]);
                continue;
            }
            foreach ($day['entries'] as $index => $row) {
                $related = trim(($row['car_reg'] ? formatRegistrationNo($row['car_reg']) : '') . ($row['party_name'] ? ' ' . $row['party_name'] : ''));
                fputcsv($output, [
                    $day['date'],
                    $index === 0 ? $day['opening'] : '',
                    $row['reference_no'],
                    transactionTypeLabel($row['transaction_type'], $row),
                    $row['narration'],
                    $related,
                    $row['debit_amount'],
                    $row['credit_amount'],
                    $index === count($day['entries']) - 1 ? $day['closing'] : '',
                ]);
            }
            fputcsv($output, [$day['date'], '', 'DAY TOTAL', '', '', '', $day['total_debit'], $day['total_credit'], $day['closing']]);
        }
        fputcsv($output, ['REPORT TOTAL', $rojmelOpeningSigned, '', '', '', '', $rojmelTotalDebit, $rojmelTotalCredit, $rojmelClosingSigned]);
        fclose($output);
        exit;
    }
}
?>

<div class="page-header entries-page-header">
    <div class="entries-page-copy">
        <h1><i class="ri-exchange-line"></i> All Entries</h1>
        <div class="text-muted"><?= $displayMode === 'rojmel' ? 'Date-wise cash and bank Rojmel with carry-forward balance.' : 'Receive/Jama, Payments, cash-bank transfers, and split bills in latest-first order.' ?></div>
    </div>
    <div class="entries-page-actions">
        <a href="<?= clean(transactionsListUrl(1, array_merge($transactionFilters, ['view' => $displayMode === 'rojmel' ? 'entries' : 'rojmel', 'account_id' => '']))) ?>" class="btn btn-outline"><i class="<?= $displayMode === 'rojmel' ? 'ri-list-check-2' : 'ri-book-2-line' ?>"></i> <?= $displayMode === 'rojmel' ? 'All Entries List' : 'Daily Rojmel' ?></a>
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
        <input type="hidden" name="view" value="<?= clean($displayMode) ?>">
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
        <?php if ($displayMode === 'rojmel'): ?>
        <div class="entries-filter-field entries-filter-type">
            <label class="form-label">Money Book</label>
            <select name="account_id" class="form-control" data-searchable="true">
                <option value="">All accessible Cash / Bank / GST</option>
                <?php foreach ($rojmelAccounts as $account): ?>
                    <option value="<?= clean($account['id']) ?>" <?= $filterAccountId === $account['id'] ? 'selected' : '' ?>><?= clean($account['name']) ?> (<?= clean($account['code']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="entries-filter-actions">
            <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Filter</button>
            <a href="list.php<?= $displayMode === 'rojmel' ? '?view=rojmel' : '' ?>" class="btn btn-outline btn-sm">Clear</a>
        </div>
    </form>
</div>

<?php if ($displayMode === 'rojmel'): ?>
<?php
    $formatRojmelBalance = static function ($amount) {
        $amount = round(floatval($amount), 2);
        return formatAmount(abs($amount)) . ' ' . ($amount >= 0 ? 'DR' : 'CR');
    };
    $rojmelExportFilters = array_merge($transactionFilters, [
        'view' => 'rojmel',
        'from_date' => $rojmelFromDate,
        'to_date' => $rojmelToDate,
        'account_id' => $filterAccountId,
        'export' => 'csv',
    ]);
?>
<div class="card">
    <div class="card-body summary-strip">
        <div><span class="text-muted">Money Book:</span> <strong><?= clean($rojmelAccountLabel) ?></strong></div>
        <div><span class="text-muted">Opening:</span> <strong class="amount <?= $rojmelOpeningSigned >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRojmelBalance($rojmelOpeningSigned) ?></strong></div>
        <div><span class="text-muted">Total Debit / Paid:</span> <strong class="amount credit-amount"><?= formatAmount($rojmelTotalDebit) ?></strong></div>
        <div><span class="text-muted">Total Credit / Received:</span> <strong class="amount debit-amount"><?= formatAmount($rojmelTotalCredit) ?></strong></div>
        <div><span class="text-muted">Closing:</span> <strong class="amount <?= $rojmelClosingSigned >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRojmelBalance($rojmelClosingSigned) ?></strong></div>
    </div>
</div>

<div class="alert alert-info">
    <i class="ri-information-line"></i>
    <div><strong>How this daily Rojmel works</strong><span>Closing Balance = Opening Balance + Credit (money received) − Debit (money paid). Each date carries its closing balance into the next date. Set the first opening amount only through Opening Balances, so this report always matches the books. When a type, user, search, status, or amount filter is applied, the movements and closing shown are for that filtered report; clear those filters to reconcile the full money-book closing.</span></div>
</div>

<div class="page-actions report-actions">
    <a href="../settings/opening_balances.php" class="btn btn-outline btn-sm"><i class="ri-scales-line"></i> Set Opening Balance</a>
    <a href="<?= clean('list.php?' . http_build_query($rojmelExportFilters)) ?>" class="btn btn-outline btn-sm"><i class="ri-download-2-line"></i> Download CSV</a>
    <button type="button" onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<?php if (empty($rojmelAccounts)): ?>
<div class="alert alert-warning"><i class="ri-lock-line"></i> You do not have access to an active Cash, Bank, or GST account. Ask an administrator to grant the relevant book permission.</div>
<?php else: ?>
<div class="table-container table-container-fill">
    <table class="table-total-room">
        <thead><tr><th>Date / Time</th><th>Reference</th><th>Type</th><th>Narration</th><th>Related</th><th class="text-right credit-amount">Debit / Paid</th><th class="text-right debit-amount">Credit / Received</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($rojmelDays as $day): ?>
            <tr class="table-group-row"><td colspan="8"><strong><?= formatDate($day['date']) ?></strong> <span class="text-muted">Opening Balance: <?= $formatRojmelBalance($day['opening']) ?></span></td></tr>
            <?php foreach ($day['entries'] as $entry): ?>
            <tr>
                <td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td>
                <td><a href="view.php?id=<?= clean($entry['id']) ?>" class="text-bold"><?= clean($entry['reference_no']) ?></a></td>
                <td><span class="badge badge-blue"><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?></span></td>
                <td class="narration-cell"><?= clean(mb_strimwidth((string) ($entry['narration'] ?? ''), 0, 58, '…')) ?></td>
                <td class="text-muted"><?php if (!empty($entry['car_reg'])): ?><i class="ri-car-line"></i> <?= formatRegistrationNo($entry['car_reg']) ?><?php endif; ?><?php if (!empty($entry['party_name'])): ?><div><?= clean($entry['party_name']) ?></div><?php endif; ?></td>
                <td class="text-right amount credit-amount"><?= $entry['debit_amount'] > 0.009 ? formatAmount($entry['debit_amount']) : '—' ?></td>
                <td class="text-right amount debit-amount"><?= $entry['credit_amount'] > 0.009 ? formatAmount($entry['credit_amount']) : '—' ?></td>
                <td class="text-right amount <?= $entry['running_balance'] >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRojmelBalance($entry['running_balance']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($day['entries'])): ?><tr><td><?= formatDate($day['date']) ?></td><td colspan="6" class="text-muted">No Cash / Bank / GST movement</td><td class="text-right amount <?= $day['closing'] >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRojmelBalance($day['closing']) ?></td></tr><?php endif; ?>
            <tr class="table-summary-row"><td colspan="5">Daily Total · Closing Balance</td><td class="text-right amount credit-amount"><?= formatAmount($day['total_debit']) ?></td><td class="text-right amount debit-amount"><?= formatAmount($day['total_credit']) ?></td><td class="text-right amount <?= $day['closing'] >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRojmelBalance($day['closing']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="5"><strong>Report Total · Closing Balance as at <?= formatDate($rojmelToDate) ?></strong></td><td class="text-right amount credit-amount"><?= formatAmount($rojmelTotalDebit) ?></td><td class="text-right amount debit-amount"><?= formatAmount($rojmelTotalCredit) ?></td><td class="text-right amount <?= $rojmelClosingSigned >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= $formatRojmelBalance($rojmelClosingSigned) ?></td></tr></tfoot>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
<?php endif; ?>

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
