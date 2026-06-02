<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/app.php';

class Auth {
    private static $bookPermissionsCache = [];
    private static $permissionSchemaEnsured = false;
    private static $auditSchemaEnsured = false;

    public static function init() {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
            session_set_cookie_params(SESSION_LIFETIME);
            session_start();
        }
        self::ensurePermissionSchema();
        self::ensureAuditLogSchema();
    }

    public static function login($identifier, $password) {
        $db = Database::getInstance();
        $identifier = trim((string) $identifier);
        $user = $db->fetch(
            "SELECT u.*, b.name as business_name
             FROM users u
             JOIN businesses b ON u.business_id = b.id
             WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
             LIMIT 1",
            [$identifier, $identifier]
        );
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['business_id'] = $user['business_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['business_name'] = $user['business_name'];
            
            $db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
            
            self::auditLog('LOGIN', 'user', $user['id'], 'User logged in');
            return true;
        }
        return false;
    }

    public static function logout() {
        if (isset($_SESSION['user_id'])) {
            self::auditLog('LOGOUT', 'user', $_SESSION['user_id'], 'User logged out');
        }
        session_destroy();
        header('Location: ' . APP_URL . 'login.php');
        exit;
    }

    public static function check() {
        self::init();
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . APP_URL . 'login.php');
            exit;
        }
    }

    public static function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    public static function user($key = null) {
        if ($key) return $_SESSION[$key] ?? null;
        return $_SESSION;
    }

    public static function isAdmin() {
        return ($_SESSION['role'] ?? '') === ROLE_ADMIN;
    }

    public static function hasRole($roles) {
        if (is_string($roles)) $roles = [$roles];
        return in_array($_SESSION['role'] ?? '', $roles);
    }

    public static function requireAdmin() {
        if (!self::isAdmin()) {
            $_SESSION['flash_error'] = 'Access denied. Admin privileges required.';
            header('Location: ' . APP_URL . 'dashboard.php');
            exit;
        }
    }

    public static function requireRole($roles) {
        if (!self::hasRole($roles)) {
            $_SESSION['flash_error'] = 'Access denied. Insufficient privileges.';
            header('Location: ' . APP_URL . 'dashboard.php');
            exit;
        }
    }

    public static function getBookDefinitions() {
        return BOOK_PERMISSIONS;
    }

    public static function getPrimaryBookKeys() {
        return array_keys(PRIMARY_BOOK_ACCOUNT_TYPES);
    }

    public static function getBookKeyForAccountType($entityType) {
        foreach (PRIMARY_BOOK_ACCOUNT_TYPES as $bookKey => $type) {
            if ($type === $entityType) {
                return $bookKey;
            }
        }
        return null;
    }

    public static function getBookPermissions($userId = null, $businessId = null, $role = null) {
        self::ensurePermissionSchema();

        $userId = $userId ?: ($_SESSION['user_id'] ?? null);
        $businessId = $businessId ?: ($_SESSION['business_id'] ?? null);
        $role = $role ?: ($_SESSION['role'] ?? null);

        if (!$userId || !$businessId) {
            return self::buildPermissionMatrix(false, false);
        }

        if ($role === ROLE_ADMIN) {
            return self::buildPermissionMatrix(true, true);
        }

        $cacheKey = $businessId . ':' . $userId;
        if (isset(self::$bookPermissionsCache[$cacheKey])) {
            return self::$bookPermissionsCache[$cacheKey];
        }

        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT book_key, can_read, can_write
             FROM user_book_permissions
             WHERE business_id = ? AND user_id = ?",
            [$businessId, $userId]
        );

        if (empty($rows)) {
            $permissions = self::buildPermissionMatrix(false, false);
        } else {
            $permissions = self::buildPermissionMatrix(false, false);
            foreach ($rows as $row) {
                if (!isset($permissions[$row['book_key']])) {
                    continue;
                }
                $permissions[$row['book_key']]['read'] = !empty($row['can_read']);
                $permissions[$row['book_key']]['write'] = !empty($row['can_write']);
            }
        }

        self::$bookPermissionsCache[$cacheKey] = $permissions;
        return $permissions;
    }

    public static function hasBookAccess($bookKey, $access = 'read') {
        if (self::isAdmin()) {
            return true;
        }

        $permissions = self::getBookPermissions();
        if (!isset($permissions[$bookKey])) {
            return false;
        }

        if ($access === 'write') {
            return !empty($permissions[$bookKey]['write']);
        }

        return !empty($permissions[$bookKey]['read']) || !empty($permissions[$bookKey]['write']);
    }

    public static function hasAnyBookAccess($bookKeys, $access = 'read') {
        foreach ((array) $bookKeys as $bookKey) {
            if (self::hasBookAccess($bookKey, $access)) {
                return true;
            }
        }
        return false;
    }

    public static function requireBookAccess($bookKey, $access = 'read') {
        if (!self::hasBookAccess($bookKey, $access)) {
            $_SESSION['flash_error'] = 'Access denied for this book.';
            header('Location: ' . APP_URL . 'dashboard.php');
            exit;
        }
    }

    public static function requireAnyBookAccess($bookKeys, $access = 'read') {
        if (!self::hasAnyBookAccess($bookKeys, $access)) {
            $_SESSION['flash_error'] = 'Access denied. You do not have permission for this book.';
            header('Location: ' . APP_URL . 'dashboard.php');
            exit;
        }
    }

    public static function getAccessiblePrimaryAccountTypes($access = 'read') {
        $types = [];
        foreach (PRIMARY_BOOK_ACCOUNT_TYPES as $bookKey => $accountType) {
            if (self::hasBookAccess($bookKey, $access)) {
                $types[] = $accountType;
            }
        }
        return $types;
    }

    public static function getAccessiblePrimaryAccounts($businessId, $access = 'read') {
        $types = self::getAccessiblePrimaryAccountTypes($access);
        if (empty($types)) {
            return [];
        }

        $db = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $rows = $db->fetchAll(
            "SELECT *
             FROM accounts
             WHERE business_id = ?
               AND entity_id IS NULL
               AND entity_type IN ($placeholders)
             ORDER BY FIELD(entity_type, 'CASH', 'BANK', 'GST')",
            array_merge([$businessId], $types)
        );

        $accounts = [];
        foreach ($rows as $row) {
            $bookKey = self::getBookKeyForAccountType($row['entity_type']);
            if ($bookKey) {
                $accounts[$bookKey] = $row;
            }
        }
        return $accounts;
    }

    public static function getAccessiblePrimaryAccountIds($businessId, $access = 'read') {
        return array_values(array_filter(array_map(
            static fn($account) => $account['id'] ?? null,
            self::getAccessiblePrimaryAccounts($businessId, $access)
        )));
    }

    public static function canAccessTransactionEntry($entryId, $businessId, $access = 'read') {
        if (self::isAdmin()) {
            return true;
        }

        $accountIds = self::getAccessiblePrimaryAccountIds($businessId, $access);
        if (empty($accountIds)) {
            return false;
        }

        $db = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $row = $db->fetch(
            "SELECT je.id
             FROM journal_entries je
             WHERE je.id = ?
               AND je.business_id = ?
               AND EXISTS (
                   SELECT 1
                   FROM journal_lines jl
                   WHERE jl.journal_entry_id = je.id
                     AND jl.account_id IN ($placeholders)
               )",
            array_merge([$entryId, $businessId], $accountIds)
        );

        return !empty($row);
    }

    public static function generateUsername($email, $fullName = '', $excludeUserId = null) {
        $base = strtolower(trim((string) $email));
        if ($base !== '' && strpos($base, '@') !== false) {
            $base = strstr($base, '@', true);
        }
        if ($base === '') {
            $base = strtolower(trim((string) $fullName));
        }
        if ($base === '') {
            $base = 'user';
        }

        $base = preg_replace('/[^a-z0-9]+/', '.', $base);
        $base = trim((string) $base, '.');
        $base = preg_replace('/\.{2,}/', '.', $base);
        if ($base === '') {
            $base = 'user';
        }

        $db = Database::getInstance();
        $username = $base;
        $suffix = 1;

        while (true) {
            $params = [$username];
            $sql = "SELECT id FROM users WHERE username = ?";
            if ($excludeUserId) {
                $sql .= " AND id <> ?";
                $params[] = $excludeUserId;
            }
            $existing = $db->fetch($sql . " LIMIT 1", $params);
            if (!$existing) {
                return $username;
            }
            $suffix++;
            $username = $base . '.' . $suffix;
        }
    }

    public static function saveBookPermissions($targetUserId, $businessId, $rawPermissions) {
        self::ensurePermissionSchema();

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $db->query(
                "DELETE FROM user_book_permissions WHERE business_id = ? AND user_id = ?",
                [$businessId, $targetUserId]
            );

            foreach (BOOK_PERMISSIONS as $bookKey => $definition) {
                $read = !empty($rawPermissions[$bookKey]['read']) ? 1 : 0;
                $write = !empty($rawPermissions[$bookKey]['write']) ? 1 : 0;

                $db->insert('user_book_permissions', [
                    'id' => Database::uuid(),
                    'business_id' => $businessId,
                    'user_id' => $targetUserId,
                    'book_key' => $bookKey,
                    'can_read' => $read,
                    'can_write' => $write,
                ]);
            }

            $db->commit();
            self::clearBookPermissionCache($targetUserId, $businessId);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function auditLog($action, $entityType, $entityId = null, $description = null, $oldValue = null, $newValue = null) {
        $db = Database::getInstance();
        self::ensureAuditLogSchema();
        try {
            $db->insert('audit_log', [
                'id' => Database::uuid(),
                'business_id' => $_SESSION['business_id'] ?? '',
                'user_id' => $_SESSION['user_id'] ?? null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description,
                'old_value' => $oldValue ? json_encode($oldValue) : null,
                'new_value' => $newValue ? json_encode($newValue) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('AutoBooks audit log failed: ' . $e->getMessage());
            try {
                $db->insert('audit_log', [
                    'id' => Database::uuid(),
                    'business_id' => $_SESSION['business_id'] ?? '',
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'action' => $action,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'description' => $description,
                    'old_value' => $oldValue ? json_encode($oldValue) : null,
                    'new_value' => $newValue ? json_encode($newValue) : null,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } catch (\Throwable $fallbackError) {
                error_log('AutoBooks audit log fallback failed: ' . $fallbackError->getMessage());
            }
        }
    }

    private static function buildPermissionMatrix($defaultRead, $defaultWrite) {
        $permissions = [];
        foreach (BOOK_PERMISSIONS as $bookKey => $definition) {
            $permissions[$bookKey] = [
                'read' => $defaultRead,
                'write' => $defaultWrite,
            ];
        }
        return $permissions;
    }

    private static function clearBookPermissionCache($userId = null, $businessId = null) {
        if ($userId && $businessId) {
            unset(self::$bookPermissionsCache[$businessId . ':' . $userId]);
            return;
        }
        self::$bookPermissionsCache = [];
    }

    private static function ensurePermissionSchema() {
        if (self::$permissionSchemaEnsured) {
            return;
        }

        $db = Database::getInstance();
        try {
            $db->query(
                "CREATE TABLE IF NOT EXISTS `user_book_permissions` (
                    `id` CHAR(36) NOT NULL,
                    `business_id` CHAR(36) NOT NULL,
                    `user_id` CHAR(36) NOT NULL,
                    `book_key` VARCHAR(50) NOT NULL,
                    `can_read` TINYINT(1) NOT NULL DEFAULT 0,
                    `can_write` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_user_book_permission` (`user_id`, `book_key`),
                    KEY `idx_ubp_business` (`business_id`),
                    CONSTRAINT `fk_ubp_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_ubp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            // Keep auth working even if the permission schema cannot be created yet.
        }

        self::$permissionSchemaEnsured = true;
    }

    private static function ensureAuditLogSchema() {
        if (self::$auditSchemaEnsured) {
            return;
        }

        $db = Database::getInstance();
        try {
            $column = $db->fetch(
                "SELECT COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'audit_log'
                   AND COLUMN_NAME = 'user_agent'"
            );

            if (!$column) {
                $db->query("ALTER TABLE `audit_log` ADD COLUMN `user_agent` VARCHAR(255) DEFAULT NULL AFTER `ip_address`");
            }
        } catch (\Throwable $e) {
            error_log('AutoBooks audit log schema check failed: ' . $e->getMessage());
        }

        self::$auditSchemaEnsured = true;
    }
}

Auth::init();
