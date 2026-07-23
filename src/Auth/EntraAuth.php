<?php
/**
 * ColdAisle - Microsoft Entra ID (Azure AD) OIDC / OAuth2 SSO
 */
declare(strict_types=1);

class EntraAuth
{
    public static function isEnabled(): bool
    {
        return (bool) App::config('auth.entra.enabled')
            && App::config('auth.entra.client_id')
            && App::config('auth.entra.tenant_id');
    }

    public static function authorizeUrl(string $state): string
    {
        $tenant = App::config('auth.entra.tenant_id', 'common');
        $clientId = App::config('auth.entra.client_id');
        $redirectUri = App::config('auth.entra.redirect_uri') ?: (App::baseUrl() . '/login_entra.php');
        $scopes = App::config('auth.entra.scopes', 'openid profile email offline_access');

        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => $scopes,
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?{$params}";
    }

    public static function handleCallback(string $code): ?array
    {
        $tenant = App::config('auth.entra.tenant_id', 'common');
        $clientId = App::config('auth.entra.client_id');
        $clientSecret = App::config('auth.entra.client_secret');
        $redirectUri = App::config('auth.entra.redirect_uri') ?: (App::baseUrl() . '/login_entra.php');

        $tokenUrl = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
        $post = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => App::config('auth.entra.scopes', 'openid profile email offline_access'),
        ];

        $tokenResponse = self::httpPost($tokenUrl, $post);
        if (!$tokenResponse || empty($tokenResponse['id_token'])) {
            App::log('Entra token exchange failed: ' . json_encode($tokenResponse), 'error');
            return null;
        }

        $claims = self::decodeJwtPayload($tokenResponse['id_token']);
        if (!$claims) {
            return null;
        }

        // Optional access token for Graph profile
        $email = $claims['email']
            ?? $claims['preferred_username']
            ?? $claims['upn']
            ?? null;
        $oid = $claims['oid'] ?? $claims['sub'] ?? null;
        $name = $claims['name'] ?? $email ?? 'Entra User';
        $username = self::usernameFromClaims($claims);

        if (!$oid || !$email) {
            App::log('Entra claims missing oid/email: ' . json_encode($claims), 'error');
            return null;
        }

        return self::upsertUser($username, $email, $name, $oid);
    }

    private static function usernameFromClaims(array $claims): string
    {
        $upn = $claims['preferred_username'] ?? $claims['upn'] ?? $claims['email'] ?? '';
        if (str_contains($upn, '@')) {
            return strtolower(explode('@', $upn)[0]);
        }
        return strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', $upn) ?: ('entra_' . substr($claims['oid'] ?? uniqid(), 0, 8)));
    }

    private static function upsertUser(string $username, string $email, string $displayName, string $externalId): array
    {
        $existing = Database::fetchOne(
            'SELECT u.*, r.name AS role_name, r.permissions AS role_permissions
             FROM users u INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.external_id = ? OR (u.auth_source = \'entra\' AND u.email = ?) OR u.username = ?',
            [$externalId, $email, $username]
        );

        if ($existing) {
            Database::update('users', [
                'email' => $email,
                'display_name' => $displayName,
                'external_id' => $externalId,
                'auth_source' => 'entra',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'user_id = :id', [':id' => (int)$existing['user_id']]);

            return Database::fetchOne(
                'SELECT u.*, r.name AS role_name, r.permissions AS role_permissions
                 FROM users u INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE u.user_id = ?',
                [(int)$existing['user_id']]
            ) ?? $existing;
        }

        // Ensure unique username
        $base = $username;
        $i = 1;
        while (Database::fetchValue('SELECT 1 FROM users WHERE username = ?', [$username])) {
            $username = $base . $i;
            $i++;
        }

        $defaultRole = (int)(App::config('auth.entra.default_role_id')
            ?? Database::fetchValue("SELECT role_id FROM roles WHERE name = 'Viewer'")
            ?? 4);

        $id = Database::insert('users', [
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'password_hash' => null,
            'auth_source' => 'entra',
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

    private static function decodeJwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }
        $payload = $parts[1];
        $payload = strtr($payload, '-_', '+/');
        $pad = strlen($payload) % 4;
        if ($pad) {
            $payload .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($payload, true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private static function httpPost(string $url, array $fields): ?array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) {
                return null;
            }
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        }

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($fields),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ];
        $body = @file_get_contents($url, false, stream_context_create($opts));
        if ($body === false) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }
}
