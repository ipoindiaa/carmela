<?php
$pageTitle = 'Partners';
$pageIcon = '<i class="ri-group-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'add') {
    verifyCsrf();
    try {
        $engine = new AccountingEngine($businessId, Auth::user('user_id'));
        $partnerId = Database::uuid();
        $name = post('name');
        $capitalAccId = $engine->createAccount('CAP-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 8)), "$name - Capital A/c", 'EQUITY', 'Capital Accounts', 'PARTNER', $partnerId);
        $currentAccId = $engine->createAccount('CUR-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 8)), "$name - Current A/c", 'LIABILITY', 'Current Liabilities', 'PARTNER', $partnerId);

        $db->insert('partners', [
            'id' => $partnerId, 'business_id' => $businessId, 'name' => $name,
            'phone' => post('phone'), 'email' => post('email'), 'pan' => post('pan'),
            'profit_share_pct' => floatval(post('profit_share_pct', 0)),
            'capital_account_id' => $capitalAccId, 'current_account_id' => $currentAccId,
            'joined_date' => post('joined_date'),
        ]);
        setFlash('success', "Partner $name added successfully!");
        redirect('list.php');
    } catch (Exception $e) { setFlash('error', $e->getMessage()); }
}

$partners = $db->fetchAll(
    "SELECT p.*, a.current_balance as capital_balance, a.current_balance_type 
     FROM partners p LEFT JOIN accounts a ON a.id = p.capital_account_id 
     WHERE p.business_id = ? ORDER BY p.name", [$businessId]);
?>

<div class="page-header">
    <h1><i class="ri-group-line"></i> Partners</h1>
    <button onclick="openModal('add-partner')" class="btn btn-primary"><i class="ri-add-line"></i> Add Partner</button>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Name</th><th>Phone</th><th>PAN</th><th>Profit Share</th><th class="text-right">Capital Balance</th><th>Joined</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr></thead>
        <tbody>
            <?php if (empty($partners)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding: 40px;">No partners yet</td></tr>
            <?php else: ?>
                <?php foreach ($partners as $p): ?>
                <tr>
                    <td class="text-bold"><?= clean($p['name']) ?></td>
                    <td><?= clean($p['phone'] ?: '-') ?></td>
                    <td><?= clean($p['pan'] ?: '-') ?></td>
                    <td><span class="badge badge-purple"><?= $p['profit_share_pct'] ?>%</span></td>
                    <td class="text-right amount"><?= formatAmount($p['capital_balance'] ?? 0) ?></td>
                    <td><?= formatDate($p['joined_date']) ?></td>
                    <td class="text-center"><span class="badge <?= $p['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-center"><a href="view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Partner Modal -->
<div class="modal-overlay" id="add-partner">
    <div class="modal">
        <div class="modal-header"><h3>Add Partner</h3><button class="modal-close" onclick="closeModal('add-partner')">×</button></div>
        <div class="modal-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">PAN</label><input type="text" name="pan" class="form-control" maxlength="10"></div>
                    <div class="form-group"><label class="form-label">Profit Share %</label><input type="number" name="profit_share_pct" class="form-control" value="0" step="0.01" min="0" max="100"></div>
                </div>
                <div class="form-group"><label class="form-label">Joined Date *</label><input type="date" name="joined_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ri-save-line"></i> Add Partner</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
