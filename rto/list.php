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

$resolveRtoCase = function () use ($db, $businessId, $userId) {
    $carId = trim((string) post('car_id'));
    $rtoType = trim((string) post('rto_type'));
    $partyName = trim((string) post('party_name'));
    $agentName = trim((string) post('agent_name'));
    $entryMode = strtoupper(trim((string) post('entry_mode', 'RECEIVE')));
    $isRecoverable = $entryMode === 'RECEIVE' ? 1 : 0;
    $narration = trim((string) post('narration'));

    if ($carId === '') throw new Exception('Select car for RTO entry.');
    $car = $db->fetch("SELECT id FROM cars WHERE id = ? AND business_id = ?", [$carId, $businessId]);
    if (!$car) throw new Exception('Select a valid car.');
    if ($rtoType === '') throw new Exception('RTO work name is required.');

    $record = [
        'id' => Database::uuid(),
        'business_id' => $businessId,
        'car_id' => $carId,
        'rto_type' => $rtoType,
        'status' => 'IN_PROGRESS',
        'party_name' => $partyName,
        'agent_name' => $agentName,
        'narration' => $narration,
        'is_recoverable' => $isRecoverable,
        'created_by' => $userId,
    ];
    $db->insert('rto_records', $record);
    Auth::auditCreate('rto_record', $record['id'], $record, 'RTO record created: ' . $rtoType, 'rto');
    return $record;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canWriteRto) {
        setFlash('error', 'You do not have write access to RTO Book.');
        redirect('list.php');
    }

    try {
        $action = post('action');

        if ($action === 'save_rto_entry') {
            $accountId = post('payment_account');
            if (!in_array($accountId, $paymentAccountIds, true)) throw new Exception('Select valid cash/bank account.');
            $entryMode = strtoupper(trim((string) post('entry_mode', 'RECEIVE')));
            $entryDate = post('entry_date');
            $entryAmount = parseDecimalInput(post('amount'));
            if ($entryAmount <= 0) throw new Exception('Amount must be greater than zero.');

            $rto = $resolveRtoCase();
            $narration = post('narration') ?: ($entryMode === 'RECEIVE' ? 'RTO money received - ' . $rto['rto_type'] : 'RTO expense - ' . $rto['rto_type']);

            if ($entryMode === 'RECEIVE') {
                $engine->rtoRecovery($rto['id'], $entryAmount, $entryDate, $accountId, $narration);
                setFlash('success', 'RTO money received.');
            } else {
                $engine->rtoExpense($rto['id'], $rto['car_id'], $entryAmount, $entryDate, $accountId, $narration);
                setFlash('success', 'RTO expense posted.');
            }

            uploadEntityAttachments($businessId, 'RTO_RECORD', $rto['id'], 'RTO_DOC', 'rto_docs', $userId, 'vouchers');
            redirect('list.php?car_id=' . urlencode($rto['car_id']));
        }
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$q = trim((string) get('q', ''));
$where = "WHERE r.business_id = ?";
$params = [$businessId];
if ($selectedCarId !== '') {
    $where .= " AND r.car_id = ?";
    $params[] = $selectedCarId;
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
    <?php if ($canWriteRto): ?><a href="#rto-form" class="btn btn-primary"><i class="ri-add-line"></i> Add RTO Money</a><?php endif; ?>
</div>

<div class="stats-grid compact-operational-grid">
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($stats['recovered'] ?? 0) ?></div><div class="stat-label">RTO Received From Buyer</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($stats['spent'] ?? 0) ?></div><div class="stat-label">RTO Paid To Agent / Office</div></div>
    <?php $rtoNet = (float)($stats['recovered'] ?? 0) - (float)($stats['spent'] ?? 0); ?>
    <div class="stat-card"><div class="stat-value <?= $rtoNet >= 0 ? 'flow-in' : 'flow-out' ?>"><?= formatAmount($rtoNet, true) ?></div><div class="stat-label">Net RTO Balance</div></div>
    <div class="stat-card"><div class="stat-value"><?= intval($stats['total_cases'] ?? 0) ?></div><div class="stat-label">RTO Entries</div></div>
</div>

<div class="entry-menu-legend page-helper-strip">
    <span><i class="ri-arrow-down-circle-line"></i> Buyer gives RTO money = income in that car</span>
    <span><i class="ri-arrow-up-circle-line"></i> You pay agent / office = expense of that car</span>
    <span><i class="ri-attachment-2"></i> Images or PDF vouchers can be attached in every case</span>
</div>

<div class="filter-bar compact-filter-bar">
    <form method="GET" class="compact-filter-form">
        <?php if ($selectedCarId !== ''): ?><input type="hidden" name="car_id" value="<?= clean($selectedCarId) ?>"><?php endif; ?>
        <input type="search" name="q" class="form-control" value="<?= clean($q) ?>" placeholder="Search car, buyer, agent, work name">
        <button class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <a href="list.php<?= $selectedCarId !== '' ? '?car_id=' . urlencode($selectedCarId) : '' ?>" class="btn btn-outline btn-sm">Clear</a>
    </form>
</div>

<?php if ($canWriteRto): ?>
<div class="card" id="rto-form">
    <div class="card-header"><h3><i class="ri-add-box-line"></i> Add RTO Money</h3></div>
    <div class="card-body">
        <div class="entry-menu-legend entry-menu-legend-inset">
            <span><i class="ri-arrow-down-circle-line"></i> Receive = buyer/customer gave RTO money</span>
            <span><i class="ri-arrow-up-circle-line"></i> Expense = you paid agent / RTO office</span>
        </div>
        <form method="POST" enctype="multipart/form-data" class="rto-entry-grid" data-confirm-submit="Post this RTO money entry? Financial corrections require reversal.">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_rto_entry">
            <div class="form-group">
                <label class="form-label">Money Type *</label>
                <select name="entry_mode" class="form-control searchable-select">
                    <option value="RECEIVE">RTO Money Received</option>
                    <option value="EXPENSE">RTO Expense Paid</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date *</label>
                <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Cash / Bank Account *</label>
                <select name="payment_account" class="form-control searchable-select" required>
                    <option value="">Select account</option>
                    <?php foreach ($paymentAccounts as $account): ?>
                        <option value="<?= clean($account['id']) ?>"><?= clean($account['name'] . ' (' . $account['code'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Amount (₹) *</label>
                <input name="amount" class="form-control currency-input" placeholder="0" required>
            </div>
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
                <input name="rto_type" class="form-control" placeholder="Transfer, NOC, tax, passing" required>
            </div>
            <div class="form-group">
                <label class="form-label">Buyer / Customer</label>
                <input name="party_name" class="form-control" placeholder="Who gives RTO money">
            </div>
            <div class="form-group">
                <label class="form-label">Agent / Office</label>
                <input name="agent_name" class="form-control" placeholder="Who receives expense payment">
            </div>
            <div class="form-group rto-span-2">
                <label class="form-label">Narration</label>
                <input name="narration" class="form-control" placeholder="Short note for this RTO entry">
            </div>
            <div class="form-group rto-span-2">
                <label class="form-label">Images / Vouchers</label>
                <input type="file" name="rto_docs[]" class="form-control" accept="image/*,application/pdf" multiple>
                <div class="form-hint">Upload receipt photos, slips, transfer papers, or proof documents.</div>
            </div>
            <div class="form-group rto-actions"><button class="btn btn-primary"><i class="ri-save-line"></i> Save RTO Money</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3><i class="ri-list-check-3"></i> RTO Money History</h3></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline table-columns-medium">
        <table>
            <thead>
                <tr>
                    <th>Car / Work</th>
                    <th>Buyer / Agent</th>
                    <th>Money Type</th>
                    <th class="text-right">Received</th>
                    <th class="text-right">Spent</th>
                    <th>Files</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:28px;">No RTO money history found.</td></tr>
                <?php endif; ?>
                <?php foreach ($records as $record):
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
                        <span class="badge <?= (float) $record['recovered_amount'] > 0 ? 'badge-green' : 'badge-red' ?>">
                            <?= (float) $record['recovered_amount'] > 0 ? 'Money In' : 'Money Out' ?>
                        </span>
                    </td>
                    <td class="text-right amount flow-in"><?= formatAmount($record['recovered_amount']) ?></td>
                    <td class="text-right amount flow-out"><?= formatAmount($record['expense_amount']) ?></td>
                    <td>
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
</div>

<div class="card">
    <div class="card-header"><h3><i class="ri-exchange-funds-line"></i> All RTO Transactions</h3></div>
    <div class="card-body card-body-flush">
        <div class="table-container table-container-inline table-columns-medium">
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
