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
            if (!$car) throw new Exception('Select a valid car.');

            $data = [
                'business_id' => $businessId,
                'car_id' => $carId,
                'rto_type' => trim((string) post('rto_type')),
                'status' => post('status', 'PENDING'),
                'party_name' => trim((string) post('party_name')),
                'agent_name' => trim((string) post('agent_name')),
                'narration' => trim((string) post('narration')),
                'is_recoverable' => post('is_recoverable') === '0' ? 0 : 1,
            ];

            if ($data['rto_type'] === '') {
                throw new Exception('RTO work name is required.');
            }

            $existing = $db->fetch("SELECT id FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $businessId]);
            if ($existing) {
                $db->update('rto_records', $data, 'id = ? AND business_id = ?', [$rtoId, $businessId]);
            } else {
                $data['id'] = $rtoId;
                $data['created_by'] = $userId;
                $db->insert('rto_records', $data);
            }

            uploadEntityAttachments($businessId, 'RTO_RECORD', $rtoId, 'RTO_DOC', 'rto_docs', $userId, 'vouchers');
            setFlash('success', 'RTO case saved.');
            redirect('list.php?car_id=' . urlencode($carId));
        }

        if ($action === 'add_expense') {
            $rtoId = post('rto_id');
            $rto = $db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $businessId]);
            if (!$rto) throw new Exception('RTO case not found.');

            $accountId = post('payment_account');
            if (!in_array($accountId, $paymentAccountIds, true)) throw new Exception('Invalid payment account.');

            $engine->rtoExpense(
                $rtoId,
                $rto['car_id'],
                parseDecimalInput(post('amount')),
                post('entry_date'),
                $accountId,
                post('narration') ?: ('RTO expense - ' . $rto['rto_type'])
            );
            uploadEntityAttachments($businessId, 'RTO_RECORD', $rtoId, 'RTO_DOC', 'rto_docs', $userId, 'vouchers');
            setFlash('success', 'RTO expense posted.');
            redirect('list.php?car_id=' . urlencode($rto['car_id']));
        }

        if ($action === 'add_recovery') {
            $rtoId = post('rto_id');
            $rto = $db->fetch("SELECT * FROM rto_records WHERE id = ? AND business_id = ?", [$rtoId, $businessId]);
            if (!$rto) throw new Exception('RTO case not found.');

            $accountId = post('payment_account');
            if (!in_array($accountId, $paymentAccountIds, true)) throw new Exception('Invalid receiving account.');

            $engine->rtoRecovery(
                $rtoId,
                parseDecimalInput(post('amount')),
                post('entry_date'),
                $accountId,
                post('narration') ?: ('RTO received - ' . $rto['rto_type'])
            );
            uploadEntityAttachments($businessId, 'RTO_RECORD', $rtoId, 'RTO_DOC', 'rto_docs', $userId, 'vouchers');
            setFlash('success', 'RTO money received.');
            redirect('list.php?car_id=' . urlencode($rto['car_id']));
        }
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$q = trim((string) get('q', ''));
$mode = get('mode', '');
$where = "WHERE r.business_id = ?";
$params = [$businessId];
if ($selectedCarId !== '') {
    $where .= " AND r.car_id = ?";
    $params[] = $selectedCarId;
}
if ($mode === 'recoverable') {
    $where .= " AND r.is_recoverable = 1";
}
if ($mode === 'business') {
    $where .= " AND r.is_recoverable = 0";
}
if ($q !== '') {
    $needle = '%' . $q . '%';
    $where .= " AND (r.rto_type LIKE ? OR r.party_name LIKE ? OR r.agent_name LIKE ? OR c.registration_no LIKE ? OR c.make LIKE ? OR c.model LIKE ?)";
    array_push($params, $needle, $needle, $needle, $needle, $needle, $needle);
}

$stats = $db->fetch(
    "SELECT
        COALESCE(SUM(expense_amount),0) AS spent,
        COALESCE(SUM(recovered_amount),0) AS recovered,
        COALESCE(SUM(CASE WHEN is_recoverable = 1 THEN GREATEST(expense_amount - recovered_amount, 0) ELSE 0 END),0) AS pending_recovery,
        COUNT(*) AS total_cases
     FROM rto_records
     WHERE business_id = ?",
    [$businessId]
);

$records = $db->fetchAll(
    "SELECT r.*, c.registration_no, c.make, c.model, c.status AS car_status
     FROM rto_records r
     JOIN cars c ON c.id = r.car_id
     $where
     ORDER BY r.created_at DESC",
    $params
);

$cars = $db->fetchAll(
    "SELECT id, registration_no, make, model, status
     FROM cars
     WHERE business_id = ? AND status <> 'CANCELLED'
     ORDER BY created_at DESC
     LIMIT 300",
    [$businessId]
);

$rtoEntries = $db->fetchAll(
    "SELECT je.id, je.entry_date, je.created_at, je.reference_no, je.transaction_type, je.narration,
            c.id AS car_id, c.registration_no, c.make, c.model
     FROM journal_entries je
     LEFT JOIN cars c ON c.id = je.car_id
     WHERE je.business_id = ?
       AND je.status = 'POSTED'
       AND je.transaction_type IN ('RTO_EXPENSE', 'RTO_RECOVERY')
       " . ($selectedCarId !== '' ? "AND je.car_id = ?" : "") . "
     ORDER BY je.entry_date DESC, je.created_at DESC
     LIMIT 200",
    $selectedCarId !== '' ? [$businessId, $selectedCarId] : [$businessId]
);
?>

<div class="page-header">
    <h1><i class="ri-file-shield-2-line"></i> RTO Book</h1>
    <?php if ($canWriteRto): ?><a href="#rto-form" class="btn btn-primary"><i class="ri-add-line"></i> Add RTO Case</a><?php endif; ?>
</div>

<div class="stats-grid compact-operational-grid">
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($stats['recovered'] ?? 0) ?></div><div class="stat-label">RTO Received From Buyer</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($stats['spent'] ?? 0) ?></div><div class="stat-label">RTO Paid To Agent / Office</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($stats['pending_recovery'] ?? 0) ?></div><div class="stat-label">Still To Receive</div></div>
    <div class="stat-card"><div class="stat-value"><?= intval($stats['total_cases'] ?? 0) ?></div><div class="stat-label">RTO Cases</div></div>
</div>

<div class="entry-menu-legend" style="margin-bottom:12px;">
    <span><i class="ri-arrow-down-circle-line"></i> Buyer gives RTO money = income in that car</span>
    <span><i class="ri-arrow-up-circle-line"></i> You pay agent / office = expense of that car</span>
    <span><i class="ri-attachment-2"></i> Images or PDF vouchers can be attached in every case</span>
</div>

<div class="filter-bar compact-filter-bar">
    <form method="GET" class="compact-filter-form">
        <?php if ($selectedCarId !== ''): ?><input type="hidden" name="car_id" value="<?= clean($selectedCarId) ?>"><?php endif; ?>
        <input type="search" name="q" class="form-control" value="<?= clean($q) ?>" placeholder="Search car, buyer, agent, work name">
        <select name="mode" class="form-control">
            <option value="">All Cases</option>
            <option value="recoverable" <?= $mode === 'recoverable' ? 'selected' : '' ?>>Recoverable From Buyer</option>
            <option value="business" <?= $mode === 'business' ? 'selected' : '' ?>>Business Cost Only</option>
        </select>
        <button class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <a href="list.php<?= $selectedCarId !== '' ? '?car_id=' . urlencode($selectedCarId) : '' ?>" class="btn btn-outline btn-sm">Clear</a>
    </form>
</div>

<?php if ($canWriteRto): ?>
<div class="card" id="rto-form" style="margin-bottom:16px;">
    <div class="card-header"><h3><i class="ri-add-box-line"></i> Add Simple RTO Case</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="rto-entry-grid">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_rto">
            <div class="form-group">
                <label class="form-label">Car *</label>
                <select name="car_id" class="form-control searchable-select" required>
                    <option value="">Select car</option>
                    <?php foreach ($cars as $car): ?>
                        <option value="<?= clean($car['id']) ?>" <?= $selectedCarId === $car['id'] ? 'selected' : '' ?>>
                            <?= clean(formatRegistrationNo($car['registration_no']) . ' - ' . trim(($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">RTO Work *</label>
                <input name="rto_type" class="form-control" placeholder="Transfer, passing, tax, NOC" required>
            </div>
            <div class="form-group">
                <label class="form-label">Buyer / Customer</label>
                <input name="party_name" class="form-control" placeholder="Buyer name">
            </div>
            <div class="form-group">
                <label class="form-label">Agent / Office</label>
                <input name="agent_name" class="form-control" placeholder="Agent or office name">
            </div>
            <div class="form-group">
                <label class="form-label">Recovery Type</label>
                <select name="is_recoverable" class="form-control">
                    <option value="1">Need to receive from buyer</option>
                    <option value="0">Business cost only</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Case Status</label>
                <select name="status" class="form-control">
                    <option value="PENDING">Open</option>
                    <option value="IN_PROGRESS">In Progress</option>
                    <option value="COMPLETED">Done</option>
                    <option value="CANCELLED">Cancelled</option>
                </select>
            </div>
            <div class="form-group rto-span-2">
                <label class="form-label">Narration</label>
                <input name="narration" class="form-control" placeholder="Short note for this RTO case">
            </div>
            <div class="form-group rto-span-2">
                <label class="form-label">Images / Vouchers</label>
                <input type="file" name="rto_docs[]" class="form-control" accept="image/*,application/pdf" multiple>
                <div class="form-hint">Upload receipt photos, agent slips, transfer papers, or PDF vouchers.</div>
            </div>
            <div class="form-group rto-actions"><button class="btn btn-primary"><i class="ri-save-line"></i> Save RTO Case</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h3><i class="ri-list-check-3"></i> RTO Cases</h3></div>
    <div class="card-body" style="padding:0;">
        <table>
            <thead>
                <tr>
                    <th>Car / Work</th>
                    <th>Buyer / Agent</th>
                    <th>Recovery Type</th>
                    <th class="text-right">Received</th>
                    <th class="text-right">Spent</th>
                    <th class="text-right">Pending</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:28px;">No RTO cases found.</td></tr>
                <?php endif; ?>
                <?php foreach ($records as $record):
                    $pending = !empty($record['is_recoverable']) ? max(0, (float) $record['expense_amount'] - (float) $record['recovered_amount']) : 0;
                    $attachments = fetchEntityAttachments($businessId, 'RTO_RECORD', $record['id'], 'RTO_DOC');
                ?>
                <tr>
                    <td>
                        <a href="../cars/view.php?id=<?= clean($record['car_id']) ?>" class="text-bold"><?= clean(formatRegistrationNo($record['registration_no'])) ?></a>
                        <div class="text-muted"><?= clean($record['rto_type']) ?> · <?= clean(trim(($record['make'] ?? '') . ' ' . ($record['model'] ?? ''))) ?></div>
                    </td>
                    <td>
                        <?= clean($record['party_name'] ?: '-') ?>
                        <div class="text-muted"><?= clean($record['agent_name'] ?: '-') ?></div>
                    </td>
                    <td>
                        <span class="badge <?= !empty($record['is_recoverable']) ? 'badge-green' : 'badge-gray' ?>">
                            <?= !empty($record['is_recoverable']) ? 'Buyer Pays' : 'Business Cost' ?>
                        </span>
                        <div class="text-muted" style="margin-top:4px;"><?= clean(str_replace('_', ' ', $record['status'])) ?></div>
                    </td>
                    <td class="text-right amount flow-in"><?= formatAmount($record['recovered_amount']) ?></td>
                    <td class="text-right amount flow-out"><?= formatAmount($record['expense_amount']) ?></td>
                    <td class="text-right amount <?= $pending > 0 ? 'flow-out' : 'flow-neutral' ?>"><?= formatAmount($pending) ?></td>
                    <td>
                        <?php if ($canWriteRto): ?>
                        <details class="row-actions-details">
                            <summary>Post</summary>
                            <form method="POST" enctype="multipart/form-data" class="mini-post-form">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="add_recovery">
                                <input type="hidden" name="rto_id" value="<?= clean($record['id']) ?>">
                                <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>">
                                <input name="amount" class="form-control currency-input" placeholder="Received ₹" value="<?= $pending > 0 ? clean(round($pending)) : '' ?>">
                                <select name="payment_account" class="form-control"><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= clean($account['name'] . ' - ' . $account['code']) ?></option><?php endforeach; ?></select>
                                <input name="narration" class="form-control" placeholder="Buyer gave RTO money">
                                <input type="file" name="rto_docs[]" class="form-control" accept="image/*,application/pdf" multiple>
                                <button class="btn btn-sm btn-success">Receive</button>
                            </form>
                            <form method="POST" enctype="multipart/form-data" class="mini-post-form">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="add_expense">
                                <input type="hidden" name="rto_id" value="<?= clean($record['id']) ?>">
                                <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>">
                                <input name="amount" class="form-control currency-input" placeholder="Spent ₹">
                                <select name="payment_account" class="form-control"><?php foreach ($paymentAccounts as $account): ?><option value="<?= clean($account['id']) ?>"><?= clean($account['name'] . ' - ' . $account['code']) ?></option><?php endforeach; ?></select>
                                <input name="narration" class="form-control" placeholder="Paid to agent / RTO">
                                <input type="file" name="rto_docs[]" class="form-control" accept="image/*,application/pdf" multiple>
                                <button class="btn btn-sm btn-outline">Add Expense</button>
                            </form>
                        </details>
                        <?php endif; ?>
                        <?php foreach ($attachments as $attachment):
                            $url = attachmentUrl($attachment);
                            $shareUrl = attachmentUrl($attachment, true);
                        ?>
                            <a href="<?= clean($url) ?>" target="_blank" class="mini-pill mini-pill-neutral"><i class="ri-attachment-line"></i> Open</a>
                            <button type="button" class="mini-pill mini-pill-neutral" data-share-url="<?= clean($shareUrl) ?>" data-share-title="<?= clean($attachment['original_name']) ?>"><i class="ri-share-forward-line"></i> Share</button>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-exchange-funds-line"></i> All RTO Transactions</h3></div>
    <div class="card-body" style="padding:0;">
        <table>
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Car</th>
                    <th>Type</th>
                    <th>Narration</th>
                    <th>Open</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rtoEntries)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:24px;">No RTO transactions posted yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rtoEntries as $entry): ?>
                    <tr>
                        <td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td>
                        <td>
                            <?php if (!empty($entry['car_id'])): ?>
                                <a href="../cars/view.php?id=<?= clean($entry['car_id']) ?>"><?= clean(formatRegistrationNo($entry['registration_no'])) ?></a>
                                <div class="text-muted"><?= clean(trim(($entry['make'] ?? '') . ' ' . ($entry['model'] ?? ''))) ?></div>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-blue"><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?></span></td>
                        <td><?= clean($entry['narration'] ?: '-') ?></td>
                        <td><a href="../transactions/view.php?id=<?= clean($entry['id']) ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i> View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
