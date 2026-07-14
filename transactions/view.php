<?php
$pageTitle = 'Transaction Detail';
$pageIcon = '<i class="ri-file-list-3-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
require_once __DIR__ . '/../includes/attachments.php';

$id = get('id');
$businessId = Auth::user('business_id');
$allowedBooks = array_merge(Auth::getPrimaryBookKeys(), ['jv_register']);
Auth::requireAnyBookAccess($allowedBooks, 'read');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

if (!Auth::canAccessTransactionEntry($id, $businessId, 'read')) {
    setFlash('error', 'You do not have access to that transaction.');
    redirect('list.php');
}
$canReverseEntry = Auth::canAccessTransactionEntry($id, $businessId, 'delete');
$canEditEntry = Auth::canAccessTransactionEntry($id, $businessId, 'write');

$entry = $db->fetch(
    "SELECT je.*, u.full_name as created_by_name, c.registration_no as car_reg, p.name as partner_name, e.name as employee_name,
            jv.reference_no as voucher_reference_no, previous.reference_no AS corrected_from_reference,
            replacement.reference_no AS corrected_by_reference, reversal.reference_no AS reversal_reference,
            original.reference_no AS original_reference
     FROM journal_entries je LEFT JOIN users u ON u.id = je.created_by
     LEFT JOIN cars c ON c.id = je.car_id LEFT JOIN partners p ON p.id = je.partner_id LEFT JOIN employees e ON e.id = je.employee_id
     LEFT JOIN journal_vouchers jv ON jv.id = je.journal_voucher_id
     LEFT JOIN journal_entries previous ON previous.id = je.corrected_from_id
     LEFT JOIN journal_entries replacement ON replacement.id = je.corrected_by_id
     LEFT JOIN journal_entries reversal ON reversal.id = je.reversed_by
     LEFT JOIN journal_entries original ON original.id = je.original_entry_id
     WHERE je.id = ? AND je.business_id = ?", [$id, $businessId]);

if (!$entry) { setFlash('error', 'Entry not found.'); redirect('list.php'); }

$voucherDetails = !empty($entry['journal_voucher_id']) ? $engine->getJournalVoucherDetails($entry['journal_voucher_id']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'upload_vouchers') {
    verifyCsrf();
    try {
        $count = uploadEntityAttachments($businessId, 'JOURNAL_ENTRY', $id, 'VOUCHER', 'vouchers', Auth::user('user_id'), 'vouchers');
        setFlash('success', $count > 0 ? "$count voucher uploaded." : 'No voucher selected.');
        redirect("view.php?id=$id");
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        redirect("view.php?id=$id");
    }
}

$lines = $db->fetchAll(
    "SELECT jl.*, a.name as account_name, a.code as account_code FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id WHERE jl.journal_entry_id = ? ORDER BY jl.entry_type DESC, jl.amount DESC", [$id]);
$vouchers = fetchEntityAttachments($businessId, 'JOURNAL_ENTRY', $id, 'VOUCHER');

$totalDr = $totalCr = 0;
foreach ($lines as $l) { if ($l['entry_type'] === 'DR') $totalDr += $l['amount']; else $totalCr += $l['amount']; }
?>

<div class="page-header">
    <h1><i class="ri-file-list-3-line"></i> <?= $entry['reference_no'] ?></h1>
    <div style="display: flex; gap: 10px;">
        <?php if ($entry['status'] === 'POSTED' && empty($entry['is_reversal']) && $canEditEntry): ?>
            <a href="edit.php?id=<?= $entry['id'] ?>" class="btn btn-primary btn-sm"><i class="ri-edit-line"></i> Edit</a>
        <?php endif; ?>
        <a href="../reports/change_history.php?entity_type=journal_entry&amp;entity_id=<?= urlencode($entry['id']) ?>" class="btn btn-outline btn-sm"><i class="ri-history-line"></i> History</a>
        <?php if ($entry['status'] === 'POSTED' && $canReverseEntry): ?>
            <a href="reverse.php?id=<?= $entry['id'] ?>" class="btn btn-danger btn-sm" data-confirm="Reverse this entry?"><i class="ri-arrow-go-back-line"></i> Reverse</a>
        <?php endif; ?>
        <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
        <a href="list.php" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<?php if (!empty($entry['corrected_from_id']) || !empty($entry['corrected_by_id']) || !empty($entry['original_entry_id'])): ?>
<div class="correction-chain-banner">
    <i class="ri-git-commit-line"></i>
    <div>
        <?php if (!empty($entry['corrected_from_id'])): ?>
            <strong>Corrected entry</strong><span>Replaces <a href="view.php?id=<?= urlencode($entry['corrected_from_id']) ?>"><?= clean($entry['corrected_from_reference'] ?: 'original entry') ?></a>. Version <?= intval($entry['version_no'] ?? 2) ?>.</span>
        <?php elseif (!empty($entry['corrected_by_id'])): ?>
            <strong>This version was corrected</strong><span>Current entry: <a href="view.php?id=<?= urlencode($entry['corrected_by_id']) ?>"><?= clean($entry['corrected_by_reference'] ?: 'view replacement') ?></a>.</span>
        <?php else: ?>
            <strong>Reversal entry</strong><span>Reverses <a href="view.php?id=<?= urlencode($entry['original_entry_id']) ?>"><?= clean($entry['original_reference'] ?: 'original entry') ?></a>.</span>
        <?php endif; ?>
        <?php if (!empty($entry['correction_reason'])): ?><span>Reason: <?= clean($entry['correction_reason']) ?></span><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($voucherDetails): ?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-header"><h3><i class="ri-bill-line"></i> Large Bill Summary</h3></div>
    <div class="card-body">
        <div class="stats-grid" style="grid-template-columns:repeat(4, minmax(0,1fr));">
            <div class="stat-card"><div class="stat-value amount <?= $voucherDetails['voucher']['primary_entry_type'] === 'CR' ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($voucherDetails['voucher']['primary_amount']) ?></div><div class="stat-label">Bill Total</div></div>
            <div class="stat-card"><div class="stat-value <?= $voucherDetails['voucher']['primary_entry_type'] === 'CR' ? 'flow-out' : 'flow-in' ?>"><?= clean($voucherDetails['voucher']['primary_entry_type'] === 'CR' ? 'Payment' : 'Receipt') ?></div><div class="stat-label">Bill Direction</div></div>
            <div class="stat-card"><div class="stat-value"><?= count($voucherDetails['lines']) ?></div><div class="stat-label">Split Lines</div></div>
            <div class="stat-card"><div class="stat-value"><?= clean($voucherDetails['voucher']['primary_account_name']) ?></div><div class="stat-label">Main Book</div></div>
        </div>
        <div class="table-container" style="margin-top:16px;">
            <table>
                <thead>
                    <tr><th>Split To</th><th>Type</th><th>Note</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($voucherDetails['lines'] as $allocation): ?>
                        <tr>
                            <td class="text-bold">
                                <?= !empty($allocation['car_reg']) ? clean(formatRegistrationNo($allocation['car_reg'])) . ' — ' : '' ?>
                                <?= clean($allocation['account_name']) ?>
                            </td>
                            <td class="text-muted"><?= clean($allocation['group_name'] . (!empty($allocation['sub_group']) ? ' / ' . $allocation['sub_group'] : '')) ?></td>
                            <td><?= clean($allocation['narration'] ?: '-') ?></td>
                            <td class="text-right amount <?= $voucherDetails['voucher']['primary_entry_type'] === 'CR' ? 'flow-out' : 'flow-in' ?>"><?= formatAmount($allocation['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Entry Details</h3></div>
        <div class="card-body">
            <table style="width: 100%;">
                <tr><td class="text-muted" style="padding: 8px 0; width: 40%;">Reference</td><td class="text-bold"><?= $entry['reference_no'] ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Date / Time</td><td><?= renderDateTimeStack($entry['entry_date'], $entry['created_at']) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Type</td><td><span class="badge badge-blue"><?= clean(transactionTypeLabel($entry['transaction_type'], $entry)) ?></span> <span class="transaction-context-chip <?= transactionFlowColorClass($entry['transaction_type']) ?>" style="margin-left:8px;display:inline-flex;"><?= clean(transactionBusinessFlowLabel($entry['transaction_type'])) ?></span></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Status</td><td><span class="badge <?= $entry['status'] === 'POSTED' ? 'badge-green' : 'badge-red' ?>"><?= $entry['status'] ?></span></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Narration</td><td><?= clean($entry['narration']) ?></td></tr>
	                <tr><td class="text-muted" style="padding: 8px 0;">Created By</td><td><?= clean($entry['created_by_name']) ?></td></tr>
	                <tr><td class="text-muted" style="padding: 8px 0;">Created At</td><td><?= renderDateTimeStack($entry['created_at'], $entry['created_at']) ?></td></tr>
	                <?php if (!empty($entry['journal_voucher_id'])): ?><tr><td class="text-muted" style="padding: 8px 0;">Voucher</td><td><a href="../reports/jv_register.php"><?= clean($entry['voucher_reference_no'] ?: $entry['journal_voucher_id']) ?></a></td></tr><?php endif; ?>
	                <?php if ($entry['car_reg']): ?><tr><td class="text-muted" style="padding: 8px 0;">Car</td><td><a href="../cars/view.php?id=<?= $entry['car_id'] ?>"><?= formatRegistrationNo($entry['car_reg']) ?></a></td></tr><?php endif; ?>
                <?php if ($entry['partner_name']): ?><tr><td class="text-muted" style="padding: 8px 0;">Partner</td><td><?= clean($entry['partner_name']) ?></td></tr><?php endif; ?>
                <?php if ($entry['employee_name']): ?><tr><td class="text-muted" style="padding: 8px 0;">Employee</td><td><?= clean($entry['employee_name']) ?></td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Journal Entry Lines</h3></div>
        <div class="card-body" style="padding: 0;">
            <table>
                <thead>
                    <tr><th>Account</th><th class="text-right debit-amount">Debit (₹)</th><th class="text-right credit-amount">Credit (₹)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line): ?>
                    <tr>
                        <td>
                            <div class="text-bold"><?= clean($line['account_name']) ?></div>
                            <div class="text-muted" style="font-size: 11px;"><?= $line['account_code'] ?><?= $line['narration'] ? ' — ' . clean($line['narration']) : '' ?></div>
                        </td>
                        <td class="text-right amount debit-amount"><?= $line['entry_type'] === 'DR' ? formatAmount($line['amount']) : '' ?></td>
                        <td class="text-right amount credit-amount"><?= $line['entry_type'] === 'CR' ? formatAmount($line['amount']) : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="text-right amount debit-amount"><?= formatAmount($totalDr) ?></td>
                        <td class="text-right amount credit-amount"><?= formatAmount($totalCr) ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php if (abs($totalDr - $totalCr) < 0.01): ?>
                <div style="padding: 10px 16px; text-align: center; font-size: 12px; color: var(--accent-green);">
                    <i class="ri-check-line"></i> Entry is balanced (Dr = Cr)
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-header"><h3><i class="ri-attachment-2"></i> Physical Vouchers</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="attachment-upload-panel">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_vouchers">
            <div class="form-group">
                <label class="form-label">Upload Bill / Voucher</label>
                <input type="file" name="vouchers[]" class="form-control" accept="image/*,application/pdf" multiple>
                <div class="form-hint">Upload bill photos or PDF. Open/share links work on mobile.</div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="ri-upload-cloud-2-line"></i> Upload Voucher</button>
        </form>

        <?php if (empty($vouchers)): ?>
            <div class="empty-state compact">No vouchers uploaded.</div>
        <?php else: ?>
            <div class="attachment-grid">
                <?php foreach ($vouchers as $attachment): ?>
                    <?php $url = attachmentUrl($attachment); $shareUrl = attachmentUrl($attachment, true); $isImage = str_starts_with($attachment['mime_type'], 'image/'); ?>
                    <div class="attachment-card">
                        <a href="<?= clean($url) ?>" target="_blank" rel="noopener" class="attachment-preview">
                            <?php if ($isImage): ?>
                                <img src="<?= clean($url) ?>" alt="<?= clean($attachment['original_name']) ?>">
                            <?php else: ?>
                                <div class="attachment-file-icon"><i class="ri-file-pdf-2-line"></i><span>PDF</span></div>
                            <?php endif; ?>
                        </a>
                        <div class="attachment-meta">
                            <strong><?= clean($attachment['original_name']) ?></strong>
                            <span><?= formatDate($attachment['created_at'], 'd M Y, h:i A') ?></span>
                        </div>
                        <div class="attachment-actions">
                            <a href="<?= clean($url) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i> Open</a>
                            <button type="button" class="btn btn-sm btn-outline" data-share-url="<?= clean($shareUrl) ?>" data-share-title="<?= clean($attachment['original_name']) ?>"><i class="ri-share-forward-line"></i> Share</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
