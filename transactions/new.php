<?php
$pageTitle = 'New Entry';
$pageIcon = '<i class="ri-add-circle-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
Auth::requireAnyBookAccess(Auth::getPrimaryBookKeys(), 'write');

// Get writable primary accounts for dropdowns
$writableAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'write');
$cashAccounts = $writableAccountGroups['cash_book'] ?? [];
$bankAccounts = $writableAccountGroups['bank_book'] ?? [];
$writablePrimaryAccounts = array_merge($cashAccounts, $bankAccounts);
$writableAccountIds = array_values(array_filter(array_map(
    static fn($account) => $account['id'] ?? null,
    $writablePrimaryAccounts
)));

$primaryAccountIcon = static function ($entityType) {
    return match ($entityType) {
        'CASH' => '💵',
        'BANK' => '🏦',
        default => '💼',
    };
};
$renderPrimaryAccountOptions = static function (array $accounts, $selectedAccountId = '') use ($primaryAccountIcon) {
    foreach ($accounts as $account) {
        $label = trim(($account['name'] ?? '') . ' (' . ($account['code'] ?? '') . ')');
        $balance = formatAmount($account['current_balance'] ?? 0) . ' ' . ($account['current_balance_type'] ?? 'DR');
        ?>
        <option value="<?= clean($account['id']) ?>" data-account-type="<?= clean($account['entity_type']) ?>" <?= $selectedAccountId === ($account['id'] ?? '') ? 'selected' : '' ?>>
            <?= $primaryAccountIcon($account['entity_type'] ?? '') ?> <?= clean($label) ?> - <?= clean($balance) ?>
        </option>
        <?php
    }
};

$preselectedAccount = get('account', '');
$preselectedAccountId = '';
$preselectedAccountType = match ($preselectedAccount) {
    'cash' => 'CASH',
    'bank' => 'BANK',
    default => '',
};
if (get('account_id', '') !== '') {
    $requestedAccountId = trim((string) get('account_id', ''));
    foreach ($writablePrimaryAccounts as $account) {
        if (($account['id'] ?? '') === $requestedAccountId) {
            $preselectedAccountId = $requestedAccountId;
            break;
        }
    }
} elseif ($preselectedAccountType !== '') {
    foreach ($writablePrimaryAccounts as $account) {
        if (($account['entity_type'] ?? '') === $preselectedAccountType) {
            $preselectedAccountId = $account['id'];
            break;
        }
    }
}
$preselectedType = get('type', '');
if (!isset(TXN_TYPES[$preselectedType])) $preselectedType = '';
$preselectedCarId = get('car_id', '');
$preselectedCar = null;
$preselectedPartyId = get('party_id', '');
$preselectedParty = null;
$preselectedAmount = get('amount', '');
$preselectedNarration = get('narration', '');
$entryCategorySystemCodes = ['CAR-REV', 'PNL', 'GST-PAY', 'GST-RCV', 'BAD-DEBT', 'ADV-WOFF', 'SAL-EXP'];
$entryCategories = $db->fetchAll(
    "SELECT id, code, name, group_name, sub_group
     FROM accounts
     WHERE business_id = ?
       AND entity_type = 'GENERAL'
       AND is_active = 1
       AND group_name IN ('INCOME','EXPENSE')
       AND COALESCE(sub_group, '') <> 'Direct Expenses (Car)'
       AND code NOT IN (" . implode(',', array_fill(0, count($entryCategorySystemCodes), '?')) . ")
     ORDER BY FIELD(group_name, 'INCOME', 'EXPENSE'), code, name",
    array_merge([$businessId], $entryCategorySystemCodes)
);
$entryCategoryOptions = array_map(static function ($account) {
    $isIncome = ($account['group_name'] ?? '') === 'INCOME';
    return [
        'value' => 'CATEGORY_ENTRY',
        'categoryAccountId' => $account['id'],
        'direction' => $isIncome ? 'in' : 'out',
        'title' => $account['name'],
        'desc' => ($isIncome ? 'Jama category' : 'Udhar category') . ' - posts to ' . $account['code'],
        'icon' => $isIncome ? 'ri-arrow-down-circle-line' : 'ri-arrow-up-circle-line',
        'flow' => $isIncome ? 'in' : 'out',
        'group' => $isIncome ? 'Jama Categories' : 'Udhar Categories',
        'text' => strtolower(trim(($account['name'] ?? '') . ' ' . ($account['code'] ?? '') . ' ' . ($account['sub_group'] ?? ''))),
    ];
}, $entryCategories);

$dbExpenseCategories = $db->fetchAll(
    "SELECT DISTINCT name FROM accounts
     WHERE business_id = ?
       AND group_name = 'EXPENSE'
       AND is_active = 1
       AND entity_type = 'GENERAL'
       AND COALESCE(sub_group, '') <> 'Direct Expenses (Car)'
     ORDER BY name",
    [$businessId]
);
$defaultExpenseCategories = [
    'Painting & Polish',
    'RTO Transfer Charges',
    'Transport Charges',
    'Repair & Service',
    'Insurance',
    'Commission',
    'Office Rent',
    'Electricity',
    'Tea & Refreshments',
    'Fuel',
    'Stationery',
    'Miscellaneous'
];
$expenseDatalistNames = array_unique(array_merge(
    array_column($dbExpenseCategories, 'name'),
    $defaultExpenseCategories
));

if ($preselectedCarId !== '') {
    $preselectedCar = $db->fetch(
        "SELECT id, registration_no, make, model
         FROM cars
         WHERE id = ? AND business_id = ?",
        [$preselectedCarId, $businessId]
    );
    if (!$preselectedCar) {
        $preselectedCarId = '';
    }
}

if ($preselectedPartyId !== '') {
    $preselectedParty = $db->fetch(
        "SELECT id, name, type FROM debtors_creditors WHERE id = ? AND business_id = ? AND is_active = 1",
        [$preselectedPartyId, $businessId]
    );
    if (!$preselectedParty) {
        $preselectedPartyId = '';
    }
}

$resolveRtoRecord = function () use ($db, $businessId, $userId) {
    $rtoType = trim((string) post('rto_type_name'));
    $carId = trim((string) post('rto_car_id'));
    $partyName = trim((string) post('rto_party_name'));
    $agentName = trim((string) post('rto_agent_name'));
    $isRecoverable = post('rto_is_recoverable', '1') === '0' ? 0 : 1;
    $narration = trim((string) post('narration'));

    if ($carId === '') {
        throw new Exception('Select car for this RTO entry.');
    }
    $car = $db->fetch("SELECT id FROM cars WHERE id = ? AND business_id = ?", [$carId, $businessId]);
    if (!$car) {
        throw new Exception('Select a valid car for RTO entry.');
    }
    if ($rtoType === '') {
        throw new Exception('Enter RTO work name.');
    }

    $record = [
        'id' => Database::uuid(),
        'business_id' => $businessId,
        'car_id' => $carId,
        'rto_type' => $rtoType,
        'status' => 'IN_PROGRESS',
        'party_name' => $partyName,
        'agent_name' => $agentName,
        'narration' => $narration,
        'is_recoverable' => $isRecoverable,
        'created_by' => $userId,
    ];
    $db->insert('rto_records', $record);
    return $record;
};

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $type = post('transaction_type');
    $date = post('entry_date');
    $amount = parseDecimalInput(post('amount'));
    $gstAmount = 0.0;
    $narration = post('narration');
    $paymentAccountId = post('payment_account');

    try {
        $entryId = null;
        $attachmentCarId = null;
        $rtoRecord = null;

        if ($type === '') {
            throw new Exception('Please select what kind of entry this is.');
        }

        if ($paymentAccountId && !in_array($paymentAccountId, $writableAccountIds, true)) {
            throw new Exception('You do not have write access to that book/account.');
        }

        switch ($type) {
            case 'CATEGORY_ENTRY':
                $categoryAccountId = post('dynamic_category_account_id');
                $categoryDirection = post('dynamic_category_direction');
                $entryId = $engine->categoryEntry($categoryAccountId, $categoryDirection, $amount, $date, $paymentAccountId, $narration, $gstAmount);
                break;

            case 'CAR_PURCHASE':
                $carId = post('car_id');
                if (empty($carId) || $carId === 'new') {
                    // Create new car first
                    $carId = Database::uuid();
                    $carRegNo = normalizeRegistrationNo(post('car_reg_no'));
                    if (!isValidRegistrationNo($carRegNo)) {
                        throw new Exception('Registration number must be like GJ05AA0001, with exactly 4 digits at the end.');
                    }
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
                        'purchase_paid_amount' => parseDecimalInput(post('purchase_paid_now', $amount)),
                        'has_second_key' => post('has_second_key') === '1' ? 1 : 0,
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
                        $pfAmount = parseDecimalInput($pfAmounts[$i] ?? 0);
                        if (!empty($pid) && $pfAmount > 0) {
                            $partnerFunding[] = [
                                'partner_id' => $pid,
                                'amount' => $pfAmount,
                                'profit_share_pct' => $pfProfitShares[$i] ?? null,
                            ];
                        }
                    }
                }

                if ($carId && $gstAmount > 0) {
                    $db->query("UPDATE cars SET purchase_price = ? WHERE id = ? AND business_id = ?", [max(0, $amount - $gstAmount), $carId, $businessId]);
                }
                $entryId = $engine->carPurchase($carId, $amount, $date, $paymentAccountId, $narration, $partnerFunding, $gstAmount, post('seller_name'), parseDecimalInput(post('purchase_paid_now', $amount)));
                $attachmentCarId = $carId;
                break;

            case 'CAR_SALE':
                $carId = post('sale_car_id');
                $salePrice = parseDecimalInput(post('sale_price'));
                $commissionAmount = parseDecimalInput(post('sale_commission_amount'));
                $amountReceived = parseDecimalInput(post('amount_received') ?: ($salePrice + $commissionAmount));
                $buyerName = post('buyer_name');
                $entryId = $engine->carSale($carId, $salePrice, $date, $paymentAccountId, $narration, $buyerName, $amountReceived, $gstAmount, $commissionAmount);
                $attachmentCarId = $carId;
                break;

            case 'CAR_EXPENSE':
                $carId = post('expense_car_id');
                $category = post('expense_category');
                $entryId = $engine->carExpense($carId, $amount, $date, $paymentAccountId, $category, $narration, $gstAmount);
                break;

            case 'GENERAL_EXPENSE':
                $category = post('expense_category');
                $entryId = $engine->generalExpense($amount, $date, $paymentAccountId, $category, $narration, $gstAmount);
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
                $grossSalary = parseDecimalInput(post('gross_salary'));
                $advanceDeduct = parseDecimalInput(post('advance_deduction', 0));
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
                $entryId = $engine->loanReceived($partyId, $amount, $date, $paymentAccountId, $narration, post('linked_car_id'));
                break;

            case 'LOAN_TAKEN':
                $partyName = post('party_name');
                $entryId = $engine->loanTaken($partyName, $amount, $date, $paymentAccountId, $narration);
                break;

            case 'LOAN_REPAID':
                $partyId = post('creditor_id');
                $entryId = $engine->loanRepaid($partyId, $amount, $date, $paymentAccountId, $narration, post('linked_car_id'));
                break;

            case 'RTO_EXPENSE':
                $rtoRecord = $resolveRtoRecord();
                $entryId = $engine->rtoExpense($rtoRecord['id'], $rtoRecord['car_id'], $amount, $date, $paymentAccountId, $narration);
                break;

            case 'RTO_RECOVERY':
                $rtoRecord = $resolveRtoRecord();
                $entryId = $engine->rtoRecovery($rtoRecord['id'], $amount, $date, $paymentAccountId, $narration);
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

            case 'JOURNAL_VOUCHER':
                if (!$paymentAccountId) {
                    throw new Exception('Primary cash/bank account is required.');
                }

                $direction = post('jv_direction', 'PAYMENT');
                $primaryEntryType = $direction === 'RECEIPT' ? 'DR' : 'CR';
                $voucherType = post('jv_voucher_type', 'SPLIT_BILL');
                $lineAccountIds = $_POST['jv_account_id'] ?? [];
                $lineAmounts = $_POST['jv_amount'] ?? [];
                $lineNarrations = $_POST['jv_narration'] ?? [];
                $allocations = [];

                foreach ($lineAccountIds as $i => $accountId) {
                $lineAmount = round(parseDecimalInput($lineAmounts[$i] ?? 0), 2);
                    if (!$accountId || $lineAmount <= 0) {
                        continue;
                    }
                    $accountExists = $db->fetch(
                        "SELECT id FROM accounts WHERE business_id = ? AND id = ? AND is_active = 1",
                        [$businessId, $accountId]
                    );
                    if (!$accountExists) {
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

        $uploadWarning = '';
        if ($entryId) {
            try {
                uploadEntityAttachments($businessId, 'JOURNAL_ENTRY', $entryId, 'VOUCHER', 'vouchers', Auth::user('user_id'), 'vouchers');
                if ($type === 'CAR_PURCHASE' && $attachmentCarId) {
                    uploadEntityAttachments($businessId, 'CAR', $attachmentCarId, 'SELLER', 'seller_images', Auth::user('user_id'), 'images');
                }
                if ($type === 'CAR_SALE' && $attachmentCarId) {
                    uploadEntityAttachments($businessId, 'CAR', $attachmentCarId, 'BUYER', 'buyer_images', Auth::user('user_id'), 'images');
                }
                if (in_array($type, ['RTO_EXPENSE', 'RTO_RECOVERY'], true) && !empty($rtoRecord['id'])) {
                    uploadEntityAttachments($businessId, 'RTO_RECORD', $rtoRecord['id'], 'RTO_DOC', 'rto_docs', Auth::user('user_id'), 'vouchers');
                }
            } catch (Exception $uploadError) {
                $uploadWarning = transactionTypeLabel($type, ['car_id' => post('linked_car_id') ?: post('sale_car_id') ?: post('expense_car_select')]) . ' entry posted successfully, but upload failed: ' . $uploadError->getMessage();
            }
        }

        if ($uploadWarning !== '') {
            setFlash('warning', $uploadWarning);
        } else {
            setFlash('success', transactionTypeLabel($type, ['car_id' => post('linked_car_id') ?: post('sale_car_id') ?: post('expense_car_select')]) . ' entry posted successfully!');
        }
        redirect('list.php');
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}
?>

<div class="page-header">
    <h1><i class="ri-add-circle-line"></i> New Entry</h1>
    <div class="entry-header-actions">
        <span><i class="ri-arrow-down-circle-line"></i> Jama</span>
        <span><i class="ri-arrow-up-circle-line"></i> Payments</span>
        <span><i class="ri-bill-line"></i> Split Bill</span>
    </div>
</div>

<div class="simple-entry-switch">
    <button type="button" class="simple-entry-option money-in" data-money-flow="in">
        <i class="ri-arrow-down-circle-line"></i>
        <span>Receive / Jama</span>
        <strong>Business received money</strong>
    </button>
    <button type="button" class="simple-entry-option money-out" data-money-flow="out">
        <i class="ri-arrow-up-circle-line"></i>
        <span>Payments</span>
        <strong>Business paid money</strong>
    </button>
    <button type="button" class="simple-entry-option split" onclick="selectSplitEntryType()">
        <i class="ri-bill-line"></i>
        <span>Split Bill</span>
        <strong>One bill, many cars/accounts</strong>
    </button>
</div>

<section class="entry-workstation">
    <form method="POST" id="transaction-form" class="entry-form" enctype="multipart/form-data">
        <?= csrfField() ?>

        <div class="entry-core-panel">
            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">What are you doing? *</label>
                    <select name="transaction_type" id="transaction_type" class="native-transaction-select" data-preselected-type="<?= clean($preselectedType) ?>">
                        <option value="">— Select Transaction Type —</option>
                        <option value="CATEGORY_ENTRY" data-flow="both" data-icon="ri-price-tag-3-line" data-title="Receive / Jama or Payments Category" data-desc="Admin-defined category account.">Receive / Jama or Payments Category</option>
                        <optgroup label="Cars">
                            <option value="CAR_PURCHASE" data-flow="out" data-icon="ri-car-line" data-title="Bought a Car" data-desc="Business paid money to buy stock.">Bought a Car</option>
                            <option value="CAR_SALE" data-flow="in" data-icon="ri-money-rupee-circle-line" data-title="Sold a Car" data-desc="Business received money from buyer.">Sold a Car</option>
                            <option value="CAR_EXPENSE" data-flow="out" data-icon="ri-tools-line" data-title="Car Repair / Service" data-desc="Business paid expense for a car.">Car Repair / Service</option>
                            <option value="RTO_EXPENSE" data-flow="out" data-icon="ri-file-shield-2-line" data-title="RTO Expense" data-desc="Pay RTO fee or agent amount for a specific car.">RTO Expense</option>
                            <option value="RTO_RECOVERY" data-flow="in" data-icon="ri-refund-2-line" data-title="RTO Recovery Received" data-desc="Receive RTO money from buyer/customer for a specific car.">RTO Recovery Received</option>
                        </optgroup>
                        <optgroup label="Business">
                            <option value="GENERAL_EXPENSE" data-flow="out" data-icon="ri-receipt-line" data-title="Office / Business Expense" data-desc="Business paid normal running expense.">Office / Business Expense</option>
                            <option value="JOURNAL_VOUCHER" data-flow="both" data-icon="ri-bill-line" data-title="Large Bill Split" data-desc="One big bill that goes into many cars or expense accounts.">Large Bill Split</option>
                            <option value="CONTRA_TRANSFER" data-flow="both" data-icon="ri-arrow-left-right-line" data-title="Cash / Bank Transfer" data-desc="Move money between business accounts.">Cash / Bank Transfer</option>
                        </optgroup>
                        <optgroup label="Partners">
                            <option value="PARTNER_INVEST" data-flow="in" data-icon="ri-briefcase-4-line" data-title="Partner Added Money" data-desc="Business received money from partner.">Partner Added Money</option>
                            <option value="PARTNER_WITHDRAW" data-flow="out" data-icon="ri-hand-coin-line" data-title="Partner Took Money" data-desc="Business paid money to partner.">Partner Took Money</option>
                            <option value="PARTNER_SETTLEMENT" data-flow="both" data-icon="ri-shake-hands-line" data-title="Partner Settlement" data-desc="Pay partner or receive from partner.">Partner Settlement</option>
                        </optgroup>
                        <optgroup label="Employees">
                            <option value="SALARY_PAYMENT" data-flow="out" data-icon="ri-wallet-3-line" data-title="Paid Salary" data-desc="Business paid salary to employee.">Paid Salary</option>
                            <option value="EMPLOYEE_ADVANCE" data-flow="out" data-icon="ri-user-received-line" data-title="Employee Took Advance" data-desc="Business gave advance to employee.">Employee Took Advance</option>
                        </optgroup>
                        <optgroup label="Loans & Debts">
                            <option value="LOAN_GIVEN" data-flow="out" data-icon="ri-arrow-up-circle-line" data-title="Lent Money to Someone" data-desc="Business gave money to debtor.">Lent Money to Someone</option>
                            <option value="LOAN_RECEIVED" data-flow="in" data-icon="ri-arrow-down-circle-line" data-title="Car Payment Clearing" data-desc="Buyer or debtor paid a pending amount. Use this for later car-payment chunks too.">Car Payment Clearing</option>
                            <option value="LOAN_TAKEN" data-flow="in" data-icon="ri-download-cloud-2-line" data-title="Borrowed Money" data-desc="Business received loan from creditor.">Borrowed Money</option>
                            <option value="LOAN_REPAID" data-flow="out" data-icon="ri-upload-cloud-2-line" data-title="Seller Payment Clearing" data-desc="Business cleared a pending seller or creditor amount, including car purchase chunks.">Seller Payment Clearing</option>
                        </optgroup>
                    </select>
                    <div class="txn-type-picker" id="txn-type-picker">
                        <button type="button" class="txn-type-trigger" id="txn-type-trigger" aria-haspopup="listbox" aria-expanded="false">
                            <span class="txn-type-trigger-icon"><i class="ri-list-check-2"></i></span>
                            <span class="txn-type-trigger-copy">
                                <strong>Select entry type</strong>
                                <small>Choose Receive/Jama or Payments first for a shorter list.</small>
                            </span>
                            <i class="ri-arrow-down-s-line"></i>
                        </button>
                        <div class="txn-type-menu" id="txn-type-menu" hidden>
                            <input type="search" class="txn-type-search" id="txn-type-search" placeholder="Search entry type..." autocomplete="off">
                            <div class="txn-type-list" id="txn-type-list" role="listbox"></div>
                        </div>
                    </div>
                    <div class="form-error" id="txn-type-error" hidden>Please choose Receive/Jama, Payments, or Split Bill.</div>
                    <input type="hidden" name="dynamic_category_account_id" id="dynamic_category_account_id">
                    <input type="hidden" name="dynamic_category_direction" id="dynamic_category_direction">
                </div>
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group" id="payment-account-group">
                    <label class="form-label" id="payment-account-label">Payment Account *</label>
                    <select name="payment_account" class="form-control searchable-select" id="payment_account">
                        <?php $renderPrimaryAccountOptions($writablePrimaryAccounts, $preselectedAccountId); ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" id="amount-group">
                    <label class="form-label">Amount (₹) *</label>
                    <div class="input-group">
                        <span class="input-prefix">₹</span>
                        <input type="text" name="amount" class="form-control amount-input currency-input" placeholder="0" inputmode="decimal" autocomplete="off" value="<?= clean($preselectedAmount) ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Narration / Description *</label>
                    <input type="text" name="narration" class="form-control" placeholder="Brief description of this entry" value="<?= clean($preselectedNarration) ?>" required>
                </div>
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
                        <input type="text" name="car_reg_no" class="form-control registration-input" placeholder="e.g., GJ05AA0001" maxlength="11" pattern="[A-Za-z]{2}[0-9]{2}[A-Za-z]{1,3}[0-9]{4}" title="Use format like GJ05AA0001. Last 4 characters must be digits.">
                        <div class="form-hint">Last 4 digits must stay exactly 4 numbers, like <strong>0001</strong>.</div>
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
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Seller Name</label>
                        <input type="text" name="seller_name" class="form-control" placeholder="Seller's full name">
                        <div class="form-hint">Required if you are not paying full purchase amount now.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount Paid Now (₹)</label>
                        <div class="input-group">
                            <span class="input-prefix">₹</span>
                            <input type="text" name="purchase_paid_now" class="form-control currency-input" placeholder="Leave blank for full payment" inputmode="decimal" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Second Key Available?</label>
                    <select name="has_second_key" class="form-control searchable-select">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Seller Images</label>
                    <input type="file" name="seller_images[]" class="form-control" accept="image/*" multiple>
                    <div class="form-hint">Optional. Upload seller-side car photos or documents.</div>
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
                                    <input type="hidden" name="pf_partner_id[]" class="pf-partner-id">
	                                <button type="button" class="picker-trigger picker-trigger-wide pf-partner-trigger" onclick="openEntityPicker('car_partner', this)">
                                        <span>Select partner</span>
                                        <i class="ri-search-line"></i>
                                    </button>
                            </div>
	                            <div class="form-group">
	                                <label class="form-label">Amount (₹)</label>
	                                <input type="text" name="pf_amount[]" class="form-control currency-input" placeholder="0" inputmode="decimal" autocomplete="off">
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
            <div class="txn-section" id="car-select-section" style="display:none;" data-preselected-expense-car="<?= clean($preselectedCarId) ?>">
                <h4 style="margin: 20px 0 16px; padding-top: 20px; border-top: 1px solid var(--border); color: var(--accent-blue);">
                    <i class="ri-car-line"></i> Select Car
                </h4>
                <?php if ($preselectedCar): ?>
                    <div class="alert alert-info" id="preselected-car-note" style="display:none; margin-bottom: 14px;">
                        <i class="ri-car-line"></i>
                        Expense will be added in <strong><?= clean($preselectedCar['registration_no']) ?></strong><?= !empty(trim(($preselectedCar['make'] ?? '') . ' ' . ($preselectedCar['model'] ?? ''))) ? ' - ' . clean(trim(($preselectedCar['make'] ?? '') . ' ' . ($preselectedCar['model'] ?? ''))) : '' ?>.
                    </div>
                <?php endif; ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Car *</label>
                        <input type="hidden" name="expense_car_id" id="expense_car_select" value="<?= clean($preselectedType === 'CAR_EXPENSE' ? $preselectedCarId : '') ?>">
                        <button type="button" class="picker-trigger picker-trigger-wide" id="car-picker-trigger" onclick="openEntityPicker('car', this)">
                            <span><?= $preselectedCar && $preselectedType === 'CAR_EXPENSE' ? clean($preselectedCar['registration_no']) : 'Select car' ?></span>
                            <i class="ri-search-line"></i>
                        </button>
                        <input type="hidden" name="sale_car_id" id="sale_car_id" value="<?= clean($preselectedType === 'CAR_SALE' ? $preselectedCarId : '') ?>">
                    </div>
                </div>
            </div>

            <!-- BUYER SECTION -->
            <div class="txn-section" id="buyer-section" style="display:none;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Sell Car Amount (₹) *</label>
                        <div class="input-group">
                            <span class="input-prefix">₹</span>
                            <input type="text" name="sale_price" class="form-control currency-input" placeholder="0" inputmode="decimal" autocomplete="off">
                        </div>
                        <div class="form-hint">This is the actual car selling amount.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Commission Income (₹)</label>
                        <div class="input-group">
                            <span class="input-prefix">₹</span>
                            <input type="text" name="sale_commission_amount" class="form-control currency-input" placeholder="0" inputmode="decimal" autocomplete="off">
                        </div>
                        <div class="form-hint">Business earning on this sold car.</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Buyer Name</label>
                        <input type="text" name="buyer_name" class="form-control" placeholder="Buyer's full name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount Received Now (₹)</label>
                        <div class="input-group">
                            <span class="input-prefix">₹</span>
                            <input type="text" name="amount_received" class="form-control currency-input" placeholder="Leave blank for full payment" inputmode="decimal" autocomplete="off">
                        </div>
                        <div class="form-hint">Leave blank to receive full car amount + commission now.</div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Buyer Images</label>
                    <input type="file" name="buyer_images[]" class="form-control" accept="image/*" multiple>
                    <div class="form-hint">Optional. Upload buyer-side delivery or party photos.</div>
                </div>
            </div>

            <!-- CATEGORY SECTION -->
            <div class="txn-section" id="category-section" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Expense Category *</label>
                    <input type="text" name="expense_category" class="form-control" placeholder="e.g., Painting, RTO Charges, Office Rent, Tea & Refreshments" list="categories">
                    <datalist id="categories">
                        <?php foreach ($expenseDatalistNames as $name): ?>
                            <option value="<?= clean($name) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <!-- PARTNER SECTION -->
            <div class="txn-section" id="partner-section" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Partner *</label>
                    <input type="hidden" name="partner_id" id="partner_id">
                    <button type="button" class="picker-trigger picker-trigger-wide" id="partner-picker-trigger" onclick="openEntityPicker('main_partner', this)">
                        <span>Select partner</span>
                        <i class="ri-search-line"></i>
                    </button>
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
                    <input type="hidden" name="employee_id" id="employee_id">
                    <button type="button" class="picker-trigger picker-trigger-wide" id="employee-picker-trigger" onclick="openEntityPicker('employee', this)">
                        <span>Select employee</span>
                        <i class="ri-search-line"></i>
                    </button>
                </div>
            </div>

            <!-- SALARY SECTION -->
            <div class="txn-section" id="salary-section" style="display:none;">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Gross Salary (₹)</label>
                        <input type="text" name="gross_salary" class="form-control currency-input" placeholder="0" inputmode="decimal" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Advance Deduction (₹)</label>
                        <input type="text" name="advance_deduction" class="form-control currency-input" value="0" inputmode="decimal" autocomplete="off">
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
                    <label class="form-label" id="debtor-label">Select Debtor *</label>
                    <input type="hidden" name="debtor_id" id="debtor_id" value="<?= clean($preselectedType === 'LOAN_RECEIVED' ? $preselectedPartyId : '') ?>">
                    <button type="button" class="picker-trigger picker-trigger-wide" id="debtor-picker-trigger" onclick="openEntityPicker('debtor', this)">
                        <span><?= $preselectedType === 'LOAN_RECEIVED' && $preselectedParty ? clean($preselectedParty['name']) : 'Select debtor / buyer' ?></span>
                        <i class="ri-search-line"></i>
                    </button>
                </div>
                <div class="form-group" id="creditor-select-wrapper">
                    <label class="form-label" id="creditor-label">Select Creditor *</label>
                    <input type="hidden" name="creditor_id" id="creditor_id" value="<?= clean($preselectedType === 'LOAN_REPAID' ? $preselectedPartyId : '') ?>">
                    <button type="button" class="picker-trigger picker-trigger-wide" id="creditor-picker-trigger" onclick="openEntityPicker('creditor', this)">
                        <span><?= $preselectedType === 'LOAN_REPAID' && $preselectedParty ? clean($preselectedParty['name']) : 'Select creditor / seller' ?></span>
                        <i class="ri-search-line"></i>
                    </button>
                </div>
                <div class="form-group">
                    <label class="form-label" id="linked-car-label">Linked Car</label>
                    <input type="hidden" name="linked_car_id" id="linked_car_id" value="<?= clean(in_array($preselectedType, ['LOAN_RECEIVED', 'LOAN_REPAID'], true) ? $preselectedCarId : '') ?>">
                    <button type="button" class="picker-trigger picker-trigger-wide" id="payment-car-picker-trigger" onclick="openEntityPicker('payment_car', this)">
                        <span><?= $preselectedCar && in_array($preselectedType, ['LOAN_RECEIVED', 'LOAN_REPAID'], true) ? clean($preselectedCar['registration_no']) : 'Select car if this payment belongs to one car' ?></span>
                        <i class="ri-search-line"></i>
                    </button>
                    <div class="form-hint" id="linked-car-hint">Use this when buyer or seller chunk payment belongs to a specific car.</div>
                </div>
            </div>

            <!-- RTO SECTION -->
            <div class="txn-section" id="rto-section" style="display:none;">
                <h4 style="margin: 20px 0 16px; padding-top: 20px; border-top: 1px solid var(--border); color: var(--accent-blue);">
                    <i class="ri-file-shield-2-line"></i> RTO Entry
                </h4>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Car *</label>
                        <input type="hidden" name="rto_car_id" id="rto_car_id" value="<?= clean($preselectedCarId) ?>">
                        <button type="button" class="picker-trigger picker-trigger-wide" id="rto-car-picker-trigger" onclick="openEntityPicker('rto_car', this)">
                            <span><?= $preselectedCar ? clean($preselectedCar['registration_no']) : 'Select car for RTO' ?></span>
                            <i class="ri-search-line"></i>
                        </button>
                    </div>
                    <div class="form-group">
                        <label class="form-label">RTO Work *</label>
                        <input type="text" name="rto_type_name" class="form-control" placeholder="Transfer, NOC, passing, tax">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Buyer / Customer</label>
                        <input type="text" name="rto_party_name" class="form-control" placeholder="Who is giving RTO money">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Agent / Office</label>
                        <input type="text" name="rto_agent_name" class="form-control" placeholder="Who is receiving RTO payment">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Recovery Type</label>
                    <select name="rto_is_recoverable" class="form-control searchable-select">
                        <option value="1">Buyer will pay RTO</option>
                        <option value="0">Business cost only</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">RTO Images / Vouchers</label>
                    <input type="file" name="rto_docs[]" class="form-control" accept="image/*,application/pdf" multiple>
                    <div class="form-hint">Upload agent slips, RTO receipts, transfer papers, or proof images for this car.</div>
                </div>
            </div>

            <!-- CONTRA SECTION -->
            <div class="txn-section" id="contra-section" style="display:none;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Transfer From *</label>
                        <select name="contra_from" class="form-control searchable-select">
                            <?php $renderPrimaryAccountOptions($writablePrimaryAccounts); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Transfer To *</label>
                        <select name="contra_to" class="form-control searchable-select">
                            <?php $renderPrimaryAccountOptions($writablePrimaryAccounts); ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- SPLIT BILL / JV SECTION -->
            <div class="txn-section" id="split-bill-section" style="display:none;">
                <div class="split-entry-panel">
                    <div>
                        <h4><i class="ri-bill-line"></i> Large Bill Split</h4>
                        <p>Use this when one bill is paid once, but that amount belongs to many cars or other expense accounts.</p>
                    </div>
                    <div class="split-entry-summary">
                        <span>Bill total</span>
                        <strong id="split-total-display">₹0</strong>
                    </div>
                    <div class="split-entry-summary">
                        <span>Split rows</span>
                        <strong id="split-line-count">0</strong>
                    </div>
                </div>
                <div class="form-hint">This saves one main daily transaction. On open, user will see the full bill split summary and every related account impact.</div>

                <div class="split-guide" style="margin-top:16px;">
                    <div><strong>1.</strong> Enter the full bill amount above.</div>
                    <div><strong>2.</strong> Add where this bill should go: cars or other accounts.</div>
                    <div><strong>3.</strong> Save only when remaining amount is zero.</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Bill Direction</label>
                        <select name="jv_direction" class="form-control searchable-select">
                            <option value="PAYMENT">Payment / Money Out</option>
                            <option value="RECEIPT">Receipt / Money In</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bill Type</label>
                        <select name="jv_voucher_type" class="form-control searchable-select">
                            <option value="SPLIT_BILL">General Large Bill</option>
                            <option value="GARAGE_BILL_SPLIT">Garage Bill Across Cars</option>
                            <option value="AUCTION_PURCHASE_SPLIT">Auction / Mela Bill</option>
                            <option value="COMMON_EXPENSE_ALLOCATION">Common Expense Allocation</option>
                            <option value="MIXED_FUNDING">Mixed Funding Entry</option>
                        </select>
                    </div>
                </div>

                <div class="split-balance-card" id="split-balance-card">
                    <div>
                        <span>Total</span>
                        <strong id="split-modal-total">₹0</strong>
                    </div>
                    <div>
                        <span>Allocated</span>
                        <strong id="split-allocated-total">₹0</strong>
                    </div>
                    <div>
                        <span>Remaining</span>
                        <strong id="split-remaining-total">₹0</strong>
                    </div>
                </div>

                <div id="split-lines" class="split-lines">
                    <div class="split-line-row">
                        <input type="hidden" name="jv_account_id[]" class="split-account-id">
                        <div class="split-account-cell">
                            <label class="form-label">Where should this part go? *</label>
                            <button type="button" class="picker-trigger" onclick="openAccountPicker(this)">
                                <span>Select car or account</span>
                                <i class="ri-search-line"></i>
                            </button>
                        </div>
                        <div>
                            <label class="form-label">Amount *</label>
                            <input type="text" name="jv_amount[]" class="form-control split-line-amount currency-input" placeholder="0" inputmode="decimal" autocomplete="off">
                        </div>
                        <div>
                            <label class="form-label">Short note</label>
                            <input type="text" name="jv_narration[]" class="form-control" placeholder="e.g. Denting for Swift">
                        </div>
                        <button type="button" class="btn btn-outline btn-icon split-remove-btn" onclick="removeSplitLine(this)" title="Remove row">
                            <i class="ri-delete-bin-line"></i>
                        </button>
                    </div>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="addSplitLine()"><i class="ri-add-line"></i> Add Another Line</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="focusFirstSplitLine()"><i class="ri-focus-3-line"></i> Continue Split</button>
                </div>
            </div>

            <div class="attachment-upload-panel">
                <div class="attachment-upload-copy">
                    <label class="form-label"><i class="ri-attachment-2"></i> Voucher / Bill Photos</label>
                    <div class="form-hint">Optional proof for this entry. Images and PDFs can be shared from transaction detail.</div>
                </div>
                <label class="voucher-dropzone">
                    <input type="file" name="vouchers[]" accept="image/*,application/pdf" multiple>
                    <span class="voucher-dropzone-icon"><i class="ri-upload-cloud-2-line"></i></span>
                    <span>
                        <strong>Upload vouchers</strong>
                        <small class="voucher-file-status">Images or PDF</small>
                    </span>
                </label>
            </div>

            <div class="entry-form-actions">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="ri-save-line"></i> Save Entry
                </button>
                <a href="list.php" class="btn btn-outline btn-lg" data-smart-back="1">Cancel</a>
            </div>
    </form>
</section>

<div class="modal-overlay" id="split-entry-modal-legacy" style="display:none;">
    <div class="modal modal-wide">
        <div class="modal-header">
            <div>
                <h3><i class="ri-bill-line"></i> Split Large Bill</h3>
                <p class="modal-subtitle">Choose the main cash/bank account, then split the amount across cars or accounts.</p>
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
                    <select name="jv_direction_modal" class="form-control searchable-select">
                        <option value="PAYMENT">Payment / Money Out</option>
                        <option value="RECEIPT">Receipt / Money In</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Split type</label>
                    <select name="jv_voucher_type_modal" class="form-control searchable-select">
                        <option value="SPLIT_BILL">Large Bill Split</option>
                        <option value="GARAGE_BILL_SPLIT">Garage Bill Across Cars</option>
                        <option value="AUCTION_PURCHASE_SPLIT">Auction / Mela Purchase Split</option>
                        <option value="COMMON_EXPENSE_ALLOCATION">Common Expense Allocation</option>
                        <option value="MIXED_FUNDING">Mixed Funding Entry</option>
                    </select>
                </div>
            </div>

            <div class="split-balance-card" id="split-balance-card-legacy">
                <div>
                    <span>Total</span>
                    <strong id="split-modal-total-legacy">₹0</strong>
                </div>
                <div>
                    <span>Allocated</span>
                    <strong id="split-allocated-total-legacy">₹0</strong>
                </div>
                <div>
                    <span>Remaining</span>
                    <strong id="split-remaining-total-legacy">₹0</strong>
                </div>
            </div>

            <div id="split-lines-legacy" class="split-lines">
                <div class="split-line-row">
                    <input type="hidden" name="jv_account_id_modal[]" class="split-account-id">
                    <div class="split-account-cell">
                        <label class="form-label">Account / Car *</label>
                        <button type="button" class="picker-trigger" onclick="openAccountPicker(this)">
                            <span>Select account/car</span>
                            <i class="ri-search-line"></i>
                        </button>
                    </div>
                    <div>
                        <label class="form-label">Amount *</label>
                        <input type="text" name="jv_amount_modal[]" class="form-control split-line-amount currency-input" placeholder="0" inputmode="decimal" autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label">Note</label>
                        <input type="text" name="jv_narration_modal[]" class="form-control" placeholder="e.g. Swift repair">
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

<div class="modal-overlay" id="entity-picker-modal">
    <div class="modal modal-picker">
        <div class="modal-header">
            <div>
                <h3><i class="ri-search-eye-line"></i> Search Records</h3>
                <p class="modal-subtitle" id="entity-picker-subtitle">Search by name, number, phone, or role.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('entity-picker-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="search" class="form-control picker-search" id="entity-picker-search" placeholder="Type to search..." autocomplete="off">
            <div class="picker-results" id="entity-picker-results"></div>
        </div>
    </div>
</div>

<script>
let activeSplitRow = null;
let activeEntityPicker = null;
const entityPickerConfig = {
    payment_car: {
        title: 'Search Cars',
        subtitle: 'Search the car linked to this buyer or seller payment.',
        inputId: 'linked_car_id',
        triggerId: 'payment-car-picker-trigger',
        emptyLabel: 'Select car if this payment belongs to one car',
    },
    rto_car: {
        title: 'Search Cars',
        subtitle: 'Select the car connected to this RTO entry.',
        inputId: 'rto_car_id',
        triggerId: 'rto-car-picker-trigger',
        emptyLabel: 'Select car for RTO',
    },
    car: {
        title: 'Search Cars',
        subtitle: 'Search available cars by registration number, make, or model.',
        inputId: 'expense_car_select',
        mirrorInputId: 'sale_car_id',
        triggerId: 'car-picker-trigger',
        emptyLabel: 'Select car',
    },
    main_partner: {
        title: 'Search Main Partners',
        subtitle: 'Search by partner name or phone number.',
        inputId: 'partner_id',
        triggerId: 'partner-picker-trigger',
        emptyLabel: 'Select partner',
    },
    car_partner: {
        title: 'Search Car-wise Partners',
        subtitle: 'Search partner for this specific car deal.',
        inputId: 'partner_id',
        triggerId: 'partner-picker-trigger',
        emptyLabel: 'Select partner',
    },
    employee: {
        title: 'Search Employees',
        subtitle: 'Search by employee name or role.',
        inputId: 'employee_id',
        triggerId: 'employee-picker-trigger',
        emptyLabel: 'Select employee',
    },
    debtor: {
        title: 'Search Debtors / Buyers',
        subtitle: 'Search by debtor, buyer, or phone number.',
        inputId: 'debtor_id',
        triggerId: 'debtor-picker-trigger',
        emptyLabel: 'Select debtor / buyer',
    },
    creditor: {
        title: 'Search Creditors / Sellers',
        subtitle: 'Search by creditor, seller, or phone number.',
        inputId: 'creditor_id',
        triggerId: 'creditor-picker-trigger',
        emptyLabel: 'Select creditor / seller',
    },
};

const preselectedExpenseCarId = <?= json_encode($preselectedType === 'CAR_EXPENSE' ? $preselectedCarId : '') ?>;
const preselectedExpenseCarLabel = <?= json_encode($preselectedCar['registration_no'] ?? '') ?>;
const moneyFlowDefaults = {
    in: 'PARTNER_INVEST',
    out: 'GENERAL_EXPENSE',
};
const entryCategoryOptions = <?= json_encode($entryCategoryOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
let activeMoneyFlow = '';
let transactionTypeOptions = [];

function parseNumericString(value) {
    const normalized = String(value || '').replace(/[^0-9.\-]/g, '');
    const parsed = parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
}

function syncPreselectedExpenseCarState(type) {
    const carPickerTrigger = document.getElementById('car-picker-trigger');
    const carPickerNote = document.getElementById('preselected-car-note');
    const expenseCarInput = document.getElementById('expense_car_select');
    const carPickerRow = document.getElementById('car-picker-row');
    const isLockedExpenseCar = type === 'CAR_EXPENSE' && !!preselectedExpenseCarId;

    if (expenseCarInput && isLockedExpenseCar) {
        expenseCarInput.value = preselectedExpenseCarId;
    }
    if (carPickerTrigger) {
        if (isLockedExpenseCar && carPickerTrigger.querySelector('span')) {
            carPickerTrigger.querySelector('span').textContent = preselectedExpenseCarLabel || 'Selected car';
        }
    }
    if (carPickerRow) {
        carPickerRow.style.display = isLockedExpenseCar ? 'none' : '';
    }
    if (carPickerNote) {
        carPickerNote.style.display = isLockedExpenseCar ? 'flex' : 'none';
    }
}

function addPartnerFundingRow() {
    const container = document.getElementById('partner-funding-rows');
    const baseRow = container?.querySelector('.partner-funding-row');
    if (!container || !baseRow) return;
    const clone = baseRow.cloneNode(true);
    clone.querySelectorAll('input').forEach((input) => input.value = '');
    clone.querySelectorAll('.pf-partner-trigger span').forEach((label) => {
        label.textContent = 'Select partner';
    });
    if (typeof initCurrencyInputs === 'function') {
        initCurrencyInputs(clone);
    }
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

document.querySelector('.amount-input')?.addEventListener('input', updateSplitTotals);
document.getElementById('split-lines')?.addEventListener('input', function(event) {
    if (event.target.classList.contains('split-line-amount')) updateSplitTotals();
});
document.getElementById('account-picker-search')?.addEventListener('input', function() {
    renderAccountPickerResults(this.value);
});
document.getElementById('entity-picker-search')?.addEventListener('input', function() {
    renderEntityPickerResults(this.value);
});

function selectSplitEntryType() {
    const select = document.getElementById('transaction_type');
    if (!select) return;
    activeMoneyFlow = 'both';
    select.value = 'JOURNAL_VOUCHER';
    select.dispatchEvent(new Event('change'));
    syncMoneyFlowButtons();
    renderTransactionTypePicker();
    document.getElementById('split-bill-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(() => document.querySelector('#split-lines .picker-trigger')?.focus(), 120);
}

function selectMoneyFlow(flow) {
    const select = document.getElementById('transaction_type');
    if (!select || !moneyFlowDefaults[flow]) return;
    activeMoneyFlow = flow;
    const defaultCategory = (entryCategoryOptions || []).find((option) => option.flow === flow);
    if (defaultCategory) {
        setDynamicCategorySelection(defaultCategory.categoryAccountId || '', defaultCategory.direction || '');
        select.value = 'CATEGORY_ENTRY';
    } else {
        clearDynamicCategorySelection();
        select.value = moneyFlowDefaults[flow];
    }
    select.dispatchEvent(new Event('change'));
    syncDynamicCategoryEntryState();
    syncMoneyFlowButtons();
    renderTransactionTypePicker();
    const amountInput = document.querySelector('.amount-input');
    setTimeout(() => amountInput?.focus(), 40);
}

function initTransactionTypePicker() {
    const select = document.getElementById('transaction_type');
    const trigger = document.getElementById('txn-type-trigger');
    const menu = document.getElementById('txn-type-menu');
    const search = document.getElementById('txn-type-search');
    if (!select || !trigger || !menu || !search) return;

    transactionTypeOptions = Array.from(select.options)
        .filter((option) => option.value && option.value !== 'CATEGORY_ENTRY')
        .map((option) => ({
            value: option.value,
            categoryAccountId: '',
            direction: '',
            title: option.dataset.title || option.textContent.trim(),
            desc: option.dataset.desc || '',
            icon: option.dataset.icon || 'ri-list-check-2',
            flow: option.dataset.flow || 'both',
            group: option.parentElement?.tagName === 'OPTGROUP' ? option.parentElement.label : 'Other',
            text: `${option.textContent} ${option.dataset.desc || ''}`.toLowerCase(),
        }))
        .concat(entryCategoryOptions || []);

    trigger.addEventListener('click', () => {
        const shouldOpen = menu.hidden;
        closeTransactionTypePicker();
        if (shouldOpen) {
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            renderTransactionTypePicker();
            setTimeout(() => search.focus(), 30);
        }
    });

    search.addEventListener('input', renderTransactionTypePicker);
    select.addEventListener('change', () => {
        const chosen = transactionTypeOptions.find((option) => getTransactionOptionKey(option) === getSelectedTransactionKey());
        if (chosen && (chosen.flow === 'in' || chosen.flow === 'out')) {
            activeMoneyFlow = chosen.flow;
        }
        if (select.value !== 'CATEGORY_ENTRY') {
            clearDynamicCategorySelection();
        }
        syncMoneyFlowButtons();
        updateTransactionTypeTrigger();
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('#txn-type-picker')) {
            closeTransactionTypePicker();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeTransactionTypePicker();
        }
    });

    updateTransactionTypeTrigger();
    renderTransactionTypePicker();
}

function renderTransactionTypePicker() {
    const select = document.getElementById('transaction_type');
    const list = document.getElementById('txn-type-list');
    const search = document.getElementById('txn-type-search');
    if (!select || !list) return;

    const query = (search?.value || '').trim().toLowerCase();
    const flow = activeMoneyFlow;
    const visible = transactionTypeOptions.filter((option) => {
        const flowMatches = !flow || flow === 'both' || option.flow === flow || option.flow === 'both';
        const queryMatches = !query || option.text.includes(query) || option.title.toLowerCase().includes(query);
        return flowMatches && queryMatches;
    });

    if (!visible.length) {
        list.innerHTML = '<div class="txn-type-empty">No matching entry type.</div>';
        return;
    }

    let lastGroup = '';
    list.innerHTML = visible.map((option) => {
        const groupLabel = option.group !== lastGroup ? `<div class="txn-type-group">${escapeHtml(option.group)}</div>` : '';
        lastGroup = option.group;
        const flowClass = option.flow === 'in' ? 'money-in' : (option.flow === 'out' ? 'money-out' : 'both');
        const activeClass = getSelectedTransactionKey() === getTransactionOptionKey(option) ? 'active' : '';
        return `
            ${groupLabel}
            <button type="button" class="txn-type-item ${flowClass} ${activeClass}" data-value="${escapeHtml(option.value)}" data-category-account-id="${escapeHtml(option.categoryAccountId || '')}" data-category-direction="${escapeHtml(option.direction || '')}" role="option" aria-selected="${activeClass ? 'true' : 'false'}">
                <span class="txn-type-icon"><i class="${escapeHtml(option.icon)}"></i></span>
                <span>
                    <strong>${escapeHtml(option.title)}</strong>
                    <small>${escapeHtml(option.desc)}</small>
                </span>
            </button>
        `;
    }).join('');

    list.querySelectorAll('.txn-type-item').forEach((button) => {
        button.addEventListener('click', () => {
            setDynamicCategorySelection(button.dataset.categoryAccountId || '', button.dataset.categoryDirection || '');
            select.value = button.dataset.value || '';
            select.dispatchEvent(new Event('change'));
            syncDynamicCategoryEntryState();
            closeTransactionTypePicker();
        });
    });
}

function closeTransactionTypePicker() {
    const menu = document.getElementById('txn-type-menu');
    const trigger = document.getElementById('txn-type-trigger');
    if (menu) menu.hidden = true;
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
}

function syncMoneyFlowButtons() {
    document.querySelectorAll('.simple-entry-option[data-money-flow]').forEach((button) => {
        button.classList.toggle('active', button.dataset.moneyFlow === activeMoneyFlow);
    });
    document.querySelector('.simple-entry-option.split')?.classList.toggle(
        'active',
        document.getElementById('transaction_type')?.value === 'JOURNAL_VOUCHER'
    );
}

function updateTransactionTypeTrigger() {
    const select = document.getElementById('transaction_type');
    const trigger = document.getElementById('txn-type-trigger');
    if (!select || !trigger) return;
    const chosen = transactionTypeOptions.find((option) => getTransactionOptionKey(option) === getSelectedTransactionKey());
    const icon = trigger.querySelector('.txn-type-trigger-icon i');
    const title = trigger.querySelector('.txn-type-trigger-copy strong');
    const desc = trigger.querySelector('.txn-type-trigger-copy small');
    if (chosen) {
        if (icon) icon.className = chosen.icon;
        if (title) title.textContent = chosen.title;
        if (desc) desc.textContent = chosen.desc;
        trigger.classList.toggle('money-in', chosen.flow === 'in');
        trigger.classList.toggle('money-out', chosen.flow === 'out');
    } else {
        if (icon) icon.className = 'ri-list-check-2';
        if (title) title.textContent = 'Select entry type';
        if (desc) desc.textContent = activeMoneyFlow === 'in'
            ? 'Showing Receive/Jama entries only.'
            : (activeMoneyFlow === 'out' ? 'Showing Payments entries only.' : 'Choose Receive/Jama or Payments first for a shorter list.');
        trigger.classList.remove('money-in', 'money-out');
    }
}

function getTransactionOptionKey(option) {
    return option.categoryAccountId ? `category:${option.categoryAccountId}` : `type:${option.value}`;
}

function getSelectedTransactionKey() {
    const select = document.getElementById('transaction_type');
    const categoryAccountId = document.getElementById('dynamic_category_account_id')?.value || '';
    return select?.value === 'CATEGORY_ENTRY' && categoryAccountId ? `category:${categoryAccountId}` : `type:${select?.value || ''}`;
}

function setDynamicCategorySelection(accountId, direction) {
    const accountInput = document.getElementById('dynamic_category_account_id');
    const directionInput = document.getElementById('dynamic_category_direction');
    if (accountInput) accountInput.value = accountId || '';
    if (directionInput) directionInput.value = direction || '';
}

function clearDynamicCategorySelection() {
    setDynamicCategorySelection('', '');
}

function syncDynamicCategoryEntryState() {
    const select = document.getElementById('transaction_type');
    const categoryDirection = document.getElementById('dynamic_category_direction')?.value || '';
    if (select?.value !== 'CATEGORY_ENTRY') return;

    document.querySelectorAll('.txn-section').forEach((section) => {
        section.style.display = 'none';
    });
    const paymentAccountGroup = document.getElementById('payment-account-group');
    if (paymentAccountGroup) paymentAccountGroup.style.display = '';
    const paymentLabel = document.getElementById('payment-account-label');
    if (paymentLabel) paymentLabel.textContent = categoryDirection === 'in' ? 'Receiving Account' : 'Payment Account';
}

function filterPrimaryPaymentAccounts(type) {
    const select = document.getElementById('payment_account');
    if (!select) return;
    const requiredAccountType = '';
    let selectedVisible = false;

    Array.from(select.options).forEach((option) => {
        const accountType = option.dataset.accountType || '';
        const isVisible = !requiredAccountType || accountType === requiredAccountType;
        option.hidden = !isVisible;
        option.disabled = !isVisible;
        if (option.selected && isVisible) selectedVisible = true;
    });

    if (!selectedVisible) {
        const firstVisible = Array.from(select.options).find((option) => !option.disabled);
        if (firstVisible) select.value = firstVisible.value;
    }
}

function syncSaleAmountUi() {
    const txnType = document.getElementById('transaction_type')?.value || '';
    const amountGroup = document.getElementById('amount-group');
    const amountInput = document.querySelector('input[name=\"amount\"]');
    const salePriceInput = document.querySelector('input[name=\"sale_price\"]');
    const commissionInput = document.querySelector('input[name=\"sale_commission_amount\"]');
    if (!amountGroup || !amountInput) return;

    const isCarSale = txnType === 'CAR_SALE';
    amountGroup.style.display = isCarSale ? 'none' : '';
    amountInput.required = !isCarSale;
    if (isCarSale) {
        const salePrice = parseNumericString(salePriceInput?.value || '0');
        const commission = parseNumericString(commissionInput?.value || '0');
        amountInput.value = String(salePrice + commission || '');
    }
}

function openSplitEntryModal() {
    updateSplitTotals();
    const section = document.getElementById('split-bill-section');
    section?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function addSplitLine() {
    const container = document.getElementById('split-lines');
    const baseRow = container?.querySelector('.split-line-row');
    if (!container || !baseRow) return;
    const clone = baseRow.cloneNode(true);
    clone.querySelectorAll('input').forEach((input) => input.value = '');
    clone.querySelector('.picker-trigger span').textContent = 'Select account/car';
    if (typeof initCurrencyInputs === 'function') {
        initCurrencyInputs(clone);
    }
    container.appendChild(clone);
    updateSplitTotals();
}

function focusFirstSplitLine() {
    document.querySelector('#split-lines .split-line-row:last-child .picker-trigger')?.focus();
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
    const total = parseNumericString(document.querySelector('.amount-input')?.value || '0');
    let allocated = 0;
    let activeRows = 0;
    document.querySelectorAll('.split-line-amount').forEach((input) => {
        const amount = parseNumericString(input.value || '0');
        allocated += amount;
        if (amount > 0) activeRows += 1;
    });
    const remaining = total - allocated;
    const balanceCard = document.getElementById('split-balance-card');

    document.getElementById('split-total-display').textContent = formatINR(total);
    document.getElementById('split-modal-total').textContent = formatINR(total);
    document.getElementById('split-allocated-total').textContent = formatINR(allocated);
    document.getElementById('split-remaining-total').textContent = formatINR(remaining);
    const countNode = document.getElementById('split-line-count');
    if (countNode) countNode.textContent = String(activeRows);
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

function openEntityPicker(kind, button) {
    activeEntityPicker = { kind, button };
    const config = entityPickerConfig[kind];
    if (!config) return;
    const title = document.querySelector('#entity-picker-modal h3');
    const subtitle = document.getElementById('entity-picker-subtitle');
    const search = document.getElementById('entity-picker-search');
    const txnType = document.getElementById('transaction_type')?.value || '';
    if (title) title.innerHTML = `<i class="ri-search-eye-line"></i> ${config.title}`;
    if (subtitle) {
        subtitle.textContent = kind === 'car' && txnType === 'CAR_SALE'
            ? 'Only in-stock cars are shown here. Already sold cars are hidden.'
            : config.subtitle;
    }
    if (search) search.value = '';
    renderEntityPickerResults('');
    openModal('entity-picker-modal');
    setTimeout(() => search?.focus(), 60);
}

async function renderEntityPickerResults(query) {
    const results = document.getElementById('entity-picker-results');
    if (!results || !activeEntityPicker?.kind) return;
    const kind = activeEntityPicker.kind;
    const searchQuery = (query || '').trim();
    const txnType = document.getElementById('transaction_type')?.value || '';
    const contextParam = (kind === 'car' || kind === 'payment_car' || kind === 'rto_car') ? `&context=${encodeURIComponent(txnType)}` : '';
    results.innerHTML = '<div class="picker-empty">Searching...</div>';

    try {
        const response = await fetch(`search_entities.php?kind=${encodeURIComponent(kind)}&q=${encodeURIComponent(searchQuery)}${contextParam}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('Search failed');
        const payload = await response.json();
        const matches = payload.results || [];
        if (!matches.length) {
            results.innerHTML = kind === 'car' && txnType === 'CAR_SALE'
                ? '<div class="picker-empty">No in-stock car found. Sold cars are hidden from sale entry.</div>'
                : '<div class="picker-empty">No match found.</div>';
            return;
        }

        results.innerHTML = matches.map((item) => `
            <button type="button" class="picker-result" data-entity-id="${item.id}" data-entity-label="${encodeURIComponent(item.label || '')}" data-linked-party-id="${item.linked_party_id || ''}" data-linked-party-label="${encodeURIComponent(item.linked_party_label || '')}">
                <span>
                    <strong>${escapeHtml(item.label)}</strong>
                    <small>${escapeHtml(item.meta || '')}</small>
                </span>
            </button>
        `).join('');

        results.querySelectorAll('.picker-result').forEach((button) => {
            button.addEventListener('click', function() {
                selectEntityPickerValue(
                    kind,
                    this.dataset.entityId,
                    decodeURIComponent(this.dataset.entityLabel || ''),
                    this.dataset.linkedPartyId || '',
                    decodeURIComponent(this.dataset.linkedPartyLabel || '')
                );
            });
        });
    } catch (error) {
        results.innerHTML = `<div class="picker-empty">Could not search right now. Error: ${escapeHtml(error.message)}. Please try again.</div>`;
    }
}

function applyLinkedPartySelection(kind, linkedPartyId, linkedPartyLabel) {
    const txnType = document.getElementById('transaction_type')?.value || '';
    if (kind !== 'payment_car' || !linkedPartyId) return;

    if (txnType === 'LOAN_RECEIVED') {
        const input = document.getElementById('debtor_id');
        const trigger = document.getElementById('debtor-picker-trigger');
        if (input) input.value = linkedPartyId;
        if (trigger?.querySelector('span')) trigger.querySelector('span').textContent = linkedPartyLabel || 'Select debtor / buyer';
    } else if (txnType === 'LOAN_REPAID') {
        const input = document.getElementById('creditor_id');
        const trigger = document.getElementById('creditor-picker-trigger');
        if (input) input.value = linkedPartyId;
        if (trigger?.querySelector('span')) trigger.querySelector('span').textContent = linkedPartyLabel || 'Select creditor / seller';
    }
}

function selectEntityPickerValue(kind, id, label, linkedPartyId = '', linkedPartyLabel = '') {
    const triggerButton = activeEntityPicker?.button || null;
    if ((kind === 'partner' || kind === 'car_partner') && triggerButton?.classList.contains('pf-partner-trigger')) {
        const row = triggerButton.closest('.partner-funding-row');
        const input = row?.querySelector('.pf-partner-id');
        const span = triggerButton.querySelector('span');
        if (input) input.value = id || '';
        if (span) span.textContent = label || 'Select partner';
        closeModal('entity-picker-modal');
        return;
    }

    const config = entityPickerConfig[kind];
    if (!config) return;
    const input = document.getElementById(config.inputId);
    const trigger = document.getElementById(config.triggerId);
    if (input) input.value = id || '';
    if (config.mirrorInputId) {
        const mirror = document.getElementById(config.mirrorInputId);
        if (mirror) mirror.value = id || '';
    }
    if (trigger) {
        const span = trigger.querySelector('span');
        if (span) span.textContent = label || config.emptyLabel;
    }
    applyLinkedPartySelection(kind, linkedPartyId, linkedPartyLabel);
    closeModal('entity-picker-modal');
}

function syncCarClearingUi() {
    const txnType = document.getElementById('transaction_type')?.value || '';
    const debtorLabel = document.getElementById('debtor-label');
    const creditorLabel = document.getElementById('creditor-label');
    const linkedCarLabel = document.getElementById('linked-car-label');
    const linkedCarHint = document.getElementById('linked-car-hint');
    const paymentCarTrigger = document.getElementById('payment-car-picker-trigger');
    const paymentCarInput = document.getElementById('linked_car_id');

    if (debtorLabel) debtorLabel.textContent = txnType === 'LOAN_RECEIVED' ? 'Buyer / Debtor *' : 'Select Debtor *';
    if (creditorLabel) creditorLabel.textContent = txnType === 'LOAN_REPAID' ? 'Seller / Creditor *' : 'Select Creditor *';
    if (linkedCarLabel) linkedCarLabel.textContent = (txnType === 'LOAN_RECEIVED' || txnType === 'LOAN_REPAID') ? 'Car for this clearing' : 'Linked Car';

    if (linkedCarHint) {
        linkedCarHint.textContent = txnType === 'LOAN_RECEIVED'
            ? 'Select the sold car when buyer pays later in chunks. The buyer will auto-fill when available.'
            : (txnType === 'LOAN_REPAID'
                ? 'Select the purchased car when seller is paid later in chunks. The seller will auto-fill when available.'
                : 'Use this when buyer or seller chunk payment belongs to a specific car.');
    }

    if (paymentCarTrigger?.querySelector('span') && !paymentCarInput?.value) {
        paymentCarTrigger.querySelector('span').textContent = txnType === 'LOAN_RECEIVED'
            ? 'Select sold car for buyer payment clearing'
            : (txnType === 'LOAN_REPAID'
                ? 'Select purchased car for seller clearing'
                : 'Select car if this payment belongs to one car');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initTransactionTypePicker();
    syncPreselectedExpenseCarState(document.getElementById('transaction_type')?.value || '');
    document.getElementById('transaction-form')?.addEventListener('submit', (event) => {
        const select = document.getElementById('transaction_type');
        const trigger = document.getElementById('txn-type-trigger');
        const error = document.getElementById('txn-type-error');
        if (select?.value) {
            trigger?.classList.remove('is-invalid');
            if (error) error.hidden = true;
            return;
        }
        event.preventDefault();
        trigger?.classList.add('is-invalid');
        if (error) error.hidden = false;
        trigger?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        trigger?.focus();
    });
    document.getElementById('transaction_type')?.addEventListener('change', () => {
        document.getElementById('txn-type-trigger')?.classList.remove('is-invalid');
        const error = document.getElementById('txn-type-error');
        if (error) error.hidden = true;
        setTimeout(syncDynamicCategoryEntryState, 0);
        setTimeout(syncSaleAmountUi, 0);
        setTimeout(syncCarClearingUi, 0);
    });
    document.querySelector('input[name="sale_price"]')?.addEventListener('input', syncSaleAmountUi);
    document.querySelector('input[name="sale_commission_amount"]')?.addEventListener('input', syncSaleAmountUi);
    document.querySelector('input[name="vouchers[]"]')?.addEventListener('change', (event) => {
        const status = document.querySelector('.voucher-file-status');
        const count = event.target.files ? event.target.files.length : 0;
        if (status) status.textContent = count ? `${count} file${count === 1 ? '' : 's'} selected` : 'Images or PDF';
    });
    document.querySelectorAll('.simple-entry-option[data-money-flow]').forEach((button) => {
        button.addEventListener('click', () => selectMoneyFlow(button.dataset.moneyFlow || ''));
    });
    filterPrimaryPaymentAccounts(document.getElementById('transaction_type')?.value || '');
    syncSaleAmountUi();
    syncCarClearingUi();
});

async function renderAccountPickerResults(query) {
    const results = document.getElementById('account-picker-results');
    if (!results) return;
    results.innerHTML = '<div class="picker-empty">Searching...</div>';

    try {
        const response = await fetch(`search_entities.php?kind=account&q=${encodeURIComponent((query || '').trim())}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('Search failed');
        const payload = await response.json();
        const matches = payload.results || [];

        if (!matches.length) {
            results.innerHTML = '<div class="picker-empty">No match. Check spelling, code, or car number.</div>';
            return;
        }

        results.innerHTML = matches.map((account) => `
            <button type="button" class="picker-result" data-account-id="${account.id}" data-account-label="${encodeURIComponent(account.label || '')}">
                <span>
                    <strong>${escapeHtml(account.label)}</strong>
                    <small>${escapeHtml(account.meta || 'General')}</small>
                </span>
            </button>
        `).join('');

        results.querySelectorAll('.picker-result').forEach((button) => {
            button.addEventListener('click', function() {
                if (!activeSplitRow) return;
                activeSplitRow.querySelector('.split-account-id').value = this.dataset.accountId || '';
                activeSplitRow.querySelector('.picker-trigger span').textContent = decodeURIComponent(this.dataset.accountLabel || '') || 'Select account/car';
                closeModal('account-picker-modal');
            });
        });
    } catch (error) {
        results.innerHTML = `<div class="picker-empty">Could not search accounts right now. Error: ${escapeHtml(error.message)}. Please try again.</div>`;
    }
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value || '';
    return div.innerHTML;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
