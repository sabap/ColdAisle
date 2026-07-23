<?php
declare(strict_types=1);

require_once __DIR__ . '/src/App.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/audit_helpers.php';

App::boot();
if (!App::isInstalled()) {
    header('Location: setup.php');
    exit;
}
$user = App::requireAuth();

// Metrics
$metrics = [
    'sites' => (int) Database::fetchValue('SELECT COUNT(*) FROM sites WHERE is_active = 1'),
    'datacenters' => (int) Database::fetchValue('SELECT COUNT(*) FROM datacenters WHERE is_active = 1'),
    'cabinets' => (int) Database::fetchValue('SELECT COUNT(*) FROM cabinets WHERE is_active = 1'),
    'devices' => (int) Database::fetchValue('SELECT COUNT(*) FROM devices WHERE is_active = 1 AND status <> \'disposed\''),
    'pdus' => (int) Database::fetchValue('SELECT COUNT(*) FROM pdus WHERE is_active = 1'),
    'disposals' => (int) Database::fetchValue("SELECT COUNT(*) FROM disposals WHERE status IN ('pending','approved','in_progress')"),
];
$auditCompliance = ['compliance_pct' => 100.0, 'overdue' => 0, 'total' => 0, 'due_soon' => 0];
try {
    $auditCompliance = audit_compliance_summary();
} catch (Throwable $e) {
    // helpers / tables may not exist yet
}

$uUsed = (int) Database::fetchValue(
    'SELECT ISNULL(SUM(u_height),0) FROM devices WHERE is_active = 1 AND cabinet_id IS NOT NULL AND position_u IS NOT NULL'
);
$uTotal = (int) Database::fetchValue('SELECT ISNULL(SUM(u_height),0) FROM cabinets WHERE is_active = 1');
$uPct = $uTotal > 0 ? round(100 * $uUsed / $uTotal, 1) : 0;

$powerKw = (float) Database::fetchValue(
    'SELECT ISNULL(SUM(last_poll_watts),0) / 1000.0 FROM pdus WHERE is_active = 1 AND last_poll_watts IS NOT NULL'
);

$recentDevices = Database::fetchAll(
    'SELECT TOP 8 d.device_id, d.label, d.device_type, d.status, d.position_u, c.name AS cabinet_name
     FROM devices d
     LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
     WHERE d.is_active = 1
     ORDER BY d.updated_at DESC'
);

$recentAudit = Database::fetchAll(
    'SELECT TOP 8 audit_id, username, action, entity_type, entity_id, created_at
     FROM audit_log ORDER BY created_at DESC'
);

$cabinets3d = Database::fetchAll(
    'SELECT c.cabinet_id, c.name, c.pos_x, c.pos_y, c.pos_z, c.rotation_deg,
            c.u_height, c.width_mm, c.depth_mm, c.color_hex,
            r.name AS room_name, r.width_m AS room_width, r.depth_m AS room_depth,
            (SELECT COUNT(*) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1) AS device_count,
            (SELECT ISNULL(SUM(d.u_height),0) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1 AND d.position_u IS NOT NULL) AS u_used
     FROM cabinets c
     INNER JOIN rooms r ON r.room_id = c.room_id
     WHERE c.is_active = 1
     ORDER BY c.name'
);
$cabinets3d = Cabinet3dData::withDevices($cabinets3d);

// Floor-placed row/room PDUs for dashboard 3D (same wireframe style as floor planner)
$pdus3d = [];
try {
    $pdus3d = Database::fetchAll(
        'SELECT p.pdu_id, p.name, p.pos_x, p.pos_y, p.pos_z, p.rotation_deg, p.front_facing,
                p.width_mm, p.depth_mm, p.height_mm, p.color_hex, p.pdu_scope,
                z.name AS zone_name, z.color_hex AS zone_color,
                r.name AS room_name, r.width_m AS room_width, r.depth_m AS room_depth
         FROM pdus p
         LEFT JOIN power_zones z ON z.zone_id = p.zone_id
         LEFT JOIN rooms r ON r.room_id = p.room_id
         WHERE p.is_active = 1
           AND p.pdu_scope IN (\'row\', \'room\')
           AND p.pos_x IS NOT NULL AND p.pos_y IS NOT NULL
         ORDER BY p.name'
    );
} catch (Throwable $e) {
    $pdus3d = [];
}

$rooms = Database::fetchAll(
    'SELECT r.room_id, r.name, r.width_m, r.depth_m, dc.name AS dc_name
     FROM rooms r
     INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
     WHERE r.is_active = 1
     ORDER BY dc.name, r.name'
);

layout_header('Dashboard', $user, 'dashboard');
?>

<div class="metrics">
    <div class="metric-card accent">
        <div class="label">Cabinets</div>
        <div class="value"><?= $metrics['cabinets'] ?></div>
        <div class="sub"><?= $metrics['datacenters'] ?> data centers</div>
    </div>
    <div class="metric-card">
        <div class="label">Devices</div>
        <div class="value"><?= $metrics['devices'] ?></div>
        <div class="sub">Active inventory</div>
    </div>
    <div class="metric-card success">
        <div class="label">U Utilization</div>
        <div class="value"><?= $uPct ?>%</div>
        <div class="sub"><?= $uUsed ?> / <?= $uTotal ?> U used</div>
    </div>
    <div class="metric-card warning">
        <div class="label">Power (polled)</div>
        <div class="value"><?= number_format($powerKw, 1) ?></div>
        <div class="sub">kW across <?= $metrics['pdus'] ?> PDUs</div>
    </div>
    <div class="metric-card <?= $metrics['disposals'] ? 'danger' : '' ?>">
        <div class="label">Disposals</div>
        <div class="value"><?= $metrics['disposals'] ?></div>
        <div class="sub">Open tracking items</div>
    </div>
    <?php
    $acPct = (float)($auditCompliance['compliance_pct'] ?? 100);
    $acOver = (int)($auditCompliance['overdue'] ?? 0);
    $acClass = $acPct >= 90 ? 'success' : ($acPct >= 70 ? 'accent' : ($acPct >= 50 ? 'warning' : 'danger'));
    ?>
    <div class="metric-card <?= $acClass === 'success' ? 'success' : ($acClass === 'danger' ? 'danger' : ($acClass === 'warning' ? 'warning' : 'accent')) ?>">
        <div class="label">Audit compliance</div>
        <div class="value"><?= number_format($acPct, 0) ?><span class="metric-unit">%</span></div>
        <div class="sub">
            <?php if ($acOver > 0): ?>
                <a href="<?= App::e(App::url('pages/audits.php')) ?>"><?= $acOver ?> overdue</a>
            <?php else: ?>
                <a href="<?= App::e(App::url('pages/audits.php')) ?>">All cabinets in window</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="dash-grid">
    <div class="card">
        <div class="card-header">
            <h2>Data Center Layout (3D)</h2>
            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/floorplan.php')) ?>">Edit Floor Plan</a>
        </div>
        <div class="panel-3d" id="dashboard-3d"
             data-cabinets='<?= App::e(json_encode($cabinets3d)) ?>'
             data-pdus='<?= App::e(json_encode($pdus3d)) ?>'
             data-rooms='<?= App::e(json_encode($rooms)) ?>'></div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Recent Devices</h2>
            <a class="btn btn-sm btn-primary" href="<?= App::e(App::url('pages/devices.php?action=new')) ?>">+ Device</a>
        </div>
        <div class="card-body flush">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Label</th><th>Type</th><th>Cabinet</th><th>U</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentDevices): ?>
                        <tr><td colspan="5" class="text-muted">No devices yet. Add cabinets and devices to get started.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentDevices as $d): ?>
                        <tr>
                            <td><a href="<?= App::e(App::url('pages/devices.php?id=' . $d['device_id'])) ?>"><?= App::e($d['label']) ?></a></td>
                            <td><?= App::e($d['device_type']) ?></td>
                            <td><?= App::e($d['cabinet_name'] ?? '—') ?></td>
                            <td><?= $d['position_u'] !== null ? (int)$d['position_u'] : '—' ?></td>
                            <td><span class="badge badge-info"><?= App::e($d['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Audit Activity</h2></div>
    <div class="card-body flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>When (UTC)</th><th>User</th><th>Action</th><th>Entity</th></tr>
                </thead>
                <tbody>
                <?php if (!$recentAudit): ?>
                    <tr><td colspan="4" class="text-muted">No activity logged yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentAudit as $a): ?>
                    <tr>
                        <td><?= App::e($a['created_at']) ?></td>
                        <td><?= App::e($a['username'] ?? 'system') ?></td>
                        <td><?= App::e($a['action']) ?></td>
                        <td><?= App::e(($a['entity_type'] ?? '') . ($a['entity_id'] ? ' #' . $a['entity_id'] : '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="<?= App::e(App::url('assets/js/dcim-3d.js')) ?>?v=3"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('dashboard-3d');
    if (el && window.WinDCIM3D) {
        const cabinets = JSON.parse(el.dataset.cabinets || '[]');
        const pdus = JSON.parse(el.dataset.pdus || '[]');
        const rooms = JSON.parse(el.dataset.rooms || '[]');
        WinDCIM3D.mount(el, { cabinets: cabinets, pdus: pdus, rooms: rooms, interactive: true });
    }
});
</script>
<?php layout_footer(); ?>
