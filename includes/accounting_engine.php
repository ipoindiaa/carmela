<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * AccountingEngine - The heart of AutoBooks Pro
 * Handles all double-entry bookkeeping automatically.
 * Users never need to understand Dr/Cr — the engine does it all.
 */
class AccountingEngine {
    private $db;
    private $businessId;
    private $userId;
    private $writablePrimaryAccountIds = null;
    private static $advancedSchemaEnsured = false;

    public function __construct($businessId, $userId) {
        $this->db = Database::getInstance();
        $this->businessId = $businessId;
        $this->userId = $userId;
        $this->ensureAdvancedSchema();
    }

    // ========================================
    // SETUP: Create default accounts for a new business
    // ========================================
    public function setupDefaultAccounts() {
        $defaults = [
            ['CASH-001', 'Cash Account', 'ASSET', 'Current Assets', 'CASH'],
            ['BANK-001', 'Bank Account', 'ASSET', 'Current Assets', 'BANK'],
            ['SAL-EXP', 'Salary Expense', 'EXPENSE', 'Indirect Expenses', 'GENERAL'],
            ['RENT-EXP', 'Office Rent', 'EXPENSE', 'Indirect Expenses', 'GENERAL'],
            ['MISC-EXP', 'Miscellaneous Expense', 'EXPENSE', 'Indirect Expenses', 'GENERAL'],
            ['CAR-REV', 'Car Sales Revenue', 'INCOME', 'Direct Income', 'GENERAL'],
            ['SALE-COMM', 'Car Sale Commission Income', 'INCOME', 'Direct Income', 'GENERAL'],
            ['CUST-ADV', 'Customer Token Advances', 'LIABILITY', 'Current Liabilities', 'GENERAL'],
            ['PNL', 'Profit & Loss Account', 'INCOME', 'P&L', 'GENERAL'],
            ['BAD-DEBT', 'Bad Debt Expense', 'EXPENSE', 'Direct Expenses', 'GENERAL'],
            ['ADV-WOFF', 'Employee Advance Write-Off Expense', 'EXPENSE', 'Indirect Expenses', 'GENERAL'],
        ];

        foreach ($defaults as $acc) {
            $this->createAccount($acc[0], $acc[1], $acc[2], $acc[3], $acc[4]);
        }
    }

    // ========================================
    // ACCOUNT MANAGEMENT
    // ========================================
    public function createAccount($code, $name, $group, $subGroup, $entityType, $entityId = null) {
        $id = Database::uuid();
        $accountRecord = [
            'id' => $id,
            'business_id' => $this->businessId,
            'code' => $code,
            'name' => $name,
            'group_name' => $group,
            'sub_group' => $subGroup,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ];
        $this->db->insert('accounts', $accountRecord);
        if (class_exists('Auth') && Auth::isLoggedIn()) {
            Auth::auditCreate('account', $id, $accountRecord, "Account created: $name", 'accounts');
        }
        return $id;
    }

    public function createPartner($name, $partnerType = 'CARWISE', $phone = '', $email = '', $pan = '', $defaultProfitShare = 0, $joinedDate = null, $module = 'partners') {
        $name = trim((string) $name);
        if ($name === '') {
            throw new Exception('Partner name is required.');
        }

        $partnerType = strtoupper(trim((string) $partnerType));
        if (!in_array($partnerType, ['MAIN', 'CARWISE'], true)) {
            throw new Exception('Invalid partner type.');
        }

        $phone = validatePhoneNumber($phone, 'Partner phone number');
        $email = validateEmailAddress($email, 'Partner email');
        $pan = strtoupper(trim((string) $pan));
        $defaultProfitShare = round(floatval($defaultProfitShare), 2);
        if ($defaultProfitShare < 0 || $defaultProfitShare > 100) {
            throw new Exception('Default car profit share must be between 0 and 100.');
        }

        $joinedDate = trim((string) ($joinedDate ?: date('Y-m-d')));
        $date = DateTime::createFromFormat('!Y-m-d', $joinedDate);
        if (!$date || $date->format('Y-m-d') !== $joinedDate) {
            throw new Exception('A valid partner joined date is required.');
        }

        $phoneMatchSql = $phone === '' ? "COALESCE(phone, '') = ''" : 'phone = ?';
        $existingParams = [$this->businessId, $name];
        if ($phone !== '') {
            $existingParams[] = $phone;
        }
        $existing = $this->db->fetch(
            "SELECT id, name FROM partners
             WHERE business_id = ?
               AND LOWER(TRIM(name)) = LOWER(?)
               AND $phoneMatchSql
             LIMIT 1",
            $existingParams
        );
        if ($existing) {
            throw new Exception("Partner {$existing['name']} already exists. Select the existing partner instead.");
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $partnerId = Database::uuid();
            $suffix = strtoupper(substr(str_replace('-', '', $partnerId), 0, 7));
            $capitalAccountId = $this->createAccount('CAP-' . $suffix, "$name - Capital A/c", 'EQUITY', 'Capital Accounts', 'PARTNER', $partnerId);
            $currentAccountId = $this->createAccount('CUR-' . $suffix, "$name - Current A/c", 'LIABILITY', 'Current Liabilities', 'PARTNER', $partnerId);
            $this->db->insert('partners', [
                'id' => $partnerId,
                'business_id' => $this->businessId,
                'name' => $name,
                'partner_type' => $partnerType,
                'phone' => $phone,
                'email' => $email,
                'pan' => $pan,
                'profit_share_pct' => $defaultProfitShare,
                'capital_account_id' => $capitalAccountId,
                'current_account_id' => $currentAccountId,
                'joined_date' => $joinedDate,
            ]);

            $created = $this->db->fetch("SELECT * FROM partners WHERE id = ? AND business_id = ?", [$partnerId, $this->businessId]);
            if (class_exists('Auth') && Auth::isLoggedIn()) {
                Auth::auditCreate('partner', $partnerId, $created ?: ['name' => $name], "Partner $name added", $module);
            }
            if ($ownsTransaction) $this->db->commit();
            return $partnerId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function setOpeningBalance($accountId, $amount, $balanceType, $date, $reason = '') {
        $amount = round(floatval($amount), 2);
        $balanceType = strtoupper((string) $balanceType) === 'CR' ? 'CR' : 'DR';
        $date = trim((string) $date);
        if ($amount < 0) {
            throw new Exception('Opening balance cannot be negative. Select DR or CR for the balance direction.');
        }
        $dateParts = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)
            ? [intval($matches[1]), intval($matches[2]), intval($matches[3])]
            : null;
        if (!$dateParts || !checkdate($dateParts[1], $dateParts[2], $dateParts[0])) {
            throw new Exception('A valid opening balance date is required.');
        }
        $this->validateDateNotLocked($date);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $account = $this->db->fetch(
                "SELECT * FROM accounts WHERE id = ? AND business_id = ? FOR UPDATE",
                [$accountId, $this->businessId]
            );
            if (!$account || $account['code'] === 'OB-EQUITY') {
                throw new Exception('Opening balance account not found.');
            }

        $oldSnapshot = [
            'opening_balance' => $account['opening_balance'] ?? 0,
            'opening_balance_type' => $account['opening_balance_type'] ?? 'DR',
            'opening_balance_date' => $account['opening_balance_date'] ?? null,
            'opening_entry_id' => $account['opening_entry_id'] ?? null,
        ];

        $oldEntryId = $account['opening_entry_id'] ?? null;
        if ($oldEntryId) {
            $oldEntry = $this->db->fetch(
                "SELECT id, entry_date, status FROM journal_entries WHERE id = ? AND business_id = ? AND transaction_type = 'OPENING_BALANCE'",
                [$oldEntryId, $this->businessId]
            );
            if ($oldEntry && $oldEntry['status'] === 'POSTED') {
                $this->reverseEntry($oldEntryId, 'Opening balance replaced: ' . ($reason ?: $account['name']), $oldEntry['entry_date']);
            }
        } elseif (floatval($account['opening_balance'] ?? 0) > 0.009) {
            // Legacy account openings were stored directly in current_balance. Remove
            // that amount before replacing it with a balanced journal entry.
            $legacyType = strtoupper((string) ($account['opening_balance_type'] ?? 'DR')) === 'CR' ? 'CR' : 'DR';
            $this->updateAccountBalance($accountId, floatval($account['opening_balance']), $legacyType === 'DR' ? 'CR' : 'DR');
        }

        $this->db->query(
            "UPDATE accounts
             SET opening_balance = 0, opening_balance_type = 'DR', opening_balance_date = NULL, opening_entry_id = NULL
             WHERE id = ? AND business_id = ?",
            [$accountId, $this->businessId]
        );

        $openingEntryId = null;
        if ($amount > 0.009) {
            $equityAccount = $this->getOrCreateSystemAccount(
                'OB-EQUITY',
                'Opening Balance Equity',
                'EQUITY',
                'Opening Balances'
            );
            $lines = [
                [
                    'account_id' => $accountId,
                    'amount' => $amount,
                    'type' => $balanceType,
                    'narration' => 'Opening balance for ' . $account['name'],
                ],
                [
                    'account_id' => $equityAccount['id'],
                    'amount' => $amount,
                    'type' => $balanceType === 'DR' ? 'CR' : 'DR',
                    'narration' => 'Opening balance offset for ' . $account['name'],
                ],
            ];
            $openingEntryId = $this->postJournalEntry(
                'OPENING_BALANCE',
                $date,
                trim('Opening balance - ' . $account['name'] . ($reason ? ': ' . $reason : '')),
                $lines,
                ['reference_prefix' => 'OB']
            );
        }

        $this->db->query(
            "UPDATE accounts
             SET opening_balance = ?, opening_balance_type = ?, opening_balance_date = ?, opening_entry_id = ?
             WHERE id = ? AND business_id = ?",
            [$amount, $balanceType, $amount > 0.009 ? $date : null, $openingEntryId, $accountId, $this->businessId]
        );

        $newSnapshot = [
            'opening_balance' => $amount,
            'opening_balance_type' => $balanceType,
            'opening_balance_date' => $amount > 0.009 ? $date : null,
            'opening_entry_id' => $openingEntryId,
        ];
            Auth::auditUpdate('account', $accountId, $oldSnapshot, $newSnapshot, 'Opening balance updated for ' . $account['name'], 'opening_balances');
            if ($ownsTransaction) $this->db->commit();
            return $openingEntryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function getOrCreateExpenseAccount($categoryName, $type = 'GENERAL') {
        $code = 'EXP-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $categoryName), 0, 10));
        $existing = $this->db->fetch(
            "SELECT id FROM accounts WHERE business_id = ? AND code = ?",
            [$this->businessId, $code]
        );
        if ($existing) return $existing['id'];

        $subGroup = $type === 'CAR_SPECIFIC' ? 'Direct Expenses (Car)' : 'Indirect Expenses';
        return $this->createAccount($code, $categoryName, 'EXPENSE', $subGroup, 'GENERAL');
    }

    private function ensureAdvancedSchema() {
        if (self::$advancedSchemaEnsured) {
            return;
        }

        try {
            $this->ensureJournalEntryTypeEnum();
            $this->ensureCarStatusEnum();
            $this->ensureCarOperationsSchema();

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `journal_vouchers` (
                    `id` CHAR(36) NOT NULL,
                    `business_id` CHAR(36) NOT NULL,
                    `voucher_date` DATE NOT NULL,
                    `reference_no` VARCHAR(50) NOT NULL,
                    `voucher_type` VARCHAR(50) NOT NULL DEFAULT 'GENERAL_JV',
                    `narration` TEXT DEFAULT NULL,
                    `status` ENUM('DRAFT','POSTED','REVERSED') NOT NULL DEFAULT 'DRAFT',
                    `primary_account_id` CHAR(36) NOT NULL,
                    `primary_entry_type` ENUM('DR','CR') NOT NULL,
                    `primary_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `posted_entry_id` CHAR(36) DEFAULT NULL,
                    `created_by` CHAR(36) NOT NULL,
                    `financial_year` INT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_jv_reference` (`business_id`, `reference_no`),
                    KEY `idx_jv_business_status` (`business_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `journal_voucher_lines` (
                    `id` CHAR(36) NOT NULL,
                    `journal_voucher_id` CHAR(36) NOT NULL,
                    `account_id` CHAR(36) NOT NULL,
                    `amount` DECIMAL(15,2) NOT NULL,
                    `entry_type` ENUM('DR','CR') NOT NULL,
                    `narration` VARCHAR(500) DEFAULT NULL,
                    `entity_type` VARCHAR(30) DEFAULT NULL,
                    `entity_id` CHAR(36) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_jvl_voucher` (`journal_voucher_id`),
                    KEY `idx_jvl_account` (`account_id`),
                    KEY `idx_jvl_entity` (`entity_type`, `entity_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `car_partnerships` (
                    `id` CHAR(36) NOT NULL,
                    `business_id` CHAR(36) NOT NULL,
                    `car_id` CHAR(36) NOT NULL,
                    `partner_id` CHAR(36) NOT NULL,
                    `funding_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `funding_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
                    `profit_share_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
                    `status` VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
                    `notes` VARCHAR(500) DEFAULT NULL,
                    `created_by` CHAR(36) DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_car_partner` (`car_id`, `partner_id`),
                    KEY `idx_cp_business` (`business_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `partner_profit_settlements` (
                    `id` CHAR(36) NOT NULL,
                    `business_id` CHAR(36) NOT NULL,
                    `car_id` CHAR(36) NOT NULL,
                    `partner_id` CHAR(36) NOT NULL,
                    `journal_entry_id` CHAR(36) DEFAULT NULL,
                    `last_settlement_entry_id` CHAR(36) DEFAULT NULL,
                    `settlement_date` DATE NOT NULL,
                    `profit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `profit_share_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
                    `direction` ENUM('PAYABLE','RECEIVABLE') NOT NULL,
                    `status` ENUM('PENDING','PARTIAL','SETTLED','REVERSED') NOT NULL DEFAULT 'PENDING',
                    `settled_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `outstanding_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `narration` VARCHAR(500) DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_pps_business` (`business_id`),
                    KEY `idx_pps_partner_status` (`partner_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `partner_settlement_applications` (
                    `id` CHAR(36) NOT NULL,
                    `business_id` CHAR(36) NOT NULL,
                    `partner_profit_settlement_id` CHAR(36) NOT NULL,
                    `journal_entry_id` CHAR(36) NOT NULL,
                    `applied_date` DATE NOT NULL,
                    `applied_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `direction` ENUM('PAYABLE','RECEIVABLE') NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_psa_business` (`business_id`),
                    KEY `idx_psa_entry` (`journal_entry_id`),
                    KEY `idx_psa_settlement` (`partner_profit_settlement_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `car_tokens` (
                    `id` CHAR(36) NOT NULL,
                    `business_id` CHAR(36) NOT NULL,
                    `car_id` CHAR(36) NOT NULL,
                    `party_id` CHAR(36) NOT NULL,
                    `journal_entry_id` CHAR(36) NOT NULL,
                    `applied_sale_entry_id` CHAR(36) DEFAULT NULL,
                    `received_date` DATE NOT NULL,
                    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `applied_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `status` ENUM('OPEN','PARTIAL','APPLIED','REVERSED') NOT NULL DEFAULT 'OPEN',
                    `narration` VARCHAR(500) DEFAULT NULL,
                    `created_by` CHAR(36) NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_car_token_entry` (`journal_entry_id`),
                    KEY `idx_car_tokens_car_status` (`business_id`, `car_id`, `status`),
                    KEY `idx_car_tokens_party` (`business_id`, `party_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `commission_car_settlements` (
                    `id` CHAR(36) NOT NULL,
                    `business_id` CHAR(36) NOT NULL,
                    `car_id` CHAR(36) NOT NULL,
                    `owner_party_id` CHAR(36) NOT NULL,
                    `buyer_party_id` CHAR(36) NOT NULL,
                    `sale_entry_id` CHAR(36) NOT NULL,
                    `sale_date` DATE NOT NULL,
                    `gross_sale_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `owner_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `buyer_outstanding_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `payment_handling` ENUM('COMMISSION_ONLY','FULL_AMOUNT') NOT NULL DEFAULT 'COMMISSION_ONLY',
                    `paid_to_owner_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `status` ENUM('NOT_APPLICABLE','PENDING','PARTIAL','PAID','REVERSED') NOT NULL DEFAULT 'NOT_APPLICABLE',
                    `created_by` CHAR(36) NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_commission_car_sale` (`sale_entry_id`),
                    KEY `idx_commission_car` (`business_id`, `car_id`, `status`),
                    KEY `idx_commission_owner` (`business_id`, `owner_party_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `commission_owner_payments` (
                    `id` CHAR(36) NOT NULL,
                    `business_id` CHAR(36) NOT NULL,
                    `settlement_id` CHAR(36) NOT NULL,
                    `journal_entry_id` CHAR(36) NOT NULL,
                    `payment_date` DATE NOT NULL,
                    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    `created_by` CHAR(36) NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_commission_owner_payment_entry` (`journal_entry_id`),
                    KEY `idx_commission_owner_payment` (`business_id`, `settlement_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            if (!$this->columnExists('journal_entries', 'journal_voucher_id')) {
                $this->db->query("ALTER TABLE `journal_entries` ADD COLUMN `journal_voucher_id` CHAR(36) DEFAULT NULL AFTER `party_id`");
            }
            if (!$this->columnExists('journal_voucher_lines', 'entity_type')) {
                $this->db->query("ALTER TABLE `journal_voucher_lines` ADD COLUMN `entity_type` VARCHAR(30) DEFAULT NULL AFTER `narration`");
            }
            if (!$this->columnExists('journal_voucher_lines', 'entity_id')) {
                $this->db->query("ALTER TABLE `journal_voucher_lines` ADD COLUMN `entity_id` CHAR(36) DEFAULT NULL AFTER `entity_type`");
            }
            if (!$this->columnExists('journal_lines', 'source_voucher_line_id')) {
                $this->db->query("ALTER TABLE `journal_lines` ADD COLUMN `source_voucher_line_id` CHAR(36) DEFAULT NULL AFTER `narration`");
            }
            $this->addIndexIfMissing('journal_voucher_lines', 'idx_jvl_entity', '`entity_type`, `entity_id`');
            $this->addIndexIfMissing('journal_lines', 'idx_jl_source_voucher_line', '`source_voucher_line_id`');
            $this->db->query(
                "UPDATE journal_voucher_lines jvl
                 JOIN accounts a ON a.id = jvl.account_id
                 SET jvl.entity_type = a.entity_type,
                     jvl.entity_id = a.entity_id
                 WHERE jvl.entity_type IS NULL
                    OR jvl.entity_type = ''
                    OR jvl.entity_id IS NULL"
            );
            $this->db->query(
                "UPDATE journal_entries je
                 JOIN (
                     SELECT jl.journal_entry_id, MIN(c.id) AS car_id
                     FROM journal_lines jl
                     JOIN cars c ON c.account_id = jl.account_id
                     GROUP BY jl.journal_entry_id
                     HAVING COUNT(DISTINCT c.id) = 1
                 ) linked_car ON linked_car.journal_entry_id = je.id
                 SET je.car_id = linked_car.car_id
                 WHERE je.car_id IS NULL"
            );
            if (!$this->columnExists('journal_entries', 'corrected_from_id')) {
                $this->db->query("ALTER TABLE `journal_entries` ADD COLUMN `corrected_from_id` CHAR(36) DEFAULT NULL AFTER `journal_voucher_id`");
            }
            if (!$this->columnExists('journal_entries', 'corrected_by_id')) {
                $this->db->query("ALTER TABLE `journal_entries` ADD COLUMN `corrected_by_id` CHAR(36) DEFAULT NULL AFTER `corrected_from_id`");
            }
            if (!$this->columnExists('journal_entries', 'correction_reason')) {
                $this->db->query("ALTER TABLE `journal_entries` ADD COLUMN `correction_reason` VARCHAR(500) DEFAULT NULL AFTER `corrected_by_id`");
            }
            if (!$this->columnExists('journal_entries', 'version_no')) {
                $this->db->query("ALTER TABLE `journal_entries` ADD COLUMN `version_no` INT NOT NULL DEFAULT 1 AFTER `correction_reason`");
            }
            $this->addIndexIfMissing('journal_entries', 'idx_correction_from', '`corrected_from_id`');

            // Entry-type backfill reads these car fields. Older databases must
            // receive them before ensureEntryTypeIdentitySchema() runs.
            if (!$this->columnExists('cars', 'sale_commission_amount')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `sale_commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `sale_price`");
            }
            if (!$this->columnExists('cars', 'purchase_paid_amount')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `purchase_paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `purchase_price`");
            }
            if (!$this->columnExists('cars', 'ownership_type')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `ownership_type` ENUM('OWNED','COMMISSION') NOT NULL DEFAULT 'OWNED' AFTER `purchase_paid_amount`");
            }
            $this->ensureEntryTypeIdentitySchema();
            if (!$this->columnExists('cars', 'sale_gst_amount')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `sale_gst_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `sale_price`");
            }
            if (!$this->columnExists('cars', 'buyer_party_id')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `buyer_party_id` CHAR(36) DEFAULT NULL AFTER `buyer_contact`");
            }
            if (!$this->columnExists('cars', 'seller_party_id')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `seller_party_id` CHAR(36) DEFAULT NULL AFTER `buyer_party_id`");
            }
            if (!$this->columnExists('cars', 'commission_owner_party_id')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `commission_owner_party_id` CHAR(36) DEFAULT NULL AFTER `ownership_type`");
            }
            if (!$this->columnExists('cars', 'expected_sale_price')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `expected_sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `commission_owner_party_id`");
            }
            if (!$this->columnExists('cars', 'expected_commission_amount')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `expected_commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `expected_sale_price`");
            }
            if (!$this->columnExists('cars', 'has_second_key')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `has_second_key` TINYINT(1) NOT NULL DEFAULT 0 AFTER `seller_party_id`");
            }
            if (!$this->columnExists('cars', 'partner_id')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `partner_id` CHAR(36) DEFAULT NULL AFTER `seller_party_id`");
            }
            $partnerIndex = $this->db->fetch(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND INDEX_NAME = 'idx_car_partner'"
            );
            if (!$partnerIndex) {
                $this->db->query("ALTER TABLE `cars` ADD INDEX `idx_car_partner` (`partner_id`)");
            }
            if (!$this->columnExists('accounts', 'opening_balance_date')) {
                $this->db->query("ALTER TABLE `accounts` ADD COLUMN `opening_balance_date` DATE DEFAULT NULL AFTER `opening_balance_type`");
            }
            if (!$this->columnExists('accounts', 'opening_entry_id')) {
                $this->db->query("ALTER TABLE `accounts` ADD COLUMN `opening_entry_id` CHAR(36) DEFAULT NULL AFTER `opening_balance_date`");
            }
            foreach ([
                'email' => "VARCHAR(100) DEFAULT NULL AFTER `phone`",
                'exit_date' => "DATE DEFAULT NULL AFTER `join_date`",
                'address' => "TEXT DEFAULT NULL AFTER `exit_date`",
                'emergency_contact_name' => "VARCHAR(200) DEFAULT NULL AFTER `address`",
                'emergency_contact_phone' => "VARCHAR(20) DEFAULT NULL AFTER `emergency_contact_name`",
                'notes' => "TEXT DEFAULT NULL AFTER `emergency_contact_phone`",
                'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
            ] as $employeeColumn => $definition) {
                if (!$this->columnExists('employees', $employeeColumn)) {
                    $this->db->query("ALTER TABLE `employees` ADD COLUMN `$employeeColumn` $definition");
                }
            }
            if (!$this->columnExists('partners', 'partner_type')) {
                $this->db->query("ALTER TABLE `partners` ADD COLUMN `partner_type` ENUM('MAIN','CARWISE') NOT NULL DEFAULT 'MAIN' AFTER `name`");
            }
            if (!$this->columnExists('car_partner_contributions', 'funding_pct')) {
                $this->db->query("ALTER TABLE `car_partner_contributions` ADD COLUMN `funding_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER `amount`");
            }
            if (!$this->columnExists('car_partner_contributions', 'profit_share_pct')) {
                $this->db->query("ALTER TABLE `car_partner_contributions` ADD COLUMN `profit_share_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER `funding_pct`");
            }

            $this->addIndexIfMissing('accounts', 'idx_accounts_business_search', '`business_id`, `entity_type`, `is_active`, `code`, `name`');
            $this->addIndexIfMissing('cars', 'idx_cars_business_search', '`business_id`, `status`, `registration_no`, `make`, `model`');
            $this->addIndexIfMissing('employees', 'idx_employees_business_search', '`business_id`, `is_active`, `name`, `role`, `phone`');
            $this->addIndexIfMissing('partners', 'idx_partners_business_search', '`business_id`, `is_active`, `name`, `phone`');
            $this->addIndexIfMissing('debtors_creditors', 'idx_parties_business_search', '`business_id`, `is_active`, `type`, `name`, `phone`');

            self::$advancedSchemaEnsured = true;
        } catch (\Throwable $e) {
            // Keep existing flows working even if migration permissions are limited.
            self::$advancedSchemaEnsured = true;
        }
    }

    private function ensureJournalEntryTypeEnum() {
        $column = $this->db->fetch(
            "SELECT COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'journal_entries'
               AND COLUMN_NAME = 'transaction_type'"
        );
        $required = ['CAR_TOKEN_RECEIVED', 'JOURNAL_VOUCHER', 'PARTNER_SETTLEMENT', 'EMPLOYEE_ADVANCE_WRITEOFF', 'GST_UTILIZATION', 'RTO_EXPENSE', 'RTO_RECOVERY'];
        $currentType = $column['COLUMN_TYPE'] ?? '';
        $needsUpdate = false;
        foreach ($required as $value) {
            if (strpos($currentType, "'" . $value . "'") === false) {
                $needsUpdate = true;
                break;
            }
        }

        if ($needsUpdate) {
            $this->db->query(
                "ALTER TABLE `journal_entries`
                 MODIFY COLUMN `transaction_type`
                 ENUM('CAR_PURCHASE','CAR_TOKEN_RECEIVED','CAR_SALE','RTO_EXPENSE','RTO_RECOVERY','CAR_EXPENSE','GENERAL_EXPENSE','JOURNAL_VOUCHER','PARTNER_INVEST','PARTNER_WITHDRAW','PARTNER_SETTLEMENT','SALARY_PAYMENT','EMPLOYEE_ADVANCE','EMPLOYEE_ADVANCE_WRITEOFF','LOAN_GIVEN','LOAN_RECEIVED','LOAN_TAKEN','LOAN_REPAID','CONTRA_TRANSFER','GST_PAYMENT','GST_UTILIZATION','OPENING_BALANCE','REVERSAL','BAD_DEBT','PROFIT_DISTRIBUTION')
                 NOT NULL"
            );
        }
    }

    private function ensureEntryTypeIdentitySchema() {
        if (!$this->columnExists('journal_entries', 'entry_type_id')) {
            $this->db->query("ALTER TABLE `journal_entries` ADD COLUMN `entry_type_id` VARCHAR(80) DEFAULT NULL AFTER `transaction_type`");
        }
        if (!$this->columnExists('journal_entries', 'entry_amount')) {
            $this->db->query("ALTER TABLE `journal_entries` ADD COLUMN `entry_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `entry_type_id`");
        }
        $this->addIndexIfMissing('journal_entries', 'idx_entry_type_id', '`business_id`, `entry_type_id`, `entry_date`, `status`');

        $unmapped = $this->db->fetch("SELECT id FROM journal_entries WHERE entry_type_id IS NULL OR entry_type_id = '' LIMIT 1");
        if (!$unmapped) return;

        $rows = $this->db->fetchAll(
            "SELECT je.id, je.transaction_type, je.narration, je.journal_voucher_id, je.car_id,
                    c.ownership_type AS car_ownership_type, c.sale_price, c.sale_commission_amount,
                    ccs.commission_amount AS settlement_commission_amount,
                    cop.journal_entry_id AS commission_owner_payment_entry_id,
                    COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS total_dr,
                    COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' AND a.code IN ('CAR-REV','SALE-COMM','GST-PAY') THEN jl.amount ELSE 0 END), 0) AS sale_income_amount,
                    COUNT(DISTINCT CASE
                        WHEN a.entity_type = 'GENERAL'
                         AND a.group_name IN ('INCOME','EXPENSE')
                         AND a.sub_group IN ('Daily Jama Categories','Daily Udhar Categories')
                         AND a.code NOT IN ('CAR-REV','SALE-COMM','PNL','GST-PAY','GST-RCV','BAD-DEBT','ADV-WOFF','SAL-EXP','OB-EQUITY')
                        THEN a.id END) AS custom_type_count,
                    MAX(CASE
                        WHEN a.entity_type = 'GENERAL'
                         AND a.group_name IN ('INCOME','EXPENSE')
                         AND a.sub_group IN ('Daily Jama Categories','Daily Udhar Categories')
                         AND a.code NOT IN ('CAR-REV','SALE-COMM','PNL','GST-PAY','GST-RCV','BAD-DEBT','ADV-WOFF','SAL-EXP','OB-EQUITY')
                        THEN a.id END) AS custom_account_id,
                    COALESCE(SUM(CASE
                        WHEN a.entity_type = 'GENERAL'
                         AND a.group_name = 'INCOME'
                         AND jl.entry_type = 'CR'
                         AND a.sub_group IN ('Daily Jama Categories','Daily Udhar Categories')
                         AND a.code NOT IN ('CAR-REV','SALE-COMM','PNL','GST-PAY','GST-RCV','BAD-DEBT','ADV-WOFF','SAL-EXP','OB-EQUITY')
                        THEN jl.amount
                        WHEN a.entity_type = 'GENERAL'
                         AND a.group_name = 'EXPENSE'
                         AND jl.entry_type = 'DR'
                         AND a.sub_group IN ('Daily Jama Categories','Daily Udhar Categories')
                         AND a.code NOT IN ('CAR-REV','SALE-COMM','PNL','GST-PAY','GST-RCV','BAD-DEBT','ADV-WOFF','SAL-EXP','OB-EQUITY')
                        THEN jl.amount ELSE 0 END), 0) AS custom_amount
             FROM journal_entries je
             LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id
             LEFT JOIN accounts a ON a.id = jl.account_id
             LEFT JOIN cars c ON c.id = je.car_id AND c.business_id = je.business_id
             LEFT JOIN commission_car_settlements ccs ON ccs.sale_entry_id = je.id AND ccs.business_id = je.business_id
             LEFT JOIN commission_owner_payments cop ON cop.journal_entry_id = je.id AND cop.business_id = je.business_id
             WHERE je.entry_type_id IS NULL OR je.entry_type_id = ''
             GROUP BY je.id, je.transaction_type, je.narration, je.journal_voucher_id, je.car_id,
                      c.ownership_type, c.sale_price, c.sale_commission_amount, ccs.commission_amount,
                      cop.journal_entry_id"
        );

        foreach ($rows as $row) {
            $transactionType = strtoupper((string) $row['transaction_type']);
            $narration = trim((string) ($row['narration'] ?? ''));
            $entryTypeId = systemEntryTypeId($transactionType);
            $entryAmount = round(floatval($row['total_dr'] ?? 0), 2);

            if (
                ($transactionType === 'CAR_SALE' && str_starts_with($narration, 'Close car account '))
                || (in_array($transactionType, ['CAR_EXPENSE', 'RTO_EXPENSE'], true) && str_starts_with($narration, 'Allocate '))
            ) {
                $entryTypeId = systemEntryTypeId('INTERNAL_ALLOCATION');
                $entryAmount = 0;
            } elseif ($transactionType === 'CAR_SALE' && ($row['car_ownership_type'] ?? '') === 'COMMISSION') {
                $entryTypeId = systemEntryTypeId('COMMISSION_CAR_SALE');
                $entryAmount = round(floatval($row['settlement_commission_amount'] ?? $row['sale_commission_amount'] ?? 0), 2);
            } elseif ($transactionType === 'CAR_SALE') {
                $entryAmount = round(floatval($row['sale_price'] ?: $row['sale_income_amount'] ?: $row['total_dr']), 2);
            } elseif ($transactionType === 'LOAN_RECEIVED' && !empty($row['car_id'])) {
                $entryTypeId = systemEntryTypeId('CAR_PAYMENT_CLEARING');
            } elseif ($transactionType === 'LOAN_REPAID' && !empty($row['commission_owner_payment_entry_id'])) {
                $entryTypeId = systemEntryTypeId('COMMISSION_OWNER_PAYMENT');
            } elseif ($transactionType === 'LOAN_REPAID' && !empty($row['car_id'])) {
                $entryTypeId = systemEntryTypeId('SELLER_PAYMENT_CLEARING');
            } elseif (
                in_array($transactionType, ['GENERAL_EXPENSE', 'JOURNAL_VOUCHER'], true)
                && empty($row['journal_voucher_id'])
                && intval($row['custom_type_count'] ?? 0) === 1
                && !empty($row['custom_account_id'])
            ) {
                $entryTypeId = customEntryTypeId($row['custom_account_id']);
                $entryAmount = round(floatval($row['custom_amount'] ?? 0), 2);
            }

            $this->db->query(
                "UPDATE journal_entries SET entry_type_id = ?, entry_amount = ? WHERE id = ?",
                [$entryTypeId, $entryAmount, $row['id']]
            );
        }
    }

    private function ensureCarOperationsSchema() {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `rto_records` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `rto_type` VARCHAR(120) NOT NULL,
                `status` ENUM('PENDING','IN_PROGRESS','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING',
                `party_name` VARCHAR(200) DEFAULT NULL,
                `rto_office` VARCHAR(160) DEFAULT NULL,
                `agent_name` VARCHAR(160) DEFAULT NULL,
                `application_no` VARCHAR(120) DEFAULT NULL,
                `receipt_no` VARCHAR(120) DEFAULT NULL,
                `expense_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `recovered_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `is_recoverable` TINYINT(1) NOT NULL DEFAULT 1,
                `due_date` DATE DEFAULT NULL,
                `submitted_date` DATE DEFAULT NULL,
                `completed_date` DATE DEFAULT NULL,
                `narration` TEXT DEFAULT NULL,
                `expense_entry_id` CHAR(36) DEFAULT NULL,
                `last_recovery_entry_id` CHAR(36) DEFAULT NULL,
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_rto_business_status` (`business_id`, `status`),
                KEY `idx_rto_car` (`car_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `rto_recoveries` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `rto_record_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `journal_entry_id` CHAR(36) NOT NULL,
                `received_date` DATE NOT NULL,
                `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `narration` VARCHAR(500) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_rto_recovery_record` (`rto_record_id`),
                KEY `idx_rto_recovery_car` (`car_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `car_second_key_events` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `event_type` ENUM('RECEIVED','GIVEN') NOT NULL,
                `event_date` DATE NOT NULL,
                `narration` VARCHAR(500) DEFAULT NULL,
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_second_key_car` (`car_id`, `event_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function ensureCarStatusEnum() {
        $column = $this->db->fetch(
            "SELECT COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cars'
               AND COLUMN_NAME = 'status'"
        );

        $currentType = $column['COLUMN_TYPE'] ?? '';
        if (strpos($currentType, "'CANCELLED'") === false) {
            $this->db->query(
                "ALTER TABLE `cars`
                 MODIFY COLUMN `status`
                 ENUM('IN_STOCK','SOLD','PENDING_PAYMENT','CANCELLED')
                 NOT NULL DEFAULT 'IN_STOCK'"
            );
        }
    }

    private function columnExists($table, $column) {
        $row = $this->db->fetch(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?",
            [$table, $column]
        );
        return !empty($row);
    }

    private function addIndexIfMissing($table, $indexName, $columnsSql) {
        try {
            $row = $this->db->fetch(
                "SELECT INDEX_NAME
                 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND INDEX_NAME = ?
                 LIMIT 1",
                [$table, $indexName]
            );
            if (!$row) {
                $this->db->query("ALTER TABLE `$table` ADD INDEX `$indexName` ($columnsSql)");
            }
        } catch (\Throwable $e) {
            error_log("AutoBooks index setup skipped for $table.$indexName: " . $e->getMessage());
        }
    }

    private function inferEntryEntitiesFromLines($lines) {
        $accountIds = array_values(array_unique(array_filter(array_column((array) $lines, 'account_id'))));
        if (empty($accountIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT a.id, MAX(a.entity_type) AS entity_type, MAX(a.entity_id) AS entity_id, MAX(c.id) AS linked_car_id
             FROM accounts a
             LEFT JOIN cars c
               ON c.business_id = a.business_id
              AND (c.id = a.entity_id OR c.account_id = a.id)
             WHERE a.business_id = ?
               AND a.id IN ($placeholders)
             GROUP BY a.id",
            array_merge([$this->businessId], $accountIds)
        );
        if (count($rows) !== count($accountIds)) {
            throw new Exception('Every journal line account must belong to the active business.');
        }

        $entitySets = [
            'car_id' => [],
            'partner_id' => [],
            'employee_id' => [],
            'party_id' => [],
        ];
        foreach ($rows as $row) {
            $entityType = strtoupper(trim((string) ($row['entity_type'] ?? '')));
            $entityId = $row['entity_id'] ?: null;
            if (!empty($row['linked_car_id'])) {
                $entityType = 'CAR';
                $entityId = $row['linked_car_id'];
            }
            if (!$entityId) continue;

            if ($entityType === 'CAR') {
                $entitySets['car_id'][$entityId] = true;
            } elseif ($entityType === 'PARTNER') {
                $entitySets['partner_id'][$entityId] = true;
            } elseif ($entityType === 'EMPLOYEE') {
                $entitySets['employee_id'][$entityId] = true;
            } elseif (in_array($entityType, ['DEBTOR', 'CREDITOR', 'BUYER', 'SELLER'], true)) {
                $entitySets['party_id'][$entityId] = true;
            }
        }

        $inferred = [];
        foreach ($entitySets as $field => $ids) {
            if (count($ids) === 1) {
                $inferred[$field] = array_key_first($ids);
            }
        }
        return $inferred;
    }

    // ========================================
    // CORE: Post a journal entry with balanced Dr/Cr lines
    // ========================================
    public function postJournalEntry($type, $date, $narration, $lines, $extras = []) {
        $this->validateDateNotLocked($date);

        // Validate balance: Dr must equal Cr
        $totalDr = 0;
        $totalCr = 0;
        $normalizedLines = [];
        foreach ($lines as $line) {
            $amount = round(floatval($line['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new Exception("Journal entry lines must be greater than zero.");
            }
            $accountId = trim((string) ($line['account_id'] ?? ''));
            if ($accountId === '') {
                throw new Exception("Journal entry line account is missing.");
            }
            $lineType = strtoupper(trim((string) ($line['type'] ?? '')));
            if (!in_array($lineType, ['DR', 'CR'], true)) {
                throw new Exception('Journal entry line type must be DR or CR.');
            }
            $account = $this->db->fetch(
                "SELECT id FROM accounts WHERE id = ? AND business_id = ? AND is_active = 1",
                [$accountId, $this->businessId]
            );
            if (!$account) {
                throw new Exception('One journal account is inactive or does not belong to this business.');
            }

            $line['account_id'] = $accountId;
            $line['amount'] = $amount;
            $line['type'] = $lineType;
            $normalizedLines[] = $line;
            if ($lineType === 'DR') $totalDr += $amount;
            else $totalCr += $amount;
        }
        $lines = $normalizedLines;

        if (empty($lines)) {
            throw new Exception('Journal entry requires at least two balanced lines.');
        }

        if (abs($totalDr - $totalCr) > 0.01) {
            throw new Exception("Journal entry is not balanced! Dr: $totalDr, Cr: $totalCr");
        }

        $inferredEntities = $this->inferEntryEntitiesFromLines($lines);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $entryId = Database::uuid();
            $refNo = getNextRefNo($this->db, $this->businessId, $date, $extras['reference_prefix'] ?? 'JE');
            $fy = getCurrentFY($date);

            $entryData = [
                'id' => $entryId,
                'business_id' => $this->businessId,
                'entry_date' => $date,
                'reference_no' => $refNo,
                'narration' => $narration,
                'transaction_type' => $type,
                'entry_type_id' => $extras['entry_type_id'] ?? systemEntryTypeId($type),
                'entry_amount' => array_key_exists('entry_amount', $extras)
                    ? round(floatval($extras['entry_amount']), 2)
                    : round(floatval($totalDr), 2),
                'status' => 'POSTED',
                'created_by' => $this->userId,
                'financial_year' => $fy,
                'car_id' => $extras['car_id'] ?? ($inferredEntities['car_id'] ?? null),
                'partner_id' => $extras['partner_id'] ?? ($inferredEntities['partner_id'] ?? null),
                'employee_id' => $extras['employee_id'] ?? ($inferredEntities['employee_id'] ?? null),
                'party_id' => $extras['party_id'] ?? ($inferredEntities['party_id'] ?? null),
                'journal_voucher_id' => $extras['journal_voucher_id'] ?? null,
            ];

            $this->db->insert('journal_entries', $entryData);

            foreach ($lines as $line) {
                $lineId = Database::uuid();
                $this->db->insert('journal_lines', [
                    'id' => $lineId,
                    'journal_entry_id' => $entryId,
                    'account_id' => $line['account_id'],
                    'amount' => $line['amount'],
                    'entry_type' => $line['type'],
                    'narration' => $line['narration'] ?? null,
                    'source_voucher_line_id' => $line['source_voucher_line_id'] ?? null,
                ]);
                $this->updateAccountBalance($line['account_id'], $line['amount'], $line['type']);
            }

            Auth::auditCreate('journal_entry', $entryId, array_merge($entryData, ['lines' => $lines]), "Posted $type entry: $narration", 'transactions');

            // Check alerts after posting
            $this->checkAlerts();

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ========================================
    // UPDATE: Account running balance
    // ========================================
    private function updateAccountBalance($accountId, $amount, $entryType) {
        $account = $this->db->fetch(
            "SELECT * FROM accounts WHERE id = ? AND business_id = ?",
            [$accountId, $this->businessId]
        );
        if (!$account) throw new Exception("Account not found: $accountId");

        $balance = abs(floatval($account['current_balance']));
        $balType = strtoupper((string) $account['current_balance_type']);
        $group = $account['group_name'];

        // Natural balance types: ASSET/EXPENSE = DR, LIABILITY/INCOME/EQUITY = CR
        $naturalDr = in_array($group, ['ASSET', 'EXPENSE', 'CONTRA']);

        $naturalType = $naturalDr ? 'DR' : 'CR';
        $signedBalance = $balType === $naturalType ? $balance : -$balance;
        if ($naturalDr) {
            $signedBalance += $entryType === 'DR' ? $amount : -$amount;
        } else {
            $signedBalance += $entryType === 'CR' ? $amount : -$amount;
        }

        if ($signedBalance >= 0) {
            $balance = $signedBalance;
            $balType = $naturalType;
        } else {
            $balance = abs($signedBalance);
            $balType = $naturalDr ? 'CR' : 'DR';
        }

        $this->db->query(
            "UPDATE accounts SET current_balance = ?, current_balance_type = ? WHERE id = ? AND business_id = ?",
            [$balance, $balType, $accountId, $this->businessId]
        );
    }

    // ========================================
    // TRANSACTION HANDLERS — The 14 types
    // ========================================
    
    /**
     * CAR PURCHASE — Business funds
     */
    public function validateCarPurchaseInput($amount, $date, $paymentAccount, $partnerFunding = [], $gstAmount = 0, $sellerName = null, $paidNow = null) {
        $this->validateDateNotLocked($date);
        [$grossAmount, $gstAmount, $baseAmount] = $this->normalizeGstComponent($amount, $gstAmount);
        $partnerFunding = $this->normalizePartnerFunding($grossAmount, $partnerFunding);

        $businessAmount = $grossAmount;
        foreach ($partnerFunding as $funding) {
            $businessAmount -= $funding['amount'];
        }
        if ($businessAmount < -0.01) {
            throw new Exception("Partner funding cannot exceed the car purchase amount.");
        }

        $paidNow = $paidNow === null ? $businessAmount : round(floatval($paidNow), 2);
        if ($paidNow < 0) throw new Exception("Paid now cannot be negative.");
        if ($paidNow - $businessAmount > 0.01) throw new Exception("Paid now cannot exceed business-funded purchase amount.");
        $sellerOutstanding = round($businessAmount - $paidNow, 2);
        if ($sellerOutstanding > 0.009 && trim((string) $sellerName) === '') {
            throw new Exception("Seller name is required when purchase payment is pending.");
        }
        if ($paidNow > 0) {
            $this->validateCashAvailable($paymentAccount, $paidNow);
        }

        return [
            'gross_amount' => $grossAmount,
            'gst_amount' => $gstAmount,
            'base_amount' => $baseAmount,
            'partner_funding' => $partnerFunding,
            'business_amount' => round($businessAmount, 2),
            'paid_now' => $paidNow,
            'seller_outstanding' => $sellerOutstanding,
        ];
    }

    public function carPurchase($carId, $amount, $date, $paymentAccount, $narration, $partnerFunding = [], $gstAmount = 0, $sellerName = null, $paidNow = null) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception("Car not found");

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $carAccountId = $car['account_id'];
            $lines = [];
            $validation = $this->validateCarPurchaseInput($amount, $date, $paymentAccount, $partnerFunding, $gstAmount, $sellerName, $paidNow);
        $grossAmount = $validation['gross_amount'];
        $gstAmount = $validation['gst_amount'];
        $partnerFunding = $validation['partner_funding'];
        $businessAmount = $validation['business_amount'];
        $paidNow = $validation['paid_now'];
        $sellerOutstanding = $validation['seller_outstanding'];
        $gstInputAccount = $gstAmount > 0 ? $this->getOrCreateSystemAccount('GST-RCV', 'GST Input Credit', 'ASSET', 'GST Assets') : null;

        $businessGst = 0.0;
        if ($businessAmount > 0) {
            $businessGst = $grossAmount > 0 ? round(($gstAmount * $businessAmount) / $grossAmount, 2) : 0.0;
            $businessBase = round($businessAmount - $businessGst, 2);
            if ($businessBase > 0) {
                $lines[] = ['account_id' => $carAccountId, 'amount' => $businessBase, 'type' => 'DR', 'narration' => 'Car purchase - business funds'];
            }
            if ($businessGst > 0 && !empty($gstInputAccount['id'])) {
                $lines[] = ['account_id' => $gstInputAccount['id'], 'amount' => $businessGst, 'type' => 'DR', 'narration' => 'GST input on car purchase'];
            }
            if ($paidNow > 0) {
                $lines[] = ['account_id' => $paymentAccount, 'amount' => $paidNow, 'type' => 'CR', 'narration' => 'Paid for car purchase'];
            }
            if ($sellerOutstanding > 0) {
                $sellerPartyId = $this->getOrCreateParty($sellerName, 'SELLER');
                $sellerParty = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$sellerPartyId, $this->businessId]);
                $lines[] = ['account_id' => $sellerParty['account_id'], 'amount' => $sellerOutstanding, 'type' => 'CR', 'narration' => "Pending purchase payment to $sellerName"];
            }
        }

        $entryId = null;
        if (!empty($lines)) {
            $entryId = $this->postJournalEntry('CAR_PURCHASE', $date, $narration, $lines, ['car_id' => $carId]);
        }

        // Partner funding entries
        $partnerGstAllocated = 0.0;
        $partnerGstTarget = round($gstAmount - $businessGst, 2);
        $partnerRowsRemaining = count(array_filter($partnerFunding, static fn($row) => floatval($row['amount'] ?? 0) > 0));
        foreach ($partnerFunding as $pf) {
            $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ? AND business_id = ?", [$pf['partner_id'], $this->businessId]);
            if (!$partner) continue;

            if ($pf['amount'] > 0) {
                $partnerRowsRemaining--;
                $partnerGst = $partnerRowsRemaining === 0
                    ? round($partnerGstTarget - $partnerGstAllocated, 2)
                    : ($grossAmount > 0 ? round(($gstAmount * $pf['amount']) / $grossAmount, 2) : 0.0);
                $partnerGstAllocated += $partnerGst;
                $partnerBase = round($pf['amount'] - $partnerGst, 2);

                $partnerLines = [];
                if ($partnerBase > 0) {
                    $partnerLines[] = ['account_id' => $carAccountId, 'amount' => $partnerBase, 'type' => 'DR', 'narration' => "Partner {$partner['name']} contribution"];
                }
                if ($partnerGst > 0 && !empty($gstInputAccount['id'])) {
                    $partnerLines[] = ['account_id' => $gstInputAccount['id'], 'amount' => $partnerGst, 'type' => 'DR', 'narration' => "GST input on {$car['registration_no']}"];
                }
                $partnerLines[] = ['account_id' => $partner['capital_account_id'], 'amount' => $pf['amount'], 'type' => 'CR', 'narration' => "Investment in car {$car['registration_no']}"];
                $partnerEntryId = $this->postJournalEntry('PARTNER_INVEST', $date, "Partner {$partner['name']} invested in {$car['registration_no']}", $partnerLines, ['car_id' => $carId, 'partner_id' => $pf['partner_id']]);

                $this->db->insert('car_partner_contributions', [
                    'id' => Database::uuid(),
                    'car_id' => $carId,
                    'partner_id' => $pf['partner_id'],
                    'amount' => $pf['amount'],
                    'funding_pct' => $pf['funding_pct'],
                    'profit_share_pct' => $pf['profit_share_pct'],
                    'contribution_date' => $date,
                    'journal_entry_id' => $partnerEntryId,
                ]);
            }

            $existingPartnership = $this->db->fetch(
                "SELECT id FROM car_partnerships WHERE car_id = ? AND partner_id = ?",
                [$carId, $pf['partner_id']]
            );

            $partnershipData = [
                'business_id' => $this->businessId,
                'car_id' => $carId,
                'partner_id' => $pf['partner_id'],
                'funding_amount' => $pf['amount'],
                'funding_pct' => $pf['funding_pct'],
                'profit_share_pct' => $pf['profit_share_pct'],
                'status' => 'ACTIVE',
                'created_by' => $this->userId,
                'notes' => $pf['notes'] ?? null,
            ];

            if ($existingPartnership) {
                $oldPartnership = $this->db->fetch("SELECT * FROM car_partnerships WHERE id = ?", [$existingPartnership['id']]);
                $this->db->update('car_partnerships', $partnershipData, 'id = ?', [$existingPartnership['id']]);
                $updatedPartnership = $this->db->fetch("SELECT * FROM car_partnerships WHERE id = ?", [$existingPartnership['id']]);
                Auth::auditUpdate('car', $carId, ['partner_terms' => $oldPartnership ?: []], ['partner_terms' => $updatedPartnership ?: []], "Car partner terms updated for {$car['registration_no']}", 'cars');
            } else {
                $partnershipData['id'] = Database::uuid();
                $this->db->insert('car_partnerships', $partnershipData);
                Auth::auditUpdate('car', $carId, ['partner_terms' => []], ['partner_terms' => $partnershipData], "Car partner terms added for {$car['registration_no']}", 'cars');
            }
        }

        $this->db->query(
            "UPDATE cars SET purchase_paid_amount = ?, seller_party_id = COALESCE(?, seller_party_id) WHERE id = ? AND business_id = ?",
            [$paidNow, $sellerPartyId ?? null, $carId, $this->businessId]
        );
        $updatedCar = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
            Auth::auditUpdate('car', $carId, $car, $updatedCar ?: [], 'Car purchase balances and seller link updated', 'transactions');

            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function receiveCarToken($carId, $partyId, $buyerName, $buyerPhone, $amount, $date, $receivingAccount, $narration) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception('Select a valid car for the token.');
        if ($car['status'] !== 'IN_STOCK') throw new Exception('Token can be received only for an in-stock car.');

        $amount = round(floatval($amount), 2);
        if ($amount <= 0) throw new Exception('Token amount must be greater than zero.');
        $party = $this->resolveParty($partyId, $buyerName, $buyerPhone, 'BUYER', ['BUYER', 'DEBTOR']);

        $otherBuyer = $this->db->fetch(
            "SELECT ct.party_id, dc.name
             FROM car_tokens ct
             JOIN debtors_creditors dc ON dc.id = ct.party_id
             WHERE ct.business_id = ? AND ct.car_id = ?
               AND ct.status IN ('OPEN','PARTIAL')
               AND (ct.amount - ct.applied_amount) > 0.009
               AND ct.party_id <> ?
             LIMIT 1",
            [$this->businessId, $carId, $party['id']]
        );
        if ($otherBuyer) {
            throw new Exception("This car already has an open token from {$otherBuyer['name']}. Reverse or settle that token before accepting another buyer.");
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $advanceAccount = $this->getOrCreateSystemAccount('CUST-ADV', 'Customer Token Advances', 'LIABILITY', 'Current Liabilities');
            $entryId = $this->postJournalEntry('CAR_TOKEN_RECEIVED', $date, $narration, [
                ['account_id' => $receivingAccount, 'amount' => $amount, 'type' => 'DR', 'narration' => "Token received from {$party['name']}"],
                ['account_id' => $advanceAccount['id'], 'amount' => $amount, 'type' => 'CR', 'narration' => "Token held for {$car['registration_no']}"],
            ], ['car_id' => $carId, 'party_id' => $party['id']]);

            $tokenId = Database::uuid();
            $tokenRecord = [
                'id' => $tokenId,
                'business_id' => $this->businessId,
                'car_id' => $carId,
                'party_id' => $party['id'],
                'journal_entry_id' => $entryId,
                'received_date' => $date,
                'amount' => $amount,
                'narration' => $narration,
                'created_by' => $this->userId,
            ];
            $this->db->insert('car_tokens', $tokenRecord);
            Auth::auditCreate('car_token', $tokenId, $tokenRecord, "Car token received for {$car['registration_no']}", 'transactions');
            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function getCarTokenSummary($carId) {
        $rows = $this->db->fetchAll(
            "SELECT ct.*, dc.name AS party_name, dc.phone AS party_phone, je.reference_no
             FROM car_tokens ct
             JOIN debtors_creditors dc ON dc.id = ct.party_id
             LEFT JOIN journal_entries je ON je.id = ct.journal_entry_id
             WHERE ct.business_id = ? AND ct.car_id = ?
             ORDER BY ct.received_date, ct.created_at",
            [$this->businessId, $carId]
        );
        $received = 0.0;
        $applied = 0.0;
        foreach ($rows as $row) {
            if ($row['status'] === 'REVERSED') continue;
            $received += floatval($row['amount']);
            $applied += floatval($row['applied_amount']);
        }
        $openRow = null;
        foreach ($rows as $row) {
            if (in_array($row['status'], ['OPEN', 'PARTIAL'], true) && floatval($row['amount']) - floatval($row['applied_amount']) > 0.009) {
                $openRow = $row;
                break;
            }
        }
        return [
            'rows' => $rows,
            'received' => round($received, 2),
            'applied' => round($applied, 2),
            'available' => round(max(0, $received - $applied), 2),
            'party_id' => $openRow['party_id'] ?? null,
            'party_name' => $openRow['party_name'] ?? null,
        ];
    }

    /**
     * CAR SALE - full or partial payment, including any token already held.
     */
    public function carSale($carId, $salePrice, $date, $receivingAccount, $narration, $buyerName = null, $amountReceived = null, $gstAmount = 0, $commissionAmount = 0, $buyerPartyId = null, $buyerPhone = null) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception('Car not found.');
        if (($car['ownership_type'] ?? 'OWNED') !== 'OWNED') throw new Exception('Use the Commission Cars section to sell a customer-owned car.');
        if ($car['status'] !== 'IN_STOCK') throw new Exception('Only in-stock cars can be sold from this entry. Use buyer payment clearing for a pending sale.');
        if ($salePrice <= 0) throw new Exception('Sale price must be greater than zero.');
        $commissionAmount = round(floatval($commissionAmount), 2);
        if ($commissionAmount < 0) throw new Exception('Commission cannot be negative.');

        $party = $this->resolveParty($buyerPartyId, $buyerName, $buyerPhone, 'BUYER', ['BUYER', 'DEBTOR']);
        $partyId = $party['id'];
        $buyerName = $party['name'];
        $tokenSummary = $this->getCarTokenSummary($carId);
        if ($tokenSummary['available'] > 0.009 && $tokenSummary['party_id'] !== $partyId) {
            throw new Exception("This car has an open token from {$tokenSummary['party_name']}. Select that buyer or reverse the token first.");
        }

        $carAccountId = $car['account_id'];
        $totalCost = $this->getCarTotalCost($carId);
        [$grossSalePrice, $gstAmount, $netSalePrice] = $this->normalizeGstComponent($salePrice, $gstAmount);
        $grossReceiptTarget = round($grossSalePrice + $commissionAmount, 2);
        $tokenApplied = round(min($tokenSummary['available'], $grossReceiptTarget), 2);
        $remainingAfterToken = round($grossReceiptTarget - $tokenApplied, 2);
        $received = $amountReceived === null ? $remainingAfterToken : round(floatval($amountReceived), 2);
        if ($received < 0) throw new Exception('Amount received now cannot be negative.');
        if ($received - $remainingAfterToken > 0.01) throw new Exception('Amount received now cannot exceed the amount remaining after token adjustment.');
        $outstanding = round($remainingAfterToken - $received, 2);
        $profit = ($netSalePrice + $commissionAmount) - $totalCost;

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $lines = [];
            if ($received > 0) {
                $lines[] = ['account_id' => $receivingAccount, 'amount' => $received, 'type' => 'DR', 'narration' => 'Buyer amount received now'];
            }
            if ($tokenApplied > 0) {
                $advanceAccount = $this->getOrCreateSystemAccount('CUST-ADV', 'Customer Token Advances', 'LIABILITY', 'Current Liabilities');
                $lines[] = ['account_id' => $advanceAccount['id'], 'amount' => $tokenApplied, 'type' => 'DR', 'narration' => "Token adjusted for {$car['registration_no']}"];
            }
            if ($outstanding > 0) {
                $lines[] = ['account_id' => $party['account_id'], 'amount' => $outstanding, 'type' => 'DR', 'narration' => "Outstanding from {$party['name']}"];
            }

            $revenueAccount = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'CAR-REV'", [$this->businessId]);
            if (!$revenueAccount) {
                $revenueAccount = ['id' => $this->createAccount('CAR-REV', 'Car Sales Revenue', 'INCOME', 'Direct Income', 'GENERAL')];
            }
            $lines[] = ['account_id' => $revenueAccount['id'], 'amount' => $netSalePrice, 'type' => 'CR', 'narration' => "Car sale revenue - {$car['registration_no']}"];
            if ($commissionAmount > 0) {
                $commissionIncome = $this->getOrCreateSystemAccount('SALE-COMM', 'Car Sale Commission Income', 'INCOME', 'Direct Income');
                $lines[] = ['account_id' => $commissionIncome['id'], 'amount' => $commissionAmount, 'type' => 'CR', 'narration' => "Commission income - {$car['registration_no']}"];
            }
            if ($gstAmount > 0) {
                $gstPayable = $this->getOrCreateSystemAccount('GST-PAY', 'GST Payable', 'LIABILITY', 'GST Liabilities');
                $lines[] = ['account_id' => $gstPayable['id'], 'amount' => $gstAmount, 'type' => 'CR', 'narration' => "GST output on {$car['registration_no']}"];
            }

            $entryId = $this->postJournalEntry('CAR_SALE', $date, $narration, $lines, [
                'car_id' => $carId,
                'party_id' => $partyId,
                'entry_amount' => $grossReceiptTarget,
            ]);
            if ($tokenApplied > 0) {
                $this->applyCarTokensToSale($carId, $partyId, $tokenApplied, $entryId);
            }

            if ($totalCost > 0) {
                $pnlAccount = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'PNL'", [$this->businessId]);
                $costLines = [
                    ['account_id' => $pnlAccount['id'], 'amount' => $totalCost, 'type' => 'DR', 'narration' => "Cost of car sold - {$car['registration_no']}"],
                    ['account_id' => $carAccountId, 'amount' => $totalCost, 'type' => 'CR', 'narration' => 'Car account closed'],
                ];
                $this->postJournalEntry('CAR_SALE', $date, "Close car account {$car['registration_no']}", $costLines, [
                    'car_id' => $carId,
                    'entry_type_id' => systemEntryTypeId('INTERNAL_ALLOCATION'),
                    'entry_amount' => 0,
                ]);
            }

            $status = $outstanding > 0 ? 'PENDING_PAYMENT' : 'SOLD';
            $this->db->query(
                "UPDATE cars SET status = ?, sold_date = ?, sale_price = ?, sale_commission_amount = ?, sale_gst_amount = ?, buyer_name = ?, buyer_party_id = ? WHERE id = ? AND business_id = ?",
                [$status, $date, $grossSalePrice, $commissionAmount, $gstAmount, $buyerName, $partyId, $carId, $this->businessId]
            );
            $soldCar = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
            Auth::auditUpdate('car', $carId, $car, $soldCar ?: [], 'Car sale, buyer, token adjustment, and payment status updated', 'transactions');
            $this->recordPartnerProfitDistribution($carId, $profit, $date);

            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function createCommissionCar(array $data) {
        $registrationNo = normalizeRegistrationNo($data['registration_no'] ?? '');
        if (!isValidRegistrationNo($registrationNo)) {
            throw new Exception('Registration number must be like GJ05AA0001, with exactly 4 digits at the end.');
        }
        $existing = $this->db->fetch(
            "SELECT id FROM cars WHERE business_id = ? AND registration_no = ?",
            [$this->businessId, $registrationNo]
        );
        if ($existing) throw new Exception('A car with this registration number already exists.');

        $receivedDate = trim((string) ($data['received_date'] ?? ''));
        $date = DateTime::createFromFormat('!Y-m-d', $receivedDate);
        if (!$date || $date->format('Y-m-d') !== $receivedDate) throw new Exception('A valid received date is required.');

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $owner = $this->resolveParty(
                $data['owner_party_id'] ?? '',
                $data['owner_name'] ?? '',
                $data['owner_phone'] ?? '',
                'SELLER',
                ['SELLER', 'CREDITOR']
            );
            $expectedSale = round(floatval($data['expected_sale_price'] ?? 0), 2);
            $expectedCommission = round(floatval($data['expected_commission_amount'] ?? 0), 2);
            if ($expectedSale < 0 || $expectedCommission < 0) throw new Exception('Expected amounts cannot be negative.');
            if ($expectedSale > 0 && $expectedCommission - $expectedSale > 0.01) {
                throw new Exception('Expected commission cannot exceed the expected sale value.');
            }

        $year = intval($data['year'] ?? 0) ?: null;
        if ($year && ($year < 1900 || $year > intval(date('Y')) + 1)) throw new Exception('Enter a valid vehicle year.');
        $carId = Database::uuid();
        $record = [
            'id' => $carId,
            'business_id' => $this->businessId,
            'registration_no' => $registrationNo,
            'make' => trim((string) ($data['make'] ?? '')),
            'model' => trim((string) ($data['model'] ?? '')),
            'year' => $year,
            'color' => trim((string) ($data['color'] ?? '')),
            'purchase_date' => $receivedDate,
            'purchase_price' => 0,
            'purchase_paid_amount' => 0,
            'ownership_type' => 'COMMISSION',
            'commission_owner_party_id' => $owner['id'],
            'seller_party_id' => $owner['id'],
            'expected_sale_price' => $expectedSale,
            'expected_commission_amount' => $expectedCommission,
            'has_second_key' => !empty($data['has_second_key']) ? 1 : 0,
            'notes' => trim((string) ($data['notes'] ?? '')),
        ];
            $this->db->insert('cars', $record);
            Auth::auditCreate('car', $carId, $record, "Commission car $registrationNo received from {$owner['name']}", 'commission_cars');
            if ($ownsTransaction) $this->db->commit();
            return $carId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function commissionCarSale($carId, $grossSaleAmount, $commissionAmount, $date, $receivingAccount, $paymentHandling, $narration, $buyerPartyId = null, $buyerName = null, $buyerPhone = null, $amountReceived = null) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ? FOR UPDATE", [$carId, $this->businessId]);
        if (!$car) throw new Exception('Commission car not found.');
        if (($car['ownership_type'] ?? 'OWNED') !== 'COMMISSION') throw new Exception('This is not a commission car.');
        if ($car['status'] !== 'IN_STOCK') throw new Exception('Only an in-stock commission car can be sold.');

        $grossSaleAmount = round(floatval($grossSaleAmount), 2);
        $commissionAmount = round(floatval($commissionAmount), 2);
        if ($grossSaleAmount <= 0) throw new Exception('Gross sale value must be greater than zero.');
        if ($commissionAmount <= 0) throw new Exception('Commission income must be greater than zero.');
        if ($commissionAmount - $grossSaleAmount > 0.01) throw new Exception('Commission cannot exceed the gross sale value.');
        $paymentHandling = strtoupper(trim((string) $paymentHandling));
        if (!in_array($paymentHandling, ['COMMISSION_ONLY', 'FULL_AMOUNT'], true)) throw new Exception('Select how buyer money was handled.');

        $owner = $this->db->fetch(
            "SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ? AND is_active = 1",
            [$car['commission_owner_party_id'], $this->businessId]
        );
        if (!$owner || !in_array($owner['type'], ['SELLER', 'CREDITOR'], true)) throw new Exception('The commission car owner account is missing or inactive.');
        $buyer = $this->resolveParty($buyerPartyId, $buyerName, $buyerPhone, 'BUYER', ['BUYER', 'DEBTOR']);
        $tokenSummary = $this->getCarTokenSummary($carId);
        if ($tokenSummary['available'] > 0.009 && $tokenSummary['party_id'] !== $buyer['id']) {
            throw new Exception("This car has an open token from {$tokenSummary['party_name']}. Select that buyer or reverse the token first.");
        }
        if ($paymentHandling === 'COMMISSION_ONLY' && $tokenSummary['available'] > 0.009) {
            throw new Exception('The business already holds a buyer token for this car. Select Full sale amount handled by business so the owner payable remains correct.');
        }

        $targetReceipt = $paymentHandling === 'FULL_AMOUNT' ? $grossSaleAmount : $commissionAmount;
        $tokenApplied = $paymentHandling === 'FULL_AMOUNT' ? round(min($tokenSummary['available'], $targetReceipt), 2) : 0.0;
        $remainingAfterToken = round($targetReceipt - $tokenApplied, 2);
        $receivedNow = $amountReceived === null ? $remainingAfterToken : round(floatval($amountReceived), 2);
        if ($receivedNow < 0 || $receivedNow - $remainingAfterToken > 0.01) {
            throw new Exception('Amount received now must be between zero and the amount remaining after token adjustment.');
        }
        if ($paymentHandling === 'COMMISSION_ONLY' && abs($receivedNow - $commissionAmount) > 0.01) {
            throw new Exception('When the owner receives the car sale amount directly, record this sale after the full commission has been received.');
        }
        $buyerOutstanding = round($remainingAfterToken - $receivedNow, 2);
        $ownerAmount = round($grossSaleAmount - $commissionAmount, 2);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $lines = [];
            if ($receivedNow > 0) {
                $lines[] = ['account_id' => $receivingAccount, 'amount' => $receivedNow, 'type' => 'DR', 'narration' => 'Money received from commission car buyer'];
            }
            if ($tokenApplied > 0) {
                $advanceAccount = $this->getOrCreateSystemAccount('CUST-ADV', 'Customer Token Advances', 'LIABILITY', 'Current Liabilities');
                $lines[] = ['account_id' => $advanceAccount['id'], 'amount' => $tokenApplied, 'type' => 'DR', 'narration' => "Token adjusted for {$car['registration_no']}"];
            }
            if ($buyerOutstanding > 0) {
                $lines[] = ['account_id' => $buyer['account_id'], 'amount' => $buyerOutstanding, 'type' => 'DR', 'narration' => "Outstanding from {$buyer['name']}"];
            }
            $commissionIncome = $this->getOrCreateSystemAccount('SALE-COMM', 'Car Sale Commission Income', 'INCOME', 'Direct Income');
            $lines[] = ['account_id' => $commissionIncome['id'], 'amount' => $commissionAmount, 'type' => 'CR', 'narration' => "Commission income - {$car['registration_no']}"];
            if ($paymentHandling === 'FULL_AMOUNT' && $ownerAmount > 0) {
                $lines[] = ['account_id' => $owner['account_id'], 'amount' => $ownerAmount, 'type' => 'CR', 'narration' => "Amount payable to owner {$owner['name']}"];
            }

            $entryId = $this->postJournalEntry('CAR_SALE', $date, $narration ?: "Commission car sold - {$car['registration_no']}", $lines, [
                'car_id' => $carId,
                'party_id' => $buyer['id'],
                'entry_type_id' => systemEntryTypeId('COMMISSION_CAR_SALE'),
                'entry_amount' => $commissionAmount,
            ]);
            if ($tokenApplied > 0) $this->applyCarTokensToSale($carId, $buyer['id'], $tokenApplied, $entryId);

            $settlementId = Database::uuid();
            $settlementStatus = $paymentHandling === 'COMMISSION_ONLY' ? 'NOT_APPLICABLE' : ($ownerAmount > 0 ? 'PENDING' : 'PAID');
            $settlement = [
                'id' => $settlementId,
                'business_id' => $this->businessId,
                'car_id' => $carId,
                'owner_party_id' => $owner['id'],
                'buyer_party_id' => $buyer['id'],
                'sale_entry_id' => $entryId,
                'sale_date' => $date,
                'gross_sale_amount' => $grossSaleAmount,
                'commission_amount' => $commissionAmount,
                'owner_amount' => $ownerAmount,
                'buyer_outstanding_amount' => $buyerOutstanding,
                'payment_handling' => $paymentHandling,
                'paid_to_owner_amount' => 0,
                'status' => $settlementStatus,
                'created_by' => $this->userId,
            ];
            $this->db->insert('commission_car_settlements', $settlement);
            $newStatus = $buyerOutstanding > 0.009 ? 'PENDING_PAYMENT' : 'SOLD';
            $this->db->query(
                "UPDATE cars SET status = ?, sold_date = ?, sale_price = ?, sale_commission_amount = ?, buyer_name = ?, buyer_party_id = ? WHERE id = ? AND business_id = ?",
                [$newStatus, $date, $grossSaleAmount, $commissionAmount, $buyer['name'], $buyer['id'], $carId, $this->businessId]
            );
            $updatedCar = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
            Auth::auditCreate('commission_car_settlement', $settlementId, $settlement, 'Commission sale and owner settlement created', 'commission_cars');
            Auth::auditUpdate('car', $carId, $car, $updatedCar ?: [], 'Commission car sold; gross value kept as memorandum and commission posted as income', 'commission_cars');
            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function getCommissionSettlement($carId) {
        return $this->db->fetch(
            "SELECT ccs.*, owner.name AS owner_name, buyer.name AS buyer_name
             FROM commission_car_settlements ccs
             JOIN debtors_creditors owner ON owner.id = ccs.owner_party_id
             JOIN debtors_creditors buyer ON buyer.id = ccs.buyer_party_id
             WHERE ccs.business_id = ? AND ccs.car_id = ? AND ccs.status <> 'REVERSED'
             ORDER BY ccs.created_at DESC LIMIT 1",
            [$this->businessId, $carId]
        );
    }

    public function payCommissionCarOwner($carId, $amount, $date, $paymentAccount, $narration = '') {
        $settlement = $this->getCommissionSettlement($carId);
        if (!$settlement || $settlement['payment_handling'] !== 'FULL_AMOUNT') throw new Exception('This commission sale has no owner amount payable by the business.');
        $amount = round(floatval($amount), 2);
        $outstanding = round(floatval($settlement['owner_amount']) - floatval($settlement['paid_to_owner_amount']), 2);
        if ($amount <= 0) throw new Exception('Owner payment must be greater than zero.');
        if ($amount - $outstanding > 0.01) throw new Exception('Owner payment cannot exceed ' . formatAmount($outstanding) . '.');
        $owner = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$settlement['owner_party_id'], $this->businessId]);
        if (!$owner) throw new Exception('Commission car owner not found.');
        $this->validateCashAvailable($paymentAccount, $amount);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $entryId = $this->postJournalEntry('LOAN_REPAID', $date, $narration ?: "Paid commission car owner - {$owner['name']}", [
                ['account_id' => $owner['account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Owner settlement for commission car"],
                ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Paid to {$owner['name']}"],
            ], [
                'party_id' => $owner['id'],
                'car_id' => $carId,
                'entry_type_id' => systemEntryTypeId('COMMISSION_OWNER_PAYMENT'),
                'entry_amount' => $amount,
            ]);
            $applicationId = Database::uuid();
            $this->db->insert('commission_owner_payments', [
                'id' => $applicationId,
                'business_id' => $this->businessId,
                'settlement_id' => $settlement['id'],
                'journal_entry_id' => $entryId,
                'payment_date' => $date,
                'amount' => $amount,
                'created_by' => $this->userId,
            ]);
            $newPaid = round(floatval($settlement['paid_to_owner_amount']) + $amount, 2);
            $newStatus = $newPaid >= floatval($settlement['owner_amount']) - 0.009 ? 'PAID' : 'PARTIAL';
            $this->db->query("UPDATE commission_car_settlements SET paid_to_owner_amount = ?, status = ? WHERE id = ? AND business_id = ?", [$newPaid, $newStatus, $settlement['id'], $this->businessId]);
            Auth::auditUpdate('commission_car_settlement', $settlement['id'], $settlement, array_merge($settlement, ['paid_to_owner_amount' => $newPaid, 'status' => $newStatus]), 'Commission car owner payment recorded', 'commission_cars');
            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function applyCarTokensToSale($carId, $partyId, $amount, $saleEntryId) {
        $remaining = round(floatval($amount), 2);
        $tokens = $this->db->fetchAll(
            "SELECT * FROM car_tokens
             WHERE business_id = ? AND car_id = ? AND party_id = ?
               AND status IN ('OPEN','PARTIAL')
             ORDER BY received_date, created_at FOR UPDATE",
            [$this->businessId, $carId, $partyId]
        );
        foreach ($tokens as $token) {
            if ($remaining <= 0.009) break;
            $available = round(floatval($token['amount']) - floatval($token['applied_amount']), 2);
            if ($available <= 0.009) continue;
            $apply = min($available, $remaining);
            $newApplied = round(floatval($token['applied_amount']) + $apply, 2);
            $status = $newApplied >= floatval($token['amount']) - 0.009 ? 'APPLIED' : 'PARTIAL';
            $this->db->query(
                "UPDATE car_tokens SET applied_amount = ?, applied_sale_entry_id = ?, status = ? WHERE id = ? AND business_id = ?",
                [$newApplied, $saleEntryId, $status, $token['id'], $this->businessId]
            );
            Auth::auditUpdate('car_token', $token['id'], $token, array_merge($token, [
                'applied_amount' => $newApplied,
                'applied_sale_entry_id' => $saleEntryId,
                'status' => $status,
            ]), 'Token adjusted against car sale', 'transactions');
            $remaining = round($remaining - $apply, 2);
        }
        if ($remaining > 0.009) throw new Exception('Available token balance changed. Reload and try the sale again.');
    }

    public function getPartyOutstandingAmount($partyId) {
        $party = $this->db->fetch(
            "SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?",
            [$partyId, $this->businessId]
        );
        if (!$party) return 0.0;
        $naturalType = in_array($party['type'], ['DEBTOR', 'BUYER'], true) ? 'DR' : 'CR';
        $openItems = $this->buildOutstandingItemsFromLedger($party['account_id'], $naturalType);
        return round(array_sum(array_column($openItems, 'outstanding_amount')), 2);
    }

    private function getCarLinkedOutstandingAmount($carId, $accountId, $normalType, array $entryTypes) {
        if (!$carId || !$accountId || empty($entryTypes)) {
            return 0.0;
        }

        $placeholders = implode(',', array_fill(0, count($entryTypes), '?'));
        $row = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS total_dr,
                COALESCE(SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS total_cr
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ?
               AND je.business_id = ?
               AND je.car_id = ?
               AND je.status = 'POSTED'
               AND je.is_reversal = 0
               AND je.transaction_type IN ($placeholders)",
            array_merge([$accountId, $this->businessId, $carId], $entryTypes)
        );

        $dr = floatval($row['total_dr'] ?? 0);
        $cr = floatval($row['total_cr'] ?? 0);
        $outstanding = strtoupper($normalType) === 'CR' ? ($cr - $dr) : ($dr - $cr);
        return round(max(0, $outstanding), 2);
    }

    private function getCarSalePendingFromSalePrice(array $car) {
        $salePrice = round(floatval($car['sale_price'] ?? 0), 2);
        if ($salePrice <= 0) {
            return 0.0;
        }

        $saleEntry = $this->db->fetch(
            "SELECT je.id, je.entry_date, je.created_at
             FROM journal_entries je
             WHERE je.business_id = ?
               AND je.car_id = ?
               AND je.transaction_type = 'CAR_SALE'
               AND je.status = 'POSTED'
               AND je.is_reversal = 0
               AND je.narration NOT LIKE 'Close car account %'
             ORDER BY je.entry_date DESC, je.created_at DESC
             LIMIT 1",
            [$this->businessId, $car['id']]
        );

        if (!$saleEntry) {
            return 0.0;
        }

        if (!empty($car['buyer_party_id'])) {
            $buyer = $this->db->fetch(
                "SELECT account_id FROM debtors_creditors WHERE id = ? AND business_id = ?",
                [$car['buyer_party_id'], $this->businessId]
            );

            if (!empty($buyer['account_id'])) {
                $initialOutstanding = $this->db->fetch(
                    "SELECT COALESCE(SUM(amount), 0) AS total
                     FROM journal_lines
                     WHERE journal_entry_id = ? AND account_id = ? AND entry_type = 'DR'",
                    [$saleEntry['id'], $buyer['account_id']]
                );
                $laterCredits = $this->db->fetch(
                    "SELECT COALESCE(SUM(jl.amount), 0) AS total
                     FROM journal_lines jl
                     JOIN journal_entries je ON je.id = jl.journal_entry_id
                     WHERE jl.account_id = ?
                       AND jl.entry_type = 'CR'
                       AND je.business_id = ?
                       AND je.car_id = ?
                       AND je.status = 'POSTED'
                       AND je.is_reversal = 0
                       AND je.transaction_type IN ('LOAN_RECEIVED', 'BAD_DEBT')
                       AND (
                           je.entry_date > ?
                           OR (je.entry_date = ? AND je.created_at >= ?)
                       )",
                    [
                        $buyer['account_id'],
                        $this->businessId,
                        $car['id'],
                        $saleEntry['entry_date'],
                        $saleEntry['entry_date'],
                        $saleEntry['created_at'],
                    ]
                );
                return round(max(0, floatval($initialOutstanding['total'] ?? 0) - floatval($laterCredits['total'] ?? 0)), 2);
            }
        }

        return 0.0;
    }

    public function getCarPendingAmounts($carId) {
        $car = $this->db->fetch(
            "SELECT * FROM cars WHERE id = ? AND business_id = ?",
            [$carId, $this->businessId]
        );
        if (!$car) {
            return [
                'sale_pending' => 0.0,
                'purchase_pending' => 0.0,
                'buyer_party_id' => null,
                'seller_party_id' => null,
            ];
        }

        $salePending = $this->getCarSalePendingFromSalePrice($car);
        if (!empty($car['buyer_party_id'])) {
            $buyer = $this->db->fetch(
                "SELECT account_id FROM debtors_creditors WHERE id = ? AND business_id = ?",
                [$car['buyer_party_id'], $this->businessId]
            );
            if (!$buyer) {
                $salePending = 0.0;
            }
        }

        $purchasePending = 0.0;
        if (!empty($car['seller_party_id'])) {
            $seller = $this->db->fetch(
                "SELECT account_id FROM debtors_creditors WHERE id = ? AND business_id = ?",
                [$car['seller_party_id'], $this->businessId]
            );
            if (!empty($seller['account_id'])) {
                $purchasePending = $this->getCarLinkedOutstandingAmount(
                    $carId,
                    $seller['account_id'],
                    'CR',
                    ['CAR_PURCHASE', 'LOAN_REPAID']
                );
            }
        }

        return [
            'sale_pending' => $salePending,
            'purchase_pending' => $purchasePending,
            'buyer_party_id' => $car['buyer_party_id'] ?: null,
            'seller_party_id' => $car['seller_party_id'] ?: null,
        ];
    }

    public function refreshPendingCarSaleStatusesForParty($partyId) {
        if (!$partyId) return;
        $outstanding = $this->getPartyOutstandingAmount($partyId);
        if ($outstanding > 0.009) return;
        $this->db->query(
            "UPDATE cars
             SET status = 'SOLD'
             WHERE business_id = ?
               AND buyer_party_id = ?
               AND status = 'PENDING_PAYMENT'",
            [$this->businessId, $partyId]
        );
    }

    public function returnSoldCar($carId, $reason) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception("Car not found.");
        if (!in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true)) {
            throw new Exception("Only sold or pending-payment cars can be returned.");
        }

        $saleEntry = $this->db->fetch(
            "SELECT *
             FROM journal_entries
             WHERE business_id = ?
               AND car_id = ?
               AND transaction_type = 'CAR_SALE'
               AND status = 'POSTED'
               AND is_reversal = 0
               AND narration NOT LIKE 'Close car account %'
             ORDER BY entry_date DESC, created_at DESC
             LIMIT 1",
            [$this->businessId, $carId]
        );
        if (!$saleEntry) throw new Exception("Original sale entry was not found.");

        if (!empty($car['buyer_party_id'])) {
            $laterReceipts = $this->db->fetch(
                "SELECT COUNT(*) AS cnt
                 FROM journal_entries
                 WHERE business_id = ?
                   AND party_id = ?
                   AND transaction_type IN ('LOAN_RECEIVED','BAD_DEBT')
                   AND status = 'POSTED'
                   AND is_reversal = 0
                   AND created_at > ?",
                [$this->businessId, $car['buyer_party_id'], $saleEntry['created_at']]
            );
            if (($laterReceipts['cnt'] ?? 0) > 0) {
                throw new Exception("Reverse later buyer payment/write-off entries before returning this car.");
            }
        }

        return $this->reverseEntry($saleEntry['id'], $reason ?: "Car returned: {$car['registration_no']}");
    }

    /**
     * CAR EXPENSE
     */
    public function carExpense($carId, $amount, $date, $paymentAccount, $categoryName, $narration, $gstAmount = 0) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception("Car not found");

        // RULE 3: Sold car cannot receive new expenses without admin override
        if (in_array($car['status'], ['SOLD', 'PENDING_PAYMENT'], true)) {
            throw new Exception("Cannot add expenses to a sold car. Admin override required.");
        }

        $this->validateCashAvailable($paymentAccount, $amount);
        [$grossAmount, $gstAmount, $baseAmount] = $this->normalizeGstComponent($amount, $gstAmount);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $expenseAccountId = $this->getOrCreateExpenseAccount($categoryName . ' - ' . $car['registration_no'], 'CAR_SPECIFIC');
            $gstInputAccount = $gstAmount > 0 ? $this->getOrCreateSystemAccount('GST-RCV', 'GST Input Credit', 'ASSET', 'GST Assets') : null;

        $lines = [];
        if ($baseAmount > 0) {
            $lines[] = ['account_id' => $expenseAccountId, 'amount' => $baseAmount, 'type' => 'DR', 'narration' => $narration];
        }
        if ($gstAmount > 0 && !empty($gstInputAccount['id'])) {
            $lines[] = ['account_id' => $gstInputAccount['id'], 'amount' => $gstAmount, 'type' => 'DR', 'narration' => "GST input for {$categoryName}"];
        }
        $lines[] = ['account_id' => $paymentAccount, 'amount' => $grossAmount, 'type' => 'CR', 'narration' => "Paid for {$categoryName}"];

        // Also debit the car asset account to track total cost
        // We create a separate entry for the car account
        $carLines = [
            ['account_id' => $car['account_id'], 'amount' => $baseAmount, 'type' => 'DR', 'narration' => "$categoryName for {$car['registration_no']}"],
            ['account_id' => $expenseAccountId, 'amount' => $baseAmount, 'type' => 'CR', 'narration' => "Expense allocated to car"],
        ];

        $entryId = $this->postJournalEntry('CAR_EXPENSE', $date, $narration, $lines, ['car_id' => $carId]);
        if ($baseAmount > 0) {
            $this->postJournalEntry('CAR_EXPENSE', $date, "Allocate {$categoryName} to {$car['registration_no']}", $carLines, [
                'car_id' => $carId,
                'entry_type_id' => systemEntryTypeId('INTERNAL_ALLOCATION'),
                'entry_amount' => 0,
            ]);
        }
            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function rtoExpense($rtoId, $carId, $amount, $date, $paymentAccount, $narration, $gstAmount = 0) {
        $rto = $this->db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $this->businessId]);
        if (!$rto) throw new Exception("RTO record not found.");
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception("Car not found.");

        $this->validateCashAvailable($paymentAccount, $amount);
        [$grossAmount, $gstAmount, $baseAmount] = $this->normalizeGstComponent($amount, $gstAmount);
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $expenseAccountId = $this->getOrCreateExpenseAccount('RTO - ' . $rto['rto_type'] . ' - ' . $car['registration_no'], 'CAR_SPECIFIC');
            $gstInputAccount = $gstAmount > 0 ? $this->getOrCreateSystemAccount('GST-RCV', 'GST Input Credit', 'ASSET', 'GST Assets') : null;

        $lines = [];
        if ($baseAmount > 0) $lines[] = ['account_id' => $expenseAccountId, 'amount' => $baseAmount, 'type' => 'DR', 'narration' => $narration];
        if ($gstAmount > 0 && !empty($gstInputAccount['id'])) $lines[] = ['account_id' => $gstInputAccount['id'], 'amount' => $gstAmount, 'type' => 'DR', 'narration' => 'GST input for RTO'];
        $lines[] = ['account_id' => $paymentAccount, 'amount' => $grossAmount, 'type' => 'CR', 'narration' => 'Paid RTO expense'];
        $entryId = $this->postJournalEntry('RTO_EXPENSE', $date, $narration, $lines, ['car_id' => $carId]);

        if ($baseAmount > 0) {
            $this->postJournalEntry('RTO_EXPENSE', $date, "Allocate RTO to {$car['registration_no']}", [
                ['account_id' => $car['account_id'], 'amount' => $baseAmount, 'type' => 'DR', 'narration' => "RTO {$rto['rto_type']}"],
                ['account_id' => $expenseAccountId, 'amount' => $baseAmount, 'type' => 'CR', 'narration' => 'RTO allocated to car'],
            ], [
                'car_id' => $carId,
                'entry_type_id' => systemEntryTypeId('INTERNAL_ALLOCATION'),
                'entry_amount' => 0,
            ]);
        }

        $this->db->query(
            "UPDATE rto_records
             SET expense_amount = expense_amount + ?, expense_entry_id = ?, status = IF(status = 'PENDING', 'IN_PROGRESS', status)
             WHERE id = ? AND business_id = ?",
            [$grossAmount, $entryId, $rtoId, $this->businessId]
        );
        $updatedRto = $this->db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $this->businessId]);
            Auth::auditUpdate('rto_record', $rtoId, $rto, $updatedRto ?: [], 'RTO expense totals updated', 'rto');
            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function rtoRecovery($rtoId, $amount, $date, $receivingAccount, $narration) {
        $rto = $this->db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $this->businessId]);
        if (!$rto) throw new Exception("RTO record not found.");
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) throw new Exception("Recovery amount must be greater than zero.");
        $pending = round(floatval($rto['expense_amount']) - floatval($rto['recovered_amount']), 2);
        if (!empty($rto['is_recoverable']) && $pending > 0 && $amount - $pending > 0.01) {
            throw new Exception("RTO recovery cannot exceed pending recovery of " . formatAmount($pending) . ".");
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $income = $this->getOrCreateSystemAccount('RTO-REC', 'RTO Recovery Income', 'INCOME', 'Direct Income');
            $entryId = $this->postJournalEntry('RTO_RECOVERY', $date, $narration, [
            ['account_id' => $receivingAccount, 'amount' => $amount, 'type' => 'DR', 'narration' => 'RTO recovery received'],
            ['account_id' => $income['id'], 'amount' => $amount, 'type' => 'CR', 'narration' => 'RTO recovery income'],
        ], ['car_id' => $rto['car_id']]);

        $this->db->insert('rto_recoveries', [
            'id' => Database::uuid(),
            'business_id' => $this->businessId,
            'rto_record_id' => $rtoId,
            'car_id' => $rto['car_id'],
            'journal_entry_id' => $entryId,
            'received_date' => $date,
            'amount' => $amount,
            'narration' => $narration,
        ]);
        $this->db->query(
            "UPDATE rto_records SET recovered_amount = recovered_amount + ?, last_recovery_entry_id = ? WHERE id = ? AND business_id = ?",
            [$amount, $entryId, $rtoId, $this->businessId]
        );
        $updatedRto = $this->db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $this->businessId]);
            Auth::auditUpdate('rto_record', $rtoId, $rto, $updatedRto ?: [], 'RTO recovery totals updated', 'rto');
            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function recordSecondKeyEvent($carId, $eventType, $date, $narration) {
        $eventType = strtoupper((string) $eventType);
        if (!in_array($eventType, ['RECEIVED', 'GIVEN'], true)) throw new Exception("Invalid second key event.");
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception("Car not found.");
        $eventId = Database::uuid();
        $eventRecord = [
            'id' => $eventId,
            'business_id' => $this->businessId,
            'car_id' => $carId,
            'event_type' => $eventType,
            'event_date' => $date,
            'narration' => $narration,
            'created_by' => $this->userId,
        ];
        $this->db->insert('car_second_key_events', $eventRecord);
        Auth::auditLog('CREATE', 'car', $carId, 'Second key event recorded', null, ['second_key_event' => $eventRecord], 'cars');
        $this->db->query(
            "UPDATE cars SET has_second_key = ? WHERE id = ? AND business_id = ?",
            [$eventType === 'RECEIVED' ? 1 : 0, $carId, $this->businessId]
        );
        $updatedCar = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        Auth::auditUpdate('car', $carId, $car, $updatedCar ?: [], 'Second key status updated', 'cars');
    }

    /**
     * GENERAL EXPENSE
     */
    public function generalExpense($amount, $date, $paymentAccount, $categoryName, $narration, $gstAmount = 0) {
        $this->validateCashAvailable($paymentAccount, $amount);
        [$grossAmount, $gstAmount, $baseAmount] = $this->normalizeGstComponent($amount, $gstAmount);
        $expenseAccountId = $this->getOrCreateExpenseAccount($categoryName);
        $gstInputAccount = $gstAmount > 0 ? $this->getOrCreateSystemAccount('GST-RCV', 'GST Input Credit', 'ASSET', 'GST Assets') : null;

        $lines = [];
        if ($baseAmount > 0) {
            $lines[] = ['account_id' => $expenseAccountId, 'amount' => $baseAmount, 'type' => 'DR', 'narration' => $narration];
        }
        if ($gstAmount > 0 && !empty($gstInputAccount['id'])) {
            $lines[] = ['account_id' => $gstInputAccount['id'], 'amount' => $gstAmount, 'type' => 'DR', 'narration' => "GST input for $categoryName"];
        }
        $lines[] = ['account_id' => $paymentAccount, 'amount' => $grossAmount, 'type' => 'CR', 'narration' => "Paid for $categoryName"];

        return $this->postJournalEntry('GENERAL_EXPENSE', $date, $narration, $lines);
    }

    public function categoryEntry($categoryAccountId, $direction, $amount, $date, $primaryAccountId, $narration, $gstAmount = 0) {
        $direction = strtolower((string) $direction);
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            throw new Exception("Amount must be greater than zero.");
        }

        $categoryAccount = $this->db->fetch(
            "SELECT * FROM accounts WHERE id = ? AND business_id = ? AND entity_type = 'GENERAL' AND is_active = 1",
            [$categoryAccountId, $this->businessId]
        );
        if (!$categoryAccount) {
            throw new Exception("Category account not found.");
        }

        $primaryAccount = $this->db->fetch(
            "SELECT * FROM accounts WHERE id = ? AND business_id = ? AND entity_type IN ('CASH','BANK') AND is_active = 1",
            [$primaryAccountId, $this->businessId]
        );
        if (!$primaryAccount) {
            throw new Exception("Cash or bank account is required.");
        }

        if ($direction === 'in') {
            if ($categoryAccount['group_name'] !== 'INCOME') {
                throw new Exception("Selected Jama category is not an income account.");
            }
            $lines = [
                ['account_id' => $primaryAccountId, 'amount' => $amount, 'type' => 'DR', 'narration' => $narration],
                ['account_id' => $categoryAccountId, 'amount' => $amount, 'type' => 'CR', 'narration' => $narration],
            ];
            return $this->postJournalEntry('JOURNAL_VOUCHER', $date, $narration, $lines, [
                'entry_type_id' => customEntryTypeId($categoryAccountId),
                'entry_amount' => $amount,
            ]);
        }

        if ($direction === 'out') {
            if ($categoryAccount['group_name'] !== 'EXPENSE') {
                throw new Exception("Selected Udhar category is not an expense account.");
            }
            $this->validateCashAvailable($primaryAccountId, $amount);
            [$grossAmount, $gstAmount, $baseAmount] = $this->normalizeGstComponent($amount, $gstAmount);
            $gstInputAccount = $gstAmount > 0 ? $this->getOrCreateSystemAccount('GST-RCV', 'GST Input Credit', 'ASSET', 'GST Assets') : null;

            $lines = [];
            if ($baseAmount > 0) {
                $lines[] = ['account_id' => $categoryAccountId, 'amount' => $baseAmount, 'type' => 'DR', 'narration' => $narration];
            }
            if ($gstAmount > 0 && !empty($gstInputAccount['id'])) {
                $lines[] = ['account_id' => $gstInputAccount['id'], 'amount' => $gstAmount, 'type' => 'DR', 'narration' => "GST input for {$categoryAccount['name']}"];
            }
            $lines[] = ['account_id' => $primaryAccountId, 'amount' => $grossAmount, 'type' => 'CR', 'narration' => $narration];

            return $this->postJournalEntry('GENERAL_EXPENSE', $date, $narration, $lines, [
                'entry_type_id' => customEntryTypeId($categoryAccountId),
                'entry_amount' => $baseAmount,
            ]);
        }

        throw new Exception("Invalid category direction.");
    }

    /**
     * PARTNER INVEST
     */
    public function partnerInvest($partnerId, $amount, $date, $receivingAccount, $narration) {
        $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ? AND business_id = ?", [$partnerId, $this->businessId]);
        if (!$partner) throw new Exception("Partner not found");
        if (($partner['partner_type'] ?? 'MAIN') !== 'MAIN') throw new Exception("Only main partners can add business capital.");

        $lines = [
            ['account_id' => $receivingAccount, 'amount' => $amount, 'type' => 'DR', 'narration' => "Received from {$partner['name']}"],
            ['account_id' => $partner['capital_account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => "Capital invested by {$partner['name']}"],
        ];

        return $this->postJournalEntry('PARTNER_INVEST', $date, $narration, $lines, ['partner_id' => $partnerId]);
    }

    /**
     * PARTNER WITHDRAW
     */
    public function partnerWithdraw($partnerId, $amount, $date, $paymentAccount, $narration) {
        $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ? AND business_id = ?", [$partnerId, $this->businessId]);
        if (!$partner) throw new Exception("Partner not found");
        if (($partner['partner_type'] ?? 'MAIN') !== 'MAIN') throw new Exception("Only main partners can withdraw business capital.");

        // RULE 5: Cannot withdraw more than available partner funds after commitments
        [$capitalAmount, $capitalType] = $this->getAccountBalanceRow($partner['capital_account_id']);
        [$currentAmount, $currentType] = $this->getAccountBalanceRow($partner['current_account_id']);
        $capitalBalance = max(0, $this->storedBalanceValue($capitalAmount, $capitalType, true));
        $currentBalance = max(0, $this->storedBalanceValue($currentAmount, $currentType, true));
        $committedFunding = $this->getCommittedPartnerFunding($partnerId);
        $pendingReceivable = $this->getPendingSettlementAmount($partnerId, 'RECEIVABLE');
        $availableBalance = max(0, $capitalBalance + $currentBalance - $committedFunding - $pendingReceivable);

        if ($amount > $availableBalance) {
            throw new Exception(
                "Withdrawal amount (" . formatAmount($amount) . ") exceeds available partner funds (" . formatAmount($availableBalance) . ")."
            );
        }

        $this->validateCashAvailable($paymentAccount, $amount);

        $lines = [
            ['account_id' => $partner['capital_account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Withdrawal by {$partner['name']}"],
            ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Paid to {$partner['name']}"],
        ];

        return $this->postJournalEntry('PARTNER_WITHDRAW', $date, $narration, $lines, ['partner_id' => $partnerId]);
    }

    public function partnerSettlement($partnerId, $amount, $date, $accountId, $direction, $narration) {
        $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ? AND business_id = ?", [$partnerId, $this->businessId]);
        if (!$partner) throw new Exception("Partner not found");
        if (($partner['partner_type'] ?? 'MAIN') !== 'MAIN') throw new Exception("Only main partners can use manual partner settlement entries.");
        if ($amount <= 0) throw new Exception("Settlement amount must be greater than zero.");

        $direction = strtoupper($direction);
        if (!in_array($direction, ['PAY', 'RECEIVE'], true)) {
            throw new Exception("Invalid settlement direction.");
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            if ($direction === 'PAY') {
            $pendingPayable = $this->getPendingSettlementAmount($partnerId, 'PAYABLE');
            if ($pendingPayable <= 0.009) {
                throw new Exception("This partner has no pending payable balance to settle.");
            }
            if ($amount - $pendingPayable > 0.01) {
                throw new Exception("Settlement amount cannot exceed pending payable balance of " . formatAmount($pendingPayable) . '.');
            }
            $this->validateCashAvailable($accountId, $amount);
            $lines = [
                ['account_id' => $partner['current_account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Settlement paid to {$partner['name']}"],
                ['account_id' => $accountId, 'amount' => $amount, 'type' => 'CR', 'narration' => "Settlement paid to {$partner['name']}"],
            ];
            $entryId = $this->postJournalEntry('PARTNER_SETTLEMENT', $date, $narration, $lines, ['partner_id' => $partnerId]);
            $this->applyPartnerSettlement($partnerId, $amount, 'PAYABLE', $entryId, $date);
            if ($ownsTransaction) $this->db->commit();
            return $entryId;
            }

        $pendingReceivable = $this->getPendingSettlementAmount($partnerId, 'RECEIVABLE');
        if ($pendingReceivable <= 0.009) {
            throw new Exception("This partner has no pending receivable balance to settle.");
        }
        if ($amount - $pendingReceivable > 0.01) {
            throw new Exception("Settlement amount cannot exceed pending receivable balance of " . formatAmount($pendingReceivable) . '.');
        }
        $lines = [
            ['account_id' => $accountId, 'amount' => $amount, 'type' => 'DR', 'narration' => "Settlement received from {$partner['name']}"],
            ['account_id' => $partner['current_account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => "Settlement received from {$partner['name']}"],
        ];
        $entryId = $this->postJournalEntry('PARTNER_SETTLEMENT', $date, $narration, $lines, ['partner_id' => $partnerId]);
        $this->applyPartnerSettlement($partnerId, $amount, 'RECEIVABLE', $entryId, $date);
            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * SALARY PAYMENT (with advance recovery)
     */
    public function salaryPayment($employeeId, $grossSalary, $advanceDeduction, $date, $paymentAccount, $month, $year) {
        $employee = $this->db->fetch("SELECT * FROM employees WHERE id = ? AND business_id = ?", [$employeeId, $this->businessId]);
        if (!$employee) throw new Exception("Employee not found");

        $grossSalary = round(floatval($grossSalary), 2);
        $advanceDeduction = round(floatval($advanceDeduction), 2);
        if ($grossSalary <= 0) {
            throw new Exception("Gross salary must be greater than zero.");
        }
        if ($advanceDeduction < 0) {
            throw new Exception("Advance deduction cannot be negative.");
        }
        if ($advanceDeduction - $grossSalary > 0.01) {
            throw new Exception("Advance deduction cannot exceed gross salary.");
        }
        $month = intval($month);
        $year = intval($year);
        if ($month < 1 || $month > 12 || $year < 2000 || $year > intval(date('Y')) + 1) {
            throw new Exception('Select a valid salary month and year.');
        }

        // RULE 6: Check duplicate salary
        $existing = $this->db->fetch(
            "SELECT id FROM salary_records WHERE business_id = ? AND employee_id = ? AND month = ? AND year = ?",
            [$this->businessId, $employeeId, $month, $year]
        );
        if ($existing) throw new Exception("Salary already processed for {$employee['name']} for $month/$year");

        if ($advanceDeduction > 0 && !empty($employee['advance_account_id'])) {
            [$advanceAmount, $advanceType] = $this->getAccountBalanceRow($employee['advance_account_id']);
            $advanceOutstanding = $this->naturalOutstandingValue($advanceAmount, $advanceType, 'DR');
            if ($advanceOutstanding <= 0.009) {
                throw new Exception("This employee has no advance outstanding to deduct.");
            }
            if ($advanceDeduction - $advanceOutstanding > 0.01) {
                throw new Exception("Advance deduction cannot exceed current advance outstanding of " . formatAmount($advanceOutstanding) . '.');
            }
        }

        $netPaid = $grossSalary - $advanceDeduction;
        $this->validateCashAvailable($paymentAccount, $netPaid);

        $salaryExpAccount = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'SAL-EXP'", [$this->businessId]);

        $lines = [
            ['account_id' => $salaryExpAccount['id'], 'amount' => $grossSalary, 'type' => 'DR', 'narration' => "Salary for {$employee['name']} - $month/$year"],
        ];

        if ($advanceDeduction > 0 && $employee['advance_account_id']) {
            $lines[] = ['account_id' => $employee['advance_account_id'], 'amount' => $advanceDeduction, 'type' => 'CR', 'narration' => "Advance recovered"];
        }

        $lines[] = ['account_id' => $paymentAccount, 'amount' => $netPaid, 'type' => 'CR', 'narration' => "Net salary paid"];

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $narration = "Salary payment to {$employee['name']} for $month/$year";
            $entryId = $this->postJournalEntry('SALARY_PAYMENT', $date, $narration, $lines, ['employee_id' => $employeeId]);

        // Record salary
        $this->db->insert('salary_records', [
            'id' => Database::uuid(),
            'employee_id' => $employeeId,
            'business_id' => $this->businessId,
            'month' => $month,
            'year' => $year,
            'gross_salary' => $grossSalary,
            'advance_deducted' => $advanceDeduction,
            'net_paid' => $netPaid,
            'payment_mode' => $this->getPaymentMode($paymentAccount),
            'journal_entry_id' => $entryId,
            'processed_date' => $date,
        ]);

            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * EMPLOYEE ADVANCE
     */
    public function employeeAdvance($employeeId, $amount, $date, $paymentAccount, $narration) {
        $employee = $this->db->fetch("SELECT * FROM employees WHERE id = ? AND business_id = ?", [$employeeId, $this->businessId]);
        if (!$employee) throw new Exception("Employee not found");

        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            throw new Exception("Advance amount must be greater than zero.");
        }

        $this->validateCashAvailable($paymentAccount, $amount);

        // Check advance limit
        $advanceBalance = abs(getAccountBalance($this->db, $employee['advance_account_id']));
        $limit = $employee['monthly_salary'] * ($this->getBusinessSetting('advance_limit_months') ?? 1);
        if (($advanceBalance + $amount) > $limit) {
            // Create alert but don't block
            $this->createAlert('ADVANCE_HIGH', "Employee {$employee['name']} advance (₹" . number_format($advanceBalance + $amount) . ") exceeds limit", 'employee', $employeeId);
        }

        $lines = [
            ['account_id' => $employee['advance_account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Advance to {$employee['name']}"],
            ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Advance paid"],
        ];

        return $this->postJournalEntry('EMPLOYEE_ADVANCE', $date, $narration, $lines, ['employee_id' => $employeeId]);
    }

    /**
     * EMPLOYEE ADVANCE WRITE-OFF
     */
    public function employeeAdvanceWriteOff($employeeId, $amount, $date, $narration) {
        $employee = $this->db->fetch(
            "SELECT e.*, a.current_balance, a.current_balance_type
             FROM employees e
             LEFT JOIN accounts a ON a.id = e.advance_account_id
             WHERE e.id = ? AND e.business_id = ?",
            [$employeeId, $this->businessId]
        );
        if (!$employee) throw new Exception("Employee not found");

        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            throw new Exception("Write-off amount must be greater than zero.");
        }

        $outstanding = $this->naturalOutstandingValue($employee['current_balance'], $employee['current_balance_type'], 'DR');
        if ($outstanding <= 0.009) {
            throw new Exception("This employee has no advance balance available for write-off.");
        }
        if ($amount - $outstanding > 0.01) {
            throw new Exception("Write-off amount cannot exceed current advance outstanding of " . formatAmount($outstanding) . '.');
        }

        $expenseAccount = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'ADV-WOFF'", [$this->businessId]);
        if (!$expenseAccount) {
            $expenseAccount = ['id' => $this->createAccount('ADV-WOFF', 'Employee Advance Write-Off Expense', 'EXPENSE', 'Indirect Expenses', 'GENERAL')];
        }

        $lines = [
            ['account_id' => $expenseAccount['id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Advance written off for {$employee['name']}"],
            ['account_id' => $employee['advance_account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => "Advance write-off for {$employee['name']}"],
        ];

        return $this->postJournalEntry('EMPLOYEE_ADVANCE_WRITEOFF', $date, $narration, $lines, ['employee_id' => $employeeId]);
    }

    /**
     * LOAN GIVEN
     */
    public function loanGiven($partyName, $amount, $date, $paymentAccount, $narration, $partyId = null, $partyPhone = null) {
        $this->validateCashAvailable($paymentAccount, $amount);
        $party = $this->resolveParty($partyId, $partyName, $partyPhone, 'DEBTOR', ['DEBTOR', 'BUYER']);
        $partyId = $party['id'];
        $partyName = $party['name'];

        $lines = [
            ['account_id' => $party['account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Loan given to $partyName"],
            ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Paid to $partyName"],
        ];

        return $this->postJournalEntry('LOAN_GIVEN', $date, $narration, $lines, ['party_id' => $partyId]);
    }

    /**
     * LOAN RECEIVED (money back from debtor)
     */
    public function loanReceived($partyId, $amount, $date, $receivingAccount, $narration, $carId = null) {
        $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$partyId, $this->businessId]);
        if (!$party) throw new Exception("Party not found");
        if (!in_array($party['type'], ['DEBTOR', 'BUYER'], true)) {
            throw new Exception("Money can be received back only from a debtor or buyer account.");
        }
        if ($carId) {
            $car = $this->db->fetch("SELECT id, registration_no, buyer_party_id FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
            if (!$car) {
                throw new Exception("Linked car not found.");
            }
            if (!empty($car['buyer_party_id']) && $car['buyer_party_id'] !== $partyId) {
                throw new Exception("Selected buyer does not match the linked car buyer.");
            }
        }

        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            throw new Exception("Received amount must be greater than zero.");
        }

        $openItems = $this->buildOutstandingItemsFromLedger($party['account_id'], 'DR');
        $outstanding = round(array_sum(array_column($openItems, 'outstanding_amount')), 2);
        if ($outstanding <= 0.009) {
            throw new Exception("This party has no debtor balance pending for recovery.");
        }
        if ($amount - $outstanding > 0.01) {
            throw new Exception("Received amount cannot exceed current debtor outstanding of " . formatAmount($outstanding) . '.');
        }

        $lines = [
            ['account_id' => $receivingAccount, 'amount' => $amount, 'type' => 'DR', 'narration' => $carId ? "Car payment clearing received from {$party['name']}" : "Received from {$party['name']}"],
            ['account_id' => $party['account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => $carId ? "Buyer payment cleared for {$party['name']}" : "Loan repaid by {$party['name']}"],
        ];

        $entryId = $this->postJournalEntry('LOAN_RECEIVED', $date, $narration ?: ($carId ? 'Car payment clearing - ' . ($car['registration_no'] ?? $party['name']) : $narration), $lines, [
            'party_id' => $partyId,
            'car_id' => $carId ?: null,
            'entry_type_id' => systemEntryTypeId($carId ? 'CAR_PAYMENT_CLEARING' : 'LOAN_RECEIVED'),
            'entry_amount' => $amount,
        ]);
        $this->refreshPendingCarSaleStatusesForParty($partyId);
        return $entryId;
    }

    /**
     * LOAN TAKEN (borrowed money)
     */
    public function loanTaken($partyName, $amount, $date, $receivingAccount, $narration, $partyId = null, $partyPhone = null) {
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            throw new Exception("Borrowed amount must be greater than zero.");
        }
        $party = $this->resolveParty($partyId, $partyName, $partyPhone, 'CREDITOR', ['CREDITOR', 'SELLER']);
        $partyId = $party['id'];
        $partyName = $party['name'];

        $lines = [
            ['account_id' => $receivingAccount, 'amount' => $amount, 'type' => 'DR', 'narration' => "Borrowed from $partyName"],
            ['account_id' => $party['account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => "Loan from $partyName"],
        ];

        return $this->postJournalEntry('LOAN_TAKEN', $date, $narration, $lines, ['party_id' => $partyId]);
    }

    /**
     * LOAN REPAID (paid back to creditor)
     */
    public function loanRepaid($partyId, $amount, $date, $paymentAccount, $narration, $carId = null) {
        $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$partyId, $this->businessId]);
        if (!$party) throw new Exception("Party not found");
        if (!in_array($party['type'], ['CREDITOR', 'SELLER'], true)) {
            throw new Exception("Loan repayment is allowed only against creditor or seller balances.");
        }
        $commissionOwnerBalance = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM commission_car_settlements
             WHERE business_id = ? AND owner_party_id = ? AND status IN ('PENDING','PARTIAL')",
            [$this->businessId, $partyId]
        );
        if (($commissionOwnerBalance['cnt'] ?? 0) > 0) {
            throw new Exception('This party has a commission car settlement. Open Commission Cars and use Pay Vehicle Owner so the per-car balance and history remain correct.');
        }
        if ($carId) {
            $car = $this->db->fetch("SELECT id, registration_no, seller_party_id FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
            if (!$car) {
                throw new Exception("Linked car not found.");
            }
            if (!empty($car['seller_party_id']) && $car['seller_party_id'] !== $partyId) {
                throw new Exception("Selected seller does not match the linked car seller.");
            }
        }

        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            throw new Exception("Repayment amount must be greater than zero.");
        }

        $openItems = $this->buildOutstandingItemsFromLedger($party['account_id'], 'CR');
        $outstanding = round(array_sum(array_column($openItems, 'outstanding_amount')), 2);
        if ($outstanding <= 0.009) {
            throw new Exception("This party has no creditor balance pending for repayment.");
        }
        if ($amount - $outstanding > 0.01) {
            throw new Exception("Repayment amount cannot exceed current creditor outstanding of " . formatAmount($outstanding) . '.');
        }

        $this->validateCashAvailable($paymentAccount, $amount);

        $lines = [
            ['account_id' => $party['account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => $carId ? "Seller payment cleared for {$party['name']}" : "Repaid to {$party['name']}"],
            ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => $carId ? "Seller payment clearing paid to {$party['name']}" : "Loan repaid to {$party['name']}"],
        ];

        return $this->postJournalEntry('LOAN_REPAID', $date, $narration ?: ($carId ? 'Seller payment clearing - ' . ($car['registration_no'] ?? $party['name']) : $narration), $lines, [
            'party_id' => $partyId,
            'car_id' => $carId ?: null,
            'entry_type_id' => systemEntryTypeId($carId ? 'SELLER_PAYMENT_CLEARING' : 'LOAN_REPAID'),
            'entry_amount' => $amount,
        ]);
    }

    /**
     * BAD DEBT WRITE-OFF
     */
    public function badDebtWriteOff($partyId, $amount, $date, $narration) {
        $party = $this->db->fetch(
            "SELECT dc.*, a.current_balance, a.current_balance_type
             FROM debtors_creditors dc
             LEFT JOIN accounts a ON a.id = dc.account_id
             WHERE dc.id = ? AND dc.business_id = ?",
            [$partyId, $this->businessId]
        );
        if (!$party) throw new Exception("Party not found");
        if (!in_array($party['type'], ['DEBTOR', 'BUYER'], true)) {
            throw new Exception("Bad debt write-off is allowed only for debtors or buyers.");
        }

        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            throw new Exception("Write-off amount must be greater than zero.");
        }

        $outstanding = $this->naturalOutstandingValue($party['current_balance'], $party['current_balance_type'], 'DR');
        if ($outstanding <= 0.009) {
            throw new Exception("This party has no debtor balance available for write-off.");
        }
        if ($amount - $outstanding > 0.01) {
            throw new Exception("Write-off amount cannot exceed current outstanding balance of " . formatAmount($outstanding) . '.');
        }

        $badDebtAccount = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'BAD-DEBT'", [$this->businessId]);
        if (!$badDebtAccount) {
            throw new Exception("Bad debt expense account is missing.");
        }

        $lines = [
            ['account_id' => $badDebtAccount['id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Bad debt expense for {$party['name']}"],
            ['account_id' => $party['account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => "Bad debt written off for {$party['name']}"],
        ];

        $entryId = $this->postJournalEntry('BAD_DEBT', $date, $narration, $lines, ['party_id' => $partyId]);
        $this->db->query("UPDATE debtors_creditors SET is_bad_debt = 1 WHERE id = ? AND business_id = ?", [$partyId, $this->businessId]);
        return $entryId;
    }

    /**
     * CONTRA TRANSFER (Cash to Bank or Bank to Cash)
     */
    public function contraTransfer($fromAccount, $toAccount, $amount, $date, $narration) {
        $this->validateCashAvailable($fromAccount, $amount);

        $lines = [
            ['account_id' => $toAccount, 'amount' => $amount, 'type' => 'DR', 'narration' => 'Transfer received'],
            ['account_id' => $fromAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => 'Transfer sent'],
        ];

        return $this->postJournalEntry('CONTRA_TRANSFER', $date, $narration, $lines);
    }

    /**
     * GST PAYMENT
     */
    public function gstPayment($amount, $date, $narration, $gstBankAccountId = null) {
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) throw new Exception("GST payment amount must be greater than zero.");

        $gstPayable = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'GST-PAY'", [$this->businessId]);
        if ($gstBankAccountId) {
            $gstBank = $this->db->fetch(
                "SELECT id FROM accounts WHERE business_id = ? AND id = ? AND entity_type = 'GST' AND is_active = 1",
                [$this->businessId, $gstBankAccountId]
            );
        } else {
            $gstBank = $this->db->fetch(
                "SELECT id FROM accounts WHERE business_id = ? AND entity_type = 'GST' AND entity_id IS NULL AND is_active = 1 ORDER BY code, name LIMIT 1",
                [$this->businessId]
            );
        }
        if (!$gstPayable || !$gstBank) throw new Exception("GST payable or GST bank account is missing.");

        $payableRow = $this->getAccountBalanceRow($gstPayable['id']);
        $payableOutstanding = $this->naturalOutstandingValue($payableRow[0], $payableRow[1], 'CR');
        if ($payableOutstanding <= 0.009) {
            throw new Exception("There is no GST payable balance available for payment.");
        }
        if ($amount - $payableOutstanding > 0.01) {
            throw new Exception("GST payment cannot exceed current GST payable balance of " . formatAmount($payableOutstanding) . '.');
        }

        $this->validateCashAvailable($gstBank['id'], $amount);

        $lines = [
            ['account_id' => $gstPayable['id'], 'amount' => $amount, 'type' => 'DR', 'narration' => 'GST liability paid'],
            ['account_id' => $gstBank['id'], 'amount' => $amount, 'type' => 'CR', 'narration' => 'Paid from GST Bank'],
        ];

        return $this->postJournalEntry('GST_PAYMENT', $date, $narration, $lines);
    }

    public function gstUtilization($amount, $date, $narration) {
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) throw new Exception("GST utilization amount must be greater than zero.");

        $gstPayable = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'GST-PAY'", [$this->businessId]);
        $gstInput = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'GST-RCV'", [$this->businessId]);
        if (!$gstPayable || !$gstInput) throw new Exception("GST payable or GST input account is missing.");

        $payableRow = $this->getAccountBalanceRow($gstPayable['id']);
        $inputRow = $this->getAccountBalanceRow($gstInput['id']);
        $availablePayable = $this->naturalOutstandingValue($payableRow[0], $payableRow[1], 'CR');
        $availableInput = $this->naturalOutstandingValue($inputRow[0], $inputRow[1], 'DR');
        $maxUtilization = min($availablePayable, $availableInput);

        if ($maxUtilization <= 0.009) {
            throw new Exception("There is no matching GST payable and GST input credit available to utilize.");
        }
        if ($amount - $maxUtilization > 0.01) {
            throw new Exception("GST utilization cannot exceed " . formatAmount($maxUtilization) . " based on current payable and input credit balances.");
        }

        $lines = [
            ['account_id' => $gstPayable['id'], 'amount' => $amount, 'type' => 'DR', 'narration' => 'GST payable adjusted against input credit'],
            ['account_id' => $gstInput['id'], 'amount' => $amount, 'type' => 'CR', 'narration' => 'GST input credit utilized'],
        ];

        return $this->postJournalEntry('GST_UTILIZATION', $date, $narration, $lines);
    }

    // ========================================
    // REVERSAL
    // ========================================
    public function entrySupportsLineCorrection($entry) {
        foreach (['car_id', 'partner_id', 'employee_id', 'party_id', 'journal_voucher_id'] as $field) {
            if (!empty($entry[$field])) {
                return false;
            }
        }

        return !in_array($entry['transaction_type'] ?? '', [
            'OPENING_BALANCE',
            'CAR_PURCHASE',
            'CAR_TOKEN_RECEIVED',
            'CAR_SALE',
            'CAR_EXPENSE',
            'RTO_EXPENSE',
            'RTO_RECOVERY',
            'PARTNER_INVEST',
            'PARTNER_WITHDRAW',
            'PARTNER_SETTLEMENT',
            'PROFIT_DISTRIBUTION',
            'SALARY_PAYMENT',
            'EMPLOYEE_ADVANCE',
            'EMPLOYEE_ADVANCE_WRITEOFF',
            'BAD_DEBT',
        ], true);
    }

    public function correctEntry($entryId, $date, $narration, $submittedLines, $reason) {
        $entry = $this->db->fetch(
            "SELECT * FROM journal_entries WHERE id = ? AND business_id = ?",
            [$entryId, $this->businessId]
        );
        if (!$entry) throw new Exception('Entry not found.');
        if ($entry['status'] !== 'POSTED' || !empty($entry['is_reversal'])) {
            throw new Exception('Only a posted original entry can be edited.');
        }

        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 5) {
            throw new Exception('Enter a clear correction reason of at least 5 characters.');
        }
        $date = trim((string) $date);
        $narration = trim((string) $narration);
        if ($narration === '') {
            throw new Exception('Narration is required.');
        }
        $this->validateDateNotLocked($entry['entry_date']);
        $this->validateDateNotLocked($date);

        $oldRows = $this->db->fetchAll(
            "SELECT jl.*, a.name AS account_name, a.code AS account_code, a.entity_type AS account_entity_type
             FROM journal_lines jl
             JOIN accounts a ON a.id = jl.account_id
             WHERE jl.journal_entry_id = ?
             ORDER BY jl.id",
            [$entryId]
        );
        if (count($oldRows) < 2) {
            throw new Exception('This entry does not contain a complete journal.');
        }
        foreach ($oldRows as $line) {
            $this->assertCorrectionAccountAccess($line['account_id'], $line['account_entity_type']);
        }

        $oldLines = array_map(function ($line) {
            return [
                'account_id' => $line['account_id'],
                'account' => trim(($line['account_code'] ?? '') . ' - ' . ($line['account_name'] ?? '')),
                'type' => $line['entry_type'],
                'amount' => round(floatval($line['amount']), 2),
                'narration' => trim((string) ($line['narration'] ?? '')),
            ];
        }, $oldRows);

        $canEditLines = $this->entrySupportsLineCorrection($entry);
        $newLines = $canEditLines
            ? $this->normalizeCorrectionLines((array) $submittedLines)
            : array_map(function ($line) {
                return [
                    'account_id' => $line['account_id'],
                    'type' => $line['entry_type'],
                    'amount' => round(floatval($line['amount']), 2),
                    'narration' => trim((string) ($line['narration'] ?? '')),
                ];
            }, $oldRows);

        $newLinesForAudit = $this->decorateCorrectionLines($newLines);
        $replacementEntryTypeId = $entry['entry_type_id'] ?: systemEntryTypeId($entry['transaction_type']);
        $replacementEntryAmount = round(floatval($entry['entry_amount'] ?? 0), 2);
        if ($canEditLines) {
            $replacementEntryAmount = round(array_sum(array_map(
                static fn($line) => ($line['type'] ?? '') === 'DR' ? floatval($line['amount'] ?? 0) : 0,
                $newLines
            )), 2);
            if (
                customEntryTypeAccountId($replacementEntryTypeId)
                || in_array($entry['transaction_type'], ['GENERAL_EXPENSE', 'JOURNAL_VOUCHER'], true)
            ) {
                $customIdentity = $this->resolveCustomEntryTypeFromLines($newLines);
                if ($customIdentity) {
                    $replacementEntryTypeId = customEntryTypeId($customIdentity['account_id']);
                    $replacementEntryAmount = $customIdentity['amount'];
                } else {
                    $replacementEntryTypeId = systemEntryTypeId($entry['transaction_type']);
                }
            }
        }
        $oldSnapshot = [
            'entry_date' => $entry['entry_date'],
            'narration' => $entry['narration'],
            'entry_type_id' => $entry['entry_type_id'] ?? null,
            'entry_amount' => $entry['entry_amount'] ?? 0,
            'lines' => $oldLines,
        ];
        $newSnapshot = [
            'entry_date' => $date,
            'narration' => $narration,
            'entry_type_id' => $replacementEntryTypeId,
            'entry_amount' => $replacementEntryAmount,
            'lines' => $newLinesForAudit,
            'correction_reason' => $reason,
        ];
        if ($this->correctionSnapshotsMatch($oldSnapshot, $newSnapshot)) {
            throw new Exception('Change the date, narration, or journal lines before saving.');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $reversalId = $this->createReversalEntry(
                $entry,
                $oldRows,
                'Correction: ' . $reason,
                $entry['entry_date']
            );
            $replacementId = Database::uuid();
            $referenceNo = getNextRefNo($this->db, $this->businessId, $date, 'COR');
            $replacement = [
                'id' => $replacementId,
                'business_id' => $this->businessId,
                'entry_date' => $date,
                'reference_no' => $referenceNo,
                'narration' => $narration,
                'transaction_type' => $entry['transaction_type'],
                'entry_type_id' => $replacementEntryTypeId,
                'entry_amount' => $replacementEntryAmount,
                'status' => 'POSTED',
                'car_id' => $entry['car_id'] ?: null,
                'partner_id' => $entry['partner_id'] ?: null,
                'employee_id' => $entry['employee_id'] ?: null,
                'party_id' => $entry['party_id'] ?: null,
                'journal_voucher_id' => $entry['journal_voucher_id'] ?: null,
                'corrected_from_id' => $entryId,
                'correction_reason' => $reason,
                'version_no' => max(1, intval($entry['version_no'] ?? 1)) + 1,
                'created_by' => $this->userId,
                'financial_year' => getCurrentFY($date),
            ];
            $this->db->insert('journal_entries', $replacement);

            foreach ($newLines as $line) {
                $this->db->insert('journal_lines', [
                    'id' => Database::uuid(),
                    'journal_entry_id' => $replacementId,
                    'account_id' => $line['account_id'],
                    'amount' => $line['amount'],
                    'entry_type' => $line['type'],
                    'narration' => $line['narration'] ?: null,
                ]);
                $this->updateAccountBalance($line['account_id'], $line['amount'], $line['type']);
            }

            $this->relinkCorrectedEntry($entry, $replacementId, $date, $narration);

            $this->db->query(
                "UPDATE journal_entries SET corrected_by_id = ?, correction_reason = ? WHERE id = ? AND business_id = ?",
                [$replacementId, $reason, $entryId, $this->businessId]
            );
            $newSnapshot['corrected_entry_id'] = $replacementId;
            $newSnapshot['reversal_entry_id'] = $reversalId;
            Auth::auditUpdate(
                'journal_entry',
                $entryId,
                $oldSnapshot,
                $newSnapshot,
                "Entry {$entry['reference_no']} corrected as {$referenceNo}: {$reason}",
                'transactions'
            );
            Auth::auditCreate(
                'journal_entry',
                $replacementId,
                array_merge($replacement, ['lines' => $newLinesForAudit]),
                "Corrected entry created from {$entry['reference_no']}",
                'transactions'
            );

            if ($ownsTransaction) $this->db->commit();
            return $replacementId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function normalizeCorrectionLines($lines) {
        $normalized = [];
        $totalDr = 0.0;
        $totalCr = 0.0;
        foreach ($lines as $line) {
            $accountId = trim((string) ($line['account_id'] ?? ''));
            $type = strtoupper(trim((string) ($line['type'] ?? '')));
            $amount = round(floatval($line['amount'] ?? 0), 2);
            if ($accountId === '' && $amount <= 0) continue;
            if (!in_array($type, ['DR', 'CR'], true)) throw new Exception('Each journal line needs a debit or credit type.');
            if ($amount <= 0) throw new Exception('Each journal line amount must be greater than zero.');
            $account = $this->db->fetch(
                "SELECT id, entity_type FROM accounts WHERE id = ? AND business_id = ? AND is_active = 1",
                [$accountId, $this->businessId]
            );
            if (!$account) throw new Exception('Select a valid active account for every journal line.');
            $this->assertCorrectionAccountAccess($account['id'], $account['entity_type']);
            $normalized[] = [
                'account_id' => $accountId,
                'type' => $type,
                'amount' => $amount,
                'narration' => trim((string) ($line['narration'] ?? '')),
            ];
            if ($type === 'DR') $totalDr += $amount;
            else $totalCr += $amount;
        }
        if (count($normalized) < 2) throw new Exception('At least two journal lines are required.');
        if (abs($totalDr - $totalCr) > 0.01) {
            throw new Exception('The corrected entry must balance. Debit and credit totals are different.');
        }
        return $normalized;
    }

    private function assertCorrectionAccountAccess($accountId, $entityType) {
        if (!class_exists('Auth') || !Auth::isLoggedIn() || Auth::isAdmin()) {
            return;
        }
        if (!in_array($entityType, array_values(PRIMARY_BOOK_ACCOUNT_TYPES), true)) {
            return;
        }
        if ($this->writablePrimaryAccountIds === null) {
            $this->writablePrimaryAccountIds = Auth::getAccessiblePrimaryAccountIds($this->businessId, 'write');
        }
        if (!in_array($accountId, $this->writablePrimaryAccountIds, true)) {
            throw new Exception('You do not have write access to every cash or bank book used by this entry.');
        }
    }

    private function relinkCorrectedEntry($entry, $replacementId, $date, $narration) {
        $entryId = $entry['id'];
        $this->db->query(
            "UPDATE accounts SET opening_entry_id = ?, opening_balance_date = ?
             WHERE business_id = ? AND opening_entry_id = ?",
            [$replacementId, $date, $this->businessId, $entryId]
        );
        $this->db->query(
            "UPDATE car_partner_contributions SET journal_entry_id = ?, contribution_date = ? WHERE journal_entry_id = ?",
            [$replacementId, $date, $entryId]
        );
        $this->db->query(
            "UPDATE salary_records SET journal_entry_id = ?, processed_date = ?
             WHERE business_id = ? AND journal_entry_id = ?",
            [$replacementId, $date, $this->businessId, $entryId]
        );
        $this->db->query(
            "UPDATE partner_profit_settlements SET journal_entry_id = ?, settlement_date = ?
             WHERE business_id = ? AND journal_entry_id = ?",
            [$replacementId, $date, $this->businessId, $entryId]
        );
        $this->db->query(
            "UPDATE partner_settlement_applications SET journal_entry_id = ?, applied_date = ?
             WHERE business_id = ? AND journal_entry_id = ?",
            [$replacementId, $date, $this->businessId, $entryId]
        );
        $this->db->query(
            "UPDATE rto_recoveries SET journal_entry_id = ?, received_date = ?, narration = ?
             WHERE business_id = ? AND journal_entry_id = ?",
            [$replacementId, $date, $narration, $this->businessId, $entryId]
        );
        $this->db->query(
            "UPDATE journal_vouchers SET posted_entry_id = ?, voucher_date = ?, narration = ?
             WHERE business_id = ? AND posted_entry_id = ?",
            [$replacementId, $date, $narration, $this->businessId, $entryId]
        );
        $this->db->query(
            "UPDATE attachments SET entity_id = ?
             WHERE business_id = ? AND entity_type = 'JOURNAL_ENTRY' AND entity_id = ?",
            [$replacementId, $this->businessId, $entryId]
        );

        if (!empty($entry['car_id']) && $entry['transaction_type'] === 'CAR_PURCHASE') {
            $this->db->query(
                "UPDATE cars SET purchase_date = ? WHERE id = ? AND business_id = ?",
                [$date, $entry['car_id'], $this->businessId]
            );
        }
        if (!empty($entry['car_id']) && $entry['transaction_type'] === 'CAR_SALE') {
            $this->db->query(
                "UPDATE cars SET sold_date = ? WHERE id = ? AND business_id = ?",
                [$date, $entry['car_id'], $this->businessId]
            );
        }
    }

    private function decorateCorrectionLines($lines) {
        $decorated = [];
        foreach ($lines as $line) {
            $account = $this->db->fetch(
                "SELECT code, name FROM accounts WHERE id = ? AND business_id = ?",
                [$line['account_id'], $this->businessId]
            );
            $decorated[] = [
                'account_id' => $line['account_id'],
                'account' => trim(($account['code'] ?? '') . ' - ' . ($account['name'] ?? '')),
                'type' => $line['type'],
                'amount' => round(floatval($line['amount']), 2),
                'narration' => trim((string) ($line['narration'] ?? '')),
            ];
        }
        return $decorated;
    }

    private function correctionSnapshotsMatch($old, $new) {
        $oldComparable = [
            'entry_date' => (string) ($old['entry_date'] ?? ''),
            'narration' => trim((string) ($old['narration'] ?? '')),
            'lines' => $old['lines'] ?? [],
        ];
        $newComparable = [
            'entry_date' => (string) ($new['entry_date'] ?? ''),
            'narration' => trim((string) ($new['narration'] ?? '')),
            'lines' => $new['lines'] ?? [],
        ];
        return json_encode($oldComparable) === json_encode($newComparable);
    }

    public function reverseEntry($entryId, $reason, $reversalDate = null) {
        $entry = $this->db->fetch("SELECT * FROM journal_entries WHERE id = ? AND business_id = ?", [$entryId, $this->businessId]);
        if (!$entry) throw new Exception("Entry not found");
        if ($entry['status'] === 'REVERSED') throw new Exception("Entry is already reversed");

        // RULE 7: Check period lock
        $this->validateDateNotLocked($entry['entry_date']);
        $reversalDate = $reversalDate ?: date('Y-m-d');
        $this->validateDateNotLocked($reversalDate);

        $lines = $this->db->fetchAll("SELECT * FROM journal_lines WHERE journal_entry_id = ?", [$entryId]);
        $this->assertEntryCanBeReversed($entry, $lines);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $reversalId = $this->createReversalEntry($entry, $lines, $reason, $reversalDate);
            $this->applyReversalBusinessEffects($entry, $lines, $reversalId);

            foreach ($this->getDependentEntriesForReversal($entry, $lines) as $dependentEntry) {
                $dependentLines = $this->db->fetchAll("SELECT * FROM journal_lines WHERE journal_entry_id = ?", [$dependentEntry['id']]);
                $this->assertEntryCanBeReversed($dependentEntry, $dependentLines, false);
                $linkedReason = "Linked reversal for {$entry['reference_no']}: {$reason}";
                $linkedReversalId = $this->createReversalEntry($dependentEntry, $dependentLines, $linkedReason);
                $this->applyReversalBusinessEffects($dependentEntry, $dependentLines, $linkedReversalId);
            }

            $reversedEntry = $this->db->fetch("SELECT * FROM journal_entries WHERE id = ? AND business_id = ?", [$entryId, $this->businessId]);
            Auth::auditLog('REVERSE', 'journal_entry', $entryId, "Reversed entry: $reason", $entry, $reversedEntry ?: ['status' => 'REVERSED', 'reversed_by' => $reversalId], 'transactions');
            if ($ownsTransaction) $this->db->commit();
            return $reversalId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function createReversalEntry($entry, $lines, $reason, $reversalDate = null) {
        $reversalLines = [];
        foreach ($lines as $line) {
            $reversalLines[] = [
                'account_id' => $line['account_id'],
                'amount' => $line['amount'],
                'type' => $line['entry_type'] === 'DR' ? 'CR' : 'DR',
                'narration' => 'Reversal: ' . ($line['narration'] ?? ''),
                'source_voucher_line_id' => $line['source_voucher_line_id'] ?? null,
            ];
        }

        $reversalId = Database::uuid();
        $reversalDate = $reversalDate ?: date('Y-m-d');
        $refNo = getNextRefNo($this->db, $this->businessId, $reversalDate, 'REV');
        $fy = getCurrentFY($reversalDate);

        $this->db->insert('journal_entries', [
            'id' => $reversalId,
            'business_id' => $this->businessId,
            'entry_date' => $reversalDate,
            'reference_no' => $refNo,
            'narration' => "REVERSAL: $reason (Original: {$entry['reference_no']})",
            'transaction_type' => 'REVERSAL',
            'entry_type_id' => systemEntryTypeId('REVERSAL'),
            'entry_amount' => round(floatval($entry['entry_amount'] ?? 0), 2),
            'is_reversal' => 1,
            'original_entry_id' => $entry['id'],
            'status' => 'POSTED',
            'car_id' => $entry['car_id'] ?: null,
            'partner_id' => $entry['partner_id'] ?: null,
            'employee_id' => $entry['employee_id'] ?: null,
            'party_id' => $entry['party_id'] ?: null,
            'journal_voucher_id' => $entry['journal_voucher_id'] ?: null,
            'created_by' => $this->userId,
            'financial_year' => $fy,
        ]);

        foreach ($reversalLines as $line) {
            $this->db->insert('journal_lines', [
                'id' => Database::uuid(),
                'journal_entry_id' => $reversalId,
                'account_id' => $line['account_id'],
                'amount' => $line['amount'],
                'entry_type' => $line['type'],
                'narration' => $line['narration'],
                'source_voucher_line_id' => $line['source_voucher_line_id'] ?? null,
            ]);
            $this->updateAccountBalance($line['account_id'], $line['amount'], $line['type']);
        }

        $this->db->query("UPDATE journal_entries SET status = 'REVERSED', reversed_by = ? WHERE id = ?", [$reversalId, $entry['id']]);
        return $reversalId;
    }

    private function resolveCustomEntryTypeFromLines(array $lines) {
        $accountIds = array_values(array_unique(array_filter(array_column($lines, 'account_id'))));
        if (!$accountIds) return null;
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $accounts = $this->db->fetchAll(
            "SELECT id, group_name
             FROM accounts
             WHERE business_id = ?
               AND id IN ($placeholders)
               AND entity_type = 'GENERAL'
               AND group_name IN ('INCOME','EXPENSE')
               AND sub_group IN ('Daily Jama Categories','Daily Udhar Categories')
               AND code NOT IN ('CAR-REV','SALE-COMM','PNL','GST-PAY','GST-RCV','BAD-DEBT','ADV-WOFF','SAL-EXP','OB-EQUITY')",
            array_merge([$this->businessId], $accountIds)
        );
        if (count($accounts) > 1) {
            throw new Exception('A simple entry can use only one custom income or expense type. Use Large Bill Split for multiple categories.');
        }
        if (!$accounts) return null;

        $account = $accounts[0];
        $naturalType = $account['group_name'] === 'INCOME' ? 'CR' : 'DR';
        $amount = 0.0;
        foreach ($lines as $line) {
            if (($line['account_id'] ?? '') === $account['id'] && ($line['type'] ?? '') === $naturalType) {
                $amount += floatval($line['amount'] ?? 0);
            }
        }
        return ['account_id' => $account['id'], 'amount' => round($amount, 2)];
    }

    private function assertEntryCanBeReversed($entry, $lines, $allowLinkedGuard = true) {
        if (!empty($entry['is_reversal']) || $entry['transaction_type'] === 'REVERSAL') {
            throw new Exception('A reversal entry is permanent correction history and cannot itself be reversed.');
        }

        if ($entry['transaction_type'] === 'CAR_TOKEN_RECEIVED') {
            $token = $this->db->fetch(
                "SELECT applied_amount FROM car_tokens WHERE business_id = ? AND journal_entry_id = ?",
                [$this->businessId, $entry['id']]
            );
            if (floatval($token['applied_amount'] ?? 0) > 0.009) {
                throw new Exception('This token is already adjusted against a car sale. Reverse the sale first, then reverse the token.');
            }
        }

        if ($entry['transaction_type'] === 'CAR_PURCHASE') {
            if (!$this->canArchiveCarPurchase($entry)) {
                throw new Exception("This car purchase already has downstream activity. Use a dedicated admin correction workflow instead of direct reversal.");
            }
        }

        if ($entry['transaction_type'] === 'PARTNER_SETTLEMENT') {
            $this->ensurePartnerSettlementApplicationTrail($entry['partner_id']);
            $applicationCount = $this->db->fetch(
                "SELECT COUNT(*) AS cnt
                 FROM partner_settlement_applications
                 WHERE business_id = ?
                   AND journal_entry_id = ?",
                [$this->businessId, $entry['id']]
            );
            if (($applicationCount['cnt'] ?? 0) <= 0 && !$this->canReverseLegacyPartnerSettlement($entry, $lines)) {
                throw new Exception("This legacy partner settlement cannot be auto-reversed safely because newer settlement activity exists. Use a controlled admin correction flow.");
            }
        }

        if ($entry['transaction_type'] === 'PROFIT_DISTRIBUTION') {
            $settled = $this->db->fetch(
                "SELECT COUNT(*) AS cnt
                 FROM partner_profit_settlements
                 WHERE business_id = ?
                   AND journal_entry_id = ?
                   AND status IN ('PARTIAL', 'SETTLED')
                   AND settled_amount > 0.009",
                [$this->businessId, $entry['id']]
            );
            if (($settled['cnt'] ?? 0) > 0) {
                throw new Exception("This partner profit entry already has settlement activity. Reverse those settlements before reversing the profit allocation.");
            }
        }

        if ($allowLinkedGuard && $entry['transaction_type'] === 'CAR_SALE' && $this->isPrimaryCarSaleEntry($entry, $lines)) {
            $commissionSettlement = $this->db->fetch(
                "SELECT paid_to_owner_amount FROM commission_car_settlements WHERE business_id = ? AND sale_entry_id = ? AND status <> 'REVERSED'",
                [$this->businessId, $entry['id']]
            );
            if (floatval($commissionSettlement['paid_to_owner_amount'] ?? 0) > 0.009) {
                throw new Exception('This commission sale already has owner payments. Reverse those owner payments first, then reverse the sale.');
            }
            $settled = $this->db->fetch(
                "SELECT COUNT(*) AS cnt
                 FROM partner_profit_settlements
                 WHERE business_id = ?
                   AND car_id = ?
                   AND status IN ('PARTIAL', 'SETTLED')
                   AND settled_amount > 0.009",
                [$this->businessId, $entry['car_id']]
            );
            if (($settled['cnt'] ?? 0) > 0) {
                throw new Exception("This car sale already has partner settlement activity. Reverse the partner settlements first, then reverse the sale.");
            }
        }
    }

    private function getDependentEntriesForReversal($entry, $lines) {
        if (empty($entry['car_id'])) {
            return [];
        }

        if ($entry['transaction_type'] === 'CAR_PURCHASE') {
            return $this->db->fetchAll(
                "SELECT *
                 FROM journal_entries
                 WHERE business_id = ?
                   AND car_id = ?
                   AND status = 'POSTED'
                   AND is_reversal = 0
                   AND id <> ?
                   AND transaction_type = 'PARTNER_INVEST'
                 ORDER BY created_at DESC, id DESC",
                [$this->businessId, $entry['car_id'], $entry['id']]
            );
        }

        if ($entry['transaction_type'] === 'CAR_SALE' && $this->isPrimaryCarSaleEntry($entry, $lines)) {
            return $this->db->fetchAll(
                "SELECT *
                 FROM journal_entries
                 WHERE business_id = ?
                   AND car_id = ?
                   AND status = 'POSTED'
                   AND is_reversal = 0
                   AND id <> ?
                   AND (
                       transaction_type = 'PROFIT_DISTRIBUTION'
                       OR (transaction_type = 'CAR_SALE' AND narration LIKE 'Close car account %')
                   )
                 ORDER BY FIELD(transaction_type, 'PROFIT_DISTRIBUTION', 'CAR_SALE'), created_at, id",
                [$this->businessId, $entry['car_id'], $entry['id']]
            );
        }

        if ($entry['transaction_type'] === 'CAR_EXPENSE' && $this->isPrimaryCarExpenseEntry($entry, $lines)) {
            $car = $this->db->fetch("SELECT account_id FROM cars WHERE id = ?", [$entry['car_id']]);
            if (!$car || empty($car['account_id'])) {
                return [];
            }

            $primaryTotal = $this->getEntryDebitTotal($lines);
            $candidates = $this->db->fetchAll(
                "SELECT *
                 FROM journal_entries
                 WHERE business_id = ?
                   AND car_id = ?
                   AND transaction_type = 'CAR_EXPENSE'
                   AND status = 'POSTED'
                   AND is_reversal = 0
                   AND id <> ?
                   AND entry_date = ?
                 ORDER BY created_at DESC, id DESC",
                [$this->businessId, $entry['car_id'], $entry['id'], $entry['entry_date']]
            );

            foreach ($candidates as $candidate) {
                $candidateLines = $this->db->fetchAll("SELECT * FROM journal_lines WHERE journal_entry_id = ?", [$candidate['id']]);
                if (!$this->isCarExpenseAllocationEntry($candidateLines, $car['account_id'])) {
                    continue;
                }
                if (abs($this->getEntryDebitTotal($candidateLines) - $primaryTotal) > 0.01) {
                    continue;
                }
                return [$candidate];
            }
        }

        return [];
    }

    private function applyReversalBusinessEffects($entry, $lines, $reversalId) {
        switch ($entry['transaction_type']) {
            case 'JOURNAL_VOUCHER':
                if (!empty($entry['journal_voucher_id'])) {
                    $voucher = $this->db->fetch(
                        "SELECT * FROM journal_vouchers WHERE id = ? AND business_id = ?",
                        [$entry['journal_voucher_id'], $this->businessId]
                    );
                    if ($voucher && $voucher['status'] !== 'REVERSED') {
                        $this->db->query(
                            "UPDATE journal_vouchers SET status = 'REVERSED' WHERE id = ? AND business_id = ?",
                            [$voucher['id'], $this->businessId]
                        );
                        Auth::auditUpdate(
                            'journal_voucher',
                            $voucher['id'],
                            ['status' => $voucher['status'], 'posted_entry_id' => $voucher['posted_entry_id']],
                            ['status' => 'REVERSED', 'posted_entry_id' => $voucher['posted_entry_id'], 'reversal_entry_id' => $reversalId],
                            "Large bill {$voucher['reference_no']} reversed",
                            'transactions'
                        );
                    }
                }
                break;

            case 'SALARY_PAYMENT':
                $this->db->query(
                    "DELETE FROM salary_records WHERE business_id = ? AND journal_entry_id = ?",
                    [$this->businessId, $entry['id']]
                );
                break;

            case 'PARTNER_INVEST':
                $this->reversePartnerContributionState($entry);
                break;

            case 'PARTNER_SETTLEMENT':
                $this->reversePartnerSettlementApplications($entry, $lines);
                break;

            case 'CAR_PURCHASE':
                $this->archiveCancelledCar($entry['car_id']);
                break;

            case 'CAR_TOKEN_RECEIVED':
                $token = $this->db->fetch(
                    "SELECT * FROM car_tokens WHERE business_id = ? AND journal_entry_id = ?",
                    [$this->businessId, $entry['id']]
                );
                if ($token) {
                    $this->db->query(
                        "UPDATE car_tokens SET status = 'REVERSED' WHERE id = ? AND business_id = ?",
                        [$token['id'], $this->businessId]
                    );
                    Auth::auditUpdate('car_token', $token['id'], $token, array_merge($token, ['status' => 'REVERSED']), 'Car token reversed', 'transactions');
                }
                break;

            case 'RTO_RECOVERY':
                $recovery = $this->db->fetch(
                    "SELECT rr.*, r.recovered_amount, r.last_recovery_entry_id
                     FROM rto_recoveries rr
                     JOIN rto_records r ON r.id = rr.rto_record_id AND r.business_id = rr.business_id
                     WHERE rr.business_id = ? AND rr.journal_entry_id = ?",
                    [$this->businessId, $entry['id']]
                );
                if ($recovery && floatval($recovery['amount']) > 0.009) {
                    $newRecovered = round(max(0, floatval($recovery['recovered_amount']) - floatval($recovery['amount'])), 2);
                    $this->db->query(
                        "UPDATE rto_recoveries SET amount = 0, narration = CONCAT(COALESCE(narration,''), ' | Reversed') WHERE id = ? AND business_id = ?",
                        [$recovery['id'], $this->businessId]
                    );
                    $latestRecovery = $this->db->fetch(
                        "SELECT journal_entry_id FROM rto_recoveries WHERE business_id = ? AND rto_record_id = ? AND amount > 0.009 ORDER BY created_at DESC LIMIT 1",
                        [$this->businessId, $recovery['rto_record_id']]
                    );
                    $this->db->query(
                        "UPDATE rto_records SET recovered_amount = ?, last_recovery_entry_id = ? WHERE id = ? AND business_id = ?",
                        [$newRecovered, $latestRecovery['journal_entry_id'] ?? null, $recovery['rto_record_id'], $this->businessId]
                    );
                    Auth::auditUpdate('rto_record', $recovery['rto_record_id'], [
                        'recovered_amount' => $recovery['recovered_amount'],
                        'last_recovery_entry_id' => $recovery['last_recovery_entry_id'],
                    ], [
                        'recovered_amount' => $newRecovered,
                        'last_recovery_entry_id' => $latestRecovery['journal_entry_id'] ?? null,
                    ], 'RTO recovery reversed', 'rto');
                }
                break;

            case 'RTO_EXPENSE':
                $rtoRecord = $this->db->fetch(
                    "SELECT * FROM rto_records WHERE business_id = ? AND expense_entry_id = ?",
                    [$this->businessId, $entry['id']]
                );
                if ($rtoRecord) {
                    $this->db->query(
                        "UPDATE rto_records SET expense_amount = 0, expense_entry_id = NULL WHERE id = ? AND business_id = ?",
                        [$rtoRecord['id'], $this->businessId]
                    );
                    Auth::auditUpdate('rto_record', $rtoRecord['id'], [
                        'expense_amount' => $rtoRecord['expense_amount'],
                        'expense_entry_id' => $rtoRecord['expense_entry_id'],
                    ], [
                        'expense_amount' => 0,
                        'expense_entry_id' => null,
                    ], 'RTO expense reversed', 'rto');
                }
                break;

            case 'PROFIT_DISTRIBUTION':
                $this->db->query(
                    "UPDATE partner_profit_settlements
                     SET status = 'REVERSED',
                         settled_amount = 0,
                         outstanding_amount = 0,
                         last_settlement_entry_id = ?,
                         narration = CONCAT(COALESCE(narration, ''), ' | Reversed by ', ?)
                     WHERE business_id = ?
                       AND journal_entry_id = ?",
                    [$reversalId, $reversalId, $this->businessId, $entry['id']]
                );
                break;

            case 'CAR_SALE':
                if ($this->isPrimaryCarSaleEntry($entry, $lines)) {
                    $appliedTokens = $this->db->fetchAll(
                        "SELECT * FROM car_tokens WHERE business_id = ? AND applied_sale_entry_id = ?",
                        [$this->businessId, $entry['id']]
                    );
                    foreach ($appliedTokens as $token) {
                        $this->db->query(
                            "UPDATE car_tokens SET applied_amount = 0, applied_sale_entry_id = NULL, status = 'OPEN' WHERE id = ? AND business_id = ?",
                            [$token['id'], $this->businessId]
                        );
                        Auth::auditUpdate('car_token', $token['id'], $token, array_merge($token, [
                            'applied_amount' => 0,
                            'applied_sale_entry_id' => null,
                            'status' => 'OPEN',
                        ]), 'Car sale reversed; token restored as available', 'transactions');
                    }
                    $this->db->query(
                        "UPDATE cars
                         SET status = 'IN_STOCK',
                             sold_date = NULL,
                             sale_price = NULL,
                             sale_commission_amount = 0,
                             sale_gst_amount = 0,
                             buyer_name = NULL,
                             buyer_party_id = NULL
                         WHERE id = ? AND business_id = ?",
                        [$entry['car_id'], $this->businessId]
                    );
                    $commissionSettlement = $this->db->fetch(
                        "SELECT * FROM commission_car_settlements WHERE business_id = ? AND sale_entry_id = ? AND status <> 'REVERSED'",
                        [$this->businessId, $entry['id']]
                    );
                    if ($commissionSettlement) {
                        $this->db->query(
                            "UPDATE commission_car_settlements SET status = 'REVERSED' WHERE id = ? AND business_id = ?",
                            [$commissionSettlement['id'], $this->businessId]
                        );
                        Auth::auditUpdate('commission_car_settlement', $commissionSettlement['id'], $commissionSettlement, array_merge($commissionSettlement, ['status' => 'REVERSED']), 'Commission car sale reversed', 'commission_cars');
                    }
                }
                break;

            case 'LOAN_REPAID':
                $ownerPayment = $this->db->fetch(
                    "SELECT cop.*, ccs.owner_amount, ccs.paid_to_owner_amount, ccs.status AS settlement_status
                     FROM commission_owner_payments cop
                     JOIN commission_car_settlements ccs ON ccs.id = cop.settlement_id
                     WHERE cop.business_id = ? AND cop.journal_entry_id = ?",
                    [$this->businessId, $entry['id']]
                );
                if ($ownerPayment) {
                    $newPaid = round(max(0, floatval($ownerPayment['paid_to_owner_amount']) - floatval($ownerPayment['amount'])), 2);
                    $newStatus = $newPaid <= 0.009 ? 'PENDING' : ($newPaid >= floatval($ownerPayment['owner_amount']) - 0.009 ? 'PAID' : 'PARTIAL');
                    $this->db->query(
                        "UPDATE commission_car_settlements SET paid_to_owner_amount = ?, status = ? WHERE id = ? AND business_id = ?",
                        [$newPaid, $newStatus, $ownerPayment['settlement_id'], $this->businessId]
                    );
                    Auth::auditUpdate('commission_car_settlement', $ownerPayment['settlement_id'], [
                        'paid_to_owner_amount' => $ownerPayment['paid_to_owner_amount'],
                        'status' => $ownerPayment['settlement_status'],
                    ], [
                        'paid_to_owner_amount' => $newPaid,
                        'status' => $newStatus,
                    ], 'Commission car owner payment reversed', 'commission_cars');
                }
                break;

            case 'BAD_DEBT':
                $this->refreshPartyBadDebtFlag($entry['party_id']);
                break;
        }
    }

    private function reversePartnerContributionState($entry) {
        if (empty($entry['car_id']) || empty($entry['partner_id'])) {
            return;
        }

        $contribution = $this->db->fetch(
            "SELECT id
             FROM car_partner_contributions
             WHERE journal_entry_id = ?
             LIMIT 1",
            [$entry['id']]
        );

        if ($contribution) {
            $this->db->query("DELETE FROM car_partner_contributions WHERE id = ?", [$contribution['id']]);
        }

        $remaining = $this->db->fetchAll(
            "SELECT amount, funding_pct, profit_share_pct
             FROM car_partner_contributions
             WHERE car_id = ? AND partner_id = ?
             ORDER BY contribution_date, created_at",
            [$entry['car_id'], $entry['partner_id']]
        );

        if (empty($remaining)) {
            $this->db->query("DELETE FROM car_partnerships WHERE car_id = ? AND partner_id = ?", [$entry['car_id'], $entry['partner_id']]);
            return;
        }

        $fundingAmount = 0.0;
        $fundingPct = 0.0;
        $profitShareWeighted = 0.0;
        foreach ($remaining as $row) {
            $amount = floatval($row['amount']);
            $fundingAmount += $amount;
            $fundingPct += floatval($row['funding_pct']);
            $profitShareWeighted += $amount * floatval($row['profit_share_pct']);
        }

        $profitSharePct = $fundingAmount > 0 ? round($profitShareWeighted / $fundingAmount, 4) : 0.0;
        $this->db->update('car_partnerships', [
            'funding_amount' => round($fundingAmount, 2),
            'funding_pct' => round($fundingPct, 4),
            'profit_share_pct' => $profitSharePct,
            'status' => 'ACTIVE',
        ], 'car_id = ? AND partner_id = ?', [$entry['car_id'], $entry['partner_id']]);
    }

    private function canArchiveCarPurchase($entry) {
        if (empty($entry['car_id'])) {
            return false;
        }

        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$entry['car_id'], $this->businessId]);
        if (!$car) {
            return false;
        }
        if (!in_array($car['status'], ['IN_STOCK', 'CANCELLED'], true)) {
            return false;
        }

        $disallowed = $this->db->fetch(
            "SELECT COUNT(*) AS cnt
             FROM journal_entries
             WHERE business_id = ?
               AND car_id = ?
               AND status = 'POSTED'
               AND is_reversal = 0
               AND id <> ?
               AND transaction_type NOT IN ('PARTNER_INVEST')",
            [$this->businessId, $entry['car_id'], $entry['id']]
        );

        return (($disallowed['cnt'] ?? 0) <= 0);
    }

    private function archiveCancelledCar($carId) {
        if (!$carId) {
            return;
        }

        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) {
            return;
        }

        $account = !empty($car['account_id'])
            ? $this->db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$car['account_id'], $this->businessId])
            : null;

        $originalReg = trim((string) ($car['registration_no'] ?? ''));
        $archivedReg = substr('VOID-' . preg_replace('/[^A-Z0-9]/', '', strtoupper($originalReg)) . '-' . strtoupper(substr(str_replace('-', '', $carId), 0, 4)), 0, 20);
        $notePrefix = "Purchase cancelled for original registration {$originalReg}.";
        $notes = trim($notePrefix . ' ' . trim((string) ($car['notes'] ?? '')));

        $this->db->query(
            "UPDATE cars
             SET status = 'CANCELLED',
                 registration_no = ?,
                 sold_date = NULL,
                 sale_price = NULL,
                 sale_commission_amount = 0,
                 sale_gst_amount = 0,
                 buyer_name = NULL,
                 notes = ?
             WHERE id = ? AND business_id = ?",
            [$archivedReg, $notes, $carId, $this->businessId]
        );

        if ($account) {
            $archivedCode = substr('VOID-' . preg_replace('/[^A-Z0-9]/', '', strtoupper($originalReg)) . '-' . strtoupper(substr(str_replace('-', '', $carId), 0, 4)), 0, 20);
            $archivedName = substr('Cancelled Car A/c - ' . $originalReg, 0, 200);
            $this->db->update('accounts', [
                'code' => $archivedCode,
                'name' => $archivedName,
                'entity_id' => null,
                'is_active' => 0,
            ], 'id = ?', [$account['id']]);
        }
    }

    private function isPrimaryCarSaleEntry($entry, $lines) {
        if ($entry['transaction_type'] !== 'CAR_SALE') {
            return false;
        }

        $narration = trim((string) ($entry['narration'] ?? ''));
        if (stripos($narration, 'Close car account ') === 0) {
            return false;
        }

        foreach ($lines as $line) {
            $lineNarration = trim((string) ($line['narration'] ?? ''));
            if ($line['entry_type'] === 'CR' && stripos($lineNarration, 'Car sale revenue') === 0) {
                return true;
            }
        }

        return true;
    }

    private function isPrimaryCarExpenseEntry($entry, $lines) {
        if ($entry['transaction_type'] !== 'CAR_EXPENSE') {
            return false;
        }

        $narration = trim((string) ($entry['narration'] ?? ''));
        return stripos($narration, 'Allocate ') !== 0;
    }

    private function isCarExpenseAllocationEntry($lines, $carAccountId) {
        foreach ($lines as $line) {
            if ($line['account_id'] === $carAccountId && $line['entry_type'] === 'DR') {
                return true;
            }
        }
        return false;
    }

    private function getEntryDebitTotal($lines) {
        $total = 0.0;
        foreach ($lines as $line) {
            if (($line['entry_type'] ?? '') === 'DR') {
                $total += floatval($line['amount'] ?? 0);
            }
        }
        return round($total, 2);
    }

    // ========================================
    // VALIDATION HELPERS
    // ========================================
    private function validateCashAvailable($accountId, $amount) {
        $account = $this->db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $this->businessId]);
        if (!$account) throw new Exception("Payment account not found");

        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            return;
        }

        $availableBalance = $this->storedBalanceValue($account['current_balance'], $account['current_balance_type'], false);

        if ($account['entity_type'] === 'CASH') {
            $business = $this->db->fetch("SELECT min_cash_balance FROM businesses WHERE id = ?", [$this->businessId]);
            $minBalance = floatval($business['min_cash_balance'] ?? 0);

            if (($availableBalance - $amount) < $minBalance) {
                throw new Exception("Insufficient cash balance. Current: " . formatAmount(max(0, $availableBalance)) . ", Required: " . formatAmount($amount) . ", Minimum: " . formatAmount($minBalance));
            }
            return;
        }

        if (in_array($account['entity_type'], ['BANK', 'GST'], true) && ($availableBalance - $amount) < -0.009) {
            $bookLabel = $account['entity_type'] === 'GST' ? 'GST bank' : 'bank';
            throw new Exception("Insufficient {$bookLabel} balance. Current: " . formatAmount(max(0, $availableBalance)) . ", Required: " . formatAmount($amount) . '.');
        }
    }

    private function validateDateNotLocked($date) {
        $business = $this->db->fetch("SELECT period_lock_date FROM businesses WHERE id = ?", [$this->businessId]);
        if ($business['period_lock_date'] && $date <= $business['period_lock_date']) {
            throw new Exception("Cannot modify entries on or before the period lock date ({$business['period_lock_date']}). Admin override required.");
        }
    }

    // ========================================
    // PARTY (DEBTOR/CREDITOR) MANAGEMENT
    // ========================================
    private function resolveParty($partyId, $name, $phone, $newType, array $allowedTypes) {
        $partyId = trim((string) $partyId);
        if ($partyId !== '') {
            $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
            $party = $this->db->fetch(
                "SELECT * FROM debtors_creditors
                 WHERE id = ? AND business_id = ? AND is_active = 1 AND type IN ($placeholders)",
                array_merge([$partyId, $this->businessId], $allowedTypes)
            );
            if (!$party) throw new Exception('Select a valid person or company.');
            return $party;
        }

        $name = trim((string) $name);
        if ($name === '') throw new Exception('Select an existing person/company or add a new one.');
        $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
        $matchingParty = $this->db->fetch(
            "SELECT * FROM debtors_creditors
             WHERE business_id = ? AND is_active = 1
               AND LOWER(TRIM(name)) = LOWER(?) AND type IN ($placeholders)
             LIMIT 1",
            array_merge([$this->businessId, $name], $allowedTypes)
        );
        if ($matchingParty) return $matchingParty;
        $createdId = $this->getOrCreateParty($name, $newType, $phone);
        return $this->db->fetch(
            "SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?",
            [$createdId, $this->businessId]
        );
    }

    public function getOrCreateParty($name, $type, $phone = null) {
        $name = trim((string) $name);
        if ($name === '') throw new Exception('Person or company name is required.');
        $phone = validatePhoneNumber($phone, 'Person/company phone number');
        $existing = $this->db->fetch(
            "SELECT id FROM debtors_creditors
             WHERE business_id = ? AND LOWER(TRIM(name)) = LOWER(?) AND type = ? AND is_active = 1",
            [$this->businessId, $name, $type]
        );
        if ($existing) return $existing['id'];

        $partyId = Database::uuid();
        $accountGroup = in_array($type, ['DEBTOR', 'BUYER']) ? 'ASSET' : 'LIABILITY';
        $subGroup = in_array($type, ['DEBTOR', 'BUYER']) ? 'Sundry Debtors' : 'Sundry Creditors';
        $entityType = in_array($type, ['DEBTOR', 'BUYER']) ? 'DEBTOR' : 'CREDITOR';
        $code = strtoupper(substr($type, 0, 3)) . '-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 8));
        $codeExists = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = ?", [$this->businessId, $code]);
        if ($codeExists) {
            $code .= '-' . strtoupper(substr(str_replace('-', '', $partyId), 0, 5));
        }

        $accountId = $this->createAccount($code, "$name ($type)", $accountGroup, $subGroup, $entityType, $partyId);

        $this->db->insert('debtors_creditors', [
            'id' => $partyId,
            'business_id' => $this->businessId,
            'name' => $name,
            'type' => $type,
            'phone' => $phone,
            'account_id' => $accountId,
        ]);

        if (class_exists('Auth') && Auth::isLoggedIn()) {
            $createdParty = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$partyId, $this->businessId]);
            Auth::auditCreate('party', $partyId, $createdParty ?: ['name' => $name, 'type' => $type], "Party created: $name", 'transactions');
        }

        return $partyId;
    }

    // ========================================
    // CAR HELPERS
    // ========================================
    public function getCarTotalCost($carId) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) return 0;
        $total = $this->db->fetch(
            "SELECT COALESCE(SUM(jl.amount), 0) AS total_cost
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ?
               AND jl.entry_type = 'DR'
               AND je.business_id = ?
               AND je.status = 'POSTED'
               AND je.is_reversal = 0",
            [$car['account_id'], $this->businessId]
        );
        return floatval($total['total_cost'] ?? 0);
    }

    public function syncCarPartyLinks($carId) {
        $car = $this->db->fetch(
            "SELECT * FROM cars WHERE id = ? AND business_id = ?",
            [$carId, $this->businessId]
        );
        if (!$car) {
            return;
        }

        if (empty($car['buyer_party_id']) && !empty($car['buyer_name'])) {
            $saleEntry = $this->db->fetch(
                "SELECT party_id
                 FROM journal_entries
                 WHERE business_id = ?
                   AND car_id = ?
                   AND transaction_type = 'CAR_SALE'
                   AND status = 'POSTED'
                   AND is_reversal = 0
                   AND party_id IS NOT NULL
                 ORDER BY entry_date DESC, created_at DESC
                 LIMIT 1",
                [$this->businessId, $carId]
            );

            $buyerPartyId = $saleEntry['party_id'] ?? null;
            if (!$buyerPartyId) {
                $buyer = $this->db->fetch(
                    "SELECT id
                     FROM debtors_creditors
                     WHERE business_id = ?
                       AND type IN ('BUYER', 'DEBTOR')
                       AND name = ?
                     ORDER BY created_at DESC
                     LIMIT 1",
                    [$this->businessId, $car['buyer_name']]
                );
                $buyerPartyId = $buyer['id'] ?? null;
            }

            if ($buyerPartyId) {
                $this->db->query(
                    "UPDATE cars SET buyer_party_id = ? WHERE id = ? AND business_id = ?",
                    [$buyerPartyId, $carId, $this->businessId]
                );
            }
        }

        if (empty($car['seller_party_id'])) {
            $seller = $this->db->fetch(
                "SELECT dc.id
                 FROM journal_entries je
                 JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.entry_type = 'CR'
                 JOIN debtors_creditors dc ON dc.account_id = jl.account_id
                 WHERE je.business_id = ?
                   AND je.car_id = ?
                   AND je.transaction_type = 'CAR_PURCHASE'
                   AND je.status = 'POSTED'
                   AND je.is_reversal = 0
                   AND dc.type IN ('SELLER', 'CREDITOR')
                 ORDER BY je.entry_date DESC, je.created_at DESC
                 LIMIT 1",
                [$this->businessId, $carId]
            );

            if (!empty($seller['id'])) {
                $this->db->query(
                    "UPDATE cars SET seller_party_id = ? WHERE id = ? AND business_id = ?",
                    [$seller['id'], $carId, $this->businessId]
                );
            }
        }
    }

    public function getCarProfitability($carId) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) return null;

        $totalCost = $this->getCarTotalCost($carId);
        $salePrice = $car['sale_price'] ?? 0;
        $saleCommissionAmount = floatval($car['sale_commission_amount'] ?? 0);
        $saleGstAmount = floatval($car['sale_gst_amount'] ?? 0);
        $netSalePrice = max(0, $salePrice - $saleGstAmount);
        $totalSaleRealisation = $salePrice + $saleCommissionAmount;
        $rtoRecovery = $this->db->fetch(
            "SELECT COALESCE(SUM(recovered_amount), 0) AS total FROM rto_records WHERE business_id = ? AND car_id = ? AND status <> 'CANCELLED'",
            [$this->businessId, $carId]
        );
        $rtoRecovered = floatval($rtoRecovery['total'] ?? 0);
        $netBusinessRevenue = $netSalePrice + $saleCommissionAmount + $rtoRecovered;
        $profit = $netBusinessRevenue - $totalCost;
        $partnerships = $this->getCarPartnerships($carId);
        $settlements = $this->db->fetchAll(
            "SELECT pps.*, p.name as partner_name
             FROM partner_profit_settlements pps
             JOIN partners p ON p.id = pps.partner_id
             WHERE pps.business_id = ? AND pps.car_id = ?
             ORDER BY pps.created_at",
            [$this->businessId, $carId]
        );

        return [
            'car' => $car,
            'purchase_price' => $car['purchase_price'],
            'total_expenses' => $totalCost - $car['purchase_price'],
            'total_cost' => $totalCost,
            'sale_price' => $salePrice,
            'sale_commission_amount' => $saleCommissionAmount,
            'sale_gst_amount' => $saleGstAmount,
            'net_sale_price' => $netSalePrice,
            'total_sale_realisation' => $totalSaleRealisation,
            'rto_recovered' => $rtoRecovered,
            'net_business_revenue' => $netBusinessRevenue,
            'profit' => $profit,
            'status' => $car['status'],
            'holding_days' => $car['sold_date'] ? max(0, (int) floor((strtotime($car['sold_date']) - strtotime($car['purchase_date'])) / 86400)) : max(0, (int) floor((time() - strtotime($car['purchase_date'])) / 86400)),
            'partnerships' => $partnerships,
            'settlements' => $settlements,
        ];
    }

    public function saveJournalVoucher($date, $narration, $primaryAccountId, $primaryEntryType, $primaryAmount, $allocations, $voucherType = 'GENERAL_JV', $status = 'DRAFT') {
        $primaryEntryType = strtoupper($primaryEntryType);
        $status = strtoupper($status);
        $primaryAmount = round(floatval($primaryAmount), 2);
        if (!in_array($primaryEntryType, ['DR', 'CR'], true)) {
            throw new Exception("Primary side must be debit or credit.");
        }
        if ($primaryAmount <= 0) {
            throw new Exception("Primary amount must be greater than zero.");
        }
        if (!in_array($status, ['DRAFT', 'POSTED'], true)) {
            throw new Exception('Voucher status must be draft or posted.');
        }

        $primaryAccount = $this->db->fetch(
            "SELECT id, name FROM accounts WHERE id = ? AND business_id = ? AND is_active = 1",
            [$primaryAccountId, $this->businessId]
        );
        if (!$primaryAccount) {
            throw new Exception('The selected main payment or receipt account is not available.');
        }

        $allocations = $this->normalizeVoucherAllocations($allocations, $primaryEntryType);
        foreach ($allocations as $allocation) {
            if ($allocation['account_id'] === $primaryAccountId) {
                throw new Exception('A split line cannot use the same account as the main payment or receipt account.');
            }
        }
        $allocatedTotal = round(array_sum(array_column($allocations, 'amount')), 2);
        if (abs($primaryAmount - $allocatedTotal) > 0.01) {
            throw new Exception("Voucher is not balanced yet. Primary amount and allocations must match.");
        }

        $voucherId = Database::uuid();
        $referenceNo = $this->getNextVoucherRefNo($date);
        $fy = getCurrentFY($date);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $savedAllocations = [];
            $voucherRecord = [
                'id' => $voucherId,
                'business_id' => $this->businessId,
                'voucher_date' => $date,
                'reference_no' => $referenceNo,
                'voucher_type' => $voucherType,
                'narration' => $narration,
                'status' => $status === 'POSTED' ? 'DRAFT' : 'DRAFT',
                'primary_account_id' => $primaryAccountId,
                'primary_entry_type' => $primaryEntryType,
                'primary_amount' => $primaryAmount,
                'created_by' => $this->userId,
                'financial_year' => $fy,
            ];
            $this->db->insert('journal_vouchers', $voucherRecord);

            foreach ($allocations as $allocation) {
                $voucherLineId = Database::uuid();
                $this->db->insert('journal_voucher_lines', [
                    'id' => $voucherLineId,
                    'journal_voucher_id' => $voucherId,
                    'account_id' => $allocation['account_id'],
                    'amount' => $allocation['amount'],
                    'entry_type' => $allocation['entry_type'],
                    'narration' => $allocation['narration'] ?? null,
                    'entity_type' => $allocation['entity_type'] ?? null,
                    'entity_id' => $allocation['entity_id'] ?? null,
                ]);
                $allocation['id'] = $voucherLineId;
                $savedAllocations[] = $allocation;
            }

            Auth::auditCreate(
                'journal_voucher',
                $voucherId,
                array_merge($voucherRecord, ['allocations' => $savedAllocations ?? []]),
                "Large bill {$referenceNo} created with " . count($allocations) . ' allocation line(s)',
                'transactions'
            );

            if ($status === 'POSTED') {
                $this->postJournalVoucher($voucherId);
            }

            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        return $voucherId;
    }

    public function postJournalVoucher($voucherId) {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $voucher = $this->db->fetch(
                "SELECT * FROM journal_vouchers WHERE id = ? AND business_id = ? FOR UPDATE",
                [$voucherId, $this->businessId]
            );
            if (!$voucher) throw new Exception("Journal voucher not found.");
            if ($voucher['status'] === 'POSTED' && !empty($voucher['posted_entry_id'])) {
                if ($ownsTransaction) $this->db->commit();
                return $voucher['posted_entry_id'];
            }

            $lines = [[
                'account_id' => $voucher['primary_account_id'],
                'amount' => floatval($voucher['primary_amount']),
                'type' => $voucher['primary_entry_type'],
                'narration' => $voucher['narration'],
            ]];

            $allocationLines = $this->db->fetchAll(
                "SELECT * FROM journal_voucher_lines WHERE journal_voucher_id = ? ORDER BY id",
                [$voucherId]
            );
            foreach ($allocationLines as $line) {
                $lines[] = [
                    'account_id' => $line['account_id'],
                    'amount' => floatval($line['amount']),
                    'type' => $line['entry_type'],
                    'narration' => $line['narration'],
                    'source_voucher_line_id' => $line['id'],
                ];
            }

            $entryId = $this->postJournalEntry(
                'JOURNAL_VOUCHER',
                $voucher['voucher_date'],
                $voucher['narration'],
                $lines,
                ['reference_prefix' => 'JV', 'journal_voucher_id' => $voucherId]
            );

            $this->db->update('journal_vouchers', [
                'status' => 'POSTED',
                'posted_entry_id' => $entryId,
            ], 'id = ? AND business_id = ?', [$voucherId, $this->businessId]);

            Auth::auditUpdate(
                'journal_voucher',
                $voucherId,
                ['status' => $voucher['status'], 'posted_entry_id' => $voucher['posted_entry_id']],
                ['status' => 'POSTED', 'posted_entry_id' => $entryId],
                "Large bill {$voucher['reference_no']} posted as journal entry",
                'transactions'
            );

            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function getCarPartnerships($carId) {
        return $this->db->fetchAll(
            "SELECT cp.*, p.name as partner_name, p.current_account_id, p.capital_account_id
             FROM car_partnerships cp
             JOIN partners p ON p.id = cp.partner_id
             WHERE cp.business_id = ? AND cp.car_id = ? AND cp.status = 'ACTIVE'
             ORDER BY cp.created_at",
            [$this->businessId, $carId]
        );
    }

    public function correctCarPartnerFunding($carId, $partnerFunding, $date, $reason) {
        $car = $this->db->fetch(
            "SELECT * FROM cars WHERE id = ? AND business_id = ?",
            [$carId, $this->businessId]
        );
        if (!$car) throw new Exception('Car not found.');
        if ($car['status'] !== 'IN_STOCK') {
            throw new Exception('Partner funding can only be corrected while the car is in stock.');
        }

        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 5) {
            throw new Exception('Enter a clear reason for changing the partner funding.');
        }
        $date = trim((string) $date);
        $this->validateDateNotLocked($date);

        $oldTerms = $this->db->fetchAll(
            "SELECT cp.*, p.name AS partner_name
             FROM car_partnerships cp
             JOIN partners p ON p.id = cp.partner_id
             WHERE cp.business_id = ? AND cp.car_id = ? AND cp.status = 'ACTIVE'
             ORDER BY cp.created_at, cp.id",
            [$this->businessId, $carId]
        );
        $oldContributions = $this->db->fetchAll(
            "SELECT cpc.*, je.reference_no, je.entry_date, je.narration
             FROM car_partner_contributions cpc
             JOIN journal_entries je ON je.id = cpc.journal_entry_id
             WHERE cpc.car_id = ?
               AND je.business_id = ?
               AND je.status = 'POSTED'
               AND je.transaction_type = 'PARTNER_INVEST'
             ORDER BY cpc.contribution_date, cpc.created_at, cpc.id",
            [$carId, $this->businessId]
        );

        $oldFundingTotal = round(array_sum(array_map(static fn($row) => floatval($row['amount']), $oldContributions)), 2);
        $normalized = $this->normalizePartnerFunding(max(0.01, floatval($car['purchase_price'])), $partnerFunding);
        $newFundingTotal = round(array_sum(array_map(static fn($row) => floatval($row['amount']), $normalized)), 2);
        if (abs($oldFundingTotal - $newFundingTotal) > 0.01) {
            throw new Exception(
                'The total partner funding must remain ' . formatAmount($oldFundingTotal)
                . '. Reallocate it between partners; use Partner Added Money or Partner Took Money for separate capital movements.'
            );
        }

        $oldComparable = array_map(static function ($row) {
            return [
                'partner_id' => $row['partner_id'],
                'partner' => $row['partner_name'],
                'funding_amount' => round(floatval($row['funding_amount']), 2),
                'funding_pct' => round(floatval($row['funding_pct']), 4),
                'profit_share_pct' => round(floatval($row['profit_share_pct']), 4),
            ];
        }, $oldTerms);

        $debitAccounts = $this->db->fetchAll(
            "SELECT jl.account_id, SUM(jl.amount) AS amount
             FROM car_partner_contributions cpc
             JOIN journal_entries je ON je.id = cpc.journal_entry_id
             JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.entry_type = 'DR'
             WHERE cpc.car_id = ?
               AND je.business_id = ?
               AND je.status = 'POSTED'
               AND je.transaction_type = 'PARTNER_INVEST'
             GROUP BY jl.account_id
             ORDER BY jl.account_id",
            [$carId, $this->businessId]
        );
        $debitTotal = round(array_sum(array_map(static fn($row) => floatval($row['amount']), $debitAccounts)), 2);
        if (abs($debitTotal - $oldFundingTotal) > 0.01) {
            throw new Exception('The existing partner contribution journal is inconsistent. Correct the journal before changing partner terms.');
        }

        $newComparable = [];
        foreach ($normalized as $row) {
            $partner = $this->db->fetch(
                "SELECT id, name, capital_account_id FROM partners WHERE id = ? AND business_id = ? AND is_active = 1",
                [$row['partner_id'], $this->businessId]
            );
            if (!$partner || empty($partner['capital_account_id'])) {
                throw new Exception('Every selected partner must be active and have a capital account.');
            }
            $row['partner_name'] = $partner['name'];
            $row['capital_account_id'] = $partner['capital_account_id'];
            $newComparable[] = $row;
        }

        $oldFingerprint = [];
        $oldFundingFingerprint = [];
        foreach ($oldComparable as $row) {
            $oldFingerprint[$row['partner_id']] = [$row['funding_amount'], $row['profit_share_pct']];
            $oldFundingFingerprint[$row['partner_id']] = $row['funding_amount'];
        }
        $newFingerprint = [];
        $newFundingFingerprint = [];
        foreach ($newComparable as $row) {
            $newFingerprint[$row['partner_id']] = [$row['amount'], $row['profit_share_pct']];
            $newFundingFingerprint[$row['partner_id']] = $row['amount'];
        }
        ksort($oldFingerprint);
        ksort($newFingerprint);
        ksort($oldFundingFingerprint);
        ksort($newFundingFingerprint);
        if (json_encode($oldFingerprint) === json_encode($newFingerprint)) {
            throw new Exception('Change a partner, funding amount, or profit share before saving.');
        }
        $fundingChanged = json_encode($oldFundingFingerprint) !== json_encode($newFundingFingerprint);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            foreach ($fundingChanged ? $oldContributions : [] as $contribution) {
                $entry = $this->db->fetch(
                    "SELECT * FROM journal_entries WHERE id = ? AND business_id = ?",
                    [$contribution['journal_entry_id'], $this->businessId]
                );
                if (!$entry || $entry['status'] !== 'POSTED') {
                    throw new Exception('A partner contribution changed while this correction was being prepared. Reload and try again.');
                }
                $lines = $this->db->fetchAll("SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id", [$entry['id']]);
                $this->assertEntryCanBeReversed($entry, $lines);
                $reversalId = $this->createReversalEntry($entry, $lines, 'Partner funding correction: ' . $reason, $date);
                $this->applyReversalBusinessEffects($entry, $lines, $reversalId);
                $this->db->query("UPDATE journal_entries SET correction_reason = ? WHERE id = ?", [$reason, $entry['id']]);
                $reversedEntry = $this->db->fetch("SELECT * FROM journal_entries WHERE id = ?", [$entry['id']]);
                Auth::auditLog(
                    'REVERSE',
                    'journal_entry',
                    $entry['id'],
                    "Partner funding corrected for {$car['registration_no']}: $reason",
                    $entry,
                    $reversedEntry ?: ['status' => 'REVERSED', 'reversed_by' => $reversalId],
                    'cars'
                );
            }

            $this->db->query("DELETE FROM car_partnerships WHERE business_id = ? AND car_id = ?", [$this->businessId, $carId]);

            if ($fundingChanged) {
                $fundedRows = array_values(array_filter($newComparable, static fn($row) => floatval($row['amount']) > 0.009));
                $allocatedByAccount = array_fill_keys(array_column($debitAccounts, 'account_id'), 0.0);
                foreach ($fundedRows as $index => $row) {
                    $isLast = $index === count($fundedRows) - 1;
                    $lines = [];
                    $rowDebitTotal = 0.0;
                    foreach ($debitAccounts as $debitAccount) {
                        $accountId = $debitAccount['account_id'];
                        $accountTotal = round(floatval($debitAccount['amount']), 2);
                        $allocation = $isLast
                            ? round($accountTotal - $allocatedByAccount[$accountId], 2)
                            : round($accountTotal * (floatval($row['amount']) / max(0.01, $oldFundingTotal)), 2);
                        if ($allocation <= 0) continue;
                        $allocatedByAccount[$accountId] += $allocation;
                        $rowDebitTotal += $allocation;
                        $lines[] = [
                            'account_id' => $accountId,
                            'amount' => $allocation,
                            'type' => 'DR',
                            'narration' => "Corrected partner funding for {$car['registration_no']}",
                        ];
                    }
                    $difference = round(floatval($row['amount']) - $rowDebitTotal, 2);
                    if (abs($difference) > 0.001 && !empty($lines)) {
                        $lines[0]['amount'] = round($lines[0]['amount'] + $difference, 2);
                        $allocatedByAccount[$lines[0]['account_id']] += $difference;
                    }
                    $lines[] = [
                        'account_id' => $row['capital_account_id'],
                        'amount' => round(floatval($row['amount']), 2),
                        'type' => 'CR',
                        'narration' => "Investment in car {$car['registration_no']}",
                    ];
                    $entryId = $this->postJournalEntry(
                        'PARTNER_INVEST',
                        $date,
                        "Corrected partner funding: {$row['partner_name']} in {$car['registration_no']}",
                        $lines,
                        ['car_id' => $carId, 'partner_id' => $row['partner_id'], 'reference_prefix' => 'COR']
                    );
                    $this->db->insert('car_partner_contributions', [
                        'id' => Database::uuid(),
                        'car_id' => $carId,
                        'partner_id' => $row['partner_id'],
                        'amount' => round(floatval($row['amount']), 2),
                        'funding_pct' => round(floatval($row['funding_pct']), 4),
                        'profit_share_pct' => round(floatval($row['profit_share_pct']), 4),
                        'contribution_date' => $date,
                        'journal_entry_id' => $entryId,
                    ]);
                }
            } else {
                foreach ($newComparable as $row) {
                    $this->db->query(
                        "UPDATE car_partner_contributions
                         SET funding_pct = ?, profit_share_pct = ?
                         WHERE car_id = ? AND partner_id = ?",
                        [round(floatval($row['funding_pct']), 4), round(floatval($row['profit_share_pct']), 4), $carId, $row['partner_id']]
                    );
                }
            }

            foreach ($newComparable as $row) {
                $this->db->insert('car_partnerships', [
                    'id' => Database::uuid(),
                    'business_id' => $this->businessId,
                    'car_id' => $carId,
                    'partner_id' => $row['partner_id'],
                    'funding_amount' => round(floatval($row['amount']), 2),
                    'funding_pct' => round(floatval($row['funding_pct']), 4),
                    'profit_share_pct' => round(floatval($row['profit_share_pct']), 4),
                    'status' => 'ACTIVE',
                    'created_by' => $this->userId,
                    'notes' => 'Corrected: ' . $reason,
                ]);
            }

            $newAuditTerms = array_map(static function ($row) {
                return [
                    'partner_id' => $row['partner_id'],
                    'partner' => $row['partner_name'],
                    'funding_amount' => round(floatval($row['amount']), 2),
                    'funding_pct' => round(floatval($row['funding_pct']), 4),
                    'profit_share_pct' => round(floatval($row['profit_share_pct']), 4),
                ];
            }, $newComparable);
            $formatTermsForAudit = static function ($rows) {
                if (empty($rows)) return 'No partner funding';
                return implode('; ', array_map(static function ($row) {
                    return ($row['partner'] ?? 'Partner')
                        . ': ' . formatAmount($row['funding_amount'] ?? 0) . ' invested'
                        . ', ' . formatPlainNumber($row['profit_share_pct'] ?? 0) . '% profit share';
                }, $rows));
            };
            Auth::auditUpdate(
                'car',
                $carId,
                [
                    'partner_funding_terms' => $formatTermsForAudit($oldComparable),
                    'total_partner_funding' => $oldFundingTotal,
                ],
                [
                    'partner_funding_terms' => $formatTermsForAudit($newAuditTerms),
                    'total_partner_funding' => $newFundingTotal,
                    'correction_date' => $date,
                    'reason' => $reason,
                ],
                "Partner funding corrected for {$car['registration_no']}: $reason",
                'cars'
            );
            if ($ownsTransaction) $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getPartnerPosition($partnerId) {
        $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ? AND business_id = ?", [$partnerId, $this->businessId]);
        if (!$partner) {
            return null;
        }

        [$capitalAmount, $capitalType] = $this->getAccountBalanceRow($partner['capital_account_id']);
        [$currentAmount, $currentType] = $this->getAccountBalanceRow($partner['current_account_id']);

        return [
            'capital_balance' => $this->storedBalanceValue($capitalAmount, $capitalType, true),
            'current_balance' => $this->storedBalanceValue($currentAmount, $currentType, true),
            'committed_funding' => $this->getCommittedPartnerFunding($partnerId),
            'pending_payable' => $this->getPendingSettlementAmount($partnerId, 'PAYABLE'),
            'pending_receivable' => $this->getPendingSettlementAmount($partnerId, 'RECEIVABLE'),
        ];
    }

    // ========================================
    // REPORT HELPERS
    // ========================================
    public function getTrialBalance($asOnDate = null) {
        $asOnDate = $asOnDate ?: date('Y-m-d');
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.code, a.name, a.group_name, a.sub_group, a.entity_type,
                    a.opening_balance, a.opening_balance_type, a.opening_entry_id,
                    COALESCE(SUM(CASE WHEN je.status IN ('POSTED','REVERSED') AND je.entry_date <= ? AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) as posted_dr,
                    COALESCE(SUM(CASE WHEN je.status IN ('POSTED','REVERSED') AND je.entry_date <= ? AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) as posted_cr
             FROM accounts a
             LEFT JOIN journal_lines jl ON jl.account_id = a.id
             LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE a.business_id = ? AND a.is_active = 1
             GROUP BY a.id
             ORDER BY a.group_name, a.sub_group, a.code",
            [$asOnDate, $asOnDate, $this->businessId]
        );

        $accounts = [];
        foreach ($rows as $row) {
            // Journal-backed openings already appear in posted_dr/posted_cr. Only
            // add the stored amount for legacy accounts without an opening entry.
            $legacyOpening = empty($row['opening_entry_id']) ? floatval($row['opening_balance']) : 0.0;
            $openingDr = strtoupper($row['opening_balance_type']) === 'DR' ? $legacyOpening : 0.0;
            $openingCr = strtoupper($row['opening_balance_type']) === 'CR' ? $legacyOpening : 0.0;
            $totalDr = $openingDr + floatval($row['posted_dr']);
            $totalCr = $openingCr + floatval($row['posted_cr']);
            $net = $totalDr - $totalCr;

            if (abs($net) < 0.005 && abs($totalDr) < 0.005 && abs($totalCr) < 0.005) {
                continue;
            }

            $row['total_dr'] = $totalDr;
            $row['total_cr'] = $totalCr;
            $row['balance_amount'] = abs($net);
            $row['balance_type'] = $net >= 0 ? 'DR' : 'CR';
            $accounts[] = $row;
        }

        return $accounts;
    }

    public function getProfitAndLoss($fromDate, $toDate) {
        $income = $this->db->fetchAll(
            "SELECT a.name, SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END) - SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END) as amount
             FROM accounts a
             JOIN journal_lines jl ON jl.account_id = a.id
             JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status IN ('POSTED','REVERSED')
             WHERE a.business_id = ? AND a.group_name = 'INCOME' AND je.entry_date BETWEEN ? AND ?
             GROUP BY a.id, a.name HAVING amount > 0 ORDER BY amount DESC",
            [$this->businessId, $fromDate, $toDate]
        );

        $expenses = $this->db->fetchAll(
            "SELECT a.name, SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END) - SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END) as amount
             FROM accounts a
             JOIN journal_lines jl ON jl.account_id = a.id
             JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status IN ('POSTED','REVERSED')
             WHERE a.business_id = ? AND a.group_name = 'EXPENSE' AND je.entry_date BETWEEN ? AND ?
             GROUP BY a.id, a.name HAVING amount > 0 ORDER BY amount DESC",
            [$this->businessId, $fromDate, $toDate]
        );

        $totalIncome = array_sum(array_column($income, 'amount'));
        $totalExpenses = array_sum(array_column($expenses, 'amount'));

        return [
            'income' => $income,
            'expenses' => $expenses,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_profit' => $totalIncome - $totalExpenses,
        ];
    }

    public function getBalanceSheet($asOnDate = null) {
        $asOnDate = $asOnDate ?: date('Y-m-d');
        $trialBalance = $this->getTrialBalance($asOnDate);
        $result = ['ASSET' => [], 'LIABILITY' => [], 'EQUITY' => []];
        $totalIncome = 0.0;
        $totalExpense = 0.0;

        foreach ($trialBalance as $row) {
            if ($row['group_name'] === 'INCOME') {
                $totalIncome += $row['balance_type'] === 'CR' ? $row['balance_amount'] : -$row['balance_amount'];
                continue;
            }
            if ($row['group_name'] === 'EXPENSE') {
                $totalExpense += $row['balance_type'] === 'DR' ? $row['balance_amount'] : -$row['balance_amount'];
                continue;
            }
            if (!isset($result[$row['group_name']])) {
                continue;
            }

            $result[$row['group_name']][] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'sub_group' => $row['sub_group'],
                'amount' => $row['balance_amount'],
                'balance_type' => $row['balance_type'],
            ];
        }

        $netProfit = round($totalIncome - $totalExpense, 2);
        if (abs($netProfit) >= 0.01) {
            $result['EQUITY'][] = [
                'code' => 'CURRENT-PROFIT',
                'name' => $netProfit >= 0 ? 'Current Period Profit' : 'Current Period Loss',
                'sub_group' => 'Current Earnings',
                'amount' => abs($netProfit),
                'balance_type' => $netProfit >= 0 ? 'CR' : 'DR',
            ];
        }

        $sumByNature = static function (array $rows, string $naturalType): float {
            $total = 0.0;
            foreach ($rows as $row) {
                $amount = floatval($row['amount'] ?? 0);
                $balanceType = strtoupper((string) ($row['balance_type'] ?? $naturalType));
                $total += $balanceType === $naturalType ? $amount : -$amount;
            }
            return round($total, 2);
        };

        $result['total_assets'] = $sumByNature($result['ASSET'], 'DR');
        $result['total_liabilities'] = $sumByNature($result['LIABILITY'], 'CR');
        $result['total_equity'] = $sumByNature($result['EQUITY'], 'CR');
        return $result;
    }

    public function getDebtorAgeingReport() {
        $rows = $this->db->fetchAll(
            "SELECT dc.*, a.current_balance, a.current_balance_type
             FROM debtors_creditors dc
             JOIN accounts a ON a.id = dc.account_id
             WHERE dc.business_id = ?
               AND dc.type IN ('DEBTOR', 'BUYER')
               AND dc.is_active = 1
             ORDER BY dc.name",
            [$this->businessId]
        );

        $report = [];
        foreach ($rows as $row) {
            $openItems = $this->buildOutstandingItemsFromLedger($row['account_id'], 'DR');
            $carPendingMap = [];
            $generalOutstanding = 0.0;
            foreach ($openItems as $item) {
                $carId = $item['car_id'] ?? null;
                if (!$carId) {
                    $generalOutstanding += round(floatval($item['outstanding_amount'] ?? 0), 2);
                    continue;
                }

                if (!isset($carPendingMap[$carId])) {
                    $carPendingMap[$carId] = [
                        'car_id' => $carId,
                        'registration_no' => $item['car_registration_no'] ?? '',
                        'make' => $item['car_make'] ?? '',
                        'model' => $item['car_model'] ?? '',
                        'amount' => 0.0,
                        'oldest_open_date' => $item['entry_date'] ?? null,
                        'open_item_count' => 0,
                    ];
                }

                $carPendingMap[$carId]['amount'] += round(floatval($item['outstanding_amount'] ?? 0), 2);
                $carPendingMap[$carId]['open_item_count']++;
                $itemDate = $item['entry_date'] ?? null;
                if ($itemDate && (
                    empty($carPendingMap[$carId]['oldest_open_date'])
                    || strtotime($itemDate) < strtotime((string) $carPendingMap[$carId]['oldest_open_date'])
                )) {
                    $carPendingMap[$carId]['oldest_open_date'] = $itemDate;
                }
            }

            $linkedCars = $this->db->fetchAll(
                "SELECT id, registration_no, make, model, sold_date
                 FROM cars
                 WHERE business_id = ?
                   AND buyer_party_id = ?
                 ORDER BY sold_date DESC, created_at DESC",
                [$this->businessId, $row['id']]
            );
            foreach ($linkedCars as $car) {
                $pending = $this->getCarPendingAmounts($car['id']);
                $salePending = round(floatval($pending['sale_pending'] ?? 0), 2);
                if ($salePending <= 0.009) {
                    continue;
                }

                if (!isset($carPendingMap[$car['id']])) {
                    $carPendingMap[$car['id']] = [
                        'car_id' => $car['id'],
                        'registration_no' => $car['registration_no'] ?? '',
                        'make' => $car['make'] ?? '',
                        'model' => $car['model'] ?? '',
                        'amount' => $salePending,
                        'oldest_open_date' => $car['sold_date'] ?? null,
                        'open_item_count' => 1,
                    ];
                    continue;
                }

                if ($salePending > round(floatval($carPendingMap[$car['id']]['amount'] ?? 0), 2)) {
                    $carPendingMap[$car['id']]['amount'] = $salePending;
                }
                if (empty($carPendingMap[$car['id']]['oldest_open_date']) && !empty($car['sold_date'])) {
                    $carPendingMap[$car['id']]['oldest_open_date'] = $car['sold_date'];
                }
            }

            $carPendingItems = array_values(array_map(static function (array $item): array {
                $item['amount'] = round(floatval($item['amount']), 2);
                return $item;
            }, $carPendingMap));
            usort($carPendingItems, static function (array $a, array $b): int {
                return ($b['amount'] <=> $a['amount'])
                    ?: strcmp((string) ($a['registration_no'] ?? ''), (string) ($b['registration_no'] ?? ''));
            });

            $outstanding = round($generalOutstanding + array_sum(array_column($carPendingItems, 'amount')), 2);
            if ($outstanding <= 0.009) {
                continue;
            }

            $oldestDate = !empty($openItems) ? min(array_column($openItems, 'entry_date')) : null;
            foreach ($carPendingItems as $carPendingItem) {
                if (empty($carPendingItem['oldest_open_date'])) {
                    continue;
                }
                if (!$oldestDate || strtotime((string) $carPendingItem['oldest_open_date']) < strtotime($oldestDate)) {
                    $oldestDate = $carPendingItem['oldest_open_date'];
                }
            }
            $daysPending = $oldestDate ? max(0, (int) floor((time() - strtotime($oldestDate)) / 86400)) : 0;
            $report[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'account_id' => $row['account_id'],
                'is_bad_debt' => !empty($row['is_bad_debt']),
                'outstanding' => round($outstanding, 2),
                'oldest_open_date' => $oldestDate,
                'days_pending' => $daysPending,
                'open_item_count' => count($openItems) + max(0, count($carPendingItems) - count(array_filter($openItems, static fn(array $item): bool => !empty($item['car_id'])))),
                'car_pending_items' => $carPendingItems,
                'open_items' => $openItems,
            ];
        }

        usort($report, static function ($a, $b) {
            return $b['outstanding'] <=> $a['outstanding'];
        });

        return $report;
    }

    public function getCreditorOutstandingReport() {
        $rows = $this->db->fetchAll(
            "SELECT dc.*, a.current_balance, a.current_balance_type
             FROM debtors_creditors dc
             JOIN accounts a ON a.id = dc.account_id
             WHERE dc.business_id = ?
               AND dc.type IN ('CREDITOR', 'SELLER')
               AND dc.is_active = 1
             ORDER BY dc.name",
            [$this->businessId]
        );

        $report = [];
        foreach ($rows as $row) {
            $openItems = $this->buildOutstandingItemsFromLedger($row['account_id'], 'CR');
            $outstanding = round(array_sum(array_column($openItems, 'outstanding_amount')), 2);
            if ($outstanding <= 0.009) {
                continue;
            }

            $report[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'outstanding' => round($outstanding, 2),
                'oldest_open_date' => !empty($openItems) ? min(array_column($openItems, 'entry_date')) : null,
                'open_item_count' => count($openItems),
                'open_items' => $openItems,
            ];
        }

        usort($report, static function ($a, $b) {
            return $b['outstanding'] <=> $a['outstanding'];
        });

        return $report;
    }

    public function getOutstandingSummary() {
        $debtors = $this->getDebtorAgeingReport();
        $creditors = $this->getCreditorOutstandingReport();

        $employeeRows = $this->db->fetchAll(
            "SELECT a.current_balance, a.current_balance_type
             FROM employees e
             JOIN accounts a ON a.id = e.advance_account_id
             WHERE e.business_id = ?",
            [$this->businessId]
        );

        $employeeAdvances = 0.0;
        foreach ($employeeRows as $row) {
            $employeeAdvances += $this->naturalOutstandingValue($row['current_balance'], $row['current_balance_type'], 'DR');
        }

        return [
            'debtors_total' => round(array_sum(array_column($debtors, 'outstanding')), 2),
            'creditors_total' => round(array_sum(array_column($creditors, 'outstanding')), 2),
            'employee_advances_total' => round($employeeAdvances, 2),
        ];
    }

    public function getPartyOpenItems($partyId) {
        $party = $this->db->fetch(
            "SELECT dc.*, a.current_balance, a.current_balance_type
             FROM debtors_creditors dc
             JOIN accounts a ON a.id = dc.account_id
             WHERE dc.id = ? AND dc.business_id = ?",
            [$partyId, $this->businessId]
        );
        if (!$party) {
            return [];
        }

        $naturalType = in_array($party['type'], ['DEBTOR', 'BUYER'], true) ? 'DR' : 'CR';
        return $this->buildOutstandingItemsFromLedger($party['account_id'], $naturalType);
    }

    public function getCarTimeline($carId) {
        $car = $this->db->fetch(
            "SELECT id, account_id FROM cars WHERE id = ? AND business_id = ?",
            [$carId, $this->businessId]
        );
        if (!$car || empty($car['account_id'])) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT
                je.id AS entry_id,
                je.entry_date,
                je.created_at,
                je.reference_no,
                je.narration,
                je.transaction_type,
                je.entry_type_id,
                je.entry_amount,
                je.business_id,
                je.status,
                je.is_reversal,
                je.original_entry_id,
                el.car_line_amount,
                el.car_line_type,
                el.cash_in_amount,
                el.cash_out_amount,
                jv.id AS voucher_id,
                jv.reference_no AS voucher_reference_no,
                jv.voucher_type,
                jv.primary_amount AS voucher_total,
                jv.status AS voucher_status,
                jva.allocation_amount AS voucher_allocation_amount,
                jva.allocation_note AS voucher_allocation_note,
                jva.allocation_line_count
             FROM journal_entries je
             JOIN (
                 SELECT
                     jl.journal_entry_id,
                     SUM(CASE WHEN jl.account_id = ? THEN jl.amount ELSE 0 END) AS car_line_amount,
                     MAX(CASE WHEN jl.account_id = ? THEN jl.entry_type END) AS car_line_type,
                     SUM(CASE WHEN a.entity_type IN ('CASH','BANK') AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END) AS cash_in_amount,
                     SUM(CASE WHEN a.entity_type IN ('CASH','BANK') AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END) AS cash_out_amount
                 FROM journal_lines jl
                 JOIN accounts a ON a.id = jl.account_id
                 GROUP BY jl.journal_entry_id
             ) el ON el.journal_entry_id = je.id
             LEFT JOIN (
                 SELECT
                     jvl.journal_voucher_id,
                     SUM(jvl.amount) AS allocation_amount,
                     GROUP_CONCAT(NULLIF(jvl.narration, '') ORDER BY jvl.id SEPARATOR ' | ') AS allocation_note,
                     COUNT(*) AS allocation_line_count
                 FROM journal_voucher_lines jvl
                 JOIN accounts allocation_account ON allocation_account.id = jvl.account_id
                 WHERE (jvl.entity_type = 'CAR' AND jvl.entity_id = ?)
                    OR allocation_account.id = ?
                 GROUP BY jvl.journal_voucher_id
             ) jva ON jva.journal_voucher_id = je.journal_voucher_id
             LEFT JOIN journal_vouchers jv ON jv.id = je.journal_voucher_id
             WHERE je.business_id = ?
               AND je.status IN ('POSTED', 'REVERSED')
               AND (je.car_id = ? OR jva.journal_voucher_id IS NOT NULL)
             ORDER BY je.entry_date DESC, je.created_at DESC, je.id DESC",
            [$car['account_id'], $car['account_id'], $carId, $car['account_id'], $this->businessId, $carId]
        );
    }

    public function getJournalVoucherRegister($fromDate = null, $toDate = null, $accessibleAccountIds = []) {
        $fromDate = $fromDate ?: date('Y-m-01');
        $toDate = $toDate ?: date('Y-m-d');
        $sql = "SELECT jv.*, pa.name as primary_account_name, u.full_name as created_by_name, je.reference_no as posted_reference_no,
                       car_allocations.car_allocations, car_allocations.car_allocation_count, car_allocations.car_allocation_total
                FROM journal_vouchers jv
                JOIN accounts pa ON pa.id = jv.primary_account_id
                JOIN users u ON u.id = jv.created_by
                LEFT JOIN journal_entries je ON je.id = jv.posted_entry_id
                LEFT JOIN (
                    SELECT
                        jvl.journal_voucher_id,
                        GROUP_CONCAT(CONCAT(c.id, ':::', c.registration_no, ':::', jvl.amount) ORDER BY c.registration_no SEPARATOR '|||') AS car_allocations,
                        COUNT(*) AS car_allocation_count,
                        SUM(jvl.amount) AS car_allocation_total
                    FROM journal_voucher_lines jvl
                    JOIN accounts allocation_account ON allocation_account.id = jvl.account_id
                    JOIN cars c
                      ON c.id = COALESCE(NULLIF(jvl.entity_id, ''), NULLIF(allocation_account.entity_id, ''))
                      OR (jvl.entity_id IS NULL AND c.account_id = allocation_account.id)
                    WHERE COALESCE(NULLIF(jvl.entity_type, ''), allocation_account.entity_type) = 'CAR'
                    GROUP BY jvl.journal_voucher_id
                ) car_allocations ON car_allocations.journal_voucher_id = jv.id
                WHERE jv.business_id = ?
                  AND jv.voucher_date BETWEEN ? AND ?";
        $params = [$this->businessId, $fromDate, $toDate];

        if (!Auth::isAdmin()) {
            if (empty($accessibleAccountIds)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($accessibleAccountIds), '?'));
            $sql .= " AND (
                        jv.primary_account_id IN ($placeholders)
                        OR EXISTS (
                            SELECT 1
                            FROM journal_voucher_lines jvl
                            WHERE jvl.journal_voucher_id = jv.id
                              AND jvl.account_id IN ($placeholders)
                        )
                     )";
            $params = array_merge($params, $accessibleAccountIds, $accessibleAccountIds);
        }

        $sql .= " ORDER BY jv.voucher_date DESC, jv.created_at DESC";
        return $this->db->fetchAll($sql, $params);
    }

    public function getJournalVoucherDetails($voucherId) {
        $voucher = $this->db->fetch(
            "SELECT jv.*, pa.name as primary_account_name, pa.code as primary_account_code, u.full_name as created_by_name, je.reference_no as posted_reference_no
             FROM journal_vouchers jv
             JOIN accounts pa ON pa.id = jv.primary_account_id
             JOIN users u ON u.id = jv.created_by
             LEFT JOIN journal_entries je ON je.id = jv.posted_entry_id
             WHERE jv.id = ? AND jv.business_id = ?",
            [$voucherId, $this->businessId]
        );
        if (!$voucher) {
            return null;
        }

        $lines = $this->db->fetchAll(
            "SELECT jvl.*, a.name as account_name, a.code as account_code, a.group_name, a.sub_group,
                    c.id AS car_id, c.registration_no as car_reg
             FROM journal_voucher_lines jvl
             JOIN journal_vouchers jv ON jv.id = jvl.journal_voucher_id
             JOIN accounts a ON a.id = jvl.account_id
             LEFT JOIN cars c
               ON c.business_id = jv.business_id
              AND (
                  c.id = COALESCE(NULLIF(jvl.entity_id, ''), NULLIF(a.entity_id, ''))
                  OR (jvl.entity_id IS NULL AND c.account_id = a.id)
              )
             WHERE jvl.journal_voucher_id = ?
             ORDER BY jvl.entry_type DESC, jvl.amount DESC, a.name",
            [$voucherId]
        );

        return [
            'voucher' => $voucher,
            'lines' => $lines,
        ];
    }

    private function normalizePartnerFunding($purchaseAmount, $partnerFunding) {
        $grouped = [];
        foreach ((array) $partnerFunding as $row) {
            $partnerId = $row['partner_id'] ?? null;
            $amount = round(floatval($row['amount'] ?? 0), 2);
            if (!$partnerId) {
                continue;
            }
            if ($amount < 0) {
                throw new Exception('Partner contribution cannot be negative.');
            }

            if (!isset($grouped[$partnerId])) {
                $partner = $this->db->fetch("SELECT id, profit_share_pct FROM partners WHERE id = ? AND business_id = ? AND is_active = 1", [$partnerId, $this->businessId]);
                if (!$partner) {
                    throw new Exception('Select a valid active partner for purchase funding.');
                }
                $grouped[$partnerId] = [
                    'partner_id' => $partnerId,
                    'amount' => 0,
                    'partner_default_pct' => floatval($partner['profit_share_pct'] ?? 0),
                    'profit_share_pct' => null,
                ];
            }

            $grouped[$partnerId]['amount'] += $amount;
            if (isset($row['profit_share_pct']) && $row['profit_share_pct'] !== '') {
                $share = floatval($row['profit_share_pct']);
                if ($share < 0 || $share > 100) {
                    throw new Exception('Each partner profit share must be between 0 and 100%.');
                }
                $grouped[$partnerId]['profit_share_pct'] = $share;
            }
        }

        $normalized = [];
        $profitShareTotal = 0.0;
        foreach ($grouped as $partnerId => $row) {
            $fundingPct = $purchaseAmount > 0 ? round(($row['amount'] / $purchaseAmount) * 100, 4) : 0.0;
            $profitPct = $row['profit_share_pct'];
            if ($profitPct === null) {
                $profitPct = $row['partner_default_pct'] > 0 ? $row['partner_default_pct'] : $fundingPct;
            }
            $profitPct = max(0, round($profitPct, 4));
            $profitShareTotal += $profitPct;
            $normalized[] = [
                'partner_id' => $partnerId,
                'amount' => round($row['amount'], 2),
                'funding_pct' => $fundingPct,
                'profit_share_pct' => $profitPct,
            ];
        }

        if ($profitShareTotal > 100.0001) {
            throw new Exception('Total partner profit share cannot exceed 100%. The business keeps any remaining percentage.');
        }

        return $normalized;
    }

    private function recordPartnerProfitDistribution($carId, $profit, $date) {
        $partnerships = $this->getCarPartnerships($carId);
        if (empty($partnerships) || abs($profit) < 0.01) {
            return null;
        }

        $pnlAccount = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'PNL'", [$this->businessId]);
        if (!$pnlAccount) {
            return null;
        }

        $lines = [];
        $settlements = [];
        foreach ($partnerships as $partnership) {
            $shareAmount = round($profit * (floatval($partnership['profit_share_pct']) / 100), 2);

            if (abs($shareAmount) < 0.01) {
                continue;
            }

            $partnerAccountId = $partnership['current_account_id'] ?: $partnership['capital_account_id'];

            if ($shareAmount > 0) {
                $lines[] = ['account_id' => $partnerAccountId, 'amount' => $shareAmount, 'type' => 'CR', 'narration' => "Profit share for {$partnership['partner_name']}"];
                $direction = 'PAYABLE';
                $profitAmount = $shareAmount;
            } else {
                $lines[] = ['account_id' => $partnerAccountId, 'amount' => abs($shareAmount), 'type' => 'DR', 'narration' => "Loss share for {$partnership['partner_name']}"];
                $direction = 'RECEIVABLE';
                $profitAmount = abs($shareAmount);
            }

            $settlements[] = [
                'partner_id' => $partnership['partner_id'],
                'profit_share_pct' => floatval($partnership['profit_share_pct']),
                'direction' => $direction,
                'profit_amount' => $profitAmount,
            ];
        }

        if (empty($lines) || empty($settlements)) {
            return null;
        }

        $counterType = $profit > 0 ? 'DR' : 'CR';
        $lines[] = [
            'account_id' => $pnlAccount['id'],
            'amount' => array_sum(array_column($settlements, 'profit_amount')),
            'type' => $counterType,
            'narration' => 'Partner profit/loss distribution',
        ];

        $entryId = $this->postJournalEntry(
            'PROFIT_DISTRIBUTION',
            $date,
            "Partner settlement allocation for car {$carId}",
            $lines,
            ['car_id' => $carId]
        );

        foreach ($settlements as $settlement) {
            $this->db->insert('partner_profit_settlements', [
                'id' => Database::uuid(),
                'business_id' => $this->businessId,
                'car_id' => $carId,
                'partner_id' => $settlement['partner_id'],
                'journal_entry_id' => $entryId,
                'settlement_date' => $date,
                'profit_amount' => $settlement['profit_amount'],
                'profit_share_pct' => $settlement['profit_share_pct'],
                'direction' => $settlement['direction'],
                'status' => 'PENDING',
                'settled_amount' => 0,
                'outstanding_amount' => $settlement['profit_amount'],
                'narration' => 'Created on car sale posting',
            ]);
        }

        return $entryId;
    }

    private function normalizeVoucherAllocations($allocations, $primaryEntryType) {
        $normalized = [];
        $counterType = $primaryEntryType === 'DR' ? 'CR' : 'DR';

        foreach ((array) $allocations as $row) {
            $accountId = $row['account_id'] ?? null;
            $amount = round(floatval($row['amount'] ?? 0), 2);
            if (!$accountId || $amount <= 0) {
                continue;
            }

            $account = $this->db->fetch(
                "SELECT a.id, a.name, a.entity_type, a.entity_id,
                        c.id AS linked_car_id, c.registration_no AS linked_car_reg
                 FROM accounts a
                 LEFT JOIN cars c
                   ON c.business_id = a.business_id
                  AND (c.id = a.entity_id OR c.account_id = a.id)
                 WHERE a.id = ?
                   AND a.business_id = ?
                   AND a.is_active = 1
                 LIMIT 1",
                [$accountId, $this->businessId]
            );
            if (!$account) {
                throw new Exception('One selected split account is not available for this business.');
            }

            $entityType = strtoupper(trim((string) ($account['entity_type'] ?? '')));
            $entityId = $account['entity_id'] ?: null;
            if (!empty($account['linked_car_id'])) {
                $entityType = 'CAR';
                $entityId = $account['linked_car_id'];
            }

            $normalized[] = [
                'account_id' => $accountId,
                'amount' => $amount,
                'entry_type' => $counterType,
                'narration' => trim((string) ($row['narration'] ?? '')),
                'entity_type' => $entityType !== '' ? $entityType : null,
                'entity_id' => $entityId,
            ];
        }

        return $normalized;
    }

    private function getNextVoucherRefNo($date) {
        $fy = getCurrentFY($date);
        $result = $this->db->fetch(
            "SELECT reference_no
             FROM journal_vouchers
             WHERE business_id = ?
               AND financial_year = ?
               AND reference_no LIKE ?
             ORDER BY reference_no DESC
             LIMIT 1",
            [$this->businessId, $fy, 'JV-' . $fy . '-%']
        );

        $next = 1;
        if ($result) {
            $parts = explode('-', $result['reference_no']);
            $next = intval(end($parts)) + 1;
        }

        return 'JV-' . $fy . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    private function getAccountBalanceRow($accountId) {
        $row = $this->db->fetch("SELECT current_balance, current_balance_type FROM accounts WHERE id = ?", [$accountId]);
        if (!$row) {
            return [0.0, 'DR'];
        }
        return [floatval($row['current_balance']), $row['current_balance_type']];
    }

    private function storedBalanceValue($amount, $type, $creditPositive = false) {
        $amount = abs(floatval($amount));
        $isCredit = strtoupper((string) $type) === 'CR';
        if ($creditPositive) {
            return $isCredit ? $amount : -$amount;
        }
        return $isCredit ? -$amount : $amount;
    }

    private function getCommittedPartnerFunding($partnerId) {
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(cp.funding_amount), 0) as total
             FROM car_partnerships cp
             JOIN cars c ON c.id = cp.car_id
             WHERE cp.business_id = ?
               AND cp.partner_id = ?
               AND c.status IN ('IN_STOCK', 'PENDING_PAYMENT')",
            [$this->businessId, $partnerId]
        );
        return floatval($row['total'] ?? 0);
    }

    private function getPendingSettlementAmount($partnerId, $direction) {
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(outstanding_amount), 0) as total
             FROM partner_profit_settlements
             WHERE business_id = ?
               AND partner_id = ?
               AND direction = ?
               AND status IN ('PENDING', 'PARTIAL')",
            [$this->businessId, $partnerId, $direction]
        );
        return floatval($row['total'] ?? 0);
    }

    private function applyPartnerSettlement($partnerId, $amount, $direction, $entryId, $date) {
        $this->applyPartnerSettlementAllocation($partnerId, $amount, $direction, $entryId, $date, true);
    }

    private function applyPartnerSettlementAllocation($partnerId, $amount, $direction, $entryId, $date, $recordApplications = true) {
        $rows = $this->db->fetchAll(
            "SELECT *
             FROM partner_profit_settlements
             WHERE business_id = ?
               AND partner_id = ?
               AND direction = ?
               AND status IN ('PENDING', 'PARTIAL')
             ORDER BY settlement_date, created_at",
            [$this->businessId, $partnerId, $direction]
        );

        $remaining = round($amount, 2);
        foreach ($rows as $row) {
            if ($remaining <= 0.009) {
                break;
            }

            $available = round(floatval($row['outstanding_amount']), 2);
            if ($available <= 0) {
                continue;
            }

            $applied = min($remaining, $available);
            $newSettled = round(floatval($row['settled_amount']) + $applied, 2);
            $newOutstanding = round($available - $applied, 2);
            $newStatus = $newOutstanding <= 0.009 ? 'SETTLED' : 'PARTIAL';

            $this->db->update('partner_profit_settlements', [
                'last_settlement_entry_id' => $entryId,
                'settled_amount' => $newSettled,
                'outstanding_amount' => max(0, $newOutstanding),
                'status' => $newStatus,
                'settlement_date' => $date,
            ], 'id = ?', [$row['id']]);

            if ($recordApplications) {
                $this->db->insert('partner_settlement_applications', [
                    'id' => Database::uuid(),
                    'business_id' => $this->businessId,
                    'partner_profit_settlement_id' => $row['id'],
                    'journal_entry_id' => $entryId,
                    'applied_date' => $date,
                    'applied_amount' => $applied,
                    'direction' => $direction,
                ]);
            }

            $remaining = round($remaining - $applied, 2);
        }

        if ($remaining > 0.009) {
            throw new Exception("Settlement amount could not be fully matched against pending partner balances.");
        }
    }

    private function reversePartnerSettlementApplications($entry, $lines) {
        $entryId = $entry['id'];
        $applications = $this->db->fetchAll(
            "SELECT *
             FROM partner_settlement_applications
             WHERE business_id = ?
               AND journal_entry_id = ?
             ORDER BY created_at DESC, id DESC",
            [$this->businessId, $entryId]
        );

        if (empty($applications)) {
            $this->reverseLegacyPartnerSettlement($entry, $lines);
            return;
        }

        foreach ($applications as $application) {
            $row = $this->db->fetch(
                "SELECT *
                 FROM partner_profit_settlements
                 WHERE id = ?
                   AND business_id = ?",
                [$application['partner_profit_settlement_id'], $this->businessId]
            );
            if (!$row) {
                continue;
            }

            $applied = round(floatval($application['applied_amount']), 2);
            $newSettled = max(0, round(floatval($row['settled_amount']) - $applied, 2));
            $newOutstanding = round(floatval($row['outstanding_amount']) + $applied, 2);
            $newStatus = $newSettled <= 0.009 ? 'PENDING' : 'PARTIAL';

            $previousApplication = $this->db->fetch(
                "SELECT journal_entry_id, applied_date
                 FROM partner_settlement_applications
                 WHERE business_id = ?
                   AND partner_profit_settlement_id = ?
                   AND journal_entry_id <> ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1",
                [$this->businessId, $row['id'], $entryId]
            );

            $updateData = [
                'settled_amount' => $newSettled,
                'outstanding_amount' => $newOutstanding,
                'status' => $newStatus,
                'last_settlement_entry_id' => $previousApplication['journal_entry_id'] ?? null,
            ];
            if (!empty($previousApplication['applied_date'])) {
                $updateData['settlement_date'] = $previousApplication['applied_date'];
            }

            $this->db->update('partner_profit_settlements', $updateData, 'id = ?', [$row['id']]);
        }

        $this->db->query(
            "DELETE FROM partner_settlement_applications
             WHERE business_id = ?
               AND journal_entry_id = ?",
            [$this->businessId, $entryId]
        );
    }

    private function canReverseLegacyPartnerSettlement($entry, $lines) {
        if (empty($entry['partner_id'])) {
            return false;
        }

        $this->ensurePartnerSettlementApplicationTrail($entry['partner_id']);
        $direction = $this->getPartnerSettlementDirection($entry, $lines);
        if (!$direction) {
            return false;
        }

        $newerSettlements = $this->db->fetch(
            "SELECT COUNT(*) AS cnt
             FROM journal_entries
             WHERE business_id = ?
               AND partner_id = ?
               AND transaction_type = 'PARTNER_SETTLEMENT'
               AND status = 'POSTED'
               AND is_reversal = 0
               AND created_at > ?
               AND id <> ?",
            [$this->businessId, $entry['partner_id'], $entry['created_at'], $entry['id']]
        );
        if (($newerSettlements['cnt'] ?? 0) > 0) {
            return false;
        }

        $amount = $this->getEntryDebitTotal($lines);
        $rows = $this->getLegacySettlementRowsForReversal($entry, $direction);
        return $this->canUnapplyLegacySettlementAmount($rows, $amount);
    }

    private function reverseLegacyPartnerSettlement($entry, $lines) {
        $direction = $this->getPartnerSettlementDirection($entry, $lines);
        if (!$direction) {
            throw new Exception("Unable to determine legacy settlement direction for reversal.");
        }

        $amount = $this->getEntryDebitTotal($lines);
        $rows = $this->getLegacySettlementRowsForReversal($entry, $direction);
        if (!$this->canUnapplyLegacySettlementAmount($rows, $amount)) {
            throw new Exception("This legacy partner settlement no longer matches the latest settlement state. Use a controlled admin correction flow.");
        }

        $remaining = round($amount, 2);
        foreach ($rows as $row) {
            if ($remaining <= 0.009) {
                break;
            }

            $available = round(floatval($row['settled_amount']), 2);
            if ($available <= 0.009) {
                continue;
            }

            $applied = min($remaining, $available);
            $newSettled = max(0, round($available - $applied, 2));
            $newOutstanding = round(floatval($row['outstanding_amount']) + $applied, 2);
            $newStatus = $newSettled <= 0.009 ? 'PENDING' : 'PARTIAL';

            $this->db->update('partner_profit_settlements', [
                'settled_amount' => $newSettled,
                'outstanding_amount' => $newOutstanding,
                'status' => $newStatus,
                'last_settlement_entry_id' => null,
            ], 'id = ?', [$row['id']]);

            $remaining = round($remaining - $applied, 2);
        }
    }

    private function getLegacySettlementRowsForReversal($entry, $direction) {
        return $this->db->fetchAll(
            "SELECT *
             FROM partner_profit_settlements
             WHERE business_id = ?
               AND partner_id = ?
               AND direction = ?
               AND last_settlement_entry_id = ?
             ORDER BY settlement_date DESC, created_at DESC, id DESC",
            [$this->businessId, $entry['partner_id'], $direction, $entry['id']]
        );
    }

    private function canUnapplyLegacySettlementAmount($rows, $amount) {
        $available = 0.0;
        foreach ($rows as $row) {
            $available += round(floatval($row['settled_amount']), 2);
        }
        return round($available, 2) + 0.009 >= round($amount, 2);
    }

    private function ensurePartnerSettlementApplicationTrail($partnerId) {
        if (!$partnerId) {
            return;
        }

        $legacyCount = $this->db->fetch(
            "SELECT COUNT(*) AS cnt
             FROM journal_entries je
             WHERE je.business_id = ?
               AND je.partner_id = ?
               AND je.transaction_type = 'PARTNER_SETTLEMENT'
               AND je.status = 'POSTED'
               AND je.is_reversal = 0
               AND NOT EXISTS (
                   SELECT 1
                   FROM partner_settlement_applications psa
                   WHERE psa.business_id = je.business_id
                     AND psa.journal_entry_id = je.id
               )",
            [$this->businessId, $partnerId]
        );

        if (($legacyCount['cnt'] ?? 0) <= 0) {
            return;
        }

        $this->rebuildPartnerSettlementTrail($partnerId);
    }

    private function rebuildPartnerSettlementTrail($partnerId) {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $rows = $this->db->fetchAll(
                "SELECT pps.id, pps.profit_amount, pps.journal_entry_id, je.entry_date
                 FROM partner_profit_settlements pps
                 JOIN journal_entries je ON je.id = pps.journal_entry_id
                 WHERE pps.business_id = ?
                   AND pps.partner_id = ?
                   AND pps.status <> 'REVERSED'",
                [$this->businessId, $partnerId]
            );

            foreach ($rows as $row) {
                $this->db->update('partner_profit_settlements', [
                    'settled_amount' => 0,
                    'outstanding_amount' => round(floatval($row['profit_amount']), 2),
                    'status' => 'PENDING',
                    'last_settlement_entry_id' => null,
                    'settlement_date' => $row['entry_date'],
                ], 'id = ?', [$row['id']]);
            }

            $this->db->query(
                "DELETE FROM partner_settlement_applications
                 WHERE business_id = ?
                   AND journal_entry_id IN (
                       SELECT id
                       FROM journal_entries
                       WHERE business_id = ?
                         AND partner_id = ?
                         AND transaction_type = 'PARTNER_SETTLEMENT'
                   )",
                [$this->businessId, $this->businessId, $partnerId]
            );

            $settlementEntries = $this->db->fetchAll(
                "SELECT *
                 FROM journal_entries
                 WHERE business_id = ?
                   AND partner_id = ?
                   AND transaction_type = 'PARTNER_SETTLEMENT'
                   AND status = 'POSTED'
                   AND is_reversal = 0
                 ORDER BY entry_date, created_at, id",
                [$this->businessId, $partnerId]
            );

            foreach ($settlementEntries as $entry) {
                $lines = $this->db->fetchAll("SELECT * FROM journal_lines WHERE journal_entry_id = ?", [$entry['id']]);
                $direction = $this->getPartnerSettlementDirection($entry, $lines);
                if (!$direction) {
                    throw new Exception("Could not rebuild partner settlement trail because a settlement direction was unclear.");
                }
                $amount = $this->getEntryDebitTotal($lines);
                $this->applyPartnerSettlementAllocation($partnerId, $amount, $direction, $entry['id'], $entry['entry_date'], true);
            }

            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function getPartnerSettlementDirection($entry, $lines) {
        if (empty($entry['partner_id'])) {
            return null;
        }

        $partner = $this->db->fetch("SELECT current_account_id FROM partners WHERE id = ?", [$entry['partner_id']]);
        if (!$partner || empty($partner['current_account_id'])) {
            return null;
        }

        foreach ($lines as $line) {
            if (($line['account_id'] ?? null) !== $partner['current_account_id']) {
                continue;
            }
            return strtoupper((string) ($line['entry_type'] ?? '')) === 'DR' ? 'PAYABLE' : 'RECEIVABLE';
        }

        return null;
    }

    // ========================================
    // ALERTS
    // ========================================
    private function checkAlerts() {
        // Check cash balance
        $cashAccounts = $this->db->fetchAll(
            "SELECT * FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND entity_id IS NULL AND is_active = 1",
            [$this->businessId]
        );
        $business = $this->db->fetch("SELECT * FROM businesses WHERE id = ?", [$this->businessId]);
        $minCashBalance = floatval($business['min_cash_balance'] ?? 0);

        foreach ($cashAccounts as $cashAccount) {
            if (floatval($cashAccount['current_balance']) < $minCashBalance) {
                $this->createAlert(
                    'CASH_LOW',
                    ($cashAccount['name'] ?? 'Cash account') . " balance (" . formatAmount($cashAccount['current_balance']) . ") is below minimum threshold",
                    'account',
                    $cashAccount['id']
                );
            }
        }
    }

    private function createAlert($type, $message, $entityType = null, $entityId = null, $severity = 'WARNING') {
        // Don't duplicate recent alerts
        $recent = $this->db->fetch(
            "SELECT id FROM alerts WHERE business_id = ? AND type = ? AND entity_id = ? AND is_resolved = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
            [$this->businessId, $type, $entityId]
        );
        if ($recent) return;

        $this->db->insert('alerts', [
            'id' => Database::uuid(),
            'business_id' => $this->businessId,
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    // ========================================
    // HELPERS
    // ========================================
    private function getPaymentMode($accountId) {
        $account = $this->db->fetch("SELECT entity_type FROM accounts WHERE id = ?", [$accountId]);
        return ($account && $account['entity_type'] === 'BANK') ? 'BANK' : 'CASH';
    }

    private function getOrCreateSystemAccount($code, $name, $group, $subGroup) {
        $account = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = ?", [$this->businessId, $code]);
        if ($account) {
            return $account;
        }

        return ['id' => $this->createAccount($code, $name, $group, $subGroup, 'GENERAL')];
    }

    private function normalizeGstComponent($grossAmount, $gstAmount = 0) {
        $grossAmount = round(floatval($grossAmount), 2);
        $gstAmount = round(floatval($gstAmount), 2);

        if ($grossAmount <= 0) {
            throw new Exception("Amount must be greater than zero.");
        }
        if ($gstAmount < 0) {
            throw new Exception("GST amount cannot be negative.");
        }
        if ($gstAmount - $grossAmount > 0.01) {
            throw new Exception("GST amount cannot exceed the total amount.");
        }

        $baseAmount = round($grossAmount - $gstAmount, 2);
        if ($baseAmount <= 0 && $gstAmount > 0) {
            throw new Exception("GST amount cannot be equal to or more than the total amount.");
        }

        return [$grossAmount, $gstAmount, $baseAmount];
    }

    private function naturalOutstandingValue($amount, $type, $naturalType) {
        $amount = abs(floatval($amount));
        $type = strtoupper((string) $type);
        $naturalType = strtoupper((string) $naturalType);
        return $type === $naturalType ? $amount : 0.0;
    }

    private function getOutstandingOriginDate($accountId, $naturalType) {
        $items = $this->buildOutstandingItemsFromLedger($accountId, $naturalType);
        if (empty($items)) {
            return null;
        }

        return min(array_column($items, 'entry_date'));
    }

    private function buildOutstandingItemsFromLedger($accountId, $naturalType) {
        $rows = $this->db->fetchAll(
            "SELECT je.id AS journal_entry_id,
                    je.original_entry_id,
                    je.reference_no,
                    je.transaction_type,
                    je.car_id,
                    je.narration AS entry_narration,
                    je.entry_date,
                    je.is_reversal,
                    je.created_at,
                    jl.id AS journal_line_id,
                    jl.entry_type,
                    jl.amount,
                    jl.narration AS line_narration,
                    c.registration_no AS car_registration_no,
                    c.make AS car_make,
                    c.model AS car_model
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             LEFT JOIN cars c ON c.id = je.car_id
             WHERE jl.account_id = ?
               AND je.status = 'POSTED'
             ORDER BY je.entry_date, je.created_at, jl.id",
            [$accountId]
        );

        $naturalType = strtoupper((string) $naturalType);
        $items = [];
        $allocationsByEntry = [];
        $sequence = 0;

        foreach ($rows as $row) {
            $entryType = strtoupper((string) ($row['entry_type'] ?? ''));
            $amount = round(floatval($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            if ($entryType === $naturalType) {
                $reopened = false;
                $originalEntryId = $row['original_entry_id'] ?? null;

                if (!empty($row['is_reversal']) && $originalEntryId && !empty($allocationsByEntry[$originalEntryId])) {
                    foreach ($allocationsByEntry[$originalEntryId] as $allocation) {
                        $itemIndex = $allocation['item_index'];
                        if (!isset($items[$itemIndex])) {
                            continue;
                        }
                        $items[$itemIndex]['outstanding_amount'] = round(
                            min(
                                $items[$itemIndex]['original_amount'],
                                $items[$itemIndex]['outstanding_amount'] + $allocation['amount']
                            ),
                            2
                        );
                        $reopened = true;
                    }
                }

                if (!$reopened) {
                    $items[] = [
                        'sequence' => $sequence++,
                        'journal_entry_id' => $row['journal_entry_id'],
                        'reference_no' => $row['reference_no'],
                        'transaction_type' => $row['transaction_type'],
                        'car_id' => $row['car_id'] ?? null,
                        'car_registration_no' => $row['car_registration_no'] ?? null,
                        'car_make' => $row['car_make'] ?? null,
                        'car_model' => $row['car_model'] ?? null,
                        'entry_date' => $row['entry_date'],
                        'created_at' => $row['created_at'],
                        'narration' => trim((string) ($row['line_narration'] ?: $row['entry_narration'] ?: '')),
                        'original_amount' => $amount,
                        'outstanding_amount' => $amount,
                    ];
                }
                continue;
            }

            $remaining = $amount;
            $allocations = [];

            while ($remaining > 0.009) {
                $oldestOpenIndex = null;
                $oldestSequence = PHP_INT_MAX;

                foreach ($items as $index => $item) {
                    if (($item['outstanding_amount'] ?? 0) <= 0.009) {
                        continue;
                    }
                    if (($item['sequence'] ?? PHP_INT_MAX) < $oldestSequence) {
                        $oldestSequence = $item['sequence'];
                        $oldestOpenIndex = $index;
                    }
                }

                if ($oldestOpenIndex === null) {
                    break;
                }

                $available = round(floatval($items[$oldestOpenIndex]['outstanding_amount'] ?? 0), 2);
                $applied = min($remaining, $available);
                $items[$oldestOpenIndex]['outstanding_amount'] = round($available - $applied, 2);
                $allocations[] = [
                    'item_index' => $oldestOpenIndex,
                    'amount' => $applied,
                ];
                $remaining = round($remaining - $applied, 2);
            }

            if (!empty($allocations)) {
                $allocationsByEntry[$row['journal_entry_id']] = $allocations;
            }
        }

        return array_values(array_filter(array_map(static function ($item) {
            if (($item['outstanding_amount'] ?? 0) <= 0.009) {
                return null;
            }
            unset($item['sequence']);
            $item['outstanding_amount'] = round(floatval($item['outstanding_amount']), 2);
            return $item;
        }, $items)));
    }

    private function refreshPartyBadDebtFlag($partyId) {
        if (!$partyId) {
            return;
        }

        $row = $this->db->fetch(
            "SELECT COUNT(*) AS cnt
             FROM journal_entries
             WHERE business_id = ?
               AND party_id = ?
               AND transaction_type = 'BAD_DEBT'
               AND status = 'POSTED'
               AND is_reversal = 0",
            [$this->businessId, $partyId]
        );

        $this->db->query(
            "UPDATE debtors_creditors
             SET is_bad_debt = ?
             WHERE id = ? AND business_id = ?",
            [(($row['cnt'] ?? 0) > 0) ? 1 : 0, $partyId, $this->businessId]
        );
    }

    private function getBusinessSetting($key) {
        $business = $this->db->fetch("SELECT * FROM businesses WHERE id = ?", [$this->businessId]);
        return $business[$key] ?? null;
    }
}
