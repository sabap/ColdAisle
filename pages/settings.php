<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('manage_settings');

$configPath = App::configPath();
$config = App::config();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    try {
        $section = $_POST['section'] ?? 'general';

        if ($section === 'general') {
            SettingsService::set('app_name', trim($_POST['app_name'] ?? 'WinDCIM'));
            SettingsService::set('org_name', trim($_POST['org_name'] ?? ''), 'general');
            SettingsService::set('disposal_notify_days', (string)(int)($_POST['disposal_notify_days'] ?? 7), 'lifecycle');
            $config['org_name'] = $_POST['org_name'] ?? $config['org_name'] ?? '';
            $config['timezone'] = $_POST['timezone'] ?? $config['timezone'] ?? 'UTC';
            $config['base_url'] = rtrim($_POST['base_url'] ?? '', '/');
        }

        if ($section === 'ldaps') {
            $config['auth']['ldaps'] = [
                'enabled' => !empty($_POST['ldaps_enabled']),
                'host' => trim($_POST['ldaps_host'] ?? ''),
                'port' => (int)($_POST['ldaps_port'] ?? 636),
                'base_dn' => trim($_POST['ldaps_base_dn'] ?? ''),
                'user_filter' => trim($_POST['ldaps_user_filter'] ?? '(sAMAccountName={username})'),
                'bind_dn' => trim($_POST['ldaps_bind_dn'] ?? ''),
                'bind_password' => $_POST['ldaps_bind_password'] !== ''
                    ? $_POST['ldaps_bind_password']
                    : ($config['auth']['ldaps']['bind_password'] ?? ''),
                'use_ssl' => !empty($_POST['ldaps_use_ssl']),
                'start_tls' => !empty($_POST['ldaps_start_tls']),
                'default_role_id' => $_POST['ldaps_default_role_id'] !== '' ? (int)$_POST['ldaps_default_role_id'] : null,
            ];
            SettingsService::set('auth_ldaps_enabled', !empty($_POST['ldaps_enabled']) ? '1' : '0', 'auth');
        }

        if ($section === 'entra') {
            $config['auth']['entra'] = [
                'enabled' => !empty($_POST['entra_enabled']),
                'tenant_id' => trim($_POST['entra_tenant_id'] ?? ''),
                'client_id' => trim($_POST['entra_client_id'] ?? ''),
                'client_secret' => $_POST['entra_client_secret'] !== ''
                    ? $_POST['entra_client_secret']
                    : ($config['auth']['entra']['client_secret'] ?? ''),
                'redirect_uri' => trim($_POST['entra_redirect_uri'] ?? ''),
                'scopes' => trim($_POST['entra_scopes'] ?? 'openid profile email offline_access'),
                'default_role_id' => $_POST['entra_default_role_id'] !== '' ? (int)$_POST['entra_default_role_id'] : null,
            ];
            SettingsService::set('auth_entra_enabled', !empty($_POST['entra_enabled']) ? '1' : '0', 'auth');
        }

        // Write config.php
        $export = var_export($config, true);
        $php = "<?php\n/** WinDCIM configuration — updated via Settings UI */\ndeclare(strict_types=1);\n\nreturn {$export};\n";
        if (file_put_contents($configPath, $php) === false) {
            throw new RuntimeException('Could not write config/config.php');
        }

        App::flash('success', 'Settings saved. Reload may be required for auth changes.');
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/settings.php');
}

// Reload config after potential changes on GET
$config = is_file($configPath) ? require $configPath : $config;
$roles = Database::fetchAll('SELECT role_id, name FROM roles ORDER BY role_id');
$ldaps = $config['auth']['ldaps'] ?? [];
$entra = $config['auth']['entra'] ?? [];

layout_header('Settings', $user, 'settings');
?>

<div class="card">
    <div class="card-header"><h2>General</h2></div>
    <div class="card-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="section" value="general">
            <div class="form-row"><label>Application Name</label>
                <input class="form-control" name="app_name" value="<?= App::e(SettingsService::get('app_name', 'WinDCIM')) ?>"></div>
            <div class="form-row"><label>Organization</label>
                <input class="form-control" name="org_name" value="<?= App::e($config['org_name'] ?? SettingsService::get('org_name', '')) ?>"></div>
            <div class="form-row"><label>Timezone</label>
                <input class="form-control" name="timezone" value="<?= App::e($config['timezone'] ?? 'UTC') ?>"></div>
            <div class="form-row"><label>Base URL</label>
                <input class="form-control" name="base_url" value="<?= App::e($config['base_url'] ?? '') ?>" placeholder="https://dcim.contoso.com"></div>
            <div class="form-row"><label>Disposal notify (days)</label>
                <input class="form-control" type="number" name="disposal_notify_days" value="<?= App::e(SettingsService::get('disposal_notify_days', '7')) ?>"></div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Save General</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>LDAPS Authentication</h2></div>
    <div class="card-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="section" value="ldaps">
            <div class="form-row full"><label><input type="checkbox" name="ldaps_enabled" value="1" <?= !empty($ldaps['enabled']) ? 'checked' : '' ?>> Enable LDAPS</label></div>
            <div class="form-row"><label>Host</label>
                <input class="form-control" name="ldaps_host" value="<?= App::e($ldaps['host'] ?? '') ?>" placeholder="dc01.contoso.com"></div>
            <div class="form-row"><label>Port</label>
                <input class="form-control" type="number" name="ldaps_port" value="<?= (int)($ldaps['port'] ?? 636) ?>"></div>
            <div class="form-row full"><label>Base DN</label>
                <input class="form-control" name="ldaps_base_dn" value="<?= App::e($ldaps['base_dn'] ?? '') ?>" placeholder="DC=contoso,DC=com"></div>
            <div class="form-row full"><label>User Filter</label>
                <input class="form-control" name="ldaps_user_filter" value="<?= App::e($ldaps['user_filter'] ?? '(sAMAccountName={username})') ?>"></div>
            <div class="form-row full"><label>Bind DN (service account)</label>
                <input class="form-control" name="ldaps_bind_dn" value="<?= App::e($ldaps['bind_dn'] ?? '') ?>"></div>
            <div class="form-row"><label>Bind Password</label>
                <input class="form-control" type="password" name="ldaps_bind_password" placeholder="Leave blank to keep"></div>
            <div class="form-row"><label>Default Role (new users)</label>
                <select class="form-control" name="ldaps_default_role_id">
                    <option value="">Viewer</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int)$r['role_id'] ?>" <?= (int)($ldaps['default_role_id'] ?? 0) === (int)$r['role_id'] ? 'selected' : '' ?>>
                            <?= App::e($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label><input type="checkbox" name="ldaps_use_ssl" value="1" <?= ($ldaps['use_ssl'] ?? true) ? 'checked' : '' ?>> Use LDAPS (SSL)</label></div>
            <div class="form-row"><label><input type="checkbox" name="ldaps_start_tls" value="1" <?= !empty($ldaps['start_tls']) ? 'checked' : '' ?>> STARTTLS</label></div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Save LDAPS</button></div>
        </form>
        <p class="hint text-muted">Requires PHP LDAP extension. Use a read-only service account for searches.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Microsoft Entra ID (SSO)</h2></div>
    <div class="card-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="section" value="entra">
            <div class="form-row full"><label><input type="checkbox" name="entra_enabled" value="1" <?= !empty($entra['enabled']) ? 'checked' : '' ?>> Enable Entra SSO</label></div>
            <div class="form-row"><label>Tenant ID</label>
                <input class="form-control" name="entra_tenant_id" value="<?= App::e($entra['tenant_id'] ?? '') ?>"></div>
            <div class="form-row"><label>Application (Client) ID</label>
                <input class="form-control" name="entra_client_id" value="<?= App::e($entra['client_id'] ?? '') ?>"></div>
            <div class="form-row"><label>Client Secret</label>
                <input class="form-control" type="password" name="entra_client_secret" placeholder="Leave blank to keep"></div>
            <div class="form-row full"><label>Redirect URI</label>
                <input class="form-control" name="entra_redirect_uri" value="<?= App::e($entra['redirect_uri'] ?? (App::baseUrl() . '/login_entra.php')) ?>">
                <p class="hint text-muted">Register this exact URI in Entra App Registration → Authentication.</p>
            </div>
            <div class="form-row full"><label>Scopes</label>
                <input class="form-control" name="entra_scopes" value="<?= App::e($entra['scopes'] ?? 'openid profile email offline_access') ?>"></div>
            <div class="form-row"><label>Default Role (new users)</label>
                <select class="form-control" name="entra_default_role_id">
                    <option value="">Viewer</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int)$r['role_id'] ?>" <?= (int)($entra['default_role_id'] ?? 0) === (int)$r['role_id'] ? 'selected' : '' ?>>
                            <?= App::e($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Save Entra</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Environment</h2></div>
    <div class="card-body">
        <table class="data">
            <tr><td>WinDCIM Version</td><td><?= App::VERSION ?></td></tr>
            <tr><td>PHP</td><td><?= App::e(PHP_VERSION) ?></td></tr>
            <tr><td>PDO Drivers</td><td><?= App::e(implode(', ', PDO::getAvailableDrivers())) ?></td></tr>
            <tr><td>LDAP</td><td><?= extension_loaded('ldap') ? 'Yes' : 'No' ?></td></tr>
            <tr><td>SNMP</td><td><?= extension_loaded('snmp') ? 'Yes' : 'No' ?></td></tr>
            <tr><td>cURL</td><td><?= extension_loaded('curl') ? 'Yes' : 'No' ?></td></tr>
            <tr><td>Config File</td><td><code><?= App::e($configPath) ?></code></td></tr>
            <tr><td>SQL Host</td><td><?= App::e(($config['database']['host'] ?? '') . '/' . ($config['database']['database'] ?? '')) ?></td></tr>
        </table>
    </div>
</div>
<?php layout_footer(); ?>
