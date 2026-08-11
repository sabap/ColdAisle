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

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';
$valid = $token !== '' ? ProductMailService::findValidReset($token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!App::verifyCsrf($csrf)) {
        $error = 'Invalid session token. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $result = ProductMailService::completePasswordReset($token, $password);
            if (!empty($result['ok'])) {
                $success = (string)$result['message'];
                $valid = null;
            } else {
                $error = (string)($result['message'] ?? 'Reset failed.');
                $valid = ProductMailService::findValidReset($token);
            }
        }
    }
}

$appName = App::appName();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set password · <?= htmlspecialchars($appName) ?></title>
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
        <p class="subtitle">Set a new password</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <p style="text-align:center;margin-top:1rem">
                <a class="btn btn-primary" style="width:100%;display:inline-block;text-align:center" href="login.php">Sign in</a>
            </p>
        <?php elseif (!$valid): ?>
            <div class="alert alert-error">This reset link is invalid or has expired.</div>
            <p style="text-align:center;margin-top:1rem">
                <a href="forgot_password.php">Request a new link</a>
                · <a href="login.php">Sign in</a>
            </p>
        <?php else: ?>
            <p class="text-muted" style="font-size:.85rem;margin-bottom:.75rem">
                Account: <strong><?= htmlspecialchars((string)$valid['username']) ?></strong>
            </p>
            <form method="post" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(App::csrfToken()) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-row">
                    <label for="password">New password</label>
                    <input class="form-control" type="password" id="password" name="password" required
                           minlength="8" autocomplete="new-password" autofocus>
                </div>
                <div class="form-row">
                    <label for="password_confirm">Confirm password</label>
                    <input class="form-control" type="password" id="password_confirm" name="password_confirm" required
                           minlength="8" autocomplete="new-password">
                </div>
                <p class="text-muted" style="font-size:.8rem;margin:.25rem 0 .75rem">Minimum 8 characters.</p>
                <button type="submit" class="btn btn-primary" style="width:100%">Update password</button>
            </form>
            <p style="text-align:center;margin-top:1rem">
                <a href="login.php">← Back to sign in</a>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
