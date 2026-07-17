<?php
$pageTitle = 'Opening Balances';
$pageIcon = '<i class="ri-scales-3-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
Auth::requireAdmin();

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$selectedAccountId = get('account_id', '');
$returnContext = get('return', '') === 'rto' ? 'rto' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $selectedAccountId = post('account_id');
        $returnContext = post('return_context', '') === 'rto' ? 'rto' : '';
        $account = $db->fetch(
            "SELECT id, name FROM accounts WHERE id = ? AND business_id = ? AND code <> 'OB-EQUITY'",
            [$selectedAccountId, $businessId]
        );
        if (!$account) {
            throw new Exception('Account not found.');
        }

        $amount = round(parseDecimalInput(post('opening_balance', 0)), 2);
        $type = strtoupper(post('opening_balance_type', 'DR')) === 'CR' ? 'CR' : 'DR';
        $date = post('opening_balance_date');
        $reason = trim((string) post('reason'));
        $engine->setOpeningBalance($selectedAccountId, $amount, $type, $date, $reason);
        setFlash('success', 'Opening balance updated for ' . $account['name'] . '.');
        $redirectParams = ['account_id' => $selectedAccountId];
        if ($returnContext === 'rto') $redirectParams['return'] = 'rto';
        redirect('opening_balances.php?' . http_build_query($redirectParams));
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$accounts = $db->fetchAll(
    "SELECT a.*,
            CASE a.entity_type
                WHEN 'CASH' THEN 'Cash'
                WHEN 'BANK' THEN 'Bank'
                WHEN 'PARTNER' THEN 'Partner'
                WHEN 'EMPLOYEE' THEN 'Employee'
                WHEN 'DEBTOR' THEN 'Debtor'
                WHEN 'CREDITOR' THEN 'Creditor'
                WHEN 'CAR' THEN 'Car'
                ELSE a.group_name
            END AS account_area
     FROM accounts a
     WHERE a.business_id = ?
       AND a.group_name IN ('ASSET', 'LIABILITY', 'EQUITY', 'CONTRA')
       AND a.code <> 'OB-EQUITY'
     ORDER BY FIELD(a.entity_type, 'CASH', 'BANK', 'DEBTOR', 'CREDITOR', 'PARTNER', 'EMPLOYEE', 'CAR', 'GENERAL'), a.name",
    [$businessId]
);

$selectedAccount = null;
foreach ($accounts as $account) {
    if ($account['id'] === $selectedAccountId) {
        $selectedAccount = $account;
        break;
    }
}
$defaultOpeningDate = $selectedAccount['opening_balance_date'] ?? (getCurrentFY() . '-04-01');
$isRtoOpening = ($selectedAccount['code'] ?? '') === 'RTO-OPEN';
?>

<div class="page-header">
    <div>
        <h1><i class="ri-scales-3-line"></i> Opening Balances</h1>
        <div class="text-muted">Set the dated starting balance for cash, bank, parties, partners, employees, cars, and other balance-sheet accounts.</div>
    </div>
    <div class="page-actions">
        <?php if ($returnContext === 'rto'): ?><a href="../rto/list.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back to RTO Book</a><?php endif; ?>
        <a href="accounts.php" class="btn btn-outline"><i class="ri-bank-card-line"></i> Account Settings</a>
    </div>
</div>

<div class="card" style="margin-bottom:18px;">
    <div class="card-header"><h3><i class="ri-edit-2-line"></i> Add or Update Opening Balance</h3></div>
    <div class="card-body">
        <form method="POST" data-confirm-submit="Update this opening balance? The previous opening entry will be reversed and retained in history.">
            <?= csrfField() ?>
            <input type="hidden" name="return_context" value="<?= clean($returnContext) ?>">
            <?php if ($isRtoOpening): ?>
                <div class="alert alert-info">
                    <i class="ri-information-line"></i>
                    <div><strong>This amount belongs to the RTO Book, not to any car.</strong><span>Use DR for old RTO money available or receivable. Use CR for an old RTO amount payable.</span></div>
                </div>
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Account *</label>
                    <select name="account_id" class="form-control searchable-select" required onchange="window.location='opening_balances.php?account_id='+encodeURIComponent(this.value)<?= $returnContext === 'rto' ? "+'&return=rto'" : '' ?>">
                        <option value="">Select account</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= clean($account['id']) ?>" <?= $selectedAccountId === $account['id'] ? 'selected' : '' ?>><?= clean($account['name']) ?> (<?= clean($account['code']) ?>) - <?= clean($account['account_area']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Opening Balance Date *</label>
                    <input type="date" name="opening_balance_date" class="form-control" value="<?= clean($defaultOpeningDate) ?>" required>
                </div>
            </div>
            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Opening Balance *</label>
                    <div class="input-group"><span class="input-prefix">₹</span><input type="text" name="opening_balance" class="form-control currency-input" value="<?= clean($selectedAccount['opening_balance'] ?? '0') ?>" inputmode="decimal" required></div>
                    <div class="form-hint">Enter 0 to clear the opening balance.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Balance Type *</label>
                    <select name="opening_balance_type" class="form-control searchable-select">
                        <option value="DR" <?= ($selectedAccount['opening_balance_type'] ?? 'DR') === 'DR' ? 'selected' : '' ?>>DR / Amount receivable or available</option>
                        <option value="CR" <?= ($selectedAccount['opening_balance_type'] ?? 'DR') === 'CR' ? 'selected' : '' ?>>CR / Amount payable or overdrawn</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason / Note</label>
                    <input type="text" name="reason" class="form-control" placeholder="e.g., Opening as per audited books">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" <?= !$selectedAccount ? 'disabled' : '' ?>><i class="ri-save-line"></i> Update Opening Balance</button>
        </form>
    </div>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Area</th><th>Code</th><th>Account</th><th>Opening Date</th><th class="text-right">Opening Balance</th><th class="text-right">Current Balance</th><th class="text-center">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $account): ?>
            <tr>
                <td><span class="badge badge-blue"><?= clean($account['account_area']) ?></span></td>
                <td class="text-bold"><?= clean($account['code']) ?></td>
                <td><?= clean($account['name']) ?></td>
                <td><?= !empty($account['opening_balance_date']) ? formatDate($account['opening_balance_date']) : '-' ?></td>
                <td class="text-right amount <?= ($account['opening_balance_type'] ?? 'DR') === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($account['opening_balance']) ?> <?= clean($account['opening_balance_type']) ?></td>
                <td class="text-right amount <?= ($account['current_balance_type'] ?? 'DR') === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></td>
                <td class="text-center">
                    <a href="?account_id=<?= clean($account['id']) ?>" class="btn btn-outline btn-sm" title="Edit opening balance"><i class="ri-edit-line"></i></a>
                    <a href="../reports/ledger.php?account_id=<?= clean($account['id']) ?>" class="btn btn-outline btn-sm" title="View ledger"><i class="ri-eye-line"></i></a>
                    <a href="../reports/change_history.php?entity_type=account&amp;entity_id=<?= clean($account['id']) ?>" class="btn btn-outline btn-sm" title="Change history"><i class="ri-history-line"></i></a>
                    <?php if (!empty($account['opening_entry_id']) || floatval($account['opening_balance']) > 0.009): ?><a href="../delete_record.php?entity_type=opening_balance&amp;id=<?= clean($account['id']) ?>" class="btn btn-outline btn-sm text-red" title="Delete opening balance"><i class="ri-delete-bin-line"></i></a><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
