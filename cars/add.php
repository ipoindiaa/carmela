<?php
$pageTitle = 'Add Car';
$pageIcon = '<i class="ri-car-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');

// Get payment accounts for dropdown
$cashAccount = getSystemAccount($db, $businessId, 'CASH');
$bankAccount = getSystemAccount($db, $businessId, 'BANK');

// Get partners for optional funding
$partners = $db->fetchAll("SELECT id, name FROM partners WHERE business_id = ? AND is_active = 1", [$businessId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $engine = new AccountingEngine($businessId, Auth::user('user_id'));
        $carId = Database::uuid();
        $regNo = post('registration_no');
        $purchasePrice = floatval(post('purchase_price'));
        $purchaseDate = post('purchase_date');
        $paymentAccount = post('payment_account');

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
            'purchase_price' => $purchasePrice,
            'account_id' => $carAccountId,
            'notes' => post('notes'),
        ]);

        // Auto-create the CAR_PURCHASE journal entry via accounting engine
        $partnerFunding = [];
        if (!empty($_POST['partner_ids'])) {
            foreach ($_POST['partner_ids'] as $idx => $pid) {
                $pAmount = floatval($_POST['partner_amounts'][$idx] ?? 0);
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
        $engine->carPurchase($carId, $purchasePrice, $purchaseDate, $paymentAccount, $narration, $partnerFunding);

        Auth::auditLog('CREATE', 'car', $carId, "Car $regNo added with purchase entry");
        setFlash('success', "Car $regNo added and purchase of " . formatAmount($purchasePrice) . " recorded successfully!");
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
        <form method="POST">
            <?= csrfField() ?>
            <h3 style="margin-bottom: 16px; font-size: 15px; color: var(--accent-blue);"><i class="ri-car-line"></i> Vehicle Details</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Registration No. *</label>
                    <input type="text" name="registration_no" class="form-control" placeholder="e.g., GJ05MX1840" required>
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
                        <input type="number" name="purchase_price" class="form-control" placeholder="0.00" step="0.01" required>
                    </div>
                </div>
            </div>

            <hr style="border-color: var(--border); margin: 24px 0;">
            <h3 style="margin-bottom: 16px; font-size: 15px; color: var(--accent-green);"><i class="ri-bank-card-line"></i> Payment Details</h3>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Pay From *</label>
                    <select name="payment_account" class="form-control" required>
                        <?php if ($cashAccount): ?><option value="<?= $cashAccount['id'] ?>">💵 Cash Account (<?= formatAmount($cashAccount['current_balance']) ?>)</option><?php endif; ?>
                        <?php if ($bankAccount): ?><option value="<?= $bankAccount['id'] ?>">🏦 Bank Account (<?= formatAmount($bankAccount['current_balance']) ?>)</option><?php endif; ?>
                    </select>
                </div>
            </div>

            <?php if (!empty($partners)): ?>
            <hr style="border-color: var(--border); margin: 24px 0;">
            <h3 style="margin-bottom: 16px; font-size: 15px; color: var(--accent-purple);"><i class="ri-group-line"></i> Partner Funding (Optional)</h3>
            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">If partners are contributing to this purchase, add their amounts below. The remaining will be paid from the selected account above.</p>

            <div id="partner-funding">
                <div class="form-row partner-row">
                    <div class="form-group">
                        <select name="partner_ids[]" class="form-control">
                            <option value="">-- Select Partner --</option>
                            <?php foreach ($partners as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= clean($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="input-group">
                            <span class="input-prefix">₹</span>
                            <input type="number" name="partner_amounts[]" class="form-control" placeholder="0.00" step="0.01">
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
    container.appendChild(row);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
