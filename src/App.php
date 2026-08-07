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
require_once __DIR__ . '/Services/EnvSensor3dData.php';
require_once __DIR__ . '/Services/EnvSensorAlertService.php';
require_once __DIR__ . '/Services/TempUnitService.php';
require_once __DIR__ . '/Services/Crypto.php';
require_once __DIR__ . '/Services/SiteBackupService.php';
require_once __DIR__ . '/Services/SmbBackupService.php';
require_once __DIR__ . '/Services/UpdateService.php';
require_once __DIR__ . '/Services/MailService.php';
require_once __DIR__ . '/Services/MibService.php';
require_once __DIR__ . '/Services/PowerAlertService.php';
require_once __DIR__ . '/Services/AlertService.php';
require_once __DIR__ . '/Services/PowerHistoryService.php';
require_once __DIR__ . '/Services/SnmpSchedulerService.php';
require_once __DIR__ . '/Services/StorageHousekeepingService.php';

class App
{
    /** App semver — keep in sync with /VERSION */
    public const VERSION = '0.3.67';
    /** Product name is fixed (not user-configurable). */
    public const APP_NAME = 'ColdAisle';
    public const ROOT = __DIR__ . '/..';

    private static bool $booted = false;
    /** hrtime(true) at start of boot — for dev request timer */
    private static int|float $requestStartHr = 0;
    /** @var array<string,float> boot phase durations in ms (dev timer) */
    private static array $bootPhasesMs = [];
    private static array $config = [];
    private static bool $securityHeadersSent = false;

    public static function configPath(): string
    {
        return self::ROOT . '/config/config.php';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::configPath());
    }

    /**
     * @param array{light?:bool} $options light=true skips schema/crypto/pending (media.php, static APIs)
     */
    public static function boot(array $options = []): void
    {
        if (self::$booted) {
            return;
        }

        if (self::$requestStartHr === 0) {
            self::$requestStartHr = hrtime(true);
        }

        $isCli = PHP_SAPI === 'cli';
        $light = !empty($options['light']);

        // Load config before session so security.cookie_* can apply
        $tPhase = hrtime(true);
        if (self::isInstalled()) {
            self::$config = require self::configPath();
        }
        self::$bootPhasesMs['config'] = (hrtime(true) - $tPhase) / 1e6;

        // Env/config-only check first (no DB yet)
        $timerWanted = !$isCli && self::requestTimerEnabled(false);

        $tPhase = hrtime(true);
        if (!$isCli && session_status() === PHP_SESSION_NONE) {
            self::startSecureSession();
        }
        self::$bootPhasesMs['session'] = (hrtime(true) - $tPhase) / 1e6;

        if (!self::isInstalled()) {
            if (!$isCli) {
                self::sendSecurityHeaders();
            }
            self::$booted = true;
            self::$bootPhasesMs['boot_done_at'] = (hrtime(true) - self::$requestStartHr) / 1e6;
            return;
        }

        $tPhase = hrtime(true);
        Database::configure(self::$config['database'] ?? []);
        date_default_timezone_set(self::$config['timezone'] ?? 'UTC');
        // Enable SQL timing before any query/connect so connect_ms is visible
        if ($timerWanted || (!$isCli && self::requestTimerEnabled(false))) {
            Database::setTimingEnabled(true);
        }
        // Warm connection (and time it) before Settings/schema
        try {
            Database::connection();
        } catch (Throwable $e) {
            self::log('DB connect: ' . $e->getMessage(), 'error');
            throw $e;
        }
        // DB-backed diagnostics flag (now that SQL is up)
        if (!$isCli && !$timerWanted && self::requestTimerEnabled(true)) {
            Database::setTimingEnabled(true);
        }
        self::$bootPhasesMs['db_connect'] = (hrtime(true) - $tPhase) / 1e6;

        // CLI poll worker: skip Schema/Crypto migration/Update finish — those can hang
        // under SYSTEM or when SQL is slow; web requests keep the full bootstrap.
        // media.php uses light boot (no schema/pending) so faceplate bursts stay cheap.
        $cliLight = $isCli && (
            getenv('COLDAISLE_CLI_LIGHT') === '1'
            || in_array('--light', $GLOBALS['argv'] ?? [], true)
        );

        if (!$cliLight && !$light) {
            $tPhase = hrtime(true);
            try {
                $ens = Schema::ensure(false);
                if (is_array($ens) && empty($ens['ok']) && !empty($ens['error'])) {
                    self::log('Schema ensure: ' . (string)$ens['error'], 'warning');
                }
            } catch (Throwable $e) {
                // Non-fatal: features depending on new columns degrade gracefully
                self::log('Schema ensure: ' . $e->getMessage(), 'warning');
            }
            self::$bootPhasesMs['schema'] = (hrtime(true) - $tPhase) / 1e6;

            // App-level secret encryption (SNMP passphrases, etc.) — skip after one-time stamp
            $tPhase = hrtime(true);
            try {
                $cryptoStamp = self::ROOT . '/storage/tmp/crypto_ok.flag';
                if (!is_file($cryptoStamp)) {
                    if (Crypto::ensureAppKey()) {
                        Crypto::reset();
                        if (is_file(self::configPath())) {
                            self::$config = require self::configPath();
                            Crypto::reset();
                        }
                    }
                    if (Crypto::isAvailable()) {
                        $migrated = SettingsService::get('secrets_migration_v1', '');
                        if ($migrated !== '1') {
                            $stats = Crypto::migratePlaintextSecrets();
                            SettingsService::set('secrets_migration_v1', '1', 'security');
                            if (($stats['sealed'] ?? 0) > 0) {
                                self::log(
                                    'Encrypted ' . (int)$stats['sealed'] . ' secret field(s) at rest (skipped '
                                    . (int)$stats['skipped'] . ', errors ' . (int)$stats['errors'] . ')',
                                    'info'
                                );
                            }
                        }
                    }
                    $tmpDir = self::ROOT . '/storage/tmp';
                    if (!is_dir($tmpDir)) {
                        @mkdir($tmpDir, 0775, true);
                    }
                    @file_put_contents($cryptoStamp, date('c') . "\n");
                }
            } catch (Throwable $e) {
                self::log('Crypto bootstrap: ' . $e->getMessage(), 'warning');
            }
            self::$bootPhasesMs['crypto'] = (hrtime(true) - $tPhase) / 1e6;

            // Finish any deferred file replacements from a previous self-update
            // (Windows/IIS often locks the script that started the update).
            $tPhase = hrtime(true);
            try {
                UpdateService::applyPendingReplacements();
            } catch (Throwable $e) {
                self::log('Pending update apply: ' . $e->getMessage(), 'warning');
            }
            self::$bootPhasesMs['pending_files'] = (hrtime(true) - $tPhase) / 1e6;
        } else {
            self::$bootPhasesMs['light'] = 1.0;
        }

        // Phase B: transport + session hardening (web only)
        $tPhase = hrtime(true);
        if (!$isCli) {
            self::enforceTransportSecurity();
            self::sendSecurityHeaders();
            AuthManager::touchSession();
        }
        self::$bootPhasesMs['post_boot'] = (hrtime(true) - $tPhase) / 1e6;

        self::$booted = true;
        self::$bootPhasesMs['boot_done_at'] = (hrtime(true) - self::$requestStartHr) / 1e6;
    }

    /**
     * Whether the current request is over HTTPS (incl. reverse-proxy headers).
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        $fwd = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($fwd === 'https') {
            return true;
        }
        // Cloudflare / some proxies
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        return false;
    }

    /**
     * Security config with defaults (safe for lab: force_https off).
     * @return array{
     *   force_https:bool,hsts:bool,hsts_max_age:int,
     *   cookie_secure:string,cookie_samesite:string,
     *   session_idle_minutes:int,session_absolute_minutes:int,
     *   bind_user_agent:bool
     * }
     */
    public static function securityConfig(): array
    {
        $s = is_array(self::$config['security'] ?? null) ? self::$config['security'] : [];
        $sameSite = strtoupper((string)($s['cookie_samesite'] ?? 'Lax'));
        if (!in_array($sameSite, ['LAX', 'STRICT', 'NONE'], true)) {
            $sameSite = 'LAX';
        }
        // PHP expects first-letter capital for session_start cookie_samesite
        $sameSiteLabel = match ($sameSite) {
            'STRICT' => 'Strict',
            'NONE' => 'None',
            default => 'Lax',
        };
        $cookieSecure = strtolower((string)($s['cookie_secure'] ?? 'auto'));
        if (!in_array($cookieSecure, ['auto', 'always', 'never'], true)) {
            $cookieSecure = 'auto';
        }
        return [
            'force_https' => !empty($s['force_https']),
            'hsts' => !empty($s['hsts']),
            'hsts_max_age' => max(0, (int)($s['hsts_max_age'] ?? 31536000)),
            'cookie_secure' => $cookieSecure,
            'cookie_samesite' => $sameSiteLabel,
            'session_idle_minutes' => max(0, (int)($s['session_idle_minutes'] ?? 480)),
            'session_absolute_minutes' => max(0, (int)($s['session_absolute_minutes'] ?? 1440)),
            'bind_user_agent' => array_key_exists('bind_user_agent', $s)
                ? !empty($s['bind_user_agent'])
                : true,
        ];
    }

    private static function startSecureSession(): void
    {
        $sec = self::isInstalled() ? self::securityConfig() : [
            'cookie_secure' => 'auto',
            'cookie_samesite' => 'Lax',
            'session_idle_minutes' => 480,
        ];

        $https = self::isHttps();
        $secure = match ($sec['cookie_secure'] ?? 'auto') {
            'always' => true,
            'never' => false,
            default => $https,
        };
        // SameSite=None requires Secure
        $sameSite = $sec['cookie_samesite'] ?? 'Lax';
        if ($sameSite === 'None' && !$secure) {
            $sameSite = 'Lax';
        }

        // Prefer cookies only; never put session id in URLs
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', $sameSite);

        $lifetime = 0; // browser session cookie; idle timeout enforced server-side
        $path = self::sessionCookiePath();

        session_name('COLDAISLESESSID');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => $path,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
        session_start([
            'cookie_lifetime' => $lifetime,
            'cookie_path' => $path,
            'cookie_secure' => $secure,
            'cookie_httponly' => true,
            'cookie_samesite' => $sameSite,
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'use_trans_sid' => false,
        ]);
    }

    /** Cookie path = app base path or '/'. */
    private static function sessionCookiePath(): string
    {
        // basePath needs SCRIPT/DOCUMENT_ROOT; works before full boot
        try {
            $bp = self::basePath();
            if ($bp === '' || $bp === '/') {
                return '/';
            }
            return rtrim($bp, '/') . '/';
        } catch (Throwable $e) {
            return '/';
        }
    }

    /**
     * Redirect HTTPâ†’HTTPS when force_https is on.
     */
    public static function enforceTransportSecurity(): void
    {
        $sec = self::securityConfig();
        if (empty($sec['force_https']) || self::isHttps()) {
            return;
        }
        // Avoid redirect loops on CLI / incomplete host
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return;
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $target = 'https://' . $host . $uri;
        header('Location: ' . $target, true, 301);
        exit;
    }

    /**
     * Browser security headers (idempotent per request).
     */
    public static function sendSecurityHeaders(): void
    {
        if (self::$securityHeadersSent || headers_sent()) {
            return;
        }
        self::$securityHeadersSent = true;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        // Frame ancestors only â€” full CSP would break existing inline scripts
        header("Content-Security-Policy: frame-ancestors 'self'");

        $sec = self::isInstalled() ? self::securityConfig() : [];
        if (self::isHttps() && !empty($sec['hsts'])) {
            $maxAge = (int)($sec['hsts_max_age'] ?? 31536000);
            header('Strict-Transport-Security: max-age=' . $maxAge . '; includeSubDomains');
        }
    }

    /**
     * Allow only same-app relative return paths (blocks open redirects).
     */
    public static function safeReturnPath(?string $path, string $fallback = 'index.php'): string
    {
        if ($path === null || $path === '') {
            return $fallback;
        }
        $path = trim($path);
        // Absolute URLs / protocol-relative
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path)) {
            return $fallback;
        }
        if (str_contains($path, "\n") || str_contains($path, "\r") || str_contains($path, "\0")) {
            return $fallback;
        }
        // Strip app base path prefix if present
        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base) + 1);
        } elseif ($base !== '' && $path === $base) {
            return $fallback;
        }
        $path = ltrim($path, '/');
        if ($path === '' || str_starts_with($path, '.')) {
            return $fallback;
        }
        return $path;
    }

    /** Reload config.php into memory (e.g. after writing app_key). */
    public static function reloadConfig(): void
    {
        if (is_file(self::configPath())) {
            self::$config = require self::configPath();
        }
        Crypto::reset();
    }

    public static function config(?string $key = null, $default = null)
    {
        if ($key === null) {
            return self::$config;
        }
        // Brand is not configurable â€” ignore any legacy config/settings value
        if ($key === 'app_name') {
            return self::APP_NAME;
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

    /**
     * Dev-only footer timing. Enable via:
     * - Settings → Diagnostics (Global Admin) — preferred
     * - config debug.request_timer = true
     * - env COLDAISLE_DEBUG=1 or COLDAISLE_REQUEST_TIMER=1
     * Shows timing to all logged-in users while on; only admins can toggle.
     *
     * @param bool $allowDb When false, skip SettingsService (no DB yet).
     */
    public static function requestTimerEnabled(bool $allowDb = true): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        $env = getenv('COLDAISLE_REQUEST_TIMER');
        if ($env === false || $env === '') {
            $env = getenv('COLDAISLE_DEBUG');
        }
        if ($env === '1' || strcasecmp((string)$env, 'true') === 0) {
            return true;
        }
        if (!empty(self::$config['debug']['request_timer'])) {
            return true;
        }
        // File flag avoids a SQL round-trip every page (synced from Settings save)
        $timerFlag = self::ROOT . '/storage/tmp/debug_request_timer.flag';
        if (is_file($timerFlag)) {
            return true;
        }
        // DB setting (Settings → Diagnostics). Safe if DB not ready yet.
        if ($allowDb && self::isInstalled() && class_exists('SettingsService', false)) {
            try {
                if (SettingsService::get('debug_request_timer', '0') === '1') {
                    // One-time bridge: older installs only have the DB row
                    self::setRequestTimerFlag(true);
                    return true;
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        return false;
    }

    /** Keep file flag in sync with Settings → Diagnostics (no SQL on each page). */
    public static function setRequestTimerFlag(bool $on): void
    {
        $dir = self::ROOT . '/storage/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $flag = $dir . '/debug_request_timer.flag';
        if ($on) {
            @file_put_contents($flag, date('c') . "\n");
        } elseif (is_file($flag)) {
            @unlink($flag);
        }
    }

    /**
     * Snapshot for footer / JSON. Call near end of HTML render.
     *
     * @return array{
     *   total_ms:float,
     *   sql_ms:float,
     *   sql_count:int,
     *   connect_ms:float,
     *   php_ms:float,
     *   boot:array<string,float>
     * }
     */
    public static function requestTimingSnapshot(): array
    {
        $start = self::$requestStartHr > 0 ? self::$requestStartHr : hrtime(true);
        $totalMs = (hrtime(true) - $start) / 1e6;
        $db = class_exists('Database', false) ? Database::timingStats() : [
            'query_count' => 0,
            'query_ms' => 0.0,
            'connect_ms' => 0.0,
        ];
        $sqlMs = (float)($db['query_ms'] ?? 0);
        $connectMs = (float)($db['connect_ms'] ?? 0);
        // PHP wall outside timed SQL (includes connect if not double-counted: connect is separate)
        $phpMs = max(0.0, $totalMs - $sqlMs);

        $boot = [];
        foreach (self::$bootPhasesMs as $k => $ms) {
            $boot[$k] = round((float)$ms, 1);
        }
        $bootDone = (float)(self::$bootPhasesMs['boot_done_at'] ?? 0);
        $pageMs = $bootDone > 0 ? max(0.0, $totalMs - $bootDone) : 0.0;
        $boot['page'] = round($pageMs, 1);

        return [
            'total_ms' => round($totalMs, 1),
            'sql_ms' => round($sqlMs, 1),
            'sql_count' => (int)($db['query_count'] ?? 0),
            'connect_ms' => round($connectMs, 1),
            'php_ms' => round($phpMs, 1),
            'boot' => $boot,
        ];
    }

    /**
     * Release the session write lock early so parallel media.php / XHR
     * requests for the same user are not serialized on the session file.
     * Safe after auth/session reads for the rest of a typical page render.
     */
    public static function releaseSessionLock(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** Always "ColdAisle" â€” not user-configurable. */
    public static function appName(): string
    {
        return self::APP_NAME;
    }

    public static function baseUrl(): string
    {
        if (!empty(self::$config['base_url'])) {
            $configured = rtrim((string)self::$config['base_url'], '/');
            // Config may say https://â€¦ before IIS has a certificate binding.
            // Until the request is actually HTTPS (or force_https is on), prefer the
            // live request origin so CSS/login links keep working over HTTP.
            if (PHP_SAPI !== 'cli'
                && !self::isHttps()
                && str_starts_with(strtolower($configured), 'https://')
                && empty(self::securityConfig()['force_https'])
            ) {
                return self::requestOriginBase();
            }
            return $configured;
        }
        return self::requestOriginBase();
    }

    /**
     * Current request origin + app base path (no trailing slash).
     * Used when base_url is empty or HTTPS is configured but not yet live.
     */
    public static function requestOriginBase(): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = self::basePath();
        return rtrim("{$scheme}://{$host}{$path}", '/');
    }

    /**
     * True when config base_url is https:// but this request is plain HTTP
     * (common right after setup, before the IIS certificate binding).
     */
    public static function httpsConfigMismatch(): bool
    {
        $configured = (string)(self::$config['base_url'] ?? '');
        if ($configured === '' || PHP_SAPI === 'cli') {
            return false;
        }
        return str_starts_with(strtolower($configured), 'https://') && !self::isHttps();
    }

    /**
     * URL path to the application root (no trailing slash), e.g. '' or '/ColdAisle'.
     * Must NOT include /pages, /api, etc. â€” those are inside the app.
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
        // Drop any accidental stdout (Net-SNMP MIB warnings, notices) so the
        // body is pure JSON — corrupted JSON often surfaces as IIS 500 in the UI.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        // SNMP / AD strings can contain invalid UTF-8; without SUBSTITUTE json_encode
        // returns false and clients see IIS "Internal Server Error" HTML or empty bodies.
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode($data, $flags);
        if ($json === false) {
            $json = json_encode([
                'error' => 'Failed to encode JSON response',
                'detail' => function_exists('json_last_error_msg') ? json_last_error_msg() : 'encode error',
            ], JSON_UNESCAPED_SLASHES) ?: '{"error":"Failed to encode JSON response"}';
            if ($code < 400) {
                http_response_code(500);
            }
        }
        echo $json;
        exit;
    }

    public static function requireAuth(): array
    {
        $user = AuthManager::user();
        if (!$user) {
            if (self::isApiRequest()) {
                self::json(['error' => 'Unauthorized'], 401);
            }
            // Remember where they were (same-app path only)
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            $pathOnly = parse_url($uri, PHP_URL_PATH) ?: '';
            $query = parse_url($uri, PHP_URL_QUERY);
            $rel = self::safeReturnPath($pathOnly, '');
            if ($rel !== '') {
                $_SESSION['return_url'] = $query ? ($rel . '?' . $query) : $rel;
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
                . '<p><a href="' . App::e(App::url('index.php')) . '">â† Dashboard</a></p>'
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
