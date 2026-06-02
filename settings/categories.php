<?php
$pageTitle = 'Entry Categories';
$pageIcon = '<i class="ri-price-tag-3-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();

$businessId = Auth::user('business_id');
$categoryGroups = [
    'in' => [
        'label' => 'Jama / Money In',
        'group_name' => 'INCOME',
        'sub_group' => 'Daily Jama Categories',
        'code_prefix' => 'INC',
        'icon' => 'ri-arrow-down-circle-line',
    ],
    'out' => [
        'label' => 'Udhar / Money Out',
        'group_name' => 'EXPENSE',
        'sub_group' => 'Daily Udhar Categories',
        'code_prefix' => 'EXP',
        'icon' => 'ri-arrow-up-circle-line',
    ],
];

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
                throw new Exception('Invalid category direction.');
            }

            $name = trim(post('name'));
            if ($name === '') {
                throw new Exception('Category name is required.');
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
                throw new Exception('That category code already exists.');
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
            Auth::auditLog('CREATE', 'account', $accountId, "Created {$meta['label']} category $name");
            setFlash('success', 'Category added successfully.');
        }

        if ($action === 'update') {
            $accountId = post('account_id');
            $name = trim(post('name'));
            $isActive = post('is_active', '0') === '1' ? 1 : 0;
            if ($name === '') {
                throw new Exception('Category name is required.');
            }

            $subGroups = array_column($categoryGroups, 'sub_group');
            $account = $db->fetch(
                "SELECT * FROM accounts WHERE id = ? AND business_id = ? AND entity_type = 'GENERAL' AND sub_group IN (?, ?)",
                [$accountId, $businessId, $subGroups[0], $subGroups[1]]
            );
            if (!$account) {
                throw new Exception('Category not found.');
            }

            $db->query(
                "UPDATE accounts SET name = ?, is_active = ? WHERE id = ? AND business_id = ?",
                [$name, $isActive, $accountId, $businessId]
            );
            Auth::auditLog('UPDATE', 'account', $accountId, "Updated category $name");
            setFlash('success', 'Category updated successfully.');
        }

        redirect('categories.php');
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$categories = $db->fetchAll(
    "SELECT *
     FROM accounts
     WHERE business_id = ?
       AND entity_type = 'GENERAL'
       AND sub_group IN (?, ?)
     ORDER BY FIELD(sub_group, ?, ?), is_active DESC, code, name",
    [
        $businessId,
        $categoryGroups['in']['sub_group'],
        $categoryGroups['out']['sub_group'],
        $categoryGroups['in']['sub_group'],
        $categoryGroups['out']['sub_group'],
    ]
);
?>

<div class="page-header">
    <div>
        <h1><i class="ri-price-tag-3-line"></i> Entry Categories</h1>
        <p class="text-muted">Create Jama and Udhar categories. Each category is its own ledger account for analysis.</p>
    </div>
</div>

<div class="card" style="margin-bottom: 18px;">
    <div class="card-header"><h3><i class="ri-add-line"></i> Add Category</h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Type *</label>
                    <select name="direction" class="form-control searchable-select">
                        <option value="out">Red / Udhar / Money Out</option>
                        <option value="in">Green / Jama / Money In</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Category Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g., Fuel, Commission, Interest Income" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Code</label>
                    <input type="text" name="code" class="form-control" placeholder="Auto if blank">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Add Category</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-list-check"></i> Jama / Udhar Categories</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-container table-container-inline">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th class="text-right">Current Total</th>
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
                            <td class="text-center">
                                <select name="is_active" class="form-control" form="<?= clean($formId) ?>">
                                    <option value="1" <?= $category['is_active'] ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= !$category['is_active'] ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </td>
                            <td class="text-center">
                                <form method="POST" id="<?= clean($formId) ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="account_id" value="<?= clean($category['id']) ?>">
                                    <button type="submit" class="btn btn-outline btn-sm"><i class="ri-save-line"></i> Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="6" class="text-center text-muted" style="padding: 32px;">No Jama or Udhar categories yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
