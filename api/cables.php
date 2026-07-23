<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

try {
    if ($method === 'GET') {
        App::json(['cables' => Database::fetchAll(
            'SELECT c.*,
                pa.label AS a_label, da.label AS a_device,
                pb.label AS b_label, db.label AS b_device,
                cp.name AS path_name
             FROM cables c
             LEFT JOIN device_ports pa ON pa.port_id = c.a_port_id
             LEFT JOIN devices da ON da.device_id = pa.device_id
             LEFT JOIN device_ports pb ON pb.port_id = c.b_port_id
             LEFT JOIN devices db ON db.device_id = pb.device_id
             LEFT JOIN cable_paths cp ON cp.path_id = c.path_id
             ORDER BY c.cable_id DESC'
        )]);
    }

    if ($method === 'POST') {
        api_require_csrf();
        $d = api_read_json();
        $id = Database::insert('cables', [
            'cable_label' => $d['cable_label'] ?? null,
            'media_type' => $d['media_type'] ?? null,
            'length_m' => $d['length_m'] ?? null,
            'color' => $d['color'] ?? null,
            'a_port_id' => $d['a_port_id'] ?? null,
            'b_port_id' => $d['b_port_id'] ?? null,
            'path_id' => $d['path_id'] ?? null,
            'status' => $d['status'] ?? 'active',
            'notes' => $d['notes'] ?? null,
            'installed_at' => date('Y-m-d H:i:s'),
        ]);
        AuditService::log((int)$user['user_id'], $user['username'], 'create', 'cable', $id);
        App::json(['cable' => Database::fetchOne('SELECT * FROM cables WHERE cable_id = ?', [$id])], 201);
    }

    if ($method === 'POST' && ($_GET['action'] ?? '') === 'path') {
        // handled below via entity
    }

    if ($method === 'DELETE') {
        api_require_csrf();
        $id = (int)($_GET['id'] ?? 0);
        Database::delete('cables', 'cable_id = ?', [$id]);
        App::json(['ok' => true]);
    }

    // Cable paths
    if (($_GET['entity'] ?? '') === 'paths') {
        if ($method === 'GET') {
            App::json(['paths' => Database::fetchAll('SELECT * FROM cable_paths ORDER BY name')]);
        }
        if ($method === 'POST') {
            api_require_csrf();
            $d = api_read_json();
            $id = Database::insert('cable_paths', [
                'room_id' => $d['room_id'] ?? null,
                'name' => trim($d['name'] ?? 'Path'),
                'path_type' => $d['path_type'] ?? 'overhead',
                'waypoints' => isset($d['waypoints']) ? json_encode($d['waypoints']) : null,
                'color_hex' => $d['color_hex'] ?? '#38bdf8',
                'notes' => $d['notes'] ?? null,
            ]);
            App::json(['path' => Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [$id])], 201);
        }
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::json(['error' => $e->getMessage()], 500);
}
