<?php
require_once __DIR__ . '/accounting_engine.php';

class RecordDeletionService {
    private $db;
    private $businessId;
    private $userId;
    private $engine;

    public function __construct($businessId, $userId) {
        $this->db = Database::getInstance();
        $this->businessId = $businessId;
        $this->userId = $userId;
        $this->engine = new AccountingEngine($businessId, $userId);
    }

    public function describe($entityType, $entityId) {
        switch ($entityType) {
            case 'car':
                $record = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$entityId, $this->businessId]);
                return $this->description($record, 'Car', $record ? formatRegistrationNo($record['registration_no']) : '',
                    'cars/list.php',
                    'Financial entries will be reversed where safe. The car will remain in History as cancelled.');
            case 'partner':
                $record = $this->db->fetch("SELECT * FROM partners WHERE id = ? AND business_id = ?", [$entityId, $this->businessId]);
                return $this->description($record, 'Partner', $record['name'] ?? '', 'partners/list.php',
                    'The partner will leave active lists and be retained in Deleted Records so old books remain readable.');
            case 'employee':
                $record = $this->db->fetch("SELECT * FROM employees WHERE id = ? AND business_id = ?", [$entityId, $this->businessId]);
                return $this->description($record, 'Employee', $record['name'] ?? '', 'employees/list.php',
                    'The employee will leave active lists and be retained in Deleted Records.');
            case 'party':
                $record = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ?", [$entityId, $this->businessId]);
                return $this->description($record, 'Debtor / Creditor', $record['name'] ?? '', 'parties/list.php',
                    'The party will leave active lists and be retained in Deleted Records so linked history remains readable.');
            case 'rto_record':
                $record = $this->db->fetch(
                    "SELECT r.*, c.registration_no FROM rto_records r JOIN cars c ON c.id = r.car_id WHERE r.id = ? AND r.business_id = ?",
                    [$entityId, $this->businessId]
                );
                $label = $record ? formatRegistrationNo($record['registration_no']) . ' - ' . $record['rto_type'] : '';
                return $this->description($record, 'RTO Record', $label, 'rto/list.php',
                    'Connected RTO receipts or expenses will be reversed before the case is cancelled.');
            case 'second_key_event':
                $record = $this->db->fetch(
                    "SELECT ske.*, c.registration_no FROM car_second_key_events ske JOIN cars c ON c.id = ske.car_id AND c.business_id = ske.business_id WHERE ske.id = ? AND ske.business_id = ?",
                    [$entityId, $this->businessId]
                );
                $label = $record ? formatRegistrationNo($record['registration_no']) . ' - ' . $record['event_type'] . ' on ' . formatDate($record['event_date']) : '';
                return $this->description($record, 'Second Key Event', $label, 'cars/view.php?id=' . urlencode($record['car_id'] ?? ''),
                    'The key movement will be removed and the car current second-key status will be recalculated from the remaining history.');
            case 'user':
                $record = $this->db->fetch("SELECT id, full_name, email, role, is_active, created_at FROM users WHERE id = ? AND business_id = ?", [$entityId, $this->businessId]);
                return $this->description($record, 'User', $record['full_name'] ?? '', 'settings/users.php',
                    'The user will be disabled immediately. Login and audit history will be preserved.');
            case 'account':
                $record = $this->db->fetch(
                    "SELECT * FROM accounts WHERE id = ? AND business_id = ? AND entity_type IN ('CASH','BANK') AND entity_id IS NULL",
                    [$entityId, $this->businessId]
                );
                return $this->description($record, 'Cash / Bank Account', $record ? $record['name'] . ' (' . $record['code'] . ')' : '', 'settings/accounts.php',
                    'An unused account is removed. An account with historical lines is archived after active balances are cleared.');
            case 'opening_balance':
                $record = $this->db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ? AND code <> 'OB-EQUITY'", [$entityId, $this->businessId]);
                return $this->description($record, 'Opening Balance', $record['name'] ?? '', 'settings/opening_balances.php',
                    'The opening entry will be reversed and retained in the ledger and audit history.');
            case 'financial_year':
                $record = $this->db->fetch("SELECT * FROM financial_years WHERE id = ? AND business_id = ?", [$entityId, $this->businessId]);
                return $this->description($record, 'Financial Year', $record['year_label'] ?? '', 'settings/financial_year.php',
                    'Only an inactive financial year with no entries can be deleted.');
            default:
                throw new Exception('This record type does not support deletion.');
        }
    }

    public function delete($entityType, $entityId, $reason) {
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 5) {
            throw new Exception('Enter a clear deletion reason of at least 5 characters.');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            switch ($entityType) {
                case 'car': $result = $this->deleteCar($entityId, $reason); break;
                case 'partner': $result = $this->deletePartner($entityId, $reason); break;
                case 'employee': $result = $this->deleteEmployee($entityId, $reason); break;
                case 'party': $result = $this->deleteParty($entityId, $reason); break;
                case 'rto_record': $result = $this->deleteRtoRecord($entityId, $reason); break;
                case 'second_key_event': $result = $this->deleteSecondKeyEvent($entityId, $reason); break;
                case 'user': $result = $this->deleteUser($entityId, $reason); break;
                case 'account': $result = $this->deleteAccount($entityId, $reason); break;
                case 'opening_balance': $result = $this->deleteOpeningBalance($entityId, $reason); break;
                case 'financial_year': $result = $this->deleteFinancialYear($entityId, $reason); break;
                default: throw new Exception('This record type does not support deletion.');
            }
            if ($ownsTransaction) $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function description($record, $typeLabel, $recordLabel, $returnUrl, $effect) {
        if (!$record) throw new Exception($typeLabel . ' not found.');
        return compact('record', 'typeLabel', 'recordLabel', 'returnUrl', 'effect');
    }

    private function deleteCar($carId, $reason) {
        $car = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ? FOR UPDATE", [$carId, $this->businessId]);
        if (!$car) throw new Exception('Car not found.');
        if ($car['status'] === 'CANCELLED') throw new Exception('This car is already deleted/cancelled.');
        $activeRto = $this->db->fetch("SELECT COUNT(*) AS cnt FROM rto_records WHERE business_id = ? AND car_id = ? AND status <> 'CANCELLED'", [$this->businessId, $carId]);
        if (($activeRto['cnt'] ?? 0) > 0) throw new Exception('Delete or complete the active RTO records for this car first.');

        $activeEntries = $this->db->fetchAll(
            "SELECT * FROM journal_entries WHERE business_id = ? AND car_id = ? AND status = 'POSTED' AND is_reversal = 0 ORDER BY created_at DESC",
            [$this->businessId, $carId]
        );
        $ownership = $car['ownership_type'] ?? 'OWNED';
        if ($ownership === 'OWNED') {
            $purchaseEntry = null;
            foreach ($activeEntries as $entry) {
                if ($entry['transaction_type'] === 'CAR_PURCHASE') $purchaseEntry = $entry;
            }
            if ($purchaseEntry) {
                $this->engine->reverseEntry($purchaseEntry['id'], 'Deleted car: ' . $reason);
            } elseif (!empty($activeEntries)) {
                throw new Exception('Reverse the active car entries first. This car cannot be deleted while money remains posted to it.');
            } else {
                $this->archiveCarWithoutEntry($car, $reason);
            }
        } else {
            if (!empty($activeEntries)) {
                throw new Exception('Reverse the sale, settlements, entity payments, tokens, and other active entries in dependency order first.');
            }
            $this->archiveCarWithoutEntry($car, $reason);
        }

        $updated = $this->db->fetch("SELECT * FROM cars WHERE id = ? AND business_id = ?", [$carId, $this->businessId]);
        Auth::auditLog('DELETE', 'car', $carId, 'Car deleted/cancelled: ' . $reason, $car, $updated, 'cars');
        return 'Car deleted safely. Financial history was preserved.';
    }

    private function archiveCarWithoutEntry($car, $reason) {
        $originalReg = trim((string) $car['registration_no']);
        $archivedReg = substr('VOID-' . preg_replace('/[^A-Z0-9]/', '', strtoupper($originalReg)) . '-' . strtoupper(substr(str_replace('-', '', $car['id']), 0, 4)), 0, 20);
        $notes = trim('Deleted: ' . $reason . '. Original registration ' . $originalReg . '. ' . ($car['notes'] ?? ''));
        $this->db->query(
            "UPDATE cars SET status = 'CANCELLED', registration_no = ?, notes = ?, sold_date = NULL, sale_price = NULL, sale_commission_amount = 0, buyer_name = NULL, buyer_party_id = NULL WHERE id = ? AND business_id = ?",
            [$archivedReg, $notes, $car['id'], $this->businessId]
        );
        if (!empty($car['account_id'])) {
            $this->db->query("UPDATE accounts SET is_active = 0, entity_id = NULL WHERE id = ? AND business_id = ?", [$car['account_id'], $this->businessId]);
        }
    }

    private function deletePartner($partnerId, $reason) {
        $partner = $this->db->fetch("SELECT * FROM partners WHERE id = ? AND business_id = ? FOR UPDATE", [$partnerId, $this->businessId]);
        if (!$partner) throw new Exception('Partner not found.');
        $accountIds = array_values(array_filter([$partner['capital_account_id'], $partner['current_account_id']]));
        $this->assertNoPostedActivity('partner_id', $partnerId, $accountIds, 'Reverse or settle all active partner entries and car funding first.');
        $linked = $this->db->fetch(
            "SELECT (SELECT COUNT(*) FROM car_partnerships WHERE business_id = ? AND partner_id = ? AND status = 'ACTIVE')
                  + (SELECT COUNT(*) FROM cars WHERE business_id = ? AND partner_id = ? AND status <> 'CANCELLED') AS cnt",
            [$this->businessId, $partnerId, $this->businessId, $partnerId]
        );
        if (($linked['cnt'] ?? 0) > 0) throw new Exception('Remove this partner from all active car funding terms first.');
        return $this->removeOrArchiveMaster('partners', 'partner', $partner, $accountIds, $reason, 'partners');
    }

    private function deleteEmployee($employeeId, $reason) {
        $employee = $this->db->fetch("SELECT * FROM employees WHERE id = ? AND business_id = ? FOR UPDATE", [$employeeId, $this->businessId]);
        if (!$employee) throw new Exception('Employee not found.');
        $accountIds = array_values(array_filter([$employee['advance_account_id']]));
        $this->assertNoPostedActivity('employee_id', $employeeId, $accountIds, 'Reverse salary, advance, or write-off entries first.');
        return $this->removeOrArchiveMaster('employees', 'employee', $employee, $accountIds, $reason, 'employees', ['exit_date' => date('Y-m-d')]);
    }

    private function deleteParty($partyId, $reason) {
        $party = $this->db->fetch("SELECT * FROM debtors_creditors WHERE id = ? AND business_id = ? FOR UPDATE", [$partyId, $this->businessId]);
        if (!$party) throw new Exception('Party not found.');
        $accountIds = array_values(array_filter([$party['account_id']]));
        $this->assertNoPostedActivity('party_id', $partyId, $accountIds, 'Reverse or clear all active receipts, payments, loans, and tokens first.');
        $carLinks = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM cars WHERE business_id = ? AND status <> 'CANCELLED' AND (buyer_party_id = ? OR seller_party_id = ? OR commission_owner_party_id = ? OR purchase_dealer_party_id = ?)",
            [$this->businessId, $partyId, $partyId, $partyId, $partyId]
        );
        if (($carLinks['cnt'] ?? 0) > 0) throw new Exception('This party is connected to an active car. Remove or reverse that car relationship first.');
        return $this->removeOrArchiveMaster('debtors_creditors', 'party', $party, $accountIds, $reason, 'parties');
    }

    private function assertNoPostedActivity($entityColumn, $entityId, $accountIds, $message) {
        $params = [$this->businessId, $entityId];
        $accountClause = '';
        if ($accountIds) {
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $accountClause = " OR EXISTS (SELECT 1 FROM journal_lines jl WHERE jl.journal_entry_id = je.id AND jl.account_id IN ($placeholders))";
            $params = array_merge($params, $accountIds);
        }
        $active = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM journal_entries je WHERE je.business_id = ? AND je.status = 'POSTED' AND je.is_reversal = 0 AND (je.$entityColumn = ? $accountClause)",
            $params
        );
        if (($active['cnt'] ?? 0) > 0) throw new Exception($message);
    }

    private function removeOrArchiveMaster($table, $entityType, $record, $accountIds, $reason, $module, $extraArchiveFields = []) {
        if (empty($record['is_active'])) {
            throw new Exception(ucfirst($entityType) . ' is already deleted.');
        }

        $sets = ['is_active = 0'];
        $params = [];
        foreach ($extraArchiveFields as $column => $value) {
            $sets[] = "$column = ?";
            $params[] = $value;
        }
        $params[] = $record['id'];
        $params[] = $this->businessId;
        $this->db->query("UPDATE $table SET " . implode(', ', $sets) . " WHERE id = ? AND business_id = ?", $params);
        foreach ($accountIds as $accountId) {
            $this->db->query("UPDATE accounts SET is_active = 0 WHERE id = ? AND business_id = ?", [$accountId, $this->businessId]);
        }
        $updated = $this->db->fetch("SELECT * FROM $table WHERE id = ? AND business_id = ?", [$record['id'], $this->businessId]);
        Auth::auditLog('DELETE', $entityType, $record['id'], ucfirst($entityType) . ' deleted/archived: ' . $reason, $record, $updated, $module);
        return ucfirst($entityType) . ' deleted from active records. Historical references were preserved.';
    }

    private function deleteRtoRecord($recordId, $reason) {
        $record = $this->db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ? FOR UPDATE", [$recordId, $this->businessId]);
        if (!$record) throw new Exception('RTO record not found.');
        if ($record['status'] === 'CANCELLED') throw new Exception('This RTO record is already deleted/cancelled.');

        $recoveries = $this->db->fetchAll(
            "SELECT rr.*, je.status FROM rto_recoveries rr JOIN journal_entries je ON je.id = rr.journal_entry_id WHERE rr.business_id = ? AND rr.rto_record_id = ? AND rr.amount > 0 ORDER BY rr.created_at DESC",
            [$this->businessId, $recordId]
        );
        foreach ($recoveries as $recovery) {
            if ($recovery['status'] === 'POSTED') $this->engine->reverseEntry($recovery['journal_entry_id'], 'Deleted RTO record: ' . $reason);
        }
        if (!empty($record['expense_entry_id'])) {
            $expense = $this->db->fetch("SELECT status FROM journal_entries WHERE id = ? AND business_id = ?", [$record['expense_entry_id'], $this->businessId]);
            if (($expense['status'] ?? '') === 'POSTED') $this->engine->reverseEntry($record['expense_entry_id'], 'Deleted RTO record: ' . $reason);
        }
        $this->db->query(
            "UPDATE rto_records SET status = 'CANCELLED', expense_amount = 0, recovered_amount = 0, expense_entry_id = NULL, last_recovery_entry_id = NULL, narration = CONCAT(COALESCE(narration,''), ?) WHERE id = ? AND business_id = ?",
            [' | Deleted: ' . $reason, $recordId, $this->businessId]
        );
        $updated = $this->db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$recordId, $this->businessId]);
        Auth::auditLog('DELETE', 'rto_record', $recordId, 'RTO record deleted/cancelled: ' . $reason, $record, $updated, 'rto');
        return 'RTO record deleted. Connected money entries were reversed and retained in history.';
    }

    private function deleteSecondKeyEvent($eventId, $reason) {
        $event = $this->db->fetch(
            "SELECT * FROM car_second_key_events WHERE id = ? AND business_id = ? FOR UPDATE",
            [$eventId, $this->businessId]
        );
        if (!$event) throw new Exception('Second key event not found.');
        $car = $this->db->fetch("SELECT id, registration_no, has_second_key FROM cars WHERE id = ? AND business_id = ? FOR UPDATE", [$event['car_id'], $this->businessId]);
        if (!$car) throw new Exception('Connected car not found.');

        $this->db->query("DELETE FROM car_second_key_events WHERE id = ? AND business_id = ?", [$eventId, $this->businessId]);
        $latest = $this->db->fetch(
            "SELECT event_type FROM car_second_key_events WHERE business_id = ? AND car_id = ? ORDER BY event_date DESC, created_at DESC LIMIT 1",
            [$this->businessId, $event['car_id']]
        );
        $hasSecondKey = ($latest['event_type'] ?? '') === 'RECEIVED' ? 1 : 0;
        $this->db->query("UPDATE cars SET has_second_key = ? WHERE id = ? AND business_id = ?", [$hasSecondKey, $event['car_id'], $this->businessId]);
        Auth::auditLog(
            'DELETE',
            'car',
            $event['car_id'],
            'Second key event deleted: ' . $reason,
            ['second_key_event' => $event, 'has_second_key' => $car['has_second_key']],
            ['second_key_event' => null, 'has_second_key' => $hasSecondKey],
            'cars'
        );
        return 'Second key event deleted. The car key status was recalculated.';
    }

    private function deleteUser($userId, $reason) {
        if ($userId === $this->userId) throw new Exception('You cannot delete your own user account.');
        $user = $this->db->fetch("SELECT id, full_name, email, role, is_active FROM users WHERE id = ? AND business_id = ? FOR UPDATE", [$userId, $this->businessId]);
        if (!$user) throw new Exception('User not found.');
        if (empty($user['is_active'])) throw new Exception('This user is already deleted/disabled.');
        if ($user['role'] === ROLE_ADMIN) {
            $admins = $this->db->fetch("SELECT COUNT(*) AS cnt FROM users WHERE business_id = ? AND role = ? AND is_active = 1 AND id <> ?", [$this->businessId, ROLE_ADMIN, $userId]);
            if (($admins['cnt'] ?? 0) < 1) throw new Exception('Keep at least one active administrator.');
        }
        $this->db->query("UPDATE users SET is_active = 0 WHERE id = ? AND business_id = ?", [$userId, $this->businessId]);
        $updated = array_merge($user, ['is_active' => 0]);
        Auth::auditLog('DELETE', 'user', $userId, 'User disabled/deleted: ' . $reason, $user, $updated, 'users');
        return 'User deleted from active access. Login and audit history were preserved.';
    }

    private function deleteAccount($accountId, $reason) {
        $account = $this->db->fetch(
            "SELECT * FROM accounts WHERE id = ? AND business_id = ? AND entity_type IN ('CASH','BANK') AND entity_id IS NULL FOR UPDATE",
            [$accountId, $this->businessId]
        );
        if (!$account) throw new Exception('Cash / bank account not found.');
        if (empty($account['is_active'])) throw new Exception('This account is already deleted/archived.');
        $other = $this->db->fetch("SELECT COUNT(*) AS cnt FROM accounts WHERE business_id = ? AND entity_type = ? AND entity_id IS NULL AND is_active = 1 AND id <> ?", [$this->businessId, $account['entity_type'], $accountId]);
        if (($other['cnt'] ?? 0) < 1) throw new Exception('Keep at least one active ' . strtolower($account['entity_type']) . ' account.');

        $activeLines = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id WHERE jl.account_id = ? AND je.status = 'POSTED' AND je.is_reversal = 0 AND je.transaction_type <> 'OPENING_BALANCE'",
            [$accountId]
        );
        if (($activeLines['cnt'] ?? 0) > 0) throw new Exception('Move or reverse all active entries from this account first.');
        if (!empty($account['opening_entry_id']) || floatval($account['opening_balance']) > 0.009) {
            $this->engine->setOpeningBalance($accountId, 0, 'DR', $account['opening_balance_date'] ?: date('Y-m-d'), 'Account deleted: ' . $reason);
        }
        $history = $this->db->fetch("SELECT COUNT(*) AS cnt FROM journal_lines WHERE account_id = ?", [$accountId]);
        if (($history['cnt'] ?? 0) > 0) {
            $this->db->query("UPDATE accounts SET is_active = 0 WHERE id = ? AND business_id = ?", [$accountId, $this->businessId]);
            $updated = $this->db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $this->businessId]);
            Auth::auditLog('DELETE', 'account', $accountId, 'Account archived: ' . $reason, $account, $updated, 'accounts');
            return 'Account archived. Historical ledger lines remain available.';
        }
        $this->db->query("DELETE FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $this->businessId]);
        Auth::auditLog('DELETE', 'account', $accountId, 'Account deleted: ' . $reason, $account, null, 'accounts');
        return 'Account deleted.';
    }

    private function deleteOpeningBalance($accountId, $reason) {
        $account = $this->db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ? AND code <> 'OB-EQUITY' FOR UPDATE", [$accountId, $this->businessId]);
        if (!$account) throw new Exception('Account not found.');
        if (empty($account['opening_entry_id']) && floatval($account['opening_balance']) <= 0.009) throw new Exception('This account has no opening balance to delete.');
        $this->engine->setOpeningBalance($accountId, 0, 'DR', $account['opening_balance_date'] ?: date('Y-m-d'), 'Deleted opening balance: ' . $reason);
        $updated = $this->db->fetch("SELECT * FROM accounts WHERE id = ? AND business_id = ?", [$accountId, $this->businessId]);
        Auth::auditLog('DELETE', 'opening_balance', $accountId, 'Opening balance deleted: ' . $reason, $account, $updated, 'opening_balances');
        return 'Opening balance deleted through reversal.';
    }

    private function deleteFinancialYear($financialYearId, $reason) {
        $year = $this->db->fetch("SELECT * FROM financial_years WHERE id = ? AND business_id = ? FOR UPDATE", [$financialYearId, $this->businessId]);
        if (!$year) throw new Exception('Financial year not found.');
        if (!empty($year['is_active'])) throw new Exception('Activate another financial year before deleting this one.');
        $entries = $this->db->fetch("SELECT COUNT(*) AS cnt FROM journal_entries WHERE business_id = ? AND entry_date BETWEEN ? AND ?", [$this->businessId, $year['start_date'], $year['end_date']]);
        if (($entries['cnt'] ?? 0) > 0) throw new Exception('This financial year contains transaction history and cannot be deleted.');
        $this->db->query("DELETE FROM financial_years WHERE id = ? AND business_id = ?", [$financialYearId, $this->businessId]);
        Auth::auditLog('DELETE', 'financial_year', $financialYearId, 'Financial year deleted: ' . $reason, $year, null, 'financial_year');
        return 'Financial year deleted.';
    }
}
