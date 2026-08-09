<?php

trait CarLoanCommissionAccounting {
    private function ensureCarLoanCommissionSchema() {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `car_loan_commissions` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `buyer_party_id` CHAR(36) NOT NULL,
                `financier_party_id` CHAR(36) NOT NULL,
                `loan_account_no` VARCHAR(100) DEFAULT NULL,
                `approval_date` DATE NOT NULL,
                `loan_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `commission_type` VARCHAR(20) NOT NULL DEFAULT 'FIXED',
                `commission_value` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                `commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `received_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `accrual_entry_id` CHAR(36) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
                `notes` VARCHAR(500) DEFAULT NULL,
                `created_by` CHAR(36) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_car_loan_accrual` (`accrual_entry_id`),
                KEY `idx_car_loan_car` (`business_id`,`car_id`,`status`),
                KEY `idx_car_loan_financier` (`business_id`,`financier_party_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `car_loan_commission_receipts` (
                `id` CHAR(36) NOT NULL,
                `business_id` CHAR(36) NOT NULL,
                `commission_id` CHAR(36) NOT NULL,
                `car_id` CHAR(36) NOT NULL,
                `receipt_date` DATE NOT NULL,
                `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `receiving_account_id` CHAR(36) NOT NULL,
                `journal_entry_id` CHAR(36) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'POSTED',
                `narration` VARCHAR(500) DEFAULT NULL,
                `created_by` CHAR(36) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_car_loan_receipt_entry` (`journal_entry_id`),
                KEY `idx_car_loan_receipt` (`business_id`,`commission_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function createCarLoanCommission($carId, array $data) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id=? AND business_id=?", [$carId,$this->businessId]);
        if (!$car || $car['status'] === 'CANCELLED') throw new Exception('Active car not found.');
        if (empty($car['buyer_party_id'])) throw new Exception('Record the buyer sale before adding its loan commission.');
        $loanAmount = round(parseDecimalInput($data['loan_amount'] ?? 0), 2);
        $commissionType = strtoupper(trim((string) ($data['commission_type'] ?? 'FIXED')));
        $commissionValue = round(parseDecimalInput($data['commission_value'] ?? 0), 4);
        if ($loanAmount <= 0) throw new Exception('Customer loan amount must be greater than zero.');
        if (!in_array($commissionType, ['FIXED','PERCENT'], true)) throw new Exception('Select fixed or percentage commission.');
        if ($commissionValue <= 0 || ($commissionType === 'PERCENT' && $commissionValue > 100)) throw new Exception('Enter a valid loan commission value.');
        $commissionAmount = $commissionType === 'PERCENT' ? round($loanAmount * $commissionValue / 100, 2) : round($commissionValue, 2);
        $date = trim((string) ($data['approval_date'] ?? ''));
        $dateObject = DateTime::createFromFormat('!Y-m-d',$date);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $date) throw new Exception('A valid loan approval date is required.');
        $this->validateDateNotLocked($date);
        $financier = $this->resolveParty($data['financier_party_id'] ?? '', $data['financier_name'] ?? '', $data['financier_phone'] ?? '', 'DEBTOR', ['DEBTOR','BUYER']);
        $income = $this->getOrCreateSystemAccount('LOAN-COMM', 'Car Loan Commission Income', 'INCOME', 'Direct Income');

        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $entryId = $this->postJournalEntry('JOURNAL_VOUCHER', $date, $data['notes'] ?: "Loan commission earned - {$car['registration_no']}", [
                ['account_id'=>$financier['account_id'],'amount'=>$commissionAmount,'type'=>'DR','narration'=>"Commission receivable from {$financier['name']}"],
                ['account_id'=>$income['id'],'amount'=>$commissionAmount,'type'=>'CR','narration'=>'Car-wise loan commission income'],
            ], ['car_id'=>$carId,'party_id'=>$financier['id'],'entry_type_id'=>systemEntryTypeId('CAR_LOAN_COMMISSION_EARNED'),'entry_amount'=>$commissionAmount]);
            $id = Database::uuid();
            $record = [
                'id'=>$id,'business_id'=>$this->businessId,'car_id'=>$carId,'buyer_party_id'=>$car['buyer_party_id'],
                'financier_party_id'=>$financier['id'],'loan_account_no'=>trim((string) ($data['loan_account_no'] ?? '')),
                'approval_date'=>$date,'loan_amount'=>$loanAmount,'commission_type'=>$commissionType,
                'commission_value'=>$commissionValue,'commission_amount'=>$commissionAmount,'accrual_entry_id'=>$entryId,
                'status'=>'PENDING','notes'=>trim((string) ($data['notes'] ?? '')),'created_by'=>$this->userId,
            ];
            $this->db->insert('car_loan_commissions', $record);
            $receivedNow = round(parseDecimalInput($data['received_now'] ?? 0), 2);
            if ($receivedNow > 0) $this->recordCarLoanCommissionReceipt($id, $receivedNow, $date, $data['receiving_account_id'] ?? '', 'Initial loan commission receipt');
            Auth::auditCreate('car_loan_commission',$id,$record,"Loan commission linked to {$car['registration_no']}",'cars');
            if ($owns) $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function recordCarLoanCommissionReceipt($commissionId, $amount, $date, $receivingAccountId, $narration = '') {
        // The whole receipt (row lock -> journal post -> receipt row -> case
        // update) must be one unit of work. Without the wrapping transaction
        // the FOR UPDATE lock on the case row is released immediately, so two
        // concurrent receipts could both read the same received_amount and
        // over-receive; a failure after the journal post would also leave an
        // orphaned entry.
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $case = $this->db->fetch("SELECT clc.*,dc.account_id,dc.name financier_name,c.registration_no FROM car_loan_commissions clc JOIN debtors_creditors dc ON dc.id=clc.financier_party_id JOIN cars c ON c.id=clc.car_id WHERE clc.id=? AND clc.business_id=? AND clc.status<>'REVERSED' FOR UPDATE",[$commissionId,$this->businessId]);
            if (!$case) throw new Exception('Loan commission case not found.');
            $amount = round(parseDecimalInput($amount), 2);
            $pending = round(floatval($case['commission_amount']) - floatval($case['received_amount']), 2);
            if ($amount <= 0 || $amount - $pending > 0.01) throw new Exception('Receipt cannot exceed pending loan commission of ' . formatAmount($pending) . '.');
            $entryId = $this->postJournalEntry('LOAN_RECEIVED', $date, $narration ?: "Loan commission received - {$case['registration_no']}", [
                ['account_id'=>$receivingAccountId,'amount'=>$amount,'type'=>'DR','narration'=>'Loan commission received'],
                ['account_id'=>$case['account_id'],'amount'=>$amount,'type'=>'CR','narration'=>"Commission receivable cleared from {$case['financier_name']}"],
            ], ['car_id'=>$case['car_id'],'party_id'=>$case['financier_party_id'],'entry_type_id'=>systemEntryTypeId('CAR_LOAN_COMMISSION_RECEIPT'),'entry_amount'=>$amount]);
            $this->db->insert('car_loan_commission_receipts', [
                'id'=>Database::uuid(),'business_id'=>$this->businessId,'commission_id'=>$commissionId,'car_id'=>$case['car_id'],
                'receipt_date'=>$date,'amount'=>$amount,'receiving_account_id'=>$receivingAccountId,'journal_entry_id'=>$entryId,
                'narration'=>$narration,'created_by'=>$this->userId,
            ]);
            $received = round(floatval($case['received_amount']) + $amount, 2);
            $this->db->query("UPDATE car_loan_commissions SET received_amount=?,status=? WHERE id=?",[$received,$received+0.009>=floatval($case['commission_amount'])?'RECEIVED':'PARTIAL',$commissionId]);
            if ($owns) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function getCarLoanCommissions($carId) {
        return $this->db->fetchAll("SELECT clc.*,dc.name financier_name,dc.phone financier_phone,je.reference_no FROM car_loan_commissions clc JOIN debtors_creditors dc ON dc.id=clc.financier_party_id LEFT JOIN journal_entries je ON je.id=clc.accrual_entry_id WHERE clc.business_id=? AND clc.car_id=? ORDER BY clc.created_at DESC",[$this->businessId,$carId]);
    }

    private function assertCarLoanCommissionEntryCanBeReversed($entry) {
        $identity = strtoupper(trim((string) ($entry['entry_type_id'] ?? '')));
        if (($entry['transaction_type'] ?? '') === 'CAR_SALE' && !empty($entry['car_id'])) {
            $active = $this->db->fetch("SELECT COUNT(*) cnt FROM car_loan_commissions WHERE business_id=? AND car_id=? AND status<>'REVERSED'",[$this->businessId,$entry['car_id']]);
            if (($active['cnt'] ?? 0) > 0) throw new Exception('Reverse the car loan commission and its receipts before reversing this sale.');
        }
        if ($identity !== systemEntryTypeId('CAR_LOAN_COMMISSION_EARNED')) return;
        $case = $this->db->fetch("SELECT id FROM car_loan_commissions WHERE business_id=? AND accrual_entry_id=? AND status<>'REVERSED'",[$this->businessId,$entry['id']]);
        if (!$case) return;
        $receipts = $this->db->fetch("SELECT COUNT(*) cnt FROM car_loan_commission_receipts WHERE business_id=? AND commission_id=? AND status='POSTED'",[$this->businessId,$case['id']]);
        if (($receipts['cnt'] ?? 0) > 0) throw new Exception('Reverse loan commission receipts before reversing the earned commission.');
    }

    private function applyCarLoanCommissionReversalEffects($entry) {
        $identity = strtoupper(trim((string) ($entry['entry_type_id'] ?? '')));
        if ($identity === systemEntryTypeId('CAR_LOAN_COMMISSION_RECEIPT')) {
            $receipt = $this->db->fetch("SELECT * FROM car_loan_commission_receipts WHERE business_id=? AND journal_entry_id=?",[$this->businessId,$entry['id']]);
            if ($receipt) {
                $this->db->query("UPDATE car_loan_commission_receipts SET status='REVERSED' WHERE id=?",[$receipt['id']]);
                $case = $this->db->fetch("SELECT commission_amount,received_amount FROM car_loan_commissions WHERE id=?",[$receipt['commission_id']]);
                $received = max(0,round(floatval($case['received_amount'] ?? 0)-floatval($receipt['amount']),2));
                $this->db->query("UPDATE car_loan_commissions SET received_amount=?,status=? WHERE id=?",[$received,$received>0.009?'PARTIAL':'PENDING',$receipt['commission_id']]);
            }
        } elseif ($identity === systemEntryTypeId('CAR_LOAN_COMMISSION_EARNED')) {
            $this->db->query("UPDATE car_loan_commissions SET status='REVERSED' WHERE business_id=? AND accrual_entry_id=?",[$this->businessId,$entry['id']]);
        }
    }
}
