<?php
/**
 * Setup wizard API — GET state, POST advance/test/launch.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/timezone_field.php';

api_require_permission('manage_settings');
api_require_csrf();

$method = api_method();

if ($method === 'GET') {
    $step = isset($_GET['step']) ? (string)$_GET['step'] : null;
    App::json(SetupWizardService::payload($step));
}

if ($method !== 'POST') {
    App::json(['error' => 'Method not allowed'], 405);
}

$body = api_read_json();
$action = (string)($body['action'] ?? '');
$user = AuthManager::user() ?? [];

if ($action === 'launch') {
    SetupWizardService::launchFromSettings();
    App::json(SetupWizardService::payload());
}

if ($action === 'test') {
    $testId = (string)($body['test'] ?? '');
    $input = is_array($body['fields'] ?? null) ? $body['fields'] : [];
    $result = SetupWizardService::runTest($testId, $input);
    App::json($result);
}

$stepId = (string)($body['step'] ?? '');
$input = is_array($body['fields'] ?? null) ? $body['fields'] : [];
if ($stepId === '') {
    App::json(['ok' => false, 'error' => 'Missing step.'], 400);
}

$result = SetupWizardService::advance($action, $stepId, $input, $user);
$code = !empty($result['ok']) ? 200 : 422;
App::json($result, $code);
