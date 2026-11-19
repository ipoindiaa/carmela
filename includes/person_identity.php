<?php
/**
 * A person can have several roles and ledgers. Identity consolidation never
 * moves journal lines, nets balances, changes shares or deletes source records.
 */
class PersonIdentity {
    private $db;
    private $businessId;

    public function __construct($businessId) {
        $this->db = Database::getInstance();
        $this->businessId = $businessId;
    }

    public static function ensureSchema($db) {
        $db->query("CREATE TABLE IF NOT EXISTS person_identities (
            id CHAR(36) NOT NULL PRIMARY KEY,
            business_id CHAR(36) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY person_business (business_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS person_identity_members (
            business_id CHAR(36) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            record_id CHAR(36) NOT NULL,
            person_id CHAR(36) NOT NULL,
            aliases TEXT NOT NULL,
            PRIMARY KEY (business_id, kind, record_id),
            KEY person_members (business_id, person_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function identityFor($kind, $recordId) {
        return $this->db->fetch(
            "SELECT p.* FROM person_identities p JOIN person_identity_members m
             ON m.person_id = p.id AND m.business_id = p.business_id
             WHERE m.business_id = ? AND m.kind = ? AND m.record_id = ?",
            [$this->businessId, $kind, $recordId]
        );
    }

    public function existingRoleByAlias($kind, $name, $role) {
        $table = $kind === 'party' ? 'debtors_creditors' : 'partners';
        $roleColumn = $kind === 'party' ? 'type' : 'partner_type';
        $rows = $this->db->fetchAll(
            "SELECT r.*, m.aliases FROM $table r JOIN person_identity_members m
             ON m.business_id = r.business_id AND m.record_id = r.id AND m.kind = ?
             WHERE r.business_id = ? AND r.is_active = 1 AND r.$roleColumn = ?",
            [$kind, $this->businessId, $role]
        );
        foreach ($rows as $row) {
            foreach (json_decode($row['aliases'], true, 512, JSON_THROW_ON_ERROR) as $alias) {
                if (mb_strtolower(trim($alias)) === mb_strtolower(trim($name))) return $row;
            }
        }
        return null;
    }

    private function record($kind, $id) {
        if (!in_array($kind, ['party', 'partner'], true)) throw new Exception('Choose a party or partner.');
        $table = $kind === 'party' ? 'debtors_creditors' : 'partners';
        $row = $this->db->fetch("SELECT * FROM $table WHERE id = ? AND business_id = ?", [$id, $this->businessId]);
        if (!$row) throw new Exception('Person record not found in this business.');
        $row['kind'] = $kind;
        $row['role'] = $kind === 'party' ? $row['type'] : $row['partner_type'] . ' Partner';
        return $row;
    }

    public function members($kind, $id) {
        $person = $this->identityFor($kind, $id);
        if (!$person) return [$this->record($kind, $id)];
        $members = $this->db->fetchAll(
            "SELECT kind, record_id FROM person_identity_members WHERE business_id = ? AND person_id = ? ORDER BY kind, record_id",
            [$this->businessId, $person['id']]
        );
        return array_map(fn($m) => $this->record($m['kind'], $m['record_id']), $members);
    }

    public function preview($leftKind, $leftId, $rightKind, $rightId) {
        if ($leftKind === $rightKind && $leftId === $rightId) throw new Exception('Choose two different records.');
        $left = $this->identityFor($leftKind, $leftId);
        $right = $this->identityFor($rightKind, $rightId);
        if ($left && $right && $left['id'] === $right['id']) throw new Exception('These records already belong to the same person.');
        $members = [];
        foreach (array_merge($this->members($leftKind, $leftId), $this->members($rightKind, $rightId)) as $row) {
            Auth::requireEntityAccess($row['kind'], 'read');
            $members[$row['kind'] . ':' . $row['id']] = $row;
        }
        ksort($members);
        return array_values($members);
    }

    public function fingerprint($members) {
        return hash('sha256', json_encode($members, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function merge($leftKind, $leftId, $rightKind, $rightId, $name, $expectedFingerprint) {
        if (!Auth::isAdmin()) throw new Exception('Only an administrator can merge persons.');
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        if ($name === '' || mb_strlen($name) > 180) throw new Exception('Enter a name of 1 to 180 characters.');
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            // Serialize identity operations for this business. No DDL here:
            // migrations run before the caller starts its transaction.
            $this->db->fetch("SELECT id FROM businesses WHERE id = ? FOR UPDATE", [$this->businessId]);
            $members = $this->preview($leftKind, $leftId, $rightKind, $rightId);
            foreach ($members as $member) {
                $table = $member['kind'] === 'party' ? 'debtors_creditors' : 'partners';
                $this->db->fetch("SELECT id FROM $table WHERE business_id = ? AND id = ? FOR UPDATE", [$this->businessId, $member['id']]);
            }
            $members = $this->preview($leftKind, $leftId, $rightKind, $rightId);
            if (!hash_equals($this->fingerprint($members), (string) $expectedFingerprint)) {
                throw new Exception('A record changed after the preview. Preview again before merging.');
            }
            foreach ($members as $row) {
                Auth::requireEntityAccess($row['kind'], 'write');
                if (empty($row['is_active'])) throw new Exception('Restore inactive records before merging them.');
            }
            $person = $this->identityFor($leftKind, $leftId);
            $personId = $person['id'] ?? Database::uuid();
            if (!$person) $this->db->insert('person_identities', ['id' => $personId, 'business_id' => $this->businessId, 'name' => $name]);
            else $this->db->query("UPDATE person_identities SET name = ? WHERE id = ? AND business_id = ?", [$name, $personId, $this->businessId]);
            foreach ($members as $row) {
                $existing = $this->db->fetch("SELECT * FROM person_identity_members WHERE business_id = ? AND kind = ? AND record_id = ?", [$this->businessId, $row['kind'], $row['id']]);
                $aliases = $existing ? json_decode($existing['aliases'], true, 512, JSON_THROW_ON_ERROR) : [];
                $aliases[] = $row['name'];
                $aliases = json_encode(array_values(array_unique($aliases)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->db->query(
                    "INSERT INTO person_identity_members (business_id, kind, record_id, person_id, aliases) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), aliases = VALUES(aliases)",
                    [$this->businessId, $row['kind'], $row['id'], $personId, $aliases]
                );
                $table = $row['kind'] === 'party' ? 'debtors_creditors' : 'partners';
                $this->db->query("UPDATE $table SET name = ? WHERE id = ? AND business_id = ?", [$name, $row['id'], $this->businessId]);
                $accounts = $row['kind'] === 'party'
                    ? [[$row['account_id'], " ($row[type])"]]
                    : [[$row['capital_account_id'], ' - Capital A/c'], [$row['current_account_id'], ' - Current A/c']];
                foreach ($accounts as [$accountId, $suffix]) {
                    if ($accountId) $this->db->query("UPDATE accounts SET name = ? WHERE id = ? AND business_id = ?", [$name . $suffix, $accountId, $this->businessId]);
                }
                Auth::auditUpdate($row['kind'], $row['id'], ['name' => $row['name'], 'person_id' => $existing['person_id'] ?? null],
                    ['name' => $name, 'person_id' => $personId], 'Person identity merged; original ledgers retained', 'persons');
            }
            Auth::auditCreate('person_identity', $personId, ['name' => $name, 'members' => $members], 'Person merge confirmed', 'persons');
            if ($ownsTransaction) $this->db->commit();
            return $personId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function search($query, $limit = 15) {
        $query = trim((string) $query);
        if (mb_strlen($query) < 2) return [];
        $needle = '%' . addcslashes($query, '%_\\') . '%';
        $results = [];
        foreach (['party' => 'debtors_creditors', 'partner' => 'partners'] as $kind => $table) {
            if (!Auth::hasEntityAccess($kind, 'read')) continue;
            $role = $kind === 'party' ? 'r.type' : "CONCAT(r.partner_type, ' Partner')";
            $rows = $this->db->fetchAll(
                "SELECT r.id, r.name, r.phone, $role AS role, m.person_id
                 FROM $table r LEFT JOIN person_identity_members m
                 ON m.record_id = r.id AND m.kind = ? AND m.business_id = r.business_id
                 WHERE r.business_id = ? AND r.is_active = 1 AND
                 (r.name LIKE ? OR r.phone LIKE ? OR m.aliases LIKE ?)
                 ORDER BY r.name, r.id LIMIT 30",
                [$kind, $this->businessId, $needle, $needle, $needle]
            );
            foreach ($rows as $row) {
                $key = $row['person_id'] ?: $kind . ':' . $row['id'];
                if (isset($results[$key])) {
                    $results[$key]['roles'][] = $row['role'];
                } else {
                    $row['kind'] = $kind;
                    $row['roles'] = [$row['role']];
                    $results[$key] = $row;
                }
            }
        }
        return array_slice(array_values($results), 0, min(30, max(1, (int) $limit)));
    }

    public function balanceSheet($asOnDate) {
        $date = DateTime::createFromFormat('!Y-m-d', (string) $asOnDate);
        if (!$date || $date->format('Y-m-d') !== $asOnDate) throw new Exception('Select a valid balance date.');
        $owners = [];
        $people = [];
        foreach ([
            'party' => ['debtors_creditors', ['account_id'], 'type'],
            'partner' => ['partners', ['capital_account_id', 'current_account_id'], 'partner_type'],
            'employee' => ['employees', ['advance_account_id'], null],
        ] as $kind => [$table, $columns, $roleColumn]) {
            // The report's balance-sheet permission controls financial access.
            // Keep inactive master records: they may have historical balances.
            $rows = $this->db->fetchAll(
                "SELECT r.*, p.id AS shared_person_id, p.name AS shared_name
                 FROM $table r LEFT JOIN person_identity_members m
                 ON m.business_id = r.business_id AND m.kind = ? AND m.record_id = r.id
                 LEFT JOIN person_identities p ON p.id = m.person_id AND p.business_id = r.business_id
                 WHERE r.business_id = ? ORDER BY r.id",
                [$kind, $this->businessId]
            );
            foreach ($rows as $row) {
                $key = $row['shared_person_id'] ? 'person:' . $row['shared_person_id'] : $kind . ':' . $row['id'];
                $people[$key] ??= ['key' => $key, 'name' => $row['shared_name'] ?? $row['name'], 'roles' => [], 'accounts' => [], 'debit' => 0.0, 'credit' => 0.0];
                $role = $roleColumn ? $row[$roleColumn] . ($kind === 'partner' ? ' Partner' : '') : 'Employee';
                $people[$key]['roles'][$role] = $role;
                foreach ($columns as $column) {
                    if (empty($row[$column])) continue;
                    $accountId = $row[$column];
                    if (isset($owners[$accountId]) && $owners[$accountId]['key'] !== $key) {
                        throw new Exception('An account is assigned to different persons. Correct the master account links before generating this report.');
                    }
                    $owners[$accountId] = ['key' => $key, 'role' => $role];
                }
            }
        }
        // One signed balance per account. Reversed originals and their reversal
        // vouchers are both included on their accounting dates, exactly once.
        $accounts = $this->db->fetchAll(
            "SELECT a.id, a.code, a.name, a.group_name, a.opening_balance, a.opening_balance_type, a.opening_entry_id,
                    COALESCE(SUM(CASE WHEN j.status IN ('POSTED','REVERSED') AND j.entry_date <= ?
                         THEN CASE WHEN l.entry_type = 'DR' THEN l.amount ELSE -l.amount END ELSE 0 END), 0) AS journal_net
             FROM accounts a LEFT JOIN journal_lines l ON l.account_id = a.id
             LEFT JOIN journal_entries j ON j.id = l.journal_entry_id AND j.business_id = a.business_id
             WHERE a.business_id = ? GROUP BY a.id ORDER BY a.code",
            [$asOnDate, $this->businessId]
        );
        foreach ($accounts as $account) {
            if (!isset($owners[$account['id']])) continue;
            $owner = $owners[$account['id']];
            $opening = empty($account['opening_entry_id']) ? (float) $account['opening_balance'] : 0.0;
            $net = round((float) $account['journal_net'] + ($account['opening_balance_type'] === 'DR' ? $opening : -$opening), 2);
            if (abs($net) < 0.005) continue;
            $account['debit'] = max(0, $net);
            $account['credit'] = max(0, -$net);
            $account['role'] = $owner['role'];
            unset($account['journal_net'], $account['opening_balance'], $account['opening_balance_type'], $account['opening_entry_id']);
            $people[$owner['key']]['accounts'][] = $account;
            $people[$owner['key']]['debit'] += $account['debit'];
            $people[$owner['key']]['credit'] += $account['credit'];
        }
        $people = array_values(array_filter($people, static fn($person) => $person['accounts']));
        usort($people, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['key'], $b['key']));
        foreach ($people as &$person) {
            $person['roles'] = array_values($person['roles']);
            $person['debit'] = round($person['debit'], 2);
            $person['credit'] = round($person['credit'], 2);
        }
        unset($person);
        return $people;
    }
}
