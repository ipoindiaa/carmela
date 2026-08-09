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
$canViewLedger = Auth::hasBookAccess('general_ledger', 'read');
?>

<div class="page-header">
    <h1><i class="ri-line-chart-line"></i> Profit & Loss Statement</h1>
    <button type="button" onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" class="filter-form">
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= $dateFrom ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= $dateTo ?>"></div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Apply</button>
    </form>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3 class="text-green"><i class="ri-arrow-down-circle-line"></i> Income</h3></div>
        <div class="card-body table-container card-body-flush">
            <table data-static-table="1">
                <tbody>
                    <?php foreach ($pnl['income'] as $item): ?>
                    <tr><td><?php if ($canViewLedger): ?><a class="text-bold" href="<?= clean(accountLedgerUrl($item['id'], $dateFrom, $dateTo)) ?>"><?= clean($item['name']) ?></a><?php else: ?><?= clean($item['name']) ?><?php endif; ?></td><td class="text-right amount positive"><?= formatAmount($item['amount']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($pnl['income'])): ?><tr><td class="text-center text-muted empty-table-cell">No income recorded</td></tr><?php endif; ?>
                    <tr class="table-summary-row"><td>Total Income</td><td class="text-right amount text-green"><?= formatAmount($pnl['total_income']) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="text-red"><i class="ri-arrow-up-circle-line"></i> Expenses</h3></div>
        <div class="card-body table-container card-body-flush">
            <table data-static-table="1">
                <tbody>
                    <?php foreach ($pnl['expenses'] as $item): ?>
                    <tr><td><?php if ($canViewLedger): ?><a class="text-bold" href="<?= clean(accountLedgerUrl($item['id'], $dateFrom, $dateTo)) ?>"><?= clean($item['name']) ?></a><?php else: ?><?= clean($item['name']) ?><?php endif; ?></td><td class="text-right amount negative"><?= formatAmount($item['amount']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($pnl['expenses'])): ?><tr><td class="text-center text-muted empty-table-cell">No expenses recorded</td></tr><?php endif; ?>
                    <tr class="table-summary-row"><td>Total Expenses</td><td class="text-right amount text-red"><?= formatAmount($pnl['total_expenses']) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card report-total">
    <div class="card-body">
        <div class="report-total-label">Net Profit / Loss</div>
        <div class="report-total-value <?= $pnl['net_profit'] >= 0 ? 'text-green' : 'text-red' ?>">
            <?= formatAmount($pnl['net_profit'], true) ?>
        </div>
        <div class="report-total-meta">
            <span class="badge <?= $pnl['net_profit'] >= 0 ? 'badge-green' : 'badge-red' ?>">
                <?= $pnl['net_profit'] >= 0 ? 'Profit' : 'Loss' ?>
            </span>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
