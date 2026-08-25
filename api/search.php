<?php
/**
 * GET /api/search.php?q=  — desktop global lookup (session + CSRF not required for GET).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = AuthManager::user();
if (!$user) {
    App::json(['error' => 'Unauthorized'], 401);
}

$q = trim((string)($_GET['q'] ?? ''));
if (!class_exists('SearchService')) {
    App::json(['q' => $q, 'total' => 0, 'groups' => []]);
}

App::json(SearchService::lookup($user, $q));
