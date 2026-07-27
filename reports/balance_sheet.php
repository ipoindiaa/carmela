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
$inventorySignedTotal = 0.0;
$balanceSheetAssets = [];
foreach ($bs['ASSET'] as $asset) {
    if (($asset['entity_type'] ?? '') === 'CAR') {
        $inventorySignedTotal += ($asset['balance_type'] ?? 'DR') === 'DR'
            ? floatval($asset['amount'])
            : -floatval($asset['amount']);
        continue;
    }
    $balanceSheetAssets[] = $asset;
}
if (abs($inventorySignedTotal) >= 0.005) {
    $balanceSheetAssets[] = [
        'id' => null,
        'code' => 'CAR-INVENTORY-SUMMARY',
        'name' => 'Vehicle Inventory (Consolidated)',
        'sub_group' => 'Inventory',
        'amount' => abs(round($inventorySignedTotal, 2)),
        'balance_type' => $inventorySignedTotal >= 0 ? 'DR' : 'CR',
        'report_url' => Auth::hasBookAccess('car_profitability', 'read')
            ? APP_URL . 'reports/car_inventory.php?' . http_build_query(['as_on' => $asOnDate])
            : null,
    ];
}
$bs['ASSET'] = $balanceSheetAssets;
$canViewLedger = Auth::hasBookAccess('general_ledger', 'read');
$ledgerFromDate = getCurrentFY($asOnDate) . '-04-01';
$ledgerUrl = static function (array $item) use ($canViewLedger, $ledgerFromDate, $asOnDate): ?string {
    if (!empty($item['report_url'])) {
        return $item['report_url'];
    }
    if (!$canViewLedger || empty($item['id'])) {
        return null;
    }

    return APP_URL . 'reports/ledger.php?' . http_build_query([
        'account_id' => $item['id'],
        'from' => $ledgerFromDate,
        'to' => $asOnDate,
    ]);
};
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

<div class="alert alert-info">
    <i class="ri-information-line"></i>
    <div><strong>Car-wise inventory has moved to the Car Inventory report.</strong><span>The Balance Sheet keeps one consolidated Vehicle Inventory asset so Total Assets remain complete and balanced.</span></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3 class="text-blue"><i class="ri-safe-2-line"></i> Assets</h3></div>
        <div class="card-body" style="padding:0;">
            <div class="table-container table-container-inline table-container-fit">
                <table>
                    <tbody>
                        <?php $lastSub = ''; foreach ($bs['ASSET'] as $item):
                            if ($item['sub_group'] !== $lastSub) { $lastSub = $item['sub_group']; ?>
                            <tr class="table-group-row"><td colspan="2"><?= $lastSub ?></td></tr>
                            <?php } ?>
                        <?php $accountUrl = $ledgerUrl($item); ?>
                        <tr class="<?= $accountUrl ? 'report-account-row' : '' ?>">
                            <td style="padding-left: 24px;">
                                <?php if ($accountUrl): ?>
                                    <a href="<?= clean($accountUrl) ?>" class="report-account-link" title="View <?= clean($item['name']) ?> ledger">
                                        <span><?= clean($item['name']) ?></span><i class="ri-arrow-right-up-line" aria-hidden="true"></i>
                                    </a>
                                <?php else: ?>
                                    <?= clean($item['name']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-right amount"><?= formatAmount($item['amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-summary-row"><td>Total Assets</td><td class="text-right amount text-blue"><?= formatAmount($bs['total_assets']) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div>
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header"><h3 class="text-yellow"><i class="ri-hand-coin-line"></i> Liabilities</h3></div>
            <div class="card-body" style="padding:0;">
                <div class="table-container table-container-inline table-container-fit">
                    <table>
                        <tbody>
                        <?php foreach ($bs['LIABILITY'] as $item): ?>
                        <?php $accountUrl = $ledgerUrl($item); ?>
                        <tr class="<?= $accountUrl ? 'report-account-row' : '' ?>">
                            <td>
                                <?php if ($accountUrl): ?>
                                    <a href="<?= clean($accountUrl) ?>" class="report-account-link" title="View <?= clean($item['name']) ?> ledger">
                                        <span><?= clean($item['name']) ?></span><i class="ri-arrow-right-up-line" aria-hidden="true"></i>
                                    </a>
                                <?php else: ?>
                                    <?= clean($item['name']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-right amount"><?= formatAmount($item['amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($bs['LIABILITY'])): ?><tr><td colspan="2" class="text-center text-muted" style="padding:16px;">None</td></tr><?php endif; ?>
                        <tr class="table-summary-row"><td>Total Liabilities</td><td class="text-right amount"><?= formatAmount($bs['total_liabilities']) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="text-purple"><i class="ri-group-line"></i> Equity / Capital</h3></div>
            <div class="card-body" style="padding:0;">
                <div class="table-container table-container-inline table-container-fit">
                    <table>
                        <tbody>
                        <?php foreach ($bs['EQUITY'] as $item): ?>
                        <?php $accountUrl = $ledgerUrl($item); ?>
                        <tr class="<?= $accountUrl ? 'report-account-row' : '' ?>">
                            <td>
                                <?php if ($accountUrl): ?>
                                    <a href="<?= clean($accountUrl) ?>" class="report-account-link" title="View <?= clean($item['name']) ?> ledger">
                                        <span><?= clean($item['name']) ?></span><i class="ri-arrow-right-up-line" aria-hidden="true"></i>
                                    </a>
                                <?php else: ?>
                                    <?= clean($item['name']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-right amount"><?= formatAmount($item['amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($bs['EQUITY'])): ?><tr><td colspan="2" class="text-center text-muted" style="padding:16px;">None</td></tr><?php endif; ?>
                        <tr class="table-summary-row"><td>Total Equity</td><td class="text-right amount"><?= formatAmount($bs['total_equity']) ?></td></tr>
                        </tbody>
                    </table>
                </div>
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
