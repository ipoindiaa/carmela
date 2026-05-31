<?php
$pageTitle = 'Balance Sheet';
$pageIcon = '<i class="ri-file-list-3-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('balance_sheet', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$asOnDate = get('as_on', date('Y-m-d'));
$bs = $engine->getBalanceSheet($asOnDate);
?>

<div class="page-header">
    <h1><i class="ri-file-list-3-line"></i> Balance Sheet</h1>
    <div style="display:flex;gap:10px;">
        <form method="GET" style="display:flex;gap:10px;align-items:center;">
            <input type="date" name="as_on" class="form-control" value="<?= clean($asOnDate) ?>">
            <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button>
        </form>
        <span class="text-muted" style="font-size:13px;">As on <?= formatDate($asOnDate) ?></span>
        <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3 class="text-blue"><i class="ri-safe-2-line"></i> Assets</h3></div>
        <div class="card-body" style="padding:0;">
            <table>
                <?php $lastSub = ''; foreach ($bs['ASSET'] as $item): 
                    if ($item['sub_group'] !== $lastSub) { $lastSub = $item['sub_group']; ?>
                    <tr style="background:var(--bg-secondary);"><td colspan="2" style="font-weight: 600; color: var(--text-muted); font-size: 12px;"><?= $lastSub ?></td></tr>
                    <?php } ?>
                <tr><td style="padding-left: 24px;"><?= clean($item['name']) ?></td><td class="text-right amount"><?= formatAmount($item['amount']) ?></td></tr>
                <?php endforeach; ?>
                <tr style="background:var(--bg-secondary);font-weight:700;font-size:15px;"><td>Total Assets</td><td class="text-right amount text-blue"><?= formatAmount($bs['total_assets']) ?></td></tr>
            </table>
        </div>
    </div>
    <div>
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header"><h3 class="text-yellow"><i class="ri-hand-coin-line"></i> Liabilities</h3></div>
            <div class="card-body" style="padding:0;">
                <table>
                <?php foreach ($bs['LIABILITY'] as $item): ?>
                <tr><td><?= clean($item['name']) ?></td><td class="text-right amount"><?= formatAmount($item['amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($bs['LIABILITY'])): ?><tr><td colspan="2" class="text-center text-muted" style="padding:16px;">None</td></tr><?php endif; ?>
                <tr style="background:var(--bg-secondary);font-weight:700;"><td>Total Liabilities</td><td class="text-right amount"><?= formatAmount($bs['total_liabilities']) ?></td></tr>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="text-purple"><i class="ri-group-line"></i> Equity / Capital</h3></div>
            <div class="card-body" style="padding:0;">
                <table>
                <?php foreach ($bs['EQUITY'] as $item): ?>
                <tr><td><?= clean($item['name']) ?></td><td class="text-right amount"><?= formatAmount($item['amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($bs['EQUITY'])): ?><tr><td colspan="2" class="text-center text-muted" style="padding:16px;">None</td></tr><?php endif; ?>
                <tr style="background:var(--bg-secondary);font-weight:700;"><td>Total Equity</td><td class="text-right amount"><?= formatAmount($bs['total_equity']) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-body" style="display:flex;justify-content:space-around;text-align:center;padding:24px;">
        <div><div class="text-muted">Total Assets</div><div style="font-size:24px;font-weight:800;color:var(--accent-blue);"><?= formatAmount($bs['total_assets']) ?></div></div>
        <div style="font-size:24px;color:var(--text-muted);">=</div>
        <div><div class="text-muted">Liabilities + Equity</div><div style="font-size:24px;font-weight:800;color:var(--accent-purple);"><?= formatAmount($bs['total_liabilities'] + $bs['total_equity']) ?></div></div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
