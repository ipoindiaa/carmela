<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/accounting_engine.php';

/**
 * Testing-data cleanup with accounting-safe scopes.
 *
 * This service is intentionally unavailable in production. Live records must
 * use the normal reversal/individual-delete flow so the audit trail stays
 * intact. The testing environment can be reset without throwing away every
 * master option an operator has already configured.
 */
class BusinessDataResetService {
    public const SCOPE_DAILY_ENTRIES = 'DAILY_ENTRIES';
    public const SCOPE_CARS_AND_LINKED_ENTRIES = 'CARS_AND_LINKED_ENTRIES';
    public const SCOPE_ALL_BUSINESS_DATA = 'ALL_BUSINESS_DATA';

    private $db;
    private $businessId;
    private $userId;

    public function __construct($businessId, $userId) {
        $this->db = Database::getInstance();
        $this->businessId = (string) $businessId;
        $this->userId = (string) $userId;
    }

    /**
     * The label/copy is shared by the settings page and server validation.
     * The phrase is not the authorization boundary; active-admin password
     * verification and the testing-environment guard are both required too.
     */
    public static function cleanupScopes(): array {
        return [
            self::SCOPE_DAILY_ENTRIES => [
                'label' => 'Clear Daily Entries',
                'confirmation_phrase' => 'CLEAR ENTRIES',
                'icon' => 'ri-file-list-3-line',
                'severity' => 'warning',
                'description' => 'Remove posted daily activity while keeping your vehicle list and saved setup.',
                'removes' => 'Entries, payments, tokens, RTO activity, split bills, car funding, sale history, and transaction attachments.',
                'keeps' => 'Cars as clean vehicle records, owner/dealer links, reference selling prices, car files, accounts, categories, parties, partners, employees, users, permissions, financial years, and audit history.',
            ],
            self::SCOPE_CARS_AND_LINKED_ENTRIES => [
                'label' => 'Clear Cars & Car Entries',
                'confirmation_phrase' => 'CLEAR CARS',
                'icon' => 'ri-car-line',
                'severity' => 'danger',
                'description' => 'Remove every car and its linked purchase, sale, payment, RTO, token, and car-document history.',
                'removes' => 'Cars, car accounts, car-linked journal entries and split bills, car files, RTO records, tokens, commissions, and car partner funding.',
                'keeps' => 'Cash/bank and custom accounts, categories, parties, partners, employees, users, permissions, financial years, unrelated entries, and audit history.',
            ],
            self::SCOPE_ALL_BUSINESS_DATA => [
                'label' => 'Erase Everything',
                'confirmation_phrase' => 'CLEAR EVERYTHING',
                'icon' => 'ri-delete-bin-6-line',
                'severity' => 'danger',
                'description' => 'Start the testing business from a blank accounting setup.',
                'removes' => 'All transactions, cars, accounts, categories, parties, partners, employees, RTO records, alerts, audit history, and uploaded files.',
                'keeps' => 'Only the business profile, user logins, and their permissions. Clean default accounts and the current financial year are recreated.',
            ],
        ];
    }

    /**
     * Backwards-compatible entry point for existing callers. The old CLEAR
     * phrase still maps to the explicit full-reset scope, but that scope is
     * now testing-only like every other bulk cleanup.
     */
    public function reset($password, $confirmationPhrase) {
        $phrase = trim((string) $confirmationPhrase);
        if (hash_equals('CLEAR', $phrase)) {
            $phrase = 'CLEAR EVERYTHING';
        }
        return $this->clear(self::SCOPE_ALL_BUSINESS_DATA, $password, $phrase);
    }

    public function clear($scope, $password, $confirmationPhrase): array {
        $scopes = self::cleanupScopes();
        $scope = strtoupper(trim((string) $scope));
        if (!isset($scopes[$scope])) {
            throw new Exception('Choose a valid testing cleanup option.');
        }

        $this->assertTestingEnvironment();
        $meta = $scopes[$scope];
        if (!hash_equals($meta['confirmation_phrase'], trim((string) $confirmationPhrase))) {
            throw new Exception('Type ' . $meta['confirmation_phrase'] . ' exactly to confirm this cleanup.');
        }

        $this->verifyActiveAdministrator($password);
        $business = $this->db->fetch("SELECT id FROM businesses WHERE id = ? LIMIT 1", [$this->businessId]);
        if (!$business) {
            throw new Exception('Business not found.');
        }

        if ($scope === self::SCOPE_ALL_BUSINESS_DATA) {
            $result = $this->clearEverything();
        } else {
            // Constructing the engine first ensures optional operational tables
            // are available before the scoped cleanup identifies them.
            new AccountingEngine($this->businessId, $this->userId);
            $availableTables = $this->availableTables();
            $this->db->beginTransaction();
            try {
                $result = $scope === self::SCOPE_DAILY_ENTRIES
                    ? $this->clearDailyEntries($availableTables)
                    : $this->clearCarsAndLinkedEntries($availableTables);

                $this->rebuildAccountBalances($availableTables);
                $this->clearStaleAlerts(
                    $availableTables,
                    $scope,
                    $result['deleted_rows'],
                    $result['updated_rows']
                );
                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }

            $fileCleanup = $this->removeAttachmentFiles($result['attachment_paths']);
            $result['deleted_files'] = $fileCleanup['deleted_files'];
            $result['file_cleanup_failed'] = $fileCleanup['failed'];
            unset($result['attachment_paths']);
        }

        $result['scope'] = $scope;
        $result['scope_label'] = $meta['label'];
        return $result;
    }

    private function assertTestingEnvironment(): void {
        if (!defined('APP_IS_TESTING') || !APP_IS_TESTING) {
            throw new Exception('Bulk cleanup is disabled in live accounts. Use the normal reversal or individual delete action instead.');
        }
    }

    private function verifyActiveAdministrator($password): void {
        $user = $this->db->fetch(
            "SELECT id, business_id, password_hash, role, is_active
             FROM users
             WHERE id = ? AND business_id = ?
             LIMIT 1",
            [$this->userId, $this->businessId]
        );
        if (!$user || empty($user['is_active']) || $user['role'] !== ROLE_ADMIN) {
            throw new Exception('Only an active administrator can clear testing data.');
        }
        if (!password_verify((string) $password, $user['password_hash'])) {
            usleep(300000);
            throw new Exception('The password is incorrect. No data was cleared.');
        }
    }

    /** Keep cars and all master setup, but remove every daily money movement. */
    private function clearDailyEntries(array $availableTables): array {
        $deletedRows = 0;
        $updatedRows = 0;
        $entryIds = $this->findDailyJournalEntryIds($availableTables);
        $voucherIds = $this->businessIds($availableTables, 'journal_vouchers');
        $rtoIds = $this->businessIds($availableTables, 'rto_records');

        $attachmentPaths = $this->deleteAttachmentsForEntities($availableTables, [
            'JOURNAL_ENTRY' => $entryIds,
            'JOURNAL_VOUCHER' => $voucherIds,
            'RTO_RECORD' => $rtoIds,
        ], $deletedRows);

        // Delete child/ledger rows before their journal or car parent. These
        // rows are all daily activity, not saved master options.
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_token_refunds');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_tokens');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'commission_owner_payments');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'commission_car_settlements');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_loan_commission_receipts');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_loan_commissions');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'partner_settlement_applications');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'partner_profit_settlements');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'salary_records');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'cash_reconciliations');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'rto_recoveries');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'rto_records');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_second_key_events');
        $deletedRows += $this->deleteCarChildRows($availableTables, 'car_partner_contributions');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_partnerships');
        $deletedRows += $this->deleteVoucherRows($availableTables, $voucherIds);
        $deletedRows += $this->deleteJournalEntries($availableTables, $entryIds);

        // A retained car remains easy to find, but no longer carries a stale
        // purchase/sale amount after the underlying transactions are removed.
        if (isset($availableTables['cars'])) {
            $statement = $this->db->query(
                "UPDATE cars
                 SET purchase_price = 0,
                     purchase_paid_amount = 0,
                     purchase_amount_mode = 'PAYMENTS',
                     status = CASE WHEN status = 'CANCELLED' THEN status ELSE 'IN_STOCK' END,
                     sold_date = NULL,
                     sale_price = NULL,
                     sale_commission_amount = 0,
                     buyer_name = NULL,
                     buyer_contact = NULL,
                     buyer_party_id = NULL,
                     has_second_key = 0
                 WHERE business_id = ?",
                [$this->businessId]
            );
            $updatedRows += $statement->rowCount();
        }

        return [
            'deleted_rows' => $deletedRows,
            'updated_rows' => $updatedRows,
            'attachment_paths' => $attachmentPaths,
        ];
    }

    /** Remove the entire vehicle inventory and every accounting row tied to it. */
    private function clearCarsAndLinkedEntries(array $availableTables): array {
        $deletedRows = 0;
        $updatedRows = 0;
        $carIds = $this->businessIds($availableTables, 'cars');
        $carAccountIds = $this->carAccountIds($availableTables);
        $voucherIds = $this->findCarVoucherIds($availableTables, $carIds, $carAccountIds);
        $entryIds = $this->findCarJournalEntryIds($availableTables, $carAccountIds, $voucherIds);

        // A voucher is one balanced record. If one allocation is car-linked,
        // keep the accounting correct by removing that complete voucher—not
        // only one line of it.
        // Follow both directions until the entry/voucher set is closed. This
        // matters when an old correction or split-bill posting points back to
        // the car allocation indirectly.
        for ($pass = 0; $pass < 4; $pass++) {
            $beforeEntries = count($entryIds);
            $beforeVouchers = count($voucherIds);
            $voucherIds = $this->expandVoucherIdsForJournalEntries($availableTables, $voucherIds, $entryIds);
            $entryIds = $this->mergeIds($entryIds, $this->journalEntryIdsForVouchers($availableTables, $voucherIds));
            $voucherLineIds = $this->voucherLineIds($availableTables, $voucherIds);
            $entryIds = $this->addJournalEntriesUsingVoucherLines($availableTables, $entryIds, $voucherLineIds);
            $entryIds = $this->expandJournalEntryClosure($availableTables, $entryIds);
            if (count($entryIds) === $beforeEntries && count($voucherIds) === $beforeVouchers) {
                break;
            }
        }

        $rtoIds = $this->businessIds($availableTables, 'rto_records');
        $attachmentPaths = $this->deleteAttachmentsForEntities($availableTables, [
            'CAR' => $carIds,
            'JOURNAL_ENTRY' => $entryIds,
            'JOURNAL_VOUCHER' => $voucherIds,
            'RTO_RECORD' => $rtoIds,
        ], $deletedRows);

        // Car-bound operational tables first.
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_token_refunds');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_tokens');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'commission_owner_payments');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'commission_car_settlements');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_loan_commission_receipts');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_loan_commissions');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'partner_settlement_applications');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'partner_profit_settlements');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'rto_recoveries');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'rto_records');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_second_key_events');
        $deletedRows += $this->deleteCarChildRows($availableTables, 'car_partner_contributions');
        $deletedRows += $this->deleteBusinessRows($availableTables, 'car_partnerships');

        // These tables are normally not car-specific. Remove only rows whose
        // journal is being removed, so unrelated payroll/cash work survives.
        $deletedRows += $this->deleteRowsByJournalEntryIds($availableTables, 'salary_records', $entryIds);
        $deletedRows += $this->deleteRowsByJournalEntryIds($availableTables, 'cash_reconciliations', $entryIds);
        $deletedRows += $this->deleteVoucherRows($availableTables, $voucherIds);
        $deletedRows += $this->deleteJournalEntries($availableTables, $entryIds);

        if (!empty($carAccountIds) && isset($availableTables['expense_categories'])) {
            $clause = $this->inClause($carAccountIds);
            $statement = $this->db->query(
                "UPDATE expense_categories SET account_id = NULL WHERE business_id = ? AND account_id IN ({$clause['sql']})",
                array_merge([$this->businessId], $clause['params'])
            );
            $updatedRows += $statement->rowCount();
        }

        if (isset($availableTables['cars'])) {
            $statement = $this->db->query("DELETE FROM cars WHERE business_id = ?", [$this->businessId]);
            $deletedRows += $statement->rowCount();
        }

        if (!empty($carAccountIds) && isset($availableTables['accounts'])) {
            $clause = $this->inClause($carAccountIds);
            $statement = $this->db->query(
                "DELETE FROM accounts WHERE business_id = ? AND id IN ({$clause['sql']})",
                array_merge([$this->businessId], $clause['params'])
            );
            $deletedRows += $statement->rowCount();
        }

        return [
            'deleted_rows' => $deletedRows,
            'updated_rows' => $updatedRows,
            'attachment_paths' => $attachmentPaths,
        ];
    }

    /** The prior all-data reset, now explicitly the final testing-only scope. */
    private function clearEverything(): array {
        $engine = new AccountingEngine($this->businessId, $this->userId);
        $availableTables = $this->availableTables();
        $businessDataTables = $this->businessDataTables();
        $deletedRows = 0;
        $foreignKeyChecksDisabled = false;

        $this->db->beginTransaction();
        try {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            $foreignKeyChecksDisabled = true;

            $deletedRows += $this->deleteJoinedChildRows($availableTables, 'journal_lines', 'journal_entries', 'journal_entry_id');
            $deletedRows += $this->deleteJoinedChildRows($availableTables, 'journal_voucher_lines', 'journal_vouchers', 'journal_voucher_id');
            $deletedRows += $this->deleteJoinedChildRows($availableTables, 'car_partner_contributions', 'cars', 'car_id');

            foreach ($businessDataTables as $table) {
                $statement = $this->db->query("DELETE FROM `{$table}` WHERE `business_id` = ?", [$this->businessId]);
                $deletedRows += $statement->rowCount();
            }

            $engine->setupDefaultAccounts();
            $financialYear = getCurrentFY();
            $this->db->insert('financial_years', [
                'id' => Database::uuid(),
                'business_id' => $this->businessId,
                'year_label' => getFYLabel($financialYear),
                'start_date' => $financialYear . '-04-01',
                'end_date' => ($financialYear + 1) . '-03-31',
                'is_active' => 1,
            ]);

            // Keep one high-signal event rather than audit noise from seeded defaults.
            if (isset($availableTables['audit_log'])) {
                $this->db->query("DELETE FROM audit_log WHERE business_id = ?", [$this->businessId]);
                Auth::auditLog(
                    'SETTING_CHANGE',
                    'business_data',
                    $this->businessId,
                    'All testing business data cleared and clean defaults recreated after password confirmation.',
                    null,
                    ['deleted_rows' => $deletedRows, 'scope' => self::SCOPE_ALL_BUSINESS_DATA],
                    'settings'
                );
            }

            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
            $foreignKeyChecksDisabled = false;
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($foreignKeyChecksDisabled) {
                try {
                    $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
                } catch (Throwable $restoreError) {
                    error_log('Could not restore foreign key checks after database reset failure: ' . $restoreError->getMessage());
                }
            }
            throw $e;
        }

        $fileCleanup = $this->removeAttachmentDirectory();
        return [
            'deleted_rows' => $deletedRows,
            'updated_rows' => 0,
            'deleted_files' => $fileCleanup['deleted_files'],
            'file_cleanup_failed' => $fileCleanup['failed'],
        ];
    }

    private function findDailyJournalEntryIds(array $availableTables): array {
        if (!isset($availableTables['journal_entries'], $availableTables['accounts'])) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT je.id
             FROM journal_entries je
             WHERE je.business_id = ?
               AND NOT EXISTS (
                    SELECT 1
                    FROM accounts opening_account
                    WHERE opening_account.business_id = je.business_id
                      AND opening_account.opening_entry_id = je.id
               )",
            [$this->businessId]
        );
        return $this->idsFromRows($rows);
    }

    private function carAccountIds(array $availableTables): array {
        if (!isset($availableTables['accounts'])) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT a.id
             FROM accounts a
             LEFT JOIN cars c ON c.account_id = a.id AND c.business_id = a.business_id
             WHERE a.business_id = ?
               AND (a.entity_type = 'CAR' OR c.id IS NOT NULL)",
            [$this->businessId]
        );
        return $this->idsFromRows($rows);
    }

    private function findCarVoucherIds(array $availableTables, array $carIds, array $carAccountIds): array {
        if (!isset($availableTables['journal_vouchers'], $availableTables['journal_voucher_lines'])) {
            return [];
        }
        $conditions = [];
        $params = [$this->businessId];
        if (!empty($carAccountIds)) {
            $clause = $this->inClause($carAccountIds);
            $conditions[] = "jv.primary_account_id IN ({$clause['sql']})";
            $conditions[] = "jvl.account_id IN ({$clause['sql']})";
            $params = array_merge($params, $clause['params'], $clause['params']);
        }
        if (!empty($carIds)) {
            $clause = $this->inClause($carIds);
            $conditions[] = "(jvl.entity_type = 'CAR' AND jvl.entity_id IN ({$clause['sql']}))";
            $params = array_merge($params, $clause['params']);
        }
        if (empty($conditions)) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT jv.id
             FROM journal_vouchers jv
             LEFT JOIN journal_voucher_lines jvl ON jvl.journal_voucher_id = jv.id
             WHERE jv.business_id = ? AND (" . implode(' OR ', $conditions) . ')',
            $params
        );
        return $this->idsFromRows($rows);
    }

    private function findCarJournalEntryIds(array $availableTables, array $carAccountIds, array $voucherIds): array {
        if (!isset($availableTables['journal_entries'])) {
            return [];
        }
        $conditions = ['je.car_id IS NOT NULL'];
        $params = [$this->businessId];
        if (!empty($carAccountIds) && isset($availableTables['journal_lines'])) {
            $clause = $this->inClause($carAccountIds);
            $conditions[] = "jl.account_id IN ({$clause['sql']})";
            $params = array_merge($params, $clause['params']);
        }
        if (!empty($voucherIds)) {
            $clause = $this->inClause($voucherIds);
            $conditions[] = "je.journal_voucher_id IN ({$clause['sql']})";
            $params = array_merge($params, $clause['params']);
        }
        $join = isset($availableTables['journal_lines']) ? 'LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id' : '';
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT je.id FROM journal_entries je {$join}
             WHERE je.business_id = ? AND (" . implode(' OR ', $conditions) . ')',
            $params
        );
        return $this->expandJournalEntryClosure($availableTables, $this->idsFromRows($rows));
    }

    private function expandVoucherIdsForJournalEntries(array $availableTables, array $voucherIds, array $entryIds): array {
        if (!isset($availableTables['journal_vouchers'])) {
            return $voucherIds;
        }
        $known = $this->idSet($voucherIds);
        $entryKnown = $this->idSet($entryIds);
        $changed = true;
        while ($changed) {
            $changed = false;
            if (!empty($entryKnown)) {
                $entryList = array_keys($entryKnown);
                $clause = $this->inClause($entryList);
                $rows = $this->db->fetchAll(
                    "SELECT DISTINCT id
                     FROM journal_vouchers
                     WHERE business_id = ? AND posted_entry_id IN ({$clause['sql']})",
                    array_merge([$this->businessId], $clause['params'])
                );
                foreach ($this->idsFromRows($rows) as $id) {
                    if (!isset($known[$id])) {
                        $known[$id] = true;
                        $changed = true;
                    }
                }

                if (isset($availableTables['journal_entries'])) {
                    $rows = $this->db->fetchAll(
                        "SELECT DISTINCT journal_voucher_id AS id
                         FROM journal_entries
                         WHERE business_id = ? AND id IN ({$clause['sql']})
                           AND journal_voucher_id IS NOT NULL AND journal_voucher_id <> ''",
                        array_merge([$this->businessId], $clause['params'])
                    );
                    foreach ($this->idsFromRows($rows) as $id) {
                        if (!isset($known[$id])) {
                            $known[$id] = true;
                            $changed = true;
                        }
                    }
                }
            }

            if ($changed && !empty($known) && isset($availableTables['journal_entries'])) {
                $voucherList = array_keys($known);
                $clause = $this->inClause($voucherList);
                $rows = $this->db->fetchAll(
                    "SELECT id FROM journal_entries
                     WHERE business_id = ? AND journal_voucher_id IN ({$clause['sql']})",
                    array_merge([$this->businessId], $clause['params'])
                );
                foreach ($this->idsFromRows($rows) as $id) {
                    if (!isset($entryKnown[$id])) {
                        $entryKnown[$id] = true;
                    }
                }
            }
        }
        return array_keys($known);
    }

    private function voucherLineIds(array $availableTables, array $voucherIds): array {
        if (!isset($availableTables['journal_voucher_lines']) || empty($voucherIds)) {
            return [];
        }
        $clause = $this->inClause($voucherIds);
        $rows = $this->db->fetchAll(
            "SELECT id FROM journal_voucher_lines WHERE journal_voucher_id IN ({$clause['sql']})",
            $clause['params']
        );
        return $this->idsFromRows($rows);
    }

    private function journalEntryIdsForVouchers(array $availableTables, array $voucherIds): array {
        if (!isset($availableTables['journal_entries']) || empty($voucherIds)) {
            return [];
        }
        $clause = $this->inClause($voucherIds);
        $rows = $this->db->fetchAll(
            "SELECT id FROM journal_entries
             WHERE business_id = ? AND journal_voucher_id IN ({$clause['sql']})",
            array_merge([$this->businessId], $clause['params'])
        );
        return $this->idsFromRows($rows);
    }

    private function addJournalEntriesUsingVoucherLines(array $availableTables, array $entryIds, array $voucherLineIds): array {
        if (!isset($availableTables['journal_lines']) || empty($voucherLineIds)) {
            return $entryIds;
        }
        $clause = $this->inClause($voucherLineIds);
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT journal_entry_id AS id
             FROM journal_lines
             WHERE source_voucher_line_id IN ({$clause['sql']})",
            $clause['params']
        );
        return $this->mergeIds($entryIds, $this->idsFromRows($rows));
    }

    /** Include reversal/correction siblings so no retained row points at deleted data. */
    private function expandJournalEntryClosure(array $availableTables, array $entryIds): array {
        if (!isset($availableTables['journal_entries']) || empty($entryIds)) {
            return $entryIds;
        }
        $fields = [];
        foreach (['corrected_from_id', 'corrected_by_id', 'original_entry_id', 'reversed_by'] as $field) {
            if ($this->columnExists('journal_entries', $field)) {
                $fields[] = $field;
            }
        }
        if (empty($fields)) {
            return $entryIds;
        }

        $known = $this->idSet($entryIds);
        $changed = true;
        while ($changed) {
            $changed = false;
            $ids = array_keys($known);
            $clause = $this->inClause($ids);
            $selectFields = implode(', ', $fields);
            $rows = $this->db->fetchAll(
                "SELECT id, {$selectFields} FROM journal_entries
                 WHERE business_id = ? AND id IN ({$clause['sql']})",
                array_merge([$this->businessId], $clause['params'])
            );
            foreach ($rows as $row) {
                foreach ($fields as $field) {
                    $id = trim((string) ($row[$field] ?? ''));
                    if ($id !== '' && !isset($known[$id])) {
                        $known[$id] = true;
                        $changed = true;
                    }
                }
            }

            $conditions = [];
            $params = [$this->businessId];
            foreach ($fields as $field) {
                $conditions[] = "{$field} IN ({$clause['sql']})";
                $params = array_merge($params, $clause['params']);
            }
            $rows = $this->db->fetchAll(
                "SELECT id FROM journal_entries
                 WHERE business_id = ? AND (" . implode(' OR ', $conditions) . ')',
                $params
            );
            foreach ($this->idsFromRows($rows) as $id) {
                if (!isset($known[$id])) {
                    $known[$id] = true;
                    $changed = true;
                }
            }
        }
        return array_keys($known);
    }

    private function deleteJournalEntries(array $availableTables, array $entryIds): int {
        if (empty($entryIds) || !isset($availableTables['journal_entries'])) {
            return 0;
        }
        $deletedRows = 0;
        $clause = $this->inClause($entryIds);

        // A last defensive nulling pass protects older/corrupt correction links.
        foreach (['corrected_from_id', 'corrected_by_id', 'original_entry_id', 'reversed_by'] as $field) {
            if ($this->columnExists('journal_entries', $field)) {
                $statement = $this->db->query(
                    "UPDATE journal_entries
                     SET {$field} = NULL
                     WHERE business_id = ? AND {$field} IN ({$clause['sql']}) AND id NOT IN ({$clause['sql']})",
                    array_merge([$this->businessId], $clause['params'], $clause['params'])
                );
                $deletedRows += $statement->rowCount();
            }
        }

        if (isset($availableTables['journal_lines'])) {
            $statement = $this->db->query(
                "DELETE FROM journal_lines WHERE journal_entry_id IN ({$clause['sql']})",
                $clause['params']
            );
            $deletedRows += $statement->rowCount();
        }
        $statement = $this->db->query(
            "DELETE FROM journal_entries WHERE business_id = ? AND id IN ({$clause['sql']})",
            array_merge([$this->businessId], $clause['params'])
        );
        return $deletedRows + $statement->rowCount();
    }

    private function deleteVoucherRows(array $availableTables, array $voucherIds): int {
        if (empty($voucherIds) || !isset($availableTables['journal_vouchers'])) {
            return 0;
        }
        $deletedRows = 0;
        $clause = $this->inClause($voucherIds);
        if (isset($availableTables['journal_voucher_lines'])) {
            $statement = $this->db->query(
                "DELETE FROM journal_voucher_lines WHERE journal_voucher_id IN ({$clause['sql']})",
                $clause['params']
            );
            $deletedRows += $statement->rowCount();
        }
        $statement = $this->db->query(
            "DELETE FROM journal_vouchers WHERE business_id = ? AND id IN ({$clause['sql']})",
            array_merge([$this->businessId], $clause['params'])
        );
        return $deletedRows + $statement->rowCount();
    }

    private function deleteRowsByJournalEntryIds(array $availableTables, string $table, array $entryIds): int {
        if (empty($entryIds) || !isset($availableTables[$table]) || !$this->columnExists($table, 'journal_entry_id')) {
            return 0;
        }
        $clause = $this->inClause($entryIds);
        $params = $clause['params'];
        $where = "journal_entry_id IN ({$clause['sql']})";
        if ($this->columnExists($table, 'business_id')) {
            $where = "business_id = ? AND {$where}";
            $params = array_merge([$this->businessId], $params);
        }
        $statement = $this->db->query("DELETE FROM `{$table}` WHERE {$where}", $params);
        return $statement->rowCount();
    }

    private function deleteCarChildRows(array $availableTables, string $table): int {
        if (!isset($availableTables[$table], $availableTables['cars'])) {
            return 0;
        }
        $statement = $this->db->query(
            "DELETE child_rows
             FROM `{$table}` child_rows
             INNER JOIN cars c ON c.id = child_rows.car_id
             WHERE c.business_id = ?",
            [$this->businessId]
        );
        return $statement->rowCount();
    }

    private function deleteBusinessRows(array $availableTables, string $table): int {
        if (!isset($availableTables[$table]) || !$this->columnExists($table, 'business_id')) {
            return 0;
        }
        $statement = $this->db->query("DELETE FROM `{$table}` WHERE business_id = ?", [$this->businessId]);
        return $statement->rowCount();
    }

    private function businessIds(array $availableTables, string $table): array {
        if (!isset($availableTables[$table]) || !$this->columnExists($table, 'business_id')) {
            return [];
        }
        return $this->idsFromRows($this->db->fetchAll("SELECT id FROM `{$table}` WHERE business_id = ?", [$this->businessId]));
    }

    /** Delete only the attachment records whose parent entity is being removed. */
    private function deleteAttachmentsForEntities(array $availableTables, array $entities, int &$deletedRows): array {
        if (!isset($availableTables['attachments'])) {
            return [];
        }
        $paths = [];
        foreach ($entities as $entityType => $ids) {
            $ids = $this->mergeIds([], $ids);
            if (empty($ids)) {
                continue;
            }
            $clause = $this->inClause($ids);
            $rows = $this->db->fetchAll(
                "SELECT id, relative_path FROM attachments
                 WHERE business_id = ? AND entity_type = ? AND entity_id IN ({$clause['sql']})",
                array_merge([$this->businessId, $entityType], $clause['params'])
            );
            if (empty($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $path = trim((string) ($row['relative_path'] ?? ''));
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
            $statement = $this->db->query(
                "DELETE FROM attachments
                 WHERE business_id = ? AND entity_type = ? AND entity_id IN ({$clause['sql']})",
                array_merge([$this->businessId, $entityType], $clause['params'])
            );
            $deletedRows += $statement->rowCount();
        }
        return array_values(array_unique($paths));
    }

    private function clearStaleAlerts(array $availableTables, string $scope, int &$deletedRows, int $updatedRows): void {
        if (!isset($availableTables['alerts'])) {
            return;
        }
        $statement = $this->db->query("DELETE FROM alerts WHERE business_id = ?", [$this->businessId]);
        $deletedRows += $statement->rowCount();
        if (isset($availableTables['audit_log'])) {
            Auth::auditLog(
                'SETTING_CHANGE',
                'testing_data_cleanup',
                $this->businessId,
                'Scoped testing cleanup (' . $scope . ') completed after password confirmation.',
                null,
                ['scope' => $scope, 'deleted_rows' => $deletedRows, 'updated_rows' => $updatedRows],
                'settings'
            );
        }
    }

    /** Recalculate stored balances from the retained journal spine. */
    private function rebuildAccountBalances(array $availableTables): void {
        if (!isset($availableTables['accounts'], $availableTables['journal_entries'], $availableTables['journal_lines'])) {
            return;
        }
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.opening_balance, a.opening_balance_type, a.opening_entry_id,
                    COALESCE(SUM(CASE WHEN je.status IN ('POSTED','REVERSED') AND jl.entry_type = 'DR' THEN jl.amount ELSE 0 END), 0) AS posted_dr,
                    COALESCE(SUM(CASE WHEN je.status IN ('POSTED','REVERSED') AND jl.entry_type = 'CR' THEN jl.amount ELSE 0 END), 0) AS posted_cr
             FROM accounts a
             LEFT JOIN journal_lines jl ON jl.account_id = a.id
             LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE a.business_id = ?
             GROUP BY a.id, a.opening_balance, a.opening_balance_type, a.opening_entry_id",
            [$this->businessId]
        );
        foreach ($rows as $row) {
            $legacyOpening = empty($row['opening_entry_id']) ? floatval($row['opening_balance'] ?? 0) : 0.0;
            $openingDr = strtoupper((string) ($row['opening_balance_type'] ?? 'DR')) === 'DR' ? $legacyOpening : 0.0;
            $openingCr = strtoupper((string) ($row['opening_balance_type'] ?? 'DR')) === 'CR' ? $legacyOpening : 0.0;
            $net = ($openingDr + floatval($row['posted_dr'] ?? 0)) - ($openingCr + floatval($row['posted_cr'] ?? 0));
            $this->db->query(
                "UPDATE accounts SET current_balance = ?, current_balance_type = ? WHERE id = ? AND business_id = ?",
                [round(abs($net), 2), $net >= 0 ? 'DR' : 'CR', $row['id'], $this->businessId]
            );
        }
    }

    private function availableTables(): array {
        $rows = $this->db->fetchAll(
            "SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );
        $tables = [];
        foreach ($rows as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? '');
            if ($this->isSafeIdentifier($table)) {
                $tables[$table] = true;
            }
        }
        return $tables;
    }

    private function businessDataTables(): array {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT TABLE_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'business_id'
             ORDER BY TABLE_NAME"
        );
        $preservedTables = ['businesses', 'users', 'user_book_permissions'];
        $tables = [];
        foreach ($rows as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? '');
            if ($this->isSafeIdentifier($table) && !in_array($table, $preservedTables, true)) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    private function deleteJoinedChildRows(array $availableTables, string $childTable, string $parentTable, string $parentIdColumn): int {
        if (!isset($availableTables[$childTable], $availableTables[$parentTable])) {
            return 0;
        }
        foreach ([$childTable, $parentTable, $parentIdColumn] as $identifier) {
            if (!$this->isSafeIdentifier($identifier)) {
                throw new RuntimeException('Unsafe database identifier detected.');
            }
        }
        $statement = $this->db->query(
            "DELETE child_rows
             FROM `{$childTable}` child_rows
             INNER JOIN `{$parentTable}` parent_rows ON parent_rows.id = child_rows.`{$parentIdColumn}`
             WHERE parent_rows.business_id = ?",
            [$this->businessId]
        );
        return $statement->rowCount();
    }

    private function columnExists(string $table, string $column): bool {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!$this->isSafeIdentifier($table) || !$this->isSafeIdentifier($column)) {
            return false;
        }
        $row = $this->db->fetch(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            [$table, $column]
        );
        $cache[$key] = (bool) $row;
        return $cache[$key];
    }

    private function idsFromRows(array $rows): array {
        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private function idSet(array $ids): array {
        $set = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $set[$id] = true;
            }
        }
        return $set;
    }

    private function mergeIds(array $first, array $second): array {
        return array_keys(array_merge($this->idSet($first), $this->idSet($second)));
    }

    private function inClause(array $values): array {
        $values = array_values(array_keys($this->idSet($values)));
        if (empty($values)) {
            throw new InvalidArgumentException('A non-empty ID list is required.');
        }
        return ['sql' => implode(',', array_fill(0, count($values), '?')), 'params' => $values];
    }

    private function isSafeIdentifier($value): bool {
        return preg_match('/^[a-zA-Z0-9_]+$/', (string) $value) === 1;
    }

    private function removeAttachmentFiles(array $relativePaths): array {
        $safeBusinessId = preg_replace('/[^a-zA-Z0-9-]/', '', $this->businessId);
        $expectedPrefix = 'uploads/attachments/' . $safeBusinessId . '/';
        $root = dirname(__DIR__) . '/uploads/attachments/' . $safeBusinessId;
        $deletedFiles = 0;
        $failed = false;
        foreach (array_unique($relativePaths) as $relativePath) {
            $relativePath = ltrim(str_replace('\\', '/', (string) $relativePath), '/');
            if (!str_starts_with($relativePath, $expectedPrefix) || str_contains($relativePath, '..')) {
                $failed = true;
                continue;
            }
            $absolutePath = dirname(__DIR__) . '/' . $relativePath;
            if (!str_starts_with($absolutePath, $root . '/')) {
                $failed = true;
                continue;
            }
            if (is_file($absolutePath) && !@unlink($absolutePath)) {
                $failed = true;
            } elseif (is_file($absolutePath)) {
                $deletedFiles++;
            }
        }
        return ['deleted_files' => $deletedFiles, 'failed' => $failed];
    }

    private function removeAttachmentDirectory(): array {
        $safeBusinessId = preg_replace('/[^a-zA-Z0-9-]/', '', $this->businessId);
        if ($safeBusinessId === '' || !hash_equals($this->businessId, $safeBusinessId)) {
            return ['deleted_files' => 0, 'failed' => true];
        }
        $deletedFiles = 0;
        $failed = false;
        foreach (['attachments', 'agreements'] as $storageType) {
            $target = dirname(__DIR__) . '/uploads/' . $storageType . '/' . $safeBusinessId;
            if (!is_dir($target)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    if (!@rmdir($item->getPathname())) {
                        $failed = true;
                    }
                } elseif (@unlink($item->getPathname())) {
                    $deletedFiles++;
                } else {
                    $failed = true;
                }
            }
            if (!@rmdir($target)) {
                $failed = true;
            }
        }
        return ['deleted_files' => $deletedFiles, 'failed' => $failed];
    }
}
