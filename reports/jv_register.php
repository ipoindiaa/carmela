<?php
$pageTitle = 'Large Bill Register';
$pageIcon = '<i class="ri-booklet-line"></i>';
require_once __DIR__ . '/../includes/header.php';
Auth::requireBookAccess('jv_register', 'read');
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$accessibleAccountIds = Auth::getAccessiblePrimaryAccountIds($businessId, 'read');
$dateFrom = get('from', date('Y-m-01'));
$dateTo = get('to', date('Y-m-d'));
$vouchers = $engine->getJournalVoucherRegister($dateFrom, $dateTo, $accessibleAccountIds);
$canViewLedger = Auth::hasBookAccess('general_ledger', 'read');
?>

<div class="page-header">
    <h1><i class="ri-booklet-line"></i> Large Bill Register</h1>
    <div style="display:flex; gap:12px;">
        <a href="../transactions/new.php?type=JOURNAL_VOUCHER" class="btn btn-primary btn-sm"><i class="ri-add-circle-line"></i> New Large Bill Split</a>
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

<div class="table-container table-container-fill">
    <table>
        <thead><tr><th>Bill Ref</th><th>Date / Time</th><th>Bill Type</th><th>Main Book</th><th>Car Allocations</th><th class="text-right">Amount</th><th>Status</th><th>Daily Entry</th><th>Narration</th></tr></thead>
        <tbody>
            <?php if (empty($vouchers)): ?>
                <tr><td colspan="9" class="text-center text-muted" style="padding: 32px;">No large bills found for this period.</td></tr>
            <?php else: ?>
                <?php foreach ($vouchers as $voucher): ?>
                    <tr>
                        <td class="text-bold">
                            <?php if (!empty($voucher['posted_entry_id'])): ?>
                                <a href="../transactions/view.php?id=<?= urlencode($voucher['posted_entry_id']) ?>"><?= clean($voucher['reference_no']) ?></a>
                            <?php else: ?>
                                <?= clean($voucher['reference_no']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= renderDateTimeStack($voucher['voucher_date'], $voucher['created_at'] ?? null) ?></td>
                        <td><?= clean($voucher['voucher_type']) ?></td>
                        <td><?php if ($canViewLedger): ?><a class="text-bold" href="<?= clean(accountLedgerUrl($voucher['primary_account_id'], $dateFrom, $dateTo)) ?>"><?= clean($voucher['primary_account_name']) ?></a><?php else: ?><?= clean($voucher['primary_account_name']) ?><?php endif; ?> <span class="dr-cr-pill <?= $voucher['primary_entry_type'] === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= $voucher['primary_entry_type'] ?></span></td>
                        <td style="min-width:210px;">
                            <?php if (empty($voucher['car_allocations'])): ?>
                                <span class="text-muted">No car allocation</span>
                            <?php else: ?>
                                <?php foreach (explode('|||', $voucher['car_allocations']) as $allocationString):
                                    $allocationParts = explode(':::', $allocationString);
                                    if (count($allocationParts) < 3) continue;
                                ?>
                                    <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:4px;">
                                        <a href="../cars/view.php?id=<?= urlencode($allocationParts[0]) ?>"><?= clean(formatRegistrationNo($allocationParts[1])) ?></a>
                                        <span class="amount"><?= formatAmount($allocationParts[2]) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-right amount <?= $voucher['primary_entry_type'] === 'DR' ? 'debit-amount' : 'credit-amount' ?>"><?= formatAmount($voucher['primary_amount']) ?></td>
                        <td><span class="badge <?= $voucher['status'] === 'POSTED' ? 'badge-green' : ($voucher['status'] === 'REVERSED' ? 'badge-red' : 'badge-gray') ?>"><?= clean($voucher['status']) ?></span></td>
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
