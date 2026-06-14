<?php
$pageTitle = 'Outstanding Summary';
$pageIcon = '<i class="ri-survey-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('outstanding_summary', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$summary = $engine->getOutstandingSummary();
$partners = $db->fetchAll("SELECT id FROM partners WHERE business_id = ? AND partner_type = 'MAIN'", [$businessId]);

$partnerPayable = 0;
$partnerReceivable = 0;
foreach ($partners as $partner) {
    $position = $engine->getPartnerPosition($partner['id']);
    $partnerPayable += $position['pending_payable'] ?? 0;
    $partnerReceivable += $position['pending_receivable'] ?? 0;
}
?>

<div class="page-header">
    <h1><i class="ri-survey-line"></i> Outstanding Summary</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value text-blue"><?= formatAmount($summary['debtors_total'] ?? 0) ?></div><div class="stat-label">Receivable from Debtors / Buyers</div></div>
    <div class="stat-card"><div class="stat-value text-yellow"><?= formatAmount($summary['creditors_total'] ?? 0) ?></div><div class="stat-label">Payable to Creditors / Sellers</div></div>
    <div class="stat-card"><div class="stat-value text-purple"><?= formatAmount($partnerPayable) ?></div><div class="stat-label">Pending Payable to Partners</div></div>
    <div class="stat-card"><div class="stat-value text-red"><?= formatAmount($partnerReceivable) ?></div><div class="stat-label">Pending Receivable from Partners</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatAmount($summary['employee_advances_total'] ?? 0) ?></div><div class="stat-label">Employee Advances Outstanding</div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
