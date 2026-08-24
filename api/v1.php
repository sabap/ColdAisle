<?php
/**
 * External machine API (service accounts + Bearer tokens).
 *
 * GET  /api/v1.php                  status
 * GET  /api/v1.php/cabinets
 * GET  /api/v1.php/devices[?cabinet_id=]
 * GET  /api/v1.php/devices/{id}
 * GET  /api/v1.php/work_orders[?status=]
 * GET  /api/v1.php/work_orders/{id}
 *
 * Auth: Authorization: Bearer ca_live_…
 * This endpoint does not use browser sessions or CSRF.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    App::json(['error' => 'Not installed'], 503);
}

if (!class_exists('ApiTokenService')) {
    App::json(['error' => 'API tokens are not deployed on this host'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    header('Allow: GET, HEAD, OPTIONS');
    exit;
}

$user = ApiTokenService::requireToken();

$v1Need = static function (string $perm) use ($user): void {
    if (!AuthManager::can($user, $perm)) {
        App::json(['error' => 'Forbidden', 'permission' => $perm], 403);
    }
};

$path = (string)($_SERVER['PATH_INFO'] ?? '');
if ($path === '') {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match('#/api/v1\.php(/.*)?#', $uri, $m)) {
        $path = (string)($m[1] ?? '');
        $qpos = strpos($path, '?');
        if ($qpos !== false) {
            $path = substr($path, 0, $qpos);
        }
    }
}
$path = '/' . trim($path, '/');
if ($path === '/') {
    $path = '';
}

$parts = $path === '' ? [] : explode('/', trim($path, '/'));
$resource = strtolower((string)($parts[0] ?? ''));
$id = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : 0;

if ($method !== 'GET' && $method !== 'HEAD') {
    App::json(['error' => 'This version of the API is read-only', 'hint' => 'Use GET'], 405);
}

try {
    if ($resource === '') {
        App::json([
            'ok' => true,
            'api' => 'coldaisle',
            'version' => App::VERSION,
            'account' => (string)$user['username'],
            'role' => (string)($user['role_name'] ?? ''),
            'scopes' => ApiTokenService::tokenScopes() ?: 'read',
            'resources' => ['cabinets', 'devices', 'work_orders'],
        ]);
    }

    if ($resource === 'cabinets') {
        $v1Need('view_cabinets');
        if ($id > 0) {
            $row = Database::fetchOne(
                'SELECT c.*, r.name AS row_name, rm.name AS room_name
                 FROM cabinets c
                 LEFT JOIN rows r ON r.row_id = c.row_id
                 LEFT JOIN rooms rm ON rm.room_id = c.room_id
                 WHERE c.cabinet_id = ?',
                [$id]
            );
            if (!$row) {
                App::json(['error' => 'Not found'], 404);
            }
            App::json(['cabinet' => ApiTokenService::stripSecrets($row)]);
        }
        $rows = Database::fetchAll(
            'SELECT c.cabinet_id, c.name, c.u_height, c.is_active, r.name AS row_name, rm.name AS room_name
             FROM cabinets c
             LEFT JOIN rows r ON r.row_id = c.row_id
             LEFT JOIN rooms rm ON rm.room_id = c.room_id
             ORDER BY c.name'
        );
        App::json(['cabinets' => $rows]);
    }

    if ($resource === 'devices') {
        $v1Need('view_devices');
        if ($id > 0) {
            $dev = Database::fetchOne(
                'SELECT d.device_id, d.label, d.serial_no, d.asset_tag, d.cabinet_id, d.position_u, d.u_height,
                        d.device_type, d.manufacturer, d.model, d.is_active, d.primary_ip, c.name AS cabinet_name
                 FROM devices d
                 LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                 WHERE d.device_id = ?',
                [$id]
            );
            if (!$dev) {
                App::json(['error' => 'Not found'], 404);
            }
            App::json(['device' => ApiTokenService::stripSecrets($dev)]);
        }
        $sql = 'SELECT d.device_id, d.label, d.serial_no, d.asset_tag, d.cabinet_id, d.position_u, d.u_height,
                       d.device_type, d.manufacturer, d.model, d.is_active, c.name AS cabinet_name
                FROM devices d
                LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                WHERE d.is_active = 1';
        $params = [];
        $cab = isset($_GET['cabinet_id']) ? (int)$_GET['cabinet_id'] : 0;
        if ($cab > 0) {
            $sql .= ' AND d.cabinet_id = ?';
            $params[] = $cab;
        }
        $sql .= ' ORDER BY d.label';
        App::json(['devices' => Database::fetchAll($sql, $params)]);
    }

    if ($resource === 'work_orders' || $resource === 'work-orders') {
        $v1Need('view_work_orders');
        if ($id > 0) {
            $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$id]);
            if (!$wo) {
                App::json(['error' => 'Not found'], 404);
            }
            $items = [];
            try {
                $items = Database::fetchAll(
                    'SELECT item_id, work_order_id, device_id, from_cabinet_id, from_position_u,
                            to_cabinet_id, to_position_u, item_status, sort_order
                     FROM work_order_items WHERE work_order_id = ? ORDER BY sort_order, item_id',
                    [$id]
                );
            } catch (Throwable $e) {
                $items = [];
            }
            App::json(['work_order' => $wo, 'items' => $items]);
        }
        $sql = 'SELECT work_order_id, title, work_type, status, change_ticket, scheduled_date,
                       itsm_provider, itsm_display_id, updated_at
                FROM work_orders WHERE 1=1';
        $params = [];
        $st = strtolower(trim((string)($_GET['status'] ?? '')));
        if ($st !== '') {
            $sql .= ' AND status = ?';
            $params[] = $st;
        }
        $sql .= ' ORDER BY updated_at DESC';
        try {
            App::json(['work_orders' => Database::fetchAll($sql, $params)]);
        } catch (Throwable $e) {
            $sql = 'SELECT work_order_id, title, work_type, status, change_ticket, scheduled_date, updated_at
                    FROM work_orders ORDER BY updated_at DESC';
            App::json(['work_orders' => Database::fetchAll($sql)]);
        }
    }

    App::json(['error' => 'Unknown resource', 'resource' => $resource], 404);
} catch (Throwable $e) {
    App::log('API v1: ' . $e->getMessage(), 'error');
    App::json(['error' => $e->getMessage()], 500);
}
