<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    App::json(['error' => 'Not installed'], 503);
}

$user = App::requireAuth();

/** @var array<string,mixed>|null */
function api_read_json(): array
{
    // php://input can only be read once — cache for CSRF + action body
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        $cached = is_array($_POST) ? $_POST : [];
        return $cached;
    }
    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : [];
    return $cached;
}

function api_require_csrf(): void
{
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $token = is_string($hdr) && $hdr !== ''
        ? $hdr
        : (api_read_json()['_csrf'] ?? ($_POST['_csrf'] ?? ''));
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !App::verifyCsrf(is_string($token) ? $token : null)) {
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
