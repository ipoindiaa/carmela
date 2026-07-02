<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function ensureAttachmentSchema() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $db = Database::getInstance();
    $db->query(
        "CREATE TABLE IF NOT EXISTS `attachments` (
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
            KEY `idx_attachment_uploaded_by` (`uploaded_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    try {
        $db->query("ALTER TABLE `attachments` MODIFY COLUMN `entity_type` VARCHAR(50) NOT NULL");
        $db->query("ALTER TABLE `attachments` MODIFY COLUMN `attachment_type` VARCHAR(50) NOT NULL");
    } catch (\Throwable $e) {
        // Older MySQL permissions may block ALTER; existing CAR/JOURNAL_ENTRY uploads still work.
    }

    $ensured = true;
}

function attachmentUploadRoot() {
    return dirname(__DIR__) . '/uploads/attachments';
}

function attachmentAllowedTypes($mode = 'images') {
    $imageTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if ($mode === 'vouchers') {
        $imageTypes['application/pdf'] = 'pdf';
    }

    return $imageTypes;
}

function normalizeFilesArray($fieldName) {
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]['name'])) {
        return [];
    }

    $files = [];
    foreach ($_FILES[$fieldName]['name'] as $index => $name) {
        $files[] = [
            'name' => $name,
            'type' => $_FILES[$fieldName]['type'][$index] ?? '',
            'tmp_name' => $_FILES[$fieldName]['tmp_name'][$index] ?? '',
            'error' => $_FILES[$fieldName]['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $_FILES[$fieldName]['size'][$index] ?? 0,
        ];
    }
    return $files;
}

function uploadEntityAttachments($businessId, $entityType, $entityId, $attachmentType, $fieldName, $uploadedBy, $mode = 'images') {
    ensureAttachmentSchema();

    $files = normalizeFilesArray($fieldName);
    if (empty($files)) {
        return 0;
    }

    $allowedTypes = attachmentAllowedTypes($mode);
    $businessFolder = preg_replace('/[^a-zA-Z0-9-]/', '', (string) $businessId);
    $targetDir = attachmentUploadRoot() . '/' . $businessFolder;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new Exception('Could not create upload folder.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $db = Database::getInstance();
    $uploaded = 0;

    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new Exception('One upload failed. Please try again.');
        }
        if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new Exception('Each upload must be 10 MB or smaller.');
        }

        $tmpName = $file['tmp_name'] ?? '';
        $mimeType = $tmpName ? $finfo->file($tmpName) : '';
        if (!isset($allowedTypes[$mimeType])) {
            throw new Exception($mode === 'vouchers' ? 'Only images or PDF vouchers are allowed.' : 'Only image uploads are allowed.');
        }

        $extension = $allowedTypes[$mimeType];
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = $targetDir . '/' . $storedName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new Exception('Could not save uploaded file.');
        }
        @chmod($targetPath, 0644);

        $relativePath = 'uploads/attachments/' . $businessFolder . '/' . $storedName;
        $db->insert('attachments', [
            'id' => Database::uuid(),
            'business_id' => $businessId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'attachment_type' => $attachmentType,
            'original_name' => mb_substr((string) $file['name'], 0, 255),
            'stored_name' => $storedName,
            'relative_path' => $relativePath,
            'mime_type' => $mimeType,
            'file_size' => (int) ($file['size'] ?? 0),
            'uploaded_by' => $uploadedBy,
        ]);
        $uploaded++;
    }

    return $uploaded;
}

function fetchEntityAttachments($businessId, $entityType, $entityId, $attachmentType = null) {
    ensureAttachmentSchema();
    $db = Database::getInstance();
    $params = [$businessId, $entityType, $entityId];
    $whereType = '';
    if ($attachmentType !== null) {
        $whereType = ' AND attachment_type = ?';
        $params[] = $attachmentType;
    }

    return $db->fetchAll(
        "SELECT *
         FROM attachments
         WHERE business_id = ?
           AND entity_type = ?
           AND entity_id = ?
           {$whereType}
         ORDER BY attachment_type, created_at DESC",
        $params
    );
}

function attachmentUrl($attachment, $absolute = false) {
    $relative = APP_URL . ltrim($attachment['relative_path'] ?? '', '/');
    if (!$absolute) {
        return $relative;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return $relative;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . $relative;
}

function deleteAttachment($businessId, $attachmentId, $entityType = null, $entityId = null) {
    ensureAttachmentSchema();

    $db = Database::getInstance();
    $params = [$attachmentId, $businessId];
    $where = "id = ? AND business_id = ?";
    if ($entityType !== null) {
        $where .= " AND entity_type = ?";
        $params[] = $entityType;
    }
    if ($entityId !== null) {
        $where .= " AND entity_id = ?";
        $params[] = $entityId;
    }

    $attachment = $db->fetch("SELECT * FROM attachments WHERE {$where} LIMIT 1", $params);
    if (!$attachment) {
        throw new Exception('Attachment not found.');
    }

    $relativePath = ltrim((string) ($attachment['relative_path'] ?? ''), '/');
    if ($relativePath !== '') {
        $fullPath = APP_ROOT . '/' . $relativePath;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    $db->query("DELETE FROM attachments WHERE id = ? AND business_id = ?", [$attachmentId, $businessId]);
}
