<?php

/**
 * Purchase Dealer / Broker accounting.
 *
 * A car is always bought FROM a legal owner (`cars.seller_party_id`). Sometimes
 * the deal is found THROUGH a dealer or broker who charges a commission. That
 * dealer is a completely separate party with its own payable ledger, linked to
 * the car through `cars.purchase_dealer_party_id`.
 *
 * Money paid to the owner is never an expense: it settles the purchase payable.
 * Dealer commission IS a cost of the car, so it is posted to a car-direct
 * expense account and then allocated into the car inventory account exactly
 * like a repair bill, which keeps car cost and profitability correct.
 */
trait CarPurchaseDealerAccounting {
    private const DEALER_PARTY_TYPES = ['DEALER', 'CREDITOR', 'SELLER'];

    private function dealerCommissionAccount() {
        return $this->getOrCreateSystemAccount(
            'DEALER-COMM',
            'Dealer / Broker Commission (Car)',
            'EXPENSE',
            'Direct Expenses (Car)'
        );
    }

    /**
     * Validate the dealer block of a purchase form without writing anything.
     * Safe to call repeatedly (the purchase flow validates twice).
     *
     * @return array|null null when no dealer is involved.
     */
    public function normalizeDealerCommissionInput(array $dealer, $defaultPaymentAccount = null) {
        $partyId = trim((string) ($dealer['party_id'] ?? ''));
        $name = trim((string) ($dealer['name'] ?? ''));
        $commission = round(parseDecimalInput($dealer['commission'] ?? 0), 2);
        $paidNowInput = trim((string) ($dealer['paid_now'] ?? ''));
        $paidNow = $paidNowInput === '' ? 0.0 : round(parseDecimalInput($paidNowInput), 2);
        $paymentAccount = trim((string) ($dealer['payment_account'] ?? '')) ?: $defaultPaymentAccount;

        if ($partyId === '' && $name === '') {
            if ($commission > 0.009) {
                throw new Exception('Select the purchase dealer / broker who earned this commission, or clear the commission amount.');
            }
            return null;
        }

        if ($partyId !== '' && $name !== '') {
            throw new Exception('Choose an existing dealer / broker or add a new one, not both.');
        }
        if ($commission < 0) {
            throw new Exception('Dealer commission cannot be negative.');
        }
        if ($paidNow < 0) {
            throw new Exception('Dealer commission paid now cannot be negative.');
        }
        if ($paidNow - $commission > 0.01) {
            throw new Exception('Dealer commission paid now cannot be more than the dealer commission.');
        }
        if ($paidNow > 0 && !$paymentAccount) {
            throw new Exception('Select the cash or bank account used to pay the dealer commission.');
        }
        if ($paidNow > 0) {
            $this->validateCashAvailable($paymentAccount, $paidNow);
        }

        return [
            'party_id' => $partyId,
            'name' => $name,
            'phone' => trim((string) ($dealer['phone'] ?? '')),
            'commission' => $commission,
            'paid_now' => $paidNow,
            'payment_account' => $paymentAccount,
        ];
    }

    /**
     * Link a dealer to a car and post its commission.
     * Returns the accrual entry id, or null when nothing was posted.
     */
    public function recordPurchaseDealerCommission($carId, array $dealer, $date, $defaultPaymentAccount = null, $narration = '') {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception('Car not found for dealer commission.');

        $input = $this->normalizeDealerCommissionInput($dealer, $defaultPaymentAccount);
        if ($input === null) return null;

        $this->validateDateNotLocked($date);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $dealerParty = $this->resolveParty(
                $input['party_id'],
                $input['name'],
                $input['phone'],
                'DEALER',
                self::DEALER_PARTY_TYPES
            );

            // The dealer link is kept even when the commission is zero or fully
            // paid, so the car always shows how it was sourced.
            $previousDealerId = $car['purchase_dealer_party_id'] ?? null;
            if ($previousDealerId && $previousDealerId !== $dealerParty['id']) {
                throw new Exception('This car already has a different purchase dealer / broker. Reverse the existing dealer commission before linking another dealer.');
            }
            $this->db->query(
                "UPDATE cars SET purchase_dealer_party_id = ? WHERE id = ? AND business_id = ?",
                [$dealerParty['id'], $carId, $this->businessId]
            );
            $updatedCar = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
            Auth::auditUpdate('car', $carId, $car, $updatedCar ?: [], "Purchase dealer / broker linked: {$dealerParty['name']}", 'cars');

            $entryId = null;
            if ($input['commission'] > 0.009) {
                $expenseAccount = $this->dealerCommissionAccount();
                $registration = $car['registration_no'] ?? '';
                $accrualNarration = $narration !== ''
                    ? $narration
                    : "Dealer commission payable to {$dealerParty['name']} for $registration";

                $entryId = $this->postJournalEntry('DEALER_COMMISSION', $date, $accrualNarration, [
                    ['account_id' => $expenseAccount['id'], 'amount' => $input['commission'], 'type' => 'DR', 'narration' => "Dealer commission for $registration"],
                    ['account_id' => $dealerParty['account_id'], 'amount' => $input['commission'], 'type' => 'CR', 'narration' => "Commission payable to {$dealerParty['name']}"],
                ], [
                    'car_id' => $carId,
                    'party_id' => $dealerParty['id'],
                    'entry_type_id' => systemEntryTypeId('DEALER_COMMISSION'),
                    'entry_amount' => $input['commission'],
                ]);

                // Owned stock carries its buying costs inside the car account so
                // total cost and profit include the dealer commission.
                if (($car['ownership_type'] ?? 'OWNED') === 'OWNED' && !empty($car['account_id'])) {
                    $this->postJournalEntry('DEALER_COMMISSION', $date, "Allocate dealer commission to $registration", [
                        ['account_id' => $car['account_id'], 'amount' => $input['commission'], 'type' => 'DR', 'narration' => "Dealer commission added to car cost"],
                        ['account_id' => $expenseAccount['id'], 'amount' => $input['commission'], 'type' => 'CR', 'narration' => 'Dealer commission allocated to car'],
                    ], [
                        'car_id' => $carId,
                        'party_id' => $dealerParty['id'],
                        'entry_type_id' => systemEntryTypeId('INTERNAL_ALLOCATION'),
                        'entry_amount' => 0,
                    ]);
                }

                if ($input['paid_now'] > 0.009) {
                    $this->payPurchaseDealerCommission(
                        $carId,
                        $input['paid_now'],
                        $date,
                        $input['payment_account'],
                        "Dealer commission paid to {$dealerParty['name']} for $registration"
                    );
                }
            }

            if ($ownsTransaction) $this->db->commit();
            return $entryId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Pay a pending purchase dealer / broker commission for one car.
     * Overpayment is blocked against both the car balance and the dealer ledger.
     */
    public function payPurchaseDealerCommission($carId, $amount, $date, $paymentAccount, $narration = '') {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        if (!$car) throw new Exception('Car not found.');
        if (empty($car['purchase_dealer_party_id'])) {
            throw new Exception('This car has no purchase dealer / broker linked.');
        }
        $dealerParty = $this->db->fetch(
            "SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?",
            [$car['purchase_dealer_party_id'], $this->businessId]
        );
        if (!$dealerParty || empty($dealerParty['account_id'])) {
            throw new Exception('The purchase dealer / broker ledger is not available.');
        }

        $amount = round(parseDecimalInput($amount), 2);
        if ($amount <= 0) throw new Exception('Dealer commission payment must be greater than zero.');
        $this->validateDateNotLocked($date);

        $pending = $this->getCarDealerPendingAmount($carId);
        if ($pending <= 0.009) {
            throw new Exception('This car has no dealer commission pending for payment.');
        }
        if ($amount - $pending > 0.01) {
            throw new Exception('Payment cannot exceed the pending dealer commission of ' . formatAmount($pending) . '.');
        }

        $ledgerOutstanding = round(array_sum(array_column(
            $this->buildOutstandingItemsFromLedger($dealerParty['account_id'], 'CR'),
            'outstanding_amount'
        )), 2);
        if ($amount - $ledgerOutstanding > 0.01) {
            throw new Exception('Payment cannot exceed the dealer ledger outstanding of ' . formatAmount($ledgerOutstanding) . '.');
        }

        $this->validateCashAvailable($paymentAccount, $amount);
        $registration = $car['registration_no'] ?? '';

        return $this->postJournalEntry(
            'DEALER_COMMISSION_PAYMENT',
            $date,
            $narration !== '' ? $narration : "Dealer commission paid to {$dealerParty['name']} for $registration",
            [
                ['account_id' => $dealerParty['account_id'], 'amount' => $amount, 'type' => 'DR', 'narration' => "Dealer commission cleared for $registration"],
                ['account_id' => $paymentAccount, 'amount' => $amount, 'type' => 'CR', 'narration' => "Dealer commission paid to {$dealerParty['name']}"],
            ],
            [
                'car_id' => $carId,
                'party_id' => $dealerParty['id'],
                'entry_type_id' => systemEntryTypeId('DEALER_COMMISSION_PAYMENT'),
                'entry_amount' => $amount,
            ]
        );
    }

    public function getCarDealerPendingAmount($carId) {
        $car = $this->db->fetch(
            "SELECT purchase_dealer_party_id FROM cars WHERE id = ? AND business_id = ?",
            [$carId, $this->businessId]
        );
        if (!$car || empty($car['purchase_dealer_party_id'])) return 0.0;
        $dealer = $this->db->fetch(
            "SELECT account_id FROM debtors_creditors WHERE id = ? AND business_id = ?",
            [$car['purchase_dealer_party_id'], $this->businessId]
        );
        if (empty($dealer['account_id'])) return 0.0;

        return $this->getCarLinkedOutstandingAmount(
            $carId,
            $dealer['account_id'],
            'CR',
            ['DEALER_COMMISSION', 'DEALER_COMMISSION_PAYMENT']
        );
    }

    /**
     * Dealer commission booked against one car. Light enough for list reports.
     */
    public function getCarDealerCommissionTotal($carId) {
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(entry_amount), 0) AS total
             FROM journal_entries
             WHERE business_id = ?
               AND car_id = ?
               AND transaction_type = 'DEALER_COMMISSION'
               AND entry_type_id = ?
               AND status = 'POSTED'
               AND is_reversal = 0",
            [$this->businessId, $carId, systemEntryTypeId('DEALER_COMMISSION')]
        );
        return round(floatval($row['total'] ?? 0), 2);
    }

    /**
     * Everything the car screens need about the purchase dealer in one call.
     */
    public function getCarDealerSettlement($carId) {
        $empty = [
            'dealer' => null,
            'commission_total' => 0.0,
            'paid_total' => 0.0,
            'pending' => 0.0,
            'history' => [],
        ];

        $car = $this->db->fetch(
            "SELECT id, registration_no, purchase_dealer_party_id FROM cars WHERE id = ? AND business_id = ?",
            [$carId, $this->businessId]
        );
        if (!$car || empty($car['purchase_dealer_party_id'])) return $empty;

        $dealer = $this->db->fetch(
            "SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?",
            [$car['purchase_dealer_party_id'], $this->businessId]
        );
        if (!$dealer || empty($dealer['account_id'])) return $empty;

        $totals = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN je.transaction_type = 'DEALER_COMMISSION' AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS commission_total,
                COALESCE(SUM(CASE WHEN je.transaction_type = 'DEALER_COMMISSION_PAYMENT' AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS paid_total
             FROM journal_entries je
             JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.account_id = ?
             WHERE je.business_id = ? AND je.car_id = ?
               AND je.status = 'POSTED' AND je.is_reversal = 0",
            [$dealer['account_id'], $this->businessId, $carId]
        );

        $history = $this->db->fetchAll(
            "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.transaction_type, je.narration,
                    jl.amount, jl.entry_type,
                    payment_account.name AS payment_account_name, payment_account.code AS payment_account_code
             FROM journal_entries je
             JOIN journal_lines jl ON jl.journal_entry_id = je.id AND jl.account_id = ?
             LEFT JOIN journal_lines payment_line
               ON payment_line.journal_entry_id = je.id
              AND payment_line.entry_type = 'CR'
              AND payment_line.account_id IN (
                  SELECT id FROM accounts WHERE business_id = ? AND entity_type IN ('CASH','BANK')
              )
             LEFT JOIN accounts payment_account ON payment_account.id = payment_line.account_id
             WHERE je.business_id = ? AND je.car_id = ?
               AND je.status IN ('POSTED','REVERSED')
               AND je.transaction_type IN ('DEALER_COMMISSION','DEALER_COMMISSION_PAYMENT')
             ORDER BY je.entry_date ASC, je.created_at ASC",
            [$dealer['account_id'], $this->businessId, $this->businessId, $carId]
        );

        return [
            'dealer' => $dealer,
            'commission_total' => round(floatval($totals['commission_total'] ?? 0), 2),
            'paid_total' => round(floatval($totals['paid_total'] ?? 0), 2),
            'pending' => $this->getCarDealerPendingAmount($carId),
            'history' => $history,
        ];
    }

    /**
     * Car-wise commission history for one dealer / broker ledger page.
     */
    public function getDealerCarSettlements($partyId) {
        $dealer = $this->db->fetch(
            "SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?",
            [$partyId, $this->businessId]
        );
        if (!$dealer || empty($dealer['account_id'])) return [];

        $cars = $this->db->fetchAll(
            "SELECT DISTINCT c.id, c.registration_no, c.make, c.model, c.purchase_date, c.status
             FROM cars c
             WHERE c.business_id = ?
               AND (
                   c.purchase_dealer_party_id = ?
                   OR EXISTS (
                       SELECT 1 FROM journal_entries je
                       WHERE je.business_id = c.business_id
                         AND je.car_id = c.id
                         AND je.party_id = ?
                         AND je.transaction_type IN ('DEALER_COMMISSION','DEALER_COMMISSION_PAYMENT')
                         AND je.status = 'POSTED' AND je.is_reversal = 0
                   )
               )
             ORDER BY c.purchase_date DESC, c.created_at DESC",
            [$this->businessId, $partyId, $partyId]
        );

        $rows = [];
        foreach ($cars as $car) {
            $settlement = $this->getCarDealerSettlement($car['id']);
            $rows[] = [
                'car' => $car,
                'commission_total' => $settlement['commission_total'],
                'paid_total' => $settlement['paid_total'],
                'pending' => $settlement['pending'],
                'history' => $settlement['history'],
            ];
        }

        return $rows;
    }

    private function assertPurchaseDealerEntryCanBeReversed($entry) {
        if (($entry['transaction_type'] ?? '') !== 'DEALER_COMMISSION') return;
        if (strtoupper(trim((string) ($entry['entry_type_id'] ?? ''))) === systemEntryTypeId('INTERNAL_ALLOCATION')) return;
        if (empty($entry['car_id'])) return;

        $payments = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM journal_entries
             WHERE business_id = ? AND car_id = ?
               AND transaction_type = 'DEALER_COMMISSION_PAYMENT'
               AND status = 'POSTED' AND is_reversal = 0",
            [$this->businessId, $entry['car_id']]
        );
        if (($payments['cnt'] ?? 0) > 0) {
            throw new Exception('Reverse the dealer commission payments for this car before reversing the commission itself.');
        }
    }

    /**
     * Dealer entries reversed as part of a purchase reversal must also be
     * reversed as a set, so the car account, expense account and dealer ledger
     * all return to zero together.
     */
    private function getPurchaseDealerDependentEntries($entry) {
        if (($entry['transaction_type'] ?? '') !== 'CAR_PURCHASE' || empty($entry['car_id'])) return [];

        return $this->db->fetchAll(
            "SELECT *
             FROM journal_entries
             WHERE business_id = ?
               AND car_id = ?
               AND status = 'POSTED'
               AND is_reversal = 0
               AND transaction_type IN ('DEALER_COMMISSION','DEALER_COMMISSION_PAYMENT')
             ORDER BY FIELD(transaction_type, 'DEALER_COMMISSION_PAYMENT', 'DEALER_COMMISSION'), created_at DESC, id DESC",
            [$this->businessId, $entry['car_id']]
        );
    }

    private function applyPurchaseDealerReversalEffects($entry) {
        if (($entry['transaction_type'] ?? '') !== 'DEALER_COMMISSION' || empty($entry['car_id'])) return;
        if (strtoupper(trim((string) ($entry['entry_type_id'] ?? ''))) === systemEntryTypeId('INTERNAL_ALLOCATION')) return;

        $remaining = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM journal_entries
             WHERE business_id = ? AND car_id = ?
               AND transaction_type IN ('DEALER_COMMISSION','DEALER_COMMISSION_PAYMENT')
               AND status = 'POSTED' AND is_reversal = 0
               AND id <> ?",
            [$this->businessId, $entry['car_id'], $entry['id']]
        );
        if (($remaining['cnt'] ?? 0) > 0) return;

        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$entry['car_id'], $this->businessId]);
        if (!$car || empty($car['purchase_dealer_party_id'])) return;

        $this->db->query(
            "UPDATE cars SET purchase_dealer_party_id = NULL WHERE id = ? AND business_id = ?",
            [$car['id'], $this->businessId]
        );
        $updatedCar = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$car['id'], $this->businessId]);
        Auth::auditUpdate('car', $car['id'], $car, $updatedCar ?: [], 'Purchase dealer commission reversed; dealer link cleared', 'cars');
    }
}
