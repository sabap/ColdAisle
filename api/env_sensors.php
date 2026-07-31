<?php
/**
 * ColdAisle - Environmental sensors API
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

function env_sensor_fetch(int $id): ?array
{
    return Database::fetchOne(
        'SELECT s.*,
                rm.name AS room_name,
                cu.name AS cooling_unit_name,
                p.name AS pdu_name
         FROM env_sensors s
         LEFT JOIN rooms rm ON rm.room_id = s.room_id
         LEFT JOIN cooling_units cu ON cu.cooling_unit_id = s.cooling_unit_id
         LEFT JOIN pdus p ON p.pdu_id = s.pdu_id
         WHERE s.sensor_id = ?',
        [$id]
    );
}

try {
    if ($method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $row = env_sensor_fetch($id);
            if (!$row || empty($row['is_active'])) {
                App::json(['error' => 'Not found'], 404);
            }
            $history = [];
            if (!empty($_GET['history'])) {
                $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
                $history = Database::fetchAll(
                    "SELECT TOP $limit reading_id, value, recorded_at
                     FROM env_readings WHERE sensor_id = ?
                     ORDER BY recorded_at DESC",
                    [$id]
                );
            }
            App::json(['sensor' => $row, 'readings' => $history]);
        }
        $sql = 'SELECT s.*, rm.name AS room_name, cu.name AS cooling_unit_name
                FROM env_sensors s
                LEFT JOIN rooms rm ON rm.room_id = s.room_id
                LEFT JOIN cooling_units cu ON cu.cooling_unit_id = s.cooling_unit_id
                WHERE s.is_active = 1';
        $params = [];
        if (!empty($_GET['cooling_unit_id'])) {
            $sql .= ' AND s.cooling_unit_id = ?';
            $params[] = (int)$_GET['cooling_unit_id'];
        }
        if (!empty($_GET['room_id'])) {
            $sql .= ' AND s.room_id = ?';
            $params[] = (int)$_GET['room_id'];
        }
        if (!empty($_GET['pdu_id'])) {
            $sql .= ' AND s.pdu_id = ?';
            $params[] = (int)$_GET['pdu_id'];
        }
        $sql .= ' ORDER BY s.name';
        App::json(['sensors' => Database::fetchAll($sql, $params)]);
    }

    if ($method === 'POST') {
        $data = api_read_json();
        $action = (string)($_GET['action'] ?? ($data['action'] ?? 'create'));

        if ($action === 'create' || $action === '') {
            $row = env_sensor_fields_from_post($data);
            if ($row['name'] === '') {
                App::json(['error' => 'name required'], 400);
            }
            $id = Database::insert('env_sensors', $row);
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'create', 'env_sensor', (int)$id, [
                'name' => $row['name'],
            ]);
            App::json(['sensor' => env_sensor_fetch((int)$id)], 201);
        }

        if ($action === 'update') {
            $id = (int)($data['sensor_id'] ?? $data['id'] ?? 0);
            if ($id <= 0) {
                App::json(['error' => 'sensor_id required'], 400);
            }
            $existing = Database::fetchOne(
                'SELECT sensor_id FROM env_sensors WHERE sensor_id = ? AND is_active = 1',
                [$id]
            );
            if (!$existing) {
                App::json(['error' => 'Not found'], 404);
            }
            $row = env_sensor_fields_from_post($data);
            if ($row['name'] === '') {
                App::json(['error' => 'name required'], 400);
            }
            $row['updated_at'] = date('Y-m-d H:i:s');
            Database::update('env_sensors', $row, 'sensor_id = :id', [':id' => $id]);
            App::json(['sensor' => env_sensor_fetch($id)]);
        }

        if ($action === 'reading' || $action === 'record_reading') {
            $id = (int)($data['sensor_id'] ?? $data['id'] ?? 0);
            $value = $data['value'] ?? null;
            if ($id <= 0 || $value === null || $value === '' || !is_numeric($value)) {
                App::json(['error' => 'sensor_id and numeric value required'], 400);
            }
            $v = (float)$value;
            Database::insert('env_readings', [
                'sensor_id' => $id,
                'value' => $v,
            ]);
            Database::update('env_sensors', [
                'last_value' => $v,
                'last_seen_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'sensor_id = :id', [':id' => $id]);
            App::json(['sensor' => env_sensor_fetch($id)]);
        }

        if ($action === 'delete' || $action === 'deactivate') {
            $id = (int)($data['sensor_id'] ?? $data['id'] ?? 0);
            if ($id <= 0) {
                App::json(['error' => 'sensor_id required'], 400);
            }
            Database::update('env_sensors', [
                'is_active' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'sensor_id = :id', [':id' => $id]);
            App::json(['ok' => true, 'sensor_id' => $id]);
        }

        App::json(['error' => 'Unknown action'], 400);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::log('API env_sensors: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
