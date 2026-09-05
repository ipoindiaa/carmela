<?php
$pageTitle = 'Car Profitability';
$pageIcon = '<i class="ri-car-washing-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('car_profitability', 'read');
$businessId = Auth::user('business_id');
require_once __DIR__ . '/../includes/accounting_engine.php';
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$search = trim((string) get('q',''));
$statusFilter = strtoupper(trim((string) get('status','')));
$carWhere = "c.business_id = ? AND COALESCE(c.ownership_type, 'OWNED') = 'OWNED'";
$carParams = [$businessId,$businessId];
if ($search !== '') { $carWhere .= " AND (c.registration_no LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR partner_rollup.partner_names LIKE ?)"; $like='%'.$search.'%'; array_push($carParams,$like,$like,$like,$like); }
if (in_array($statusFilter,['IN_STOCK','PENDING_PAYMENT','SOLD','CANCELLED'],true)) { $carWhere .= " AND c.status=?"; $carParams[]=$statusFilter; }

$cars = $db->fetchAll(
    "SELECT c.*, a.current_balance as total_cost, partner_rollup.partner_names
     FROM cars c LEFT JOIN accounts a ON a.id = c.account_id
     LEFT JOIN (
        SELECT cp.car_id, GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS partner_names
        FROM car_partnerships cp
        JOIN partners p ON p.id = cp.partner_id
        WHERE cp.business_id = ? AND cp.status = 'ACTIVE'
        GROUP BY cp.car_id
     ) partner_rollup ON partner_rollup.car_id = c.id
     WHERE {$carWhere} ORDER BY c.created_at DESC", $carParams);

$grandTotalCost = 0; $grandTotalSale = 0; $grandRtoNet = 0; $grandLoanCommission = 0; $grandTokenForfeit = 0; $grandProfit = 0;
?>

<div class="page-header">
    <h1><i class="ri-car-washing-line"></i> Car Profitability Report</h1>
    <button type="button" onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar"><form method="get"><div><label class="form-label">Car or Partner</label><input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Registration, make, model, partner"></div><div><label class="form-label">Status</label><select name="status" class="form-control"><option value="">All statuses</option><?php foreach(['IN_STOCK'=>'In Stock','PENDING_PAYMENT'=>'Payment Pending','SOLD'=>'Sold','CANCELLED'=>'Cancelled'] as $value=>$label): ?><option value="<?= $value ?>" <?= $statusFilter===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button><?php if($search!==''||$statusFilter!==''): ?><a href="car_profitability.php" class="btn btn-ghost btn-sm">Clear all</a><?php endif; ?></form></div>

<div class="table-container table-container-fill table-container-fit car-profitability-table">
    <table class="table-compact table-total-room">
        <thead><tr><th>Reg. No.</th><th>Make/Model</th><th>Partners</th><th class="text-center">Status</th><th class="text-right">Days</th><th class="text-right">Purchase Amount</th><th class="text-right">Expenses</th><th class="text-right">Total Cost</th><th class="text-right">Sale + Comm.</th><th class="text-right">RTO Net</th><th class="text-right">Loan Commission</th><th class="text-right">Token Forfeit</th><th class="text-right">Profit/Loss</th></tr></thead>
        <tbody>
        <?php foreach ($cars as $car):
            $carProfitability = $engine->getCarProfitability($car['id']);
            $totalCost = $carProfitability['total_cost'] ?? ($car['total_cost'] ?? $car['purchase_price']);
            $expenses = max(0, $carProfitability['total_expenses'] ?? ($totalCost - $car['purchase_price']));
            $profit = $car['status'] === 'SOLD' ? $carProfitability['profit'] : null;
            $grossSalePrice = $carProfitability['sale_price'] ?? $car['sale_price'];
            $commissionAmount = $carProfitability['sale_commission_amount'] ?? ($car['sale_commission_amount'] ?? 0);
            $totalSaleRealisation = $carProfitability['total_sale_realisation'] ?? ($grossSalePrice + $commissionAmount);
            $rtoRecovered = $carProfitability['rto_recovered'] ?? 0;
            $rtoExpense = $carProfitability['rto_expense'] ?? 0;
            $rtoNet = $carProfitability['rto_net'] ?? ($rtoRecovered - $rtoExpense);
            $loanCommissionIncome = $carProfitability['loan_commission_income'] ?? 0;
            $tokenForfeitNet = $carProfitability['token_forfeiture_net'] ?? 0;
            $dealerCommission = $carProfitability['dealer_commission'] ?? 0;
            if ($car['status'] === 'SOLD') { $grandTotalCost += $totalCost; $grandTotalSale += $totalSaleRealisation; $grandRtoNet += $rtoNet; $grandLoanCommission += $loanCommissionIncome; $grandTokenForfeit += $tokenForfeitNet; $grandProfit += $profit; }
        ?>
        <tr>
            <td><a href="../cars/view.php?id=<?= $car['id'] ?>" class="text-bold"><?= clean(formatRegistrationNo($car['registration_no'])) ?></a></td>
            <td><?= clean($car['make'] . ' ' . $car['model']) ?></td>
            <td><?= clean($car['partner_names'] ?: '-') ?></td>
            <td class="text-center"><?php $sb = ['IN_STOCK'=>'badge-blue','SOLD'=>'badge-green','PENDING_PAYMENT'=>'badge-yellow','CANCELLED'=>'badge-gray']; ?>
                <span class="badge <?= $sb[$car['status']] ?? 'badge-gray' ?>"><?= CAR_STATUS[$car['status']] ?></span></td>
            <td class="text-right"><?= intval($carProfitability['holding_days']) ?></td>
            <td class="text-right amount"><?= formatAmount($car['purchase_price']) ?></td>
            <td class="text-right amount"><?= formatAmount($expenses) ?><?php if ($dealerCommission > 0.009): ?><div class="table-secondary">Incl. dealer commission <?= formatAmount($dealerCommission) ?></div><?php endif; ?></td>
            <td class="text-right amount text-bold"><?= formatAmount($totalCost) ?></td>
            <td class="text-right amount">
                <?php if ($grossSalePrice): ?>
                    <?= formatAmount($totalSaleRealisation) ?>
                    <?php if ($commissionAmount > 0): ?><div class="table-secondary">Includes commission <?= formatAmount($commissionAmount) ?></div><?php endif; ?>
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
            <td class="text-right amount <?= $rtoNet >= 0 ? 'flow-in' : 'flow-out' ?>">
                <?= abs($rtoNet) > 0.009 ? formatAmount($rtoNet, true) : '-' ?>
                <?php if ($rtoRecovered > 0 || $rtoExpense > 0): ?><div class="table-secondary">In <?= formatAmount($rtoRecovered) ?> · Out <?= formatAmount($rtoExpense) ?></div><?php endif; ?>
            </td>
            <td class="text-right amount flow-in"><?= $loanCommissionIncome > 0 ? formatAmount($loanCommissionIncome) : '-' ?></td>
            <td class="text-right amount <?= $tokenForfeitNet > 0 ? 'flow-in' : ($tokenForfeitNet < 0 ? 'flow-out' : '') ?>"><?= abs($tokenForfeitNet) > 0.009 ? formatAmount($tokenForfeitNet, true) : '-' ?></td>
            <td class="text-right amount <?= $profit !== null ? ($profit >= 0 ? 'positive' : 'negative') : '' ?>">
                <?= $profit !== null ? formatAmount($profit, true) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7">Grand Total (Sold Cars)</td>
                <td class="text-right amount"><?= formatAmount($grandTotalCost) ?></td>
                <td class="text-right amount"><?= formatAmount($grandTotalSale) ?></td>
                <td class="text-right amount <?= $grandRtoNet >= 0 ? 'flow-in' : 'flow-out' ?>"><?= formatAmount($grandRtoNet, true) ?></td><td class="text-right amount flow-in"><?= formatAmount($grandLoanCommission) ?></td>
                <td class="text-right amount <?= $grandTokenForfeit >= 0 ? 'flow-in' : 'flow-out' ?>"><?= formatAmount($grandTokenForfeit, true) ?></td>
                <td class="text-right amount <?= $grandProfit >= 0 ? 'positive' : 'negative' ?>"><?= formatAmount($grandProfit, true) ?></td>
            </tr>
        </tfoot>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
