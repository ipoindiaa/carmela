<?php
$logFile = __DIR__ . '/search_debug.log';
$logDataPre = [
    'stage' => 'pre-auth',
    'time' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'get' => $_GET,
    'session_exists' => isset($_SESSION),
];
file_put_contents($logFile, json_encode($logDataPre) . "\n", FILE_APPEND);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$db = Database::getInstance();

Auth::check();

$logDataPost = [
    'stage' => 'post-auth',
    'time' => date('Y-m-d H:i:s'),
    'user_id' => $_SESSION['user_id'] ?? null,
    'business_id' => $_SESSION['business_id'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];
file_put_contents($logFile, json_encode($logDataPost) . "\n", FILE_APPEND);

Auth::requireAnyBookAccess(array_keys(BOOK_PERMISSIONS), 'read');

header('Content-Type: application/json');

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$kind = strtolower(get('kind', ''));
$query = trim(get('q', ''));
$context = strtoupper(trim(get('context', '')));
$needle = '%' . $query . '%';
$prefix = $query . '%';
$limit = 25;
$results = [];

switch ($kind) {
    case 'account':
        $rows = $db->fetchAll(
            "SELECT id, code, name, group_name, sub_group, entity_type
             FROM accounts
             WHERE business_id = ?
               AND is_active = 1
               AND (
                   code LIKE ?
                   OR name LIKE ?
                   OR group_name LIKE ?
                   OR sub_group LIKE ?
                   OR entity_type LIKE ?
               )
             ORDER BY
               CASE
                 WHEN code LIKE ? THEN 0
                 WHEN name LIKE ? THEN 1
                 ELSE 2
               END,
               group_name, sub_group, code, name
             LIMIT $limit",
            [$businessId, $needle, $needle, $needle, $needle, $needle, $prefix, $prefix]
        );
        foreach ($rows as $row) {
            $results[] = [
                'id' => $row['id'],
                'label' => formatAllRegistrationNosInString(trim(($row['code'] ?: '') . ' — ' . ($row['name'] ?: ''), ' —')),
                'meta' => trim(($row['group_name'] ?: 'General') . (!empty($row['sub_group']) ? ' / ' . $row['sub_group'] : '') . (!empty($row['entity_type']) ? ' | ' . $row['entity_type'] : '')),
            ];
        }
        break;

    case 'car':
        $statusFilterSql = "AND status <> 'CANCELLED'";
        if (in_array($context, ['CAR_SALE', 'CAR_EXPENSE'], true)) {
            $statusFilterSql = "AND status = 'IN_STOCK'";
        }
        $rows = $db->fetchAll(
            "SELECT id, registration_no, make, model, year, status
             FROM cars
             WHERE business_id = ?
               $statusFilterSql
               AND (
                   registration_no LIKE ?
                   OR make LIKE ?
                   OR model LIKE ?
                   OR CONCAT(COALESCE(make, ''), ' ', COALESCE(model, '')) LIKE ?
               )
             ORDER BY
               CASE
                 WHEN registration_no LIKE ? THEN 0
                 WHEN make LIKE ? THEN 1
                 ELSE 2
               END,
               registration_no
             LIMIT $limit",
            [$businessId, $needle, $needle, $needle, $needle, $prefix, $prefix]
        );
        foreach ($rows as $row) {
            $results[] = [
                'id' => $row['id'],
                'label' => trim(formatRegistrationNo($row['registration_no']) . ' — ' . trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? ''))),
                'meta' => trim(($row['year'] ?: '') . ' ' . ($row['status'] ?? '')),
            ];
        }
        break;

    case 'partner':
    case 'main_partner':
    case 'car_partner':
        $partnerTypeFilter = '';
        if ($kind === 'main_partner') {
            $partnerTypeFilter = " AND partner_type = 'MAIN'";
        } elseif ($kind === 'car_partner') {
            $partnerTypeFilter = " AND partner_type = 'CARWISE'";
        }
        $rows = $db->fetchAll(
            "SELECT id, name, phone, profit_share_pct, partner_type
             FROM partners
             WHERE business_id = ?
               AND is_active = 1
               $partnerTypeFilter
               AND (
                   name LIKE ?
                   OR phone LIKE ?
               )
             ORDER BY name
             LIMIT $limit",
            [$businessId, $needle, $needle]
        );
        foreach ($rows as $row) {
            $results[] = [
                'id' => $row['id'],
                'label' => $row['name'],
                'meta' => (($row['partner_type'] ?? 'MAIN') === 'CARWISE' ? 'Car-wise' : 'Main') . ' | Share ' . formatPlainNumber($row['profit_share_pct'] ?? 0) . '%' . (!empty($row['phone']) ? ' | ' . $row['phone'] : ''),
            ];
        }
        break;

    case 'employee':
        $rows = $db->fetchAll(
            "SELECT id, name, role, phone, monthly_salary
             FROM employees
             WHERE business_id = ?
               AND is_active = 1
               AND (
                   name LIKE ?
                   OR role LIKE ?
                   OR phone LIKE ?
               )
             ORDER BY CASE WHEN name LIKE ? THEN 0 ELSE 1 END, name
             LIMIT $limit",
            [$businessId, $needle, $needle, $needle, $prefix]
        );
        foreach ($rows as $row) {
            $results[] = [
                'id' => $row['id'],
                'label' => $row['name'],
                'meta' => trim(($row['role'] ?: 'Employee') . (!empty($row['phone']) ? ' | ' . $row['phone'] : '') . ' | Salary ' . formatAmount($row['monthly_salary'] ?? 0)),
            ];
        }
        break;

    case 'debtor':
    case 'creditor':
        $types = $kind === 'debtor' ? ['DEBTOR', 'BUYER'] : ['CREDITOR', 'SELLER'];
        $rows = $db->fetchAll(
            "SELECT id, name, type, phone
             FROM debtors_creditors
             WHERE business_id = ?
               AND is_active = 1
               AND type IN (?, ?)
               AND (
                   name LIKE ?
                   OR phone LIKE ?
               )
             ORDER BY name
             LIMIT $limit",
            [$businessId, $types[0], $types[1], $needle, $needle]
        );
        foreach ($rows as $row) {
            $results[] = [
                'id' => $row['id'],
                'label' => $row['name'],
                'meta' => trim($row['type'] . (!empty($row['phone']) ? ' | ' . $row['phone'] : '')),
            ];
        }
        break;
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
