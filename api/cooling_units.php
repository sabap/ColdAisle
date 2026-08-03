<?php
/**
 * ColdAisle - Cooling units API
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/cooling_helpers.php';

$method = api_method();
$user = AuthManager::user();

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    api_require_permission('edit_cooling');
    api_require_csrf();
} else {
    api_require_permission('view_cooling');
}

function cooling_unit_fetch(int $id): ?array
{
    return Database::fetchOne(
        'SELECT u.*,
                rm.name AS room_name, dc.name AS dc_name,
                primary_u.name AS primary_unit_name
         FROM cooling_units u
         LEFT JOIN rooms rm ON rm.room_id = u.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         LEFT JOIN cooling_units primary_u ON primary_u.cooling_unit_id = u.standby_of_id
         WHERE u.cooling_unit_id = ?',
        [$id]
    );
}

try {
    if ($method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $row = cooling_unit_fetch($id);
            if (!$row || empty($row['is_active'])) {
                App::json(['error' => 'Not found'], 404);
            }
            App::json(['cooling_unit' => $row]);
        }
        $roomId = (int)($_GET['room_id'] ?? 0);
        $sql = 'SELECT u.*, rm.name AS room_name, dc.name AS dc_name
                FROM cooling_units u
                LEFT JOIN rooms rm ON rm.room_id = u.room_id
                LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
                WHERE u.is_active = 1';
        $params = [];
        if ($roomId > 0) {
            $sql .= ' AND u.room_id = ?';
            $params[] = $roomId;
        }
        $sql .= ' ORDER BY u.name';
        App::json(['cooling_units' => Database::fetchAll($sql, $params)]);
    }

    if ($method === 'POST') {
        $data = api_read_json();
        $action = (string)($_GET['action'] ?? ($data['action'] ?? 'create'));

        if ($action === 'create' || $action === '') {
            $row = cooling_unit_fields_from_post($data);
            if ($row['name'] === '') {
                App::json(['error' => 'name required'], 400);
            }
            $row = cooling_unit_finalize_snmp($row, null);
            $id = Database::insert('cooling_units', $row);
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'create', 'cooling_unit', (int)$id, [
                'name' => $row['name'],
            ]);
            App::json(['cooling_unit' => cooling_unit_fetch((int)$id)], 201);
        }

        if ($action === 'update') {
            $id = (int)($data['cooling_unit_id'] ?? $data['id'] ?? 0);
            if ($id <= 0) {
                App::json(['error' => 'cooling_unit_id required'], 400);
            }
            $existing = Database::fetchOne(
                'SELECT * FROM cooling_units WHERE cooling_unit_id = ? AND is_active = 1',
                [$id]
            );
            if (!$existing) {
                App::json(['error' => 'Not found'], 404);
            }
            $row = cooling_unit_fields_from_post($data);
            if ($row['name'] === '') {
                App::json(['error' => 'name required'], 400);
            }
            if ($row['standby_of_id'] === $id) {
                $row['standby_of_id'] = null;
            }
            $row = cooling_unit_finalize_snmp($row, $existing);
            $row['updated_at'] = date('Y-m-d H:i:s');
            Database::update('cooling_units', $row, 'cooling_unit_id = :id', [':id' => $id]);
            App::json(['cooling_unit' => cooling_unit_fetch($id)]);
        }

        if ($action === 'delete' || $action === 'deactivate') {
            $id = (int)($data['cooling_unit_id'] ?? $data['id'] ?? 0);
            if ($id <= 0) {
                App::json(['error' => 'cooling_unit_id required'], 400);
            }
            Database::update('cooling_units', [
                'is_active' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'cooling_unit_id = :id', [':id' => $id]);
            App::json(['ok' => true, 'cooling_unit_id' => $id]);
        }

        App::json(['error' => 'Unknown action'], 400);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API cooling_units: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
