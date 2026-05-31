<?php
$pageTitle = 'Cash Book';
$pageIcon = '<i class="ri-book-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('cash_book', 'read');
$businessId = Auth::user('business_id');
$dateFrom = get('from', date('Y-m-01'));
$dateTo = get('to', date('Y-m-d'));
$cashAccount = $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND entity_id IS NULL", [$businessId]);

$entries = $db->fetchAll(
    "SELECT je.entry_date, je.reference_no, je.narration, je.transaction_type, jl.amount, jl.entry_type
     FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
     WHERE jl.account_id = ? AND je.status = 'POSTED' AND je.entry_date BETWEEN ? AND ?
     ORDER BY je.entry_date, je.created_at", [$cashAccount['id'], $dateFrom, $dateTo]);
?>

<div class="page-header">
    <h1><i class="ri-book-2-line"></i> Cash Book</h1>
    <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex;gap:12px;align-items:end;">
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= $dateFrom ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= $dateTo ?>"></div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Apply</button>
    </form>
</div>

<div class="card" style="margin-bottom: 16px;">
    <div class="card-body" style="display:flex;gap:40px;">
        <div><span class="text-muted">Current Balance:</span> <strong class="amount <?= ($cashAccount['current_balance_type'] ?? 'DR') === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($cashAccount['current_balance'] ?? 0) ?></strong></div>
    </div>
</div>

<div class="table-container">
    <table>
        <thead><tr><th>Date</th><th>Ref</th><th>Type</th><th>Narration</th><th class="text-right debit-amount">Receipt (Dr)</th><th class="text-right credit-amount">Payment (Cr)</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php $bal = $cashAccount['opening_balance'] ?? 0; $totalDr = 0; $totalCr = 0; ?>
        <?php foreach ($entries as $e): 
            if ($e['entry_type'] === 'DR') { $bal += $e['amount']; $totalDr += $e['amount']; } else { $bal -= $e['amount']; $totalCr += $e['amount']; }
        ?>
        <tr>
            <td><?= formatDate($e['entry_date']) ?></td><td><?= $e['reference_no'] ?></td>
            <td><span class="badge badge-blue" style="font-size:10px;"><?= TXN_TYPES[$e['transaction_type']] ?? $e['transaction_type'] ?></span></td>
            <td><?= clean(mb_substr($e['narration']??'',0,50)) ?></td>
            <td class="text-right amount debit-amount"><?= $e['entry_type']==='DR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount credit-amount"><?= $e['entry_type']==='CR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount <?= $bal >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($bal) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:var(--bg-secondary);font-weight:700;">
            <td colspan="4">Total</td><td class="text-right amount debit-amount"><?= formatAmount($totalDr) ?></td><td class="text-right amount credit-amount"><?= formatAmount($totalCr) ?></td><td class="text-right amount <?= $bal >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($bal) ?></td>
        </tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
