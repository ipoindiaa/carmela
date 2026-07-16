<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/record_deletion.php';
Auth::check();

$entityType = strtolower(trim((string) get('entity_type')));
$entityId = trim((string) get('id'));
$businessId = Auth::user('business_id');

$adminOnly = ['user', 'account', 'opening_balance', 'financial_year'];
if (in_array($entityType, $adminOnly, true)) {
    Auth::requireAdmin();
} elseif ($entityType === 'second_key_event') {
    Auth::requireEntityAccess('car', 'delete');
} else {
    Auth::requireEntityAccess($entityType, 'delete');
}

try {
    $deletion = new RecordDeletionService($businessId, Auth::user('user_id'));
    $description = $deletion->describe($entityType, $entityId);
} catch (Throwable $e) {
    setFlash('error', $e->getMessage());
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $message = $deletion->delete($entityType, $entityId, post('reason'));
        setFlash('success', $message . ' The action is available in Change History and Audit Log.');
        redirect($description['returnUrl']);
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect('delete_record.php?' . http_build_query(['entity_type' => $entityType, 'id' => $entityId]));
    }
}

$pageTitle = 'Delete Record';
$pageIcon = '<i class="ri-delete-bin-line"></i>';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><i class="ri-delete-bin-line"></i> Delete <?= clean($description['typeLabel']) ?></h1>
        <p class="page-subtitle">The record will leave active screens, while its deletion remains immutable in the audit history.</p>
    </div>
    <a href="<?= clean(APP_URL . $description['returnUrl']) ?>" class="btn btn-outline" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
</div>

<div class="card" style="max-width:760px;">
    <div class="card-header"><h3><?= clean($description['recordLabel']) ?></h3></div>
    <div class="card-body">
        <div class="alert alert-warning"><i class="ri-alert-line"></i><div class="alert-copy"><strong>Safe deletion</strong><span><?= clean($description['effect']) ?></span></div></div>
        <div class="detail-list" style="margin:20px 0;">
            <div><span>Record Type</span><strong><?= clean($description['typeLabel']) ?></strong></div>
            <div><span>Record</span><strong><?= clean($description['recordLabel']) ?></strong></div>
            <div><span>Deleted By</span><strong><?= clean(Auth::user('full_name')) ?></strong></div>
        </div>
        <form method="POST" data-confirm-submit="Delete this record now? This action will be logged and financial data will only be removed through reversal.">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Deletion Reason *</label>
                <textarea name="reason" class="form-control" rows="3" minlength="5" required placeholder="Explain why this record was added by mistake"></textarea>
                <div class="form-hint">This reason will appear in Change History and the Audit Log.</div>
            </div>
            <div class="form-actions form-actions-start">
                <button type="submit" class="btn btn-danger"><i class="ri-delete-bin-line"></i> Confirm Delete</button>
                <a href="<?= clean(APP_URL . $description['returnUrl']) ?>" class="btn btn-outline" data-smart-back="1">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
