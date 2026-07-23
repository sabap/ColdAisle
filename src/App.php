<?php
/**
 * ColdAisle - Application bootstrap & helpers
 * (formerly WinDCIM; Windows IIS + SQL Server primary target)
 */
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Auth/AuthManager.php';
require_once __DIR__ . '/Auth/LocalAuth.php';
require_once __DIR__ . '/Auth/LdapAuth.php';
require_once __DIR__ . '/Auth/EntraAuth.php';
require_once __DIR__ . '/Services/AuditService.php';
require_once __DIR__ . '/Services/SettingsService.php';
require_once __DIR__ . '/Services/Cabinet3dData.php';

class App
{
    /** App semver — keep in sync with /VERSION */
    public const VERSION = '0.2.0';
    public const ROOT = __DIR__ . '/..';

    private static bool $booted = false;
    private static array $config = [];

    public static function configPath(): string
    {
        return self::ROOT . '/config/config.php';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::configPath());
    }

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_name('COLDAISLESESSID');
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }

        if (!self::isInstalled()) {
            self::$booted = true;
            return;
        }

        self::$config = require self::configPath();
        Database::configure(self::$config['database'] ?? []);
        date_default_timezone_set(self::$config['timezone'] ?? 'UTC');
        try {
            Schema::ensure();
        } catch (Throwable $e) {
            // Non-fatal: features depending on new columns degrade gracefully
            self::log('Schema ensure: ' . $e->getMessage(), 'warning');
        }
        self::$booted = true;
    }

    public static function config(?string $key = null, $default = null)
    {
        if ($key === null) {
            return self::$config;
        }
        $parts = explode('.', $key);
        $val = self::$config;
        foreach ($parts as $p) {
            if (!is_array($val) || !array_key_exists($p, $val)) {
                return $default;
            }
            $val = $val[$p];
        }
        return $val;
    }

    public static function baseUrl(): string
    {
        if (!empty(self::$config['base_url'])) {
            return rtrim((string)self::$config['base_url'], '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = self::basePath();
        return rtrim("{$scheme}://{$host}{$path}", '/');
    }

    /**
     * URL path to the application root (no trailing slash), e.g. '' or '/ColdAisle'.
     * Must NOT include /pages, /api, etc. — those are inside the app.
     */
    public static function basePath(): string
    {
        // Prefer filesystem: app root relative to IIS document root
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
        $appRoot = realpath(self::ROOT);
        if ($docRoot && $appRoot) {
            $docNorm = strtolower(str_replace('\\', '/', $docRoot));
            $appNorm = strtolower(str_replace('\\', '/', $appRoot));
            if (str_starts_with($appNorm, $docNorm)) {
                $rel = substr(str_replace('\\', '/', $appRoot), strlen(str_replace('\\', '/', $docRoot)));
                $rel = '/' . trim(str_replace('\\', '/', $rel), '/');
                if ($rel === '/') {
                    return '';
                }
                return rtrim($rel, '/');
            }
        }

        // Fallback: dirname(SCRIPT_NAME) stripped of known app subdirectories
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = dirname($script);
        $subdirs = ['api', 'pages', 'includes', 'scripts', 'assets', 'config', 'src', 'storage', 'sql', 'templates'];
        // Walk up while the last segment is a known app subfolder
        for ($i = 0; $i < 6; $i++) {
            $base = basename($dir);
            if ($base === '' || $base === '/' || $base === '\\' || $base === '.') {
                break;
            }
            if (!in_array(strtolower($base), $subdirs, true)) {
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
            return '';
        }
        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    public static function url(string $path = ''): string
    {
        // Allow query strings: "pages/devices.php?id=1"
        $path = ltrim($path, '/');
        $base = self::baseUrl();
        if ($path === '') {
            return $base;
        }
        return $base . '/' . $path;
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . self::url($path));
        exit;
    }

    public static function json($data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function requireAuth(): array
    {
        $user = AuthManager::user();
        if (!$user) {
            if (self::isApiRequest()) {
                self::json(['error' => 'Unauthorized'], 401);
            }
            self::redirect('login.php');
        }
        return $user;
    }

    public static function requirePermission(string $perm): array
    {
        $user = self::requireAuth();
        if (!AuthManager::can($user, $perm)) {
            if (self::isApiRequest()) {
                self::json(['error' => 'Forbidden', 'permission' => $perm], 403);
            }
            http_response_code(403);
            $role = App::e((string)($user['role_name'] ?? 'unknown'));
            $need = App::e($perm);
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Access denied</title>'
                . '<style>body{font-family:system-ui,sans-serif;background:#0b1220;color:#e5eef7;display:grid;place-items:center;min-height:100vh;margin:0}'
                . '.box{max-width:28rem;padding:2rem;border:1px solid #2a3648;border-radius:12px;background:#111827}'
                . 'a{color:#93c5fd}</style></head><body><div class="box">'
                . '<h1 style="margin-top:0;font-size:1.25rem">Access denied</h1>'
                . '<p>Your role (<strong>' . $role . '</strong>) does not include permission <code>' . $need . '</code>.</p>'
                . '<p><a href="' . App::e(App::url('index.php')) . '">← Dashboard</a></p>'
                . '</div></body></html>';
            exit;
        }
        return $user;
    }

    /** Require any of the listed permissions. */
    public static function requireAnyPermission(array $perms): array
    {
        $user = self::requireAuth();
        foreach ($perms as $p) {
            if (AuthManager::can($user, (string)$p)) {
                return $user;
            }
        }
        // Fail with first permission for messaging
        return self::requirePermission((string)($perms[0] ?? 'admin'));
    }

    public static function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($uri, '/api/')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function getFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    public static function log(string $message, string $level = 'info'): void
    {
        $dir = self::ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
