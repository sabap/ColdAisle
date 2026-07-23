<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

try {
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        api_require_permission('edit_infrastructure');
    }
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

        if ($id) {
            $cab = Database::fetchOne(
                'SELECT c.*, r.name AS room_name FROM cabinets c
                 INNER JOIN rooms r ON r.room_id = c.room_id
                 WHERE c.cabinet_id = ?',
                [$id]
            );
            if (!$cab) {
                App::json(['error' => 'Not found'], 404);
            }
            $devices = Database::fetchAll(
                'SELECT * FROM devices WHERE cabinet_id = ? AND is_active = 1 ORDER BY position_u DESC',
                [$id]
            );
            App::json(['cabinet' => $cab, 'devices' => $devices]);
        }

        $sql = 'SELECT c.*, r.name AS room_name FROM cabinets c
                INNER JOIN rooms r ON r.room_id = c.room_id WHERE c.is_active = 1';
        $params = [];
        if ($roomId) {
            $sql .= ' AND c.room_id = ?';
            $params[] = $roomId;
        }
        $sql .= ' ORDER BY c.name';
        App::json(['cabinets' => Database::fetchAll($sql, $params)]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $data = api_read_json();
        $roomId = (int)($data['room_id'] ?? 0);
        if (!$roomId) {
            App::json(['error' => 'room_id required'], 400);
        }
        $roomOk = Database::fetchValue('SELECT 1 FROM rooms WHERE room_id = ? AND is_active = 1', [$roomId]);
        if (!$roomOk) {
            App::json(['error' => 'Room not found'], 404);
        }
        $name = trim((string)($data['name'] ?? 'CAB'));
        if ($name === '') {
            $name = 'CAB';
        }
        // Omit optional nulls that confuse some ODBC bindings
        $row = [
            'room_id' => $roomId,
            'name' => $name,
            'u_height' => max(1, min(60, (int)($data['u_height'] ?? 42))),
            'width_mm' => max(100, (int)($data['width_mm'] ?? 600)),
            'depth_mm' => max(100, (int)($data['depth_mm'] ?? 1200)),
            'pos_x' => (float)($data['pos_x'] ?? 0),
            'pos_y' => (float)($data['pos_y'] ?? 0),
            'pos_z' => (float)($data['pos_z'] ?? 0),
            'rotation_deg' => (float)($data['rotation_deg'] ?? 0),
            'color_hex' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($data['color_hex'] ?? ''))
                ? (string)$data['color_hex']
                : '#2d3748',
            'front_facing' => (string)($data['front_facing'] ?? 'north'),
            'is_active' => 1,
        ];
        if (!empty($data['model_key'])) {
            $row['model_key'] = substr((string)$data['model_key'], 0, 50);
        }
        if (array_key_exists('row_id', $data)) {
            if ($data['row_id'] === null || $data['row_id'] === '' || (int)$data['row_id'] === 0) {
                // leave unset on insert = NULL
            } else {
                $row['row_id'] = (int)$data['row_id'];
            }
        }
        if (isset($data['location_tag']) && $data['location_tag'] !== '') {
            $row['location_tag'] = (string)$data['location_tag'];
        }
        // Optional vendor note stored in notes when provided and notes empty
        if (!empty($data['vendor_name']) && empty($data['notes'])) {
            $skus = isset($data['sku_summary']) ? (string)$data['sku_summary'] : '';
            $row['notes'] = trim('Vendor: ' . $data['vendor_name'] . ($skus !== '' ? ' · ' . $skus : ''));
        }
        if (isset($data['max_kw']) && $data['max_kw'] !== '' && $data['max_kw'] !== null) {
            $row['max_kw'] = (float)$data['max_kw'];
        }
        if (isset($data['notes']) && $data['notes'] !== '') {
            $row['notes'] = (string)$data['notes'];
        }

        $id = Database::insert('cabinets', $row);
        if (!$id) {
            // Fallback: locate by room + name + latest (ODBC identity edge cases)
            $cab = Database::fetchOne(
                'SELECT TOP 1 * FROM cabinets WHERE room_id = ? AND name = ? ORDER BY cabinet_id DESC',
                [$roomId, $name]
            );
            $id = $cab ? (int)$cab['cabinet_id'] : 0;
        } else {
            $cab = Database::fetchOne('SELECT * FROM cabinets WHERE cabinet_id = ?', [$id]);
        }
        if (!$cab) {
            App::json(['error' => 'Cabinet was created but could not be reloaded (id=' . $id . ')'], 500);
        }
        AuditService::log((int)$user['user_id'], $user['username'], 'create', 'cabinet', (int)$cab['cabinet_id'], ['name' => $name]);
        App::json(['cabinet' => $cab], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        api_require_csrf();
        $data = api_read_json();
        $id = (int)($data['cabinet_id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            App::json(['error' => 'cabinet_id required'], 400);
        }
        $fields = [];
        $map = [
            'name', 'location_tag', 'u_height', 'width_mm', 'depth_mm', 'max_weight_kg', 'max_kw',
            'pos_x', 'pos_y', 'pos_z', 'rotation_deg', 'color_hex', 'front_facing', 'notes',
            'row_id', 'room_id', 'model_key',
        ];
        foreach ($map as $k) {
            if (array_key_exists($k, $data)) {
                $fields[$k] = $data[$k];
            }
        }
        if (array_key_exists('row_id', $fields)) {
            if ($fields['row_id'] === null || $fields['row_id'] === '' || (int)$fields['row_id'] === 0) {
                $fields['row_id'] = null;
            } else {
                $fields['row_id'] = (int)$fields['row_id'];
            }
        }
        if (!$fields) {
            App::json(['error' => 'No fields to update'], 400);
        }
        $fields['updated_at'] = date('Y-m-d H:i:s');
        Database::update('cabinets', $fields, 'cabinet_id = :id', [':id' => $id]);
        $cab = Database::fetchOne('SELECT * FROM cabinets WHERE cabinet_id = ?', [$id]);
        AuditService::log((int)$user['user_id'], $user['username'], 'update', 'cabinet', $id, $fields);
        App::json(['cabinet' => $cab]);
    }

    if ($method === 'DELETE') {
        api_require_csrf();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            App::json(['error' => 'id required'], 400);
        }
        // Soft delete if devices exist
        $devCount = (int) Database::fetchValue('SELECT COUNT(*) FROM devices WHERE cabinet_id = ? AND is_active = 1', [$id]);
        if ($devCount > 0) {
            Database::update('cabinets', ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')], 'cabinet_id = :id', [':id' => $id]);
        } else {
            Database::delete('cabinets', 'cabinet_id = ?', [$id]);
        }
        AuditService::log((int)$user['user_id'], $user['username'], 'delete', 'cabinet', $id);
        App::json(['ok' => true]);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API cabinets: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
