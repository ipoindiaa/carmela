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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Dashboard' ?> — <?= APP_NAME ?></title>
    <meta name="description" content="<?= APP_NAME ?> — Car Trading Accounting System">
    <link rel="stylesheet" href="<?= APP_URL ?>assets/css/style.css?v=<?= $cssVersion ?>">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.1.0/fonts/remixicon.css" rel="stylesheet">
</head>
<body>
<div class="app-container">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">A</div>
            <div>
                <div class="brand-text"><?= APP_NAME ?></div>
            </div>
            <span class="brand-version">v<?= APP_VERSION ?></span>
        </div>

        <nav class="sidebar-nav">
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
                <?php endif; ?>
                <?php if ($canReadPrimaryBooks): ?>
                <a href="<?= APP_URL ?>transactions/list.php" class="nav-link <?= $currentPage === 'list' && strpos($_SERVER['PHP_SELF'], 'transactions') !== false ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-exchange-line"></i></span>
                    Transactions
                </a>
                <?php endif; ?>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Management</div>
                <a href="<?= APP_URL ?>cars/list.php" class="nav-link <?= strpos($_SERVER['PHP_SELF'], 'cars/') !== false ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-car-line"></i></span>
                    Cars
                </a>
                <a href="<?= APP_URL ?>partners/list.php" class="nav-link <?= strpos($_SERVER['PHP_SELF'], 'partners/') !== false ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-group-line"></i></span>
                    Partners
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
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('bank_book', 'read')): ?>
                <a href="<?= APP_URL ?>reports/bankbook.php" class="nav-link <?= $currentPage === 'bankbook' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-bank-line"></i></span>
                    Bank Book
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('gst_book', 'read')): ?>
                <a href="<?= APP_URL ?>reports/gst_book.php" class="nav-link <?= $currentPage === 'gst_book' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-file-list-2-line"></i></span>
                    GST Book
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('trial_balance', 'read')): ?>
                <a href="<?= APP_URL ?>reports/trial_balance.php" class="nav-link <?= $currentPage === 'trial_balance' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-scales-3-line"></i></span>
                    Trial Balance
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('profit_loss', 'read')): ?>
                <a href="<?= APP_URL ?>reports/profit_loss.php" class="nav-link <?= $currentPage === 'profit_loss' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-line-chart-line"></i></span>
                    Profit & Loss
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('balance_sheet', 'read')): ?>
                <a href="<?= APP_URL ?>reports/balance_sheet.php" class="nav-link <?= $currentPage === 'balance_sheet' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-file-list-3-line"></i></span>
                    Balance Sheet
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('car_profitability', 'read')): ?>
                <a href="<?= APP_URL ?>reports/car_profitability.php" class="nav-link <?= $currentPage === 'car_profitability' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-car-washing-line"></i></span>
                    Car Profitability
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('general_ledger', 'read')): ?>
                <a href="<?= APP_URL ?>reports/ledger.php" class="nav-link <?= $currentPage === 'ledger' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-file-text-line"></i></span>
                    General Ledger
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('debtor_ageing', 'read')): ?>
                <a href="<?= APP_URL ?>reports/debtor_ageing.php" class="nav-link <?= $currentPage === 'debtor_ageing' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-timer-line"></i></span>
                    Debtor Ageing
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('creditors_report', 'read')): ?>
                <a href="<?= APP_URL ?>reports/creditors.php" class="nav-link <?= $currentPage === 'creditors' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-hand-coin-line"></i></span>
                    Creditors
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('partner_accounts', 'read')): ?>
                <a href="<?= APP_URL ?>reports/partner_accounts.php" class="nav-link <?= $currentPage === 'partner_accounts' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-group-2-line"></i></span>
                    Partner Accounts
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('employee_advances', 'read')): ?>
                <a href="<?= APP_URL ?>reports/employee_advances.php" class="nav-link <?= $currentPage === 'employee_advances' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-user-star-line"></i></span>
                    Employee Advances
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('outstanding_summary', 'read')): ?>
                <a href="<?= APP_URL ?>reports/outstanding_summary.php" class="nav-link <?= $currentPage === 'outstanding_summary' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-survey-line"></i></span>
                    Outstanding Summary
                </a>
                <?php endif; ?>
                <?php if (Auth::hasBookAccess('jv_register', 'read')): ?>
                <a href="<?= APP_URL ?>reports/jv_register.php" class="nav-link <?= $currentPage === 'jv_register' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="ri-booklet-line"></i></span>
                    JV Register
                </a>
                <?php endif; ?>
                <?php if (Auth::isAdmin()): ?>
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
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Header -->
        <header class="top-header">
            <div class="header-left">
                <button class="header-btn" id="sidebar-toggle" type="button" aria-label="Toggle navigation menu">
                    <i class="ri-menu-line"></i>
                </button>
                <div class="page-title">
                    <span class="title-icon"><?= $pageIcon ?? '<i class="ri-dashboard-3-line"></i>' ?></span>
                    <?= $pageTitle ?? 'Dashboard' ?>
                </div>
            </div>
            <div class="header-right">
                <?php if ($canWritePrimaryBooks): ?>
                <a href="<?= APP_URL ?>transactions/new.php" class="btn btn-primary btn-sm top-entry-btn">
                    <i class="ri-add-line"></i> New Entry
                </a>
                <?php endif; ?>
                <div class="header-btn" style="font-size:12px; color: var(--text-muted);">
                    <i class="ri-calendar-line"></i>
                    FY <?= getFYLabel() ?>
                </div>
                <a href="<?= APP_URL ?>dashboard.php#alerts" class="header-btn notification-btn">
                    <i class="ri-notification-3-line"></i>
                    <?php if ($unreadAlerts > 0): ?>
                        <span class="badge"><?= $unreadAlerts ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= APP_URL ?>logout.php" class="header-btn desktop-logout-btn" title="Logout">
                    <i class="ri-logout-box-r-line"></i>
                </a>
            </div>
        </header>

        <div class="page-content animate-fade">
            <?php if ($flash = getFlash('success')): ?>
                <div class="alert alert-success"><i class="ri-check-line"></i> <?= $flash ?> <button class="alert-close" onclick="this.parentElement.remove()">×</button></div>
            <?php endif; ?>
            <?php if ($flash = getFlash('error')): ?>
                <div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= $flash ?> <button class="alert-close" onclick="this.parentElement.remove()">×</button></div>
            <?php endif; ?>
            <?php if ($flash = getFlash('warning')): ?>
                <div class="alert alert-warning"><i class="ri-alert-line"></i> <?= $flash ?> <button class="alert-close" onclick="this.parentElement.remove()">×</button></div>
            <?php endif; ?>
