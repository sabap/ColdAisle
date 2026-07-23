<?php
/**
 * ColdAisle sample configuration
 * Copy to config.php or use the web setup wizard (setup.php).
 */
declare(strict_types=1);

return [
    // Brand is fixed in code (App::APP_NAME); kept here for reference only
    'app_name' => 'ColdAisle',
    'version' => '0.2.12',
    // Generate: base64_encode(random_bytes(32)) — used to encrypt secrets in the DB
    // Never commit a real production key.
    'app_key' => '',
    'timezone' => 'UTC',
    'base_url' => '', // e.g. https://dcim.contoso.com
    'org_name' => 'My Organization',
    // Phase B — transport & session hardening (see Settings → Security)
    'security' => [
        'force_https' => false,          // 301 redirect HTTP → HTTPS
        'hsts' => false,                 // Strict-Transport-Security (only when already HTTPS)
        'hsts_max_age' => 31536000,      // 1 year
        'cookie_secure' => 'auto',       // auto | always | never
        'cookie_samesite' => 'Lax',      // Lax | Strict | None
        'session_idle_minutes' => 480,   // 0 = disabled (8h default)
        'session_absolute_minutes' => 1440, // 0 = disabled (24h default)
        'bind_user_agent' => true,       // invalidate session if UA changes
    ],
    'database' => [
        'host' => 'localhost',
        'port' => 1433,
        'database' => 'ColdAisle',
        'username' => 'dcim_app',
        'password' => 'CHANGE_ME',
        'encrypt' => false,
        'trust_server_certificate' => true,
        'odbc_driver' => 'ODBC Driver 18 for SQL Server',
    ],
    'auth' => [
        'local' => ['enabled' => true],
        'ldaps' => [
            'enabled' => false,
            'host' => 'dc01.contoso.com',
            'port' => 636,
            'base_dn' => 'DC=contoso,DC=com',
            'user_filter' => '(sAMAccountName={username})',
            'bind_dn' => 'CN=svc-dcim,OU=Service Accounts,DC=contoso,DC=com',
            'bind_password' => '',
            'use_ssl' => true,
            'start_tls' => false,
            'default_role_id' => null,
        ],
        'entra' => [
            'enabled' => false,
            'tenant_id' => '00000000-0000-0000-0000-000000000000',
            'client_id' => '00000000-0000-0000-0000-000000000000',
            'client_secret' => '',
            'redirect_uri' => 'https://dcim.contoso.com/login_entra.php',
            'scopes' => 'openid profile email offline_access',
            'default_role_id' => null,
        ],
    ],
    // One-click updates always use public github.com/sabap/ColdAisle (not configurable)
    'updates' => [
        'enabled' => true,
        'auto_check' => true,
        'check_interval_hours' => 24,
        'ssl_verify' => true, // set false only if Windows PHP lacks CA certs (lab)
    ],
];
