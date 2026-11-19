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
$views = ['tiranga' => 'Tiranga Car Only', 'partners' => 'Partners Only', 'all' => 'All'];
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'tiranga';
if (!isset($views[$view])) $view = 'tiranga';
$pnl = $engine->getProfitAndLoss($dateFrom, $dateTo);
$result = $pnl[['tiranga' => 'tiranga_profit', 'partners' => 'partner_profit', 'all' => 'net_profit'][$view]];
$canViewLedger = Auth::hasBookAccess('general_ledger', 'read');
?>

<div class="page-header">
    <h1><i class="ri-line-chart-line"></i> Profit & Loss Statement</h1>
    <button type="button" onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar no-print">
    <form method="GET" class="filter-form">
        <div><label class="form-label" for="pnl-from">From</label><input id="pnl-from" type="date" name="from" class="form-control" value="<?= clean($dateFrom) ?>"></div>
        <div><label class="form-label" for="pnl-to">To</label><input id="pnl-to" type="date" name="to" class="form-control" value="<?= clean($dateTo) ?>"></div>
        <div><label class="form-label" for="pnl-view">Profit View</label>
            <select id="pnl-view" name="view" class="form-control">
                <?php foreach ($views as $key => $label): ?>
                    <option value="<?= clean($key) ?>" <?= $view === $key ? 'selected' : '' ?>><?= clean($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Apply</button>
    </form>
</div>

<p><strong>Profit &amp; Loss · <?= clean($views[$view]) ?></strong> · <?= clean(formatDate($dateFrom)) ?> to <?= clean(formatDate($dateTo)) ?></p>
<p class="text-muted">Only recorded car-wise partner allocations are included. Review historical sales with missing allocations before relying on their split.</p>

<?php if ($view !== 'partners'): ?>
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
<?php endif; ?>

<div class="card report-total">
    <div class="card-body">
        <div class="report-total-label"><?= clean($views[$view]) ?> · Net Profit / Loss</div>
        <div class="report-total-value <?= $result >= 0 ? 'text-green' : 'text-red' ?>">
            <?= formatAmount($result, true) ?>
        </div>
        <div class="report-total-meta">
            <span class="badge <?= $result >= 0 ? 'badge-green' : 'badge-red' ?>">
                <?= $result == 0 ? 'Break even' : ($result > 0 ? 'Profit' : 'Loss') ?>
            </span>
        </div>
    </div>
</div>

<?php if ($view !== 'partners'): ?>
<div class="card">
    <div class="card-header"><h3>Profit Split</h3></div>
    <div class="card-body table-container card-body-flush">
        <table data-static-table="1"><tbody>
            <tr><td>Combined profit / loss before split</td><td class="text-right amount"><?= formatAmount($pnl['net_profit'], true) ?></td></tr>
            <tr><td>Allocated to partners</td><td class="text-right amount"><?= formatAmount($pnl['partner_profit'], true) ?></td></tr>
            <tr class="table-summary-row"><td>Retained by Tiranga Car</td><td class="text-right amount"><?= formatAmount($pnl['tiranga_profit'], true) ?></td></tr>
        </tbody></table>
    </div>
</div>
<?php endif; ?>

<?php if ($view !== 'tiranga'): ?>
<div class="card">
    <div class="card-header"><h3>Car-wise Partner Allocations</h3></div>
    <div class="card-body table-container card-body-flush">
        <table data-static-table="1"><thead><tr><th>Partner</th><th class="text-right">Profit / Loss</th></tr></thead><tbody>
            <?php foreach ($pnl['partner_shares'] as $share): ?>
                <tr><td><?= clean($share['name']) ?></td><td class="text-right amount"><?= formatAmount($share['amount'], true) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$pnl['partner_shares']): ?><tr><td colspan="2" class="text-muted">No net partner allocation for this period.</td></tr><?php endif; ?>
            <tr class="table-summary-row"><td>Total Partner Profit / Loss</td><td class="text-right amount"><?= formatAmount($pnl['partner_profit'], true) ?></td></tr>
        </tbody></table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
