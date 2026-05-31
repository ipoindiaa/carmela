<?php
$pageTitle = 'Dashboard';
$pageIcon = '<i class="ri-dashboard-3-line"></i>';
require_once __DIR__ . '/includes/header.php';

$businessId = Auth::user('business_id');

$primaryAccounts = Auth::getAccessiblePrimaryAccounts($businessId, 'read');
$tabMeta = [
    'cash_book' => ['tab' => 'cash', 'label' => 'Cash Account', 'icon' => '💵', 'class' => 'cash'],
    'bank_book' => ['tab' => 'bank', 'label' => 'Bank Account', 'icon' => '🏦', 'class' => 'bank'],
    'gst_book' => ['tab' => 'gst', 'label' => 'GST Account', 'icon' => '📋', 'class' => 'gst'],
];
$availableTabs = [];
foreach ($tabMeta as $bookKey => $meta) {
    if (!empty($primaryAccounts[$bookKey])) {
        $availableTabs[$meta['tab']] = [
            'book_key' => $bookKey,
            'label' => $meta['label'],
            'icon' => $meta['icon'],
            'class' => $meta['class'],
            'account' => $primaryAccounts[$bookKey],
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

$accountLedger = [];
if ($activeAccountId) {
    $accountLedger = $db->fetchAll(
        "SELECT jl.*, je.entry_date, je.reference_no, je.narration, je.transaction_type, je.id as entry_id
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = ? AND je.status = 'POSTED'
         ORDER BY je.entry_date DESC, je.created_at DESC
         LIMIT 8",
        [$activeAccountId]
    );
}

$totalCars = $db->fetch("SELECT COUNT(*) as cnt FROM cars WHERE business_id = ? AND status = 'IN_STOCK'", [$businessId]);
$totalSold = $db->fetch("SELECT COUNT(*) as cnt FROM cars WHERE business_id = ? AND status = 'SOLD'", [$businessId]);
$totalPartners = $db->fetch("SELECT COUNT(*) as cnt FROM partners WHERE business_id = ? AND is_active = 1", [$businessId]);
$totalEmployees = $db->fetch("SELECT COUNT(*) as cnt FROM employees WHERE business_id = ? AND is_active = 1", [$businessId]);

$recentTxns = [];
if (!empty($accessibleAccountIds)) {
    $placeholders = implode(',', array_fill(0, count($accessibleAccountIds), '?'));
    $recentTxns = $db->fetchAll(
        "SELECT je.*, u.full_name as created_by_name
         FROM journal_entries je
         LEFT JOIN users u ON u.id = je.created_by
         WHERE je.business_id = ? AND je.status = 'POSTED'
           AND EXISTS (
               SELECT 1
               FROM journal_lines jl_filter
               WHERE jl_filter.journal_entry_id = je.id
                 AND jl_filter.account_id IN ($placeholders)
           )
         ORDER BY je.created_at DESC
         LIMIT 8",
        array_merge([$businessId], $accessibleAccountIds)
    );
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
     WHERE je.business_id = ? AND a.group_name = 'INCOME' AND je.status = 'POSTED'
     AND je.entry_date BETWEEN ? AND ?",
    [$businessId, $monthStart, $monthEnd]
);
$monthExpense = $db->fetch(
    "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE -jl.amount END), 0) as total
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     JOIN accounts a ON a.id = jl.account_id
     WHERE je.business_id = ? AND a.group_name = 'EXPENSE' AND je.status = 'POSTED'
     AND je.entry_date BETWEEN ? AND ?",
    [$businessId, $monthStart, $monthEnd]
);
$monthProfit = ($monthIncome['total'] ?? 0) - ($monthExpense['total'] ?? 0);
$canWritePrimaryBooks = Auth::hasAnyBookAccess(Auth::getPrimaryBookKeys(), 'write');
?>

<section class="dashboard-hero">
    <div>
        <div class="dashboard-eyebrow">AutoBooks Pro Command Center</div>
        <h1>Roz ni entry fast, clear ane mistake-proof.</h1>
        <p>Cash, bank, GST, car expense, partner, salary, loan ane motu split bill - badhu ek New Entry flow mathi manage karo.</p>
        <div class="dashboard-hero-actions">
            <?php if ($canWritePrimaryBooks): ?>
                <a href="transactions/new.php" class="btn btn-primary btn-lg"><i class="ri-add-circle-line"></i> Navi Entry</a>
                <a href="transactions/new.php?type=JOURNAL_VOUCHER" class="btn btn-outline btn-lg"><i class="ri-bill-line"></i> Motu Bill Split</a>
            <?php endif; ?>
            <a href="transactions/list.php" class="btn btn-outline btn-lg"><i class="ri-list-check-2"></i> Entries Jo</a>
        </div>
    </div>
    <div class="dashboard-focus-card">
        <span>Current Month P&L</span>
        <strong><?= formatAmount($monthProfit) ?></strong>
        <div class="dashboard-focus-list">
            <div><i class="ri-arrow-down-circle-line"></i> Income: <?= formatAmount($monthIncome['total'] ?? 0) ?></div>
            <div><i class="ri-arrow-up-circle-line"></i> Expense: <?= formatAmount($monthExpense['total'] ?? 0) ?></div>
            <div><i class="ri-notification-3-line"></i> Pending alerts: <?= count($alerts) ?></div>
        </div>
    </div>
</section>

<?php if (!empty($availableTabs)): ?>
    <div class="dashboard-book-grid">
        <?php foreach ($availableTabs as $tabKey => $tab): ?>
            <a href="?tab=<?= clean($tabKey) ?>" class="dashboard-book-card <?= $activeTab === $tabKey ? 'active' : '' ?>">
                <div class="dashboard-book-top">
                    <div>
                        <div class="dashboard-book-label"><?= clean($tab['label']) ?></div>
                        <div class="dashboard-book-balance"><?= formatAmount($tab['account']['current_balance'] ?? 0) ?></div>
                    </div>
                    <div class="dashboard-book-icon"><?= $tab['icon'] ?></div>
                </div>
                <span class="text-muted">Click kari recent ledger jo.</span>
            </a>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="alert alert-info">
        <i class="ri-information-line"></i> Your user account does not have any Cash, Bank, or GST book access yet.
    </div>
<?php endif; ?>

<div class="dashboard-stat-grid">
    <div class="dashboard-mini-stat">
        <div class="dashboard-mini-icon"><i class="ri-car-line"></i></div>
        <div>
            <div class="dashboard-mini-value"><?= $totalCars['cnt'] ?? 0 ?></div>
            <div class="dashboard-mini-label">Cars In Stock</div>
        </div>
    </div>
    <div class="dashboard-mini-stat">
        <div class="dashboard-mini-icon" style="background: var(--accent-green-glow); color: var(--accent-green);"><i class="ri-check-double-line"></i></div>
        <div>
            <div class="dashboard-mini-value"><?= $totalSold['cnt'] ?? 0 ?></div>
            <div class="dashboard-mini-label">Cars Sold</div>
        </div>
    </div>
    <div class="dashboard-mini-stat">
        <div class="dashboard-mini-icon" style="background: var(--accent-yellow-glow); color: var(--accent-yellow);"><i class="ri-group-line"></i></div>
        <div>
            <div class="dashboard-mini-value"><?= $totalPartners['cnt'] ?? 0 ?></div>
            <div class="dashboard-mini-label">Partners</div>
        </div>
    </div>
    <div class="dashboard-mini-stat">
        <div class="dashboard-mini-icon" style="background: var(--accent-cyan); color: white;"><i class="ri-user-star-line"></i></div>
        <div>
            <div class="dashboard-mini-value"><?= $totalEmployees['cnt'] ?? 0 ?></div>
            <div class="dashboard-mini-label">Employees</div>
        </div>
    </div>
</div>

<div class="dashboard-main-grid">
    <section class="dashboard-panel">
        <div class="dashboard-panel-head">
            <h3><i class="ri-book-open-line"></i> <?= clean($activeAccountLabel) ?> Recent Ledger</h3>
            <?php if ($activeBookKey && Auth::hasBookAccess($activeBookKey, 'write')): ?>
                <a href="transactions/new.php?account=<?= clean($activeTab) ?>" class="btn btn-primary btn-sm"><i class="ri-add-line"></i> Entry</a>
            <?php endif; ?>
        </div>
        <div class="dashboard-panel-body">
            <?php if (empty($accountLedger)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📝</div>
                    <h3>No ledger activity yet</h3>
                    <p>First entry add karta ledger ahiya dekhase.</p>
                </div>
            <?php else: ?>
                <?php foreach ($accountLedger as $line): ?>
                    <a href="transactions/view.php?id=<?= $line['entry_id'] ?>" class="dashboard-activity-row">
                        <div class="activity-dot <?= $line['entry_type'] === 'DR' ? 'in' : 'out' ?>">
                            <i class="<?= $line['entry_type'] === 'DR' ? 'ri-arrow-down-line' : 'ri-arrow-up-line' ?>"></i>
                        </div>
                        <div class="activity-info">
                            <strong><?= TXN_TYPES[$line['transaction_type']] ?? clean($line['transaction_type']) ?></strong>
                            <span><?= clean(mb_substr($line['narration'] ?? '', 0, 70)) ?></span>
                        </div>
                        <div class="activity-side">
                            <strong><?= formatAmount($line['amount']) ?></strong>
                            <span><?= clean($line['reference_no']) ?> · <?= formatDate($line['entry_date']) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
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
                            <strong><?= TXN_TYPES[$txn['transaction_type']] ?? clean($txn['transaction_type']) ?></strong>
                            <span><?= clean(mb_substr($txn['narration'] ?? '', 0, 55)) ?></span>
                        </div>
                        <div class="activity-side">
                            <strong><?= clean($txn['reference_no']) ?></strong>
                            <span><?= formatDate($txn['entry_date']) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
