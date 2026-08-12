<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
Auth::check();
$db = Database::getInstance();

// Get alert count
$alertCount = $db->fetch("SELECT COUNT(*) as cnt FROM alerts WHERE business_id = ? AND is_read = 0", [Auth::user('business_id')]);
$unreadAlerts = $alertCount['cnt'] ?? 0;

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$canWritePrimaryBooks = Auth::hasAnyBookAccess(Auth::getPrimaryBookKeys(), 'write');
$canReadPrimaryBooks = Auth::hasAnyBookAccess(Auth::getPrimaryBookKeys(), 'read');
$cssVersion = @filemtime(__DIR__ . '/../assets/css/style.css') ?: APP_VERSION;
$uiCssVersion = @filemtime(__DIR__ . '/../assets/css/ui-system.css') ?: APP_VERSION;
$polishCssVersion = @filemtime(__DIR__ . '/../assets/css/ui-polish.css') ?: APP_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#ffffff">
    <?php if (APP_IS_TESTING): ?><meta name="robots" content="noindex, nofollow, noarchive"><?php endif; ?>
    <title><?= APP_IS_TESTING ? '[TEST] ' : '' ?><?= $pageTitle ?? 'Dashboard' ?> — <?= APP_NAME ?></title>
    <meta name="description" content="<?= APP_NAME ?> — Car Trading Accounting System">
    <link rel="icon" type="image/png" href="<?= APP_URL ?>logo.png">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>assets/css/style.css?v=<?= $cssVersion ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>assets/css/ui-system.css?v=<?= $uiCssVersion ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>assets/css/ui-polish.css?v=<?= $polishCssVersion ?>">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.1.0/fonts/remixicon.css" rel="stylesheet">
</head>
<body class="<?= APP_IS_TESTING ? 'env-testing' : '' ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-container">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar" aria-label="Application sidebar">
        <a class="sidebar-brand" href="<?= APP_URL ?>dashboard.php" aria-label="<?= APP_NAME ?> dashboard">
            <div class="brand-icon">
                <img src="<?= APP_URL ?>logo.png" alt="">
            </div>
            <div>
                <div class="brand-text"><?= APP_NAME ?></div>
            </div>
            <span class="brand-version">v<?= APP_VERSION ?></span>
        </a>

        <nav class="sidebar-nav" aria-label="Primary navigation">
            <div class="nav-section">
                <div class="nav-section-title">Main</div>
                <a href="<?= APP_URL ?>dashboard.php" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-dashboard-3-line"></i></span>
                    Dashboard
                </a>
                <?php if ($canWritePrimaryBooks): ?>
                <a href="<?= APP_URL ?>transactions/new.php" class="nav-link <?= $currentPage === 'new' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-add-circle-line"></i></span>
                    New Entry
                </a>
                <a href="<?= APP_URL ?>cars/purchase_payments.php" class="nav-link <?= $currentPage === 'purchase_payments' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-hand-coin-line"></i></span>
                    Car Purchase Payments
                </a>
                <?php endif; ?>
                <?php if ($canReadPrimaryBooks): ?>
                <a href="<?= APP_URL ?>transactions/list.php" class="nav-link <?= $currentPage === 'list' && strpos($_SERVER['PHP_SELF'], 'transactions') !== false ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-exchange-line"></i></span>
                    All Entries
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('car_profitability', 'read')): ?>
                <a href="<?= APP_URL ?>reports/action_center.php" class="nav-link <?= $currentPage === 'action_center' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-task-line"></i></span>
                    Action Center
                </a>
                <?php endif; ?>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Management</div>
                <a href="<?= APP_URL ?>cars/list.php" class="nav-link <?= strpos($_SERVER['PHP_SELF'], 'cars/') !== false && strpos($_SERVER['PHP_SELF'], 'commission') === false && strpos($_SERVER['PHP_SELF'], 'outside-cars') === false ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-car-line"></i></span>
                    Cars
                </a>
                <a href="<?= APP_URL ?>outside-cars/list.php" class="nav-link <?= strpos($_SERVER['PHP_SELF'], 'outside-cars/') !== false ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-steering-2-line"></i></span>
                    Outside Cars
                </a>
                <?php if (Auth::hasBookAccess('car_profitability', 'read')): ?>
                <a href="<?= APP_URL ?>reports/car_inventory.php" class="nav-link <?= $currentPage === 'car_inventory' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-parking-box-line"></i></span>
                    Car Inventory
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('rto_book', 'read')): ?>
                <a href="<?= APP_URL ?>rto/list.php" class="nav-link <?= strpos($_SERVER['PHP_SELF'], 'rto/') !== false ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-file-shield-2-line"></i></span>
                    RTO Book
                </a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>partners/list.php?type=MAIN" class="nav-link <?= strpos($_SERVER['PHP_SELF'], 'partners/') !== false && (($_GET['type'] ?? '') !== 'CARWISE') ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-group-line"></i></span>
                    Main Partners
                </a>
                <a href="<?= APP_URL ?>partners/list.php?type=CARWISE" class="nav-link <?= strpos($_SERVER['PHP_SELF'], 'partners/') !== false && (($_GET['type'] ?? '') === 'CARWISE') ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-team-line"></i></span>
                    Car-wise Partners
                </a>
                <a href="<?= APP_URL ?>employees/list.php" class="nav-link <?= strpos($_SERVER['PHP_SELF'], 'employees/') !== false ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-user-star-line"></i></span>
                    Employees
                </a>
                <a href="<?= APP_URL ?>parties/list.php" class="nav-link <?= strpos($_SERVER['PHP_SELF'], 'parties/') !== false ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-contacts-book-line"></i></span>
                    Debtors / Creditors
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Reports</div>
                <?php if (Auth::hasBookAccess('cash_book', 'read')): ?>
                <a href="<?= APP_URL ?>reports/cashbook.php" class="nav-link <?= $currentPage === 'cashbook' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-book-2-line"></i></span>
                    Cash Book
                </a>
                <a href="<?= APP_URL ?>reports/cash_reconciliation.php" class="nav-link <?= $currentPage === 'cash_reconciliation' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-bank-card-line"></i></span>
                    End-of-Day Cash Count
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('bank_book', 'read')): ?>
                <a href="<?= APP_URL ?>reports/bankbook.php" class="nav-link <?= $currentPage === 'bankbook' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-bank-line"></i></span>
                    Bank Book
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('trial_balance') && Auth::hasBookAccess('trial_balance', 'read')): ?>
                <a href="<?= APP_URL ?>reports/trial_balance.php" class="nav-link <?= $currentPage === 'trial_balance' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-scales-3-line"></i></span>
                    Trial Balance
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('profit_loss') && Auth::hasBookAccess('profit_loss', 'read')): ?>
                <a href="<?= APP_URL ?>reports/profit_loss.php" class="nav-link <?= $currentPage === 'profit_loss' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-line-chart-line"></i></span>
                    Profit & Loss
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('profit_loss', 'read') || Auth::hasBookAccess('general_ledger', 'read')): ?>
                <a href="<?= APP_URL ?>reports/entry_types.php" class="nav-link <?= $currentPage === 'entry_types' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-layout-grid-line"></i></span>
                    Income &amp; Expense Types
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('balance_sheet') && Auth::hasBookAccess('balance_sheet', 'read')): ?>
                <a href="<?= APP_URL ?>reports/balance_sheet.php" class="nav-link <?= $currentPage === 'balance_sheet' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-file-list-3-line"></i></span>
                    Balance Sheet
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('car_profitability') && Auth::hasBookAccess('car_profitability', 'read')): ?>
                <a href="<?= APP_URL ?>reports/car_profitability.php" class="nav-link <?= $currentPage === 'car_profitability' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-car-washing-line"></i></span>
                    Car Profitability
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('car_profitability', 'read')): ?>
                <a href="<?= APP_URL ?>reports/loan_commissions.php" class="nav-link <?= $currentPage === 'loan_commissions' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-bank-card-line"></i></span>
                    Loan Commissions
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('general_ledger') && Auth::hasBookAccess('general_ledger', 'read')): ?>
                <a href="<?= APP_URL ?>reports/ledger.php" class="nav-link <?= $currentPage === 'ledger' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-file-text-line"></i></span>
                    General Ledger
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('debtor_ageing') && Auth::hasBookAccess('debtor_ageing', 'read')): ?>
                <a href="<?= APP_URL ?>reports/debtor_ageing.php" class="nav-link <?= $currentPage === 'debtor_ageing' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-timer-line"></i></span>
                    Debtor Ageing
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('creditors_report') && Auth::hasBookAccess('creditors_report', 'read')): ?>
                <a href="<?= APP_URL ?>reports/creditors.php" class="nav-link <?= $currentPage === 'creditors' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-hand-coin-line"></i></span>
                    Creditors
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('partner_accounts') && Auth::hasBookAccess('partner_accounts', 'read')): ?>
                <a href="<?= APP_URL ?>reports/partner_accounts.php" class="nav-link <?= $currentPage === 'partner_accounts' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-group-2-line"></i></span>
                    Partner Accounts
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('employee_advances') && Auth::hasBookAccess('employee_advances', 'read')): ?>
                <a href="<?= APP_URL ?>reports/employee_advances.php" class="nav-link <?= $currentPage === 'employee_advances' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-user-star-line"></i></span>
                    Employee Advances
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('outstanding_summary') && Auth::hasBookAccess('outstanding_summary', 'read')): ?>
                <a href="<?= APP_URL ?>reports/outstanding_summary.php" class="nav-link <?= $currentPage === 'outstanding_summary' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-survey-line"></i></span>
                    Outstanding Summary
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('jv_register') && Auth::hasBookAccess('jv_register', 'read')): ?>
                <a href="<?= APP_URL ?>reports/jv_register.php" class="nav-link <?= $currentPage === 'jv_register' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-booklet-line"></i></span>
                    JV Register
                </a>
                <?php endif; ?>
                <?php if (!isClientHiddenBook('audit_log') && Auth::isAdmin()): ?>
                <a href="<?= APP_URL ?>reports/audit_log.php" class="nav-link <?= $currentPage === 'audit_log' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-shield-check-line"></i></span>
                    Audit Log
                </a>
                <?php endif; ?>
            </div>

            <?php if (Auth::isAdmin()): ?>
            <div class="nav-section">
                <div class="nav-section-title">Settings</div>
                <a href="<?= APP_URL ?>settings/business.php" class="nav-link <?= $currentPage === 'business' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-building-2-line"></i></span>
                    Business Profile
                </a>
                <a href="<?= APP_URL ?>settings/users.php" class="nav-link <?= $currentPage === 'users' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-user-settings-line"></i></span>
                    User Management
                </a>
                <a href="<?= APP_URL ?>settings/accounts.php" class="nav-link <?= $currentPage === 'accounts' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-bank-card-line"></i></span>
                    Account Settings
                </a>
                <a href="<?= APP_URL ?>settings/opening_balances.php" class="nav-link <?= $currentPage === 'opening_balances' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-scales-3-line"></i></span>
                    Opening Balances
                </a>
                <a href="<?= APP_URL ?>settings/categories.php" class="nav-link <?= $currentPage === 'categories' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-price-tag-3-line"></i></span>
                    Custom Entry Types
                </a>
                <a href="<?= APP_URL ?>settings/financial_year.php" class="nav-link <?= $currentPage === 'financial_year' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-calendar-line"></i></span>
                    Financial Year
                </a>
            </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="user-avatar"><?= strtoupper(substr(Auth::user('full_name'), 0, 1)) ?></div>
                <div class="user-info">
                    <div class="user-name"><?= clean(Auth::user('full_name')) ?></div>
                    <div class="user-role"><?= Auth::user('role') ?></div>
                </div>
            </div>
            <a href="<?= APP_URL ?>logout.php" class="sidebar-logout-btn">
                <i class="ri-logout-box-r-line"></i>
                Logout
            </a>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebar-backdrop" aria-hidden="true"></div>

    <!-- Main Content -->
    <main class="main-content" id="main-content" tabindex="-1">
        <!-- Top Header -->
        <header class="top-header">
            <div class="top-header-inner">
                <div class="header-left">
                    <button class="header-btn" id="sidebar-toggle" type="button" aria-label="Toggle navigation menu" aria-controls="sidebar" aria-expanded="false">
                        <i class="ri-menu-line" aria-hidden="true"></i>
                    </button>
                    <div class="page-title">
                        <span class="title-icon"><?= $pageIcon ?? '<i class="ri-dashboard-3-line"></i>' ?></span>
                        <?= $pageTitle ?? 'Dashboard' ?>
                        <?php if (APP_IS_TESTING): ?><span class="environment-badge">TEST</span><?php endif; ?>
                    </div>
                </div>
                <div class="header-right">
                    <?php if ($canWritePrimaryBooks): ?>
                    <a href="<?= APP_URL ?>transactions/new.php" class="btn btn-primary btn-sm top-entry-btn">
                        <i class="ri-add-line"></i> New Entry
                    </a>
                    <?php endif; ?>
                    <div class="header-context" title="Current financial year">
                        <i class="ri-calendar-line" aria-hidden="true"></i>
                        FY <?= getFYLabel() ?>
                    </div>
                    <a href="<?= APP_URL ?>dashboard.php#alerts" class="header-btn notification-btn" aria-label="View alerts">
                        <i class="ri-notification-3-line" aria-hidden="true"></i>
                        <?php if ($unreadAlerts > 0): ?>
                            <span class="badge"><?= $unreadAlerts ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= APP_URL ?>logout.php" class="header-btn desktop-logout-btn" title="Logout" aria-label="Logout">
                        <i class="ri-logout-box-r-line" aria-hidden="true"></i>
                    </a>
                </div>
            </div><!-- /.top-header-inner -->
        </header>

        <div class="page-content animate-fade">
            <?php if ($flash = getFlash('success')): ?>
                <div class="alert alert-success" role="status" aria-live="polite" data-auto-dismiss><i class="ri-check-line" aria-hidden="true"></i> <?= $flash ?> <button type="button" class="alert-close" aria-label="Dismiss message" onclick="this.parentElement.remove()">×</button></div>
            <?php endif; ?>
            <?php if ($flash = getFlash('error')): ?>
                <div class="alert alert-error" role="alert" data-auto-dismiss><i class="ri-error-warning-line" aria-hidden="true"></i> <?= $flash ?> <button type="button" class="alert-close" aria-label="Dismiss message" onclick="this.parentElement.remove()">×</button></div>
            <?php endif; ?>
            <?php if ($flash = getFlash('warning')): ?>
                <div class="alert alert-warning" role="alert" data-auto-dismiss><i class="ri-alert-line" aria-hidden="true"></i> <?= $flash ?> <button type="button" class="alert-close" aria-label="Dismiss message" onclick="this.parentElement.remove()">×</button></div>
            <?php endif; ?>
