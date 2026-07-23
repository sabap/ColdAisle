<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_reports');

$report = $_GET['report'] ?? '';

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

function report_power_capacity(): array
{
    return [
        'zones' => Database::fetchAll('SELECT * FROM power_zones ORDER BY name'),
        'pdus' => Database::fetchAll(
            'SELECT p.name, p.pdu_scope, p.rated_amps, p.rated_volts, p.last_poll_watts, p.last_poll_amps, p.last_poll_at,
                    c.name AS cabinet_name, z.name AS zone_name
             FROM pdus p
             LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
             LEFT JOIN power_zones z ON z.zone_id = p.zone_id
             WHERE p.is_active = 1 ORDER BY p.name'
        ),
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
    'inventory_summary' => 'Inventory Summary',
    'cabinet_utilization' => 'Cabinet Utilization',
    'power_capacity' => 'Power Capacity',
    'warranty_expiration' => 'Warranty Expiration',
    'disposal_queue' => 'Disposal Queue',
    'cable_inventory' => 'Cable Inventory',
    'orphaned_devices' => 'Orphaned Devices',
    'audit_history' => 'Audit History',
];
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

<?php if ($report === 'inventory_summary'):
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
    $data = report_power_capacity(); ?>
<div class="card"><div class="card-header"><h2>Power Zones</h2></div>
    <div class="card-body flush"><table class="data">
        <thead><tr><th>Name</th><th>Feed</th><th>Voltage</th><th>Max kW</th><th>Max A</th></tr></thead>
        <tbody>
        <?php foreach ($data['zones'] as $z): ?>
            <tr><td><?= App::e($z['name']) ?></td><td><?= App::e($z['feed_type']) ?></td>
                <td><?= App::e((string)$z['voltage']) ?></td><td><?= App::e((string)$z['max_kw']) ?></td>
                <td><?= App::e((string)$z['max_amps']) ?></td></tr>
        <?php endforeach; ?>
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
