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
    // Facility kW respects site load rollup (prefer row/room vs sum-all)
    $metrics['power_kw'] = 0.0;
    try {
        require_once dirname(__DIR__) . '/includes/power_helpers.php';
        $allPduRows = Database::fetchAll(
            'SELECT pdu_id, pdu_scope, zone_id, include_in_site_load, last_poll_watts, last_poll_amps, last_poll_at, name
             FROM pdus WHERE is_active = 1'
        );
        $metrics['power_kw'] = power_site_load_totals($allPduRows)['kw'];
    } catch (Throwable $e) {
        $metrics['power_kw'] = (float)Database::fetchValue(
            'SELECT ISNULL(SUM(last_poll_watts),0) / 1000.0 FROM pdus WHERE is_active = 1 AND last_poll_watts IS NOT NULL'
        );
    }
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
    'pdu_polled' => 0,
    'pdu_amps' => null,
    'last_poll_at' => null,
    'top_pdus' => [],
    'site_load_mode' => function_exists('power_site_load_mode') ? power_site_load_mode() : 'all',
    'snmp_stale' => 0,
    'snmp_monitored' => 0,
];
try {
    require_once dirname(__DIR__) . '/includes/power_helpers.php';
    if (is_file(dirname(__DIR__) . '/includes/snmp_helpers.php')) {
        require_once dirname(__DIR__) . '/includes/snmp_helpers.php';
    }
    $allPduRows = Database::fetchAll(
        'SELECT p.pdu_id, p.name, p.pdu_scope, p.zone_id, p.include_in_site_load,
                p.last_poll_watts, p.last_poll_amps, p.last_poll_at, p.snmp_enabled, p.snmp_auto_poll,
                z.name AS zone_name
         FROM pdus p
         LEFT JOIN power_zones z ON z.zone_id = p.zone_id
         WHERE p.is_active = 1'
    );
    $siteIds = array_fill_keys(power_site_load_pdu_ids($allPduRows), true);
    $ampsSum = 0.0;
    $anyAmps = false;
    $polled = 0;
    $lastAt = null;
    $ranked = [];
    foreach ($allPduRows as $tp) {
        $pid = (int)$tp['pdu_id'];
        if (empty($siteIds[$pid])) {
            continue;
        }
        if ($tp['last_poll_watts'] !== null && $tp['last_poll_watts'] !== '') {
            $polled++;
            $ranked[] = $tp;
        }
        if ($tp['last_poll_amps'] !== null && $tp['last_poll_amps'] !== '') {
            $ampsSum += (float)$tp['last_poll_amps'];
            $anyAmps = true;
        }
        $at = (string)($tp['last_poll_at'] ?? '');
        if ($at !== '' && ($lastAt === null || strcmp($at, $lastAt) > 0)) {
            $lastAt = $at;
        }
    }
    $power['last_poll_at'] = $lastAt;
    $power['pdu_polled'] = $polled;
    $power['pdu_amps'] = $anyAmps ? round($ampsSum, 1) : null;
    // Fleet SNMP stale (PDUs with SNMP/auto-poll + UPS auto-poll)
    $staleCount = 0;
    $monitored = 0;
    foreach ($allPduRows as $tp) {
        if (empty($tp['snmp_enabled']) && empty($tp['snmp_auto_poll'])) {
            continue;
        }
        $monitored++;
        if (function_exists('snmp_poll_is_stale') && snmp_poll_is_stale($tp['last_poll_at'] ?? null)) {
            $staleCount++;
        } elseif (!function_exists('snmp_poll_is_stale')) {
            $at = (string)($tp['last_poll_at'] ?? '');
            if ($at === '' || strtotime($at) < (time() - 3600)) {
                $staleCount++;
            }
        }
    }
    try {
        $upsMon = Database::fetchAll(
            'SELECT snmp_last_poll_at, snmp_enabled, snmp_auto_poll
             FROM ups_units WHERE is_active = 1'
        );
        foreach ($upsMon as $uu) {
            if (empty($uu['snmp_enabled']) && empty($uu['snmp_auto_poll'])) {
                continue;
            }
            $monitored++;
            if (function_exists('snmp_poll_is_stale') && snmp_poll_is_stale($uu['snmp_last_poll_at'] ?? null)) {
                $staleCount++;
            } elseif (!function_exists('snmp_poll_is_stale')) {
                $at = (string)($uu['snmp_last_poll_at'] ?? '');
                if ($at === '' || strtotime($at) < (time() - 3600)) {
                    $staleCount++;
                }
            }
        }
    } catch (Throwable $e) {
        // ups table optional
    }
    $power['snmp_stale'] = $staleCount;
    $power['snmp_monitored'] = $monitored;
    usort($ranked, static function ($a, $b) {
        return ((float)($b['last_poll_watts'] ?? 0)) <=> ((float)($a['last_poll_watts'] ?? 0));
    });
    foreach (array_slice($ranked, 0, 8) as $tp) {
        $w = (float)$tp['last_poll_watts'];
        $power['top_pdus'][] = [
            'name' => (string)$tp['name'],
            'kw' => round($w / 1000.0, 3),
            'amps' => $tp['last_poll_amps'] !== null ? round((float)$tp['last_poll_amps'], 1) : null,
            'zone_name' => $tp['zone_name'] ?? null,
            'last_poll' => $tp['last_poll_at'] ?? null,
        ];
    }
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

// UPS 24h history (load % + est. kW) for NOC power panel
$upsHistory = ['t' => [], 'load_pct' => [], 'kw' => [], 'points' => 0];
try {
    if (class_exists('UpsHistoryService')) {
        $uh = UpsHistoryService::series('ups_site', null, 24);
        $t = $uh['series']['t'] ?? [];
        $lp = $uh['series']['load_pct'] ?? [];
        $ukw = $uh['series']['kw'] ?? [];
        $n = count($t);
        $step = $n > 48 ? (int)ceil($n / 48) : 1;
        for ($i = 0; $i < $n; $i += $step) {
            $upsHistory['t'][] = $t[$i];
            $upsHistory['load_pct'][] = $lp[$i] ?? null;
            $upsHistory['kw'][] = $ukw[$i] ?? null;
        }
        $upsHistory['points'] = count($upsHistory['t']);
        if (!empty($uh['summary']['load_pct']['avg'])) {
            // keep live avg from snapshot as primary; history avg as fallback note
            $upsHistory['load_avg_24h'] = $uh['summary']['load_pct']['avg'] ?? null;
            $upsHistory['load_max_24h'] = $uh['summary']['load_pct']['max'] ?? null;
        }
        if (!empty($uh['summary']['kw'])) {
            $upsHistory['kw_avg_24h'] = $uh['summary']['kw']['avg'] ?? null;
            $upsHistory['kw_max_24h'] = $uh['summary']['kw']['max'] ?? null;
        }
    }
} catch (Throwable $e) {
    App::log('NOC ups history: ' . $e->getMessage(), 'warning');
}

// Power zones with live load
$zones = [];
try {
    $zones = Database::fetchAll(
        'SELECT z.zone_id, z.name, z.color_hex, z.feed_type, z.max_kw, z.voltage,
                dc.name AS dc_name,
                (SELECT COUNT(*) FROM pdus p WHERE p.zone_id = z.zone_id AND p.is_active = 1) AS pdu_count,
                (SELECT ISNULL(SUM(p.last_poll_watts),0) FROM pdus p
                 WHERE p.zone_id = z.zone_id AND p.is_active = 1 AND p.last_poll_watts IS NOT NULL) AS watts,
                (SELECT COUNT(*) FROM ups_units u WHERE u.zone_id = z.zone_id AND u.is_active = 1) AS ups_count,
                (SELECT AVG(CAST(u.last_load_pct AS FLOAT)) FROM ups_units u
                 WHERE u.zone_id = z.zone_id AND u.is_active = 1 AND u.last_load_pct IS NOT NULL) AS ups_avg_load
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
        $z['ups_count'] = (int)($z['ups_count'] ?? 0);
        $z['ups_avg_load'] = $z['ups_avg_load'] !== null && $z['ups_avg_load'] !== ''
            ? round((float)$z['ups_avg_load'], 1) : null;
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
    try {
        $rows = Database::fetchAll(
            "SELECT TOP 12 notification_id, title, message, category, entity_type, entity_id,
                    is_cleared, cleared_at, created_at
             FROM notifications
             WHERE user_id IS NULL
             ORDER BY notification_id DESC"
        );
    } catch (Throwable $eCols) {
        $rows = Database::fetchAll(
            "SELECT TOP 12 notification_id, title, message, category, entity_type, entity_id, created_at
             FROM notifications
             WHERE user_id IS NULL
             ORDER BY notification_id DESC"
        );
    }
    if (class_exists('NotificationAlertStatus')) {
        $rows = NotificationAlertStatus::enrich($rows);
    }
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
        $alertState = (string)($n['alert_state'] ?? '');
        $isCleared = !empty($n['is_cleared']) || $alertState === 'cleared' || $sev === 'ok';
        if ($isCleared && $sev !== 'info') {
            // Keep original severity for border tint but flag cleared for green check UI
            // (sev-ok styling still used for recovery-only events)
        }
        $recentAlerts[] = [
            'id' => (int)$n['notification_id'],
            'title' => $title,
            'message' => mb_substr(preg_replace('/\s+/', ' ', (string)($n['message'] ?? '')) ?? '', 0, 180),
            'category' => $cat,
            'severity' => $sev,
            'is_cleared' => $isCleared,
            'alert_state' => $isCleared ? 'cleared' : ($alertState !== '' ? $alertState : 'active'),
            'alert_state_label' => $isCleared ? 'Cleared' : (string)($n['alert_state_label'] ?? 'Active'),
            'entity_type' => $n['entity_type'] ?? null,
            'entity_id' => isset($n['entity_id']) ? (int)$n['entity_id'] : null,
            'created_at' => (string)($n['created_at'] ?? ''),
            'cleared_at' => isset($n['cleared_at']) ? (string)$n['cleared_at'] : null,
        ];
    }
} catch (Throwable $e) {
    $recentAlerts = [];
}

// NOC wall display options (Settings → NOC wall display)
$nocShowLabels = true;
$nocShowRaceways = true;
$nocAutoRotate = true;
$nocPanelSec = 20;
$nocClearedTtl = 120;
$nocCamTiltPct = 63;
$nocCamZoomPct = 72;
try {
    $nocShowLabels = SettingsService::get('noc_show_labels', '1') === '1';
    $nocShowRaceways = SettingsService::get('noc_show_raceways', '1') === '1';
    $nocAutoRotate = SettingsService::get('noc_auto_rotate', '1') === '1';
    $nocPanelSec = (int)SettingsService::get('noc_panel_rotate_sec', '20');
    if (!in_array($nocPanelSec, [5, 10, 20, 30, 40, 50, 60], true)) {
        $nocPanelSec = 20;
    }
    $nocClearedTtl = (int)SettingsService::get('noc_cleared_alert_ttl_sec', '120');
    if (!in_array($nocClearedTtl, [0, 30, 60, 120, 300, 600, 1800, -1], true)) {
        $nocClearedTtl = 120;
    }
    $nocCamTiltPct = max(0, min(100, (int)SettingsService::get('noc_cam_tilt_pct', '63')));
    $nocCamZoomPct = max(0, min(100, (int)SettingsService::get('noc_cam_zoom_pct', '72')));
} catch (Throwable $e) {
}

// Filter cleared alerts by TTL for wall display
if ($recentAlerts !== []) {
    $nowTs = time();
    $filtered = [];
    foreach ($recentAlerts as $a) {
        $isCleared = !empty($a['is_cleared']) || (($a['alert_state'] ?? '') === 'cleared')
            || (($a['severity'] ?? '') === 'ok');
        if ($isCleared) {
            if ($nocClearedTtl === 0) {
                continue; // hide immediately
            }
            if ($nocClearedTtl > 0) {
                $clearedAt = null;
                if (!empty($a['cleared_at'])) {
                    $clearedAt = strtotime((string)$a['cleared_at']);
                }
                if ($clearedAt === false || $clearedAt === null) {
                    $clearedAt = !empty($a['created_at']) ? strtotime((string)$a['created_at']) : $nowTs;
                }
                if ($clearedAt !== false && ($nowTs - (int)$clearedAt) > $nocClearedTtl) {
                    continue;
                }
            }
            // -1 = keep until grid drop-off
        }
        $filtered[] = $a;
    }
    $recentAlerts = $filtered;
}

$out = [
    'ok' => true,
    'updated_at' => gmdate('c'),
    'app' => App::APP_NAME,
    'version' => App::VERSION,
    'temp_unit' => class_exists('TempUnitService') ? TempUnitService::siteUnit() : 'C',
    'temp_symbol' => class_exists('TempUnitService') ? TempUnitService::symbol() : '°C',
    'noc' => [
        'show_labels' => $nocShowLabels,
        'show_raceways' => $nocShowRaceways,
        'auto_rotate' => $nocAutoRotate,
        'panel_rotate_sec' => $nocPanelSec,
        'panel_rotate_ms' => $nocPanelSec * 1000,
        'cleared_alert_ttl_sec' => $nocClearedTtl,
        'cam_tilt_pct' => $nocCamTiltPct,
        'cam_zoom_pct' => $nocCamZoomPct,
    ],
    'metrics' => $metrics,
    'env' => $env,
    'power' => $power,
    'power_history' => $powerHistory,
    'ups_history' => $upsHistory,
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
                        $dev['front_image_md'] = null;
                        $dev['rear_image_md'] = null;
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
    $cablePaths3d = [];
    try {
        // Slim columns for wall display (avoid huge notes / dual waypoint payloads)
        $cablePaths3d = Database::fetchAll(
            'SELECT path_id, room_id, name, path_code, path_type, path_kind, media_class, feed_to,
                    width_m, elevation_m, color_hex, waypoints, is_active
             FROM cable_paths
             WHERE (is_active IS NULL OR is_active = 1)
             ORDER BY name'
        );
    } catch (Throwable $e) {
        try {
            $cablePaths3d = Database::fetchAll(
                'SELECT path_id, room_id, name, path_type, path_kind, feed_to, width_m, color_hex, waypoints
                 FROM cable_paths ORDER BY name'
            );
        } catch (Throwable $e2) {
            try {
                $cablePaths3d = Database::fetchAll('SELECT * FROM cable_paths ORDER BY name');
            } catch (Throwable $e3) {
                $cablePaths3d = [];
            }
        }
    }
    if ($cablePaths3d && class_exists('CablePlantService')) {
        foreach ($cablePaths3d as &$cp) {
            $cp['waypoints_list'] = CablePlantService::parseWaypoints($cp['waypoints'] ?? null);
            // Drop raw JSON string — list is enough for 3D (smaller NOC payload)
            unset($cp['waypoints']);
        }
        unset($cp);
    }

    // Ensure every scene cabinet carries health (NOC wall depends on this + live poll)
    if ($cabinets3d && $cabinetHealth) {
        $hById = [];
        foreach ($cabinetHealth as $hr) {
            $hid = (int)($hr['cabinet_id'] ?? 0);
            if ($hid > 0) {
                $hById[$hid] = $hr;
            }
        }
        foreach ($cabinets3d as &$cabRow) {
            $cid = (int)($cabRow['cabinet_id'] ?? 0);
            if ($cid < 1 || !isset($hById[$cid])) {
                continue;
            }
            $hr = $hById[$cid];
            $st = (string)($hr['status'] ?? 'unknown');
            $cabRow['health_status'] = $st;
            $cabRow['health_display_hex'] = (string)($hr['health_display_hex']
                ?? ($hr['color'] ?? ''));
            if (empty($cabRow['health']) || !is_array($cabRow['health'])) {
                $cabRow['health'] = [
                    'status' => $st,
                    'label' => (string)($hr['label'] ?? ''),
                    'color' => (string)($hr['color'] ?? ''),
                    'reasons' => $hr['reasons'] ?? [],
                ];
            } else {
                $cabRow['health']['status'] = $st;
            }
        }
        unset($cabRow);
    }

    $airflow3d = [];
    try {
        if (class_exists('Schema')) {
            Schema::ensureAirflow();
        }
        $airflow3d = Database::fetchAll(
            'SELECT a.*, r.name AS room_name, r.width_m AS room_width, r.depth_m AS room_depth
             FROM airflow_anchors a
             LEFT JOIN rooms r ON r.room_id = a.room_id
             WHERE a.is_active = 1
             ORDER BY a.kind, a.name, a.anchor_id'
        ) ?: [];
    } catch (Throwable $e) {
        $airflow3d = [];
    }

    $out['scene'] = [
        'cabinets' => $cabinets3d,
        'pdus' => $pdus3d,
        'cooling' => $cooling3d,
        'ups' => $ups3d,
        'rooms' => $rooms,
        'env_sensors' => $envSensors3d,
        'airflow_anchors' => $airflow3d,
        'cable_paths' => $cablePaths3d,
        'cabinet_health' => $cabinetHealth,
        'logo_url' => App::url('assets/img/logo.svg'),
    ];
}

App::json($out);
