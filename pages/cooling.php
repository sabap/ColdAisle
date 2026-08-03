<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/cooling_helpers.php';
App::boot();
$user = App::requirePermission('view_cooling');

$units = [];
$sensors = [];
try {
    $units = Database::fetchAll(
        'SELECT u.*,
                rm.name AS room_name, dc.name AS dc_name,
                primary_u.name AS primary_unit_name
         FROM cooling_units u
         LEFT JOIN rooms rm ON rm.room_id = u.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         LEFT JOIN cooling_units primary_u ON primary_u.cooling_unit_id = u.standby_of_id
         WHERE u.is_active = 1
         ORDER BY u.name'
    );
} catch (Throwable $e) {
    $units = [];
    App::log('cooling dashboard units: ' . $e->getMessage(), 'warning');
}

try {
    $sensors = Database::fetchAll(
        'SELECT s.*,
                rm.name AS room_name,
                cu.name AS cooling_unit_name,
                p.name AS pdu_name
         FROM env_sensors s
         LEFT JOIN rooms rm ON rm.room_id = s.room_id
         LEFT JOIN cooling_units cu ON cu.cooling_unit_id = s.cooling_unit_id
         LEFT JOIN pdus p ON p.pdu_id = s.pdu_id
         WHERE s.is_active = 1
         ORDER BY s.name'
    );
} catch (Throwable $e) {
    $sensors = [];
}

$types = cooling_unit_types();
$roles = cooling_unit_roles();
$media = cooling_media();
$kinds = env_sensor_kinds();

$primaryCount = count(array_filter($units, static fn($u) => ($u['unit_role'] ?? '') === 'primary'));
$standbyCount = count(array_filter($units, static fn($u) => ($u['unit_role'] ?? '') === 'standby'));
$snmpOn = count(array_filter($units, static fn($u) => !empty($u['snmp_enabled'])));
$ratedKw = array_sum(array_map(static fn($u) => (float)($u['rated_kw_cooling'] ?? 0), $units));
$chilledWater = count(array_filter($units, static fn($u) => ($u['cooling_medium'] ?? '') === 'chilled_water'));
$pumps = count(array_filter($units, static fn($u) => in_array($u['unit_type'] ?? '', ['chilled_water_pump', 'ac_pump'], true)));

$sensorByStatus = ['ok' => 0, 'warn' => 0, 'crit' => 0, 'unknown' => 0];
foreach ($sensors as $s) {
    $val = $s['last_value'] !== null && $s['last_value'] !== '' ? (float)$s['last_value'] : null;
    $st = env_sensor_threshold_status($val, $s);
    $sensorByStatus[$st] = ($sensorByStatus[$st] ?? 0) + 1;
}

$staleSensors = count(array_filter($sensors, static function ($s) {
    if (empty($s['last_seen_at'])) {
        return true;
    }
    return strtotime((string)$s['last_seen_at']) < (time() - 3600);
}));

$ashraeRec = cooling_ashrae_envelope('recommended');

layout_header('Cooling & Environment', $user, 'cooling');
?>

<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0" style="font-size:.92rem">
            Inventory air handlers, pumps, and environmental points. Start simple (active/standby pair)
            or expand with ASHRAE-oriented sensors and SNMP — no cooling zones required.
        </p>
    </div>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/env_sensors.php')) ?>">Env sensors</a>
        <a class="btn btn-primary" href="<?= App::e(App::url('pages/cooling_units.php')) ?>">Air &amp; pumps</a>
    </div>
</div>

<div class="metrics power-metrics">
    <div class="metric-card accent">
        <div class="label">Cooling units</div>
        <div class="value"><?= count($units) ?></div>
        <div class="sub">
            <?= (int)$primaryCount ?> primary · <?= (int)$standbyCount ?> standby
            <?php if ($pumps): ?> · <?= (int)$pumps ?> pump(s)<?php endif; ?>
        </div>
    </div>
    <div class="metric-card success">
        <div class="label">Rated capacity</div>
        <div class="value">
            <?php if ($ratedKw > 0): ?>
                <?= number_format($ratedKw, 1) ?> <span class="metric-unit">kW</span>
            <?php else: ?>
                —
            <?php endif; ?>
        </div>
        <div class="sub"><?= $chilledWater ? $chilledWater . ' chilled-water unit(s)' : 'Set rated kW on units' ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Env sensors</div>
        <div class="value"><?= count($sensors) ?></div>
        <div class="sub">
            <?= (int)$sensorByStatus['ok'] ?> ok ·
            <?= (int)$sensorByStatus['warn'] ?> warn ·
            <?= (int)$sensorByStatus['crit'] ?> crit
        </div>
    </div>
    <div class="metric-card <?= $staleSensors && $sensors ? 'danger' : '' ?>">
        <div class="label">SNMP / polls</div>
        <div class="value"><?= $snmpOn ?></div>
        <div class="sub">
            <?= $snmpOn ? 'units with SNMP' : 'enable on units when ready' ?>
            <?php if ($staleSensors && $sensors): ?> · <?= $staleSensors ?> sensor(s) stale<?php endif; ?>
        </div>
    </div>
</div>

<div class="card mb-2">
    <div class="card-header flex-between">
        <h3 class="mt-0 mb-0" style="font-size:1rem">ASHRAE TC 9.9 guidance (reference)</h3>
        <span class="badge badge-muted">not a compliance engine</span>
    </div>
    <div class="card-body">
        <p class="text-muted mb-1" style="font-size:.88rem">
            <strong><?= App::e($ashraeRec['label']) ?></strong>:
            dry-bulb
            <?php if ($ashraeRec['db_c'][0] !== null): ?>
                <?php
                $ashLo = (float)$ashraeRec['db_c'][0];
                $ashHi = (float)$ashraeRec['db_c'][1];
                if (class_exists('TempUnitService')) {
                    echo App::e(TempUnitService::format($ashLo, 0) . '–' . TempUnitService::format($ashHi, 0)
                        . ' ' . TempUnitService::symbol());
                } else {
                    echo App::e((string)$ashLo . '–' . (string)$ashHi . ' °C');
                }
                ?>
            <?php else: ?>
                site-defined
            <?php endif; ?>
            · RH limits apply (dew point preferred in full ASHRAE tables).
            <?= App::e($ashraeRec['notes']) ?>
        </p>
        <p class="text-muted mb-0" style="font-size:.8rem">
            Set an ASHRAE class on each unit and optional warn/crit thresholds on sensors.
            Classes A1–A4 and custom site bands are available on the unit form.
        </p>
    </div>
</div>

<div class="grid-2" style="gap:1rem;align-items:start">
    <div class="card">
        <div class="card-header flex-between">
            <h3 class="mt-0 mb-0" style="font-size:1rem">Cooling inventory</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/cooling_units.php')) ?>">All units</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (!$units): ?>
                <p class="text-muted p-2 mb-0">
                    No cooling units yet. Add your CRAC/CRAH pair (or pumps) under
                    <a href="<?= App::e(App::url('pages/cooling_units.php')) ?>">Air &amp; pumps</a>,
                    then place them on the floor plan like PDUs.
                </p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table-sm">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Role</th>
                            <th>Medium</th>
                            <th>Location</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($units, 0, 12) as $u): ?>
                            <tr>
                                <td>
                                    <a href="<?= App::e(App::url('pages/cooling_units.php?id=' . (int)$u['cooling_unit_id'])) ?>">
                                        <?= App::e($u['name']) ?>
                                    </a>
                                </td>
                                <td><?= App::e($types[$u['unit_type'] ?? ''] ?? ($u['unit_type'] ?? '—')) ?></td>
                                <td>
                                    <?= App::e($roles[$u['unit_role'] ?? ''] ?? ($u['unit_role'] ?? '—')) ?>
                                    <?php if (!empty($u['primary_unit_name'])): ?>
                                        <span class="text-muted" style="font-size:.75rem">of <?= App::e($u['primary_unit_name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= App::e($media[$u['cooling_medium'] ?? ''] ?? ($u['cooling_medium'] ?? '—')) ?></td>
                                <td class="text-muted" style="font-size:.85rem">
                                    <?php
                                    $loc = trim(($u['dc_name'] ?? '') . ' / ' . ($u['room_name'] ?? ''), ' /');
                                    echo App::e($loc !== '' ? $loc : '—');
                                    ?>
                                </td>
                                <td><span class="badge"><?= App::e((string)($u['status'] ?? '—')) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header flex-between">
            <h3 class="mt-0 mb-0" style="font-size:1rem">Environmental points</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/env_sensors.php')) ?>">All sensors</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (!$sensors): ?>
                <p class="text-muted p-2 mb-0">
                    No sensors yet. Add standalone probes, PDU-attached ports, or unit-mounted points under
                    <a href="<?= App::e(App::url('pages/env_sensors.php')) ?>">Env sensors</a>.
                </p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table-sm">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Kind</th>
                            <th>Host</th>
                            <th>Last value</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($sensors, 0, 12) as $s):
                            $val = $s['last_value'] !== null && $s['last_value'] !== '' ? (float)$s['last_value'] : null;
                            $st = env_sensor_threshold_status($val, $s);
                            $badge = match ($st) {
                                'crit' => 'badge-danger',
                                'warn' => 'badge-warning',
                                'ok' => 'badge-success',
                                default => 'badge-muted',
                            };
                            $hostLabel = match ($s['host_type'] ?? '') {
                                'cooling_unit' => $s['cooling_unit_name'] ?? 'Cooling unit',
                                'pdu' => $s['pdu_name'] ?? 'PDU',
                                'room' => $s['room_name'] ?? 'Room',
                                default => env_sensor_hosts()[$s['host_type'] ?? ''] ?? ($s['host_type'] ?? '—'),
                            };
                            ?>
                            <tr>
                                <td>
                                    <a href="<?= App::e(App::url('pages/env_sensors.php?id=' . (int)$s['sensor_id'])) ?>">
                                        <?= App::e($s['name']) ?>
                                    </a>
                                </td>
                                <td><?= App::e($kinds[$s['sensor_kind'] ?? ''] ?? ($s['sensor_kind'] ?? '—')) ?></td>
                                <td class="text-muted" style="font-size:.85rem"><?= App::e((string)$hostLabel) ?></td>
                                <td>
                                    <?php if ($val !== null): ?>
                                        <?= App::e(rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.')) ?>
                                        <?= App::e((string)($s['unit'] ?? '')) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $badge ?>"><?= App::e($st) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php layout_footer(); ?>
