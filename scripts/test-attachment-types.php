<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/attachments.php';

function assertAttachmentType($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$accepted = [
    ['car.jpg', 'image/jpeg', 'jpg'],
    ['rc.pdf', 'application/pdf', 'pdf'],
    ['agreement.doc', 'application/msword', 'doc'],
    ['agreement.docx', 'application/zip', 'docx'],
    ['statement.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
    ['payments.csv', 'text/plain', 'csv'],
    ['documents.zip', 'application/zip', 'zip'],
    ['documents.rar', 'application/x-rar-compressed', 'rar'],
    ['documents.7z', 'application/x-7z-compressed', '7z'],
];
foreach ($accepted as [$name, $mimeType, $extension]) {
    assertAttachmentType(
        resolveAttachmentExtension($name, $mimeType, 'documents') === $extension,
        "$name is accepted using verified extension and MIME type"
    );
}

$rejected = [
    ['payload.php', 'text/x-php'],
    ['renamed.docx', 'text/x-php'],
    ['script.svg', 'image/svg+xml'],
    ['program.exe', 'application/octet-stream'],
];
foreach ($rejected as [$name, $mimeType]) {
    $blocked = false;
    try {
        resolveAttachmentExtension($name, $mimeType, 'documents');
    } catch (Throwable $e) {
        $blocked = true;
    }
    assertAttachmentType($blocked, "$name is rejected");
}

$accept = attachmentAcceptAttribute('documents');
foreach (['.pdf', '.doc', '.docx', '.xls', '.xlsx', '.csv', '.zip', '.rar', '.7z'] as $extension) {
    assertAttachmentType(str_contains($accept, $extension), "$extension is exposed by file pickers");
}

assertAttachmentType(attachmentIconClass(['original_name' => 'archive.zip', 'mime_type' => 'application/zip']) === 'ri-file-zip-line', 'Archive preview uses a file icon');
assertAttachmentType(attachmentTypeLabel(['original_name' => 'agreement.docx']) === 'DOCX', 'Document preview shows its real type');

echo "Attachment type regression checks completed.\n";
