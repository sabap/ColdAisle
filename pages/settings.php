<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/src/Services/UpdateService.php';
App::boot();
$user = App::requirePermission('manage_settings');

$configPath = App::configPath();
$config = App::config();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    try {
        $section = $_POST['section'] ?? 'general';

        if ($section === 'general') {
            // Application name is fixed to ColdAisle (not user-configurable)
            SettingsService::set('org_name', trim($_POST['org_name'] ?? ''), 'general');
            SettingsService::set('disposal_notify_days', (string)(int)($_POST['disposal_notify_days'] ?? 7), 'lifecycle');
            $config['app_name'] = App::APP_NAME;
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

        if ($section === 'updates') {
            // Do not persist owner/repo/token — hard-coded to public sabap/ColdAisle
            $config['updates'] = [
                'enabled' => !empty($_POST['updates_enabled']),
                'auto_check' => !empty($_POST['updates_auto_check']),
                'check_interval_hours' => max(1, min(168, (int)($_POST['check_interval_hours'] ?? 24))),
                'ssl_verify' => !empty($_POST['updates_ssl_verify']),
            ];
        }

        if ($section === 'security') {
            $same = strtoupper(trim((string)($_POST['cookie_samesite'] ?? 'Lax')));
            if (!in_array($same, ['LAX', 'STRICT', 'NONE'], true)) {
                $same = 'LAX';
            }
            $sameLabel = match ($same) {
                'STRICT' => 'Strict',
                'NONE' => 'None',
                default => 'Lax',
            };
            $cookieSecure = strtolower(trim((string)($_POST['cookie_secure'] ?? 'auto')));
            if (!in_array($cookieSecure, ['auto', 'always', 'never'], true)) {
                $cookieSecure = 'auto';
            }
            $config['security'] = [
                'force_https' => !empty($_POST['force_https']),
                'hsts' => !empty($_POST['hsts']),
                'hsts_max_age' => max(0, min(63072000, (int)($_POST['hsts_max_age'] ?? 31536000))),
                'cookie_secure' => $cookieSecure,
                'cookie_samesite' => $sameLabel,
                'session_idle_minutes' => max(0, min(10080, (int)($_POST['session_idle_minutes'] ?? 480))),
                'session_absolute_minutes' => max(0, min(43200, (int)($_POST['session_absolute_minutes'] ?? 1440))),
                'bind_user_agent' => !empty($_POST['bind_user_agent']),
            ];
        }

        if ($section === 'update_check') {
            $status = UpdateService::checkForUpdate(true);
            if (!empty($status['ok'])) {
                if (!empty($status['update_available'])) {
                    App::flash('success', 'Update available: v' . ($status['latest'] ?? '?')
                        . ' (you have v' . ($status['current'] ?? '?') . ').');
                } else {
                    App::flash('success', 'You are on the latest version (v' . ($status['current'] ?? '?') . ').');
                }
            } else {
                App::flash('error', $status['error'] ?? 'Update check failed.');
            }
            App::redirect('pages/settings.php#updates');
        }

        if ($section === 'update_apply') {
            @set_time_limit(600);
            $result = UpdateService::applyUpdate(null);
            AuditService::log((int)$user['user_id'], $user['username'], 'update_apply', 'system', null, [
                'version' => $result['version'] ?? null,
                'ok' => !empty($result['ok']),
            ]);
            if (!empty($result['ok'])) {
                App::flash('success', $result['message'] ?? 'Update applied.');
            } else {
                App::flash('error', $result['message'] ?? 'Update failed.');
            }
            App::redirect('pages/settings.php#updates');
        }

        if ($section === 'export_site_backup') {
            @set_time_limit(600);
            $path = SiteBackupService::export([
                'include_audit' => !empty($_POST['include_audit']),
                'include_readings' => !empty($_POST['include_readings']),
            ]);
            AuditService::log((int)$user['user_id'], $user['username'], 'site_backup_export', 'system', null, [
                'file' => basename($path),
                'bytes' => @filesize($path) ?: null,
            ]);
            $name = basename($path);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . (string)filesize($path));
            header('Cache-Control: no-store');
            readfile($path);
            exit;
        }

        // Write config.php (for general / auth / updates / security)
        if (!in_array($section, ['update_check', 'update_apply', 'export_site_backup'], true)) {
            $export = var_export($config, true);
            $php = "<?php\n/** ColdAisle configuration — updated via Settings UI */\ndeclare(strict_types=1);\n\nreturn {$export};\n";
            if (file_put_contents($configPath, $php) === false) {
                throw new RuntimeException('Could not write config/config.php');
            }
            App::flash('success', 'Settings saved. Reload may be required for auth changes.');
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    $redirHash = '';
    $secPost = (string)($_POST['section'] ?? '');
    if (str_starts_with($secPost, 'update')) {
        $redirHash = '#updates';
    } elseif ($secPost === 'security') {
        $redirHash = '#security';
    } elseif ($secPost === 'export_site_backup') {
        $redirHash = '#backup';
    }
    App::redirect('pages/settings.php' . $redirHash);
}

// Reload config after potential changes on GET
$config = is_file($configPath) ? require $configPath : $config;
$roles = Database::fetchAll('SELECT role_id, name FROM roles ORDER BY role_id');
$ldaps = $config['auth']['ldaps'] ?? [];
$entra = $config['auth']['entra'] ?? [];
$secCfg = App::securityConfig();
$updCfg = UpdateService::config();
$updStatus = null;
try {
    // Non-forced: use cache when fresh
    $updStatus = UpdateService::checkForUpdate(false);
} catch (Throwable $e) {
    $updStatus = null;
}
// Fixed public donation link (not user-configurable)
$paypalUrl = 'https://paypal.me/mattelsberry';

layout_header('Settings', $user, 'settings');
?>

<div class="card" id="support">
    <div class="card-header flex-between">
        <h2>Support ColdAisle</h2>
        <a class="btn btn-primary" href="<?= App::e($paypalUrl) ?>" target="_blank" rel="noopener noreferrer">
            Donate with PayPal
        </a>
    </div>
    <div class="card-body">
        <p class="text-muted" style="margin-top:0;font-size:.9rem">
            ColdAisle is free and open source. If it helps your datacenter, optional donations keep development going —
            no accounts, no paywalls, no marketing push.
        </p>
        <p style="margin:.5rem 0 0">
            <a class="btn btn-primary" href="<?= App::e($paypalUrl) ?>" target="_blank" rel="noopener noreferrer">
                💙 Donate with PayPal
            </a>
            <a class="btn btn-secondary" href="https://github.com/sabap/ColdAisle" target="_blank" rel="noopener noreferrer">
                GitHub
            </a>
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>General</h2></div>
    <div class="card-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="section" value="general">
            <div class="form-row"><label>Organization</label>
                <input class="form-control" name="org_name" value="<?= App::e($config['org_name'] ?? SettingsService::get('org_name', '')) ?>"></div>
            <div class="form-row"><label>Timezone</label>
                <input class="form-control" name="timezone" value="<?= App::e($config['timezone'] ?? 'UTC') ?>"></div>
            <div class="form-row full"><label>Public site URL (optional)</label>
                <input class="form-control" name="base_url" id="settings_base_url"
                       value="<?= App::e($config['base_url'] ?? '') ?>"
                       placeholder="Leave blank to auto-detect from the browser">
                <p class="text-muted" style="font-size:.75rem;margin:.3rem 0 0">
                    Leave blank unless you use a reverse proxy or a public name that differs from this server.
                    If you set <code>https://…</code>, IIS must already have an HTTPS binding and TLS certificate
                    for that hostname. Enable <strong>Force HTTPS</strong> under Security only after HTTPS works in the browser.
                </p>
                <?php if (App::httpsConfigMismatch()): ?>
                    <div class="alert alert-error" style="margin-top:.5rem">
                        Configured URL is HTTPS but this page was loaded over HTTP (certificate/binding may still be missing).
                        ColdAisle is using the current HTTP address for links until HTTPS works or Force HTTPS is enabled.
                    </div>
                <?php endif; ?>
            </div>
            <div class="form-row"><label>Disposal notify (days)</label>
                <input class="form-control" type="number" name="disposal_notify_days" value="<?= App::e(SettingsService::get('disposal_notify_days', '7')) ?>"></div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Save General</button></div>
        </form>
    </div>
</div>

<div class="card" id="security">
    <div class="card-header"><h2>Security (HTTPS &amp; sessions)</h2></div>
    <div class="card-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="section" value="security">
            <div class="form-row full">
                <p class="text-muted" style="margin:0;font-size:.85rem">
                    Current request:
                    <strong><?= App::isHttps() ? 'HTTPS' : 'HTTP' ?></strong>
                    · Session cookie Secure flag follows cookie mode below.
                    Enable <em>Force HTTPS</em> only after a certificate is bound in IIS.
                </p>
            </div>
            <div class="form-row full"><label>
                <input type="checkbox" name="force_https" value="1" <?= !empty($secCfg['force_https']) ? 'checked' : '' ?>>
                Force HTTPS (301 redirect HTTP → HTTPS)
            </label></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="hsts" value="1" <?= !empty($secCfg['hsts']) ? 'checked' : '' ?>>
                Send HSTS header when already on HTTPS
            </label></div>
            <div class="form-row"><label>HSTS max-age (seconds)</label>
                <input class="form-control" type="number" min="0" max="63072000" name="hsts_max_age"
                       value="<?= (int)$secCfg['hsts_max_age'] ?>"></div>
            <div class="form-row"><label>Session cookie Secure</label>
                <select class="form-control" name="cookie_secure">
                    <?php foreach (['auto' => 'Auto (Secure when HTTPS)', 'always' => 'Always Secure', 'never' => 'Never (lab HTTP only)'] as $val => $lab): ?>
                        <option value="<?= $val ?>" <?= ($secCfg['cookie_secure'] ?? 'auto') === $val ? 'selected' : '' ?>>
                            <?= App::e($lab) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>SameSite</label>
                <select class="form-control" name="cookie_samesite">
                    <?php foreach (['Lax', 'Strict', 'None'] as $ss): ?>
                        <option value="<?= $ss ?>" <?= ($secCfg['cookie_samesite'] ?? 'Lax') === $ss ? 'selected' : '' ?>>
                            <?= $ss ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Idle timeout (minutes, 0=off)</label>
                <input class="form-control" type="number" min="0" max="10080" name="session_idle_minutes"
                       value="<?= (int)$secCfg['session_idle_minutes'] ?>"
                       title="Default 480 = 8 hours"></div>
            <div class="form-row"><label>Absolute timeout (minutes, 0=off)</label>
                <input class="form-control" type="number" min="0" max="43200" name="session_absolute_minutes"
                       value="<?= (int)$secCfg['session_absolute_minutes'] ?>"
                       title="Default 1440 = 24 hours from login"></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="bind_user_agent" value="1" <?= !empty($secCfg['bind_user_agent']) ? 'checked' : '' ?>>
                Bind session to browser user-agent (invalidate if UA changes)
            </label></div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Save Security</button></div>
        </form>
        <p class="hint text-muted" style="margin-top:.75rem">
            Headers always sent: <code>X-Content-Type-Options</code>, <code>X-Frame-Options</code>,
            <code>Referrer-Policy</code>, <code>Permissions-Policy</code>, <code>CSP frame-ancestors</code>.
            Session cookies are HttpOnly; IDs are never put in URLs.
        </p>
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

<div class="card" id="updates">
    <div class="card-header flex-between">
        <h2>Updates</h2>
        <span class="text-muted" style="font-size:.85rem">
            Installed v<?= App::e(UpdateService::installedVersion()) ?>
        </span>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.9rem;margin-top:0">
            Checks the public project
            <a href="<?= App::e(UpdateService::githubUrl()) ?>" target="_blank" rel="noopener"><strong>sabap/ColdAisle</strong></a>
            for newer versions, backs up this install, downloads the package, and applies it
            (preserving <code>config/config.php</code> and <code>storage/</code> uploads &amp; logs).
            No GitHub account or token is required.
        </p>

        <?php if ($updStatus): ?>
            <?php if (!empty($updStatus['update_available'])): ?>
                <div class="alert alert-info" style="margin-bottom:1rem">
                    <strong>Update available:</strong>
                    v<?= App::e((string)$updStatus['latest']) ?>
                    (you have v<?= App::e((string)$updStatus['current']) ?>)
                    <?php if (!empty($updStatus['html_url'])): ?>
                        · <a href="<?= App::e((string)$updStatus['html_url']) ?>" target="_blank" rel="noopener">Release notes</a>
                    <?php endif; ?>
                </div>
            <?php elseif (!empty($updStatus['ok'])): ?>
                <div class="alert alert-success" style="margin-bottom:1rem">
                    Up to date (v<?= App::e((string)$updStatus['current']) ?>).
                    <?php if (!empty($updStatus['checked_at'])): ?>
                        <span class="text-muted">Last check: <?= App::e((string)$updStatus['checked_at']) ?>
                            <?= !empty($updStatus['cached']) ? '(cached)' : '' ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning" style="margin-bottom:1rem">
                    <?= App::e((string)($updStatus['error'] ?? 'Could not check for updates.')) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" class="form-grid" style="margin-bottom:1.25rem">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="section" value="updates">
            <div class="form-row full"><label>
                <input type="checkbox" name="updates_enabled" value="1" <?= !empty($updCfg['enabled']) ? 'checked' : '' ?>>
                Enable update checks
            </label></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="updates_auto_check" value="1" <?= !empty($updCfg['auto_check']) ? 'checked' : '' ?>>
                Auto-check on dashboard (uses cache interval below)
            </label></div>
            <div class="form-row"><label>Check interval (hours)</label>
                <input class="form-control" type="number" min="1" max="168" name="check_interval_hours"
                       value="<?= (int)($updCfg['check_interval_hours'] ?? 24) ?>"></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="updates_ssl_verify" value="1" <?= ($updCfg['ssl_verify'] ?? true) ? 'checked' : '' ?>>
                Verify TLS certificates (uncheck only if PHP has no CA bundle — lab/dev)
            </label></div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Save update settings</button></div>
        </form>

        <div class="flex gap-1" style="flex-wrap:wrap" id="update-actions">
            <form method="post" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="section" value="update_check">
                <button class="btn btn-secondary" type="submit">Check for updates</button>
            </form>
            <?php if ($updStatus && !empty($updStatus['update_available'])): ?>
            <form method="post" style="display:inline" id="form-update-apply"
                  onsubmit="return coldAisleStartUpdate(this, '<?= App::e((string)$updStatus['latest']) ?>');">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="section" value="update_apply">
                <button class="btn btn-primary" type="submit" id="btn-update-apply">
                    Update to v<?= App::e((string)$updStatus['latest']) ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div id="update-progress" class="alert alert-success" style="display:none;margin-top:.75rem" role="status" aria-live="polite">
            <strong>Updating…</strong>
            <span id="update-progress-text"> Creating backup, downloading release, applying files. This can take 1–3 minutes — keep this tab open.</span>
            <div style="margin-top:.5rem;height:6px;background:var(--surface-2,#1e293b);border-radius:4px;overflow:hidden">
                <div id="update-progress-bar" style="height:100%;width:30%;background:var(--accent,#3b82f6);border-radius:4px;animation:coldaisle-indeterminate 1.2s ease-in-out infinite"></div>
            </div>
        </div>
        <p class="text-muted" style="font-size:.75rem;margin:.75rem 0 0">
            Backups are written to <code>storage/backups/</code>. Requires PHP <code>curl</code>
            <?= extension_loaded('zip') ? ' and <code>zip</code>' : ' (and PowerShell Expand-Archive if <code>zip</code> is missing)' ?>.
            The IIS app pool needs <strong>Modify</strong> on the whole site folder (not only <code>config</code>/<code>storage</code>) for updates to replace application files.
        </p>
        <style>
            @keyframes coldaisle-indeterminate {
                0% { transform: translateX(-100%); width: 40%; }
                50% { width: 60%; }
                100% { transform: translateX(250%); width: 40%; }
            }
        </style>
        <script>
        function coldAisleStartUpdate(form, version) {
            if (!confirm('Backup this install and update to v' + version + '? The site may be briefly unavailable.')) {
                return false;
            }
            var prog = document.getElementById('update-progress');
            var btn = document.getElementById('btn-update-apply');
            if (prog) prog.style.display = 'block';
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Updating to v' + version + '…';
            }
            // Allow other buttons to be disabled so the admin does not double-submit
            document.querySelectorAll('#update-actions button').forEach(function (b) {
                if (b !== btn) b.disabled = true;
            });
            return true;
        }
        </script>
    </div>
</div>

<div class="card" id="backup">
    <div class="card-header"><h2>Site backup &amp; migration</h2></div>
    <div class="card-body">
        <p class="text-muted" style="margin-top:0;font-size:.9rem">
            Export a portable package of this site (database rows, uploads, and <code>app_key</code>)
            to restore on a new web/SQL pair via <strong>setup.php → Restore from backup</strong>.
            The package does <em>not</em> include the SQL password — you enter connection details on the new server.
        </p>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="section" value="export_site_backup">
            <div class="form-row full"><label>
                <input type="checkbox" name="include_audit" value="1" checked>
                Include audit log
            </label></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="include_readings" value="1" checked>
                Include SNMP / PDU historical readings
            </label></div>
            <div class="form-row">
                <button class="btn btn-primary" type="submit"
                        onclick="return confirm('Create and download a site backup ZIP now? Large sites may take a minute.');">
                    Download site backup
                </button>
            </div>
        </form>
        <p class="hint text-muted" style="margin-top:.75rem">
            Files are also stored under <code>storage/backups/</code>. Keep backups private —
            they contain password hashes and encrypted SNMP secrets (and <code>app_key</code> to decrypt them).
            <?= extension_loaded('zip') ? '' : ' PHP <code>zip</code> extension recommended (PowerShell Compress-Archive used as fallback).' ?>
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Environment</h2></div>
    <div class="card-body">
        <table class="data">
            <tr><td>ColdAisle Version</td><td><?= App::e(UpdateService::installedVersion()) ?> <span class="text-muted">(App::VERSION <?= App::e(App::VERSION) ?>)</span></td></tr>
            <tr><td>PHP</td><td><?= App::e(PHP_VERSION) ?></td></tr>
            <tr><td>PDO Drivers</td><td><?= App::e(implode(', ', PDO::getAvailableDrivers())) ?></td></tr>
            <tr><td>LDAP</td><td><?= extension_loaded('ldap') ? 'Yes' : 'No' ?></td></tr>
            <tr><td>SNMP</td><td><?= extension_loaded('snmp') ? 'Yes' : 'No' ?></td></tr>
            <tr><td>cURL</td><td><?= extension_loaded('curl') ? 'Yes' : 'No' ?></td></tr>
            <tr><td>Zip</td><td><?= extension_loaded('zip') ? 'Yes' : 'No (PowerShell fallback)' ?></td></tr>
            <tr><td>Config File</td><td><code><?= App::e($configPath) ?></code></td></tr>
            <tr><td>SQL Host</td><td><?= App::e(($config['database']['host'] ?? '') . '/' . ($config['database']['database'] ?? '')) ?></td></tr>
            <tr><td>Update source</td><td>
                <a href="<?= App::e(UpdateService::githubUrl()) ?>" target="_blank" rel="noopener">
                    sabap/ColdAisle
                </a>
                · public (no token)
            </td></tr>
        </table>
    </div>
</div>
<?php layout_footer(); ?>
