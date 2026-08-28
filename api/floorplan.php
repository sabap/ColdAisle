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

    // --- Cooling unit floor placement ---
    $fpAction = (string)($_GET['action'] ?? '');
    if ($method === 'POST' && in_array($fpAction, [
        'place_ups', 'create_floor_ups', 'update_floor_ups', 'unplace_ups',
    ], true)) {
        api_require_any_permission(['edit_infrastructure', 'edit_power']);
        api_require_csrf();
        $data = api_read_json();

        if ($fpAction === 'create_floor_ups') {
            $roomId = (int)($data['room_id'] ?? 0);
            if ($roomId < 1) {
                App::json(['error' => 'room_id required'], 400);
            }
            $facing = floorplan_normalize_facing($data['front_facing'] ?? 'north');
            $scope = strtolower(trim((string)($data['ups_scope'] ?? 'in_row')));
            if (!in_array($scope, ['in_row', 'in_rack'], true)) {
                $scope = 'in_row';
            }
            $geo = floorplan_ups_geometry_from_data($data, $facing, $scope);
            $name = trim((string)($data['name'] ?? 'UPS'));
            if ($name === '') {
                $name = 'UPS';
            }
            $row = array_merge($geo, [
                'name' => mb_substr($name, 0, 150),
                'ups_scope' => $scope,
                'room_id' => $roomId,
                'manufacturer' => (string)($data['manufacturer'] ?? 'Schneider Electric'),
                'model' => (string)($data['model'] ?? 'Symmetra 40K'),
                'rated_kva' => isset($data['rated_kva']) ? (float)$data['rated_kva'] : 40.0,
                'rated_kw' => isset($data['rated_kw']) ? (float)$data['rated_kw'] : 40.0,
                'phases' => 3,
                'status' => 'production',
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $uid = Database::insert('ups_units', $row);
            AuditService::log((int)$user['user_id'], $user['username'], 'create', 'ups', (int)$uid, [
                'name' => $name, 'floorplan' => true,
            ]);
            App::json(['ups_unit' => floorplan_fetch_ups((int)$uid)], 201);
        }

        if ($fpAction === 'place_ups') {
            $uid = (int)($data['ups_id'] ?? 0);
            $roomId = (int)($data['room_id'] ?? 0);
            if ($uid < 1 || $roomId < 1) {
                App::json(['error' => 'ups_id and room_id required'], 400);
            }
            $existing = Database::fetchOne(
                'SELECT * FROM ups_units WHERE ups_id = ? AND is_active = 1',
                [$uid]
            );
            if (!$existing) {
                App::json(['error' => 'UPS not found'], 404);
            }
            $facing = floorplan_normalize_facing(
                $data['front_facing'] ?? ($existing['front_facing'] ?? 'north')
            );
            $scope = (string)($existing['ups_scope'] ?? 'in_row');
            $geo = floorplan_ups_geometry_from_data(
                array_merge($existing, $data),
                $facing,
                $scope
            );
            $fields = array_merge($geo, [
                'room_id' => $roomId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Database::update('ups_units', $fields, 'ups_id = :id', [':id' => $uid]);
            AuditService::log((int)$user['user_id'], $user['username'], 'update', 'ups', $uid, [
                'floorplan_place' => true,
            ]);
            App::json(['ups_unit' => floorplan_fetch_ups($uid)]);
        }

        if ($fpAction === 'update_floor_ups') {
            $uid = (int)($data['ups_id'] ?? 0);
            if ($uid < 1) {
                App::json(['error' => 'ups_id required'], 400);
            }
            $existing = Database::fetchOne(
                'SELECT * FROM ups_units WHERE ups_id = ? AND is_active = 1',
                [$uid]
            );
            if (!$existing) {
                App::json(['error' => 'UPS not found'], 404);
            }
            $facing = floorplan_normalize_facing(
                $data['front_facing'] ?? ($existing['front_facing'] ?? 'north')
            );
            $geo = floorplan_ups_geometry_from_data(
                array_merge($existing, $data),
                $facing,
                (string)($existing['ups_scope'] ?? 'in_row')
            );
            $fields = array_merge($geo, ['updated_at' => date('Y-m-d H:i:s')]);
            if (array_key_exists('name', $data) && trim((string)$data['name']) !== '') {
                $fields['name'] = mb_substr(trim((string)$data['name']), 0, 150);
            }
            Database::update('ups_units', $fields, 'ups_id = :id', [':id' => $uid]);
            App::json(['ups_unit' => floorplan_fetch_ups($uid)]);
        }

        if ($fpAction === 'unplace_ups') {
            $uid = (int)($data['ups_id'] ?? 0);
            if ($uid < 1) {
                App::json(['error' => 'ups_id required'], 400);
            }
            Database::update('ups_units', [
                'pos_x' => null,
                'pos_y' => null,
                'pos_z' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'ups_id = :id', [':id' => $uid]);
            AuditService::log((int)$user['user_id'], $user['username'], 'update', 'ups', $uid, [
                'floorplan_unplace' => true,
            ]);
            App::json(['ok' => true, 'ups_id' => $uid]);
        }
    }

    if ($method === 'POST' && in_array($fpAction, [
        'place_cooling', 'create_floor_cooling', 'update_floor_cooling', 'unplace_cooling',
    ], true)) {
        api_require_any_permission(['edit_infrastructure', 'edit_cooling']);
        api_require_csrf();
        $data = api_read_json();

        if ($fpAction === 'create_floor_cooling') {
            $roomId = (int)($data['room_id'] ?? 0);
            if (!$roomId) {
                App::json(['error' => 'room_id required'], 400);
            }
            if (!floorplan_fetch_room($roomId)) {
                App::json(['error' => 'Room not found'], 404);
            }
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                $name = 'Cooling unit';
            }
            $unitType = strtolower((string)($data['unit_type'] ?? 'crac'));
            $allowedTypes = [
                'crac', 'crah', 'in_row', 'chiller', 'chilled_water_pump', 'ac_pump', 'cdu', 'ahu', 'other',
            ];
            if (!in_array($unitType, $allowedTypes, true)) {
                $unitType = 'crac';
            }
            $medium = strtolower((string)($data['cooling_medium'] ?? 'dx'));
            if (!in_array($medium, ['dx', 'chilled_water', 'glycol', 'dual', 'other'], true)) {
                $medium = $unitType === 'crah' || str_contains($unitType, 'pump') ? 'chilled_water' : 'dx';
            }
            $facing = floorplan_normalize_facing($data['front_facing'] ?? 'north');
            $geom = floorplan_cooling_geometry_from_data($data, $facing, $unitType);
            $role = strtolower((string)($data['unit_role'] ?? 'primary'));
            if (!in_array($role, ['primary', 'standby', 'shared', 'unknown'], true)) {
                $role = 'primary';
            }
            $row = array_merge([
                'name' => $name,
                'unit_type' => $unitType,
                'unit_role' => $role,
                'cooling_medium' => $medium,
                'room_id' => $roomId,
                'manufacturer' => trim((string)($data['manufacturer'] ?? '')) !== ''
                    ? trim((string)$data['manufacturer']) : null,
                'model' => trim((string)($data['model'] ?? '')) !== ''
                    ? trim((string)$data['model']) : null,
                'status' => 'production',
                'is_active' => 1,
            ], $geom);
            $cid = Database::insert('cooling_units', $row);
            AuditService::log((int)$user['user_id'], $user['username'], 'create', 'cooling_unit', (int)$cid, [
                'name' => $name,
                'floor_placed' => true,
                'room_id' => $roomId,
            ]);
            App::json(['cooling_unit' => floorplan_fetch_cooling((int)$cid)], 201);
        }

        if ($fpAction === 'place_cooling') {
            $cid = (int)($data['cooling_unit_id'] ?? 0);
            $roomId = (int)($data['room_id'] ?? 0);
            if ($cid <= 0 || $roomId <= 0) {
                App::json(['error' => 'cooling_unit_id and room_id required'], 400);
            }
            $unit = Database::fetchOne(
                'SELECT * FROM cooling_units WHERE cooling_unit_id = ? AND is_active = 1',
                [$cid]
            );
            if (!$unit) {
                App::json(['error' => 'Cooling unit not found'], 404);
            }
            if (!floorplan_fetch_room($roomId)) {
                App::json(['error' => 'Room not found'], 404);
            }
            $facing = floorplan_normalize_facing($data['front_facing'] ?? ($unit['front_facing'] ?? 'north'));
            $geom = floorplan_cooling_geometry_from_data(
                array_merge($unit, $data),
                $facing,
                (string)($unit['unit_type'] ?? 'crac')
            );
            $fields = array_merge(['room_id' => $roomId], $geom);
            if (array_key_exists('name', $data) && trim((string)$data['name']) !== '') {
                $fields['name'] = trim((string)$data['name']);
            }
            Database::update('cooling_units', $fields, 'cooling_unit_id = :id', [':id' => $cid]);
            AuditService::log((int)$user['user_id'], $user['username'], 'update', 'cooling_unit', $cid, [
                'floor_place' => true,
                'room_id' => $roomId,
            ]);
            App::json(['cooling_unit' => floorplan_fetch_cooling($cid)]);
        }

        if ($fpAction === 'update_floor_cooling') {
            $cid = (int)($data['cooling_unit_id'] ?? 0);
            if ($cid <= 0) {
                App::json(['error' => 'cooling_unit_id required'], 400);
            }
            $unit = Database::fetchOne(
                'SELECT * FROM cooling_units WHERE cooling_unit_id = ? AND is_active = 1',
                [$cid]
            );
            if (!$unit) {
                App::json(['error' => 'Cooling unit not found'], 404);
            }
            $fields = [];
            if (array_key_exists('name', $data) && trim((string)$data['name']) !== '') {
                $fields['name'] = trim((string)$data['name']);
            }
            $hasGeom = false;
            foreach (['pos_x', 'pos_y', 'width_mm', 'depth_mm', 'height_mm', 'rotation_deg', 'front_facing', 'color_hex'] as $k) {
                if (array_key_exists($k, $data)) {
                    $hasGeom = true;
                    break;
                }
            }
            if ($hasGeom) {
                $facing = floorplan_normalize_facing($data['front_facing'] ?? ($unit['front_facing'] ?? 'north'));
                $merged = array_merge($unit, $data);
                $fields = array_merge(
                    $fields,
                    floorplan_cooling_geometry_from_data($merged, $facing, (string)($unit['unit_type'] ?? 'crac'))
                );
            }
            if (!$fields) {
                App::json(['error' => 'No fields to update'], 400);
            }
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Database::update('cooling_units', $fields, 'cooling_unit_id = :id', [':id' => $cid]);
            App::json(['cooling_unit' => floorplan_fetch_cooling($cid)]);
        }

        if ($fpAction === 'unplace_cooling') {
            $cid = (int)($data['cooling_unit_id'] ?? 0);
            if ($cid <= 0) {
                App::json(['error' => 'cooling_unit_id required'], 400);
            }
            $unit = Database::fetchOne(
                'SELECT cooling_unit_id FROM cooling_units WHERE cooling_unit_id = ? AND is_active = 1',
                [$cid]
            );
            if (!$unit) {
                App::json(['error' => 'Cooling unit not found'], 404);
            }
            Database::update('cooling_units', [
                'pos_x' => null,
                'pos_y' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'cooling_unit_id = :id', [':id' => $cid]);
            AuditService::log((int)$user['user_id'], $user['username'], 'update', 'cooling_unit', $cid, [
                'floor_unplace' => true,
            ]);
            App::json(['ok' => true, 'cooling_unit_id' => $cid]);
        }
    }

    if ($method === 'POST' && in_array($fpAction, [
        'create_airflow_anchor', 'update_airflow_anchor', 'delete_airflow_anchor',
    ], true)) {
        api_require_any_permission(['edit_infrastructure', 'edit_cooling']);
        api_require_csrf();
        $data = api_read_json();
        if (class_exists('Schema')) {
            Schema::ensureAirflow();
        }

        if ($fpAction === 'create_airflow_anchor') {
            $roomId = (int)($data['room_id'] ?? 0);
            if ($roomId < 1 || !floorplan_fetch_room($roomId)) {
                App::json(['error' => 'room_id required'], 400);
            }
            $kind = strtolower(trim((string)($data['kind'] ?? 'supply_vent')));
            if (!in_array($kind, ['supply_vent', 'return'], true)) {
                $kind = 'supply_vent';
            }
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                $name = $kind === 'return' ? 'Return' : 'Supply vent';
            }
            $w = (float)($data['width_m'] ?? 0.6);
            $d = (float)($data['depth_m'] ?? 0.6);
            if ($w < 0.2) {
                $w = 0.6;
            }
            if ($d < 0.2) {
                $d = 0.6;
            }
            $color = trim((string)($data['color_hex'] ?? ''));
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $color = $kind === 'return' ? '#fb923c' : '#38bdf8';
            }
            $z = isset($data['pos_z']) && $data['pos_z'] !== '' && $data['pos_z'] !== null
                ? (float)$data['pos_z'] : null;
            $cid = isset($data['cooling_unit_id']) ? (int)$data['cooling_unit_id'] : 0;
            $id = (int)Database::insert('airflow_anchors', [
                'room_id' => $roomId,
                'kind' => $kind,
                'name' => $name,
                'pos_x' => round((float)($data['pos_x'] ?? 0), 3),
                'pos_y' => round((float)($data['pos_y'] ?? 0), 3),
                'pos_z' => $z,
                'width_m' => $w,
                'depth_m' => $d,
                'rotation_deg' => (float)($data['rotation_deg'] ?? 0),
                'color_hex' => $color,
                'cooling_unit_id' => $cid > 0 ? $cid : null,
                'is_locked' => 0,
                'is_active' => 1,
            ]);
            if (class_exists('AuditService')) {
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'create', 'airflow_anchor', $id, [
                    'kind' => $kind,
                    'room_id' => $roomId,
                ]);
            }
            App::json(['anchor' => floorplan_fetch_airflow($id)], 201);
        }

        if ($fpAction === 'update_airflow_anchor') {
            $id = (int)($data['anchor_id'] ?? 0);
            $row = $id > 0 ? floorplan_fetch_airflow($id) : null;
            if (!$row) {
                App::json(['error' => 'Airflow marker not found'], 404);
            }
            $fields = [];
            if (array_key_exists('name', $data) && trim((string)$data['name']) !== '') {
                $fields['name'] = trim((string)$data['name']);
            }
            if (array_key_exists('kind', $data)) {
                $k = strtolower(trim((string)$data['kind']));
                if (in_array($k, ['supply_vent', 'return'], true)) {
                    $fields['kind'] = $k;
                }
            }
            foreach (['pos_x', 'pos_y', 'pos_z', 'width_m', 'depth_m', 'rotation_deg'] as $col) {
                if (array_key_exists($col, $data) && $data[$col] !== '') {
                    $fields[$col] = round((float)$data[$col], 3);
                }
            }
            if (array_key_exists('color_hex', $data) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$data['color_hex'])) {
                $fields['color_hex'] = (string)$data['color_hex'];
            }
            if (array_key_exists('is_locked', $data)) {
                $fields['is_locked'] = !empty($data['is_locked']) ? 1 : 0;
            }
            if (array_key_exists('cooling_unit_id', $data)) {
                $cid = (int)$data['cooling_unit_id'];
                $fields['cooling_unit_id'] = $cid > 0 ? $cid : null;
            }
            if (!$fields) {
                App::json(['error' => 'No fields to update'], 400);
            }
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Database::update('airflow_anchors', $fields, 'anchor_id = :id', [':id' => $id]);
            App::json(['anchor' => floorplan_fetch_airflow($id)]);
        }

        if ($fpAction === 'delete_airflow_anchor') {
            $id = (int)($data['anchor_id'] ?? 0);
            if ($id < 1) {
                App::json(['error' => 'anchor_id required'], 400);
            }
            Database::delete('airflow_anchors', 'anchor_id = ?', [$id]);
            if (class_exists('AuditService')) {
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'delete', 'airflow_anchor', $id, []);
            }
            App::json(['ok' => true, 'anchor_id' => $id]);
        }
    }

    // --- Row / room PDU floor placement ---
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

    // Raceway geometry save (shared CablePlantService — same as Cabling page)
    if ($method === 'POST' && (($_GET['action'] ?? '') === 'save_cable_path')) {
        api_require_permission('edit_infrastructure');
        api_require_csrf();
        if (!class_exists('CablePlantService')) {
            App::json(['error' => 'CablePlantService unavailable'], 500);
        }
        $data = api_read_json();
        $pathId = (int)($data['path_id'] ?? 0);
        $roomId = (int)($data['room_id'] ?? $_GET['room_id'] ?? 0);
        if ($roomId > 0) {
            $data['room_id'] = $roomId;
        }
        $res = CablePlantService::savePath($data, $pathId > 0 ? $pathId : null);
        if (empty($res['ok'])) {
            App::json(['error' => $res['message'] ?? 'Save failed'], 400);
        }
        $row = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [(int)$res['path_id']]);
        if ($row) {
            $row['waypoints_list'] = CablePlantService::parseWaypoints($row['waypoints'] ?? null);
        }
        AuditService::log(
            (int)$user['user_id'],
            $user['username'],
            $pathId > 0 ? 'update' : 'create',
            'cable_path',
            (int)$res['path_id']
        );
        App::json(['path' => $row, 'message' => $res['message']], $pathId > 0 ? 200 : 201);
    }

    // Re-apply U-channel elevations = matching ladder + offset (~10 in)
    if ($method === 'POST' && (($_GET['action'] ?? '') === 'reapply_uchannel_elev')) {
        api_require_permission('edit_infrastructure');
        api_require_csrf();
        if (!class_exists('CablePlantService')) {
            App::json(['error' => 'CablePlantService unavailable'], 500);
        }
        $data = api_read_json();
        $roomId = (int)($data['room_id'] ?? $_GET['room_id'] ?? 0);
        $offset = isset($data['elevation_offset_m']) && $data['elevation_offset_m'] !== '' && $data['elevation_offset_m'] !== null
            ? (float)$data['elevation_offset_m']
            : CablePlantService::DEFAULT_U_CHANNEL_ELEV_OFFSET_M;
        $res = CablePlantService::reapplyUChannelElevations($roomId, $offset);
        if (empty($res['ok'])) {
            App::json(['error' => $res['message'] ?? 'No updates'], 400);
        }
        $paths = CablePlantService::pathsForRoom($roomId, true);
        App::json(['ok' => true, 'message' => $res['message'], 'updated' => $res['updated'], 'cable_paths' => $paths]);
    }

    // Clone raceway (same plan geometry / fillets; new kind + elevation offset)
    if ($method === 'POST' && (($_GET['action'] ?? '') === 'clone_cable_path')) {
        api_require_permission('edit_infrastructure');
        api_require_csrf();
        if (!class_exists('CablePlantService')) {
            App::json(['error' => 'CablePlantService unavailable'], 500);
        }
        $data = api_read_json();
        $sourceId = (int)($data['path_id'] ?? $data['source_path_id'] ?? 0);
        $bulk = !empty($data['bulk']) || !empty($data['all_of_kind']);
        if ($bulk) {
            $roomId = (int)($data['room_id'] ?? 0);
            $srcKind = (string)($data['source_kind'] ?? $data['from_kind'] ?? 'ladder');
            $res = CablePlantService::clonePathsByKindInRoom($roomId, $srcKind, $data);
            if (empty($res['ok'])) {
                App::json(['error' => $res['message'] ?? 'Clone failed'], 400);
            }
            AuditService::log(
                (int)$user['user_id'],
                $user['username'],
                'create',
                'cable_path',
                0,
                ['bulk_clone' => true, 'count' => count($res['created'] ?? []), 'from_kind' => $srcKind]
            );
            App::json($res);
        }
        $res = CablePlantService::clonePath($sourceId, $data);
        if (empty($res['ok'])) {
            App::json(['error' => $res['message'] ?? 'Clone failed'], 400);
        }
        AuditService::log(
            (int)$user['user_id'],
            $user['username'],
            'create',
            'cable_path',
            (int)$res['path_id'],
            ['cloned_from' => $sourceId]
        );
        App::json($res, 201);
    }

    // Merge two raceways at endpoints (shared CablePlantService)
    if ($method === 'POST' && (($_GET['action'] ?? '') === 'merge_cable_paths')) {
        api_require_permission('edit_infrastructure');
        api_require_csrf();
        if (!class_exists('CablePlantService')) {
            App::json(['error' => 'CablePlantService unavailable'], 500);
        }
        $data = api_read_json();
        $keepId = (int)($data['path_id_keep'] ?? $data['keep_id'] ?? 0);
        $otherId = (int)($data['path_id_other'] ?? $data['other_id'] ?? 0);
        $endKeep = (int)($data['end_keep'] ?? 1);
        $endOther = (int)($data['end_other'] ?? 0);
        $res = CablePlantService::mergePathsAtEndpoints($keepId, $endKeep, $otherId, $endOther);
        if (empty($res['ok'])) {
            App::json(['error' => $res['message'] ?? 'Merge failed'], 400);
        }
        $row = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [(int)$res['path_id']]);
        if ($row && class_exists('CablePlantService')) {
            $row['waypoints_list'] = CablePlantService::parseWaypoints($row['waypoints'] ?? null);
        }
        AuditService::log(
            (int)$user['user_id'],
            $user['username'],
            'merge',
            'cable_path',
            (int)$res['path_id'],
            ['absorbed' => $otherId, 'junction_index' => (int)($res['junction_index'] ?? -1)]
        );
        App::json([
            'path' => $row,
            'message' => $res['message'],
            'junction_index' => (int)($res['junction_index'] ?? -1),
            'absorbed_path_id' => $otherId,
        ]);
    }

    // Delete raceway / pathway (shared CablePlantService)
    if ($method === 'POST' && (($_GET['action'] ?? '') === 'delete_cable_path')) {
        api_require_permission('edit_infrastructure');
        api_require_csrf();
        if (!class_exists('CablePlantService')) {
            App::json(['error' => 'CablePlantService unavailable'], 500);
        }
        $data = api_read_json();
        $pathId = (int)($data['path_id'] ?? $_GET['id'] ?? 0);
        $force = !empty($data['force']);
        $res = CablePlantService::deletePath($pathId, $force);
        if (empty($res['ok'])) {
            App::json([
                'error' => $res['message'] ?? 'Delete failed',
                'in_use' => true,
            ], 409);
        }
        AuditService::log(
            (int)$user['user_id'],
            $user['username'],
            'delete',
            'cable_path',
            $pathId,
            ['force' => $force, 'unlinked' => (int)($res['unlinked'] ?? 0)]
        );
        App::json(['ok' => true, 'message' => $res['message'], 'unlinked' => (int)($res['unlinked'] ?? 0)]);
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
            (SELECT ISNULL(SUM(d.u_height),0) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1 AND d.position_u IS NOT NULL AND d.parent_device_id IS NULL) AS u_used
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
                    p.icmp_monitor, p.icmp_fail_count, p.icmp_last_at, p.icmp_last_ok,
                    p.icmp_last_rtt_ms, p.icmp_last_error,
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
        if (class_exists('CabinetHealthService')) {
            $placedPdus = CabinetHealthService::attachPdus($placedPdus);
        }
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

    $placedCooling = [];
    $unplacedCooling = [];
    try {
        $dcId = (int)($room['datacenter_id'] ?? 0);
        $placedCooling = Database::fetchAll(
            'SELECT u.cooling_unit_id, u.name, u.unit_type, u.unit_role, u.cooling_medium,
                    u.room_id, u.pos_x, u.pos_y, u.pos_z, u.rotation_deg, u.front_facing,
                    u.width_mm, u.depth_mm, u.height_mm, u.color_hex, u.primary_ip, u.status,
                    u.rated_kw_cooling, u.standby_of_id
             FROM cooling_units u
             WHERE u.is_active = 1
               AND u.room_id = ?
               AND u.pos_x IS NOT NULL AND u.pos_y IS NOT NULL
             ORDER BY u.name',
            [$roomId]
        );
        $unplacedCooling = Database::fetchAll(
            'SELECT u.cooling_unit_id, u.name, u.unit_type, u.unit_role, u.cooling_medium,
                    u.width_mm, u.depth_mm, u.height_mm, u.color_hex, u.front_facing,
                    u.primary_ip, u.status, u.room_id
             FROM cooling_units u
             LEFT JOIN rooms rm ON rm.room_id = u.room_id
             WHERE u.is_active = 1
               AND (u.pos_x IS NULL OR u.pos_y IS NULL)
               AND (
                    ? = 0
                    OR u.room_id IS NULL
                    OR u.room_id = ?
                    OR rm.datacenter_id = ?
               )
             ORDER BY u.name',
            [$dcId, $roomId, $dcId]
        );
    } catch (Throwable $e) {
        $placedCooling = [];
        $unplacedCooling = [];
        App::log('floorplan cooling query: ' . $e->getMessage(), 'warning');
    }

    $placedUps = [];
    $unplacedUps = [];
    try {
        $placedUps = Database::fetchAll(
            'SELECT u.ups_id, u.name, u.ups_scope, u.room_id, u.pos_x, u.pos_y, u.pos_z,
                    u.rotation_deg, u.front_facing, u.width_mm, u.depth_mm, u.height_mm, u.color_hex,
                    u.primary_ip, u.status, u.rated_kva, u.rated_kw,
                    u.last_output_status, u.last_load_pct, u.last_battery_pct, u.last_runtime_min
             FROM ups_units u
             WHERE u.is_active = 1 AND u.room_id = ?
               AND u.pos_x IS NOT NULL AND u.pos_y IS NOT NULL
             ORDER BY u.name',
            [$roomId]
        );
        if (function_exists('ups_health_status') || is_file(dirname(__DIR__) . '/includes/ups_helpers.php')) {
            require_once dirname(__DIR__) . '/includes/ups_helpers.php';
            foreach ($placedUps as &$pu) {
                $pu['health_status'] = ups_health_status($pu);
            }
            unset($pu);
        }
        $unplacedUps = Database::fetchAll(
            'SELECT u.ups_id, u.name, u.ups_scope, u.width_mm, u.depth_mm, u.height_mm, u.color_hex,
                    u.primary_ip, u.status, u.room_id, u.rated_kva
             FROM ups_units u
             WHERE u.is_active = 1
               AND (u.pos_x IS NULL OR u.pos_y IS NULL)
               AND (u.room_id IS NULL OR u.room_id = ?)
             ORDER BY u.name',
            [$roomId]
        );
    } catch (Throwable $e) {
        $placedUps = [];
        $unplacedUps = [];
        App::log('floorplan UPS query: ' . $e->getMessage(), 'warning');
    }

    $paths = [];
    try {
        if (class_exists('CablePlantService')) {
            $paths = CablePlantService::pathsForRoom($roomId, true);
        } else {
            $paths = Database::fetchAll('SELECT * FROM cable_paths WHERE room_id = ?', [$roomId]);
        }
    } catch (Throwable $e) {
        $paths = [];
    }
    $units = SettingsService::get('length_units', 'metric');

    $envSensors3d = [];
    try {
        if (class_exists('EnvSensor3dData')) {
            $envSensors3d = EnvSensor3dData::forFloor(EnvSensor3dData::DEFAULT_RADIUS_M, $roomId);
        }
    } catch (Throwable $e) {
        $envSensors3d = [];
    }

    $airflowAnchors = [];
    try {
        if (class_exists('Schema')) {
            Schema::ensureAirflow();
        }
        $airflowAnchors = Database::fetchAll(
            'SELECT * FROM airflow_anchors WHERE room_id = ? AND is_active = 1 ORDER BY kind, name, anchor_id',
            [$roomId]
        ) ?: [];
    } catch (Throwable $e) {
        $airflowAnchors = [];
    }

    App::json([
        'room' => $room,
        'cabinets' => $cabinets,
        'rows' => $rows,
        'zones' => $zones,
        'placed_pdus' => $placedPdus,
        'unplaced_pdus' => $unplacedPdus,
        'placed_cooling' => $placedCooling,
        'unplaced_cooling' => $unplacedCooling,
        'placed_ups' => $placedUps,
        'unplaced_ups' => $unplacedUps,
        'env_sensors' => $envSensors3d,
        'airflow_anchors' => $airflowAnchors,
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

function floorplan_fetch_airflow(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $row = Database::fetchOne('SELECT * FROM airflow_anchors WHERE anchor_id = ?', [$id]);
    return $row ?: null;
}

function floorplan_normalize_facing($facing): string
{
    $f = strtolower(trim((string)$facing));
    $short = ['n' => 'north', 's' => 'south', 'e' => 'east', 'w' => 'west'];
    if (isset($short[$f])) {
        $f = $short[$f];
    }
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

function floorplan_cooling_default_color(string $unitType): string
{
    return match ($unitType) {
        'crac', 'crah', 'in_row', 'ahu' => '#0ea5e9',
        'chiller' => '#0284c7',
        'chilled_water_pump', 'ac_pump' => '#0369a1',
        'cdu' => '#38bdf8',
        default => '#64748b',
    };
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function floorplan_cooling_geometry_from_data(array $data, string $facing, string $unitType = 'crac'): array
{
    $defaultColor = floorplan_cooling_default_color($unitType);
    $color = (string)($data['color_hex'] ?? $defaultColor);
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $color = $defaultColor;
    }
    $defaultW = str_contains($unitType, 'pump') ? 600 : 1200;
    $defaultD = str_contains($unitType, 'pump') ? 600 : 900;
    $defaultH = str_contains($unitType, 'pump') ? 1200 : 2000;
    return [
        'pos_x' => round((float)($data['pos_x'] ?? 0), 3),
        'pos_y' => round((float)($data['pos_y'] ?? 0), 3),
        'pos_z' => isset($data['pos_z']) && $data['pos_z'] !== '' && $data['pos_z'] !== null
            ? round((float)$data['pos_z'], 3) : 0.0,
        'width_mm' => max(100, min(8000, (int)($data['width_mm'] ?? $defaultW))),
        'depth_mm' => max(100, min(8000, (int)($data['depth_mm'] ?? $defaultD))),
        'height_mm' => max(100, min(8000, (int)($data['height_mm'] ?? $defaultH))),
        'front_facing' => $facing,
        'rotation_deg' => isset($data['rotation_deg']) && $data['rotation_deg'] !== '' && $data['rotation_deg'] !== null
            ? (float)$data['rotation_deg'] : 0.0,
        'color_hex' => $color,
    ];
}

function floorplan_fetch_cooling(int $coolingUnitId): ?array
{
    return Database::fetchOne(
        'SELECT u.cooling_unit_id, u.name, u.unit_type, u.unit_role, u.cooling_medium,
                u.room_id, u.pos_x, u.pos_y, u.pos_z, u.rotation_deg, u.front_facing,
                u.width_mm, u.depth_mm, u.height_mm, u.color_hex, u.primary_ip, u.status,
                u.rated_kw_cooling, u.standby_of_id, u.is_active
         FROM cooling_units u
         WHERE u.cooling_unit_id = ?',
        [$coolingUnitId]
    );
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function floorplan_ups_geometry_from_data(array $data, string $facing, string $scope = 'in_row'): array
{
    $color = (string)($data['color_hex'] ?? '#7c3aed');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $color = '#7c3aed';
    }
    $defaultW = 600;
    $defaultD = $scope === 'in_rack' ? 1000 : 1100;
    $defaultH = $scope === 'in_rack' ? 1800 : 2000;
    return [
        'pos_x' => round((float)($data['pos_x'] ?? 0), 3),
        'pos_y' => round((float)($data['pos_y'] ?? 0), 3),
        'pos_z' => isset($data['pos_z']) && $data['pos_z'] !== '' && $data['pos_z'] !== null
            ? round((float)$data['pos_z'], 3) : 0.0,
        'width_mm' => max(100, min(8000, (int)($data['width_mm'] ?? $defaultW))),
        'depth_mm' => max(100, min(8000, (int)($data['depth_mm'] ?? $defaultD))),
        'height_mm' => max(100, min(8000, (int)($data['height_mm'] ?? $defaultH))),
        'front_facing' => $facing,
        'rotation_deg' => isset($data['rotation_deg']) && $data['rotation_deg'] !== '' && $data['rotation_deg'] !== null
            ? (float)$data['rotation_deg'] : 0.0,
        'color_hex' => $color,
    ];
}

function floorplan_fetch_ups(int $upsId): ?array
{
    $row = Database::fetchOne(
        'SELECT u.ups_id, u.name, u.ups_scope, u.room_id, u.pos_x, u.pos_y, u.pos_z,
                u.rotation_deg, u.front_facing, u.width_mm, u.depth_mm, u.height_mm, u.color_hex,
                u.primary_ip, u.status, u.rated_kva, u.rated_kw, u.is_active,
                u.last_output_status, u.last_load_pct, u.last_battery_pct, u.last_runtime_min
         FROM ups_units u
         WHERE u.ups_id = ?',
        [$upsId]
    );
    if ($row && (function_exists('ups_health_status') || is_file(dirname(__DIR__) . '/includes/ups_helpers.php'))) {
        require_once dirname(__DIR__) . '/includes/ups_helpers.php';
        $row['health_status'] = ups_health_status($row);
    }
    return $row;
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
