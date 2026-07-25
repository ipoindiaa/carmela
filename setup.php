<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/accounting_engine.php';
require_once __DIR__ . '/includes/auth.php';

$db = Database::getInstance();
$error = '';
$step = intval(post('step') ?: get('step', 1));
$businessCount = intval(($db->fetch("SELECT COUNT(*) AS cnt FROM businesses")['cnt'] ?? 0));
$userCount = intval(($db->fetch("SELECT COUNT(*) AS cnt FROM users")['cnt'] ?? 0));

if ($businessCount > 0 && empty($_SESSION['setup_business_id'])) {
    if ($userCount > 0) {
        redirect('login.php');
    }

    $unfinishedBusiness = $db->fetch("SELECT id FROM businesses ORDER BY created_at DESC LIMIT 1");
    if ($unfinishedBusiness) {
        $_SESSION['setup_business_id'] = $unfinishedBusiness['id'];
        $step = 2;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        if ($step === 1) {
            // Create Business
            if ($businessCount > 0) {
                throw new Exception('Setup is already in progress or complete.');
            }
            $businessName = trim((string) post('business_name'));
            if ($businessName === '') {
                throw new Exception('Business name is required.');
            }
            $businessId = Database::uuid();
            $phone = validatePhoneNumber(post('phone'), 'Phone number');
            $email = validateEmailAddress(post('email'), 'Email');
            $db->insert('businesses', [
                'id' => $businessId,
                'name' => $businessName,
                'gstin' => post('gstin') ?: null,
                'address' => post('address') ?: null,
                'phone' => $phone,
                'email' => $email,
                'fy_start_month' => intval(post('fy_start_month', 4)),
            ]);

            $_SESSION['setup_business_id'] = $businessId;
            $step = 2;
        } elseif ($step === 2) {
            // Create Admin User
            $businessId = $_SESSION['setup_business_id'] ?? '';
            $business = $db->fetch("SELECT id FROM businesses WHERE id = ?", [$businessId]);
            if (!$business) {
                throw new Exception('Setup session expired. Restart the setup process.');
            }
            $existingAdmin = $db->fetch("SELECT id FROM users WHERE business_id = ? LIMIT 1", [$businessId]);
            if ($existingAdmin) {
                unset($_SESSION['setup_business_id']);
                redirect('login.php');
            }
            $fullName = trim((string) post('full_name'));
            if ($fullName === '') {
                throw new Exception('Administrator name is required.');
            }
            $email = trim(post('admin_email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid admin email address.');
            }
            $password = (string) post('password');
            if (strlen($password) < 8) {
                throw new Exception('Password must be at least 8 characters.');
            }
            if (!hash_equals($password, (string) post('confirm_password'))) {
                throw new Exception('Password and confirmation do not match.');
            }
            $userId = Database::uuid();
            $engine = new AccountingEngine($businessId, $userId);
            $db->beginTransaction();
            try {
                $db->insert('users', [
                    'id' => $userId,
                    'business_id' => $businessId,
                    'username' => Auth::generateUsername($email, $fullName),
                    'password_hash' => Auth::hashPassword($password),
                    'full_name' => $fullName,
                    'email' => $email,
                    'role' => 'ADMIN',
                ]);

                $engine->setupDefaultAccounts();

                $fy = getCurrentFY();
                $db->insert('financial_years', [
                    'id' => Database::uuid(),
                    'business_id' => $businessId,
                    'year_label' => getFYLabel($fy),
                    'start_date' => $fy . '-04-01',
                    'end_date' => ($fy + 1) . '-03-31',
                    'is_active' => 1,
                ]);
                $db->commit();
            } catch (Throwable $setupError) {
                if ($db->inTransaction()) $db->rollBack();
                throw $setupError;
            }

            // Auto-login
            unset($_SESSION['setup_business_id']);
            Auth::login($email, $password);
            
            setFlash('success', 'Welcome to ' . APP_NAME . '! Your business has been set up successfully.');
            redirect('dashboard.php');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$cssVersion = @filemtime(__DIR__ . '/assets/css/style.css') ?: APP_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — <?= APP_NAME ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="apple-touch-icon" href="logo.png">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssVersion ?>">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.1.0/fonts/remixicon.css" rel="stylesheet">
</head>
<body>
<div class="setup-container">
    <div class="setup-card animate-fade">
        <div class="login-logo">
            <div class="logo-icon">
                <img src="logo.png" alt="<?= APP_NAME ?> logo">
            </div>
            <h1><?= APP_NAME ?></h1>
            <p>Initial Setup Wizard</p>
        </div>

        <div class="setup-steps">
            <div class="setup-step <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>"></div>
            <div class="setup-step <?= $step >= 2 ? 'active' : '' ?>"></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
        <h3 style="margin-bottom: 6px; font-size: 18px;">Step 1: Business Details</h3>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">Set up your car trading business</p>
        
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="1">
            <div class="form-group">
                <label class="form-label">Business / Firm Name *</label>
                <input type="text" name="business_name" class="form-control" placeholder="e.g., Car Mela Auto" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">GSTIN (optional)</label>
                    <input type="text" name="gstin" class="form-control" placeholder="e.g., 24XXXXX1234X1Z5" maxlength="15">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="10 digit phone" inputmode="numeric" pattern="[0-9]{10}" maxlength="10">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea name="address" class="form-control" rows="2" placeholder="Business address"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="business@email.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Financial Year Starts</label>
                    <select name="fy_start_month" class="form-control">
                        <option value="4" selected>April (India Standard)</option>
                        <option value="1">January</option>
                        <option value="7">July</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">
                Continue <i class="ri-arrow-right-line"></i>
            </button>
        </form>

        <?php elseif ($step === 2): ?>
        <h3 style="margin-bottom: 6px; font-size: 18px;">Step 2: Admin Account</h3>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;">Create your admin login credentials</p>
        
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="2">
            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" name="full_name" class="form-control" placeholder="Your full name" required>
            </div>
            <div class="form-group">
                <label class="form-label">Admin Email *</label>
                <input type="email" name="admin_email" class="form-control" placeholder="admin@email.com" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Password *</label>
                <input type="password" name="password" class="form-control" placeholder="Min 8 characters" required minlength="8">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password *</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" required>
                </div>
            </div>
            <button type="submit" class="btn btn-success btn-block btn-lg">
                <i class="ri-check-line"></i> Complete Setup
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php $jsVersion = @filemtime(__DIR__ . '/assets/js/app.js') ?: APP_VERSION; ?>
<script src="assets/js/app.js?v=<?= $jsVersion ?>"></script>
</body>
</html>
