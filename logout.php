<?php
declare(strict_types=1);

require_once __DIR__ . '/src/App.php';
App::boot();
AuthManager::logout();
header('Location: login.php');
exit;
