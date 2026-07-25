-- AutoBooks Pro - Complete Database Schema
-- MySQL 8.0+

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+05:30";

CREATE DATABASE IF NOT EXISTS `autobooks_pro` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `autobooks_pro`;

-- ============================================================
-- TABLE: businesses
-- ============================================================
CREATE TABLE `businesses` (
    `id` CHAR(36) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `gstin` VARCHAR(15) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `fy_start_month` INT NOT NULL DEFAULT 4,
    `currency` CHAR(3) NOT NULL DEFAULT 'INR',
    `min_cash_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `max_single_txn_limit` DECIMAL(15,2) DEFAULT NULL,
    `advance_limit_months` INT NOT NULL DEFAULT 1,
    `auto_lock_days` INT NOT NULL DEFAULT 10,
    `period_lock_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE `users` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `username` VARCHAR(50) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(200) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `role` ENUM('ADMIN','PARTNER','ACCOUNTANT','OPERATOR') NOT NULL DEFAULT 'OPERATOR',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    KEY `fk_users_business` (`business_id`),
    CONSTRAINT `fk_users_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: accounts (Chart of Accounts)
-- ============================================================
CREATE TABLE `accounts` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `group_name` ENUM('ASSET','LIABILITY','INCOME','EXPENSE','EQUITY','CONTRA') NOT NULL,
    `sub_group` VARCHAR(100) DEFAULT NULL,
    `entity_type` ENUM('CASH','BANK','GST','CAR','PARTNER','EMPLOYEE','DEBTOR','CREDITOR','GENERAL') NOT NULL DEFAULT 'GENERAL',
    `entity_id` CHAR(36) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `opening_balance_type` ENUM('DR','CR') NOT NULL DEFAULT 'DR',
    `opening_balance_date` DATE DEFAULT NULL,
    `opening_entry_id` CHAR(36) DEFAULT NULL,
    `current_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `current_balance_type` ENUM('DR','CR') NOT NULL DEFAULT 'DR',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_account_code` (`business_id`, `code`),
    KEY `idx_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_accounts_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: journal_entries
-- ============================================================
CREATE TABLE `journal_entries` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `entry_date` DATE NOT NULL,
    `reference_no` VARCHAR(50) NOT NULL,
    `narration` TEXT DEFAULT NULL,
    `transaction_type` ENUM('CAR_PURCHASE','CAR_TOKEN_RECEIVED','CAR_SALE','RTO_EXPENSE','RTO_RECOVERY','CAR_EXPENSE','GENERAL_EXPENSE','JOURNAL_VOUCHER','PARTNER_INVEST','PARTNER_WITHDRAW','PARTNER_SETTLEMENT','SALARY_PAYMENT','EMPLOYEE_COMMISSION','EMPLOYEE_ADVANCE','EMPLOYEE_ADVANCE_WRITEOFF','LOAN_GIVEN','LOAN_RECEIVED','LOAN_TAKEN','LOAN_REPAID','CONTRA_TRANSFER','GST_PAYMENT','GST_UTILIZATION','OPENING_BALANCE','REVERSAL','BAD_DEBT','PROFIT_DISTRIBUTION') NOT NULL,
    `entry_type_id` VARCHAR(80) DEFAULT NULL,
    `entry_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `is_reversal` TINYINT(1) NOT NULL DEFAULT 0,
    `reversed_by` CHAR(36) DEFAULT NULL,
    `original_entry_id` CHAR(36) DEFAULT NULL,
    `status` ENUM('DRAFT','POSTED','REVERSED') NOT NULL DEFAULT 'POSTED',
    `car_id` CHAR(36) DEFAULT NULL,
    `partner_id` CHAR(36) DEFAULT NULL,
    `employee_id` CHAR(36) DEFAULT NULL,
    `party_id` CHAR(36) DEFAULT NULL,
    `journal_voucher_id` CHAR(36) DEFAULT NULL,
    `corrected_from_id` CHAR(36) DEFAULT NULL,
    `corrected_by_id` CHAR(36) DEFAULT NULL,
    `correction_reason` VARCHAR(500) DEFAULT NULL,
    `version_no` INT NOT NULL DEFAULT 1,
    `created_by` CHAR(36) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `financial_year` INT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_reference` (`business_id`, `reference_no`),
    KEY `idx_date` (`entry_date`),
    KEY `idx_type` (`transaction_type`),
    KEY `idx_entry_type_id` (`business_id`, `entry_type_id`, `entry_date`, `status`),
    KEY `idx_status` (`status`),
    KEY `idx_correction_from` (`corrected_from_id`),
    CONSTRAINT `fk_je_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_je_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: journal_lines
-- ============================================================
CREATE TABLE `journal_lines` (
    `id` CHAR(36) NOT NULL,
    `journal_entry_id` CHAR(36) NOT NULL,
    `account_id` CHAR(36) NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `entry_type` ENUM('DR','CR') NOT NULL,
    `narration` VARCHAR(500) DEFAULT NULL,
    `source_voucher_line_id` CHAR(36) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_account` (`account_id`),
    KEY `idx_jl_source_voucher_line` (`source_voucher_line_id`),
    CONSTRAINT `fk_jl_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`),
    CONSTRAINT `fk_jl_account` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: cars
-- ============================================================
CREATE TABLE `cars` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `registration_no` VARCHAR(20) NOT NULL,
    `make` VARCHAR(100) DEFAULT NULL,
    `model` VARCHAR(100) DEFAULT NULL,
    `year` INT DEFAULT NULL,
    `color` VARCHAR(50) DEFAULT NULL,
    `purchase_date` DATE NOT NULL,
    `purchase_price` DECIMAL(15,2) NOT NULL,
    `purchase_paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `ownership_type` ENUM('OWNED','COMMISSION','OUTSIDE') NOT NULL DEFAULT 'OWNED',
    `commission_owner_party_id` CHAR(36) DEFAULT NULL,
    `expected_sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `expected_commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('IN_STOCK','SOLD','PENDING_PAYMENT','CANCELLED') NOT NULL DEFAULT 'IN_STOCK',
    `account_id` CHAR(36) DEFAULT NULL,
    `sold_date` DATE DEFAULT NULL,
    `sale_price` DECIMAL(15,2) DEFAULT NULL,
    `sale_commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `sale_gst_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `buyer_name` VARCHAR(200) DEFAULT NULL,
    `buyer_contact` VARCHAR(20) DEFAULT NULL,
    `buyer_party_id` CHAR(36) DEFAULT NULL,
    `seller_party_id` CHAR(36) DEFAULT NULL,
    `partner_id` CHAR(36) DEFAULT NULL,
    `has_second_key` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_car_reg` (`business_id`, `registration_no`),
    KEY `idx_status` (`status`),
    KEY `idx_car_partner` (`partner_id`),
    CONSTRAINT `fk_cars_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_cars_account` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: partners
-- ============================================================
CREATE TABLE `partners` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `partner_type` ENUM('MAIN','CARWISE') NOT NULL DEFAULT 'MAIN',
    `phone` VARCHAR(20) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `pan` VARCHAR(10) DEFAULT NULL,
    `profit_share_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `capital_account_id` CHAR(36) DEFAULT NULL,
    `current_account_id` CHAR(36) DEFAULT NULL,
    `joined_date` DATE NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_partners_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `cars`
    ADD CONSTRAINT `fk_cars_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners`(`id`);

-- ============================================================
-- TABLE: car_partner_contributions
-- ============================================================
CREATE TABLE `car_partner_contributions` (
    `id` CHAR(36) NOT NULL,
    `car_id` CHAR(36) NOT NULL,
    `partner_id` CHAR(36) NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `funding_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
    `profit_share_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
    `contribution_date` DATE NOT NULL,
    `journal_entry_id` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_cpc_car` FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`),
    CONSTRAINT `fk_cpc_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners`(`id`),
    CONSTRAINT `fk_cpc_je` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: car_partnerships
-- ============================================================
CREATE TABLE `car_partnerships` (
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
    KEY `idx_cp_business` (`business_id`),
    CONSTRAINT `fk_cp_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_cp_car` FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`),
    CONSTRAINT `fk_cp_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: partner_profit_settlements
-- ============================================================
CREATE TABLE `partner_profit_settlements` (
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
    KEY `idx_pps_partner_status` (`partner_id`, `status`),
    CONSTRAINT `fk_pps_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_pps_car` FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`),
    CONSTRAINT `fk_pps_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: partner_settlement_applications
-- ============================================================
CREATE TABLE `partner_settlement_applications` (
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
    KEY `idx_psa_settlement` (`partner_profit_settlement_id`),
    CONSTRAINT `fk_psa_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_psa_settlement` FOREIGN KEY (`partner_profit_settlement_id`) REFERENCES `partner_profit_settlements`(`id`),
    CONSTRAINT `fk_psa_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: employees
-- ============================================================
CREATE TABLE `employees` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `role` VARCHAR(100) DEFAULT NULL,
    `monthly_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `advance_account_id` CHAR(36) DEFAULT NULL,
    `join_date` DATE NOT NULL,
    `exit_date` DATE DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `emergency_contact_name` VARCHAR(200) DEFAULT NULL,
    `emergency_contact_phone` VARCHAR(20) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_employees_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: salary_records
-- ============================================================
CREATE TABLE `salary_records` (
    `id` CHAR(36) NOT NULL,
    `employee_id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `month` INT NOT NULL,
    `year` INT NOT NULL,
    `gross_salary` DECIMAL(10,2) NOT NULL,
    `advance_deducted` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `net_paid` DECIMAL(10,2) NOT NULL,
    `payment_mode` ENUM('CASH','BANK') NOT NULL DEFAULT 'CASH',
    `journal_entry_id` CHAR(36) DEFAULT NULL,
    `processed_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_salary_month` (`employee_id`, `month`, `year`),
    CONSTRAINT `fk_salary_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`),
    CONSTRAINT `fk_salary_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: debtors_creditors (Parties)
-- ============================================================
CREATE TABLE `debtors_creditors` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `type` ENUM('DEBTOR','CREDITOR','BUYER','SELLER') NOT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `pan_gstin` VARCHAR(15) DEFAULT NULL,
    `account_id` CHAR(36) DEFAULT NULL,
    `is_bad_debt` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_dc_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: car_tokens (buyer advances held against a specific car)
-- ============================================================
CREATE TABLE `car_tokens` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: commission_car_settlements
-- Gross sale value is memorandum data. Only commission is income.
-- ============================================================
CREATE TABLE `commission_car_settlements` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `commission_owner_payments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: financial_years
-- ============================================================
CREATE TABLE `financial_years` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `year_label` VARCHAR(20) NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_fy_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: audit_log
-- ============================================================
CREATE TABLE `audit_log` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) DEFAULT NULL,
    `action` ENUM('CREATE','VIEW','REVERSE','EXPORT','LOGIN','LOGOUT','SETTING_CHANGE','DELETE','UPDATE') NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` CHAR(36) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `old_value` JSON DEFAULT NULL,
    `new_value` JSON DEFAULT NULL,
    `changed_fields` JSON DEFAULT NULL,
    `module` VARCHAR(100) DEFAULT NULL,
    `request_uri` VARCHAR(500) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_business` (`business_id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_date` (`created_at`),
    CONSTRAINT `fk_audit_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: alerts
-- ============================================================
CREATE TABLE `alerts` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `type` ENUM('CASH_LOW','DEBTOR_OVERDUE','ADVANCE_HIGH','CAR_AGING','TRIAL_IMBALANCE','PARTNER_WITHDRAWAL','SALARY_DUPLICATE') NOT NULL,
    `severity` ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'WARNING',
    `message` TEXT NOT NULL,
    `entity_type` VARCHAR(50) DEFAULT NULL,
    `entity_id` CHAR(36) DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_alerts_business` (`business_id`),
    CONSTRAINT `fk_alerts_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: attachments
-- ============================================================
CREATE TABLE `attachments` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` CHAR(36) NOT NULL,
    `attachment_type` VARCHAR(50) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `relative_path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(120) NOT NULL,
    `file_size` INT NOT NULL DEFAULT 0,
    `uploaded_by` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attachment_entity` (`business_id`, `entity_type`, `entity_id`, `attachment_type`),
    KEY `idx_attachment_uploaded_by` (`uploaded_by`),
    CONSTRAINT `fk_attachments_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_attachments_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: rto_records
-- ============================================================
CREATE TABLE `rto_records` (
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
    KEY `idx_rto_car` (`car_id`),
    CONSTRAINT `fk_rto_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_rto_car` FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: rto_recoveries
-- ============================================================
CREATE TABLE `rto_recoveries` (
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
    KEY `idx_rto_recovery_car` (`car_id`),
    CONSTRAINT `fk_rr_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_rr_record` FOREIGN KEY (`rto_record_id`) REFERENCES `rto_records`(`id`),
    CONSTRAINT `fk_rr_car` FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`),
    CONSTRAINT `fk_rr_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: car_second_key_events
-- ============================================================
CREATE TABLE `car_second_key_events` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `car_id` CHAR(36) NOT NULL,
    `event_type` ENUM('RECEIVED','GIVEN') NOT NULL,
    `event_date` DATE NOT NULL,
    `narration` VARCHAR(500) DEFAULT NULL,
    `created_by` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_second_key_car` (`car_id`, `event_date`),
    CONSTRAINT `fk_ske_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_ske_car` FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: expense_categories
-- ============================================================
CREATE TABLE `expense_categories` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('CAR_SPECIFIC','GENERAL') NOT NULL DEFAULT 'GENERAL',
    `account_id` CHAR(36) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_ec_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: journal_vouchers
-- ============================================================
CREATE TABLE `journal_vouchers` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `voucher_date` DATE NOT NULL,
    `reference_no` VARCHAR(50) NOT NULL,
    `voucher_type` VARCHAR(50) NOT NULL DEFAULT 'GENERAL_JV',
    `narration` TEXT DEFAULT NULL,
    `status` ENUM('DRAFT','POSTED','REVERSED') NOT NULL DEFAULT 'DRAFT',
    `primary_account_id` CHAR(36) NOT NULL,
    `primary_entry_type` ENUM('DR','CR') NOT NULL,
    `primary_amount` DECIMAL(15,2) NOT NULL,
    `posted_entry_id` CHAR(36) DEFAULT NULL,
    `created_by` CHAR(36) NOT NULL,
    `financial_year` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_jv_reference` (`business_id`, `reference_no`),
    KEY `idx_jv_business_status` (`business_id`, `status`),
    CONSTRAINT `fk_jv_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_jv_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: journal_voucher_lines
-- ============================================================
CREATE TABLE `journal_voucher_lines` (
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
    KEY `idx_jvl_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_jvl_voucher` FOREIGN KEY (`journal_voucher_id`) REFERENCES `journal_vouchers`(`id`),
    CONSTRAINT `fk_jvl_account` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: user_book_permissions
-- ============================================================
CREATE TABLE `user_book_permissions` (
    `id` CHAR(36) NOT NULL,
    `business_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `book_key` VARCHAR(50) NOT NULL,
    `can_read` TINYINT(1) NOT NULL DEFAULT 0,
    `can_write` TINYINT(1) NOT NULL DEFAULT 0,
    `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_book_permission` (`user_id`, `book_key`),
    KEY `idx_ubp_business` (`business_id`),
    CONSTRAINT `fk_ubp_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
    CONSTRAINT `fk_ubp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- OUTSIDE CARS (entity-owned vehicles, A/B/C/K settlement)
-- ============================================================
CREATE TABLE `source_entities` (
    `id` CHAR(36) NOT NULL, `business_id` CHAR(36) NOT NULL, `party_id` CHAR(36) NOT NULL,
    `entity_kind` VARCHAR(30) NOT NULL DEFAULT 'OTHER_CAR_MELA', `display_name` VARCHAR(200) NOT NULL,
    `notes` VARCHAR(500) DEFAULT NULL, `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` CHAR(36) DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), UNIQUE KEY `uk_source_entity_party` (`business_id`,`party_id`),
    KEY `idx_source_entity_name` (`business_id`,`display_name`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_car_deals` (
    `id` CHAR(36) NOT NULL, `business_id` CHAR(36) NOT NULL, `car_id` CHAR(36) NOT NULL, `source_entity_id` CHAR(36) NOT NULL,
    `deal_type` VARCHAR(30) NOT NULL DEFAULT 'HYBRID', `source_base_value` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `expected_sale_value` DECIMAL(15,2) NOT NULL DEFAULT 0, `tiranga_profit_pct` DECIMAL(7,4) NOT NULL DEFAULT 50,
    `entity_profit_pct` DECIMAL(7,4) NOT NULL DEFAULT 50, `tiranga_loss_pct` DECIMAL(7,4) NOT NULL DEFAULT 50,
    `entity_loss_pct` DECIMAL(7,4) NOT NULL DEFAULT 50, `commission_type` VARCHAR(20) NOT NULL DEFAULT 'FIXED',
    `commission_value` DECIMAL(15,4) NOT NULL DEFAULT 0, `commission_charged_to` VARCHAR(20) NOT NULL DEFAULT 'BUYER',
    `chassis_no` VARCHAR(100) DEFAULT NULL, `engine_no` VARCHAR(100) DEFAULT NULL, `insurance_details` VARCHAR(500) DEFAULT NULL,
    `hypothecation_details` VARCHAR(500) DEFAULT NULL, `second_key_details` VARCHAR(300) DEFAULT NULL,
    `physical_status` VARCHAR(30) NOT NULL DEFAULT 'RECEIVED', `buyer_status` VARCHAR(30) NOT NULL DEFAULT 'NO_BUYER',
    `rto_status` VARCHAR(30) NOT NULL DEFAULT 'NOT_STARTED', `settlement_status` VARCHAR(30) NOT NULL DEFAULT 'TERMS_PENDING',
    `agreement_status` VARCHAR(30) NOT NULL DEFAULT 'DRAFT', `terms_version` INT NOT NULL DEFAULT 1,
    `terms_locked_at` TIMESTAMP NULL DEFAULT NULL, `notes` TEXT DEFAULT NULL, `created_by` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), UNIQUE KEY `uk_outside_car_deal` (`business_id`,`car_id`),
    KEY `idx_outside_source` (`business_id`,`source_entity_id`), KEY `idx_outside_status` (`business_id`,`physical_status`,`settlement_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_car_advances` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`source_entity_id` CHAR(36) NOT NULL,
    `direction` VARCHAR(30) NOT NULL,`entry_date` DATE NOT NULL,`amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `applied_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,`journal_entry_id` CHAR(36) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
    `narration` VARCHAR(500) DEFAULT NULL,`created_by` CHAR(36) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),UNIQUE KEY `uk_outside_advance_entry` (`journal_entry_id`),KEY `idx_outside_advance_car` (`business_id`,`car_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_car_expenses` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`source_entity_id` CHAR(36) NOT NULL,
    `expense_date` DATE NOT NULL,`category` VARCHAR(120) NOT NULL,`vendor_name` VARCHAR(200) DEFAULT NULL,`responsibility` VARCHAR(30) NOT NULL,
    `actual_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,`approved_recoverable_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `difference_bearer` VARCHAR(20) DEFAULT NULL,`journal_entry_id` CHAR(36) NOT NULL,`reclass_entry_id` CHAR(36) DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',`narration` VARCHAR(500) DEFAULT NULL,`created_by` CHAR(36) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),UNIQUE KEY `uk_outside_expense_entry` (`journal_entry_id`),KEY `idx_outside_expense_car` (`business_id`,`car_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_car_sales` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`source_entity_id` CHAR(36) NOT NULL,`buyer_party_id` CHAR(36) NOT NULL,
    `sale_date` DATE NOT NULL,`vehicle_sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0,`discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `net_vehicle_value` DECIMAL(15,2) NOT NULL DEFAULT 0,`separate_commission` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `buyer_rto_charge` DECIMAL(15,2) NOT NULL DEFAULT 0,`other_buyer_charges` DECIMAL(15,2) NOT NULL DEFAULT 0,`buyer_total` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `token_applied` DECIMAL(15,2) NOT NULL DEFAULT 0,`received_at_sale` DECIMAL(15,2) NOT NULL DEFAULT 0,`buyer_outstanding` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `sale_entry_id` CHAR(36) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',`created_by` CHAR(36) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `uk_outside_sale_entry` (`sale_entry_id`),
    KEY `idx_outside_sale_car` (`business_id`,`car_id`,`status`),KEY `idx_outside_sale_buyer` (`business_id`,`buyer_party_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_car_buyer_payments` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`sale_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`buyer_party_id` CHAR(36) NOT NULL,
    `payment_date` DATE NOT NULL,`payment_kind` VARCHAR(20) NOT NULL DEFAULT 'RECEIPT',`amount` DECIMAL(15,2) NOT NULL DEFAULT 0,`journal_entry_id` CHAR(36) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
    `created_by` CHAR(36) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `uk_outside_buyer_payment_entry` (`journal_entry_id`),
    KEY `idx_outside_buyer_payment_sale` (`business_id`,`sale_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_car_settlements` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`source_entity_id` CHAR(36) NOT NULL,`sale_id` CHAR(36) NOT NULL,
    `settlement_date` DATE NOT NULL,`actual_expenses` DECIMAL(15,2) NOT NULL DEFAULT 0,`approved_expenses` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `exact_margin` DECIMAL(15,2) NOT NULL DEFAULT 0,`approved_margin` DECIMAL(15,2) NOT NULL DEFAULT 0,`margin_difference` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `difference_target` VARCHAR(30) DEFAULT NULL,`tiranga_share` DECIMAL(15,2) NOT NULL DEFAULT 0,`entity_share` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `separate_commission` DECIMAL(15,2) NOT NULL DEFAULT 0,`tiranga_income` DECIMAL(15,2) NOT NULL DEFAULT 0,`tiranga_entitlement` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `entity_gross_entitlement` DECIMAL(15,2) NOT NULL DEFAULT 0,`advances_applied` DECIMAL(15,2) NOT NULL DEFAULT 0,`settled_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `remaining_entity_payable` DECIMAL(15,2) NOT NULL DEFAULT 0,`remaining_entity_receivable` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `allocation_entry_id` CHAR(36) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'APPROVED',`approval_reason` VARCHAR(500) NOT NULL,`approved_by` CHAR(36) NOT NULL,
    `approved_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),UNIQUE KEY `uk_outside_settlement_entry` (`allocation_entry_id`),KEY `idx_outside_settlement_car` (`business_id`,`car_id`,`status`),
    KEY `idx_outside_settlement_entity` (`business_id`,`source_entity_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_entity_payments` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`settlement_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`source_entity_id` CHAR(36) NOT NULL,
    `payment_date` DATE NOT NULL,`amount` DECIMAL(15,2) NOT NULL DEFAULT 0,`journal_entry_id` CHAR(36) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
    `created_by` CHAR(36) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `uk_outside_entity_payment_entry` (`journal_entry_id`),
    KEY `idx_outside_entity_payment_settlement` (`business_id`,`settlement_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_car_rto_movements` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`source_entity_id` CHAR(36) NOT NULL,`movement_type` VARCHAR(20) NOT NULL,
    `movement_date` DATE NOT NULL,`amount` DECIMAL(15,2) NOT NULL DEFAULT 0,`adjustment_bearer` VARCHAR(30) DEFAULT NULL,`journal_entry_id` CHAR(36) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
    `narration` VARCHAR(500) DEFAULT NULL,`created_by` CHAR(36) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),
    UNIQUE KEY `uk_outside_rto_entry` (`journal_entry_id`),KEY `idx_outside_rto_car` (`business_id`,`car_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_agreement_clause_templates` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`version_code` VARCHAR(30) NOT NULL,`language` VARCHAR(20) NOT NULL DEFAULT 'BILINGUAL',
    `title` VARCHAR(200) NOT NULL,`clauses_json` LONGTEXT NOT NULL,`is_active` TINYINT(1) NOT NULL DEFAULT 1,`created_by` CHAR(36) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `uk_outside_clause_version` (`business_id`,`version_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_car_agreements` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`sale_id` CHAR(36) NOT NULL,`version_no` INT NOT NULL DEFAULT 1,
    `language` VARCHAR(20) NOT NULL DEFAULT 'BILINGUAL',`status` VARCHAR(20) NOT NULL DEFAULT 'GENERATED',`clause_version` VARCHAR(30) NOT NULL DEFAULT 'GUJ-EN-1',
    `snapshot_json` LONGTEXT NOT NULL,`snapshot_hash` CHAR(64) NOT NULL,`html_path` VARCHAR(500) NOT NULL,`pdf_path` VARCHAR(500) DEFAULT NULL,
    `created_by` CHAR(36) NOT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,`signed_at` TIMESTAMP NULL DEFAULT NULL,PRIMARY KEY (`id`),
    UNIQUE KEY `uk_outside_agreement_version` (`business_id`,`car_id`,`version_no`),KEY `idx_outside_agreement_status` (`business_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `outside_car_deliveries` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`sale_id` CHAR(36) NOT NULL,`delivery_date` DATE NOT NULL,
    `delivery_time` TIME DEFAULT NULL,`odometer` INT DEFAULT NULL,`fuel_level` VARCHAR(30) DEFAULT NULL,`keys_handed_over` INT NOT NULL DEFAULT 1,
    `documents_handed_over` VARCHAR(500) DEFAULT NULL,`receiver_name` VARCHAR(200) NOT NULL,`override_used` TINYINT(1) NOT NULL DEFAULT 0,
    `override_reason` VARCHAR(500) DEFAULT NULL,`promised_payment_date` DATE DEFAULT NULL,`buyer_balance_at_delivery` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `recorded_by` CHAR(36) NOT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `uk_outside_delivery_car` (`business_id`,`car_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `car_loan_commissions` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`buyer_party_id` CHAR(36) NOT NULL,`financier_party_id` CHAR(36) NOT NULL,
    `loan_account_no` VARCHAR(100) DEFAULT NULL,`approval_date` DATE NOT NULL,`loan_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,`commission_type` VARCHAR(20) NOT NULL DEFAULT 'FIXED',
    `commission_value` DECIMAL(15,4) NOT NULL DEFAULT 0,`commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,`received_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `accrual_entry_id` CHAR(36) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',`notes` VARCHAR(500) DEFAULT NULL,`created_by` CHAR(36) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),
    UNIQUE KEY `uk_car_loan_accrual` (`accrual_entry_id`),KEY `idx_car_loan_car` (`business_id`,`car_id`,`status`),KEY `idx_car_loan_financier` (`business_id`,`financier_party_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `car_loan_commission_receipts` (
    `id` CHAR(36) NOT NULL,`business_id` CHAR(36) NOT NULL,`commission_id` CHAR(36) NOT NULL,`car_id` CHAR(36) NOT NULL,`receipt_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,`receiving_account_id` CHAR(36) NOT NULL,`journal_entry_id` CHAR(36) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
    `narration` VARCHAR(500) DEFAULT NULL,`created_by` CHAR(36) NOT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`),
    UNIQUE KEY `uk_car_loan_receipt_entry` (`journal_entry_id`),KEY `idx_car_loan_receipt` (`business_id`,`commission_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
