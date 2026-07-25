<?php
$pageTitle = 'Audit Log';
$pageIcon = '<i class="ri-shield-check-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();
$businessId = Auth::user('business_id');

$page = max(1, intval(get('page', 1)));
$perPage = 50;
$logFrom = trim((string) get('from_date', ''));
$logTo = trim((string) get('to_date', ''));
$logUser = trim((string) get('user_id', ''));
$logQuery = trim((string) get('q', ''));
$logAction = trim((string) get('action', ''));
$logEntity = trim((string) get('entity_type', ''));
$logModule = trim((string) get('module', ''));
$logWhere = "al.business_id = ?";
$logParams = [$businessId];
if ($logFrom !== '') { $logWhere .= " AND DATE(al.created_at) >= ?"; $logParams[] = $logFrom; }
if ($logTo !== '') { $logWhere .= " AND DATE(al.created_at) <= ?"; $logParams[] = $logTo; }
if ($logUser !== '') { $logWhere .= " AND al.user_id = ?"; $logParams[] = $logUser; }
if ($logQuery !== '') { $logWhere .= " AND (al.description LIKE ? OR al.entity_id LIKE ? OR al.request_uri LIKE ? OR al.ip_address LIKE ?)"; $like='%'.$logQuery.'%'; array_push($logParams,$like,$like,$like,$like); }
if ($logAction !== '') { $logWhere .= " AND al.action = ?"; $logParams[] = $logAction; }
if ($logEntity !== '') { $logWhere .= " AND al.entity_type = ?"; $logParams[] = $logEntity; }
if ($logModule !== '') { $logWhere .= " AND al.module = ?"; $logParams[] = $logModule; }
$total = $db->fetch("SELECT COUNT(*) as cnt FROM audit_log al WHERE {$logWhere}", $logParams);
$pagination = paginate($total['cnt'], $perPage, $page);
$logListParams = array_merge($logParams, [$perPage, $pagination['offset']]);

$logs = $db->fetchAll(
    "SELECT al.*, u.full_name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id 
     WHERE {$logWhere} ORDER BY al.created_at DESC LIMIT ? OFFSET ?",
    $logListParams);

function auditLogUrl($page, $logFrom = '', $logTo = '', $logUser = '', $logQuery = '', $logAction = '', $logEntity = '', $logModule = '') {
    $query = ['page' => $page];
    if ($logFrom !== '') $query['from_date'] = $logFrom;
    if ($logTo !== '') $query['to_date'] = $logTo;
    if ($logUser !== '') $query['user_id'] = $logUser;
    if ($logQuery !== '') $query['q'] = $logQuery;
    if ($logAction !== '') $query['action'] = $logAction;
    if ($logEntity !== '') $query['entity_type'] = $logEntity;
    if ($logModule !== '') $query['module'] = $logModule;
    return '?' . http_build_query($query);
}
$auditActions = $db->fetchAll("SELECT DISTINCT action FROM audit_log WHERE business_id = ? ORDER BY action", [$businessId]);
$auditEntities = $db->fetchAll("SELECT DISTINCT entity_type FROM audit_log WHERE business_id = ? ORDER BY entity_type", [$businessId]);
$auditModules = $db->fetchAll("SELECT DISTINCT module FROM audit_log WHERE business_id = ? AND module IS NOT NULL ORDER BY module", [$businessId]);
$auditUsers = $db->fetchAll("SELECT id,full_name FROM users WHERE business_id=? ORDER BY full_name",[$businessId]);
?>

<div class="page-header">
    <h1><i class="ri-shield-check-line"></i> Audit Log</h1>
</div>

<div class="filter-bar">
    <form method="GET">
        <div><label class="form-label">Search audit trail</label><input type="search" name="q" class="form-control" value="<?= clean($logQuery) ?>" placeholder="Reason, record ID, page, IP"></div>
        <div><label class="form-label">From</label><input type="date" name="from_date" class="form-control" value="<?= clean($logFrom) ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to_date" class="form-control" value="<?= clean($logTo) ?>"></div>
        <div><label class="form-label">User</label><select name="user_id" class="form-control"><option value="">All users</option><?php foreach($auditUsers as $row): ?><option value="<?= clean($row['id']) ?>" <?= $logUser===$row['id']?'selected':'' ?>><?= clean($row['full_name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Action</label><select name="action" class="form-control"><option value="">All</option><?php foreach ($auditActions as $row): ?><option value="<?= clean($row['action']) ?>" <?= $logAction === $row['action'] ? 'selected' : '' ?>><?= clean($row['action']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Record</label><select name="entity_type" class="form-control"><option value="">All</option><?php foreach ($auditEntities as $row): ?><option value="<?= clean($row['entity_type']) ?>" <?= $logEntity === $row['entity_type'] ? 'selected' : '' ?>><?= clean(ucwords(str_replace('_', ' ', $row['entity_type']))) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Module</label><select name="module" class="form-control"><option value="">All</option><?php foreach ($auditModules as $row): ?><option value="<?= clean($row['module']) ?>" <?= $logModule === $row['module'] ? 'selected' : '' ?>><?= clean($row['module']) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <?php if ($logFrom !== '' || $logTo !== '' || $logUser !== '' || $logQuery !== '' || $logAction !== '' || $logEntity !== '' || $logModule !== ''): ?><a href="audit_log.php" class="btn btn-ghost btn-sm">Clear all</a><?php endif; ?>
    </form>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Record</th><th>Module / Page</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): 
            $actionBadge = ['CREATE' => 'badge-green', 'UPDATE' => 'badge-blue', 'DELETE' => 'badge-red', 'LOGIN' => 'badge-purple', 'LOGOUT' => 'badge-gray', 'REVERSE' => 'badge-yellow'];
        ?>
        <tr>
            <td style="white-space:nowrap;"><?= formatDate($log['created_at'], 'd M Y, H:i:s') ?></td>
            <td><?= clean($log['full_name'] ?? 'System') ?></td>
            <td><span class="badge <?= $actionBadge[$log['action']] ?? 'badge-gray' ?>"><?= $log['action'] ?></span></td>
            <td><?= clean($log['entity_type'] ?? '-') ?><?php if (!empty($log['entity_id'])): ?><div class="text-muted" style="font-size:10px;"><?= clean($log['entity_id']) ?></div><?php endif; ?></td>
            <td><?= clean($log['module'] ?? '-') ?><?php if (!empty($log['request_uri'])): ?><div class="text-muted" style="font-size:10px;max-width:220px;word-break:break-all;"><?= clean($log['request_uri']) ?></div><?php endif; ?></td>
            <td style="max-width:360px;">
                <?= clean(mb_substr($log['description'] ?? '', 0, 120)) ?>
                <?php $fieldChanges = !empty($log['changed_fields']) ? json_decode($log['changed_fields'], true) : []; ?>
                <?php if (is_array($fieldChanges) && !empty($fieldChanges)): ?><div class="text-muted" style="font-size:10px;margin-top:4px;">Fields: <?= clean(implode(', ', array_keys($fieldChanges))) ?></div><?php endif; ?>
                <?php if (!empty($log['entity_id']) && Auth::hasEntityAccess($log['entity_type'], 'read')): ?><a href="change_history.php?entity_type=<?= urlencode($log['entity_type']) ?>&amp;entity_id=<?= urlencode($log['entity_id']) ?>" style="font-size:11px;">View history</a><?php endif; ?>
            </td>
            <td class="text-muted"><?= $log['ip_address'] ?? '-' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><tr><td colspan="7" class="text-center text-muted" style="padding: 40px;">No audit log entries</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
        <a href="<?= clean(auditLogUrl($i, $logFrom, $logTo, $logUser, $logQuery, $logAction, $logEntity, $logModule)) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
