<?php
/**
 * ColdAisle - Authentication orchestrator & RBAC
 *
 * Roles (system):
 *  - Viewer             — general view-only
 *  - Department Admin   — view all; edit devices in own department
 *  - Data Center Admin  — view all; edit infrastructure, devices, power, cabling, lifecycle
 *  - Global Admin       — full platform (settings + users); also legacy name "Administrator"
 *
 * Permissions are coarse keys (JSON array on roles.permissions). Prefer helpers
 * (canEditDevice, canEditInfrastructure, …) over raw keys in page code.
 */
declare(strict_types=1);

class AuthManager
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'view_dashboard',
        'view_floorplan',
        'view_datacenters',
        'view_cabinets',
        'view_devices',
        'view_power',
        'view_cables',
        'view_snmp',
        'view_disposals',
        'view_audits',
        'view_reports',
        'view_notifications',
        'edit_devices_all',
        'edit_devices_dept',
        'edit_infrastructure', // floorplan, DCs, rooms, rows, cabinets
        'edit_power',
        'edit_cables',
        'edit_templates',
        'edit_disposals',
        'edit_audits',
        'edit_snmp',
        'manage_users',
        'manage_settings',
    ];

    /** Nav key → required view permission */
    public const NAV_PERMISSIONS = [
        'dashboard' => 'view_dashboard',
        'floorplan' => 'view_floorplan',
        'datacenters' => 'view_datacenters',
        'cabinets' => 'view_cabinets',
        'devices' => 'view_devices',
        'device_templates' => 'view_devices',
        'power' => 'view_power',
        'power_zones' => 'view_power',
        'power_pdus' => 'view_power',
        'cables' => 'view_cables',
        'snmp' => 'view_snmp',
        'disposals' => 'view_disposals',
        'audits' => 'view_audits',
        'reports' => 'view_reports',
        'notifications' => 'view_notifications',
        'users' => 'manage_users',
        'settings' => 'manage_settings',
    ];

    /**
     * Canonical system roles and their permission sets.
     * @return array<string, array{description:string, permissions:list<string>}>
     */
    public static function systemRoleDefinitions(): array
    {
        $viewAll = [
            'view_dashboard', 'view_floorplan', 'view_datacenters', 'view_cabinets',
            'view_devices', 'view_power', 'view_cables', 'view_snmp', 'view_disposals',
            'view_audits', 'view_reports', 'view_notifications',
        ];

        return [
            'Viewer' => [
                'description' => 'General view-only — inventory, power, reports (no changes)',
                'permissions' => $viewAll,
            ],
            'Department Admin' => [
                'description' => 'View all; fully modify devices (and decommission) in their department',
                'permissions' => array_merge($viewAll, [
                    'edit_devices_dept',
                    'edit_disposals',
                ]),
            ],
            'Data Center Admin' => [
                'description' => 'View all; modify zones, rows, cabinets, devices, power, cabling, lifecycle',
                'permissions' => array_merge($viewAll, [
                    'edit_devices_all',
                    'edit_infrastructure',
                    'edit_power',
                    'edit_cables',
                    'edit_templates',
                    'edit_disposals',
                    'edit_audits',
                    'edit_snmp',
                ]),
            ],
            'Global Admin' => [
                'description' => 'Full platform access including site settings and user administration',
                'permissions' => ['*'],
            ],
            // Legacy install/setup name — same as Global Admin
            'Administrator' => [
                'description' => 'Full platform access (legacy name for Global Admin)',
                'permissions' => ['*'],
            ],
        ];
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        static $cached = null;
        if ($cached && (int)$cached['user_id'] === (int)$_SESSION['user_id']) {
            return $cached;
        }
        try {
            $row = Database::fetchOne(
                'SELECT u.*, r.name AS role_name, r.permissions AS role_permissions,
                        d.name AS department_name, d.color_hex AS department_color, d.code AS department_code
                 FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 LEFT JOIN departments d ON d.department_id = u.department_id
                 WHERE u.user_id = ? AND u.is_active = 1',
                [(int)$_SESSION['user_id']]
            );
        } catch (Throwable $e) {
            $row = Database::fetchOne(
                'SELECT u.*, r.name AS role_name, r.permissions AS role_permissions
                 FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE u.user_id = ? AND u.is_active = 1',
                [(int)$_SESSION['user_id']]
            );
        }
        $cached = $row;
        return $row;
    }

    /** Clear per-request user cache (e.g. after role change mid-request — rare). */
    public static function clearUserCache(): void
    {
        // static $cached cannot be cleared from outside without a flag; re-login is fine
    }

    public static function login(array $user, string $source = 'local'): void
    {
        session_regenerate_id(true);
        // Rotate CSRF after privilege change (session fixation / stolen token)
        unset($_SESSION['_csrf']);
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['auth_source'] = $source;
        $_SESSION['login_at'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['_ua'] = self::userAgentFingerprint();

        Database::update('users', [
            'last_login' => date('Y-m-d H:i:s'),
        ], 'user_id = :id', [':id' => (int)$user['user_id']]);

        AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'login', 'user', (int)$user['user_id'], [
            'source' => $source,
        ]);
    }

    public static function logout(): void
    {
        $user = null;
        try {
            $user = self::user();
        } catch (Throwable $e) {
            $user = null;
        }
        if ($user) {
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'logout', 'user', (int)$user['user_id']);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'] ?: '/',
                'domain' => $p['domain'] ?? '',
                'secure' => !empty($p['secure']),
                'httponly' => !empty($p['httponly']),
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Idle / absolute session expiry + optional user-agent binding.
     * Called from App::boot() on web requests.
     */
    public static function touchSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
            return;
        }

        $sec = App::securityConfig();
        $now = time();
        $loginAt = (int)($_SESSION['login_at'] ?? $now);
        $last = (int)($_SESSION['last_activity'] ?? $loginAt);

        $absolute = (int)($sec['session_absolute_minutes'] ?? 1440);
        if ($absolute > 0 && ($now - $loginAt) > ($absolute * 60)) {
            self::expireSession('Session expired (maximum lifetime). Please sign in again.');
            return;
        }

        $idle = (int)($sec['session_idle_minutes'] ?? 480);
        if ($idle > 0 && ($now - $last) > ($idle * 60)) {
            self::expireSession('Session expired due to inactivity. Please sign in again.');
            return;
        }

        if (!empty($sec['bind_user_agent'])) {
            $fp = self::userAgentFingerprint();
            if (empty($_SESSION['_ua'])) {
                $_SESSION['_ua'] = $fp;
            } elseif (!hash_equals((string)$_SESSION['_ua'], $fp)) {
                self::expireSession('Session invalidated (client changed). Please sign in again.');
                return;
            }
        }

        $_SESSION['last_activity'] = $now;
    }

    private static function expireSession(string $message): void
    {
        $user = null;
        try {
            if (!empty($_SESSION['user_id'])) {
                $user = self::user();
            }
        } catch (Throwable $e) {
            $user = null;
        }
        if ($user) {
            try {
                AuditService::log(
                    (int)$user['user_id'],
                    $user['username'] ?? '',
                    'session_expired',
                    'user',
                    (int)$user['user_id'],
                    ['reason' => $message]
                );
            } catch (Throwable $e) {
                // non-fatal
            }
        }
        // Clear auth data but keep the secure session (for flash + login CSRF)
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_flash'][] = ['type' => 'error', 'message' => $message];
        if (App::isApiRequest()) {
            App::json(['error' => 'Session expired', 'message' => $message], 401);
        }
        App::redirect('login.php');
    }

    private static function userAgentFingerprint(): string
    {
        return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    /**
     * @return list<string>
     */
    public static function permissionsOf(array $user): array
    {
        $perms = json_decode($user['role_permissions'] ?? '[]', true);
        return is_array($perms) ? array_values(array_map('strval', $perms)) : [];
    }

    public static function can(array $user, string $permission): bool
    {
        $perms = self::permissionsOf($user);
        if (in_array('*', $perms, true)) {
            return true;
        }

        // Legacy / alias keys used by older pages
        $aliases = [
            'admin' => ['manage_settings', 'manage_users', '*'],
            'users' => ['manage_users'],
            'settings' => ['manage_settings'],
            'dashboard' => ['view_dashboard'],
            'datacenters' => ['view_datacenters', 'edit_infrastructure'],
            'cabinets' => ['view_cabinets', 'edit_infrastructure'],
            'devices' => ['view_devices', 'edit_devices_all', 'edit_devices_dept'],
            'power' => ['view_power', 'edit_power'],
            'cables' => ['view_cables', 'edit_cables'],
            'reports' => ['view_reports'],
            'audits' => ['view_audits', 'edit_audits'],
            'disposals' => ['view_disposals', 'edit_disposals'],
            'snmp' => ['view_snmp', 'edit_snmp'],
        ];

        $roleName = (string)($user['role_name'] ?? '');
        if (in_array($roleName, ['Administrator', 'Global Admin'], true)) {
            return true;
        }

        if (in_array($permission, $perms, true)) {
            return true;
        }

        // If checking a view_* and user has any edit for that domain
        if (str_starts_with($permission, 'view_')) {
            // edit implies view for related domains
            $editImplies = [
                'view_devices' => ['edit_devices_all', 'edit_devices_dept'],
                'view_cabinets' => ['edit_infrastructure'],
                'view_datacenters' => ['edit_infrastructure'],
                'view_floorplan' => ['edit_infrastructure'],
                'view_power' => ['edit_power'],
                'view_cables' => ['edit_cables'],
                'view_disposals' => ['edit_disposals'],
                'view_audits' => ['edit_audits'],
                'view_snmp' => ['edit_snmp'],
            ];
            foreach ($editImplies[$permission] ?? [] as $ep) {
                if (in_array($ep, $perms, true)) {
                    return true;
                }
            }
        }

        // Alias: request "devices" satisfied by view_devices etc.
        foreach ($aliases[$permission] ?? [] as $alt) {
            if ($alt === '*' || in_array($alt, $perms, true)) {
                return true;
            }
        }

        return false;
    }

    public static function canViewNav(array $user, string $navKey): bool
    {
        $perm = self::NAV_PERMISSIONS[$navKey] ?? null;
        if ($perm === null) {
            return true;
        }
        return self::can($user, $perm);
    }

    public static function isAdmin(array $user): bool
    {
        $name = (string)($user['role_name'] ?? '');
        if (in_array($name, ['Administrator', 'Global Admin'], true)) {
            return true;
        }
        $perms = self::permissionsOf($user);
        return in_array('*', $perms, true)
            || (self::can($user, 'manage_settings') && self::can($user, 'manage_users'));
    }

    public static function isGlobalAdmin(array $user): bool
    {
        return self::isAdmin($user);
    }

    public static function isDataCenterAdmin(array $user): bool
    {
        if (self::isAdmin($user)) {
            return true;
        }
        return self::can($user, 'edit_infrastructure') && self::can($user, 'edit_devices_all');
    }

    public static function isDepartmentAdmin(array $user): bool
    {
        if (self::isAdmin($user) || self::can($user, 'edit_devices_all')) {
            return true;
        }
        return self::can($user, 'edit_devices_dept');
    }

    public static function canManageDevices(array $user): bool
    {
        return self::can($user, 'edit_devices_all') || self::can($user, 'edit_devices_dept');
    }

    public static function canEditInfrastructure(array $user): bool
    {
        return self::can($user, 'edit_infrastructure');
    }

    public static function canEditPower(array $user): bool
    {
        return self::can($user, 'edit_power');
    }

    public static function canEditCables(array $user): bool
    {
        return self::can($user, 'edit_cables');
    }

    public static function canEditDisposals(array $user): bool
    {
        return self::can($user, 'edit_disposals');
    }

    public static function canEditSnmp(array $user): bool
    {
        return self::can($user, 'edit_snmp');
    }

    public static function canManageUsers(array $user): bool
    {
        return self::can($user, 'manage_users');
    }

    public static function canManageSettings(array $user): bool
    {
        return self::can($user, 'manage_settings');
    }

    /**
     * Department-scoped device edit:
     * - edit_devices_all / Global Admin: any device
     * - edit_devices_dept: only devices in user's department (or unassigned)
     * - view only: never
     */
    public static function canEditDevice(array $user, ?array $device): bool
    {
        if (self::can($user, 'edit_devices_all') || self::isAdmin($user)) {
            return true;
        }
        if (!self::can($user, 'edit_devices_dept')) {
            return false;
        }
        $userDept = !empty($user['department_id']) ? (int)$user['department_id'] : null;
        // Department Admin should have a department; if none, deny broad edit
        if ($userDept === null) {
            return false;
        }
        if ($device === null) {
            // Creating a device — allowed (will be forced into their department)
            return true;
        }
        $devDept = array_key_exists('department_id', $device) && $device['department_id'] !== null && $device['department_id'] !== ''
            ? (int)$device['department_id']
            : null;
        // Unassigned devices can be claimed by department admins
        if ($devDept === null) {
            return true;
        }
        return $userDept === $devDept;
    }

    /**
     * Resolve department_id from external group memberships (LDAPS / Entra).
     * @param list<string> $groupIds
     */
    public static function departmentIdFromGroups(string $authSource, array $groupIds): ?int
    {
        $groupIds = array_values(array_filter(array_map('strval', $groupIds), static fn($g) => $g !== ''));
        if (!$groupIds) {
            return null;
        }
        try {
            $maps = Database::fetchAll(
                'SELECT department_id, group_id FROM department_group_maps
                 WHERE is_active = 1 AND auth_source = ?',
                [strtolower($authSource)]
            );
        } catch (Throwable $e) {
            return null;
        }
        $want = [];
        foreach ($groupIds as $g) {
            $want[strtolower($g)] = true;
        }
        foreach ($maps as $m) {
            $gid = strtolower((string)($m['group_id'] ?? ''));
            if ($gid !== '' && isset($want[$gid])) {
                return (int)$m['department_id'];
            }
        }
        return null;
    }

    /**
     * Resolve role_id from external security groups (for AD/Entra login later).
     * Highest-privilege match wins (Global Admin > DC Admin > Dept Admin > Viewer).
     * @param list<string> $groupIds
     */
    public static function roleIdFromGroups(string $authSource, array $groupIds): ?int
    {
        $groupIds = array_values(array_filter(array_map('strval', $groupIds), static fn($g) => $g !== ''));
        if (!$groupIds) {
            return null;
        }
        try {
            $maps = Database::fetchAll(
                'SELECT m.role_id, m.group_id, r.name AS role_name
                 FROM role_group_maps m
                 INNER JOIN roles r ON r.role_id = m.role_id
                 WHERE m.is_active = 1 AND m.auth_source = ?',
                [strtolower($authSource)]
            );
        } catch (Throwable $e) {
            return null;
        }
        $want = [];
        foreach ($groupIds as $g) {
            $want[strtolower($g)] = true;
        }
        $rank = [
            'Global Admin' => 100,
            'Administrator' => 100,
            'Data Center Admin' => 80,
            'Department Admin' => 60,
            'Operator' => 70,
            'Viewer' => 20,
            'Auditor' => 30,
        ];
        $best = null;
        $bestScore = -1;
        foreach ($maps as $m) {
            $gid = strtolower((string)($m['group_id'] ?? ''));
            if ($gid === '' || !isset($want[$gid])) {
                continue;
            }
            $score = $rank[$m['role_name'] ?? ''] ?? 10;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int)$m['role_id'];
            }
        }
        return $best;
    }

    public static function attemptLocal(string $username, string $password): ?array
    {
        return LocalAuth::authenticate($username, $password);
    }

    public static function attemptLdap(string $username, string $password): ?array
    {
        if (!App::config('auth.ldaps.enabled')) {
            return null;
        }
        return LdapAuth::authenticate($username, $password);
    }

    public static function attempt(string $username, string $password): ?array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return null;
        }

        $localUser = Database::fetchOne(
            'SELECT * FROM users WHERE username = ? AND is_active = 1',
            [$username]
        );

        if ($localUser && ($localUser['auth_source'] ?? 'local') === 'local' && !empty($localUser['password_hash'])) {
            $user = LocalAuth::authenticate($username, $password);
            if ($user) {
                return $user;
            }
        }

        if (App::config('auth.ldaps.enabled')) {
            $user = LdapAuth::authenticate($username, $password);
            if ($user) {
                return $user;
            }
        }

        if (!$localUser || ($localUser['auth_source'] ?? '') !== 'local') {
            return LocalAuth::authenticate($username, $password);
        }

        return null;
    }
}
