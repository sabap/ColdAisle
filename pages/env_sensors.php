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
                throw new RuntimeException('Sensor and numeric value required.');
            }
            $value = (float)$val;
            Database::insert('env_readings', [
                'sensor_id' => $sid,
                'value' => $value,
            ]);
            Database::update('env_sensors', [
                'last_value' => $value,
                'last_seen_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'sensor_id = :id', [':id' => $sid]);
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
                c.name AS cabinet_name
         FROM env_sensors s
         LEFT JOIN rooms rm ON rm.room_id = s.room_id
         LEFT JOIN cooling_units cu ON cu.cooling_unit_id = s.cooling_unit_id
         LEFT JOIN pdus p ON p.pdu_id = s.pdu_id
         LEFT JOIN cabinets c ON c.cabinet_id = s.cabinet_id
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
            'SELECT TOP 50 reading_id, value, recorded_at
             FROM env_readings WHERE sensor_id = ?
             ORDER BY recorded_at DESC',
            [$sensorId]
        );
    } catch (Throwable $e) {
        $readings = [];
    }
    $val = $s['last_value'] !== null && $s['last_value'] !== '' ? (float)$s['last_value'] : null;
    $st = env_sensor_threshold_status($val, $s);

    layout_header('Sensor: ' . $s['name'], $user, 'env_sensors');
    ?>
    <div class="flex-between mb-2">
        <div>
            <a class="text-muted" href="<?= App::e(App::url('pages/env_sensors.php')) ?>">← All sensors</a>
            <h2 class="mt-1 mb-0"><?= App::e($s['name']) ?></h2>
            <p class="text-muted mb-0" style="font-size:.9rem">
                <?= App::e($kinds[$s['sensor_kind'] ?? ''] ?? '') ?>
                · <?= App::e($hosts[$s['host_type'] ?? ''] ?? '') ?>
                · <?= App::e($placements[$s['placement'] ?? ''] ?? '') ?>
            </p>
        </div>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cooling.php')) ?>">Dashboard</a>
    </div>

    <div class="metrics power-metrics mb-2">
        <div class="metric-card <?= $st === 'crit' ? 'danger' : ($st === 'warn' ? 'warning' : 'success') ?>">
            <div class="label">Last value</div>
            <div class="value">
                <?php if ($val !== null): ?>
                    <?= App::e(rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.')) ?>
                    <?php
                    $hum = $s['last_humidity'] ?? null;
                    if (($s['sensor_kind'] ?? '') === 'temp_humidity' && $hum !== null && $hum !== ''):
                        $hv = (float)$hum;
                        ?>
                        <span class="metric-unit">°C</span>
                        <span class="text-muted" style="font-size:.85rem;font-weight:500">
                            / <?= App::e(rtrim(rtrim(number_format($hv, 1, '.', ''), '0'), '.')) ?>%RH
                        </span>
                    <?php else: ?>
                        <span class="metric-unit"><?= App::e((string)($s['unit'] ?? '')) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <div class="sub">status: <?= App::e($st) ?><?= !empty($s['snmp_index']) ? ' · key ' . App::e((string)$s['snmp_index']) : '' ?></div>
        </div>
        <div class="metric-card">
            <div class="label">Host</div>
            <div class="value" style="font-size:1rem">
                <?php
                echo App::e(match ($s['host_type'] ?? '') {
                    'cooling_unit' => (string)($s['cooling_unit_name'] ?? 'Cooling unit'),
                    'pdu' => (string)($s['pdu_name'] ?? 'PDU'),
                    'cabinet' => (string)($s['cabinet_name'] ?? 'Cabinet'),
                    'room' => (string)($s['room_name'] ?? 'Room'),
                    default => (string)($hosts[$s['host_type'] ?? ''] ?? 'Standalone'),
                });
                ?>
            </div>
            <div class="sub"><?= App::e((string)($s['location_label'] ?? $s['room_name'] ?? '')) ?></div>
        </div>
        <div class="metric-card">
            <div class="label">Thresholds</div>
            <div class="value" style="font-size:.95rem">
                W <?= App::e(($s['warn_low'] ?? '—') . ' / ' . ($s['warn_high'] ?? '—')) ?>
            </div>
            <div class="sub">
                C <?= App::e(($s['crit_low'] ?? '—') . ' / ' . ($s['crit_high'] ?? '—')) ?>
            </div>
        </div>
        <div class="metric-card">
            <div class="label">Last seen</div>
            <div class="value" style="font-size:.95rem"><?= App::e((string)($s['last_seen_at'] ?? '—')) ?></div>
            <div class="sub"><?= !empty($s['snmp_oid']) ? 'OID set' : 'manual / host poll later' ?></div>
        </div>
    </div>

    <?php if (!empty($s['device_id'])): ?>
    <p class="mb-2">
        <a class="btn btn-secondary btn-sm"
           href="<?= App::e(App::url('pages/devices.php?id=' . (int)$s['device_id'])) ?>">
            Open host device
        </a>
    </p>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <div class="card mb-2">
        <div class="card-header"><h3 class="mt-0 mb-0" style="font-size:1rem">Manual reading</h3></div>
        <div class="card-body">
            <form method="post" class="form-grid form-grid-3" style="max-width:28rem">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="record_reading">
                <input type="hidden" name="sensor_id" value="<?= (int)$sensorId ?>">
                <div class="form-row"><label>Value (<?= App::e((string)($s['unit'] ?? '')) ?>)</label>
                    <input class="form-control" type="number" step="any" name="value" required></div>
                <div class="form-row" style="align-self:end">
                    <button type="submit" class="btn btn-primary">Record</button>
                </div>
            </form>
            <p class="text-muted mb-0 mt-1" style="font-size:.8rem">
                SNMP polling for probes will reuse site OID templates in a later slice; manual entry validates history now.
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
                    <thead><tr><th>When (UTC storage)</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php foreach ($readings as $r): ?>
                        <tr>
                            <td><?= App::e((string)$r['recorded_at']) ?></td>
                            <td><?= App::e((string)$r['value']) ?> <?= App::e((string)($s['unit'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
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
                                        <?= App::e(rtrim(rtrim(number_format($val, 1, '.', ''), '0'), '.') ?: '0') ?>
                                        <?php
                                        $humL = $s['last_humidity'] ?? null;
                                        if ($kindKey === 'temp_humidity' && $humL !== null && $humL !== ''):
                                            ?>
                                            <span class="text-muted">°C</span>
                                            <span class="env-sensor-rh">/ <?= App::e(rtrim(rtrim(number_format((float)$humL, 0, '.', ''), '0'), '.') ?: '0') ?>%RH</span>
                                        <?php else: ?>
                                            <span class="text-muted"><?= App::e((string)($s['unit'] ?? '')) ?></span>
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
