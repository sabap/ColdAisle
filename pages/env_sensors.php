<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/cooling_helpers.php';
App::boot();
$user = App::requirePermission('view_cooling');
$canEdit = AuthManager::canEditCooling($user);

$sensorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$filterCu = isset($_GET['cooling_unit_id']) ? (int)$_GET['cooling_unit_id'] : 0;
$filterDevice = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$prefillHost = (string)($_GET['host_type'] ?? '');

$kinds = env_sensor_kinds();
$hosts = env_sensor_hosts();
$placements = env_sensor_placements();

$rooms = [];
$coolingUnits = [];
$pdus = [];
$cabinets = [];
$devices = [];
try {
    $rooms = Database::fetchAll(
        'SELECT r.room_id, r.name, dc.name AS dc_name
         FROM rooms r
         INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
         ORDER BY dc.name, r.name'
    );
    $coolingUnits = Database::fetchAll(
        'SELECT cooling_unit_id, name FROM cooling_units WHERE is_active = 1 ORDER BY name'
    );
    $pdus = Database::fetchAll(
        'SELECT pdu_id, name FROM pdus WHERE is_active = 1 ORDER BY name'
    );
    $cabinets = Database::fetchAll(
        'SELECT cabinet_id, name FROM cabinets WHERE is_active = 1 ORDER BY name'
    );
    // Prefer env hosts first, then everything else with SNMP/IP (for AP9340 already as "other")
    $devices = Database::fetchAll(
        "SELECT device_id, label, device_type, cabinet_id, position_u, manufacturer, model, mgmt_ip, primary_ip
         FROM devices WHERE is_active = 1
         ORDER BY
           CASE WHEN device_type IN ('env_monitor','env_module') THEN 0 ELSE 1 END,
           label"
    );
} catch (Throwable $e) {
    // partial lists ok
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to modify environmental sensors.');
        App::redirect('pages/env_sensors.php');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add_sensor' || $action === 'update_sensor') {
            $row = env_sensor_fields_from_post($_POST);
            if ($row['name'] === '') {
                throw new RuntimeException('Name is required.');
            }
            if ($action === 'update_sensor') {
                $sid = (int)($_POST['sensor_id'] ?? 0);
                if ($sid <= 0) {
                    throw new RuntimeException('Sensor id required.');
                }
                $row['updated_at'] = date('Y-m-d H:i:s');
                Database::update('env_sensors', $row, 'sensor_id = :id', [':id' => $sid]);
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'update', 'env_sensor', $sid, [
                    'name' => $row['name'],
                ]);
                App::flash('success', 'Sensor updated.');
                App::redirect('pages/env_sensors.php?id=' . $sid);
            }
            $id = Database::insert('env_sensors', $row);
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'create', 'env_sensor', (int)$id, [
                'name' => $row['name'],
            ]);
            App::flash('success', 'Sensor created.');
            App::redirect('pages/env_sensors.php?id=' . (int)$id);
        }
        if ($action === 'record_reading') {
            $sid = (int)($_POST['sensor_id'] ?? 0);
            $val = $_POST['value'] ?? '';
            if ($sid <= 0 || $val === '' || !is_numeric($val)) {
                throw new RuntimeException('Sensor and numeric temperature/primary value required.');
            }
            $value = (float)$val;
            $humIn = $_POST['humidity'] ?? '';
            $humVal = ($humIn !== '' && is_numeric($humIn)) ? (float)$humIn : null;
            $now = date('Y-m-d H:i:s');
            $sensorRow = Database::fetchOne('SELECT * FROM env_sensors WHERE sensor_id = ?', [$sid]);
            $kind = (string)($sensorRow['sensor_kind'] ?? 'temperature');
            // Manual entry is in site display unit; store °C for temperature kinds
            if (class_exists('TempUnitService') && TempUnitService::isTempKind($kind) && $kind !== 'humidity') {
                $conv = TempUnitService::toC($value);
                if ($conv !== null) {
                    $value = $conv;
                }
            }

            try {
                Database::insert('env_readings', [
                    'sensor_id' => $sid,
                    'value' => $value,
                    'recorded_at' => $now,
                    'metric' => $kind === 'humidity' ? 'humidity' : 'temperature',
                ]);
            } catch (Throwable $e) {
                Database::insert('env_readings', [
                    'sensor_id' => $sid,
                    'value' => $value,
                    'recorded_at' => $now,
                ]);
            }
            $fields = [
                'last_value' => $value,
                'last_seen_at' => $now,
                'updated_at' => $now,
            ];
            if ($humVal !== null && ($kind === 'temp_humidity' || $kind === 'humidity')) {
                $fields['last_humidity'] = $humVal;
                try {
                    Database::insert('env_readings', [
                        'sensor_id' => $sid,
                        'value' => $humVal,
                        'recorded_at' => $now,
                        'metric' => 'humidity',
                    ]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            Database::update('env_sensors', $fields, 'sensor_id = :id', [':id' => $sid]);

            // Threshold alerts after manual entry
            if (class_exists('EnvSensorAlertService')) {
                try {
                    $fresh = Database::fetchOne('SELECT * FROM env_sensors WHERE sensor_id = ?', [$sid]);
                    if ($fresh) {
                        EnvSensorAlertService::evaluateSensor($fresh);
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }

            App::flash('success', 'Reading recorded.');
            App::redirect('pages/env_sensors.php?id=' . $sid);
        }
        if ($action === 'deactivate_sensor') {
            $sid = (int)($_POST['sensor_id'] ?? 0);
            if ($sid <= 0) {
                throw new RuntimeException('Sensor id required.');
            }
            Database::update('env_sensors', [
                'is_active' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'sensor_id = :id', [':id' => $sid]);
            App::flash('success', 'Sensor deactivated.');
            App::redirect('pages/env_sensors.php');
        }
    } catch (Throwable $e) {
        App::log('env_sensors POST: ' . $e->getMessage(), 'error');
        App::flash('error', $e->getMessage());
        $rid = (int)($_POST['sensor_id'] ?? 0);
        App::redirect('pages/env_sensors.php' . ($rid ? '?id=' . $rid : ''));
    }
}

if ($sensorId > 0) {
    $s = Database::fetchOne(
        'SELECT s.*,
                rm.name AS room_name,
                cu.name AS cooling_unit_name,
                p.name AS pdu_name,
                c.name AS cabinet_name,
                d.label AS device_label
         FROM env_sensors s
         LEFT JOIN rooms rm ON rm.room_id = s.room_id
         LEFT JOIN cooling_units cu ON cu.cooling_unit_id = s.cooling_unit_id
         LEFT JOIN pdus p ON p.pdu_id = s.pdu_id
         LEFT JOIN cabinets c ON c.cabinet_id = s.cabinet_id
         LEFT JOIN devices d ON d.device_id = s.device_id
         WHERE s.sensor_id = ?',
        [$sensorId]
    );
    if (!$s || empty($s['is_active'])) {
        App::flash('error', 'Sensor not found.');
        App::redirect('pages/env_sensors.php');
    }
    $readings = [];
    try {
        $readings = Database::fetchAll(
            'SELECT TOP 80 reading_id, value, recorded_at, metric
             FROM env_readings WHERE sensor_id = ?
             ORDER BY recorded_at DESC',
            [$sensorId]
        );
    } catch (Throwable $e) {
        try {
            $readings = Database::fetchAll(
                'SELECT TOP 80 reading_id, value, recorded_at
                 FROM env_readings WHERE sensor_id = ?
                 ORDER BY recorded_at DESC',
                [$sensorId]
            );
        } catch (Throwable $e2) {
            $readings = [];
        }
    }
    $val = $s['last_value'] !== null && $s['last_value'] !== '' ? (float)$s['last_value'] : null;
    $hum = isset($s['last_humidity']) && $s['last_humidity'] !== null && $s['last_humidity'] !== ''
        ? (float)$s['last_humidity'] : null;
    $kindKey = (string)($s['sensor_kind'] ?? 'temperature');
    $isCombo = $kindKey === 'temp_humidity';
    $st = env_sensor_threshold_status($val, $s);
    $stHum = ($hum !== null && ($isCombo || $kindKey === 'humidity'))
        ? env_sensor_threshold_status($hum, [
            'warn_high' => class_exists('EnvSensorAlertService')
                ? EnvSensorAlertService::settings()['rh_warn'] : 70,
            'crit_high' => class_exists('EnvSensorAlertService')
                ? EnvSensorAlertService::settings()['rh_crit'] : 90,
            'warn_low' => 20,
            'crit_low' => null,
        ])
        : 'unknown';

    layout_header('Sensor: ' . $s['name'], $user, 'env_sensors');
    ?>
    <div class="flex-between mb-2">
        <div>
            <a class="text-muted" href="<?= App::e(App::url('pages/env_sensors.php')) ?>">← All sensors</a>
            <h2 class="mt-1 mb-0"><?= App::e($s['name']) ?></h2>
            <p class="text-muted mb-0" style="font-size:.9rem">
                <?= App::e($kinds[$kindKey] ?? $kindKey) ?>
                · <?= App::e($placements[$s['placement'] ?? ''] ?? '') ?>
                <?php if (!empty($s['cabinet_name'])): ?>
                    · <?= App::e((string)$s['cabinet_name']) ?>
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cooling.php')) ?>">Dashboard</a>
    </div>

    <div class="metrics power-metrics mb-2">
        <div class="metric-card <?= $st === 'crit' ? 'danger' : ($st === 'warn' ? 'warning' : 'success') ?>">
            <div class="label"><?= $kindKey === 'humidity' ? 'Humidity' : 'Temperature' ?></div>
            <div class="value">
                <?php if ($val !== null): ?>
                    <?php
                    $dispVal = $val;
                    $dispUnit = $kindKey === 'humidity' ? '%RH' : (class_exists('TempUnitService') ? TempUnitService::symbol() : '°C');
                    if ($kindKey !== 'humidity' && class_exists('TempUnitService') && TempUnitService::isTempKind($kindKey)) {
                        $conv = TempUnitService::fromC($val);
                        $dispVal = $conv ?? $val;
                    }
                    ?>
                    <?= App::e(rtrim(rtrim(number_format($dispVal, 1, '.', ''), '0'), '.') ?: '0') ?>
                    <span class="metric-unit"><?= App::e($dispUnit) ?></span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <div class="sub">status: <?= App::e($st) ?><?= !empty($s['snmp_index']) ? ' · ' . App::e((string)$s['snmp_index']) : '' ?></div>
        </div>
        <?php if ($isCombo): ?>
        <div class="metric-card <?= $stHum === 'crit' ? 'danger' : ($stHum === 'warn' ? 'warning' : 'success') ?>">
            <div class="label">Relative humidity</div>
            <div class="value">
                <?php if ($hum !== null): ?>
                    <?= App::e(rtrim(rtrim(number_format($hum, 0, '.', ''), '0'), '.') ?: '0') ?>
                    <span class="metric-unit">%RH</span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <div class="sub">status: <?= App::e($stHum) ?></div>
        </div>
        <?php endif; ?>
        <div class="metric-card">
            <div class="label">Host</div>
            <div class="value" style="font-size:1rem">
                <?= App::e(env_sensor_host_display($s, $hosts)) ?>
            </div>
            <div class="sub"><?= App::e((string)($s['location_label'] ?? $s['room_name'] ?? '')) ?></div>
        </div>
        <div class="metric-card">
            <div class="label">Thresholds (primary<?= class_exists('TempUnitService') && TempUnitService::isTempKind($kindKey) ? ' ' . TempUnitService::symbol() : '' ?>)</div>
            <div class="value" style="font-size:.95rem">
                <?php
                $fmtThr = static function ($v) use ($kindKey) {
                    if ($v === null || $v === '') {
                        return '—';
                    }
                    if (!is_numeric($v)) {
                        return (string)$v;
                    }
                    if (class_exists('TempUnitService') && TempUnitService::isTempKind($kindKey)) {
                        return TempUnitService::format((float)$v, 1);
                    }
                    return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.') ?: '0';
                };
                ?>
                W <?= App::e($fmtThr($s['warn_low'] ?? null) . ' / ' . $fmtThr($s['warn_high'] ?? null)) ?>
            </div>
            <div class="sub">
                C <?= App::e($fmtThr($s['crit_low'] ?? null) . ' / ' . $fmtThr($s['crit_high'] ?? null)) ?>
            </div>
        </div>
        <div class="metric-card">
            <div class="label">Last seen</div>
            <div class="value" style="font-size:.95rem"><?= App::e((string)($s['last_seen_at'] ?? '—')) ?></div>
            <div class="sub">
                <?php if (!empty($s['device_id'])): ?>
                    via host poll / manual
                <?php else: ?>
                    manual
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($s['device_id'])): ?>
    <p class="mb-2">
        <a class="btn btn-secondary btn-sm"
           href="<?= App::e(App::url('pages/devices.php?id=' . (int)$s['device_id'])) ?>">
            Open host device
        </a>
        <span class="text-muted" style="font-size:.85rem;margin-left:.5rem">
            Enable <strong>Scheduled poll</strong> on the host so this sensor refreshes automatically.
        </span>
    </p>
    <?php endif; ?>

    <div class="card power-history-wide mb-2" data-env-history data-id="<?= (int)$sensorId ?>" data-hours="24">
        <div class="card-header flex-between">
            <h3 class="mt-0 mb-0" style="font-size:1rem">History (24h)</h3>
            <span class="text-muted" style="font-size:.8rem" data-env-chart-status>Loading…</span>
        </div>
        <div class="card-body">
            <div class="mb-2">
                <div class="text-muted" style="font-size:.8rem;margin-bottom:.35rem">Temperature / primary</div>
                <div data-env-series="temp" class="env-chart-host" style="min-height:170px"></div>
            </div>
            <div data-env-hum-wrap <?= ($isCombo || $kindKey === 'humidity') ? '' : 'hidden' ?>>
                <div class="text-muted" style="font-size:.8rem;margin-bottom:.35rem">Humidity</div>
                <div data-env-series="humidity" class="env-chart-host" style="min-height:150px"></div>
            </div>
        </div>
    </div>

    <?php if ($canEdit): ?>
    <div class="card mb-2">
        <div class="card-header"><h3 class="mt-0 mb-0" style="font-size:1rem">Manual reading</h3></div>
        <div class="card-body">
            <form method="post" class="form-grid form-grid-3" style="max-width:36rem">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="record_reading">
                <input type="hidden" name="sensor_id" value="<?= (int)$sensorId ?>">
                <div class="form-row">
                    <label><?= $kindKey === 'humidity'
                        ? 'Humidity (%RH)'
                        : ('Temperature (' . (class_exists('TempUnitService') ? TempUnitService::symbol() : '°C') . ')') ?></label>
                    <input class="form-control" type="number" step="any" name="value" required
                           value="<?= $val !== null
                               ? App::e(
                                   (class_exists('TempUnitService') && TempUnitService::isTempKind($kindKey) && $kindKey !== 'humidity')
                                       ? (string)round((float)TempUnitService::fromC($val), 2)
                                       : (string)$val
                               )
                               : '' ?>">
                </div>
                <?php if ($isCombo): ?>
                <div class="form-row">
                    <label>Humidity (%RH)</label>
                    <input class="form-control" type="number" step="any" name="humidity"
                           value="<?= $hum !== null ? App::e((string)$hum) : '' ?>"
                           placeholder="optional">
                </div>
                <?php endif; ?>
                <div class="form-row" style="align-self:end">
                    <button type="submit" class="btn btn-primary">Record</button>
                </div>
            </form>
            <p class="text-muted mb-0 mt-1" style="font-size:.8rem">
                Prefer SNMP via host device Poll / scheduled poll. Manual entry still updates history and can fire threshold alerts.
            </p>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-header"><h3 class="mt-0 mb-0" style="font-size:1rem">Edit sensor</h3></div>
        <div class="card-body">
            <?php
            $edit = $s;
            $formAction = 'update_sensor';
            require __DIR__ . '/_env_sensor_form.php';
            ?>
            <form method="post" class="mt-2" onsubmit="return confirm('Deactivate this sensor?');">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="deactivate_sensor">
                <input type="hidden" name="sensor_id" value="<?= (int)$sensorId ?>">
                <button type="submit" class="btn btn-danger btn-sm">Deactivate sensor</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3 class="mt-0 mb-0" style="font-size:1rem">Recent readings</h3></div>
        <div class="card-body" style="padding:0">
            <?php if (!$readings): ?>
                <p class="text-muted p-2 mb-0">No readings yet.</p>
            <?php else: ?>
                <table class="table table-sm">
                    <thead><tr><th>When (UTC)</th><th>Metric</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php foreach ($readings as $r):
                        $m = strtolower((string)($r['metric'] ?? ''));
                        $mLab = $m === 'humidity' ? 'Humidity' : ($m === 'temperature' || $m === 'primary' || $m === '' ? 'Temp / primary' : $m);
                        $unitR = ($m === 'humidity' || $kindKey === 'humidity')
                            ? '%RH'
                            : (class_exists('TempUnitService') ? TempUnitService::symbol() : '°C');
                        $rv = $r['value'];
                        if ($m !== 'humidity' && $kindKey !== 'humidity' && is_numeric($rv)
                            && class_exists('TempUnitService') && TempUnitService::isTempKind($kindKey)
                        ) {
                            $rv = TempUnitService::format((float)$rv, 2);
                        }
                        ?>
                        <tr>
                            <td><?= App::e((string)$r['recorded_at']) ?></td>
                            <td class="text-muted" style="font-size:.85rem"><?= App::e($mLab) ?></td>
                            <td><?= App::e((string)$rv) ?> <?= App::e($unitR) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <script src="<?= App::e(App::url('assets/js/env-charts.js')) ?>?v=1"></script>
    <?php
    layout_footer();
    return;
}

// List
$where = 's.is_active = 1';
$params = [];
if ($filterCu > 0) {
    $where .= ' AND s.cooling_unit_id = ?';
    $params[] = $filterCu;
}
if ($filterDevice > 0) {
    $where .= ' AND s.device_id = ?';
    $params[] = $filterDevice;
}
$sensors = [];
try {
    $sensors = Database::fetchAll(
        "SELECT s.*,
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
         WHERE $where
         ORDER BY s.name",
        $params
    );
} catch (Throwable $e) {
    // Fallback without cabinet join if COALESCE/schema varies
    try {
        $sensors = Database::fetchAll(
            "SELECT s.*,
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
             WHERE $where
             ORDER BY s.name",
            $params
        );
    } catch (Throwable $e2) {
        $sensors = [];
    }
}

layout_header('Environmental sensors', $user, 'env_sensors');
?>

<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0" style="font-size:.92rem">
            Temperature, humidity, and related points linked to env managers, expansion modules, PDUs, or rooms.
            Values refresh when the host device is polled (or via manual reading).
        </p>
    </div>
    <?php if ($canEdit): ?>
        <button type="button" class="btn btn-primary" data-ca-modal-open="addEnvSensor"
                id="btnAddEnvSensor">Add sensor</button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (!$sensors): ?>
            <p class="text-muted p-2 mb-0">No sensors yet. Add cold-aisle or supply-air points as needed.</p>
        <?php else: ?>
            <div class="table-wrap env-sensors-table-wrap">
                <table class="table table-env-sensors">
                    <thead>
                    <tr>
                        <th class="col-name">Name</th>
                        <th class="col-kind">Kind</th>
                        <th class="col-host">Host</th>
                        <th class="col-place">Placement</th>
                        <th class="col-value">Last value</th>
                        <th class="col-status">Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sensors as $s):
                        $val = $s['last_value'] !== null && $s['last_value'] !== '' ? (float)$s['last_value'] : null;
                        $st = env_sensor_threshold_status($val, $s);
                        $badge = match ($st) {
                            'crit' => 'badge-danger',
                            'warn' => 'badge-warning',
                            'ok' => 'badge-success',
                            default => 'badge-muted',
                        };
                        $hostLabel = env_sensor_host_display($s, $hosts);
                        $kindKey = (string)($s['sensor_kind'] ?? '');
                        $kindFull = $kinds[$kindKey] ?? $kindKey;
                        $kindShort = env_sensor_kind_short($kindKey, $kinds);
                        $deviceId = (int)($s['device_id'] ?? 0);
                        ?>
                        <tr>
                            <td class="col-name">
                                <a href="<?= App::e(App::url('pages/env_sensors.php?id=' . (int)$s['sensor_id'])) ?>">
                                    <strong><?= App::e($s['name']) ?></strong>
                                </a>
                                <?php if (!empty($s['location_label'])): ?>
                                    <div class="text-muted env-sensor-sub"><?= App::e((string)$s['location_label']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="col-kind" title="<?= App::e($kindFull) ?>">
                                <?= App::e($kindShort) ?>
                            </td>
                            <td class="col-host">
                                <?php if ($deviceId > 0 && ($s['host_type'] ?? '') === 'device'): ?>
                                    <a href="<?= App::e(App::url('pages/devices.php?id=' . $deviceId)) ?>">
                                        <?= App::e($hostLabel) ?>
                                    </a>
                                    <?php if (!empty($s['device_type'])): ?>
                                        <div class="text-muted env-sensor-sub"><?= App::e((string)$s['device_type']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= App::e($hostLabel !== '' ? $hostLabel : '—') ?>
                                    <div class="text-muted env-sensor-sub"><?= App::e($hosts[$s['host_type'] ?? ''] ?? '') ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="col-place">
                                <?= App::e($placements[$s['placement'] ?? ''] ?? '—') ?>
                                <?php if (!empty($s['cabinet_name'])): ?>
                                    <div class="text-muted env-sensor-sub"><?= App::e((string)$s['cabinet_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="col-value">
                                <?php if ($val !== null): ?>
                                    <span class="env-sensor-value">
                                        <?php
                                        $listVal = $val;
                                        $listUnit = (string)($s['unit'] ?? '');
                                        if ($kindKey !== 'humidity' && class_exists('TempUnitService')
                                            && TempUnitService::isTempKind($kindKey)
                                        ) {
                                            $listVal = TempUnitService::fromC($val) ?? $val;
                                            $listUnit = TempUnitService::symbol();
                                        }
                                        ?>
                                        <?= App::e(rtrim(rtrim(number_format((float)$listVal, 1, '.', ''), '0'), '.') ?: '0') ?>
                                        <?php
                                        $humL = $s['last_humidity'] ?? null;
                                        if ($kindKey === 'temp_humidity' && $humL !== null && $humL !== ''):
                                            ?>
                                            <span class="text-muted"><?= App::e(class_exists('TempUnitService') ? TempUnitService::symbol() : '°C') ?></span>
                                            <span class="env-sensor-rh">/ <?= App::e(rtrim(rtrim(number_format((float)$humL, 0, '.', ''), '0'), '.') ?: '0') ?>%RH</span>
                                        <?php else: ?>
                                            <span class="text-muted"><?= App::e($listUnit) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if (!empty($s['last_seen_at'])): ?>
                                        <div class="text-muted env-sensor-sub"><?= App::e((string)$s['last_seen_at']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-status"><span class="badge <?= $badge ?>"><?= App::e($st) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit):
    $edit = [];
    if ($filterCu > 0) {
        $edit = ['host_type' => 'cooling_unit', 'cooling_unit_id' => $filterCu];
    } elseif ($filterDevice > 0) {
        $edit = ['host_type' => $prefillHost !== '' ? $prefillHost : 'device', 'device_id' => $filterDevice];
        foreach ($devices as $dv) {
            if ((int)$dv['device_id'] === $filterDevice) {
                if (!empty($dv['cabinet_id'])) {
                    $edit['cabinet_id'] = (int)$dv['cabinet_id'];
                }
                break;
            }
        }
    }
    $modalId = 'addEnvSensor';
    // Prefer in-page modal on device pages; only auto-open if ?open=1 (legacy deep links)
    $autoOpen = isset($_GET['open']) && (string)$_GET['open'] === '1';
    require __DIR__ . '/_env_sensor_add_modal.php';
endif; ?>

<?php layout_footer(); ?>
