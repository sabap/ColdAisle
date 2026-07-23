<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

try {
    // Update room floor dimensions / name
    if ($method === 'PUT' || $method === 'PATCH' || ($method === 'POST' && (($_GET['action'] ?? '') === 'update_room'))) {
        api_require_permission('edit_infrastructure');
        api_require_csrf();
        $data = api_read_json();
        $roomId = (int)($data['room_id'] ?? $_GET['room_id'] ?? 0);
        if (!$roomId) {
            App::json(['error' => 'room_id required'], 400);
        }
        $existing = Database::fetchOne('SELECT * FROM rooms WHERE room_id = ?', [$roomId]);
        if (!$existing) {
            App::json(['error' => 'Room not found'], 404);
        }

        $fields = [];
        if (array_key_exists('name', $data) && trim((string)$data['name']) !== '') {
            $fields['name'] = trim((string)$data['name']);
        }
        if (array_key_exists('code', $data)) {
            $fields['code'] = $data['code'] !== null && $data['code'] !== '' ? (string)$data['code'] : null;
        }
        if (array_key_exists('width_m', $data)) {
            $w = round((float)$data['width_m'], 2);
            if ($w <= 0 || $w > 10000) {
                App::json(['error' => 'width_m must be between 0 and 10000 meters'], 400);
            }
            $fields['width_m'] = $w;
        }
        if (array_key_exists('depth_m', $data)) {
            $d = round((float)$data['depth_m'], 2);
            if ($d <= 0 || $d > 10000) {
                App::json(['error' => 'depth_m must be between 0 and 10000 meters'], 400);
            }
            $fields['depth_m'] = $d;
        }
        if (array_key_exists('floor_level', $data)) {
            $fields['floor_level'] = $data['floor_level'] !== null && $data['floor_level'] !== ''
                ? (string)$data['floor_level'] : null;
        }
        if (array_key_exists('notes', $data)) {
            $fields['notes'] = $data['notes'];
        }

        // Compass: which plan edge is geographic North (stored on parent data center)
        if (array_key_exists('north_edge', $data)) {
            $edge = strtolower(trim((string)$data['north_edge']));
            if (!in_array($edge, ['top', 'right', 'bottom', 'left'], true)) {
                App::json(['error' => 'north_edge must be top, right, bottom, or left'], 400);
            }
            $dcId = (int)$existing['datacenter_id'];
            Database::update('datacenters', ['north_edge' => $edge], 'datacenter_id = :id', [':id' => $dcId]);
            AuditService::log((int)$user['user_id'], $user['username'], 'update', 'datacenter', $dcId, [
                'north_edge' => $edge,
            ]);
        }

        if ($fields) {
            Database::update('rooms', $fields, 'room_id = :id', [':id' => $roomId]);
            AuditService::log((int)$user['user_id'], $user['username'], 'update', 'room', $roomId, $fields);
        } elseif (!array_key_exists('north_edge', $data)) {
            App::json(['error' => 'No fields to update'], 400);
        }

        $room = floorplan_fetch_room($roomId);
        App::json(['room' => $room]);
    }

    // Persist display / planner preferences
    if ($method === 'POST' && (($_GET['action'] ?? '') === 'set_units')) {
        api_require_permission('edit_infrastructure');
        api_require_csrf();
        $data = api_read_json();
        $units = strtolower(trim((string)($data['units'] ?? 'metric')));
        if (!in_array($units, ['metric', 'imperial'], true)) {
            App::json(['error' => 'units must be metric or imperial'], 400);
        }
        SettingsService::set('length_units', $units, 'display');
        App::json(['units' => $units]);
    }

    if ($method === 'POST' && (($_GET['action'] ?? '') === 'set_planner_prefs')) {
        api_require_permission('edit_infrastructure');
        api_require_csrf();
        $data = api_read_json();
        if (array_key_exists('show_grid', $data)) {
            SettingsService::set('floorplan_show_grid', !empty($data['show_grid']) ? '1' : '0', 'display');
        }
        if (array_key_exists('snap_to_grid', $data)) {
            SettingsService::set('floorplan_snap', !empty($data['snap_to_grid']) ? '1' : '0', 'display');
        }
        if (array_key_exists('grid_ft', $data)) {
            $g = (float)$data['grid_ft'];
            if ($g <= 0 || $g > 50) {
                App::json(['error' => 'grid_ft must be between 0 and 50'], 400);
            }
            SettingsService::set('floorplan_grid_ft', (string)$g, 'display');
        }
        App::json([
            'show_grid' => SettingsService::get('floorplan_show_grid', '1') === '1',
            'snap_to_grid' => SettingsService::get('floorplan_snap', '1') === '1',
            'grid_ft' => (float)SettingsService::get('floorplan_grid_ft', '1'),
        ]);
    }

    // --- Row / room PDU floor placement ---
    $fpAction = (string)($_GET['action'] ?? '');
    if ($method === 'POST' && in_array($fpAction, [
        'place_pdu', 'create_floor_pdu', 'update_floor_pdu', 'unplace_pdu',
    ], true)) {
        api_require_any_permission(['edit_infrastructure', 'edit_power']);
        api_require_csrf();
        $data = api_read_json();

        if ($fpAction === 'create_floor_pdu') {
            $roomId = (int)($data['room_id'] ?? 0);
            if (!$roomId) {
                App::json(['error' => 'room_id required'], 400);
            }
            $room = floorplan_fetch_room($roomId);
            if (!$room) {
                App::json(['error' => 'Room not found'], 404);
            }
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                $name = 'Row PDU';
            }
            $scope = strtolower((string)($data['pdu_scope'] ?? 'row'));
            if (!in_array($scope, ['row', 'room'], true)) {
                $scope = 'row';
            }
            $facing = floorplan_normalize_facing($data['front_facing'] ?? 'north');
            $geom = floorplan_pdu_geometry_from_data($data, $facing);
            $zoneId = isset($data['zone_id']) && $data['zone_id'] !== '' && $data['zone_id'] !== null
                ? (int)$data['zone_id'] : null;
            $rowId = isset($data['row_id']) && $data['row_id'] !== '' && $data['row_id'] !== null
                ? (int)$data['row_id'] : null;
            $outputMode = strtolower((string)($data['output_mode'] ?? 'breakers'));
            if (!in_array($outputMode, ['outlets', 'breakers'], true)) {
                $outputMode = 'breakers';
            }
            $row = array_merge([
                'name' => $name,
                'pdu_scope' => $scope,
                'room_id' => $roomId,
                'row_id' => $rowId,
                'zone_id' => $zoneId,
                'cabinet_id' => null,
                'output_mode' => $outputMode,
                'num_outlets' => $outputMode === 'outlets' ? max(1, (int)($data['num_outlets'] ?? 24)) : 0,
                'num_breaker_slots' => $outputMode === 'breakers'
                    ? max(1, min(128, (int)($data['num_breaker_slots'] ?? 42))) : null,
                'breaker_layout' => $outputMode === 'breakers'
                    ? (string)($data['breaker_layout'] ?? 'odd_right_even_left') : null,
                'breaker_columns' => $outputMode === 'breakers' ? 2 : null,
                'phases' => max(1, min(3, (int)($data['phases'] ?? 3))),
                'phase_wiring' => (string)($data['phase_wiring'] ?? 'wye'),
                'input_voltage' => isset($data['input_voltage']) && $data['input_voltage'] !== ''
                    ? (int)$data['input_voltage'] : 208,
                'rated_amps' => isset($data['rated_amps']) && $data['rated_amps'] !== ''
                    ? (float)$data['rated_amps'] : 30.0,
                'manufacturer' => trim((string)($data['manufacturer'] ?? '')) !== ''
                    ? trim((string)$data['manufacturer']) : null,
                'model' => trim((string)($data['model'] ?? '')) !== ''
                    ? trim((string)$data['model']) : null,
                'is_active' => 1,
                'mount_style' => 'vertical_rear',
            ], $geom);
            $pid = Database::insert('pdus', $row);
            AuditService::log((int)$user['user_id'], $user['username'], 'create', 'pdu', (int)$pid, [
                'name' => $name,
                'floor_placed' => true,
                'room_id' => $roomId,
            ]);
            App::json(['pdu' => floorplan_fetch_pdu((int)$pid)], 201);
        }

        if ($fpAction === 'place_pdu') {
            $pid = (int)($data['pdu_id'] ?? 0);
            $roomId = (int)($data['room_id'] ?? 0);
            if ($pid <= 0 || $roomId <= 0) {
                App::json(['error' => 'pdu_id and room_id required'], 400);
            }
            $pdu = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$pid]);
            if (!$pdu) {
                App::json(['error' => 'PDU not found'], 404);
            }
            $scope = strtolower((string)($pdu['pdu_scope'] ?? 'rack'));
            if (!in_array($scope, ['row', 'room'], true)) {
                App::json(['error' => 'Only row/room PDUs can be placed on the floor plan'], 400);
            }
            if (!floorplan_fetch_room($roomId)) {
                App::json(['error' => 'Room not found'], 404);
            }
            $facing = floorplan_normalize_facing($data['front_facing'] ?? ($pdu['front_facing'] ?? 'north'));
            $geom = floorplan_pdu_geometry_from_data(array_merge($pdu, $data), $facing);
            $fields = array_merge(['room_id' => $roomId], $geom);
            if (array_key_exists('zone_id', $data)) {
                $fields['zone_id'] = $data['zone_id'] !== '' && $data['zone_id'] !== null
                    ? (int)$data['zone_id'] : null;
            }
            if (array_key_exists('row_id', $data)) {
                $fields['row_id'] = $data['row_id'] !== '' && $data['row_id'] !== null
                    ? (int)$data['row_id'] : null;
            }
            if (array_key_exists('name', $data) && trim((string)$data['name']) !== '') {
                $fields['name'] = trim((string)$data['name']);
            }
            Database::update('pdus', $fields, 'pdu_id = :id', [':id' => $pid]);
            AuditService::log((int)$user['user_id'], $user['username'], 'update', 'pdu', $pid, [
                'floor_place' => true,
                'room_id' => $roomId,
            ]);
            App::json(['pdu' => floorplan_fetch_pdu($pid)]);
        }

        if ($fpAction === 'update_floor_pdu') {
            $pid = (int)($data['pdu_id'] ?? 0);
            if ($pid <= 0) {
                App::json(['error' => 'pdu_id required'], 400);
            }
            $pdu = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$pid]);
            if (!$pdu) {
                App::json(['error' => 'PDU not found'], 404);
            }
            $fields = [];
            if (array_key_exists('name', $data) && trim((string)$data['name']) !== '') {
                $fields['name'] = trim((string)$data['name']);
            }
            if (array_key_exists('zone_id', $data)) {
                $fields['zone_id'] = $data['zone_id'] !== '' && $data['zone_id'] !== null
                    ? (int)$data['zone_id'] : null;
            }
            if (array_key_exists('row_id', $data)) {
                $fields['row_id'] = $data['row_id'] !== '' && $data['row_id'] !== null
                    ? (int)$data['row_id'] : null;
            }
            $hasGeom = false;
            foreach (['pos_x', 'pos_y', 'width_mm', 'depth_mm', 'height_mm', 'rotation_deg', 'front_facing', 'color_hex'] as $k) {
                if (array_key_exists($k, $data)) {
                    $hasGeom = true;
                    break;
                }
            }
            if ($hasGeom) {
                $facing = floorplan_normalize_facing($data['front_facing'] ?? ($pdu['front_facing'] ?? 'north'));
                $merged = array_merge($pdu, $data);
                $fields = array_merge($fields, floorplan_pdu_geometry_from_data($merged, $facing));
            }
            if (!$fields) {
                App::json(['error' => 'No fields to update'], 400);
            }
            Database::update('pdus', $fields, 'pdu_id = :id', [':id' => $pid]);
            App::json(['pdu' => floorplan_fetch_pdu($pid)]);
        }

        if ($fpAction === 'unplace_pdu') {
            $pid = (int)($data['pdu_id'] ?? 0);
            if ($pid <= 0) {
                App::json(['error' => 'pdu_id required'], 400);
            }
            $pdu = Database::fetchOne('SELECT pdu_id FROM pdus WHERE pdu_id = ? AND is_active = 1', [$pid]);
            if (!$pdu) {
                App::json(['error' => 'PDU not found'], 404);
            }
            Database::update('pdus', [
                'room_id' => null,
                'pos_x' => null,
                'pos_y' => null,
            ], 'pdu_id = :id', [':id' => $pid]);
            AuditService::log((int)$user['user_id'], $user['username'], 'update', 'pdu', $pid, [
                'floor_unplace' => true,
            ]);
            App::json(['ok' => true, 'pdu_id' => $pid]);
        }
    }

    // Default GET: floor plan payload
    $roomId = (int)($_GET['room_id'] ?? 0);
    if (!$roomId) {
        App::json(['error' => 'room_id required'], 400);
    }

    $room = floorplan_fetch_room($roomId);
    if (!$room) {
        App::json(['error' => 'Room not found'], 404);
    }

    $cabinets = Database::fetchAll(
        'SELECT c.*,
            cr.name AS row_name,
            cr.zone_id AS row_zone_id,
            (SELECT COUNT(*) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1) AS device_count,
            (SELECT ISNULL(SUM(d.u_height),0) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1 AND d.position_u IS NOT NULL) AS u_used
         FROM cabinets c
         LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
         WHERE c.room_id = ? AND c.is_active = 1
         ORDER BY c.name',
        [$roomId]
    );
    $cabinets = Cabinet3dData::withDevices($cabinets);

    $rows = Database::fetchAll(
        'SELECT cr.*,
                (SELECT COUNT(*) FROM cabinets c WHERE c.row_id = cr.row_id AND c.is_active = 1) AS cabinet_count
         FROM cabinet_rows cr
         WHERE cr.room_id = ?
         ORDER BY cr.name',
        [$roomId]
    );

    // Power zones for this room's datacenter (for future / optional row→zone assignment)
    $zones = [];
    try {
        $dcId = (int)($room['datacenter_id'] ?? 0);
        if ($dcId) {
            $zones = Database::fetchAll(
                'SELECT zone_id, name, color_hex, feed_type FROM power_zones WHERE datacenter_id = ? ORDER BY name',
                [$dcId]
            );
        }
    } catch (Throwable $e) {
        $zones = [];
    }

    $placedPdus = [];
    $unplacedPdus = [];
    try {
        $dcId = (int)($room['datacenter_id'] ?? 0);
        $placedPdus = Database::fetchAll(
            'SELECT p.pdu_id, p.name, p.pdu_scope, p.row_id, p.zone_id, p.room_id,
                    p.pos_x, p.pos_y, p.pos_z, p.rotation_deg, p.front_facing,
                    p.width_mm, p.depth_mm, p.height_mm, p.color_hex, p.output_mode, p.num_breaker_slots,
                    p.rated_amps, p.phases, p.phase_wiring, p.ip_address,
                    r.name AS row_name, z.name AS zone_name, z.color_hex AS zone_color
             FROM pdus p
             LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
             LEFT JOIN power_zones z ON z.zone_id = p.zone_id
             WHERE p.is_active = 1
               AND p.pdu_scope IN (\'row\', \'room\')
               AND p.room_id = ?
               AND p.pos_x IS NOT NULL AND p.pos_y IS NOT NULL
             ORDER BY p.name',
            [$roomId]
        );
        // Unplaced: row/room PDUs with no floor coords; prefer same DC via zone or row room
        $unplacedPdus = Database::fetchAll(
            'SELECT p.pdu_id, p.name, p.pdu_scope, p.row_id, p.zone_id,
                    p.output_mode, p.num_breaker_slots, p.rated_amps, p.phases,
                    p.width_mm, p.depth_mm, p.height_mm, p.color_hex, p.front_facing,
                    r.name AS row_name, z.name AS zone_name, z.color_hex AS zone_color
             FROM pdus p
             LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
             LEFT JOIN power_zones z ON z.zone_id = p.zone_id
             LEFT JOIN rooms rm ON rm.room_id = r.room_id
             WHERE p.is_active = 1
               AND p.pdu_scope IN (\'row\', \'room\')
               AND (p.pos_x IS NULL OR p.pos_y IS NULL OR p.room_id IS NULL)
               AND (
                    ? = 0
                    OR z.datacenter_id = ?
                    OR rm.datacenter_id = ?
                    OR (p.zone_id IS NULL AND p.row_id IS NULL)
               )
             ORDER BY p.name',
            [$dcId, $dcId, $dcId]
        );
    } catch (Throwable $e) {
        // Columns may not exist yet on first request before Schema::ensure
        $placedPdus = [];
        $unplacedPdus = [];
        App::log('floorplan PDU query: ' . $e->getMessage(), 'warning');
    }

    $paths = Database::fetchAll('SELECT * FROM cable_paths WHERE room_id = ?', [$roomId]);
    $units = SettingsService::get('length_units', 'metric');

    App::json([
        'room' => $room,
        'cabinets' => $cabinets,
        'rows' => $rows,
        'zones' => $zones,
        'placed_pdus' => $placedPdus,
        'unplaced_pdus' => $unplacedPdus,
        'cable_paths' => $paths,
        'units' => $units === 'imperial' ? 'imperial' : 'metric',
        'planner' => [
            'show_grid' => SettingsService::get('floorplan_show_grid', '1') === '1',
            'snap_to_grid' => SettingsService::get('floorplan_snap', '1') === '1',
            'grid_ft' => (float)SettingsService::get('floorplan_grid_ft', '1'),
        ],
    ]);
} catch (Throwable $e) {
    App::log('API floorplan: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}

function floorplan_fetch_room(int $roomId): ?array
{
    try {
        return Database::fetchOne(
            "SELECT r.*, dc.name AS dc_name, dc.datacenter_id,
                    dc.floor_width_m AS dc_floor_width_m, dc.floor_depth_m AS dc_floor_depth_m,
                    ISNULL(dc.north_edge, 'top') AS north_edge
             FROM rooms r
             INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
             WHERE r.room_id = ?",
            [$roomId]
        );
    } catch (Throwable $e) {
        // Column may not exist yet on very old DBs
        $row = Database::fetchOne(
            'SELECT r.*, dc.name AS dc_name, dc.datacenter_id,
                    dc.floor_width_m AS dc_floor_width_m, dc.floor_depth_m AS dc_floor_depth_m
             FROM rooms r
             INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
             WHERE r.room_id = ?',
            [$roomId]
        );
        if ($row) {
            $row['north_edge'] = 'top';
        }
        return $row;
    }
}

function floorplan_normalize_facing($facing): string
{
    $f = strtolower(trim((string)$facing));
    return in_array($f, ['north', 'south', 'east', 'west'], true) ? $f : 'north';
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function floorplan_pdu_geometry_from_data(array $data, string $facing): array
{
    $color = (string)($data['color_hex'] ?? '#b45309');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $color = '#b45309';
    }
    return [
        'pos_x' => round((float)($data['pos_x'] ?? 0), 3),
        'pos_y' => round((float)($data['pos_y'] ?? 0), 3),
        'pos_z' => isset($data['pos_z']) && $data['pos_z'] !== '' && $data['pos_z'] !== null
            ? round((float)$data['pos_z'], 3) : 0.0,
        'width_mm' => max(100, min(5000, (int)($data['width_mm'] ?? 600))),
        'depth_mm' => max(100, min(5000, (int)($data['depth_mm'] ?? 300))),
        'height_mm' => max(100, min(5000, (int)($data['height_mm'] ?? 1800))),
        'front_facing' => $facing,
        'rotation_deg' => isset($data['rotation_deg']) && $data['rotation_deg'] !== '' && $data['rotation_deg'] !== null
            ? (float)$data['rotation_deg'] : 0.0,
        'color_hex' => $color,
    ];
}

function floorplan_fetch_pdu(int $pduId): ?array
{
    return Database::fetchOne(
        'SELECT p.pdu_id, p.name, p.pdu_scope, p.row_id, p.zone_id, p.room_id,
                p.pos_x, p.pos_y, p.pos_z, p.rotation_deg, p.front_facing,
                p.width_mm, p.depth_mm, p.height_mm, p.color_hex, p.output_mode, p.num_breaker_slots,
                p.rated_amps, p.phases, p.phase_wiring, p.ip_address, p.is_active,
                r.name AS row_name, z.name AS zone_name, z.color_hex AS zone_color
         FROM pdus p
         LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
         LEFT JOIN power_zones z ON z.zone_id = p.zone_id
         WHERE p.pdu_id = ?',
        [$pduId]
    );
}
