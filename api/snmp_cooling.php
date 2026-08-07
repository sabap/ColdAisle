<?php
/**
 * Cooling unit SNMP actions: discover OIDs, save site template, poll now, auto-poll.
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
function snmp_cooling_load(int $id): array
{
    $unit = Database::fetchOne(
        'SELECT * FROM cooling_units WHERE cooling_unit_id = ? AND is_active = 1',
        [$id]
    );
    if (!$unit) {
        App::json(['error' => 'Cooling unit not found'], 404);
    }
    return $unit;
}

function snmp_cooling_can_edit(array $user): void
{
    if (!AuthManager::canEditCooling($user) && !AuthManager::canEditSnmp($user)) {
        App::json(['error' => 'Forbidden — need edit cooling or SNMP permission'], 403);
    }
}

/**
 * @param array<string,mixed>|null $tpl
 * @return array<string,mixed>|null
 */
function snmp_cooling_template_public(?array $tpl): ?array
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

// Capture Net-SNMP noise so it never corrupts JSON
if (ob_get_level() === 0) {
    ob_start();
}

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
        'error' => 'PHP fatal during cooling SNMP request: ' . $msg
            . ($file !== '' ? " ({$file}:{$line})" : ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

try {
    if ($method === 'GET') {
        $id = (int)($_GET['cooling_unit_id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            App::json(['error' => 'cooling_unit_id required'], 400);
        }
        if (!AuthManager::can($user, 'view_cooling') && !AuthManager::canEditSnmp($user)) {
            App::json(['error' => 'Forbidden'], 403);
        }
        $unit = snmp_cooling_load($id);
        $tid = (int)($unit['snmp_site_template_id'] ?? 0);
        $tpl = $tid > 0 ? SnmpDiscover::getSiteTemplate($tid) : null;
        $prereqs = SnmpDiscover::discoverPrereqsCooling($unit);
        $snap = null;
        if (!empty($unit['last_poll_json'])) {
            $decoded = json_decode((string)$unit['last_poll_json'], true);
            if (is_array($decoded)) {
                $snap = $decoded;
            }
        }
        App::json([
            'cooling_unit_id' => (int)$unit['cooling_unit_id'],
            'name' => (string)$unit['name'],
            'manufacturer' => (string)($unit['manufacturer'] ?? ''),
            'model' => (string)($unit['model'] ?? ''),
            'host' => $prereqs['host'],
            'snmp_enabled' => !empty($unit['snmp_enabled']),
            'snmp_version' => (string)($unit['snmp_version'] ?? ''),
            'snmp_auto_poll' => !empty($unit['snmp_auto_poll']),
            'snmp_site_template_id' => $tid ?: null,
            'snmp_last_poll_at' => $unit['snmp_last_poll_at'] ?? null,
            'last_poll' => $snap,
            'template' => snmp_cooling_template_public($tpl),
            'discover_ready' => $prereqs['ok'],
            'discover_missing' => $prereqs['missing'],
            'template_name_preview' => $prereqs['ok']
                ? SnmpDiscover::templateName($prereqs['vendor'], $prereqs['model'])
                : null,
        ]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        snmp_cooling_can_edit($user);
        $data = api_read_json();
        $action = trim((string)($data['action'] ?? $_GET['action'] ?? ''));
        $id = (int)($data['cooling_unit_id'] ?? $_GET['cooling_unit_id'] ?? 0);
        if ($id < 1) {
            App::json(['error' => 'cooling_unit_id required'], 400);
        }
        $unit = snmp_cooling_load($id);

        if ($action === 'discover') {
            $prereqs = SnmpDiscover::discoverPrereqsCooling($unit);
            if (!$prereqs['ok']) {
                App::json([
                    'error' => 'Cannot discover: missing ' . implode(', ', $prereqs['missing']) . '.',
                    'missing' => $prereqs['missing'],
                ], 400);
            }
            if (empty($unit['snmp_enabled']) && empty($unit['snmp_version'])) {
                App::json(['error' => 'Enable SNMP and set version/credentials on this unit first.'], 400);
            }

            $creds = SnmpDiscover::credsFromCooling($unit);
            // Prefer Liebert/Vertiv LGP trees over APC power/EMS roots
            $result = SnmpDiscover::discover($creds, [
                'family' => 'cooling',
                'manufacturer' => $prereqs['vendor'] ?? '',
                'model' => $prereqs['model'] ?? '',
            ]);
            $templateName = SnmpDiscover::templateName($prereqs['vendor'], $prereqs['model']);
            $existing = SnmpDiscover::findSiteTemplateByName($templateName);

            App::json([
                'ok' => true,
                'cooling_unit_id' => $id,
                'host' => $result['host'],
                'sysDescr' => $result['sysDescr'] ?? null,
                'candidates' => $result['candidates'] ?? [],
                'proposed_map' => $result['proposed_map'] ?? [],
                'walk_count' => $result['walk_count'] ?? 0,
                'message' => (string)($result['message'] ?? ''),
                'template_name' => $templateName,
                'vendor' => $prereqs['vendor'],
                'model' => $prereqs['model'],
                'existing_template' => $existing
                    ? snmp_cooling_template_public($existing)
                    : null,
            ]);
        }

        if ($action === 'save_template') {
            $prereqs = SnmpDiscover::discoverPrereqsCooling($unit);
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
                'Discovered from cooling unit #' . $id . ' (' . ($unit['name'] ?? '') . ')'
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

            SnmpDiscover::assignTemplateToCooling($id, (int)$saved['template_id']);

            // Auto-enable scheduled poll so air units keep updating
            $autoOn = false;
            try {
                Database::update('cooling_units', [
                    'snmp_auto_poll' => 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'cooling_unit_id = :id', [':id' => $id]);
                $autoOn = true;
            } catch (Throwable $e) {
                // ignore
            }

            $tpl = SnmpDiscover::getSiteTemplate((int)$saved['template_id']);
            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                !empty($saved['overwritten']) ? 'snmp_template_overwrite' : 'snmp_template_create',
                'cooling_unit',
                $id,
                [
                    'template_id' => $saved['template_id'],
                    'name' => $saved['name'],
                    'snmp_auto_poll' => $autoOn,
                ]
            );

            $message = (!empty($saved['overwritten'])
                    ? 'Overwrote template "' . $saved['name'] . '"'
                    : 'Created template "' . $saved['name'] . '"')
                . ' and assigned to this cooling unit.';
            if ($autoOn) {
                $message .= ' Scheduled poll enabled (Windows task / poll_snmp.php).';
            }

            App::json([
                'ok' => true,
                'created' => !empty($saved['created']),
                'overwritten' => !empty($saved['overwritten']),
                'snmp_auto_poll' => $autoOn || !empty($unit['snmp_auto_poll']),
                'template' => snmp_cooling_template_public($tpl),
                'message' => $message,
            ]);
        }

        if ($action === 'poll_now') {
            $tid = (int)($unit['snmp_site_template_id'] ?? 0);
            if ($tid < 1) {
                App::json([
                    'error' => 'No site OID template assigned. Run Discover OIDs first.',
                ], 400);
            }
            $result = SnmpPoller::pollCoolingUnit($unit);
            $fresh = Database::fetchOne(
                'SELECT snmp_last_poll_at, last_poll_json, snmp_site_template_id, snmp_auto_poll
                 FROM cooling_units WHERE cooling_unit_id = ?',
                [$id]
            );
            $bits = [(string)($result['message'] ?? 'Poll complete.')];
            $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
            if ($metrics) {
                $sample = [];
                foreach ($metrics as $k => $v) {
                    if (count($sample) >= 8) {
                        break;
                    }
                    $sample[] = $k . '=' . (is_scalar($v) ? (string)$v : json_encode($v));
                }
                $bits[] = 'Sample: ' . implode(', ', $sample)
                    . (count($metrics) > 8 ? '…' : '') . '.';
            }
            if (empty($fresh['snmp_auto_poll'])) {
                $bits[] = 'Tip: enable Scheduled poll so this unit refreshes automatically.';
            } else {
                $bits[] = 'Scheduled poll is on.';
            }
            App::json([
                'ok' => true,
                'result' => $result,
                'snmp_last_poll_at' => $fresh['snmp_last_poll_at'] ?? null,
                'metrics' => $metrics,
                'snmp_auto_poll' => !empty($fresh['snmp_auto_poll']),
                'message' => implode(' ', $bits),
            ]);
        }

        if ($action === 'set_auto_poll') {
            $enabled = !empty($data['enabled']);
            $tid = (int)($unit['snmp_site_template_id'] ?? 0);
            if ($enabled && $tid < 1) {
                App::json([
                    'error' => 'Assign a site OID template (Discover OIDs) before enabling scheduled poll.',
                ], 400);
            }
            $host = trim((string)($unit['primary_ip'] ?? ''));
            if ($enabled && $host === '') {
                App::json(['error' => 'Cooling unit needs a primary IP for scheduled poll.'], 400);
            }

            Database::update('cooling_units', [
                'snmp_auto_poll' => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'cooling_unit_id = :id', [':id' => $id]);

            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                $enabled ? 'snmp_auto_poll_on' : 'snmp_auto_poll_off',
                'cooling_unit',
                $id,
                ['snmp_site_template_id' => $tid ?: null]
            );

            App::json([
                'ok' => true,
                'snmp_auto_poll' => $enabled,
                'message' => $enabled
                    ? 'Scheduled poll enabled — unit is included in the SNMP scheduler.'
                    : 'Scheduled poll disabled.',
            ]);
        }

        App::json(['error' => 'Unknown action. Use discover, save_template, poll_now, or set_auto_poll.'], 400);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API snmp_cooling: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
