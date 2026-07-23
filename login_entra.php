<?php
declare(strict_types=1);

require_once __DIR__ . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    header('Location: setup.php');
    exit;
}

if (!EntraAuth::isEnabled()) {
    App::flash('error', 'Microsoft Entra SSO is not enabled.');
    App::redirect('login.php');
}

// Callback
if (isset($_GET['code'])) {
    $state = $_GET['state'] ?? '';
    if (empty($_SESSION['entra_state']) || !hash_equals($_SESSION['entra_state'], $state)) {
        App::flash('error', 'Invalid OAuth state.');
        App::redirect('login.php');
    }
    unset($_SESSION['entra_state']);

    try {
        $user = EntraAuth::handleCallback($_GET['code']);
        if ($user) {
            AuthManager::login($user, 'entra');
            App::redirect('index.php');
        }
        App::flash('error', 'Entra sign-in failed. Check logs and app registration.');
    } catch (Throwable $e) {
        App::log('Entra callback error: ' . $e->getMessage(), 'error');
        App::flash('error', 'Entra sign-in error.');
    }
    App::redirect('login.php');
}

if (isset($_GET['error'])) {
    App::flash('error', 'Entra error: ' . ($_GET['error_description'] ?? $_GET['error']));
    App::redirect('login.php');
}

// Start auth
$state = bin2hex(random_bytes(16));
$_SESSION['entra_state'] = $state;
header('Location: ' . EntraAuth::authorizeUrl($state));
exit;
