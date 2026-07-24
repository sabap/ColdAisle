<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/timezone_field.php';
App::boot();
$user = App::requirePermission('view_datacenters');
$canEdit = AuthManager::canEditInfrastructure($user);

/**
 * Null-out empty strings for optional NVARCHAR fields.
 */
function dc_null_str(?string $v): ?string
{
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function dc_normalize_north(string $north): string
{
    $north = strtolower(trim($north));
    return in_array($north, ['top', 'right', 'bottom', 'left'], true) ? $north : 'top';
}

// Handle form posts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to modify data center infrastructure.');
        App::redirect('pages/datacenters.php');
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_site' || $action === 'update_site') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Site name is required.');
            }
            $row = [
                'name' => $name,
                'code' => dc_null_str($_POST['code'] ?? null),
                'address' => dc_null_str($_POST['address'] ?? null),
                'city' => dc_null_str($_POST['city'] ?? null),
                'timezone' => coldaisle_normalize_timezone($_POST['timezone'] ?? 'UTC'),
            ];
            if ($action === 'update_site') {
                $id = (int)($_POST['site_id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Site id required.');
                }
                if (array_key_exists('is_active', $_POST)) {
                    $row['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
                }
                Database::update('sites', $row, 'site_id = :id', [':id' => $id]);
                App::flash('success', 'Site updated.');
            } else {
                $row['is_active'] = 1;
                Database::insert('sites', $row);
                App::flash('success', 'Site created.');
            }
        }

        if ($action === 'add_dc' || $action === 'update_dc') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Data center name is required.');
            }
            $siteId = (int)($_POST['site_id'] ?? 0);
            if ($siteId <= 0) {
                throw new RuntimeException('Site is required.');
            }
            $north = dc_normalize_north((string)($_POST['north_edge'] ?? 'top'));
            $row = [
                'site_id' => $siteId,
                'name' => $name,
                'code' => dc_null_str($_POST['code'] ?? null),
                'floor_width_m' => (float)($_POST['floor_width_m'] ?? 40),
                'floor_depth_m' => (float)($_POST['floor_depth_m'] ?? 25),
                'max_kw' => ($_POST['max_kw'] ?? '') !== '' ? (float)$_POST['max_kw'] : null,
            ];
            try {
                $hasNorth = Database::fetchValue(
                    "SELECT 1 FROM sys.columns c INNER JOIN sys.tables t ON t.object_id = c.object_id
                     WHERE t.name = 'datacenters' AND c.name = 'north_edge'"
                );
                if ($hasNorth) {
                    $row['north_edge'] = $north;
                }
            } catch (Throwable $e) {
                // ignore schema probe
            }

            if ($action === 'update_dc') {
                $id = (int)($_POST['datacenter_id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Data center id required.');
                }
                if (array_key_exists('is_active', $_POST)) {
                    $row['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
                }
                Database::update('datacenters', $row, 'datacenter_id = :id', [':id' => $id]);
                App::flash('success', 'Data center updated.');
            } else {
                $row['is_active'] = 1;
                Database::insert('datacenters', $row);
                App::flash('success', 'Data center created.');
            }
        }

        if ($action === 'add_room' || $action === 'update_room') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Room name is required.');
            }
            $dcId = (int)($_POST['datacenter_id'] ?? 0);
            if ($dcId <= 0) {
                throw new RuntimeException('Data center is required.');
            }
            $row = [
                'datacenter_id' => $dcId,
                'name' => $name,
                'code' => dc_null_str($_POST['code'] ?? null),
                'width_m' => (float)($_POST['width_m'] ?? 20),
                'depth_m' => (float)($_POST['depth_m'] ?? 15),
                'floor_level' => dc_null_str($_POST['floor_level'] ?? null),
            ];
            if ($action === 'update_room') {
                $id = (int)($_POST['room_id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Room id required.');
                }
                if (array_key_exists('is_active', $_POST)) {
                    $row['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
                }
                Database::update('rooms', $row, 'room_id = :id', [':id' => $id]);
                App::flash('success', 'Room updated.');
            } else {
                $row['is_active'] = 1;
                Database::insert('rooms', $row);
                App::flash('success', 'Room created.');
            }
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/datacenters.php');
}

$sites = Database::fetchAll('SELECT * FROM sites WHERE is_active = 1 ORDER BY name');
// Include inactive when editing
$allSitesForSelect = Database::fetchAll('SELECT site_id, name, is_active FROM sites ORDER BY name');
try {
    $dcs = Database::fetchAll(
        'SELECT dc.*, s.name AS site_name,
            (SELECT COUNT(*) FROM rooms r WHERE r.datacenter_id = dc.datacenter_id AND r.is_active = 1) AS room_count
         FROM datacenters dc
         INNER JOIN sites s ON s.site_id = dc.site_id
         WHERE dc.is_active = 1 ORDER BY s.name, dc.name'
    );
} catch (Throwable $e) {
    $dcs = [];
}
foreach ($dcs as &$dcRow) {
    if (empty($dcRow['north_edge'])) {
        $dcRow['north_edge'] = 'top';
    }
}
unset($dcRow);
$rooms = Database::fetchAll(
    'SELECT r.*, dc.name AS dc_name, dc.datacenter_id,
        (SELECT COUNT(*) FROM cabinets c WHERE c.room_id = r.room_id AND c.is_active = 1) AS cab_count
     FROM rooms r
     INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
     WHERE r.is_active = 1 ORDER BY dc.name, r.name'
);

$editSiteId = (int)($_GET['edit_site'] ?? 0);
$editDcId = (int)($_GET['edit_dc'] ?? 0);
$editRoomId = (int)($_GET['edit_room'] ?? 0);
$editSite = null;
$editDc = null;
$editRoom = null;

if ($editSiteId > 0) {
    $editSite = Database::fetchOne('SELECT * FROM sites WHERE site_id = ?', [$editSiteId]);
}
if ($editDcId > 0) {
    $editDc = Database::fetchOne('SELECT * FROM datacenters WHERE datacenter_id = ?', [$editDcId]);
    if ($editDc && empty($editDc['north_edge'])) {
        $editDc['north_edge'] = 'top';
    }
}
if ($editRoomId > 0) {
    $editRoom = Database::fetchOne('SELECT * FROM rooms WHERE room_id = ?', [$editRoomId]);
}

$northOptions = [
    'top' => 'Top of floor plan',
    'right' => 'Right side of plan',
    'bottom' => 'Bottom of plan',
    'left' => 'Left side of plan',
];

layout_header('Data Centers', $user, 'datacenters');
?>

<div class="flex-between mb-2">
    <p class="text-muted mb-0">
        Sites, data centers, and rooms that structure floor plans and cabinets.
    </p>
    <div class="flex gap-1" style="flex-wrap:wrap">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/floorplan.php')) ?>">Floor plan</a>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cabinets.php')) ?>">Cabinets</a>
    </div>
</div>

<div class="metrics">
    <div class="metric-card">
        <div class="label">Sites</div>
        <div class="value"><?= count($sites) ?></div>
    </div>
    <div class="metric-card accent">
        <div class="label">Data centers</div>
        <div class="value"><?= count($dcs) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Rooms</div>
        <div class="value"><?= count($rooms) ?></div>
        <div class="sub"><?= array_sum(array_map(static fn($r) => (int)($r['cab_count'] ?? 0), $rooms)) ?> cabinets</div>
    </div>
</div>

<div class="split-2">
    <!-- Sites -->
    <div class="card">
        <div class="card-header flex-between">
            <h2>Sites</h2>
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                <span class="text-muted" style="font-size:.85rem"><?= count($sites) ?></span>
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-add-site">Add site</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body flush">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th>Name</th><th>Code</th><th>City</th><th>Timezone</th>
                        <?php if ($canEdit): ?><th class="col-actions"></th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sites as $s): ?>
                        <tr>
                            <td><strong><?= App::e($s['name']) ?></strong></td>
                            <td><?= App::e($s['code'] ?? '—') ?></td>
                            <td><?= App::e($s['city'] ?? '—') ?></td>
                            <td class="text-muted" style="font-size:.85rem"><?= App::e($s['timezone'] ?? 'UTC') ?></td>
                            <?php if ($canEdit): ?>
                            <td class="actions col-actions">
                                <a class="btn btn-sm btn-secondary" href="?edit_site=<?= (int)$s['site_id'] ?>">Edit</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sites): ?>
                        <tr>
                            <td colspan="<?= $canEdit ? 5 : 4 ?>" class="text-muted">
                                No sites yet.
                                <?php if ($canEdit): ?> Use <strong>Add site</strong> to create one.<?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Data Centers -->
    <div class="card">
        <div class="card-header flex-between">
            <h2>Data Centers</h2>
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                <span class="text-muted" style="font-size:.85rem"><?= count($dcs) ?></span>
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-add-dc"
                        <?= !$sites ? 'disabled title="Add a site first"' : '' ?>>Add data center</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body flush">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th>Name</th><th>Site</th><th>Rooms</th><th>Floor (m)</th><th>North</th>
                        <?php if ($canEdit): ?><th class="col-actions"></th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dcs as $d):
                        $ne = strtolower((string)($d['north_edge'] ?? 'top'));
                        ?>
                        <tr>
                            <td><strong><?= App::e($d['name']) ?></strong>
                                <?php if (!empty($d['code'])): ?>
                                    <span class="text-muted" style="font-size:.78rem"> · <?= App::e((string)$d['code']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= App::e($d['site_name']) ?></td>
                            <td><?= (int)$d['room_count'] ?></td>
                            <td style="font-size:.85rem"><?= App::e((string)$d['floor_width_m']) ?> × <?= App::e((string)$d['floor_depth_m']) ?></td>
                            <td><span class="badge"><?= App::e($northOptions[$ne] ?? $ne) ?></span></td>
                            <?php if ($canEdit): ?>
                            <td class="actions col-actions">
                                <a class="btn btn-sm btn-secondary" href="?edit_dc=<?= (int)$d['datacenter_id'] ?>">Edit</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$dcs): ?>
                        <tr>
                            <td colspan="<?= $canEdit ? 6 : 5 ?>" class="text-muted">
                                No data centers yet.
                                <?php if ($canEdit && $sites): ?> Use <strong>Add data center</strong>.<?php elseif ($canEdit): ?> Add a site first.<?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Rooms -->
<div class="card">
    <div class="card-header flex-between">
        <h2>Rooms</h2>
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
            <span class="text-muted" style="font-size:.85rem"><?= count($rooms) ?></span>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-add-room"
                    <?= !$dcs ? 'disabled title="Add a data center first"' : '' ?>>Add room</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Name</th><th>Data Center</th><th>Size (m)</th><th>Level</th><th>Cabinets</th>
                    <th class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rooms as $r): ?>
                    <tr>
                        <td><strong><?= App::e($r['name']) ?></strong>
                            <?php if (!empty($r['code'])): ?>
                                <span class="text-muted" style="font-size:.78rem"> · <?= App::e((string)$r['code']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= App::e($r['dc_name']) ?></td>
                        <td style="font-size:.85rem"><?= App::e((string)$r['width_m']) ?> × <?= App::e((string)$r['depth_m']) ?></td>
                        <td><?= App::e($r['floor_level'] ?? '—') ?></td>
                        <td><?= (int)$r['cab_count'] ?></td>
                        <td class="actions col-actions" style="white-space:nowrap">
                            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/floorplan.php')) ?>">Floor plan</a>
                            <?php if ($canEdit): ?>
                                <a class="btn btn-sm btn-secondary" href="?edit_room=<?= (int)$r['room_id'] ?>">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rooms): ?>
                    <tr>
                        <td colspan="6" class="text-muted">
                            No rooms yet.
                            <?php if ($canEdit && $dcs): ?> Use <strong>Add room</strong>.<?php elseif ($canEdit): ?> Add a data center first.<?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canEdit): ?>
<!-- Add site -->
<div class="app-modal" id="modal-add-site" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-add-site-title">
        <div class="app-modal-head">
            <h3 id="modal-add-site-title">Add site</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_site">
                <div class="form-row"><label>Name *</label>
                    <input class="form-control" name="name" required placeholder="Main campus"></div>
                <div class="form-row"><label>Code</label>
                    <input class="form-control" name="code" placeholder="HQ"></div>
                <div class="form-row"><label>City</label>
                    <input class="form-control" name="city"></div>
                <?php
                coldaisle_render_timezone_field([
                    'name' => 'timezone',
                    'value' => 'UTC',
                    'full' => true,
                ]);
                ?>
                <div class="form-row full"><label>Address</label>
                    <input class="form-control" name="address"></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Add site</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add data center -->
<div class="app-modal" id="modal-add-dc" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel app-modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="modal-add-dc-title">
        <div class="app-modal-head">
            <h3 id="modal-add-dc-title">Add data center</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_dc">
                <div class="form-row"><label>Site *</label>
                    <select class="form-control" name="site_id" required>
                        <?php foreach ($sites as $s): ?>
                            <option value="<?= (int)$s['site_id'] ?>"><?= App::e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Name *</label>
                    <input class="form-control" name="name" required placeholder="DC-1"></div>
                <div class="form-row"><label>Code</label>
                    <input class="form-control" name="code"></div>
                <div class="form-row"><label>Max kW</label>
                    <input class="form-control" name="max_kw" type="number" step="0.1"></div>
                <div class="form-row"><label>Floor width (m)</label>
                    <input class="form-control" name="floor_width_m" type="number" step="0.1" value="40"></div>
                <div class="form-row"><label>Floor depth (m)</label>
                    <input class="form-control" name="floor_depth_m" type="number" step="0.1" value="25"></div>
                <div class="form-row full"><label>North is…</label>
                    <select class="form-control" name="north_edge">
                        <?php foreach ($northOptions as $val => $lab): ?>
                            <option value="<?= $val ?>" <?= $val === 'top' ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:.75rem;margin:.3rem 0 0">
                        Which edge of the 2D floor plan drawing faces geographic north.
                    </p>
                </div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Add data center</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add room -->
<div class="app-modal" id="modal-add-room" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-add-room-title">
        <div class="app-modal-head">
            <h3 id="modal-add-room-title">Add room</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_room">
                <div class="form-row full"><label>Data center *</label>
                    <select class="form-control" name="datacenter_id" required>
                        <?php foreach ($dcs as $d): ?>
                            <option value="<?= (int)$d['datacenter_id'] ?>">
                                <?= App::e($d['site_name'] . ' / ' . $d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Name *</label>
                    <input class="form-control" name="name" required placeholder="Hall A"></div>
                <div class="form-row"><label>Code</label>
                    <input class="form-control" name="code"></div>
                <div class="form-row"><label>Floor level</label>
                    <input class="form-control" name="floor_level" placeholder="1 / Basement / Mezz"></div>
                <div class="form-row"><label>Width (m)</label>
                    <input class="form-control" name="width_m" type="number" step="0.1" value="20"></div>
                <div class="form-row"><label>Depth (m)</label>
                    <input class="form-control" name="depth_m" type="number" step="0.1" value="15"></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Add room</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editSite): ?>
<div class="app-modal" id="modal-edit-site" aria-hidden="false">
    <div class="app-modal-backdrop" data-modal-close-nav></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-edit-site-title">
        <div class="app-modal-head">
            <h3 id="modal-edit-site-title">Edit site</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/datacenters.php')) ?>" aria-label="Close">✕</a>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="update_site">
                <input type="hidden" name="site_id" value="<?= (int)$editSite['site_id'] ?>">
                <div class="form-row"><label>Name *</label>
                    <input class="form-control" name="name" required value="<?= App::e($editSite['name'] ?? '') ?>"></div>
                <div class="form-row"><label>Code</label>
                    <input class="form-control" name="code" value="<?= App::e($editSite['code'] ?? '') ?>"></div>
                <div class="form-row"><label>City</label>
                    <input class="form-control" name="city" value="<?= App::e($editSite['city'] ?? '') ?>"></div>
                <?php
                coldaisle_render_timezone_field([
                    'name' => 'timezone',
                    'value' => (string)($editSite['timezone'] ?? 'UTC'),
                    'full' => true,
                ]);
                ?>
                <div class="form-row full"><label>Address</label>
                    <input class="form-control" name="address" value="<?= App::e($editSite['address'] ?? '') ?>"></div>
                <div class="form-row full"><label>
                    <input type="checkbox" name="is_active" value="1" <?= !empty($editSite['is_active']) ? 'checked' : '' ?>> Active
                </label></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Save site</button>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/datacenters.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($editDc): ?>
<div class="app-modal" id="modal-edit-dc" aria-hidden="false">
    <div class="app-modal-backdrop" data-modal-close-nav></div>
    <div class="app-modal-panel app-modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="modal-edit-dc-title">
        <div class="app-modal-head">
            <h3 id="modal-edit-dc-title">Edit data center</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/datacenters.php')) ?>" aria-label="Close">✕</a>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="update_dc">
                <input type="hidden" name="datacenter_id" value="<?= (int)$editDc['datacenter_id'] ?>">
                <div class="form-row"><label>Site *</label>
                    <select class="form-control" name="site_id" required>
                        <?php foreach ($allSitesForSelect as $s): ?>
                            <option value="<?= (int)$s['site_id'] ?>"
                                <?= (int)$editDc['site_id'] === (int)$s['site_id'] ? 'selected' : '' ?>>
                                <?= App::e($s['name']) ?><?= empty($s['is_active']) ? ' (inactive)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Name *</label>
                    <input class="form-control" name="name" required value="<?= App::e($editDc['name'] ?? '') ?>"></div>
                <div class="form-row"><label>Code</label>
                    <input class="form-control" name="code" value="<?= App::e($editDc['code'] ?? '') ?>"></div>
                <div class="form-row"><label>Max kW</label>
                    <input class="form-control" name="max_kw" type="number" step="0.1"
                           value="<?= App::e((string)($editDc['max_kw'] ?? '')) ?>"></div>
                <div class="form-row"><label>Floor width (m)</label>
                    <input class="form-control" name="floor_width_m" type="number" step="0.1"
                           value="<?= App::e((string)($editDc['floor_width_m'] ?? '40')) ?>"></div>
                <div class="form-row"><label>Floor depth (m)</label>
                    <input class="form-control" name="floor_depth_m" type="number" step="0.1"
                           value="<?= App::e((string)($editDc['floor_depth_m'] ?? '25')) ?>"></div>
                <div class="form-row full"><label>North is…</label>
                    <select class="form-control" name="north_edge">
                        <?php
                        $ne = strtolower((string)($editDc['north_edge'] ?? 'top'));
                        foreach ($northOptions as $val => $lab): ?>
                            <option value="<?= $val ?>" <?= $ne === $val ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row full"><label>
                    <input type="checkbox" name="is_active" value="1" <?= !empty($editDc['is_active']) ? 'checked' : '' ?>> Active
                </label></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Save data center</button>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/datacenters.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($editRoom): ?>
<div class="app-modal" id="modal-edit-room" aria-hidden="false">
    <div class="app-modal-backdrop" data-modal-close-nav></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-edit-room-title">
        <div class="app-modal-head">
            <h3 id="modal-edit-room-title">Edit room</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/datacenters.php')) ?>" aria-label="Close">✕</a>
        </div>
        <div class="app-modal-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="update_room">
                <input type="hidden" name="room_id" value="<?= (int)$editRoom['room_id'] ?>">
                <div class="form-row full"><label>Data center *</label>
                    <select class="form-control" name="datacenter_id" required>
                        <?php
                        // Include all active DCs; ensure current DC is listed even if inactive
                        $dcOptions = $dcs;
                        $have = false;
                        foreach ($dcOptions as $d) {
                            if ((int)$d['datacenter_id'] === (int)$editRoom['datacenter_id']) {
                                $have = true;
                                break;
                            }
                        }
                        if (!$have) {
                            $cur = Database::fetchOne(
                                'SELECT dc.datacenter_id, dc.name, s.name AS site_name
                                 FROM datacenters dc
                                 INNER JOIN sites s ON s.site_id = dc.site_id
                                 WHERE dc.datacenter_id = ?',
                                [(int)$editRoom['datacenter_id']]
                            );
                            if ($cur) {
                                $dcOptions[] = $cur;
                            }
                        }
                        foreach ($dcOptions as $d): ?>
                            <option value="<?= (int)$d['datacenter_id'] ?>"
                                <?= (int)$editRoom['datacenter_id'] === (int)$d['datacenter_id'] ? 'selected' : '' ?>>
                                <?= App::e(($d['site_name'] ?? '') . ' / ' . $d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Name *</label>
                    <input class="form-control" name="name" required value="<?= App::e($editRoom['name'] ?? '') ?>"></div>
                <div class="form-row"><label>Code</label>
                    <input class="form-control" name="code" value="<?= App::e($editRoom['code'] ?? '') ?>"></div>
                <div class="form-row"><label>Floor level</label>
                    <input class="form-control" name="floor_level" value="<?= App::e($editRoom['floor_level'] ?? '') ?>"></div>
                <div class="form-row"><label>Width (m)</label>
                    <input class="form-control" name="width_m" type="number" step="0.1"
                           value="<?= App::e((string)($editRoom['width_m'] ?? '20')) ?>"></div>
                <div class="form-row"><label>Depth (m)</label>
                    <input class="form-control" name="depth_m" type="number" step="0.1"
                           value="<?= App::e((string)($editRoom['depth_m'] ?? '15')) ?>"></div>
                <div class="form-row full"><label>
                    <input type="checkbox" name="is_active" value="1" <?= !empty($editRoom['is_active']) ? 'checked' : '' ?>> Active
                </label></div>
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Save room</button>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/datacenters.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var listUrl = <?= json_encode(App::url('pages/datacenters.php'), JSON_UNESCAPED_SLASHES) ?>;

    function openModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.hidden = false;
        el.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        var focus = el.querySelector('input:not([type=hidden]), select, textarea, button');
        if (focus) setTimeout(function () { focus.focus(); }, 50);
    }
    function closeModal(el) {
        if (!el) return;
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.app-modal:not([hidden])')) {
            document.body.style.overflow = '';
        }
    }

    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            openModal(btn.getAttribute('data-open-modal'));
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.closest('.app-modal'));
        });
    });
    document.querySelectorAll('[data-modal-close-nav]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.location.href = listUrl;
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('.app-modal:not([hidden])');
        if (!open) return;
        if (open.id === 'modal-edit-site' || open.id === 'modal-edit-dc' || open.id === 'modal-edit-room') {
            window.location.href = listUrl;
        } else {
            closeModal(open);
        }
    });

    if (document.getElementById('modal-edit-site')
        || document.getElementById('modal-edit-dc')
        || document.getElementById('modal-edit-room')) {
        document.body.style.overflow = 'hidden';
    }
})();
</script>
<?php endif; ?>

<?php layout_footer(); ?>
