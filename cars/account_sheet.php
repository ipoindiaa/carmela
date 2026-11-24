<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
Auth::check();
Auth::requireEntityAccess('car', 'read');
$id = get('id');
$businessId = Auth::user('business_id');
$db = Database::getInstance();
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$p = $engine->getCarProfitability($id);
if (!$p) { http_response_code(404); exit('Car not found.'); }
$car = $p['car'];
$owned = ($car['ownership_type'] ?? 'OWNED') === 'OWNED';
$sold = in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true);
$encodedId = urlencode($id);
$detail = $owned ? 'view.php' : (($car['ownership_type'] === 'OUTSIDE') ? '../outside-cars/view.php' : 'commission_view.php');
$pending = $engine->getCarPendingAmounts($id);
$commission = $owned ? null : $engine->getCommissionSettlement($id);
$settlements = [];
$settlementDates = [];
$partnerShare = 0;
foreach ($p['settlements'] as $row) {
    if ($row['status'] === 'REVERSED') continue;
    $sign = $row['direction'] === 'PAYABLE' ? 1 : -1;
    $settlements[$row['partner_id']] ??= [0, 0, 0];
    if (!empty($row['settlement_date'])) {
        $settlementDates[$row['partner_id']][$row['settlement_date']] = $row['settlement_date'];
    }
    foreach (['profit_amount', 'settled_amount', 'outstanding_amount'] as $i => $key) {
        $settlements[$row['partner_id']][$i] += $sign * abs((float) $row[$key]);
    }
    $partnerShare += $sign * abs((float) $row['profit_amount']);
}
$timeline = $engine->getCarTimeline($id);
$purchasePaid = $owned ? $db->fetch(
    "SELECT COALESCE(SUM(CASE
        WHEN je.transaction_type = 'CAR_PURCHASE' AND a.entity_type IN ('CASH','BANK','GST') AND jl.entry_type = 'CR' THEN jl.amount
        WHEN je.transaction_type = 'PURCHASE_PAYMENT_REPAIR' AND a.entity_type IN ('CASH','BANK','GST') AND jl.entry_type = 'DR' THEN -jl.amount
        WHEN je.transaction_type = 'LOAN_REPAID' AND jl.account_id = seller.account_id AND jl.entry_type = 'DR' THEN jl.amount
        ELSE 0 END), 0) AS total
     FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id = je.id
     JOIN accounts a ON a.id = jl.account_id AND a.business_id = je.business_id
     LEFT JOIN debtors_creditors seller ON seller.id = ? AND seller.business_id = je.business_id
     WHERE je.business_id = ? AND je.car_id = ? AND je.status = 'POSTED' AND je.is_reversal = 0
     AND je.transaction_type IN ('CAR_PURCHASE','PURCHASE_PAYMENT_REPAIR','LOAN_REPAID')",
    [$car['seller_party_id'], $businessId, $id]
) : null;
$accounts = [];
foreach ($db->fetchAll(
    "SELECT DISTINCT je.id, a.name, a.code FROM journal_entries je
     JOIN journal_lines jl ON jl.journal_entry_id = je.id
     JOIN accounts a ON a.id = jl.account_id AND a.business_id = je.business_id
     WHERE je.business_id = ? AND je.car_id = ? AND je.status IN ('POSTED','REVERSED')
     AND a.entity_type IN ('CASH','BANK','GST') ORDER BY a.name",
    [$businessId, $id]
) as $row) $accounts[$row['id']][] = $row['name'] . ' (' . $row['code'] . ')';
$summary = $owned ? [
    'ખરીદી કિંમત' => $p['purchase_price'],
    'વેચનારને ચૂકવેલ' => max(0, (float) $purchasePaid['total']),
    'વેચનારને બાકી' => $pending['purchase_pending'],
    'ડીલરને બાકી' => $pending['dealer_pending'],
    'કુલ ખર્ચ' => $p['total_expenses'],
    'પડતર કિંમત' => $p['total_cost'],
] : [
    'તિરંગાનું વેચાણ કમિશન' => $commission['commission_amount'] ?? null,
    'માલિકને ચૂકવેલ' => $commission['paid_to_owner_amount'] ?? null,
    'માલિકને બાકી' => ($commission['payment_handling'] ?? '') === 'FULL_AMOUNT'
        ? max(0, (float) $commission['owner_amount'] - (float) $commission['paid_to_owner_amount']) : null,
    'કુલ ખર્ચ' => $p['total_expenses'],
];
$summary += [
    'વેચાણ કિંમત' => $sold ? $p['sale_price'] : null,
    'ખરીદદાર પાસેથી બાકી' => $sold ? $pending['sale_pending'] : null,
    'RTO ચોખ્ખું (નફામાં સામેલ નથી)' => $p['rto_net'],
    'લોન કમિશન' => $p['loan_commission_income'],
    'જપ્ત ટોકન ચોખ્ખું' => $p['token_forfeiture_net'],
];
if ($owned) $summary += [
    'વેચાણ કમિશન' => $sold ? $p['sale_commission_amount'] : null,
    'કુલ નફો / નુકસાન' => $sold ? $p['profit'] : null,
    'પાર્ટનરનો ફાળવેલ હિસ્સો' => $sold ? $partnerShare : null,
    'તિરંગાનો ચોખ્ખો નફો / નુકસાન' => $sold ? $p['profit'] - $partnerShare : null,
];
$money = static fn($n) => $n === null ? '—' : formatAmount($n);
$tradingResult = $owned && $sold ? (float) $p['sale_price'] - (float) $p['total_cost'] : null;
?>
<!doctype html>
<html lang="gu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>કાર હિસાબ પત્રક · <?= clean($car['registration_no']) ?></title>
<style>
body{max-width:70rem;margin:1rem auto;padding:0 1rem;color:#111;background:#fff;font:15px/1.45 system-ui,sans-serif}
h1,h2{margin:.6em 0}.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-bottom:1rem}
th,td{padding:.35rem .5rem;border:1px solid #aaa;text-align:start;overflow-wrap:anywhere}
.amount{text-align:end;white-space:nowrap}.toolbar{display:flex;gap:1rem;align-items:center}
@page{size:A4 portrait;margin:10mm}
@media print{body{max-width:none;margin:0;padding:0;font-size:9pt}.toolbar{display:none}.scroll{overflow:visible}thead{display:table-header-group}tr{break-inside:avoid}h2{break-after:avoid}a{color:inherit;text-decoration:none}th,td{padding:2pt 3pt}}
</style>
</head>
<body>
<nav class="toolbar"><a href="<?= $detail ?>?id=<?= $encodedId ?>">← કારની વિગતો અને ફાઇલો</a><button type="button" onclick="window.print()">Print / Save as PDF</button></nav>
<header>
<h1>કાર હિસાબ પત્રક · <?= clean(formatRegistrationNo($car['registration_no'])) ?></h1>
<p><?= clean(trim($car['make'] . ' ' . $car['model'])) ?> · <?= clean(CAR_STATUS[$car['status']] ?? $car['status']) ?> · <?= $owned ? 'પોતાની કાર' : 'કમિશન કાર' ?></p>
<p>ખરીદી / નોંધણી: <?= clean($car['purchase_date']) ?> · વેચાણ: <?= clean($car['sold_date'] ?: '—') ?></p>
<?php if (!empty($car['notes'])): ?><p>નોંધ: <?= nl2br(clean($car['notes'])) ?></p><?php endif; ?>
</header>
<h2>હિસાબનો સારાંશ</h2>
<table><thead><tr><th><?= clean($car['registration_no']) ?> · વિગત</th><th class="amount">રકમ</th></tr></thead><tbody>
<?php foreach ($summary as $label => $value): ?><tr><th scope="row"><?= clean($label) ?></th><td class="amount"><?= $money($value) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php if ($owned): ?>
<h2>નફા / નુકસાનની ગણતરી</h2>
<table><thead><tr><th>ગણતરી</th><th class="amount">રકમ</th></tr></thead><tbody>
<tr><th scope="row">(A) ખરીદી કિંમત</th><td class="amount"><?= $money($p['purchase_price']) ?></td></tr>
<tr><th scope="row">(B) કુલ ખર્ચ</th><td class="amount"><?= $money($p['total_expenses']) ?></td></tr>
<tr><th scope="row">(A + B) પડતર કિંમત</th><td class="amount"><?= $money($p['total_cost']) ?></td></tr>
<tr><th scope="row">(C) વેચાણ કિંમત</th><td class="amount"><?= $money($sold ? $p['sale_price'] : null) ?></td></tr>
<tr><th scope="row">C − (A + B) વેચાણનો નફો / નુકસાન</th><td class="amount"><?= $money($tradingResult) ?></td></tr>
</tbody></table>
<?php endif; ?>
<?php if ($owned): ?>
<h2>ભાગીદારી</h2>
<div class="scroll"><table><thead><tr><th><?= clean($car['registration_no']) ?> · પાર્ટનર</th><th>રોકાણ</th><th>હિસ્સો %</th><th>ફાળવણી</th><th>સેટલ થયેલ</th><th>બાકી</th><th>ફાળવણી તારીખ</th></tr></thead><tbody>
<?php foreach ($p['partnerships'] as $partner): $amounts = $settlements[$partner['partner_id']] ?? [null, null, null]; ?>
<tr><th scope="row"><?= clean($partner['partner_name']) ?></th><td class="amount"><?= $money($partner['funding_amount']) ?></td><td><?= clean($partner['profit_share_pct']) ?></td><?php foreach ($amounts as $amount): ?><td class="amount"><?= $money($amount) ?></td><?php endforeach; ?><td><?= clean(implode(', ', $settlementDates[$partner['partner_id']] ?? []) ?: '—') ?></td></tr>
<?php endforeach; ?>
<?php if (!$p['partnerships']): ?><tr><td colspan="7">કોઈ પાર્ટનર નોંધાયેલ નથી.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
<h2>પેમેન્ટ, વસૂલાત અને ખર્ચની નોંધ</h2>
<p>મૂળ એન્ટ્રી જોવા રેફરન્સ ખોલો. રિવર્સલ અલગ દેખાય છે; નીચેના હલનચલન ખર્ચના કુલમાં ફરી ઉમેરાતા નથી.</p>
<div class="scroll"><table><thead><tr><th><?= clean($car['registration_no']) ?> · તારીખ / રેફ.</th><th>પ્રકાર / વિગત</th><th>Cash / Bank A/c</th><th>આવક</th><th>ચુકવણી</th><th>કાર ખાતાની રકમ</th></tr></thead><tbody>
<?php foreach ($timeline as $row):
    $split = !empty($row['voucher_id']) && (float) $row['voucher_allocation_amount'] > 0;
    $in = $split ? (($row['voucher_primary_entry_type'] === 'DR') ? $row['voucher_allocation_amount'] : 0) : $row['cash_in_amount'];
    $out = $split ? (($row['voucher_primary_entry_type'] === 'CR') ? $row['voucher_allocation_amount'] : 0) : $row['cash_out_amount'];
?>
<tr><td><?= clean($row['entry_date']) ?><br><a href="../transactions/view.php?id=<?= urlencode($row['entry_id']) ?>"><?= clean($row['reference_no']) ?></a></td>
<td><?= clean(transactionTypeLabel($row['transaction_type'], $row)) ?><?= $row['is_reversal'] ? ' · રિવર્સલ' : '' ?><br><?= clean($row['voucher_allocation_note'] ?: $row['narration']) ?></td>
<td><?= clean(implode(', ', $accounts[$row['entry_id']] ?? [])) ?></td>
<td class="amount"><?= $money($in ?: null) ?></td><td class="amount"><?= $money($out ?: null) ?></td>
<td class="amount"><?= $money($row['car_line_amount'] ?: null) ?> <?= clean($row['car_line_type'] ?? '') ?></td></tr>
<?php endforeach; ?>
<?php if (!$timeline): ?><tr><td colspan="6">કોઈ નોંધાયેલી એન્ટ્રી નથી.</td></tr><?php endif; ?>
</tbody></table></div>
</body></html>
