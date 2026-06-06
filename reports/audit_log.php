<?php
$pageTitle = 'Audit Log';
$pageIcon = '<i class="ri-shield-check-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();
$businessId = Auth::user('business_id');

$page = max(1, intval(get('page', 1)));
$perPage = 50;
$logDate = trim((string) get('date', ''));
$logWhere = "al.business_id = ?";
$logParams = [$businessId];
if ($logDate !== '') {
    $logWhere .= " AND DATE(al.created_at) = ?";
    $logParams[] = $logDate;
}
$total = $db->fetch("SELECT COUNT(*) as cnt FROM audit_log al WHERE {$logWhere}", $logParams);
$pagination = paginate($total['cnt'], $perPage, $page);
$logListParams = array_merge($logParams, [$perPage, $pagination['offset']]);

$logs = $db->fetchAll(
    "SELECT al.*, u.full_name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id 
     WHERE {$logWhere} ORDER BY al.created_at DESC LIMIT ? OFFSET ?",
    $logListParams);

function auditLogUrl($page, $logDate = '') {
    $query = ['page' => $page];
    if ($logDate !== '') $query['date'] = $logDate;
    return '?' . http_build_query($query);
}
?>

<div class="page-header">
    <h1><i class="ri-shield-check-line"></i> Audit Log</h1>
</div>

<div class="filter-bar">
    <form method="GET">
        <div>
            <label class="form-label">Particular Date</label>
            <input type="date" name="date" class="form-control" value="<?= clean($logDate) ?>">
        </div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <?php if ($logDate !== ''): ?><a href="audit_log.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </form>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): 
            $actionBadge = ['CREATE' => 'badge-green', 'UPDATE' => 'badge-blue', 'DELETE' => 'badge-red', 'LOGIN' => 'badge-purple', 'LOGOUT' => 'badge-gray', 'REVERSE' => 'badge-yellow'];
        ?>
        <tr>
            <td style="white-space:nowrap;"><?= formatDate($log['created_at'], 'd M Y, H:i:s') ?></td>
            <td><?= clean($log['full_name'] ?? 'System') ?></td>
            <td><span class="badge <?= $actionBadge[$log['action']] ?? 'badge-gray' ?>"><?= $log['action'] ?></span></td>
            <td><?= clean($log['entity_type'] ?? '-') ?></td>
            <td style="max-width:300px;"><?= clean(mb_substr($log['details'] ?? '', 0, 80)) ?></td>
            <td class="text-muted"><?= $log['ip_address'] ?? '-' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">No audit log entries</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
        <a href="<?= clean(auditLogUrl($i, $logDate)) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
