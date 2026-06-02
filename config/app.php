<?php
// Application Configuration
define('APP_NAME', 'AutoBooks Pro');
define('APP_VERSION', '1.0');
define('APP_CURRENCY', '₹');
define('APP_CURRENCY_CODE', 'INR');
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_FY_START_MONTH', 4); // April

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
    'gst_book' => [
        'label' => 'GST Book',
        'description' => 'View GST account activity and post GST-bank entries',
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
]);

define('PRIMARY_BOOK_ACCOUNT_TYPES', [
    'cash_book' => 'CASH',
    'bank_book' => 'BANK',
    'gst_book' => 'GST',
]);

// Transaction Types
define('TXN_TYPES', [
    'CAR_PURCHASE' => 'Bought a Car',
    'CAR_SALE' => 'Sold a Car',
    'CAR_EXPENSE' => 'Car Repair / Service',
    'GENERAL_EXPENSE' => 'Office / Business Expense',
    'JOURNAL_VOUCHER' => 'Journal Voucher',
    'PARTNER_INVEST' => 'Partner Added Money',
    'PARTNER_WITHDRAW' => 'Partner Took Money',
    'PARTNER_SETTLEMENT' => 'Partner Settlement',
    'SALARY_PAYMENT' => 'Paid Salary',
    'EMPLOYEE_ADVANCE' => 'Employee Took Advance',
    'EMPLOYEE_ADVANCE_WRITEOFF' => 'Employee Advance Write-Off',
    'LOAN_GIVEN' => 'Lent Money to Someone',
    'LOAN_RECEIVED' => 'Received Money Back',
    'LOAN_TAKEN' => 'Borrowed Money',
    'LOAN_REPAID' => 'Repaid a Loan',
    'CONTRA_TRANSFER' => 'Cash to Bank / Bank to Cash',
    'GST_PAYMENT' => 'GST Payment',
    'GST_UTILIZATION' => 'GST Input Utilized',
    'BAD_DEBT' => 'Bad Debt Write-Off',
]);

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
