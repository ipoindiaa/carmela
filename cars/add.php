<?php
$pageTitle = 'Add Car';
$pageIcon = '<i class="ri-car-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
Auth::requireEntityAccess('car', 'write');

// Get payment accounts for dropdown
$primaryAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'write');
$paymentAccounts = array_merge($primaryAccountGroups['cash_book'] ?? [], $primaryAccountGroups['bank_book'] ?? []);
$paymentAccountIds = array_values(array_filter(array_map(
    static fn($account) => $account['id'] ?? null,
    $paymentAccounts
)));

$partners = $db->fetchAll("SELECT id, name, partner_type FROM partners WHERE business_id = ? AND is_active = 1 ORDER BY name", [$businessId]);
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $carId = Database::uuid();
        $regNo = normalizeRegistrationNo(post('registration_no'));
        if (!isValidRegistrationNo($regNo)) {
            throw new Exception('Registration number must be like GJ05AA0001, with exactly 4 digits at the end.');
        }
        $existingCar = $db->fetch("SELECT id FROM cars WHERE business_id = ? AND registration_no = ?", [$businessId, $regNo]);
        if ($existingCar) {
            throw new Exception('A car with this registration number already exists.');
        }
        $purchasePrice = parseDecimalInput(post('purchase_price'));
        $gstAmount = 0.0;
        $purchaseDate = post('purchase_date');
        $paymentAccount = post('payment_account');
        $purchasePaidInput = trim((string) post('purchase_paid_now', ''));
        $purchasePaidNow = $purchasePaidInput === '' ? null : parseDecimalInput($purchasePaidInput);
        $sellerName = post('seller_name');
        if (!in_array($paymentAccount, $paymentAccountIds, true)) {
            throw new Exception('You do not have write access to that payment account.');
        }

        $partnerIds = array_values((array) ($_POST['partner_ids'] ?? []));
        $partnerAmounts = array_values((array) ($_POST['partner_amounts'] ?? []));
        $partnerShares = array_values((array) ($_POST['partner_profit_share_pcts'] ?? []));
        $newPartnerName = trim((string) post('new_car_partner_name'));
        if ($newPartnerName !== '' && trim((string) ($partnerIds[0] ?? '')) !== '') {
            throw new Exception('Select an existing partner or create a new one, not both.');
        }
        if ($newPartnerName !== '') {
            if (!Auth::hasEntityAccess('partner', 'write')) {
                throw new Exception('You do not have permission to add a new partner. Select an existing partner instead.');
            }
            $newPartnerId = $engine->createPartner($newPartnerName, 'CARWISE', post('new_car_partner_phone'), '', '', 0, $purchaseDate, 'cars');
            if (empty($partnerIds)) {
                $partnerIds[] = $newPartnerId;
                $partnerAmounts[] = '';
                $partnerShares[] = '';
            } else {
                $partnerIds[0] = $newPartnerId;
            }
        }

        $partnerFunding = [];
        $seenPartnerIds = [];
        $rowCount = max(count($partnerIds), count($partnerAmounts), count($partnerShares));
        for ($idx = 0; $idx < $rowCount; $idx++) {
            $partnerId = trim((string) ($partnerIds[$idx] ?? ''));
            $amountInput = trim((string) ($partnerAmounts[$idx] ?? ''));
            $shareInput = trim((string) ($partnerShares[$idx] ?? ''));
            if ($partnerId === '') {
                if ($amountInput !== '' || $shareInput !== '') {
                    throw new Exception('Select a partner for every contribution or profit share entered.');
                }
                continue;
            }
            if (isset($seenPartnerIds[$partnerId])) {
                throw new Exception('Each partner can be added to a car only once.');
            }
            $seenPartnerIds[$partnerId] = true;
            $partnerFunding[] = [
                'partner_id' => $partnerId,
                'amount' => $amountInput === '' ? 0 : parseDecimalInput($amountInput),
                'profit_share_pct' => $shareInput === '' ? null : $shareInput,
            ];
        }

        $validation = $engine->validateCarPurchaseInput($purchasePrice, $purchaseDate, $paymentAccount, $partnerFunding, $gstAmount, $sellerName, $purchasePaidNow);
        $partnerFunding = $validation['partner_funding'];
        $purchasePaidNow = $validation['paid_now'];

        // Create car account in Chart of Accounts
        $carAccountCode = 'CAR-' . strtoupper(str_replace(' ', '', $regNo));
        $carAccountId = $engine->createAccount($carAccountCode, "Car A/c - $regNo", 'ASSET', 'Inventory', 'CAR', $carId);

        // Insert car record
        $db->insert('cars', [
            'id' => $carId,
            'business_id' => $businessId,
            'registration_no' => $regNo,
            'make' => post('make'),
            'model' => post('model'),
            'year' => intval(post('year')) ?: null,
            'color' => post('color'),
            'purchase_date' => $purchaseDate,
            'purchase_price' => max(0, $purchasePrice - $gstAmount),
            'purchase_paid_amount' => $purchasePaidNow,
            'ownership_type' => 'OWNED',
            'has_second_key' => post('has_second_key') === '1' ? 1 : 0,
            'partner_id' => null,
            'account_id' => $carAccountId,
            'notes' => post('notes'),
        ]);

        // Auto-create the CAR_PURCHASE journal entry via accounting engine
        $narration = "Purchased car $regNo - " . post('make') . ' ' . post('model');
        $engine->carPurchase($carId, $purchasePrice, $purchaseDate, $paymentAccount, $narration, $partnerFunding, $gstAmount, $sellerName, $purchasePaidNow);
        $uploadWarning = '';
        try {
            uploadEntityAttachments($businessId, 'CAR', $carId, 'SELLER', 'seller_images', Auth::user('user_id'), 'documents');
        } catch (Exception $uploadError) {
            $uploadWarning = ' Seller file upload failed: ' . $uploadError->getMessage();
        }

        $createdCar = $db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $businessId]);
        Auth::auditCreate('car', $carId, $createdCar ?: ['registration_no' => $regNo], "Car $regNo added with purchase entry", 'cars');
        setFlash($uploadWarning ? 'warning' : 'success', "Car $regNo added and purchase of " . formatAmount($purchasePrice) . " recorded successfully!" . $uploadWarning);
        redirect("view.php?id=$carId");
    } catch (Exception $e) {
        $formError = $e->getMessage();
    }
}
?>

<div class="page-header">
    <h1><i class="ri-car-line"></i> Add New Car</h1>
    <a href="list.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back</a>
</div>

<?php if ($formError !== ''): ?>
<div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($formError) ?></div>
<?php endif; ?>

<div class="card" style="max-width: 800px;">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" data-confirm-submit="Add this car and post the purchase, payment, and partner terms?">
            <?= csrfField() ?>
            <h3 style="margin-bottom: 16px; font-size: 15px; color: var(--accent-blue);"><i class="ri-car-line"></i> Vehicle Details</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Registration No. *</label>
                    <input type="text" name="registration_no" class="form-control registration-input" placeholder="e.g., GJ05AA0001" maxlength="11" pattern="[A-Za-z]{2}[0-9]{2}[A-Za-z]{1,3}[0-9]{4}" title="Use format like GJ05AA0001. Last 4 characters must be digits." required>
                    <div class="form-hint">Last 4 digits must stay exactly 4 numbers, like <strong>0001</strong>.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Purchase Date *</label>
                    <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Make</label>
                    <input type="text" name="make" class="form-control" placeholder="e.g., Maruti" list="makes">
                    <datalist id="makes"><option value="Maruti"><option value="Hyundai"><option value="Tata"><option value="Honda"><option value="Toyota"><option value="Mahindra"><option value="Kia"><option value="Renault"><option value="Ford"><option value="Volkswagen"></datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">Model</label>
                    <input type="text" name="model" class="form-control" placeholder="e.g., Swift LXI">
                </div>
                <div class="form-group">
                    <label class="form-label">Year</label>
                    <input type="number" name="year" class="form-control" placeholder="e.g., 2020" min="1990" max="2030">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Color</label>
                    <input type="text" name="color" class="form-control" placeholder="e.g., White">
                </div>
                <div class="form-group">
                    <label class="form-label">Purchase Price (₹) *</label>
                    <div class="input-group">
                        <span class="input-prefix">₹</span>
                        <input type="text" name="purchase_price" class="form-control currency-input" placeholder="0" inputmode="decimal" autocomplete="off" required>
                    </div>
                </div>
            </div>
            <hr style="border-color: var(--border); margin: 24px 0;">
            <h3 style="margin-bottom: 16px; font-size: 15px; color: var(--accent-green);"><i class="ri-bank-card-line"></i> Payment Details</h3>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Seller Name</label>
                    <input type="text" name="seller_name" class="form-control" placeholder="Seller's full name">
                    <div class="form-hint">Required if purchase payment will remain pending.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount Paid Now (₹)</label>
                    <div class="input-group">
                        <span class="input-prefix">₹</span>
                        <input type="text" name="purchase_paid_now" class="form-control currency-input" placeholder="Leave blank for full payment" inputmode="decimal" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Pay From *</label>
                    <select name="payment_account" class="form-control" required>
                        <?php foreach ($paymentAccounts as $account): ?>
                            <?php $icon = ($account['entity_type'] ?? '') === 'CASH' ? '💵' : '🏦'; ?>
                            <option value="<?= clean($account['id']) ?>"><?= $icon ?> <?= clean($account['name']) ?> (<?= clean($account['code']) ?>) - <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Second Key Available?</label>
                <select name="has_second_key" class="form-control">
                    <option value="0">No</option>
                    <option value="1">Yes</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Seller Files</label>
                <input type="file" name="seller_images[]" class="form-control" accept="<?= clean(attachmentAcceptAttribute('documents')) ?>" multiple>
                <div class="form-hint">Photos, PDF, Office documents, text/CSV, or archives. Maximum 10 MB each.</div>
            </div>

            <hr style="border-color: var(--border); margin: 24px 0;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                <div>
                    <h3 style="font-size:15px;color:var(--accent-purple);"><i class="ri-group-line"></i> Car Partners <span class="text-muted" style="font-weight:500;">(Optional)</span></h3>
                    <div class="form-hint">Add the partners funding this car and set each partner's profit share. The business keeps any remaining share.</div>
                </div>
                <?php if (Auth::hasEntityAccess('partner', 'write')): ?><button type="button" class="btn btn-outline btn-sm" id="quick-partner-toggle" onclick="toggleQuickPartner()"><i class="ri-user-add-line"></i> Create New Partner</button><?php endif; ?>
            </div>

            <div id="partner-funding">
                <div class="form-row partner-row car-partner-row">
                    <div class="form-group partner-select-group">
                        <label class="form-label partner-role-label">Partner</label>
                        <select name="partner_ids[]" class="form-control">
                            <option value="">No partner</option>
                            <?php foreach ($partners as $p): ?>
                                <option value="<?= clean($p['id']) ?>"><?= clean($p['name']) ?> (<?= ($p['partner_type'] ?? 'MAIN') === 'CARWISE' ? 'Car-wise' : 'Main' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contribution (₹)</label>
                        <div class="input-group">
                            <span class="input-prefix">₹</span>
                            <input type="text" name="partner_amounts[]" class="form-control currency-input" placeholder="Optional" inputmode="decimal" autocomplete="off">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Profit Share %</label>
                        <input type="number" name="partner_profit_share_pcts[]" class="form-control" placeholder="Auto if blank" step="0.01" min="0" max="100">
                    </div>
                    <div class="form-group partner-row-action is-placeholder" style="display:flex;visibility:hidden;align-self:end;">
                        <button type="button" class="btn btn-outline btn-icon" title="Remove partner" onclick="removePartnerRow(this)"><i class="ri-delete-bin-line"></i></button>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="addPartnerRow()"><i class="ri-add-line"></i> Add Partner</button>
                <span class="form-hint">Partner shares may total up to 100%. The business keeps the remainder.</span>
            </div>
            <?php if (Auth::hasEntityAccess('partner', 'write')): ?>
            <div id="quick-partner-fields" class="alert alert-info" style="display:none;margin-bottom:16px;">
                <div class="form-row" style="width:100%;margin-bottom:0;">
                    <div class="form-group"><label class="form-label">New Partner Name *</label><input type="text" name="new_car_partner_name" class="form-control" placeholder="Full name"></div>
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="new_car_partner_phone" class="form-control" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="10 digit phone"></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes about this car"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-lg"><i class="ri-save-line"></i> Add Car & Record Purchase</button>
        </form>
    </div>
</div>

<script>
function addPartnerRow() {
    const container = document.getElementById('partner-funding');
    const row = container.querySelector('.partner-row').cloneNode(true);
    row.querySelectorAll('.custom-select').forEach((wrapper) => {
        const select = wrapper.querySelector('select');
        if (!select) return;
        select.classList.remove('custom-select-native');
        select.removeAttribute('data-select-enhanced');
        select.removeAttribute('tabindex');
        wrapper.replaceWith(select);
    });
    row.querySelectorAll('input').forEach(i => i.value = '');
    row.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    const roleLabel = row.querySelector('.partner-role-label');
    if (roleLabel) roleLabel.textContent = 'Partner';
    const action = row.querySelector('.partner-row-action');
    if (action) {
        action.classList.remove('is-placeholder');
        action.style.visibility = 'visible';
    }
    if (typeof initCurrencyInputs === 'function') {
        initCurrencyInputs(row);
    }
    container.appendChild(row);
    if (typeof enhanceSelects === 'function') {
        enhanceSelects(row);
    }
}

function removePartnerRow(button) {
    button.closest('.partner-row')?.remove();
}

function toggleQuickPartner() {
    const panel = document.getElementById('quick-partner-fields');
    const button = document.getElementById('quick-partner-toggle');
    const primaryRow = document.querySelector('#partner-funding .partner-row');
    const selectGroup = primaryRow?.querySelector('.partner-select-group');
    const select = selectGroup?.querySelector('select');
    const nameInput = panel?.querySelector('input[name="new_car_partner_name"]');
    if (!panel || !button || !selectGroup || !nameInput) return;

    const opening = panel.style.display === 'none';
    panel.style.display = opening ? 'flex' : 'none';
    selectGroup.style.display = opening ? 'none' : '';
    nameInput.required = opening;
    if (opening && select) {
        select.value = '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        nameInput.focus();
    } else {
        nameInput.value = '';
        const phone = panel.querySelector('input[name="new_car_partner_phone"]');
        if (phone) phone.value = '';
    }
    button.innerHTML = opening
        ? '<i class="ri-close-line"></i> Use Existing Partner'
        : '<i class="ri-user-add-line"></i> Create New Partner';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
