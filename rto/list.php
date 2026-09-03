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
$canDeleteRto = Auth::hasBookAccess('rto_book', 'delete');
$primaryAccountGroups = Auth::getAccessiblePrimaryAccountList($businessId, 'write');
$paymentAccounts = array_merge($primaryAccountGroups['cash_book'] ?? [], $primaryAccountGroups['bank_book'] ?? []);
$paymentAccountIds = array_values(array_filter(array_map(static fn($account) => $account['id'] ?? null, $paymentAccounts)));
$selectedCarId = get('car_id', '');
$rtoOpeningAccount = $engine->getRtoOpeningAccount(true);

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
    if ($rtoType === '') $rtoType = 'RTO - ' . $carId;

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

            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) $db->beginTransaction();
            try {
                $rto = $resolveRtoCase();
                $narration = post('narration') ?: ($entryMode === 'RECEIVE' ? 'RTO money received - ' . $rto['rto_type'] : 'RTO expense - ' . $rto['rto_type']);

                if ($entryMode === 'RECEIVE') {
                    $engine->rtoRecovery($rto['id'], $entryAmount, $entryDate, $accountId, $narration);
                } else {
                    $engine->rtoExpense($rto['id'], $rto['car_id'], $entryAmount, $entryDate, $accountId, $narration);
                }
                if ($ownsTransaction) $db->commit();
            } catch (Throwable $postingError) {
                if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                throw $postingError;
            }

            $uploadWarning = '';
            try {
                uploadEntityAttachments($businessId, 'RTO_RECORD', $rto['id'], 'RTO_DOC', 'rto_docs', $userId, 'documents');
            } catch (Throwable $uploadError) {
                $uploadWarning = ' Entry was posted, but the file upload failed: ' . $uploadError->getMessage();
            }
            setFlash($uploadWarning ? 'warning' : 'success', ($entryMode === 'RECEIVE' ? 'RTO money received.' : 'RTO expense posted.') . $uploadWarning);
            redirect('list.php?car_id=' . urlencode($rto['car_id']));
        }
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }
}

$q = trim((string) get('q', ''));
$filterDate = trim((string) get('date', ''));
if ($filterDate !== '') {
    $parsedFilterDate = DateTime::createFromFormat('Y-m-d', $filterDate);
    if (!$parsedFilterDate || $parsedFilterDate->format('Y-m-d') !== $filterDate) {
        $filterDate = '';
    }
}
$showDeleted = get('show') === 'deleted';
$where = "WHERE r.business_id = ? AND r.status " . ($showDeleted ? "= 'CANCELLED'" : "<> 'CANCELLED'");
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
if ($filterDate !== '') {
    $where .= " AND (
        EXISTS (
            SELECT 1 FROM journal_entries rto_expense_entry
            WHERE rto_expense_entry.id = r.expense_entry_id
              AND rto_expense_entry.business_id = r.business_id
              AND rto_expense_entry.entry_date = ?
        )
        OR EXISTS (
            SELECT 1
            FROM rto_recoveries rr_filter
            JOIN journal_entries rto_recovery_entry ON rto_recovery_entry.id = rr_filter.journal_entry_id
            WHERE rr_filter.rto_record_id = r.id
              AND rr_filter.business_id = r.business_id
              AND rto_recovery_entry.entry_date = ?
        )
    )";
    array_push($params, $filterDate, $filterDate);
}

$records = $db->fetchAll(
    "SELECT r.*, c.registration_no, c.make, c.model, c.status AS car_status
     FROM rto_records r
     JOIN cars c ON c.id = r.car_id
     $where
     ORDER BY r.created_at DESC",
    $params
);

$hasCaseFilter = $q !== '' || $selectedCarId !== '' || $filterDate !== '' || $showDeleted;
$filteredCarIds = array_values(array_unique(array_filter(array_column($records, 'car_id'))));
$rtoPnlWhere = "WHERE je.business_id = ?
    AND je.status IN ('POSTED','REVERSED')
    AND a.code IN ('RTO-REC','RTO-EXP')";
$rtoPnlParams = [$businessId];
if ($selectedCarId !== '') {
    $rtoPnlWhere .= ' AND je.car_id = ?';
    $rtoPnlParams[] = $selectedCarId;
} elseif ($hasCaseFilter) {
    if ($filteredCarIds) {
        $rtoPnlPlaceholders = implode(',', array_fill(0, count($filteredCarIds), '?'));
        $rtoPnlWhere .= " AND je.car_id IN ($rtoPnlPlaceholders)";
        $rtoPnlParams = array_merge($rtoPnlParams, $filteredCarIds);
    } else {
        $rtoPnlWhere .= ' AND 1 = 0';
    }
}
if ($filterDate !== '') {
    $rtoPnlWhere .= ' AND je.entry_date = ?';
    $rtoPnlParams[] = $filterDate;
}
$rtoPnlRows = $db->fetchAll(
    "SELECT a.code,
            COALESCE(SUM(CASE
                WHEN a.group_name = 'INCOME' AND jl.entry_type = 'CR' THEN jl.amount
                WHEN a.group_name = 'INCOME' THEN -jl.amount
                WHEN a.group_name = 'EXPENSE' AND jl.entry_type = 'DR' THEN jl.amount
                ELSE -jl.amount
            END), 0) AS amount
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_entry_id
     JOIN accounts a ON a.id = jl.account_id
     $rtoPnlWhere
     GROUP BY a.code",
    $rtoPnlParams
);
$rtoPnlByCode = array_column($rtoPnlRows, 'amount', 'code');
$rtoPnlIncome = floatval($rtoPnlByCode['RTO-REC'] ?? 0);
$rtoPnlExpense = floatval($rtoPnlByCode['RTO-EXP'] ?? 0);
$rtoPnlNet = round($rtoPnlIncome - $rtoPnlExpense, 2);
if ($hasCaseFilter) {
    $filteredRecovered = array_sum(array_map(static fn($record) => (float) ($record['recovered_amount'] ?? 0), $records));
    $filteredSpent = array_sum(array_map(static fn($record) => (float) ($record['expense_amount'] ?? 0), $records));
    $stats = [
        'opening' => 0,
        'recovered' => $filteredRecovered,
        'spent' => $filteredSpent,
        'net' => $filteredRecovered - $filteredSpent,
    ];
} else {
    $stats = $engine->getRtoBookSummary();
}

$cars = $db->fetchAll(
    "SELECT id, registration_no, make, model, status
     FROM cars
     WHERE business_id = ? AND status <> 'CANCELLED'
     ORDER BY created_at DESC
     LIMIT 300",
    [$businessId]
);

$rtoEntryWhere = "(je.transaction_type IN ('RTO_EXPENSE', 'RTO_RECOVERY') AND ABS(COALESCE(je.entry_amount, 0)) > 0.009)";
$rtoEntryParams = [$businessId];
if ($selectedCarId === '' && !empty($rtoOpeningAccount['id'])) {
    $rtoEntryWhere = "($rtoEntryWhere OR EXISTS (
        SELECT 1 FROM journal_lines opening_line
        WHERE opening_line.journal_entry_id = je.id AND opening_line.account_id = ?
    ))";
    $rtoEntryParams[] = $rtoOpeningAccount['id'];
}
if ($selectedCarId !== '') {
    $rtoEntryWhere .= ' AND je.car_id = ?';
    $rtoEntryParams[] = $selectedCarId;
}
if ($filterDate !== '') {
    $rtoEntryWhere .= ' AND je.entry_date = ?';
    $rtoEntryParams[] = $filterDate;
}
if ($hasCaseFilter && $selectedCarId === '') {
    if ($filteredCarIds) {
        $filteredCarPlaceholders = implode(',', array_fill(0, count($filteredCarIds), '?'));
        $rtoEntryWhere .= " AND je.car_id IN ($filteredCarPlaceholders)";
        $rtoEntryParams = array_merge($rtoEntryParams, $filteredCarIds);
    } else {
        $rtoEntryWhere .= ' AND 1 = 0';
    }
}
$rtoEntries = $db->fetchAll(
    "SELECT je.id, je.business_id, je.entry_date, je.created_at, je.reference_no, je.transaction_type, je.entry_type_id, je.entry_amount, je.narration,
            c.id AS car_id, c.registration_no, c.make, c.model
     FROM journal_entries je
     LEFT JOIN cars c ON c.id = je.car_id
     WHERE je.business_id = ?
       AND je.status IN ('POSTED','REVERSED')
       AND $rtoEntryWhere
     ORDER BY je.entry_date DESC, je.created_at DESC
     LIMIT 200",
    $rtoEntryParams
);
$rtoDraft = $_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_rto_entry' ? $_POST : [];
$rtoDraftValue = static fn(string $key, string $default = ''): string => trim((string) ($rtoDraft[$key] ?? $default));
$clearFilterParams = $selectedCarId !== '' ? ['car_id' => $selectedCarId] : [];
$archiveFilterParams = array_filter([
    'car_id' => $selectedCarId,
    'q' => $q,
    'date' => $filterDate,
    'show' => $showDeleted ? 'active' : 'deleted',
], static fn($value) => $value !== '');
?>

<div class="page-header">
    <h1><i class="ri-file-shield-2-line"></i> RTO Book</h1>
    <div class="page-actions">
        <?php if (Auth::isAdmin() && $rtoOpeningAccount): ?><a href="../settings/opening_balances.php?account_id=<?= clean($rtoOpeningAccount['id']) ?>&amp;return=rto" class="btn btn-outline"><i class="ri-scales-3-line"></i> RTO Opening Balance</a><?php endif; ?>
        <?php if ($canWriteRto): ?><a href="#rto-form" class="btn btn-primary"><i class="ri-add-line"></i> Add RTO Money</a><?php endif; ?>
    </div>
</div>

<div class="stats-grid compact-operational-grid">
    <div class="stat-card"><div class="stat-value <?= ($stats['opening'] ?? 0) >= 0 ? 'flow-in' : 'flow-out' ?>"><?= formatAmount($stats['opening'] ?? 0, true) ?></div><div class="stat-label"><?= $hasCaseFilter ? 'Opening Excluded From Filter' : 'Opening RTO Balance' ?></div></div>
    <div class="stat-card"><div class="stat-value flow-in"><?= formatAmount($stats['recovered'] ?? 0) ?></div><div class="stat-label">RTO Received From Buyer</div></div>
    <div class="stat-card"><div class="stat-value flow-out"><?= formatAmount($stats['spent'] ?? 0) ?></div><div class="stat-label">RTO Paid To Agent / Office</div></div>
    <div class="stat-card"><div class="stat-value <?= $rtoPnlNet >= 0 ? 'flow-in' : 'flow-out' ?>"><?= formatAmount($rtoPnlNet, true) ?></div><div class="stat-label">P&amp;L RTO Net</div><div class="table-secondary">Income <?= formatAmount($rtoPnlIncome) ?> · Expense <?= formatAmount($rtoPnlExpense) ?></div></div>
    <?php $rtoNet = (float) ($stats['net'] ?? 0); ?>
    <div class="stat-card"><div class="stat-value <?= $rtoNet >= 0 ? 'flow-in' : 'flow-out' ?>"><?= formatAmount($rtoNet, true) ?></div><div class="stat-label">Net RTO Balance</div></div>
</div>
<div class="alert alert-info">
    <i class="ri-information-line"></i>
    <div><strong>RTO is included in company profit.</strong><span>RTO money received is income and RTO money paid is expense. Only the net affects Profit &amp; Loss; the Cash/Bank movement remains visible on the Dashboard and in All Entries for a complete audit trail.</span></div>
</div>
<?php if ($hasCaseFilter): ?><div class="filter-context-note">Summary and transaction history are limited to the matching RTO cases. Clear filters to see the complete RTO book.</div><?php endif; ?>

<div class="entry-menu-legend page-helper-strip">
    <span><i class="ri-arrow-down-circle-line"></i> Buyer gives RTO money = income in that car</span>
    <span><i class="ri-arrow-up-circle-line"></i> You pay agent / office = expense of that car</span>
    <span><i class="ri-scales-3-line"></i> Old RTO amount = RTO Opening Balance, without selecting a car</span>
    <span><i class="ri-attachment-2"></i> Photos, documents, and archives can be attached to every case</span>
</div>

<div class="filter-bar compact-filter-bar">
    <form method="GET" class="compact-filter-form">
        <?php if ($selectedCarId !== ''): ?><input type="hidden" name="car_id" value="<?= clean($selectedCarId) ?>"><?php endif; ?>
        <div><label class="form-label">Specific Day</label><input type="date" name="date" class="form-control" value="<?= clean($filterDate) ?>"></div>
        <div><label class="form-label">Search</label><input type="search" name="q" class="form-control" value="<?= clean($q) ?>" placeholder="Car, buyer, agent, RTO narration"></div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Apply</button>
        <a href="list.php<?= $clearFilterParams ? '?' . clean(http_build_query($clearFilterParams)) : '' ?>" class="btn btn-outline btn-sm">Clear</a>
        <a href="list.php?<?= clean(http_build_query($archiveFilterParams)) ?>" class="btn btn-outline btn-sm"><i class="ri-archive-line"></i> <?= $showDeleted ? 'Active Records' : 'Deleted Records' ?></a>
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
                    <option value="RECEIVE" <?= $rtoDraftValue('entry_mode', 'RECEIVE') === 'RECEIVE' ? 'selected' : '' ?>>RTO Money Received</option>
                    <option value="EXPENSE" <?= $rtoDraftValue('entry_mode') === 'EXPENSE' ? 'selected' : '' ?>>RTO Expense Paid</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date *</label>
                <input type="date" name="entry_date" class="form-control" value="<?= clean($rtoDraftValue('entry_date', date('Y-m-d'))) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Cash / Bank Account *</label>
                <select name="payment_account" class="form-control searchable-select" required>
                    <option value="">Select account</option>
                    <?php foreach ($paymentAccounts as $account): ?>
                        <option value="<?= clean($account['id']) ?>" <?= $rtoDraftValue('payment_account') === $account['id'] ? 'selected' : '' ?>><?= clean($account['name'] . ' (' . $account['code'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Amount (₹) *</label>
                <input name="amount" class="form-control currency-input" value="<?= clean($rtoDraftValue('amount')) ?>" placeholder="0" required>
            </div>
            <div class="form-group">
                <label class="form-label">Car *</label>
                <select name="car_id" class="form-control searchable-select" required>
                    <option value="">Select car</option>
                    <?php foreach ($cars as $car): ?>
                        <option value="<?= clean($car['id']) ?>" <?= $rtoDraftValue('car_id', $selectedCarId) === $car['id'] ? 'selected' : '' ?>>
                            <?= clean(formatRegistrationNo($car['registration_no']) . ' - ' . trim(($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">RTO Narration (Optional)</label>
                <input name="rto_type" class="form-control" value="<?= clean($rtoDraftValue('rto_type')) ?>" placeholder="Transfer, NOC, tax, passing">
            </div>
            <div class="form-group">
                <label class="form-label">Buyer / Customer</label>
                <input name="party_name" class="form-control" value="<?= clean($rtoDraftValue('party_name')) ?>" placeholder="Who gives RTO money">
            </div>
            <div class="form-group">
                <label class="form-label">Agent / Office</label>
                <input name="agent_name" class="form-control" value="<?= clean($rtoDraftValue('agent_name')) ?>" placeholder="Who receives expense payment">
            </div>
            <div class="form-group rto-span-2">
                <label class="form-label">Additional Note</label>
                <input name="narration" class="form-control" value="<?= clean($rtoDraftValue('narration')) ?>" placeholder="Optional file, receipt, or follow-up note">
            </div>
            <div class="form-group rto-span-2">
                <label class="form-label">Files / Vouchers</label>
                <input type="file" name="rto_docs[]" class="form-control" accept="<?= clean(attachmentAcceptAttribute('documents')) ?>" multiple>
                <div class="form-hint">Photos, PDF, Office documents, text/CSV, or archives. Maximum 10 MB each.</div>
            </div>
            <div class="form-group rto-actions"><button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save RTO Money</button></div>
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
                    <th>Car / RTO Narration</th>
                    <th>Buyer / Agent</th>
                    <th>Money Type</th>
                    <th class="text-right">Received</th>
                    <th class="text-right">Spent</th>
                    <th>Files</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="7" class="text-center text-muted empty-table-cell">No RTO money history found.</td></tr>
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
                            <a href="<?= clean($url) ?>" target="_blank" rel="noopener noreferrer" class="mini-pill mini-pill-neutral"><i class="ri-attachment-line"></i> Open</a>
                            <button type="button" class="mini-pill mini-pill-neutral" data-share-url="<?= clean($shareUrl) ?>" data-share-title="<?= clean($attachment['original_name']) ?>"><i class="ri-share-forward-line"></i> Share</button>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-center">
                        <a href="../reports/change_history.php?entity_type=rto_record&amp;entity_id=<?= clean($record['id']) ?>" class="btn btn-sm btn-outline" title="History"><i class="ri-history-line"></i></a>
                        <?php if ($record['status'] !== 'CANCELLED' && $canDeleteRto): ?><a href="../delete_record.php?entity_type=rto_record&amp;id=<?= clean($record['id']) ?>" class="btn btn-sm btn-outline text-red" title="Delete"><i class="ri-delete-bin-line"></i></a><?php endif; ?>
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
                    <th>Ref</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rtoEntries)): ?>
                    <tr><td colspan="5" class="text-center text-muted empty-table-cell">No RTO transactions posted yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rtoEntries as $entry): ?>
                    <tr>
                        <td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td>
                        <td>
                            <?php if (!empty($entry['car_id'])): ?>
                                <a href="../cars/view.php?id=<?= clean($entry['car_id']) ?>"><?= clean(formatRegistrationNo($entry['registration_no'])) ?></a>
                                <div class="text-muted"><?= clean(trim(($entry['make'] ?? '') . ' ' . ($entry['model'] ?? ''))) ?></div>
                            <?php else: ?>
                                <span class="text-bold"><?= $entry['transaction_type'] === 'RTO_RECOVERY' ? 'General RTO Recovery' : 'RTO Book' ?></span>
                                <div class="text-muted"><?= $entry['transaction_type'] === 'RTO_RECOVERY' ? 'No car linked' : 'Opening balance · no car linked' ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-blue"><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?></span></td>
                        <td><?= clean($entry['narration'] ?: '-') ?></td>
                        <td><a href="../transactions/view.php?id=<?= urlencode($entry['id']) ?>" class="text-bold"><?= clean($entry['reference_no']) ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
