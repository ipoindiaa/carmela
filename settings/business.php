<?php
$pageTitle = 'Business Profile';
$pageIcon = '<i class="ri-building-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();
$businessId = Auth::user('business_id');

$business = $db->fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $db->query("UPDATE businesses SET name = ?, gstin = ?, address = ?, phone = ?, email = ?, updated_at = NOW() WHERE id = ?",
        [post('name'), post('gstin'), post('address'), post('phone'), post('email'), $businessId]);
    Auth::auditLog('UPDATE', 'business', $businessId, 'Business profile updated');
    setFlash('success', 'Business profile updated!');
    redirect('business.php');
}
?>

<div class="page-header">
    <h1><i class="ri-building-2-line"></i> Business Profile</h1>
</div>

<div class="card" style="max-width: 600px;">
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group"><label class="form-label">Business Name *</label><input type="text" name="name" class="form-control" value="<?= clean($business['name']) ?>" required></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">GSTIN</label><input type="text" name="gstin" class="form-control" value="<?= clean($business['gstin'] ?? '') ?>" maxlength="15"></div>
                <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= clean($business['phone'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= clean($business['email'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="3"><?= clean($business['address'] ?? '') ?></textarea></div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Changes</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
