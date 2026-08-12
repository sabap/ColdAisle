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

    // --- Port-to-port connection (from device UI) ---
    if ($entity === 'connection') {
        if (!class_exists('CableRouteService')) {
            App::json(['error' => 'CableRouteService unavailable'], 500);
        }
        if ($method === 'POST') {
            api_require_csrf();
            if (!AuthManager::can($user, 'edit_cables')
                && !AuthManager::can($user, 'edit_infrastructure')
                && !AuthManager::canManageDevices($user)
                && !AuthManager::isAdmin($user)
            ) {
                App::json(['error' => 'Forbidden'], 403);
            }
            $d = api_read_json();
            $action = (string)($d['action'] ?? 'upsert');
            if ($action === 'disconnect') {
                $portId = (int)($d['port_id'] ?? $d['local_port_id'] ?? 0);
                $res = CableRouteService::disconnectPort($portId);
                if (empty($res['ok'])) {
                    App::json(['error' => $res['message'] ?? 'Disconnect failed'], 400);
                }
                if (class_exists('AuditService')) {
                    AuditService::log(
                        (int)$user['user_id'],
                        $user['username'] ?? '',
                        'delete',
                        'cable',
                        (int)($res['cable_id'] ?? 0)
                    );
                }
                App::json($res);
            }
            // upsert (default)
            $localPort = (int)($d['local_port_id'] ?? $d['port_id'] ?? $d['a_port_id'] ?? 0);
            $peerPort = (int)($d['peer_port_id'] ?? $d['b_port_id'] ?? 0);
            $res = CableRouteService::upsertPortConnection($localPort, $peerPort, $d);
            if (empty($res['ok'])) {
                App::json(['error' => $res['message'] ?? 'Connect failed'], 400);
            }
            if (class_exists('AuditService')) {
                AuditService::log(
                    (int)$user['user_id'],
                    $user['username'] ?? '',
                    !empty($res['created']) ? 'create' : 'update',
                    'cable',
                    (int)($res['cable_id'] ?? 0)
                );
            }
            // Full route payload for UI refresh
            if (!empty($res['cable_id'])) {
                $full = CableRouteService::routeForCable((int)$res['cable_id'], false);
                $res['route'] = $full['route'] ?? null;
            }
            App::json($res);
        }
        App::json(['error' => 'Method not allowed'], 405);
    }

    // --- Multi-hop raceway routes ---
    if ($entity === 'routes') {
        if (!class_exists('CableRouteService')) {
            App::json(['error' => 'CableRouteService unavailable'], 500);
        }
        if ($method === 'GET') {
            $cableId = (int)($_GET['cable_id'] ?? 0);
            $deviceId = (int)($_GET['device_id'] ?? 0);
            $calc = !empty($_GET['calculate']);
            $routeOpts = [];
            if (!empty($_GET['network']) || !empty($_GET['raceway_network'])) {
                $routeOpts['network'] = (string)($_GET['network'] ?? $_GET['raceway_network']);
            }
            if (!empty($_GET['media_class'])) {
                $routeOpts['media_class'] = (string)$_GET['media_class'];
            }
            if ($cableId > 0) {
                $res = CableRouteService::routeForCable($cableId, $calc, $routeOpts);
                if (empty($res['ok'])) {
                    App::json(['error' => $res['message'] ?? 'Route failed'], 400);
                }
                App::json($res);
            }
            if ($deviceId > 0) {
                $res = CableRouteService::routesForDevice($deviceId, $calc, $routeOpts);
                App::json($res);
            }
            App::json(['error' => 'cable_id or device_id required'], 400);
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
            $action = (string)($d['action'] ?? 'calculate');
            if ($action === 'calculate') {
                $from = (int)($d['from_cabinet_id'] ?? $d['cabinet_a'] ?? 0);
                $to = (int)($d['to_cabinet_id'] ?? $d['cabinet_b'] ?? 0);
                $routeOpts = [];
                if (!empty($d['network']) || !empty($d['raceway_network'])) {
                    $routeOpts['network'] = (string)($d['network'] ?? $d['raceway_network']);
                }
                if (!empty($d['media_class'])) {
                    $routeOpts['media_class'] = (string)$d['media_class'];
                }
                if (isset($d['path_kinds']) && is_array($d['path_kinds'])) {
                    $routeOpts['path_kinds'] = $d['path_kinds'];
                }
                $res = CableRouteService::calculateBetweenCabinets($from, $to, $routeOpts);
                if (empty($res['ok'])) {
                    App::json(['error' => $res['message'] ?? 'No route'], 400);
                }
                App::json($res);
            }
            if ($action === 'apply') {
                $cableId = (int)($d['cable_id'] ?? 0);
                $pathIds = $d['path_ids'] ?? [];
                if (!is_array($pathIds)) {
                    $pathIds = [];
                }
                $source = (string)($d['source'] ?? 'manual');
                $res = CableRouteService::applyRouteToCable($cableId, $pathIds, $source);
                if (empty($res['ok'])) {
                    App::json(['error' => $res['message'] ?? 'Apply failed'], 400);
                }
                App::json($res);
            }
            if ($action === 'calculate_and_apply') {
                $cableId = (int)($d['cable_id'] ?? 0);
                $from = (int)($d['from_cabinet_id'] ?? 0);
                $to = (int)($d['to_cabinet_id'] ?? 0);
                if ($from < 1 || $to < 1) {
                    // Derive from cable ends
                    $r0 = CableRouteService::routeForCable($cableId, false);
                    if (!empty($r0['route']['a']['cabinet_id'])) {
                        $from = (int)$r0['route']['a']['cabinet_id'];
                    }
                    if (!empty($r0['route']['b']['cabinet_id'])) {
                        $to = (int)$r0['route']['b']['cabinet_id'];
                    }
                }
                $routeOpts = [];
                if (!empty($d['network']) || !empty($d['raceway_network'])) {
                    $routeOpts['network'] = (string)($d['network'] ?? $d['raceway_network']);
                }
                if (!empty($d['media_class'])) {
                    $routeOpts['media_class'] = (string)$d['media_class'];
                }
                if (isset($d['path_kinds']) && is_array($d['path_kinds'])) {
                    $routeOpts['path_kinds'] = $d['path_kinds'];
                }
                $calc = CableRouteService::calculateBetweenCabinets($from, $to, $routeOpts);
                if (empty($calc['ok'])) {
                    App::json(['error' => $calc['message'] ?? 'No route'], 400);
                }
                $res = CableRouteService::applyRouteToCable(
                    $cableId,
                    $calc['path_ids'] ?? [],
                    'calculated'
                );
                if (empty($res['ok'])) {
                    App::json(['error' => $res['message'] ?? 'Apply failed'], 400);
                }
                $full = CableRouteService::routeForCable($cableId, false);
                App::json([
                    'ok' => true,
                    'message' => $calc['message'] ?? 'Route applied',
                    'path_ids' => $calc['path_ids'] ?? [],
                    'route' => $full['route'] ?? null,
                ]);
            }
            App::json(['error' => 'Unknown action'], 400);
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
