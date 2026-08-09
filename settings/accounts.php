<?php
$pageTitle = 'Account Settings';
$pageIcon = '<i class="ri-bank-card-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/business_data_reset.php';
Auth::requireAdmin();

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$accountTypes = [
    'CASH' => ['label' => 'Cash Account', 'prefix' => 'CASH', 'icon' => 'ri-wallet-3-line'],
    'BANK' => ['label' => 'Bank Account', 'prefix' => 'BANK', 'icon' => 'ri-bank-line'],
];

$nextAccountCode = static function ($type) use ($db, $businessId, $accountTypes) {
    $prefix = $accountTypes[$type]['prefix'];
    $row = $db->fetch(
        "SELECT code
         FROM accounts
         WHERE business_id = ?
           AND entity_type = ?
           AND code LIKE ?
         ORDER BY code DESC
         LIMIT 1",
        [$businessId, $type, $prefix . '-%']
    );
    $next = 1;
    if ($row && preg_match('/-(\d+)$/', $row['code'], $matches)) {
        $next = intval($matches[1]) + 1;
    }
    return $prefix . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    try {
        if ($action === 'clear_database') {
            $resetService = new BusinessDataResetService($businessId, Auth::user('user_id'));
            $result = $resetService->reset(post('current_password'), post('confirmation_phrase'));
            if (!empty($result['file_cleanup_failed'])) {
                setFlash('warning', 'Business data was cleared, but one or more old attachment files could not be removed from storage.');
            }
            setFlash('success', 'All business data was cleared. Your users and business profile were kept, and clean default accounts were recreated.');
        }

        if ($action === 'create') {
            $type = strtoupper(post('entity_type'));
            if (!isset($accountTypes[$type])) {
                throw new Exception('Invalid account type.');
            }

            $name = trim(post('name'));
            if ($name === '') {
                throw new Exception('Account name is required.');
            }

            $code = strtoupper(trim(post('code')));
            if ($code === '') {
                $code = $nextAccountCode($type);
            }
            if (!preg_match('/^[A-Z0-9-]{3,20}$/', $code)) {
                throw new Exception('Account code can use only A-Z, 0-9 and hyphen, up to 20 characters.');
            }

            $exists = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = ?", [$businessId, $code]);
            if ($exists) {
                throw new Exception('That account code already exists.');
            }

            $openingBalance = round(parseDecimalInput(post('opening_balance', 0)), 2);
            $openingType = strtoupper(post('opening_balance_type', 'DR')) === 'CR' ? 'CR' : 'DR';
            $openingDate = post('opening_balance_date', getCurrentFY() . '-04-01');

            $accountId = Database::uuid();

            $db->insert('accounts', [
                'id' => $accountId,
                'business_id' => $businessId,
                'code' => $code,
                'name' => $name,
                'group_name' => 'ASSET',
                'sub_group' => 'Current Assets',
                'entity_type' => $type,
                'entity_id' => null,
                'is_active' => 1,
                'opening_balance' => 0,
                'opening_balance_type' => 'DR',
                'current_balance' => 0,
                'current_balance_type' => 'DR',
            ]);
            if ($openingBalance > 0.009) {
                $engine->setOpeningBalance($accountId, $openingBalance, $openingType, $openingDate, 'Account created with opening balance');
            }
            $createdAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $businessId]);
            Auth::auditCreate('account', $accountId, $createdAccount ?: ['name' => $name, 'code' => $code], "Created {$accountTypes[$type]['label']} $name", 'accounts');
            setFlash('success', 'Account added successfully.');
        }

        if ($action === 'update') {
            $accountId = post('account_id');
            $name = trim(post('name'));
            $isActive = post('is_active', '0') === '1' ? 1 : 0;
            if ($name === '') {
                throw new Exception('Account name is required.');
            }

            $account = $db->fetch(
                "SELECT * FROM accounts WHERE id = ? AND business_id = ? AND entity_type IN ('CASH','BANK') AND entity_id IS NULL",
                [$accountId, $businessId]
            );
            if (!$account) {
                throw new Exception('Account not found.');
            }

            if (!$isActive) {
                $activeCount = $db->fetch(
                    "SELECT COUNT(*) AS cnt FROM accounts WHERE business_id = ? AND entity_type = ? AND entity_id IS NULL AND is_active = 1 AND id <> ?",
                    [$businessId, $account['entity_type'], $accountId]
                );
                if (($activeCount['cnt'] ?? 0) < 1) {
                    throw new Exception('Keep at least one active ' . strtolower($accountTypes[$account['entity_type']]['label']) . '.');
                }
            }

            $db->query(
                "UPDATE accounts SET name = ?, is_active = ? WHERE id = ? AND business_id = ?",
                [$name, $isActive, $accountId, $businessId]
            );
            $updatedAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $businessId]);
            Auth::auditUpdate('account', $accountId, $account, $updatedAccount ?: [], "Updated account $name", 'accounts');
            setFlash('success', 'Account updated successfully.');
        }

        redirect('accounts.php');
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }
}

$accounts = $db->fetchAll(
    "SELECT *
     FROM accounts
     WHERE business_id = ?
       AND entity_type IN ('CASH','BANK')
       AND entity_id IS NULL
     ORDER BY FIELD(entity_type, 'CASH', 'BANK'), is_active DESC, code, name",
    [$businessId]
);
?>

<div class="page-header">
    <div>
        <h1><i class="ri-bank-card-line"></i> Account Settings</h1>
        <p class="text-muted">Manage the business Cash and Bank accounts used in New Entry.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-add-line"></i> Add Account</h3></div>
    <div class="card-body">
        <form method="POST" data-confirm-submit="Create this account and save its opening balance?">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Account Type *</label>
                    <select name="entity_type" class="form-control searchable-select">
                        <?php foreach ($accountTypes as $type => $meta): ?>
                            <option value="<?= clean($type) ?>"><?= clean($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Account Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g., HDFC Current A/c" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Code</label>
                    <input type="text" name="code" class="form-control" placeholder="Auto if blank">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Opening Balance</label>
                    <div class="input-group">
                        <span class="input-prefix">₹</span>
                        <input type="text" name="opening_balance" class="form-control currency-input" value="0" inputmode="decimal" autocomplete="off">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Balance Type</label>
                    <select name="opening_balance_type" class="form-control searchable-select">
                        <option value="DR">DR / Money Available</option>
                        <option value="CR">CR / Overdrawn</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Opening Balance Date</label>
                    <input type="date" name="opening_balance_date" class="form-control" value="<?= clean(getCurrentFY() . '-04-01') ?>" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Add Account</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-list-check"></i> Business Accounts</h3></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th class="text-right">Current Balance</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <?php $formId = 'account-form-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $account['id']); ?>
                        <tr>
                            <td><i class="<?= clean($accountTypes[$account['entity_type']]['icon'] ?? 'ri-bank-card-line') ?>"></i> <?= clean($accountTypes[$account['entity_type']]['label'] ?? $account['entity_type']) ?></td>
                            <td class="text-bold"><?= clean($account['code']) ?></td>
                            <td>
                                <input type="text" name="name" class="form-control" value="<?= clean($account['name']) ?>" form="<?= clean($formId) ?>" required>
                            </td>
                            <td class="text-right amount <?= ($account['current_balance_type'] ?? 'DR') === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></td>
                            <td class="text-center">
                                <select name="is_active" class="form-control" form="<?= clean($formId) ?>">
                                    <option value="1" <?= $account['is_active'] ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= !$account['is_active'] ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </td>
                            <td class="text-center">
                                <form method="POST" id="<?= clean($formId) ?>" data-confirm-submit="Save changes to this account?">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="account_id" value="<?= clean($account['id']) ?>">
                                    <button type="submit" class="btn btn-outline btn-sm"><i class="ri-save-line"></i> Save</button>
                                    <a href="../reports/ledger.php?account_id=<?= clean($account['id']) ?>" class="btn btn-outline btn-sm" title="View ledger"><i class="ri-eye-line"></i></a>
                                    <a href="opening_balances.php?account_id=<?= clean($account['id']) ?>" class="btn btn-outline btn-sm" title="Edit opening balance"><i class="ri-scales-3-line"></i></a>
                                    <a href="../reports/change_history.php?entity_type=account&amp;entity_id=<?= clean($account['id']) ?>" class="btn btn-outline btn-sm" title="Change history"><i class="ri-history-line"></i></a>
                                    <?php if (!empty($account['is_active'])): ?><a href="../delete_record.php?entity_type=account&amp;id=<?= clean($account['id']) ?>" class="btn btn-outline btn-sm text-red" title="Delete account"><i class="ri-delete-bin-line"></i></a><?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($accounts)): ?>
                        <tr><td colspan="6" class="text-center text-muted empty-table-cell">No primary accounts found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card danger-zone">
    <div class="card-header">
        <h3 class="text-red"><i class="ri-alarm-warning-line"></i> Danger Zone</h3>
    </div>
    <div class="card-body danger-zone-content">
        <h4>Clear all business data</h4>
        <p class="text-muted">
            Permanently erase transactions, cars, parties, partners, employees, RTO records, accounts, audit history, alerts, categories, and attachments.
            Your business profile, user logins, and book permissions will be kept. Clean default accounts and the current financial year will be recreated.
        </p>
        <button type="button" class="btn btn-danger" onclick="openModal('clearDatabaseWarningModal')">
            <i class="ri-delete-bin-6-line"></i> Clear Database
        </button>
    </div>
</div>

<div class="modal-overlay" id="clearDatabaseWarningModal" role="dialog" aria-modal="true" aria-labelledby="clearDatabaseWarningTitle">
    <div class="modal">
        <div class="modal-header">
            <h3 id="clearDatabaseWarningTitle"><i class="ri-error-warning-line text-red"></i> Permanent data deletion</h3>
            <button type="button" class="modal-close" onclick="closeModal('clearDatabaseWarningModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-error">
                <i class="ri-alarm-warning-line"></i>
                <span>This action cannot be undone. There is no recovery option inside the application.</span>
            </div>
            <div class="stack stack-sm">
                <p>This will remove all accounting and operational data for <strong><?= clean(Auth::user('business_name')) ?></strong>, including uploaded attachments.</p>
                <p class="text-muted">Only the business profile, user logins, and their permissions will remain.</p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('clearDatabaseWarningModal')">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="closeModal('clearDatabaseWarningModal'); openModal('clearDatabasePasswordModal'); document.getElementById('clearDatabasePassword').focus();">
                I understand, continue
            </button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="clearDatabasePasswordModal" role="dialog" aria-modal="true" aria-labelledby="clearDatabasePasswordTitle">
    <div class="modal">
        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="clear_database">
            <div class="modal-header">
                <h3 id="clearDatabasePasswordTitle"><i class="ri-lock-password-line text-red"></i> Confirm database reset</h3>
                <button type="button" class="modal-close" onclick="closeModal('clearDatabasePasswordModal')" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="clearDatabasePassword">Your current password *</label>
                    <input type="password" id="clearDatabasePassword" name="current_password" class="form-control" autocomplete="current-password" required>
                </div>
                <div class="form-group form-group-last">
                    <label class="form-label" for="clearDatabasePhrase">Type CLEAR to confirm *</label>
                    <input type="text" id="clearDatabasePhrase" name="confirmation_phrase" class="form-control" placeholder="CLEAR" pattern="CLEAR" autocomplete="off" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('clearDatabasePasswordModal')">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="ri-delete-bin-6-line"></i> Permanently Clear Data</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
