<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/accounting_engine.php';

class BusinessDataResetService {
    private $db;
    private $businessId;
    private $userId;

    public function __construct($businessId, $userId) {
        $this->db = Database::getInstance();
        $this->businessId = (string) $businessId;
        $this->userId = (string) $userId;
    }

    public function reset($password, $confirmationPhrase) {
        if (!hash_equals('CLEAR', trim((string) $confirmationPhrase))) {
            throw new Exception('Type CLEAR exactly to confirm the database reset.');
        }

        $user = $this->db->fetch(
            "SELECT id, business_id, password_hash, role, is_active
             FROM users
             WHERE id = ? AND business_id = ?
             LIMIT 1",
            [$this->userId, $this->businessId]
        );
        if (!$user || empty($user['is_active']) || $user['role'] !== ROLE_ADMIN) {
            throw new Exception('Only an active administrator can clear business data.');
        }
        if (!password_verify((string) $password, $user['password_hash'])) {
            usleep(300000);
            throw new Exception('The password is incorrect. No data was cleared.');
        }

        $business = $this->db->fetch("SELECT id FROM businesses WHERE id = ? LIMIT 1", [$this->businessId]);
        if (!$business) {
            throw new Exception('Business not found.');
        }

        // Constructing the engine first ensures all optional data tables exist and
        // can be included in the same reset operation.
        $engine = new AccountingEngine($this->businessId, $this->userId);
        $availableTables = $this->availableTables();
        $businessDataTables = $this->businessDataTables();
        $deletedRows = 0;
        $foreignKeyChecksDisabled = false;

        $this->db->beginTransaction();
        try {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            $foreignKeyChecksDisabled = true;

            // These child tables do not carry business_id themselves.
            $deletedRows += $this->deleteJoinedChildRows(
                $availableTables,
                'journal_lines',
                'journal_entries',
                'journal_entry_id'
            );
            $deletedRows += $this->deleteJoinedChildRows(
                $availableTables,
                'journal_voucher_lines',
                'journal_vouchers',
                'journal_voucher_id'
            );
            $deletedRows += $this->deleteJoinedChildRows(
                $availableTables,
                'car_partner_contributions',
                'cars',
                'car_id'
            );

            foreach ($businessDataTables as $table) {
                $statement = $this->db->query(
                    "DELETE FROM `{$table}` WHERE `business_id` = ?",
                    [$this->businessId]
                );
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

            // setupDefaultAccounts() is normally audited account-by-account. For a
            // reset, retain one clear security event instead of seed noise.
            if (isset($availableTables['audit_log'])) {
                $this->db->query("DELETE FROM audit_log WHERE business_id = ?", [$this->businessId]);
                Auth::auditLog(
                    'SETTING_CHANGE',
                    'business_data',
                    $this->businessId,
                    'All business data cleared and clean defaults recreated after password confirmation.',
                    null,
                    ['deleted_rows' => $deletedRows],
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
            'deleted_files' => $fileCleanup['deleted_files'],
            'file_cleanup_failed' => $fileCleanup['failed'],
        ];
    }

    private function availableTables() {
        $rows = $this->db->fetchAll(
            "SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = 'BASE TABLE'"
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

    private function businessDataTables() {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT TABLE_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND COLUMN_NAME = 'business_id'
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

    private function deleteJoinedChildRows($availableTables, $childTable, $parentTable, $parentIdColumn) {
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
             INNER JOIN `{$parentTable}` parent_rows
                ON parent_rows.id = child_rows.`{$parentIdColumn}`
             WHERE parent_rows.business_id = ?",
            [$this->businessId]
        );
        return $statement->rowCount();
    }

    private function isSafeIdentifier($value) {
        return preg_match('/^[a-zA-Z0-9_]+$/', (string) $value) === 1;
    }

    private function removeAttachmentDirectory() {
        $safeBusinessId = preg_replace('/[^a-zA-Z0-9-]/', '', $this->businessId);
        if ($safeBusinessId === '' || !hash_equals($this->businessId, $safeBusinessId)) {
            return ['deleted_files' => 0, 'failed' => true];
        }

        $deletedFiles = 0;
        $failed = false;
        foreach (['attachments','agreements'] as $storageType) {
            $target = dirname(__DIR__) . '/uploads/' . $storageType . '/' . $safeBusinessId;
            if (!is_dir($target)) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    if (!@rmdir($item->getPathname())) $failed = true;
                } else {
                    if (@unlink($item->getPathname())) $deletedFiles++;
                    else $failed = true;
                }
            }
            if (!@rmdir($target)) $failed = true;
        }

        return ['deleted_files' => $deletedFiles, 'failed' => $failed];
    }
}
