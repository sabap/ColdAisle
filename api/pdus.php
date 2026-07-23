<?php
/**
 * ColdAisle - PDU + outlets API
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    api_require_permission('edit_power');
}

function pdu_null($v)
{
    if ($v === null || $v === '') {
        return null;
    }
    return $v;
}

/**
 * Normalize phase / voltage fields from request payload.
 * @return array{phases:int,phase_wiring:?string,input_voltage:?int,input_voltage_ln:?int,output_voltage:?int,output_voltage_ln:?int,rated_volts:?int,sync_zone_voltage:int}
 */
function pdu_electrical_from_payload(array $d, ?array $existing = null): array
{
    $phases = (int)($d['phases'] ?? ($existing['phases'] ?? 1));
    if (!in_array($phases, [1, 2, 3], true)) {
        $phases = 1;
    }

    $wiring = strtolower((string)($d['phase_wiring'] ?? ($existing['phase_wiring'] ?? '')));
    $allowed = [
        1 => ['single'],
        2 => ['split_phase', 'two_phase'],
        3 => ['wye', 'delta'],
    ];
    if (!in_array($wiring, $allowed[$phases], true)) {
        $wiring = match ($phases) {
            2 => 'split_phase',
            3 => 'wye',
            default => 'single',
        };
    }

    // Prefer explicit input/output; fall back to legacy rated_volts
    $inLl = pdu_null($d['input_voltage'] ?? null);
    if ($inLl === null && array_key_exists('rated_volts', $d)) {
        $inLl = pdu_null($d['rated_volts']);
    }
    if ($inLl === null && $existing) {
        $inLl = $existing['input_voltage'] ?? $existing['rated_volts'] ?? null;
    }

    $inLn = pdu_null($d['input_voltage_ln'] ?? ($existing['input_voltage_ln'] ?? null));
    $outLl = pdu_null($d['output_voltage'] ?? ($existing['output_voltage'] ?? null));
    $outLn = pdu_null($d['output_voltage_ln'] ?? ($existing['output_voltage_ln'] ?? null));

    // Single-phase: L-N only — clear L-L secondary fields that don't apply
    if ($phases === 1) {
        $inLn = null;
        $outLn = null;
    }
    // Delta: typically no neutral L-N on input
    if ($phases === 3 && $wiring === 'delta') {
        // keep ln fields if provided (corner-grounded edge cases) but don't force
    }
    // Split-phase: if L-L set and L-N empty, derive L-N as half
    if ($phases === 2 && $wiring === 'split_phase' && $inLl !== null && $inLn === null) {
        $inLn = (int)round((float)$inLl / 2);
    }
    // 3-phase wye: if L-L set and L-N empty, derive L-N ≈ L-L / √3
    if ($phases === 3 && $wiring === 'wye' && $inLl !== null && $inLn === null) {
        $inLn = (int)round((float)$inLl / 1.732);
    }

    $sync = array_key_exists('sync_zone_voltage', $d)
        ? (!empty($d['sync_zone_voltage']) ? 1 : 0)
        : (int)($existing['sync_zone_voltage'] ?? 1);

    $inLlInt = $inLl !== null ? (int)$inLl : null;
    $outLlInt = $outLl !== null ? (int)$outLl : null;

    return [
        'phases' => $phases,
        'phase_wiring' => $wiring,
        'input_voltage' => $inLlInt,
        'input_voltage_ln' => $inLn !== null ? (int)$inLn : null,
        'output_voltage' => $outLlInt,
        'output_voltage_ln' => $outLn !== null ? (int)$outLn : null,
        // Keep rated_volts in sync with primary input for older UI/reports
        'rated_volts' => $inLlInt ?? ($outLlInt ?? null),
        'sync_zone_voltage' => $sync,
    ];
}

/**
 * When enabled, push row/room PDU distribution voltage onto the linked power zone.
 * Uses input L-L (or L-N for single-phase) as the zone bus voltage.
 */
function pdu_sync_zone_voltage(?int $zoneId, array $electrical, string $scope): void
{
    if (!$zoneId || empty($electrical['sync_zone_voltage'])) {
        return;
    }
    // Zone voltage tracks row/room distribution feeds primarily
    if (!in_array($scope, ['row', 'room'], true)) {
        return;
    }
    $volts = $electrical['input_voltage']
        ?? $electrical['input_voltage_ln']
        ?? $electrical['rated_volts']
        ?? null;
    if ($volts === null) {
        return;
    }
    Database::update(
        'power_zones',
        ['voltage' => (int)$volts],
        'zone_id = :id',
        [':id' => $zoneId]
    );
}

function pdu_fetch(int $id): ?array
{
    $pdu = Database::fetchOne(
        'SELECT p.*, c.name AS cabinet_name, z.name AS zone_name, z.voltage AS zone_voltage,
                r.name AS row_name
         FROM pdus p
         LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
         LEFT JOIN power_zones z ON z.zone_id = p.zone_id
         LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
         WHERE p.pdu_id = ?',
        [$id]
    );
    if (!$pdu) {
        return null;
    }
    $pdu['outlets'] = Database::fetchAll(
        'SELECT o.*,
                d.label AS device_label,
                ps.name AS power_supply_name
         FROM pdu_outlets o
         LEFT JOIN devices d ON d.device_id = o.connected_device_id
         LEFT JOIN device_power_supplies ps ON ps.power_supply_id = o.device_power_supply_id
         WHERE o.pdu_id = ?
         ORDER BY o.outlet_number',
        [$id]
    );
    return $pdu;
}

function pdu_sync_outlets(int $pduId, int $numOutlets, string $defaultType = 'C13'): void
{
    $numOutlets = max(0, min(128, $numOutlets));
    $existing = Database::fetchAll(
        'SELECT outlet_id, outlet_number FROM pdu_outlets WHERE pdu_id = ?',
        [$pduId]
    );
    $byNum = [];
    foreach ($existing as $o) {
        $byNum[(int)$o['outlet_number']] = $o;
    }
    for ($i = 1; $i <= $numOutlets; $i++) {
        if (!isset($byNum[$i])) {
            Database::insert('pdu_outlets', [
                'pdu_id' => $pduId,
                'outlet_number' => $i,
                'label' => 'Outlet ' . $i,
                'outlet_type' => $defaultType,
            ]);
        }
    }
    // Remove extras above num (only if unused)
    foreach ($byNum as $num => $o) {
        if ($num > $numOutlets) {
            Database::delete('pdu_outlets', 'outlet_id = ?', [(int)$o['outlet_id']]);
        }
    }
}

try {
    // List by cabinet or all
    if ($method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $cabinetId = (int)($_GET['cabinet_id'] ?? 0);
        if ($id) {
            $pdu = pdu_fetch($id);
            if (!$pdu) {
                App::json(['error' => 'PDU not found'], 404);
            }
            // devices in same cabinet for outlet mapping UI
            $devices = [];
            if (!empty($pdu['cabinet_id'])) {
                $devices = Database::fetchAll(
                    'SELECT d.device_id, d.label, d.device_type,
                            (SELECT COUNT(*) FROM device_power_supplies ps WHERE ps.device_id = d.device_id) AS psu_count
                     FROM devices d
                     WHERE d.cabinet_id = ? AND d.is_active = 1
                     ORDER BY d.label',
                    [(int)$pdu['cabinet_id']]
                );
                foreach ($devices as &$dev) {
                    $dev['power_supplies'] = Database::fetchAll(
                        'SELECT * FROM device_power_supplies WHERE device_id = ? ORDER BY sort_order, power_supply_id',
                        [(int)$dev['device_id']]
                    );
                    $dev['power_ports'] = Database::fetchAll(
                        "SELECT port_id, port_number, label, media_type FROM device_ports
                         WHERE device_id = ? AND port_type = 'power' ORDER BY port_number",
                        [(int)$dev['device_id']]
                    );
                }
                unset($dev);
            }
            App::json(['pdu' => $pdu, 'cabinet_devices' => $devices]);
        }

        $sql = 'SELECT p.*, c.name AS cabinet_name, z.name AS zone_name
                FROM pdus p
                LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
                LEFT JOIN power_zones z ON z.zone_id = p.zone_id
                WHERE p.is_active = 1';
        $params = [];
        if ($cabinetId) {
            $sql .= ' AND p.cabinet_id = ?';
            $params[] = $cabinetId;
        }
        $sql .= ' ORDER BY p.name';
        App::json(['pdus' => Database::fetchAll($sql, $params)]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $d = api_read_json();
        $mount = strtolower((string)($d['mount_style'] ?? 'vertical_rear'));
        if (!in_array($mount, ['vertical_rear', 'u_mounted'], true)) {
            $mount = 'vertical_rear';
        }
        $numOutlets = max(1, min(128, (int)($d['num_outlets'] ?? 24)));
        $scope = strtolower((string)($d['pdu_scope'] ?? 'rack'));
        if (!in_array($scope, ['rack', 'row', 'room'], true)) {
            $scope = 'rack';
        }
        // Row PDUs default to 3-phase if not specified
        if ($scope === 'row' && !isset($d['phases'])) {
            $d['phases'] = 3;
            $d['phase_wiring'] = $d['phase_wiring'] ?? 'wye';
        }
        $elec = pdu_electrical_from_payload($d);
        $row = array_merge([
            'name' => trim((string)($d['name'] ?? 'PDU')),
            'cabinet_id' => pdu_null($d['cabinet_id'] ?? null) !== null ? (int)$d['cabinet_id'] : null,
            'row_id' => pdu_null($d['row_id'] ?? null) !== null ? (int)$d['row_id'] : null,
            'zone_id' => pdu_null($d['zone_id'] ?? null) !== null ? (int)$d['zone_id'] : null,
            'pdu_scope' => $scope,
            'mount_style' => $mount,
            'position_u' => $mount === 'u_mounted' && pdu_null($d['position_u'] ?? null) !== null
                ? (int)$d['position_u'] : null,
            'u_height' => $mount === 'u_mounted'
                ? max(1, (int)($d['u_height'] ?? 1)) : null,
            'manufacturer' => pdu_null($d['manufacturer'] ?? null),
            'model' => pdu_null($d['model'] ?? null),
            'ip_address' => pdu_null($d['ip_address'] ?? null),
            'output_mode' => in_array(strtolower((string)($d['output_mode'] ?? 'outlets')), ['outlets', 'breakers'], true)
                ? strtolower((string)($d['output_mode'] ?? 'outlets')) : 'outlets',
            'num_outlets' => $numOutlets,
            'num_breaker_slots' => isset($d['num_breaker_slots']) && $d['num_breaker_slots'] !== '' && $d['num_breaker_slots'] !== null
                ? max(1, min(128, (int)$d['num_breaker_slots'])) : null,
            'rated_amps' => pdu_null($d['rated_amps'] ?? null) !== null ? (float)$d['rated_amps'] : null,
            'input_type' => pdu_null($d['input_type'] ?? null),
            'snmp_enabled' => !empty($d['snmp_enabled']) ? 1 : 0,
            'snmp_version' => pdu_null($d['snmp_version'] ?? '2c') ?? '2c',
            'snmp_port' => (int)($d['snmp_port'] ?? 161),
            'snmp_community' => pdu_null($d['snmp_community'] ?? null),
            'snmp_security_name' => pdu_null($d['snmp_security_name'] ?? null),
            'snmp_auth_protocol' => pdu_null($d['snmp_auth_protocol'] ?? null),
            'snmp_auth_passphrase' => pdu_null($d['snmp_auth_passphrase'] ?? null),
            'snmp_priv_protocol' => pdu_null($d['snmp_priv_protocol'] ?? null),
            'snmp_priv_passphrase' => pdu_null($d['snmp_priv_passphrase'] ?? null),
            'snmp_context' => pdu_null($d['snmp_context'] ?? null),
            'snmp_v3_sec_level' => pdu_null($d['snmp_v3_sec_level'] ?? null),
            'notes' => pdu_null($d['notes'] ?? null),
            'is_active' => 1,
        ], $elec);
        if ($row['name'] === '') {
            App::json(['error' => 'Name is required'], 400);
        }
        $id = Database::insert('pdus', $row);
        if (!$id) {
            $found = Database::fetchOne(
                'SELECT TOP 1 pdu_id FROM pdus WHERE name = ? ORDER BY pdu_id DESC',
                [$row['name']]
            );
            $id = $found ? (int)$found['pdu_id'] : 0;
        }
        pdu_sync_outlets($id, $numOutlets, (string)($d['outlet_type'] ?? 'C13'));
        pdu_sync_zone_voltage($row['zone_id'], $elec, $scope);
        AuditService::log((int)$user['user_id'], $user['username'], 'create', 'pdu', $id, ['name' => $row['name']]);
        App::json(['pdu' => pdu_fetch($id)], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        api_require_csrf();
        $d = api_read_json();
        $id = (int)($d['pdu_id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            App::json(['error' => 'pdu_id required'], 400);
        }

        // Outlet batch update (two-way link with device_power_supplies)
        if (!empty($d['outlets']) && is_array($d['outlets'])) {
            foreach ($d['outlets'] as $o) {
                $oid = (int)($o['outlet_id'] ?? 0);
                if (!$oid) {
                    continue;
                }
                $existingOut = Database::fetchOne(
                    'SELECT * FROM pdu_outlets WHERE outlet_id = ? AND pdu_id = ?',
                    [$oid, $id]
                );
                if (!$existingOut) {
                    continue;
                }
                $of = [];
                foreach (['label', 'outlet_type', 'rated_amps', 'bank', 'notes'] as $k) {
                    if (array_key_exists($k, $o)) {
                        $of[$k] = $o[$k] === '' ? null : $o[$k];
                    }
                }
                if (array_key_exists('connected_device_id', $o)) {
                    $of['connected_device_id'] = $o['connected_device_id'] !== null && $o['connected_device_id'] !== ''
                        ? (int)$o['connected_device_id'] : null;
                }
                if (array_key_exists('connected_power_port_id', $o)) {
                    $of['connected_power_port_id'] = $o['connected_power_port_id'] !== null && $o['connected_power_port_id'] !== ''
                        ? (int)$o['connected_power_port_id'] : null;
                }
                if (array_key_exists('device_power_supply_id', $o)) {
                    $of['device_power_supply_id'] = $o['device_power_supply_id'] !== null && $o['device_power_supply_id'] !== ''
                        ? (int)$o['device_power_supply_id'] : null;
                }
                // Clear prior PSU reverse-link if PSU changed
                $oldPsu = $existingOut['device_power_supply_id'] ?? null;
                $newPsu = array_key_exists('device_power_supply_id', $of)
                    ? $of['device_power_supply_id']
                    : $oldPsu;
                if ($of) {
                    Database::update('pdu_outlets', $of, 'outlet_id = :id AND pdu_id = :p', [
                        ':id' => $oid,
                        ':p' => $id,
                    ]);
                }
                if (!empty($oldPsu) && (int)$oldPsu !== (int)($newPsu ?? 0)) {
                    Database::update('device_power_supplies', [
                        'pdu_id' => null,
                        'pdu_outlet_id' => null,
                    ], 'power_supply_id = :id', [':id' => (int)$oldPsu]);
                }
                if (!empty($newPsu)) {
                    $psuRow = Database::fetchOne(
                        'SELECT * FROM device_power_supplies WHERE power_supply_id = ?',
                        [(int)$newPsu]
                    );
                    if ($psuRow) {
                        Database::update('device_power_supplies', [
                            'pdu_id' => $id,
                            'pdu_outlet_id' => $oid,
                        ], 'power_supply_id = :id', [':id' => (int)$newPsu]);
                        // Keep device on outlet in sync with PSU owner
                        Database::update('pdu_outlets', [
                            'connected_device_id' => (int)$psuRow['device_id'],
                            'device_power_supply_id' => (int)$newPsu,
                        ], 'outlet_id = :id', [':id' => $oid]);
                    }
                }
            }
        }

        $existingPdu = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ?', [$id]);
        if (!$existingPdu) {
            App::json(['error' => 'PDU not found'], 404);
        }

        $fields = [];
        $map = [
            'name', 'cabinet_id', 'row_id', 'zone_id', 'pdu_scope', 'mount_style', 'position_u', 'u_height',
            'manufacturer', 'model', 'ip_address', 'output_mode', 'num_outlets', 'num_breaker_slots', 'rated_amps',
            'input_type', 'snmp_enabled', 'snmp_version', 'snmp_port', 'snmp_community',
            'snmp_security_name', 'snmp_auth_protocol', 'snmp_auth_passphrase',
            'snmp_priv_protocol', 'snmp_priv_passphrase', 'snmp_context', 'snmp_v3_sec_level', 'notes',
        ];
        foreach ($map as $k) {
            if (array_key_exists($k, $d)) {
                $fields[$k] = $d[$k] === '' ? null : $d[$k];
            }
        }
        // Electrical fields (phases / voltages)
        $elecKeys = ['phases', 'phase_wiring', 'input_voltage', 'input_voltage_ln',
            'output_voltage', 'output_voltage_ln', 'rated_volts', 'sync_zone_voltage'];
        $elecTouched = false;
        foreach ($elecKeys as $ek) {
            if (array_key_exists($ek, $d)) {
                $elecTouched = true;
                break;
            }
        }
        if ($elecTouched) {
            $elec = pdu_electrical_from_payload($d, $existingPdu);
            $fields = array_merge($fields, $elec);
        }
        if (isset($fields['snmp_enabled'])) {
            $fields['snmp_enabled'] = $fields['snmp_enabled'] ? 1 : 0;
        }
        if (isset($fields['mount_style'])) {
            $ms = strtolower((string)$fields['mount_style']);
            $fields['mount_style'] = in_array($ms, ['vertical_rear', 'u_mounted'], true) ? $ms : 'vertical_rear';
            if ($fields['mount_style'] === 'vertical_rear') {
                $fields['position_u'] = null;
                $fields['u_height'] = null;
            }
        }
        if (isset($fields['pdu_scope'])) {
            $sc = strtolower((string)$fields['pdu_scope']);
            $fields['pdu_scope'] = in_array($sc, ['rack', 'row', 'room'], true) ? $sc : 'rack';
        }
        if ($fields) {
            Database::update('pdus', $fields, 'pdu_id = :id', [':id' => $id]);
        }
        if (isset($fields['num_outlets'])) {
            pdu_sync_outlets($id, (int)$fields['num_outlets'], (string)($d['outlet_type'] ?? 'C13'));
        }

        // Zone voltage sync after update
        $merged = array_merge($existingPdu, $fields);
        $scope = (string)($merged['pdu_scope'] ?? 'rack');
        $zoneId = !empty($merged['zone_id']) ? (int)$merged['zone_id'] : null;
        $elecForSync = [
            'input_voltage' => $merged['input_voltage'] ?? null,
            'input_voltage_ln' => $merged['input_voltage_ln'] ?? null,
            'rated_volts' => $merged['rated_volts'] ?? null,
            'sync_zone_voltage' => $merged['sync_zone_voltage'] ?? 1,
        ];
        pdu_sync_zone_voltage($zoneId, $elecForSync, $scope);

        AuditService::log((int)$user['user_id'], $user['username'], 'update', 'pdu', $id, $fields);
        App::json(['pdu' => pdu_fetch($id)]);
    }

    if ($method === 'DELETE') {
        api_require_csrf();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            App::json(['error' => 'id required'], 400);
        }
        Database::update('pdus', ['is_active' => 0], 'pdu_id = :id', [':id' => $id]);
        AuditService::log((int)$user['user_id'], $user['username'], 'delete', 'pdu', $id);
        App::json(['ok' => true]);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API pdus: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
