<?php
$pageTitle = 'Car Profitability';
$pageIcon = '<i class="ri-car-washing-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('car_profitability', 'read');
$businessId = Auth::user('business_id');
require_once __DIR__ . '/../includes/accounting_engine.php';
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

$cars = $db->fetchAll(
    "SELECT c.*, a.current_balance as total_cost 
     FROM cars c LEFT JOIN accounts a ON a.id = c.account_id 
     WHERE c.business_id = ? ORDER BY c.status, c.created_at DESC", [$businessId]);

$grandTotalCost = 0; $grandTotalSale = 0; $grandProfit = 0;
?>

<div class="page-header">
    <h1><i class="ri-car-washing-line"></i> Car Profitability Report</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Reg. No.</th><th>Make/Model</th><th class="text-center">Status</th><th class="text-right">Holding Days</th><th class="text-right">Purchase</th><th class="text-right">Expenses</th><th class="text-right">Total Cost</th><th class="text-right">Sale Price</th><th class="text-right">Profit/Loss</th><th>Partner Settlements</th></tr></thead>
        <tbody>
        <?php foreach ($cars as $car):
            $carProfitability = $engine->getCarProfitability($car['id']);
            $totalCost = $carProfitability['total_cost'] ?? ($car['total_cost'] ?? $car['purchase_price']);
            $expenses = max(0, $carProfitability['total_expenses'] ?? ($totalCost - $car['purchase_price']));
            $profit = $car['status'] === 'SOLD' ? $carProfitability['profit'] : null;
            $grossSalePrice = $carProfitability['sale_price'] ?? $car['sale_price'];
            $saleGstAmount = $carProfitability['sale_gst_amount'] ?? ($car['sale_gst_amount'] ?? 0);
            $settlementSummary = [];
            foreach ($carProfitability['settlements'] as $settlement) {
                $settlementSummary[] = $settlement['partner_name'] . ': ' . $settlement['status'];
            }
            if ($car['status'] === 'SOLD') { $grandTotalCost += $totalCost; $grandTotalSale += $car['sale_price']; $grandProfit += $profit; }
        ?>
        <tr>
            <td><a href="../cars/view.php?id=<?= $car['id'] ?>" class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a></td>
            <td><?= clean($car['make'] . ' ' . $car['model']) ?></td>
            <td class="text-center"><?php $sb = ['IN_STOCK'=>'badge-blue','SOLD'=>'badge-green','PENDING_PAYMENT'=>'badge-yellow','CANCELLED'=>'badge-gray']; ?>
                <span class="badge <?= $sb[$car['status']] ?? 'badge-gray' ?>"><?= CAR_STATUS[$car['status']] ?></span></td>
            <td class="text-right"><?= intval($carProfitability['holding_days']) ?></td>
            <td class="text-right amount"><?= formatAmount($car['purchase_price']) ?></td>
            <td class="text-right amount"><?= formatAmount($expenses) ?></td>
            <td class="text-right amount text-bold"><?= formatAmount($totalCost) ?></td>
            <td class="text-right amount">
                <?php if ($grossSalePrice): ?>
                    <?= formatAmount($grossSalePrice) ?>
                    <?php if ($saleGstAmount > 0): ?><div class="text-muted" style="font-size:11px;">GST <?= formatAmount($saleGstAmount) ?></div><?php endif; ?>
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
            <td class="text-right amount <?= $profit !== null ? ($profit >= 0 ? 'positive' : 'negative') : '' ?>">
                <?= $profit !== null ? formatAmount($profit, true) : '-' ?></td>
            <td style="font-size:12px;"><?= !empty($settlementSummary) ? clean(implode(' | ', $settlementSummary)) : '<span class="text-muted">Business only</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6">Grand Total (Sold Cars)</td>
                <td class="text-right amount"><?= formatAmount($grandTotalCost) ?></td>
                <td class="text-right amount"><?= formatAmount($grandTotalSale) ?></td>
                <td class="text-right amount <?= $grandProfit >= 0 ? 'positive' : 'negative' ?>"><?= formatAmount($grandProfit, true) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
