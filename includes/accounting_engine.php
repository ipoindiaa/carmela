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
            ['GST-001', 'GST Bank Account', 'ASSET', 'Current Assets', 'GST'],
            ['SAL-EXP', 'Salary Expense', 'EXPENSE', 'Indirect Expenses', 'GENERAL'],
            ['RENT-EXP', 'Office Rent', 'EXPENSE', 'Indirect Expenses', 'GENERAL'],
            ['MISC-EXP', 'Miscellaneous Expense', 'EXPENSE', 'Indirect Expenses', 'GENERAL'],
            ['CAR-REV', 'Car Sales Revenue', 'INCOME', 'Direct Income', 'GENERAL'],
            ['PNL', 'Profit & Loss Account', 'INCOME', 'P&L', 'GENERAL'],
            ['GST-PAY', 'GST Payable', 'LIABILITY', 'GST Liabilities', 'GENERAL'],
            ['GST-RCV', 'GST Input Credit', 'ASSET', 'GST Assets', 'GENERAL'],
            ['BAD-DEBT', 'Bad Debt Expense', 'EXPENSE', 'Direct Expenses', 'GENERAL'],
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

            if (!$this->columnExists('journal_entries', 'journal_voucher_id')) {
                $this->db->query("ALTER TABLE `journal_entries` ADD COLUMN `journal_voucher_id` CHAR(36) DEFAULT NULL AFTER `party_id`");
            }
            if (!$this->columnExists('car_partner_contributions', 'funding_pct')) {
                $this->db->query("ALTER TABLE `car_partner_contributions` ADD COLUMN `funding_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER `amount`");
            }
            if (!$this->columnExists('car_partner_contributions', 'profit_share_pct')) {
                $this->db->query("ALTER TABLE `car_partner_contributions` ADD COLUMN `profit_share_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER `funding_pct`");
            }

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
        $required = ['JOURNAL_VOUCHER', 'PARTNER_SETTLEMENT'];
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
                 ENUM('CAR_PURCHASE','CAR_SALE','CAR_EXPENSE','GENERAL_EXPENSE','JOURNAL_VOUCHER','PARTNER_INVEST','PARTNER_WITHDRAW','PARTNER_SETTLEMENT','SALARY_PAYMENT','EMPLOYEE_ADVANCE','LOAN_GIVEN','LOAN_RECEIVED','LOAN_TAKEN','LOAN_REPAID','CONTRA_TRANSFER','GST_PAYMENT','OPENING_BALANCE','REVERSAL','BAD_DEBT','PROFIT_DISTRIBUTION')
                 NOT NULL"
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

    // ========================================
    // CORE: Post a journal entry with balanced Dr/Cr lines
    // ========================================
    public function postJournalEntry($type, $date, $narration, $lines, $extras = []) {
        $this->validateDateNotLocked($date);

        // Validate balance: Dr must equal Cr
        $totalDr = 0;
        $totalCr = 0;
        foreach ($lines as $line) {
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
    public function carPurchase($carId, $amount, $date, $paymentAccount, $narration, $partnerFunding = []) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
        if (!$car) throw new Exception("Car not found");

        $carAccountId = $car['account_id'];
        $lines = [];
        $partnerFunding = $this->normalizePartnerFunding($amount, $partnerFunding);

        // Business funding
        $businessAmount = $amount;
        foreach ($partnerFunding as $pf) {
            $businessAmount -= $pf['amount'];
        }

        if ($businessAmount < -0.01) {
            throw new Exception("Partner funding cannot exceed the car purchase amount.");
        }

        if ($businessAmount > 0) {
            // Validate: check payment account balance
            $this->validateCashAvailable($paymentAccount, $businessAmount);
            
            $lines[] = ['account_id' => $carAccountId, 'amount' => $businessAmount, 'type' => 'DR', 'narration' => 'Car purchase - business funds'];
            $lines[] = ['account_id' => $paymentAccount, 'amount' => $businessAmount, 'type' => 'CR', 'narration' => 'Paid for car purchase'];
        }

        $entryId = null;
        if (!empty($lines)) {
            $entryId = $this->postJournalEntry('CAR_PURCHASE', $date, $narration, $lines, ['car_id' => $carId]);
        }

        // Partner funding entries
        foreach ($partnerFunding as $pf) {
            $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ?", [$pf['partner_id']]);
            if (!$partner) continue;

            $partnerLines = [
                ['account_id' => $carAccountId, 'amount' => $pf['amount'], 'type' => 'DR', 'narration' => "Partner {$partner['name']} contribution"],
                ['account_id' => $partner['capital_account_id'], 'amount' => $pf['amount'], 'type' => 'CR', 'narration' => "Investment in car {$car['registration_no']}"],
            ];
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

        return $entryId;
    }

    /**
     * CAR SALE — Full or partial payment
     */
    public function carSale($carId, $salePrice, $date, $receivingAccount, $narration, $buyerName = null, $amountReceived = null) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
        if (!$car) throw new Exception("Car not found");
        if ($car['status'] === 'SOLD') throw new Exception("Car is already sold");

        $carAccountId = $car['account_id'];
        $totalCost = $this->getCarTotalCost($carId);
        $received = $amountReceived ?? $salePrice;
        $outstanding = $salePrice - $received;
        $profit = $salePrice - $totalCost;

        $lines = [];
        $lines[] = ['account_id' => $receivingAccount, 'amount' => $received, 'type' => 'DR', 'narration' => 'Sale amount received'];
        
        if ($outstanding > 0 && $buyerName) {
            // Create debtor for outstanding amount
            $partyId = $this->getOrCreateParty($buyerName, 'BUYER');
            $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);
            $lines[] = ['account_id' => $party['account_id'], 'amount' => $outstanding, 'type' => 'DR', 'narration' => "Outstanding from $buyerName"];
        }

        // Revenue entry
        $revenueAccount = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'CAR-REV'", [$this->businessId]);
        $lines[] = ['account_id' => $revenueAccount['id'], 'amount' => $salePrice, 'type' => 'CR', 'narration' => "Car sale revenue - {$car['registration_no']}"];

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
        $this->db->query("UPDATE cars SET status = ?, sold_date = ?, sale_price = ?, buyer_name = ? WHERE id = ?",
            [$status, $date, $salePrice, $buyerName, $carId]);

        $this->recordPartnerProfitDistribution($carId, $profit, $date);

        return $entryId;
    }

    /**
     * CAR EXPENSE
     */
    public function carExpense($carId, $amount, $date, $paymentAccount, $categoryName, $narration) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
        if (!$car) throw new Exception("Car not found");

        // RULE 3: Sold car cannot receive new expenses without admin override
        if ($car['status'] === 'SOLD') {
            throw new Exception("Cannot add expenses to a sold car. Admin override required.");
        }

        $this->validateCashAvailable($paymentAccount, $amount);

        $expenseAccountId = $this->getOrCreateExpenseAccount($categoryName . ' - ' . $car['registration_no'], 'CAR_SPECIFIC');

        $lines = [
            ['account_id' => $expenseAccountId, 'amount' => $amount, 'type' => 'DR', 'narration' => $narration],
            ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Paid for {$categoryName}"],
        ];

        // Also debit the car asset account to track total cost
        // We create a separate entry for the car account
        $carLines = [
            ['account_id' => $car['account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "$categoryName for {$car['registration_no']}"],
            ['account_id' => $expenseAccountId, 'amount' => $amount, 'type' => 'CR', 'narration' => "Expense allocated to car"],
        ];

        $entryId = $this->postJournalEntry('CAR_EXPENSE', $date, $narration, $lines, ['car_id' => $carId]);
        $this->postJournalEntry('CAR_EXPENSE', $date, "Allocate {$categoryName} to {$car['registration_no']}", $carLines, ['car_id' => $carId]);
        return $entryId;
    }

    /**
     * GENERAL EXPENSE
     */
    public function generalExpense($amount, $date, $paymentAccount, $categoryName, $narration) {
        $this->validateCashAvailable($paymentAccount, $amount);
        $expenseAccountId = $this->getOrCreateExpenseAccount($categoryName);

        $lines = [
            ['account_id' => $expenseAccountId, 'amount' => $amount, 'type' => 'DR', 'narration' => $narration],
            ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Paid for $categoryName"],
        ];

        return $this->postJournalEntry('GENERAL_EXPENSE', $date, $narration, $lines);
    }

    /**
     * PARTNER INVEST
     */
    public function partnerInvest($partnerId, $amount, $date, $receivingAccount, $narration) {
        $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ?", [$partnerId]);
        if (!$partner) throw new Exception("Partner not found");

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
                "Withdrawal amount (₹" . number_format($amount, 2) . ") exceeds available partner funds (₹" . number_format($availableBalance, 2) . ")."
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
        if ($amount <= 0) throw new Exception("Settlement amount must be greater than zero.");

        $direction = strtoupper($direction);
        if (!in_array($direction, ['PAY', 'RECEIVE'], true)) {
            throw new Exception("Invalid settlement direction.");
        }

        if ($direction === 'PAY') {
            $this->validateCashAvailable($accountId, $amount);
            $lines = [
                ['account_id' => $partner['current_account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Settlement paid to {$partner['name']}"],
                ['account_id' => $accountId, 'amount' => $amount, 'type' => 'CR', 'narration' => "Settlement paid to {$partner['name']}"],
            ];
            $entryId = $this->postJournalEntry('PARTNER_SETTLEMENT', $date, $narration, $lines, ['partner_id' => $partnerId]);
            $this->applyPartnerSettlement($partnerId, $amount, 'PAYABLE', $entryId, $date);
            return $entryId;
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

        // RULE 6: Check duplicate salary
        $existing = $this->db->fetch(
            "SELECT id FROM salary_records WHERE employee_id = ? AND month = ? AND year = ?",
            [$employeeId, $month, $year]
        );
        if ($existing) throw new Exception("Salary already processed for {$employee['name']} for $month/$year");

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
    public function loanReceived($partyId, $amount, $date, $receivingAccount, $narration) {
        $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);
        if (!$party) throw new Exception("Party not found");

        $lines = [
            ['account_id' => $receivingAccount, 'amount' => $amount, 'type' => 'DR', 'narration' => "Received from {$party['name']}"],
            ['account_id' => $party['account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => "Loan repaid by {$party['name']}"],
        ];

        return $this->postJournalEntry('LOAN_RECEIVED', $date, $narration, $lines, ['party_id' => $partyId]);
    }

    /**
     * LOAN TAKEN (borrowed money)
     */
    public function loanTaken($partyName, $amount, $date, $receivingAccount, $narration) {
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
    public function loanRepaid($partyId, $amount, $date, $paymentAccount, $narration) {
        $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ?", [$partyId]);
        if (!$party) throw new Exception("Party not found");

        $this->validateCashAvailable($paymentAccount, $amount);

        $lines = [
            ['account_id' => $party['account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Repaid to {$party['name']}"],
            ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Loan repaid to {$party['name']}"],
        ];

        return $this->postJournalEntry('LOAN_REPAID', $date, $narration, $lines, ['party_id' => $partyId]);
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
    public function gstPayment($amount, $date, $narration) {
        $gstPayable = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND code = 'GST-PAY'", [$this->businessId]);
        $gstBank = $this->db->fetch("SELECT id FROM accounts WHERE business_id = ? AND entity_type = 'GST'", [$this->businessId]);

        $lines = [
            ['account_id' => $gstPayable['id'], 'amount' => $amount, 'type' => 'DR', 'narration' => 'GST liability paid'],
            ['account_id' => $gstBank['id'], 'amount' => $amount, 'type' => 'CR', 'narration' => 'Paid from GST Bank'],
        ];

        return $this->postJournalEntry('GST_PAYMENT', $date, $narration, $lines);
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

        $reversalLines = [];
        foreach ($lines as $line) {
            $reversalLines[] = [
                'account_id' => $line['account_id'],
                'amount' => $line['amount'],
                'type' => $line['entry_type'] === 'DR' ? 'CR' : 'DR',
                'narration' => 'Reversal: ' . ($line['narration'] ?? ''),
            ];
        }

        $this->db->beginTransaction();
        try {
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
                'original_entry_id' => $entryId,
                'status' => 'POSTED',
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

            // Mark original as reversed
            $this->db->query("UPDATE journal_entries SET status = 'REVERSED', reversed_by = ? WHERE id = ?", [$reversalId, $entryId]);

            Auth::auditLog('REVERSE', 'journal_entry', $entryId, "Reversed entry: $reason");
            $this->db->commit();
            return $reversalId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ========================================
    // VALIDATION HELPERS
    // ========================================
    private function validateCashAvailable($accountId, $amount) {
        $account = $this->db->fetch("SELECT * FROM accounts WHERE id = ?", [$accountId]);
        if (!$account) throw new Exception("Payment account not found");

        if ($account['entity_type'] === 'CASH') {
            $business = $this->db->fetch("SELECT min_cash_balance FROM businesses WHERE id = ?", [$this->businessId]);
            $minBalance = $business['min_cash_balance'] ?? 0;
            $currentBalance = $account['current_balance'];

            if (($currentBalance - $amount) < $minBalance) {
                throw new Exception("Insufficient cash balance. Current: ₹" . number_format($currentBalance, 2) . ", Required: ₹" . number_format($amount, 2) . ", Minimum: ₹" . number_format($minBalance, 2));
            }
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

        $account = $this->db->fetch("SELECT current_balance FROM accounts WHERE id = ?", [$car['account_id']]);
        return $account['current_balance'] ?? 0;
    }

    public function getCarProfitability($carId) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ?", [$carId]);
        if (!$car) return null;

        $totalCost = $this->getCarTotalCost($carId);
        $salePrice = $car['sale_price'] ?? 0;
        $profit = $salePrice - $totalCost;
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

    public function getJournalVoucherRegister($fromDate = null, $toDate = null) {
        $fromDate = $fromDate ?: date('Y-m-01');
        $toDate = $toDate ?: date('Y-m-d');
        return $this->db->fetchAll(
            "SELECT jv.*, pa.name as primary_account_name, u.full_name as created_by_name, je.reference_no as posted_reference_no
             FROM journal_vouchers jv
             JOIN accounts pa ON pa.id = jv.primary_account_id
             JOIN users u ON u.id = jv.created_by
             LEFT JOIN journal_entries je ON je.id = jv.posted_entry_id
             WHERE jv.business_id = ?
               AND jv.voucher_date BETWEEN ? AND ?
             ORDER BY jv.voucher_date DESC, jv.created_at DESC",
            [$this->businessId, $fromDate, $toDate]
        );
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

            $remaining = round($remaining - $applied, 2);
        }
    }

    // ========================================
    // ALERTS
    // ========================================
    private function checkAlerts() {
        // Check cash balance
        $cashAccount = $this->db->fetch("SELECT * FROM accounts WHERE business_id = ? AND entity_type = 'CASH' AND entity_id IS NULL", [$this->businessId]);
        $business = $this->db->fetch("SELECT * FROM businesses WHERE id = ?", [$this->businessId]);

        if ($cashAccount && $cashAccount['current_balance'] < ($business['min_cash_balance'] ?? 0)) {
            $this->createAlert('CASH_LOW', "Cash balance (₹" . number_format($cashAccount['current_balance'], 2) . ") is below minimum threshold", 'account', $cashAccount['id']);
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

    private function getBusinessSetting($key) {
        $business = $this->db->fetch("SELECT * FROM businesses WHERE id = ?", [$this->businessId]);
        return $business[$key] ?? null;
    }
}
