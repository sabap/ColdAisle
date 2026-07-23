<?php
/**
 * ColdAisle sample configuration
 * Copy to config.php or use the web setup wizard (setup.php).
 */
declare(strict_types=1);

return [
    'app_name' => 'ColdAisle',
    'version' => '0.1.0',
    'timezone' => 'UTC',
    'base_url' => '', // e.g. https://dcim.contoso.com
    'org_name' => 'My Organization',
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
    // One-click updates from public GitHub (token optional; useful for rate limits or private forks)
    'updates' => [
        'enabled' => true,
        'github_owner' => 'sabap',
        'github_repo' => 'ColdAisle',
        'github_token' => '', // optional — never commit a real token
        'auto_check' => true,
        'check_interval_hours' => 24,
        'ssl_verify' => true, // set false only if Windows PHP lacks CA certs (lab)
    ],
];
