<?php
$pageTitle = 'Add Commission Car';
$pageIcon = '<i class="ri-hand-coin-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
Auth::requireEntityAccess('car', 'write');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$owners = $db->fetchAll(
    "SELECT id, name, type, phone FROM debtors_creditors WHERE business_id = ? AND is_active = 1 AND type IN ('SELLER','CREDITOR') ORDER BY name",
    [$businessId]
);
$formError = '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$ownerMode = $isPost ? post('owner_mode', $owners ? 'existing' : 'new') : ($owners ? 'existing' : 'new');
$ownerMode = in_array($ownerMode, ['existing', 'new'], true) ? $ownerMode : ($owners ? 'existing' : 'new');

if ($isPost) {
    verifyCsrf();
    try {
        $ownerPartyId = $ownerMode === 'existing' ? trim((string) post('owner_party_id')) : '';
        $ownerName = $ownerMode === 'new' ? trim((string) post('owner_name')) : '';
        $ownerPhone = $ownerMode === 'new' ? trim((string) post('owner_phone')) : '';
        if ($ownerMode === 'existing' && $ownerPartyId === '') throw new Exception('Select the vehicle owner.');
        if ($ownerMode === 'new' && $ownerName === '') throw new Exception('Enter the owner or company name.');
        $carId = $engine->createCommissionCar([
            'registration_no' => post('registration_no'),
            'received_date' => post('received_date'),
            'make' => post('make'),
            'model' => post('model'),
            'year' => post('year'),
            'color' => post('color'),
            'owner_party_id' => $ownerPartyId,
            'owner_name' => $ownerName,
            'owner_phone' => $ownerPhone,
            'expected_sale_price' => parseDecimalInput(post('expected_sale_price')),
            'expected_commission_amount' => parseDecimalInput(post('expected_commission_amount')),
            'has_second_key' => post('has_second_key') === '1',
            'notes' => post('notes'),
        ]);
        setFlash('success', 'Commission car added. No inventory purchase or expense was posted.');
        redirect('commission_view.php?id=' . $carId);
    } catch (Throwable $e) {
        $formError = $e->getMessage();
    }
}
?>

<div class="page-header">
    <div><h1><i class="ri-hand-coin-line"></i> Add Commission Car</h1><p class="page-subtitle">A customer-owned car held for sale. It is not business inventory.</p></div>
    <a href="commission.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back</a>
</div>

<?php if ($formError): ?><div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($formError) ?></div><?php endif; ?>

<form method="POST" class="card commission-form-card" data-confirm-submit="Add this customer-owned car to Commission Cars? No purchase entry will be posted.">
    <?= csrfField() ?>
    <div class="card-header"><h3><i class="ri-car-line"></i> Vehicle and Owner</h3></div>
    <div class="card-body">
        <div class="alert alert-info commission-accounting-note">
            <i class="ri-information-line"></i>
            <div><strong>This car belongs to the customer.</strong><span>The expected selling value is for tracking only. Income is recorded only when commission is earned at sale.</span></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label">Registration No. *</label><input type="text" name="registration_no" class="form-control registration-input" placeholder="GJ05AA0001" maxlength="11" value="<?= clean(post('registration_no')) ?>" required></div>
            <div class="form-group"><label class="form-label">Received Date *</label><input type="date" name="received_date" class="form-control" value="<?= clean(post('received_date', date('Y-m-d'))) ?>" required></div>
            <div class="form-group"><label class="form-label">Second Key</label><select name="has_second_key" class="form-control"><option value="0" <?= post('has_second_key', '0') !== '1' ? 'selected' : '' ?>>No</option><option value="1" <?= post('has_second_key') === '1' ? 'selected' : '' ?>>Yes</option></select></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label">Make</label><input type="text" name="make" class="form-control" placeholder="Maruti" value="<?= clean(post('make')) ?>"></div>
            <div class="form-group"><label class="form-label">Model</label><input type="text" name="model" class="form-control" placeholder="Swift LXI" value="<?= clean(post('model')) ?>"></div>
            <div class="form-group"><label class="form-label">Year</label><input type="number" name="year" class="form-control" min="1990" max="<?= date('Y') + 1 ?>" value="<?= clean(post('year')) ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">Color</label><input type="text" name="color" class="form-control" value="<?= clean(post('color')) ?>"></div>
        <div class="exclusive-choice" data-exclusive-choice data-default-mode="<?= clean($ownerMode) ?>">
            <input type="hidden" name="owner_mode" value="<?= clean($ownerMode) ?>" data-exclusive-mode data-keep-enabled="1">
            <div class="exclusive-choice-header">
                <div><strong>Vehicle Owner *</strong><span>Choose an existing owner or create one now.</span></div>
                <div class="exclusive-choice-options" role="group" aria-label="Vehicle owner source">
                    <?php if ($owners): ?><button type="button" class="exclusive-choice-option" data-exclusive-option="existing"><i class="ri-search-line"></i> Select Existing</button><?php endif; ?>
                    <button type="button" class="exclusive-choice-option" data-exclusive-option="new"><i class="ri-user-add-line"></i> Add New</button>
                </div>
            </div>
            <?php if ($owners): ?>
            <div data-exclusive-panel="existing">
                <div class="form-group"><label class="form-label">Owner / Company *</label><select name="owner_party_id" class="form-control searchable-select" required><option value="">Select owner</option><?php foreach ($owners as $owner): ?><option value="<?= clean($owner['id']) ?>" <?= post('owner_party_id') === $owner['id'] ? 'selected' : '' ?>><?= clean($owner['name']) ?><?= $owner['phone'] ? ' - ' . clean($owner['phone']) : '' ?></option><?php endforeach; ?></select></div>
            </div>
            <?php endif; ?>
            <div data-exclusive-panel="new">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Owner / Company Name *</label><input type="text" name="owner_name" class="form-control" value="<?= clean(post('owner_name')) ?>" placeholder="Enter owner or company name" required></div>
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="owner_phone" class="form-control" value="<?= clean(post('owner_phone')) ?>" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="10-digit mobile number"></div>
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Expected Selling Value (₹)</label><div class="input-group"><span class="input-prefix">₹</span><input type="text" name="expected_sale_price" class="form-control currency-input" inputmode="decimal" value="<?= clean(post('expected_sale_price')) ?>" placeholder="For tracking only"></div></div>
            <div class="form-group"><label class="form-label">Expected Commission (₹)</label><div class="input-group"><span class="input-prefix">₹</span><input type="text" name="expected_commission_amount" class="form-control currency-input" inputmode="decimal" value="<?= clean(post('expected_commission_amount')) ?>" placeholder="Optional"></div></div>
        </div>
        <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="3" placeholder="Owner instructions, asking price, vehicle condition, or agreed terms"><?= clean(post('notes')) ?></textarea></div>
        <div class="form-actions form-actions-start"><button class="btn btn-primary" type="submit"><i class="ri-save-line"></i> Add Commission Car</button><a href="commission.php" class="btn btn-outline">Cancel</a></div>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
