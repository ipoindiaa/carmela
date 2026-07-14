<?php
$pageTitle = 'Financial Year';
$pageIcon = '<i class="ri-calendar-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireAdmin();
$businessId = Auth::user('business_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');
    if ($action === 'add') {
        $yearLabel = post('year_label');
        $startDate = post('start_date');
        $endDate = post('end_date');
        $fyId = Database::uuid();
        $db->insert('financial_years', [
            'id' => $fyId, 'business_id' => $businessId,
            'year_label' => $yearLabel, 'start_date' => $startDate, 'end_date' => $endDate,
        ]);
        $createdFy = $db->fetch("SELECT * FROM financial_years WHERE id = ? AND business_id = ?", [$fyId, $businessId]);
        Auth::auditCreate('financial_year', $fyId, $createdFy ?: ['year_label' => $yearLabel], "Financial year $yearLabel created", 'financial_year');
        setFlash('success', "Financial Year $yearLabel created!");
    } elseif ($action === 'activate') {
        $fyId = post('fy_id');
        $previousActive = $db->fetch("SELECT id, year_label, is_active FROM financial_years WHERE business_id = ? AND is_active = 1 LIMIT 1", [$businessId]);
        $targetFy = $db->fetch("SELECT * FROM financial_years WHERE id = ? AND business_id = ?", [$fyId, $businessId]);
        if (!$targetFy) throw new Exception('Financial year not found.');
        $db->query("UPDATE financial_years SET is_active = 0 WHERE business_id = ?", [$businessId]);
        $db->query("UPDATE financial_years SET is_active = 1 WHERE id = ? AND business_id = ?", [$fyId, $businessId]);
        Auth::auditUpdate('financial_year', $fyId, ['active_financial_year' => $previousActive['year_label'] ?? null], ['active_financial_year' => $targetFy['year_label']], 'Active financial year changed', 'financial_year');
        setFlash('success', 'Financial year activated!');
    }
    redirect('financial_year.php');
}

$fyList = $db->fetchAll("SELECT * FROM financial_years WHERE business_id = ? ORDER BY start_date DESC", [$businessId]);
?>

<div class="page-header">
    <h1><i class="ri-calendar-line"></i> Financial Year</h1>
    <button onclick="openModal('add-fy')" class="btn btn-primary"><i class="ri-add-line"></i> New FY</button>
</div>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Year</th><th>Start Date</th><th>End Date</th><th class="text-center">Status</th><th>Closed</th><th class="text-center">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($fyList as $fy): ?>
        <tr>
            <td class="text-bold"><?= clean($fy['year_label']) ?></td>
            <td><?= formatDate($fy['start_date']) ?></td>
            <td><?= formatDate($fy['end_date']) ?></td>
            <td class="text-center"><span class="badge <?= $fy['is_active'] ? 'badge-green' : 'badge-gray' ?>"><?= $fy['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td><span class="badge <?= $fy['is_locked'] ? 'badge-red' : 'badge-blue' ?>"><?= $fy['is_locked'] ? 'Closed' : 'Open' ?></span></td>
            <td class="text-center">
                <?php if (!$fy['is_active']): ?>
                <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="fy_id" value="<?= $fy['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline" data-confirm="Activate this FY?"><i class="ri-check-line"></i> Activate</button>
                </form>
                <?php else: ?>
                <span class="text-muted">Current</span>
                <?php endif; ?>
                <a href="../reports/change_history.php?entity_type=financial_year&amp;entity_id=<?= clean($fy['id']) ?>" class="btn btn-sm btn-outline" title="Change history"><i class="ri-history-line"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="modal-overlay" id="add-fy">
    <div class="modal">
        <div class="modal-header"><h3>New Financial Year</h3><button class="modal-close" onclick="closeModal('add-fy')">×</button></div>
        <div class="modal-body">
            <form method="POST" data-confirm-submit="Create this financial year with the entered date range?"><?= csrfField() ?><input type="hidden" name="action" value="add">
                <div class="form-group"><label class="form-label">Label *</label><input type="text" name="year_label" class="form-control" placeholder="e.g., 2024-25" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Start Date *</label><input type="date" name="start_date" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">End Date *</label><input type="date" name="end_date" class="form-control" required></div>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ri-save-line"></i> Create FY</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
