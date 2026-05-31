<?php
$pageTitle = 'JV Composer';
$pageIcon = '<i class="ri-file-edit-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
Auth::requireBookAccess('jv_register', 'write');

$accounts = $db->fetchAll(
    "SELECT id, code, name, group_name, entity_type
     FROM accounts
     WHERE business_id = ? AND is_active = 1
     ORDER BY group_name, code, name",
    [$businessId]
);
$drafts = $db->fetchAll(
    "SELECT jv.*, a.name as primary_account_name
     FROM journal_vouchers jv
     JOIN accounts a ON a.id = jv.primary_account_id
     WHERE jv.business_id = ? AND jv.status = 'DRAFT'
     ORDER BY jv.voucher_date DESC, jv.created_at DESC
     LIMIT 20",
    [$businessId]
);

function buildVoucherAllocationsFromPost() {
    $rows = [];
    $accountIds = $_POST['allocation_account_id'] ?? [];
    $amounts = $_POST['allocation_amount'] ?? [];
    $narrations = $_POST['allocation_narration'] ?? [];
    foreach ($accountIds as $idx => $accountId) {
        $amount = floatval($amounts[$idx] ?? 0);
        if (!$accountId || $amount <= 0) {
            continue;
        }
        $rows[] = [
            'account_id' => $accountId,
            'amount' => $amount,
            'narration' => $narrations[$idx] ?? null,
        ];
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $action = post('action');

        if ($action === 'save' || $action === 'post') {
            $primaryAccountId = post('primary_account_id');
            $primaryEntryType = post('primary_entry_type');
            $primaryAmount = floatval(post('primary_amount'));
            $voucherType = post('voucher_type', 'GENERAL_JV');
            $voucherDate = post('voucher_date');
            $narration = post('narration');
            $allocations = buildVoucherAllocationsFromPost();

            $primaryAccount = $db->fetch(
                "SELECT id, entity_type FROM accounts WHERE id = ? AND business_id = ?",
                [$primaryAccountId, $businessId]
            );
            if (!$primaryAccount) {
                throw new Exception('Primary account not found.');
            }

            $bookKey = Auth::getBookKeyForAccountType($primaryAccount['entity_type']);
            if ($bookKey && !Auth::hasBookAccess($bookKey, 'write')) {
                throw new Exception('You do not have write access to the selected operational book.');
            }

            $voucherId = $engine->saveJournalVoucher(
                $voucherDate,
                $narration,
                $primaryAccountId,
                $primaryEntryType,
                $primaryAmount,
                $allocations,
                $voucherType,
                $action === 'post' ? 'POSTED' : 'DRAFT'
            );

            setFlash('success', $action === 'post' ? 'Journal voucher posted successfully.' : 'Journal voucher saved as draft.');
            redirect('jv.php' . ($action === 'post' ? '' : '?draft=' . $voucherId));
        }

        if ($action === 'post_existing') {
            $voucherId = post('voucher_id');
            $engine->postJournalVoucher($voucherId);
            setFlash('success', 'Draft voucher posted successfully.');
            redirect('jv.php');
        }

        throw new Exception('Invalid JV action.');
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}
?>

<div class="page-header">
    <h1><i class="ri-file-edit-line"></i> JV Composer</h1>
    <div style="display:flex; gap:12px;">
        <a href="new.php" class="btn btn-outline"><i class="ri-arrow-left-line"></i> Simple Entry</a>
        <a href="../reports/jv_register.php" class="btn btn-outline"><i class="ri-booklet-line"></i> JV Register</a>
    </div>
</div>

<div class="card" style="margin-bottom: 24px;">
    <div class="card-body">
        <div class="text-muted" style="font-size: 13px;">
            Use one primary side and split the opposite side across as many accounts as needed. This is ideal for garage bills, auction settlements, common expense allocation, and mixed funding entries.
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" id="jv-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="jv-action" value="save">

            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Voucher Type *</label>
                    <select name="voucher_type" class="form-control">
                        <option value="GENERAL_JV">General JV</option>
                        <option value="GARAGE_SPLIT">Garage Bill Split</option>
                        <option value="AUCTION_PURCHASE">Auction / Mela Purchase</option>
                        <option value="COMMON_EXPENSE">Common Expense Allocation</option>
                        <option value="MIXED_FUNDING">Mixed Funding Entry</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Voucher Date *</label>
                    <input type="date" name="voucher_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Primary Side *</label>
                    <select name="primary_entry_type" class="form-control">
                        <option value="DR">Debit</option>
                        <option value="CR">Credit</option>
                    </select>
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Primary Account *</label>
                    <select name="primary_account_id" class="form-control" required>
                        <option value="">-- Select Account --</option>
                        <?php $lastGroup = ''; foreach ($accounts as $account): ?>
                            <?php if ($account['group_name'] !== $lastGroup): ?>
                                <?php if ($lastGroup !== ''): ?></optgroup><?php endif; ?>
                                <optgroup label="<?= clean(ACCOUNT_GROUPS[$account['group_name']] ?? $account['group_name']) ?>">
                                <?php $lastGroup = $account['group_name']; ?>
                            <?php endif; ?>
                            <option value="<?= $account['id'] ?>"><?= clean($account['code'] . ' - ' . $account['name']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($lastGroup !== ''): ?></optgroup><?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Primary Amount *</label>
                    <input type="number" name="primary_amount" id="primary-amount" class="form-control amount-input" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Remaining to Allocate</label>
                    <div class="jv-balance-pill" id="jv-remaining">₹0.00</div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Narration *</label>
                <input type="text" name="narration" class="form-control" placeholder="What is this voucher for?" required>
            </div>

            <h3 style="margin: 24px 0 12px;">Allocation Lines</h3>
            <div id="allocation-rows">
                <div class="form-row-3 allocation-row">
                    <div class="form-group">
                        <label class="form-label">Account</label>
                        <select name="allocation_account_id[]" class="form-control">
                            <option value="">-- Select Account --</option>
                            <?php $lastGroup = ''; foreach ($accounts as $account): ?>
                                <?php if ($account['group_name'] !== $lastGroup): ?>
                                    <?php if ($lastGroup !== ''): ?></optgroup><?php endif; ?>
                                    <optgroup label="<?= clean(ACCOUNT_GROUPS[$account['group_name']] ?? $account['group_name']) ?>">
                                    <?php $lastGroup = $account['group_name']; ?>
                                <?php endif; ?>
                                <option value="<?= $account['id'] ?>"><?= clean($account['code'] . ' - ' . $account['name']) ?></option>
                            <?php endforeach; ?>
                            <?php if ($lastGroup !== ''): ?></optgroup><?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount</label>
                        <input type="number" name="allocation_amount[]" class="form-control amount-input allocation-amount" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Line Narration</label>
                        <input type="text" name="allocation_narration[]" class="form-control" placeholder="Optional note">
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:12px; margin-top: 12px;">
                <button type="button" class="btn btn-outline" id="add-allocation-row"><i class="ri-add-line"></i> Add Allocation Line</button>
                <button type="button" class="btn btn-outline" id="remove-allocation-row"><i class="ri-subtract-line"></i> Remove Last Line</button>
            </div>

            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border); display:flex; gap:12px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-outline btn-lg" onclick="document.getElementById('jv-action').value='save'">
                    <i class="ri-draft-line"></i> Save Draft
                </button>
                <button type="submit" class="btn btn-primary btn-lg" onclick="document.getElementById('jv-action').value='post'">
                    <i class="ri-check-double-line"></i> Post Voucher
                </button>
                <a href="list.php" class="btn btn-outline btn-lg">Cancel</a>
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3><i class="ri-draft-line"></i> Draft Journal Vouchers</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <table>
            <thead>
                <tr>
                    <th>Ref</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Primary Account</th>
                    <th class="text-right">Amount</th>
                    <th>Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($drafts)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding: 32px;">No draft vouchers yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($drafts as $draft): ?>
                        <tr>
                            <td class="text-bold"><?= clean($draft['reference_no']) ?></td>
                            <td><?= formatDate($draft['voucher_date']) ?></td>
                            <td><?= clean($draft['voucher_type']) ?></td>
                            <td><?= clean($draft['primary_account_name']) ?></td>
                            <td class="text-right amount"><?= formatAmount($draft['primary_amount']) ?></td>
                            <td><span class="badge badge-gray"><?= clean($draft['status']) ?></span></td>
                            <td class="text-center">
                                <form method="POST" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="post_existing">
                                    <input type="hidden" name="voucher_id" value="<?= $draft['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline"><i class="ri-check-line"></i> Post</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const allocationContainer = document.getElementById('allocation-rows');
const addAllocationButton = document.getElementById('add-allocation-row');
const removeAllocationButton = document.getElementById('remove-allocation-row');
const primaryAmountInput = document.getElementById('primary-amount');
const remainingNode = document.getElementById('jv-remaining');

function formatVoucherINR(num) {
    const value = parseFloat(num || '0');
    if (Number.isNaN(value)) {
        return '₹0.00';
    }
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(value);
}

function calculateRemainingAllocation() {
    const primaryAmount = parseFloat(primaryAmountInput?.value || '0');
    const allocated = Array.from(document.querySelectorAll('.allocation-amount')).reduce((sum, input) => {
        return sum + parseFloat(input.value || '0');
    }, 0);
    const remaining = primaryAmount - allocated;
    remainingNode.textContent = formatVoucherINR(remaining);
    remainingNode.classList.toggle('is-balanced', Math.abs(remaining) < 0.01 && primaryAmount > 0);
    remainingNode.classList.toggle('is-warning', Math.abs(remaining) >= 0.01);
}

function bindAllocationInputs(row) {
    row.querySelectorAll('.allocation-amount').forEach((input) => {
        input.addEventListener('input', calculateRemainingAllocation);
    });
}

addAllocationButton?.addEventListener('click', () => {
    const baseRow = allocationContainer.querySelector('.allocation-row');
    if (!baseRow) return;
    const clone = baseRow.cloneNode(true);
    clone.querySelectorAll('input').forEach((input) => input.value = '');
    clone.querySelectorAll('select').forEach((select) => select.selectedIndex = 0);
    allocationContainer.appendChild(clone);
    bindAllocationInputs(clone);
    calculateRemainingAllocation();
});

removeAllocationButton?.addEventListener('click', () => {
    const rows = allocationContainer.querySelectorAll('.allocation-row');
    if (rows.length > 1) {
        rows[rows.length - 1].remove();
        calculateRemainingAllocation();
    }
});

primaryAmountInput?.addEventListener('input', calculateRemainingAllocation);
document.querySelectorAll('.allocation-row').forEach(bindAllocationInputs);
calculateRemainingAllocation();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
