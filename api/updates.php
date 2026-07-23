<?php
/**
 * ColdAisle updates API — check / apply (admin manage_settings only).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/src/Services/UpdateService.php';

$method = api_method();
$user = AuthManager::user();

try {
    api_require_permission('manage_settings');

    if ($method === 'GET') {
        $force = isset($_GET['force']) && (string)$_GET['force'] === '1';
        $status = UpdateService::checkForUpdate($force);
        App::json([
            'status' => $status,
            'installed' => UpdateService::installedVersion(),
            'config' => [
                'enabled' => !empty(UpdateService::config()['enabled']),
                'owner' => UpdateService::config()['github_owner'],
                'repo' => UpdateService::config()['github_repo'],
                'has_token' => trim((string)UpdateService::config()['github_token']) !== '',
                'auto_check' => !empty(UpdateService::config()['auto_check']),
            ],
        ]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $data = api_read_json();
        $action = trim((string)($data['action'] ?? ''));

        if ($action === 'check') {
            $status = UpdateService::checkForUpdate(true);
            AuditService::log((int)$user['user_id'], $user['username'], 'update_check', 'system', null, [
                'latest' => $status['latest'] ?? null,
                'available' => !empty($status['update_available']),
            ]);
            App::json(['ok' => true, 'status' => $status]);
        }

        if ($action === 'apply') {
            $version = isset($data['version']) ? trim((string)$data['version']) : null;
            $result = UpdateService::applyUpdate($version ?: null);
            AuditService::log((int)$user['user_id'], $user['username'], 'update_apply', 'system', null, [
                'version' => $result['version'] ?? null,
                'backup' => $result['backup'] ?? null,
            ]);
            App::json($result);
        }

        App::json(['error' => 'Unknown action. Use check or apply.'], 400);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API updates: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
