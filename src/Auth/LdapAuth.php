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
        $parsed = self::parseConfig($cfg);

        if ($parsed['host'] === '') {
            $add('Configuration', false, 'Host is required.');
            return self::testResult(false, 'Host is required.', $steps);
        }
        if ($parsed['base_dn'] === '') {
            $add('Configuration', false, 'Base DN is required.');
            return self::testResult(false, 'Base DN is required.', $steps);
        }

        $tlsNote = $parsed['tls_insecure']
            ? ' · TLS cert verify OFF (internal CA / lab)'
            : ' · TLS cert verify ON';
        $add(
            'Configuration',
            true,
            $parsed['uri']
            . ' · Base DN: ' . $parsed['base_dn']
            . ($parsed['start_tls'] ? ' · STARTTLS' : '')
            . ($parsed['use_ssl'] && !$parsed['start_tls'] ? ' · SSL' : '')
            . $tlsNote
        );

        $tlsPrep = self::prepareTlsEnvironment($parsed['tls_insecure']);
        $add('TLS setup', true, $tlsPrep['detail']);

        // ldap_connect is lazy — real TCP/TLS happens on first bind/search
        $conn = @ldap_connect($parsed['uri']);
        if (!$conn) {
            $add('LDAP handle', false, 'ldap_connect failed for ' . $parsed['uri']);
            return self::testResult(false, 'Could not create LDAP handle.', $steps);
        }
        self::applyConnectionOptions($conn, $cfg);
        $add(
            'LDAP handle',
            true,
            'Handle created for ' . $parsed['uri']
            . ' (TCP/TLS is not confirmed until bind — that is normal for PHP LDAP).'
        );

        if ($parsed['start_tls']) {
            if (!@ldap_start_tls($conn)) {
                $err = ldap_error($conn);
                $errno = ldap_errno($conn);
                @ldap_unbind($conn);
                $add('STARTTLS', false, self::explainLdapError($err, $errno, $parsed));
                return self::testResult(false, 'STARTTLS failed.', $steps);
            }
            $add('STARTTLS', true, 'TLS negotiated.');
        }

        if ($parsed['bind_dn'] !== '') {
            if ($parsed['bind_password'] === '') {
                $add(
                    'Service bind',
                    false,
                    'Bind password is empty. Enter it on the form (or Save LDAPS first). '
                    . '“Leave blank to keep” only works after a password has been saved.'
                );
                @ldap_unbind($conn);
                return self::testResult(false, 'Service bind password is empty.', $steps);
            }
            if (!@ldap_bind($conn, $parsed['bind_dn'], $parsed['bind_password'])) {
                $err = ldap_error($conn);
                $errno = ldap_errno($conn);
                @ldap_unbind($conn);
                $add('Service bind', false, self::explainLdapError($err, $errno, $parsed));
                return self::testResult(false, 'Service account bind failed.', $steps);
            }
            $add('Service bind', true, 'Bound as service account.');
        } else {
            if (!@ldap_bind($conn)) {
                $err = ldap_error($conn);
                $errno = ldap_errno($conn);
                @ldap_unbind($conn);
                $add('Service bind', false, 'No Bind DN configured and anonymous bind failed: '
                    . self::explainLdapError($err, $errno, $parsed));
                return self::testResult(false, 'Provide a Bind DN / password for directory search.', $steps);
            }
            $add('Service bind', true, 'Anonymous bind succeeded (no Bind DN configured).');
        }

        $probe = @ldap_search($conn, $parsed['base_dn'], '(objectClass=*)', ['dn'], 0, 1, 5);
        if ($probe === false) {
            $err = ldap_error($conn);
            $errno = ldap_errno($conn);
            @ldap_unbind($conn);
            $add('Base DN search', false, self::explainLdapError($err, $errno, $parsed));
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
                $parsed['user_filter']
            );
            $search = @ldap_search(
                $conn,
                $parsed['base_dn'],
                $filter,
                ['dn', 'mail', 'displayName', 'cn', 'sAMAccountName', 'userPrincipalName'],
                0,
                5,
                8
            );
            if ($search === false) {
                $err = ldap_error($conn);
                $errno = ldap_errno($conn);
                @ldap_unbind($conn);
                $add('User lookup', false, self::explainLdapError($err, $errno, $parsed) . ' · filter: ' . $filter);
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
                    $errno = ldap_errno($conn);
                    @ldap_unbind($conn);
                    $add('User password bind', false, self::explainLdapError($err, $errno, $parsed));
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

        $cfg = App::config('auth.ldaps', []) ?: [];
        $parsed = self::parseConfig($cfg);
        if ($parsed['host'] === '' || $parsed['base_dn'] === '') {
            return null;
        }

        self::prepareTlsEnvironment($parsed['tls_insecure']);

        $conn = @ldap_connect($parsed['uri']);
        if (!$conn) {
            App::log("LDAP connect failed to {$parsed['uri']}", 'error');
            return null;
        }

        self::applyConnectionOptions($conn, $cfg);

        if ($parsed['start_tls']) {
            if (!@ldap_start_tls($conn)) {
                App::log('LDAP STARTTLS failed: ' . ldap_error($conn), 'error');
                return null;
            }
        }

        if ($parsed['bind_dn'] !== '') {
            if (!@ldap_bind($conn, $parsed['bind_dn'], $parsed['bind_password'])) {
                App::log('LDAP service bind failed: ' . ldap_error($conn) . ' errno=' . ldap_errno($conn), 'error');
                return null;
            }
        }

        $escapedUser = self::escapeFilter($username);
        $filter = str_replace(
            ['{username}', '{user}'],
            [$escapedUser, $escapedUser],
            $parsed['user_filter']
        );

        $search = @ldap_search($conn, $parsed['base_dn'], $filter, ['dn', 'mail', 'displayName', 'cn', 'sAMAccountName', 'userPrincipalName']);
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

        if (!@ldap_bind($conn, $userDn, $password)) {
            return null;
        }

        $email = $entry['mail'][0] ?? ($entry['userprincipalname'][0] ?? $username . '@local');
        $display = $entry['displayname'][0] ?? ($entry['cn'][0] ?? $username);
        $sam = $entry['samaccountname'][0] ?? $username;

        @ldap_unbind($conn);

        return self::upsertUser($sam, $email, $display, $userDn);
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array{
     *   host:string,port:int,base_dn:string,user_filter:string,bind_dn:string,bind_password:string,
     *   use_ssl:bool,start_tls:bool,tls_insecure:bool,uri:string
     * }
     */
    private static function parseConfig(array $cfg): array
    {
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
        $useStartTls = !empty($cfg['start_tls']);
        $useSsl = !empty($cfg['use_ssl']) || $port === 636;
        // Default use_ssl true when key missing
        if (!array_key_exists('use_ssl', $cfg)) {
            $useSsl = true || $port === 636;
        }
        $uri = ($useSsl && !$useStartTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port;

        return [
            'host' => $host,
            'port' => $port,
            'base_dn' => $baseDn,
            'user_filter' => $userFilter,
            'bind_dn' => trim((string)($cfg['bind_dn'] ?? '')),
            'bind_password' => (string)($cfg['bind_password'] ?? ''),
            'use_ssl' => $useSsl,
            'start_tls' => $useStartTls,
            'tls_insecure' => !empty($cfg['tls_insecure']),
            'uri' => $uri,
        ];
    }

    /**
     * Must run BEFORE ldap_connect for global TLS options to take effect.
     * @return array{detail:string}
     */
    private static function prepareTlsEnvironment(bool $insecure): array
    {
        $parts = [];

        if ($insecure) {
            // Internal enterprise CAs often are not in PHP/OpenLDAP trust store
            if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
                @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
                $parts[] = 'LDAP_OPT_X_TLS_REQUIRE_CERT=never';
            }
            @putenv('LDAPTLS_REQCERT=never');
            $parts[] = 'LDAPTLS_REQCERT=never';
            return ['detail' => 'Insecure TLS mode: ' . implode(', ', $parts)];
        }

        if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_DEMAND')) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            $parts[] = 'require cert=demand';
        }
        @putenv('LDAPTLS_REQCERT=demand');

        $ca = self::resolveLdapCaFile();
        if ($ca !== null) {
            if (defined('LDAP_OPT_X_TLS_CACERTFILE')) {
                @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $ca);
            }
            @putenv('LDAPTLS_CACERT=' . $ca);
            $parts[] = 'CA file: ' . $ca;
        } else {
            $parts[] = 'no app CA file found (using system defaults only — if bind fails with Can\'t contact LDAP server, trust your internal CA or enable Skip LDAPS certificate verify)';
        }

        return ['detail' => implode(' · ', $parts)];
    }

    /** Path where enterprise LDAPS CA chain is stored (uploaded from Settings). */
    public static function enterpriseCaPath(): string
    {
        return App::ROOT . '/config/ldap-ca.pem';
    }

    /**
     * @return array{installed:bool,path:?string,bytes:int,cert_count:int,subjects:list<string>}
     */
    public static function enterpriseCaStatus(): array
    {
        $path = self::enterpriseCaPath();
        if (!is_file($path) || filesize($path) < 50) {
            return [
                'installed' => false,
                'path' => null,
                'bytes' => 0,
                'cert_count' => 0,
                'subjects' => [],
            ];
        }
        $pem = (string)file_get_contents($path);
        $subjects = self::pemSubjects($pem);
        return [
            'installed' => true,
            'path' => $path,
            'bytes' => (int)filesize($path),
            'cert_count' => count($subjects),
            'subjects' => $subjects,
        ];
    }

    /**
     * Install uploaded enterprise CA (PEM or DER/.cer) for LDAPS trust.
     *
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file $_FILES entry
     * @return array{ok:bool,message:string,path:string,cert_count:int,subjects:list<string>}
     */
    public static function installEnterpriseCaUpload(array $file, bool $append = false): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($err));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }
        $raw = (string)file_get_contents($tmp);
        if ($raw === '') {
            throw new RuntimeException('Uploaded file is empty.');
        }
        $name = (string)($file['name'] ?? 'ca.cer');
        $pem = self::normalizeToPem($raw, $name);
        $subjects = self::pemSubjects($pem);
        if ($subjects === []) {
            throw new RuntimeException(
                'No X.509 certificates found. Upload a .pem / .crt / .cer file '
                . '(Base-64 or DER). For AD CS, export the root CA certificate.'
            );
        }

        $path = self::enterpriseCaPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('config/ is not writable — cannot save ldap-ca.pem.');
        }

        $out = $pem;
        if ($append && is_file($path) && filesize($path) > 50) {
            $existing = rtrim((string)file_get_contents($path));
            $out = $existing . "\n\n" . $pem;
        }
        if (!str_ends_with($out, "\n")) {
            $out .= "\n";
        }
        if (file_put_contents($path, $out) === false) {
            throw new RuntimeException('Could not write ' . $path);
        }
        @chmod($path, 0640);

        $finalSubjects = self::pemSubjects((string)file_get_contents($path));
        return [
            'ok' => true,
            'message' => 'Enterprise CA installed for LDAPS trust ('
                . count($finalSubjects) . ' certificate(s) in config/ldap-ca.pem). '
                . 'Uncheck “Skip LDAPS certificate verify” and re-test.',
            'path' => $path,
            'cert_count' => count($finalSubjects),
            'subjects' => $finalSubjects,
        ];
    }

    public static function removeEnterpriseCa(): bool
    {
        $path = self::enterpriseCaPath();
        if (!is_file($path)) {
            return false;
        }
        return @unlink($path);
    }

    /** Convert PEM or DER bytes into one or more PEM certificates. */
    private static function normalizeToPem(string $raw, string $filename): string
    {
        $trim = trim($raw);
        if (str_contains($trim, 'BEGIN CERTIFICATE')) {
            // Already PEM (possibly multiple)
            if (!preg_match_all(
                '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
                $trim,
                $m
            )) {
                throw new RuntimeException('PEM file did not contain a CERTIFICATE block.');
            }
            return implode("\n", $m[0]) . "\n";
        }

        // Base64 body without headers (common Windows "Base-64 X.509 (.CER)")
        $compact = preg_replace('/\s+/', '', $trim) ?? '';
        if ($compact !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact) && strlen($compact) > 100) {
            $der = base64_decode($compact, true);
            if ($der !== false && $der !== '') {
                $raw = $der;
            }
        }

        // DER binary
        if (function_exists('openssl_x509_read')) {
            $x509 = @openssl_x509_read($raw);
            if ($x509 === false) {
                // Try as file-style PEM reconstruction failed; last try base64 wrap
                $x509 = @openssl_x509_read(
                    "-----BEGIN CERTIFICATE-----\n"
                    . chunk_split(base64_encode($raw), 64, "\n")
                    . "-----END CERTIFICATE-----\n"
                );
            }
            if ($x509 !== false) {
                $out = '';
                if (!@openssl_x509_export($x509, $out) || $out === '') {
                    throw new RuntimeException('Could not export certificate to PEM.');
                }
                return $out;
            }
        }

        throw new RuntimeException(
            'Could not parse certificate from “' . $filename . '”. '
            . 'Export as Base-64 X.509 (.CER) or PEM from AD Certificate Services.'
        );
    }

    /** @return list<string> */
    private static function pemSubjects(string $pem): array
    {
        $subjects = [];
        if (!function_exists('openssl_x509_parse')) {
            if (preg_match_all('/BEGIN CERTIFICATE/', $pem, $m)) {
                return array_fill(0, count($m[0]), '(certificate)');
            }
            return [];
        }
        if (!preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $pem,
            $blocks
        )) {
            return [];
        }
        foreach ($blocks[0] as $block) {
            $x509 = @openssl_x509_read($block);
            if ($x509 === false) {
                continue;
            }
            $info = @openssl_x509_parse($x509);
            if (is_array($info)) {
                $name = $info['subject']['CN']
                    ?? $info['subject']['OU']
                    ?? $info['name']
                    ?? 'certificate';
                if (is_array($name)) {
                    $name = implode(', ', $name);
                }
                $subjects[] = (string)$name;
            } else {
                $subjects[] = 'certificate';
            }
        }
        return $subjects;
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Upload exceeds PHP size limit (upload_max_filesize / post_max_size).',
            UPLOAD_ERR_PARTIAL => 'Upload was incomplete — try again.',
            UPLOAD_ERR_NO_FILE => 'No file selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write the upload to disk.',
            default => 'Upload failed (error code ' . $code . ').',
        };
    }

    private static function resolveLdapCaFile(): ?string
    {
        // Prefer enterprise CA uploaded for LDAPS
        $enterprise = self::enterpriseCaPath();
        if (is_file($enterprise) && filesize($enterprise) > 50) {
            return $enterprise;
        }

        $candidates = [
            App::ROOT . '/config/cacert.pem',
        ];
        // Reuse GitHub CA bundle helper if present
        if (class_exists('UpdateService')) {
            try {
                $status = UpdateService::caBundleStatus();
                if (!empty($status['path']) && is_file((string)$status['path'])) {
                    $candidates[] = (string)$status['path'];
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        $iniCa = trim((string)ini_get('openssl.cafile'));
        if ($iniCa !== '') {
            $candidates[] = $iniCa;
        }
        foreach ($candidates as $p) {
            $p = trim($p);
            if ($p !== '' && is_file($p) && filesize($p) > 500) {
                return $p;
            }
        }
        return null;
    }

    /** @param resource|\LDAP\Connection $conn */
    private static function applyConnectionOptions($conn, array $cfg): void
    {
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        $timeout = (int)($cfg['network_timeout'] ?? 10);
        if ($timeout < 3) {
            $timeout = 10;
        }
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        if (defined('LDAP_OPT_TIMELIMIT')) {
            @ldap_set_option($conn, LDAP_OPT_TIMELIMIT, $timeout);
        }
    }

    /**
     * @param array<string,mixed> $parsed from parseConfig
     */
    private static function explainLdapError(string $err, int $errno, array $parsed): string
    {
        $msg = $err !== '' ? $err : 'unknown error';
        $msg .= ' (ldap_errno=' . $errno . ')';

        $lower = strtolower($err);
        if (
            str_contains($lower, "can't contact")
            || str_contains($lower, 'server is unavailable')
            || $errno === -1
            || $errno === 81
        ) {
            $msg .= '. This often means the TCP/TLS handshake failed (not a wrong Bind DN). '
                . 'Check: (1) web server can reach ' . ($parsed['host'] ?? '') . ':' . ($parsed['port'] ?? 636)
                . ' (firewall), (2) LDAPS certificate is trusted by PHP/OpenLDAP, '
                . '(3) try enabling “Skip LDAPS certificate verify” for internal PKI, '
                . '(4) optional: place your enterprise root CA PEM in config/ldap-ca.pem.';
        } elseif (str_contains($lower, 'invalid credentials') || $errno === 49) {
            $msg .= '. Bind DN or password is wrong, account locked/disabled, or password expired.';
        } elseif (str_contains($lower, 'stronger auth') || $errno === 8) {
            $msg .= '. Server requires LDAPS/STARTTLS — enable Use LDAPS (SSL) or STARTTLS.';
        }

        return $msg;
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
