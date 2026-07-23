<?php
declare(strict_types=1);

require_once __DIR__ . '/src/App.php';
App::boot();
AuthManager::logout();
// Fresh session for login page CSRF
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Cookie params already set during boot; reopen empty session
    @session_start();
}
App::redirect('login.php');
