<?php
$pageTitle = 'Profit & Loss';
$pageIcon = '<i class="ri-line-chart-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('profit_loss', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$fy = getCurrentFY();
$dateFrom = get('from', $fy . '-04-01');
$dateTo = get('to', date('Y-m-d'));
$pnl = $engine->getProfitAndLoss($dateFrom, $dateTo);
?>

<div class="page-header">
    <h1><i class="ri-line-chart-line"></i> Profit & Loss Statement</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex;gap:12px;align-items:end;">
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= $dateFrom ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= $dateTo ?>"></div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Apply</button>
    </form>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3 class="text-green"><i class="ri-arrow-down-circle-line"></i> Income</h3></div>
        <div class="card-body" style="padding:0;">
            <div class="table-container table-container-inline">
                <table>
                    <tbody>
                        <?php foreach ($pnl['income'] as $item): ?>
                        <tr><td><?= clean($item['name']) ?></td><td class="text-right amount positive"><?= formatAmount($item['amount']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($pnl['income'])): ?><tr><td class="text-center text-muted" style="padding:20px;">No income recorded</td></tr><?php endif; ?>
                        <tr class="table-summary-row"><td>Total Income</td><td class="text-right amount text-green"><?= formatAmount($pnl['total_income']) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="text-red"><i class="ri-arrow-up-circle-line"></i> Expenses</h3></div>
        <div class="card-body" style="padding:0;">
            <div class="table-container table-container-inline">
                <table>
                    <tbody>
                        <?php foreach ($pnl['expenses'] as $item): ?>
                        <tr><td><?= clean($item['name']) ?></td><td class="text-right amount negative"><?= formatAmount($item['amount']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($pnl['expenses'])): ?><tr><td class="text-center text-muted" style="padding:20px;">No expenses recorded</td></tr><?php endif; ?>
                        <tr class="table-summary-row"><td>Total Expenses</td><td class="text-right amount text-red"><?= formatAmount($pnl['total_expenses']) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-body" style="text-align: center; padding: 32px;">
        <div style="font-size: 14px; color: var(--text-muted); margin-bottom: 8px;">Net Profit / Loss</div>
        <div style="font-size: 36px; font-weight: 800; color: <?= $pnl['net_profit'] >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>;">
            <?= formatAmount($pnl['net_profit'], true) ?>
        </div>
        <div style="margin-top: 8px;">
            <span class="badge <?= $pnl['net_profit'] >= 0 ? 'badge-green' : 'badge-red' ?>">
                <?= $pnl['net_profit'] >= 0 ? 'PROFIT' : 'LOSS' ?>
            </span>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
