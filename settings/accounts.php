<?php
$pageTitle = 'Account Settings';
$pageIcon = '<i class="ri-bank-card-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();

$businessId = Auth::user('business_id');
$accountTypes = [
    'CASH' => ['label' => 'Cash Account', 'prefix' => 'CASH', 'icon' => 'ri-wallet-3-line'],
    'BANK' => ['label' => 'Bank Account', 'prefix' => 'BANK', 'icon' => 'ri-bank-line'],
    'GST' => ['label' => 'GST Bank Account', 'prefix' => 'GST', 'icon' => 'ri-file-list-2-line'],
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

            $db->insert('accounts', [
                'id' => Database::uuid(),
                'business_id' => $businessId,
                'code' => $code,
                'name' => $name,
                'group_name' => 'ASSET',
                'sub_group' => 'Current Assets',
                'entity_type' => $type,
                'entity_id' => null,
                'is_active' => 1,
                'opening_balance' => $openingBalance,
                'opening_balance_type' => $openingType,
                'current_balance' => $openingBalance,
                'current_balance_type' => $openingType,
            ]);
            Auth::auditLog('CREATE', 'account', null, "Created {$accountTypes[$type]['label']} $name");
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
                "SELECT * FROM accounts WHERE id = ? AND business_id = ? AND entity_type IN ('CASH','BANK','GST') AND entity_id IS NULL",
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
            Auth::auditLog('UPDATE', 'account', $accountId, "Updated account $name");
            setFlash('success', 'Account updated successfully.');
        }

        redirect('accounts.php');
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$accounts = $db->fetchAll(
    "SELECT *
     FROM accounts
     WHERE business_id = ?
       AND entity_type IN ('CASH','BANK','GST')
       AND entity_id IS NULL
     ORDER BY FIELD(entity_type, 'CASH', 'BANK', 'GST'), is_active DESC, code, name",
    [$businessId]
);
?>

<div class="page-header">
    <div>
        <h1><i class="ri-bank-card-line"></i> Account Settings</h1>
        <p class="text-muted">Manage the business Cash, Bank, and GST Bank accounts used in New Entry.</p>
    </div>
</div>

<div class="card" style="margin-bottom: 18px;">
    <div class="card-header"><h3><i class="ri-add-line"></i> Add Account</h3></div>
    <div class="card-body">
        <form method="POST">
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
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Add Account</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-list-check"></i> Business Accounts</h3></div>
    <div class="card-body" style="padding:0;">
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
                                <form method="POST" id="<?= clean($formId) ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="account_id" value="<?= clean($account['id']) ?>">
                                    <button type="submit" class="btn btn-outline btn-sm"><i class="ri-save-line"></i> Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($accounts)): ?>
                        <tr><td colspan="6" class="text-center text-muted" style="padding: 32px;">No primary accounts found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
