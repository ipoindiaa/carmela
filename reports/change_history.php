<?php
$pageTitle = 'Change History';
$pageIcon = '<i class="ri-history-line"></i>';
require_once __DIR__ . '/../includes/header.php';

$businessId = Auth::user('business_id');
$entityType = trim((string) get('entity_type', ''));
$entityId = trim((string) get('entity_id', ''));
if (!$entityType || !$entityId) {
    setFlash('error', 'A record is required to view change history.');
    redirect('../dashboard.php');
}
Auth::requireEntityAccess($entityType, 'read');

$history = Auth::getRecordHistory($entityType, $entityId, $businessId, 200);
$entityLabel = ucwords(str_replace('_', ' ', $entityType));

function decodeAuditJson($value) {
    if (is_array($value)) return $value;
    if (!$value) return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function displayAuditValue($value) {
    if ($value === null || $value === '') return '-';
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if (is_array($value)) return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return (string) $value;
}

function renderAuditLines($lines) {
    if (!is_array($lines) || empty($lines)) return '<span class="text-muted">-</span>';
    global $db, $businessId;
    static $accountLabels = [];
    $html = '<div class="audit-line-list">';
    foreach ($lines as $line) {
        if (!is_array($line)) continue;
        $accountId = trim((string) ($line['account_id'] ?? ''));
        $account = trim((string) ($line['account'] ?? ''));
        if ($account === '' && $accountId !== '') {
            if (!array_key_exists($accountId, $accountLabels)) {
                $row = $db->fetch(
                    "SELECT code, name FROM accounts WHERE id = ? AND business_id = ?",
                    [$accountId, $businessId]
                );
                $accountLabels[$accountId] = $row ? trim($row['code'] . ' - ' . $row['name']) : $accountId;
            }
            $account = $accountLabels[$accountId];
        }
        if ($account === '') $account = 'Account';
        $type = strtoupper((string) ($line['type'] ?? $line['entry_type'] ?? ''));
        $amount = floatval($line['amount'] ?? 0);
        $note = trim((string) ($line['narration'] ?? ''));
        $html .= '<div class="audit-line-item">';
        $html .= '<span><strong>' . clean($account) . '</strong>' . ($note !== '' ? '<small>' . clean($note) . '</small>' : '') . '</span>';
        $html .= '<b class="' . ($type === 'DR' ? 'debit-amount' : 'credit-amount') . '">' . clean($type) . ' ' . clean(formatAmount($amount)) . '</b>';
        $html .= '</div>';
    }
    return $html . '</div>';
}
?>

<div class="page-header">
    <h1><i class="ri-history-line"></i> <?= clean($entityLabel) ?> Change History</h1>
    <button type="button" class="btn btn-outline" onclick="history.back()"><i class="ri-arrow-left-line"></i> Back</button>
</div>
<div class="history-record-context text-muted">Record ID: <?= clean($entityId) ?></div>

<?php if (empty($history)): ?>
    <div class="empty-state">No recorded changes for this record yet.</div>
<?php else: ?>
    <div class="history-timeline">
        <?php foreach ($history as $log): ?>
            <?php
                $changes = decodeAuditJson($log['changed_fields'] ?? null);
                if (empty($changes)) {
                    $old = decodeAuditJson($log['old_value'] ?? null);
                    $new = decodeAuditJson($log['new_value'] ?? null);
                    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
                        $oldFieldValue = $old[$field] ?? null;
                        $newFieldValue = $new[$field] ?? null;
                        $oldComparable = is_array($oldFieldValue) ? json_encode($oldFieldValue) : (string) $oldFieldValue;
                        $newComparable = is_array($newFieldValue) ? json_encode($newFieldValue) : (string) $newFieldValue;
                        if ($oldComparable !== $newComparable) {
                            $changes[$field] = ['old' => $oldFieldValue, 'new' => $newFieldValue];
                        }
                    }
                }
            ?>
            <div class="card" style="margin-bottom:14px;">
                <div class="card-header" style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;">
                    <div>
                        <h3><span class="badge badge-blue"><?= clean($log['action']) ?></span> <?= clean($log['description'] ?: $entityLabel . ' changed') ?></h3>
                        <div class="text-muted" style="margin-top:5px;"><?= clean($log['full_name'] ?? 'System') ?> · <?= clean($log['module'] ?: 'system') ?> · <?= formatDate($log['created_at'], 'd M Y, h:i:s A') ?></div>
                    </div>
                    <?php if (!empty($log['request_uri'])): ?><span class="text-muted" style="font-size:11px;max-width:300px;word-break:break-all;"><?= clean($log['request_uri']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($changes)): ?>
                    <div class="card-body" style="padding:0;">
                        <table data-static-table="1">
                            <thead><tr><th>Field</th><th>Old Value</th><th>New Value</th></tr></thead>
                            <tbody>
                            <?php foreach ($changes as $field => $change): ?>
                                <tr>
                                    <td class="text-bold"><?= clean(ucwords(str_replace('_', ' ', $field))) ?></td>
                                    <?php if ($field === 'lines'): ?>
                                        <td><?= renderAuditLines($change['old'] ?? []) ?></td>
                                        <td><?= renderAuditLines($change['new'] ?? []) ?></td>
                                    <?php else: ?>
                                        <td><?= clean(displayAuditValue($change['old'] ?? null)) ?></td>
                                        <td><?= clean(displayAuditValue($change['new'] ?? null)) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
