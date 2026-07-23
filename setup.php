<?php
/**
 * ColdAisle - Web-based installer
 * Creates config, connects to SQL Server, builds schema, seeds admin account.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/App.php';
App::boot();

// Already installed?
if (App::isInstalled() && !isset($_GET['force'])) {
    header('Location: index.php');
    exit;
}

$step = (int)($_GET['step'] ?? $_POST['step'] ?? 1);
$errors = [];
$success = [];
$form = [
    'sql_host' => $_POST['sql_host'] ?? 'localhost',
    'sql_port' => $_POST['sql_port'] ?? '1433',
    'sql_database' => $_POST['sql_database'] ?? 'ColdAisle',
    'sql_username' => $_POST['sql_username'] ?? 'sa',
    'sql_password' => $_POST['sql_password'] ?? '',
    'sql_encrypt' => isset($_POST['sql_encrypt']),
    'sql_trust_cert' => !isset($_POST['sql_submitted']) || isset($_POST['sql_trust_cert']),
    'create_database' => !isset($_POST['sql_submitted']) || isset($_POST['create_database']),
    'odbc_driver' => $_POST['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server',
    'admin_username' => $_POST['admin_username'] ?? 'admin',
    'admin_email' => $_POST['admin_email'] ?? 'admin@localhost',
    'admin_display' => $_POST['admin_display'] ?? 'Administrator',
    'admin_password' => $_POST['admin_password'] ?? '',
    'admin_password2' => $_POST['admin_password2'] ?? '',
    'org_name' => $_POST['org_name'] ?? 'My Organization',
    'timezone' => $_POST['timezone'] ?? 'UTC',
    'base_url' => $_POST['base_url'] ?? '',
    'site_name' => $_POST['site_name'] ?? 'Primary Site',
    'dc_name' => $_POST['dc_name'] ?? 'Data Center 1',
];

$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$drivers = PDO::getAvailableDrivers();
$hasSqlsrv = in_array('sqlsrv', $drivers, true);
$hasOdbc = in_array('odbc', $drivers, true);
$hasPdo = $hasSqlsrv || $hasOdbc;
$hasJson = extension_loaded('json');
$hasMbstring = extension_loaded('mbstring');
$hasCurl = extension_loaded('curl');
$hasLdap = extension_loaded('ldap');
$hasSnmp = extension_loaded('snmp');
$configWritable = is_writable(__DIR__ . '/config') || is_writable(__DIR__);
$storageWritable = is_writable(__DIR__ . '/storage') || @mkdir(__DIR__ . '/storage/logs', 0775, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'test_connection') {
        try {
            $cfg = [
                'host' => $form['sql_host'],
                'port' => (int)$form['sql_port'],
                'username' => $form['sql_username'],
                'password' => $form['sql_password'],
                'encrypt' => $form['sql_encrypt'],
                'trust_server_certificate' => $form['sql_trust_cert'],
                'odbc_driver' => $form['odbc_driver'],
            ];
            $pdo = Database::connectServer($cfg);
            $ver = $pdo->query('SELECT @@VERSION')->fetchColumn();
            $success[] = 'Connection successful. ' . strtok((string)$ver, "\n");
            $step = 2;
        } catch (Throwable $e) {
            $errors[] = 'Connection failed: ' . $e->getMessage();
            $step = 2;
        }
    }

    if ($action === 'install') {
        // Validate
        if ($form['admin_password'] === '') {
            $errors[] = 'Admin password is required.';
        } elseif (strlen($form['admin_password']) < 8) {
            $errors[] = 'Admin password must be at least 8 characters.';
        } elseif ($form['admin_password'] !== $form['admin_password2']) {
            $errors[] = 'Admin passwords do not match.';
        }
        if ($form['admin_username'] === '') {
            $errors[] = 'Admin username is required.';
        }
        if ($form['sql_host'] === '' || $form['sql_database'] === '') {
            $errors[] = 'SQL host and database name are required.';
        }

        if (!$errors) {
            try {
                $dbCfg = [
                    'host' => $form['sql_host'],
                    'port' => (int)$form['sql_port'],
                    'database' => $form['sql_database'],
                    'username' => $form['sql_username'],
                    'password' => $form['sql_password'],
                    'encrypt' => $form['sql_encrypt'],
                    'trust_server_certificate' => $form['sql_trust_cert'],
                    'odbc_driver' => $form['odbc_driver'],
                ];

                // Create database if requested
                // Note: pdo_odbc does not support PDO::quote() — use bracketed identifiers
                // and a safe alphanumeric database name only.
                $serverPdo = Database::connectServer($dbCfg);
                if ($form['create_database']) {
                    $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $form['sql_database']);
                    if ($dbName === '') {
                        throw new RuntimeException('Invalid database name.');
                    }
                    $stmt = $serverPdo->prepare(
                        'SELECT database_id FROM sys.databases WHERE name = ?'
                    );
                    $stmt->execute([$dbName]);
                    $exists = $stmt->fetchColumn();
                    if (!$exists) {
                        $serverPdo->exec("CREATE DATABASE [{$dbName}]");
                        $success[] = "Database [{$dbName}] created.";
                    } else {
                        $success[] = "Database [{$dbName}] already exists - applying schema.";
                    }
                }

                // Connect to target DB and run schema
                Database::configure($dbCfg);
                $pdo = Database::connection();
                $schema = file_get_contents(__DIR__ . '/sql/schema.sql');
                if ($schema === false) {
                    throw new RuntimeException('Could not read sql/schema.sql');
                }
                Database::executeScript($pdo, $schema);
                $success[] = 'Schema applied successfully.';

                // Create admin user
                $roleId = (int) Database::fetchValue("SELECT role_id FROM roles WHERE name = 'Administrator'");
                if (!$roleId) {
                    throw new RuntimeException('Administrator role not found after schema load.');
                }

                $existingAdmin = Database::fetchOne('SELECT user_id FROM users WHERE username = ?', [$form['admin_username']]);
                $hash = password_hash($form['admin_password'], PASSWORD_DEFAULT);
                if ($existingAdmin) {
                    Database::update('users', [
                        'email' => $form['admin_email'],
                        'display_name' => $form['admin_display'],
                        'password_hash' => $hash,
                        'auth_source' => 'local',
                        'role_id' => $roleId,
                        'is_active' => 1,
                        'must_change_password' => 0,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], 'user_id = :id', [':id' => (int)$existingAdmin['user_id']]);
                    $success[] = 'Admin account updated.';
                } else {
                    Database::insert('users', [
                        'username' => $form['admin_username'],
                        'email' => $form['admin_email'],
                        'display_name' => $form['admin_display'],
                        'password_hash' => $hash,
                        'auth_source' => 'local',
                        'role_id' => $roleId,
                        'is_active' => 1,
                        'must_change_password' => 0,
                    ]);
                    $success[] = 'Admin account created.';
                }

                // Seed org structure
                $siteId = Database::fetchValue('SELECT TOP 1 site_id FROM sites');
                if (!$siteId) {
                    $siteId = Database::insert('sites', [
                        'name' => $form['site_name'],
                        'code' => 'SITE1',
                        'timezone' => $form['timezone'],
                        'is_active' => 1,
                    ]);
                    $dcId = Database::insert('datacenters', [
                        'site_id' => $siteId,
                        'name' => $form['dc_name'],
                        'code' => 'DC1',
                        'floor_width_m' => 40,
                        'floor_depth_m' => 25,
                        'is_active' => 1,
                    ]);
                    Database::insert('rooms', [
                        'datacenter_id' => $dcId,
                        'name' => 'Main Hall',
                        'code' => 'HALL-A',
                        'width_m' => 30,
                        'depth_m' => 20,
                        'is_active' => 1,
                    ]);
                    $success[] = 'Default site, data center, and room created.';
                }

                // Update settings safely
                $sets = [
                    'org_name' => $form['org_name'],
                    'app_name' => 'ColdAisle',
                ];
                foreach ($sets as $k => $v) {
                    $ex = Database::fetchValue('SELECT 1 FROM settings WHERE setting_key = ?', [$k]);
                    if ($ex) {
                        Database::update('settings', ['setting_value' => $v, 'updated_at' => date('Y-m-d H:i:s')], 'setting_key = :k', [':k' => $k]);
                    } else {
                        Database::insert('settings', ['setting_key' => $k, 'setting_value' => $v, 'category' => 'general']);
                    }
                }

                // Write config file
                $baseUrl = rtrim($form['base_url'], '/');
                $configPhp = self_generate_config($dbCfg, $form, $baseUrl);
                $configDir = __DIR__ . '/config';
                $configPath = $configDir . '/config.php';
                if (!is_dir($configDir) && !@mkdir($configDir, 0775, true)) {
                    throw new RuntimeException(
                        "Could not create config directory: {$configDir}. " .
                        'Grant Modify to IUSR and IIS AppPool\\DefaultAppPool on the config folder.'
                    );
                }
                if (!is_writable($configDir)) {
                    $who = function_exists('get_current_user') ? (string)get_current_user() : 'unknown';
                    throw new RuntimeException(
                        "config/ is not writable by the PHP process (user: {$who}). " .
                        'With fastcgi.impersonate=1, grant Modify on config\\ and storage\\ to ' .
                        'NT AUTHORITY\\IUSR, IIS_IUSRS, and IIS AppPool\\DefaultAppPool. Path: ' . $configDir
                    );
                }
                $written = @file_put_contents($configPath, $configPhp);
                if ($written === false) {
                    $err = error_get_last();
                    $detail = is_array($err) ? ($err['message'] ?? '') : '';
                    throw new RuntimeException(
                        'Could not write config/config.php — check folder permissions. ' . $detail
                    );
                }
                // Restrict permissions if possible
                @chmod($configPath, 0640);

                $success[] = 'Configuration written to config/config.php';
                $step = 4;
            } catch (Throwable $e) {
                $errors[] = 'Installation failed: ' . $e->getMessage();
                $step = 3;
            }
        } else {
            $step = 3;
        }
    }
}

function self_generate_config(array $dbCfg, array $form, string $baseUrl): string
{
    $export = var_export([
        'app_name' => 'ColdAisle',
        'version' => '0.2.3',
        // 32-byte key, base64 — used to encrypt SNMP/API secrets at rest in the DB
        'app_key' => base64_encode(random_bytes(32)),
        'timezone' => $form['timezone'],
        'base_url' => $baseUrl,
        'org_name' => $form['org_name'],
        'security' => [
            'force_https' => false,
            'hsts' => false,
            'hsts_max_age' => 31536000,
            'cookie_secure' => 'auto',
            'cookie_samesite' => 'Lax',
            'session_idle_minutes' => 480,
            'session_absolute_minutes' => 1440,
            'bind_user_agent' => true,
        ],
        'database' => [
            'host' => $dbCfg['host'],
            'port' => $dbCfg['port'],
            'database' => $dbCfg['database'],
            'username' => $dbCfg['username'],
            'password' => $dbCfg['password'],
            'encrypt' => !empty($dbCfg['encrypt']),
            'trust_server_certificate' => !empty($dbCfg['trust_server_certificate']),
            'odbc_driver' => $dbCfg['odbc_driver'],
        ],
        'auth' => [
            'local' => ['enabled' => true],
            'ldaps' => [
                'enabled' => false,
                'host' => '',
                'port' => 636,
                'base_dn' => '',
                'user_filter' => '(sAMAccountName={username})',
                'bind_dn' => '',
                'bind_password' => '',
                'use_ssl' => true,
                'start_tls' => false,
                'default_role_id' => null,
            ],
            'entra' => [
                'enabled' => false,
                'tenant_id' => '',
                'client_id' => '',
                'client_secret' => '',
                'redirect_uri' => '',
                'scopes' => 'openid profile email offline_access',
                'default_role_id' => null,
            ],
        ],
        'updates' => [
            'enabled' => true,
            'github_owner' => 'sabap',
            'github_repo' => 'ColdAisle',
            'github_token' => '',
            'auto_check' => true,
            'check_interval_hours' => 24,
            'ssl_verify' => true,
        ],
        'installed_at' => date('c'),
    ], true);

    return "<?php\n/**\n * ColdAisle configuration — generated by setup wizard\n * Do not commit secrets to source control.\n */\ndeclare(strict_types=1);\n\nreturn {$export};\n";
}

function req_badge(bool $ok): string
{
    return $ok
        ? '<span class="badge ok">OK</span>'
        : '<span class="badge fail">Missing</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ColdAisle Setup</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        body.setup-body { background: linear-gradient(145deg, #0f172a 0%, #1e293b 50%, #0f172a 100%); min-height: 100vh; }
        .setup-wrap { max-width: 760px; margin: 2rem auto; padding: 0 1rem; }
        .setup-card { background: var(--surface); border-radius: 12px; padding: 2rem; box-shadow: 0 20px 50px rgba(0,0,0,.4); border: 1px solid var(--border); }
        .setup-steps { display: flex; gap: .5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .setup-steps span { flex: 1; text-align: center; padding: .5rem; border-radius: 8px; background: var(--surface-2); font-size: .85rem; color: var(--muted); min-width: 100px; }
        .setup-steps span.active { background: var(--accent); color: #fff; font-weight: 600; }
        .setup-steps span.done { background: var(--success-dim); color: var(--success); }
        .req-table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        .req-table td { padding: .5rem .75rem; border-bottom: 1px solid var(--border); }
        .badge { display: inline-block; padding: .15rem .5rem; border-radius: 4px; font-size: .75rem; font-weight: 600; }
        .badge.ok { background: #14532d; color: #86efac; }
        .badge.fail { background: #7f1d1d; color: #fca5a5; }
        .badge.warn { background: #713f12; color: #fde68a; }
        .form-row { margin-bottom: 1rem; }
        .form-row label { display: block; margin-bottom: .35rem; font-weight: 500; font-size: .9rem; }
        .form-row input[type=text], .form-row input[type=password], .form-row input[type=number], .form-row input[type=email], .form-row select {
            width: 100%; padding: .6rem .75rem; border-radius: 8px; border: 1px solid var(--border);
            background: var(--surface-2); color: var(--text); font-size: 1rem;
        }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }
        .check-label { display: flex; align-items: center; gap: .5rem; font-weight: 400; }
        .alert { padding: .75rem 1rem; border-radius: 8px; margin-bottom: .75rem; }
        .alert-error { background: #7f1d1d55; border: 1px solid #ef4444; color: #fecaca; }
        .alert-success { background: #14532d55; border: 1px solid #22c55e; color: #bbf7d0; }
        .btn-row { display: flex; gap: .75rem; margin-top: 1.5rem; flex-wrap: wrap; }
        .hint { font-size: .8rem; color: var(--muted); margin-top: .25rem; }
        .logo-setup { text-align: center; margin-bottom: 1.5rem; }
        .logo-setup h1 { margin: 0; font-size: 1.75rem; letter-spacing: -0.02em; }
        .logo-setup p { color: var(--muted); margin: .35rem 0 0; }
    </style>
</head>
<body class="setup-body">
<div class="setup-wrap">
    <div class="logo-setup">
        <h1>⚡ ColdAisle</h1>
        <p>Data Center Infrastructure Management — Setup Wizard</p>
    </div>

    <div class="setup-card">
        <div class="setup-steps">
            <span class="<?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">1. Requirements</span>
            <span class="<?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">2. Database</span>
            <span class="<?= $step >= 3 ? ($step > 3 ? 'done' : 'active') : '' ?>">3. Organization</span>
            <span class="<?= $step >= 4 ? 'active' : '' ?>">4. Complete</span>
        </div>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
        <?php foreach ($success as $s): ?>
            <div class="alert alert-success"><?= htmlspecialchars($s) ?></div>
        <?php endforeach; ?>

        <?php if ($step <= 1): ?>
            <h2>System Requirements</h2>
            <p class="hint">ColdAisle runs on IIS with PHP 8+ and Microsoft SQL Server.</p>
            <table class="req-table">
                <tr><td>PHP 8.0+</td><td><?= req_badge($phpOk) ?> <?= htmlspecialchars(PHP_VERSION) ?></td></tr>
                <tr><td>PDO SQL Server (sqlsrv)</td><td><?= $hasSqlsrv ? req_badge(true) : '<span class="badge warn">Optional</span>' ?></td></tr>
                <tr><td>PDO ODBC</td><td><?= $hasOdbc ? req_badge(true) : '<span class="badge warn">Optional</span>' ?></td></tr>
                <tr><td>SQL PDO driver (sqlsrv or odbc)</td><td><?= req_badge($hasPdo) ?></td></tr>
                <tr><td>JSON extension</td><td><?= req_badge($hasJson) ?></td></tr>
                <tr><td>mbstring</td><td><?= req_badge($hasMbstring) ?></td></tr>
                <tr><td>cURL (Entra SSO)</td><td><?= $hasCurl ? req_badge(true) : '<span class="badge warn">Recommended</span>' ?></td></tr>
                <tr><td>LDAP (LDAPS auth)</td><td><?= $hasLdap ? req_badge(true) : '<span class="badge warn">Optional</span>' ?></td></tr>
                <tr><td>SNMP extension</td><td><?= $hasSnmp ? req_badge(true) : '<span class="badge warn">Optional</span>' ?></td></tr>
                <tr><td>config/ writable</td><td><?= req_badge($configWritable) ?></td></tr>
                <tr><td>storage/ writable</td><td><?= req_badge($storageWritable) ?></td></tr>
            </table>
            <?php if (!$hasPdo): ?>
                <div class="alert alert-error">
                    Install <strong>Microsoft Drivers for PHP for SQL Server</strong> (php_pdo_sqlsrv)
                    or enable <strong>pdo_odbc</strong> with ODBC Driver 17/18 for SQL Server.
                </div>
            <?php endif; ?>
            <div class="btn-row">
                <a class="btn btn-primary" href="?step=2" <?= !$phpOk || !$hasPdo ? 'style="pointer-events:none;opacity:.5"' : '' ?>>Continue →</a>
            </div>

        <?php elseif ($step === 2): ?>
            <h2>SQL Server Connection</h2>
            <form method="post">
                <input type="hidden" name="sql_submitted" value="1">
                <input type="hidden" name="step" value="2">
                <div class="form-grid">
                    <div class="form-row">
                        <label>SQL Host / Instance</label>
                        <input type="text" name="sql_host" value="<?= htmlspecialchars($form['sql_host']) ?>" required>
                        <p class="hint">e.g. localhost, .\SQLEXPRESS, or sql.contoso.com</p>
                    </div>
                    <div class="form-row">
                        <label>Port</label>
                        <input type="number" name="sql_port" value="<?= htmlspecialchars((string)$form['sql_port']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Database Name</label>
                        <input type="text" name="sql_database" value="<?= htmlspecialchars($form['sql_database']) ?>" required>
                    </div>
                    <div class="form-row">
                        <label>ODBC Driver Name</label>
                        <input type="text" name="odbc_driver" value="<?= htmlspecialchars($form['odbc_driver']) ?>">
                        <p class="hint">Used when pdo_sqlsrv is not available</p>
                    </div>
                    <div class="form-row">
                        <label>SQL Username</label>
                        <input type="text" name="sql_username" value="<?= htmlspecialchars($form['sql_username']) ?>" required>
                    </div>
                    <div class="form-row">
                        <label>SQL Password</label>
                        <input type="password" name="sql_password" value="<?= htmlspecialchars($form['sql_password']) ?>">
                    </div>
                </div>
                <div class="form-row">
                    <label class="check-label"><input type="checkbox" name="create_database" <?= $form['create_database'] ? 'checked' : '' ?>> Create database if it does not exist</label>
                </div>
                <div class="form-row">
                    <label class="check-label"><input type="checkbox" name="sql_encrypt" <?= $form['sql_encrypt'] ? 'checked' : '' ?>> Encrypt connection</label>
                </div>
                <div class="form-row">
                    <label class="check-label"><input type="checkbox" name="sql_trust_cert" <?= $form['sql_trust_cert'] ? 'checked' : '' ?>> Trust server certificate</label>
                </div>
                <div class="btn-row">
                    <button type="submit" name="action" value="test_connection" class="btn btn-secondary">Test Connection</button>
                    <button type="submit" name="action" value="go_org" class="btn btn-primary" formaction="?step=3" formmethod="post"
                            onclick="this.form.action='setup.php?step=3'; this.form.elements.namedItem('action') && (this.form.querySelector('[name=action]').value='noop');">Continue →</button>
                    <a class="btn btn-ghost" href="?step=1">← Back</a>
                </div>
            </form>
            <form method="post" action="setup.php?step=3" id="continueForm" style="display:none">
                <?php foreach (['sql_host','sql_port','sql_database','sql_username','sql_password','odbc_driver'] as $f): ?>
                    <input type="hidden" name="<?= $f ?>" value="<?= htmlspecialchars((string)$form[$f]) ?>">
                <?php endforeach; ?>
                <?php if ($form['sql_encrypt']): ?><input type="hidden" name="sql_encrypt" value="1"><?php endif; ?>
                <?php if ($form['sql_trust_cert']): ?><input type="hidden" name="sql_trust_cert" value="1"><?php endif; ?>
                <?php if ($form['create_database']): ?><input type="hidden" name="create_database" value="1"><?php endif; ?>
                <input type="hidden" name="sql_submitted" value="1">
            </form>
            <script>
                document.querySelector('button[value=go_org]')?.addEventListener('click', function(e) {
                    e.preventDefault();
                    const src = this.form;
                    const dst = document.getElementById('continueForm');
                    ['sql_host','sql_port','sql_database','sql_username','sql_password','odbc_driver'].forEach(n => {
                        const el = src.elements.namedItem(n);
                        if (el && dst.elements.namedItem(n)) dst.elements.namedItem(n).value = el.value;
                    });
                    // rebuild checkboxes
                    dst.querySelectorAll('[name=sql_encrypt],[name=sql_trust_cert],[name=create_database]').forEach(x => x.remove());
                    if (src.sql_encrypt?.checked) { const i=document.createElement('input'); i.type='hidden'; i.name='sql_encrypt'; i.value='1'; dst.appendChild(i); }
                    if (src.sql_trust_cert?.checked) { const i=document.createElement('input'); i.type='hidden'; i.name='sql_trust_cert'; i.value='1'; dst.appendChild(i); }
                    if (src.create_database?.checked) { const i=document.createElement('input'); i.type='hidden'; i.name='create_database'; i.value='1'; dst.appendChild(i); }
                    dst.submit();
                });
            </script>

        <?php elseif ($step === 3): ?>
            <h2>Organization & Admin Account</h2>
            <p class="hint">A local administrator account is created for initial access. Default suggestion: <code>admin</code></p>
            <form method="post">
                <input type="hidden" name="step" value="3">
                <input type="hidden" name="sql_submitted" value="1">
                <?php foreach (['sql_host','sql_port','sql_database','sql_username','sql_password','odbc_driver'] as $f): ?>
                    <input type="hidden" name="<?= $f ?>" value="<?= htmlspecialchars((string)$form[$f]) ?>">
                <?php endforeach; ?>
                <?php if ($form['sql_encrypt']): ?><input type="hidden" name="sql_encrypt" value="1"><?php endif; ?>
                <?php if ($form['sql_trust_cert']): ?><input type="hidden" name="sql_trust_cert" value="1"><?php endif; ?>
                <?php if ($form['create_database']): ?><input type="hidden" name="create_database" value="1"><?php endif; ?>

                <h3 style="margin-top:0">Organization</h3>
                <div class="form-grid">
                    <div class="form-row">
                        <label>Organization Name</label>
                        <input type="text" name="org_name" value="<?= htmlspecialchars($form['org_name']) ?>" required>
                    </div>
                    <div class="form-row">
                        <label>Timezone</label>
                        <input type="text" name="timezone" value="<?= htmlspecialchars($form['timezone']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Site Name</label>
                        <input type="text" name="site_name" value="<?= htmlspecialchars($form['site_name']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Data Center Name</label>
                        <input type="text" name="dc_name" value="<?= htmlspecialchars($form['dc_name']) ?>">
                    </div>
                    <div class="form-row" style="grid-column:1/-1">
                        <label>Base URL (optional)</label>
                        <input type="text" name="base_url" value="<?= htmlspecialchars($form['base_url']) ?>" placeholder="https://dcim.contoso.com">
                        <p class="hint">Leave blank to auto-detect. Set if behind reverse proxy.</p>
                    </div>
                </div>

                <h3>Administrator Account</h3>
                <div class="form-grid">
                    <div class="form-row">
                        <label>Username</label>
                        <input type="text" name="admin_username" value="<?= htmlspecialchars($form['admin_username']) ?>" required autocomplete="username">
                    </div>
                    <div class="form-row">
                        <label>Display Name</label>
                        <input type="text" name="admin_display" value="<?= htmlspecialchars($form['admin_display']) ?>">
                    </div>
                    <div class="form-row">
                        <label>Email</label>
                        <input type="email" name="admin_email" value="<?= htmlspecialchars($form['admin_email']) ?>" required>
                    </div>
                    <div class="form-row"></div>
                    <div class="form-row">
                        <label>Password</label>
                        <input type="password" name="admin_password" required autocomplete="new-password" minlength="8">
                    </div>
                    <div class="form-row">
                        <label>Confirm Password</label>
                        <input type="password" name="admin_password2" required autocomplete="new-password" minlength="8">
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" name="action" value="install" class="btn btn-primary">Install ColdAisle</button>
                    <a class="btn btn-ghost" href="?step=2">← Back</a>
                </div>
            </form>

        <?php else: ?>
            <h2>Installation Complete</h2>
            <p>ColdAisle is ready. Sign in with the administrator account you created.</p>
            <div class="alert alert-success">
                <strong>Next steps</strong>
                <ul style="margin:.5rem 0 0;padding-left:1.25rem">
                    <li>Log in and open <strong>Settings</strong> to configure LDAPS or Microsoft Entra SSO</li>
                    <li>Use the <strong>Floor Planner</strong> to drag cabinets onto the room canvas</li>
                    <li>Add devices to U-slots, configure power zones and PDUs</li>
                    <li>Schedule the SNMP poll script via Task Scheduler (see README)</li>
                </ul>
            </div>
            <div class="btn-row">
                <a class="btn btn-primary" href="login.php">Go to Login →</a>
            </div>
        <?php endif; ?>
    </div>
    <p style="text-align:center;color:#64748b;margin-top:1.5rem;font-size:.85rem">ColdAisle v<?= App::VERSION ?> · IIS + SQL Server</p>
</div>
</body>
</html>
