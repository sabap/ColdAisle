<?php
/**
 * Device SNMP actions: discover OIDs, save site template, poll now, auto-poll toggle.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/src/Services/SnmpDiscover.php';
require_once dirname(__DIR__) . '/src/Services/SnmpPoller.php';

$method = api_method();
$user = AuthManager::user();

/**
 * @return array<string,mixed>
 */
function snmp_device_load(int $id): array
{
    $dev = Database::fetchOne('SELECT * FROM devices WHERE device_id = ? AND is_active = 1', [$id]);
    if (!$dev) {
        App::json(['error' => 'Device not found'], 404);
    }
    return $dev;
}

/**
 * @param array<string,mixed> $device
 */
function snmp_device_can_edit(array $user, array $device): void
{
    if (!AuthManager::canEditDevice($user, $device) && !AuthManager::canEditSnmp($user)) {
        App::json(['error' => 'Forbidden — cannot edit this device / SNMP'], 403);
    }
}

/**
 * @param array<string,mixed>|null $tpl
 * @return array<string,mixed>|null
 */
function snmp_device_template_public(?array $tpl): ?array
{
    if (!$tpl) {
        return null;
    }
    $map = json_decode((string)($tpl['oid_map'] ?? '{}'), true);
    if (!is_array($map)) {
        $map = [];
    }
    return [
        'template_id' => (int)$tpl['template_id'],
        'name' => (string)$tpl['name'],
        'vendor' => (string)($tpl['vendor'] ?? ''),
        'model' => (string)($tpl['model'] ?? ''),
        'source' => (string)($tpl['source'] ?? ''),
        'oid_map' => $map,
        'is_active' => !empty($tpl['is_active']),
        'updated_at' => $tpl['updated_at'] ?? null,
    ];
}

// Capture accidental Net-SNMP / extension noise so it never corrupts JSON
if (ob_get_level() === 0) {
    ob_start();
}

// Convert fatal PHP errors into JSON (IIS otherwise returns bare "Internal Server Error")
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'] ?? 0, $fatal, true)) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $msg = (string)($err['message'] ?? 'Fatal error');
    $file = basename((string)($err['file'] ?? ''));
    $line = (int)($err['line'] ?? 0);
    echo json_encode([
        'error' => 'PHP fatal during SNMP request: ' . $msg
            . ($file !== '' ? " ({$file}:{$line})" : ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

try {
    if ($method === 'GET') {
        $id = (int)($_GET['device_id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            App::json(['error' => 'device_id required'], 400);
        }
        $dev = snmp_device_load($id);
        $tpl = null;
        $tid = (int)($dev['snmp_site_template_id'] ?? 0);
        if ($tid > 0) {
            $tpl = SnmpDiscover::getSiteTemplate($tid);
        }
        $prereqs = SnmpDiscover::discoverPrereqs($dev);
        App::json([
            'device_id' => (int)$dev['device_id'],
            'label' => (string)$dev['label'],
            'manufacturer' => (string)($dev['manufacturer'] ?? ''),
            'model' => (string)($dev['model'] ?? ''),
            'host' => $prereqs['host'],
            'snmp_version' => (string)($dev['snmp_version'] ?? ''),
            'snmp_auto_poll' => !empty($dev['snmp_auto_poll']),
            'snmp_site_template_id' => $tid ?: null,
            'snmp_last_poll_at' => $dev['snmp_last_poll_at'] ?? null,
            'snmp_last_poll_watts' => $dev['snmp_last_poll_watts'] ?? null,
            'snmp_last_poll_amps' => $dev['snmp_last_poll_amps'] ?? null,
            'template' => snmp_device_template_public($tpl),
            'discover_ready' => $prereqs['ok'],
            'discover_missing' => $prereqs['missing'],
            'template_name_preview' => $prereqs['ok']
                ? SnmpDiscover::templateName($prereqs['vendor'], $prereqs['model'])
                : null,
        ]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $data = api_read_json();
        $action = trim((string)($data['action'] ?? $_GET['action'] ?? ''));
        $id = (int)($data['device_id'] ?? $_GET['device_id'] ?? 0);
        if ($id < 1) {
            App::json(['error' => 'device_id required'], 400);
        }
        $dev = snmp_device_load($id);
        snmp_device_can_edit($user, $dev);

        if ($action === 'discover') {
            @ini_set('max_execution_time', '25');
            if (function_exists('set_time_limit')) {
                @set_time_limit(25);
            }
            // Prevent Windows Net-SNMP MIB autoload hang (same as poll worker)
            @putenv('MIBS=');
            @putenv('MIBDIRS=' . str_replace('\\', '/', dirname(__DIR__) . '/storage/snmp/mibs'));

            $stepFile = dirname(__DIR__) . '/storage/logs/snmp_discover_last.txt';
            $mark = static function (string $s) use ($stepFile, $id): void {
                @file_put_contents(
                    $stepFile,
                    date('c') . " device_id={$id} {$s}\n",
                    LOCK_EX
                );
                App::log("snmp_device discover device_id={$id} {$s}", 'info');
            };
            $mark('begin');

            $prereqs = SnmpDiscover::discoverPrereqs($dev);
            if (!$prereqs['ok']) {
                App::json([
                    'error' => 'Cannot discover: missing ' . implode(', ', $prereqs['missing']) . '.',
                    'missing' => $prereqs['missing'],
                ], 400);
            }
            if (empty($dev['snmp_version'])) {
                App::json(['error' => 'SNMP version is not configured on this device.'], 400);
            }

            $creds = SnmpDiscover::credsFromDevice($dev);
            $ver = strtolower((string)($creds['snmp_version'] ?? ''));
            $mark('version=' . $ver . ' host=' . ($creds['host'] ?? ''));
            if ($ver === '3' || $ver === 'v3') {
                if (trim((string)($creds['security_name'] ?? '')) === '') {
                    App::json([
                        'error' => 'SNMPv3 user (security name) is empty. Open Edit, set SNMP version 3, '
                            . 'select a credential profile (or enter the v3 user), Save, then Discover again.',
                    ], 400);
                }
            }
            if (in_array($ver, ['1', '2c', '2'], true) && trim((string)($creds['community'] ?? '')) === '') {
                App::json([
                    'error' => 'SNMP community is empty. Set the read community on the device and Save.',
                ], 400);
            }

            try {
                $mark('discover_call');
                $result = SnmpDiscover::discover($creds);
                $mark('discover_ok walk=' . (int)($result['walk_count'] ?? 0));
            } catch (Throwable $e) {
                $mark('discover_fail ' . $e->getMessage());
                App::log('Device discover failed device_id=' . $id . ': ' . $e->getMessage(), 'error');
                App::json([
                    'error' => $e->getMessage() !== ''
                        ? $e->getMessage()
                        : 'SNMP discovery failed (no detail). Check IP, community/v3, UDP/161 from IIS.',
                ], 500);
            }

            try {
                $templateName = SnmpDiscover::templateName($prereqs['vendor'], $prereqs['model']);
            } catch (Throwable $e) {
                App::json(['error' => 'Template name: ' . $e->getMessage()], 400);
            }
            $existing = SnmpDiscover::findSiteTemplateByName($templateName);
            $mark('done template=' . $templateName);

            App::json([
                'ok' => true,
                'device_id' => $id,
                'host' => $result['host'],
                'sysDescr' => $result['sysDescr'],
                'candidates' => $result['candidates'],
                'proposed_map' => $result['proposed_map'],
                'walk_count' => $result['walk_count'],
                'message' => $result['message'],
                'template_name' => $templateName,
                'vendor' => $prereqs['vendor'],
                'model' => $prereqs['model'],
                'existing_template' => $existing
                    ? snmp_device_template_public($existing)
                    : null,
            ]);
        }

        if ($action === 'save_template') {
            // Only after a successful discover (client-side); still re-check prereqs
            $prereqs = SnmpDiscover::discoverPrereqs($dev);
            if (!$prereqs['ok']) {
                App::json([
                    'error' => 'Cannot create template: missing ' . implode(', ', $prereqs['missing']) . '.',
                    'missing' => $prereqs['missing'],
                ], 400);
            }

            $oidMap = $data['oid_map'] ?? null;
            if (!is_array($oidMap) || !$oidMap) {
                App::json(['error' => 'oid_map required (from discover results).'], 400);
            }
            $overwrite = !empty($data['overwrite']);

            $saved = SnmpDiscover::saveSiteTemplate(
                $prereqs['vendor'],
                $prereqs['model'],
                $oidMap,
                $overwrite,
                'discovered',
                'Discovered from device #' . $id . ' (' . ($dev['label'] ?? '') . ')'
            );

            if (!empty($saved['exists'])) {
                App::json([
                    'ok' => false,
                    'exists' => true,
                    'template_id' => $saved['template_id'],
                    'name' => $saved['name'],
                    'oid_map' => $saved['oid_map'],
                    'message' => 'Template "' . $saved['name'] . '" already exists. Cancel or overwrite.',
                ], 409);
            }

            // Assign to this device so Poll now / auto-poll use it
            SnmpDiscover::assignTemplateToDevice($id, (int)$saved['template_id']);

            // Env managers: enable scheduled poll by default so probes keep updating
            $autoOn = false;
            $dtype = (string)($dev['device_type'] ?? '');
            if (in_array($dtype, ['env_monitor', 'env_module'], true)) {
                try {
                    Database::update('devices', [
                        'snmp_auto_poll' => 1,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], 'device_id = :id', [':id' => $id]);
                    $autoOn = true;
                } catch (Throwable $e) {
                    // ignore
                }
            }

            $tpl = SnmpDiscover::getSiteTemplate((int)$saved['template_id']);
            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                !empty($saved['overwritten']) ? 'snmp_template_overwrite' : 'snmp_template_create',
                'device',
                $id,
                ['template_id' => $saved['template_id'], 'name' => $saved['name'], 'snmp_auto_poll' => $autoOn]
            );

            $msg = !empty($saved['overwritten'])
                ? ('Overwrote template "' . $saved['name'] . '" and assigned to this device.')
                : ('Created template "' . $saved['name'] . '" and assigned to this device.');
            if ($autoOn) {
                $msg .= ' Scheduled poll enabled for this env device (Windows task / poll_snmp.php).';
            }

            App::json([
                'ok' => true,
                'created' => !empty($saved['created']),
                'overwritten' => !empty($saved['overwritten']),
                'snmp_auto_poll' => $autoOn || !empty($dev['snmp_auto_poll']),
                'template' => snmp_device_template_public($tpl),
                'message' => $msg,
            ]);
        }

        if ($action === 'poll_now') {
            $tid = (int)($dev['snmp_site_template_id'] ?? 0);
            if ($tid < 1) {
                App::json([
                    'error' => 'No site OID template assigned. Run Discover OIDs first.',
                ], 400);
            }
            $result = SnmpPoller::pollDevice($dev);
            $fresh = Database::fetchOne(
                'SELECT snmp_last_poll_at, snmp_last_poll_watts, snmp_last_poll_amps, snmp_site_template_id
                 FROM devices WHERE device_id = ?',
                [$id]
            );
            $bits = ['Polled ' . $result['ok'] . ' metric(s) from site template.'];
            if ($result['failed'] > 0) {
                $bits[] = $result['failed'] . ' OID(s) soft-failed.';
            }
            $env = is_array($result['env'] ?? null) ? $result['env'] : [];
            if (!empty($env['probes'])) {
                $bits[] = (int)$env['probes'] . ' EMS probe(s) seen.';
            }
            // Prefer L#/R# + temp snapshot (shows which slots are live vs 0° dead)
            if (!empty($env['snapshot']) && is_array($env['snapshot'])) {
                $bits[] = 'Slots: ' . implode(', ', array_slice($env['snapshot'], 0, 12))
                    . (count($env['snapshot']) > 12 ? '…' : '') . '.';
            } elseif (!empty($result['probe_names']) && is_array($result['probe_names'])) {
                $sample = [];
                foreach ($result['probe_names'] as $pi => $pn) {
                    $sample[] = $pi . '=' . $pn;
                    if (count($sample) >= 6) {
                        break;
                    }
                }
                if ($sample) {
                    $bits[] = 'Probe names: ' . implode(', ', $sample)
                        . (count($result['probe_names']) > 6 ? '…' : '') . '.';
                }
            }
            if (!empty($env['updated'])) {
                $envBit = 'Updated ' . (int)$env['updated'] . ' env sensor(s)';
                if (!empty($env['readings'])) {
                    $envBit .= ' (' . (int)$env['readings'] . ' reading(s))';
                }
                if (!empty($env['candidates'])) {
                    $envBit .= ' of ' . (int)$env['candidates'] . ' candidate(s)';
                }
                $bits[] = $envBit . '.';
                if (!empty($env['matched']) && is_array($env['matched'])) {
                    $bits[] = 'Map: ' . implode('; ', array_slice($env['matched'], 0, 8))
                        . (count($env['matched']) > 8 ? '…' : '') . '.';
                }
            } elseif (!empty($env['keys']) || !empty($env['probes'])) {
                $bits[] = 'EMS probes answered but no env sensors matched by name/index '
                    . '(candidates=' . (int)($env['candidates'] ?? 0) . '). '
                    . 'Name sensors like the EMS probe labels, or set Probe/map key to the SNMP index.';
            }
            if (!empty($env['skipped_dead'])) {
                $bits[] = 'Skipped ' . (int)$env['skipped_dead'] . ' dead/empty probe slot(s).';
            }
            if (!empty($env['alerts']['alerted'])) {
                $bits[] = 'Env alerts mailed: ' . (int)$env['alerts']['alerted'] . '.';
            }
            $autoPoll = !empty($dev['snmp_auto_poll']);
            if (!$autoPoll && in_array((string)($dev['device_type'] ?? ''), ['env_monitor', 'env_module'], true)) {
                $bits[] = 'Tip: enable Scheduled poll on this device so sensors refresh automatically.';
            } elseif ($autoPoll) {
                $bits[] = 'Scheduled poll is on.';
            }
            $memLive = 0;
            $uioLive = 0;
            if (!empty($result['probe_meta']) && is_array($result['probe_meta'])) {
                foreach ($result['probe_meta'] as $pm) {
                    if (empty($pm['live'])) {
                        continue;
                    }
                    if (($pm['source'] ?? '') === 'mem') {
                        $memLive++;
                    } elseif (($pm['source'] ?? '') === 'uio') {
                        $uioLive++;
                    }
                }
            }
            if ($memLive > 0) {
                $bits[] = $memLive . ' MEM modular sensor(s) live.';
            }
            if ($uioLive > 0) {
                $bits[] = $uioLive . ' UIO expansion sensor(s) live.';
            }
            if ($fresh && $fresh['snmp_last_poll_watts'] !== null) {
                $w = (float)$fresh['snmp_last_poll_watts'];
                $bits[] = 'Load ' . ($w >= 1000 ? number_format($w / 1000, 3) . ' kW' : rtrim(rtrim(sprintf('%.2F', $w), '0'), '.') . ' W');
            }
            if ($fresh && $fresh['snmp_last_poll_amps'] !== null) {
                $bits[] = rtrim(rtrim(sprintf('%.2F', (float)$fresh['snmp_last_poll_amps']), '0'), '.') . ' A';
            }
            App::json([
                'ok' => true,
                'result' => $result,
                'snmp_last_poll_at' => $fresh['snmp_last_poll_at'] ?? null,
                'snmp_last_poll_watts' => $fresh['snmp_last_poll_watts'] ?? null,
                'snmp_last_poll_amps' => $fresh['snmp_last_poll_amps'] ?? null,
                'snmp_auto_poll' => !empty($dev['snmp_auto_poll']),
                'env' => $env,
                'message' => implode(' ', $bits),
            ]);
        }

        if ($action === 'set_auto_poll') {
            $enabled = !empty($data['enabled']);
            $tid = (int)($dev['snmp_site_template_id'] ?? 0);
            if ($enabled && $tid < 1) {
                App::json([
                    'error' => 'Assign a site OID template (Discover OIDs) before enabling auto-poll.',
                ], 400);
            }
            if ($enabled && empty($dev['snmp_version'])) {
                App::json(['error' => 'SNMP version must be configured before auto-poll.'], 400);
            }
            $prereqs = SnmpDiscover::discoverPrereqs($dev);
            if ($enabled && $prereqs['host'] === '') {
                App::json(['error' => 'Device needs a management or primary IP for auto-poll.'], 400);
            }

            Database::update('devices', [
                'snmp_auto_poll' => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'device_id = :id', [':id' => $id]);

            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                $enabled ? 'snmp_auto_poll_on' : 'snmp_auto_poll_off',
                'device',
                $id,
                ['snmp_site_template_id' => $tid ?: null]
            );

            App::json([
                'ok' => true,
                'snmp_auto_poll' => $enabled,
                'message' => $enabled
                    ? 'Scheduled poll enabled — device is included in the SNMP scheduler.'
                    : 'Scheduled poll disabled.',
            ]);
        }

        App::json(['error' => 'Unknown action. Use discover, save_template, poll_now, or set_auto_poll.'], 400);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    App::log('API snmp_device: ' . $e->getMessage(), 'error');
    $msg = $e->getMessage();
    if ($msg === '') {
        $msg = 'SNMP request failed unexpectedly. Check storage/logs/app.log and that PHP snmp is loaded.';
    }
    App::json(['error' => $msg], 500);
}
