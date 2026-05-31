<?php
$pageTitle = 'Debtors & Creditors';
$pageIcon = '<i class="ri-contacts-book-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'add') {
    verifyCsrf();
    try {
        $engine = new AccountingEngine($businessId, Auth::user('user_id'));
        $partyId = $engine->getOrCreateParty(post('name'), post('type'));
        $db->query("UPDATE debtors_creditors SET phone = ?, email = ?, address = ?, pan_gstin = ? WHERE id = ?",
            [post('phone'), post('email'), post('address'), post('pan_gstin'), $partyId]);
        setFlash('success', 'Party added!');
        redirect('list.php');
    } catch (Exception $e) { setFlash('error', $e->getMessage()); }
}

$parties = $db->fetchAll(
    "SELECT dc.*, a.current_balance, a.current_balance_type FROM debtors_creditors dc 
     LEFT JOIN accounts a ON a.id = dc.account_id WHERE dc.business_id = ? ORDER BY dc.type, dc.name", [$businessId]);
?>

<div class="page-header">
    <h1><i class="ri-contacts-book-line"></i> Debtors & Creditors</h1>
    <button onclick="openModal('add-party')" class="btn btn-primary"><i class="ri-add-line"></i> Add Party</button>
</div>

<div class="table-container">
    <table>
        <thead><tr><th>Name</th><th>Type</th><th>Phone</th><th class="text-right">Balance</th><th>Bad Debt</th><th class="text-center">Actions</th></tr></thead>
        <tbody>
            <?php foreach ($parties as $p): ?>
            <tr>
                <td class="text-bold"><?= clean($p['name']) ?></td>
                <td><span class="badge <?= in_array($p['type'], ['DEBTOR','BUYER']) ? 'badge-blue' : 'badge-yellow' ?>"><?= $p['type'] ?></span></td>
                <td><?= clean($p['phone'] ?: '-') ?></td>
                <td class="text-right amount"><?= formatAmount($p['current_balance'] ?? 0) ?></td>
                <td><?= $p['is_bad_debt'] ? '<span class="badge badge-red">Bad Debt</span>' : '-' ?></td>
                <td class="text-center"><a href="view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($parties)): ?><tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">No parties yet</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="modal-overlay" id="add-party">
    <div class="modal">
        <div class="modal-header"><h3>Add Party</h3><button class="modal-close" onclick="closeModal('add-party')">×</button></div>
        <div class="modal-body">
            <form method="POST">
                <?= csrfField() ?><input type="hidden" name="action" value="add">
                <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-control" required><option value="DEBTOR">Debtor</option><option value="CREDITOR">Creditor</option><option value="BUYER">Buyer</option><option value="SELLER">Seller</option></select></div>
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                    <div class="form-group"><label class="form-label">PAN / GSTIN</label><input type="text" name="pan_gstin" class="form-control"></div>
                </div>
                <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ri-save-line"></i> Add Party</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
