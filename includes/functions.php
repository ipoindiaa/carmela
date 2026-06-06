<?php
require_once __DIR__ . '/../config/app.php';

/**
 * Format amount in Indian Rupee style (₹1,23,456.78)
 */
function formatAmount($amount, $showSign = false) {
    $amount = floatval($amount);
    $sign = '';
    if ($showSign && $amount > 0) $sign = '+';
    if ($amount < 0) { $sign = '-'; $amount = abs($amount); }
    
    $decimal = number_format($amount - floor($amount), 2);
    $decimal = substr($decimal, 1); // remove leading 0
    $whole = floor($amount);
    
    if ($whole < 1000) return $sign . APP_CURRENCY . number_format($whole, 0) . $decimal;
    
    $lastThree = substr($whole, -3);
    $remaining = substr($whole, 0, -3);
    $remaining = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining);
    
    return $sign . APP_CURRENCY . $remaining . ',' . $lastThree . $decimal;
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'd M Y') {
    if (!$date) return '-';
    return date($format, strtotime($date));
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
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
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
 * Format car registration number to GJ05ZX(1212) style.
 */
function formatRegistrationNo($value) {
    $val = trim((string) $value);
    if (empty($val)) return '';
    if (preg_match('/^([A-Z]{2}[0-9]{2}[A-Z]{1,3})([0-9]{4})$/i', $val, $matches)) {
        return strtoupper($matches[1]) . '(' . $matches[2] . ')';
    }
    return strtoupper($val);
}

/**
 * Replaces any normalized registration number in a string with its formatted style.
 */
function formatAllRegistrationNosInString($str) {
    if (empty($str)) return '';
    return preg_replace_callback(
        '/([A-Z]{2}[0-9]{2}[A-Z]{1,3})([0-9]{4})\b/i',
        function ($matches) {
            return strtoupper($matches[1]) . '(' . $matches[2] . ')';
        },
        $str
    );
}
