<?php
$pageTitle = 'RTO Book';
$pageIcon = '<i class="ri-file-shield-2-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';

Auth::requireBookAccess('rto_book', 'read');
$businessId = Auth::user('business_id');
$userId = Auth::user('user_id');
$engine = new AccountingEngine($businessId, $userId);
$canWriteRto = Auth::hasBookAccess('rto_book', 'write');
$primaryAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'write');
$paymentAccounts = array_merge($primaryAccountGroups['cash_book'] ?? [], $primaryAccountGroups['bank_book'] ?? []);
$paymentAccountIds = array_values(array_filter(array_map(static fn($account) => $account['id'] ?? null, $paymentAccounts)));
$selectedCarId = get('car_id', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canWriteRto) {
        setFlash('error', 'You do not have write access to RTO Book.');
        redirect('list.php');
    }
    try {
        $action = post('action');
        if ($action === 'save_rto') {
            $rtoId = post('rto_id') ?: Database::uuid();
            $carId = post('car_id');
            $car = $db->fetch("SELECT id FROM cars WHERE id = ? AND business_id = ?", [$carId, $businessId]);
            if (!$car) throw new Exception('Select a valid car for RTO work.');
            $data = [
                'business_id' => $businessId,
                'car_id' => $carId,
                'rto_type' => post('rto_type'),
                'status' => post('status', 'PENDING'),
                'party_name' => post('party_name'),
                'rto_office' => post('rto_office'),
                'agent_name' => post('agent_name'),
                'application_no' => post('application_no'),
                'receipt_no' => post('receipt_no'),
                'is_recoverable' => post('is_recoverable') === '1' ? 1 : 0,
                'due_date' => post('due_date') ?: null,
                'submitted_date' => post('submitted_date') ?: null,
                'completed_date' => post('completed_date') ?: null,
                'narration' => post('narration'),
            ];
            if ($data['rto_type'] === '') throw new Exception('RTO type is required.');
            $existing = $db->fetch("SELECT id FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $businessId]);
            if ($existing) {
                $db->update('rto_records', $data, 'id = ? AND business_id = ?', [$rtoId, $businessId]);
            } else {
                $data['id'] = $rtoId;
                $data['created_by'] = $userId;
                $db->insert('rto_records', $data);
            }
            uploadEntityAttachments($businessId, 'RTO_RECORD', $rtoId, 'RTO_DOC', 'rto_docs', $userId, 'vouchers');
            setFlash('success', 'RTO record saved.');
            redirect('list.php?car_id=' . urlencode($carId));
        }

        if ($action === 'add_expense') {
            $rtoId = post('rto_id');
            $rto = $db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $businessId]);
            if (!$rto) throw new Exception('RTO record not found.');
            $accountId = post('payment_account');
            if (!in_array($accountId, $paymentAccountIds, true)) throw new Exception('Invalid payment account.');
            $engine->rtoExpense($rtoId, $rto['car_id'], parseDecimalInput(post('amount')), post('entry_date'), $accountId, post('narration') ?: ('RTO expense - ' . $rto['rto_type']));
            uploadEntityAttachments($businessId, 'RTO_RECORD', $rtoId, 'RTO_DOC', 'rto_docs', $userId, 'vouchers');
            setFlash('success', 'RTO expense posted.');
            redirect('list.php?car_id=' . urlencode($rto['car_id']));
        }

        if ($action === 'add_recovery') {
            $rtoId = post('rto_id');
            $rto = $db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $businessId]);
            if (!$rto) throw new Exception('RTO record not found.');
            $accountId = post('payment_account');
            if (!in_array($accountId, $paymentAccountIds, true)) throw new Exception('Invalid receiving account.');
            $engine->rtoRecovery($rtoId, parseDecimalInput(post('amount')), post('entry_date'), $accountId, post('narration') ?: ('RTO recovery - ' . $rto['rto_type']));
            setFlash('success', 'RTO recovery received.');
            redirect('list.php?car_id=' . urlencode($rto['car_id']));
        }
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$q = trim((string) get('q', ''));
$status = get('status', '');
$agent = trim((string) get('agent', ''));
$where = "WHERE r.business_id = ?";
$params = [$businessId];
if ($selectedCarId !== '') { $where .= " AND r.car_id = ?"; $params[] = $selectedCarId; }
if ($status !== '') { $where .= " AND r.status = ?"; $params[] = $status; }
if ($agent !== '') { $where .= " AND r.agent_name LIKE ?"; $params[] = '%' . $agent . '%'; }
if ($q !== '') {
    $where .= " AND (r.rto_type LIKE ? OR r.party_name LIKE ? OR r.rto_office LIKE ? OR r.agent_name LIKE ? OR r.application_no LIKE ? OR c.registration_no LIKE ? OR c.make LIKE ? OR c.model LIKE ?)";
    $needle = '%' . $q . '%';
    array_push($params, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
}

$stats = $db->fetch(
    "SELECT COALESCE(SUM(expense_amount),0) AS spent,
            COALESCE(SUM(recovered_amount),0) AS recovered,
            COALESCE(SUM(CASE WHEN is_recoverable = 1 THEN GREATEST(expense_amount - recovered_amount, 0) ELSE 0 END),0) AS pending,
            SUM(status = 'COMPLETED') AS completed_count,
            SUM(status IN ('PENDING','IN_PROGRESS')) AS pending_count
     FROM rto_records WHERE business_id = ?",
    [$businessId]
);

$records = $db->fetchAll(
    "SELECT r.*, c.registration_no, c.make, c.model
     FROM rto_records r
     JOIN cars c ON c.id = r.car_id
     $where
     ORDER BY FIELD(r.status, 'IN_PROGRESS','PENDING','COMPLETED','CANCELLED'), r.due_date IS NULL, r.due_date ASC, r.created_at DESC",
    $params
);
$cars = $db->fetchAll("SELECT id, registration_no, make, model, status FROM cars WHERE business_id = ? AND status <> 'CANCELLED' ORDER BY created_at DESC LIMIT 200", [$businessId]);
?>

<div class="page-header">
    <h1><i class="ri-file-shield-2-line"></i> RTO Book</h1>
    <?php if ($canWriteRto): ?><a href="#rto-form" class="btn btn-primary"><i class="ri-add-line"></i> Add RTO Work</a><?php endif; ?>
</div>

<div class="stats-grid compact-operational-grid">
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($stats['spent'] ?? 0) ?></div><div class="stat-label">Total Spent</div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($stats['recovered'] ?? 0) ?></div><div class="stat-label">Recovered</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($stats['pending'] ?? 0) ?></div><div class="stat-label">Pending Recovery</div></div>
    <div class="stat-card"><div class="stat-value"><?= intval($stats['pending_count'] ?? 0) ?></div><div class="stat-label">Pending Work</div></div>
    <div class="stat-card"><div class="stat-value"><?= intval($stats['completed_count'] ?? 0) ?></div><div class="stat-label">Completed</div></div>
</div>

<div class="filter-bar compact-filter-bar">
    <form method="GET" class="compact-filter-form">
        <input type="search" name="q" class="form-control" value="<?= clean($q) ?>" placeholder="Search car, party, agent, application">
        <select name="status" class="form-control">
            <option value="">All Status</option>
            <?php foreach (['PENDING','IN_PROGRESS','COMPLETED','CANCELLED'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= clean(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="agent" class="form-control" value="<?= clean($agent) ?>" placeholder="Agent">
        <?php if ($selectedCarId !== ''): ?><input type="hidden" name="car_id" value="<?= clean($selectedCarId) ?>"><?php endif; ?>
        <button class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Filter</button>
        <a href="list.php" class="btn btn-outline btn-sm">Clear</a>
    </form>
</div>

<?php if ($canWriteRto): ?>
<div class="card" id="rto-form" style="margin-bottom:16px;">
    <div class="card-header"><h3><i class="ri-add-box-line"></i> Add / Update RTO Work</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="rto-entry-grid">
            <?= csrfField() ?><input type="hidden" name="action" value="save_rto">
            <div class="form-group"><label class="form-label">Car *</label><select name="car_id" class="form-control" required><option value="">Select car</option><?php foreach ($cars as $car): ?><option value="<?= clean($car['id']) ?>" <?= $selectedCarId === $car['id'] ? 'selected' : '' ?>><?= clean(formatRegistrationNo($car['registration_no']) . ' - ' . trim(($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">RTO Type *</label><input name="rto_type" class="form-control" placeholder="Transfer, passing, tax, NOC" required></div>
            <div class="form-group"><label class="form-label">Status</label><select name="status" class="form-control"><option value="PENDING">Pending</option><option value="IN_PROGRESS">In Progress</option><option value="COMPLETED">Completed</option><option value="CANCELLED">Cancelled</option></select></div>
            <div class="form-group"><label class="form-label">Party / Customer</label><input name="party_name" class="form-control" placeholder="Owner/customer name"></div>
            <div class="form-group"><label class="form-label">RTO Office</label><input name="rto_office" class="form-control" placeholder="Office"></div>
            <div class="form-group"><label class="form-label">Agent</label><input name="agent_name" class="form-control" placeholder="Agent name"></div>
            <div class="form-group"><label class="form-label">Application No.</label><input name="application_no" class="form-control"></div>
            <div class="form-group"><label class="form-label">Receipt No.</label><input name="receipt_no" class="form-control"></div>
            <div class="form-group"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control"></div>
            <div class="form-group"><label class="form-label">Submitted</label><input type="date" name="submitted_date" class="form-control"></div>
            <div class="form-group"><label class="form-label">Completed</label><input type="date" name="completed_date" class="form-control"></div>
            <div class="form-group"><label class="form-label">Recoverable?</label><select name="is_recoverable" class="form-control"><option value="1">Yes, recover from owner/customer</option><option value="0">No, business cost only</option></select></div>
            <div class="form-group rto-span-2"><label class="form-label">Narration</label><input name="narration" class="form-control" placeholder="Work notes"></div>
            <div class="form-group"><label class="form-label">Documents</label><input type="file" name="rto_docs[]" class="form-control" accept="image/*,application/pdf" multiple></div>
            <div class="form-group rto-actions"><button class="btn btn-primary"><i class="ri-save-line"></i> Save RTO Work</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Car / Work</th><th>Party / Agent</th><th>Status</th><th>Dates</th><th class="text-right">Spent</th><th class="text-right">Recovered</th><th class="text-right">Pending</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($records)): ?><tr><td colspan="8" class="text-center text-muted" style="padding:28px;">No RTO records found.</td></tr><?php endif; ?>
            <?php foreach ($records as $record):
                $pending = !empty($record['is_recoverable']) ? max(0, (float)$record['expense_amount'] - (float)$record['recovered_amount']) : 0;
                $attachments = fetchEntityAttachments($businessId, 'RTO_RECORD', $record['id'], 'RTO_DOC');
            ?>
            <tr>
                <td><a href="../cars/view.php?id=<?= clean($record['car_id']) ?>" class="text-bold"><?= clean(formatRegistrationNo($record['registration_no'])) ?></a><div class="text-muted"><?= clean($record['rto_type']) ?> · <?= clean(trim(($record['make'] ?? '') . ' ' . ($record['model'] ?? ''))) ?></div></td>
                <td><?= clean($record['party_name'] ?: '-') ?><div class="text-muted"><?= clean($record['agent_name'] ?: '-') ?></div></td>
                <td><span class="badge badge-blue"><?= clean(str_replace('_', ' ', $record['status'])) ?></span><?php if (empty($record['is_recoverable'])): ?><div class="mini-pill mini-pill-neutral">Business cost</div><?php endif; ?></td>
                <td><?= renderDateTimeStack($record['due_date'], $record['updated_at']) ?><div class="text-muted">App: <?= clean($record['application_no'] ?: '-') ?></div></td>
                <td class="text-right amount flow-out"><?= formatAmount($record['expense_amount']) ?></td>
                <td class="text-right amount flow-in"><?= formatAmount($record['recovered_amount']) ?></td>
                <td class="text-right amount <?= $pending > 0 ? 'flow-out' : 'flow-neutral' ?>"><?= formatAmount($pending) ?></td>
                <td>
                    <?php if ($canWriteRto): ?>
                    <details class="row-actions-details"><summary>Post</summary>
                        <form method="POST" enctype="multipart/form-data" class="mini-post-form">
                            <?= csrfField() ?><input type="hidden" name="action" value="add_expense"><input type="hidden" name="rto_id" value="<?= clean($record['id']) ?>">
                            <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>"><input name="amount" class="form-control currency-input" placeholder="Expense ₹">
                            <select name="payment_account" class="form-control"><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= clean($account['name'] . ' - ' . $account['code']) ?></option><?php endforeach; ?></select>
                            <input name="narration" class="form-control" placeholder="Expense note"><input type="file" name="rto_docs[]" class="form-control" accept="image/*,application/pdf" multiple><button class="btn btn-sm btn-outline">Add Expense</button>
                        </form>
                        <form method="POST" class="mini-post-form">
                            <?= csrfField() ?><input type="hidden" name="action" value="add_recovery"><input type="hidden" name="rto_id" value="<?= clean($record['id']) ?>">
                            <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>"><input name="amount" class="form-control currency-input" placeholder="Recovery ₹" value="<?= $pending > 0 ? clean(round($pending)) : '' ?>">
                            <select name="payment_account" class="form-control"><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= clean($account['name'] . ' - ' . $account['code']) ?></option><?php endforeach; ?></select>
                            <input name="narration" class="form-control" placeholder="Recovery note"><button class="btn btn-sm btn-success">Receive</button>
                        </form>
                    </details>
                    <?php endif; ?>
                    <?php foreach ($attachments as $attachment): $url = attachmentUrl($attachment); ?><a href="<?= clean($url) ?>" target="_blank" class="mini-pill mini-pill-neutral"><i class="ri-attachment-line"></i> Doc</a><?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
