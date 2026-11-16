<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/power_helpers.php';
require_once dirname(__DIR__) . '/includes/work_order_helpers.php';
require_once dirname(__DIR__) . '/includes/cooling_helpers.php';
require_once dirname(__DIR__) . '/includes/executive_report.php';
App::boot();
$user = App::requirePermission('view_reports');

$report = (string)($_GET['report'] ?? '');
if ($report === 'executive') {
    $report = 'temperature';
    $_GET['report'] = 'temperature';
    $_GET['mode'] = 'executive';
}

// Power history report filters
$phPreset = strtolower(trim((string)($_GET['preset'] ?? 'week')));
if (!in_array($phPreset, ['week', 'month', 'year', 'custom'], true)) {
    $phPreset = 'week';
}
$phScope = strtolower(trim((string)($_GET['scope'] ?? 'site')));
if (!in_array($phScope, ['site', 'zone', 'pdu'], true)) {
    $phScope = 'site';
}
$phId = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;
$phFrom = trim((string)($_GET['from'] ?? ''));
$phTo = trim((string)($_GET['to'] ?? ''));
if ($phPreset === 'custom' && ($phFrom === '' || $phTo === '')) {
    $phFrom = $phFrom !== '' ? $phFrom : date('Y-m-d', strtotime('-7 days'));
    $phTo = $phTo !== '' ? $phTo : date('Y-m-d');
}
$phZones = [];
$phPdus = [];
try {
    $phZones = Database::fetchAll('SELECT zone_id, name FROM power_zones ORDER BY name');
    $phPdus = Database::fetchAll(
        'SELECT pdu_id, name FROM pdus WHERE is_active = 1 ORDER BY name'
    );
    if (function_exists('power_natural_sort_rows')) {
        $phPdus = power_natural_sort_rows($phPdus, 'name');
    }
} catch (Throwable $e) {
    $phZones = [];
    $phPdus = [];
}

function report_inventory_summary(): array
{
    return [
        'by_type' => Database::fetchAll(
            "SELECT device_type, COUNT(*) AS cnt FROM devices WHERE is_active = 1 GROUP BY device_type ORDER BY cnt DESC"
        ),
        'by_status' => Database::fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM devices WHERE is_active = 1 GROUP BY status ORDER BY cnt DESC"
        ),
        'by_dc' => Database::fetchAll(
            "SELECT dc.name, COUNT(d.device_id) AS cnt
             FROM datacenters dc
             LEFT JOIN rooms r ON r.datacenter_id = dc.datacenter_id
             LEFT JOIN cabinets c ON c.room_id = r.room_id AND c.is_active = 1
             LEFT JOIN devices d ON d.cabinet_id = c.cabinet_id AND d.is_active = 1
             WHERE dc.is_active = 1
             GROUP BY dc.name ORDER BY dc.name"
        ),
    ];
}

function report_cabinet_utilization(): array
{
    return Database::fetchAll(
        "SELECT c.name, c.u_height,
            ISNULL((SELECT SUM(d.u_height) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1 AND d.position_u IS NOT NULL), 0) AS u_used,
            r.name AS room_name, dc.name AS dc_name
         FROM cabinets c
         INNER JOIN rooms r ON r.room_id = c.room_id
         INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
         WHERE c.is_active = 1
         ORDER BY dc.name, c.name"
    );
}

function report_power_capacity(float $needKw = 0.0, int $needU = 0): array
{
    if (!function_exists('power_zone_capacity_snapshot')) {
        require_once dirname(__DIR__) . '/includes/power_helpers.php';
    }
    $zones = Database::fetchAll(
        'SELECT z.*, dc.name AS dc_name
         FROM power_zones z
         LEFT JOIN datacenters dc ON dc.datacenter_id = z.datacenter_id
         ORDER BY z.name'
    );
    $pdus = [];
    try {
        $pdus = Database::fetchAll(
            'SELECT pdu_id, zone_id, pdu_scope, include_in_site_load, last_poll_watts,
                    last_poll_phases, rated_amps, phases, name, rated_volts, last_poll_amps, last_poll_at
             FROM pdus WHERE is_active = 1'
        );
    } catch (Throwable $e) {
        $pdus = Database::fetchAll(
            'SELECT pdu_id, zone_id, pdu_scope, include_in_site_load, last_poll_watts,
                    name, rated_amps, rated_volts, last_poll_amps, last_poll_at
             FROM pdus WHERE is_active = 1'
        );
    }
    $uBy = function_exists('power_all_zones_u_capacity') ? power_all_zones_u_capacity() : [];
    $snapshots = [];
    foreach ($zones as $z) {
        $zid = (int)$z['zone_id'];
        $snap = power_zone_capacity_snapshot(
            $pdus,
            $z,
            $uBy[$zid] ?? ['u_total' => 0, 'u_used' => 0, 'free_u' => 0, 'cabinets' => 0]
        );
        $snap['dc_name'] = (string)($z['dc_name'] ?? '');
        $snap['voltage'] = $z['voltage'] ?? null;
        $snap['max_amps'] = $z['max_amps'] ?? null;
        $snapshots[] = $snap;
    }
    $fits = ($needKw > 0 || $needU > 0)
        ? power_capacity_fits_filter($snapshots, $needKw, $needU)
        : $snapshots;

    $pduRows = Database::fetchAll(
        'SELECT p.name, p.pdu_scope, p.rated_amps, p.rated_volts, p.last_poll_watts, p.last_poll_amps, p.last_poll_at,
                c.name AS cabinet_name, z.name AS zone_name
         FROM pdus p
         LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
         LEFT JOIN power_zones z ON z.zone_id = p.zone_id
         WHERE p.is_active = 1 ORDER BY p.name'
    );
    if (function_exists('power_natural_sort_rows')) {
        $pduRows = power_natural_sort_rows($pduRows, 'name');
    }

    return [
        'zones' => $snapshots,
        'fits' => $fits,
        'need_kw' => $needKw,
        'need_u' => $needU,
        'pdus' => $pduRows,
    ];
}

/**
 * Warranty report rows with optional window filter.
 * @param string $filter all|soon|expired|window (window = within notify days or expired)
 * @return list<array<string,mixed>>
 */
function report_warranty(string $filter = 'all', int $days = 60): array
{
    $days = max(0, min(730, $days));
    $datePred = '';
    $params = [];
    if ($filter === 'expired') {
        $datePred = ' AND warranty_end < CAST(GETUTCDATE() AS date)';
    } elseif ($filter === 'soon' || $filter === 'window') {
        $datePred = ' AND warranty_end <= DATEADD(day, ?, CAST(GETUTCDATE() AS date))';
        $params[] = $days;
    }

    $parts = [
        "SELECT 'device' AS kind, d.device_id AS entity_id, d.label, d.asset_tag,
                d.manufacturer, d.model, d.serial_no, d.warranty_end, d.warranty_provider,
                d.status, dep.name AS department_name
         FROM devices d
         LEFT JOIN departments dep ON dep.department_id = d.department_id
         WHERE d.is_active = 1
           AND d.status NOT IN ('disposed')
           AND d.warranty_end IS NOT NULL" . str_replace('warranty_end', 'd.warranty_end', $datePred),
    ];
    try {
        $parts[] = "SELECT 'pdu' AS kind, p.pdu_id, p.name, CAST(NULL AS NVARCHAR(100)),
                           p.manufacturer, p.model, p.serial_no, p.warranty_end, p.warranty_provider,
                           CAST(NULL AS NVARCHAR(50)), CAST(NULL AS NVARCHAR(150))
                    FROM pdus p
                    WHERE p.is_active = 1 AND p.warranty_end IS NOT NULL"
            . str_replace('warranty_end', 'p.warranty_end', $datePred);
    } catch (Throwable $e) {
        // columns not yet on this install
    }
    try {
        $parts[] = "SELECT 'ups' AS kind, u.ups_id, u.name, u.asset_tag,
                           u.manufacturer, u.model, u.serial_no, u.warranty_end, u.warranty_provider,
                           u.status, CAST(NULL AS NVARCHAR(150))
                    FROM ups_units u
                    WHERE u.is_active = 1 AND u.warranty_end IS NOT NULL"
            . str_replace('warranty_end', 'u.warranty_end', $datePred);
    } catch (Throwable $e) {
        // optional
    }

    $sql = implode("\nUNION ALL\n", $parts) . ' ORDER BY warranty_end, label';
    $bind = [];
    foreach ($parts as $_) {
        foreach ($params as $p) {
            $bind[] = $p;
        }
    }
    try {
        return Database::fetchAll($sql, $bind);
    } catch (Throwable $e) {
        $sql = "SELECT 'device' AS kind, d.device_id AS entity_id, d.label, d.asset_tag,
                       d.manufacturer, d.model, d.serial_no, d.warranty_end, d.warranty_provider,
                       d.status, dep.name AS department_name
                FROM devices d
                LEFT JOIN departments dep ON dep.department_id = d.department_id
                WHERE d.is_active = 1
                  AND d.status NOT IN ('disposed')
                  AND d.warranty_end IS NOT NULL"
            . str_replace('warranty_end', 'd.warranty_end', $datePred)
            . ' ORDER BY d.warranty_end, d.label';
        return Database::fetchAll($sql, $params);
    }
}

function report_disposal_queue(): array
{
    return Database::fetchAll(
        "SELECT d.*, dev.label AS device_label FROM disposals d
         INNER JOIN devices dev ON dev.device_id = d.device_id
         WHERE d.status NOT IN ('completed','cancelled')
         ORDER BY d.scheduled_date"
    );
}

function report_cables(): array
{
    return Database::fetchAll(
        "SELECT c.cable_label, c.media_type, c.length_m, c.status,
                da.label AS a_device, pa.label AS a_port,
                db.label AS b_device, pb.label AS b_port
         FROM cables c
         LEFT JOIN device_ports pa ON pa.port_id = c.a_port_id
         LEFT JOIN devices da ON da.device_id = pa.device_id
         LEFT JOIN device_ports pb ON pb.port_id = c.b_port_id
         LEFT JOIN devices db ON db.device_id = pb.device_id
         ORDER BY c.cable_id DESC"
    );
}

function report_orphans(): array
{
    return Database::fetchAll(
        "SELECT device_id, label, device_type, status, serial_no, asset_tag
         FROM devices WHERE is_active = 1 AND cabinet_id IS NULL ORDER BY label"
    );
}

function report_audit_history(): array
{
    return Database::fetchAll(
        "SELECT * FROM audit_jobs WHERE status = 'completed' ORDER BY completed_at DESC"
    );
}

function report_work_orders(): array
{
    try {
        return Database::fetchAll(
            "SELECT w.work_order_id, w.title, w.work_type, w.status, w.change_ticket,
                    w.itsm_display_id, w.itsm_provider, w.scheduled_date, w.updated_at,
                    (SELECT COUNT(*) FROM work_order_items i WHERE i.work_order_id = w.work_order_id) AS item_count
             FROM work_orders w
             ORDER BY w.updated_at DESC"
        );
    } catch (Throwable $e) {
        return Database::fetchAll(
            "SELECT w.work_order_id, w.title, w.work_type, w.status, w.change_ticket,
                    w.scheduled_date, w.updated_at,
                    (SELECT COUNT(*) FROM work_order_items i WHERE i.work_order_id = w.work_order_id) AS item_count
             FROM work_orders w
             ORDER BY w.updated_at DESC"
        );
    }
}

/** @return array<string,string> */
function report_catalog(): array
{
    return [
        'power_history' => 'Power History',
        'power_path' => 'Power Path',
        'inventory_summary' => 'Inventory Summary',
        'cabinet_utilization' => 'Cabinet Utilization',
        'power_capacity' => 'Power Capacity',
        'cooling' => 'Cooling',
        'temperature' => 'Temperature',
        'humidity' => 'Humidity',
        'warranty_expiration' => 'Warranty Expiration',
        'disposal_queue' => 'Disposal Queue',
        'cable_inventory' => 'Cable Inventory',
        'orphaned_devices' => 'Orphaned Devices',
        'work_orders' => 'Work orders',
        'audit_history' => 'Audit History',
    ];
}

/** Detail or Executive URL for the open report. Keeps the current filters. */
function report_mode_href(bool $executive): string
{
    $q = $_GET;
    unset($q['export']);
    if ($executive) {
        $q['mode'] = 'executive';
    } else {
        unset($q['mode']);
    }
    $qs = http_build_query($q);
    return $qs === '' ? '?' : ('?' . $qs);
}

/** Current report URL with export=csv (keeps filters). */
function report_export_url(array $extra = []): string
{
    $q = $_GET;
    unset($q['export']);
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    $q['export'] = 'csv';
    $qs = http_build_query($q);
    return App::url('pages/reports.php' . ($qs !== '' ? '?' . $qs : ''));
}

function report_export_btn(array $extra = []): void
{
    echo '<a class="btn btn-sm btn-secondary" href="' . App::e(report_export_url($extra)) . '">Export CSV</a>';
}

function report_fmt_temp(mixed $celsius): string
{
    if ($celsius === null || $celsius === '' || !is_numeric($celsius)) {
        return '';
    }
    if (class_exists('TempUnitService')) {
        return TempUnitService::format((float)$celsius, 1, true);
    }
    $s = rtrim(rtrim(number_format((float)$celsius, 1, '.', ''), '0'), '.');
    return ($s === '' || $s === '-' ? '0' : $s) . ' °C';
}

function report_fmt_rh(mixed $rh): string
{
    if ($rh === null || $rh === '' || !is_numeric($rh)) {
        return '';
    }
    $s = rtrim(rtrim(number_format((float)$rh, 1, '.', ''), '0'), '.');
    return ($s === '' || $s === '-' ? '0' : $s) . ' %RH';
}

function report_dash(string $value): string
{
    return $value !== '' ? $value : '—';
}

/**
 * Latest cooling-unit telemetry and aisle/room sensor readings, including humidity.
 *
 * @return array{units:list<array<string,mixed>>,sensors:list<array<string,mixed>>}
 */
function report_cooling(): array
{
    $types = cooling_unit_types();
    $roles = cooling_unit_roles();
    $kinds = env_sensor_kinds();
    $placements = env_sensor_placements();
    $hosts = env_sensor_hosts();
    $units = [];
    try {
        $rows = Database::fetchAll(
            'SELECT u.cooling_unit_id, u.name, u.unit_type, u.unit_role, u.snmp_last_poll_at,
                    u.last_poll_json, rm.name AS room_name
             FROM cooling_units u
             LEFT JOIN rooms rm ON rm.room_id = u.room_id
             WHERE u.is_active = 1
             ORDER BY u.name'
        );
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $u) {
        $snap = cooling_poll_snapshot_promote($u['last_poll_json'] ?? null);
        $rhSet = '';
        foreach ($snap['display'] as $item) {
            if (($item['key'] ?? '') === 'return_humidity_setpoint') {
                $rhSet = (string)($item['value'] ?? '');
                break;
            }
        }
        $coolPct = $snap['cooling_capacity_pct'];
        $units[] = [
            'cooling_unit_id' => (int)$u['cooling_unit_id'],
            'name' => (string)$u['name'],
            'room' => (string)($u['room_name'] ?? ''),
            'type' => $types[(string)($u['unit_type'] ?? '')] ?? (string)($u['unit_type'] ?? ''),
            'role' => $roles[(string)($u['unit_role'] ?? '')] ?? (string)($u['unit_role'] ?? ''),
            'state' => (string)($snap['system_state'] ?? ''),
            'supply' => report_fmt_temp($snap['supply_temp']),
            'return' => report_fmt_temp($snap['return_temp']),
            'humidity' => report_fmt_rh($snap['humidity']),
            'humidity_setpoint' => $rhSet,
            'cooling_pct' => $coolPct !== null ? ((string)(int)round((float)$coolPct) . '%') : '',
            'polled' => (string)($u['snmp_last_poll_at'] ?? ($snap['polled_at'] ?? '')),
        ];
    }

    $sensorKindSql = "s.sensor_kind IN ('temperature', 'temp_humidity', 'humidity', 'dew_point')";
    $sensorSql = "SELECT s.*,
                    rm.name AS room_name,
                    cu.name AS cooling_unit_name,
                    p.name AS pdu_name,
                    d.label AS device_label,
                    d.device_type AS device_type,
                    cab.name AS cabinet_name
             FROM env_sensors s
             LEFT JOIN rooms rm ON rm.room_id = s.room_id
             LEFT JOIN cooling_units cu ON cu.cooling_unit_id = s.cooling_unit_id
             LEFT JOIN pdus p ON p.pdu_id = s.pdu_id
             LEFT JOIN devices d ON d.device_id = s.device_id
             LEFT JOIN cabinets cab ON cab.cabinet_id = COALESCE(s.cabinet_id, d.cabinet_id)
             WHERE s.is_active = 1
               AND $sensorKindSql
             ORDER BY s.name";
    $sensorSqlPlain = "SELECT s.*,
                    rm.name AS room_name,
                    cu.name AS cooling_unit_name,
                    p.name AS pdu_name,
                    d.label AS device_label,
                    d.device_type AS device_type
             FROM env_sensors s
             LEFT JOIN rooms rm ON rm.room_id = s.room_id
             LEFT JOIN cooling_units cu ON cu.cooling_unit_id = s.cooling_unit_id
             LEFT JOIN pdus p ON p.pdu_id = s.pdu_id
             LEFT JOIN devices d ON d.device_id = s.device_id
             WHERE s.is_active = 1
               AND $sensorKindSql
             ORDER BY s.name";
    try {
        $sensorRows = Database::fetchAll($sensorSql);
    } catch (Throwable $e) {
        try {
            $sensorRows = Database::fetchAll($sensorSqlPlain);
        } catch (Throwable $e2) {
            $sensorRows = [];
        }
    }
    $sensors = [];
    foreach ($sensorRows as $s) {
        $kind = (string)($s['sensor_kind'] ?? '');
        $val = isset($s['last_value']) && $s['last_value'] !== null && $s['last_value'] !== '' && is_numeric($s['last_value'])
            ? (float)$s['last_value'] : null;
        $hum = isset($s['last_humidity']) && $s['last_humidity'] !== null && $s['last_humidity'] !== '' && is_numeric($s['last_humidity'])
            ? (float)$s['last_humidity'] : null;
        $tempText = '';
        $humText = '';
        if ($kind === 'humidity') {
            $humText = report_fmt_rh($hum ?? $val);
        } else {
            $tempText = report_fmt_temp($val);
            $humText = report_fmt_rh($hum);
        }
        $sensors[] = [
            'sensor_id' => (int)$s['sensor_id'],
            'name' => (string)$s['name'],
            'kind' => env_sensor_kind_short($kind, $kinds),
            'room' => (string)($s['room_name'] ?? ''),
            'placement' => $placements[(string)($s['placement'] ?? '')] ?? '',
            'host' => env_sensor_host_display($s, $hosts),
            'temperature' => $tempText,
            'humidity' => $humText,
            'seen' => (string)($s['last_seen_at'] ?? ''),
        ];
    }

    return ['units' => $units, 'sensors' => $sensors];
}

/**
 * @return array{span:string,hours:int,label:string}
 */
function report_climate_span(): array
{
    $span = strtolower(trim((string)($_GET['span'] ?? '7d')));
    if (!in_array($span, ['24h', '7d', '30d'], true)) {
        $span = '7d';
    }
    $hours = match ($span) {
        '24h' => 24,
        '30d' => 24 * 30,
        default => 24 * 7,
    };
    $label = match ($span) {
        '24h' => 'the last 24 hours',
        '30d' => 'the last 30 days',
        default => 'the last 7 days',
    };
    return ['span' => $span, 'hours' => $hours, 'label' => $label];
}

function report_climate_room_id(): int
{
    if (!isset($_GET['room_id']) || $_GET['room_id'] === '') {
        return 0;
    }
    return max(0, (int)$_GET['room_id']);
}

/** @return list<array<string,mixed>> */
function report_climate_rooms(): array
{
    try {
        return Database::fetchAll(
            'SELECT r.room_id, r.name, dc.name AS dc_name
             FROM rooms r
             LEFT JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
             WHERE r.is_active = 1
             ORDER BY dc.name, r.name'
        );
    } catch (Throwable $e) {
        return [];
    }
}

function report_climate_since(int $hours): string
{
    return date('Y-m-d H:i:s', time() - ($hours * 3600));
}

function report_climate_num(mixed $v): ?float
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return null;
    }
    return (float)$v;
}

function report_climate_status_label(string $status): string
{
    return match ($status) {
        'ok' => 'OK',
        'warn' => 'Warn',
        'crit' => 'Crit',
        default => '',
    };
}

function report_climate_status_html(string $status): string
{
    $label = report_climate_status_label($status);
    if ($label === '') {
        return '—';
    }
    $badge = match ($status) {
        'crit' => 'badge-danger',
        'warn' => 'badge-warning',
        default => 'badge-success',
    };
    return '<span class="badge ' . $badge . '">' . App::e($label) . '</span>';
}

/**
 * Min, average, and max from stored sensor samples.
 *
 * @return array<int,array<string,mixed>>
 */
function report_climate_sensor_stats(string $which, string $since): array
{
    $tempWhere = "s.sensor_kind IN ('temperature', 'temp_humidity', 'dew_point')
              AND (r.metric IS NULL OR r.metric = '' OR r.metric IN ('temperature', 'temp', 'celsius', 'primary'))";
    $humWhere = "(
                (s.sensor_kind = 'humidity' AND (r.metric IS NULL OR r.metric = '' OR r.metric IN ('humidity', 'primary')))
                OR (s.sensor_kind = 'temp_humidity' AND r.metric = 'humidity')
              )";
    $where = $which === 'humidity' ? $humWhere : $tempWhere;
    $sql = "SELECT r.sensor_id,
                   MIN(r.value) AS min_v,
                   AVG(r.value) AS avg_v,
                   MAX(r.value) AS max_v,
                   COUNT(*) AS samples
            FROM env_readings r
            INNER JOIN env_sensors s ON s.sensor_id = r.sensor_id
            WHERE s.is_active = 1
              AND r.recorded_at >= ?
              AND $where
            GROUP BY r.sensor_id";
    try {
        $rows = Database::fetchAll($sql, [$since]);
    } catch (Throwable $e) {
        $fallback = $which === 'humidity'
            ? "s.sensor_kind = 'humidity'"
            : "s.sensor_kind IN ('temperature', 'temp_humidity', 'dew_point')";
        try {
            $rows = Database::fetchAll(
                "SELECT r.sensor_id,
                        MIN(r.value) AS min_v,
                        AVG(r.value) AS avg_v,
                        MAX(r.value) AS max_v,
                        COUNT(*) AS samples
                 FROM env_readings r
                 INNER JOIN env_sensors s ON s.sensor_id = r.sensor_id
                 WHERE s.is_active = 1
                   AND r.recorded_at >= ?
                   AND $fallback
                 GROUP BY r.sensor_id",
                [$since]
            );
        } catch (Throwable $e2) {
            $rows = [];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['sensor_id']] = $r;
    }
    return $out;
}

/** @return array<int,array<string,mixed>> */
function report_climate_cooling_stats(string $since): array
{
    try {
        $rows = Database::fetchAll(
            'SELECT cooling_unit_id,
                    MIN(supply_temp_c) AS supply_min, AVG(supply_temp_c) AS supply_avg, MAX(supply_temp_c) AS supply_max,
                    MIN(return_temp_c) AS return_min, AVG(return_temp_c) AS return_avg, MAX(return_temp_c) AS return_max,
                    MIN(humidity) AS hum_min, AVG(humidity) AS hum_avg, MAX(humidity) AS hum_max,
                    SUM(CASE WHEN supply_temp_c IS NOT NULL THEN 1 ELSE 0 END) AS supply_n,
                    SUM(CASE WHEN return_temp_c IS NOT NULL THEN 1 ELSE 0 END) AS return_n,
                    SUM(CASE WHEN humidity IS NOT NULL THEN 1 ELSE 0 END) AS hum_n
             FROM cooling_readings
             WHERE polled_at >= ?
             GROUP BY cooling_unit_id',
            [$since]
        );
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['cooling_unit_id']] = $r;
    }
    return $out;
}

/**
 * @param array<string,mixed>|null $stat
 * @return array{min:string,avg:string,max:string,samples:int}
 */
function report_climate_stat_text(?array $stat, string $minKey, string $avgKey, string $maxKey, string $nKey, bool $temp): array
{
    $fmt = $temp
        ? static fn (mixed $v): string => report_fmt_temp($v)
        : static fn (mixed $v): string => report_fmt_rh($v);
    $n = (int)($stat[$nKey] ?? $stat['samples'] ?? 0);
    if ($stat === null || $n < 1) {
        return ['min' => '', 'avg' => '', 'max' => '', 'samples' => 0];
    }
    return [
        'min' => $fmt($stat[$minKey] ?? null),
        'avg' => $fmt($stat[$avgKey] ?? null),
        'max' => $fmt($stat[$maxKey] ?? null),
        'samples' => $n,
    ];
}

/**
 * Temperature or humidity rows for Reports.
 *
 * @return list<array<string,mixed>>
 */
function report_climate(string $which): array
{
    $which = $which === 'humidity' ? 'humidity' : 'temperature';
    $temp = $which === 'temperature';
    $span = report_climate_span();
    $since = report_climate_since($span['hours']);
    $roomId = report_climate_room_id();
    $kinds = env_sensor_kinds();
    $placements = env_sensor_placements();
    $hosts = env_sensor_hosts();
    $unitTypes = cooling_unit_types();
    $sensorStats = report_climate_sensor_stats($which, $since);
    $coolingStats = report_climate_cooling_stats($since);
    $kindSql = $temp
        ? "s.sensor_kind IN ('temperature', 'temp_humidity', 'dew_point')"
        : "s.sensor_kind IN ('humidity', 'temp_humidity')";
    $sensorSql = "SELECT s.*,
                    rm.name AS room_name,
                    cu.name AS cooling_unit_name,
                    p.name AS pdu_name,
                    d.label AS device_label,
                    d.device_type AS device_type,
                    cab.name AS cabinet_name
             FROM env_sensors s
             LEFT JOIN rooms rm ON rm.room_id = s.room_id
             LEFT JOIN cooling_units cu ON cu.cooling_unit_id = s.cooling_unit_id
             LEFT JOIN pdus p ON p.pdu_id = s.pdu_id
             LEFT JOIN devices d ON d.device_id = s.device_id
             LEFT JOIN cabinets cab ON cab.cabinet_id = COALESCE(s.cabinet_id, d.cabinet_id)
             WHERE s.is_active = 1
               AND $kindSql
             ORDER BY s.name";
    $sensorSqlPlain = "SELECT s.*,
                    rm.name AS room_name,
                    cu.name AS cooling_unit_name,
                    p.name AS pdu_name,
                    d.label AS device_label,
                    d.device_type AS device_type
             FROM env_sensors s
             LEFT JOIN rooms rm ON rm.room_id = s.room_id
             LEFT JOIN cooling_units cu ON cu.cooling_unit_id = s.cooling_unit_id
             LEFT JOIN pdus p ON p.pdu_id = s.pdu_id
             LEFT JOIN devices d ON d.device_id = s.device_id
             WHERE s.is_active = 1
               AND $kindSql
             ORDER BY s.name";
    try {
        $sensorRows = Database::fetchAll($sensorSql);
    } catch (Throwable $e) {
        try {
            $sensorRows = Database::fetchAll($sensorSqlPlain);
        } catch (Throwable $e2) {
            $sensorRows = [];
        }
    }

    $rows = [];
    foreach ($sensorRows as $s) {
        $sid = (int)$s['sensor_id'];
        $sensorRoom = (int)($s['room_id'] ?? 0);
        if ($roomId > 0 && $sensorRoom !== $roomId) {
            continue;
        }
        $kind = (string)($s['sensor_kind'] ?? '');
        $placement = $placements[(string)($s['placement'] ?? '')] ?? '';
        if ($temp) {
            $current = report_climate_num($s['last_value'] ?? null);
            $currentText = report_fmt_temp($current);
            $status = function_exists('env_sensor_threshold_status')
                ? env_sensor_threshold_status($current, $s)
                : '';
            if ($status === 'unknown') {
                $status = '';
            }
            $point = $kind === 'dew_point'
                ? ($placement !== '' ? ('Dew point · ' . $placement) : 'Dew point')
                : $placement;
            $stat = report_climate_stat_text($sensorStats[$sid] ?? null, 'min_v', 'avg_v', 'max_v', 'samples', true);
        } else {
            $fromHumidity = report_climate_num($s['last_humidity'] ?? null);
            $fromValue = report_climate_num($s['last_value'] ?? null);
            $current = $kind === 'humidity' ? ($fromHumidity ?? $fromValue) : $fromHumidity;
            $currentText = report_fmt_rh($current);
            $status = '';
            if ($kind === 'humidity' && function_exists('env_sensor_threshold_status')) {
                $status = env_sensor_threshold_status($current, $s);
                if ($status === 'unknown') {
                    $status = '';
                }
            }
            $point = $placement !== '' ? $placement : 'Humidity';
            $stat = report_climate_stat_text($sensorStats[$sid] ?? null, 'min_v', 'avg_v', 'max_v', 'samples', false);
        }
        $rows[] = [
            'source' => 'Sensor',
            'name' => (string)($s['name'] ?? ''),
            'href' => 'pages/env_sensors.php?id=' . $sid,
            'room' => (string)($s['room_name'] ?? ''),
            'point' => $point,
            'host' => env_sensor_host_display($s, $hosts),
            'location' => (string)($s['location_label'] ?? ''),
            'current' => $currentText,
            'min' => $stat['min'],
            'avg' => $stat['avg'],
            'max' => $stat['max'],
            'samples' => $stat['samples'],
            'status' => $status,
            'seen' => (string)($s['last_seen_at'] ?? ''),
            'kind' => env_sensor_kind_short($kind, $kinds),
        ];
    }

    try {
        $units = Database::fetchAll(
            'SELECT u.cooling_unit_id, u.name, u.unit_type, u.room_id, u.snmp_last_poll_at,
                    u.last_poll_json, rm.name AS room_name
             FROM cooling_units u
             LEFT JOIN rooms rm ON rm.room_id = u.room_id
             WHERE u.is_active = 1
             ORDER BY u.name'
        );
    } catch (Throwable $e) {
        $units = [];
    }
    foreach ($units as $u) {
        $uid = (int)$u['cooling_unit_id'];
        if ($roomId > 0 && (int)($u['room_id'] ?? 0) !== $roomId) {
            continue;
        }
        $snap = function_exists('cooling_poll_snapshot_promote')
            ? cooling_poll_snapshot_promote($u['last_poll_json'] ?? null)
            : [];
        $stats = $coolingStats[$uid] ?? null;
        $typeLabel = $unitTypes[(string)($u['unit_type'] ?? '')] ?? (string)($u['unit_type'] ?? '');
        $seen = (string)($u['snmp_last_poll_at'] ?? '');
        $points = $temp
            ? [
                ['Supply', 'supply_temp', 'supply_min', 'supply_avg', 'supply_max', 'supply_n', true],
                ['Return', 'return_temp', 'return_min', 'return_avg', 'return_max', 'return_n', true],
            ]
            : [
                ['Humidity', 'humidity', 'hum_min', 'hum_avg', 'hum_max', 'hum_n', false],
            ];
        foreach ($points as $p) {
            [$point, $snapKey, $minKey, $avgKey, $maxKey, $nKey, $isTemp] = $p;
            $current = report_climate_num($snap[$snapKey] ?? null);
            $stat = report_climate_stat_text($stats, $minKey, $avgKey, $maxKey, $nKey, $isTemp);
            if ($current === null && $stat['samples'] < 1) {
                continue;
            }
            $rows[] = [
                'source' => 'Cooling unit',
                'name' => (string)($u['name'] ?? ''),
                'href' => 'pages/cooling_units.php?id=' . $uid,
                'room' => (string)($u['room_name'] ?? ''),
                'point' => $point,
                'host' => $typeLabel,
                'location' => '',
                'current' => $isTemp ? report_fmt_temp($current) : report_fmt_rh($current),
                'min' => $stat['min'],
                'avg' => $stat['avg'],
                'max' => $stat['max'],
                'samples' => $stat['samples'],
                'status' => '',
                'seen' => $seen,
                'kind' => '',
            ];
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $rank = ['crit' => 0, 'warn' => 1, 'ok' => 2];
        $ra = $rank[$a['status']] ?? 3;
        $rb = $rank[$b['status']] ?? 3;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $cmp = strcasecmp((string)$a['room'], (string)$b['room']);
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcasecmp((string)$a['name'], (string)$b['name']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcasecmp((string)$a['point'], (string)$b['point']);
    });
    return $rows;
}

function report_emit_csv(string $report): never
{
    $stamp = date('Ymd-His');
    $file = 'coldaisle-report-' . preg_replace('/\W+/', '-', $report) . '-' . $stamp . '.csv';

    if ($report === 'inventory_summary') {
        $data = report_inventory_summary();
        $rows = [];
        foreach ($data['by_type'] as $r) {
            $rows[] = ['type', (string)$r['device_type'], (int)$r['cnt']];
        }
        foreach ($data['by_status'] as $r) {
            $rows[] = ['status', (string)$r['status'], (int)$r['cnt']];
        }
        foreach ($data['by_dc'] as $r) {
            $rows[] = ['data_center', (string)$r['name'], (int)$r['cnt']];
        }
        ListPager::sendCsv($file, ['Group', 'Name', 'Count'], $rows);
    }

    if ($report === 'cabinet_utilization') {
        $rows = [];
        foreach (report_cabinet_utilization() as $r) {
            $h = (int)$r['u_height'];
            $u = (int)$r['u_used'];
            $pct = $h > 0 ? round(100 * $u / $h, 1) : 0;
            $rows[] = [
                $r['name'] ?? '',
                $r['dc_name'] ?? '',
                $r['room_name'] ?? '',
                $h,
                $u,
                $h - $u,
                $pct,
            ];
        }
        ListPager::sendCsv($file, ['Cabinet', 'Data center', 'Room', 'U height', 'Used U', 'Free U', 'Percent'], $rows);
    }

    if ($report === 'power_capacity') {
        $needKw = isset($_GET['need_kw']) && $_GET['need_kw'] !== '' ? (float)$_GET['need_kw'] : 0.0;
        $needU = isset($_GET['need_u']) && $_GET['need_u'] !== '' ? (int)$_GET['need_u'] : 0;
        $data = report_power_capacity(max(0, $needKw), max(0, $needU));
        $sheet = strtolower(trim((string)($_GET['sheet'] ?? 'zones')));
        if ($sheet === 'pdus') {
            $rows = [];
            foreach ($data['pdus'] as $p) {
                $rows[] = [
                    $p['name'] ?? '',
                    $p['pdu_scope'] ?? '',
                    $p['zone_name'] ?? '',
                    $p['cabinet_name'] ?? '',
                    $p['rated_volts'] ?? '',
                    $p['rated_amps'] ?? '',
                    $p['last_poll_watts'] ?? '',
                    $p['last_poll_amps'] ?? '',
                    $p['last_poll_at'] ?? '',
                ];
            }
            ListPager::sendCsv($file, ['Name', 'Scope', 'Zone', 'Cabinet', 'Rated V', 'Rated A', 'Last W', 'Last A', 'Polled'], $rows);
        }
        $src = ($needKw > 0 || $needU > 0) ? $data['fits'] : $data['zones'];
        $rows = [];
        foreach ($src as $z) {
            $rows[] = [
                $z['name'] ?? '',
                $z['dc_name'] ?? '',
                $z['feed_type'] ?? '',
                $z['load_kw'] ?? '',
                $z['max_kw'] ?? '',
                $z['free_kw'] ?? '',
                $z['util_pct'] ?? '',
                $z['free_u'] ?? '',
                $z['u_total'] ?? '',
                function_exists('power_format_phase_amps') ? power_format_phase_amps($z['phase_amps'] ?? []) : '',
                $z['imbalance']['label'] ?? '',
            ];
        }
        ListPager::sendCsv($file, [
            'Zone', 'Data center', 'Feed', 'Load kW', 'Max kW', 'Free kW', 'Util %', 'Free U', 'U total', 'Phases A', 'Balance',
        ], $rows);
    }

    if ($report === 'warranty_expiration') {
        $days = max(0, min(730, (int)($_GET['days'] ?? SettingsService::get('warranty_notify_days', '60'))));
        $filter = strtolower(trim((string)($_GET['wfilter'] ?? 'window')));
        if (!in_array($filter, ['all', 'soon', 'expired', 'window'], true)) {
            $filter = 'window';
        }
        $rows = [];
        foreach (report_warranty($filter, $days) as $r) {
            $dLeft = class_exists('AssetLifecycleService')
                ? AssetLifecycleService::daysUntil(isset($r['warranty_end']) ? (string)$r['warranty_end'] : null)
                : null;
            $rows[] = [
                $r['kind'] ?? 'device',
                $r['label'] ?? '',
                $r['asset_tag'] ?? '',
                trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? '')),
                $r['serial_no'] ?? '',
                $r['warranty_provider'] ?? '',
                $r['warranty_end'] ?? '',
                $dLeft,
                $r['department_name'] ?? '',
                $r['status'] ?? '',
            ];
        }
        ListPager::sendCsv($file, [
            'Kind', 'Name', 'Asset tag', 'Make/Model', 'Serial', 'Provider', 'Warranty end', 'Days remaining', 'Department', 'Status',
        ], $rows);
    }

    if ($report === 'disposal_queue') {
        $rows = [];
        foreach (report_disposal_queue() as $r) {
            $rows[] = [
                $r['device_label'] ?? '',
                $r['status'] ?? '',
                $r['method'] ?? '',
                $r['scheduled_date'] ?? '',
                $r['reason'] ?? '',
            ];
        }
        ListPager::sendCsv($file, ['Device', 'Status', 'Method', 'Scheduled', 'Reason'], $rows);
    }

    if ($report === 'cable_inventory') {
        $rows = [];
        foreach (report_cables() as $r) {
            $rows[] = [
                $r['cable_label'] ?? '',
                trim(($r['a_device'] ?? '') . ' / ' . ($r['a_port'] ?? ''), ' /'),
                trim(($r['b_device'] ?? '') . ' / ' . ($r['b_port'] ?? ''), ' /'),
                $r['media_type'] ?? '',
                $r['length_m'] ?? '',
                $r['status'] ?? '',
            ];
        }
        ListPager::sendCsv($file, ['Label', 'A', 'B', 'Media', 'Length m', 'Status'], $rows);
    }

    if ($report === 'orphaned_devices') {
        $rows = [];
        foreach (report_orphans() as $r) {
            $rows[] = [
                $r['label'] ?? '',
                $r['device_type'] ?? '',
                $r['status'] ?? '',
                $r['serial_no'] ?? '',
                $r['asset_tag'] ?? '',
            ];
        }
        ListPager::sendCsv($file, ['Label', 'Type', 'Status', 'Serial', 'Asset tag'], $rows);
    }

    if ($report === 'audit_history') {
        $rows = [];
        foreach (report_audit_history() as $r) {
            $rows[] = [
                $r['name'] ?? '',
                $r['audit_type'] ?? '',
                $r['completed_at'] ?? '',
                $r['findings_summary'] ?? '',
            ];
        }
        ListPager::sendCsv($file, ['Name', 'Type', 'Completed', 'Findings'], $rows);
    }

    if ($report === 'power_path') {
        $zone = isset($_GET['zone_id']) && $_GET['zone_id'] !== '' ? (int)$_GET['zone_id'] : 0;
        $view = strtolower(trim((string)($_GET['view'] ?? 'all')));
        if (!in_array($view, ['all', 'unmapped', 'single_feed', 'half_map', 'no_row_feed'], true)) {
            $view = 'all';
        }
        $pp = class_exists('PowerPathService')
            ? PowerPathService::report(['zone_id' => $zone, 'view' => $view])
            : ['paths' => []];
        $rows = [];
        foreach ($pp['paths'] ?? [] as $pr) {
            $status = 'OK';
            if (!empty($pr['half_map'])) {
                $status = 'Half-map';
            } elseif (empty($pr['mapped'])) {
                $status = 'Unmapped';
            } elseif (empty($pr['has_row_feed']) && !empty($pr['cabinet_id'])) {
                $status = 'No row feed';
            }
            $outlet = '';
            if (!empty($pr['mapped'])) {
                $outlet = (string)($pr['rack_pdu_name'] ?? '');
                $ol = (string)(($pr['outlet_label'] ?? '') !== ''
                    ? $pr['outlet_label']
                    : ('#' . ($pr['outlet_number'] ?? '')));
                $outlet .= ($outlet !== '' ? ' · ' : '') . $ol;
            }
            $rows[] = [
                $pr['device_label'] ?? '',
                $pr['device_type'] ?? '',
                $pr['psu_name'] ?? '',
                $pr['cabinet_name'] ?? '',
                $outlet,
                $pr['row_feed_summary'] ?? '',
                $pr['zone_name'] ?? '',
                $pr['feed_type'] ?? '',
                $pr['ups_summary'] ?? '',
                $status,
            ];
        }
        ListPager::sendCsv($file, [
            'Device', 'Type', 'PSU', 'Cabinet', 'Rack PDU / outlet', 'Row/room feed', 'Zone', 'Feed type', 'UPS', 'Status',
        ], $rows);
    }

    if ($report === 'work_orders') {
        $types = work_order_types();
        $statuses = work_order_statuses();
        $rows = [];
        foreach (report_work_orders() as $r) {
            $rows[] = [
                $r['title'] ?? '',
                $types[$r['work_type'] ?? ''] ?? ($r['work_type'] ?? ''),
                $r['change_ticket'] ?? '',
                $r['itsm_display_id'] ?? '',
                $r['itsm_provider'] ?? '',
                $statuses[$r['status'] ?? ''] ?? ($r['status'] ?? ''),
                $r['scheduled_date'] ?? '',
                $r['item_count'] ?? 0,
                $r['updated_at'] ?? '',
            ];
        }
        ListPager::sendCsv($file, [
            'Title', 'Type', 'Ticket', 'ITSM id', 'ITSM', 'Status', 'Scheduled', 'Devices', 'Updated',
        ], $rows);
    }

    if ($report === 'cooling') {
        $data = report_cooling();
        $rows = [];
        foreach ($data['units'] as $r) {
            $rows[] = [
                'Cooling unit',
                $r['name'],
                $r['room'],
                $r['type'],
                $r['role'],
                '',
                $r['state'],
                $r['supply'],
                $r['return'],
                '',
                $r['humidity'],
                $r['humidity_setpoint'],
                $r['cooling_pct'],
                $r['polled'],
            ];
        }
        foreach ($data['sensors'] as $r) {
            $rows[] = [
                'Sensor',
                $r['name'],
                $r['room'],
                $r['kind'],
                $r['placement'],
                $r['host'],
                '',
                '',
                '',
                $r['temperature'],
                $r['humidity'],
                '',
                '',
                $r['seen'],
            ];
        }
        ListPager::sendCsv($file, [
            'Section', 'Name', 'Room', 'Type', 'Role or placement', 'Host', 'State',
            'Supply', 'Return', 'Temperature', 'Humidity', 'Humidity setpoint', 'Cooling %', 'Last reading',
        ], $rows);
    }

    if ($report === 'temperature' || $report === 'humidity') {
        $rows = [];
        foreach (report_climate($report) as $r) {
            $rows[] = [
                $r['source'],
                $r['name'],
                $r['room'],
                $r['point'],
                $r['host'],
                $r['location'],
                $r['current'],
                $r['min'],
                $r['avg'],
                $r['max'],
                $r['samples'],
                report_climate_status_label((string)$r['status']),
                $r['seen'],
            ];
        }
        ListPager::sendCsv($file, [
            'Source', 'Name', 'Room', 'Point', 'Host', 'Location',
            'Current', 'Min', 'Avg', 'Max', 'Samples', 'Status', 'Last reading',
        ], $rows);
    }

    App::flash('error', 'That report cannot be exported as CSV.');
    App::redirect('pages/reports.php' . ($report !== '' ? '?report=' . rawurlencode($report) : ''));
}

$catalog = report_catalog();
$execMode = strtolower(trim((string)($_GET['mode'] ?? ''))) === 'executive';
if ($execMode && isset($catalog[$report]) && strtolower(trim((string)($_GET['export'] ?? ''))) === 'pdf') {
    report_exec_send_pdf(report_exec_brief((string)$report));
}

if (class_exists('ListPager') && ListPager::wantsCsv() && $report !== '' && $report !== 'power_history') {
    report_emit_csv((string)$report);
}

layout_header('Reports', $user, 'reports');

// Power path filters
$ppZone = isset($_GET['zone_id']) && $_GET['zone_id'] !== '' ? (int)$_GET['zone_id'] : 0;
$ppView = strtolower(trim((string)($_GET['view'] ?? 'all')));
if (!in_array($ppView, ['all', 'unmapped', 'single_feed', 'half_map', 'no_row_feed'], true)) {
    $ppView = 'all';
}
?>

<div class="card">
    <div class="card-header"><h2>Report Catalog</h2></div>
    <div class="card-body">
        <div class="metrics">
            <?php foreach ($catalog as $key => $label): ?>
                <div class="metric-card catalog-card<?= $report === $key ? ' is-on' : '' ?>">
                    <div class="label">Report</div>
                    <div class="value"><?= App::e($label) ?></div>
                    <div class="catalog-picks">
                        <a class="<?= ($report === $key && !$execMode) ? 'is-on' : '' ?>" href="<?= App::e('?report=' . urlencode($key)) ?>">Detail</a>
                        <a class="<?= ($report === $key && $execMode) ? 'is-on' : '' ?>" href="<?= App::e('?report=' . urlencode($key) . '&mode=executive') ?>">Executive</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($report !== '' && isset($catalog[$report])): ?>
<div class="report-mode">
    <strong><?= App::e($catalog[$report]) ?></strong>
    <div class="report-mode-switch">
        <a class="<?= $execMode ? '' : 'is-on' ?>" href="<?= App::e(report_mode_href(false)) ?>">Detail</a>
        <a class="<?= $execMode ? 'is-on' : '' ?>" href="<?= App::e(report_mode_href(true)) ?>">Executive</a>
    </div>
</div>
<?php endif; ?>

<?php if ($execMode && isset($catalog[$report])):
    $brief = report_exec_brief((string)$report);
    $briefCharts = is_array($brief['charts'] ?? null) ? $brief['charts'] : [];
    $briefTable = is_array($brief['table'] ?? null) ? $brief['table'] : [];
    $briefRows = is_array($briefTable['rows'] ?? null) ? $briefTable['rows'] : [];
    $briefHeads = is_array($briefTable['headers'] ?? null) ? $briefTable['headers'] : [];
    ?>
<div class="card mb-2">
    <div class="card-header flex-between">
        <h2 style="margin:0"><?= App::e((string)($brief['title'] ?? 'Executive')) ?></h2>
        <div class="flex gap-1">
            <a class="btn btn-sm btn-primary" href="<?= App::e(report_exec_pdf_url()) ?>">Export PDF</a>
            <?php if ($report === 'power_history'):
                $csvQs = http_build_query(array_filter([
                    'scope' => $phScope,
                    'id' => $phId,
                    'preset' => $phPreset !== 'custom' ? $phPreset : null,
                    'from' => $phPreset === 'custom' ? $phFrom : null,
                    'to' => $phPreset === 'custom' ? $phTo : null,
                    'format' => 'csv',
                ], static fn($v) => $v !== null && $v !== ''));
                ?>
                <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('api/power_history.php?' . $csvQs)) ?>">Export CSV</a>
            <?php else: ?>
                <?php report_export_btn(); ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <p style="margin-top:0"><?= App::e((string)($brief['lead'] ?? '')) ?></p>
        <?php if (trim((string)($brief['note'] ?? '')) !== ''): ?>
            <p class="text-muted" style="margin-top:0"><?= App::e((string)$brief['note']) ?></p>
        <?php endif; ?>
        <?php if ($report === 'temperature' || $report === 'humidity'):
            $execSpan = report_climate_span();
            $execRoomId = report_climate_room_id();
            $execRooms = report_climate_rooms();
            ?>
            <form method="get" class="form-grid">
                <input type="hidden" name="report" value="<?= App::e($report) ?>">
                <input type="hidden" name="mode" value="executive">
                <div class="form-row"><label>Period</label>
                    <select class="form-control" name="span">
                        <option value="24h"<?= $execSpan['span'] === '24h' ? ' selected' : '' ?>>Last 24 hours</option>
                        <option value="7d"<?= $execSpan['span'] === '7d' ? ' selected' : '' ?>>Last 7 days</option>
                        <option value="30d"<?= $execSpan['span'] === '30d' ? ' selected' : '' ?>>Last 30 days</option>
                    </select>
                </div>
                <div class="form-row"><label>Room</label>
                    <select class="form-control" name="room_id">
                        <option value="">All rooms</option>
                        <?php foreach ($execRooms as $rm): ?>
                            <?php
                            $rid = (int)$rm['room_id'];
                            $dcName = trim((string)($rm['dc_name'] ?? ''));
                            $roomLabel = $dcName !== '' ? ($dcName . ' / ' . (string)$rm['name']) : (string)$rm['name'];
                            ?>
                            <option value="<?= $rid ?>"<?= $execRoomId === $rid ? ' selected' : '' ?>><?= App::e($roomLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row" style="align-self:end">
                    <button class="btn btn-primary" type="submit">Apply</button>
                    <?php if ($execSpan['span'] !== '7d' || $execRoomId > 0): ?>
                        <a class="btn btn-secondary" href="<?= App::e('?report=' . urlencode($report) . '&mode=executive') ?>">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        <?php elseif ($report === 'power_history'): ?>
            <form method="get" class="form-grid" id="power-history-report-form">
                <input type="hidden" name="report" value="power_history">
                <input type="hidden" name="mode" value="executive">
                <div class="form-row"><label>Range</label>
                    <select class="form-control" name="preset" id="ph_preset">
                        <option value="week" <?= $phPreset === 'week' ? 'selected' : '' ?>>Last 7 days</option>
                        <option value="month" <?= $phPreset === 'month' ? 'selected' : '' ?>>Last 31 days</option>
                        <option value="year" <?= $phPreset === 'year' ? 'selected' : '' ?>>Last 365 days</option>
                        <option value="custom" <?= $phPreset === 'custom' ? 'selected' : '' ?>>Custom range</option>
                    </select>
                </div>
                <div class="form-row ph-custom" <?= $phPreset !== 'custom' ? 'style="display:none"' : '' ?>><label>From</label>
                    <input class="form-control" type="date" name="from" value="<?= App::e($phFrom) ?>"></div>
                <div class="form-row ph-custom" <?= $phPreset !== 'custom' ? 'style="display:none"' : '' ?>><label>To</label>
                    <input class="form-control" type="date" name="to" value="<?= App::e($phTo) ?>"></div>
                <div class="form-row"><label>Scope</label>
                    <select class="form-control" name="scope" id="ph_scope">
                        <option value="site" <?= $phScope === 'site' ? 'selected' : '' ?>>Entire site</option>
                        <option value="zone" <?= $phScope === 'zone' ? 'selected' : '' ?>>Zone</option>
                        <option value="pdu" <?= $phScope === 'pdu' ? 'selected' : '' ?>>PDU</option>
                    </select>
                </div>
                <div class="form-row" id="ph_zone_row" <?= $phScope !== 'zone' ? 'style="display:none"' : '' ?>><label>Zone</label>
                    <select class="form-control" name="id" id="ph_zone_id" <?= $phScope !== 'zone' ? 'disabled' : '' ?>>
                        <option value="">— select —</option>
                        <?php foreach ($phZones as $z): ?>
                            <option value="<?= (int)$z['zone_id'] ?>" <?= $phScope === 'zone' && $phId === (int)$z['zone_id'] ? 'selected' : '' ?>><?= App::e($z['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row" id="ph_pdu_row" <?= $phScope !== 'pdu' ? 'style="display:none"' : '' ?>><label>PDU</label>
                    <select class="form-control" name="id" id="ph_pdu_id" <?= $phScope !== 'pdu' ? 'disabled' : '' ?>>
                        <option value="">— select —</option>
                        <?php foreach ($phPdus as $p): ?>
                            <option value="<?= (int)$p['pdu_id'] ?>" <?= $phScope === 'pdu' && $phId === (int)$p['pdu_id'] ? 'selected' : '' ?>><?= App::e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row" style="align-self:end">
                    <button class="btn btn-primary" type="submit">Apply</button>
                </div>
            </form>
            <script>
            (function () {
                var preset = document.getElementById('ph_preset');
                var scope = document.getElementById('ph_scope');
                function syncCustom() {
                    var custom = preset && preset.value === 'custom';
                    document.querySelectorAll('.ph-custom').forEach(function (el) {
                        el.style.display = custom ? '' : 'none';
                    });
                }
                function syncScope() {
                    var v = scope ? scope.value : 'site';
                    var zr = document.getElementById('ph_zone_row');
                    var pr = document.getElementById('ph_pdu_row');
                    var zi = document.getElementById('ph_zone_id');
                    var pi = document.getElementById('ph_pdu_id');
                    if (zr) zr.style.display = v === 'zone' ? '' : 'none';
                    if (pr) pr.style.display = v === 'pdu' ? '' : 'none';
                    if (zi) { zi.disabled = v !== 'zone'; if (v !== 'zone') zi.name = ''; else zi.name = 'id'; }
                    if (pi) { pi.disabled = v !== 'pdu'; if (v !== 'pdu') pi.name = ''; else pi.name = 'id'; }
                    if (v === 'site') {
                        if (zi) zi.name = '';
                        if (pi) pi.name = '';
                    }
                }
                if (preset) preset.addEventListener('change', syncCustom);
                if (scope) scope.addEventListener('change', syncScope);
                syncCustom();
                syncScope();
            })();
            </script>
        <?php elseif ($report === 'power_path'):
            $execZones = [];
            try {
                $execZones = Database::fetchAll('SELECT zone_id, name FROM power_zones ORDER BY name');
            } catch (Throwable $e) {
                $execZones = [];
            }
            ?>
            <form method="get" class="form-grid">
                <input type="hidden" name="report" value="power_path">
                <input type="hidden" name="mode" value="executive">
                <div class="form-row"><label>Zone</label>
                    <select class="form-control" name="zone_id">
                        <option value="">All zones</option>
                        <?php foreach ($execZones as $z): ?>
                            <option value="<?= (int)$z['zone_id'] ?>"<?= $ppZone === (int)$z['zone_id'] ? ' selected' : '' ?>><?= App::e((string)$z['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row" style="align-self:end">
                    <button class="btn btn-primary" type="submit">Apply</button>
                </div>
            </form>
        <?php endif; ?>
        <?php if (array_key_exists('has_samples', $brief) && empty($brief['has_samples'])): ?>
            <p class="text-muted" style="font-size:.85rem;margin:.75rem 0 0">
                No samples were stored in this period. The figures above are the latest reading on each sensor.
            </p>
        <?php endif; ?>
    </div>
</div>
<?php if (!empty($brief['kpis'])): ?>
<div class="metrics">
    <?php foreach ($brief['kpis'] as $kpi): ?>
        <div class="metric-card <?= App::e((string)($kpi['tone'] ?? '')) ?>">
            <div class="label"><?= App::e((string)($kpi['label'] ?? '')) ?></div>
            <div class="value"><?= App::e((string)($kpi['value'] ?? '')) ?></div>
            <div class="sub"><?= App::e((string)($kpi['sub'] ?? '')) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php foreach ($briefCharts as $chart): ?>
    <?php if (!is_array($chart)) { continue; } ?>
    <div class="card mb-2">
        <div class="card-header"><h2 style="margin:0"><?= App::e((string)($chart['title'] ?? 'Chart')) ?></h2></div>
        <div class="card-body">
            <div class="exec-chart-frame">
                <?= report_exec_svg($chart, (string)($brief['context'] ?? '')) ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if ($briefRows !== []): ?>
<div class="card">
    <div class="card-header"><h2 style="margin:0"><?= App::e((string)($briefTable['title'] ?? 'Detail')) ?></h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <?php foreach ($briefHeads as $head): ?>
                    <th><?= App::e((string)$head) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($briefRows as $row): ?>
                <?php if (!is_array($row)) { continue; } ?>
                <tr>
                    <?php foreach ($row as $cell): ?>
                        <?php $cellText = (string)$cell; $cellKey = strtolower($cellText); ?>
                        <td><?php
                            if (in_array($cellKey, ['crit', 'warn', 'ok'], true)) {
                                echo report_climate_status_html($cellKey);
                            } else {
                                echo App::e($cellText);
                            }
                        ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif ($report === 'power_history'):
    $hoursMap = ['week' => 24 * 7, 'month' => 24 * 31, 'year' => 24 * 365];
    $phHours = $hoursMap[$phPreset] ?? 24 * 7;
    $phFromArg = $phPreset === 'custom' ? $phFrom : null;
    $phToArg = $phPreset === 'custom' ? $phTo : null;
    $phData = ['ok' => false, 'series' => ['t' => []], 'summary' => [], 'outages' => []];
    if (class_exists('PowerHistoryService')) {
        $phData = PowerHistoryService::series(
            $phScope,
            $phId,
            $phHours,
            $phFromArg,
            $phToArg
        );
    }
    $phSummary = $phData['summary'] ?? [];
    $phRows = class_exists('PowerHistoryService') ? PowerHistoryService::tableRows($phData) : [];
    $csvQs = http_build_query(array_filter([
        'scope' => $phScope,
        'id' => $phId,
        'preset' => $phPreset !== 'custom' ? $phPreset : null,
        'from' => $phPreset === 'custom' ? $phFrom : null,
        'to' => $phPreset === 'custom' ? $phTo : null,
        'format' => 'csv',
    ], static fn($v) => $v !== null && $v !== ''));
    $chartHours = (int)($phData['hours'] ?? $phHours);
    $chartFrom = $phPreset === 'custom' ? $phFrom : '';
    $chartTo = $phPreset === 'custom' ? $phTo : '';
    ?>
<div class="card mb-2">
    <div class="card-header flex-between">
        <h2 style="margin:0">Power History</h2>
        <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('api/power_history.php?' . $csvQs)) ?>">Export CSV</a>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.9rem;margin-top:0">
            Historical load and voltage from SNMP samples (retention ~<?= (int)(class_exists('PowerHistoryService') ? PowerHistoryService::RETENTION_DAYS : 400) ?> days).
            Weekly / monthly / annual presets or a custom date range.
        </p>
        <form method="get" class="form-grid" id="power-history-report-form">
            <input type="hidden" name="report" value="power_history">
            <div class="form-row"><label>Range</label>
                <select class="form-control" name="preset" id="ph_preset">
                    <option value="week" <?= $phPreset === 'week' ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="month" <?= $phPreset === 'month' ? 'selected' : '' ?>>Last 31 days</option>
                    <option value="year" <?= $phPreset === 'year' ? 'selected' : '' ?>>Last 365 days</option>
                    <option value="custom" <?= $phPreset === 'custom' ? 'selected' : '' ?>>Custom range</option>
                </select>
            </div>
            <div class="form-row ph-custom" <?= $phPreset !== 'custom' ? 'style="display:none"' : '' ?>><label>From</label>
                <input class="form-control" type="date" name="from" value="<?= App::e($phFrom) ?>"></div>
            <div class="form-row ph-custom" <?= $phPreset !== 'custom' ? 'style="display:none"' : '' ?>><label>To</label>
                <input class="form-control" type="date" name="to" value="<?= App::e($phTo) ?>"></div>
            <div class="form-row"><label>Scope</label>
                <select class="form-control" name="scope" id="ph_scope">
                    <option value="site" <?= $phScope === 'site' ? 'selected' : '' ?>>Entire site</option>
                    <option value="zone" <?= $phScope === 'zone' ? 'selected' : '' ?>>Zone</option>
                    <option value="pdu" <?= $phScope === 'pdu' ? 'selected' : '' ?>>PDU</option>
                </select>
            </div>
            <div class="form-row" id="ph_zone_row" <?= $phScope !== 'zone' ? 'style="display:none"' : '' ?>><label>Zone</label>
                <select class="form-control" name="id" id="ph_zone_id" <?= $phScope !== 'zone' ? 'disabled' : '' ?>>
                    <option value="">— select —</option>
                    <?php foreach ($phZones as $z): ?>
                        <option value="<?= (int)$z['zone_id'] ?>" <?= $phScope === 'zone' && $phId === (int)$z['zone_id'] ? 'selected' : '' ?>>
                            <?= App::e($z['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row" id="ph_pdu_row" <?= $phScope !== 'pdu' ? 'style="display:none"' : '' ?>><label>PDU</label>
                <select class="form-control" name="id" id="ph_pdu_id" <?= $phScope !== 'pdu' ? 'disabled' : '' ?>>
                    <option value="">— select —</option>
                    <?php foreach ($phPdus as $p): ?>
                        <option value="<?= (int)$p['pdu_id'] ?>" <?= $phScope === 'pdu' && $phId === (int)$p['pdu_id'] ? 'selected' : '' ?>>
                            <?= App::e($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row" style="align-self:end">
                <button class="btn btn-primary" type="submit">Run report</button>
            </div>
        </form>
    </div>
</div>

<div class="metrics power-metrics">
    <div class="metric-card warning">
        <div class="label">Peak load</div>
        <div class="value"><?= isset($phSummary['kw']['max']) ? number_format((float)$phSummary['kw']['max'], 2) : '—' ?> <span class="metric-unit">kW</span></div>
        <div class="sub">avg <?= isset($phSummary['kw']['avg']) ? number_format((float)$phSummary['kw']['avg'], 2) : '—' ?> · min <?= isset($phSummary['kw']['min']) ? number_format((float)$phSummary['kw']['min'], 2) : '—' ?></div>
    </div>
    <div class="metric-card accent">
        <div class="label">Input voltage</div>
        <div class="value"><?= isset($phSummary['volts']['avg']) ? number_format((float)$phSummary['volts']['avg'], 0) : '—' ?> <span class="metric-unit">V avg</span></div>
        <div class="sub">peak <?= isset($phSummary['volts']['max']) ? number_format((float)$phSummary['volts']['max'], 0) : '—' ?> · min <?= isset($phSummary['volts']['min']) ? number_format((float)$phSummary['volts']['min'], 0) : '—' ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Samples</div>
        <div class="value"><?= (int)($phSummary['sample_count'] ?? 0) ?></div>
        <div class="sub"><?= (int)($phSummary['points'] ?? 0) ?> chart buckets · <?= (int)($phSummary['bucket_minutes'] ?? 0) ?> min</div>
    </div>
    <div class="metric-card <?= !empty($phSummary['outage_events']) ? 'danger' : 'success' ?>">
        <div class="label">Outage events</div>
        <div class="value"><?= (int)($phSummary['outage_events'] ?? 0) ?></div>
        <div class="sub">phase dead / low-V / overload</div>
    </div>
</div>

<div class="card power-history-wide mb-2"
     data-power-history
     data-scope="<?= App::e($phScope) ?>"
     <?= $phId ? 'data-id="' . (int)$phId . '"' : '' ?>
     <?php if ($phPreset === 'custom'): ?>
        data-from="<?= App::e($chartFrom) ?>"
        data-to="<?= App::e($chartTo) ?>"
     <?php else: ?>
        data-preset="<?= App::e($phPreset) ?>"
        data-hours="<?= (int)$chartHours ?>"
     <?php endif; ?>>
    <div class="card-header flex-between">
        <h2 style="margin:0;font-size:1.05rem">Load &amp; voltage</h2>
        <span class="text-muted" style="font-size:.8rem">
            <?= App::e(($phData['from'] ?? '') . ' → ' . ($phData['to'] ?? '')) ?>
        </span>
    </div>
    <div class="card-body power-history-body">
        <div class="power-outage-summary" data-outage-summary hidden></div>
        <div class="power-chart power-chart-lg" data-metric="kw" data-unit="kW" data-label="Output (usage)" data-color="#38bdf8" data-height="220"></div>
        <div class="power-chart power-chart-lg" data-metric="volts" data-unit="V" data-label="Input voltage (avg L–N)" data-color="#a78bfa" data-height="180"></div>
    </div>
</div>

<div class="card">
    <div class="card-header flex-between">
        <h2>Bucket table</h2>
        <span class="text-muted" style="font-size:.85rem"><?= count($phRows) ?> rows</span>
    </div>
    <div class="card-body flush" style="max-height:28rem;overflow:auto">
        <table class="data">
            <thead><tr><th>Time</th><th>kW</th><th>Watts</th><th>Volts</th><th>Amps</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($phRows) as $r): ?>
                <tr>
                    <td><?= App::e((string)$r['time']) ?></td>
                    <td><?= $r['kw'] !== null ? App::e((string)$r['kw']) : '—' ?></td>
                    <td><?= $r['watts'] !== null ? App::e((string)$r['watts']) : '—' ?></td>
                    <td><?= $r['volts'] !== null ? App::e((string)$r['volts']) : '—' ?></td>
                    <td><?= $r['amps'] !== null ? App::e((string)$r['amps']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$phRows): ?>
                <tr><td colspan="5" class="text-muted">No samples in this range. Poll PDUs with SNMP to build history.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function () {
    var preset = document.getElementById('ph_preset');
    var scope = document.getElementById('ph_scope');
    function syncCustom() {
        var custom = preset && preset.value === 'custom';
        document.querySelectorAll('.ph-custom').forEach(function (el) {
            el.style.display = custom ? '' : 'none';
        });
    }
    function syncScope() {
        var v = scope ? scope.value : 'site';
        var zr = document.getElementById('ph_zone_row');
        var pr = document.getElementById('ph_pdu_row');
        var zi = document.getElementById('ph_zone_id');
        var pi = document.getElementById('ph_pdu_id');
        if (zr) zr.style.display = v === 'zone' ? '' : 'none';
        if (pr) pr.style.display = v === 'pdu' ? '' : 'none';
        if (zi) { zi.disabled = v !== 'zone'; if (v !== 'zone') zi.name = ''; else zi.name = 'id'; }
        if (pi) { pi.disabled = v !== 'pdu'; if (v !== 'pdu') pi.name = ''; else pi.name = 'id'; }
        if (v === 'site') {
            if (zi) zi.name = '';
            if (pi) pi.name = '';
        }
    }
    if (preset) preset.addEventListener('change', syncCustom);
    if (scope) scope.addEventListener('change', syncScope);
    syncCustom();
    syncScope();
})();
</script>
<script src="<?= App::e(App::url('assets/js/power-charts.js')) ?>?v=4"></script>

<?php elseif ($report === 'power_path'):
    $ppData = class_exists('PowerPathService')
        ? PowerPathService::report([
            'zone_id' => $ppZone,
            'view' => $ppView,
        ])
        : [
            'summary' => [
                'path_rows' => 0, 'mapped' => 0, 'unmapped_psus' => 0, 'half_maps' => 0,
                'single_feed_devices' => 0, 'cabinets_no_row_feed' => 0, 'ups_no_zone' => 0,
                'devices_with_psus' => 0,
            ],
            'paths' => [],
            'single_feed_devices' => [],
            'cabinets_no_row_feed' => [],
            'ups_no_zone' => [],
            'zones' => [],
            'filters' => ['zone_id' => $ppZone, 'view' => $ppView],
        ];
    $ppSum = $ppData['summary'];
    $ppPaths = $ppData['paths'];
    $ppZones = $ppData['zones'] ?? [];
    ?>
<div class="card mb-2">
    <div class="card-header flex-between">
        <h2 style="margin:0">Power Path</h2>
        <div class="flex gap-1">
            <?php report_export_btn(); ?>
            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">Power dashboard</a>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.9rem;margin-top:0">
            End-to-end inventory from <strong>device PSU → rack PDU outlet → row/room feed → zone → UPS</strong>
            (UPS is associated by power zone, not a hard feed link).
            Use filters to find unmapped cords, single-feed risk, half-maps, and cabinets without a row breaker feed.
        </p>
        <form method="get" class="form-grid">
            <input type="hidden" name="report" value="power_path">
            <div class="form-row"><label>Zone</label>
                <select class="form-control" name="zone_id">
                    <option value="">All zones</option>
                    <?php foreach ($ppZones as $z): ?>
                        <option value="<?= (int)$z['zone_id'] ?>" <?= $ppZone === (int)$z['zone_id'] ? 'selected' : '' ?>>
                            <?= App::e((string)$z['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>View</label>
                <select class="form-control" name="view">
                    <option value="all" <?= $ppView === 'all' ? 'selected' : '' ?>>All PSU paths</option>
                    <option value="unmapped" <?= $ppView === 'unmapped' ? 'selected' : '' ?>>Unmapped PSUs only</option>
                    <option value="single_feed" <?= $ppView === 'single_feed' ? 'selected' : '' ?>>Single-feed / partial map</option>
                    <option value="half_map" <?= $ppView === 'half_map' ? 'selected' : '' ?>>Half-map inconsistencies</option>
                    <option value="no_row_feed" <?= $ppView === 'no_row_feed' ? 'selected' : '' ?>>No row/room feed</option>
                </select>
            </div>
            <div class="form-row" style="align-self:end">
                <button class="btn btn-primary" type="submit">Run report</button>
            </div>
        </form>
    </div>
</div>

<div class="metrics power-metrics mb-2">
    <div class="metric-card success">
        <div class="label">Mapped PSUs</div>
        <div class="value"><?= (int)$ppSum['mapped'] ?></div>
        <div class="sub">of <?= (int)$ppSum['path_rows'] ?> PSU lines</div>
    </div>
    <div class="metric-card <?= (int)$ppSum['unmapped_psus'] > 0 ? 'warning' : '' ?>">
        <div class="label">Unmapped PSUs</div>
        <div class="value"><?= (int)$ppSum['unmapped_psus'] ?></div>
        <div class="sub">no outlet map</div>
    </div>
    <div class="metric-card <?= (int)$ppSum['single_feed_devices'] > 0 ? 'warning' : '' ?>">
        <div class="label">Single-feed risk</div>
        <div class="value"><?= (int)$ppSum['single_feed_devices'] ?></div>
        <div class="sub">devices</div>
    </div>
    <div class="metric-card <?= (int)$ppSum['half_maps'] > 0 ? 'danger' : '' ?>">
        <div class="label">Half-maps</div>
        <div class="value"><?= (int)$ppSum['half_maps'] ?></div>
        <div class="sub">link mismatch</div>
    </div>
    <div class="metric-card <?= (int)$ppSum['cabinets_no_row_feed'] > 0 ? 'warning' : '' ?>">
        <div class="label">Cabinets no row feed</div>
        <div class="value"><?= (int)$ppSum['cabinets_no_row_feed'] ?></div>
        <div class="sub">rack PDU, no breaker</div>
    </div>
</div>

<div class="card mb-2">
    <div class="card-header"><h2>Paths (<?= count($ppPaths) ?>)</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Device</th>
                <th>PSU</th>
                <th>Cabinet</th>
                <th>Rack PDU / outlet</th>
                <th>Row/room feed</th>
                <th>Zone</th>
                <th>UPS (zone)</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($ppPaths as $pr): ?>
                <tr>
                    <td>
                        <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$pr['device_id'])) ?>">
                            <?= App::e((string)$pr['device_label']) ?>
                        </a>
                        <?php if (!empty($pr['device_type'])): ?>
                            <div class="text-muted" style="font-size:.72rem"><?= App::e((string)$pr['device_type']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= App::e((string)$pr['psu_name']) ?></td>
                    <td>
                        <?php if (!empty($pr['cabinet_id'])): ?>
                            <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$pr['cabinet_id'])) ?>">
                                <?= App::e((string)$pr['cabinet_name']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.85rem">
                        <?php if (!empty($pr['mapped'])): ?>
                            <a href="<?= App::e(App::url('pages/power_pdus.php?id=' . (int)$pr['rack_pdu_id'])) ?>">
                                <?= App::e((string)$pr['rack_pdu_name']) ?>
                            </a>
                            ·
                            <?= App::e(
                                $pr['outlet_label'] !== ''
                                    ? (string)$pr['outlet_label']
                                    : ('#' . (string)$pr['outlet_number'])
                            ) ?>
                        <?php else: ?>
                            <span class="text-muted">Unmapped</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.85rem">
                        <?php if (!empty($pr['row_feed_summary'])): ?>
                            <?= App::e((string)$pr['row_feed_summary']) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($pr['zone_name'])): ?>
                            <?= App::e((string)$pr['zone_name']) ?>
                            <?php if (!empty($pr['feed_type'])): ?>
                                <span class="badge" style="font-size:.7rem"><?= App::e((string)$pr['feed_type']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.85rem">
                        <?php if (!empty($pr['ups_summary'])): ?>
                            <?= App::e((string)$pr['ups_summary']) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($pr['half_map'])): ?>
                            <span class="badge badge-danger" title="<?= App::e((string)($pr['half_reason'] ?? '')) ?>">Half-map</span>
                        <?php elseif (empty($pr['mapped'])): ?>
                            <span class="badge badge-warning">Unmapped</span>
                        <?php elseif (empty($pr['has_row_feed']) && !empty($pr['cabinet_id'])): ?>
                            <span class="badge badge-warning">No row feed</span>
                        <?php else: ?>
                            <span class="badge badge-success">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$ppPaths): ?>
                <tr><td colspan="8" class="text-muted">No PSU path rows for this filter.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($ppData['single_feed_devices']) && $ppView === 'all'): ?>
<div class="card mb-2">
    <div class="card-header"><h2>Single-feed / partial map devices</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead><tr><th>Device</th><th>Cabinet</th><th>PSUs</th><th>Mapped</th><th>Distinct PDUs</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($ppData['single_feed_devices'] as $sf): ?>
                <tr>
                    <td>
                        <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$sf['device_id'])) ?>">
                            <?= App::e((string)$sf['device_label']) ?>
                        </a>
                    </td>
                    <td><?= App::e((string)($sf['cabinet_name'] ?? '—')) ?></td>
                    <td><?= (int)$sf['psu_count'] ?></td>
                    <td><?= (int)$sf['mapped_count'] ?></td>
                    <td><?= (int)$sf['distinct_pdus'] ?></td>
                    <td class="text-muted" style="font-size:.85rem"><?= App::e((string)$sf['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($ppData['cabinets_no_row_feed']) && in_array($ppView, ['all', 'no_row_feed'], true)): ?>
<div class="card mb-2">
    <div class="card-header"><h2>Cabinets with rack PDUs but no row/room breaker feed</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead><tr><th>Cabinet</th><th>Row</th><th>Rack PDUs</th><th>Devices</th></tr></thead>
            <tbody>
            <?php foreach ($ppData['cabinets_no_row_feed'] as $cn): ?>
                <tr>
                    <td>
                        <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$cn['cabinet_id'])) ?>">
                            <?= App::e((string)$cn['cabinet_name']) ?>
                        </a>
                    </td>
                    <td><?= App::e((string)($cn['row_name'] ?: '—')) ?></td>
                    <td><?= (int)$cn['rack_pdu_count'] ?></td>
                    <td><?= (int)$cn['device_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($ppData['cabinets_no_row_feed'])): ?>
                <tr><td colspan="4" class="text-muted">None — every racked cabinet with PDUs has a breaker feed.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($ppData['ups_no_zone'])): ?>
<div class="card mb-2">
    <div class="card-header"><h2>UPS without a power zone</h2></div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.85rem;margin:0 0 .5rem">
            UPS is only shown on paths when assigned to a zone (soft association). Assign a zone on the UPS page to include them.
        </p>
        <ul style="margin:0;padding-left:1.2rem">
            <?php foreach ($ppData['ups_no_zone'] as $uu): ?>
                <li>
                    <a href="<?= App::e(App::url('pages/power_ups.php?id=' . (int)$uu['ups_id'])) ?>">
                        <?= App::e((string)$uu['name']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php elseif ($report === 'inventory_summary'):
    $data = report_inventory_summary(); ?>
<div class="flex-between mb-2">
    <p class="text-muted mb-0">Active devices by type, status, and data center.</p>
    <?php report_export_btn(); ?>
</div>
<div class="split-2">
    <div class="card"><div class="card-header"><h2>By Type</h2></div>
        <div class="card-body flush"><table class="data"><thead><tr><th>Type</th><th>Count</th></tr></thead><tbody>
        <?php foreach ($data['by_type'] as $r): ?><tr><td><?= App::e($r['device_type']) ?></td><td><?= (int)$r['cnt'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <div class="card"><div class="card-header"><h2>By Status</h2></div>
        <div class="card-body flush"><table class="data"><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>
        <?php foreach ($data['by_status'] as $r): ?><tr><td><?= App::e($r['status']) ?></td><td><?= (int)$r['cnt'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
</div>
<div class="card"><div class="card-header"><h2>By Data Center</h2></div>
    <div class="card-body flush"><table class="data"><thead><tr><th>Data Center</th><th>Devices</th></tr></thead><tbody>
    <?php foreach ($data['by_dc'] as $r): ?><tr><td><?= App::e($r['name']) ?></td><td><?= (int)$r['cnt'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>

<?php elseif ($report === 'cabinet_utilization'):
    $rows = report_cabinet_utilization(); ?>
<div class="card"><div class="card-header flex-between"><h2 style="margin:0">Cabinet Utilization</h2><?php report_export_btn(); ?></div>
    <div class="card-body flush"><table class="data">
        <thead><tr><th>Cabinet</th><th>Location</th><th>U Height</th><th>Used</th><th>Free</th><th>%</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $pct = $r['u_height'] ? round(100 * (int)$r['u_used'] / (int)$r['u_height'], 1) : 0;
        ?>
            <tr>
                <td><?= App::e($r['name']) ?></td>
                <td><?= App::e($r['dc_name'] . ' / ' . $r['room_name']) ?></td>
                <td><?= (int)$r['u_height'] ?></td>
                <td><?= (int)$r['u_used'] ?></td>
                <td><?= (int)$r['u_height'] - (int)$r['u_used'] ?></td>
                <td><span class="badge <?= $pct > 85 ? 'badge-danger' : 'badge-success' ?>"><?= $pct ?>%</span></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>

<?php elseif ($report === 'power_capacity'):
    $pcNeedKw = isset($_GET['need_kw']) && $_GET['need_kw'] !== '' ? (float)$_GET['need_kw'] : 0.0;
    $pcNeedU = isset($_GET['need_u']) && $_GET['need_u'] !== '' ? (int)$_GET['need_u'] : 0;
    if ($pcNeedKw < 0) {
        $pcNeedKw = 0.0;
    }
    if ($pcNeedU < 0) {
        $pcNeedU = 0;
    }
    $data = report_power_capacity($pcNeedKw, $pcNeedU);
    $pcFiltering = $pcNeedKw > 0 || $pcNeedU > 0;
    ?>
<div class="card mb-2">
    <div class="card-header flex-between">
        <h2 style="margin:0">Power Capacity</h2>
        <div class="flex gap-1">
            <?php report_export_btn(); ?>
            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">Power dashboard</a>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size:.9rem;margin-top:0">
            Live headroom per zone: free kW (max − rollup load), free U on assigned rows, phase amps, and
            <strong>imbalance</strong> when (max−min)/max phase amps ≥ 20%.
            Use <strong>Fits?</strong> to list zones with enough free power and/or U for a placement.
        </p>
        <form method="get" class="form-grid">
            <input type="hidden" name="report" value="power_capacity">
            <div class="form-row"><label>Need free kW</label>
                <input class="form-control" type="number" step="0.1" min="0" name="need_kw"
                       value="<?= $pcNeedKw > 0 ? App::e((string)$pcNeedKw) : '' ?>" placeholder="e.g. 2.5"></div>
            <div class="form-row"><label>Need free U</label>
                <input class="form-control" type="number" min="0" name="need_u"
                       value="<?= $pcNeedU > 0 ? (int)$pcNeedU : '' ?>" placeholder="e.g. 2"></div>
            <div class="form-row" style="align-self:end">
                <button class="btn btn-primary" type="submit">Fits?</button>
                <?php if ($pcFiltering): ?>
                    <a class="btn btn-secondary" href="?report=power_capacity">Clear</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($pcFiltering): ?>
            <p class="text-muted" style="font-size:.85rem;margin:.75rem 0 0">
                Showing zones with
                <?= $pcNeedKw > 0 ? '≥ ' . number_format($pcNeedKw, 1) . ' kW free' : '' ?>
                <?= $pcNeedKw > 0 && $pcNeedU > 0 ? ' and ' : '' ?>
                <?= $pcNeedU > 0 ? '≥ ' . (int)$pcNeedU . ' U free' : '' ?>
                — <?= count($data['fits']) ?> of <?= count($data['zones']) ?> zone(s).
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-2"><div class="card-header"><h2>Zones (live)</h2></div>
    <div class="card-body flush"><table class="data">
        <thead>
        <tr>
            <th>Zone</th>
            <th>Feed</th>
            <th>Load kW</th>
            <th>Max kW</th>
            <th>Free kW</th>
            <th>Util</th>
            <th>Free U</th>
            <th>Phases (A)</th>
            <th>Balance</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $pcRows = $pcFiltering ? $data['fits'] : $data['zones'];
        foreach ($pcRows as $z):
            $util = $z['util_pct'];
            $imb = !empty($z['imbalanced']);
            ?>
            <tr>
                <td>
                    <a href="<?= App::e(App::url('pages/power_zones.php?id=' . (int)$z['zone_id'])) ?>">
                        <?= App::e((string)$z['name']) ?>
                    </a>
                    <?php if (!empty($z['dc_name'])): ?>
                        <div class="text-muted" style="font-size:.72rem"><?= App::e((string)$z['dc_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= App::e((string)($z['feed_type'] ?? '—')) ?></td>
                <td><?= number_format((float)$z['load_kw'], 2) ?></td>
                <td><?= $z['max_kw'] !== null ? number_format((float)$z['max_kw'], 1) : '—' ?></td>
                <td>
                    <?php if ($z['free_kw'] !== null): ?>
                        <strong><?= number_format((float)$z['free_kw'], 2) ?></strong>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($util !== null): ?>
                        <span class="badge badge-<?= App::e(power_util_class((float)$util) === 'danger' ? 'danger' : (power_util_class((float)$util) === 'warning' ? 'warning' : 'success')) ?>">
                            <?= App::e((string)$util) ?>%
                        </span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td><?= (int)$z['free_u'] ?> <span class="text-muted">/ <?= (int)$z['u_total'] ?></span></td>
                <td style="font-size:.85rem"><?= App::e(power_format_phase_amps($z['phase_amps'] ?? [])) ?></td>
                <td>
                    <?php if ($imb): ?>
                        <span class="badge badge-warning"><?= App::e((string)($z['imbalance']['label'] ?? 'Imbalanced')) ?></span>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:.85rem"><?= App::e((string)($z['imbalance']['label'] ?? '—')) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pcRows): ?>
            <tr>
                <td colspan="9" class="text-muted">
                    <?= $pcFiltering ? 'No zones fit those free kW / free U requirements.' : 'No power zones defined.' ?>
                </td>
            </tr>
        <?php endif; ?>
        </tbody></table></div></div>
<div class="card"><div class="card-header flex-between"><h2 style="margin:0">PDUs</h2><?php report_export_btn(['sheet' => 'pdus']); ?></div>
    <div class="card-body flush"><table class="data">
        <thead><tr><th>Name</th><th>Scope</th><th>Zone</th><th>Rated</th><th>Last W</th><th>Last A</th><th>Polled</th></tr></thead>
        <tbody>
        <?php foreach ($data['pdus'] as $p): ?>
            <tr>
                <td><?= App::e($p['name']) ?></td>
                <td><?= App::e($p['pdu_scope']) ?></td>
                <td><?= App::e($p['zone_name'] ?? '—') ?></td>
                <td><?= App::e(($p['rated_volts'] ?? '?') . 'V / ' . ($p['rated_amps'] ?? '?') . 'A') ?></td>
                <td><?= App::e((string)$p['last_poll_watts']) ?></td>
                <td><?= App::e((string)$p['last_poll_amps']) ?></td>
                <td><?= App::e($p['last_poll_at'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>

<?php elseif ($report === 'warranty_expiration'):
    $wDays = max(0, min(730, (int)($_GET['days'] ?? SettingsService::get('warranty_notify_days', '60'))));
    $wFilter = strtolower(trim((string)($_GET['wfilter'] ?? 'window')));
    if (!in_array($wFilter, ['all', 'soon', 'expired', 'window'], true)) {
        $wFilter = 'window';
    }
    $rows = report_warranty($wFilter, $wDays);
    $expiredCount = 0;
    $soonCount = 0;
    foreach ($rows as $rr) {
        $dLeft = class_exists('AssetLifecycleService')
            ? AssetLifecycleService::daysUntil(isset($rr['warranty_end']) ? (string)$rr['warranty_end'] : null)
            : null;
        if ($dLeft !== null && $dLeft < 0) {
            $expiredCount++;
        } elseif ($dLeft !== null && $dLeft <= $wDays) {
            $soonCount++;
        }
    }
    ?>
<div class="card">
    <div class="card-header flex-between">
        <h2 style="margin:0">Warranty Expiration</h2>
        <div class="flex gap-1" style="align-items:center;flex-wrap:wrap">
        <?php report_export_btn(); ?>
        <form method="get" class="flex gap-1" style="align-items:center;flex-wrap:wrap;margin:0">
            <input type="hidden" name="report" value="warranty_expiration">
            <label class="text-muted" style="font-size:.8rem;margin:0">Filter</label>
            <select class="form-control" name="wfilter" style="width:auto" onchange="this.form.submit()">
                <option value="window" <?= $wFilter === 'window' ? 'selected' : '' ?>>Due within window + expired</option>
                <option value="expired" <?= $wFilter === 'expired' ? 'selected' : '' ?>>Expired only</option>
                <option value="all" <?= $wFilter === 'all' ? 'selected' : '' ?>>All with warranty date</option>
            </select>
            <label class="text-muted" style="font-size:.8rem;margin:0">Days</label>
            <input class="form-control" type="number" name="days" min="0" max="730" value="<?= (int)$wDays ?>"
                   style="width:5rem" onchange="this.form.submit()">
        </form>
        </div>
    </div>
    <div class="card-body" style="padding-bottom:0">
        <p class="text-muted" style="font-size:.85rem;margin:0 0 .75rem">
            Showing <?= count($rows) ?> asset(s) (devices, PDUs, UPS)
            <?php if ($wFilter !== 'all'): ?>
                · <?= $expiredCount ?> expired · <?= $soonCount ?> within <?= (int)$wDays ?> day(s)
            <?php endif; ?>
            · Email digests: Settings → General (warranty notify)
        </p>
    </div>
    <div class="card-body flush"><table class="data">
        <thead>
        <tr>
            <th>Kind</th><th>Name</th><th>Asset tag</th><th>Make/Model</th><th>Serial</th>
            <th>Provider</th><th>Warranty end</th><th>Remaining</th><th>Dept</th><th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $dLeft = class_exists('AssetLifecycleService')
                ? AssetLifecycleService::daysUntil(isset($r['warranty_end']) ? (string)$r['warranty_end'] : null)
                : null;
            $badge = class_exists('AssetLifecycleService')
                ? AssetLifecycleService::warrantyBadge($dLeft)
                : ['label' => '—', 'class' => ''];
            $kind = (string)($r['kind'] ?? 'device');
            $eid = (int)($r['entity_id'] ?? $r['device_id'] ?? 0);
            $href = '';
            if ($eid > 0) {
                $href = match ($kind) {
                    'pdu' => App::url('pages/power_pdus.php?id=' . $eid),
                    'ups' => App::url('pages/power_ups.php?id=' . $eid),
                    default => App::url('pages/devices.php?id=' . $eid),
                };
            }
            ?>
            <tr>
                <td><?= App::e($kind === 'pdu' ? 'PDU' : ($kind === 'ups' ? 'UPS' : 'Device')) ?></td>
                <td><?php if ($href !== ''): ?>
                    <a href="<?= App::e($href) ?>"><?= App::e($r['label']) ?></a>
                <?php else: ?>
                    <?= App::e($r['label']) ?>
                <?php endif; ?></td>
                <td><?= App::e($r['asset_tag'] ?? '') ?></td>
                <td><?= App::e(trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? ''))) ?></td>
                <td><?= App::e($r['serial_no'] ?? '') ?></td>
                <td><?= App::e($r['warranty_provider'] ?? '') ?></td>
                <td><?= App::e($r['warranty_end'] ?? '') ?></td>
                <td><?php if ($badge['label'] !== '—'): ?>
                    <span class="badge <?= App::e($badge['class']) ?>"><?= App::e($badge['label']) ?></span>
                <?php else: ?>—<?php endif; ?></td>
                <td><?= App::e($r['department_name'] ?? '') ?></td>
                <td><?= App::e($r['status'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-muted">No matching warranty dates.</td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php elseif ($report === 'disposal_queue'):
    $rows = report_disposal_queue(); ?>
<div class="card"><div class="card-header flex-between"><h2 style="margin:0">Open Disposals</h2><?php report_export_btn(); ?></div>
    <div class="card-body flush"><table class="data">
        <thead><tr><th>Device</th><th>Status</th><th>Method</th><th>Scheduled</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= App::e($r['device_label']) ?></td>
                <td><?= App::e($r['status']) ?></td>
                <td><?= App::e($r['method'] ?? '') ?></td>
                <td><?= App::e($r['scheduled_date'] ?? '') ?></td>
                <td><?= App::e($r['reason'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>

<?php elseif ($report === 'cable_inventory'):
    $rows = report_cables(); ?>
<div class="card"><div class="card-header flex-between"><h2 style="margin:0">Cable Inventory</h2><?php report_export_btn(); ?></div>
    <div class="card-body flush"><table class="data">
        <thead><tr><th>Label</th><th>A</th><th>B</th><th>Media</th><th>Length</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= App::e($r['cable_label'] ?? '') ?></td>
                <td><?= App::e(($r['a_device'] ?? '') . ' / ' . ($r['a_port'] ?? '')) ?></td>
                <td><?= App::e(($r['b_device'] ?? '') . ' / ' . ($r['b_port'] ?? '')) ?></td>
                <td><?= App::e($r['media_type'] ?? '') ?></td>
                <td><?= App::e((string)$r['length_m']) ?></td>
                <td><?= App::e($r['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>

<?php elseif ($report === 'orphaned_devices'):
    $rows = report_orphans(); ?>
<div class="card"><div class="card-header flex-between"><h2 style="margin:0">Orphaned Devices (no cabinet)</h2><?php report_export_btn(); ?></div>
    <div class="card-body flush"><table class="data">
        <thead><tr><th>Label</th><th>Type</th><th>Status</th><th>Serial</th><th>Asset Tag</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="<?= App::e(App::url('pages/devices.php?id=' . $r['device_id'])) ?>"><?= App::e($r['label']) ?></a></td>
                <td><?= App::e($r['device_type']) ?></td>
                <td><?= App::e($r['status']) ?></td>
                <td><?= App::e($r['serial_no'] ?? '') ?></td>
                <td><?= App::e($r['asset_tag'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">All active devices are assigned.</td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php elseif ($report === 'audit_history'):
    $rows = report_audit_history(); ?>
<div class="card"><div class="card-header flex-between"><h2 style="margin:0">Completed Audits</h2><?php report_export_btn(); ?></div>
    <div class="card-body flush"><table class="data">
        <thead><tr><th>Name</th><th>Type</th><th>Completed</th><th>Findings</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="<?= App::e(App::url('pages/audits.php?job_id=' . $r['job_id'])) ?>"><?= App::e($r['name']) ?></a></td>
                <td><?= App::e($r['audit_type']) ?></td>
                <td><?= App::e($r['completed_at'] ?? '') ?></td>
                <td><?= App::e($r['findings_summary'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>

<?php elseif ($report === 'work_orders'):
    $woTypes = work_order_types();
    $woStatuses = work_order_statuses();
    $rows = report_work_orders();
    ?>
<div class="card">
    <div class="card-header flex-between">
        <h2 style="margin:0">Work orders</h2>
        <div class="flex gap-1">
            <?php report_export_btn(); ?>
            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/work_orders.php')) ?>">Open list</a>
        </div>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Title</th><th>Type</th><th>Ticket</th><th>Status</th>
                <th>Scheduled</th><th>Devices</th><th>Updated</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <a href="<?= App::e(App::url('pages/work_orders.php?id=' . (int)$r['work_order_id'])) ?>">
                            <?= App::e((string)$r['title']) ?>
                        </a>
                    </td>
                    <td><?= App::e($woTypes[$r['work_type'] ?? ''] ?? (string)($r['work_type'] ?? '')) ?></td>
                    <td><?= App::e((string)(($r['itsm_display_id'] ?? '') !== ''
                        ? $r['itsm_display_id']
                        : (($r['change_ticket'] ?? '') !== '' ? $r['change_ticket'] : '—'))) ?></td>
                    <td><?= App::e($woStatuses[$r['status'] ?? ''] ?? (string)($r['status'] ?? '')) ?></td>
                    <td><?= App::e((string)($r['scheduled_date'] ?: '—')) ?></td>
                    <td><?= (int)($r['item_count'] ?? 0) ?></td>
                    <td style="font-size:.85rem"><?= App::e((string)($r['updated_at'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-muted">No work orders yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($report === 'cooling'):
    $cool = report_cooling();
    $tempSym = class_exists('TempUnitService') ? TempUnitService::symbol() : '°C';
    ?>
<div class="card">
    <div class="card-header flex-between">
        <h2 style="margin:0">Cooling</h2>
        <?php report_export_btn(); ?>
    </div>
    <div class="card-body">
        <p class="text-muted" style="margin:0">
            Latest supply, return, and humidity for each cooling unit, plus temperature and humidity from aisle and room sensors.
            Temperatures use the site unit (<?= App::e($tempSym) ?>). Humidity is relative humidity.
        </p>
    </div>
</div>
<div class="card">
    <div class="card-header"><h2 style="margin:0">Cooling units</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th><th>Room</th><th>Type</th><th>Role</th><th>State</th>
                <th>Supply</th><th>Return</th><th>Humidity</th><th>Humidity setpoint</th>
                <th>Cooling %</th><th>Last poll</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($cool['units'] as $r): ?>
                <tr>
                    <td><a href="<?= App::e(App::url('pages/cooling_units.php?id=' . (int)$r['cooling_unit_id'])) ?>"><?= App::e((string)$r['name']) ?></a></td>
                    <td><?= App::e(report_dash((string)$r['room'])) ?></td>
                    <td><?= App::e((string)$r['type']) ?></td>
                    <td><?= App::e((string)$r['role']) ?></td>
                    <td><?= App::e(report_dash((string)$r['state'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['supply'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['return'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['humidity'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['humidity_setpoint'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['cooling_pct'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['polled'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cool['units']): ?>
                <tr><td colspan="11" class="text-muted">No cooling units yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="card">
    <div class="card-header"><h2 style="margin:0">Sensors</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th><th>Kind</th><th>Room</th><th>Placement</th><th>Host</th>
                <th>Temperature</th><th>Humidity</th><th>Last seen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($cool['sensors'] as $r): ?>
                <tr>
                    <td><a href="<?= App::e(App::url('pages/env_sensors.php?id=' . (int)$r['sensor_id'])) ?>"><?= App::e((string)$r['name']) ?></a></td>
                    <td><?= App::e((string)$r['kind']) ?></td>
                    <td><?= App::e(report_dash((string)$r['room'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['placement'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['host'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['temperature'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['humidity'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['seen'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cool['sensors']): ?>
                <tr><td colspan="8" class="text-muted">No temperature or humidity sensors yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($report === 'temperature' || $report === 'humidity'):
    $climateWhich = $report === 'humidity' ? 'humidity' : 'temperature';
    $climateTitle = $climateWhich === 'humidity' ? 'Humidity' : 'Temperature';
    $climateSpan = report_climate_span();
    $climateRoomId = report_climate_room_id();
    $climateRooms = report_climate_rooms();
    $climateRows = report_climate($climateWhich);
    $tempSym = class_exists('TempUnitService') ? TempUnitService::symbol() : '°C';
    $climateCounts = ['crit' => 0, 'warn' => 0, 'ok' => 0];
    $climateSamples = 0;
    foreach ($climateRows as $cr) {
        $st = (string)($cr['status'] ?? '');
        if (isset($climateCounts[$st])) {
            $climateCounts[$st]++;
        }
        $climateSamples += (int)($cr['samples'] ?? 0);
    }
    $climateHasStatus = ($climateCounts['crit'] + $climateCounts['warn'] + $climateCounts['ok']) > 0;
    ?>
<div class="card mb-2">
    <div class="card-header flex-between">
        <h2 style="margin:0"><?= App::e($climateTitle) ?></h2>
        <?php report_export_btn(); ?>
    </div>
    <div class="card-body">
        <?php if ($climateWhich === 'humidity'): ?>
            <p class="text-muted" style="margin-top:0">
                Latest relative humidity, plus the minimum, average, and maximum over <?= App::e($climateSpan['label']) ?>.
                A humidity sensor uses its warn and crit limits for status. A combined temperature and humidity probe keeps those limits on temperature, so its humidity row has no status.
                Cooling units appear when they have a humidity reading or samples in this period.
            </p>
        <?php else: ?>
            <p class="text-muted" style="margin-top:0">
                Latest temperature in the site unit (<?= App::e($tempSym) ?>), plus the minimum, average, and maximum over <?= App::e($climateSpan['label']) ?>.
                Dew point is included. Cooling units contribute supply and return when those readings exist.
                Status compares the latest reading with the warn and crit limits on that sensor.
            </p>
        <?php endif; ?>
        <form method="get" class="form-grid">
            <input type="hidden" name="report" value="<?= App::e($climateWhich) ?>">
            <div class="form-row"><label>Period</label>
                <select class="form-control" name="span">
                    <option value="24h"<?= $climateSpan['span'] === '24h' ? ' selected' : '' ?>>Last 24 hours</option>
                    <option value="7d"<?= $climateSpan['span'] === '7d' ? ' selected' : '' ?>>Last 7 days</option>
                    <option value="30d"<?= $climateSpan['span'] === '30d' ? ' selected' : '' ?>>Last 30 days</option>
                </select>
            </div>
            <div class="form-row"><label>Room</label>
                <select class="form-control" name="room_id">
                    <option value="">All rooms</option>
                    <?php foreach ($climateRooms as $rm): ?>
                        <?php
                        $rid = (int)$rm['room_id'];
                        $dcName = trim((string)($rm['dc_name'] ?? ''));
                        $roomLabel = $dcName !== '' ? ($dcName . ' / ' . (string)$rm['name']) : (string)$rm['name'];
                        ?>
                        <option value="<?= $rid ?>"<?= $climateRoomId === $rid ? ' selected' : '' ?>><?= App::e($roomLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row" style="align-self:end">
                <button class="btn btn-primary" type="submit">Apply</button>
                <?php if ($climateSpan['span'] !== '7d' || $climateRoomId > 0): ?>
                    <a class="btn btn-secondary" href="?report=<?= App::e($climateWhich) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>
        <p class="text-muted" style="font-size:.85rem;margin:.75rem 0 0">
            <?= count($climateRows) ?> row<?= count($climateRows) === 1 ? '' : 's' ?>
            <?php if ($climateHasStatus): ?>
                — <?= (int)$climateCounts['crit'] ?> crit, <?= (int)$climateCounts['warn'] ?> warn, <?= (int)$climateCounts['ok'] ?> OK
            <?php endif; ?>
        </p>
        <?php if ($climateRows && $climateSamples === 0): ?>
            <p class="text-muted" style="font-size:.85rem;margin:.35rem 0 0">
                No samples were stored in this period. Current is the latest reading kept on each sensor.
            </p>
        <?php endif; ?>
    </div>
</div>
<div class="card">
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Source</th><th>Name</th><th>Room</th><th>Point</th><th>Host</th>
                <th>Current</th><th>Min</th><th>Avg</th><th>Max</th><th>Samples</th>
                <th>Status</th><th>Last reading</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($climateRows as $r): ?>
                <tr>
                    <td><?= App::e((string)$r['source']) ?></td>
                    <td>
                        <a href="<?= App::e(App::url((string)$r['href'])) ?>"><?= App::e((string)$r['name']) ?></a>
                        <?php if ((string)$r['location'] !== ''): ?>
                            <div class="text-muted" style="font-size:.8rem"><?= App::e((string)$r['location']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= App::e(report_dash((string)$r['room'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['point'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['host'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['current'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['min'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['avg'])) ?></td>
                    <td><?= App::e(report_dash((string)$r['max'])) ?></td>
                    <td><?= (int)$r['samples'] > 0 ? (int)$r['samples'] : '—' ?></td>
                    <td><?= report_climate_status_html((string)$r['status']) ?></td>
                    <td><?= App::e(report_dash((string)$r['seen'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$climateRows): ?>
                <tr>
                    <td colspan="12" class="text-muted">
                        <?= $climateWhich === 'humidity'
                            ? 'No humidity sensors, and no cooling unit reported humidity in this period.'
                            : 'No temperature sensors, and no cooling unit reported supply or return in this period.' ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php layout_footer(); ?>
