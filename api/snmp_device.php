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
            $result = SnmpDiscover::discover($creds);
            $templateName = SnmpDiscover::templateName($prereqs['vendor'], $prereqs['model']);
            $existing = SnmpDiscover::findSiteTemplateByName($templateName);

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

            $tpl = SnmpDiscover::getSiteTemplate((int)$saved['template_id']);
            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                !empty($saved['overwritten']) ? 'snmp_template_overwrite' : 'snmp_template_create',
                'device',
                $id,
                ['template_id' => $saved['template_id'], 'name' => $saved['name']]
            );

            App::json([
                'ok' => true,
                'created' => !empty($saved['created']),
                'overwritten' => !empty($saved['overwritten']),
                'template' => snmp_device_template_public($tpl),
                'message' => !empty($saved['overwritten'])
                    ? ('Overwrote template "' . $saved['name'] . '" and assigned to this device.')
                    : ('Created template "' . $saved['name'] . '" and assigned to this device.'),
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
    App::log('API snmp_device: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
