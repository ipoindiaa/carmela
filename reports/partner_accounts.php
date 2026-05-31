<?php
$pageTitle = 'Partner Accounts';
$pageIcon = '<i class="ri-group-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('partner_accounts', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$partners = $db->fetchAll("SELECT * FROM partners WHERE business_id = ? ORDER BY is_active DESC, name", [$businessId]);
?>

<div class="page-header">
    <h1><i class="ri-group-2-line"></i> Partner Accounts</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="table-container">
    <table>
        <thead><tr><th>Partner</th><th class="text-right">Capital</th><th class="text-right">Current A/c</th><th class="text-right">Committed Funding</th><th class="text-right">Pending Payable</th><th class="text-right">Pending Receivable</th><th class="text-center">Action</th></tr></thead>
        <tbody>
            <?php if (empty($partners)): ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding: 32px;">No partners found.</td></tr>
            <?php else: ?>
                <?php foreach ($partners as $partner): ?>
                    <?php $position = $engine->getPartnerPosition($partner['id']); ?>
                    <tr>
                        <td class="text-bold"><?= clean($partner['name']) ?></td>
                        <td class="text-right amount"><?= formatAmount($position['capital_balance'] ?? 0) ?></td>
                        <td class="text-right amount"><?= formatAmount($position['current_balance'] ?? 0) ?></td>
                        <td class="text-right amount"><?= formatAmount($position['committed_funding'] ?? 0) ?></td>
                        <td class="text-right amount"><?= formatAmount($position['pending_payable'] ?? 0) ?></td>
                        <td class="text-right amount"><?= formatAmount($position['pending_receivable'] ?? 0) ?></td>
                        <td class="text-center"><a href="../partners/view.php?id=<?= $partner['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
