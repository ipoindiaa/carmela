<?php
$pageTitle = 'Add Outside Car';
$pageIcon = '<i class="ri-steering-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
Auth::requireEntityAccess('car', 'write');

$owners = $db->fetchAll(
    "SELECT id, name, phone FROM debtors_creditors WHERE business_id = ? AND is_active = 1 AND type IN ('SELLER','CREDITOR') ORDER BY name",
    [$businessId]
);

$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $regNo = normalizeRegistrationNo(post('registration_no'));
        if (!isValidRegistrationNo($regNo)) {
            throw new Exception('Registration number must be like GJ05AA0001, with exactly 4 digits at the end.');
        }

        $ownerMode = in_array(post('owner_mode'), ['existing', 'new'], true) ? post('owner_mode') : ($owners ? 'existing' : 'new');
        $ownerPartyId = $ownerMode === 'existing' ? trim((string) post('owner_party_id')) : '';
        $ownerName = $ownerMode === 'new' ? trim((string) post('owner_name')) : '';
        $ownerPhone = $ownerMode === 'new' ? trim((string) post('owner_phone')) : '';

        if ($ownerMode === 'existing' && $ownerPartyId === '') {
            throw new Exception('Select the source entity / owner.');
        }
        if ($ownerMode === 'new' && $ownerName === '') {
            throw new Exception('Enter the source entity / owner name.');
        }

        $expectedCommissionInput = trim((string) post('expected_commission_amount', ''));
        $expectedCommission = $expectedCommissionInput === '' ? 0.0 : parseDecimalInput($expectedCommissionInput);

        $carId = $engine->createOutsideCar([
            'registration_no' => $regNo,
            'received_date' => post('received_date'),
            'owner_party_id' => $ownerPartyId,
            'owner_name' => $ownerName,
            'owner_phone' => $ownerPhone,
            'make' => post('make'),
            'model' => post('model'),
            'year' => post('year'),
            'color' => post('color'),
            'expected_commission_amount' => $expectedCommission,
            'has_second_key' => post('has_second_key') === '1' ? 1 : 0,
            'notes' => post('notes'),
        ]);

        $uploadWarning = '';
        try {
            uploadEntityAttachments($businessId, 'CAR', $carId, 'SELLER', 'seller_images', Auth::user('user_id'), 'documents');
        } catch (Exception $uploadError) {
            $uploadWarning = ' File upload warning: ' . $uploadError->getMessage();
        }

        setFlash($uploadWarning ? 'warning' : 'success', "Outside car $regNo added successfully!" . $uploadWarning);
        redirect("view.php?id=$carId");
    } catch (Throwable $e) {
        $formError = $e->getMessage();
    }
}
?>

<div class="page-header">
    <div>
        <h1><i class="ri-steering-2-line"></i> Add Outside Car</h1>
        <p class="page-subtitle">Add a commission car taken from an external entity (not in owned inventory)</p>
    </div>
    <div class="page-actions">
        <a href="../cars/add.php" class="btn btn-outline"><i class="ri-car-line"></i> Add Owned Car</a>
        <a href="list.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back to Outside Cars</a>
    </div>
</div>

<?php if ($formError !== ''): ?>
<div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($formError) ?></div>
<?php endif; ?>

<div class="card form-card">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" data-confirm-submit="Add this outside car to the system?">
            <?= csrfField() ?>
            <div class="alert alert-info">
                <i class="ri-information-line"></i>
                <div>
                    <strong>Outside Car (Commission Basis)</strong>
                    <div>This car belongs to an external party. It will not be in owned inventory. All expenses and RTO transactions can be logged against it, and commission will be earned upon sale.</div>
                </div>
            </div>

            <h3 class="form-section-title form-section-title-standalone section-accent-blue"><i class="ri-car-line"></i> Vehicle Details</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Registration No. *</label>
                    <input type="text" name="registration_no" class="form-control registration-input" placeholder="e.g., GJ05AA0001" maxlength="11" pattern="[A-Za-z]{2}[0-9]{2}[A-Za-z]{1,3}[0-9]{4}" title="Use format like GJ05AA0001. Last 4 characters must be digits." value="<?= clean(post('registration_no')) ?>" data-registration-check-url="<?= clean(APP_URL . 'cars/check_registration.php') ?>" required>
                    <div class="form-hint">Format: <strong>GJ05AA0001</strong>. Last 4 digits must be numbers.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Date Taken / Received *</label>
                    <input type="date" name="received_date" class="form-control" value="<?= clean(post('received_date') ?: date('Y-m-d')) ?>" required>
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Make</label>
                    <input type="text" name="make" class="form-control" placeholder="e.g., Maruti" value="<?= clean(post('make')) ?>" list="makes">
                    <datalist id="makes"><option value="Maruti"><option value="Hyundai"><option value="Tata"><option value="Honda"><option value="Toyota"><option value="Mahindra"><option value="Kia"><option value="Renault"><option value="Ford"><option value="Volkswagen"></datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">Model</label>
                    <input type="text" name="model" class="form-control" placeholder="e.g., Swift LXI" value="<?= clean(post('model')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Year</label>
                    <input type="number" name="year" class="form-control" placeholder="e.g., 2020" min="1990" max="<?= date('Y') + 1 ?>" value="<?= clean(post('year')) ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Color</label>
                    <input type="text" name="color" class="form-control" placeholder="e.g., White" value="<?= clean(post('color')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Second Key Available?</label>
                    <select name="has_second_key" class="form-control">
                        <option value="0" <?= post('has_second_key') === '0' ? 'selected' : '' ?>>No</option>
                        <option value="1" <?= post('has_second_key') === '1' ? 'selected' : '' ?>>Yes</option>
                    </select>
                </div>
            </div>

            <hr class="form-divider">
            <h3 class="form-section-title form-section-title-standalone section-accent-purple"><i class="ri-user-shared-line"></i> Source Entity (Owner / Provider)</h3>

            <?php $ownerMode = $owners ? 'existing' : 'new'; ?>
            <div class="exclusive-choice" data-exclusive-choice data-default-mode="<?= clean($ownerMode) ?>">
                <input type="hidden" name="owner_mode" value="<?= clean($ownerMode) ?>" data-exclusive-mode data-keep-enabled="1">
                <div class="exclusive-choice-header">
                    <div><strong>Select or Add Source Entity *</strong><span>This entity will have a ledger account in our system.</span></div>
                    <div class="exclusive-choice-options" role="group" aria-label="Owner source">
                        <?php if ($owners): ?><button type="button" class="exclusive-choice-option" data-exclusive-option="existing"><i class="ri-search-line"></i> Select Existing Party</button><?php endif; ?>
                        <button type="button" class="exclusive-choice-option" data-exclusive-option="new"><i class="ri-user-add-line"></i> Add New Party</button>
                    </div>
                </div>
                <?php if ($owners): ?>
                <div data-exclusive-panel="existing">
                    <div class="form-group">
                        <label class="form-label">Select Party / Owner *</label>
                        <select name="owner_party_id" class="form-control searchable-select" required>
                            <option value="">Select source entity</option>
                            <?php foreach ($owners as $owner): ?>
                                <option value="<?= clean($owner['id']) ?>" <?= post('owner_party_id') === $owner['id'] ? 'selected' : '' ?>><?= clean($owner['name']) ?><?= $owner['phone'] ? ' (' . clean($owner['phone']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
                <div data-exclusive-panel="new">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Full Name / Business Name *</label>
                            <input type="text" name="owner_name" class="form-control" placeholder="Enter party name" value="<?= clean(post('owner_name')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="owner_phone" class="form-control" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="10-digit mobile number" value="<?= clean(post('owner_phone')) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <hr class="form-divider">
            <h3 class="form-section-title form-section-title-standalone section-accent-green"><i class="ri-percent-line"></i> Commission Terms</h3>

            <div class="form-group">
                <label class="form-label">Agreed Commission (₹) <span class="text-muted">(Optional)</span></label>
                <div class="input-group">
                    <span class="input-prefix">₹</span>
                    <input type="text" name="expected_commission_amount" class="form-control currency-input" placeholder="0 (Can be set later)" inputmode="decimal" autocomplete="off" value="<?= clean(post('expected_commission_amount')) ?>">
                </div>
                <div class="form-hint">Commission does not need to be decided now. You can add or edit the commission anytime inside this car's detail page before selling.</div>
            </div>

            <div class="form-group detail-subsection">
                <label class="form-label">Vehicle Files / RC Documents</label>
                <input type="file" name="seller_images[]" class="form-control" accept="<?= clean(attachmentAcceptAttribute('documents')) ?>" multiple>
                <div class="form-hint">Photos, RC copy, agreement docs, PDF, or archives. Maximum 10 MB each.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Any additional details about this outside car or terms"><?= clean(post('notes')) ?></textarea>
            </div>

            <div class="form-actions form-actions-start form-actions-spaced">
                <button type="submit" class="btn btn-primary btn-lg"><i class="ri-save-line"></i> Save Outside Car</button>
                <a href="list.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
