<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/power_helpers.php';
App::boot();
$user = App::requirePermission('view_reports');

$report = $_GET['report'] ?? '';

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

function report_warranty(): array
{
    return Database::fetchAll(
        "SELECT label, manufacturer, model, serial_no, warranty_end, status, cabinet_id
         FROM devices
         WHERE is_active = 1 AND warranty_end IS NOT NULL
         ORDER BY warranty_end"
    );
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

layout_header('Reports', $user, 'reports');

$catalog = [
    'power_history' => 'Power History',
    'power_path' => 'Power Path',
    'inventory_summary' => 'Inventory Summary',
    'cabinet_utilization' => 'Cabinet Utilization',
    'power_capacity' => 'Power Capacity',
    'warranty_expiration' => 'Warranty Expiration',
    'disposal_queue' => 'Disposal Queue',
    'cable_inventory' => 'Cable Inventory',
    'orphaned_devices' => 'Orphaned Devices',
    'audit_history' => 'Audit History',
];

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
                <a class="metric-card" href="?report=<?= urlencode($key) ?>" style="color:inherit;text-decoration:none">
                    <div class="label">Report</div>
                    <div class="value" style="font-size:1rem"><?= App::e($label) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($report === 'power_history'):
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
        <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">Power dashboard</a>
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
<div class="card"><div class="card-header"><h2>Cabinet Utilization</h2></div>
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
        <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">Power dashboard</a>
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
<div class="card"><div class="card-header"><h2>PDUs</h2></div>
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
    $rows = report_warranty(); ?>
<div class="card"><div class="card-header"><h2>Warranty Expiration</h2></div>
    <div class="card-body flush"><table class="data">
        <thead><tr><th>Label</th><th>Make/Model</th><th>Serial</th><th>Warranty End</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= App::e($r['label']) ?></td>
                <td><?= App::e(trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? ''))) ?></td>
                <td><?= App::e($r['serial_no'] ?? '') ?></td>
                <td><?= App::e($r['warranty_end']) ?></td>
                <td><?= App::e($r['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">No warranty dates recorded.</td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php elseif ($report === 'disposal_queue'):
    $rows = report_disposal_queue(); ?>
<div class="card"><div class="card-header"><h2>Open Disposals</h2></div>
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
<div class="card"><div class="card-header"><h2>Cable Inventory</h2></div>
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
<div class="card"><div class="card-header"><h2>Orphaned Devices (no cabinet)</h2></div>
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
<div class="card"><div class="card-header"><h2>Completed Audits</h2></div>
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
<?php endif; ?>

<?php layout_footer(); ?>
