<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

class Database {
    private static $instance = null;
    private $pdo;
    private $transactionDepth = 0;

    private function __construct() {
        $this->connect();
    }

    private function connect(): void {
        $maxAttempts = 3;
        $lastError = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . APP_TIMEZONE_OFFSET . "'",
                ]);
                // Ensure timezone after connect (quote-safe already validated constant)
                $this->pdo->exec('SET time_zone = ' . $this->pdo->quote(APP_TIMEZONE_OFFSET));
                // Strict mode and sane defaults
                $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
                return;
            } catch (PDOException $e) {
                $lastError = $e;
                // Retry only on transient connection errors
                $msg = $e->getMessage();
                $isTransient = stripos($msg, 'gone away') !== false
                    || stripos($msg, 'Lost connection') !== false
                    || stripos($msg, 'Connection refused') !== false
                    || stripos($msg, 'Too many connections') !== false;
                if (!$isTransient || $attempt === $maxAttempts) {
                    break;
                }
                usleep(200000 * $attempt);
            }
        }
        // Throw instead of die so callers can handle and log properly
        throw new RuntimeException("Database connection failed after $maxAttempts attempts: " . $lastError->getMessage(), 0, $lastError);
    }

    /** Reconnect if connection was lost */
    private function ensureConnection(): void {
        try {
            // Lightweight ping
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            // Reconnect once
            $this->connect();
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** For tests: reset singleton */
    public static function resetInstance(): void {
        self::$instance = null;
    }

    public function getConnection() {
        $this->ensureConnection();
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        $this->ensureConnection();
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // Handle MySQL server has gone away -> retry once after reconnect
            if (stripos($e->getMessage(), 'gone away') !== false || stripos($e->getMessage(), 'Lost connection') !== false) {
                $this->connect();
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            // Deadlock retry once
            if (stripos($e->getMessage(), 'Deadlock') !== false || $e->getCode() == '40001') {
                usleep(100000);
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    /** Safely quote identifiers (table/column names) */
    private function quoteIdentifier($name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function insert($table, $data) {
        if (empty($data)) throw new InvalidArgumentException('Insert data cannot be empty');
        // Validate table name
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new InvalidArgumentException('Invalid table name');
        $columns = array_map([$this, 'quoteIdentifier'], array_keys($data));
        $columnsSql = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO " . $this->quoteIdentifier($table) . " ($columnsSql) VALUES ($placeholders)";
        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /** Insert or ignore duplicate - returns id or null on duplicate */
    public function insertIgnore($table, $data) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new InvalidArgumentException('Invalid table name');
        $columns = array_map([$this, 'quoteIdentifier'], array_keys($data));
        $columnsSql = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT IGNORE INTO " . $this->quoteIdentifier($table) . " ($columnsSql) VALUES ($placeholders)";
        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = []) {
        if (empty($data)) throw new InvalidArgumentException('Update data cannot be empty');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new InvalidArgumentException('Invalid table name');
        $set = implode(', ', array_map(fn($k) => $this->quoteIdentifier($k) . " = ?", array_keys($data)));
        $sql = "UPDATE " . $this->quoteIdentifier($table) . " SET $set WHERE $where";
        return $this->query($sql, array_merge(array_values($data), $whereParams));
    }

    /** Transaction with savepoint nesting support */
    public function beginTransaction() {
        if ($this->transactionDepth === 0) {
            $this->ensureConnection();
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT sp_' . $this->transactionDepth);
        }
        $this->transactionDepth++;
    }

    public function inTransaction() {
        // Depth >0 means we are in a logical transaction
        if ($this->transactionDepth > 0) return true;
        return $this->pdo->inTransaction();
    }

    public function commit() {
        if ($this->transactionDepth <= 0) return;
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT sp_' . $this->transactionDepth);
        }
    }

    public function rollBack() {
        if ($this->transactionDepth <= 0) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_' . $this->transactionDepth);
        }
    }

    /** Force rollback entire chain */
    public function rollBackAll(): void {
        $this->transactionDepth = 0;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public static function uuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
