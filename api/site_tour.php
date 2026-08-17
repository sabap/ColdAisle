<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = AuthManager::user() ?? [];
api_require_csrf();
$method = api_method();

if ($method === 'GET') {
    App::json(SiteTourService::payload($user));
}

if ($method !== 'POST') {
    App::json(['error' => 'Method not allowed'], 405);
}

$body = api_read_json();
$action = (string)($body['action'] ?? '');

$state = match ($action) {
    'start' => SiteTourService::start(),
    'exit' => SiteTourService::exitTour(),
    'complete' => SiteTourService::complete(),
    'goto' => SiteTourService::goto((int)($body['step'] ?? 0)),
    default => null,
};

if ($state === null) {
    App::json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

App::json(array_merge(SiteTourService::payload($user), ['ok' => true]));
