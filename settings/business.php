<?php
$pageTitle = 'Business Profile';
$pageIcon = '<i class="ri-building-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();
$businessId = Auth::user('business_id');

$business = $db->fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $phone = validatePhoneNumber(post('phone'), 'Phone number');
    $email = validateEmailAddress(post('email'), 'Email');
    $db->query("UPDATE businesses SET name = ?, gstin = ?, address = ?, phone = ?, email = ?, updated_at = NOW() WHERE id = ?",
        [post('name'), post('gstin'), post('address'), $phone, $email, $businessId]);
    $updatedBusiness = $db->fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);
    Auth::auditUpdate('business', $businessId, $business, $updatedBusiness ?: [], 'Business profile updated', 'business');
    setFlash('success', 'Business profile updated!');
    redirect('business.php');
}
?>

<div class="page-header">
    <h1><i class="ri-building-2-line"></i> Business Profile</h1>
    <a href="../reports/change_history.php?entity_type=business&amp;entity_id=<?= clean($businessId) ?>" class="btn btn-outline"><i class="ri-history-line"></i> History</a>
</div>

<div class="card content-narrow">
    <div class="card-body">
        <form method="POST" data-confirm-submit="Save these business profile changes?">
            <?= csrfField() ?>
            <div class="form-group"><label class="form-label">Business Name *</label><input type="text" name="name" class="form-control" value="<?= clean($business['name']) ?>" required></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">GSTIN</label><input type="text" name="gstin" class="form-control" value="<?= clean($business['gstin'] ?? '') ?>" maxlength="15"></div>
                <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= clean($business['phone'] ?? '') ?>" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="10 digit phone"></div>
            </div>
            <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= clean($business['email'] ?? '') ?>" placeholder="name@example.com"></div>
            <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="3"><?= clean($business['address'] ?? '') ?></textarea></div>
            <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Changes</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
