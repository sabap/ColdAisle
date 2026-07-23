<?php
/**
 * WinDCIM sample configuration
 * Copy to config.php or use the web setup wizard (setup.php).
 */
declare(strict_types=1);

return [
    'app_name' => 'WinDCIM',
    'version' => '1.0.0',
    'timezone' => 'UTC',
    'base_url' => '', // e.g. https://dcim.contoso.com
    'org_name' => 'My Organization',
    'database' => [
        'host' => 'localhost',
        'port' => 1433,
        'database' => 'WinDCIM',
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
];
