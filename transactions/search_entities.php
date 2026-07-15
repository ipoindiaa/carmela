<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$db = Database::getInstance();

Auth::check();

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
            "SELECT a.id, a.code, a.name, a.group_name, a.sub_group, a.entity_type, a.entity_id,
                    c.id AS car_id, c.registration_no AS car_reg, c.status AS car_status
             FROM accounts a
             LEFT JOIN cars c
               ON c.business_id = a.business_id
              AND (c.id = a.entity_id OR c.account_id = a.id)
             WHERE a.business_id = ?
               AND a.is_active = 1
               AND (
                   a.code LIKE ?
                   OR a.name LIKE ?
                   OR a.group_name LIKE ?
                   OR a.sub_group LIKE ?
                   OR a.entity_type LIKE ?
                   OR c.registration_no LIKE ?
               )
             ORDER BY
               CASE
                 WHEN c.registration_no LIKE ? THEN 0
                 WHEN a.code LIKE ? THEN 1
                 WHEN a.name LIKE ? THEN 2
                 ELSE 2
               END,
               a.group_name, a.sub_group, a.code, a.name
             LIMIT $limit",
            [$businessId, $needle, $needle, $needle, $needle, $needle, $needle, $prefix, $prefix, $prefix]
        );
        foreach ($rows as $row) {
            $isCarAccount = !empty($row['car_id']);
            $label = $isCarAccount
                ? formatRegistrationNo($row['car_reg']) . ' — Car cost account'
                : formatAllRegistrationNosInString(trim(($row['code'] ?: '') . ' — ' . ($row['name'] ?: ''), ' —'));
            $meta = $isCarAccount
                ? 'Adds this split amount to the car timeline and total cost | ' . str_replace('_', ' ', $row['car_status'])
                : trim(($row['group_name'] ?: 'General') . (!empty($row['sub_group']) ? ' / ' . $row['sub_group'] : '') . (!empty($row['entity_type']) ? ' | ' . $row['entity_type'] : ''));
            $results[] = [
                'id' => $row['id'],
                'label' => $label,
                'meta' => $meta,
                'entity_type' => $isCarAccount ? 'CAR' : $row['entity_type'],
                'entity_id' => $isCarAccount ? $row['car_id'] : $row['entity_id'],
            ];
        }
        break;

    case 'car':
    case 'payment_car':
    case 'rto_car':
        $statusFilterSql = "AND status <> 'CANCELLED'";
        if (in_array($context, ['CAR_SALE', 'CAR_TOKEN_RECEIVED', 'CAR_EXPENSE'], true)) {
            $statusFilterSql = "AND status = 'IN_STOCK'";
        } elseif ($kind === 'payment_car' && $context === 'LOAN_RECEIVED') {
            $statusFilterSql = "AND status IN ('SOLD', 'PENDING_PAYMENT')";
        } elseif ($kind === 'payment_car' && $context === 'LOAN_REPAID') {
            $statusFilterSql = "AND status IN ('IN_STOCK', 'PENDING_PAYMENT', 'SOLD')";
        }
        $ownershipFilterSql = '';
        if (in_array($context, ['CAR_SALE', 'CAR_EXPENSE'], true) || ($kind === 'payment_car' && $context === 'LOAN_REPAID')) {
            $ownershipFilterSql = "AND COALESCE(ownership_type, 'OWNED') = 'OWNED'";
        }
        $rows = $db->fetchAll(
            "SELECT c.id, c.registration_no, c.make, c.model, c.year, c.status,
                    buyer.id AS buyer_party_id, buyer.name AS buyer_name,
                    seller.id AS seller_party_id, seller.name AS seller_name,
                    token_party.id AS token_party_id, token_party.name AS token_party_name,
                    COALESCE(tokens.available_amount, 0) AS token_available
             FROM cars c
             LEFT JOIN debtors_creditors buyer ON buyer.id = c.buyer_party_id AND buyer.business_id = c.business_id
             LEFT JOIN debtors_creditors seller ON seller.id = c.seller_party_id AND seller.business_id = c.business_id
             LEFT JOIN (
                 SELECT business_id, car_id, party_id, SUM(amount - applied_amount) AS available_amount
                 FROM car_tokens
                 WHERE status IN ('OPEN','PARTIAL')
                 GROUP BY business_id, car_id, party_id
             ) tokens ON tokens.business_id = c.business_id AND tokens.car_id = c.id AND tokens.available_amount > 0.009
             LEFT JOIN debtors_creditors token_party ON token_party.id = tokens.party_id AND token_party.business_id = c.business_id
             WHERE c.business_id = ?
               $statusFilterSql
               $ownershipFilterSql
               AND (
                   c.registration_no LIKE ?
                   OR c.make LIKE ?
                   OR c.model LIKE ?
                   OR CONCAT(COALESCE(c.make, ''), ' ', COALESCE(c.model, '')) LIKE ?
               )
             ORDER BY
               CASE
                 WHEN c.registration_no LIKE ? THEN 0
                 WHEN c.make LIKE ? THEN 1
                 ELSE 2
               END,
               c.registration_no
             LIMIT $limit",
            [$businessId, $needle, $needle, $needle, $needle, $prefix, $prefix]
        );
        foreach ($rows as $row) {
            $linkedPartyId = null;
            $linkedPartyLabel = '';
            if ($kind === 'payment_car') {
                if ($context === 'LOAN_RECEIVED') {
                    $linkedPartyId = $row['buyer_party_id'] ?? null;
                    $linkedPartyLabel = $row['buyer_name'] ?? '';
                } elseif ($context === 'LOAN_REPAID') {
                    $linkedPartyId = $row['seller_party_id'] ?? null;
                    $linkedPartyLabel = $row['seller_name'] ?? '';
                }
            } elseif ($kind === 'car' && in_array($context, ['CAR_SALE', 'CAR_TOKEN_RECEIVED'], true)) {
                $linkedPartyId = $row['token_party_id'] ?? null;
                $linkedPartyLabel = $row['token_party_name'] ?? '';
            }
            $results[] = [
                'id' => $row['id'],
                'label' => trim(formatRegistrationNo($row['registration_no']) . ' — ' . trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? ''))),
                'meta' => trim(($row['year'] ?: '') . ' ' . ($row['status'] ?? '')),
                'linked_party_id' => $linkedPartyId,
                'linked_party_label' => $linkedPartyLabel,
                'token_available' => floatval($row['token_available'] ?? 0),
            ];
        }
        break;

    case 'partner':
    case 'main_partner':
        $partnerTypeFilter = '';
        if ($kind === 'main_partner') {
            $partnerTypeFilter = " AND partner_type = 'MAIN'";
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
                'meta' => (($row['partner_type'] ?? 'MAIN') === 'CARWISE' ? 'Car-wise' : 'Main') . ' | Default car share ' . formatPlainNumber($row['profit_share_pct'] ?? 0) . '%' . (!empty($row['phone']) ? ' | ' . $row['phone'] : ''),
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

    case 'buyer':
    case 'counterparty_debtor':
    case 'debtor':
    case 'counterparty_creditor':
    case 'creditor':
        $isDebtor = in_array($kind, ['buyer', 'counterparty_debtor', 'debtor'], true);
        $types = $isDebtor ? ['DEBTOR', 'BUYER'] : ['CREDITOR', 'SELLER'];
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
