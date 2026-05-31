<?php
$pageTitle = 'JV Register';
$pageIcon = '<i class="ri-booklet-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('jv_register', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$dateFrom = get('from', date('Y-m-01'));
$dateTo = get('to', date('Y-m-d'));
$vouchers = $engine->getJournalVoucherRegister($dateFrom, $dateTo);
?>

<div class="page-header">
    <h1><i class="ri-booklet-line"></i> JV Register</h1>
    <div style="display:flex; gap:12px;">
        <a href="../transactions/new.php?type=JOURNAL_VOUCHER" class="btn btn-primary btn-sm"><i class="ri-add-circle-line"></i> New Split Entry</a>
        <button onclick="printPage()" class="btn btn-outline btn-sm"><i class="ri-printer-line"></i> Print</button>
    </div>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
        <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= clean($dateFrom) ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= clean($dateTo) ?>"></div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-filter-line"></i> Filter</button>
    </form>
</div>

<div class="table-container">
    <table>
        <thead><tr><th>JV Ref</th><th>Date</th><th>Type</th><th>Primary Account</th><th class="text-right">Amount</th><th>Status</th><th>Posted Ref</th><th>Narration</th></tr></thead>
        <tbody>
            <?php if (empty($vouchers)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding: 32px;">No journal vouchers found for this period.</td></tr>
            <?php else: ?>
                <?php foreach ($vouchers as $voucher): ?>
                    <tr>
                        <td class="text-bold"><?= clean($voucher['reference_no']) ?></td>
                        <td><?= formatDate($voucher['voucher_date']) ?></td>
                        <td><?= clean($voucher['voucher_type']) ?></td>
                        <td><?= clean($voucher['primary_account_name']) ?> <span class="text-muted">(<?= $voucher['primary_entry_type'] ?>)</span></td>
                        <td class="text-right amount"><?= formatAmount($voucher['primary_amount']) ?></td>
                        <td><span class="badge <?= $voucher['status'] === 'POSTED' ? 'badge-green' : 'badge-gray' ?>"><?= clean($voucher['status']) ?></span></td>
                        <td>
                            <?php if (!empty($voucher['posted_entry_id'])): ?>
                                <a href="../transactions/view.php?id=<?= $voucher['posted_entry_id'] ?>"><?= clean($voucher['posted_reference_no']) ?></a>
                            <?php else: ?>
                                <span class="text-muted">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td><?= clean(mb_substr($voucher['narration'] ?? '', 0, 80)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
