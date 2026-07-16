<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
Auth::check();

$id = get('id');
$businessId = Auth::user('business_id');

Auth::requireAnyBookAccess(array_merge(Auth::getPrimaryBookKeys(), ['jv_register']), 'delete');
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
    verifyCsrf();
    $reason = post('reason');
    if (mb_strlen(trim((string) $reason)) < 5) { setFlash('error', 'Enter a clear deletion reason of at least 5 characters.'); redirect("reverse.php?id=$id"); }

    try {
        $entryBeforeDelete = $db->fetch("SELECT * FROM journal_entries WHERE id = ? AND business_id = ?", [$id, $businessId]);
        $engine = new AccountingEngine($businessId, Auth::user('user_id'));
        $reversalId = $engine->reverseEntry($id, $reason);
        Auth::auditLog('DELETE', 'journal_entry', $id, 'Entry deleted through reversal: ' . $reason, $entryBeforeDelete, ['reversal_entry_id' => $reversalId], 'transactions');
        setFlash('success', 'Entry deleted from active books. Reversal Ref: ' . $reversalId);
        redirect("view.php?id=$reversalId");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
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

<div class="card" style="max-width: 600px;">
    <div class="card-body">
        <div class="alert alert-warning"><i class="ri-alert-line"></i> The entry will disappear from active books by creating a mirror-image reversal. Its original values and deletion reason remain permanently available in History.</div>

        <div class="table-container" style="margin-bottom:20px;">
            <table style="width:100%;">
                <tr><td class="text-muted" style="padding: 8px 0;">Reference</td><td class="text-bold"><?= $entry['reference_no'] ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Date / Time</td><td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Type</td><td><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Narration</td><td><?= clean($entry['narration']) ?></td></tr>
            </table>
        </div>

        <form method="POST" data-confirm-submit="Create the reversal entry now? The original record will remain in history and cannot be restored silently.">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Deletion Reason *</label>
                <textarea name="reason" class="form-control" placeholder="Why was this entry added by mistake?" required minlength="5" rows="3"></textarea>
            </div>
            <div style="display: flex; gap: 12px;">
                <button type="submit" class="btn btn-danger"><i class="ri-delete-bin-line"></i> Confirm Delete</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline" data-smart-back="1">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
