<?php
/**
 * ColdAisle - LDAPS authentication against Active Directory / LDAP
 */
declare(strict_types=1);

class LdapAuth
{
    /**
     * Connectivity / config test for Settings UI (does not create users).
     *
     * @param array<string,mixed>|null $cfg Override config (form values); null = saved config
     * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function testConnection(?array $cfg = null, ?string $testUsername = null, ?string $testPassword = null): array
    {
        $steps = [];
        $add = static function (string $name, bool $ok, string $detail) use (&$steps): void {
            $steps[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        if (!function_exists('ldap_connect')) {
            $add('PHP LDAP extension', false, 'ldap extension is not loaded. Enable extension=ldap in php.ini and restart IIS/PHP.');
            return self::testResult(false, 'PHP LDAP extension missing.', $steps);
        }
        $add('PHP LDAP extension', true, 'ldap extension is loaded.');

        $cfg = is_array($cfg) ? $cfg : (App::config('auth.ldaps', []) ?: []);
        $host = trim((string)($cfg['host'] ?? ''));
        $port = (int)($cfg['port'] ?? 636);
        if ($port <= 0) {
            $port = 636;
        }
        $baseDn = trim((string)($cfg['base_dn'] ?? ''));
        $userFilter = trim((string)($cfg['user_filter'] ?? '(sAMAccountName={username})'));
        if ($userFilter === '') {
            $userFilter = '(sAMAccountName={username})';
        }
        $bindDn = trim((string)($cfg['bind_dn'] ?? ''));
        $bindPassword = (string)($cfg['bind_password'] ?? '');
        $useStartTls = !empty($cfg['start_tls']);
        $useSsl = ($cfg['use_ssl'] ?? true) || $port === 636;

        if ($host === '') {
            $add('Configuration', false, 'Host is required.');
            return self::testResult(false, 'Host is required.', $steps);
        }
        if ($baseDn === '') {
            $add('Configuration', false, 'Base DN is required.');
            return self::testResult(false, 'Base DN is required.', $steps);
        }
        $add(
            'Configuration',
            true,
            ($useSsl && !$useStartTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port
            . ' · Base DN: ' . $baseDn
            . ($useStartTls ? ' · STARTTLS' : '')
            . ($useSsl && !$useStartTls ? ' · SSL' : '')
        );

        $uri = ($useSsl && !$useStartTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if (!$conn) {
            $add('Connect', false, 'ldap_connect failed for ' . $uri);
            return self::testResult(false, 'Could not open LDAP connection.', $steps);
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 8);
        if (defined('LDAP_OPT_TIMELIMIT')) {
            @ldap_set_option($conn, LDAP_OPT_TIMELIMIT, 10);
        }
        $add('Connect', true, 'Connected to ' . $uri);

        if ($useStartTls) {
            if (!@ldap_start_tls($conn)) {
                $err = ldap_error($conn);
                @ldap_unbind($conn);
                $add('STARTTLS', false, $err);
                return self::testResult(false, 'STARTTLS failed: ' . $err, $steps);
            }
            $add('STARTTLS', true, 'TLS negotiated.');
        }

        if ($bindDn !== '') {
            if (!@ldap_bind($conn, $bindDn, $bindPassword)) {
                $err = ldap_error($conn);
                @ldap_unbind($conn);
                $add('Service bind', false, $err . ' (check Bind DN and password — leave password blank on the form to use the saved value).');
                return self::testResult(false, 'Service account bind failed.', $steps);
            }
            $add('Service bind', true, 'Bound as service account.');
        } else {
            // Anonymous bind attempt (many ADs disallow this)
            if (!@ldap_bind($conn)) {
                $err = ldap_error($conn);
                @ldap_unbind($conn);
                $add('Service bind', false, 'No Bind DN configured and anonymous bind failed: ' . $err);
                return self::testResult(false, 'Provide a Bind DN / password for directory search.', $steps);
            }
            $add('Service bind', true, 'Anonymous bind succeeded (no Bind DN configured).');
        }

        // Lightweight search to prove base DN is reachable
        $probe = @ldap_search($conn, $baseDn, '(objectClass=*)', ['dn'], 0, 1, 5);
        if ($probe === false) {
            $err = ldap_error($conn);
            @ldap_unbind($conn);
            $add('Base DN search', false, $err);
            return self::testResult(false, 'Could not search Base DN.', $steps);
        }
        $add('Base DN search', true, 'Directory search against Base DN succeeded.');

        $testUsername = trim((string)$testUsername);
        $testPassword = (string)$testPassword;
        if ($testUsername !== '') {
            $escapedUser = self::escapeFilter($testUsername);
            $filter = str_replace(
                ['{username}', '{user}'],
                [$escapedUser, $escapedUser],
                $userFilter
            );
            $search = @ldap_search(
                $conn,
                $baseDn,
                $filter,
                ['dn', 'mail', 'displayName', 'cn', 'sAMAccountName', 'userPrincipalName'],
                0,
                5,
                8
            );
            if ($search === false) {
                $err = ldap_error($conn);
                @ldap_unbind($conn);
                $add('User lookup', false, $err . ' · filter: ' . $filter);
                return self::testResult(false, 'User search failed.', $steps);
            }
            $entries = ldap_get_entries($conn, $search);
            if (empty($entries['count']) || (int)$entries['count'] < 1) {
                @ldap_unbind($conn);
                $add('User lookup', false, 'No entry matched filter: ' . $filter);
                return self::testResult(false, 'Test user not found in directory.', $steps);
            }
            $entry = $entries[0];
            $userDn = (string)($entry['dn'] ?? '');
            $display = $entry['displayname'][0] ?? ($entry['cn'][0] ?? $testUsername);
            $add('User lookup', true, 'Found ' . $display . ' · ' . $userDn);

            if ($testPassword !== '') {
                if (!@ldap_bind($conn, $userDn, $testPassword)) {
                    $err = ldap_error($conn);
                    @ldap_unbind($conn);
                    $add('User password bind', false, $err);
                    return self::testResult(false, 'Test user password bind failed.', $steps);
                }
                $add('User password bind', true, 'Test user credentials accepted.');
            } else {
                $add('User password bind', true, 'Skipped (no test password provided).');
            }
        } else {
            $add('User lookup', true, 'Skipped (optional — enter a test username to verify the filter).');
        }

        @ldap_unbind($conn);
        return self::testResult(true, 'LDAPS connection test passed.', $steps);
    }

    /**
     * @param list<array{name:string,ok:bool,detail:string}> $steps
     * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    private static function testResult(bool $ok, string $summary, array $steps): array
    {
        return ['ok' => $ok, 'summary' => $summary, 'steps' => $steps];
    }

    public static function authenticate(string $username, string $password): ?array
    {
        if (!function_exists('ldap_connect')) {
            App::log('LDAP extension not loaded', 'error');
            return null;
        }

        $cfg = App::config('auth.ldaps', []);
        $host = $cfg['host'] ?? '';
        $port = (int)($cfg['port'] ?? 636);
        $baseDn = $cfg['base_dn'] ?? '';
        $userFilter = $cfg['user_filter'] ?? '(sAMAccountName={username})';
        $bindDn = $cfg['bind_dn'] ?? '';
        $bindPassword = $cfg['bind_password'] ?? '';
        $useStartTls = !empty($cfg['start_tls']);
        $useSsl = ($cfg['use_ssl'] ?? true) || $port === 636;

        if ($host === '' || $baseDn === '') {
            return null;
        }

        $uri = ($useSsl && !$useStartTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if (!$conn) {
            App::log("LDAP connect failed to {$uri}", 'error');
            return null;
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (!empty($cfg['network_timeout'])) {
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)$cfg['network_timeout']);
        }

        if ($useStartTls) {
            if (!@ldap_start_tls($conn)) {
                App::log('LDAP STARTTLS failed: ' . ldap_error($conn), 'error');
                return null;
            }
        }

        // Service bind for search
        if ($bindDn !== '') {
            if (!@ldap_bind($conn, $bindDn, $bindPassword)) {
                App::log('LDAP service bind failed: ' . ldap_error($conn), 'error');
                return null;
            }
        }

        $escapedUser = self::escapeFilter($username);
        $filter = str_replace(
            ['{username}', '{user}'],
            [$escapedUser, $escapedUser],
            $userFilter
        );

        $search = @ldap_search($conn, $baseDn, $filter, ['dn', 'mail', 'displayName', 'cn', 'sAMAccountName', 'userPrincipalName']);
        if (!$search) {
            App::log('LDAP search failed: ' . ldap_error($conn), 'error');
            return null;
        }

        $entries = ldap_get_entries($conn, $search);
        if (empty($entries['count']) || $entries['count'] < 1) {
            return null;
        }

        $entry = $entries[0];
        $userDn = $entry['dn'];

        // Bind as user to verify password
        if (!@ldap_bind($conn, $userDn, $password)) {
            return null;
        }

        $email = $entry['mail'][0] ?? ($entry['userprincipalname'][0] ?? $username . '@local');
        $display = $entry['displayname'][0] ?? ($entry['cn'][0] ?? $username);
        $sam = $entry['samaccountname'][0] ?? $username;

        @ldap_unbind($conn);

        return self::upsertUser($sam, $email, $display, $userDn);
    }

    private static function escapeFilter(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', defined('LDAP_ESCAPE_FILTER') ? LDAP_ESCAPE_FILTER : 1);
        }
        return str_replace(
            ['\\', '*', '(', ')', "\x00"],
            ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
            $value
        );
    }

    private static function upsertUser(string $username, string $email, string $displayName, string $externalId): array
    {
        $existing = Database::fetchOne(
            'SELECT u.*, r.name AS role_name, r.permissions AS role_permissions
             FROM users u INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.username = ? OR u.external_id = ?',
            [$username, $externalId]
        );

        if ($existing) {
            Database::update('users', [
                'email' => $email,
                'display_name' => $displayName,
                'external_id' => $externalId,
                'auth_source' => 'ldaps',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'user_id = :id', [':id' => (int)$existing['user_id']]);

            return Database::fetchOne(
                'SELECT u.*, r.name AS role_name, r.permissions AS role_permissions
                 FROM users u INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE u.user_id = ?',
                [(int)$existing['user_id']]
            ) ?? $existing;
        }

        $defaultRole = (int)(App::config('auth.ldaps.default_role_id')
            ?? Database::fetchValue("SELECT role_id FROM roles WHERE name = 'Viewer'")
            ?? 4);

        $id = Database::insert('users', [
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'password_hash' => null,
            'auth_source' => 'ldaps',
            'external_id' => $externalId,
            'role_id' => $defaultRole,
            'is_active' => 1,
        ]);

        return Database::fetchOne(
            'SELECT u.*, r.name AS role_name, r.permissions AS role_permissions
             FROM users u INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ?',
            [$id]
        );
    }
}
