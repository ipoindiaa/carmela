<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// If already logged in, redirect to dashboard
if (Auth::isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim(post('identifier'));
    $password = post('password');
    
    if (empty($identifier) || empty($password)) {
        $error = 'Please enter your email/username and password.';
    } else {
        if (Auth::login($identifier, $password)) {
            redirect('dashboard.php');
        } else {
            $error = 'Invalid email/username or password.';
        }
    }
}

// Check if system needs setup (no businesses exist)
$db = Database::getInstance();
$businessCount = $db->fetch("SELECT COUNT(*) as cnt FROM businesses");
$needsSetup = ($businessCount && $businessCount['cnt'] == 0);
if ($needsSetup) {
    redirect('setup.php');
}

$cssVersion = @filemtime(__DIR__ . '/assets/css/style.css') ?: APP_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= APP_NAME ?></title>
    <meta name="description" content="Login to <?= APP_NAME ?> — Car Trading Accounting System">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssVersion ?>">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.1.0/fonts/remixicon.css" rel="stylesheet">
</head>
<body>
<div class="login-container">
    <div class="login-card animate-fade">
        <div class="login-logo">
            <div class="logo-icon">A</div>
            <h1><?= APP_NAME ?></h1>
            <p>Car Trading Accounting System</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="ri-error-warning-line"></i> <?= clean($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label" for="identifier"><i class="ri-mail-line"></i> Email or Username</label>
                <input type="text" id="identifier" name="identifier" class="form-control" placeholder="Enter your email or username" value="<?= clean(post('identifier')) ?>" autofocus required>
            </div>
            <div class="form-group">
                <label class="form-label" for="password"><i class="ri-lock-line"></i> Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top: 8px;">
                <i class="ri-login-box-line"></i> Sign In
            </button>
        </form>

        <div style="text-align: center; margin-top: 24px;">
            <p style="font-size: 12px; color: var(--text-muted);">
                <?= APP_NAME ?> v<?= APP_VERSION ?> &bull; FY <?= getFYLabel() ?>
            </p>
        </div>
    </div>
</div>
</body>
</html>
