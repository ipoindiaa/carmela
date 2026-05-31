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

// Active tab
$defaultTab = array_key_first($availableTabs);
$activeTab = get('tab', $defaultTab ?: '');
if (!$activeTab || !isset($availableTabs[$activeTab])) {
    $activeTab = $defaultTab ?: '';
}

// Get ledger for the active account tab
$activeAccountId = null;
$activeAccountLabel = $availableTabs[$activeTab]['label'] ?? 'Account';
$activeBookKey = $availableTabs[$activeTab]['book_key'] ?? null;
$activeAccountId = $availableTabs[$activeTab]['account']['id'] ?? null;

// Get recent transactions for the active account
$accountLedger = [];
if ($activeAccountId) {
    $accountLedger = $db->fetchAll(
        "SELECT jl.*, je.entry_date, je.reference_no, je.narration, je.transaction_type, je.id as entry_id
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_entry_id
         WHERE jl.account_id = ? AND je.status = 'POSTED'
         ORDER BY je.entry_date DESC, je.created_at DESC
         LIMIT 20",
        [$activeAccountId]
    );
}

// Stats
$totalCars = $db->fetch("SELECT COUNT(*) as cnt FROM cars WHERE business_id = ? AND status = 'IN_STOCK'", [$businessId]);
$totalSold = $db->fetch("SELECT COUNT(*) as cnt FROM cars WHERE business_id = ? AND status = 'SOLD'", [$businessId]);
$totalPartners = $db->fetch("SELECT COUNT(*) as cnt FROM partners WHERE business_id = ? AND is_active = 1", [$businessId]);
$totalEmployees = $db->fetch("SELECT COUNT(*) as cnt FROM employees WHERE business_id = ? AND is_active = 1", [$businessId]);

// Recent transactions (all accounts)
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
         LIMIT 10",
        array_merge([$businessId], $accessibleAccountIds)
    );
}

// Alerts
$alerts = $db->fetchAll(
    "SELECT * FROM alerts WHERE business_id = ? AND is_resolved = 0 ORDER BY created_at DESC LIMIT 10",
    [$businessId]
);

// Monthly P&L
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
?>

<?php if (!empty($availableTabs)): ?>
    <!-- 3-Account Tab Cards -->
    <div class="account-tabs">
        <?php foreach ($availableTabs as $tabKey => $tab): ?>
            <a href="?tab=<?= $tabKey ?>" class="account-tab <?= $activeTab === $tabKey ? 'active' : '' ?> <?= $tab['class'] ?>">
                <div class="tab-icon"><?= $tab['icon'] ?></div>
                <div class="tab-content">
                    <div class="tab-label"><?= $tab['label'] ?></div>
                    <div class="tab-balance"><?= formatAmount($tab['account']['current_balance'] ?? 0) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Account Ledger for Active Tab -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h3><i class="ri-file-list-3-line"></i> <?= $activeAccountLabel ?> — Recent Activity</h3>
            <?php if ($activeBookKey && Auth::hasBookAccess($activeBookKey, 'write')): ?>
                <a href="transactions/new.php?account=<?= $activeTab ?>" class="btn btn-primary btn-sm"><i class="ri-add-line"></i> New Entry</a>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($accountLedger)): ?>
                <div class="empty-state" style="padding: 40px;">
                    <div class="empty-icon">📝</div>
                    <h3>No Activity Yet</h3>
                    <p>Start by recording your first entry for this account</p>
                    <?php if ($activeBookKey && Auth::hasBookAccess($activeBookKey, 'write')): ?>
                        <a href="transactions/new.php?account=<?= $activeTab ?>" class="btn btn-primary btn-sm"><i class="ri-add-line"></i> New Entry</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Ref</th>
                            <th>Type</th>
                            <th>Narration</th>
                            <th class="text-right">Receipt / Dr</th>
                            <th class="text-right">Payment / Cr</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accountLedger as $line): ?>
                        <tr>
                            <td><?= formatDate($line['entry_date']) ?></td>
                            <td><a href="transactions/view.php?id=<?= $line['entry_id'] ?>" class="text-bold"><?= $line['reference_no'] ?></a></td>
                            <td><span class="badge badge-blue"><?= TXN_TYPES[$line['transaction_type']] ?? $line['transaction_type'] ?></span></td>
                            <td style="max-width: 250px;"><?= clean(mb_substr($line['narration'] ?? '', 0, 50)) ?></td>
                            <td class="text-right"><?= $line['entry_type'] === 'DR' ? formatAmount($line['amount']) : '' ?></td>
                            <td class="text-right"><?= $line['entry_type'] === 'CR' ? formatAmount($line['amount']) : '' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-body">
            <div class="alert alert-info" style="margin: 0;">
                <i class="ri-information-line"></i> No operational books are assigned to your account yet. Ask an admin to grant read or write access to Cash, Bank, or GST books.
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon" style="background: var(--accent-blue-glow); color: var(--accent-blue);"><i class="ri-car-line"></i></div>
        </div>
        <div class="stat-value"><?= $totalCars['cnt'] ?? 0 ?></div>
        <div class="stat-label">Cars In Stock</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon" style="background: var(--accent-green-glow); color: var(--accent-green);"><i class="ri-check-double-line"></i></div>
        </div>
        <div class="stat-value"><?= $totalSold['cnt'] ?? 0 ?></div>
        <div class="stat-label">Cars Sold</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon" style="background: var(--accent-purple-glow); color: var(--accent-purple);"><i class="ri-group-line"></i></div>
        </div>
        <div class="stat-value"><?= $totalPartners['cnt'] ?? 0 ?></div>
        <div class="stat-label">Partners</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon" style="background: var(--accent-yellow-glow); color: var(--accent-yellow);"><i class="ri-line-chart-line"></i></div>
        </div>
        <div class="stat-value"><?= formatAmount(($monthIncome['total'] ?? 0) - ($monthExpense['total'] ?? 0)) ?></div>
        <div class="stat-label">This Month P&L</div>
    </div>
</div>

<div class="grid-2">
    <!-- Recent Transactions -->
    <div class="card">
        <div class="card-header">
            <h3><i class="ri-exchange-line"></i> Recent Transactions</h3>
            <?php if (Auth::hasAnyBookAccess(Auth::getPrimaryBookKeys(), 'read')): ?>
                <a href="transactions/list.php" class="btn btn-sm btn-outline">View All</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($recentTxns)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📝</div>
                    <h3>No Transactions Yet</h3>
                    <p>Start by recording your first entry</p>
                    <?php if (Auth::hasAnyBookAccess(Auth::getPrimaryBookKeys(), 'write')): ?>
                        <a href="transactions/new.php" class="btn btn-primary btn-sm"><i class="ri-add-line"></i> New Entry</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($recentTxns as $txn): 
                    $isIncome = in_array($txn['transaction_type'], ['CAR_SALE','PARTNER_INVEST','LOAN_RECEIVED','LOAN_TAKEN']);
                    $iconClass = $isIncome ? 'in' : ($txn['transaction_type'] === 'CONTRA_TRANSFER' ? 'transfer' : 'out');
                    $icon = $isIncome ? 'ri-arrow-down-line' : ($txn['transaction_type'] === 'CONTRA_TRANSFER' ? 'ri-arrow-left-right-line' : 'ri-arrow-up-line');
                ?>
                <div class="transaction-item">
                    <div class="txn-icon <?= $iconClass ?>"><i class="<?= $icon ?>"></i></div>
                    <div class="txn-info">
                        <div class="txn-type"><?= TXN_TYPES[$txn['transaction_type']] ?? $txn['transaction_type'] ?></div>
                        <div class="txn-narration"><?= clean(mb_substr($txn['narration'] ?? '', 0, 50)) ?></div>
                    </div>
                    <div class="txn-amount">
                        <div class="amount"><?= $txn['reference_no'] ?></div>
                        <div class="txn-date"><?= formatDate($txn['entry_date']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alerts -->
    <div class="card" id="alerts">
        <div class="card-header">
            <h3><i class="ri-notification-3-line"></i> Alerts & Notifications</h3>
            <?php if (!empty($alerts)): ?>
                <span class="badge badge-red"><?= count($alerts) ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($alerts)): ?>
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <h3>All Clear!</h3>
                    <p>No pending alerts or notifications</p>
                </div>
            <?php else: ?>
                <?php foreach ($alerts as $alert): 
                    $severityClass = ['INFO' => 'blue', 'WARNING' => 'yellow', 'CRITICAL' => 'red'][$alert['severity']] ?? 'blue';
                ?>
                <div class="transaction-item">
                    <div class="txn-icon" style="background: var(--accent-<?= $severityClass ?>-glow); color: var(--accent-<?= $severityClass ?>);">
                        <i class="ri-alert-line"></i>
                    </div>
                    <div class="txn-info">
                        <div class="txn-type"><?= ALERT_TYPES[$alert['type']] ?? $alert['type'] ?></div>
                        <div class="txn-narration"><?= clean($alert['message']) ?></div>
                    </div>
                    <div class="txn-amount">
                        <span class="badge badge-<?= $severityClass ?>"><?= $alert['severity'] ?></span>
                        <div class="txn-date"><?= timeAgo($alert['created_at']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
