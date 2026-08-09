<?php
$pageTitle = 'Custom Entry Types';
$pageIcon = '<i class="ri-price-tag-3-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();

$businessId = Auth::user('business_id');
$categoryGroups = [
    'in' => [
        'label' => 'Receive / Money In',
        'group_name' => 'INCOME',
        'sub_group' => 'Daily Jama Categories',
        'code_prefix' => 'INC',
        'icon' => 'ri-arrow-down-circle-line',
    ],
    'out' => [
        'label' => 'Payment / Money Out',
        'group_name' => 'EXPENSE',
        'sub_group' => 'Daily Udhar Categories',
        'code_prefix' => 'EXP',
        'icon' => 'ri-arrow-up-circle-line',
    ],
];
$systemCodes = ['CAR-REV', 'PNL', 'BAD-DEBT', 'ADV-WOFF', 'SAL-EXP'];
$categoryWhereSql = "business_id = ?
       AND entity_type = 'GENERAL'
       AND group_name IN ('INCOME','EXPENSE')
       AND sub_group IN ('Daily Jama Categories','Daily Udhar Categories')
       AND code NOT IN (" . implode(',', array_fill(0, count($systemCodes), '?')) . ")";

$nextCategoryCode = static function ($direction) use ($db, $businessId, $categoryGroups) {
    $prefix = $categoryGroups[$direction]['code_prefix'];
    $row = $db->fetch(
        "SELECT code
         FROM accounts
         WHERE business_id = ?
           AND code LIKE ?
         ORDER BY code DESC
         LIMIT 1",
        [$businessId, $prefix . '-%']
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
            $direction = strtolower(post('direction'));
            if (!isset($categoryGroups[$direction])) {
                throw new Exception('Invalid entry direction.');
            }

            $name = trim(post('name'));
            if ($name === '') {
                throw new Exception('Entry type name is required.');
            }

            $code = strtoupper(trim(post('code')));
            if ($code === '') {
                $code = $nextCategoryCode($direction);
            }
            if (!preg_match('/^[A-Z0-9-]{3,20}$/', $code)) {
                throw new Exception('Code can use only A-Z, 0-9 and hyphen, up to 20 characters.');
            }

            $exists = $db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = ?", [$businessId, $code]);
            if ($exists) {
                throw new Exception('That entry type code already exists.');
            }

            $meta = $categoryGroups[$direction];
            $accountId = Database::uuid();
            $db->insert('accounts', [
                'id' => $accountId,
                'business_id' => $businessId,
                'code' => $code,
                'name' => $name,
                'group_name' => $meta['group_name'],
                'sub_group' => $meta['sub_group'],
                'entity_type' => 'GENERAL',
                'entity_id' => null,
                'is_active' => 1,
                'opening_balance' => 0,
                'opening_balance_type' => $direction === 'in' ? 'CR' : 'DR',
                'current_balance' => 0,
                'current_balance_type' => $direction === 'in' ? 'CR' : 'DR',
            ]);
            $createdCategory = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $businessId]);
            Auth::auditCreate('account', $accountId, $createdCategory ?: ['name' => $name, 'code' => $code], "Created {$meta['label']} entry type $name", 'categories');
            setFlash('success', 'Custom entry type added successfully.');
        }

        if ($action === 'update') {
            $accountId = post('account_id');
            $name = trim(post('name'));
            $isActive = post('is_active', '0') === '1' ? 1 : 0;
            if ($name === '') {
                throw new Exception('Entry type name is required.');
            }

            $account = $db->fetch(
                "SELECT * FROM accounts
                 WHERE id = ?
                   AND $categoryWhereSql",
                array_merge([$accountId, $businessId], $systemCodes)
            );
            if (!$account) {
                throw new Exception('Custom entry type not found.');
            }

            $db->query(
                "UPDATE accounts SET name = ?, is_active = ? WHERE id = ? AND business_id = ?",
                [$name, $isActive, $accountId, $businessId]
            );
            $updatedCategory = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $businessId]);
            Auth::auditUpdate('account', $accountId, $account, $updatedCategory ?: [], "Updated custom entry type $name", 'categories');
            setFlash('success', 'Custom entry type updated successfully.');
        }

        if ($action === 'delete') {
            $accountId = post('account_id');
            $account = $db->fetch(
                "SELECT * FROM accounts
                 WHERE id = ?
                   AND $categoryWhereSql",
                array_merge([$accountId, $businessId], $systemCodes)
            );
            if (!$account) {
                throw new Exception('Custom entry type not found.');
            }

            $usage = $db->fetch("SELECT COUNT(*) AS cnt FROM journal_lines WHERE account_id = ?", [$accountId]);
            if (($usage['cnt'] ?? 0) > 0) {
                throw new Exception('This entry type has transactions connected to it, so it cannot be deleted. Mark it inactive instead.');
            }

            $db->query("DELETE FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $businessId]);
            Auth::auditLog('DELETE', 'account', $accountId, "Deleted custom entry type {$account['name']}", $account, null, 'categories');
            setFlash('success', 'Custom entry type deleted successfully.');
        }

        redirect('categories.php');
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$categories = $db->fetchAll(
    "SELECT a.*,
            COALESCE(usage_stats.line_count, 0) AS linked_entries
     FROM accounts a
     LEFT JOIN (
        SELECT account_id, COUNT(*) AS line_count
        FROM journal_lines
        GROUP BY account_id
     ) usage_stats ON usage_stats.account_id = a.id
     WHERE a.business_id = ?
       AND a.entity_type = 'GENERAL'
       AND a.group_name IN ('INCOME','EXPENSE')
       AND a.sub_group IN ('Daily Jama Categories','Daily Udhar Categories')
       AND a.code NOT IN (" . implode(',', array_fill(0, count($systemCodes), '?')) . ")
     ORDER BY FIELD(a.group_name, 'INCOME', 'EXPENSE'), a.is_active DESC, a.code, a.name",
    array_merge([$businessId], $systemCodes)
);
?>

<div class="page-header">
    <div>
        <h1><i class="ri-price-tag-3-line"></i> Custom Entry Types</h1>
        <p class="text-muted">Add a distinct money-in or money-out type only when the predefined entry types do not fit.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-add-line"></i> Add Custom Entry Type</h3></div>
    <div class="card-body">
        <form method="POST" data-confirm-submit="Create this custom entry type?">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Type *</label>
                    <select name="direction" class="form-control searchable-select">
                        <option value="out">Payment / Money Out</option>
                        <option value="in">Receive / Money In</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Entry Type Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g., Fuel or Interest Income" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Code</label>
                    <input type="text" name="code" class="form-control" placeholder="Auto if blank">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Add Entry Type</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-list-check"></i> Custom Money In / Money Out Types</h3></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th class="text-right">Current Total</th>
                        <th class="text-center">Entries</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                        <?php
                            $direction = $category['group_name'] === 'INCOME' ? 'in' : 'out';
                            $meta = $categoryGroups[$direction];
                            $formId = 'category-form-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $category['id']);
                        ?>
                        <tr>
                            <td><i class="<?= clean($meta['icon']) ?>"></i> <?= clean($meta['label']) ?></td>
                            <td class="text-bold"><?= clean($category['code']) ?></td>
                            <td><input type="text" name="name" class="form-control" value="<?= clean($category['name']) ?>" form="<?= clean($formId) ?>" required></td>
                            <td class="text-right amount <?= $direction === 'in' ? 'credit-amount' : 'debit-amount' ?>"><?= formatAmount($category['current_balance']) ?> <?= clean($category['current_balance_type']) ?></td>
                            <td class="text-center"><?= intval($category['linked_entries'] ?? 0) ?></td>
                            <td class="text-center">
                                <select name="is_active" class="form-control" form="<?= clean($formId) ?>">
                                    <option value="1" <?= $category['is_active'] ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= !$category['is_active'] ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </td>
                            <td class="text-center">
                                <form method="POST" id="<?= clean($formId) ?>" class="inline-form" data-confirm-submit="Save this entry type change?">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="account_id" value="<?= clean($category['id']) ?>">
                                    <button type="submit" class="btn btn-outline btn-sm"><i class="ri-save-line"></i> Save</button>
                                </form>
                                <a href="../reports/ledger.php?account_id=<?= clean($category['id']) ?>" class="btn btn-outline btn-sm" title="View ledger"><i class="ri-eye-line"></i></a>
                                <a href="../reports/entry_types.php?entry_type_id=<?= urlencode(customEntryTypeId($category['id'])) ?>#entry-type-details" class="btn btn-outline btn-sm" title="View entry type summary"><i class="ri-bar-chart-box-line"></i></a>
                                <a href="../reports/change_history.php?entity_type=account&amp;entity_id=<?= clean($category['id']) ?>" class="btn btn-outline btn-sm" title="Change history"><i class="ri-history-line"></i></a>
                                <?php if (intval($category['linked_entries'] ?? 0) === 0): ?>
                                    <form method="POST" class="inline-form" data-confirm="Delete this entry type? This is allowed only because no transactions are connected.">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="account_id" value="<?= clean($category['id']) ?>">
                                        <button type="submit" class="btn btn-outline btn-sm text-red"><i class="ri-delete-bin-line"></i> Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-gray">Used</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="7" class="text-center text-muted empty-table-cell">No custom entry types yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
