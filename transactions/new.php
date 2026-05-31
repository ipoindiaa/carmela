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
$accounts = $db->fetchAll(
    "SELECT id, code, name, group_name, sub_group, entity_type
     FROM accounts
     WHERE business_id = ? AND is_active = 1
     ORDER BY group_name, sub_group, code, name",
    [$businessId]
);
$accountIds = array_column($accounts, 'id');

$preselectedAccount = get('account', '');
if ($preselectedAccount === 'cash' && !$cashAccount) $preselectedAccount = '';
if ($preselectedAccount === 'bank' && !$bankAccount) $preselectedAccount = '';
if ($preselectedAccount === 'gst' && !$gstAccount) $preselectedAccount = '';
$preselectedType = get('type', '');
if (!isset(TXN_TYPES[$preselectedType])) $preselectedType = '';

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

            case 'JOURNAL_VOUCHER':
                if (!$paymentAccountId) {
                    throw new Exception('Primary cash/bank/GST account is required.');
                }

                $direction = post('jv_direction', 'PAYMENT');
                $primaryEntryType = $direction === 'RECEIPT' ? 'DR' : 'CR';
                $voucherType = post('jv_voucher_type', 'SPLIT_BILL');
                $lineAccountIds = $_POST['jv_account_id'] ?? [];
                $lineAmounts = $_POST['jv_amount'] ?? [];
                $lineNarrations = $_POST['jv_narration'] ?? [];
                $allocations = [];

                foreach ($lineAccountIds as $i => $accountId) {
                    $lineAmount = round(floatval($lineAmounts[$i] ?? 0), 2);
                    if (!$accountId || $lineAmount <= 0) {
                        continue;
                    }
                    if (!in_array($accountId, $accountIds, true)) {
                        throw new Exception('One selected split account is not valid for this business.');
                    }
                    $allocations[] = [
                        'account_id' => $accountId,
                        'amount' => $lineAmount,
                        'narration' => $lineNarrations[$i] ?? '',
                    ];
                }

                if (count($allocations) < 1) {
                    throw new Exception('Add at least one split line before saving this entry.');
                }

                $voucherId = $engine->saveJournalVoucher(
                    $date,
                    $narration,
                    $paymentAccountId,
                    $primaryEntryType,
                    $amount,
                    $allocations,
                    $voucherType,
                    'POSTED'
                );
                $entryId = $engine->postJournalVoucher($voucherId);
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
    <p class="text-muted">All entries from one screen: simple entry, car entry, salary, loan, and large split bills.</p>
</div>

<div class="entry-helper-strip">
    <div>
        <strong>Operator shortcut</strong>
        <span>For a large bill, select “Large Bill / Split Entry” and complete the split details in the modal.</span>
    </div>
    <button type="button" class="btn btn-outline btn-sm" onclick="selectSplitEntryType()"><i class="ri-bill-line"></i> Add split bill</button>
</div>

<div class="card entry-card">
    <div class="card-body">
        <form method="POST" id="transaction-form">
            <?= csrfField() ?>

            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">What are you doing? *</label>
                    <select name="transaction_type" id="transaction_type" class="form-control searchable-select" data-preselected-type="<?= clean($preselectedType) ?>" required>
                        <option value="">— Select Transaction Type —</option>
                        <optgroup label="Cars">
                            <option value="CAR_PURCHASE">🚗 Bought a Car</option>
                            <option value="CAR_SALE">💰 Sold a Car</option>
                            <option value="CAR_EXPENSE">🔧 Car Repair / Service</option>
                        </optgroup>
                        <optgroup label="Business">
                            <option value="GENERAL_EXPENSE">🧾 Office / Business Expense</option>
                            <option value="JOURNAL_VOUCHER">🧩 Large Bill / Split Entry</option>
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
                    <select name="payment_account" class="form-control searchable-select" id="payment_account">
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
	                                <select name="pf_partner_id[]" class="form-control searchable-select">
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
                        <select name="expense_car_id" id="expense_car_select" class="form-control searchable-select">
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
                    <select name="partner_id" class="form-control searchable-select">
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
                    <select name="settlement_direction" class="form-control searchable-select">
                        <option value="PAY">Pay partner from business</option>
                        <option value="RECEIVE">Receive from partner</option>
                    </select>
                </div>
            </div>

            <!-- EMPLOYEE SECTION -->
            <div class="txn-section" id="employee-section" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Employee *</label>
                    <select name="employee_id" class="form-control searchable-select">
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
                            <select name="salary_month" class="form-control searchable-select">
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
                    <select name="debtor_id" class="form-control searchable-select">
                        <option value="">— Select —</option>
                        <?php foreach ($debtors as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= clean($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="creditor-select-wrapper">
                    <label class="form-label">Select Creditor *</label>
                    <select name="creditor_id" class="form-control searchable-select">
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
                        <select name="contra_from" class="form-control searchable-select">
                            <?php if ($cashAccount): ?><option value="<?= $cashAccount['id'] ?>">💵 Cash Account</option><?php endif; ?>
                            <?php if ($bankAccount): ?><option value="<?= $bankAccount['id'] ?>">🏦 Bank Account</option><?php endif; ?>
                            <?php if ($gstAccount): ?><option value="<?= $gstAccount['id'] ?>">📋 GST Account</option><?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Transfer To *</label>
                        <select name="contra_to" class="form-control searchable-select">
                            <?php if ($bankAccount): ?><option value="<?= $bankAccount['id'] ?>">🏦 Bank Account</option><?php endif; ?>
                            <?php if ($cashAccount): ?><option value="<?= $cashAccount['id'] ?>">💵 Cash Account</option><?php endif; ?>
                            <?php if ($gstAccount): ?><option value="<?= $gstAccount['id'] ?>">📋 GST Account</option><?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- SPLIT BILL / JV SECTION -->
            <div class="txn-section" id="split-bill-section" style="display:none;">
                <div class="split-entry-panel">
                    <div>
                        <h4><i class="ri-bill-line"></i> Large Bill / Split Entry</h4>
                        <p>Example: pay one garage bill once, then split the repair amount across multiple cars or accounts.</p>
                    </div>
                    <div class="split-entry-summary">
                        <span>Total amount</span>
                        <strong id="split-total-display">₹0.00</strong>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="openSplitEntryModal()">
                        <i class="ri-add-box-line"></i> Open split details
                    </button>
                </div>
                <div class="form-hint">Saving this entry creates a posted JV. Corrections should be done through reversal entries.</div>
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

<div class="modal-overlay" id="split-entry-modal">
    <div class="modal modal-wide">
        <div class="modal-header">
            <div>
                <h3><i class="ri-bill-line"></i> Split Large Bill</h3>
                <p class="modal-subtitle">Choose the main cash/bank/GST account, then split the amount across cars or accounts.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('split-entry-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="split-guide">
                <div><strong>1.</strong> Enter the total amount in the main form.</div>
                <div><strong>2.</strong> Search accounts/cars and add split rows here.</div>
                <div><strong>3.</strong> Save only when the remaining amount is zero.</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Entry direction</label>
                    <select name="jv_direction" class="form-control searchable-select" form="transaction-form">
                        <option value="PAYMENT">Payment / Money Out</option>
                        <option value="RECEIPT">Receipt / Money In</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Split type</label>
                    <select name="jv_voucher_type" class="form-control searchable-select" form="transaction-form">
                        <option value="SPLIT_BILL">Large Bill Split</option>
                        <option value="GARAGE_BILL_SPLIT">Garage Bill Across Cars</option>
                        <option value="AUCTION_PURCHASE_SPLIT">Auction / Mela Purchase Split</option>
                        <option value="COMMON_EXPENSE_ALLOCATION">Common Expense Allocation</option>
                        <option value="MIXED_FUNDING">Mixed Funding Entry</option>
                    </select>
                </div>
            </div>

            <div class="split-balance-card" id="split-balance-card">
                <div>
                    <span>Total</span>
                    <strong id="split-modal-total">₹0.00</strong>
                </div>
                <div>
                    <span>Allocated</span>
                    <strong id="split-allocated-total">₹0.00</strong>
                </div>
                <div>
                    <span>Remaining</span>
                    <strong id="split-remaining-total">₹0.00</strong>
                </div>
            </div>

            <div id="split-lines" class="split-lines">
                <div class="split-line-row">
                    <input type="hidden" name="jv_account_id[]" class="split-account-id" form="transaction-form">
                    <div class="split-account-cell">
                        <label class="form-label">Account / Car *</label>
                        <button type="button" class="picker-trigger" onclick="openAccountPicker(this)">
                            <span>Select account/car</span>
                            <i class="ri-search-line"></i>
                        </button>
                    </div>
                    <div>
                        <label class="form-label">Amount *</label>
                        <input type="number" name="jv_amount[]" class="form-control split-line-amount" placeholder="0.00" step="0.01" min="0" form="transaction-form">
                    </div>
                    <div>
                        <label class="form-label">Note</label>
                        <input type="text" name="jv_narration[]" class="form-control" placeholder="e.g. Swift repair" form="transaction-form">
                    </div>
                    <button type="button" class="btn btn-outline btn-icon split-remove-btn" onclick="removeSplitLine(this)" title="Remove row">
                        <i class="ri-delete-bin-line"></i>
                    </button>
                </div>
            </div>

            <button type="button" class="btn btn-outline btn-sm" onclick="addSplitLine()"><i class="ri-add-line"></i> Add another split</button>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('split-entry-modal')">Done</button>
            <button type="button" class="btn btn-primary" onclick="closeModal('split-entry-modal')"><i class="ri-check-line"></i> Use this split</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="account-picker-modal">
    <div class="modal modal-picker">
        <div class="modal-header">
            <div>
                <h3><i class="ri-search-eye-line"></i> Account / Car Search</h3>
                <p class="modal-subtitle">Search by name, code, car number, or group.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('account-picker-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="search" class="form-control picker-search" id="account-picker-search" placeholder="Type: car no, garage, expense, partner..." autocomplete="off">
            <div class="picker-results" id="account-picker-results"></div>
        </div>
    </div>
</div>

<script>
const accountPickerData = <?= json_encode(array_map(static function ($account) {
    $labelParts = array_filter([$account['code'] ?? '', $account['name'] ?? '']);
    return [
        'id' => $account['id'],
        'label' => implode(' — ', $labelParts),
        'group' => trim(($account['group_name'] ?? '') . ' / ' . ($account['sub_group'] ?? ''), ' /'),
        'entity' => $account['entity_type'] ?: 'GENERAL',
        'search' => strtolower(implode(' ', [
            $account['code'] ?? '',
            $account['name'] ?? '',
            $account['group_name'] ?? '',
            $account['sub_group'] ?? '',
            $account['entity_type'] ?? '',
        ])),
    ];
}, $accounts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let activeSplitRow = null;

// Sync car select for sale
document.getElementById('expense_car_select')?.addEventListener('change', function() {
    document.getElementById('sale_car_id').value = this.value;
});

function addPartnerFundingRow() {
    const container = document.getElementById('partner-funding-rows');
    const baseRow = container?.querySelector('.partner-funding-row');
    if (!container || !baseRow) return;
    const clone = baseRow.cloneNode(true);
    clone.querySelectorAll('.searchable-select-wrap').forEach((wrapper) => {
        const select = wrapper.querySelector('select');
        if (!select) return;
        delete select.dataset.searchEnhanced;
        wrapper.replaceWith(select);
    });
    clone.querySelectorAll('input').forEach((input) => input.value = '');
    clone.querySelectorAll('select').forEach((select) => select.selectedIndex = 0);
    container.insertBefore(clone, container.lastElementChild);
    window.enhanceSearchableSelects?.(clone);
}

// Show/hide debtor vs creditor select
document.getElementById('transaction_type')?.addEventListener('change', function() {
    const type = this.value;
    const debtorWrapper = document.getElementById('debtor-select-wrapper');
    const creditorWrapper = document.getElementById('creditor-select-wrapper');
    if (debtorWrapper) debtorWrapper.style.display = (type === 'LOAN_RECEIVED') ? 'block' : 'none';
    if (creditorWrapper) creditorWrapper.style.display = (type === 'LOAN_REPAID') ? 'block' : 'none';
});

document.querySelector('.amount-input')?.addEventListener('input', updateSplitTotals);
document.getElementById('split-lines')?.addEventListener('input', function(event) {
    if (event.target.classList.contains('split-line-amount')) updateSplitTotals();
});
document.getElementById('account-picker-search')?.addEventListener('input', function() {
    renderAccountPickerResults(this.value);
});

function selectSplitEntryType() {
    const select = document.getElementById('transaction_type');
    if (!select) return;
    select.value = 'JOURNAL_VOUCHER';
    select.dispatchEvent(new Event('change'));
    openSplitEntryModal();
}

function openSplitEntryModal() {
    updateSplitTotals();
    openModal('split-entry-modal');
}

function addSplitLine() {
    const container = document.getElementById('split-lines');
    const baseRow = container?.querySelector('.split-line-row');
    if (!container || !baseRow) return;
    const clone = baseRow.cloneNode(true);
    clone.querySelectorAll('input').forEach((input) => input.value = '');
    clone.querySelector('.picker-trigger span').textContent = 'Select account/car';
    container.appendChild(clone);
    updateSplitTotals();
}

function removeSplitLine(button) {
    const rows = document.querySelectorAll('#split-lines .split-line-row');
    const row = button.closest('.split-line-row');
    if (!row) return;
    if (rows.length === 1) {
        row.querySelectorAll('input').forEach((input) => input.value = '');
        row.querySelector('.picker-trigger span').textContent = 'Select account/car';
    } else {
        row.remove();
    }
    updateSplitTotals();
}

function updateSplitTotals() {
    const total = parseFloat(document.querySelector('.amount-input')?.value || '0') || 0;
    let allocated = 0;
    document.querySelectorAll('.split-line-amount').forEach((input) => {
        allocated += parseFloat(input.value || '0') || 0;
    });
    const remaining = total - allocated;
    const balanceCard = document.getElementById('split-balance-card');

    document.getElementById('split-total-display').textContent = formatINR(total);
    document.getElementById('split-modal-total').textContent = formatINR(total);
    document.getElementById('split-allocated-total').textContent = formatINR(allocated);
    document.getElementById('split-remaining-total').textContent = formatINR(remaining);
    balanceCard?.classList.toggle('is-balanced', Math.abs(remaining) < 0.01 && total > 0);
    balanceCard?.classList.toggle('is-warning', Math.abs(remaining) >= 0.01);
}

function openAccountPicker(button) {
    activeSplitRow = button.closest('.split-line-row');
    const search = document.getElementById('account-picker-search');
    if (search) search.value = '';
    renderAccountPickerResults('');
    openModal('account-picker-modal');
    setTimeout(() => search?.focus(), 60);
}

function renderAccountPickerResults(query) {
    const results = document.getElementById('account-picker-results');
    if (!results) return;
    const needle = (query || '').trim().toLowerCase();
    const matches = accountPickerData
        .filter((account) => !needle || account.search.includes(needle))
        .slice(0, 80);

    if (!matches.length) {
        results.innerHTML = '<div class="picker-empty">No match. Check spelling or car number.</div>';
        return;
    }

    results.innerHTML = matches.map((account) => `
        <button type="button" class="picker-result" data-account-id="${account.id}">
            <span>
                <strong>${escapeHtml(account.label)}</strong>
                <small>${escapeHtml(account.group || 'General')}</small>
            </span>
            <em>${escapeHtml(account.entity)}</em>
        </button>
    `).join('');

    results.querySelectorAll('.picker-result').forEach((button) => {
        button.addEventListener('click', function() {
            const selected = accountPickerData.find((account) => account.id === this.dataset.accountId);
            if (!selected || !activeSplitRow) return;
            activeSplitRow.querySelector('.split-account-id').value = selected.id;
            activeSplitRow.querySelector('.picker-trigger span').textContent = selected.label;
            closeModal('account-picker-modal');
        });
    });
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value || '';
    return div.innerHTML;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
