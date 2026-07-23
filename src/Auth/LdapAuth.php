<?php
/**
 * ColdAisle - LDAPS authentication against Active Directory / LDAP
 */
declare(strict_types=1);

class LdapAuth
{
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
