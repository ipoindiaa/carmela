<?php
// Application Configuration
require_once __DIR__ . '/environment.php';
define('APP_IS_TESTING', APP_ENV === 'testing');
define('APP_NAME', 'Tiranga Car World');
define('APP_VERSION', '1.0');
define('APP_CURRENCY', '₹');
define('APP_CURRENCY_CODE', 'INR');
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_TIMEZONE_OFFSET', '+05:30');
define('APP_FY_START_MONTH', 4); // April

date_default_timezone_set(APP_TIMEZONE);

// Paths
define('APP_ROOT', dirname(__DIR__));
define('APP_URL', '/');

// Session
define('SESSION_LIFETIME', 3600 * 8); // 8 hours

// Roles
define('ROLE_ADMIN', 'ADMIN');
define('ROLE_PARTNER', 'PARTNER');
define('ROLE_ACCOUNTANT', 'ACCOUNTANT');
define('ROLE_OPERATOR', 'OPERATOR');

// Book / report permissions
define('BOOK_PERMISSIONS', [
    'cash_book' => [
        'label' => 'Cash Book',
        'description' => 'View cash activity and post cash-based entries',
    ],
    'bank_book' => [
        'label' => 'Bank Book',
        'description' => 'View bank activity and post bank-based entries',
    ],
    'general_ledger' => [
        'label' => 'General Ledger',
        'description' => 'View full account ledgers and account balances',
    ],
    'trial_balance' => [
        'label' => 'Trial Balance',
        'description' => 'View debit and credit totals across accounts',
    ],
    'profit_loss' => [
        'label' => 'Profit & Loss',
        'description' => 'View income, expense, and net profit reports',
    ],
    'balance_sheet' => [
        'label' => 'Balance Sheet',
        'description' => 'View assets, liabilities, and capital summary',
    ],
    'car_profitability' => [
        'label' => 'Car Profitability',
        'description' => 'View vehicle-wise profit and loss',
    ],
    'debtor_ageing' => [
        'label' => 'Debtor Ageing',
        'description' => 'View outstanding debtor balances and ageing',
    ],
    'creditors_report' => [
        'label' => 'Creditors',
        'description' => 'View supplier and creditor outstanding balances',
    ],
    'partner_accounts' => [
        'label' => 'Partner Accounts',
        'description' => 'View partner capital, current, and settlement positions',
    ],
    'employee_advances' => [
        'label' => 'Employee Advances',
        'description' => 'View employee advance balances and recovery status',
    ],
    'outstanding_summary' => [
        'label' => 'Outstanding Summary',
        'description' => 'View a combined receivable and payable summary',
    ],
    'jv_register' => [
        'label' => 'JV Register',
        'description' => 'View journal vouchers, drafts, and posted JV entries',
    ],
    'rto_book' => [
        'label' => 'RTO Book',
        'description' => 'Manage car-wise RTO work, expenses, and recoveries',
    ],
]);

define('PRIMARY_BOOK_ACCOUNT_TYPES', [
    'cash_book' => 'CASH',
    'bank_book' => 'BANK',
]);

define('CLIENT_DEMO_HIDDEN_BOOKS', []);

// One source of truth for entry names, flow, grouping, and summary behavior.
define('ENTRY_TYPE_META', [
    'CAR_PURCHASE' => ['label' => 'Bought a Car', 'flow' => 'out', 'category' => 'Cars', 'icon' => 'ri-car-line', 'description' => 'Cars purchased for business stock.', 'selectable' => true, 'summary' => true],
    'CAR_TOKEN_RECEIVED' => ['label' => 'Car Token Received', 'flow' => 'in', 'category' => 'Cars', 'icon' => 'ri-hand-coin-line', 'description' => 'Advance money received for a specific car.', 'selectable' => true, 'summary' => true],
    'CAR_SALE' => ['label' => 'Sold a Car', 'flow' => 'in', 'category' => 'Cars', 'icon' => 'ri-money-rupee-circle-line', 'description' => 'Owned cars sold to buyers.', 'selectable' => true, 'summary' => true],
    'COMMISSION_CAR_SALE' => ['label' => 'Commission Car Sale', 'flow' => 'in', 'category' => 'Cars', 'icon' => 'ri-percent-line', 'description' => 'Commission earned by selling a customer-owned car.', 'selectable' => false, 'summary' => true],
    'RTO_EXPENSE' => ['label' => 'RTO Expense', 'flow' => 'out', 'category' => 'Cars', 'icon' => 'ri-file-shield-2-line', 'description' => 'RTO fees paid for a specific car.', 'selectable' => true, 'summary' => true],
    'RTO_RECOVERY' => ['label' => 'RTO Recovery Received', 'flow' => 'in', 'category' => 'Cars', 'icon' => 'ri-refund-2-line', 'description' => 'RTO money recovered from a buyer or party.', 'selectable' => true, 'summary' => true],
    'CAR_EXPENSE' => ['label' => 'Car Repair / Service', 'flow' => 'out', 'category' => 'Cars', 'icon' => 'ri-tools-line', 'description' => 'Repair and service spending assigned to cars.', 'selectable' => true, 'summary' => true],
    'GENERAL_EXPENSE' => ['label' => 'Office / Business Expense', 'flow' => 'out', 'category' => 'Business', 'icon' => 'ri-receipt-line', 'description' => 'General operating costs not assigned to a custom type.', 'selectable' => true, 'summary' => true],
    'JOURNAL_VOUCHER' => ['label' => 'Large Bill Split', 'flow' => 'neutral', 'category' => 'Business', 'icon' => 'ri-bill-line', 'description' => 'One bill allocated across multiple cars or accounts.', 'selectable' => true, 'summary' => false],
    'PARTNER_INVEST' => ['label' => 'Partner Added Money', 'flow' => 'in', 'category' => 'Partners', 'icon' => 'ri-user-add-line', 'description' => 'Capital introduced into the business by a partner.', 'selectable' => true, 'summary' => true],
    'PARTNER_WITHDRAW' => ['label' => 'Partner Took Money', 'flow' => 'out', 'category' => 'Partners', 'icon' => 'ri-user-unfollow-line', 'description' => 'Money withdrawn from the business by a partner.', 'selectable' => true, 'summary' => true],
    'PARTNER_SETTLEMENT' => ['label' => 'Partner Settlement', 'flow' => 'out', 'category' => 'Partners', 'icon' => 'ri-scales-3-line', 'description' => 'Settlement paid to or recovered from a partner.', 'selectable' => true, 'summary' => true],
    'SALARY_PAYMENT' => ['label' => 'Paid Salary', 'flow' => 'out', 'category' => 'Employees', 'icon' => 'ri-user-star-line', 'description' => 'Salary paid to employees.', 'selectable' => true, 'summary' => true],
    'EMPLOYEE_ADVANCE' => ['label' => 'Employee Took Advance', 'flow' => 'out', 'category' => 'Employees', 'icon' => 'ri-cash-line', 'description' => 'Salary advance paid to an employee.', 'selectable' => true, 'summary' => true],
    'EMPLOYEE_ADVANCE_WRITEOFF' => ['label' => 'Employee Advance Write-Off', 'flow' => 'out', 'category' => 'Employees', 'icon' => 'ri-delete-back-2-line', 'description' => 'Unrecoverable employee advance written off.', 'selectable' => true, 'summary' => true],
    'LOAN_GIVEN' => ['label' => 'Lent Money to Someone', 'flow' => 'out', 'category' => 'Parties', 'icon' => 'ri-arrow-right-up-line', 'description' => 'Money given to a person or company and recoverable later.', 'selectable' => true, 'summary' => true],
    'LOAN_RECEIVED' => ['label' => 'Payment Clearing Received', 'flow' => 'in', 'category' => 'Parties', 'icon' => 'ri-arrow-left-down-line', 'description' => 'Outstanding money received from a buyer or debtor.', 'selectable' => true, 'summary' => true],
    'CAR_PAYMENT_CLEARING' => ['label' => 'Car Payment Clearing', 'flow' => 'in', 'category' => 'Cars', 'icon' => 'ri-car-line', 'description' => 'Pending buyer money received for a specific car.', 'selectable' => false, 'summary' => true],
    'LOAN_TAKEN' => ['label' => 'Borrowed Money', 'flow' => 'in', 'category' => 'Parties', 'icon' => 'ri-hand-coin-line', 'description' => 'Money borrowed by the business.', 'selectable' => true, 'summary' => true],
    'LOAN_REPAID' => ['label' => 'Payment Clearing Paid', 'flow' => 'out', 'category' => 'Parties', 'icon' => 'ri-secure-payment-line', 'description' => 'Outstanding money paid to a seller or creditor.', 'selectable' => true, 'summary' => true],
    'SELLER_PAYMENT_CLEARING' => ['label' => 'Seller Payment Clearing', 'flow' => 'out', 'category' => 'Cars', 'icon' => 'ri-car-line', 'description' => 'Pending purchase money paid to a car seller.', 'selectable' => false, 'summary' => true],
    'COMMISSION_OWNER_PAYMENT' => ['label' => 'Commission Owner Payment', 'flow' => 'out', 'category' => 'Cars', 'icon' => 'ri-hand-coin-line', 'description' => 'Sale proceeds paid to the owner of a commission car.', 'selectable' => false, 'summary' => true],
    'CONTRA_TRANSFER' => ['label' => 'Cash to Bank / Bank to Cash', 'flow' => 'neutral', 'category' => 'Internal', 'icon' => 'ri-swap-box-line', 'description' => 'Money moved between business cash and bank accounts.', 'selectable' => true, 'summary' => false],
    'GST_PAYMENT' => ['label' => 'Tax Payment', 'flow' => 'out', 'category' => 'Tax', 'icon' => 'ri-government-line', 'description' => 'Tax liability paid by the business.', 'selectable' => true, 'summary' => true],
    'GST_UTILIZATION' => ['label' => 'Tax Credit Utilized', 'flow' => 'neutral', 'category' => 'Tax', 'icon' => 'ri-exchange-funds-line', 'description' => 'Input tax credit adjusted against tax payable.', 'selectable' => true, 'summary' => false],
    'OPENING_BALANCE' => ['label' => 'Opening Balance', 'flow' => 'neutral', 'category' => 'Internal', 'icon' => 'ri-scales-line', 'description' => 'Balance carried into the system at setup.', 'selectable' => true, 'summary' => false],
    'BAD_DEBT' => ['label' => 'Bad Debt Write-Off', 'flow' => 'out', 'category' => 'Parties', 'icon' => 'ri-file-damage-line', 'description' => 'Receivable written off as unrecoverable.', 'selectable' => true, 'summary' => true],
    'PROFIT_DISTRIBUTION' => ['label' => 'Profit Distribution', 'flow' => 'out', 'category' => 'Partners', 'icon' => 'ri-pie-chart-line', 'description' => 'Car profit allocated to partners.', 'selectable' => false, 'summary' => true],
    'REVERSAL' => ['label' => 'Reversal', 'flow' => 'neutral', 'category' => 'Internal', 'icon' => 'ri-arrow-go-back-line', 'description' => 'Audit-preserved reversal of an earlier entry.', 'selectable' => false, 'summary' => false],
    'INTERNAL_ALLOCATION' => ['label' => 'Internal Cost Allocation', 'flow' => 'neutral', 'category' => 'Internal', 'icon' => 'ri-git-merge-line', 'description' => 'Supporting journal generated by an operational entry.', 'selectable' => false, 'summary' => false],
]);

define('TXN_TYPES', array_map(
    static fn($meta) => $meta['label'],
    array_filter(ENTRY_TYPE_META, static fn($meta) => !empty($meta['selectable']))
));

// Account Groups
define('ACCOUNT_GROUPS', [
    'ASSET' => 'Assets',
    'LIABILITY' => 'Liabilities',
    'INCOME' => 'Income',
    'EXPENSE' => 'Expenses',
    'EQUITY' => 'Equity',
    'CONTRA' => 'Contra',
]);

// Car Status
define('CAR_STATUS', [
    'IN_STOCK' => 'In Stock',
    'SOLD' => 'Sold',
    'PENDING_PAYMENT' => 'Pending Payment',
    'CANCELLED' => 'Cancelled',
]);

// Alert Types
define('ALERT_TYPES', [
    'CASH_LOW' => 'Cash Balance Low',
    'DEBTOR_OVERDUE' => 'Debtor Overdue',
    'ADVANCE_HIGH' => 'Employee Advance Too High',
    'CAR_AGING' => 'Car In Stock Too Long',
    'TRIAL_IMBALANCE' => 'Trial Balance Imbalance',
    'PARTNER_WITHDRAWAL' => 'Partner Withdrawal Exceeds Capital',
    'SALARY_DUPLICATE' => 'Duplicate Salary Processing',
]);
