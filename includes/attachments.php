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
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'heic' => ['image/heic', 'image/heif'],
        'heif' => ['image/heic', 'image/heif'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],
    ];

    if ($mode === 'images') return $imageTypes;

    $officeContainerTypes = ['application/x-ole-storage', 'application/vnd.ms-office', 'application/cdfv2'];
    $zipContainerTypes = ['application/zip', 'application/x-zip', 'application/x-zip-compressed'];

    return array_merge($imageTypes, [
        'pdf' => ['application/pdf'],
        'doc' => array_merge(['application/msword'], $officeContainerTypes),
        'docx' => array_merge(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $zipContainerTypes),
        'xls' => array_merge(['application/vnd.ms-excel'], $officeContainerTypes),
        'xlsx' => array_merge(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $zipContainerTypes),
        'ppt' => array_merge(['application/vnd.ms-powerpoint'], $officeContainerTypes),
        'pptx' => array_merge(['application/vnd.openxmlformats-officedocument.presentationml.presentation'], $zipContainerTypes),
        'csv' => ['text/csv', 'application/csv', 'text/plain', 'application/vnd.ms-excel'],
        'txt' => ['text/plain'],
        'rtf' => ['application/rtf', 'text/rtf'],
        'odt' => array_merge(['application/vnd.oasis.opendocument.text'], $zipContainerTypes),
        'ods' => array_merge(['application/vnd.oasis.opendocument.spreadsheet'], $zipContainerTypes),
        'zip' => array_merge($zipContainerTypes, ['multipart/x-zip']),
        'rar' => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed'],
        '7z' => ['application/x-7z-compressed'],
        'tar' => ['application/x-tar'],
        'gz' => ['application/gzip', 'application/x-gzip'],
    ]);
}

function attachmentAcceptAttribute($mode = 'documents') {
    return implode(',', array_map(static fn($extension) => '.' . $extension, array_keys(attachmentAllowedTypes($mode))));
}

function resolveAttachmentExtension($originalName, $mimeType, $mode = 'documents') {
    $extension = strtolower((string) pathinfo((string) $originalName, PATHINFO_EXTENSION));
    $mimeType = strtolower(trim((string) $mimeType));
    $allowedTypes = attachmentAllowedTypes($mode === 'vouchers' ? 'documents' : $mode);
    if ($extension === '' || !isset($allowedTypes[$extension]) || !in_array($mimeType, $allowedTypes[$extension], true)) {
        if ($mode === 'images') {
            throw new Exception('Only JPG, PNG, WebP, GIF, HEIC, or TIFF images are allowed.');
        }
        throw new Exception('Unsupported file. Upload an image, PDF, Office document, text/CSV file, or ZIP/RAR/7z/TAR/GZ archive.');
    }
    return $extension;
}

function attachmentFileExtension($attachment) {
    $name = $attachment['original_name'] ?? ($attachment['stored_name'] ?? '');
    return strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));
}

function attachmentIsImage($attachment) {
    return str_starts_with(strtolower((string) ($attachment['mime_type'] ?? '')), 'image/');
}

function attachmentTypeLabel($attachment) {
    $extension = attachmentFileExtension($attachment);
    return $extension !== '' ? strtoupper($extension) : 'FILE';
}

function attachmentIconClass($attachment) {
    if (attachmentIsImage($attachment)) return 'ri-image-line';
    return match (attachmentFileExtension($attachment)) {
        'pdf' => 'ri-file-pdf-2-line',
        'doc', 'docx', 'odt', 'rtf' => 'ri-file-word-2-line',
        'xls', 'xlsx', 'ods', 'csv' => 'ri-file-excel-2-line',
        'ppt', 'pptx' => 'ri-file-ppt-2-line',
        'zip', 'rar', '7z', 'tar', 'gz' => 'ri-file-zip-line',
        'txt' => 'ri-file-text-line',
        default => 'ri-file-3-line',
    };
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
        $extension = resolveAttachmentExtension($file['name'] ?? '', $mimeType, $mode);
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = $targetDir . '/' . $storedName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new Exception('Could not save uploaded file.');
        }
        @chmod($targetPath, 0644);

        $relativePath = 'uploads/attachments/' . $businessFolder . '/' . $storedName;
        $attachmentId = Database::uuid();
        $attachmentRecord = [
            'id' => $attachmentId,
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
        ];
        $db->insert('attachments', $attachmentRecord);
        if (class_exists('Auth')) {
            Auth::auditLog('CREATE', strtolower($entityType), $entityId, 'Attachment uploaded: ' . $attachmentRecord['original_name'], null, $attachmentRecord, strtolower($entityType));
        }
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
    if (class_exists('Auth')) {
        Auth::auditLog('DELETE', strtolower($attachment['entity_type']), $attachment['entity_id'], 'Attachment deleted: ' . $attachment['original_name'], $attachment, null, strtolower($attachment['entity_type']));
    }
}
