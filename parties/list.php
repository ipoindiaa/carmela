<?php
$pageTitle = 'Debtors & Creditors';
$pageIcon = '<i class="ri-contacts-book-line"></i>';
$isLazyRequest = ($_GET['lazy'] ?? '') === '1';
if ($isLazyRequest) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';
    Auth::check();
    $db = Database::getInstance();
} else {
    require_once __DIR__ . '/../includes/header.php';
}
require_once __DIR__ . '/../includes/accounting_engine.php';
$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'add') {
    verifyCsrf();
    try {
        $engine = new AccountingEngine($businessId, Auth::user('user_id'));
        $partyId = $engine->getOrCreateParty(post('name'), post('type'));
        $db->query("UPDATE debtors_creditors SET phone = ?, email = ?, address = ?, pan_gstin = ? WHERE id = ?",
            [post('phone'), post('email'), post('address'), post('pan_gstin'), $partyId]);
        setFlash('success', 'Party added!');
        redirect('list.php');
    } catch (Exception $e) { setFlash('error', $e->getMessage()); }
}

$page = max(1, intval(get('page', 1)));
$perPage = 30;

function partiesListUrl($page, $lazy = false) {
    $query = ['page' => $page];
    if ($lazy) $query['lazy'] = 1;
    return 'list.php?' . http_build_query($query);
}

function renderPartyRows($parties, $snapshots) {
    ob_start();
    ?>
    <?php foreach ($parties as $p): ?>
    <?php
        $snapshot = $snapshots[$p['id']] ?? null;
        $outstandingAmount = round(floatval($snapshot['amount'] ?? 0), 2);
        $outstandingLabel = $snapshot['label'] ?? 'Clear';
        $amountClass = $snapshot['class'] ?? 'text-muted';
    ?>
    <tr>
        <td class="text-bold"><?= clean($p['name']) ?></td>
        <td><span class="badge <?= in_array($p['type'], ['DEBTOR','BUYER']) ? 'badge-blue' : 'badge-yellow' ?>"><?= $p['type'] ?></span></td>
        <td><?= clean($p['phone'] ?: '-') ?></td>
        <td class="text-right">
            <div class="amount <?= $amountClass ?>"><?= formatAmount($outstandingAmount) ?></div>
            <div class="text-muted" style="font-size: 11px;"><?= clean($outstandingLabel) ?></div>
        </td>
        <td><?= $p['is_bad_debt'] ? '<span class="badge badge-red">Bad Debt</span>' : '-' ?></td>
        <td class="text-center"><a href="view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($parties)): ?><tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">No parties yet</td></tr><?php endif; ?>
    <?php
    return trim(ob_get_clean());
}

$total = $db->fetch("SELECT COUNT(*) as cnt FROM debtors_creditors WHERE business_id = ?", [$businessId]);
$pagination = paginate($total['cnt'], $perPage, $page);
$parties = $db->fetchAll(
    "SELECT dc.*, a.current_balance, a.current_balance_type
     FROM debtors_creditors dc
     LEFT JOIN accounts a ON a.id = dc.account_id
     WHERE dc.business_id = ?
     ORDER BY dc.type, dc.name
     LIMIT ? OFFSET ?",
    [$businessId, $perPage, $pagination['offset']]
);

$partySnapshots = [];
foreach ($parties as $party) {
    $openItems = $engine->getPartyOpenItems($party['id']);
    $openOutstanding = round(array_sum(array_column($openItems, 'outstanding_amount')), 2);
    $signedBalance = signedBalanceValue($party['current_balance'] ?? 0, $party['current_balance_type'] ?? 'DR');

    if (in_array($party['type'], ['DEBTOR', 'BUYER'], true)) {
        if ($openOutstanding > 0.009) {
            $partySnapshots[$party['id']] = ['amount' => $openOutstanding, 'label' => 'Receivable', 'class' => 'debit-amount'];
        } elseif ($signedBalance < -0.009) {
            $partySnapshots[$party['id']] = ['amount' => abs($signedBalance), 'label' => 'Advance / Overpaid', 'class' => 'credit-amount'];
        } else {
            $partySnapshots[$party['id']] = ['amount' => 0, 'label' => 'Clear', 'class' => 'text-muted'];
        }
    } else {
        if ($openOutstanding > 0.009) {
            $partySnapshots[$party['id']] = ['amount' => $openOutstanding, 'label' => 'Payable', 'class' => 'credit-amount'];
        } elseif ($signedBalance > 0.009) {
            $partySnapshots[$party['id']] = ['amount' => abs($signedBalance), 'label' => 'Advance Paid', 'class' => 'debit-amount'];
        } else {
            $partySnapshots[$party['id']] = ['amount' => 0, 'label' => 'Clear', 'class' => 'text-muted'];
        }
    }
}

if ($isLazyRequest) {
    header('Content-Type: application/json');
    $nextPage = $page < $pagination['total_pages'] ? $page + 1 : null;
    echo json_encode([
        'html' => renderPartyRows($parties, $partySnapshots),
        'next_url' => $nextPage ? partiesListUrl($nextPage, true) : '',
    ]);
    exit;
}

$nextUrl = $page < $pagination['total_pages'] ? partiesListUrl($page + 1, true) : '';
?>

<div class="page-header">
    <h1><i class="ri-contacts-book-line"></i> Debtors & Creditors</h1>
    <button onclick="openModal('add-party')" class="btn btn-primary"><i class="ri-add-line"></i> Add Party</button>
</div>

<div class="table-container table-container-fill" data-lazy-list data-next-url="<?= clean($nextUrl) ?>">
    <table>
        <thead><tr><th>Name</th><th>Type</th><th>Phone</th><th class="text-right">Balance</th><th>Bad Debt</th><th class="text-center">Actions</th></tr></thead>
        <tbody>
            <?= renderPartyRows($parties, $partySnapshots) ?>
        </tbody>
    </table>
    <?php if ($nextUrl): ?>
        <div class="lazy-list-footer" data-lazy-sentinel>
            <span data-lazy-status>More parties will load as you scroll.</span>
        </div>
    <?php endif; ?>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
<div class="pagination no-js-pagination">
    <?php if ($page > 1): ?><a href="<?= clean(partiesListUrl($page - 1)) ?>">← Prev</a><?php endif; ?>
    <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
        <a href="<?= clean(partiesListUrl($i)) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pagination['total_pages']): ?><a href="<?= clean(partiesListUrl($page + 1)) ?>">Next →</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="add-party">
    <div class="modal">
        <div class="modal-header"><h3>Add Party</h3><button class="modal-close" onclick="closeModal('add-party')">×</button></div>
        <div class="modal-body">
            <form method="POST">
                <?= csrfField() ?><input type="hidden" name="action" value="add">
                <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Type *</label><select name="type" class="form-control" required><option value="DEBTOR">Debtor</option><option value="CREDITOR">Creditor</option><option value="BUYER">Buyer</option><option value="SELLER">Seller</option></select></div>
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                    <div class="form-group"><label class="form-label">PAN / GSTIN</label><input type="text" name="pan_gstin" class="form-control"></div>
                </div>
                <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ri-save-line"></i> Add Party</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
