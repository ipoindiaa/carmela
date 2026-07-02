<?php
$requestedType = strtoupper(trim((string) ($_GET['type'] ?? '')));
if (!in_array($requestedType, ['MAIN', 'CARWISE'], true)) {
    $requestedType = '';
}
$pageTitle = $requestedType === 'CARWISE' ? 'Car-wise Partners' : ($requestedType === 'MAIN' ? 'Main Partners' : 'Partners');
$pageIcon = '<i class="ri-group-line"></i>';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/accounting_engine.php';

$businessId = Auth::user('business_id');
$engine = new AccountingEngine($businessId, Auth::user('user_id'));
$search = trim((string) get('q', ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'add') {
    verifyCsrf();
    try {
        $partnerId = Database::uuid();
        $name = trim((string) post('name'));
        $partnerType = strtoupper((string) post('partner_type', 'MAIN'));
        if (!in_array($partnerType, ['MAIN', 'CARWISE'], true)) {
            throw new Exception('Invalid partner type.');
        }
        $phone = validatePhoneNumber(post('phone'), 'Phone number');
        $email = validateEmailAddress(post('email'), 'Email');
        $capitalAccId = $engine->createAccount('CAP-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 8)), "$name - Capital A/c", 'EQUITY', 'Capital Accounts', 'PARTNER', $partnerId);
        $currentAccId = $engine->createAccount('CUR-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 8)), "$name - Current A/c", 'LIABILITY', 'Current Liabilities', 'PARTNER', $partnerId);

        $db->insert('partners', [
            'id' => $partnerId, 'business_id' => $businessId, 'name' => $name,
            'partner_type' => $partnerType,
            'phone' => $phone, 'email' => $email, 'pan' => post('pan'),
            'profit_share_pct' => floatval(post('profit_share_pct', 0)),
            'capital_account_id' => $capitalAccId, 'current_account_id' => $currentAccId,
            'joined_date' => post('joined_date'),
        ]);
        setFlash('success', "Partner $name added successfully!");
        redirect('list.php' . ($partnerType ? '?type=' . urlencode($partnerType) : ''));
    } catch (Exception $e) { setFlash('error', $e->getMessage()); }
}

$partnerWhere = "WHERE p.business_id = ?";
$partnerParams = [$businessId];
if ($requestedType !== '') {
    $partnerWhere .= " AND p.partner_type = ?";
    $partnerParams[] = $requestedType;
}
if ($search !== '') {
    $partnerWhere .= " AND (
        p.name LIKE ?
        OR p.phone LIKE ?
        OR p.email LIKE ?
        OR p.pan LIKE ?
        OR CASE WHEN p.partner_type = 'MAIN' THEN 'Main' ELSE 'Car-wise' END LIKE ?
        OR p.profit_share_pct LIKE ?
        OR p.joined_date LIKE ?
        OR CASE WHEN p.is_active = 1 THEN 'Active' ELSE 'Inactive' END LIKE ?
    )";
    $needle = '%' . $search . '%';
    array_push($partnerParams, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
}
$partners = $db->fetchAll(
    "SELECT p.*, a.current_balance as capital_balance, a.current_balance_type
     FROM partners p LEFT JOIN accounts a ON a.id = p.capital_account_id
     $partnerWhere
     ORDER BY p.partner_type, p.created_at DESC, p.name",
    $partnerParams
);
$mainPartners = array_values(array_filter($partners, static fn($partner) => ($partner['partner_type'] ?? 'MAIN') === 'MAIN'));
$carWisePartners = array_values(array_filter($partners, static fn($partner) => ($partner['partner_type'] ?? 'MAIN') === 'CARWISE'));
$pageHeading = $requestedType === 'CARWISE' ? 'Car-wise Partners' : ($requestedType === 'MAIN' ? 'Main Partners' : 'Partners');
$pageDescription = $requestedType === 'CARWISE'
    ? 'Deal-specific partners for individual cars and changing profit percentages.'
    : ($requestedType === 'MAIN'
        ? 'Core business partners for capital, withdrawals, and overall business funding.'
        : 'Manage both main business partners and car-wise deal partners.');
?>

<div class="page-header">
    <div>
        <h1><i class="ri-group-line"></i> <?= clean($pageHeading) ?></h1>
        <div class="text-muted" style="margin-top:4px;"><?= clean($pageDescription) ?></div>
    </div>
    <button onclick="openModal('add-partner')" class="btn btn-primary"><i class="ri-add-line"></i> Add Partner</button>
</div>

<div class="filter-bar">
    <form method="GET">
        <?php if ($requestedType !== ''): ?><input type="hidden" name="type" value="<?= clean($requestedType) ?>"><?php endif; ?>
        <div class="filter-main-field filter-main-field-wide">
            <label class="form-label">Search partner</label>
            <input type="search" name="q" class="form-control" value="<?= clean($search) ?>" placeholder="Name, phone, email, PAN, share, date, or status">
        </div>
        <button type="submit" class="btn btn-outline btn-sm"><i class="ri-search-line"></i> Search</button>
        <a href="list.php<?= $requestedType !== '' ? '?type=' . urlencode($requestedType) : '' ?>" class="btn btn-outline btn-sm">Clear</a>
    </form>
</div>

<?php if ($requestedType === ''): ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
        <div class="stat-card"><div class="stat-value text-blue"><?= count($mainPartners) ?></div><div class="stat-label">Main Partners</div></div>
        <div class="stat-card"><div class="stat-value text-purple"><?= count($carWisePartners) ?></div><div class="stat-label">Car-wise Partners</div></div>
    </div>
</div>
<?php endif; ?>

<?php if ($requestedType !== 'CARWISE'): ?>
<div class="table-container table-container-fill" style="margin-bottom:20px;">
    <div style="padding:16px 16px 0;font-weight:700;">Main Partners</div>
    <table>
        <thead><tr><th>Name</th><th>Phone</th><th>PAN</th><th>Default Share</th><th class="text-right">Capital Balance</th><th>Joined / Time</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr></thead>
        <tbody>
            <?php if (empty($mainPartners)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding: 40px;">No main partners yet</td></tr>
            <?php else: ?>
                <?php foreach ($mainPartners as $p): ?>
                <tr>
                    <td class="text-bold"><?= clean($p['name']) ?></td>
                    <td><?= clean($p['phone'] ?: '-') ?></td>
                    <td><?= clean($p['pan'] ?: '-') ?></td>
                    <td><span class="badge badge-purple"><?= $p['profit_share_pct'] ?>%</span></td>
                    <td class="text-right amount <?= signedAmountColorClass($p['capital_balance'] ?? 0, 'in') ?>"><?= formatAmount($p['capital_balance'] ?? 0) ?></td>
                    <td><?= renderDateTimeStack($p['joined_date'], $p['created_at']) ?></td>
                    <td class="text-center"><span class="badge <?= $p['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-center"><a href="view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($requestedType !== 'MAIN'): ?>
<div class="table-container table-container-fill">
    <div style="padding:16px 16px 0;font-weight:700;">Car-wise Partners</div>
    <table>
        <thead><tr><th>Name</th><th>Phone</th><th>PAN</th><th>Default Share</th><th class="text-right">Capital Balance</th><th>Joined / Time</th><th class="text-center">Status</th><th class="text-center">Actions</th></tr></thead>
        <tbody>
            <?php if (empty($carWisePartners)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding: 40px;">No car-wise partners yet</td></tr>
            <?php else: ?>
                <?php foreach ($carWisePartners as $p): ?>
                <tr>
                    <td class="text-bold"><?= clean($p['name']) ?></td>
                    <td><?= clean($p['phone'] ?: '-') ?></td>
                    <td><?= clean($p['pan'] ?: '-') ?></td>
                    <td><span class="badge badge-purple"><?= $p['profit_share_pct'] ?>%</span></td>
                    <td class="text-right amount <?= signedAmountColorClass($p['capital_balance'] ?? 0, 'in') ?>"><?= formatAmount($p['capital_balance'] ?? 0) ?></td>
                    <td><?= renderDateTimeStack($p['joined_date'], $p['created_at']) ?></td>
                    <td class="text-center"><span class="badge <?= $p['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-center"><a href="view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline"><i class="ri-eye-line"></i></a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Add Partner Modal -->
<div class="modal-overlay" id="add-partner">
    <div class="modal">
        <div class="modal-header"><h3>Add Partner</h3><button class="modal-close" onclick="closeModal('add-partner')">×</button></div>
        <div class="modal-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label class="form-label">Partner Type *</label>
                    <select name="partner_type" class="form-control" required>
                        <option value="MAIN" <?= $requestedType === 'MAIN' ? 'selected' : '' ?>>Main Partner</option>
                        <option value="CARWISE" <?= $requestedType === 'CARWISE' ? 'selected' : '' ?>>Car-wise Partner</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="10 digit phone"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" placeholder="name@example.com"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">PAN</label><input type="text" name="pan" class="form-control" maxlength="10"></div>
                    <div class="form-group"><label class="form-label">Default Profit Share %</label><input type="number" name="profit_share_pct" class="form-control" value="0" step="0.01" min="0" max="100"></div>
                </div>
                <div class="form-group"><label class="form-label">Joined Date *</label><input type="date" name="joined_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ri-save-line"></i> Add Partner</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
