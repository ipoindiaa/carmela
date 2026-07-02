<?php
$pageTitle = 'Add Car';
$pageIcon = '<i class="ri-car-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

// Get payment accounts for dropdown
$primaryAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'write');
$paymentAccounts = array_merge($primaryAccountGroups['cash_book'] ?? [], $primaryAccountGroups['bank_book'] ?? []);
$paymentAccountIds = array_values(array_filter(array_map(
    static fn($account) => $account['id'] ?? null,
    $paymentAccounts
)));

// Get car-wise partners for optional funding
$partners = $db->fetchAll("SELECT id, name FROM partners WHERE business_id = ? AND is_active = 1 AND partner_type = 'CARWISE' ORDER BY name", [$businessId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $carId = Database::uuid();
        $regNo = normalizeRegistrationNo(post('registration_no'));
        if (!isValidRegistrationNo($regNo)) {
            throw new Exception('Registration number must be like GJ05AA0001, with exactly 4 digits at the end.');
        }
        $purchasePrice = parseDecimalInput(post('purchase_price'));
        $gstAmount = 0.0;
        $purchaseDate = post('purchase_date');
        $paymentAccount = post('payment_account');
        $purchasePaidNow = parseDecimalInput(post('purchase_paid_now', $purchasePrice));
        $sellerName = post('seller_name');
        if (!in_array($paymentAccount, $paymentAccountIds, true)) {
            throw new Exception('You do not have write access to that payment account.');
        }

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
            'has_second_key' => post('has_second_key') === '1' ? 1 : 0,
            'account_id' => $carAccountId,
            'notes' => post('notes'),
        ]);

        // Auto-create the CAR_PURCHASE journal entry via accounting engine
        $partnerFunding = [];
        if (!empty($_POST['partner_ids'])) {
            foreach ($_POST['partner_ids'] as $idx => $pid) {
                $pAmount = parseDecimalInput($_POST['partner_amounts'][$idx] ?? 0);
                if ($pid && $pAmount > 0) {
                    $partnerFunding[] = [
                        'partner_id' => $pid,
                        'amount' => $pAmount,
                        'profit_share_pct' => $_POST['partner_profit_share_pcts'][$idx] ?? null,
                    ];
                }
            }
        }

        $narration = "Purchased car $regNo - " . post('make') . ' ' . post('model');
        $engine->carPurchase($carId, $purchasePrice, $purchaseDate, $paymentAccount, $narration, $partnerFunding, $gstAmount, $sellerName, $purchasePaidNow);
        $uploadWarning = '';
        try {
            uploadEntityAttachments($businessId, 'CAR', $carId, 'SELLER', 'seller_images', Auth::user('user_id'), 'images');
        } catch (Exception $uploadError) {
            $uploadWarning = ' Seller image upload failed: ' . $uploadError->getMessage();
        }

        Auth::auditLog('CREATE', 'car', $carId, "Car $regNo added with purchase entry");
        setFlash($uploadWarning ? 'warning' : 'success', "Car $regNo added and purchase of " . formatAmount($purchasePrice) . " recorded successfully!" . $uploadWarning);
        redirect("view.php?id=$carId");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}
?>

<div class="page-header">
    <h1><i class="ri-car-line"></i> Add New Car</h1>
    <a href="list.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Back</a>
</div>

<div class="card" style="max-width: 800px;">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
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
                <label class="form-label">Seller Images</label>
                <input type="file" name="seller_images[]" class="form-control" accept="image/*" multiple>
                <div class="form-hint">Optional. Upload photos or documents received from seller.</div>
            </div>

            <?php if (!empty($partners)): ?>
            <hr style="border-color: var(--border); margin: 24px 0;">
            <h3 style="margin-bottom: 16px; font-size: 15px; color: var(--accent-purple);"><i class="ri-group-line"></i> Car-wise Partners (Optional)</h3>
            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">Add only deal-specific partners for this car. Main business partners are managed separately.</p>

            <div id="partner-funding">
                <div class="form-row partner-row">
                    <div class="form-group">
                        <select name="partner_ids[]" class="form-control">
                            <option value="">-- Select Car-wise Partner --</option>
                            <?php foreach ($partners as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= clean($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="input-group">
                            <span class="input-prefix">₹</span>
                            <input type="text" name="partner_amounts[]" class="form-control currency-input" placeholder="0" inputmode="decimal" autocomplete="off">
                        </div>
                    </div>
                    <div class="form-group">
                        <input type="number" name="partner_profit_share_pcts[]" class="form-control" placeholder="Profit share %" step="0.01" min="0">
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="addPartnerRow()" style="margin-bottom: 16px;"><i class="ri-add-line"></i> Add Partner</button>
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
    row.querySelectorAll('input').forEach(i => i.value = '');
    row.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    if (typeof initCurrencyInputs === 'function') {
        initCurrencyInputs(row);
    }
    container.appendChild(row);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
