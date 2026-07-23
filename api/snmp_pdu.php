<?php
/**
 * PDU SNMP actions: discover OIDs, save site template, poll now.
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
function snmp_pdu_load(int $id): array
{
    $pdu = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$id]);
    if (!$pdu) {
        App::json(['error' => 'PDU not found'], 404);
    }
    return $pdu;
}

function snmp_pdu_can_edit(array $user): void
{
    if (!AuthManager::canEditPower($user) && !AuthManager::canEditSnmp($user)) {
        App::json(['error' => 'Forbidden — need edit power or SNMP permission'], 403);
    }
}

/**
 * @param array<string,mixed>|null $tpl
 * @return array<string,mixed>|null
 */
function snmp_pdu_template_public(?array $tpl): ?array
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
        $id = (int)($_GET['pdu_id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            App::json(['error' => 'pdu_id required'], 400);
        }
        $pdu = snmp_pdu_load($id);
        $tid = (int)($pdu['snmp_site_template_id'] ?? 0);
        $tpl = $tid > 0 ? SnmpDiscover::getSiteTemplate($tid) : null;
        $prereqs = SnmpDiscover::discoverPrereqsPdu($pdu);
        App::json([
            'pdu_id' => (int)$pdu['pdu_id'],
            'name' => (string)$pdu['name'],
            'manufacturer' => (string)($pdu['manufacturer'] ?? ''),
            'model' => (string)($pdu['model'] ?? ''),
            'host' => $prereqs['host'],
            'snmp_enabled' => !empty($pdu['snmp_enabled']),
            'snmp_version' => (string)($pdu['snmp_version'] ?? ''),
            'snmp_site_template_id' => $tid ?: null,
            'last_poll_at' => $pdu['last_poll_at'] ?? null,
            'last_poll_watts' => $pdu['last_poll_watts'] ?? null,
            'last_poll_amps' => $pdu['last_poll_amps'] ?? null,
            'template' => snmp_pdu_template_public($tpl),
            'discover_ready' => $prereqs['ok'],
            'discover_missing' => $prereqs['missing'],
            'template_name_preview' => $prereqs['ok']
                ? SnmpDiscover::templateName($prereqs['vendor'], $prereqs['model'])
                : null,
        ]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        snmp_pdu_can_edit($user);
        $data = api_read_json();
        $action = trim((string)($data['action'] ?? $_GET['action'] ?? ''));
        $id = (int)($data['pdu_id'] ?? $_GET['pdu_id'] ?? 0);
        if ($id < 1) {
            App::json(['error' => 'pdu_id required'], 400);
        }
        $pdu = snmp_pdu_load($id);

        if ($action === 'discover') {
            $prereqs = SnmpDiscover::discoverPrereqsPdu($pdu);
            if (!$prereqs['ok']) {
                App::json([
                    'error' => 'Cannot discover: missing ' . implode(', ', $prereqs['missing']) . '.',
                    'missing' => $prereqs['missing'],
                ], 400);
            }
            if (empty($pdu['snmp_enabled']) && empty($pdu['snmp_version'])) {
                App::json(['error' => 'Enable SNMP and set version/credentials on this PDU first.'], 400);
            }

            $creds = SnmpDiscover::credsFromPdu($pdu);
            $result = SnmpDiscover::discover($creds);
            $templateName = SnmpDiscover::templateName($prereqs['vendor'], $prereqs['model']);
            $existing = SnmpDiscover::findSiteTemplateByName($templateName);

            App::json([
                'ok' => true,
                'pdu_id' => $id,
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
                    ? snmp_pdu_template_public($existing)
                    : null,
            ]);
        }

        if ($action === 'save_template') {
            $prereqs = SnmpDiscover::discoverPrereqsPdu($pdu);
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
                'Discovered from PDU #' . $id . ' (' . ($pdu['name'] ?? '') . ')'
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

            // Link template to PDU only (no snmp_targets row — Poll now / auto-poll use the template)
            SnmpDiscover::assignTemplateToPdu($id, (int)$saved['template_id']);

            $tpl = SnmpDiscover::getSiteTemplate((int)$saved['template_id']);
            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                !empty($saved['overwritten']) ? 'snmp_template_overwrite' : 'snmp_template_create',
                'pdu',
                $id,
                [
                    'template_id' => $saved['template_id'],
                    'name' => $saved['name'],
                ]
            );

            App::json([
                'ok' => true,
                'created' => !empty($saved['created']),
                'overwritten' => !empty($saved['overwritten']),
                'template' => snmp_pdu_template_public($tpl),
                'message' => (!empty($saved['overwritten'])
                        ? 'Overwrote template "' . $saved['name'] . '"'
                        : 'Created template "' . $saved['name'] . '"')
                    . ' and assigned to this PDU. Enable Scheduled poll to include it in the scheduler.',
            ]);
        }

        if ($action === 'poll_now') {
            $result = SnmpPoller::pollPduById($id);
            $fresh = Database::fetchOne(
                'SELECT last_poll_at, last_poll_watts, last_poll_amps, snmp_site_template_id
                 FROM pdus WHERE pdu_id = ?',
                [$id]
            );
            $bits = [$result['message']];
            if ($fresh && $fresh['last_poll_watts'] !== null) {
                $w = (float)$fresh['last_poll_watts'];
                $bits[] = 'Load ' . ($w >= 1000
                    ? number_format($w / 1000, 3) . ' kW'
                    : rtrim(rtrim(sprintf('%.2F', $w), '0'), '.') . ' W');
            }
            if ($fresh && $fresh['last_poll_amps'] !== null) {
                $bits[] = rtrim(rtrim(sprintf('%.2F', (float)$fresh['last_poll_amps']), '0'), '.') . ' A';
            }
            App::json([
                'ok' => true,
                'result' => $result,
                'last_poll_at' => $fresh['last_poll_at'] ?? null,
                'last_poll_watts' => $fresh['last_poll_watts'] ?? null,
                'last_poll_amps' => $fresh['last_poll_amps'] ?? null,
                'message' => implode(' ', $bits),
            ]);
        }

        if ($action === 'set_auto_poll') {
            $enabled = !empty($data['enabled']);
            $tid = (int)($pdu['snmp_site_template_id'] ?? 0);
            if ($enabled && $tid < 1) {
                App::json([
                    'error' => 'Assign a site OID template (Discover OIDs) before enabling scheduled poll.',
                ], 400);
            }
            if ($enabled && empty($pdu['ip_address'])) {
                App::json(['error' => 'PDU needs an IP address for scheduled poll.'], 400);
            }

            Database::update('pdus', [
                'snmp_auto_poll' => $enabled ? 1 : 0,
            ], 'pdu_id = :id', [':id' => $id]);

            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                $enabled ? 'snmp_auto_poll_on' : 'snmp_auto_poll_off',
                'pdu',
                $id,
                ['snmp_site_template_id' => $tid ?: null]
            );

            App::json([
                'ok' => true,
                'snmp_auto_poll' => $enabled,
                'message' => $enabled
                    ? 'Scheduled poll enabled — PDU is included in the SNMP scheduler.'
                    : 'Scheduled poll disabled.',
            ]);
        }

        App::json(['error' => 'Unknown action. Use discover, save_template, poll_now, or set_auto_poll.'], 400);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API snmp_pdu: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
