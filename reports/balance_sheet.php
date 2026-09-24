<?php
ob_start();
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

if (get('export') === 'excel') {
    if (ob_get_level() > 0) ob_end_clean();
    $filenameDate = preg_replace('/[^0-9-]/', '', (string) $asOnDate);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="balance-sheet-' . $filenameDate . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $output = fopen('php://output', 'w');
    // Excel uses the BOM to detect UTF-8, including Indian account names.
    fwrite($output, "\xEF\xBB\xBF");
    $writeCsvRow = static function ($stream, array $values): void {
        $safeValues = array_map(static function ($value) {
            $value = (string) ($value ?? '');
            if (is_numeric($value)) return $value;
            return preg_match('/^[\\s]*[=+@\\-]/u', $value) ? "'" . $value : $value;
        }, $values);
        fputcsv($stream, $safeValues, ',', '"', '');
    };

    $writeCsvRow($output, ['Balance Sheet', 'As on', $asOnDate]);
    $writeCsvRow($output, []);
    $writeCsvRow($output, ['Section', 'Group', 'Account', 'Account Code', 'Debit Balance (INR)', 'Credit Balance (INR)']);
    foreach (['ASSET' => 'Assets', 'LIABILITY' => 'Liabilities', 'EQUITY' => 'Capital'] as $sectionCode => $sectionLabel) {
        $lastGroup = null;
        foreach ($bs[$sectionCode] as $item) {
            $group = (string) ($item['sub_group'] ?? '');
            $writeCsvRow($output, [
                $sectionLabel,
                $group !== $lastGroup ? $group : '',
                $item['name'] ?? '',
                $item['code'] ?? '',
                strtoupper((string) ($item['balance_type'] ?? 'DR')) === 'DR' ? round(floatval($item['amount'] ?? 0), 2) : 0,
                strtoupper((string) ($item['balance_type'] ?? 'DR')) === 'CR' ? round(floatval($item['amount'] ?? 0), 2) : 0,
            ]);
            $lastGroup = $group;
        }
        $totalKey = $sectionCode === 'ASSET' ? 'total_assets' : ($sectionCode === 'LIABILITY' ? 'total_liabilities' : 'total_equity');
        $totalLabel = $sectionCode === 'ASSET' ? 'Total Assets' : ($sectionCode === 'LIABILITY' ? 'Total Liabilities' : 'Total Capital');
        $total = round(floatval($bs[$totalKey] ?? 0), 2);
        $writeCsvRow($output, [$sectionLabel, '', $totalLabel, '', $sectionCode === 'ASSET' ? $total : 0, $sectionCode === 'ASSET' ? 0 : $total]);
    }
    $liabilitiesAndCapital = round(floatval($bs['total_liabilities']) + floatval($bs['total_equity']), 2);
    $writeCsvRow($output, []);
    $writeCsvRow($output, ['Balance Check', '', 'Total Assets', '', round(floatval($bs['total_assets']), 2), 0]);
    $writeCsvRow($output, ['Balance Check', '', 'Liabilities + Capital', '', 0, $liabilitiesAndCapital]);
    $writeCsvRow($output, ['Balance Check', '', 'Difference (Assets − Liabilities and Capital)', '', round(floatval($bs['total_assets']) - $liabilitiesAndCapital, 2), 0]);
    fclose($output);
    exit;
}
?>

<div class="page-header balance-sheet-page-header">
    <h1><i class="ri-file-list-3-line"></i> Balance Sheet</h1>
    <div class="page-actions">
        <form method="GET" class="inline-form">
            <label class="sr-only" for="balance-sheet-date">Balance sheet date</label>
            <input type="date" id="balance-sheet-date" name="as_on" class="form-control" value="<?= clean($asOnDate) ?>">
            <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button>
        </form>
        <span class="meta-text">As on <?= formatDate($asOnDate) ?></span>
        <a href="<?= clean('balance_sheet.php?' . http_build_query(['as_on' => $asOnDate, 'export' => 'excel'])) ?>" class="btn btn-outline btn-sm"><i class="ri-file-excel-2-line"></i> Download Excel (CSV)</a>
        <button type="button" onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
    </div>
</div>

<div class="balance-sheet-report">
<div class="alert alert-info">
    <i class="ri-information-line"></i>
    <div><strong>Car-wise inventory has moved to the Car Inventory report.</strong><span>The Balance Sheet keeps one consolidated Vehicle Inventory asset so Total Assets remain complete and balanced.</span></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3 class="text-blue"><i class="ri-safe-2-line"></i> Assets</h3></div>
        <div class="card-body card-body-flush">
            <div class="table-container table-container-inline table-container-fit">
                <table>
                    <tbody>
                        <?php $lastSub = ''; foreach ($bs['ASSET'] as $item):
                            if ($item['sub_group'] !== $lastSub) { $lastSub = $item['sub_group']; ?>
                            <tr class="table-group-row"><td colspan="2"><?= $lastSub ?></td></tr>
                            <?php } ?>
                        <?php $accountUrl = $ledgerUrl($item); ?>
                        <tr class="<?= $accountUrl ? 'report-account-row' : '' ?>">
                            <td class="report-account-indent">
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
    <div class="stack">
        <div class="card">
            <div class="card-header"><h3 class="text-yellow"><i class="ri-hand-coin-line"></i> Liabilities</h3></div>
            <div class="card-body card-body-flush">
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
                        <?php if (empty($bs['LIABILITY'])): ?><tr><td colspan="2" class="text-center text-muted empty-table-cell">None</td></tr><?php endif; ?>
                        <tr class="table-summary-row"><td>Total Liabilities</td><td class="text-right amount"><?= formatAmount($bs['total_liabilities']) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="text-purple"><i class="ri-group-line"></i> Capital</h3></div>
            <div class="card-body card-body-flush">
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
                        <?php if (empty($bs['EQUITY'])): ?><tr><td colspan="2" class="text-center text-muted empty-table-cell">None</td></tr><?php endif; ?>
                        <tr class="table-summary-row"><td>Total Capital</td><td class="text-right amount"><?= formatAmount($bs['total_equity']) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body accounting-equation">
        <div><div class="text-muted">Total Assets</div><strong class="text-blue"><?= formatAmount($bs['total_assets']) ?></strong></div>
        <div class="accounting-equation-sign" aria-hidden="true">=</div>
        <div><div class="text-muted">Liabilities + Capital</div><strong class="text-purple"><?= formatAmount($bs['total_liabilities'] + $bs['total_equity']) ?></strong></div>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
