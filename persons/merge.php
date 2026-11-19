<?php
$pageTitle = 'Merge Persons';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';
if (!Auth::isAdmin()) { http_response_code(403); exit('Administrator access required.'); }
new AccountingEngine(Auth::user('business_id'), Auth::user('user_id'));
$people = new PersonIdentity(Auth::user('business_id'));
$leftKind = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('left_kind') : get('kind');
$leftId = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('left_id') : get('id');
$rightKind = post('right_kind');
$rightId = post('right_id');
$members = null;
$error = '';
$chosenName = post('chosen_name');
try {
    $leftMembers = $people->members($leftKind, $leftId);
    foreach ($leftMembers as $member) Auth::requireEntityAccess($member['kind'], 'write');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $members = $people->preview($leftKind, $leftId, $rightKind, $rightId);
        foreach ($members as $member) Auth::requireEntityAccess($member['kind'], 'write');
        if (post('action') === 'merge') {
            if (post('confirm') !== '1') throw new Exception('Confirm these records represent the same person or legal entity.');
            $people->merge($leftKind, $leftId, $rightKind, $rightId, $chosenName, post('fingerprint'));
            setFlash('success', 'Persons merged. All roles now use the chosen name; each ledger and its history remain intact.');
            redirect('view.php?' . http_build_query(['kind' => $leftKind, 'id' => $leftId]));
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $members = null;
}
?>
<div class="page-header"><h1>Merge Persons</h1></div>
<?php if ($error): ?><div class="alert alert-danger"><?= clean($error) ?></div><?php endif; ?>
<div class="card"><div class="card-body">
<p>Merge only when both records are the same person or legal entity. Capital, profit, debtor and creditor accounts remain separate. This does not offset balances or change any financial entry.</p>
<p>Contact details remain with their original role. All previous names remain searchable. If several accounts have the same role, the combined profile lists each account separately for settlement.</p>
<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="left_kind" value="<?= clean($leftKind) ?>">
    <input type="hidden" name="left_id" value="<?= clean($leftId) ?>">
    <?php if (!$members): ?>
        <p><strong>Starting record:</strong> <?= clean($leftMembers[0]['name'] ?? '') ?></p>
        <div class="form-group"><label class="form-label" for="merge-search">Find the other record</label>
            <input id="merge-search" type="search" class="form-control" autocomplete="off" placeholder="Type at least 2 letters">
            <div id="merge-results" aria-live="polite"></div>
        </div>
        <input type="hidden" name="right_kind" id="merge-kind" required>
        <input type="hidden" name="right_id" id="merge-id" required>
        <p id="merge-selected"></p>
        <button name="action" value="preview" class="btn btn-primary">Preview Merge</button>
    <?php else: ?>
        <input type="hidden" name="right_kind" value="<?= clean($rightKind) ?>">
        <input type="hidden" name="right_id" value="<?= clean($rightId) ?>">
        <input type="hidden" name="fingerprint" value="<?= clean($people->fingerprint($members)) ?>">
        <h3>Records that will belong to one person</h3>
        <div class="table-container"><table data-static-table="1"><thead><tr><th>Current Name</th><th>Role</th><th>Phone</th><th>Account Balances</th></tr></thead><tbody>
        <?php foreach ($members as $member): ?>
            <tr><td><?= clean($member['name']) ?></td><td><?= clean($member['role']) ?></td><td><?= clean($member['phone'] ?: '—') ?></td><td>
            <?php foreach (array_filter([$member['account_id'] ?? null, $member['capital_account_id'] ?? null, $member['current_account_id'] ?? null]) as $accountId):
                $account = $db->fetch("SELECT name, current_balance, current_balance_type FROM accounts WHERE id = ? AND business_id = ?", [$accountId, Auth::user('business_id')]); ?>
                <div><?= clean($account['name']) ?>: <?= formatAmount($account['current_balance']) ?> <?= clean($account['current_balance_type']) ?></div>
            <?php endforeach; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="form-group"><label class="form-label" for="chosen-name">Name to use everywhere</label>
            <input id="chosen-name" name="chosen_name" class="form-control" maxlength="180" value="<?= clean($chosenName ?: $members[0]['name']) ?>" required>
        </div>
        <label class="check-row"><input type="checkbox" name="confirm" value="1" required> These records represent the same person or legal entity, and I have checked the roles and balances.</label>
        <button name="action" value="merge" class="btn btn-primary">Confirm Merge</button>
    <?php endif; ?>
    <a class="btn btn-outline" href="view.php?<?= clean(http_build_query(['kind' => $leftKind, 'id' => $leftId])) ?>">Cancel</a>
</form>
</div></div>
<?php if (!$members): ?>
<script>
(() => {
    const input = document.getElementById('merge-search');
    const results = document.getElementById('merge-results');
    let timer, controller;
    input.addEventListener('input', () => {
        clearTimeout(timer); controller?.abort(); results.replaceChildren();
        document.getElementById('merge-id').value = '';
        document.getElementById('merge-kind').value = '';
        document.getElementById('merge-selected').textContent = '';
        const query = input.value.trim();
        if (query.length < 2) return;
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const response = await fetch('search.php?q=' + encodeURIComponent(query), {signal: controller.signal});
                if (!response.ok) throw new Error('Search unavailable');
                const rows = await response.json();
                if (input.value.trim() !== query) return;
                if (!rows.length) results.textContent = 'No matching records.';
                rows.forEach(row => {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'btn btn-outline btn-sm';
                    button.textContent = row.name + ' · ' + row.roles.join(', ') + (row.phone ? ' · ' + row.phone : '');
                    button.addEventListener('click', () => {
                        document.getElementById('merge-kind').value = row.kind;
                        document.getElementById('merge-id').value = row.id;
                        document.getElementById('merge-selected').textContent = 'Selected: ' + button.textContent;
                        results.replaceChildren();
                    });
                    results.append(button);
                });
            } catch (error) { if (error.name !== 'AbortError') results.textContent = 'Search unavailable. Please try again.'; }
        }, 250);
    });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
