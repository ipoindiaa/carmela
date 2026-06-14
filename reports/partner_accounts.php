<?php
$pageTitle = 'Partner Accounts';
$pageIcon = '<i class="ri-group-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('partner_accounts', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$search = trim((string) get('q', ''));
$partnerSql = "SELECT * FROM partners WHERE business_id = ?";
$partnerParams = [$businessId];
if ($search !== '') {
    $partnerSql .= " AND name LIKE ?";
    $partnerParams[] = '%' . $search . '%';
}
$partnerSql .= " ORDER BY partner_type, is_active DESC, name";
$partners = $db->fetchAll($partnerSql, $partnerParams);
$mainPartners = array_values(array_filter($partners, static fn($partner) => ($partner['partner_type'] ?? 'MAIN') === 'MAIN'));
$carWisePartners = array_values(array_filter($partners, static fn($partner) => ($partner['partner_type'] ?? 'MAIN') === 'CARWISE'));
?>

<div class="page-header">
    <h1><i class="ri-group-2-line"></i> Partner Accounts</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;width:100%;">
        <div style="min-width:240px;flex:1 1 280px;">
            <label class="form-label">Search partner</label>
            <input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Type partner name">
        </div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <a href="partner_accounts.php" class="btn btn-outline btn-sm">Clear</a>
    </form>
</div>

<div class="table-container table-container-fill" style="margin-bottom:20px;">
    <div style="padding:16px 16px 0;font-weight:700;">Main Partner Accounts</div>
    <table>
        <thead><tr><th>Partner</th><th class="text-right">Capital</th><th class="text-right">Current A/c</th><th class="text-right">Committed Funding</th><th class="text-right">Pending Payable</th><th class="text-right">Pending Receivable</th><th class="text-center">Action</th></tr></thead>
        <tbody>
            <?php if (empty($mainPartners)): ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding: 32px;">No main partners found.</td></tr>
            <?php else: ?>
                <?php foreach ($mainPartners as $partner): ?>
                    <?php $position = $engine->getPartnerPosition($partner['id']); ?>
                    <?php
                        $capitalBalance = floatval($position['capital_balance'] ?? 0);
                        $currentBalance = floatval($position['current_balance'] ?? 0);
                    ?>
                    <tr>
                        <td class="text-bold"><?= clean($partner['name']) ?></td>
                        <td class="text-right amount <?= $capitalBalance >= 0 ? 'credit-amount' : 'debit-amount' ?>"><?= formatAmount($capitalBalance, true) ?></td>
                        <td class="text-right amount <?= $currentBalance >= 0 ? 'credit-amount' : 'debit-amount' ?>"><?= formatAmount($currentBalance, true) ?></td>
                        <td class="text-right amount"><?= formatAmount($position['committed_funding'] ?? 0) ?></td>
                        <td class="text-right amount credit-amount"><?= formatAmount($position['pending_payable'] ?? 0) ?></td>
                        <td class="text-right amount debit-amount"><?= formatAmount($position['pending_receivable'] ?? 0) ?></td>
                        <td class="text-center"><a href="../partners/view.php?id=<?= $partner['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="table-container table-container-fill">
    <div style="padding:16px 16px 0;font-weight:700;">Car-wise Partner Accounts</div>
    <table>
        <thead><tr><th>Partner</th><th class="text-right">Capital</th><th class="text-right">Current A/c</th><th class="text-right">Committed Funding</th><th class="text-right">Pending Payable</th><th class="text-right">Pending Receivable</th><th class="text-center">Action</th></tr></thead>
        <tbody>
            <?php if (empty($carWisePartners)): ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding: 32px;">No car-wise partners found.</td></tr>
            <?php else: ?>
                <?php foreach ($carWisePartners as $partner): ?>
                    <?php $position = $engine->getPartnerPosition($partner['id']); ?>
                    <?php
                        $capitalBalance = floatval($position['capital_balance'] ?? 0);
                        $currentBalance = floatval($position['current_balance'] ?? 0);
                    ?>
                    <tr>
                        <td class="text-bold"><?= clean($partner['name']) ?></td>
                        <td class="text-right amount <?= $capitalBalance >= 0 ? 'credit-amount' : 'debit-amount' ?>"><?= formatAmount($capitalBalance, true) ?></td>
                        <td class="text-right amount <?= $currentBalance >= 0 ? 'credit-amount' : 'debit-amount' ?>"><?= formatAmount($currentBalance, true) ?></td>
                        <td class="text-right amount"><?= formatAmount($position['committed_funding'] ?? 0) ?></td>
                        <td class="text-right amount credit-amount"><?= formatAmount($position['pending_payable'] ?? 0) ?></td>
                        <td class="text-right amount debit-amount"><?= formatAmount($position['pending_receivable'] ?? 0) ?></td>
                        <td class="text-center"><a href="../partners/view.php?id=<?= $partner['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
