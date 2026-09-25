<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
Auth::check();
$db = Database::getInstance();

$id = get('id');
$businessId = Auth::user('business_id');

Auth::requireAnyBookAccess(array_merge(Auth::getPrimaryBookKeys(), ['jv_register','car_profitability']), 'delete');
if (!Auth::canAccessTransactionEntry($id, $businessId, 'delete')) {
    setFlash('error', 'You do not have delete access for that entry.');
    redirect('list.php');
}
$entry = $db->fetch("SELECT * FROM journal_entries WHERE id = ? AND business_id = ?", [$id, $businessId]);
if (!$entry) { setFlash('error', 'Entry not found.'); redirect('list.php'); }
if ($entry['status'] !== 'POSTED' || !empty($entry['is_reversal'])) {
    setFlash('error', !empty($entry['is_reversal']) ? 'A reversal entry is permanent history and cannot be deleted.' : 'Only an active posted entry can be deleted.');
    redirect("view.php?id=$id");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $reason = trim((string) post('reason'));
        if ($reason === '') $reason = 'No reason provided';

        $entryBeforeDelete = $db->fetch("SELECT * FROM journal_entries WHERE id = ? AND business_id = ?", [$id, $businessId]);
        $engine = new AccountingEngine($businessId, Auth::user('user_id'));
        $reversalId = $engine->reverseEntry($id, $reason);
        Auth::auditLog('DELETE', 'journal_entry', $id, 'Entry deleted through reversal: ' . $reason, $entryBeforeDelete, ['reversal_entry_id' => $reversalId], 'transactions');
        setFlash('success', 'Entry deleted from active books. Reversal Ref: ' . $reversalId);
        redirect("view.php?id=$reversalId");
    } catch (Throwable $e) {
        error_log(sprintf(
            'Journal reversal failed for business %s, entry %s: %s in %s:%d',
            (string) $businessId,
            (string) $id,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        setFlash('error', $e instanceof Exception
            ? $e->getMessage()
            : 'The entry could not be reversed because of an unexpected server error. No changes were saved; please try again or contact support.');
        redirect("reverse.php?id=$id");
    }
}

$pageTitle = 'Delete Entry';
$pageIcon = '<i class="ri-delete-bin-line"></i>';
require_once __DIR__ . '/../includes/header.php';

?>

<div class="page-header">
    <h1><i class="ri-delete-bin-line"></i> Delete Entry: <?= $entry['reference_no'] ?></h1>
</div>

<div class="card content-narrow">
    <div class="card-body">
        <div class="alert alert-warning"><i class="ri-alert-line"></i> The entry will be removed from active books by creating a mirror-image reversal. Its original values remain in History. A reason is optional; if left blank, History will record “No reason provided.”</div>

        <div class="table-container block-end">
            <table class="detail-table">
                <tr><td class="text-muted">Reference</td><td class="text-bold"><?= $entry['reference_no'] ?></td></tr>
                <tr><td class="text-muted">Date / Time</td><td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td></tr>
                <tr><td class="text-muted">Type</td><td><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?></td></tr>
                <tr><td class="text-muted">Narration</td><td><?= clean($entry['narration']) ?></td></tr>
            </table>
        </div>

        <form method="POST" data-confirm-submit="Create the reversal entry now? The original record will remain in history and cannot be restored silently.">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Deletion Reason <span class="text-muted">(Optional)</span></label>
                <textarea name="reason" class="form-control" placeholder="Optional — add a note for the audit history" rows="3"></textarea>
            </div>
            <div class="form-actions form-actions-start">
                <button type="submit" class="btn btn-danger"><i class="ri-delete-bin-line"></i> Confirm Delete</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline" data-smart-back="1">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
