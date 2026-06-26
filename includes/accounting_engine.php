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
        $this->db->insert('accounts', [
            'id' => $id,
            'business_id' => $this->businessId,
            'code' => $code,
            'name' => $name,
            'group_name' => $group,
            'sub_group' => $subGroup,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
        return $id;
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
                    PRIMARY KEY (`id`),
                    KEY `idx_jvl_voucher` (`journal_voucher_id`),
                    KEY `idx_jvl_account` (`account_id`)
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

            if (!$this->columnExists('journal_entries', 'journal_voucher_id')) {
                $this->db->query("ALTER TABLE `journal_entries` ADD COLUMN `journal_voucher_id` CHAR(36) DEFAULT NULL AFTER `party_id`");
            }
            if (!$this->columnExists('cars', 'sale_gst_amount')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `sale_gst_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `sale_price`");
            }
            if (!$this->columnExists('cars', 'sale_commission_amount')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `sale_commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `sale_price`");
            }
            if (!$this->columnExists('cars', 'buyer_party_id')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `buyer_party_id` CHAR(36) DEFAULT NULL AFTER `buyer_contact`");
            }
            if (!$this->columnExists('cars', 'seller_party_id')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `seller_party_id` CHAR(36) DEFAULT NULL AFTER `buyer_party_id`");
            }
            if (!$this->columnExists('cars', 'purchase_paid_amount')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `purchase_paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `purchase_price`");
            }
            if (!$this->columnExists('cars', 'has_second_key')) {
                $this->db->query("ALTER TABLE `cars` ADD COLUMN `has_second_key` TINYINT(1) NOT NULL DEFAULT 0 AFTER `seller_party_id`");
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
        $required = ['JOURNAL_VOUCHER', 'PARTNER_SETTLEMENT', 'EMPLOYEE_ADVANCE_WRITEOFF', 'GST_UTILIZATION', 'RTO_EXPENSE', 'RTO_RECOVERY'];
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
                 ENUM('CAR_PURCHASE','CAR_SALE','RTO_EXPENSE','RTO_RECOVERY','CAR_EXPENSE','GENERAL_EXPENSE','JOURNAL_VOUCHER','PARTNER_INVEST','PARTNER_WITHDRAW','PARTNER_SETTLEMENT','SALARY_PAYMENT','EMPLOYEE_ADVANCE','EMPLOYEE_ADVANCE_WRITEOFF','LOAN_GIVEN','LOAN_RECEIVED','LOAN_TAKEN','LOAN_REPAID','CONTRA_TRANSFER','GST_PAYMENT','GST_UTILIZATION','OPENING_BALANCE','REVERSAL','BAD_DEBT','PROFIT_DISTRIBUTION')
                 NOT NULL"
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

    // ========================================
    // CORE: Post a journal entry with balanced Dr/Cr lines
    // ========================================
    public function postJournalEntry($type, $date, $narration, $lines, $extras = []) {
        $this->validateDateNotLocked($date);

        // Validate balance: Dr must equal Cr
        $totalDr = 0;
        $totalCr = 0;
        foreach ($lines as $line) {
            $amount = round(floatval($line['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new Exception("Journal entry lines must be greater than zero.");
            }
            if (empty($line['account_id'])) {
                throw new Exception("Journal entry line account is missing.");
            }
            if ($line['type'] === 'DR') $totalDr += $line['amount'];
            else $totalCr += $line['amount'];
        }

        if (abs($totalDr - $totalCr) > 0.01) {
            throw new Exception("Journal entry is not balanced! Dr: $totalDr, Cr: $totalCr");
        }

        $this->db->beginTransaction();

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
                'status' => 'POSTED',
                'created_by' => $this->userId,
                'financial_year' => $fy,
                'car_id' => $extras['car_id'] ?? null,
                'partner_id' => $extras['partner_id'] ?? null,
                'employee_id' => $extras['employee_id'] ?? null,
                'party_id' => $extras['party_id'] ?? null,
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
                ]);
                $this->updateAccountBalance($line['account_id'], $line['amount'], $line['type']);
            }

            Auth::auditLog('CREATE', 'journal_entry', $entryId, "Posted $type entry: $narration");

            // Check alerts after posting
            $this->checkAlerts();

            $this->db->commit();
            return $entryId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ========================================
    // UPDATE: Account running balance
    // ========================================
    private function updateAccountBalance($accountId, $amount, $entryType) {
        $account = $this->db->fetch("SELECT * FROM accounts WHERE id = ?", [$accountId]);
        if (!$account) throw new Exception("Account not found: $accountId");

        $balance = $account['current_balance'];
        $balType = $account['current_balance_type'];
        $group = $account['group_name'];

        // Natural balance types: ASSET/EXPENSE = DR, LIABILITY/INCOME/EQUITY = CR
        $naturalDr = in_array($group, ['ASSET', 'EXPENSE', 'CONTRA']);

        if ($naturalDr) {
            if ($entryType === 'DR') $balance += $amount;
            else $balance -= $amount;
        } else {
            if ($entryType === 'CR') $balance += $amount;
            else $balance -= $amount;
        }

        if ($balance >= 0) {
            $balType = $naturalDr ? 'DR' : 'CR';
        } else {
            $balance = abs($balance);
            $balType = $naturalDr ? 'CR' : 'DR';
        }

        $this->db->query(
            "UPDATE accounts SET current_balance = ?, current_balance_type = ? WHERE id = ?",
            [$balance, $balType, $accountId]
        );
    }

    // ========================================
    // TRANSACTION HANDLERS — The 14 types
    // ========================================
    
    /**
     * CAR PURCHASE — Business funds
     */
    public function carPurchase($carId, $amount, $date, $paymentAccount, $narration, $partnerFunding = [], $gstAmount = 0, $sellerName = null, $paidNow = null) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
        if (!$car) throw new Exception("Car not found");

        $carAccountId = $car['account_id'];
        $lines = [];
        [$grossAmount, $gstAmount, $baseAmount] = $this->normalizeGstComponent($amount, $gstAmount);
        $partnerFunding = $this->normalizePartnerFunding($grossAmount, $partnerFunding);
        $gstInputAccount = $gstAmount > 0 ? $this->getOrCreateSystemAccount('GST-RCV', 'GST Input Credit', 'ASSET', 'GST Assets') : null;

        // Business funding
        $businessAmount = $grossAmount;
        foreach ($partnerFunding as $pf) {
            $businessAmount -= $pf['amount'];
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

        if ($businessAmount > 0) {
            if ($paidNow > 0) {
                $this->validateCashAvailable($paymentAccount, $paidNow);
            }

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
                $sellerParty = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$sellerPartyId]);
                $lines[] = ['account_id' => $sellerParty['account_id'], 'amount' => $sellerOutstanding, 'type' => 'CR', 'narration' => "Pending purchase payment to $sellerName"];
            }
        }

        $entryId = null;
        if (!empty($lines)) {
            $entryId = $this->postJournalEntry('CAR_PURCHASE', $date, $narration, $lines, ['car_id' => $carId]);
        }

        // Partner funding entries
        $partnerGstAllocated = 0.0;
        $partnerRowsRemaining = count($partnerFunding);
        foreach ($partnerFunding as $pf) {
            $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ?", [$pf['partner_id']]);
            if (!$partner) continue;

            $partnerRowsRemaining--;
            $partnerGst = $partnerRowsRemaining === 0
                ? round($gstAmount - $partnerGstAllocated, 2)
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

            // Record contribution
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
                $this->db->update('car_partnerships', $partnershipData, 'id = ?', [$existingPartnership['id']]);
            } else {
                $partnershipData['id'] = Database::uuid();
                $this->db->insert('car_partnerships', $partnershipData);
            }
        }

        $this->db->query(
            "UPDATE cars SET purchase_paid_amount = ?, seller_party_id = COALESCE(?, seller_party_id) WHERE id = ? AND business_id = ?",
            [$paidNow, $sellerPartyId ?? null, $carId, $this->businessId]
        );

        return $entryId;
    }

    /**
     * CAR SALE — Full or partial payment
     */
    public function carSale($carId, $salePrice, $date, $receivingAccount, $narration, $buyerName = null, $amountReceived = null, $gstAmount = 0, $commissionAmount = 0) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
        if (!$car) throw new Exception("Car not found");
        if ($car['status'] !== 'IN_STOCK') throw new Exception("Only in-stock cars can be sold from this entry. Use debtor recovery for pending buyer payment.");
        if ($salePrice <= 0) throw new Exception("Sale price must be greater than zero.");
        $commissionAmount = round(floatval($commissionAmount), 2);
        if ($commissionAmount < 0) throw new Exception("Commission cannot be negative.");

        $carAccountId = $car['account_id'];
        $totalCost = $this->getCarTotalCost($carId);
        [$grossSalePrice, $gstAmount, $netSalePrice] = $this->normalizeGstComponent($salePrice, $gstAmount);
        $grossReceiptTarget = round($grossSalePrice + $commissionAmount, 2);
        $received = $amountReceived === null ? $grossReceiptTarget : round(floatval($amountReceived), 2);
        if ($received < 0) throw new Exception("Amount received cannot be negative.");
        if ($received - $grossReceiptTarget > 0.01) throw new Exception("Amount received cannot be more than total buyer amount.");
        $outstanding = $grossReceiptTarget - $received;
        if ($outstanding > 0.009 && trim((string) $buyerName) === '') {
            throw new Exception("Buyer name is required when sale payment is pending.");
        }
        $profit = ($netSalePrice + $commissionAmount) - $totalCost;

        $lines = [];
        if ($received > 0) {
            $lines[] = ['account_id' => $receivingAccount, 'amount' => $received, 'type' => 'DR', 'narration' => 'Buyer amount received'];
        }
        
        if ($outstanding > 0 && $buyerName) {
            // Create debtor for outstanding amount
            $partyId = $this->getOrCreateParty($buyerName, 'BUYER');
            $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);
            $lines[] = ['account_id' => $party['account_id'], 'amount' => $outstanding, 'type' => 'DR', 'narration' => "Outstanding from $buyerName"];
        }

        // Revenue entry
        $revenueAccount = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'CAR-REV'", [$this->businessId]);
        $lines[] = ['account_id' => $revenueAccount['id'], 'amount' => $netSalePrice, 'type' => 'CR', 'narration' => "Car sale revenue - {$car['registration_no']}"];
        if ($commissionAmount > 0) {
            $commissionIncome = $this->getOrCreateSystemAccount('SALE-COMM', 'Car Sale Commission Income', 'INCOME', 'Direct Income');
            $lines[] = ['account_id' => $commissionIncome['id'], 'amount' => $commissionAmount, 'type' => 'CR', 'narration' => "Commission income - {$car['registration_no']}"];
        }
        if ($gstAmount > 0) {
            $gstPayable = $this->getOrCreateSystemAccount('GST-PAY', 'GST Payable', 'LIABILITY', 'GST Liabilities');
            $lines[] = ['account_id' => $gstPayable['id'], 'amount' => $gstAmount, 'type' => 'CR', 'narration' => "GST output on {$car['registration_no']}"];
        }

        $entryId = $this->postJournalEntry('CAR_SALE', $date, $narration, $lines, ['car_id' => $carId, 'party_id' => $partyId ?? null]);

        // Close car account — transfer cost to P&L
        if ($totalCost > 0) {
            $pnlAccount = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'PNL'", [$this->businessId]);
            $costLines = [
                ['account_id' => $pnlAccount['id'], 'amount' => $totalCost, 'type' => 'DR', 'narration' => "Cost of car sold - {$car['registration_no']}"],
                ['account_id' => $carAccountId, 'amount' => $totalCost, 'type' => 'CR', 'narration' => "Car account closed"],
            ];
            $this->postJournalEntry('CAR_SALE', $date, "Close car account {$car['registration_no']}", $costLines, ['car_id' => $carId]);
        }

        // Update car status
        $status = $outstanding > 0 ? 'PENDING_PAYMENT' : 'SOLD';
        $this->db->query("UPDATE cars SET status = ?, sold_date = ?, sale_price = ?, sale_commission_amount = ?, sale_gst_amount = ?, buyer_name = ?, buyer_party_id = ? WHERE id = ?",
            [$status, $date, $grossSalePrice, $commissionAmount, $gstAmount, $buyerName, $partyId ?? null, $carId]);

        $this->recordPartnerProfitDistribution($carId, $profit, $date);

        return $entryId;
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
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
        if (!$car) throw new Exception("Car not found");

        // RULE 3: Sold car cannot receive new expenses without admin override
        if ($car['status'] === 'SOLD') {
            throw new Exception("Cannot add expenses to a sold car. Admin override required.");
        }

        $this->validateCashAvailable($paymentAccount, $amount);
        [$grossAmount, $gstAmount, $baseAmount] = $this->normalizeGstComponent($amount, $gstAmount);

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
            $this->postJournalEntry('CAR_EXPENSE', $date, "Allocate {$categoryName} to {$car['registration_no']}", $carLines, ['car_id' => $carId]);
        }
        return $entryId;
    }

    public function rtoExpense($rtoId, $carId, $amount, $date, $paymentAccount, $narration, $gstAmount = 0) {
        $rto = $this->db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $this->businessId]);
        if (!$rto) throw new Exception("RTO record not found.");
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception("Car not found.");
        if ($car['status'] === 'SOLD') throw new Exception("Cannot add RTO expense to a fully sold car.");

        $this->validateCashAvailable($paymentAccount, $amount);
        [$grossAmount, $gstAmount, $baseAmount] = $this->normalizeGstComponent($amount, $gstAmount);
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
            ], ['car_id' => $carId]);
        }

        $this->db->query(
            "UPDATE rto_records
             SET expense_amount = expense_amount + ?, expense_entry_id = ?, status = IF(status = 'PENDING', 'IN_PROGRESS', status)
             WHERE id = ? AND business_id = ?",
            [$grossAmount, $entryId, $rtoId, $this->businessId]
        );
        return $entryId;
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
        return $entryId;
    }

    public function recordSecondKeyEvent($carId, $eventType, $date, $narration) {
        $eventType = strtoupper((string) $eventType);
        if (!in_array($eventType, ['RECEIVED', 'GIVEN'], true)) throw new Exception("Invalid second key event.");
        $car = $this->db->fetch("SELECT id FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception("Car not found.");
        $this->db->insert('car_second_key_events', [
            'id' => Database::uuid(),
            'business_id' => $this->businessId,
            'car_id' => $carId,
            'event_type' => $eventType,
            'event_date' => $date,
            'narration' => $narration,
            'created_by' => $this->userId,
        ]);
        $this->db->query(
            "UPDATE cars SET has_second_key = ? WHERE id = ? AND business_id = ?",
            [$eventType === 'RECEIVED' ? 1 : 0, $carId, $this->businessId]
        );
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
            return $this->postJournalEntry('JOURNAL_VOUCHER', $date, $narration, $lines);
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

            return $this->postJournalEntry('GENERAL_EXPENSE', $date, $narration, $lines);
        }

        throw new Exception("Invalid category direction.");
    }

    /**
     * PARTNER INVEST
     */
    public function partnerInvest($partnerId, $amount, $date, $receivingAccount, $narration) {
        $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ?", [$partnerId]);
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
        $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ?", [$partnerId]);
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
        $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ?", [$partnerId]);
        if (!$partner) throw new Exception("Partner not found");
        if (($partner['partner_type'] ?? 'MAIN') !== 'MAIN') throw new Exception("Only main partners can use manual partner settlement entries.");
        if ($amount <= 0) throw new Exception("Settlement amount must be greater than zero.");

        $direction = strtoupper($direction);
        if (!in_array($direction, ['PAY', 'RECEIVE'], true)) {
            throw new Exception("Invalid settlement direction.");
        }

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
        return $entryId;
    }

    /**
     * SALARY PAYMENT (with advance recovery)
     */
    public function salaryPayment($employeeId, $grossSalary, $advanceDeduction, $date, $paymentAccount, $month, $year) {
        $employee = $this->db->fetch("SELECT * FROM employees WHERE id = ?", [$employeeId]);
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

        // RULE 6: Check duplicate salary
        $existing = $this->db->fetch(
            "SELECT id FROM salary_records WHERE employee_id = ? AND month = ? AND year = ?",
            [$employeeId, $month, $year]
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

        return $entryId;
    }

    /**
     * EMPLOYEE ADVANCE
     */
    public function employeeAdvance($employeeId, $amount, $date, $paymentAccount, $narration) {
        $employee = $this->db->fetch("SELECT * FROM employees WHERE id = ?", [$employeeId]);
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
    public function loanGiven($partyName, $amount, $date, $paymentAccount, $narration) {
        $this->validateCashAvailable($paymentAccount, $amount);
        $partyId = $this->getOrCreateParty($partyName, 'DEBTOR');
        $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);

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
        $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);
        if (!$party) throw new Exception("Party not found");
        if (!in_array($party['type'], ['DEBTOR', 'BUYER'], true)) {
            throw new Exception("Money can be received back only from a debtor or buyer account.");
        }
        if ($carId) {
            $car = $this->db->fetch("SELECT id, buyer_party_id FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
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
            ['account_id' => $receivingAccount, 'amount' => $amount, 'type' => 'DR', 'narration' => "Received from {$party['name']}"],
            ['account_id' => $party['account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => "Loan repaid by {$party['name']}"],
        ];

        $entryId = $this->postJournalEntry('LOAN_RECEIVED', $date, $narration, $lines, ['party_id' => $partyId, 'car_id' => $carId ?: null]);
        $this->refreshPendingCarSaleStatusesForParty($partyId);
        return $entryId;
    }

    /**
     * LOAN TAKEN (borrowed money)
     */
    public function loanTaken($partyName, $amount, $date, $receivingAccount, $narration) {
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            throw new Exception("Borrowed amount must be greater than zero.");
        }
        $partyId = $this->getOrCreateParty($partyName, 'CREDITOR');
        $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);

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
        $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);
        if (!$party) throw new Exception("Party not found");
        if (!in_array($party['type'], ['CREDITOR', 'SELLER'], true)) {
            throw new Exception("Loan repayment is allowed only against creditor or seller balances.");
        }
        if ($carId) {
            $car = $this->db->fetch("SELECT id, seller_party_id FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
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
            ['account_id' => $party['account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Repaid to {$party['name']}"],
            ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Loan repaid to {$party['name']}"],
        ];

        return $this->postJournalEntry('LOAN_REPAID', $date, $narration, $lines, ['party_id' => $partyId, 'car_id' => $carId ?: null]);
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
    public function reverseEntry($entryId, $reason) {
        $entry = $this->db->fetch("SELECT * FROM journal_entries WHERE id = ? AND business_id = ?", [$entryId, $this->businessId]);
        if (!$entry) throw new Exception("Entry not found");
        if ($entry['status'] === 'REVERSED') throw new Exception("Entry is already reversed");

        // RULE 7: Check period lock
        $this->validateDateNotLocked($entry['entry_date']);

        $lines = $this->db->fetchAll("SELECT * FROM journal_lines WHERE journal_entry_id = ?", [$entryId]);
        $this->assertEntryCanBeReversed($entry, $lines);

        $this->db->beginTransaction();
        try {
            $reversalId = $this->createReversalEntry($entry, $lines, $reason);
            $this->applyReversalBusinessEffects($entry, $lines, $reversalId);

            foreach ($this->getDependentEntriesForReversal($entry, $lines) as $dependentEntry) {
                $dependentLines = $this->db->fetchAll("SELECT * FROM journal_lines WHERE journal_entry_id = ?", [$dependentEntry['id']]);
                $this->assertEntryCanBeReversed($dependentEntry, $dependentLines, false);
                $linkedReason = "Linked reversal for {$entry['reference_no']}: {$reason}";
                $linkedReversalId = $this->createReversalEntry($dependentEntry, $dependentLines, $linkedReason);
                $this->applyReversalBusinessEffects($dependentEntry, $dependentLines, $linkedReversalId);
            }

            Auth::auditLog('REVERSE', 'journal_entry', $entryId, "Reversed entry: $reason");
            $this->db->commit();
            return $reversalId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function createReversalEntry($entry, $lines, $reason) {
        $reversalLines = [];
        foreach ($lines as $line) {
            $reversalLines[] = [
                'account_id' => $line['account_id'],
                'amount' => $line['amount'],
                'type' => $line['entry_type'] === 'DR' ? 'CR' : 'DR',
                'narration' => 'Reversal: ' . ($line['narration'] ?? ''),
            ];
        }

        $reversalId = Database::uuid();
        $refNo = getNextRefNo($this->db, $this->businessId, date('Y-m-d'), 'REV');
        $fy = getCurrentFY();

        $this->db->insert('journal_entries', [
            'id' => $reversalId,
            'business_id' => $this->businessId,
            'entry_date' => date('Y-m-d'),
            'reference_no' => $refNo,
            'narration' => "REVERSAL: $reason (Original: {$entry['reference_no']})",
            'transaction_type' => 'REVERSAL',
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
            ]);
            $this->updateAccountBalance($line['account_id'], $line['amount'], $line['type']);
        }

        $this->db->query("UPDATE journal_entries SET status = 'REVERSED', reversed_by = ? WHERE id = ?", [$reversalId, $entry['id']]);
        return $reversalId;
    }

    private function assertEntryCanBeReversed($entry, $lines, $allowLinkedGuard = true) {
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
            ? $this->db->fetch("SELECT * FROM accounts WHERE id = ?", [$car['account_id']])
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
        $account = $this->db->fetch("SELECT * FROM accounts WHERE id = ?", [$accountId]);
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
    public function getOrCreateParty($name, $type) {
        $existing = $this->db->fetch(
            "SELECT id FROM debtors_creditors WHERE business_id = ? AND name = ? AND type = ?",
            [$this->businessId, $name, $type]
        );
        if ($existing) return $existing['id'];

        $partyId = Database::uuid();
        $accountGroup = in_array($type, ['DEBTOR', 'BUYER']) ? 'ASSET' : 'LIABILITY';
        $subGroup = in_array($type, ['DEBTOR', 'BUYER']) ? 'Sundry Debtors' : 'Sundry Creditors';
        $entityType = in_array($type, ['DEBTOR', 'BUYER']) ? 'DEBTOR' : 'CREDITOR';
        $code = strtoupper(substr($type, 0, 3)) . '-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 8));

        $accountId = $this->createAccount($code, "$name ($type)", $accountGroup, $subGroup, $entityType, $partyId);

        $this->db->insert('debtors_creditors', [
            'id' => $partyId,
            'business_id' => $this->businessId,
            'name' => $name,
            'type' => $type,
            'account_id' => $accountId,
        ]);

        return $partyId;
    }

    // ========================================
    // CAR HELPERS
    // ========================================
    public function getCarTotalCost($carId) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
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
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
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

        $allocations = $this->normalizeVoucherAllocations($allocations, $primaryEntryType);
        $allocatedTotal = round(array_sum(array_column($allocations, 'amount')), 2);
        if (abs($primaryAmount - $allocatedTotal) > 0.01) {
            throw new Exception("Voucher is not balanced yet. Primary amount and allocations must match.");
        }

        $voucherId = Database::uuid();
        $referenceNo = $this->getNextVoucherRefNo($date);
        $fy = getCurrentFY($date);

        $this->db->beginTransaction();
        try {
            $this->db->insert('journal_vouchers', [
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
            ]);

            foreach ($allocations as $allocation) {
                $this->db->insert('journal_voucher_lines', [
                    'id' => Database::uuid(),
                    'journal_voucher_id' => $voucherId,
                    'account_id' => $allocation['account_id'],
                    'amount' => $allocation['amount'],
                    'entry_type' => $allocation['entry_type'],
                    'narration' => $allocation['narration'] ?? null,
                ]);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($status === 'POSTED') {
            $this->postJournalVoucher($voucherId);
        }

        return $voucherId;
    }

    public function postJournalVoucher($voucherId) {
        $voucher = $this->db->fetch(
            "SELECT * FROM journal_vouchers WHERE id = ? AND business_id = ?",
            [$voucherId, $this->businessId]
        );
        if (!$voucher) throw new Exception("Journal voucher not found.");
        if ($voucher['status'] === 'POSTED' && !empty($voucher['posted_entry_id'])) {
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
        ], 'id = ?', [$voucherId]);

        return $entryId;
    }

    public function getCarPartnerships($carId) {
        return $this->db->fetchAll(
            "SELECT cp.*, p.name as partner_name, p.current_account_id, p.capital_account_id
             FROM car_partnerships cp
             JOIN partners p ON p.id = cp.partner_id
             WHERE cp.business_id = ? AND cp.car_id = ?
             ORDER BY cp.created_at",
            [$this->businessId, $carId]
        );
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
                    a.opening_balance, a.opening_balance_type,
                    COALESCE(SUM(CASE WHEN je.status = 'POSTED' AND je.entry_date <= ? AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) as posted_dr,
                    COALESCE(SUM(CASE WHEN je.status = 'POSTED' AND je.entry_date <= ? AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) as posted_cr
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
            $openingDr = strtoupper($row['opening_balance_type']) === 'DR' ? floatval($row['opening_balance']) : 0.0;
            $openingCr = strtoupper($row['opening_balance_type']) === 'CR' ? floatval($row['opening_balance']) : 0.0;
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
             JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'POSTED'
             WHERE a.business_id = ? AND a.group_name = 'INCOME' AND je.entry_date BETWEEN ? AND ?
             GROUP BY a.id, a.name HAVING amount > 0 ORDER BY amount DESC",
            [$this->businessId, $fromDate, $toDate]
        );

        $expenses = $this->db->fetchAll(
            "SELECT a.name, SUM(CASE WHEN jl.entry_type = 'DR' THEN jl.amount ELSE 0 END) - SUM(CASE WHEN jl.entry_type = 'CR' THEN jl.amount ELSE 0 END) as amount
             FROM accounts a
             JOIN journal_lines jl ON jl.account_id = a.id
             JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'POSTED'
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
            $outstanding = round(array_sum(array_column($openItems, 'outstanding_amount')), 2);
            if ($outstanding <= 0.009) {
                continue;
            }

            $oldestDate = !empty($openItems) ? min(array_column($openItems, 'entry_date')) : null;
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
                'open_item_count' => count($openItems),
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

    public function getJournalVoucherRegister($fromDate = null, $toDate = null, $accessibleAccountIds = []) {
        $fromDate = $fromDate ?: date('Y-m-01');
        $toDate = $toDate ?: date('Y-m-d');
        $sql = "SELECT jv.*, pa.name as primary_account_name, u.full_name as created_by_name, je.reference_no as posted_reference_no
                FROM journal_vouchers jv
                JOIN accounts pa ON pa.id = jv.primary_account_id
                JOIN users u ON u.id = jv.created_by
                LEFT JOIN journal_entries je ON je.id = jv.posted_entry_id
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
            "SELECT jvl.*, a.name as account_name, a.code as account_code, a.group_name, a.sub_group, c.registration_no as car_reg
             FROM journal_voucher_lines jvl
             JOIN accounts a ON a.id = jvl.account_id
             LEFT JOIN cars c ON c.account_id = a.id
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
            if (!$partnerId || $amount <= 0) {
                continue;
            }

            if (!isset($grouped[$partnerId])) {
                $partner = $this->db->fetch("SELECT id, profit_share_pct FROM partners WHERE id = ?", [$partnerId]);
                if (!$partner) {
                    continue;
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
                $grouped[$partnerId]['profit_share_pct'] = floatval($row['profit_share_pct']);
            }
        }

        $normalized = [];
        $profitShareTotal = 0.0;
        foreach ($grouped as $partnerId => $row) {
            $fundingPct = $purchaseAmount > 0 ? round(($row['amount'] / $purchaseAmount) * 100, 4) : 0.0;
            $profitPct = $row['profit_share_pct'];
            if ($profitPct === null || $profitPct <= 0) {
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

        if (!empty($normalized) && $profitShareTotal > 0 && abs($profitShareTotal - 100) > 0.01) {
            foreach ($normalized as &$row) {
                $row['profit_share_pct'] = round(($row['profit_share_pct'] / $profitShareTotal) * 100, 4);
            }
            unset($row);
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
        $distributedTotal = 0.0;
        $remainingRows = count($partnerships);
        foreach ($partnerships as $partnership) {
            $remainingRows--;
            $shareAmount = $remainingRows === 0
                ? round($profit - $distributedTotal, 2)
                : round($profit * (floatval($partnership['profit_share_pct']) / 100), 2);

            if (abs($shareAmount) < 0.01) {
                continue;
            }

            $distributedTotal += $shareAmount;
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

            $normalized[] = [
                'account_id' => $accountId,
                'amount' => $amount,
                'entry_type' => strtoupper($row['entry_type'] ?? $counterType),
                'narration' => trim((string) ($row['narration'] ?? '')),
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
        $this->db->beginTransaction();
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

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
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
                    je.narration AS entry_narration,
                    je.entry_date,
                    je.is_reversal,
                    je.created_at,
                    jl.id AS journal_line_id,
                    jl.entry_type,
                    jl.amount,
                    jl.narration AS line_narration
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
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
