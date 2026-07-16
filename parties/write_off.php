<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

Auth::check();
Auth::requireAdmin();

$db = Database::getInstance();
$businessId = Auth::user('business_id');
$partyId = get('id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

$party = $db->fetch(
    "SELECT dc.*, a.current_balance, a.current_balance_type
     FROM debtors_creditors dc
     LEFT JOIN accounts a ON a.id = dc.account_id
     WHERE dc.id = ? AND dc.business_id = ?",
    [$partyId, $businessId]
);

if (!$party) {
    setFlash('error', 'Party not found.');
    redirect('list.php');
}

if (!in_array($party['type'], ['DEBTOR', 'BUYER'], true)) {
    setFlash('error', 'Bad debt write-off is only available for debtors or buyers.');
    redirect('view.php?id=' . urlencode($partyId));
}

$outstanding = (($party['current_balance_type'] ?? 'DR') === 'DR') ? abs((float) ($party['current_balance'] ?? 0)) : 0.0;
if ($outstanding <= 0.009) {
    setFlash('error', 'This party does not currently have an outstanding debtor balance.');
    redirect('view.php?id=' . urlencode($partyId));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $date = post('entry_date');
    $amount = floatval(post('amount'));
    $narration = post('narration');

    try {
        $entryId = $engine->badDebtWriteOff($partyId, $amount, $date, $narration);
        setFlash('success', 'Bad debt write-off posted successfully.');
        redirect('../transactions/view.php?id=' . urlencode($entryId));
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$pageTitle = 'Write Off Bad Debt';
$pageIcon = '<i class="ri-close-circle-line"></i>';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="ri-close-circle-line"></i> Write Off Bad Debt</h1>
    <a href="view.php?id=<?= $party['id'] ?>" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
</div>

<div class="card" style="max-width: 760px;">
    <div class="card-body">
        <div class="alert alert-warning">
            <i class="ri-alert-line"></i>
            Use this only when the balance is genuinely unrecoverable. The system will debit `Bad Debt Expense` and credit this party account.
        </div>

        <div class="stats-grid" style="grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 20px;">
            <div class="stat-card"><div class="stat-value"><?= clean($party['name']) ?></div><div class="stat-label">Party</div></div>
            <div class="stat-card"><div class="stat-value"><?= clean($party['type']) ?></div><div class="stat-label">Type</div></div>
            <div class="stat-card"><div class="stat-value text-red"><?= formatAmount($outstanding) ?></div><div class="stat-label">Current Outstanding</div></div>
        </div>

        <form method="POST" data-confirm-submit="Post this bad debt write-off? It will create a financial entry and can only be corrected through reversal.">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Write-Off Amount (₹) *</label>
                    <input type="number" name="amount" class="form-control" value="<?= clean(number_format($outstanding, 2, '.', '')) ?>" min="0.01" max="<?= clean(number_format($outstanding, 2, '.', '')) ?>" step="0.01" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Reason / Narration *</label>
                <textarea name="narration" class="form-control" rows="3" required>Bad debt written off for <?= clean($party['name']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-danger"><i class="ri-save-line"></i> Post Write-Off</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
