<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    App::json(['error' => 'Not installed'], 503);
}

$user = App::requireAuth();

function api_read_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function api_require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (api_read_json()['_csrf'] ?? ($_POST['_csrf'] ?? ''));
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !App::verifyCsrf(is_string($token) ? $token : null)) {
        // Allow if session-authenticated same-origin; still prefer token
        // Strict mode:
        App::json(['error' => 'Invalid CSRF token'], 419);
    }
}

function api_method(): string
{
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    // Method override
    if ($m === 'POST') {
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
        if ($override) {
            return strtoupper($override);
        }
    }
    return $m;
}

/** Require a permission for the current API user (403 if missing). */
function api_require_permission(string $permission): void
{
    $user = AuthManager::user();
    if (!$user || !AuthManager::can($user, $permission)) {
        App::json(['error' => 'Forbidden', 'permission' => $permission], 403);
    }
}

/** Require any of the listed permissions. */
function api_require_any_permission(array $permissions): void
{
    $user = AuthManager::user();
    if (!$user) {
        App::json(['error' => 'Forbidden'], 403);
    }
    foreach ($permissions as $p) {
        if (AuthManager::can($user, (string)$p)) {
            return;
        }
    }
    App::json(['error' => 'Forbidden', 'permission' => (string)($permissions[0] ?? '')], 403);
}
