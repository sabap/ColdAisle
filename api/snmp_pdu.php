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
            $discoverOpts = [
                'manufacturer' => $prereqs['vendor'],
                'model' => $prereqs['model'],
                'family' => 'auto',
            ];
            // Explicit APC inventory → PowerNet ruleset; other vendors use default (or sysDescr)
            if (SnmpDiscover::isApcManufacturer($prereqs['vendor'])) {
                $discoverOpts['family'] = 'apc';
            }
            $result = SnmpDiscover::discover($creds, $discoverOpts);

            // Prefer inventory model; fall back to SNMP-discovered model for template naming
            $discoveredModel = $result['model_no'] ?? null;
            if (!is_string($discoveredModel) || trim($discoveredModel) === '') {
                $discoveredModel = null;
            } else {
                $discoveredModel = trim($discoveredModel);
            }
            $modelForTpl = trim((string)($prereqs['model'] ?? ''));
            if ($modelForTpl === '' && $discoveredModel !== null) {
                $modelForTpl = $discoveredModel;
            }
            if ($modelForTpl === '') {
                $modelForTpl = 'unknown';
            }
            $templateName = SnmpDiscover::templateName($prereqs['vendor'], $modelForTpl);
            $existing = SnmpDiscover::findSiteTemplateByName($templateName);

            $serialApplied = false;
            $serial = $result['serial_no'] ?? null;
            if (is_string($serial) && $serial !== '') {
                $serialApplied = SnmpDiscover::applySerialToPduIfEmpty($id, $serial);
            }
            $modelApplied = false;
            $modelNo = $discoveredModel;
            if (is_string($modelNo) && $modelNo !== '') {
                $modelApplied = SnmpDiscover::applyModelToPduIfEmpty($id, $modelNo);
            }
            $macApplied = false;
            $mac = $result['mac_address'] ?? null;
            if (is_string($mac) && $mac !== '') {
                $macApplied = SnmpDiscover::applyMacToPduIfEmpty($id, $mac);
            }
            $msg = (string)($result['message'] ?? '');
            if ($serialApplied) {
                $msg .= ' Serial number saved on PDU: ' . $serial . '.';
            } elseif (is_string($serial) && $serial !== '' && !empty($pdu['serial_no'])) {
                $msg .= ' Serial from device: ' . $serial . ' (PDU field already set).';
            } elseif (is_string($serial) && $serial !== '') {
                $msg .= ' Serial from device: ' . $serial . '.';
            }
            if ($modelApplied) {
                $msg .= ' Model saved on PDU: ' . $modelNo . '.';
            } elseif (is_string($modelNo) && $modelNo !== '' && !empty($pdu['model'])) {
                $msg .= ' Model from device: ' . $modelNo . ' (PDU field already set).';
            } elseif (is_string($modelNo) && $modelNo !== '') {
                $msg .= ' Model from device: ' . $modelNo . '.';
            }
            if ($macApplied) {
                $msg .= ' MAC address saved on PDU: ' . $mac . '.';
            } elseif (is_string($mac) && $mac !== '' && !empty($pdu['mac_address'])) {
                $msg .= ' MAC from device: ' . $mac . ' (PDU field already set).';
            }

            App::json([
                'ok' => true,
                'pdu_id' => $id,
                'host' => $result['host'],
                'ruleset' => (string)($result['ruleset'] ?? 'default'),
                'sysDescr' => $result['sysDescr'],
                'candidates' => $result['candidates'],
                'proposed_map' => $result['proposed_map'],
                'walk_count' => $result['walk_count'],
                'message' => $msg,
                'serial_no' => $serial,
                'serial_oid' => $result['serial_oid'] ?? null,
                'serial_applied' => $serialApplied,
                'model_no' => $modelNo,
                'model_oid' => $result['model_oid'] ?? null,
                'model_applied' => $modelApplied,
                'mac_address' => $mac,
                'mac_oid' => $result['mac_oid'] ?? null,
                'mac_applied' => $macApplied,
                'template_name' => $templateName,
                'vendor' => $prereqs['vendor'],
                'model' => $modelForTpl,
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

            // Model for template name: inventory → request body → unknown
            $modelForTpl = trim((string)($prereqs['model'] ?? ''));
            if ($modelForTpl === '' && !empty($data['model_no'])) {
                $modelForTpl = trim((string)$data['model_no']);
            }
            if ($modelForTpl === '') {
                $modelForTpl = 'unknown';
            }

            $saved = SnmpDiscover::saveSiteTemplate(
                $prereqs['vendor'],
                $modelForTpl,
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

            // Optional serial / model from discover UI
            $serialApplied = false;
            if (!empty($data['serial_no'])) {
                $serialApplied = SnmpDiscover::applySerialToPduIfEmpty($id, (string)$data['serial_no']);
            }
            $modelApplied = false;
            if (!empty($data['model_no'])) {
                $modelApplied = SnmpDiscover::applyModelToPduIfEmpty($id, (string)$data['model_no']);
            }

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
                    'serial_applied' => $serialApplied,
                    'model_applied' => $modelApplied,
                ]
            );

            $message = (!empty($saved['overwritten'])
                    ? 'Overwrote template "' . $saved['name'] . '"'
                    : 'Created template "' . $saved['name'] . '"')
                . ' and assigned to this PDU. Enable Scheduled poll to include it in the scheduler.';
            if ($serialApplied) {
                $message .= ' Serial number saved on PDU.';
            }
            if ($modelApplied) {
                $message .= ' Model saved on PDU.';
            }

            App::json([
                'ok' => true,
                'created' => !empty($saved['created']),
                'overwritten' => !empty($saved['overwritten']),
                'serial_applied' => $serialApplied,
                'model_applied' => $modelApplied,
                'template' => snmp_pdu_template_public($tpl),
                'message' => $message,
            ]);
        }

        if ($action === 'poll_now') {
            // Bound wall time so IIS/PHP hard-kill is less likely → blank HTTP 500
            if (function_exists('set_time_limit')) {
                @set_time_limit(55);
            }
            try {
                $result = SnmpPoller::pollPduById($id);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $isTimeout = (bool)preg_match('/timed?\s*out|maximum execution|execution time/i', $msg);
                App::log('PDU poll_now failed pdu_id=' . $id . ': ' . $msg, 'error');
                App::json([
                    'ok' => false,
                    'error' => $isTimeout
                        ? 'SNMP poll timed out talking to the device. Check IP, community/v3 credentials, and UDP/161.'
                        : $msg,
                    'timeout' => $isTimeout,
                ], $isTimeout ? 504 : 500);
            }
            $fresh = Database::fetchOne(
                'SELECT last_poll_at, last_poll_watts, last_poll_amps, last_poll_phases, last_poll_outlets, snmp_site_template_id
                 FROM pdus WHERE pdu_id = ?',
                [$id]
            );
            $bits = [$result['message']];
            if ($fresh && $fresh['last_poll_watts'] !== null) {
                $w = (float)$fresh['last_poll_watts'];
                $bits[] = 'Load ' . ($w >= 1000
                    ? number_format($w / 1000, 3) . ' kW'
                    : rtrim(rtrim(sprintf('%.2F', $w), '0'), '.') . ' W');
            } elseif (!empty($result['load_diag']['summary'])) {
                $bits[] = (string)$result['load_diag']['summary'];
            }
            if ($fresh && $fresh['last_poll_amps'] !== null) {
                $bits[] = rtrim(rtrim(sprintf('%.2F', (float)$fresh['last_poll_amps']), '0'), '.') . ' A';
            }
            // Compact raw load metrics for paste-back / diagnostics
            if (!empty($result['load_diag']['raw']) && is_array($result['load_diag']['raw'])) {
                $snip = [];
                foreach ($result['load_diag']['raw'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $k = (string)($row['key'] ?? '');
                    if (!preg_match('/watt|amp|volt|pf|load|input/i', $k)) {
                        continue;
                    }
                    $snip[] = $k . '=' . ($row['numeric'] !== null && $row['numeric'] !== ''
                        ? $row['numeric']
                        : (string)($row['raw'] ?? '?'));
                    if (count($snip) >= 12) {
                        break;
                    }
                }
                if ($snip) {
                    $bits[] = 'Raw: ' . implode(', ', $snip);
                }
            }
            $phaseJson = $fresh['last_poll_phases'] ?? null;
            $phaseData = is_string($phaseJson) ? json_decode($phaseJson, true) : null;
            if (is_array($phaseData) && $phaseData) {
                $nPh = 0;
                foreach (['L1', 'L2', 'L3'] as $lab) {
                    if (isset($phaseData[$lab])) {
                        $nPh++;
                    }
                }
                if ($nPh > 0) {
                    $bits[] = $nPh . ' phase(s)';
                }
            }
            $outletJson = $fresh['last_poll_outlets'] ?? null;
            $outletData = is_string($outletJson) ? json_decode($outletJson, true) : null;
            if (is_array($outletData) && $outletData) {
                $bits[] = count($outletData) . ' outlet(s)';
            } elseif (!empty($result['outlet_diag']['message'])
                && ($result['outlet_diag']['status'] ?? '') !== 'ok'
            ) {
                // already folded into result.message for form poll; keep JSON detail
            }
            $loadDiag = $result['load_diag'] ?? null;
            if (is_array($loadDiag) && !empty($loadDiag['hints'])) {
                $bits[] = 'Hint: ' . (string)$loadDiag['hints'][0];
            }
            App::json([
                'ok' => true,
                'result' => $result,
                'last_poll_at' => $fresh['last_poll_at'] ?? null,
                'last_poll_watts' => $fresh['last_poll_watts'] ?? null,
                'last_poll_amps' => $fresh['last_poll_amps'] ?? null,
                'last_poll_phases' => is_array($phaseData) ? $phaseData : null,
                'last_poll_outlets' => is_array($outletData) ? $outletData : null,
                'outlet_diag' => $result['outlet_diag'] ?? null,
                'load_diag' => $loadDiag,
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
