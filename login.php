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
    verifyCsrf();
    $identifier = trim(post('identifier'));
    $password = post('password');
    
    if (empty($identifier) || empty($password)) {
        $error = 'Please enter your email/username and password.';
    } else {
        if (Auth::login($identifier, $password)) {
            redirect('dashboard.php');
        } else {
            usleep(300000);
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
$uiCssVersion = @filemtime(__DIR__ . '/assets/css/ui-system.css') ?: APP_VERSION;
$polishCssVersion = @filemtime(__DIR__ . '/assets/css/ui-polish.css') ?: APP_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#ffffff">
    <?php if (APP_IS_TESTING): ?><meta name="robots" content="noindex, nofollow, noarchive"><?php endif; ?>
    <title><?= APP_IS_TESTING ? '[TEST] ' : '' ?>Login — <?= APP_NAME ?></title>
    <meta name="description" content="Login to <?= APP_NAME ?> — Car Trading Accounting System">
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="apple-touch-icon" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssVersion ?>">
    <link rel="stylesheet" href="assets/css/ui-system.css?v=<?= $uiCssVersion ?>">
    <link rel="stylesheet" href="assets/css/ui-polish.css?v=<?= $polishCssVersion ?>">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.1.0/fonts/remixicon.css" rel="stylesheet">
</head>
<body class="<?= APP_IS_TESTING ? 'env-testing' : '' ?>">
<div class="login-container">
    <div class="login-card animate-fade">
        <div class="login-logo">
            <div class="logo-icon">
                <img src="logo.png" alt="<?= APP_NAME ?> logo">
            </div>
            <h1><?= APP_NAME ?></h1>
            <?php if (APP_IS_TESTING): ?><span class="environment-badge environment-badge-login">TEST DATABASE</span><?php endif; ?>
            <p>Car Trading Accounting System</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><i class="ri-error-warning-line" aria-hidden="true"></i> <?= clean($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label" for="identifier"><i class="ri-mail-line" aria-hidden="true"></i> Email or Username</label>
                <input type="text" id="identifier" name="identifier" class="form-control" placeholder="Enter your email or username" value="<?= clean(post('identifier')) ?>" autocomplete="username" autofocus required>
            </div>
            <div class="form-group">
                <label class="form-label" for="password"><i class="ri-lock-line" aria-hidden="true"></i> Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg auth-submit">
                <i class="ri-login-box-line" aria-hidden="true"></i> Sign In
            </button>
        </form>

        <div class="auth-footer">
            <?= APP_NAME ?> v<?= APP_VERSION ?> &bull; FY <?= getFYLabel() ?>
        </div>
    </div>
</div>
</body>
</html>
