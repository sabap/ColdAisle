<?php
/**
 * External machine API (service accounts + Bearer tokens).
 *
 * GET    /api/v1.php
 * GET    /api/v1.php/cabinets[?q=&page=&per_page=]
 * GET    /api/v1.php/cabinets/{id}
 * GET    /api/v1.php/devices[?cabinet_id=&q=&page=&per_page=]
 * GET    /api/v1.php/devices/{id}
 * PATCH  /api/v1.php/devices/{id}                 write scope
 * POST   /api/v1.php/devices/{id}/notes           write scope
 * GET    /api/v1.php/pdus[?q=&zone_id=&page=]
 * GET    /api/v1.php/pdus/{id}
 * GET    /api/v1.php/ups[?q=&page=]
 * GET    /api/v1.php/ups/{id}
 * GET    /api/v1.php/work_orders[?status=&q=&page=]
 * GET    /api/v1.php/work_orders/{id}
 * POST   /api/v1.php/work_orders                  write scope
 * PATCH  /api/v1.php/work_orders/{id}             write scope
 * POST   /api/v1.php/work_orders/{id}/items       write scope
 *
 * Auth: Authorization: Bearer ca_live_…
 * This endpoint does not use browser sessions or CSRF.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/work_order_helpers.php';
App::boot();

if (!App::isInstalled()) {
    App::json(['error' => 'Not installed'], 503);
}

if (!class_exists('ApiTokenService') || !class_exists('ApiV1Service')) {
    App::json(['error' => 'API tokens are not deployed on this host'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    header('Allow: GET, HEAD, POST, PATCH, OPTIONS');
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
$sub = strtolower((string)($parts[2] ?? ''));
$subId = isset($parts[3]) && ctype_digit($parts[3]) ? (int)$parts[3] : 0;

if ($resource === 'work-orders') {
    $resource = 'work_orders';
}
if ($resource === 'pdu') {
    $resource = 'pdus';
}

$allowed = ['GET', 'HEAD', 'POST', 'PATCH'];
if (!in_array($method, $allowed, true)) {
    App::json(['error' => 'Method not allowed', 'hint' => 'Use GET, POST, or PATCH'], 405);
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
            'resources' => ApiV1Service::RESOURCES,
            'writes' => ApiTokenService::hasWriteScope(),
            'pagination' => ['page' => 1, 'per_page' => ApiV1Service::DEFAULT_PER, 'max_per_page' => ApiV1Service::MAX_PER],
        ]);
    }

    // ---- Cabinets ----
    if ($resource === 'cabinets') {
        $v1Need('view_cabinets');
        if ($method !== 'GET' && $method !== 'HEAD') {
            App::json(['error' => 'Cabinets are read-only in v1'], 405);
        }
        if ($id > 0) {
            $row = Database::fetchOne(
                'SELECT c.*, cr.name AS row_name, rm.name AS room_name
                 FROM cabinets c
                 LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
                 LEFT JOIN rooms rm ON rm.room_id = c.room_id
                 WHERE c.cabinet_id = ?',
                [$id]
            );
            if (!$row) {
                App::json(['error' => 'Not found'], 404);
            }
            App::json(['cabinet' => ApiV1Service::jsonRow($row)]);
        }
        $sql = 'SELECT c.cabinet_id, c.name, c.u_height, c.is_active, c.location_tag, c.room_id, c.row_id,
                       cr.name AS row_name, rm.name AS room_name
                FROM cabinets c
                LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
                LEFT JOIN rooms rm ON rm.room_id = c.room_id
                WHERE 1=1';
        $params = [];
        $q = ApiV1Service::q();
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (c.name LIKE ? OR ISNULL(c.location_tag, \'\') LIKE ?
                         OR ISNULL(cr.name, \'\') LIKE ? OR ISNULL(rm.name, \'\') LIKE ?
                         OR CAST(c.cabinet_id AS NVARCHAR(20)) = ?)';
            array_push($params, $like, $like, $like, $like, $q);
        }
        $sql .= ' ORDER BY c.name';
        $page = ApiV1Service::paginate($sql, $params);
        App::json(ApiV1Service::listPayload('cabinets', $page['rows'], $page['total'], $page['page']));
    }

    // ---- Devices ----
    if ($resource === 'devices') {
        if ($method === 'POST' && $id > 0 && $sub === 'notes') {
            $dev = Database::fetchOne('SELECT * FROM devices WHERE device_id = ?', [$id]);
            if (!$dev) {
                App::json(['error' => 'Not found'], 404);
            }
            App::json(['note' => ApiV1Service::addDeviceNote($user, $dev, ApiV1Service::readJson())], 201);
        }
        if ($method === 'PATCH' && $id > 0 && $sub === '') {
            $dev = Database::fetchOne('SELECT * FROM devices WHERE device_id = ?', [$id]);
            if (!$dev) {
                App::json(['error' => 'Not found'], 404);
            }
            App::json(['device' => ApiV1Service::patchDevice($user, $dev, ApiV1Service::readJson())]);
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            App::json(['error' => 'Method not allowed on this path'], 405);
        }
        $v1Need('view_devices');
        if ($id > 0) {
            $dev = Database::fetchOne(
                'SELECT d.device_id, d.label, d.serial_no, d.asset_tag, d.cabinet_id, d.position_u, d.u_height,
                        d.device_type, d.manufacturer, d.model, d.is_active, d.primary_ip, d.mgmt_ip, d.hostname,
                        d.status, c.name AS cabinet_name
                 FROM devices d
                 LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                 WHERE d.device_id = ?',
                [$id]
            );
            if (!$dev) {
                App::json(['error' => 'Not found'], 404);
            }
            App::json(['device' => ApiV1Service::jsonRow($dev)]);
        }
        $sql = 'SELECT d.device_id, d.label, d.serial_no, d.asset_tag, d.cabinet_id, d.position_u, d.u_height,
                       d.device_type, d.manufacturer, d.model, d.is_active, d.primary_ip, d.status,
                       c.name AS cabinet_name
                FROM devices d
                LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                WHERE d.is_active = 1';
        $params = [];
        $cab = isset($_GET['cabinet_id']) ? (int)$_GET['cabinet_id'] : 0;
        if ($cab > 0) {
            $sql .= ' AND d.cabinet_id = ?';
            $params[] = $cab;
        }
        $q = ApiV1Service::q();
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (d.label LIKE ? OR ISNULL(d.hostname, \'\') LIKE ? OR ISNULL(d.serial_no, \'\') LIKE ?
                         OR ISNULL(d.asset_tag, \'\') LIKE ? OR ISNULL(d.primary_ip, \'\') LIKE ?
                         OR CAST(d.device_id AS NVARCHAR(20)) = ?)';
            array_push($params, $like, $like, $like, $like, $like, $q);
        }
        $sql .= ' ORDER BY d.label';
        $page = ApiV1Service::paginate($sql, $params);
        App::json(ApiV1Service::listPayload('devices', $page['rows'], $page['total'], $page['page']));
    }

    // ---- PDUs ----
    if ($resource === 'pdus') {
        $v1Need('view_power');
        if ($method !== 'GET' && $method !== 'HEAD') {
            App::json(['error' => 'PDUs are read-only in v1'], 405);
        }
        if ($id > 0) {
            $row = Database::fetchOne(
                'SELECT p.pdu_id, p.name, p.ip_address, p.pdu_scope, p.is_active, p.rated_amps, p.rated_volts,
                        p.last_poll_watts, p.last_poll_amps, p.last_poll_at, p.snmp_enabled, p.snmp_version,
                        p.cabinet_id, p.row_id, p.zone_id, p.serial_no, p.manufacturer, p.model,
                        c.name AS cabinet_name, r.name AS row_name, z.name AS zone_name
                 FROM pdus p
                 LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
                 LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
                 LEFT JOIN power_zones z ON z.zone_id = p.zone_id
                 WHERE p.pdu_id = ?',
                [$id]
            );
            if (!$row) {
                App::json(['error' => 'Not found'], 404);
            }
            App::json(['pdu' => ApiV1Service::jsonRow($row)]);
        }
        $sql = 'SELECT p.pdu_id, p.name, p.ip_address, p.pdu_scope, p.is_active, p.rated_amps,
                       p.last_poll_watts, p.snmp_enabled, p.cabinet_id, c.name AS cabinet_name,
                       r.name AS row_name, z.name AS zone_name
                FROM pdus p
                LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
                LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
                LEFT JOIN power_zones z ON z.zone_id = p.zone_id
                WHERE p.is_active = 1';
        $params = [];
        $zid = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;
        if ($zid > 0) {
            $sql .= ' AND p.zone_id = ?';
            $params[] = $zid;
        }
        $q = ApiV1Service::q();
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (p.name LIKE ? OR ISNULL(p.ip_address, \'\') LIKE ?
                         OR ISNULL(p.serial_no, \'\') LIKE ? OR ISNULL(c.name, \'\') LIKE ?
                         OR CAST(p.pdu_id AS NVARCHAR(20)) = ?)';
            array_push($params, $like, $like, $like, $like, $q);
        }
        $sql .= ' ORDER BY p.name';
        $page = ApiV1Service::paginate($sql, $params);
        App::json(ApiV1Service::listPayload('pdus', $page['rows'], $page['total'], $page['page']));
    }

    // ---- UPS ----
    if ($resource === 'ups') {
        $v1Need('view_power');
        if ($method !== 'GET' && $method !== 'HEAD') {
            App::json(['error' => 'UPS is read-only in v1'], 405);
        }
        if ($id > 0) {
            $row = Database::fetchOne(
                'SELECT u.ups_id, u.name, u.primary_ip, u.ups_scope, u.manufacturer, u.model, u.serial_no,
                        u.asset_tag, u.is_active, u.last_load_pct, u.last_battery_pct, u.last_output_status,
                        u.snmp_last_poll_at, u.rated_kva, u.rated_kw, u.room_id, u.zone_id,
                        rm.name AS room_name, z.name AS zone_name
                 FROM ups_units u
                 LEFT JOIN rooms rm ON rm.room_id = u.room_id
                 LEFT JOIN power_zones z ON z.zone_id = u.zone_id
                 WHERE u.ups_id = ?',
                [$id]
            );
            if (!$row) {
                App::json(['error' => 'Not found'], 404);
            }
            App::json(['ups' => ApiV1Service::jsonRow($row)]);
        }
        $sql = 'SELECT u.ups_id, u.name, u.primary_ip, u.ups_scope, u.manufacturer, u.model, u.is_active,
                       u.last_load_pct, u.last_battery_pct, rm.name AS room_name, z.name AS zone_name
                FROM ups_units u
                LEFT JOIN rooms rm ON rm.room_id = u.room_id
                LEFT JOIN power_zones z ON z.zone_id = u.zone_id
                WHERE u.is_active = 1';
        $params = [];
        $q = ApiV1Service::q();
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (u.name LIKE ? OR ISNULL(u.primary_ip, \'\') LIKE ?
                         OR ISNULL(u.serial_no, \'\') LIKE ? OR ISNULL(u.model, \'\') LIKE ?
                         OR CAST(u.ups_id AS NVARCHAR(20)) = ?)';
            array_push($params, $like, $like, $like, $like, $q);
        }
        $sql .= ' ORDER BY u.name';
        $page = ApiV1Service::paginate($sql, $params);
        App::json(ApiV1Service::listPayload('ups', $page['rows'], $page['total'], $page['page']));
    }

    // ---- Work orders ----
    if ($resource === 'work_orders') {
        if ($method === 'POST' && $id < 1) {
            App::json(ApiV1Service::createWorkOrder($user, ApiV1Service::readJson()), 201);
        }
        if ($method === 'POST' && $id > 0 && $sub === 'items') {
            App::json(ApiV1Service::addWorkOrderItem($user, $id, ApiV1Service::readJson()), 201);
        }
        if ($method === 'PATCH' && $id > 0 && $sub === '') {
            App::json(ApiV1Service::patchWorkOrder($user, $id, ApiV1Service::readJson()));
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            App::json(['error' => 'Method not allowed on this path'], 405);
        }
        $v1Need('view_work_orders');
        if ($id > 0) {
            App::json(ApiV1Service::loadWorkOrder($id));
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
        $q = ApiV1Service::q();
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (title LIKE ? OR ISNULL(change_ticket, \'\') LIKE ?
                         OR ISNULL(itsm_display_id, \'\') LIKE ?
                         OR CAST(work_order_id AS NVARCHAR(20)) = ?)';
            array_push($params, $like, $like, $like, $q);
        }
        $sql .= ' ORDER BY updated_at DESC';
        try {
            $page = ApiV1Service::paginate($sql, $params);
            App::json(ApiV1Service::listPayload('work_orders', $page['rows'], $page['total'], $page['page']));
        } catch (Throwable $e) {
            $sql = 'SELECT work_order_id, title, work_type, status, change_ticket, scheduled_date, updated_at
                    FROM work_orders ORDER BY updated_at DESC';
            $page = ApiV1Service::paginate($sql, []);
            App::json(ApiV1Service::listPayload('work_orders', $page['rows'], $page['total'], $page['page']));
        }
    }

    App::json(['error' => 'Unknown resource', 'resource' => $resource], 404);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $code = $e instanceof RuntimeException ? 400 : 500;
    App::log('API v1: ' . $msg, $code >= 500 ? 'error' : 'info');
    App::json(['error' => $msg], $code);
}
