<?php
$pageTitle = 'End-of-Day Cash Reconciliation';
$pageIcon = '<i class="ri-bank-card-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('cash_book', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

$cashAccounts = $db->fetchAll(
    "SELECT * FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND entity_id IS NULL AND is_active = 1 ORDER BY code, name",
    [$businessId]
);
$selectedAccountId = get('account_id', $cashAccounts[0]['id'] ?? '');
$cashAccount = null;
foreach ($cashAccounts as $account) {
    if ($account['id'] === $selectedAccountId) { $cashAccount = $account; break; }
}

$bookBalance = $cashAccount ? $engine->getCashBookSignedBalance($cashAccount['id']) : 0.0;
$history = $cashAccount ? $engine->getCashReconciliationHistory($cashAccount['id']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    Auth::requireBookAccess('cash_book', 'write');
    try {
        $result = $engine->reconcileCash(
            post('cash_account_id'),
            post('counted_amount'),
            post('recon_date'),
            post('reason')
        );
        if (abs($result['shortage']) > 0.01) {
            setFlash('warning', "Cash reconciled. Shortage of " . formatAmount($result['shortage']) . " recorded as an expense (owner approved).");
        } elseif (abs($result['surplus']) > 0.01) {
            setFlash('success', "Cash reconciled. Surplus of " . formatAmount($result['surplus']) . " recorded as income.");
        } else {
            setFlash('success', 'Cash reconciled exactly. Book balance matches the physical count.');
        }
        redirect('cash_reconciliation.php?account_id=' . urlencode(post('cash_account_id')));
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        redirect('cash_reconciliation.php?account_id=' . urlencode(post('cash_account_id')));
    }
}
?>

<div class="page-header">
    <h1><i class="ri-bank-card-line"></i> End-of-Day Cash Reconciliation</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET">
        <div class="filter-main-field">
            <label class="form-label">Cash Account</label>
            <select name="account_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($cashAccounts as $account): ?>
                    <option value="<?= clean($account['id']) ?>" <?= $account['id'] === $selectedAccountId ? 'selected' : '' ?>><?= clean($account['name']) ?> (<?= clean($account['code']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if (!$cashAccount): ?>
    <div class="alert alert-info"><i class="ri-information-line"></i> No cash account found. Create a cash account first.</div>
<?php else: ?>

<div class="card" style="max-width: 640px; margin-top: 20px;">
    <div class="card-header"><h3><i class="ri-bank-card-line"></i> Today's Cash Count</h3><div class="card-header-note">Compare the book balance with physical cash in the drawer.</div></div>
    <div class="card-body">
        <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: 16px;">
            <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($bookBalance) ?></div><div class="stat-label">Book Balance</div></div>
            <div class="stat-card"><div class="stat-value flow-neutral"><?= clean($cashAccount['name']) ?></div><div class="stat-label">Cash Account</div></div>
        </div>

        <form method="POST" data-confirm-submit="Save this end-of-day cash count? If it differs from the book balance, an entry will be posted and must be approved by the owner.">
            <?= csrfField() ?>
            <input type="hidden" name="cash_account_id" value="<?= clean($selectedAccountId) ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Counted Cash (₹) *</label>
                    <div class="input-group"><span class="input-prefix">₹</span>
                        <input type="text" name="counted_amount" class="form-control currency-input" placeholder="Physical cash count" inputmode="decimal" autocomplete="off" required>
                    </div>
                    <div class="form-hint">Type the cash physically present at closing.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Count Date *</label>
                    <input type="date" name="recon_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Reason (if different from book)</label>
                <input type="text" name="reason" class="form-control" placeholder="e.g., ₹500 went to petty expenses not recorded yet">
                <div class="form-hint">Required only when the count differs. A difference also needs owner (admin) approval.</div>
            </div>
            <div class="form-actions form-actions-start">
                <button type="submit" class="btn btn-primary"><i class="ri-check-double-line"></i> Reconcile Cash</button>
                <?php if (abs($bookBalance) > 0.01): ?>
                    <span class="text-muted" style="align-self:center;">Book says <?= formatAmount($bookBalance) ?></span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-header"><h3><i class="ri-history-line"></i> Reconciliation History</h3></div>
    <div class="card-body card-body-flush table-container">
        <table>
            <thead><tr><th>Date</th><th>Book</th><th class="text-right">Counted</th><th class="text-right">Shortage</th><th class="text-right">Surplus</th><th>Status</th><th>Entry</th><th>Reason</th><th>Approved By</th></tr></thead>
            <tbody>
            <?php if (empty($history)): ?>
                <tr><td colspan="9" class="text-center text-muted empty-table-cell">No reconciliations recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($history as $recon): ?>
                <?php $st = strtoupper((string) $recon['status']); ?>
                <tr>
                    <td><?= formatDate($recon['recon_date']) ?></td>
                    <td class="text-right amount"><?= formatAmount($recon['book_balance']) ?></td>
                    <td class="text-right amount"><?= formatAmount($recon['counted_amount']) ?></td>
                    <td class="text-right amount flow-out"><?= floatval($recon['shortage']) > 0 ? formatAmount($recon['shortage']) : '-' ?></td>
                    <td class="text-right amount flow-in"><?= floatval($recon['surplus']) > 0 ? formatAmount($recon['surplus']) : '-' ?></td>
                    <td><span class="badge <?= $st === 'SHORTAGE' ? 'badge-red' : ($st === 'SURPLUS' ? 'badge-green' : 'badge-blue') ?>"><?= clean($recon['status']) ?></span></td>
                    <td><?= !empty($recon['journal_entry_id']) ? '<a href="../transactions/view.php?id=' . urlencode($recon['journal_entry_id']) . '">View</a>' : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-muted"><?= clean($recon['reason'] ?: '-') ?></td>
                    <td><?= clean($recon['created_by_name'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
