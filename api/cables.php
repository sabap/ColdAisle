<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$user = AuthManager::user();

try {
    $entity = strtolower(trim((string)($_GET['entity'] ?? '')));

    // --- Raceways / pathways ---
    if ($entity === 'paths') {
        if ($method === 'GET') {
            $roomId = (int)($_GET['room_id'] ?? 0);
            if ($roomId > 0 && class_exists('CablePlantService')) {
                App::json(['paths' => CablePlantService::pathsForRoom($roomId, empty($_GET['all']))]);
            }
            $rows = Database::fetchAll('SELECT * FROM cable_paths ORDER BY name');
            if (class_exists('CablePlantService')) {
                foreach ($rows as &$r) {
                    $r['waypoints_list'] = CablePlantService::parseWaypoints($r['waypoints'] ?? null);
                }
                unset($r);
            }
            App::json(['paths' => $rows]);
        }

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            api_require_csrf();
            if (!AuthManager::can($user, 'edit_cables')
                && !AuthManager::can($user, 'edit_infrastructure')
                && !AuthManager::isAdmin($user)
            ) {
                App::json(['error' => 'Forbidden'], 403);
            }
            $d = api_read_json();
            $pathId = (int)($d['path_id'] ?? $_GET['id'] ?? 0);
            if (!class_exists('CablePlantService')) {
                App::json(['error' => 'CablePlantService unavailable'], 500);
            }
            $res = CablePlantService::savePath($d, $pathId > 0 ? $pathId : null);
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

        if ($method === 'DELETE') {
            api_require_csrf();
            if (!AuthManager::can($user, 'edit_cables')
                && !AuthManager::can($user, 'edit_infrastructure')
                && !AuthManager::isAdmin($user)
            ) {
                App::json(['error' => 'Forbidden'], 403);
            }
            $id = (int)($_GET['id'] ?? 0);
            $force = !empty($_GET['force']);
            if (class_exists('CablePlantService')) {
                $res = CablePlantService::deletePath($id, $force);
                if (empty($res['ok'])) {
                    App::json(['error' => $res['message'] ?? 'Delete failed'], 409);
                }
                App::json(['ok' => true, 'message' => $res['message'], 'unlinked' => (int)($res['unlinked'] ?? 0)]);
            }
            $inUse = (int)Database::fetchValue('SELECT COUNT(*) FROM cables WHERE path_id = ?', [$id]);
            if ($inUse > 0) {
                App::json(['error' => "Path used by {$inUse} cable(s)"], 409);
            }
            Database::delete('cable_paths', 'path_id = ?', [$id]);
            App::json(['ok' => true]);
        }

        App::json(['error' => 'Method not allowed'], 405);
    }

    // --- Cables ---
    if ($method === 'GET') {
        App::json(['cables' => Database::fetchAll(
            'SELECT c.*,
                pa.label AS a_label, da.label AS a_device,
                pb.label AS b_label, db.label AS b_device,
                cp.name AS path_name, cp.color_hex AS path_color
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
        if (!AuthManager::can($user, 'edit_cables')
            && !AuthManager::can($user, 'edit_infrastructure')
            && !AuthManager::isAdmin($user)
        ) {
            App::json(['error' => 'Forbidden'], 403);
        }
        $d = api_read_json();
        $fields = class_exists('CablePlantService')
            ? CablePlantService::cableFieldsFromInput($d)
            : [
                'cable_label' => $d['cable_label'] ?? null,
                'media_type' => $d['media_type'] ?? null,
                'length_m' => $d['length_m'] ?? null,
                'color' => $d['color'] ?? null,
                'a_port_id' => $d['a_port_id'] ?? null,
                'b_port_id' => $d['b_port_id'] ?? null,
                'path_id' => $d['path_id'] ?? null,
                'status' => $d['status'] ?? 'active',
                'notes' => $d['notes'] ?? null,
            ];
        $fields['installed_at'] = date('Y-m-d H:i:s');
        $id = Database::insert('cables', $fields);
        AuditService::log((int)$user['user_id'], $user['username'], 'create', 'cable', $id);
        App::json(['cable' => Database::fetchOne('SELECT * FROM cables WHERE cable_id = ?', [$id])], 201);
    }

    if ($method === 'DELETE') {
        api_require_csrf();
        $id = (int)($_GET['id'] ?? 0);
        Database::delete('cables', 'cable_id = ?', [$id]);
        App::json(['ok' => true]);
    }

    App::json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    App::json(['error' => $e->getMessage()], 500);
}
