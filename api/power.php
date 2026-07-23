<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();
$entity = $_GET['entity'] ?? 'zones';

try {
    if ($entity === 'zones') {
        if ($method === 'GET') {
            App::json(['zones' => Database::fetchAll(
                'SELECT z.*, dc.name AS dc_name FROM power_zones z
                 INNER JOIN datacenters dc ON dc.datacenter_id = z.datacenter_id
                 ORDER BY z.name'
            )]);
        }
        if ($method === 'POST') {
            api_require_csrf();
            $d = api_read_json();
            $id = Database::insert('power_zones', [
                'datacenter_id' => (int)$d['datacenter_id'],
                'name' => trim($d['name'] ?? 'Zone'),
                'description' => $d['description'] ?? null,
                'feed_type' => $d['feed_type'] ?? 'A',
                'voltage' => (int)($d['voltage'] ?? 208),
                'max_amps' => $d['max_amps'] ?? null,
                'max_kw' => $d['max_kw'] ?? null,
                'color_hex' => $d['color_hex'] ?? '#ef4444',
                'notes' => $d['notes'] ?? null,
            ]);
            AuditService::log((int)$user['user_id'], $user['username'], 'create', 'power_zone', $id);
            App::json(['zone' => Database::fetchOne('SELECT * FROM power_zones WHERE zone_id = ?', [$id])], 201);
        }
    }

    if ($entity === 'pdus') {
        if ($method === 'GET') {
            App::json(['pdus' => Database::fetchAll(
                'SELECT p.*, c.name AS cabinet_name, z.name AS zone_name
                 FROM pdus p
                 LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
                 LEFT JOIN power_zones z ON z.zone_id = p.zone_id
                 WHERE p.is_active = 1
                 ORDER BY p.name'
            )]);
        }
        if ($method === 'POST') {
            api_require_csrf();
            $d = api_read_json();
            $pduRow = Crypto::sealFields([
                'device_id' => $d['device_id'] ?? null,
                'cabinet_id' => $d['cabinet_id'] ?? null,
                'row_id' => $d['row_id'] ?? null,
                'zone_id' => $d['zone_id'] ?? null,
                'circuit_id' => $d['circuit_id'] ?? null,
                'name' => trim($d['name'] ?? 'PDU'),
                'pdu_scope' => $d['pdu_scope'] ?? 'rack',
                'manufacturer' => $d['manufacturer'] ?? null,
                'model' => $d['model'] ?? null,
                'ip_address' => $d['ip_address'] ?? null,
                'num_outlets' => (int)($d['num_outlets'] ?? 24),
                'rated_amps' => $d['rated_amps'] ?? null,
                'rated_volts' => $d['rated_volts'] ?? null,
                'input_type' => $d['input_type'] ?? null,
                'snmp_enabled' => !empty($d['snmp_enabled']) ? 1 : 0,
                'snmp_version' => $d['snmp_version'] ?? '3',
                'snmp_port' => (int)($d['snmp_port'] ?? 161),
                'snmp_security_name' => $d['snmp_security_name'] ?? null,
                'snmp_auth_protocol' => $d['snmp_auth_protocol'] ?? null,
                'snmp_auth_passphrase' => $d['snmp_auth_passphrase'] ?? null,
                'snmp_priv_protocol' => $d['snmp_priv_protocol'] ?? null,
                'snmp_priv_passphrase' => $d['snmp_priv_passphrase'] ?? null,
                'snmp_context' => $d['snmp_context'] ?? null,
                'is_active' => 1,
            ], ['snmp_auth_passphrase', 'snmp_priv_passphrase', 'snmp_community']);
            $id = Database::insert('pdus', $pduRow);
            $outlets = (int)($d['num_outlets'] ?? 24);
            for ($i = 1; $i <= $outlets; $i++) {
                Database::insert('pdu_outlets', [
                    'pdu_id' => $id,
                    'outlet_number' => $i,
                    'label' => 'Outlet ' . $i,
                    'outlet_type' => $d['outlet_type'] ?? 'C13',
                ]);
            }
            AuditService::log((int)$user['user_id'], $user['username'], 'create', 'pdu', $id);
            App::json(['pdu' => Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ?', [$id])], 201);
        }
        if ($method === 'PUT') {
            api_require_csrf();
            $d = api_read_json();
            $id = (int)($d['pdu_id'] ?? 0);
            $fields = [];
            foreach ([
                'name', 'cabinet_id', 'row_id', 'zone_id', 'circuit_id', 'pdu_scope',
                'manufacturer', 'model', 'ip_address', 'num_outlets', 'rated_amps', 'rated_volts',
                'input_type', 'snmp_enabled', 'snmp_version', 'snmp_port', 'snmp_security_name',
                'snmp_auth_protocol', 'snmp_auth_passphrase', 'snmp_priv_protocol',
                'snmp_priv_passphrase', 'snmp_context', 'notes', 'snmp_community',
            ] as $k) {
                if (array_key_exists($k, $d)) {
                    $fields[$k] = $d[$k];
                }
            }
            if (isset($fields['snmp_enabled'])) {
                $fields['snmp_enabled'] = $fields['snmp_enabled'] ? 1 : 0;
            }
            foreach (['snmp_community', 'snmp_auth_passphrase', 'snmp_priv_passphrase'] as $sk) {
                if (array_key_exists($sk, $fields) && ($fields[$sk] === null || $fields[$sk] === '')) {
                    unset($fields[$sk]); // keep existing
                }
            }
            $fields = Crypto::sealFields($fields, [
                'snmp_community', 'snmp_auth_passphrase', 'snmp_priv_passphrase',
            ]);
            if ($fields) {
                Database::update('pdus', $fields, 'pdu_id = :id', [':id' => $id]);
            }
            App::json(['pdu' => Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ?', [$id])]);
        }
    }

    if ($entity === 'panels') {
        if ($method === 'GET') {
            App::json(['panels' => Database::fetchAll(
                'SELECT p.*, z.name AS zone_name FROM power_panels p
                 LEFT JOIN power_zones z ON z.zone_id = p.zone_id ORDER BY p.name'
            )]);
        }
        if ($method === 'POST') {
            api_require_csrf();
            $d = api_read_json();
            $id = Database::insert('power_panels', [
                'zone_id' => $d['zone_id'] ?? null,
                'room_id' => $d['room_id'] ?? null,
                'name' => trim($d['name'] ?? 'Panel'),
                'panel_type' => $d['panel_type'] ?? 'sub',
                'voltage' => $d['voltage'] ?? 208,
                'phases' => (int)($d['phases'] ?? 3),
                'main_breaker_amps' => $d['main_breaker_amps'] ?? null,
                'num_poles' => $d['num_poles'] ?? null,
                'location_notes' => $d['location_notes'] ?? null,
                'notes' => $d['notes'] ?? null,
            ]);
            App::json(['panel' => Database::fetchOne('SELECT * FROM power_panels WHERE panel_id = ?', [$id])], 201);
        }
    }

    App::json(['error' => 'Unknown entity or method'], 400);
} catch (Throwable $e) {
    App::json(['error' => $e->getMessage()], 500);
}
