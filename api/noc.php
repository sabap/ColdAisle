<?php
/**
 * Public NOC metrics + optional 3D scene (no login).
 * Optional shared secret: Settings → NOC (noc_access_token) as ?token= or X-NOC-Token.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
App::boot(['light' => true]);

if (!App::isInstalled()) {
    App::json(['error' => 'Not installed'], 503);
}

/**
 * Optional site token. Empty = open access (internal LAN / TV wall).
 */
function noc_check_access(): void
{
    $need = '';
    try {
        $need = trim((string)SettingsService::get('noc_access_token', ''));
    } catch (Throwable $e) {
        $need = '';
    }
    if ($need === '') {
        return;
    }
    $got = (string)($_GET['token'] ?? $_SERVER['HTTP_X_NOC_TOKEN'] ?? '');
    if ($got === '' || !hash_equals($need, $got)) {
        App::json(['error' => 'Forbidden — invalid or missing NOC token'], 403);
    }
}

noc_check_access();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    App::json(['error' => 'Method not allowed'], 405);
}

$includeScene = !empty($_GET['scene']) || (($_GET['include'] ?? '') === 'scene');

$metrics = [
    'sites' => 0,
    'datacenters' => 0,
    'rooms' => 0,
    'cabinets' => 0,
    'devices' => 0,
    'pdus' => 0,
    'ups_units' => 0,
    'cooling_units' => 0,
    'env_sensors' => 0,
    'open_disposals' => 0,
    'u_used' => 0,
    'u_total' => 0,
    'u_pct' => 0.0,
    'power_kw' => 0.0,
];

try {
    $metrics['sites'] = (int)Database::fetchValue('SELECT COUNT(*) FROM sites WHERE is_active = 1');
} catch (Throwable $e) {
}
try {
    $metrics['datacenters'] = (int)Database::fetchValue('SELECT COUNT(*) FROM datacenters WHERE is_active = 1');
    $metrics['rooms'] = (int)Database::fetchValue('SELECT COUNT(*) FROM rooms WHERE is_active = 1');
    $metrics['cabinets'] = (int)Database::fetchValue('SELECT COUNT(*) FROM cabinets WHERE is_active = 1');
    $metrics['devices'] = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM devices WHERE is_active = 1 AND status <> 'disposed'"
    );
    $metrics['pdus'] = (int)Database::fetchValue('SELECT COUNT(*) FROM pdus WHERE is_active = 1');
    $metrics['u_used'] = (int)Database::fetchValue(
        'SELECT ISNULL(SUM(u_height),0) FROM devices
         WHERE is_active = 1 AND cabinet_id IS NOT NULL AND position_u IS NOT NULL AND parent_device_id IS NULL'
    );
    $metrics['u_total'] = (int)Database::fetchValue(
        'SELECT ISNULL(SUM(u_height),0) FROM cabinets WHERE is_active = 1'
    );
    $metrics['u_pct'] = $metrics['u_total'] > 0
        ? round(100.0 * $metrics['u_used'] / $metrics['u_total'], 1)
        : 0.0;
    $metrics['power_kw'] = (float)Database::fetchValue(
        'SELECT ISNULL(SUM(last_poll_watts),0) / 1000.0 FROM pdus WHERE is_active = 1 AND last_poll_watts IS NOT NULL'
    );
    $metrics['open_disposals'] = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM disposals WHERE status IN ('pending','approved','in_progress')"
    );
} catch (Throwable $e) {
    App::log('NOC metrics core: ' . $e->getMessage(), 'warning');
}
try {
    $metrics['cooling_units'] = (int)Database::fetchValue(
        'SELECT COUNT(*) FROM cooling_units WHERE is_active = 1'
    );
} catch (Throwable $e) {
}
try {
    $metrics['ups_units'] = (int)Database::fetchValue(
        'SELECT COUNT(*) FROM ups_units WHERE is_active = 1'
    );
} catch (Throwable $e) {
}
try {
    $metrics['env_sensors'] = (int)Database::fetchValue(
        'SELECT COUNT(*) FROM env_sensors WHERE is_active = 1'
    );
} catch (Throwable $e) {
}

$env = ['ok' => 0, 'warn' => 0, 'crit' => 0, 'unknown' => 0, 'stale' => 0];
try {
    require_once dirname(__DIR__) . '/includes/cooling_helpers.php';
    $sensors = Database::fetchAll(
        'SELECT last_value, warn_low, warn_high, crit_low, crit_high, last_seen_at, sensor_kind
         FROM env_sensors WHERE is_active = 1'
    );
    $staleBefore = time() - 3600;
    foreach ($sensors as $s) {
        $val = isset($s['last_value']) && $s['last_value'] !== null && $s['last_value'] !== ''
            ? (float)$s['last_value'] : null;
        $st = function_exists('env_sensor_threshold_status')
            ? env_sensor_threshold_status($val, $s)
            : 'unknown';
        $env[$st] = ($env[$st] ?? 0) + 1;
        $seen = (string)($s['last_seen_at'] ?? '');
        if ($seen === '' || strtotime($seen) < $staleBefore) {
            $env['stale']++;
        }
    }
} catch (Throwable $e) {
    App::log('NOC env: ' . $e->getMessage(), 'warning');
}

$power = [
    'kw' => $metrics['power_kw'],
    'pdu_count' => $metrics['pdus'],
    'last_poll_at' => null,
];
try {
    $power['last_poll_at'] = Database::fetchValue(
        'SELECT MAX(last_poll_at) FROM pdus WHERE is_active = 1'
    );
} catch (Throwable $e) {
}

// 24h site power series for sparkline (downsampled)
$powerHistory = ['t' => [], 'kw' => [], 'points' => 0];
try {
    if (class_exists('PowerHistoryService')) {
        $series = PowerHistoryService::series('site', null, 24);
        $t = $series['series']['t'] ?? [];
        $kw = $series['series']['kw'] ?? [];
        $n = count($t);
        // Cap ~48 points for lightweight TV clients
        $step = $n > 48 ? (int)ceil($n / 48) : 1;
        for ($i = 0; $i < $n; $i += $step) {
            $powerHistory['t'][] = $t[$i];
            $powerHistory['kw'][] = $kw[$i] ?? null;
        }
        $powerHistory['points'] = count($powerHistory['t']);
        if (!empty($series['summary']['kw'])) {
            $power['kw_avg_24h'] = $series['summary']['kw']['avg'] ?? null;
            $power['kw_max_24h'] = $series['summary']['kw']['max'] ?? null;
            $power['kw_min_24h'] = $series['summary']['kw']['min'] ?? null;
        }
    }
} catch (Throwable $e) {
    App::log('NOC power history: ' . $e->getMessage(), 'warning');
}

// Power zones with live load
$zones = [];
try {
    $zones = Database::fetchAll(
        'SELECT z.zone_id, z.name, z.color_hex, z.feed_type, z.max_kw, z.voltage,
                dc.name AS dc_name,
                (SELECT COUNT(*) FROM pdus p WHERE p.zone_id = z.zone_id AND p.is_active = 1) AS pdu_count,
                (SELECT ISNULL(SUM(p.last_poll_watts),0) FROM pdus p
                 WHERE p.zone_id = z.zone_id AND p.is_active = 1 AND p.last_poll_watts IS NOT NULL) AS watts
         FROM power_zones z
         LEFT JOIN datacenters dc ON dc.datacenter_id = z.datacenter_id
         ORDER BY dc.name, z.name'
    );
    foreach ($zones as &$z) {
        $w = (float)($z['watts'] ?? 0);
        $z['kw'] = round($w / 1000.0, 3);
        $maxKw = isset($z['max_kw']) && $z['max_kw'] !== null && $z['max_kw'] !== ''
            ? (float)$z['max_kw'] : null;
        $z['max_kw'] = $maxKw;
        $z['util_pct'] = ($maxKw !== null && $maxKw > 0)
            ? round(100.0 * $z['kw'] / $maxKw, 1)
            : null;
        $z['pdu_count'] = (int)($z['pdu_count'] ?? 0);
        unset($z['watts']);
    }
    unset($z);
} catch (Throwable $e) {
    $zones = [];
}

// Cooling inventory snapshot (aggregates over all units; list is top N)
$cooling = [
    'units' => $metrics['cooling_units'],
    'primary' => 0,
    'standby' => 0,
    'rated_kw' => 0.0,
    'snmp_on' => 0,
    'list' => [],
];
try {
    $cooling['primary'] = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM cooling_units WHERE is_active = 1 AND unit_role = 'primary'"
    );
    $cooling['standby'] = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM cooling_units WHERE is_active = 1 AND unit_role = 'standby'"
    );
    $cooling['snmp_on'] = (int)Database::fetchValue(
        'SELECT COUNT(*) FROM cooling_units WHERE is_active = 1 AND snmp_enabled = 1'
    );
    $cooling['rated_kw'] = round((float)Database::fetchValue(
        'SELECT ISNULL(SUM(rated_kw_cooling),0) FROM cooling_units WHERE is_active = 1'
    ), 1);
    $cuRows = Database::fetchAll(
        'SELECT TOP 12 cooling_unit_id, name, unit_type, unit_role, status,
                rated_kw_cooling, snmp_enabled, snmp_last_poll_at, primary_ip
         FROM cooling_units WHERE is_active = 1 ORDER BY name'
    );
    foreach ($cuRows as $cu) {
        $cooling['list'][] = [
            'name' => (string)$cu['name'],
            'type' => (string)($cu['unit_type'] ?? ''),
            'role' => (string)($cu['unit_role'] ?? ''),
            'status' => (string)($cu['status'] ?? ''),
            'rated_kw' => $cu['rated_kw_cooling'] !== null ? (float)$cu['rated_kw_cooling'] : null,
            'snmp' => !empty($cu['snmp_enabled']),
            'last_poll' => $cu['snmp_last_poll_at'] ?? null,
        ];
    }
} catch (Throwable $e) {
}

// UPS inventory snapshot (load / battery / health for wall)
$ups = [
    'units' => $metrics['ups_units'],
    'online' => 0,
    'on_battery' => 0,
    'bypass' => 0,
    'snmp_on' => 0,
    'polled' => 0,
    'health_ok' => 0,
    'health_warn' => 0,
    'health_crit' => 0,
    'avg_load_pct' => null,
    'max_load_pct' => null,
    'min_battery_pct' => null,
    'avg_battery_pct' => null,
    'rated_kva' => 0.0,
    'last_poll_at' => null,
    'list' => [],
];
try {
    if (is_file(dirname(__DIR__) . '/includes/ups_helpers.php')) {
        require_once dirname(__DIR__) . '/includes/ups_helpers.php';
    }
    if (function_exists('ups_dashboard_snapshot')) {
        $snap = ups_dashboard_snapshot(12);
        $ups = array_merge($ups, $snap);
        $metrics['ups_units'] = (int)($snap['units'] ?? $metrics['ups_units']);
    }
} catch (Throwable $e) {
    App::log('NOC ups: ' . $e->getMessage(), 'warning');
}

// Hottest env sensors (for cooling panel)
$hotSensors = [];
try {
    $hotRows = Database::fetchAll(
        "SELECT TOP 8 sensor_id, name, sensor_kind, last_value, last_humidity, unit, last_seen_at,
                warn_high, crit_high
         FROM env_sensors
         WHERE is_active = 1 AND last_value IS NOT NULL
           AND sensor_kind IN ('temperature','temp_humidity','dew_point')
         ORDER BY last_value DESC"
    );
    foreach ($hotRows as $hs) {
        $cVal = (float)$hs['last_value'];
        $disp = $cVal;
        $sym = '°C';
        if (class_exists('TempUnitService')) {
            $disp = TempUnitService::fromC($cVal) ?? $cVal;
            $sym = TempUnitService::symbol();
        }
        $st = function_exists('env_sensor_threshold_status')
            ? env_sensor_threshold_status($cVal, $hs)
            : 'unknown';
        $hotSensors[] = [
            'name' => (string)$hs['name'],
            'value' => round($disp, 1),
            'unit' => $sym,
            'status' => $st,
            'humidity' => isset($hs['last_humidity']) && $hs['last_humidity'] !== null && $hs['last_humidity'] !== ''
                ? (float)$hs['last_humidity'] : null,
        ];
    }
} catch (Throwable $e) {
}

// Live cabinet health for NOC 3D (every poll — not only full scene reloads)
$cabinetHealth = [];
try {
    if (!class_exists('CabinetHealthService')
        && is_file(dirname(__DIR__) . '/src/Services/CabinetHealthService.php')
    ) {
        require_once dirname(__DIR__) . '/src/Services/CabinetHealthService.php';
    }
    if (class_exists('CabinetHealthService')) {
        $placedIds = Database::fetchAll(
            'SELECT cabinet_id, color_hex FROM cabinets
             WHERE is_active = 1 AND pos_x IS NOT NULL AND pos_y IS NOT NULL'
        );
        $idList = [];
        $colorById = [];
        foreach ($placedIds as $pr) {
            $cid = (int)$pr['cabinet_id'];
            if ($cid > 0) {
                $idList[] = $cid;
                $colorById[$cid] = (string)($pr['color_hex'] ?? '#2d3748');
            }
        }
        if ($idList) {
            $map = CabinetHealthService::forCabinetIds($idList);
            foreach ($map as $cid => $h) {
                $st = (string)($h['status'] ?? 'unknown');
                // Only ship non-idle rows when many cabinets — always include warn/crit;
                // include ok/unknown so the 3D view can clear a recovered rack.
                $cabinetHealth[] = [
                    'cabinet_id' => (int)$cid,
                    'status' => $st,
                    'label' => (string)($h['label'] ?? ''),
                    'color' => (string)($h['color'] ?? CabinetHealthService::statusColor($st)),
                    'health_display_hex' => CabinetHealthService::blendHex(
                        $colorById[(int)$cid] ?? '#2d3748',
                        $st
                    ),
                    'reasons' => $h['reasons'] ?? [],
                ];
            }
        }
    }
} catch (Throwable $e) {
    App::log('NOC cabinet_health: ' . $e->getMessage(), 'warning');
    $cabinetHealth = [];
}

// Recent broadcast notifications for NOC glass toast (no auth — site-wide only)
$recentAlerts = [];
try {
    $rows = Database::fetchAll(
        "SELECT TOP 12 notification_id, title, message, category, entity_type, entity_id, created_at
         FROM notifications
         WHERE user_id IS NULL
         ORDER BY notification_id DESC"
    );
    foreach ($rows as $n) {
        $cat = strtolower((string)($n['category'] ?? 'info'));
        $title = (string)($n['title'] ?? 'Alert');
        $sev = 'info';
        if (in_array($cat, ['warning', 'critical', 'power', 'icmp', 'snmp', 'env', 'error', 'danger'], true)
            || stripos($title, 'DOWN') !== false
            || stripos($title, 'critical') !== false
            || stripos($title, 'CRIT') !== false
        ) {
            $sev = (stripos($title, 'recovered') !== false || stripos($title, ' recovered') !== false)
                ? 'ok'
                : ((stripos($title, 'DOWN') !== false || stripos($title, 'critical') !== false || $cat === 'critical')
                    ? 'crit' : 'warn');
        }
        if (stripos($title, 'recovered') !== false || stripos($title, '[TEST]') !== false && stripos($title, 'recovered') !== false) {
            $sev = 'ok';
        }
        $recentAlerts[] = [
            'id' => (int)$n['notification_id'],
            'title' => $title,
            'message' => mb_substr(preg_replace('/\s+/', ' ', (string)($n['message'] ?? '')) ?? '', 0, 180),
            'category' => $cat,
            'severity' => $sev,
            'entity_type' => $n['entity_type'] ?? null,
            'entity_id' => isset($n['entity_id']) ? (int)$n['entity_id'] : null,
            'created_at' => (string)($n['created_at'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $recentAlerts = [];
}

$out = [
    'ok' => true,
    'updated_at' => gmdate('c'),
    'app' => App::APP_NAME,
    'version' => App::VERSION,
    'temp_unit' => class_exists('TempUnitService') ? TempUnitService::siteUnit() : 'C',
    'temp_symbol' => class_exists('TempUnitService') ? TempUnitService::symbol() : '°C',
    'metrics' => $metrics,
    'env' => $env,
    'power' => $power,
    'power_history' => $powerHistory,
    'zones' => $zones,
    'ups' => $ups,
    'cooling' => $cooling,
    'hot_sensors' => $hotSensors,
    'cabinet_health' => $cabinetHealth,
    'recent_alerts' => $recentAlerts,
];

if ($includeScene) {
    $cabinets3d = [];
    $pdus3d = [];
    $rooms = [];
    $envSensors3d = [];
    try {
        $cabinets3d = Database::fetchAll(
            'SELECT c.cabinet_id, c.name, c.pos_x, c.pos_y, c.pos_z, c.rotation_deg,
                    c.u_height, c.width_mm, c.depth_mm, c.color_hex,
                    r.name AS room_name, r.width_m AS room_width, r.depth_m AS room_depth,
                    (SELECT COUNT(*) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1) AS device_count,
                    (SELECT ISNULL(SUM(d.u_height),0) FROM devices d
                     WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1
                       AND d.position_u IS NOT NULL AND d.parent_device_id IS NULL) AS u_used
             FROM cabinets c
             INNER JOIN rooms r ON r.room_id = c.room_id
             WHERE c.is_active = 1 AND c.pos_x IS NOT NULL AND c.pos_y IS NOT NULL
             ORDER BY c.name'
        );
        if (class_exists('Cabinet3dData')) {
            $cabinets3d = Cabinet3dData::withDevices($cabinets3d);
            // Public NOC: strip faceplate URLs (media.php requires login)
            foreach ($cabinets3d as &$cab) {
                if (!empty($cab['devices']) && is_array($cab['devices'])) {
                    foreach ($cab['devices'] as &$dev) {
                        $dev['front_image'] = null;
                        $dev['rear_image'] = null;
                    }
                    unset($dev);
                }
            }
            unset($cab);
        }
    } catch (Throwable $e) {
        $cabinets3d = [];
    }
    try {
        $pdus3d = Database::fetchAll(
            'SELECT p.pdu_id, p.name, p.pos_x, p.pos_y, p.pos_z, p.rotation_deg, p.front_facing,
                    p.width_mm, p.depth_mm, p.height_mm, p.color_hex, p.pdu_scope, p.ip_address,
                    p.icmp_monitor, p.icmp_fail_count, p.icmp_last_at, p.icmp_last_ok,
                    p.icmp_last_rtt_ms, p.icmp_last_error,
                    r.name AS room_name, r.width_m AS room_width, r.depth_m AS room_depth
             FROM pdus p
             LEFT JOIN rooms r ON r.room_id = p.room_id
             WHERE p.is_active = 1
               AND p.pdu_scope IN (\'row\', \'room\')
               AND p.pos_x IS NOT NULL AND p.pos_y IS NOT NULL
             ORDER BY p.name'
        );
        if (class_exists('CabinetHealthService')) {
            $pdus3d = CabinetHealthService::attachPdus($pdus3d);
        }
    } catch (Throwable $e) {
        $pdus3d = [];
    }
    try {
        $rooms = Database::fetchAll(
            'SELECT r.room_id, r.name, r.width_m, r.depth_m, dc.name AS dc_name
             FROM rooms r
             INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
             WHERE r.is_active = 1
             ORDER BY dc.name, r.name'
        );
    } catch (Throwable $e) {
        $rooms = [];
    }
    try {
        if (class_exists('EnvSensor3dData')) {
            $envSensors3d = EnvSensor3dData::forFloor();
        }
    } catch (Throwable $e) {
        $envSensors3d = [];
    }
    $cooling3d = [];
    try {
        $cooling3d = Database::fetchAll(
            'SELECT u.cooling_unit_id, u.name, u.unit_type, u.unit_role, u.cooling_medium,
                    u.pos_x, u.pos_y, u.pos_z, u.rotation_deg, u.front_facing,
                    u.width_mm, u.depth_mm, u.height_mm, u.color_hex, u.status,
                    r.name AS room_name, r.width_m AS room_width, r.depth_m AS room_depth
             FROM cooling_units u
             LEFT JOIN rooms r ON r.room_id = u.room_id
             WHERE u.is_active = 1
               AND u.pos_x IS NOT NULL AND u.pos_y IS NOT NULL
             ORDER BY u.name'
        );
    } catch (Throwable $e) {
        $cooling3d = [];
    }
    $ups3d = [];
    try {
        if (is_file(dirname(__DIR__) . '/includes/ups_helpers.php')) {
            require_once dirname(__DIR__) . '/includes/ups_helpers.php';
        }
        $ups3d = Database::fetchAll(
            'SELECT u.ups_id, u.name, u.ups_scope, u.pos_x, u.pos_y, u.pos_z, u.rotation_deg, u.front_facing,
                    u.width_mm, u.depth_mm, u.height_mm, u.color_hex, u.status,
                    u.last_output_status, u.last_load_pct, u.last_battery_pct, u.last_runtime_min,
                    r.name AS room_name, r.width_m AS room_width, r.depth_m AS room_depth
             FROM ups_units u
             LEFT JOIN rooms r ON r.room_id = u.room_id
             WHERE u.is_active = 1
               AND u.pos_x IS NOT NULL AND u.pos_y IS NOT NULL
             ORDER BY u.name'
        );
        if (function_exists('ups_health_status')) {
            foreach ($ups3d as &$uu) {
                $uu['health_status'] = ups_health_status($uu);
            }
            unset($uu);
        }
    } catch (Throwable $e) {
        $ups3d = [];
    }
    $out['scene'] = [
        'cabinets' => $cabinets3d,
        'pdus' => $pdus3d,
        'cooling' => $cooling3d,
        'ups' => $ups3d,
        'rooms' => $rooms,
        'env_sensors' => $envSensors3d,
        'logo_url' => App::url('assets/img/logo.svg'),
    ];
}

App::json($out);
