?php
/**
 * ColdAisle sample configuration
 * Copy to config.php or use the web setup wizard (setup.php).
 */
declare(strict_types=1);

return [
    // Brand is fixed in code (App::APP_NAME); kept here for reference only
    'app_name' => 'ColdAisle',
    'version' => '0.2.27',
    // Generate: base64_encode(random_bytes(32)) â€” used to encrypt secrets in the DB
    // Never commit a real production key.
    'app_key' => '',
    'timezone' => 'UTC',
    'base_url' => '', // e.g. https://dcim.contoso.com
    'org_name' => 'My Organization',
    // Phase B â€” transport & session hardening (see Settings â†’ Security)
    'security' => [
        'force_https' => false,          // 301 redirect HTTP â†’ HTTPS
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
            // true = do not verify LDAPS server cert (internal CA without ldap-ca.pem)
            'tls_insecure' => false,
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
    // Outbound email (Settings â†’ Email). Used for test mail now; notifications later.
    'mail' => [
        'enabled' => false,
        'host' => 'smtp.contoso.com',
        'port' => 587,
        'encryption' => 'tls', // none | tls (STARTTLS) | ssl (implicit TLS, port 465)
        'auth' => true,
        'auth_mode' => 'login', // none | login | plain
        'username' => '',
        'password' => '',
        'from_email' => 'coldaisle@contoso.com',
        'from_name' => 'ColdAisle',
        'reply_to' => '',
        'timeout' => 30,
        'verify_peer' => true,
    ],
    // OpenDCIM import (scripts/opendcim_import.php). Prefer CLI flags / env in lab.
    // Mode A (default): merge into existing DC by name; never overwrite cabinet floor positions.
    'opendcim' => [
        'base_url' => 'https://dcim.example.org',
        'user_id' => 'dcim',
        'api_key' => '',
        'tls_verify' => false, // private IP / self-signed lab
        'timeout' => 90,
        // Optional DNS override: hostname => IP
        // 'resolve' => ['dcim.example.org' => '192.0.2.10'],
    ],
];
