<?php
/**
 * UPS SNMP Discover / Poll / auto-poll toggle.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/src/Services/SnmpDiscover.php';
require_once dirname(__DIR__) . '/src/Services/SnmpPoller.php';
require_once dirname(__DIR__) . '/includes/ups_helpers.php';

api_require_permission('view_power');
$method = api_method();
$user = AuthManager::user();

try {
    if ($method === 'POST') {
        api_require_csrf();
        if (!AuthManager::canEditPower($user) && !AuthManager::canEditSnmp($user)) {
            App::json(['error' => 'Forbidden'], 403);
        }
        $data = api_read_json();
        $action = trim((string)($data['action'] ?? ''));
        $upsId = (int)($data['ups_id'] ?? 0);
        if ($upsId < 1) {
            App::json(['error' => 'ups_id required'], 400);
        }
        $unit = Database::fetchOne('SELECT * FROM ups_units WHERE ups_id = ? AND is_active = 1', [$upsId]);
        if (!$unit) {
            App::json(['error' => 'UPS not found'], 404);
        }

        if ($action === 'set_auto_poll') {
            $on = !empty($data['enabled']);
            Database::update('ups_units', [
                'snmp_auto_poll' => $on ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'ups_id = :id', [':id' => $upsId]);
            App::json(['ok' => true, 'snmp_auto_poll' => $on, 'message' => $on ? 'Scheduled poll on' : 'Scheduled poll off']);
        }

        if ($action === 'assign_template') {
            $tid = (int)($data['template_id'] ?? 0);
            if ($tid > 0) {
                $tpl = Database::fetchOne(
                    'SELECT template_id, name FROM snmp_site_oid_templates WHERE template_id = ? AND is_active = 1',
                    [$tid]
                );
                if (!$tpl) {
                    App::json(['error' => 'Site OID template not found or inactive'], 404);
                }
                SnmpDiscover::assignTemplateToUps($upsId, $tid);
                App::json([
                    'ok' => true,
                    'template_id' => $tid,
                    'template_name' => (string)($tpl['name'] ?? ''),
                    'message' => 'Assigned site OID template: ' . (string)($tpl['name'] ?? ('#' . $tid)),
                ]);
            }
            // Clear assignment
            SnmpDiscover::assignTemplateToUps($upsId, 0);
            App::json([
                'ok' => true,
                'template_id' => null,
                'message' => 'Site OID template cleared from this UPS.',
            ]);
        }

        if ($action === 'create_default_template') {
            // Create/update a default APC PowerNet UPS map and assign it (no live discover)
            $map = ups_default_apc_oid_map();
            $name = trim((string)($data['template_name'] ?? ''));
            if ($name === '') {
                $name = 'APC UPS — PowerNet (default)';
            }
            $existingId = (int)($unit['snmp_site_template_id'] ?? 0);
            // Prefer reuse of any existing default-named template
            $byName = Database::fetchOne(
                'SELECT template_id FROM snmp_site_oid_templates WHERE name = ? AND is_active = 1',
                [$name]
            );
            if ($byName && (int)$byName['template_id'] > 0) {
                $existingId = (int)$byName['template_id'];
            }
            $payload = [
                'name' => mb_substr($name, 0, 150),
                'oid_map' => json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'notes' => 'UPS / PowerNet default map (enterprise 318.1.1.1)',
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($existingId > 0) {
                Database::update('snmp_site_oid_templates', $payload, 'template_id = :id', [':id' => $existingId]);
                $tid = $existingId;
            } else {
                $payload['created_at'] = date('Y-m-d H:i:s');
                try {
                    $payload['vendor'] = 'APC';
                    $payload['model'] = (string)($unit['model'] ?? 'Symmetra');
                    $tid = (int)Database::insert('snmp_site_oid_templates', $payload);
                } catch (Throwable $e) {
                    unset($payload['vendor'], $payload['model']);
                    $tid = (int)Database::insert('snmp_site_oid_templates', $payload);
                }
            }
            SnmpDiscover::assignTemplateToUps($upsId, $tid);
            App::json([
                'ok' => true,
                'template_id' => $tid,
                'message' => 'Default APC UPS OID template ready and assigned.',
            ]);
        }

        if ($action === 'discover') {
            $host = trim((string)($unit['primary_ip'] ?? ''));
            if ($host === '') {
                App::json(['error' => 'Set primary IP first'], 400);
            }
            $creds = [
                'host' => $host,
                'port' => (int)($unit['snmp_port'] ?? 161),
                'snmp_version' => (string)($unit['snmp_version'] ?? '3'),
                'security_name' => (string)($unit['snmp_security_name'] ?? ''),
                'auth_protocol' => (string)($unit['snmp_auth_protocol'] ?? 'SHA'),
                'auth_passphrase' => (string)(Crypto::decryptQuiet($unit['snmp_auth_passphrase'] ?? null) ?? ''),
                'priv_protocol' => (string)($unit['snmp_priv_protocol'] ?? 'AES'),
                'priv_passphrase' => (string)(Crypto::decryptQuiet($unit['snmp_priv_passphrase'] ?? null) ?? ''),
                'context' => (string)($unit['snmp_context'] ?? ''),
                'community' => (string)(Crypto::decryptQuiet($unit['snmp_community'] ?? null) ?? 'public'),
                'security_level' => (string)($unit['snmp_v3_sec_level'] ?? ''),
            ];
            $result = SnmpDiscover::discover($creds, [
                'family' => 'ups',
                'ruleset' => 'ups',
                'manufacturer' => (string)($unit['manufacturer'] ?? 'APC'),
                'model' => (string)($unit['model'] ?? 'Symmetra'),
            ]);
            // Ensure proposed map has standard UPS keys when present in candidates
            $proposed = $result['proposed_map'] ?? [];
            $defaults = ups_default_apc_oid_map();
            foreach ($defaults as $k => $oid) {
                if (empty($proposed[$k])) {
                    // Prefer default if discover found related OID score high
                    foreach ($result['candidates'] ?? [] as $c) {
                        $co = ltrim((string)($c['oid'] ?? ''), '.');
                        if ($co === ltrim($oid, '.') || str_starts_with($co, rtrim($oid, '.0'))) {
                            $proposed[$k] = $oid;
                            break;
                        }
                    }
                }
                if (empty($proposed[$k])) {
                    $proposed[$k] = $oid; // seed defaults for Symmetra template
                }
            }
            // Ensure serial + sysuptime + manufacture_date always present for inventory / display
            if (empty($proposed['serial_no']) && !empty($defaults['serial_no'])) {
                $proposed['serial_no'] = $defaults['serial_no'];
            }
            if (empty($proposed['sysuptime']) && !empty($defaults['sysuptime'])) {
                $proposed['sysuptime'] = $defaults['sysuptime'];
            }
            if (empty($proposed['manufacture_date']) && !empty($defaults['manufacture_date'])) {
                $proposed['manufacture_date'] = $defaults['manufacture_date'];
            }
            $result['proposed_map'] = $proposed;
            $result['default_ups_map'] = $defaults;
            App::json($result);
        }

        if ($action === 'save_template') {
            $map = $data['oid_map'] ?? null;
            if (!is_array($map) || !$map) {
                $map = ups_default_apc_oid_map();
            }
            // Merge essential keys into existing maps from older discovers
            foreach (ups_default_apc_oid_map() as $k => $oid) {
                if (empty($map[$k])) {
                    $map[$k] = $oid;
                }
            }
            $name = trim((string)($data['template_name'] ?? ''));
            if ($name === '') {
                $name = 'APC UPS — ' . (string)($unit['model'] ?? 'Symmetra');
            }
            $existingId = (int)($unit['snmp_site_template_id'] ?? 0);
            $payload = [
                'name' => mb_substr($name, 0, 150),
                'oid_map' => json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'notes' => 'UPS / PowerNet (enterprise 318.1.1.1)',
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($existingId > 0 && !empty($data['overwrite'])) {
                Database::update('snmp_site_oid_templates', $payload, 'template_id = :id', [':id' => $existingId]);
                $tid = $existingId;
            } else {
                // Prefer update existing template when re-discovering
                if ($existingId > 0) {
                    Database::update('snmp_site_oid_templates', $payload, 'template_id = :id', [':id' => $existingId]);
                    $tid = $existingId;
                } else {
                    $payload['created_at'] = date('Y-m-d H:i:s');
                    try {
                        $payload['vendor'] = 'APC';
                        $tid = (int)Database::insert('snmp_site_oid_templates', $payload);
                    } catch (Throwable $e) {
                        unset($payload['vendor']);
                        $tid = (int)Database::insert('snmp_site_oid_templates', $payload);
                    }
                }
            }
            Database::update('ups_units', [
                'snmp_site_template_id' => $tid,
                'snmp_enabled' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'ups_id = :id', [':id' => $upsId]);
            App::json(['ok' => true, 'template_id' => $tid, 'message' => 'UPS SNMP template saved and assigned.']);
        }

        if ($action === 'poll') {
            $result = SnmpPoller::pollUpsUnit($unit);
            $fresh = Database::fetchOne('SELECT * FROM ups_units WHERE ups_id = ?', [$upsId]);
            App::json([
                'ok' => true,
                'message' => $result['message'] ?? 'Poll complete',
                'result' => $result,
                'unit' => [
                    'last_output_status' => $fresh['last_output_status'] ?? null,
                    'last_load_pct' => $fresh['last_load_pct'] ?? null,
                    'last_battery_pct' => $fresh['last_battery_pct'] ?? null,
                    'last_runtime_min' => $fresh['last_runtime_min'] ?? null,
                    'snmp_last_poll_at' => $fresh['snmp_last_poll_at'] ?? null,
                    'health' => ups_health_status($fresh ?: $unit),
                ],
            ]);
        }

        App::json(['error' => 'Unknown action'], 400);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API snmp_ups: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
