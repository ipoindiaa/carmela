<?php
$pageTitle = 'Transaction Detail';
$pageIcon = '<i class="ri-file-list-3-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

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

$entry = $db->fetch(
    "SELECT je.*, u.full_name as created_by_name, c.registration_no as car_reg, p.name as partner_name, e.name as employee_name,
            jv.reference_no as voucher_reference_no
     FROM journal_entries je LEFT JOIN users u ON u.id = je.created_by
     LEFT JOIN cars c ON c.id = je.car_id LEFT JOIN partners p ON p.id = je.partner_id LEFT JOIN employees e ON e.id = je.employee_id
     LEFT JOIN journal_vouchers jv ON jv.id = je.journal_voucher_id
     WHERE je.id = ? AND je.business_id = ?", [$id, $businessId]);

if (!$entry) { setFlash('error', 'Entry not found.'); redirect('list.php'); }

$lines = $db->fetchAll(
    "SELECT jl.*, a.name as account_name, a.code as account_code FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id WHERE jl.journal_entry_id = ? ORDER BY jl.entry_type DESC, jl.amount DESC", [$id]);

$totalDr = $totalCr = 0;
foreach ($lines as $l) { if ($l['entry_type'] === 'DR') $totalDr += $l['amount']; else $totalCr += $l['amount']; }
?>

<div class="page-header">
    <h1><i class="ri-file-list-3-line"></i> <?= $entry['reference_no'] ?></h1>
    <div style="display: flex; gap: 10px;">
        <?php if ($entry['status'] === 'POSTED' && $canReverseEntry): ?>
            <a href="reverse.php?id=<?= $entry['id'] ?>" class="btn btn-danger btn-sm" data-confirm="Reverse this entry?"><i class="ri-arrow-go-back-line"></i> Reverse</a>
        <?php endif; ?>
        <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
        <a href="list.php" class="btn btn-outline btn-sm" data-smart-back="1"><i class="ri-arrow-left-line"></i> Back</a>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Entry Details</h3></div>
        <div class="card-body">
            <table style="width: 100%;">
                <tr><td class="text-muted" style="padding: 8px 0; width: 40%;">Reference</td><td class="text-bold"><?= $entry['reference_no'] ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Date</td><td><?= formatDate($entry['entry_date']) ?></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Type</td><td><span class="badge badge-blue"><?= TXN_TYPES[$entry['transaction_type']] ?? $entry['transaction_type'] ?></span></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Status</td><td><span class="badge <?= $entry['status'] === 'POSTED' ? 'badge-green' : 'badge-red' ?>"><?= $entry['status'] ?></span></td></tr>
                <tr><td class="text-muted" style="padding: 8px 0;">Narration</td><td><?= clean($entry['narration']) ?></td></tr>
	                <tr><td class="text-muted" style="padding: 8px 0;">Created By</td><td><?= clean($entry['created_by_name']) ?></td></tr>
	                <tr><td class="text-muted" style="padding: 8px 0;">Created At</td><td><?= formatDate($entry['created_at'], 'd M Y, h:i A') ?></td></tr>
	                <?php if (!empty($entry['journal_voucher_id'])): ?><tr><td class="text-muted" style="padding: 8px 0;">Voucher</td><td><a href="../reports/jv_register.php"><?= clean($entry['voucher_reference_no'] ?: $entry['journal_voucher_id']) ?></a></td></tr><?php endif; ?>
	                <?php if ($entry['car_reg']): ?><tr><td class="text-muted" style="padding: 8px 0;">Car</td><td><a href="../cars/view.php?id=<?= $entry['car_id'] ?>"><?= $entry['car_reg'] ?></a></td></tr><?php endif; ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
