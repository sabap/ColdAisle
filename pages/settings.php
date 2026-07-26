<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/timezone_field.php';
require_once dirname(__DIR__) . '/src/Services/UpdateService.php';
App::boot();
$user = App::requirePermission('manage_settings');

$configPath = App::configPath();
$config = App::config();

// Authenticated download of Windows task registration script (no OS elevation from web)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['download'] ?? '') === 'snmp_poll_task'
) {
    try {
        if (!class_exists('SnmpSchedulerService')) {
            require_once dirname(__DIR__) . '/src/Services/SnmpSchedulerService.php';
        }
        $body = SnmpSchedulerService::renderedRegistrationScript();
        $fname = 'Register-ColdAisle-SnmpPollTask.ps1';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . (string)strlen($body));
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
        App::redirect('pages/settings.php#snmp-schedule');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    try {
        $section = $_POST['section'] ?? 'general';

        if ($section === 'general') {
            // Application name is fixed to ColdAisle (not user-configurable)
            SettingsService::set('org_name', trim($_POST['org_name'] ?? ''), 'general');
            SettingsService::set('disposal_notify_days', (string)(int)($_POST['disposal_notify_days'] ?? 7), 'lifecycle');
            $config['app_name'] = App::APP_NAME;
            $config['org_name'] = $_POST['org_name'] ?? $config['org_name'] ?? '';
            $config['timezone'] = coldaisle_normalize_timezone($_POST['timezone'] ?? 'UTC');
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
                'tls_insecure' => !empty($_POST['ldaps_tls_insecure']),
                'default_role_id' => $_POST['ldaps_default_role_id'] !== '' ? (int)$_POST['ldaps_default_role_id'] : null,
            ];
            SettingsService::set('auth_ldaps_enabled', !empty($_POST['ldaps_enabled']) ? '1' : '0', 'auth');

            // Enterprise CA upload (PEM/CER) for LDAPS TLS trust
            if (!empty($_POST['ldaps_remove_ca'])) {
                if (method_exists('LdapAuth', 'removeEnterpriseCa') && LdapAuth::removeEnterpriseCa()) {
                    App::flash('success', 'Removed config/ldap-ca.pem (enterprise CA).');
                } elseif (!method_exists('LdapAuth', 'removeEnterpriseCa')) {
                    throw new RuntimeException('Enterprise CA helpers not deployed (update LdapAuth.php).');
                }
            } elseif (!empty($_FILES['ldaps_ca_file']['name'])) {
                if (!method_exists('LdapAuth', 'installEnterpriseCaUpload')) {
                    throw new RuntimeException('Enterprise CA helpers not deployed (update LdapAuth.php).');
                }
                $install = LdapAuth::installEnterpriseCaUpload(
                    $_FILES['ldaps_ca_file'],
                    !empty($_POST['ldaps_ca_append'])
                );
                AuditService::log((int)$user['user_id'], $user['username'], 'ldaps_ca_install', 'system', null, [
                    'cert_count' => $install['cert_count'] ?? 0,
                    'subjects' => $install['subjects'] ?? [],
                ]);
                App::flash('success', $install['message'] ?? 'Enterprise CA installed.');
            }
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

        if ($section === 'mail') {
            if (!class_exists('MailService')) {
                throw new RuntimeException('MailService is not installed on this host. Deploy src/Services/MailService.php.');
            }
            $existingMail = is_array($config['mail'] ?? null) ? $config['mail'] : [];
            $mailCfg = MailService::configFromPost($_POST, $existingMail);
            $from = (string)($mailCfg['from_email'] ?? '');
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('From email is not a valid address.');
            }
            $reply = (string)($mailCfg['reply_to'] ?? '');
            if ($reply !== '' && !filter_var($reply, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Reply-To is not a valid address.');
            }
            if (!empty($mailCfg['enabled']) && !MailService::isConfigured($mailCfg)) {
                throw new RuntimeException(
                    'Cannot enable mail until host and a valid From address are set'
                    . (($mailCfg['auth_mode'] ?? 'none') !== 'none' ? ' (and username when authentication is on).' : '.')
                );
            }
            $config['mail'] = $mailCfg;
            SettingsService::set('mail_enabled', !empty($mailCfg['enabled']) ? '1' : '0', 'mail');
        }

        if ($section === 'power_alerts') {
            if (!class_exists('PowerAlertService')) {
                throw new RuntimeException('PowerAlertService is not installed. Deploy the latest release.');
            }
            $rawEmails = trim((string)($_POST['power_alerts_email'] ?? ''));
            if ($rawEmails !== '') {
                $parsed = PowerAlertService::normalizeEmailList($rawEmails);
                if (!$parsed) {
                    throw new RuntimeException('Alert email list has no valid addresses.');
                }
            }
            PowerAlertService::saveSettingsFromPost($_POST);
            App::flash('success', 'Power alert settings saved.');
            App::redirect('pages/settings.php#power-alerts');
        }

        if ($section === 'snmp_schedule') {
            if (!class_exists('SnmpSchedulerService')) {
                require_once dirname(__DIR__) . '/src/Services/SnmpSchedulerService.php';
            }
            $saved = SnmpSchedulerService::saveFromPost($_POST);
            AuditService::log((int)$user['user_id'], $user['username'], 'snmp_scheduler_save', 'system', null, $saved);
            App::flash(
                'success',
                $saved['enabled']
                    ? ('Scheduled SNMP polling enabled · interval ' . $saved['interval_sec'] . 's. Register the Windows task if status is not Active.')
                    : 'Scheduled SNMP polling disabled in ColdAisle (Windows task may still tick; worker will no-op).'
            );
            App::redirect('pages/settings.php#snmp-schedule');
        }

        if ($section === 'test_mail') {
            // AJAX modal response (same pattern as test_ldaps)
            $json = static function (array $payload): void {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            };
            try {
                if (!class_exists('MailService')) {
                    $json([
                        'ok' => false,
                        'summary' => 'MailService is not installed on this host.',
                        'steps' => [[
                            'ok' => false,
                            'name' => 'MailService',
                            'detail' => 'Deploy src/Services/MailService.php (update to a recent ColdAisle release).',
                        ]],
                    ]);
                }
                $existingMail = is_array($config['mail'] ?? null) ? $config['mail'] : [];
                // Prefer form values so admins can test before clicking Save
                $override = MailService::configFromPost($_POST, $existingMail);
                $override['enabled'] = true;
                $to = trim((string)($_POST['mail_test_to'] ?? ''));
                if ($to === '') {
                    $to = (string)($user['email'] ?? '');
                }

                $encLabel = match ((string)($override['encryption'] ?? 'tls')) {
                    'ssl' => 'SSL/TLS',
                    'tls' => 'STARTTLS',
                    default => 'no encryption',
                };
                $authLabel = match ((string)($override['auth_mode'] ?? 'none')) {
                    'login' => 'AUTH LOGIN',
                    'plain' => 'AUTH PLAIN',
                    default => 'no auth',
                };
                $host = trim((string)($override['host'] ?? ''));
                $port = (int)($override['port'] ?? 0);
                $from = trim((string)($override['from_email'] ?? ''));

                $steps = [];
                $cfgOk = $host !== '' && $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)
                    && (($override['auth_mode'] ?? 'none') === 'none' || trim((string)($override['username'] ?? '')) !== '');
                $steps[] = [
                    'ok' => $cfgOk && $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL),
                    'name' => 'Configuration',
                    'detail' => ($host !== '' ? "{$host}:{$port}" : 'host missing')
                        . " · {$encLabel} · {$authLabel}"
                        . ($from !== '' ? " · From {$from}" : ' · From missing')
                        . ($to !== '' ? " · To {$to}" : ' · recipient missing'),
                ];

                $result = MailService::sendTest($to, $override);
                $ok = !empty($result['ok']);
                $msg = (string)($result['message'] ?? ($ok ? 'Test message sent.' : 'Test message failed.'));
                $steps[] = [
                    'ok' => $ok,
                    'name' => 'SMTP delivery',
                    'detail' => $msg,
                ];
                if ($ok) {
                    $steps[] = [
                        'ok' => true,
                        'name' => 'Inbox check',
                        'detail' => 'Server accepted the message. Check the recipient mailbox (and spam folder).',
                    ];
                }

                AuditService::log((int)$user['user_id'], $user['username'], 'mail_test', 'system', null, [
                    'ok' => $ok,
                    'to' => $to,
                ]);

                $json([
                    'ok' => $ok,
                    'summary' => $ok
                        ? ('Test email accepted for ' . ($to !== '' ? $to : 'recipient') . '.')
                        : $msg,
                    'steps' => $steps,
                    'to' => $to,
                ]);
            } catch (Throwable $e) {
                $json([
                    'ok' => false,
                    'summary' => 'Test error: ' . $e->getMessage(),
                    'steps' => [[
                        'ok' => false,
                        'name' => 'Exception',
                        'detail' => $e->getMessage(),
                    ]],
                ]);
            }
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

        if ($section === 'install_ca_bundle') {
            $result = UpdateService::installCaBundle();
            AuditService::log((int)$user['user_id'], $user['username'], 'install_ca_bundle', 'system', null, [
                'bytes' => $result['bytes'] ?? null,
            ]);
            App::flash('success', $result['message'] ?? 'CA certificates installed.');
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

        if ($section === 'test_ldaps') {
            try {
                $saved = is_array($config['auth']['ldaps'] ?? null) ? $config['auth']['ldaps'] : [];
                $bindPass = (string)($_POST['ldaps_bind_password'] ?? '');
                if ($bindPass === '') {
                    $bindPass = (string)($saved['bind_password'] ?? '');
                }
                $testCfg = [
                    'host' => trim((string)($_POST['ldaps_host'] ?? '')),
                    'port' => (int)($_POST['ldaps_port'] ?? 636),
                    'base_dn' => trim((string)($_POST['ldaps_base_dn'] ?? '')),
                    'user_filter' => trim((string)($_POST['ldaps_user_filter'] ?? '(sAMAccountName={username})')),
                    'bind_dn' => trim((string)($_POST['ldaps_bind_dn'] ?? '')),
                    'bind_password' => $bindPass,
                    'use_ssl' => !empty($_POST['ldaps_use_ssl']),
                    'start_tls' => !empty($_POST['ldaps_start_tls']),
                    'tls_insecure' => !empty($_POST['ldaps_tls_insecure']),
                ];
                $result = LdapAuth::testConnection(
                    $testCfg,
                    trim((string)($_POST['ldaps_test_username'] ?? '')),
                    (string)($_POST['ldaps_test_password'] ?? '')
                );
            } catch (Throwable $e) {
                $result = [
                    'ok' => false,
                    'summary' => 'Test error: ' . $e->getMessage(),
                    'steps' => [],
                ];
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Write config.php (for general / auth / updates / security / mail)
        // power_alerts / snmp_schedule use settings table only (redirect earlier or no config.php)
        if (!in_array($section, [
            'update_check', 'update_apply', 'install_ca_bundle', 'export_site_backup', 'test_ldaps', 'test_mail',
            'power_alerts', 'snmp_schedule',
        ], true)) {
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
    if (str_starts_with($secPost, 'update') || $secPost === 'install_ca_bundle') {
        $redirHash = '#updates';
    } elseif ($secPost === 'security') {
        $redirHash = '#security';
    } elseif ($secPost === 'export_site_backup') {
        $redirHash = '#backup';
    } elseif ($secPost === 'snmp_schedule') {
        $redirHash = '#snmp-schedule';
    } elseif ($secPost === 'ldaps') {
        $redirHash = '#ldaps';
    } elseif ($secPost === 'mail' || $secPost === 'test_mail') {
        $redirHash = '#mail';
    } elseif ($secPost === 'power_alerts') {
        $redirHash = '#power-alerts';
    }
    App::redirect('pages/settings.php' . $redirHash);
}

// Reload config after potential changes on GET
$config = is_file($configPath) ? require $configPath : $config;
$roles = Database::fetchAll('SELECT role_id, name FROM roles ORDER BY role_id');
$ldaps = $config['auth']['ldaps'] ?? [];
$entra = $config['auth']['entra'] ?? [];
$secCfg = App::securityConfig();
// Tolerate partial deploys (newer settings.php + older supporting classes)
$mailCfg = class_exists('MailService') ? MailService::config() : [
    'enabled' => false, 'host' => '', 'port' => 587, 'encryption' => 'tls',
    'auth_mode' => 'login', 'username' => '', 'password' => '',
    'from_email' => '', 'from_name' => App::APP_NAME, 'reply_to' => '',
    'timeout' => 30, 'verify_peer' => true,
];
$mailStatus = class_exists('MailService')
    ? MailService::status()
    : ['ready' => false, 'enabled' => false, 'label' => 'Unavailable', 'detail' => 'MailService not deployed on this host.'];
$powerAlerts = class_exists('PowerAlertService')
    ? PowerAlertService::settings()
    : [
        'enabled' => false, 'email' => '', 'warn_pct' => 75.0, 'crit_pct' => 90.0,
        'cooldown_min' => 60, 'hold_sec' => 120, 'util' => true, 'load_state' => true, 'ps' => true,
    ];
if (!class_exists('SnmpSchedulerService')
    && is_file(dirname(__DIR__) . '/src/Services/SnmpSchedulerService.php')
) {
    require_once dirname(__DIR__) . '/src/Services/SnmpSchedulerService.php';
}
$snmpSchedule = class_exists('SnmpSchedulerService')
    ? SnmpSchedulerService::status()
    : [
        'enabled' => false,
        'interval_sec' => 300,
        'active' => false,
        'status' => 'unavailable',
        'status_label' => 'Unavailable',
        'status_detail' => 'SnmpSchedulerService not deployed on this host.',
        'last_run_at' => null,
        'last_result' => null,
        'last_ok' => 0,
        'last_fail' => 0,
    ];
$updCfg = UpdateService::config();
$caStatus = UpdateService::caBundleStatus();
$ldapCaStatus = method_exists('LdapAuth', 'enterpriseCaStatus')
    ? LdapAuth::enterpriseCaStatus()
    : ['installed' => false, 'cert_count' => 0, 'bytes' => 0, 'subjects' => [], 'path' => null];
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
    <div class="card-header">
        <h2>Support ColdAisle</h2>
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
            <?php
            coldaisle_render_timezone_field([
                'name' => 'timezone',
                'value' => (string)($config['timezone'] ?? 'UTC'),
                'id' => 'timezone_input',
                'full' => true,
                'hint' => 'Type to filter (e.g. New → America/New_York). Click a match or press Enter to choose the first result.',
            ]);
            ?>
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

<div class="card" id="mail">
    <div class="card-header flex-between">
        <h2>Email (SMTP)</h2>
        <span class="badge <?= !empty($mailStatus['ready']) ? 'badge-success' : (!empty($mailStatus['enabled']) ? 'badge-warning' : '') ?>"
              title="<?= App::e($mailStatus['detail'] ?? '') ?>">
            <?= App::e($mailStatus['label'] ?? '—') ?>
        </span>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.9rem;margin-top:0">
            Outbound SMTP for power load alerts, disposal notices, and other system mail.
            Send a test message after saving to confirm the server accepts mail from this host.
        </p>
        <p class="text-muted" style="font-size:.8rem;margin:0 0 1rem">
            <?= App::e($mailStatus['detail'] ?? '') ?>
        </p>

        <form method="post" class="form-grid" id="mail_settings_form">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">

            <div class="form-row full"><label>
                <input type="checkbox" name="mail_enabled" value="1" id="mail_enabled"
                    <?= !empty($mailCfg['enabled']) ? 'checked' : '' ?>>
                Enable outbound email
            </label></div>

            <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">SMTP server</h4></div>
            <div class="form-row"><label>Server host</label>
                <input class="form-control" name="mail_host" id="mail_host"
                       value="<?= App::e($mailCfg['host'] ?? '') ?>"
                       placeholder="smtp.office365.com / smtp.gmail.com / mail.contoso.com"
                       autocomplete="off"></div>
            <div class="form-row"><label>Port</label>
                <input class="form-control" type="number" min="1" max="65535" name="mail_port" id="mail_port"
                       value="<?= (int)($mailCfg['port'] ?? 587) ?>"></div>
            <div class="form-row"><label>Encryption</label>
                <select class="form-control" name="mail_encryption" id="mail_encryption">
                    <?php
                    $mailEnc = (string)($mailCfg['encryption'] ?? 'tls');
                    foreach ([
                        'none' => 'None (typically port 25)',
                        'tls' => 'STARTTLS (typically port 587)',
                        'ssl' => 'SSL/TLS implicit (typically port 465)',
                    ] as $val => $lab): ?>
                        <option value="<?= $val ?>" <?= $mailEnc === $val ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Timeout (seconds)</label>
                <input class="form-control" type="number" min="5" max="120" name="mail_timeout"
                       value="<?= (int)($mailCfg['timeout'] ?? 30) ?>"></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="mail_verify_peer" value="1"
                    <?= !empty($mailCfg['verify_peer']) ? 'checked' : '' ?>>
                Verify TLS certificate (recommended)
            </label>
                <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">
                    Uses the same CA store as Updates (<code>config/cacert.pem</code> or PHP’s CA path).
                    Uncheck only for lab servers with self-signed SMTP certs.
                </p>
            </div>

            <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Authentication</h4></div>
            <div class="form-row"><label>Method</label>
                <select class="form-control" name="mail_auth_mode" id="mail_auth_mode">
                    <?php
                    $mailAuth = (string)($mailCfg['auth_mode'] ?? 'login');
                    foreach ([
                        'none' => 'No authentication',
                        'login' => 'Username / password (AUTH LOGIN)',
                        'plain' => 'Username / password (AUTH PLAIN)',
                    ] as $val => $lab): ?>
                        <option value="<?= $val ?>" <?= $mailAuth === $val ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row mail-auth-fields"><label>Username</label>
                <input class="form-control" name="mail_username" id="mail_username"
                       value="<?= App::e($mailCfg['username'] ?? '') ?>"
                       autocomplete="off"></div>
            <div class="form-row mail-auth-fields"><label>Password</label>
                <input class="form-control" type="password" name="mail_password" id="mail_password"
                       value="" placeholder="<?= !empty($mailCfg['password']) ? '•••• saved (leave blank to keep)' : '' ?>"
                       autocomplete="new-password"></div>

            <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Sender</h4></div>
            <div class="form-row"><label>From email</label>
                <input class="form-control" type="email" name="mail_from_email" id="mail_from_email"
                       value="<?= App::e($mailCfg['from_email'] ?? '') ?>"
                       placeholder="coldaisle@contoso.com"></div>
            <div class="form-row"><label>From name</label>
                <input class="form-control" name="mail_from_name"
                       value="<?= App::e($mailCfg['from_name'] ?? App::APP_NAME) ?>"
                       placeholder="ColdAisle"></div>
            <div class="form-row full"><label>Reply-To (optional)</label>
                <input class="form-control" type="email" name="mail_reply_to"
                       value="<?= App::e($mailCfg['reply_to'] ?? '') ?>"
                       placeholder="Same as From if blank"></div>

            <div class="form-row full" style="margin-top:.35rem;padding-top:.75rem;border-top:1px solid var(--border,#2a3648)">
                <label style="font-weight:600">Send test message</label>
                <p class="text-muted" style="font-size:.75rem;margin:.2rem 0 .5rem">
                    Uses the values in this form (save not required). Leave recipient blank to use your account email
                    (<?= App::e((string)($user['email'] ?? 'not set')) ?>).
                </p>
            </div>
            <div class="form-row"><label>Test recipient</label>
                <input class="form-control" type="email" name="mail_test_to" id="mail_test_to"
                       value="" placeholder="<?= App::e((string)($user['email'] ?? 'you@contoso.com')) ?>"
                       autocomplete="off"></div>
            <div class="form-row full" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <button class="btn btn-primary" type="submit" name="section" value="mail">Save Email</button>
                <button class="btn btn-secondary" type="button" id="mail_test_btn">Send test email</button>
            </div>
        </form>
        <p class="hint text-muted" style="margin-top:.75rem">
            Common setups: Microsoft 365 / Exchange (<code>smtp.office365.com</code>:587 STARTTLS + LOGIN),
            Gmail app password (<code>smtp.gmail.com</code>:587), or your internal relay (often port 25, no auth).
            Ensure this IIS host can reach the SMTP server (firewall / network ACL).
        </p>
        <script>
        (function () {
            var auth = document.getElementById('mail_auth_mode');
            var enc = document.getElementById('mail_encryption');
            var port = document.getElementById('mail_port');
            function toggleAuth() {
                var on = auth && auth.value !== 'none';
                document.querySelectorAll('.mail-auth-fields').forEach(function (el) {
                    el.style.display = on ? '' : 'none';
                });
            }
            var lastEnc = enc ? enc.value : 'tls';
            function suggestPort() {
                if (!enc || !port) return;
                var v = enc.value;
                // Only auto-change when port still matches previous encryption default
                var defaults = { none: '25', tls: '587', ssl: '465' };
                var prevDefault = defaults[lastEnc] || '';
                if (String(port.value) === prevDefault || port.value === '') {
                    port.value = defaults[v] || '587';
                }
                lastEnc = v;
            }
            if (auth) auth.addEventListener('change', toggleAuth);
            if (enc) enc.addEventListener('change', suggestPort);
            toggleAuth();
        })();
        </script>
    </div>
</div>

<!-- SMTP test result modal (same chrome as LDAPS test) -->
<div id="mail_test_modal" class="ldaps-modal" hidden aria-hidden="true">
    <div class="ldaps-modal-backdrop" data-mail-close></div>
    <div class="ldaps-modal-panel ldaps-pending" role="dialog" aria-modal="true" aria-labelledby="mail_test_title">
        <div class="ldaps-modal-head">
            <h3 id="mail_test_title">SMTP test</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-mail-close aria-label="Close">✕</button>
        </div>
        <div id="mail_test_body" class="ldaps-modal-body"></div>
        <div class="ldaps-modal-foot">
            <button type="button" class="btn btn-secondary" data-mail-close>Close</button>
        </div>
    </div>
</div>

<?php
$snmpCardActive = !empty($snmpSchedule['active']);
$snmpBadgeClass = match ((string)($snmpSchedule['status'] ?? 'off')) {
    'active', 'running' => 'badge-success',
    'pending_task', 'stale' => 'badge-warning',
    default => '',
};
?>
<div class="card settings-feature-card <?= $snmpCardActive ? 'settings-feature-active' : 'settings-feature-inactive' ?>"
     id="snmp-schedule" data-snmp-active="<?= $snmpCardActive ? '1' : '0' ?>">
    <div class="card-header flex-between">
        <h2>SNMP schedule</h2>
        <span class="badge <?= App::e($snmpBadgeClass) ?>" id="snmpScheduleBadge">
            <?= App::e((string)$snmpSchedule['status_label']) ?>
        </span>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.9rem;margin-top:0">
            Control <strong>whether</strong> scheduled polling runs and <strong>how often</strong> each enrolled
            PDU/device is polled. ColdAisle never changes Windows Task Scheduler from the browser
            (that would require elevating the web process). A short OS tick runs
            <code>scripts/poll_snmp.php</code>; this page sets policy the worker reads.
        </p>
        <?php if (!class_exists('SnmpSchedulerService')): ?>
            <p class="alert alert-error">SnmpSchedulerService is not deployed. Update ColdAisle to enable this section.</p>
        <?php else: ?>
        <p class="snmp-schedule-status-detail" style="font-size:.88rem;margin:.25rem 0 1rem">
            <?= App::e((string)$snmpSchedule['status_detail']) ?>
        </p>
        <form method="post" class="form-grid" id="snmpScheduleForm">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="section" value="snmp_schedule">

            <div class="form-row full">
                <label class="snmp-schedule-toggle-label" style="display:flex;align-items:center;gap:.65rem;cursor:pointer">
                    <input type="checkbox" name="snmp_scheduler_enabled" value="1" id="snmpSchedulerEnabled"
                        <?= !empty($snmpSchedule['enabled']) ? 'checked' : '' ?>>
                    <span>Enable scheduled SNMP polling</span>
                </label>
            </div>

            <div class="form-row"><label>Default poll interval (seconds)</label>
                <input class="form-control" type="number" min="60" max="86400" step="30"
                       name="snmp_scheduler_interval_sec" id="snmpSchedulerInterval"
                       value="<?= (int)$snmpSchedule['interval_sec'] ?>">
                <span class="text-muted" style="font-size:.75rem">
                    Minimum 60. Applies to PDUs/devices with Scheduled poll on.
                    Classic SNMP targets can override via their own interval.
                </span>
            </div>
            <div class="form-row"><label>Last worker run</label>
                <input class="form-control" type="text" readonly
                       value="<?= App::e($snmpSchedule['last_run_at'] ?? '— never —') ?>">
            </div>
            <?php if (!empty($snmpSchedule['last_result'])): ?>
            <div class="form-row full"><label>Last result</label>
                <input class="form-control" type="text" readonly value="<?= App::e((string)$snmpSchedule['last_result']) ?>">
            </div>
            <?php endif; ?>

            <div class="form-row full" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
                <button class="btn btn-primary" type="submit">Save SNMP schedule</button>
                <a class="btn btn-secondary" href="<?= App::e(App::url('pages/settings.php?download=snmp_poll_task')) ?>">
                    Download task script (.ps1)
                </a>
                <button type="button" class="btn btn-ghost btn-sm" id="snmpShowTaskHelp">Windows task help</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<div id="snmp_task_modal" class="ldaps-modal" hidden aria-hidden="true">
    <div class="ldaps-modal-backdrop" data-snmp-task-close></div>
    <div class="ldaps-modal-panel" role="dialog" aria-modal="true" aria-labelledby="snmp_task_title">
        <div class="ldaps-modal-head">
            <h3 id="snmp_task_title">Register Windows SNMP poll task</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-snmp-task-close aria-label="Close">✕</button>
        </div>
        <div class="ldaps-modal-body" style="font-size:.9rem">
            <p style="margin-top:0">
                Enabling schedule in ColdAisle only sets policy. On the <strong>application server</strong>,
                an administrator must register a Task Scheduler job that invokes the CLI worker
                (fixed short tick; intervals stay in Settings).
            </p>
            <ol style="padding-left:1.2rem;margin:.5rem 0 1rem">
                <li>Download <code>Register-ColdAisle-SnmpPollTask.ps1</code> (paths for this site are filled in).</li>
                <li>Copy it to the server if needed.</li>
                <li>Open <strong>elevated</strong> PowerShell (Run as administrator).</li>
                <li>If scripts are blocked: <code>Set-ExecutionPolicy -Scope Process Bypass</code></li>
                <li>Run: <code>.\Register-ColdAisle-SnmpPollTask.ps1</code></li>
                <li>Save this form with schedule <strong>enabled</strong>, then wait 1–2 minutes —
                    status should become <strong>Active</strong>.</li>
            </ol>
            <p class="text-muted" style="font-size:.8rem;margin-bottom:0">
                The web app never elevates or calls <code>schtasks</code>. To remove the task later:
                <code>.\Register-ColdAisle-SnmpPollTask.ps1 -Unregister</code>
            </p>
        </div>
        <div class="ldaps-modal-foot" style="display:flex;flex-wrap:wrap;gap:.5rem">
            <a class="btn btn-primary" href="<?= App::e(App::url('pages/settings.php?download=snmp_poll_task')) ?>">
                Download .ps1
            </a>
            <button type="button" class="btn btn-secondary" data-snmp-task-close>Close</button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('snmp_task_modal');
    var en = document.getElementById('snmpSchedulerEnabled');
    var help = document.getElementById('snmpShowTaskHelp');
    if (!modal) return;
    function openModal() {
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }
    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }
    modal.querySelectorAll('[data-snmp-task-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });
    if (help) help.addEventListener('click', openModal);
    if (en) {
        var wasOn = en.checked;
        en.addEventListener('change', function () {
            if (en.checked && !wasOn) {
                openModal();
            }
            wasOn = en.checked;
            var card = document.getElementById('snmp-schedule');
            if (card && !en.checked) {
                card.classList.add('settings-feature-inactive');
                card.classList.remove('settings-feature-active');
            }
        });
    }
})();
</script>

<div class="card" id="power-alerts">
    <div class="card-header flex-between">
        <h2>Power alerts</h2>
        <span class="badge <?= !empty($powerAlerts['enabled']) ? 'badge-success' : '' ?>">
            <?= !empty($powerAlerts['enabled']) ? 'Enabled' : 'Off' ?>
        </span>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.9rem;margin-top:0">
            After SNMP poll, evaluate phase util, APC load-state, and power-supply health.
            Alerts are <strong>held and batched</strong> into one digest (not one email per PDU),
            rolled up as PDU → Cabinet → Row → Zone → Datacenter — so a site-wide event
            does not generate dozens of messages.
        </p>
        <?php if (!class_exists('PowerAlertService')): ?>
            <p class="alert alert-error">PowerAlertService is not deployed on this host. Update ColdAisle to enable this section.</p>
        <?php else: ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="section" value="power_alerts">

            <div class="form-row full"><label>
                <input type="checkbox" name="power_alerts_enabled" value="1"
                    <?= !empty($powerAlerts['enabled']) ? 'checked' : '' ?>>
                Enable power alerts
            </label></div>

            <div class="form-row full"><label>Alert email recipients</label>
                <input class="form-control" name="power_alerts_email"
                       value="<?= App::e($powerAlerts['email'] ?? '') ?>"
                       placeholder="ops@contoso.com, oncall@contoso.com">
                <span class="text-muted" style="font-size:.75rem">Comma-separated. Requires Settings → Email (SMTP) enabled.</span>
            </div>

            <div class="form-row"><label>Warning util %</label>
                <input class="form-control" type="number" min="1" max="100" step="1"
                       name="power_alerts_warn_pct" value="<?= App::e((string)(int)$powerAlerts['warn_pct']) ?>">
            </div>
            <div class="form-row"><label>Critical util %</label>
                <input class="form-control" type="number" min="1" max="100" step="1"
                       name="power_alerts_crit_pct" value="<?= App::e((string)(int)$powerAlerts['crit_pct']) ?>">
            </div>
            <div class="form-row"><label>Hold time (minutes)</label>
                <input class="form-control" type="number" min="0.25" max="60" step="0.25"
                       name="power_alerts_hold_min"
                       value="<?= App::e((string)round(((int)($powerAlerts['hold_sec'] ?? 120)) / 60, 2)) ?>">
                <span class="text-muted" style="font-size:.75rem">
                    Wait after the first alert so other PDUs can join the same digest (default 2).
                    One email lists all affected PDUs by location.
                </span>
            </div>
            <div class="form-row"><label>Re-alert cooldown (minutes)</label>
                <input class="form-control" type="number" min="5" max="10080" step="1"
                       name="power_alerts_cooldown_min" value="<?= App::e((string)(int)$powerAlerts['cooldown_min']) ?>">
                <span class="text-muted" style="font-size:.75rem">After a digest, the same condition will not re-queue until this elapses (severity escalations still queue).</span>
            </div>

            <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Check types</h4></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="power_alerts_util" value="1"
                    <?= !empty($powerAlerts['util']) ? 'checked' : '' ?>>
                Phase / device current vs rating (util %)
            </label></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="power_alerts_load_state" value="1"
                    <?= !empty($powerAlerts['load_state']) ? 'checked' : '' ?>>
                APC phase load-state (near overload / overload)
            </label></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="power_alerts_ps" value="1"
                    <?= !empty($powerAlerts['ps']) ? 'checked' : '' ?>>
                Power supply fault / alarm
            </label></div>

            <div class="form-row full">
                <button class="btn btn-primary" type="submit">Save power alerts</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card" id="ldaps">
    <div class="card-header"><h2>LDAPS Authentication</h2></div>
    <div class="card-body">
        <form method="post" class="form-grid" enctype="multipart/form-data">
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
            <div class="form-row full" style="margin-top:.35rem;padding-top:.75rem;border-top:1px solid var(--border,#2a3648)">
                <label style="font-weight:600">Enterprise CA (AD Certificate Services)</label>
                <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 .5rem">
                    Upload your <strong>root</strong> (and intermediate if needed) CA certificate so PHP can trust
                    <code>ldaps://</code> with verification enabled. Stored as <code>config/ldap-ca.pem</code> (not in git).
                    Export from AD CS as <em>Base-64 X.509 (.CER)</em> or PEM.
                </p>
                <?php if (!empty($ldapCaStatus['installed'])): ?>
                    <p style="font-size:.85rem;margin:0 0 .5rem">
                        Status: <span class="badge ok">Installed</span>
                        · <?= (int)$ldapCaStatus['cert_count'] ?> cert(s)
                        · <?= number_format((int)$ldapCaStatus['bytes']) ?> bytes
                        <?php if (!empty($ldapCaStatus['subjects'])): ?>
                            <br><span class="text-muted">Subject(s):
                                <?= App::e(implode(' · ', $ldapCaStatus['subjects'])) ?></span>
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <p style="font-size:.85rem;margin:0 0 .5rem">
                        Status: <span class="badge fail">Not installed</span>
                        — LDAPS verify will fail until a CA is uploaded (or skip-verify is enabled).
                    </p>
                <?php endif; ?>
            </div>
            <div class="form-row full"><label>Upload CA certificate (.pem / .crt / .cer)</label>
                <input class="form-control" type="file" name="ldaps_ca_file"
                       accept=".pem,.crt,.cer,.cert,application/x-x509-ca-cert,application/x-pem-file,text/plain">
            </div>
            <div class="form-row full"><label>
                <input type="checkbox" name="ldaps_ca_append" value="1">
                Append to existing chain (keep current ldap-ca.pem and add this cert)
            </label></div>
            <?php if (!empty($ldapCaStatus['installed'])): ?>
            <div class="form-row full"><label>
                <input type="checkbox" name="ldaps_remove_ca" value="1">
                Remove installed enterprise CA (delete config/ldap-ca.pem)
            </label></div>
            <?php endif; ?>
            <div class="form-row full"><label>
                <input type="checkbox" name="ldaps_tls_insecure" value="1" <?= !empty($ldaps['tls_insecure']) ? 'checked' : '' ?>>
                Skip LDAPS certificate verify (temporary / lab)
            </label>
                <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">
                    Use only until your enterprise CA is uploaded above. After upload, uncheck this and run
                    <strong>Test connection</strong> again.
                </p>
            </div>
            <div class="form-row full" style="margin-top:.5rem;padding-top:.75rem;border-top:1px solid var(--border,#2a3648)">
                <label style="font-weight:600">Connection test</label>
                <p class="text-muted" style="font-size:.75rem;margin:.2rem 0 .5rem">
                    Uses the values in this form (save not required). Leave bind password blank to use the saved password.
                    Optional test user verifies the filter (and password if provided). Does not create ColdAisle users.
                </p>
            </div>
            <div class="form-row"><label>Test username (optional)</label>
                <input class="form-control" type="text" name="ldaps_test_username" id="ldaps_test_username"
                       autocomplete="off" placeholder="domain user (sAMAccountName)"></div>
            <div class="form-row"><label>Test password (optional)</label>
                <input class="form-control" type="password" name="ldaps_test_password" id="ldaps_test_password"
                       autocomplete="new-password" placeholder="Only if testing user bind"></div>
            <div class="form-row full" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <button class="btn btn-primary" type="submit">Save LDAPS</button>
                <button class="btn btn-secondary" type="button" id="ldaps_test_btn">Test connection</button>
            </div>
        </form>
        <p class="hint text-muted">Requires PHP LDAP extension. Use a read-only service account for searches.</p>
    </div>
</div>

<!-- LDAPS test result modal -->
<div id="ldaps_test_modal" class="ldaps-modal" hidden aria-hidden="true">
    <div class="ldaps-modal-backdrop" data-ldaps-close></div>
    <div class="ldaps-modal-panel" role="dialog" aria-modal="true" aria-labelledby="ldaps_test_title">
        <div class="ldaps-modal-head">
            <h3 id="ldaps_test_title">LDAPS test</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-ldaps-close aria-label="Close">✕</button>
        </div>
        <div id="ldaps_test_body" class="ldaps-modal-body"></div>
        <div class="ldaps-modal-foot">
            <button type="button" class="btn btn-secondary" data-ldaps-close>Close</button>
        </div>
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
                Verify TLS certificates when contacting GitHub (recommended)
            </label>
                <p class="text-muted" style="font-size:.75rem;margin:.3rem 0 0">
                    Requires a CA certificate list. Status:
                    <?php if (!empty($caStatus['found'])): ?>
                        <span class="badge ok">OK</span>
                        <code><?= App::e((string)$caStatus['path']) ?></code>
                    <?php else: ?>
                        <span class="badge fail">Missing</span>
                        — click <strong>Install CA certificates</strong> below (keeps verify enabled).
                    <?php endif; ?>
                </p>
            </div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Save update settings</button></div>
        </form>

        <div class="flex gap-1" style="flex-wrap:wrap;margin:.75rem 0" id="update-actions">
            <?php
            $caOk = !empty($caStatus['found']);
            $caBtnTitle = $caOk
                ? 'Already installed — TLS certificate verification is ready. You only need this if Status shows Missing, or to refresh the CA list later.'
                : 'Download Mozilla CA list into config/cacert.pem so “Verify TLS certificates” can work';
            ?>
            <form method="post" style="display:inline"
                  onsubmit="<?= $caOk ? 'return false;' : 'return true;' ?>">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="section" value="install_ca_bundle">
                <button class="btn btn-secondary" type="submit"
                        <?= $caOk ? 'disabled' : '' ?>
                        title="<?= App::e($caBtnTitle) ?>"
                        style="<?= $caOk ? 'opacity:.45;cursor:not-allowed;' : '' ?>">
                    Install CA certificates
                </button>
            </form>
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
<script>
// Shared helpers for settings connection-test modals
function settingsTestEsc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}
function settingsTestStepsHtml(steps) {
    var html = '<ul class="ldaps-steps">';
    (steps || []).forEach(function (step) {
        var stepOk = !!step.ok;
        html += '<li class="' + (stepOk ? 'ldaps-ok' : 'ldaps-bad') + '">';
        html += '<span class="ldaps-ico" aria-hidden="true">' + (stepOk ? '✓' : '✗') + '</span>';
        html += '<span class="ldaps-name">' + settingsTestEsc(step.name || '') + '</span>';
        html += '<span class="ldaps-detail">' + settingsTestEsc(step.detail || '') + '</span>';
        html += '</li>';
    });
    html += '</ul>';
    return html;
}
function settingsTestPendingHtml(msg, sub) {
    return '<div class="settings-test-loading">'
        + '<div class="settings-test-spinner" role="status" aria-label="Loading"></div>'
        + '<p class="ldaps-summary">' + settingsTestEsc(msg || 'Please wait…') + '</p>'
        + (sub ? '<p class="settings-test-sub">' + settingsTestEsc(sub) + '</p>' : '')
        + '</div>';
}

// SMTP test modal
(function () {
    var btn = document.getElementById('mail_test_btn');
    var modal = document.getElementById('mail_test_modal');
    var body = document.getElementById('mail_test_body');
    var title = document.getElementById('mail_test_title');
    if (!btn || !modal || !body) return;

    var form = document.getElementById('mail_settings_form') || btn.closest('form');
    var panel = modal.querySelector('.ldaps-modal-panel');
    var defaultTo = <?= json_encode((string)($user['email'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function openModal() {
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.ldaps-modal:not([hidden]), .app-modal:not([hidden])')) {
            document.body.style.overflow = '';
        }
    }
    modal.querySelectorAll('[data-mail-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    function showResult(data) {
        var ok = !!(data && data.ok);
        panel.classList.remove('ldaps-pass', 'ldaps-fail', 'ldaps-pending');
        panel.classList.add(ok ? 'ldaps-pass' : 'ldaps-fail');
        title.textContent = ok ? 'SMTP test passed' : 'SMTP test failed';
        var html = '<p class="ldaps-summary">' + settingsTestEsc(data.summary || (ok ? 'OK' : 'Failed')) + '</p>';
        html += settingsTestStepsHtml(data.steps || []);
        body.innerHTML = html;
    }

    btn.addEventListener('click', function () {
        if (!form) return;
        panel.classList.remove('ldaps-pass', 'ldaps-fail');
        panel.classList.add('ldaps-pending');
        title.textContent = 'Sending test email…';
        body.innerHTML = settingsTestPendingHtml(
            'Contacting SMTP server — please wait.',
            'This can take several seconds depending on network and timeout settings.'
        );
        openModal();
        btn.disabled = true;

        var fd = new FormData(form);
        fd.set('section', 'test_mail');
        fd.set('_csrf', (window.ColdAisle && window.ColdAisle.csrf) || form.querySelector('[name=_csrf]').value);
        var toEl = document.getElementById('mail_test_to');
        var toVal = toEl && toEl.value ? toEl.value.trim() : '';
        if (!toVal && defaultTo) {
            toVal = defaultTo;
            if (toEl) toEl.value = defaultTo;
        }
        fd.set('mail_test_to', toVal);

        fetch(window.location.pathname + (window.location.search || ''), {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json().then(function (j) {
                return { json: j };
            }).catch(function () {
                return { json: { ok: false, summary: 'Invalid response from server.', steps: [] } };
            });
        }).then(function (res) {
            if (res.json && typeof res.json.ok !== 'undefined') {
                showResult(res.json);
            } else {
                showResult({
                    ok: false,
                    summary: (res.json && res.json.error) || 'Test request failed.',
                    steps: []
                });
            }
        }).catch(function (err) {
            showResult({
                ok: false,
                summary: 'Network error: ' + (err && err.message ? err.message : 'request failed'),
                steps: []
            });
        }).finally(function () {
            btn.disabled = false;
        });
    });
})();

// LDAPS connection test modal
(function () {
    var btn = document.getElementById('ldaps_test_btn');
    var modal = document.getElementById('ldaps_test_modal');
    var body = document.getElementById('ldaps_test_body');
    var title = document.getElementById('ldaps_test_title');
    if (!btn || !modal || !body) return;

    var form = btn.closest('form');
    var panel = modal.querySelector('.ldaps-modal-panel');

    function openModal() {
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.ldaps-modal:not([hidden]), .app-modal:not([hidden])')) {
            document.body.style.overflow = '';
        }
    }
    modal.querySelectorAll('[data-ldaps-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    function showResult(data) {
        var ok = !!(data && data.ok);
        panel.classList.remove('ldaps-pass', 'ldaps-fail', 'ldaps-pending');
        panel.classList.add(ok ? 'ldaps-pass' : 'ldaps-fail');
        title.textContent = ok ? 'LDAPS test passed' : 'LDAPS test failed';
        var html = '<p class="ldaps-summary">' + settingsTestEsc(data.summary || (ok ? 'OK' : 'Failed')) + '</p>';
        html += settingsTestStepsHtml(data.steps || []);
        body.innerHTML = html;
    }

    btn.addEventListener('click', function () {
        if (!form) return;
        panel.classList.remove('ldaps-pass', 'ldaps-fail');
        panel.classList.add('ldaps-pending');
        title.textContent = 'Testing LDAPS…';
        body.innerHTML = settingsTestPendingHtml(
            'Contacting directory — please wait.',
            'Binding and search can take a few seconds.'
        );
        openModal();
        btn.disabled = true;

        var fd = new FormData(form);
        fd.set('section', 'test_ldaps');
        fd.set('_csrf', (window.ColdAisle && window.ColdAisle.csrf) || form.querySelector('[name=_csrf]').value);

        // Ensure optional test fields are included even if outside name quirks
        var tu = document.getElementById('ldaps_test_username');
        var tp = document.getElementById('ldaps_test_password');
        if (tu) fd.set('ldaps_test_username', tu.value || '');
        if (tp) fd.set('ldaps_test_password', tp.value || '');

        fetch(window.location.pathname + (window.location.search || ''), {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json().then(function (j) {
                return { okHttp: r.ok, json: j };
            }).catch(function () {
                return { okHttp: false, json: { ok: false, summary: 'Invalid response from server.', steps: [] } };
            });
        }).then(function (res) {
            if (res.json && typeof res.json.ok !== 'undefined') {
                showResult(res.json);
            } else {
                showResult({
                    ok: false,
                    summary: (res.json && res.json.error) || 'Test request failed.',
                    steps: []
                });
            }
        }).catch(function (err) {
            showResult({
                ok: false,
                summary: 'Network error: ' + (err && err.message ? err.message : 'request failed'),
                steps: []
            });
        }).finally(function () {
            btn.disabled = false;
        });
    });
})();
</script>
<?php layout_footer(); ?>
