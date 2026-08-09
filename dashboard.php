<?php
$pageTitle = 'Dashboard';
$pageIcon = '<i class="ri-dashboard-3-line"></i>';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$todayDate = date('Y-m-d');
$dashboardMinimumRows = 8;

$primaryAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'read');
$tabMeta = [
    'cash_book' => ['tab' => 'cash', 'fallback_label' => 'Cash Account', 'icon' => '💵', 'class' => 'cash'],
    'bank_book' => ['tab' => 'bank', 'fallback_label' => 'Bank Account', 'icon' => '🏦', 'class' => 'bank'],
];
$availableTabs = [];
foreach ($tabMeta as $bookKey => $meta) {
    if (isClientHiddenBook($bookKey)) {
        continue;
    }
    foreach (($primaryAccountGroups[$bookKey] ?? []) as $index => $account) {
        $tabKey = $meta['tab'] . ($index ? '-' . ($index + 1) : '');
        $availableTabs[$tabKey] = [
            'book_key' => $bookKey,
            'book_label' => $bookKey === 'cash_book' ? 'Cash Book' : 'Bank Book',
            'label' => $account['name'] ?: $meta['fallback_label'],
            'icon' => $meta['icon'],
            'class' => $meta['class'],
            'account' => $account,
        ];
    }
}
$accessibleAccountIds = array_values(array_filter(array_map(
    static fn($tab) => $tab['account']['id'] ?? null,
    $availableTabs
)));

$defaultTab = array_key_first($availableTabs);
$activeTab = get('tab', $defaultTab ?: '');
if (!$activeTab || !isset($availableTabs[$activeTab])) {
    $activeTab = $defaultTab ?: '';
}

$activeAccountId = $availableTabs[$activeTab]['account']['id'] ?? null;
$activeAccountLabel = $availableTabs[$activeTab]['label'] ?? 'Account';
$activeBookKey = $availableTabs[$activeTab]['book_key'] ?? null;
$fyStartDate = getCurrentFY() . '-04-01';

$accountLedger = [];
if ($activeAccountId) {
    $todayLedger = $db->fetchAll(
        "SELECT jl.*, je.business_id, je.entry_date, je.reference_no, je.narration, je.transaction_type, je.entry_type_id, je.entry_amount, je.id as entry_id
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = ? AND je.status IN ('POSTED','REVERSED') AND je.entry_date = ?
         ORDER BY je.entry_date DESC, je.created_at DESC
         LIMIT 24",
        [$activeAccountId, $todayDate]
    );
    $olderLedger = [];
    $remainingLedger = max(0, $dashboardMinimumRows - count($todayLedger));
    if ($remainingLedger > 0) {
        $olderLedger = $db->fetchAll(
            "SELECT jl.*, je.business_id, je.entry_date, je.reference_no, je.narration, je.transaction_type, je.entry_type_id, je.entry_amount, je.id as entry_id
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ? AND je.status IN ('POSTED','REVERSED') AND je.entry_date <> ?
             ORDER BY je.entry_date DESC, je.created_at DESC
             LIMIT ?",
            [$activeAccountId, $todayDate, $remainingLedger]
        );
    }
    $accountLedger = array_merge($todayLedger, $olderLedger);
}

$accountLedgerFromDate = !empty($accountLedger)
    ? min(array_column($accountLedger, 'entry_date'))
    : $fyStartDate;
$accountLedgerToDate = !empty($accountLedger)
    ? max(array_column($accountLedger, 'entry_date'))
    : $todayDate;

$bookViewMoreUrl = match ($activeBookKey) {
    'cash_book' => 'reports/cashbook.php?' . http_build_query([
        'from' => $accountLedgerFromDate,
        'to' => $accountLedgerToDate,
    ]),
    'bank_book' => 'reports/bankbook.php?' . http_build_query([
        'account_id' => $activeAccountId,
        'from' => $accountLedgerFromDate,
        'to' => $accountLedgerToDate,
    ]),
    default => 'transactions/list.php',
};

$totalCars = $db->fetch("SELECT COUNT(*) as cnt FROM cars WHERE business_id = ? AND COALESCE(ownership_type, 'OWNED') = 'OWNED' AND status = 'IN_STOCK'", [$businessId]);
$totalOutsideCars = $db->fetch("SELECT COUNT(*) as cnt FROM cars WHERE business_id = ? AND COALESCE(ownership_type, 'OWNED') = 'OUTSIDE' AND status = 'IN_STOCK'", [$businessId]);
$totalSold = $db->fetch("SELECT COUNT(*) as cnt FROM cars WHERE business_id = ? AND COALESCE(ownership_type, 'OWNED') = 'OWNED' AND status = 'SOLD'", [$businessId]);

$recentTxns = [];
if (!empty($accessibleAccountIds)) {
    $placeholders = implode(',', array_fill(0, count($accessibleAccountIds), '?'));
    $todayTxns = $db->fetchAll(
        "SELECT je.*, u.full_name as created_by_name
         FROM journal_entries je
         LEFT JOIN users u ON u.id = je.created_by
         WHERE je.business_id = ? AND je.status IN ('POSTED','REVERSED') AND je.entry_date = ?
           AND EXISTS (
               SELECT 1
               FROM journal_lines jl_filter
               WHERE jl_filter.journal_entry_id = je.id
                 AND jl_filter.account_id IN ($placeholders)
           )
         ORDER BY je.created_at DESC
         LIMIT 24",
        array_merge([$businessId, $todayDate], $accessibleAccountIds)
    );
    $olderTxns = [];
    $remainingTxns = max(0, $dashboardMinimumRows - count($todayTxns));
    if ($remainingTxns > 0) {
        $olderTxns = $db->fetchAll(
            "SELECT je.*, u.full_name as created_by_name
             FROM journal_entries je
             LEFT JOIN users u ON u.id = je.created_by
             WHERE je.business_id = ? AND je.status IN ('POSTED','REVERSED') AND je.entry_date <> ?
               AND EXISTS (
                   SELECT 1
                   FROM journal_lines jl_filter
                   WHERE jl_filter.journal_entry_id = je.id
                     AND jl_filter.account_id IN ($placeholders)
               )
             ORDER BY je.created_at DESC
             LIMIT ?",
            array_merge([$businessId, $todayDate], $accessibleAccountIds, [$remainingTxns])
        );
    }
    $recentTxns = array_merge($todayTxns, $olderTxns);
}

$alerts = $db->fetchAll(
    "SELECT * FROM alerts WHERE business_id = ? AND is_resolved = 0 ORDER BY created_at DESC LIMIT 6",
    [$businessId]
);

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$monthIncome = $db->fetch(
    "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE -jl.amount END), 0) as total
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     JOIN accounts a ON a.id = jl.account_id
     WHERE je.business_id = ? AND a.group_name = 'INCOME' AND je.status IN ('POSTED','REVERSED')
     AND je.entry_date BETWEEN ? AND ?",
    [$businessId, $monthStart, $monthEnd]
);
$monthExpense = $db->fetch(
    "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE -jl.amount END), 0) as total
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     JOIN accounts a ON a.id = jl.account_id
     WHERE je.business_id = ? AND a.group_name = 'EXPENSE' AND je.status IN ('POSTED','REVERSED')
     AND je.entry_date BETWEEN ? AND ?",
    [$businessId, $monthStart, $monthEnd]
);
$monthProfit = ($monthIncome['total'] ?? 0) - ($monthExpense['total'] ?? 0);
$canWritePrimaryBooks = Auth::hasAnyBookAccess(Auth::getPrimaryBookKeys(), 'write');
?>

<section class="dashboard-command">
    <div class="dashboard-command-head">
        <div>
            <div class="dashboard-eyebrow"><?= APP_NAME ?></div>
            <h1>Today’s control desk</h1>
            <p>Start entries, check books, and catch pending work without leaving this screen.</p>
        </div>
        <div class="dashboard-command-actions">
            <?php if ($canWritePrimaryBooks): ?>
                <a href="transactions/new.php" class="btn btn-primary"><i class="ri-add-circle-line"></i> New Entry</a>
                <a href="transactions/new.php?type=JOURNAL_VOUCHER" class="btn btn-outline"><i class="ri-bill-line"></i> Split Bill</a>
            <?php endif; ?>
            <a href="transactions/list.php" class="btn btn-outline"><i class="ri-list-check-2"></i> All Entries</a>
        </div>
    </div>

    <div class="dashboard-kpi-grid">
        <a href="cars/list.php?status=IN_STOCK" class="dashboard-kpi-card">
            <span class="dashboard-kpi-icon in"><i class="ri-car-line"></i></span>
            <div>
                <small>Ready Cars</small>
                <strong><?= intval($totalCars['cnt'] ?? 0) ?></strong>
            </div>
        </a>
        <a href="outside-cars/list.php?status=IN_STOCK" class="dashboard-kpi-card">
            <span class="dashboard-kpi-icon neutral"><i class="ri-steering-2-line"></i></span>
            <div>
                <small>Outside Cars</small>
                <strong><?= intval($totalOutsideCars['cnt'] ?? 0) ?></strong>
            </div>
        </a>
        <a href="cars/list.php?status=SOLD" class="dashboard-kpi-card">
            <span class="dashboard-kpi-icon neutral"><i class="ri-checkbox-circle-line"></i></span>
            <div>
                <small>Sold Cars</small>
                <strong><?= intval($totalSold['cnt'] ?? 0) ?></strong>
            </div>
        </a>
        <?php if (!isClientHiddenBook('profit_loss')): ?>
            <a href="reports/profit_loss.php" class="dashboard-kpi-card dashboard-kpi-wide">
                <span class="dashboard-kpi-icon <?= $monthProfit >= 0 ? 'in' : 'out' ?>"><i class="ri-line-chart-line"></i></span>
                <div>
                    <small>Month P&amp;L</small>
                    <strong class="<?= signedAmountColorClass($monthProfit, 'in') ?>"><?= formatAmount($monthProfit) ?></strong>
                    <em>Income <?= formatAmount($monthIncome['total'] ?? 0) ?> · Expense <?= formatAmount($monthExpense['total'] ?? 0) ?></em>
                </div>
            </a>
        <?php endif; ?>
        <a href="#alerts" class="dashboard-kpi-card">
            <span class="dashboard-kpi-icon <?= count($alerts) ? 'out' : 'in' ?>"><i class="ri-notification-3-line"></i></span>
            <div>
                <small>Alerts</small>
                <strong><?= count($alerts) ?></strong>
            </div>
        </a>
    </div>
</section>

<?php if (!empty($availableTabs)): ?>
    <section class="dashboard-account-switcher">
        <div class="dashboard-section-title">
            <div>
                <span>Books</span>
                <strong>Select ledger to review</strong>
            </div>
            <a href="<?= clean($bookViewMoreUrl) ?>">Open full book <i class="ri-arrow-right-line"></i></a>
        </div>
        <div class="dashboard-account-rail">
        <?php foreach ($availableTabs as $tabKey => $tab): ?>
            <a href="?tab=<?= clean($tabKey) ?>" class="dashboard-account-card <?= $activeTab === $tabKey ? 'active' : '' ?>">
                <span class="dashboard-account-type dashboard-book-tag-<?= clean($tab['class']) ?>"><?= clean($tab['book_label']) ?></span>
                <div class="dashboard-account-main">
                    <div class="dashboard-account-name"><?= clean($tab['label']) ?></div>
                    <div class="dashboard-account-meta"><?= clean($tab['account']['code'] ?? '') ?><?php if (!empty($tab['account']['current_balance_type'])): ?> · <?= clean($tab['account']['current_balance_type']) ?><?php endif; ?></div>
                </div>
                <div class="dashboard-account-side">
                    <strong><?= formatAmount($tab['account']['current_balance'] ?? 0) ?></strong>
                    <span><?= $activeTab === $tabKey ? 'Selected' : 'View' ?> <i class="<?= $activeTab === $tabKey ? 'ri-check-line' : 'ri-arrow-right-line' ?>"></i></span>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
    </section>
<?php else: ?>
    <div class="alert alert-info">
        <i class="ri-information-line"></i> Your user account does not have any Cash or Bank book access yet.
    </div>
<?php endif; ?>

<div class="dashboard-main-grid">
    <section class="dashboard-panel">
        <div class="dashboard-panel-head">
            <h3><i class="ri-book-open-line"></i> <?= clean($activeAccountLabel) ?> Recent Ledger</h3>
            <?php if ($activeBookKey && Auth::hasBookAccess($activeBookKey, 'write')): ?>
                <a href="transactions/new.php?account_id=<?= clean($activeAccountId) ?>" class="btn btn-primary btn-sm"><i class="ri-add-line"></i> Entry</a>
            <?php endif; ?>
        </div>
        <div class="dashboard-panel-body">
            <?php if (empty($accountLedger)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📝</div>
                    <h3>No ledger activity yet</h3>
                    <p>Your first entry will appear here after it is saved.</p>
                </div>
            <?php else: ?>
                <?php foreach ($accountLedger as $line): ?>
                    <a href="transactions/view.php?id=<?= $line['entry_id'] ?>" class="dashboard-activity-row">
                        <div class="activity-dot <?= $line['entry_type'] === 'DR' ? 'in' : 'out' ?>">
                            <i class="<?= $line['entry_type'] === 'DR' ? 'ri-arrow-down-line' : 'ri-arrow-up-line' ?>"></i>
                        </div>
                        <div class="activity-info">
                            <strong><?= clean(transactionTypeLabel($line['transaction_type'], $line)) ?></strong>
                            <span><?= clean(mb_substr($line['narration'] ?? '', 0, 70)) ?></span>
                        </div>
                        <div class="activity-side">
                            <strong class="amount <?= $line['entry_type'] === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($line['amount']) ?></strong>
                            <span><?= clean($line['reference_no']) ?></span>
                            <?= renderDateTimeStack($line['entry_date'], $line['created_at'] ?? null) ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="dashboard-panel-foot">
            <a href="<?= clean($bookViewMoreUrl) ?>" class="btn btn-outline btn-sm">View more ledger</a>
        </div>
    </section>

    <section class="dashboard-panel" id="alerts">
        <div class="dashboard-panel-head">
            <h3><i class="ri-pulse-line"></i> Activity & Alerts</h3>
            <a href="transactions/list.php" class="btn btn-outline btn-sm">View All</a>
        </div>
        <div class="dashboard-panel-body">
            <?php if (!empty($alerts)): ?>
                <?php foreach ($alerts as $alert): 
                    $severityClass = ['INFO' => 'blue', 'WARNING' => 'yellow', 'CRITICAL' => 'red'][$alert['severity']] ?? 'blue';
                ?>
                    <div class="dashboard-alert">
                        <span class="badge badge-<?= $severityClass ?>"><?= clean($alert['severity']) ?></span>
                        <strong><?= ALERT_TYPES[$alert['type']] ?? clean($alert['type']) ?></strong>
                        <div class="text-muted"><?= clean($alert['message']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (empty($recentTxns) && empty($alerts)): ?>
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <h3>All clear</h3>
                    <p>No alerts or recent transactions yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentTxns as $txn): ?>
                    <a href="transactions/view.php?id=<?= $txn['id'] ?>" class="dashboard-activity-row">
                        <div class="activity-dot"><i class="ri-exchange-line"></i></div>
                        <div class="activity-info">
                            <strong><?= clean(transactionTypeLabel($txn['transaction_type'], $txn)) ?></strong>
                            <span><?= clean(mb_substr($txn['narration'] ?? '', 0, 55)) ?></span>
                        </div>
                        <div class="activity-side">
                            <strong><?= clean($txn['reference_no']) ?></strong>
                            <?= renderDateTimeStack($txn['entry_date'], $txn['created_at'] ?? null) ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="dashboard-panel-foot">
            <a href="transactions/list.php" class="btn btn-outline btn-sm">View more entries</a>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
