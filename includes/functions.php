<?php
require_once __DIR__ . '/../config/app.php';

/**
 * Format amount in Indian Rupee style without decimals (₹1,23,456)
 */
function formatAmount($amount, $showSign = false) {
    $amount = round(floatval($amount));
    $sign = '';
    if ($showSign && $amount > 0) $sign = '+';
    if ($amount < 0) { $sign = '-'; $amount = abs($amount); }

    $whole = (int) $amount;

    if ($whole < 1000) return $sign . APP_CURRENCY . number_format($whole, 0);

    $lastThree = substr($whole, -3);
    $remaining = substr($whole, 0, -3);
    $remaining = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining);

    return $sign . APP_CURRENCY . $remaining . ',' . $lastThree;
}

/**
 * Format plain numbers without decimals for general UI display.
 */
function formatPlainNumber($value) {
    return number_format(round((float) $value), 0, '.', ',');
}

function transactionTypeLabel($transactionType, array $context = []) {
    $transactionType = strtoupper((string) $transactionType);
    $entryTypeId = trim((string) ($context['entry_type_id'] ?? ''));
    $explicitName = trim((string) ($context['entry_type_name'] ?? ''));
    if ($explicitName !== '') {
        return $explicitName;
    }
    if (str_starts_with($entryTypeId, 'CUSTOM:')) {
        $accountId = substr($entryTypeId, 7);
        $businessId = $context['business_id'] ?? (class_exists('Auth') && Auth::isLoggedIn() ? Auth::user('business_id') : null);
        if ($accountId !== '' && $businessId && class_exists('Database')) {
            static $customEntryTypeNames = [];
            $cacheKey = $businessId . ':' . $accountId;
            if (!array_key_exists($cacheKey, $customEntryTypeNames)) {
                $row = Database::getInstance()->fetch(
                    "SELECT name FROM accounts WHERE id = ? AND business_id = ? LIMIT 1",
                    [$accountId, $businessId]
                );
                $customEntryTypeNames[$cacheKey] = trim((string) ($row['name'] ?? ''));
            }
            if ($customEntryTypeNames[$cacheKey] !== '') {
                return $customEntryTypeNames[$cacheKey];
            }
        }
    }
    if (str_starts_with($entryTypeId, 'SYSTEM:')) {
        $identityCode = substr($entryTypeId, 7);
        if (!empty(ENTRY_TYPE_META[$identityCode]['label'])) {
            return ENTRY_TYPE_META[$identityCode]['label'];
        }
    }
    $hasCarLink = !empty($context['car_id']) || !empty($context['car_reg']);

    return match ($transactionType) {
        'CAR_SALE' => (($context['car_ownership_type'] ?? $context['ownership_type'] ?? '') === 'COMMISSION') ? 'Commission Car Sale' : (TXN_TYPES[$transactionType] ?? $transactionType),
        'LOAN_REPAID' => (($context['car_ownership_type'] ?? $context['ownership_type'] ?? '') === 'COMMISSION') ? 'Commission Owner Payment' : ($hasCarLink ? 'Seller Payment Clearing' : 'Payment Clearing Paid'),
        'LOAN_RECEIVED' => $hasCarLink ? 'Car Payment Clearing' : 'Payment Clearing Received',
        default => TXN_TYPES[$transactionType] ?? $transactionType,
    };
}

function systemEntryTypeId($transactionType) {
    return 'SYSTEM:' . strtoupper(trim((string) $transactionType));
}

function customEntryTypeId($accountId) {
    return 'CUSTOM:' . trim((string) $accountId);
}

function customEntryTypeAccountId($entryTypeId) {
    $entryTypeId = trim((string) $entryTypeId);
    return str_starts_with($entryTypeId, 'CUSTOM:') ? substr($entryTypeId, 7) : null;
}

function entryTypeMeta($transactionType, array $context = []) {
    $entryTypeId = trim((string) ($context['entry_type_id'] ?? ''));
    if (str_starts_with($entryTypeId, 'SYSTEM:')) {
        $code = substr($entryTypeId, 7);
        if (isset(ENTRY_TYPE_META[$code])) {
            return array_merge(ENTRY_TYPE_META[$code], ['id' => $entryTypeId, 'source' => 'SYSTEM', 'code' => $code]);
        }
    }
    if (str_starts_with($entryTypeId, 'CUSTOM:')) {
        $flow = strtolower((string) ($context['entry_type_flow'] ?? ''));
        if (!in_array($flow, ['in', 'out'], true)) {
            $group = strtoupper((string) ($context['entry_type_group'] ?? ''));
            $accountId = customEntryTypeAccountId($entryTypeId);
            $businessId = $context['business_id'] ?? (class_exists('Auth') && Auth::isLoggedIn() ? Auth::user('business_id') : null);
            if ($group === '' && $accountId && $businessId && class_exists('Database')) {
                static $customEntryTypeGroups = [];
                $cacheKey = $businessId . ':' . $accountId;
                if (!array_key_exists($cacheKey, $customEntryTypeGroups)) {
                    $row = Database::getInstance()->fetch(
                        "SELECT group_name FROM accounts WHERE id = ? AND business_id = ? LIMIT 1",
                        [$accountId, $businessId]
                    );
                    $customEntryTypeGroups[$cacheKey] = strtoupper((string) ($row['group_name'] ?? ''));
                }
                $group = $customEntryTypeGroups[$cacheKey];
            }
            $flow = $group === 'INCOME' ? 'in' : 'out';
        }
        return [
            'id' => $entryTypeId,
            'source' => 'CUSTOM',
            'code' => $context['entry_type_code'] ?? customEntryTypeAccountId($entryTypeId),
            'label' => transactionTypeLabel($transactionType, $context),
            'flow' => $flow,
            'category' => 'Custom',
            'icon' => $flow === 'in' ? 'ri-arrow-down-circle-line' : 'ri-arrow-up-circle-line',
            'description' => 'Custom money-in or money-out entry type.',
            'summary' => true,
        ];
    }
    $code = strtoupper((string) $transactionType);
    return array_merge(
        ENTRY_TYPE_META[$code] ?? ['label' => TXN_TYPES[$code] ?? $code, 'flow' => 'neutral', 'category' => 'Other', 'icon' => 'ri-file-list-3-line', 'description' => '', 'summary' => false],
        ['id' => systemEntryTypeId($code), 'source' => 'SYSTEM', 'code' => $code]
    );
}

/**
 * Transaction flow from business perspective.
 */
function transactionBusinessFlow($transactionType, array $context = []) {
    return entryTypeMeta($transactionType, $context)['flow'] ?? 'neutral';
}

function transactionBusinessFlowLabel($transactionType) {
    return match (transactionBusinessFlow($transactionType)) {
        'in' => 'Money In',
        'out' => 'Money Out',
        default => 'Transfer / Internal',
    };
}

function flowColorClass($flow) {
    return match ((string) $flow) {
        'in' => 'flow-in',
        'out' => 'flow-out',
        default => 'flow-neutral',
    };
}

function transactionFlowColorClass($transactionType, array $context = []) {
    return flowColorClass(transactionBusinessFlow($transactionType, $context));
}

function signedAmountColorClass($amount, $positiveMeans = 'in') {
    $value = floatval($amount);
    if (abs($value) < 0.00001) {
        return 'flow-neutral';
    }

    $positiveMeans = strtolower((string) $positiveMeans) === 'out' ? 'out' : 'in';
    if ($value > 0) {
        return $positiveMeans === 'in' ? 'flow-in' : 'flow-out';
    }

    return $positiveMeans === 'in' ? 'flow-out' : 'flow-in';
}

function normalizePhoneNumber($phone) {
    return preg_replace('/\D+/', '', (string) $phone);
}

function validatePhoneNumber($phone, $fieldLabel = 'Phone number', $required = false) {
    $normalized = normalizePhoneNumber($phone);
    if ($normalized === '') {
        if ($required) {
            throw new Exception($fieldLabel . ' is required.');
        }
        return null;
    }
    if (!preg_match('/^\d{10}$/', $normalized)) {
        throw new Exception($fieldLabel . ' must be exactly 10 digits.');
    }
    return $normalized;
}

function validateEmailAddress($email, $fieldLabel = 'Email', $required = false) {
    $normalized = strtolower(trim((string) $email));
    if ($normalized === '') {
        if ($required) {
            throw new Exception($fieldLabel . ' is required.');
        }
        return null;
    }
    if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid ' . strtolower($fieldLabel) . '.');
    }
    return $normalized;
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'd M Y') {
    if (!$date) return '-';
    return date($format, strtotime($date));
}

/**
 * Format time for display.
 */
function formatTime($dateTime, $format = 'h:i A') {
    if (!$dateTime) return '-';
    $timestamp = strtotime($dateTime);
    if ($timestamp === false) return '-';
    return date($format, $timestamp);
}

/**
 * Render date + time in a compact two-line stack.
 */
function renderDateTimeStack($date, $timeSource = null, $dateFormat = 'd M Y', $timeFormat = 'h:i A') {
    if (!$date && !$timeSource) {
        return '<span class="date-time-stack"><span class="date-time-primary">-</span><span class="date-time-secondary">-</span></span>';
    }

    $dateLabel = formatDate($date ?: $timeSource, $dateFormat);
    $resolvedTimeSource = $timeSource ?: $date;
    $hasTime = is_string($resolvedTimeSource) && preg_match('/\d{1,2}:\d{2}/', $resolvedTimeSource);
    $timeLabel = $hasTime ? formatTime($resolvedTimeSource, $timeFormat) : '-';

    return '<span class="date-time-stack">'
        . '<span class="date-time-primary">' . clean($dateLabel) . '</span>'
        . '<span class="date-time-secondary">' . clean($timeLabel) . '</span>'
        . '</span>';
}

/**
 * Get current financial year
 */
function getCurrentFY($date = null) {
    $timestamp = $date ? strtotime($date) : time();
    $month = date('n', $timestamp);
    $year = date('Y', $timestamp);
    if ($month < APP_FY_START_MONTH) $year--;
    return $year;
}

/**
 * Get FY label (e.g., "2025-26")
 */
function getFYLabel($year = null) {
    if (!$year) $year = getCurrentFY();
    return $year . '-' . substr($year + 1, -2);
}

/**
 * Flash message helpers
 */
function setFlash($type, $message) {
    $_SESSION["flash_{$type}"] = $message;
}

function getFlash($type) {
    $msg = $_SESSION["flash_{$type}"] ?? null;
    unset($_SESSION["flash_{$type}"]);
    return $msg;
}

function hasFlash($type) {
    return isset($_SESSION["flash_{$type}"]);
}

/**
 * Redirect helper
 */
function redirect($url) {
    if (headers_sent()) {
        echo '<script>window.location.href="' . $url . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $url . '"></noscript>';
    } else {
        header("Location: $url");
    }
    exit;
}

/**
 * Sanitize input
 */
function clean($input) {
    if (is_array($input)) {
        return array_map('clean', $input);
    }
    return htmlspecialchars(trim((string) $input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get POST value safely
 */
function post($key, $default = '') {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

/**
 * Get GET value safely
 */
function get($key, $default = '') {
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

function isClientHiddenBook($bookKey) {
    return in_array($bookKey, CLIENT_DEMO_HIDDEN_BOOKS, true);
}

/**
 * Parse a decimal input that may include commas or currency symbols.
 */
function parseDecimalInput($value, $default = 0.0) {
    if (is_numeric($value)) {
        return floatval($value);
    }

    $normalized = preg_replace('/[^0-9.\-]/', '', (string) $value);
    if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === '-.') {
        return floatval($default);
    }

    return floatval($normalized);
}

/**
 * Normalize a vehicle registration number for validation/storage.
 */
function normalizeRegistrationNo($value) {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim((string) $value)));
}

/**
 * Validate Gujarat/Indian style registration numbers with exactly four trailing digits.
 */
function isValidRegistrationNo($value) {
    $normalized = normalizeRegistrationNo($value);
    return (bool) preg_match('/^[A-Z]{2}[0-9]{2}[A-Z]{1,3}[0-9]{4}$/', $normalized);
}

/**
 * Find an existing car using the normalized registration number, including
 * legacy rows that may still contain spaces, dashes, slashes, or dots.
 */
function findCarByRegistrationNo($db, $businessId, $value) {
    $normalized = normalizeRegistrationNo($value);
    if ($normalized === '') return null;

    return $db->fetch(
        "SELECT * FROM cars
         WHERE business_id = ?
           AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(registration_no, ' ', ''), '-', ''), '/', ''), '.', '')) = ?
         LIMIT 1",
        [$businessId, $normalized]
    ) ?: null;
}

/**
 * Generate next reference number
 */
function getNextRefNo($db, $businessId, $date = null, $prefix = 'JE') {
    $fy = getCurrentFY($date);
    $result = $db->fetch(
        "SELECT reference_no
         FROM journal_entries
         WHERE business_id = ?
           AND financial_year = ?
           AND reference_no LIKE ?
         ORDER BY reference_no DESC
         LIMIT 1",
        [$businessId, $fy, $prefix . '-' . $fy . '-%']
    );
    if ($result) {
        $parts = explode('-', $result['reference_no']);
        $num = intval(end($parts)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . '-' . $fy . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

/**
 * Convert a stored balance amount + type into a signed number.
 */
function signedBalanceValue($amount, $type) {
    $amount = abs(floatval($amount));
    return strtoupper((string) $type) === 'CR' ? -$amount : $amount;
}

/**
 * Time ago helper
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

/**
 * Get account balance
 */
function getAccountBalance($db, $accountId) {
    $result = $db->fetch("SELECT current_balance, current_balance_type FROM accounts WHERE id = ?", [$accountId]);
    if (!$result) return 0;
    return $result['current_balance_type'] === 'CR' ? -$result['current_balance'] : $result['current_balance'];
}

/**
 * Get account by entity type
 */
function getSystemAccount($db, $businessId, $entityType) {
    return $db->fetch("SELECT * FROM accounts WHERE business_id = ? AND entity_type = ? AND entity_id IS NULL AND is_active = 1", [$businessId, $entityType]);
}

/**
 * CSRF Token helpers
 */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
}

/**
 * Pagination helper
 */
function paginate($total, $perPage = 20, $currentPage = 1) {
    $total = max(0, (int) $total);
    $perPage = max(1, (int) $perPage);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    return [
        'total' => $total,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'offset' => $offset,
    ];
}

/**
 * Format car registration number for display without bracketed suffix.
 */
function formatRegistrationNo($value) {
    $val = trim((string) $value);
    if (empty($val)) return '';
    return strtoupper($val);
}

/**
 * Replaces any normalized registration number in a string with plain uppercase display.
 */
function formatAllRegistrationNosInString($str) {
    if (empty($str)) return '';
    return preg_replace_callback(
        '/([A-Z]{2}[0-9]{2}[A-Z]{1,3})([0-9]{4})\b/i',
        function ($matches) {
            return strtoupper($matches[1] . $matches[2]);
        },
        $str
    );
}
