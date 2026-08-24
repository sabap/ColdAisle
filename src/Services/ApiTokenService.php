<?php
/**
 * Machine API tokens for service accounts (api-service-*).
 *
 * Tokens are shown once, stored as HMAC-SHA256, never as plaintext.
 * They authenticate only /api/v1.php — not the browser UI.
 */
declare(strict_types=1);

class ApiTokenService
{
    public const PREFIX = 'ca_live_';
    public const USERNAME_PREFIX = 'api-service-';

    /** @var array<string,mixed>|null */
    private static ?array $tokenRow = null;

    public static function usernamePrefix(): string
    {
        return self::USERNAME_PREFIX;
    }

    public static function normalizeUsername(string $raw): string
    {
        $s = strtolower(trim($raw));
        if (str_starts_with($s, self::USERNAME_PREFIX)) {
            $s = substr($s, strlen(self::USERNAME_PREFIX));
        }
        $s = preg_replace('/[^a-z0-9-]+/', '-', $s) ?? $s;
        $s = trim($s, '-');
        if ($s === '') {
            throw new RuntimeException('Enter a short name (letters, numbers, hyphens). It becomes api-service-{name}.');
        }
        if (strlen($s) > 80) {
            throw new RuntimeException('Service account name is too long.');
        }
        return self::USERNAME_PREFIX . $s;
    }

    public static function isServiceAccount(?array $user): bool
    {
        if (!$user) {
            return false;
        }
        if (!empty($user['is_service_account'])) {
            return true;
        }
        if (strtolower((string)($user['auth_source'] ?? '')) === 'api') {
            return true;
        }
        return str_starts_with(strtolower((string)($user['username'] ?? '')), self::USERNAME_PREFIX);
    }

    public static function tokenId(): ?int
    {
        return self::$tokenRow ? (int)self::$tokenRow['token_id'] : null;
    }

    public static function tokenScopes(): string
    {
        return self::$tokenRow ? strtolower((string)(self::$tokenRow['scopes'] ?? 'read')) : '';
    }

    public static function hasWriteScope(): bool
    {
        $s = self::tokenScopes();
        return $s === 'write' || str_contains($s, 'write');
    }

    /**
     * If this request is for /api/v1 and a Bearer token is present, set the API user.
     * Returns the user or null (no token / not v1). Throws on bad token.
     */
    public static function authenticateRequest(): ?array
    {
        if (!self::isV1Request()) {
            return null;
        }
        $raw = self::bearerToken();
        if ($raw === null || $raw === '') {
            return null;
        }
        $user = self::userFromToken($raw);
        if (!$user) {
            throw new RuntimeException('Invalid or revoked API token.');
        }
        AuthManager::setApiUser($user);
        return $user;
    }

    /** @return array<string,mixed> */
    public static function requireToken(): array
    {
        try {
            $user = self::authenticateRequest();
        } catch (Throwable $e) {
            App::json(['error' => $e->getMessage()], 401);
        }
        if (!$user) {
            App::json(['error' => 'Unauthorized — send Authorization: Bearer ca_live_…'], 401);
        }
        if (empty($user['is_active'])) {
            App::json(['error' => 'Service account is disabled'], 403);
        }
        return $user;
    }

    public static function isV1Request(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        return str_contains($uri, '/api/v1.php')
            || str_contains($script, '/api/v1.php')
            || str_contains($uri, '/api/v1/');
    }

    /**
     * @return array{token:string,prefix:string,token_id:int}
     */
    public static function createToken(int $userId, string $name, string $scopes, ?int $createdBy, ?DateTimeInterface $expires = null): array
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Token';
        }
        $scopes = strtolower(trim($scopes));
        if (!in_array($scopes, ['read', 'write'], true)) {
            $scopes = 'read';
        }
        $secret = bin2hex(random_bytes(24));
        $plain = self::PREFIX . $secret;
        $prefix = substr($plain, 0, 16);
        $hash = self::hashToken($plain);
        $id = (int)Database::insert('api_tokens', [
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 100),
            'token_prefix' => $prefix,
            'token_hash' => $hash,
            'scopes' => $scopes,
            'created_by' => $createdBy,
            'expires_at' => $expires ? $expires->format('Y-m-d H:i:s') : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['token' => $plain, 'prefix' => $prefix, 'token_id' => $id];
    }

    public static function revokeToken(int $tokenId, int $userId): void
    {
        Database::update('api_tokens', [
            'revoked_at' => date('Y-m-d H:i:s'),
        ], 'token_id = :id AND user_id = :uid AND revoked_at IS NULL', [
            ':id' => $tokenId,
            ':uid' => $userId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function listForUser(int $userId): array
    {
        try {
            return Database::fetchAll(
                'SELECT token_id, user_id, name, token_prefix, scopes, last_used_at, expires_at, revoked_at, created_at
                 FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC',
                [$userId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function stripSecrets(array $row): array
    {
        foreach ([
            'snmp_community', 'snmp_v3_auth_pass', 'snmp_v3_priv_pass',
            'snmp_auth_passphrase', 'snmp_priv_passphrase', 'password_hash',
            'bind_password', 'client_secret',
        ] as $k) {
            unset($row[$k]);
        }
        return $row;
    }

    private static function bearerToken(): ?string
    {
        $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)/i', $hdr, $m)) {
            return $m[1];
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private static function userFromToken(string $plain): ?array
    {
        $plain = trim($plain);
        if (!str_starts_with($plain, self::PREFIX) || strlen($plain) < 20) {
            return null;
        }
        $prefix = substr($plain, 0, 16);
        $hash = self::hashToken($plain);
        $now = date('Y-m-d H:i:s');
        try {
            $rows = Database::fetchAll(
                'SELECT * FROM api_tokens
                 WHERE token_prefix = ? AND revoked_at IS NULL
                   AND (expires_at IS NULL OR expires_at > ?)',
                [$prefix, $now]
            );
        } catch (Throwable $e) {
            return null;
        }
        $tok = null;
        foreach ($rows as $row) {
            if (hash_equals((string)$row['token_hash'], $hash)) {
                $tok = $row;
                break;
            }
        }
        if (!$tok) {
            return null;
        }
        $user = Database::fetchOne(
            'SELECT u.*, r.name AS role_name, r.permissions AS role_permissions,
                    d.name AS department_name, d.color_hex AS department_color, d.code AS department_code
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN departments d ON d.department_id = u.department_id
             WHERE u.user_id = ? AND u.is_active = 1',
            [(int)$tok['user_id']]
        );
        if (!$user || !self::isServiceAccount($user)) {
            return null;
        }
        self::$tokenRow = $tok;
        $last = (string)($tok['last_used_at'] ?? '');
        if ($last === '' || strtotime($last) < time() - 60) {
            try {
                Database::update('api_tokens', [
                    'last_used_at' => $now,
                ], 'token_id = :id', [':id' => (int)$tok['token_id']]);
            } catch (Throwable $e) {
                // ignore
            }
        }
        $user['_api_token_id'] = (int)$tok['token_id'];
        $user['_api_scopes'] = (string)$tok['scopes'];
        return $user;
    }

    private static function hashToken(string $plain): string
    {
        $pepper = (string)(App::config('app_key') ?? '');
        if ($pepper === '') {
            $pepper = 'coldaisle-api-v1';
        }
        return hash_hmac('sha256', $plain, $pepper);
    }
}
