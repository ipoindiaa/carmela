<?php
$pageTitle = 'General Ledger';
$pageIcon = '<i class="ri-file-text-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('general_ledger', 'read');
$businessId = Auth::user('business_id');
$accountId = get('account_id', '');
$dateFrom = get('from', getCurrentFY() . '-04-01');
$dateTo = get('to', date('Y-m-d'));

$entries = [];
$selectedAccount = null;
$balanceAsOn = ['signed' => 0.0, 'type' => 'DR', 'amount' => 0.0];
$openingBalanceSigned = 0.0;
if ($accountId) {
    $selectedAccount = $db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $businessId]);
    if ($selectedAccount) {
        $priorMovement = $db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS dr_total,
                    COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS cr_total
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ?
               AND je.status IN ('POSTED','REVERSED')
               AND je.entry_date < ?",
            [$accountId, $dateFrom]
        );
        $asOnMovement = $db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS dr_total,
                    COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS cr_total
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ?
               AND je.status IN ('POSTED','REVERSED')
               AND je.entry_date <= ?",
            [$accountId, $dateTo]
        );
        $signedOpening = empty($selectedAccount['opening_entry_id'])
            ? signedBalanceValue($selectedAccount['opening_balance'] ?? 0, $selectedAccount['opening_balance_type'] ?? 'DR')
            : 0.0;
        $openingBalanceSigned = round($signedOpening + floatval($priorMovement['dr_total'] ?? 0) - floatval($priorMovement['cr_total'] ?? 0), 2);
        $asOnSigned = round($signedOpening + floatval($asOnMovement['dr_total'] ?? 0) - floatval($asOnMovement['cr_total'] ?? 0), 2);
        $balanceAsOn = [
            'signed' => $asOnSigned,
            'type' => $asOnSigned >= 0 ? 'DR' : 'CR',
            'amount' => abs($asOnSigned),
        ];

        $entries = $db->fetchAll(
            "SELECT je.id AS entry_id, je.business_id, je.entry_date, je.created_at, je.reference_no, je.narration, je.transaction_type, je.entry_type_id, je.entry_amount, jl.amount, jl.entry_type,
                    je.car_id, je.party_id, c.registration_no AS car_registration_no, dc.name AS party_name, dc.type AS party_type
             FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
             LEFT JOIN cars c ON c.id = je.car_id AND c.business_id = je.business_id
             LEFT JOIN debtors_creditors dc ON dc.id = je.party_id AND dc.business_id = je.business_id
             WHERE jl.account_id = ? AND je.status IN ('POSTED','REVERSED') AND je.entry_date BETWEEN ? AND ?
             ORDER BY je.entry_date, je.created_at", [$accountId, $dateFrom, $dateTo]);
    }
}

$displayEntries = [];
$bal = $openingBalanceSigned;
foreach ($entries as $entry) {
    if ($entry['entry_type'] === 'DR') {
        $bal += $entry['amount'];
    } else {
        $bal -= $entry['amount'];
    }
    $entry['running_balance'] = $bal;
    $displayEntries[] = $entry;
}
$displayEntries = array_reverse($displayEntries);
?>

<div class="page-header">
    <h1><i class="ri-file-text-line"></i> General Ledger</h1>
    <button type="button" onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
</div>

<div class="filter-bar">
    <form method="GET" class="filter-form">
        <div class="filter-main-field">
            <label class="form-label">Account</label>
            <input type="hidden" name="account_id" id="ledger_account_id" value="<?= clean($accountId) ?>" required>
            <button type="button" class="picker-trigger picker-trigger-wide" id="ledger-account-trigger">
                <span><?= $selectedAccount ? clean(trim(($selectedAccount['code'] ?? '') . ' — ' . ($selectedAccount['name'] ?? ''), ' —')) : 'Select account' ?></span>
                <i class="ri-search-line"></i>
            </button>
        </div>
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= $dateFrom ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= $dateTo ?>"></div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> View</button>
    </form>
</div>

<?php if ($selectedAccount): ?>
<div class="card">
    <div class="card-body summary-strip">
        <div><span class="text-muted">Account:</span> <strong><?= clean($selectedAccount['name']) ?></strong></div>
        <div><span class="text-muted">Balance As On <?= formatDate($dateTo) ?>:</span> <strong class="amount <?= ($balanceAsOn['type'] ?? 'DR') === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($balanceAsOn['amount']) ?> <?= $balanceAsOn['type'] ?></strong></div>
    </div>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Date / Time</th><th>Ref</th><th>Type</th><th>Car / Party</th><th>Narration</th><th class="text-right debit-amount">Debit</th><th class="text-right credit-amount">Credit</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($displayEntries as $e): ?>
        <tr>
            <td><?= renderDateTimeStack($e['entry_date'], $e['created_at']) ?></td><td><a class="text-bold" href="../transactions/view.php?id=<?= urlencode($e['entry_id']) ?>"><?= clean($e['reference_no']) ?></a></td>
            <td><span class="badge badge-blue"><?= clean(transactionTypeLabel($e['transaction_type'], $e)) ?></span></td>
            <td><?php if (!empty($e['car_registration_no'])): ?><a href="../cars/view.php?id=<?= urlencode((string) $e['car_id']) ?>"><?= clean($e['car_registration_no']) ?></a><?php else: ?>-<?php endif; ?><?php if (!empty($e['party_name'])): ?><div class="table-note table-note-compact"><a href="../parties/view.php?id=<?= urlencode((string) $e['party_id']) ?>"><?= clean($e['party_name']) ?></a><?= !empty($e['party_type']) ? ' · ' . clean(partyTypeLabel($e['party_type'])) : '' ?></div><?php endif; ?></td>
            <td><?= clean(mb_substr($e['narration']??'',0,50)) ?></td>
            <td class="text-right amount debit-amount"><?= $e['entry_type']==='DR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount credit-amount"><?= $e['entry_type']==='CR' ? formatAmount($e['amount']) : '' ?></td>
            <td class="text-right amount <?= $e['running_balance'] >= 0 ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount(abs($e['running_balance'])) ?> <?= $e['running_balance'] >= 0 ? 'Dr' : 'Cr' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?><tr><td colspan="7" class="text-center text-muted empty-table-cell">No entries for this period</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="modal-overlay" id="ledger-account-modal">
    <div class="modal modal-picker">
        <div class="modal-header">
            <div>
                <h3><i class="ri-search-eye-line"></i> Search Account</h3>
                <p class="modal-subtitle">Search by account code, name, group, or car number.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('ledger-account-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="search" class="form-control picker-search" id="ledger-account-search" placeholder="Type account code or name..." autocomplete="off">
            <div class="picker-results" id="ledger-account-results"></div>
        </div>
    </div>
</div>

<script>
const ledgerAccountTrigger = document.getElementById('ledger-account-trigger');
const ledgerAccountSearch = document.getElementById('ledger-account-search');
const ledgerAccountResults = document.getElementById('ledger-account-results');
const ledgerAccountInput = document.getElementById('ledger_account_id');

ledgerAccountTrigger?.addEventListener('click', function() {
    if (ledgerAccountSearch) ledgerAccountSearch.value = '';
    renderLedgerAccountResults('');
    openModal('ledger-account-modal');
    setTimeout(() => ledgerAccountSearch?.focus(), 60);
});

ledgerAccountSearch?.addEventListener('input', function() {
    renderLedgerAccountResults(this.value);
});

async function renderLedgerAccountResults(query) {
    if (!ledgerAccountResults) return;
    ledgerAccountResults.innerHTML = '<div class="picker-empty">Searching...</div>';

    try {
        const response = await fetch(`../transactions/search_entities.php?kind=account&q=${encodeURIComponent((query || '').trim())}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('Search failed');
        const payload = await response.json();
        const matches = payload.results || [];

        if (!matches.length) {
            ledgerAccountResults.innerHTML = '<div class="picker-empty">No account found.</div>';
            return;
        }

        ledgerAccountResults.innerHTML = matches.map((item) => `
            <button type="button" class="picker-result" data-account-id="${item.id}" data-account-label="${encodeURIComponent(item.label || '')}">
                <span>
                    <strong>${escapeHtml(item.label || '')}</strong>
                    <small>${escapeHtml(item.meta || '')}</small>
                </span>
            </button>
        `).join('');

        ledgerAccountResults.querySelectorAll('.picker-result').forEach((button) => {
            button.addEventListener('click', function() {
                if (ledgerAccountInput) ledgerAccountInput.value = this.dataset.accountId || '';
                const label = decodeURIComponent(this.dataset.accountLabel || '') || 'Select account';
                const span = ledgerAccountTrigger?.querySelector('span');
                if (span) span.textContent = label;
                closeModal('ledger-account-modal');
            });
        });
    } catch (error) {
        ledgerAccountResults.innerHTML = `<div class="picker-empty">Could not search accounts right now. Error: ${escapeHtml(error.message)}.</div>`;
    }
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value || '';
    return div.innerHTML;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
