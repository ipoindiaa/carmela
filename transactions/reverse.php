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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $reason = post('reason');
    if (empty($reason)) { setFlash('error', 'Reason is required for reversal.'); redirect("reverse.php?id=$id"); }

    try {
        $engine = new AccountingEngine($businessId, Auth::user('user_id'));
        $reversalId = $engine->reverseEntry($id, $reason);
        setFlash('success', 'Entry reversed successfully. Reversal Ref: ' . $reversalId);
        redirect("view.php?id=$reversalId");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        redirect("reverse.php?id=$id");
    }
}

$pageTitle = 'Reverse Entry';
$pageIcon = '<i class="ri-arrow-go-back-line"></i>';
require_once __DIR__ . '/../includes/header.php';

$entry = $db->fetch("SELECT * FROM journal_entries WHERE id = ? AND business_id = ?", [$id, $businessId]);
if (!$entry) { setFlash('error', 'Entry not found.'); redirect('list.php'); }
?>

<div class="page-header">
    <h1><i class="ri-arrow-go-back-line"></i> Reverse Entry: <?= $entry['reference_no'] ?></h1>
</div>

<div class="card" style="max-width: 600px;">
    <div class="card-body">
        <div class="alert alert-warning"><i class="ri-alert-line"></i> This will create a mirror-image reversal entry. The original entry will be marked as REVERSED.</div>

        <table style="width: 100%; margin-bottom: 20px;">
            <tr><td class="text-muted" style="padding: 8px 0;">Reference</td><td class="text-bold"><?= $entry['reference_no'] ?></td></tr>
            <tr><td class="text-muted" style="padding: 8px 0;">Date / Time</td><td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td></tr>
            <tr><td class="text-muted" style="padding: 8px 0;">Type</td><td><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?></td></tr>
            <tr><td class="text-muted" style="padding: 8px 0;">Narration</td><td><?= clean($entry['narration']) ?></td></tr>
        </table>

        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Reason for Reversal *</label>
                <textarea name="reason" class="form-control" placeholder="Why is this entry being reversed?" required rows="3"></textarea>
            </div>
            <div style="display: flex; gap: 12px;">
                <button type="submit" class="btn btn-danger"><i class="ri-arrow-go-back-line"></i> Confirm Reversal</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline" data-smart-back="1">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
