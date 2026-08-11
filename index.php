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

// Tech mode session → field hub (desktop chrome is full dashboard)
if (class_exists('TechMode') && TechMode::isActive() && empty($_GET['stay'])) {
    App::redirect('pages/tech.php');
}

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
    'SELECT ISNULL(SUM(u_height),0) FROM devices WHERE is_active = 1 AND cabinet_id IS NOT NULL AND position_u IS NOT NULL AND parent_device_id IS NULL'
);
$uTotal = (int) Database::fetchValue('SELECT ISNULL(SUM(u_height),0) FROM cabinets WHERE is_active = 1');
$uPct = $uTotal > 0 ? round(100 * $uUsed / $uTotal, 1) : 0;

$powerKw = (float) Database::fetchValue(
    'SELECT ISNULL(SUM(last_poll_watts),0) / 1000.0 FROM pdus WHERE is_active = 1 AND last_poll_watts IS NOT NULL'
);

$upsDash = [
    'units' => 0,
    'online' => 0,
    'on_battery' => 0,
    'health_crit' => 0,
    'health_warn' => 0,
    'avg_load_pct' => null,
    'min_battery_pct' => null,
];
try {
    require_once __DIR__ . '/includes/ups_helpers.php';
    if (function_exists('ups_dashboard_snapshot')) {
        $upsDash = array_merge($upsDash, ups_dashboard_snapshot(0));
        // listLimit 0 still returns aggregates; keep list empty for main dash
        $upsDash['list'] = [];
    }
} catch (Throwable $e) {
    // table may not exist yet
}

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
            (SELECT ISNULL(SUM(d.u_height),0) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1 AND d.position_u IS NOT NULL AND d.parent_device_id IS NULL) AS u_used
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
                p.width_mm, p.depth_mm, p.height_mm, p.color_hex, p.pdu_scope, p.ip_address,
                p.icmp_monitor, p.icmp_fail_count, p.icmp_last_at, p.icmp_last_ok,
                p.icmp_last_rtt_ms, p.icmp_last_error,
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
    if (class_exists('CabinetHealthService')) {
        $pdus3d = CabinetHealthService::attachPdus($pdus3d);
    }
} catch (Throwable $e) {
    $pdus3d = [];
}

// Floor-placed cooling / AC units for dashboard 3D (wireframe + snowflake logo)
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

// Floor-placed UPS (Symmetra in-row frames)
$ups3d = [];
try {
    require_once __DIR__ . '/includes/ups_helpers.php';
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
    foreach ($ups3d as &$uu) {
        $uu['health_status'] = ups_health_status($uu);
    }
    unset($uu);
} catch (Throwable $e) {
    $ups3d = [];
}

// Env heat spheres (cabinet + placement → soft ~3 ft influence)
$envSensors3d = [];
$envHeatDiag = ['placeable' => 0, 'with_value' => 0, 'no_cabinet' => 0, 'cabinet_unplaced' => 0];
try {
    if (class_exists('EnvSensor3dData')) {
        $envSensors3d = EnvSensor3dData::forFloor();
        $envHeatDiag = EnvSensor3dData::diagnostics();
    }
} catch (Throwable $e) {
    $envSensors3d = [];
    App::log('Dashboard env 3d: ' . $e->getMessage(), 'warning');
}

$rooms = Database::fetchAll(
    'SELECT r.room_id, r.name, r.width_m, r.depth_m, dc.name AS dc_name
     FROM rooms r
     INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
     WHERE r.is_active = 1
     ORDER BY dc.name, r.name'
);

// Update banner for admins (cached check when auto_check enabled)
$dashUpdate = null;
if (AuthManager::can($user, 'manage_settings')) {
    try {
        require_once __DIR__ . '/src/Services/UpdateService.php';
        $updCfg = UpdateService::config();
        if (!empty($updCfg['enabled']) && !empty($updCfg['auto_check'])) {
            $dashUpdate = UpdateService::checkForUpdate(false);
        } else {
            $dashUpdate = UpdateService::cachedStatus();
        }
    } catch (Throwable $e) {
        $dashUpdate = null;
    }
}

layout_header('Dashboard', $user, 'dashboard');
?>

<?php if ($dashUpdate && !empty($dashUpdate['update_available'])): ?>
<?php
    $dashNotesHref = (string)($dashUpdate['notes_url']
        ?? $dashUpdate['html_url']
        ?? (class_exists('UpdateService')
            ? UpdateService::changelogUrl((string)($dashUpdate['latest'] ?? ''))
            : 'https://github.com/sabap/ColdAisle/blob/main/CHANGELOG.md'));
?>
<div class="alert alert-info" style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
    <div>
        <strong>ColdAisle update available:</strong>
        v<?= App::e((string)$dashUpdate['latest']) ?>
        <span class="text-muted">(running v<?= App::e((string)$dashUpdate['current']) ?>)</span>
        <?php if ($dashNotesHref !== ''): ?>
            · <a href="<?= App::e($dashNotesHref) ?>" target="_blank" rel="noopener">Release notes</a>
        <?php endif; ?>
    </div>
    <a class="btn btn-sm btn-primary" href="<?= App::e(App::url('pages/settings.php#updates')) ?>">Review &amp; update</a>
</div>
<?php elseif ($dashUpdate && empty($dashUpdate['ok']) && !empty($dashUpdate['error']) && AuthManager::can($user, 'manage_settings')): ?>
<div class="alert alert-warning" style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
    <div>
        <strong>Update check:</strong>
        <?= App::e((string)$dashUpdate['error']) ?>
    </div>
    <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/settings.php#updates')) ?>">Update settings</a>
</div>
<?php endif; ?>

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
    <?php
    $upsUnits = (int)($upsDash['units'] ?? 0);
    $upsCardCls = '';
    if ((int)($upsDash['health_crit'] ?? 0) > 0 || (int)($upsDash['on_battery'] ?? 0) > 0) {
        $upsCardCls = 'danger';
    } elseif ((int)($upsDash['health_warn'] ?? 0) > 0) {
        $upsCardCls = 'warning';
    } elseif ($upsUnits > 0) {
        $upsCardCls = 'success';
    }
    ?>
    <div class="metric-card <?= App::e($upsCardCls) ?>">
        <div class="label">UPS</div>
        <div class="value"><?= $upsUnits ?></div>
        <div class="sub">
            <?php if ($upsUnits < 1): ?>
                <a href="<?= App::e(App::url('pages/power_ups.php')) ?>">Add UPS inventory</a>
            <?php else: ?>
                <a href="<?= App::e(App::url('pages/power.php')) ?>">
                    <?= (int)($upsDash['online'] ?? 0) ?> online
                    <?php if ((int)($upsDash['on_battery'] ?? 0) > 0): ?>
                        · <?= (int)$upsDash['on_battery'] ?> on battery
                    <?php elseif ($upsDash['avg_load_pct'] !== null): ?>
                        · load <?= App::e((string)$upsDash['avg_load_pct']) ?>%
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        </div>
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
        <div class="card-header" style="border-top:1px solid var(--border);padding:.5rem 1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
            <label class="text-muted" style="font-size:.85rem;display:flex;align-items:center;gap:.4rem;cursor:pointer;margin:0">
                <input type="checkbox" id="dash3dHeatToggle" checked
                    <?= count($envSensors3d) < 1 ? 'disabled' : '' ?>>
                Temp heat spheres (~3 ft)
            </label>
            <span class="text-muted" style="font-size:.78rem" id="dash3dHeatHint">
                <?php if (count($envSensors3d) > 0): ?>
                    <?= count($envSensors3d) ?> sensor(s) on plan · cabinet + placement · not CFD
                <?php elseif ((int)($envHeatDiag['with_value'] ?? 0) > 0 && (int)($envHeatDiag['cabinet_unplaced'] ?? 0) > 0): ?>
                    <?= (int)$envHeatDiag['cabinet_unplaced'] ?> sensor(s) use a cabinet that is
                    <strong>not on the floor plan</strong> — place that rack under Floor Plan
                    (spheres use each sensor’s Cabinet field, not the MM/TH module rack).
                <?php elseif ((int)($envHeatDiag['with_value'] ?? 0) > 0 && (int)($envHeatDiag['no_cabinet'] ?? 0) > 0): ?>
                    Set <strong>Cabinet</strong> on each sensor (the IT rack where the probe sits).
                <?php elseif ((int)($envHeatDiag['with_value'] ?? 0) < 1): ?>
                    No sensor readings yet — Poll the env manager first.
                <?php else: ?>
                    No placeable heat sensors yet.
                <?php endif; ?>
            </span>
        </div>
        <div class="panel-3d" id="dashboard-3d"
             data-cabinets='<?= App::e(json_encode($cabinets3d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
             data-pdus='<?= App::e(json_encode($pdus3d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
             data-cooling='<?= App::e(json_encode($cooling3d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
             data-ups='<?= App::e(json_encode($ups3d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
             data-rooms='<?= App::e(json_encode($rooms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
             data-env-sensors='<?= App::e(json_encode($envSensors3d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
             data-logo-url='<?= App::e(App::url('assets/img/logo.svg')) ?>'></div>
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

<script>
(function () {
  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Failed to load ' + src)); };
      document.head.appendChild(s);
    });
  }

  function mount3d() {
    var el = document.getElementById('dashboard-3d');
    if (!el) return;
    el.classList.add('dash-3d-loading');
    var threeUrl = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
    var app3d = <?= json_encode(App::url('assets/js/dcim-3d.js') . '?v=13') ?>;
    loadScript(threeUrl)
      .then(function () { return loadScript(app3d); })
      .then(function () {
        if (!window.ColdAisle3D) return;
        var cabinets = JSON.parse(el.dataset.cabinets || '[]');
        var pdus = JSON.parse(el.dataset.pdus || '[]');
        var cooling = JSON.parse(el.dataset.cooling || '[]');
        var ups = JSON.parse(el.dataset.ups || '[]');
        var rooms = JSON.parse(el.dataset.rooms || '[]');
        var envSensors = JSON.parse(el.dataset.envSensors || '[]');
        var logoUrl = el.dataset.logoUrl || '';
        var heatOn = true;
        var tog = document.getElementById('dash3dHeatToggle');
        if (tog) heatOn = !!tog.checked;
        var view = ColdAisle3D.mount(el, {
          cabinets: cabinets,
          pdus: pdus,
          cooling: cooling,
          ups: ups,
          rooms: rooms,
          envSensors: envSensors,
          logoUrl: logoUrl,
          heatOverlay: heatOn,
          interactive: true,
          textureFaces: 'front',
        });
        if (tog && view && typeof view.setHeatOverlay === 'function') {
          tog.addEventListener('change', function () {
            view.setHeatOverlay(!!tog.checked);
          });
        }
        el.classList.remove('dash-3d-loading');
      })
      .catch(function () {
        el.classList.remove('dash-3d-loading');
        el.innerHTML = '<div class="empty-state"><p>3D view could not load (network or script blocked).</p></div>';
      });
  }

  // Paint the rest of the dashboard first; 3D is secondary
  function schedule3d() {
    if (window.requestIdleCallback) {
      requestIdleCallback(function () { mount3d(); }, { timeout: 1200 });
    } else {
      setTimeout(mount3d, 50);
    }
  }
  if (document.readyState === 'complete') schedule3d();
  else window.addEventListener('load', schedule3d);
})();
</script>
<?php layout_footer(); ?>
