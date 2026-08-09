<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
Auth::check();

$db = Database::getInstance();
$id = trim((string) get('id'));
$businessId = Auth::user('business_id');
$userId = Auth::user('user_id');
$allowedBooks = array_merge(Auth::getPrimaryBookKeys(), ['jv_register']);
Auth::requireAnyBookAccess($allowedBooks, 'write');
if (!Auth::canAccessTransactionEntry($id, $businessId, 'write')) {
    setFlash('error', 'You do not have edit access for that entry.');
    redirect('list.php');
}

$engine = new AccountingEngine($businessId, $userId);
$entry = $db->fetch(
    "SELECT je.*, u.full_name AS created_by_name
     FROM journal_entries je
     LEFT JOIN users u ON u.id = je.created_by
     WHERE je.id = ? AND je.business_id = ?",
    [$id, $businessId]
);
if (!$entry) {
    setFlash('error', 'Entry not found.');
    redirect('list.php');
}
if ($entry['status'] !== 'POSTED' || !empty($entry['is_reversal'])) {
    setFlash('error', 'Only a posted original entry can be edited.');
    redirect("view.php?id=$id");
}

$lines = $db->fetchAll(
    "SELECT jl.*, a.name AS account_name, a.code AS account_code, a.entity_type AS account_entity_type
     FROM journal_lines jl
     JOIN accounts a ON a.id = jl.account_id
     WHERE jl.journal_entry_id = ?
     ORDER BY jl.id",
    [$id]
);
$writablePrimaryIds = Auth::isAdmin() ? [] : Auth::getAccessiblePrimaryAccountIds($businessId, 'write');
if (!Auth::isAdmin()) {
    foreach ($lines as $line) {
        if (
            in_array($line['account_entity_type'], array_values(PRIMARY_BOOK_ACCOUNT_TYPES), true)
            && !in_array($line['account_id'], $writablePrimaryIds, true)
        ) {
            setFlash('error', 'You need write access to every cash or bank book used by this entry.');
            redirect("view.php?id=$id");
        }
    }
}
$accounts = $db->fetchAll(
    "SELECT id, code, name, group_name, entity_type
     FROM accounts
     WHERE business_id = ? AND is_active = 1
     ORDER BY group_name, name",
    [$businessId]
);
if (!Auth::isAdmin()) {
    $primaryTypes = array_values(PRIMARY_BOOK_ACCOUNT_TYPES);
    $accounts = array_values(array_filter($accounts, function ($account) use ($primaryTypes, $writablePrimaryIds) {
        return !in_array($account['entity_type'] ?? '', $primaryTypes, true)
            || in_array($account['id'], $writablePrimaryIds, true);
    }));
}
$canEditLines = $engine->entrySupportsLineCorrection($entry);
$formDate = $entry['entry_date'];
$formNarration = $entry['narration'];
$formReason = '';
$formLines = array_map(function ($line) {
    return [
        'account_id' => $line['account_id'],
        'type' => $line['entry_type'],
        'amount' => $line['amount'],
        'narration' => $line['narration'],
    ];
}, $lines);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $formDate = trim((string) post('entry_date'));
    $formNarration = trim((string) post('narration'));
    $formReason = trim((string) post('correction_reason'));
    if ($canEditLines) {
        $accountIds = $_POST['line_account_id'] ?? [];
        $types = $_POST['line_type'] ?? [];
        $amounts = $_POST['line_amount'] ?? [];
        $narrations = $_POST['line_narration'] ?? [];
        $formLines = [];
        foreach ($accountIds as $index => $accountId) {
            $formLines[] = [
                'account_id' => $accountId,
                'type' => $types[$index] ?? '',
                'amount' => parseDecimalInput($amounts[$index] ?? 0),
                'narration' => $narrations[$index] ?? '',
            ];
        }
    }

    try {
        $replacementId = $engine->correctEntry(
            $id,
            $formDate,
            $formNarration,
            $canEditLines ? $formLines : [],
            $formReason
        );
        setFlash('success', 'Entry corrected. The original and reversal are preserved in Change History.');
        redirect("view.php?id=$replacementId");
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }
}

$pageTitle = 'Edit Entry';
$pageIcon = '<i class="ri-edit-line"></i>';
require_once __DIR__ . '/../includes/header.php';

function renderCorrectionAccountOptions($accounts, $selectedId = '') {
    foreach ($accounts as $account) {
        $selected = $account['id'] === $selectedId ? ' selected' : '';
        echo '<option value="' . clean($account['id']) . '"' . $selected . '>'
            . clean($account['name'] . ' (' . $account['code'] . ') - ' . $account['group_name'])
            . '</option>';
    }
}
?>

<div class="page-header correction-page-header">
    <h1><i class="ri-edit-line"></i> Edit <?= clean($entry['reference_no']) ?></h1>
    <div class="page-actions">
        <a href="../reports/change_history.php?entity_type=journal_entry&amp;entity_id=<?= urlencode($id) ?>" class="btn btn-outline"><i class="ri-history-line"></i> Change History</a>
        <a href="view.php?id=<?= urlencode($id) ?>" class="btn btn-outline" data-smart-back="1"><i class="ri-arrow-left-line"></i> Cancel</a>
    </div>
</div>
<div class="correction-context text-muted"><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?> · Posted by <?= clean($entry['created_by_name'] ?? 'System') ?></div>

<div class="correction-notice">
    <i class="ri-shield-check-line"></i>
    <div>
        <strong>The posted entry will not be overwritten.</strong>
        <span>Saving creates a balancing reversal and a corrected replacement, with your reason, user, date, time, and every changed field recorded.</span>
    </div>
</div>

<form method="POST" class="correction-form" data-confirm-submit="Save this correction? The original entry will be reversed and kept in Change History.">
    <?= csrfField() ?>
    <div class="card">
        <div class="card-header"><h3><i class="ri-file-edit-line"></i> Entry Details</h3></div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Entry Date *</label>
                    <input type="date" name="entry_date" class="form-control" value="<?= clean($formDate) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Reference</label>
                    <input type="text" class="form-control" value="<?= clean($entry['reference_no']) ?>" disabled>
                    <div class="form-hint">The corrected entry receives a new reference number.</div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Narration (Optional)</label>
                <textarea name="narration" class="form-control" rows="3" placeholder="Optional"><?= clean($formNarration) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3><i class="ri-scales-3-line"></i> Journal Lines</h3>
                <?php if (!$canEditLines): ?><div class="text-muted correction-card-note">Amounts and accounts are locked because this entry is linked to an operational record.</div><?php endif; ?>
            </div>
            <?php if ($canEditLines): ?><button type="button" class="btn btn-outline btn-sm" id="add-correction-line"><i class="ri-add-line"></i> Add Line</button><?php endif; ?>
        </div>
        <?php if ($canEditLines): ?>
            <div class="card-body correction-lines" id="correction-lines">
                <?php foreach ($formLines as $index => $line): ?>
                    <div class="correction-line" data-correction-line>
                        <div class="form-group correction-account-field">
                            <label class="form-label">Account *</label>
                            <select name="line_account_id[]" class="form-control" required data-search-placeholder="Search accounts">
                                <option value="">Select account</option>
                                <?php renderCorrectionAccountOptions($accounts, $line['account_id']); ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Type *</label>
                            <select name="line_type[]" class="form-control" required>
                                <option value="DR" <?= $line['type'] === 'DR' ? 'selected' : '' ?>>Debit</option>
                                <option value="CR" <?= $line['type'] === 'CR' ? 'selected' : '' ?>>Credit</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Amount *</label>
                            <input type="number" name="line_amount[]" class="form-control correction-line-amount" min="0.01" step="0.01" value="<?= clean($line['amount']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Line Note</label>
                            <input type="text" name="line_narration[]" class="form-control" value="<?= clean($line['narration']) ?>" maxlength="500">
                        </div>
                        <button type="button" class="btn btn-icon btn-outline correction-remove-line" title="Remove line" aria-label="Remove journal line"><i class="ri-delete-bin-line"></i></button>
                    </div>
                <?php endforeach; ?>
                <div class="correction-balance" id="correction-balance" aria-live="polite"></div>
            </div>
        <?php else: ?>
            <div class="table-container table-container-inline">
                <table data-static-table="1">
                    <thead><tr><th>Account</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead>
                    <tbody>
                    <?php foreach ($lines as $line): ?>
                        <tr>
                            <td><strong><?= clean($line['account_name']) ?></strong><div class="text-muted"><?= clean($line['account_code']) ?></div></td>
                            <td class="text-right amount debit-amount"><?= $line['entry_type'] === 'DR' ? formatAmount($line['amount']) : '-' ?></td>
                            <td class="text-right amount credit-amount"><?= $line['entry_type'] === 'CR' ? formatAmount($line['amount']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card correction-reason-card">
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Reason for Change *</label>
                <textarea name="correction_reason" class="form-control" rows="3" required minlength="5" placeholder="Example: Payment amount entered incorrectly"><?= clean($formReason) ?></textarea>
                <div class="form-hint">This reason is permanent and visible in Change History.</div>
            </div>
            <div class="correction-form-actions">
                <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Correction</button>
                <a href="view.php?id=<?= urlencode($id) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php if ($canEditLines): ?>
<template id="correction-line-template">
    <div class="correction-line" data-correction-line>
        <div class="form-group correction-account-field">
            <label class="form-label">Account *</label>
            <select name="line_account_id[]" class="form-control" required data-search-placeholder="Search accounts">
                <option value="">Select account</option>
                <?php renderCorrectionAccountOptions($accounts); ?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Type *</label><select name="line_type[]" class="form-control" required><option value="DR">Debit</option><option value="CR">Credit</option></select></div>
        <div class="form-group"><label class="form-label">Amount *</label><input type="number" name="line_amount[]" class="form-control correction-line-amount" min="0.01" step="0.01" required></div>
        <div class="form-group"><label class="form-label">Line Note</label><input type="text" name="line_narration[]" class="form-control" maxlength="500"></div>
        <button type="button" class="btn btn-icon btn-outline correction-remove-line" title="Remove line" aria-label="Remove journal line"><i class="ri-delete-bin-line"></i></button>
    </div>
</template>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const lines = document.getElementById('correction-lines');
    const template = document.getElementById('correction-line-template');
    const balance = document.getElementById('correction-balance');
    const updateBalance = () => {
        let debit = 0;
        let credit = 0;
        lines.querySelectorAll('[data-correction-line]').forEach((row) => {
            const type = row.querySelector('[name="line_type[]"]')?.value;
            const amount = Number(row.querySelector('[name="line_amount[]"]')?.value || 0);
            if (type === 'DR') debit += amount;
            if (type === 'CR') credit += amount;
        });
        const balanced = debit > 0 && Math.abs(debit - credit) < 0.01;
        balance.className = `correction-balance ${balanced ? 'is-balanced' : 'is-unbalanced'}`;
        balance.innerHTML = `<span>Debit: ₹${debit.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span><span>Credit: ₹${credit.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span><strong>${balanced ? '<i class="ri-check-line"></i> Balanced' : '<i class="ri-error-warning-line"></i> Not balanced'}</strong>`;
    };
    document.getElementById('add-correction-line')?.addEventListener('click', () => {
        const row = template.content.firstElementChild.cloneNode(true);
        lines.insertBefore(row, balance);
        window.enhanceSelects(row);
        updateBalance();
    });
    lines.addEventListener('click', (event) => {
        const remove = event.target.closest('.correction-remove-line');
        if (!remove) return;
        if (lines.querySelectorAll('[data-correction-line]').length <= 2) {
            window.showToast ? showToast('A journal entry needs at least two lines.', 'warning') : alert('A journal entry needs at least two lines.');
            return;
        }
        remove.closest('[data-correction-line]').remove();
        updateBalance();
    });
    lines.addEventListener('input', updateBalance);
    lines.addEventListener('change', updateBalance);
    updateBalance();
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
