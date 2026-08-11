<?php
declare(strict_types=1);

require_once __DIR__ . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    header('Location: setup.php');
    exit;
}

if (AuthManager::user()) {
    App::redirect('index.php');
}

require_once __DIR__ . '/src/Services/ProductMailService.php';

$message = '';
$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!App::verifyCsrf($csrf)) {
        $error = 'Invalid session token. Please try again.';
    } else {
        $ident = trim((string)($_POST['identity'] ?? ''));
        $result = ProductMailService::requestPasswordReset($ident);
        if (empty($result['ok'])) {
            $error = (string)($result['message'] ?? 'Request failed.');
        } else {
            $message = (string)$result['message'];
            $done = true;
        }
    }
}

$appName = App::appName();
$mailHint = ProductMailService::mailReady()
    ? ''
    : 'Outbound email is not configured. Contact an administrator if you need a password reset.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password · <?= htmlspecialchars($appName) ?></title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/img/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .login-brand { display: flex; flex-direction: column; align-items: center; gap: .65rem; margin-bottom: .25rem; }
        .login-brand img { width: 56px; height: 56px; border-radius: 12px; box-shadow: 0 0 0 1px rgba(56,189,248,.3); }
        .login-card h1 { margin: 0; }
    </style>
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <img src="assets/img/logo.svg" width="56" height="56" alt="">
            <h1><?= htmlspecialchars($appName) ?></h1>
        </div>
        <p class="subtitle">Reset local account password</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($mailHint !== '' && !$done): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($mailHint) ?></div>
        <?php endif; ?>

        <?php if (!$done): ?>
        <form method="post" autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(App::csrfToken()) ?>">
            <div class="form-row">
                <label for="identity">Username or email</label>
                <input class="form-control" type="text" id="identity" name="identity" required autofocus
                       value="<?= htmlspecialchars($_POST['identity'] ?? '') ?>" autocomplete="username">
            </div>
            <p class="text-muted" style="font-size:.8rem;margin:.25rem 0 .75rem">
                Only <strong>local</strong> accounts can reset via email. Domain (LDAPS) and Entra users
                should use their organization password tools.
            </p>
            <button type="submit" class="btn btn-primary" style="width:100%">Send reset link</button>
        </form>
        <?php endif; ?>

        <p style="text-align:center;margin-top:1rem">
            <a href="login.php">← Back to sign in</a>
        </p>
    </div>
</body>
</html>
