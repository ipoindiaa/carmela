<?php
$pageTitle = 'Person-wise Balance Sheet';
$pageIcon = '<i class="ri-contacts-book-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('balance_sheet', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
new AccountingEngine($businessId, Auth::user('user_id'));
$asOnDate = is_string($_GET['as_on'] ?? null) ? $_GET['as_on'] : date('Y-m-d');
$selected = is_string($_GET['person'] ?? null) ? $_GET['person'] : '';
$people = [];
$error = '';
try {
    $people = (new PersonIdentity($businessId))->balanceSheet($asOnDate);
    if ($selected !== '' && !in_array($selected, array_column($people, 'key'), true)) {
        throw new Exception('This person has no balance on the selected date. Choose All Persons.');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$shown = $error ? [] : array_values(array_filter($people, static fn($person) => $selected === '' || $person['key'] === $selected));
$debit = round(array_sum(array_column($shown, 'debit')), 2);
$credit = round(array_sum(array_column($shown, 'credit')), 2);
$title = ($selected && $shown ? $shown[0]['name'] : 'All Persons') . ' · Balance Sheet · ' . $asOnDate;
$shareLines = [Auth::user('business_name'), $title, 'Amounts in INR. Debit and credit balances remain separate.', ''];
foreach ($shown as $person) {
    $shareLines[] = $person['name'] . ' (' . implode(', ', $person['roles']) . ')';
    foreach ($person['accounts'] as $account) {
        $shareLines[] = $account['name'] . ' [' . $account['code'] . ']: '
            . number_format($account['debit'] ?: $account['credit'], 2, '.', ',') . ($account['debit'] > 0 ? ' DR' : ' CR');
    }
    $shareLines[] = 'Total: ' . number_format($person['debit'], 2, '.', ',') . ' DR | ' . number_format($person['credit'], 2, '.', ',') . ' CR';
    $shareLines[] = '';
}
$shareLines[] = 'Report total: ' . number_format($debit, 2, '.', ',') . ' DR | ' . number_format($credit, 2, '.', ',') . ' CR';
?>
<div class="page-header">
    <h1>Person-wise Balance Sheet</h1>
    <div class="page-actions no-print">
        <a class="btn btn-outline btn-sm" href="balance_sheet.php?as_on=<?= clean($asOnDate) ?>">Business Balance Sheet</a>
        <?php if (!$error && $shown): ?>
        <button type="button" id="share-person-balances" class="btn btn-outline btn-sm">Share Report</button>
        <button type="button" onclick="printPage()" class="btn btn-outline btn-sm">Print / Save PDF</button>
        <?php endif; ?>
    </div>
</div>
<div class="filter-bar no-print"><form method="GET" class="filter-form">
    <div><label class="form-label" for="person-balance-date">As On</label><input type="date" class="form-control" id="person-balance-date" name="as_on" value="<?= clean($asOnDate) ?>" required></div>
    <div><label class="form-label" for="person-balance-person">Person</label><select class="form-control searchable-select" id="person-balance-person" name="person">
        <option value="">All Persons</option>
        <?php foreach ($people as $person): ?><option value="<?= clean($person['key']) ?>" <?= $selected === $person['key'] ? 'selected' : '' ?>><?= clean($person['name'] . ' · ' . implode(', ', $person['roles'])) ?></option><?php endforeach; ?>
    </select></div>
    <button class="btn btn-primary btn-sm" type="submit">Generate Report</button>
</form></div>
<?php if ($error): ?><div class="alert alert-danger"><?= clean($error) ?></div><?php endif; ?>
<div class="balance-sheet-report person-balance-report">
<h2><?= clean(Auth::user('business_name')) ?></h2>
<p><strong><?= clean($title) ?></strong></p>
<p>Person account balances only. Capital, profit and trading balances are shown separately without offsetting. Cash, bank, inventory and other business accounts remain in the Business Balance Sheet.</p>
<div class="table-container">
<table data-static-table="1"><thead><tr><th>Person / Account</th><th>Role</th><th>Account Group</th><th class="text-right">Debit (₹)</th><th class="text-right">Credit (₹)</th></tr></thead><tbody>
<?php foreach ($shown as $person): ?>
    <tr class="table-group-row"><th colspan="5" scope="rowgroup"><?= clean($person['name']) ?></th></tr>
    <?php foreach ($person['accounts'] as $account): ?>
    <tr><td><?= clean($account['name']) ?><div class="table-secondary"><?= clean($account['code']) ?></div></td><td><?= clean($account['role']) ?></td><td><?= clean($account['group_name']) ?></td>
        <td class="text-right amount"><?= $account['debit'] > 0 ? formatAmount($account['debit']) : '—' ?></td><td class="text-right amount"><?= $account['credit'] > 0 ? formatAmount($account['credit']) : '—' ?></td></tr>
    <?php endforeach; ?>
    <tr class="table-summary-row"><th colspan="3" scope="row"><?= clean($person['name']) ?> · Total</th><td class="text-right amount"><?= formatAmount($person['debit']) ?></td><td class="text-right amount"><?= formatAmount($person['credit']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$shown && !$error): ?><tr><td colspan="5">No person balances on this date.</td></tr><?php endif; ?>
<tr class="table-summary-row"><th colspan="3" scope="row">Report Total</th><td class="text-right amount"><?= formatAmount($debit) ?></td><td class="text-right amount"><?= formatAmount($credit) ?></td></tr>
</tbody></table>
</div>
</div>
<p id="person-share-status" class="no-print" aria-live="polite"></p>
<script type="application/json" id="person-share-data"><?= json_encode(['title' => $title, 'text' => implode("\n", $shareLines), 'filename' => 'person-balances-' . preg_replace('/[^0-9-]/', '', $asOnDate) . '.txt'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script>
document.getElementById('share-person-balances')?.addEventListener('click', async () => {
    const data = JSON.parse(document.getElementById('person-share-data').textContent);
    const status = document.getElementById('person-share-status');
    if (navigator.share) {
        try {
            await navigator.share({title: data.title, text: data.text});
            status.textContent = 'Report shared.';
            return;
        } catch (error) { if (error.name === 'AbortError') return; }
    }
    // Share the generated report itself, not a private URL requiring login.
    const url = URL.createObjectURL(new Blob([data.text], {type: 'text/plain;charset=utf-8'}));
    const link = document.createElement('a');
    link.href = url; link.download = data.filename;
    document.body.append(link); link.click(); link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    status.textContent = 'Report downloaded. You can attach this file to a message. Use Print / Save PDF for a PDF copy.';
});
</script>
<style>
@media print {
    @page { margin: 12mm; }
    .no-print { display: none !important; }
    .person-balance-report { color: #000 !important; font-size: 10pt; }
    .person-balance-report .table-container { overflow: visible !important; }
    .person-balance-report table { width: 100%; border-collapse: collapse; }
    .person-balance-report thead { display: table-header-group; }
    .person-balance-report tr { break-inside: avoid; }
    .person-balance-report td, .person-balance-report th { padding: 4px; border-bottom: 1px solid #bbb; color: #000 !important; background: #fff !important; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
