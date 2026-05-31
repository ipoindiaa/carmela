<?php
$pageTitle = 'New Entry';
$pageIcon = '<i class="ri-add-circle-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
Auth::requireAnyBookAccess(Auth::getPrimaryBookKeys(), 'write');

// Get writable primary accounts for dropdowns
$writableAccounts = Auth::getAccessiblePrimaryAccounts($businessId, 'write');
$cashAccount = $writableAccounts['cash_book'] ?? null;
$bankAccount = $writableAccounts['bank_book'] ?? null;
$gstAccount = $writableAccounts['gst_book'] ?? null;
$writableAccountIds = array_values(array_filter([
    $cashAccount['id'] ?? null,
    $bankAccount['id'] ?? null,
    $gstAccount['id'] ?? null,
]));

$cars = $db->fetchAll("SELECT * FROM cars WHERE business_id = ? AND status = 'IN_STOCK' ORDER BY registration_no", [$businessId]);
$partners = $db->fetchAll("SELECT * FROM partners WHERE business_id = ? AND is_active = 1 ORDER BY name", [$businessId]);
$employees = $db->fetchAll("SELECT * FROM employees WHERE business_id = ? AND is_active = 1 ORDER BY name", [$businessId]);
$debtors = $db->fetchAll("SELECT * FROM debtors_creditors WHERE business_id = ? AND type IN ('DEBTOR','BUYER') AND is_active = 1 ORDER BY name", [$businessId]);
$creditors = $db->fetchAll("SELECT * FROM debtors_creditors WHERE business_id = ? AND type IN ('CREDITOR','SELLER') AND is_active = 1 ORDER BY name", [$businessId]);

$preselectedAccount = get('account', '');
if ($preselectedAccount === 'cash' && !$cashAccount) $preselectedAccount = '';
if ($preselectedAccount === 'bank' && !$bankAccount) $preselectedAccount = '';
if ($preselectedAccount === 'gst' && !$gstAccount) $preselectedAccount = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $type = post('transaction_type');
    $date = post('entry_date');
    $amount = floatval(post('amount'));
    $narration = post('narration');
    $paymentAccountId = post('payment_account');

    try {
        $entryId = null;

        if ($paymentAccountId && !in_array($paymentAccountId, $writableAccountIds, true)) {
            throw new Exception('You do not have write access to that book/account.');
        }

        switch ($type) {
            case 'CAR_PURCHASE':
                $carId = post('car_id');
                if (empty($carId) || $carId === 'new') {
                    // Create new car first
                    $carId = Database::uuid();
                    $carRegNo = post('car_reg_no');
                    $carAccountCode = 'CAR-' . strtoupper(str_replace(' ', '', $carRegNo));
                    $carAccountId = $engine->createAccount($carAccountCode, "Car A/c - $carRegNo", 'ASSET', 'Inventory', 'CAR', $carId);
                    
                    $db->insert('cars', [
                        'id' => $carId,
                        'business_id' => $businessId,
                        'registration_no' => $carRegNo,
                        'make' => post('car_make'),
                        'model' => post('car_model'),
                        'year' => intval(post('car_year')) ?: null,
                        'color' => post('car_color'),
                        'purchase_date' => $date,
                        'purchase_price' => $amount,
                        'account_id' => $carAccountId,
                    ]);
                }
                
                // Partner funding
                $partnerFunding = [];
                if (post('partner_funding_enabled')) {
                    $pfPartners = $_POST['pf_partner_id'] ?? [];
                    $pfAmounts = $_POST['pf_amount'] ?? [];
                    $pfProfitShares = $_POST['pf_profit_share_pct'] ?? [];
                    foreach ($pfPartners as $i => $pid) {
                        if (!empty($pid) && !empty($pfAmounts[$i])) {
                            $partnerFunding[] = [
                                'partner_id' => $pid,
                                'amount' => floatval($pfAmounts[$i]),
                                'profit_share_pct' => $pfProfitShares[$i] ?? null,
                            ];
                        }
                    }
                }

                $entryId = $engine->carPurchase($carId, $amount, $date, $paymentAccountId, $narration, $partnerFunding);
                break;

            case 'CAR_SALE':
                $carId = post('sale_car_id');
                $salePrice = floatval(post('sale_price'));
                $amountReceived = floatval(post('amount_received') ?: $salePrice);
                $buyerName = post('buyer_name');
                $entryId = $engine->carSale($carId, $salePrice, $date, $paymentAccountId, $narration, $buyerName, $amountReceived);
                break;

            case 'CAR_EXPENSE':
                $carId = post('expense_car_id');
                $category = post('expense_category');
                $entryId = $engine->carExpense($carId, $amount, $date, $paymentAccountId, $category, $narration);
                break;

            case 'GENERAL_EXPENSE':
                $category = post('expense_category');
                $entryId = $engine->generalExpense($amount, $date, $paymentAccountId, $category, $narration);
                break;

            case 'PARTNER_INVEST':
                $partnerId = post('partner_id');
                $entryId = $engine->partnerInvest($partnerId, $amount, $date, $paymentAccountId, $narration);
                break;

            case 'PARTNER_WITHDRAW':
                $partnerId = post('partner_id');
                $entryId = $engine->partnerWithdraw($partnerId, $amount, $date, $paymentAccountId, $narration);
                break;

            case 'PARTNER_SETTLEMENT':
                $partnerId = post('partner_id');
                $settlementDirection = post('settlement_direction');
                $entryId = $engine->partnerSettlement($partnerId, $amount, $date, $paymentAccountId, $settlementDirection, $narration);
                break;

            case 'SALARY_PAYMENT':
                $employeeId = post('employee_id');
                $grossSalary = floatval(post('gross_salary'));
                $advanceDeduct = floatval(post('advance_deduction', 0));
                $salMonth = intval(post('salary_month'));
                $salYear = intval(post('salary_year'));
                $entryId = $engine->salaryPayment($employeeId, $grossSalary, $advanceDeduct, $date, $paymentAccountId, $salMonth, $salYear);
                break;

            case 'EMPLOYEE_ADVANCE':
                $employeeId = post('employee_id');
                $entryId = $engine->employeeAdvance($employeeId, $amount, $date, $paymentAccountId, $narration);
                break;

            case 'LOAN_GIVEN':
                $partyName = post('party_name');
                $entryId = $engine->loanGiven($partyName, $amount, $date, $paymentAccountId, $narration);
                break;

            case 'LOAN_RECEIVED':
                $partyId = post('debtor_id');
                $entryId = $engine->loanReceived($partyId, $amount, $date, $paymentAccountId, $narration);
                break;

            case 'LOAN_TAKEN':
                $partyName = post('party_name');
                $entryId = $engine->loanTaken($partyName, $amount, $date, $paymentAccountId, $narration);
                break;

            case 'LOAN_REPAID':
                $partyId = post('creditor_id');
                $entryId = $engine->loanRepaid($partyId, $amount, $date, $paymentAccountId, $narration);
                break;

            case 'CONTRA_TRANSFER':
                $fromAccount = post('contra_from');
                $toAccount = post('contra_to');
                if (!in_array($fromAccount, $writableAccountIds, true) || !in_array($toAccount, $writableAccountIds, true)) {
                    throw new Exception('You do not have write access to one of the selected books.');
                }
                if ($fromAccount === $toAccount) {
                    throw new Exception('Transfer From and Transfer To must be different accounts.');
                }
                $entryId = $engine->contraTransfer($fromAccount, $toAccount, $amount, $date, $narration);
                break;

            case 'GST_PAYMENT':
                if (!Auth::hasBookAccess('gst_book', 'write')) {
                    throw new Exception('You do not have write access to the GST book.');
                }
                $entryId = $engine->gstPayment($amount, $date, $narration);
                break;

            default:
                throw new Exception("Invalid transaction type: $type");
        }

        setFlash('success', TXN_TYPES[$type] . ' entry posted successfully!');
        redirect('list.php');
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}
?>

<div class="page-header">
    <h1><i class="ri-add-circle-line"></i> New Entry</h1>
    <a href="jv.php" class="btn btn-outline"><i class="ri-file-edit-line"></i> Open JV Composer</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" id="transaction-form">
            <?= csrfField() ?>

            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">What are you doing? *</label>
                    <select name="transaction_type" id="transaction_type" class="form-control" required>
                        <option value="">— Select Transaction Type —</option>
                        <optgroup label="Cars">
                            <option value="CAR_PURCHASE">🚗 Bought a Car</option>
                            <option value="CAR_SALE">💰 Sold a Car</option>
                            <option value="CAR_EXPENSE">🔧 Car Repair / Service</option>
                        </optgroup>
                        <optgroup label="Business">
                            <option value="GENERAL_EXPENSE">🧾 Office / Business Expense</option>
                            <option value="CONTRA_TRANSFER">🔄 Cash ↔ Bank Transfer</option>
                            <option value="GST_PAYMENT">📋 GST Payment</option>
                        </optgroup>
                        <optgroup label="Partners">
                            <option value="PARTNER_INVEST">💼 Partner Added Money</option>
                            <option value="PARTNER_WITHDRAW">💸 Partner Took Money</option>
                            <option value="PARTNER_SETTLEMENT">🤝 Partner Settlement</option>
                        </optgroup>
                        <optgroup label="Employees">
                            <option value="SALARY_PAYMENT">💵 Paid Salary</option>
                            <option value="EMPLOYEE_ADVANCE">🤝 Employee Took Advance</option>
                        </optgroup>
                        <optgroup label="Loans & Debts">
                            <option value="LOAN_GIVEN">📤 Lent Money to Someone</option>
                            <option value="LOAN_RECEIVED">📥 Received Money Back</option>
                            <option value="LOAN_TAKEN">📥 Borrowed Money</option>
                            <option value="LOAN_REPAID">📤 Repaid a Loan</option>
                        </optgroup>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" id="payment-account-label">Payment Account *</label>
                    <select name="payment_account" class="form-control" id="payment_account">
                        <?php if ($cashAccount): ?>
                            <option value="<?= $cashAccount['id'] ?>" <?= $preselectedAccount === 'cash' ? 'selected' : '' ?>>💵 Cash Account (<?= formatAmount($cashAccount['current_balance'] ?? 0) ?>)</option>
                        <?php endif; ?>
                        <?php if ($bankAccount): ?>
                            <option value="<?= $bankAccount['id'] ?>" <?= $preselectedAccount === 'bank' ? 'selected' : '' ?>>🏦 Bank Account (<?= formatAmount($bankAccount['current_balance'] ?? 0) ?>)</option>
                        <?php endif; ?>
                        <?php if ($gstAccount): ?>
                            <option value="<?= $gstAccount['id'] ?>" <?= $preselectedAccount === 'gst' ? 'selected' : '' ?>>📋 GST Account (<?= formatAmount($gstAccount['current_balance'] ?? 0) ?>)</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Amount (₹) *</label>
                    <div class="input-group">
                        <span class="input-prefix">₹</span>
                        <input type="number" name="amount" class="form-control amount-input" placeholder="0.00" step="0.01" min="0.01" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Narration / Description *</label>
                    <input type="text" name="narration" class="form-control" placeholder="Brief description of this entry" required>
                </div>
            </div>

            <!-- CAR PURCHASE SECTION -->
            <div class="txn-section" id="car-section" style="display:none;">
                <h4 style="margin: 20px 0 16px; padding-top: 20px; border-top: 1px solid var(--border); color: var(--accent-blue);">
                    <i class="ri-car-line"></i> Car Details
                </h4>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Registration No. *</label>
                        <input type="text" name="car_reg_no" class="form-control" placeholder="e.g., GJ05MX1840">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Make</label>
                        <input type="text" name="car_make" class="form-control" placeholder="e.g., Maruti">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Model</label>
                        <input type="text" name="car_model" class="form-control" placeholder="e.g., Swift LXI">
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Year</label>
                        <input type="number" name="car_year" class="form-control" placeholder="e.g., 2020" min="1990" max="2030">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Color</label>
                        <input type="text" name="car_color" class="form-control" placeholder="e.g., White">
                    </div>
                    <div class="form-group">
                        <label class="form-label">&nbsp;</label>
                        <input type="hidden" name="car_id" value="new">
                    </div>
                </div>
            </div>

            <!-- PARTNER FUNDING SECTION -->
	            <div class="txn-section" id="partner-funding-section" style="display:none;">
                <h4 style="margin: 20px 0 16px; padding-top: 20px; border-top: 1px solid var(--border); color: var(--accent-purple);">
                    <i class="ri-group-line"></i> Partner Funding (Optional)
                </h4>
                <div>
                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; cursor: pointer;">
                        <input type="checkbox" name="partner_funding_enabled" value="1" onchange="document.getElementById('partner-funding-rows').style.display = this.checked ? 'block' : 'none'">
                        <span style="font-size: 14px;">Partners are contributing to this purchase</span>
                    </label>
	                    <div id="partner-funding-rows" style="display: none;">
	                        <div class="form-row partner-funding-row">
	                            <div class="form-group">
	                                <label class="form-label">Partner</label>
	                                <select name="pf_partner_id[]" class="form-control">
                                    <option value="">Select Partner</option>
                                    <?php foreach ($partners as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= clean($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
	                            <div class="form-group">
	                                <label class="form-label">Amount (₹)</label>
	                                <input type="number" name="pf_amount[]" class="form-control" placeholder="0.00" step="0.01">
	                            </div>
	                            <div class="form-group">
	                                <label class="form-label">Profit Share %</label>
	                                <input type="number" name="pf_profit_share_pct[]" class="form-control" placeholder="Auto from funding" step="0.01" min="0">
	                            </div>
	                        </div>
                            <button type="button" class="btn btn-outline btn-sm" onclick="addPartnerFundingRow()"><i class="ri-add-line"></i> Add Partner Row</button>
	                    </div>
	                </div>
	            </div>

            <!-- CAR SELECT SECTION (for expenses / sale) -->
            <div class="txn-section" id="car-select-section" style="display:none;">
                <h4 style="margin: 20px 0 16px; padding-top: 20px; border-top: 1px solid var(--border); color: var(--accent-blue);">
                    <i class="ri-car-line"></i> Select Car
                </h4>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Car *</label>
                        <select name="expense_car_id" id="expense_car_select" class="form-control">
                            <option value="">— Select Car —</option>
                            <?php foreach ($cars as $car): ?>
                                <option value="<?= $car['id'] ?>"><?= clean($car['registration_no']) ?> — <?= clean($car['make'] . ' ' . $car['model']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="sale_car_id" id="sale_car_id">
                    </div>
                </div>
            </div>

            <!-- BUYER SECTION -->
            <div class="txn-section" id="buyer-section" style="display:none;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Sale Price (₹) *</label>
                        <div class="input-group">
                            <span class="input-prefix">₹</span>
                            <input type="number" name="sale_price" class="form-control" placeholder="0.00" step="0.01">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Buyer Name</label>
                        <input type="text" name="buyer_name" class="form-control" placeholder="Buyer's full name">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount Received Now (₹)</label>
                    <div class="input-group">
                        <span class="input-prefix">₹</span>
                        <input type="number" name="amount_received" class="form-control" placeholder="Leave blank for full payment" step="0.01">
                    </div>
                    <div class="form-hint">Leave blank if receiving full payment now</div>
                </div>
            </div>

            <!-- CATEGORY SECTION -->
            <div class="txn-section" id="category-section" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Expense Category *</label>
                    <input type="text" name="expense_category" class="form-control" placeholder="e.g., Painting, RTO Charges, Office Rent, Tea & Refreshments" list="categories">
                    <datalist id="categories">
                        <option value="Painting & Polish">
                        <option value="RTO Transfer Charges">
                        <option value="Transport Charges">
                        <option value="Repair & Service">
                        <option value="Insurance">
                        <option value="Commission">
                        <option value="Office Rent">
                        <option value="Electricity">
                        <option value="Tea & Refreshments">
                        <option value="Fuel">
                        <option value="Stationery">
                        <option value="Miscellaneous">
                    </datalist>
                </div>
            </div>

            <!-- PARTNER SECTION -->
            <div class="txn-section" id="partner-section" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Partner *</label>
                    <select name="partner_id" class="form-control">
                        <option value="">— Select Partner —</option>
                        <?php foreach ($partners as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= clean($p['name']) ?> (Share: <?= $p['profit_share_pct'] ?>%)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="txn-section" id="partner-settlement-section" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Settlement Direction *</label>
                    <select name="settlement_direction" class="form-control">
                        <option value="PAY">Pay partner from business</option>
                        <option value="RECEIVE">Receive from partner</option>
                    </select>
                </div>
            </div>

            <!-- EMPLOYEE SECTION -->
            <div class="txn-section" id="employee-section" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Employee *</label>
                    <select name="employee_id" class="form-control">
                        <option value="">— Select Employee —</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= clean($emp['name']) ?> — <?= $emp['role'] ?> (₹<?= number_format($emp['monthly_salary']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- SALARY SECTION -->
            <div class="txn-section" id="salary-section" style="display:none;">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Gross Salary (₹)</label>
                        <input type="number" name="gross_salary" class="form-control" placeholder="0.00" step="0.01">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Advance Deduction (₹)</label>
                        <input type="number" name="advance_deduction" class="form-control" value="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Salary Month / Year</label>
                        <div class="form-row">
                            <select name="salary_month" class="form-control">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                                <?php endfor; ?>
                            </select>
                            <input type="number" name="salary_year" class="form-control" value="<?= date('Y') ?>" min="2020">
                        </div>
                    </div>
                </div>
            </div>

            <!-- PARTY NAME SECTION (new debtor/creditor) -->
            <div class="txn-section" id="party-name-section" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Person / Company Name *</label>
                    <input type="text" name="party_name" class="form-control" placeholder="Full name of person or company">
                </div>
            </div>

            <!-- PARTY SELECT SECTION (existing debtor/creditor) -->
            <div class="txn-section" id="party-select-section" style="display:none;">
                <div class="form-group" id="debtor-select-wrapper">
                    <label class="form-label">Select Debtor *</label>
                    <select name="debtor_id" class="form-control">
                        <option value="">— Select —</option>
                        <?php foreach ($debtors as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= clean($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="creditor-select-wrapper">
                    <label class="form-label">Select Creditor *</label>
                    <select name="creditor_id" class="form-control">
                        <option value="">— Select —</option>
                        <?php foreach ($creditors as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= clean($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- CONTRA SECTION -->
            <div class="txn-section" id="contra-section" style="display:none;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Transfer From *</label>
                        <select name="contra_from" class="form-control">
                            <?php if ($cashAccount): ?><option value="<?= $cashAccount['id'] ?>">💵 Cash Account</option><?php endif; ?>
                            <?php if ($bankAccount): ?><option value="<?= $bankAccount['id'] ?>">🏦 Bank Account</option><?php endif; ?>
                            <?php if ($gstAccount): ?><option value="<?= $gstAccount['id'] ?>">📋 GST Account</option><?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Transfer To *</label>
                        <select name="contra_to" class="form-control">
                            <?php if ($bankAccount): ?><option value="<?= $bankAccount['id'] ?>">🏦 Bank Account</option><?php endif; ?>
                            <?php if ($cashAccount): ?><option value="<?= $cashAccount['id'] ?>">💵 Cash Account</option><?php endif; ?>
                            <?php if ($gstAccount): ?><option value="<?= $gstAccount['id'] ?>">📋 GST Account</option><?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border); display: flex; gap: 12px;">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="ri-save-line"></i> Save Entry
                </button>
                <a href="list.php" class="btn btn-outline btn-lg">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
// Sync car select for sale
document.getElementById('expense_car_select')?.addEventListener('change', function() {
    document.getElementById('sale_car_id').value = this.value;
});

function addPartnerFundingRow() {
    const container = document.getElementById('partner-funding-rows');
    const baseRow = container?.querySelector('.partner-funding-row');
    if (!container || !baseRow) return;
    const clone = baseRow.cloneNode(true);
    clone.querySelectorAll('input').forEach((input) => input.value = '');
    clone.querySelectorAll('select').forEach((select) => select.selectedIndex = 0);
    container.insertBefore(clone, container.lastElementChild);
}

// Show/hide debtor vs creditor select
document.getElementById('transaction_type')?.addEventListener('change', function() {
    const type = this.value;
    const debtorWrapper = document.getElementById('debtor-select-wrapper');
    const creditorWrapper = document.getElementById('creditor-select-wrapper');
    if (debtorWrapper) debtorWrapper.style.display = (type === 'LOAN_RECEIVED') ? 'block' : 'none';
    if (creditorWrapper) creditorWrapper.style.display = (type === 'LOAN_REPAID') ? 'block' : 'none';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
