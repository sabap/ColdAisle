<?php
/**
 * ColdAisle — Cabinet rows API (room-scoped rows; optional zone link)
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

try {
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        api_require_permission('edit_infrastructure');
    }
    if ($method === 'GET') {
        $roomId = (int)($_GET['room_id'] ?? 0);
        $rowId = (int)($_GET['id'] ?? 0);
        if ($rowId) {
            $row = Database::fetchOne(
                'SELECT cr.*, r.name AS room_name,
                        (SELECT COUNT(*) FROM cabinets c WHERE c.row_id = cr.row_id AND c.is_active = 1) AS cabinet_count
                 FROM cabinet_rows cr
                 INNER JOIN rooms r ON r.room_id = cr.room_id
                 WHERE cr.row_id = ?',
                [$rowId]
            );
            if (!$row) {
                App::json(['error' => 'Row not found'], 404);
            }
            App::json(['row' => $row]);
        }
        if (!$roomId) {
            App::json(['error' => 'room_id required'], 400);
        }
        $rows = Database::fetchAll(
            'SELECT cr.*,
                    (SELECT COUNT(*) FROM cabinets c WHERE c.row_id = cr.row_id AND c.is_active = 1) AS cabinet_count
             FROM cabinet_rows cr
             WHERE cr.room_id = ?
             ORDER BY cr.name',
            [$roomId]
        );
        App::json(['rows' => $rows]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $data = api_read_json();
        $roomId = (int)($data['room_id'] ?? 0);
        if (!$roomId) {
            App::json(['error' => 'room_id required'], 400);
        }
        $room = Database::fetchOne('SELECT room_id, datacenter_id FROM rooms WHERE room_id = ?', [$roomId]);
        if (!$room) {
            App::json(['error' => 'Room not found'], 404);
        }
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            // Auto-name: Row A, Row B, …
            $count = (int)Database::fetchValue(
                'SELECT COUNT(*) FROM cabinet_rows WHERE room_id = ?',
                [$roomId]
            );
            $name = 'Row ' . chr(65 + min($count, 25));
        }
        $row = [
            'room_id' => $roomId,
            'name' => $name,
            'data_center_id' => (int)$room['datacenter_id'],
            'pos_x' => (float)($data['pos_x'] ?? 0),
            'pos_y' => (float)($data['pos_y'] ?? 0),
            'rotation_deg' => (float)($data['rotation_deg'] ?? 0),
        ];
        if (array_key_exists('zone_id', $data)) {
            $row['zone_id'] = $data['zone_id'] !== null && $data['zone_id'] !== ''
                ? (int)$data['zone_id'] : null;
        }
        if (!empty($data['color_hex']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$data['color_hex'])) {
            $row['color_hex'] = (string)$data['color_hex'];
        }
        if (!empty($data['notes'])) {
            $row['notes'] = (string)$data['notes'];
        }
        $id = Database::insert('cabinet_rows', $row);
        if (!$id) {
            $created = Database::fetchOne(
                'SELECT TOP 1 * FROM cabinet_rows WHERE room_id = ? AND name = ? ORDER BY row_id DESC',
                [$roomId, $name]
            );
            $id = $created ? (int)$created['row_id'] : 0;
        } else {
            $created = Database::fetchOne('SELECT * FROM cabinet_rows WHERE row_id = ?', [$id]);
        }
        AuditService::log((int)$user['user_id'], $user['username'], 'create', 'cabinet_row', $id, ['name' => $name]);
        App::json(['row' => $created], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        api_require_csrf();
        $data = api_read_json();
        $id = (int)($data['row_id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            App::json(['error' => 'row_id required'], 400);
        }
        $fields = [];
        foreach (['name', 'notes', 'pos_x', 'pos_y', 'rotation_deg', 'color_hex'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[$k] = $data[$k];
            }
        }
        if (array_key_exists('zone_id', $data)) {
            $fields['zone_id'] = $data['zone_id'] !== null && $data['zone_id'] !== ''
                ? (int)$data['zone_id'] : null;
        }
        if (!$fields) {
            App::json(['error' => 'No fields to update'], 400);
        }
        Database::update('cabinet_rows', $fields, 'row_id = :id', [':id' => $id]);
        $row = Database::fetchOne('SELECT * FROM cabinet_rows WHERE row_id = ?', [$id]);
        AuditService::log((int)$user['user_id'], $user['username'], 'update', 'cabinet_row', $id, $fields);
        App::json(['row' => $row]);
    }

    if ($method === 'DELETE') {
        api_require_csrf();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            App::json(['error' => 'id required'], 400);
        }
        // Unassign cabinets first
        Database::query('UPDATE cabinets SET row_id = NULL WHERE row_id = ?', [$id]);
        Database::delete('cabinet_rows', 'row_id = ?', [$id]);
        AuditService::log((int)$user['user_id'], $user['username'], 'delete', 'cabinet_row', $id);
        App::json(['ok' => true]);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API rows: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
