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

$error = '';
$entraEnabled = EntraAuth::isEnabled();
$ldapsEnabled = (bool) App::config('auth.ldaps.enabled');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf = $_POST['_csrf'] ?? '';

    if (!App::verifyCsrf($csrf)) {
        $error = 'Invalid session token. Please try again.';
    } else {
        try {
            $user = AuthManager::attempt($username, $password);
            if ($user) {
                AuthManager::login($user, $user['auth_source'] ?? 'local');
                $redirect = App::safeReturnPath($_SESSION['return_url'] ?? null, 'index.php');
                unset($_SESSION['return_url']);
                App::redirect($redirect);
            }
            $error = 'Invalid username or password.';
        } catch (Throwable $e) {
            App::log('Login error: ' . $e->getMessage(), 'error');
            $error = 'Authentication error. Check application logs.';
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
    <title>Login · <?= htmlspecialchars($appName) ?></title>
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
        <p class="subtitle">Data Center Infrastructure Management</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(App::csrfToken()) ?>">
            <div class="form-row">
                <label for="username">Username</label>
                <input class="form-control" type="text" id="username" name="username" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username">
            </div>
            <div class="form-row">
                <label for="password">Password</label>
                <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Sign In</button>
        </form>

        <p style="text-align:center;margin-top:.75rem;font-size:.85rem">
            <a href="forgot_password.php">Forgot password?</a>
            <span class="text-muted"> (local accounts)</span>
        </p>

        <?php if ($ldapsEnabled): ?>
            <p class="text-muted" style="text-align:center;font-size:.8rem;margin-top:.5rem">
                Domain accounts (LDAPS) are accepted on this form.
            </p>
        <?php endif; ?>

        <?php if ($entraEnabled): ?>
            <div class="login-divider">or</div>
            <a class="btn btn-secondary" style="width:100%" href="login_entra.php">
                Sign in with Microsoft Entra ID
            </a>
        <?php endif; ?>
    </div>
</body>
</html>
