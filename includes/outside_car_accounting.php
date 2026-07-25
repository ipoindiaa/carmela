<?php

/**
 * Outside-car consignment / joint-deal accounting.
 *
 * This trait deliberately keeps the new workflow separate from the legacy
 * commission-car tables. Legacy COMMISSION records remain readable and
 * reversible under their original rules; new records use ownership OUTSIDE.
 */
trait OutsideCarAccounting {
    private function ensureOutsideCarSchema() {
        $ownership = $this->db->fetch(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'ownership_type'"
        );
        if (strpos((string) ($ownership['COLUMN_TYPE'] ?? ''), "'OUTSIDE'") === false) {
            $this->db->query(
                "ALTER TABLE `cars` MODIFY COLUMN `ownership_type`
                 ENUM('OWNED','COMMISSION','OUTSIDE') NOT NULL DEFAULT 'OWNED'"
            );
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `source_entities` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `party_id` CHAR(36) NOT NULL,
                `entity_kind` VARCHAR(30) NOT NULL DEFAULT 'OTHER_CAR_MELA',
                `display_name` VARCHAR(200) NOT NULL,
                `notes` VARCHAR(500) DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_source_entity_party` (`business_id`,`party_id`),
                KEY `idx_source_entity_name` (`business_id`,`display_name`,`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_car_deals` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `source_entity_id` CHAR(36) NOT NULL,
                `deal_type` VARCHAR(30) NOT NULL DEFAULT 'HYBRID',
                `source_base_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `expected_sale_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `tiranga_profit_pct` DECIMAL(7,4) NOT NULL DEFAULT 50.0000,
                `entity_profit_pct` DECIMAL(7,4) NOT NULL DEFAULT 50.0000,
                `tiranga_loss_pct` DECIMAL(7,4) NOT NULL DEFAULT 50.0000,
                `entity_loss_pct` DECIMAL(7,4) NOT NULL DEFAULT 50.0000,
                `commission_type` VARCHAR(20) NOT NULL DEFAULT 'FIXED',
                `commission_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                `commission_charged_to` VARCHAR(20) NOT NULL DEFAULT 'BUYER',
                `chassis_no` VARCHAR(100) DEFAULT NULL,
                `engine_no` VARCHAR(100) DEFAULT NULL,
                `insurance_details` VARCHAR(500) DEFAULT NULL,
                `hypothecation_details` VARCHAR(500) DEFAULT NULL,
                `second_key_details` VARCHAR(300) DEFAULT NULL,
                `physical_status` VARCHAR(30) NOT NULL DEFAULT 'RECEIVED',
                `buyer_status` VARCHAR(30) NOT NULL DEFAULT 'NO_BUYER',
                `rto_status` VARCHAR(30) NOT NULL DEFAULT 'NOT_STARTED',
                `settlement_status` VARCHAR(30) NOT NULL DEFAULT 'TERMS_PENDING',
                `agreement_status` VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
                `terms_version` INT NOT NULL DEFAULT 1,
                `terms_locked_at` TIMESTAMP NULL DEFAULT NULL,
                `notes` TEXT DEFAULT NULL,
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_outside_car_deal` (`business_id`,`car_id`),
                KEY `idx_outside_source` (`business_id`,`source_entity_id`),
                KEY `idx_outside_status` (`business_id`,`physical_status`,`settlement_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        foreach ([
            'chassis_no' => "VARCHAR(100) DEFAULT NULL AFTER `commission_charged_to`",
            'engine_no' => "VARCHAR(100) DEFAULT NULL AFTER `chassis_no`",
            'insurance_details' => "VARCHAR(500) DEFAULT NULL AFTER `engine_no`",
            'hypothecation_details' => "VARCHAR(500) DEFAULT NULL AFTER `insurance_details`",
            'second_key_details' => "VARCHAR(300) DEFAULT NULL AFTER `hypothecation_details`",
        ] as $column => $definition) {
            if (!$this->columnExists('outside_car_deals', $column)) {
                $this->db->query("ALTER TABLE `outside_car_deals` ADD COLUMN `$column` $definition");
            }
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_car_advances` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `source_entity_id` CHAR(36) NOT NULL,
                `direction` VARCHAR(30) NOT NULL,
                `entry_date` DATE NOT NULL,
                `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `applied_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `journal_entry_id` CHAR(36) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
                `narration` VARCHAR(500) DEFAULT NULL,
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_outside_advance_entry` (`journal_entry_id`),
                KEY `idx_outside_advance_car` (`business_id`,`car_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_car_expenses` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `source_entity_id` CHAR(36) NOT NULL,
                `expense_date` DATE NOT NULL,
                `category` VARCHAR(120) NOT NULL,
                `vendor_name` VARCHAR(200) DEFAULT NULL,
                `responsibility` VARCHAR(30) NOT NULL,
                `actual_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `approved_recoverable_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `difference_bearer` VARCHAR(20) DEFAULT NULL,
                `journal_entry_id` CHAR(36) NOT NULL,
                `reclass_entry_id` CHAR(36) DEFAULT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
                `narration` VARCHAR(500) DEFAULT NULL,
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_outside_expense_entry` (`journal_entry_id`),
                KEY `idx_outside_expense_car` (`business_id`,`car_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_car_sales` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `source_entity_id` CHAR(36) NOT NULL,
                `buyer_party_id` CHAR(36) NOT NULL,
                `sale_date` DATE NOT NULL,
                `vehicle_sale_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `net_vehicle_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `separate_commission` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `buyer_rto_charge` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `other_buyer_charges` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `buyer_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `token_applied` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `received_at_sale` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `buyer_outstanding` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `sale_entry_id` CHAR(36) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_outside_sale_car` (`business_id`,`car_id`,`status`),
                UNIQUE KEY `uk_outside_sale_entry` (`sale_entry_id`),
                KEY `idx_outside_sale_buyer` (`business_id`,`buyer_party_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_car_buyer_payments` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `sale_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `buyer_party_id` CHAR(36) NOT NULL,
                `payment_date` DATE NOT NULL,
                `payment_kind` VARCHAR(20) NOT NULL DEFAULT 'RECEIPT',
                `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `journal_entry_id` CHAR(36) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_outside_buyer_payment_entry` (`journal_entry_id`),
                KEY `idx_outside_buyer_payment_sale` (`business_id`,`sale_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!$this->columnExists('outside_car_buyer_payments', 'payment_kind')) {
            $this->db->query("ALTER TABLE `outside_car_buyer_payments` ADD COLUMN `payment_kind` VARCHAR(20) NOT NULL DEFAULT 'RECEIPT' AFTER `payment_date`");
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_car_settlements` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `source_entity_id` CHAR(36) NOT NULL,
                `sale_id` CHAR(36) NOT NULL,
                `settlement_date` DATE NOT NULL,
                `actual_expenses` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `approved_expenses` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `exact_margin` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `approved_margin` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `margin_difference` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `difference_target` VARCHAR(30) DEFAULT NULL,
                `tiranga_share` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `entity_share` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `separate_commission` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `tiranga_income` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `tiranga_entitlement` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `entity_gross_entitlement` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `advances_applied` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `settled_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `remaining_entity_payable` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `remaining_entity_receivable` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `allocation_entry_id` CHAR(36) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'APPROVED',
                `approval_reason` VARCHAR(500) NOT NULL,
                `approved_by` CHAR(36) NOT NULL,
                `approved_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_outside_settlement_car` (`business_id`,`car_id`,`status`),
                UNIQUE KEY `uk_outside_settlement_entry` (`allocation_entry_id`),
                KEY `idx_outside_settlement_entity` (`business_id`,`source_entity_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!$this->columnExists('outside_car_settlements', 'remaining_entity_receivable')) {
            $this->db->query("ALTER TABLE `outside_car_settlements` ADD COLUMN `remaining_entity_receivable` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `remaining_entity_payable`");
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_entity_payments` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `settlement_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `source_entity_id` CHAR(36) NOT NULL,
                `payment_date` DATE NOT NULL,
                `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `journal_entry_id` CHAR(36) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_outside_entity_payment_entry` (`journal_entry_id`),
                KEY `idx_outside_entity_payment_settlement` (`business_id`,`settlement_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_car_rto_movements` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `source_entity_id` CHAR(36) NOT NULL,
                `movement_type` VARCHAR(20) NOT NULL,
                `movement_date` DATE NOT NULL,
                `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `adjustment_bearer` VARCHAR(30) DEFAULT NULL,
                `journal_entry_id` CHAR(36) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
                `narration` VARCHAR(500) DEFAULT NULL,
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_outside_rto_entry` (`journal_entry_id`),
                KEY `idx_outside_rto_car` (`business_id`,`car_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!$this->columnExists('outside_car_rto_movements', 'adjustment_bearer')) {
            $this->db->query("ALTER TABLE `outside_car_rto_movements` ADD COLUMN `adjustment_bearer` VARCHAR(30) DEFAULT NULL AFTER `amount`");
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_car_agreements` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `sale_id` CHAR(36) NOT NULL,
                `version_no` INT NOT NULL DEFAULT 1,
                `language` VARCHAR(20) NOT NULL DEFAULT 'BILINGUAL',
                `status` VARCHAR(20) NOT NULL DEFAULT 'GENERATED',
                `clause_version` VARCHAR(30) NOT NULL DEFAULT 'GUJ-EN-1',
                `snapshot_json` LONGTEXT NOT NULL,
                `snapshot_hash` CHAR(64) NOT NULL,
                `html_path` VARCHAR(500) NOT NULL,
                `pdf_path` VARCHAR(500) DEFAULT NULL,
                `created_by` CHAR(36) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `signed_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_outside_agreement_version` (`business_id`,`car_id`,`version_no`),
                KEY `idx_outside_agreement_status` (`business_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_agreement_clause_templates` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `version_code` VARCHAR(30) NOT NULL,
                `language` VARCHAR(20) NOT NULL DEFAULT 'BILINGUAL',
                `title` VARCHAR(200) NOT NULL,
                `clauses_json` LONGTEXT NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` CHAR(36) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_outside_clause_version` (`business_id`,`version_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `outside_car_deliveries` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `sale_id` CHAR(36) NOT NULL,
                `delivery_date` DATE NOT NULL,
                `delivery_time` TIME DEFAULT NULL,
                `odometer` INT DEFAULT NULL,
                `fuel_level` VARCHAR(30) DEFAULT NULL,
                `keys_handed_over` INT NOT NULL DEFAULT 1,
                `documents_handed_over` VARCHAR(500) DEFAULT NULL,
                `receiver_name` VARCHAR(200) NOT NULL,
                `override_used` TINYINT(1) NOT NULL DEFAULT 0,
                `override_reason` VARCHAR(500) DEFAULT NULL,
                `promised_payment_date` DATE DEFAULT NULL,
                `buyer_balance_at_delivery` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `recorded_by` CHAR(36) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_outside_delivery_car` (`business_id`,`car_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query(
            "INSERT IGNORE INTO source_entities (id,business_id,party_id,entity_kind,display_name,notes,created_by)
             SELECT UUID(),c.business_id,c.commission_owner_party_id,'LEGACY_COMMISSION',dc.name,
                    'Auto-wrapped historical Commission Car owner; original journals and calculation mode are unchanged.',?
             FROM cars c JOIN debtors_creditors dc ON dc.id=c.commission_owner_party_id AND dc.business_id=c.business_id
             WHERE c.ownership_type='COMMISSION' AND c.commission_owner_party_id IS NOT NULL",
            [$this->userId]
        );
    }

    private function outsideSystemAccount($code, $name, $group, $subGroup) {
        return $this->getOrCreateSystemAccount($code, $name, $group, $subGroup);
    }

    private function outsideContext($carId, $lock = false) {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $row = $this->db->fetch(
            "SELECT c.*, ocd.id AS deal_id, ocd.source_entity_id, ocd.deal_type,
                    ocd.source_base_value, ocd.expected_sale_value,
                    ocd.tiranga_profit_pct, ocd.entity_profit_pct,
                    ocd.tiranga_loss_pct, ocd.entity_loss_pct,
                    ocd.commission_type, ocd.commission_value,
                    ocd.chassis_no, ocd.engine_no, ocd.insurance_details,
                    ocd.hypothecation_details, ocd.second_key_details,
                    ocd.physical_status, ocd.buyer_status, ocd.rto_status,
                    ocd.settlement_status, ocd.agreement_status,
                    se.party_id AS source_party_id, se.display_name AS source_entity_name,
                    dc.account_id AS source_account_id, dc.phone AS source_phone
             FROM cars c
             JOIN outside_car_deals ocd ON ocd.car_id = c.id AND ocd.business_id = c.business_id
             JOIN source_entities se ON se.id = ocd.source_entity_id AND se.business_id = c.business_id
             JOIN debtors_creditors dc ON dc.id = se.party_id AND dc.business_id = c.business_id
             WHERE c.id = ? AND c.business_id = ? AND c.ownership_type = 'OUTSIDE'$suffix",
            [$carId, $this->businessId]
        );
        if (!$row) throw new Exception('Outside Car not found.');
        return $row;
    }

    public function getOrCreateSourceEntity($partyId, $name, $phone = '', $kind = 'OTHER_CAR_MELA', $notes = '') {
        $kind = strtoupper(trim((string) $kind));
        if (!in_array($kind, ['OTHER_CAR_MELA','DEALER','COMPANY','INDIVIDUAL','BROKER','OTHER'], true)) $kind = 'OTHER';
        $party = $this->resolveParty($partyId, $name, $phone, 'CREDITOR', ['CREDITOR','SELLER']);
        $existing = $this->db->fetch(
            "SELECT * FROM source_entities WHERE business_id = ? AND party_id = ?",
            [$this->businessId, $party['id']]
        );
        if ($existing) return $existing;
        $id = Database::uuid();
        $record = [
            'id' => $id, 'business_id' => $this->businessId, 'party_id' => $party['id'],
            'entity_kind' => $kind, 'display_name' => $party['name'], 'notes' => trim((string) $notes),
            'created_by' => $this->userId,
        ];
        $this->db->insert('source_entities', $record);
        Auth::auditCreate('source_entity', $id, $record, "Source Entity {$party['name']} created", 'outside_cars');
        return array_merge($record, ['account_id' => $party['account_id'], 'phone' => $party['phone'] ?? '']);
    }

    public function createOutsideCar(array $data) {
        $registrationNo = normalizeRegistrationNo($data['registration_no'] ?? '');
        if (!isValidRegistrationNo($registrationNo)) throw new Exception('Registration number must be like GJ05AA0001, with exactly 4 digits at the end.');
        if (findCarByRegistrationNo($this->db, $this->businessId, $registrationNo)) throw new Exception('A car with this registration number already exists.');
        $receivedDate = trim((string) ($data['received_date'] ?? ''));
        $date = DateTime::createFromFormat('!Y-m-d', $receivedDate);
        if (!$date || $date->format('Y-m-d') !== $receivedDate) throw new Exception('A valid received date is required.');

        $base = round(floatval($data['source_base_value'] ?? 0), 2);
        $expectedSale = round(floatval($data['expected_sale_value'] ?? 0), 2);
        $tirangaProfit = round(floatval($data['tiranga_profit_pct'] ?? 0), 4);
        $entityProfit = round(floatval($data['entity_profit_pct'] ?? 0), 4);
        $tirangaLoss = round(floatval($data['tiranga_loss_pct'] ?? $tirangaProfit), 4);
        $entityLoss = round(floatval($data['entity_loss_pct'] ?? $entityProfit), 4);
        $dealType = strtoupper(trim((string) ($data['deal_type'] ?? 'HYBRID')));
        $commissionType = strtoupper(trim((string) ($data['commission_type'] ?? 'FIXED')));
        $commissionValue = round(floatval($data['commission_value'] ?? 0), 4);
        if ($base < 0 || $expectedSale < 0) throw new Exception('Base and expected sale values cannot be negative.');
        if (!in_array($dealType, ['PROFIT_SHARE','FIXED_COMMISSION','HYBRID'], true)) throw new Exception('Select a valid Outside Car deal type.');
        if (!in_array($commissionType, ['NONE','FIXED','PERCENT'], true)) throw new Exception('Select a valid commission type.');
        if ($commissionValue < 0 || ($commissionType === 'PERCENT' && $commissionValue > 100)) throw new Exception('Commission must be non-negative and percentage commission cannot exceed 100%.');
        if ($commissionType === 'NONE') $commissionValue = 0;
        if (abs(($tirangaProfit + $entityProfit) - 100) > 0.0001) throw new Exception('Profit shares must total exactly 100%.');
        if (abs(($tirangaLoss + $entityLoss) - 100) > 0.0001) throw new Exception('Loss shares must total exactly 100%.');
        foreach ([$tirangaProfit,$entityProfit,$tirangaLoss,$entityLoss] as $pct) if ($pct < 0 || $pct > 100) throw new Exception('Profit and loss shares must be between 0% and 100%.');

        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $source = $this->getOrCreateSourceEntity(
                $data['source_party_id'] ?? '', $data['source_name'] ?? '', $data['source_phone'] ?? '',
                $data['source_kind'] ?? 'OTHER_CAR_MELA', $data['source_notes'] ?? ''
            );
            $carId = Database::uuid();
            $car = [
                'id' => $carId, 'business_id' => $this->businessId, 'registration_no' => $registrationNo,
                'make' => trim((string) ($data['make'] ?? '')), 'model' => trim((string) ($data['model'] ?? '')),
                'year' => intval($data['year'] ?? 0) ?: null, 'color' => trim((string) ($data['color'] ?? '')),
                'purchase_date' => $receivedDate, 'purchase_price' => 0, 'purchase_paid_amount' => 0,
                'ownership_type' => 'OUTSIDE', 'commission_owner_party_id' => $source['party_id'],
                'seller_party_id' => $source['party_id'], 'expected_sale_price' => $expectedSale,
                'expected_commission_amount' => $commissionType === 'FIXED' ? round($commissionValue, 2) : 0,
                'has_second_key' => !empty($data['has_second_key']) ? 1 : 0,
                'notes' => trim((string) ($data['notes'] ?? '')),
            ];
            $this->db->insert('cars', $car);
            $dealId = Database::uuid();
            $deal = [
                'id' => $dealId, 'business_id' => $this->businessId, 'car_id' => $carId,
                'source_entity_id' => $source['id'], 'deal_type' => $dealType,
                'source_base_value' => $base, 'expected_sale_value' => $expectedSale,
                'tiranga_profit_pct' => $tirangaProfit, 'entity_profit_pct' => $entityProfit,
                'tiranga_loss_pct' => $tirangaLoss, 'entity_loss_pct' => $entityLoss,
                'commission_type' => $commissionType,
                'commission_value' => $commissionValue,
                'chassis_no' => trim((string) ($data['chassis_no'] ?? '')),
                'engine_no' => trim((string) ($data['engine_no'] ?? '')),
                'insurance_details' => trim((string) ($data['insurance_details'] ?? '')),
                'hypothecation_details' => trim((string) ($data['hypothecation_details'] ?? '')),
                'second_key_details' => trim((string) ($data['second_key_details'] ?? '')),
                'settlement_status' => 'CALCULATION_PENDING', 'notes' => trim((string) ($data['notes'] ?? '')),
                'created_by' => $this->userId,
            ];
            $this->db->insert('outside_car_deals', $deal);
            Auth::auditCreate('car', $carId, ['car' => $car, 'deal' => $deal], "Outside Car $registrationNo received from {$source['display_name']}", 'outside_cars');
            if ($owns) $this->db->commit();
            return $carId;
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function recordOutsideEntityAdvance($carId, $amount, $date, $accountId, $direction, $narration = '') {
        $car = $this->outsideContext($carId);
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) throw new Exception('Advance amount must be greater than zero.');
        $direction = strtoupper(trim((string) $direction));
        if (!in_array($direction, ['PAID_TO_ENTITY','RECEIVED_FROM_ENTITY'], true)) throw new Exception('Select a valid advance direction.');
        if ($direction === 'PAID_TO_ENTITY') {
            $this->validateCashAvailable($accountId, $amount);
            $advance = $this->outsideSystemAccount('OUTCAR-ADV', 'Outside Car Entity Advances', 'ASSET', 'Current Assets');
            $lines = [
                ['account_id' => $advance['id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Advance for {$car['registration_no']}"],
                ['account_id' => $accountId, 'amount' => $amount, 'type' => 'CR', 'narration' => "Paid to {$car['source_entity_name']}"],
            ];
            $entryType = 'OUTSIDE_ENTITY_BASE_ADVANCE';
        } else {
            $lines = [
                ['account_id' => $accountId, 'amount' => $amount, 'type' => 'DR', 'narration' => "Advance received from {$car['source_entity_name']}"],
                ['account_id' => $car['source_account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => "Source Entity advance for {$car['registration_no']}"],
            ];
            $entryType = 'OUTSIDE_ENTITY_ADVANCE_RECEIVED';
        }
        $entryId = $this->postJournalEntry($direction === 'PAID_TO_ENTITY' ? 'LOAN_GIVEN' : 'LOAN_TAKEN', $date, $narration ?: "Outside Car advance - {$car['registration_no']}", $lines, [
            'car_id' => $carId, 'party_id' => $car['source_party_id'], 'entry_type_id' => systemEntryTypeId($entryType), 'entry_amount' => $amount,
        ]);
        $this->db->insert('outside_car_advances', [
            'id' => Database::uuid(), 'business_id' => $this->businessId, 'car_id' => $carId,
            'source_entity_id' => $car['source_entity_id'], 'direction' => $direction, 'entry_date' => $date,
            'amount' => $amount, 'journal_entry_id' => $entryId, 'narration' => $narration, 'created_by' => $this->userId,
        ]);
        $this->db->query("UPDATE outside_car_deals SET settlement_status = 'ADVANCE_PAID' WHERE id = ?", [$car['deal_id']]);
        return $entryId;
    }

    public function recordOutsideBuyerRefund($carId, $amount, $date, $paymentAccount, $narration = '') {
        $car = $this->outsideContext($carId);
        $sale = $this->getOutsideSale($carId, true);
        if (!$sale) throw new Exception('Record the Outside Car sale before refunding buyer money.');
        $amount = round(floatval($amount), 2);
        $netPaid = round(floatval($sale['buyer_total']) - floatval($sale['buyer_outstanding']), 2);
        if ($amount <= 0 || $amount - $netPaid > 0.01) throw new Exception('Refund cannot exceed net buyer money applied to this sale.');
        $this->validateCashAvailable($paymentAccount, $amount);
        $entryId = $this->postJournalEntry('LOAN_REPAID', $date, $narration ?: "Outside Car buyer refund - {$car['registration_no']}", [
            ['account_id'=>$sale['buyer_account_id'],'amount'=>$amount,'type'=>'DR','narration'=>'Buyer refund creates amount due'],
            ['account_id'=>$paymentAccount,'amount'=>$amount,'type'=>'CR','narration'=>'Buyer refund paid'],
        ], ['car_id'=>$carId,'party_id'=>$sale['buyer_party_id'],'entry_type_id'=>systemEntryTypeId('OUTSIDE_BUYER_REFUND'),'entry_amount'=>$amount]);
        $this->db->insert('outside_car_buyer_payments', [
            'id'=>Database::uuid(),'business_id'=>$this->businessId,'sale_id'=>$sale['id'],'car_id'=>$carId,
            'buyer_party_id'=>$sale['buyer_party_id'],'payment_date'=>$date,'payment_kind'=>'REFUND','amount'=>$amount,
            'journal_entry_id'=>$entryId,'created_by'=>$this->userId,
        ]);
        $newOutstanding = round(floatval($sale['buyer_outstanding']) + $amount, 2);
        $this->db->query("UPDATE outside_car_sales SET buyer_outstanding=? WHERE id=?",[$newOutstanding,$sale['id']]);
        $this->db->query("UPDATE outside_car_deals SET buyer_status='PARTLY_PAID' WHERE id=?",[$car['deal_id']]);
        $this->db->query("UPDATE cars SET status='PENDING_PAYMENT' WHERE id=? AND business_id=?",[$carId,$this->businessId]);
        return $entryId;
    }

    public function recordOutsideCarExpense($carId, array $data) {
        $car = $this->outsideContext($carId);
        $amount = round(floatval($data['amount'] ?? 0), 2);
        $approved = round(floatval($data['approved_recoverable_amount'] ?? 0), 2);
        if ($amount <= 0) throw new Exception('Expense amount must be greater than zero.');
        if ($approved < 0 || $approved - $amount > 0.01) throw new Exception('Approved recovery cannot exceed actual expense.');
        $responsibility = strtoupper(trim((string) ($data['responsibility'] ?? 'RECOVERABLE')));
        if (!in_array($responsibility, ['RECOVERABLE','TIRANGA','SOURCE_ENTITY','BUYER','EXCLUDED'], true)) throw new Exception('Select who bears this expense.');
        if ($responsibility !== 'RECOVERABLE') $approved = 0;
        $bearer = strtoupper(trim((string) ($data['difference_bearer'] ?? '')));
        if ($responsibility === 'RECOVERABLE' && $approved + 0.01 < $amount && !in_array($bearer, ['TIRANGA','SOURCE_ENTITY'], true)) {
            throw new Exception('Choose who bears the non-recoverable expense difference.');
        }
        $accountId = trim((string) ($data['payment_account'] ?? ''));
        $this->validateCashAvailable($accountId, $amount);
        if ($responsibility === 'RECOVERABLE') $debit = $this->outsideSystemAccount('OUTCAR-COST', 'Outside Car Recoverable Costs', 'ASSET', 'Current Assets')['id'];
        elseif ($responsibility === 'SOURCE_ENTITY') $debit = $car['source_account_id'];
        elseif ($responsibility === 'BUYER') {
            $sale = $this->getOutsideSale($carId);
            if (!$sale) throw new Exception('A buyer must be linked before assigning an expense to the buyer.');
            $buyer = $this->db->fetch("SELECT account_id FROM debtors_creditors WHERE id = ? AND business_id = ?", [$sale['buyer_party_id'], $this->businessId]);
            $debit = $buyer['account_id'];
        } else $debit = $this->outsideSystemAccount('OUTCAR-EXP', 'Outside Car Business Expense / Adjustment', 'EXPENSE', 'Direct Expenses (Car)')['id'];

        $entryId = $this->postJournalEntry('CAR_EXPENSE', $data['expense_date'], $data['narration'] ?: ($data['category'] . " - {$car['registration_no']}"), [
            ['account_id' => $debit, 'amount' => $amount, 'type' => 'DR', 'narration' => $data['category']],
            ['account_id' => $accountId, 'amount' => $amount, 'type' => 'CR', 'narration' => 'Outside Car expense paid'],
        ], ['car_id' => $carId, 'party_id' => $responsibility === 'SOURCE_ENTITY' ? $car['source_party_id'] : null,
            'entry_type_id' => systemEntryTypeId('OUTSIDE_CAR_EXPENSE'), 'entry_amount' => $amount]);
        $this->db->insert('outside_car_expenses', [
            'id' => Database::uuid(), 'business_id' => $this->businessId, 'car_id' => $carId,
            'source_entity_id' => $car['source_entity_id'], 'expense_date' => $data['expense_date'],
            'category' => trim((string) $data['category']), 'vendor_name' => trim((string) ($data['vendor_name'] ?? '')),
            'responsibility' => $responsibility, 'actual_amount' => $amount,
            'approved_recoverable_amount' => $approved, 'difference_bearer' => $bearer ?: null,
            'journal_entry_id' => $entryId, 'narration' => trim((string) ($data['narration'] ?? '')), 'created_by' => $this->userId,
        ]);
        if ($responsibility === 'BUYER' && !empty($sale['id'])) {
            $this->db->query("UPDATE outside_car_sales SET other_buyer_charges=other_buyer_charges+?,buyer_total=buyer_total+?,buyer_outstanding=buyer_outstanding+? WHERE id=?",[$amount,$amount,$amount,$sale['id']]);
            $this->db->query("UPDATE outside_car_deals SET buyer_status='PARTLY_PAID' WHERE id=?",[$car['deal_id']]);
            $this->db->query("UPDATE cars SET status='PENDING_PAYMENT' WHERE id=? AND business_id=?",[$carId,$this->businessId]);
        }
        return $entryId;
    }

    private function getOutsideSale($carId, $lock = false) {
        return $this->db->fetch(
            "SELECT ocs.*, dc.name AS buyer_name, dc.phone AS buyer_phone, dc.account_id AS buyer_account_id
             FROM outside_car_sales ocs JOIN debtors_creditors dc ON dc.id = ocs.buyer_party_id
             WHERE ocs.business_id = ? AND ocs.car_id = ? AND ocs.status <> 'REVERSED'
             ORDER BY ocs.created_at DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''),
            [$this->businessId, $carId]
        );
    }

    public function recordOutsideCarSale($carId, array $data) {
        $car = $this->outsideContext($carId);
        if ($this->getOutsideSale($carId)) throw new Exception('This Outside Car already has an active sale.');
        $vehicle = round(floatval($data['vehicle_sale_price'] ?? 0), 2);
        $discount = round(floatval($data['discount_amount'] ?? 0), 2);
        $commissionRaw = trim((string) ($data['separate_commission'] ?? ''));
        if ($commissionRaw === '') {
            if ($car['commission_type'] === 'PERCENT') $commission = round($vehicle * floatval($car['commission_value']) / 100, 2);
            elseif ($car['commission_type'] === 'FIXED') $commission = round(floatval($car['commission_value']), 2);
            else $commission = 0;
        } else {
            $commission = round(floatval($commissionRaw), 2);
        }
        $rto = round(floatval($data['buyer_rto_charge'] ?? 0), 2);
        $other = round(floatval($data['other_buyer_charges'] ?? 0), 2);
        if ($vehicle <= 0 || min($discount,$commission,$rto,$other) < 0 || $discount - $vehicle > 0.01) throw new Exception('Enter valid sale, discount, commission, and buyer charge amounts.');
        $netVehicle = round($vehicle - $discount, 2);
        $buyerTotal = round($netVehicle + $commission + $rto + $other, 2);
        $buyer = $this->resolveParty($data['buyer_party_id'] ?? '', $data['buyer_name'] ?? '', $data['buyer_phone'] ?? '', 'BUYER', ['BUYER','DEBTOR']);
        $token = $this->getCarTokenSummary($carId);
        if ($token['available'] > 0.009 && $token['party_id'] !== $buyer['id']) throw new Exception("This car has an open token from {$token['party_name']}. Select that buyer or reverse the token first.");
        $tokenApplied = round(min($token['available'], $buyerTotal), 2);
        $remaining = round($buyerTotal - $tokenApplied, 2);
        $received = trim((string) ($data['amount_received_now'] ?? '')) === '' ? 0 : round(floatval($data['amount_received_now']), 2);
        if ($received < 0 || $received - $remaining > 0.01) throw new Exception('Amount received now cannot exceed the amount due after token.');
        $outstanding = round($remaining - $received, 2);

        $lines = [];
        if ($received > 0) $lines[] = ['account_id' => $data['receiving_account'], 'amount' => $received, 'type' => 'DR', 'narration' => 'Buyer money received'];
        if ($tokenApplied > 0) {
            $advance = $this->outsideSystemAccount('CUST-ADV', 'Customer Token Advances', 'LIABILITY', 'Current Liabilities');
            $lines[] = ['account_id' => $advance['id'], 'amount' => $tokenApplied, 'type' => 'DR', 'narration' => 'Buyer token applied'];
        }
        if ($outstanding > 0) $lines[] = ['account_id' => $buyer['account_id'], 'amount' => $outstanding, 'type' => 'DR', 'narration' => 'Buyer outstanding'];
        $clear = $this->outsideSystemAccount('OUTCAR-CLEAR', 'Outside Car Sale Clearing', 'LIABILITY', 'Outside Car Clearing');
        $lines[] = ['account_id' => $clear['id'], 'amount' => $netVehicle, 'type' => 'CR', 'narration' => 'Outside Car vehicle value'];
        if ($commission + $other > 0) {
            $income = $this->outsideSystemAccount('OUTCAR-COMM', 'Outside Car Commission / Service Income', 'INCOME', 'Direct Income');
            $lines[] = ['account_id' => $income['id'], 'amount' => round($commission + $other, 2), 'type' => 'CR', 'narration' => 'Separate commission and buyer service charges'];
        }
        if ($rto > 0) {
            $rtoClear = $this->outsideSystemAccount('RTO-CLEAR', 'RTO Clearing', 'LIABILITY', 'Current Liabilities');
            $lines[] = ['account_id' => $rtoClear['id'], 'amount' => $rto, 'type' => 'CR', 'narration' => 'Buyer RTO charge'];
        }
        $entryId = $this->postJournalEntry('CAR_SALE', $data['sale_date'], $data['narration'] ?: "Outside Car sale - {$car['registration_no']}", $lines, [
            'car_id' => $carId, 'party_id' => $buyer['id'], 'entry_type_id' => systemEntryTypeId('OUTSIDE_CAR_SALE'), 'entry_amount' => $buyerTotal,
        ]);
        if ($tokenApplied > 0) $this->applyCarTokensToSale($carId, $buyer['id'], $tokenApplied, $entryId);
        $saleId = Database::uuid();
        $this->db->insert('outside_car_sales', [
            'id' => $saleId, 'business_id' => $this->businessId, 'car_id' => $carId, 'source_entity_id' => $car['source_entity_id'],
            'buyer_party_id' => $buyer['id'], 'sale_date' => $data['sale_date'], 'vehicle_sale_price' => $vehicle,
            'discount_amount' => $discount, 'net_vehicle_value' => $netVehicle, 'separate_commission' => $commission,
            'buyer_rto_charge' => $rto, 'other_buyer_charges' => $other, 'buyer_total' => $buyerTotal,
            'token_applied' => $tokenApplied, 'received_at_sale' => $received, 'buyer_outstanding' => $outstanding,
            'sale_entry_id' => $entryId, 'created_by' => $this->userId,
        ]);
        $carStatus = $outstanding > 0.009 ? 'PENDING_PAYMENT' : 'SOLD';
        $this->db->query("UPDATE cars SET status = ?, sold_date = ?, sale_price = ?, sale_commission_amount = ?, buyer_name = ?, buyer_party_id = ? WHERE id = ? AND business_id = ?", [$carStatus,$data['sale_date'],$vehicle,$commission,$buyer['name'],$buyer['id'],$carId,$this->businessId]);
        $this->db->query("UPDATE outside_car_deals SET physical_status = 'RESERVED', buyer_status = ?, rto_status = ?, terms_locked_at = NOW() WHERE id = ?", [$outstanding > 0.009 ? 'PARTLY_PAID' : 'FULLY_PAID', $rto > 0 ? 'IN_PROGRESS' : 'NOT_STARTED', $car['deal_id']]);
        return $entryId;
    }

    public function recordOutsideBuyerPayment($carId, $amount, $date, $receivingAccount, $narration = '') {
        $car = $this->outsideContext($carId);
        $sale = $this->getOutsideSale($carId, true);
        if (!$sale) throw new Exception('Record the Outside Car sale before receiving a balance payment.');
        $amount = round(floatval($amount), 2);
        if ($amount <= 0 || $amount - floatval($sale['buyer_outstanding']) > 0.01) throw new Exception('Payment cannot exceed the current buyer outstanding.');
        $entryId = $this->postJournalEntry('LOAN_RECEIVED', $date, $narration ?: "Outside Car buyer payment - {$car['registration_no']}", [
            ['account_id' => $receivingAccount, 'amount' => $amount, 'type' => 'DR', 'narration' => 'Buyer payment received'],
            ['account_id' => $sale['buyer_account_id'], 'amount' => $amount, 'type' => 'CR', 'narration' => 'Buyer outstanding cleared'],
        ], ['car_id' => $carId, 'party_id' => $sale['buyer_party_id'], 'entry_type_id' => systemEntryTypeId('OUTSIDE_BUYER_PAYMENT'), 'entry_amount' => $amount]);
        $paymentId = Database::uuid();
        $this->db->insert('outside_car_buyer_payments', [
            'id' => $paymentId, 'business_id' => $this->businessId, 'sale_id' => $sale['id'], 'car_id' => $carId,
            'buyer_party_id' => $sale['buyer_party_id'], 'payment_date' => $date, 'payment_kind' => 'RECEIPT', 'amount' => $amount,
            'journal_entry_id' => $entryId, 'created_by' => $this->userId,
        ]);
        $newOutstanding = round(floatval($sale['buyer_outstanding']) - $amount, 2);
        $this->db->query("UPDATE outside_car_sales SET buyer_outstanding = ? WHERE id = ?", [$newOutstanding,$sale['id']]);
        $this->db->query("UPDATE outside_car_deals SET buyer_status = ? WHERE id = ?", [$newOutstanding <= 0.009 ? 'FULLY_PAID' : 'PARTLY_PAID',$car['deal_id']]);
        if ($newOutstanding <= 0.009) $this->db->query("UPDATE cars SET status = 'SOLD' WHERE id = ? AND business_id = ?", [$carId,$this->businessId]);
        return $entryId;
    }

    public function approveOutsideCarSettlement($carId, array $data) {
        $car = $this->outsideContext($carId, true);
        $sale = $this->getOutsideSale($carId, true);
        if (!$sale) throw new Exception('Record the sale before approving settlement.');
        $active = $this->db->fetch("SELECT id FROM outside_car_settlements WHERE business_id = ? AND car_id = ? AND status <> 'REVERSED'", [$this->businessId,$carId]);
        if ($active) throw new Exception('This Outside Car already has an approved settlement.');
        $expenses = $this->db->fetchAll("SELECT * FROM outside_car_expenses WHERE business_id = ? AND car_id = ? AND status = 'POSTED'", [$this->businessId,$carId]);
        $actual = round(array_sum(array_map(fn($r) => floatval($r['actual_amount']), $expenses)), 2);
        $approvedExpense = round(array_sum(array_map(fn($r) => floatval($r['approved_recoverable_amount']), $expenses)), 2);
        $exactMargin = round(floatval($sale['net_vehicle_value']) - floatval($car['source_base_value']) - $approvedExpense, 2);
        $approvedMargin = trim((string) ($data['approved_margin'] ?? '')) === '' ? $exactMargin : round(floatval($data['approved_margin']), 2);
        $marginDifference = round($exactMargin - $approvedMargin, 2);
        $target = strtoupper(trim((string) ($data['difference_target'] ?? '')));
        if (abs($marginDifference) > 0.009 && !in_array($target, ['SOURCE_ENTITY','TIRANGA','ROUND_OFF'], true)) throw new Exception('Classify the difference between exact and approved margin.');
        $reason = trim((string) ($data['approval_reason'] ?? ''));
        if (strlen($reason) < 5) throw new Exception('Settlement approval reason must be at least 5 characters.');
        $isProfit = $approvedMargin >= 0;
        $tirangaPct = $isProfit ? floatval($car['tiranga_profit_pct']) : floatval($car['tiranga_loss_pct']);
        $entityPct = $isProfit ? floatval($car['entity_profit_pct']) : floatval($car['entity_loss_pct']);
        $tirangaShare = round($approvedMargin * $tirangaPct / 100, 2);
        $entityShare = round($approvedMargin - $tirangaShare, 2);
        if ($target === 'SOURCE_ENTITY') $entityShare = round($entityShare + $marginDifference, 2);
        elseif ($target === 'TIRANGA') $tirangaShare = round($tirangaShare + $marginDifference, 2);

        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $recoverable = $this->outsideSystemAccount('OUTCAR-COST', 'Outside Car Recoverable Costs', 'ASSET', 'Current Assets');
            $expenseAccount = $this->outsideSystemAccount('OUTCAR-EXP', 'Outside Car Business Expense / Adjustment', 'EXPENSE', 'Direct Expenses (Car)');
            $reclassExpenseIds = [];
            $reclassLines = [];
            foreach ($expenses as $expense) {
                $difference = round(floatval($expense['actual_amount']) - floatval($expense['approved_recoverable_amount']), 2);
                if ($expense['responsibility'] !== 'RECOVERABLE' || $difference <= 0.009 || !empty($expense['reclass_entry_id'])) continue;
                $debit = $expense['difference_bearer'] === 'SOURCE_ENTITY' ? $car['source_account_id'] : $expenseAccount['id'];
                $reclassLines[] = ['account_id' => $debit, 'amount' => $difference, 'type' => 'DR', 'narration' => "Non-recoverable {$expense['category']} difference"];
                $reclassLines[] = ['account_id' => $recoverable['id'], 'amount' => $difference, 'type' => 'CR', 'narration' => "Recoverable {$expense['category']} cost reduced"];
                $reclassExpenseIds[] = $expense['id'];
            }

            $clear = $this->outsideSystemAccount('OUTCAR-CLEAR', 'Outside Car Sale Clearing', 'LIABILITY', 'Outside Car Clearing');
            $profitIncome = $this->outsideSystemAccount('OUTCAR-PROFIT', 'Outside Car Deal Profit Income', 'INCOME', 'Direct Income');
            $roundAccount = $marginDifference >= 0
                ? $this->outsideSystemAccount('SETTLE-RNDI', 'Outside Car Settlement Round Off Income', 'INCOME', 'Settlement Adjustments')
                : $this->outsideSystemAccount('SETTLE-RNDE', 'Outside Car Settlement Round Off Expense', 'EXPENSE', 'Settlement Adjustments');
            $lines = [['account_id' => $clear['id'], 'amount' => floatval($sale['net_vehicle_value']), 'type' => 'DR', 'narration' => 'Allocate Outside Car vehicle value']];
            $entityPostingEntitlement = round(floatval($car['source_base_value']) + $entityShare, 2);
            if ($entityPostingEntitlement > 0) $lines[] = ['account_id' => $car['source_account_id'], 'amount' => $entityPostingEntitlement, 'type' => 'CR', 'narration' => 'Source Entity base and share'];
            elseif ($entityPostingEntitlement < 0) $lines[] = ['account_id' => $car['source_account_id'], 'amount' => abs($entityPostingEntitlement), 'type' => 'DR', 'narration' => 'Source Entity loss receivable'];
            if ($approvedExpense > 0) $lines[] = ['account_id' => $recoverable['id'], 'amount' => $approvedExpense, 'type' => 'CR', 'narration' => 'Approved expense recovery'];
            if ($tirangaShare > 0) $lines[] = ['account_id' => $profitIncome['id'], 'amount' => $tirangaShare, 'type' => 'CR', 'narration' => 'Tiranga profit share'];
            elseif ($tirangaShare < 0) $lines[] = ['account_id' => $expenseAccount['id'], 'amount' => abs($tirangaShare), 'type' => 'DR', 'narration' => 'Tiranga loss share'];
            if ($target === 'ROUND_OFF' && abs($marginDifference) > 0.009) {
                $lines[] = ['account_id' => $roundAccount['id'], 'amount' => abs($marginDifference), 'type' => $marginDifference > 0 ? 'CR' : 'DR', 'narration' => 'Approved settlement difference'];
            }
            $lines = array_merge($lines, $reclassLines);

            $advances = $this->db->fetchAll("SELECT * FROM outside_car_advances WHERE business_id = ? AND car_id = ? AND direction = 'PAID_TO_ENTITY' AND status = 'POSTED' AND amount > applied_amount", [$this->businessId,$carId]);
            $advanceApplied = round(array_sum(array_map(fn($r) => floatval($r['amount']) - floatval($r['applied_amount']), $advances)), 2);
            if ($advanceApplied > 0) {
                $advanceAccount = $this->outsideSystemAccount('OUTCAR-ADV', 'Outside Car Entity Advances', 'ASSET', 'Current Assets');
                $lines[] = ['account_id' => $car['source_account_id'], 'amount' => $advanceApplied, 'type' => 'DR', 'narration' => 'Advance applied to Source Entity entitlement'];
                $lines[] = ['account_id' => $advanceAccount['id'], 'amount' => $advanceApplied, 'type' => 'CR', 'narration' => 'Outside Car advance cleared'];
            }
            $allocationId = $this->postJournalEntry('JOURNAL_VOUCHER', $data['settlement_date'], "Outside Car settlement allocation - {$car['registration_no']}", $lines, [
                'car_id' => $carId, 'party_id' => $car['source_party_id'], 'entry_type_id' => systemEntryTypeId('OUTSIDE_CAR_SETTLEMENT'),
                'entry_amount' => floatval($sale['net_vehicle_value']), 'audit_metadata' => ['approval_reason' => $reason],
            ]);
            foreach ($reclassExpenseIds as $expenseId) {
                $this->db->query("UPDATE outside_car_expenses SET reclass_entry_id = ? WHERE id = ?", [$allocationId,$expenseId]);
            }

            if ($advanceApplied > 0) {
                $this->db->query("UPDATE outside_car_advances SET applied_amount = amount WHERE business_id = ? AND car_id = ? AND direction = 'PAID_TO_ENTITY' AND status = 'POSTED'", [$this->businessId,$carId]);
            }
            $directEntityCosts = round(array_sum(array_map(
                fn($r) => $r['responsibility'] === 'SOURCE_ENTITY' ? floatval($r['actual_amount']) : 0,
                $expenses
            )), 2);
            $entityRecoverableDifferences = round(array_sum(array_map(
                fn($r) => $r['responsibility'] === 'RECOVERABLE' && $r['difference_bearer'] === 'SOURCE_ENTITY'
                    ? max(0, floatval($r['actual_amount']) - floatval($r['approved_recoverable_amount'])) : 0,
                $expenses
            )), 2);
            $entityCosts = round($directEntityCosts + $entityRecoverableDifferences, 2);
            $receivedFromEntityRow = $this->db->fetch("SELECT COALESCE(SUM(amount),0) total FROM outside_car_advances WHERE business_id = ? AND car_id = ? AND direction = 'RECEIVED_FROM_ENTITY' AND status = 'POSTED'", [$this->businessId,$carId]);
            $receivedFromEntity = round(floatval($receivedFromEntityRow['total'] ?? 0), 2);
            $entityGrossEntitlement = round($entityPostingEntitlement - $entityCosts, 2);
            $entityNet = round($entityGrossEntitlement + $receivedFromEntity - $advanceApplied, 2);
            $remaining = round(max(0, $entityNet), 2);
            $receivable = round(max(0, -$entityNet), 2);
            $settlementId = Database::uuid();
            $settlement = [
                'id' => $settlementId, 'business_id' => $this->businessId, 'car_id' => $carId,
                'source_entity_id' => $car['source_entity_id'], 'sale_id' => $sale['id'], 'settlement_date' => $data['settlement_date'],
                'actual_expenses' => $actual, 'approved_expenses' => $approvedExpense, 'exact_margin' => $exactMargin,
                'approved_margin' => $approvedMargin, 'margin_difference' => $marginDifference, 'difference_target' => $target ?: null,
                'tiranga_share' => $tirangaShare, 'entity_share' => $entityShare, 'separate_commission' => $sale['separate_commission'],
                'tiranga_income' => round($tirangaShare + floatval($sale['separate_commission']), 2),
                'tiranga_entitlement' => round($approvedExpense + $tirangaShare + floatval($sale['separate_commission']), 2),
                'entity_gross_entitlement' => $entityGrossEntitlement, 'advances_applied' => $advanceApplied,
                'remaining_entity_payable' => $remaining, 'remaining_entity_receivable' => $receivable,
                'allocation_entry_id' => $allocationId,
                'status' => ($remaining > 0.009 || $receivable > 0.009) ? 'APPROVED' : 'SETTLED', 'approval_reason' => $reason,
                'approved_by' => $this->userId,
            ];
            $this->db->insert('outside_car_settlements', $settlement);
            $this->db->query("UPDATE outside_car_deals SET settlement_status = ? WHERE id = ?", [($remaining > 0.009 || $receivable > 0.009) ? 'SETTLEMENT_APPROVED' : 'FULLY_SETTLED',$car['deal_id']]);
            Auth::auditCreate('outside_car_settlement', $settlementId, $settlement, 'Outside Car settlement approved: ' . $reason, 'outside_cars');
            if ($owns) $this->db->commit();
            return $allocationId;
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function payOutsideEntity($carId, $amount, $date, $paymentAccount, $narration = '') {
        $car = $this->outsideContext($carId);
        $settlement = $this->db->fetch("SELECT * FROM outside_car_settlements WHERE business_id = ? AND car_id = ? AND status IN ('APPROVED','PARTIAL')", [$this->businessId,$carId]);
        if (!$settlement) throw new Exception('Approve the Outside Car settlement before paying the Source Entity.');
        $amount = round(floatval($amount), 2);
        if ($amount <= 0 || $amount - floatval($settlement['remaining_entity_payable']) > 0.01) throw new Exception('Payment cannot exceed remaining Source Entity payable.');
        $this->validateCashAvailable($paymentAccount, $amount);
        $entryId = $this->postJournalEntry('LOAN_REPAID', $date, $narration ?: "Source Entity settlement - {$car['registration_no']}", [
            ['account_id' => $car['source_account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => 'Source Entity payable settled'],
            ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Paid to {$car['source_entity_name']}"],
        ], ['car_id' => $carId, 'party_id' => $car['source_party_id'], 'entry_type_id' => systemEntryTypeId('OUTSIDE_ENTITY_SETTLEMENT_PAYMENT'), 'entry_amount' => $amount]);
        $this->db->insert('outside_entity_payments', [
            'id' => Database::uuid(), 'business_id' => $this->businessId, 'settlement_id' => $settlement['id'],
            'car_id' => $carId, 'source_entity_id' => $car['source_entity_id'], 'payment_date' => $date,
            'amount' => $amount, 'journal_entry_id' => $entryId, 'created_by' => $this->userId,
        ]);
        $settled = round(floatval($settlement['settled_amount']) + $amount, 2);
        $remaining = round(floatval($settlement['remaining_entity_payable']) - $amount, 2);
        $status = $remaining <= 0.009 ? 'SETTLED' : 'PARTIAL';
        $this->db->query("UPDATE outside_car_settlements SET settled_amount = ?, remaining_entity_payable = ?, status = ? WHERE id = ?", [$settled,max(0,$remaining),$status,$settlement['id']]);
        $this->db->query("UPDATE outside_car_deals SET settlement_status = ? WHERE id = ?", [$status === 'SETTLED' ? 'FULLY_SETTLED' : 'PARTLY_SETTLED',$car['deal_id']]);
        return $entryId;
    }

    public function recordOutsideRtoMovement($carId, $type, $amount, $date, $accountId, $narration = '') {
        $car = $this->outsideContext($carId);
        $type = strtoupper(trim((string) $type));
        if (!in_array($type, ['RECEIVE','PAY'], true)) throw new Exception('Select RTO money received or paid.');
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) throw new Exception('RTO amount must be greater than zero.');
        $rto = $this->outsideSystemAccount('RTO-CLEAR', 'RTO Clearing', 'LIABILITY', 'Current Liabilities');
        if ($type === 'RECEIVE') $lines = [
            ['account_id' => $accountId, 'amount' => $amount, 'type' => 'DR', 'narration' => 'RTO money received'],
            ['account_id' => $rto['id'], 'amount' => $amount, 'type' => 'CR', 'narration' => 'Outside Car RTO clearing'],
        ];
        else {
            $this->validateCashAvailable($accountId, $amount);
            $sale = $this->getOutsideSale($carId);
            $moves = $this->db->fetch("SELECT COALESCE(SUM(CASE WHEN movement_type IN ('RECEIVE','ALLOCATE') THEN amount WHEN movement_type='PAY' THEN -amount ELSE 0 END),0) AS balance FROM outside_car_rto_movements WHERE business_id=? AND car_id=? AND status='POSTED'",[$this->businessId,$carId]);
            $available = round(floatval($sale['buyer_rto_charge'] ?? 0) + floatval($moves['balance'] ?? 0), 2);
            if ($amount - $available > 0.01) throw new Exception('RTO payment exceeds this car\'s clearing balance. Record and approve the shortfall allocation first.');
            $lines = [
                ['account_id' => $rto['id'], 'amount' => $amount, 'type' => 'DR', 'narration' => 'RTO clearing utilized'],
                ['account_id' => $accountId, 'amount' => $amount, 'type' => 'CR', 'narration' => 'RTO payment made'],
            ];
        }
        $entryId = $this->postJournalEntry($type === 'RECEIVE' ? 'RTO_RECOVERY' : 'RTO_EXPENSE', $date, $narration ?: "Outside Car RTO $type - {$car['registration_no']}", $lines, [
            'car_id' => $carId, 'entry_type_id' => systemEntryTypeId($type === 'RECEIVE' ? 'OUTSIDE_RTO_RECEIPT' : 'OUTSIDE_RTO_PAYMENT'), 'entry_amount' => $amount,
        ]);
        $this->db->insert('outside_car_rto_movements', [
            'id' => Database::uuid(), 'business_id' => $this->businessId, 'car_id' => $carId,
            'source_entity_id' => $car['source_entity_id'], 'movement_type' => $type, 'movement_date' => $date,
            'amount' => $amount, 'journal_entry_id' => $entryId, 'narration' => $narration, 'created_by' => $this->userId,
        ]);
        $this->db->query("UPDATE outside_car_deals SET rto_status = 'IN_PROGRESS' WHERE id = ?", [$car['deal_id']]);
        return $entryId;
    }

    public function recordOutsideRtoShortfall($carId, $amount, $bearer, $date, $reason) {
        $car = $this->outsideContext($carId);
        $amount = round(floatval($amount), 2);
        $bearer = strtoupper(trim((string) $bearer));
        $reason = trim((string) $reason);
        if ($amount <= 0) throw new Exception('RTO shortfall amount must be greater than zero.');
        if (!in_array($bearer, ['TIRANGA','SOURCE_ENTITY'], true)) throw new Exception('Choose Tiranga or the Source Entity as the RTO shortfall bearer.');
        if (strlen($reason) < 5) throw new Exception('An approval reason is required for the RTO shortfall allocation.');
        $rto = $this->outsideSystemAccount('RTO-CLEAR', 'RTO Clearing', 'LIABILITY', 'Current Liabilities');
        $debitAccount = $bearer === 'SOURCE_ENTITY'
            ? $car['source_account_id']
            : $this->outsideSystemAccount('OUTCAR-EXP', 'Outside Car Business Expense / Adjustment', 'EXPENSE', 'Direct Expenses (Car)')['id'];
        $entryId = $this->postJournalEntry('JOURNAL_VOUCHER', $date, "Outside Car RTO shortfall - {$car['registration_no']}: {$reason}", [
            ['account_id'=>$debitAccount,'amount'=>$amount,'type'=>'DR','narration'=>"RTO shortfall borne by " . str_replace('_',' ',$bearer)],
            ['account_id'=>$rto['id'],'amount'=>$amount,'type'=>'CR','narration'=>'RTO clearing shortfall funded'],
        ], ['car_id'=>$carId,'party_id'=>$bearer === 'SOURCE_ENTITY' ? $car['source_party_id'] : null,'entry_type_id'=>systemEntryTypeId('OUTSIDE_RTO_ADJUSTMENT'),'entry_amount'=>$amount,'audit_metadata'=>['bearer'=>$bearer,'reason'=>$reason]]);
        $this->db->insert('outside_car_rto_movements', [
            'id'=>Database::uuid(),'business_id'=>$this->businessId,'car_id'=>$carId,'source_entity_id'=>$car['source_entity_id'],
            'movement_type'=>'ALLOCATE','movement_date'=>$date,'amount'=>$amount,'adjustment_bearer'=>$bearer,
            'journal_entry_id'=>$entryId,'narration'=>$reason,'created_by'=>$this->userId,
        ]);
        $this->db->query("UPDATE outside_car_deals SET rto_status='IN_PROGRESS' WHERE id=?",[$car['deal_id']]);
        Auth::auditCreate('outside_car',$carId,['rto_shortfall'=>$amount,'bearer'=>$bearer,'journal_entry_id'=>$entryId],"RTO shortfall approved: {$reason}",'outside_cars');
        return $entryId;
    }

    public function completeOutsideRto($carId, $reason) {
        $car = $this->outsideContext($carId);
        $reason = trim((string) $reason);
        if (strlen($reason) < 5) throw new Exception('RTO completion reason or file reference is required.');
        $sale = $this->getOutsideSale($carId);
        $moves = $this->db->fetch("SELECT COALESCE(SUM(CASE WHEN movement_type IN ('RECEIVE','ALLOCATE') AND status='POSTED' THEN amount WHEN movement_type='PAY' AND status='POSTED' THEN -amount ELSE 0 END),0) balance FROM outside_car_rto_movements WHERE business_id=? AND car_id=?",[$this->businessId,$carId]);
        $balance = round(floatval($sale['buyer_rto_charge'] ?? 0) + floatval($moves['balance'] ?? 0), 2);
        if (abs($balance) > 0.01) throw new Exception('RTO clearing must be zero before completion. Current balance: ' . formatAmount($balance));
        $this->db->query("UPDATE outside_car_deals SET rto_status='COMPLETED' WHERE id=?",[$car['deal_id']]);
        Auth::auditUpdate('outside_car',$carId,['rto_status'=>$car['rto_status']],['rto_status'=>'COMPLETED','reason'=>$reason],'Outside Car RTO completed: '.$reason,'outside_cars');
    }

    public function getOutsideCarFinancials($carId) {
        $car = $this->outsideContext($carId);
        $sale = $this->getOutsideSale($carId);
        $expense = $this->db->fetch("SELECT COALESCE(SUM(actual_amount),0) actual, COALESCE(SUM(approved_recoverable_amount),0) approved FROM outside_car_expenses WHERE business_id = ? AND car_id = ? AND status = 'POSTED'", [$this->businessId,$carId]);
        $advance = $this->db->fetch("SELECT COALESCE(SUM(CASE WHEN direction='PAID_TO_ENTITY' THEN amount ELSE 0 END),0) paid, COALESCE(SUM(CASE WHEN direction='RECEIVED_FROM_ENTITY' THEN amount ELSE 0 END),0) received FROM outside_car_advances WHERE business_id = ? AND car_id = ? AND status = 'POSTED'", [$this->businessId,$carId]);
        $rto = $this->db->fetch("SELECT COALESCE(SUM(CASE WHEN movement_type='RECEIVE' THEN amount ELSE 0 END),0) received, COALESCE(SUM(CASE WHEN movement_type='ALLOCATE' THEN amount ELSE 0 END),0) allocated, COALESCE(SUM(CASE WHEN movement_type='PAY' THEN amount ELSE 0 END),0) paid FROM outside_car_rto_movements WHERE business_id = ? AND car_id = ? AND status = 'POSTED'", [$this->businessId,$carId]);
        $settlement = $this->db->fetch("SELECT * FROM outside_car_settlements WHERE business_id = ? AND car_id = ? AND status <> 'REVERSED' ORDER BY created_at DESC LIMIT 1", [$this->businessId,$carId]);
        return ['car'=>$car,'sale'=>$sale,'expense'=>$expense,'advance'=>$advance,'rto'=>$rto,'settlement'=>$settlement];
    }

    public function generateOutsideCarAgreement($carId, array $data = []) {
        $car = $this->outsideContext($carId);
        $sale = $this->getOutsideSale($carId);
        if (!$sale) throw new Exception('Record the buyer sale before generating the agreement.');

        $vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!class_exists('Mpdf\\Mpdf') && is_file($vendorAutoload)) require_once $vendorAutoload;
        if (!class_exists('Mpdf\\Mpdf')) throw new Exception('Agreement PDF dependency is missing. Run composer install before generating agreements.');
        $fontPath = dirname(__DIR__) . '/assets/fonts/NotoSansGujarati-Regular.ttf';
        if (!is_file($fontPath)) throw new Exception('Gujarati agreement font is missing: assets/fonts/NotoSansGujarati-Regular.ttf');

        $template = $this->db->fetch(
            "SELECT * FROM outside_agreement_clause_templates WHERE business_id = ? AND is_active = 1 ORDER BY created_at DESC LIMIT 1",
            [$this->businessId]
        );
        if (!$template) {
            $clauses = [
                ['gu' => 'ખરીદનાર દ્વારા વાહન, દસ્તાવેજો અને દર્શાવેલી સ્થિતિ તપાસીને સ્વીકારવામાં આવ્યા છે.', 'en' => 'The buyer confirms inspection and acceptance of the vehicle, documents, and disclosed condition.'],
                ['gu' => 'બાકી રકમ અને આરટીઓ સંબંધિત જવાબદારીઓ આ કરારમાં દર્શાવ્યા મુજબ રહેશે.', 'en' => 'Balance payment and RTO responsibilities remain as recorded in this agreement.'],
                ['gu' => 'સહી કરાયેલ કરારમાં ફેરફાર માટે નવી આવૃત્તિ અને તમામ પક્ષોની સંમતિ જરૂરી રહેશે.', 'en' => 'Any amendment after signing requires a new version and consent of all parties.'],
                ['gu' => 'આ નમૂનો વ્યવસાયિક રેકોર્ડ માટે છે અને સ્થાનિક કાનૂની સમીક્ષા જરૂરી છે.', 'en' => 'This template is for business records and requires local legal review.'],
            ];
            $template = [
                'id' => Database::uuid(), 'business_id' => $this->businessId, 'version_code' => 'GUJ-EN-1',
                'language' => 'BILINGUAL', 'title' => 'વાહન વેચાણ કરાર / Vehicle Sale Agreement',
                'clauses_json' => json_encode($clauses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => $this->userId,
            ];
            $this->db->insert('outside_agreement_clause_templates', $template);
        }
        $clauses = json_decode($template['clauses_json'], true);
        if (!is_array($clauses)) throw new Exception('Agreement clause template is invalid.');
        $business = $this->db->fetch("SELECT * FROM businesses WHERE id = ?", [$this->businessId]);
        $source = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$car['source_party_id'],$this->businessId]);
        $version = $this->db->fetch("SELECT COALESCE(MAX(version_no),0)+1 AS next_version FROM outside_car_agreements WHERE business_id = ? AND car_id = ?", [$this->businessId,$carId]);
        $versionNo = max(1, intval($version['next_version'] ?? 1));
        $payments = $this->db->fetchAll("SELECT payment_date, amount FROM outside_car_buyer_payments WHERE business_id = ? AND sale_id = ? AND status = 'POSTED' ORDER BY payment_date, created_at", [$this->businessId,$sale['id']]);
        $snapshot = [
            'schema' => 'outside-car-agreement-v1', 'generated_at' => date(DATE_ATOM),
            'business' => ['name'=>$business['name'] ?? APP_NAME,'address'=>$business['address'] ?? '','phone'=>$business['phone'] ?? '','gstin'=>$business['gstin'] ?? ''],
            'source_entity' => ['name'=>$source['name'] ?? $car['source_entity_name'],'phone'=>$source['phone'] ?? '','address'=>$source['address'] ?? ''],
            'buyer' => ['name'=>$sale['buyer_name'],'phone'=>$sale['buyer_phone'] ?? '','address'=>$data['buyer_address'] ?? ''],
            'vehicle' => ['registration_no'=>$car['registration_no'],'make'=>$car['make'],'model'=>$car['model'],'year'=>$car['year'],'color'=>$car['color'],'chassis_no'=>$car['chassis_no'] ?? '','engine_no'=>$car['engine_no'] ?? '','second_key'=>!empty($car['has_second_key']),'insurance'=>$car['insurance_details'] ?? '','hypothecation'=>$car['hypothecation_details'] ?? ''],
            'amounts' => ['A_source_base'=>round(floatval($car['source_base_value']),2),'B_actual_expenses'=>round(floatval($this->getOutsideCarFinancials($carId)['expense']['actual'] ?? 0),2),'C_vehicle_selling_price'=>round(floatval($sale['vehicle_sale_price']),2),'K_separate_commission'=>round(floatval($sale['separate_commission']),2),'discount'=>round(floatval($sale['discount_amount']),2),'buyer_rto'=>round(floatval($sale['buyer_rto_charge']),2),'buyer_total'=>round(floatval($sale['buyer_total']),2),'buyer_outstanding'=>round(floatval($sale['buyer_outstanding']),2)],
            'sale_date'=>$sale['sale_date'],'payments'=>$payments,
            'delivery_terms'=>trim((string) ($data['delivery_terms'] ?? 'Full payment and agreement readiness required before delivery.')),
            'witnesses'=>[['name'=>trim((string) ($data['witness_1'] ?? ''))],['name'=>trim((string) ($data['witness_2'] ?? ''))]],
            'clause_version'=>$template['version_code'],'clauses'=>$clauses,
        ];
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($snapshotJson === false) throw new Exception('Could not create agreement snapshot.');
        $hash = hash('sha256', $snapshotJson);
        $h = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn($value) => number_format(floatval($value), 2);
        $clauseHtml = '';
        foreach ($clauses as $index => $clause) {
            $clauseHtml .= '<div class="clause"><b>' . ($index + 1) . '.</b> ' . $h($clause['gu'] ?? '') . '<br><span class="en">' . $h($clause['en'] ?? '') . '</span></div>';
        }
        $html = '<!doctype html><html lang="gu"><head><meta charset="utf-8"><style>@font-face{font-family:notogujarati;src:url("' . $h($fontPath) . '")}body{font-family:notogujarati,sans-serif;font-size:10.5pt;color:#111}h1{text-align:center;font-size:18pt}.meta{font-size:8pt;color:#555;text-align:center}.box{border:1px solid #444;padding:10px;margin:10px 0}.grid{width:100%;border-collapse:collapse}.grid td,.grid th{border:1px solid #777;padding:5px;text-align:left}.en{color:#333}.clause{margin:8px 0}.sign{height:70px;vertical-align:bottom;text-align:center}</style></head><body>'
            . '<h1>' . $h($template['title']) . '</h1><div class="meta">Version ' . $versionNo . ' · SHA-256 ' . $h($hash) . '</div>'
            . '<div class="box"><b>' . $h($business['name'] ?? APP_NAME) . '</b><br>' . $h($business['address'] ?? '') . '<br>' . $h($business['phone'] ?? '') . '</div>'
            . '<table class="grid"><tr><th>Source Entity</th><td>' . $h($source['name'] ?? '') . '<br>' . $h($source['phone'] ?? '') . '</td><th>Buyer</th><td>' . $h($sale['buyer_name']) . '<br>' . $h($sale['buyer_phone'] ?? '') . '</td></tr>'
            . '<tr><th>Vehicle</th><td colspan="3">' . $h(formatRegistrationNo($car['registration_no'])) . ' · ' . $h(trim($car['make'] . ' ' . $car['model'])) . ' · ' . $h($car['year']) . '</td></tr></table>'
            . '<h3>A / B / C / K Calculation</h3><table class="grid"><tr><th>A: Source Base Value</th><td>₹' . $money($snapshot['amounts']['A_source_base']) . '</td><th>B: Actual Expenses</th><td>₹' . $money($snapshot['amounts']['B_actual_expenses']) . '</td></tr><tr><th>C: Vehicle Selling Price</th><td>₹' . $money($snapshot['amounts']['C_vehicle_selling_price']) . '</td><th>K: Separate Commission</th><td>₹' . $money($snapshot['amounts']['K_separate_commission']) . '</td></tr><tr><th>Buyer RTO</th><td>₹' . $money($snapshot['amounts']['buyer_rto']) . '</td><th>Buyer Outstanding</th><td>₹' . $money($snapshot['amounts']['buyer_outstanding']) . '</td></tr></table>'
            . '<h3>Terms / શરતો</h3>' . $clauseHtml . '<div class="box"><b>Delivery terms:</b> ' . $h($snapshot['delivery_terms']) . '</div>'
            . '<table class="grid"><tr><td class="sign">Source Entity Signature</td><td class="sign">Buyer Signature</td><td class="sign">Tiranga Authorized Signatory</td></tr><tr><td class="sign">Witness 1: ' . $h($snapshot['witnesses'][0]['name']) . '</td><td class="sign">Witness 2: ' . $h($snapshot['witnesses'][1]['name']) . '</td><td class="sign">Date</td></tr></table></body></html>';

        $folder = dirname(__DIR__) . '/uploads/agreements/' . preg_replace('/[^a-zA-Z0-9-]/', '', $this->businessId);
        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) throw new Exception('Could not create agreement storage folder.');
        $baseName = preg_replace('/[^A-Z0-9-]/', '', strtoupper($car['registration_no'])) . '-v' . $versionNo . '-' . substr($hash, 0, 12);
        $htmlPath = $folder . '/' . $baseName . '.html';
        $pdfPath = $folder . '/' . $baseName . '.pdf';
        if (file_put_contents($htmlPath, $html, LOCK_EX) === false) throw new Exception('Could not save immutable agreement HTML.');
        try {
            $fontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
            $mpdf = new \Mpdf\Mpdf([
                'mode'=>'utf-8','format'=>'A4','fontDir'=>array_merge((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'], [dirname($fontPath)]),
                'fontdata'=>$fontConfig['fontdata'] + ['notogujarati'=>['R'=>basename($fontPath)]], 'default_font'=>'notogujarati',
                'tempDir'=>sys_get_temp_dir(),
            ]);
            $mpdf->WriteHTML($html);
            $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);
        } catch (Throwable $e) {
            @unlink($htmlPath);
            throw new Exception('Agreement PDF generation failed: ' . $e->getMessage(), 0, $e);
        }
        $id = Database::uuid();
        $record = ['id'=>$id,'business_id'=>$this->businessId,'car_id'=>$carId,'sale_id'=>$sale['id'],'version_no'=>$versionNo,'language'=>'BILINGUAL','status'=>'GENERATED','clause_version'=>$template['version_code'],'snapshot_json'=>$snapshotJson,'snapshot_hash'=>$hash,'html_path'=>str_replace(dirname(__DIR__) . '/', '', $htmlPath),'pdf_path'=>str_replace(dirname(__DIR__) . '/', '', $pdfPath),'created_by'=>$this->userId];
        $this->db->insert('outside_car_agreements',$record);
        $this->db->query("UPDATE outside_car_deals SET agreement_status='GENERATED' WHERE id = ?",[$car['deal_id']]);
        Auth::auditCreate('outside_car_agreement',$id,$record,'Immutable Outside Car agreement generated','outside_cars');
        return $record;
    }

    public function markOutsideCarAgreementSigned($agreementId) {
        $agreement = $this->db->fetch("SELECT * FROM outside_car_agreements WHERE id = ? AND business_id = ?",[$agreementId,$this->businessId]);
        if (!$agreement) throw new Exception('Agreement not found.');
        if ($agreement['status'] === 'SIGNED') return;
        $this->db->query("UPDATE outside_car_agreements SET status='SIGNED', signed_at=NOW() WHERE id = ? AND business_id = ?",[$agreementId,$this->businessId]);
        $this->db->query("UPDATE outside_car_deals SET agreement_status='SIGNED' WHERE business_id = ? AND car_id = ?",[$this->businessId,$agreement['car_id']]);
        Auth::auditUpdate('outside_car_agreement',$agreementId,['status'=>$agreement['status']],['status'=>'SIGNED'],'Signed Outside Car agreement attached','outside_cars');
    }

    public function recordOutsideCarDelivery($carId, array $data) {
        $car = $this->outsideContext($carId);
        $sale = $this->getOutsideSale($carId);
        if (!$sale) throw new Exception('Record the sale before vehicle delivery.');
        if ($this->db->fetch("SELECT id FROM outside_car_deliveries WHERE business_id = ? AND car_id = ?", [$this->businessId,$carId])) throw new Exception('Delivery is already recorded.');
        $agreement = $this->db->fetch("SELECT * FROM outside_car_agreements WHERE business_id = ? AND car_id = ? ORDER BY version_no DESC LIMIT 1", [$this->businessId,$carId]);
        $override = !empty($data['override_used']);
        if ((floatval($sale['buyer_outstanding']) > 0.009 || !$agreement) && !$override) throw new Exception('Full buyer payment and a generated agreement are required before delivery, or record an authorized override.');
        if ($override && strlen(trim((string) ($data['override_reason'] ?? ''))) < 5) throw new Exception('Delivery override reason is required.');
        $id = Database::uuid();
        $record = [
            'id'=>$id,'business_id'=>$this->businessId,'car_id'=>$carId,'sale_id'=>$sale['id'],
            'delivery_date'=>$data['delivery_date'],'delivery_time'=>$data['delivery_time'] ?: null,
            'odometer'=>intval($data['odometer'] ?? 0) ?: null,'fuel_level'=>trim((string) ($data['fuel_level'] ?? '')),
            'keys_handed_over'=>max(1,intval($data['keys_handed_over'] ?? 1)),
            'documents_handed_over'=>trim((string) ($data['documents_handed_over'] ?? '')),
            'receiver_name'=>trim((string) ($data['receiver_name'] ?? '')),
            'override_used'=>$override ? 1 : 0,'override_reason'=>$override ? trim((string) $data['override_reason']) : null,
            'promised_payment_date'=>$override ? ($data['promised_payment_date'] ?: null) : null,
            'buyer_balance_at_delivery'=>$sale['buyer_outstanding'],'recorded_by'=>$this->userId,
        ];
        if ($record['receiver_name'] === '') throw new Exception('Receiver name is required.');
        $this->db->insert('outside_car_deliveries',$record);
        $this->db->query("UPDATE outside_car_deals SET physical_status = 'DELIVERED' WHERE id = ?",[$car['deal_id']]);
        Auth::auditCreate('outside_car_delivery',$id,$record,'Outside Car delivery recorded' . ($override ? ' with override' : ''),'outside_cars');
        return $id;
    }

    private function assertOutsideCarEntryCanBeReversed($entry) {
        $identity = $this->outsideEntryIdentity($entry['entry_type_id'] ?? '');
        if ($identity === 'OUTSIDE_CAR_SALE') {
            $sale = $this->db->fetch("SELECT id FROM outside_car_sales WHERE business_id = ? AND sale_entry_id = ? AND status <> 'REVERSED'",[$this->businessId,$entry['id']]);
            if ($sale) {
                $settled = $this->db->fetch("SELECT COUNT(*) cnt FROM outside_car_settlements WHERE business_id = ? AND sale_id = ? AND status <> 'REVERSED'",[$this->businessId,$sale['id']]);
                $payments = $this->db->fetch("SELECT COUNT(*) cnt FROM outside_car_buyer_payments WHERE business_id = ? AND sale_id = ? AND status = 'POSTED'",[$this->businessId,$sale['id']]);
                $rto = $this->db->fetch("SELECT COUNT(*) cnt FROM outside_car_rto_movements WHERE business_id=? AND car_id=? AND status='POSTED'",[$this->businessId,$entry['car_id'] ?? '']);
                if (($settled['cnt'] ?? 0) > 0 || ($payments['cnt'] ?? 0) > 0 || ($rto['cnt'] ?? 0) > 0) throw new Exception('Reverse Outside Car settlement, later buyer payments, and RTO movements before reversing the sale.');
            }
        }
        if ($identity === 'OUTSIDE_CAR_SETTLEMENT') {
            $settlement = $this->db->fetch("SELECT id FROM outside_car_settlements WHERE business_id = ? AND allocation_entry_id = ? AND status <> 'REVERSED'",[$this->businessId,$entry['id']]);
            if ($settlement) {
                $payments = $this->db->fetch("SELECT COUNT(*) cnt FROM outside_entity_payments WHERE business_id = ? AND settlement_id = ? AND status = 'POSTED'",[$this->businessId,$settlement['id']]);
                if (($payments['cnt'] ?? 0) > 0) throw new Exception('Reverse Source Entity payments before reversing the Outside Car settlement.');
            }
        }
        if ($identity === 'OUTSIDE_CAR_EXPENSE' || $identity === 'OUTSIDE_ENTITY_BASE_ADVANCE') {
            $settlement = $this->db->fetch("SELECT COUNT(*) cnt FROM outside_car_settlements WHERE business_id=? AND car_id=? AND status <> 'REVERSED'",[$this->businessId,$entry['car_id'] ?? '']);
            if (($settlement['cnt'] ?? 0) > 0) throw new Exception('Reverse the Outside Car settlement before reversing its expenses or applied advances.');
        }
    }

    private function applyOutsideCarReversalEffects($entry, $reversalId) {
        $identity = $this->outsideEntryIdentity($entry['entry_type_id'] ?? '');
        $map = [
            'OUTSIDE_ENTITY_BASE_ADVANCE' => ['outside_car_advances','journal_entry_id'],
            'OUTSIDE_ENTITY_ADVANCE_RECEIVED' => ['outside_car_advances','journal_entry_id'],
            'OUTSIDE_CAR_EXPENSE' => ['outside_car_expenses','journal_entry_id'],
            'OUTSIDE_BUYER_PAYMENT' => ['outside_car_buyer_payments','journal_entry_id'],
            'OUTSIDE_BUYER_REFUND' => ['outside_car_buyer_payments','journal_entry_id'],
            'OUTSIDE_ENTITY_SETTLEMENT_PAYMENT' => ['outside_entity_payments','journal_entry_id'],
            'OUTSIDE_RTO_RECEIPT' => ['outside_car_rto_movements','journal_entry_id'],
            'OUTSIDE_RTO_PAYMENT' => ['outside_car_rto_movements','journal_entry_id'],
            'OUTSIDE_RTO_ADJUSTMENT' => ['outside_car_rto_movements','journal_entry_id'],
        ];
        if (isset($map[$identity])) {
            [$table,$column] = $map[$identity];
            $this->db->query("UPDATE `$table` SET status = 'REVERSED' WHERE business_id = ? AND `$column` = ?",[$this->businessId,$entry['id']]);
        }
        if ($identity === 'OUTSIDE_BUYER_PAYMENT' || $identity === 'OUTSIDE_BUYER_REFUND') {
            $payment = $this->db->fetch("SELECT * FROM outside_car_buyer_payments WHERE business_id = ? AND journal_entry_id = ?",[$this->businessId,$entry['id']]);
            if ($payment) {
                $delta = $identity === 'OUTSIDE_BUYER_REFUND' ? -floatval($payment['amount']) : floatval($payment['amount']);
                $this->db->query("UPDATE outside_car_sales SET buyer_outstanding = GREATEST(0,buyer_outstanding + ?) WHERE id = ?",[$delta,$payment['sale_id']]);
                $current = $this->db->fetch("SELECT buyer_outstanding FROM outside_car_sales WHERE id=?",[$payment['sale_id']]);
                $paid = floatval($current['buyer_outstanding'] ?? 0) <= 0.009;
                $this->db->query("UPDATE outside_car_deals SET buyer_status=? WHERE business_id=? AND car_id=?",[$paid?'FULLY_PAID':'PARTLY_PAID',$this->businessId,$payment['car_id']]);
                $this->db->query("UPDATE cars SET status=? WHERE business_id=? AND id=?",[$paid?'SOLD':'PENDING_PAYMENT',$this->businessId,$payment['car_id']]);
            }
        } elseif ($identity === 'OUTSIDE_CAR_EXPENSE') {
            $expense = $this->db->fetch("SELECT * FROM outside_car_expenses WHERE business_id=? AND journal_entry_id=?",[$this->businessId,$entry['id']]);
            if ($expense && $expense['responsibility'] === 'BUYER') {
                $sale = $this->db->fetch("SELECT id FROM outside_car_sales WHERE business_id=? AND car_id=? AND status<>'REVERSED' ORDER BY created_at DESC LIMIT 1",[$this->businessId,$expense['car_id']]);
                if ($sale) $this->db->query("UPDATE outside_car_sales SET other_buyer_charges=GREATEST(0,other_buyer_charges-?),buyer_total=GREATEST(0,buyer_total-?),buyer_outstanding=GREATEST(0,buyer_outstanding-?) WHERE id=?",[$expense['actual_amount'],$expense['actual_amount'],$expense['actual_amount'],$sale['id']]);
            }
        } elseif ($identity === 'OUTSIDE_ENTITY_SETTLEMENT_PAYMENT') {
            $payment = $this->db->fetch("SELECT * FROM outside_entity_payments WHERE business_id = ? AND journal_entry_id = ?",[$this->businessId,$entry['id']]);
            if ($payment) {
                $this->db->query("UPDATE outside_car_settlements SET settled_amount = GREATEST(0,settled_amount-?), remaining_entity_payable = remaining_entity_payable+?, status='PARTIAL' WHERE id = ?",[$payment['amount'],$payment['amount'],$payment['settlement_id']]);
                $this->db->query("UPDATE outside_car_deals SET settlement_status='PARTLY_SETTLED' WHERE business_id=? AND car_id=?",[$this->businessId,$payment['car_id']]);
            }
        } elseif ($identity === 'OUTSIDE_CAR_SETTLEMENT') {
            $this->db->query("UPDATE outside_car_settlements SET status='REVERSED', remaining_entity_payable=0 WHERE business_id = ? AND allocation_entry_id = ?",[$this->businessId,$entry['id']]);
            if (!empty($entry['car_id'])) {
                $this->db->query("UPDATE outside_car_deals SET settlement_status='CALCULATION_PENDING' WHERE business_id = ? AND car_id = ?",[$this->businessId,$entry['car_id']]);
                $this->db->query("UPDATE outside_car_advances SET applied_amount=0 WHERE business_id=? AND car_id=? AND direction='PAID_TO_ENTITY' AND status='POSTED'",[$this->businessId,$entry['car_id']]);
                $this->db->query("UPDATE outside_car_expenses SET reclass_entry_id=NULL WHERE business_id=? AND car_id=? AND reclass_entry_id=?",[$this->businessId,$entry['car_id'],$entry['id']]);
            }
        } elseif ($identity === 'OUTSIDE_CAR_SALE') {
            $this->db->query("UPDATE outside_car_sales SET status='REVERSED', buyer_outstanding=0 WHERE business_id = ? AND sale_entry_id = ?",[$this->businessId,$entry['id']]);
            $tokens = $this->db->fetchAll("SELECT id FROM car_tokens WHERE business_id=? AND applied_sale_entry_id=?",[$this->businessId,$entry['id']]);
            foreach ($tokens as $token) $this->db->query("UPDATE car_tokens SET applied_amount=0,applied_sale_entry_id=NULL,status='OPEN' WHERE id=? AND business_id=?",[$token['id'],$this->businessId]);
            if (!empty($entry['car_id'])) {
                $this->db->query("UPDATE outside_car_deals SET buyer_status='NO_BUYER', physical_status='WITH_TIRANGA', terms_locked_at=NULL WHERE business_id = ? AND car_id = ?",[$this->businessId,$entry['car_id']]);
                $this->db->query("UPDATE cars SET status='IN_STOCK',sold_date=NULL,sale_price=NULL,sale_commission_amount=0,buyer_name=NULL,buyer_party_id=NULL WHERE business_id=? AND id=?",[$this->businessId,$entry['car_id']]);
            }
        }
    }

    private function outsideEntryIdentity($entryTypeId) {
        $entryTypeId = strtoupper(trim((string) $entryTypeId));
        return str_starts_with($entryTypeId, 'SYSTEM:') ? substr($entryTypeId, 7) : '';
    }
}
