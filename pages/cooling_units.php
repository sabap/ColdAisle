<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/cooling_helpers.php';
App::boot();
$user = App::requirePermission('view_cooling');
$canEdit = AuthManager::canEditCooling($user);

$unitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$types = cooling_unit_types();
$roles = cooling_unit_roles();
$media = cooling_media();
$statuses = cooling_unit_statuses();
$ashrae = cooling_ashrae_classes();

$rooms = [];
try {
    $rooms = Database::fetchAll(
        'SELECT r.room_id, r.name, dc.name AS dc_name
         FROM rooms r
         INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
         WHERE r.is_active = 1
         ORDER BY dc.name, r.name'
    );
} catch (Throwable $e) {
    $rooms = Database::fetchAll(
        'SELECT r.room_id, r.name, dc.name AS dc_name
         FROM rooms r
         INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
         ORDER BY dc.name, r.name'
    );
}

$peerUnits = [];
try {
    $peerUnits = Database::fetchAll(
        'SELECT cooling_unit_id, name, unit_role FROM cooling_units WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $peerUnits = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to modify cooling units.');
        App::redirect('pages/cooling_units.php');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add_unit' || $action === 'update_unit') {
            $row = cooling_unit_fields_from_post($_POST);
            if ($row['name'] === '') {
                throw new RuntimeException('Name is required.');
            }
            // Prevent self-standby
            if ($action === 'update_unit') {
                $uid = (int)($_POST['cooling_unit_id'] ?? 0);
                if ($uid > 0 && $row['standby_of_id'] === $uid) {
                    $row['standby_of_id'] = null;
                }
            }
            if ($action === 'update_unit') {
                $uid = (int)($_POST['cooling_unit_id'] ?? 0);
                if ($uid <= 0) {
                    throw new RuntimeException('Unit id required.');
                }
                $row['updated_at'] = date('Y-m-d H:i:s');
                Database::update('cooling_units', $row, 'cooling_unit_id = :id', [':id' => $uid]);
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'update', 'cooling_unit', $uid, [
                    'name' => $row['name'],
                ]);
                App::flash('success', 'Cooling unit updated.');
                App::redirect('pages/cooling_units.php?id=' . $uid);
            }
            $id = Database::insert('cooling_units', $row);
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'create', 'cooling_unit', (int)$id, [
                'name' => $row['name'],
            ]);
            App::flash('success', 'Cooling unit created.');
            App::redirect('pages/cooling_units.php?id=' . (int)$id);
        }
        if ($action === 'deactivate_unit') {
            $uid = (int)($_POST['cooling_unit_id'] ?? 0);
            if ($uid <= 0) {
                throw new RuntimeException('Unit id required.');
            }
            Database::update('cooling_units', [
                'is_active' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'cooling_unit_id = :id', [':id' => $uid]);
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'delete', 'cooling_unit', $uid, []);
            App::flash('success', 'Cooling unit deactivated.');
            App::redirect('pages/cooling_units.php');
        }
    } catch (Throwable $e) {
        App::log('cooling_units POST: ' . $e->getMessage(), 'error');
        App::flash('error', $e->getMessage());
        $redirectId = (int)($_POST['cooling_unit_id'] ?? 0);
        App::redirect('pages/cooling_units.php' . ($redirectId ? '?id=' . $redirectId : ''));
    }
}

// Detail view
if ($unitId > 0) {
    $u = Database::fetchOne(
        'SELECT u.*,
                rm.name AS room_name, dc.name AS dc_name,
                primary_u.name AS primary_unit_name
         FROM cooling_units u
         LEFT JOIN rooms rm ON rm.room_id = u.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         LEFT JOIN cooling_units primary_u ON primary_u.cooling_unit_id = u.standby_of_id
         WHERE u.cooling_unit_id = ?',
        [$unitId]
    );
    if (!$u || empty($u['is_active'])) {
        App::flash('error', 'Cooling unit not found.');
        App::redirect('pages/cooling_units.php');
    }
    $linkedSensors = [];
    try {
        $linkedSensors = Database::fetchAll(
            'SELECT sensor_id, name, sensor_kind, last_value, unit, last_seen_at
             FROM env_sensors WHERE cooling_unit_id = ? AND is_active = 1 ORDER BY name',
            [$unitId]
        );
    } catch (Throwable $e) {
        $linkedSensors = [];
    }
    $env = cooling_ashrae_envelope((string)($u['ashrae_class'] ?? 'recommended'));
    $placed = $u['pos_x'] !== null && $u['pos_y'] !== null && $u['room_id'];

    layout_header('Cooling unit: ' . $u['name'], $user, 'cooling_units');
    ?>
    <div class="flex-between mb-2">
        <div>
            <a class="text-muted" href="<?= App::e(App::url('pages/cooling_units.php')) ?>">← All units</a>
            <h2 class="mt-1 mb-0"><?= App::e($u['name']) ?></h2>
            <p class="text-muted mb-0" style="font-size:.9rem">
                <?= App::e($types[$u['unit_type'] ?? ''] ?? ($u['unit_type'] ?? '')) ?>
                · <?= App::e($roles[$u['unit_role'] ?? ''] ?? '') ?>
                · <?= App::e($media[$u['cooling_medium'] ?? ''] ?? '') ?>
            </p>
        </div>
        <div class="flex gap-1">
            <?php if ($placed && $u['room_id']): ?>
                <a class="btn btn-secondary" href="<?= App::e(App::url('pages/floorplan.php?room_id=' . (int)$u['room_id'])) ?>">Floor plan</a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cooling.php')) ?>">Dashboard</a>
        </div>
    </div>

    <div class="metrics power-metrics mb-2">
        <div class="metric-card">
            <div class="label">Status</div>
            <div class="value" style="font-size:1.25rem"><?= App::e($statuses[$u['status'] ?? ''] ?? ($u['status'] ?? '—')) ?></div>
            <div class="sub"><?= App::e((string)($u['room_name'] ?? 'No room')) ?></div>
        </div>
        <div class="metric-card accent">
            <div class="label">Rated cooling</div>
            <div class="value">
                <?php if ($u['rated_kw_cooling'] !== null && $u['rated_kw_cooling'] !== ''): ?>
                    <?= number_format((float)$u['rated_kw_cooling'], 1) ?> <span class="metric-unit">kW</span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <div class="sub">
                <?php if ($u['rated_tons'] !== null && $u['rated_tons'] !== ''): ?>
                    <?= number_format((float)$u['rated_tons'], 1) ?> tons
                <?php else: ?>
                    optional tons / CFM on form
                <?php endif; ?>
            </div>
        </div>
        <div class="metric-card">
            <div class="label">Network</div>
            <div class="value" style="font-size:1.1rem"><?= App::e((string)($u['primary_ip'] ?? '—')) ?></div>
            <div class="sub"><?= !empty($u['snmp_enabled']) ? 'SNMP on' : 'SNMP off' ?></div>
        </div>
        <div class="metric-card success">
            <div class="label">ASHRAE class</div>
            <div class="value" style="font-size:1.1rem"><?= App::e($env['label']) ?></div>
            <div class="sub"><?= App::e($env['notes']) ?></div>
        </div>
    </div>

    <?php if ($canEdit): ?>
    <div class="card mb-2">
        <div class="card-header"><h3 class="mt-0 mb-0" style="font-size:1rem">Edit unit</h3></div>
        <div class="card-body">
            <?php
            $edit = $u;
            $formAction = 'update_unit';
            require __DIR__ . '/_cooling_unit_form.php';
            ?>
            <form method="post" class="mt-2" onsubmit="return confirm('Deactivate this cooling unit?');">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="deactivate_unit">
                <input type="hidden" name="cooling_unit_id" value="<?= (int)$unitId ?>">
                <button type="submit" class="btn btn-danger btn-sm">Deactivate unit</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="card mb-2">
        <div class="card-body">
            <dl class="detail-grid">
                <dt>Manufacturer</dt><dd><?= App::e((string)($u['manufacturer'] ?? '—')) ?></dd>
                <dt>Model</dt><dd><?= App::e((string)($u['model'] ?? '—')) ?></dd>
                <dt>Serial</dt><dd><?= App::e((string)($u['serial_no'] ?? '—')) ?></dd>
                <dt>Asset tag</dt><dd><?= App::e((string)($u['asset_tag'] ?? '—')) ?></dd>
                <dt>Warranty</dt>
                <dd>
                    <?= App::e((string)($u['warranty_provider'] ?? '—')) ?>
                    <?php if (!empty($u['warranty_end'])): ?>
                        · ends <?= App::e((string)$u['warranty_end']) ?>
                    <?php endif; ?>
                </dd>
                <dt>Standby of</dt><dd><?= App::e((string)($u['primary_unit_name'] ?? '—')) ?></dd>
                <dt>Notes</dt><dd><?= nl2br(App::e((string)($u['notes'] ?? '—'))) ?></dd>
            </dl>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($linkedSensors): ?>
    <div class="card">
        <div class="card-header flex-between">
            <h3 class="mt-0 mb-0" style="font-size:1rem">Linked sensors</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/env_sensors.php?cooling_unit_id=' . $unitId)) ?>">Manage</a>
        </div>
        <div class="card-body" style="padding:0">
            <table class="table table-sm">
                <thead><tr><th>Name</th><th>Kind</th><th>Last</th></tr></thead>
                <tbody>
                <?php foreach ($linkedSensors as $s): ?>
                    <tr>
                        <td><a href="<?= App::e(App::url('pages/env_sensors.php?id=' . (int)$s['sensor_id'])) ?>"><?= App::e($s['name']) ?></a></td>
                        <td><?= App::e(env_sensor_kinds()[$s['sensor_kind'] ?? ''] ?? ($s['sensor_kind'] ?? '')) ?></td>
                        <td>
                            <?php if ($s['last_value'] !== null && $s['last_value'] !== ''): ?>
                                <?= App::e((string)$s['last_value']) ?> <?= App::e((string)($s['unit'] ?? '')) ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php
    layout_footer();
    return;
}

// List view
$units = [];
try {
    $units = Database::fetchAll(
        'SELECT u.*, rm.name AS room_name, dc.name AS dc_name,
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
    App::flash('error', 'Cooling tables not ready — open Settings → Schema health and run Ensure schema.');
}

layout_header('Air units & pumps', $user, 'cooling_units');
?>

<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0" style="font-size:.92rem">
            CRAC/CRAH, in-row, chillers, chilled-water and AC pumps. Mark primary/standby pairs without defining cooling zones.
            Place units on the floor plan from the palette (like row PDUs).
        </p>
    </div>
    <?php if ($canEdit): ?>
        <button type="button" class="btn btn-primary" data-modal-open="addCoolingUnit">Add unit</button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (!$units): ?>
            <p class="text-muted p-2 mb-0">No active cooling units. Add your active/standby pair to get started.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Role</th>
                        <th>Medium</th>
                        <th>Room</th>
                        <th>IP</th>
                        <th>kW</th>
                        <th>Status</th>
                        <th>Floor</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($units as $u):
                        $onFloor = $u['pos_x'] !== null && $u['pos_y'] !== null;
                        ?>
                        <tr>
                            <td>
                                <a href="<?= App::e(App::url('pages/cooling_units.php?id=' . (int)$u['cooling_unit_id'])) ?>">
                                    <strong><?= App::e($u['name']) ?></strong>
                                </a>
                                <?php if (!empty($u['manufacturer']) || !empty($u['model'])): ?>
                                    <div class="text-muted" style="font-size:.78rem">
                                        <?= App::e(trim(($u['manufacturer'] ?? '') . ' ' . ($u['model'] ?? ''))) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= App::e($types[$u['unit_type'] ?? ''] ?? ($u['unit_type'] ?? '—')) ?></td>
                            <td>
                                <?= App::e($roles[$u['unit_role'] ?? ''] ?? '—') ?>
                                <?php if (!empty($u['primary_unit_name'])): ?>
                                    <div class="text-muted" style="font-size:.75rem">→ <?= App::e($u['primary_unit_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= App::e($media[$u['cooling_medium'] ?? ''] ?? '—') ?></td>
                            <td class="text-muted" style="font-size:.85rem">
                                <?php
                                $loc = trim(($u['dc_name'] ?? '') . ' / ' . ($u['room_name'] ?? ''), ' /');
                                echo App::e($loc !== '' ? $loc : '—');
                                ?>
                            </td>
                            <td><?= App::e((string)($u['primary_ip'] ?? '—')) ?></td>
                            <td>
                                <?= $u['rated_kw_cooling'] !== null && $u['rated_kw_cooling'] !== ''
                                    ? number_format((float)$u['rated_kw_cooling'], 1)
                                    : '—' ?>
                            </td>
                            <td><span class="badge"><?= App::e((string)($u['status'] ?? '—')) ?></span></td>
                            <td><?= $onFloor ? '✓' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="modal-overlay" id="addCoolingUnit" hidden>
    <div class="modal-panel modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="addCoolingUnitTitle">
        <div class="modal-header">
            <h2 id="addCoolingUnitTitle">Add cooling unit</h2>
            <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <?php
            $edit = [];
            $formAction = 'add_unit';
            require __DIR__ . '/_cooling_unit_form.php';
            ?>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var overlay = document.getElementById('addCoolingUnit');
  if (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay && window.ColdAisle && ColdAisle.closeModal) {
        ColdAisle.closeModal(overlay);
      }
    });
  }
});
</script>
<?php endif; ?>

<?php layout_footer(); ?>
