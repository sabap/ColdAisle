<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_datacenters');

// Handle form posts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!AuthManager::canEditInfrastructure($user)) {
        App::flash('error', 'You do not have permission to modify data center infrastructure.');
        App::redirect('pages/datacenters.php');
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_site') {
            Database::insert('sites', [
                'name' => trim($_POST['name'] ?? 'Site'),
                'code' => $_POST['code'] ?? null,
                'address' => $_POST['address'] ?? null,
                'city' => $_POST['city'] ?? null,
                'timezone' => $_POST['timezone'] ?? 'UTC',
                'is_active' => 1,
            ]);
            App::flash('success', 'Site created.');
        }
        if ($action === 'add_dc') {
            $north = strtolower(trim((string)($_POST['north_edge'] ?? 'top')));
            if (!in_array($north, ['top', 'right', 'bottom', 'left'], true)) {
                $north = 'top';
            }
            $row = [
                'site_id' => (int)$_POST['site_id'],
                'name' => trim($_POST['name'] ?? 'DC'),
                'code' => $_POST['code'] ?? null,
                'floor_width_m' => (float)($_POST['floor_width_m'] ?? 40),
                'floor_depth_m' => (float)($_POST['floor_depth_m'] ?? 25),
                'max_kw' => $_POST['max_kw'] !== '' ? (float)$_POST['max_kw'] : null,
                'is_active' => 1,
            ];
            // north_edge added by Schema::ensure(); include when available
            try {
                $hasNorth = Database::fetchValue(
                    "SELECT 1 FROM sys.columns c INNER JOIN sys.tables t ON t.object_id = c.object_id
                     WHERE t.name = 'datacenters' AND c.name = 'north_edge'"
                );
                if ($hasNorth) {
                    $row['north_edge'] = $north;
                }
            } catch (Throwable $e) {
                // ignore
            }
            Database::insert('datacenters', $row);
            App::flash('success', 'Data center created.');
        }
        if ($action === 'update_dc_north') {
            $dcId = (int)($_POST['datacenter_id'] ?? 0);
            $north = strtolower(trim((string)($_POST['north_edge'] ?? 'top')));
            if (!in_array($north, ['top', 'right', 'bottom', 'left'], true)) {
                $north = 'top';
            }
            if ($dcId) {
                Database::update('datacenters', ['north_edge' => $north], 'datacenter_id = :id', [':id' => $dcId]);
                App::flash('success', 'Data center North orientation updated.');
            }
        }
        if ($action === 'add_room') {
            Database::insert('rooms', [
                'datacenter_id' => (int)$_POST['datacenter_id'],
                'name' => trim($_POST['name'] ?? 'Room'),
                'code' => $_POST['code'] ?? null,
                'width_m' => (float)($_POST['width_m'] ?? 20),
                'depth_m' => (float)($_POST['depth_m'] ?? 15),
                'floor_level' => $_POST['floor_level'] ?? null,
                'is_active' => 1,
            ]);
            App::flash('success', 'Room created.');
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/datacenters.php');
}

$sites = Database::fetchAll('SELECT * FROM sites WHERE is_active = 1 ORDER BY name');
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
// Normalize north_edge for display
foreach ($dcs as &$dcRow) {
    if (empty($dcRow['north_edge'])) {
        $dcRow['north_edge'] = 'top';
    }
}
unset($dcRow);
$rooms = Database::fetchAll(
    'SELECT r.*, dc.name AS dc_name,
        (SELECT COUNT(*) FROM cabinets c WHERE c.room_id = r.room_id AND c.is_active = 1) AS cab_count
     FROM rooms r
     INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
     WHERE r.is_active = 1 ORDER BY dc.name, r.name'
);

layout_header('Data Centers', $user, 'datacenters');
?>

<div class="split-2">
    <div class="card">
        <div class="card-header"><h2>Sites</h2></div>
        <div class="card-body flush">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Name</th><th>Code</th><th>City</th></tr></thead>
                    <tbody>
                    <?php foreach ($sites as $s): ?>
                        <tr>
                            <td><?= App::e($s['name']) ?></td>
                            <td><?= App::e($s['code']) ?></td>
                            <td><?= App::e($s['city']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-body">
            <h3 class="mt-0">Add Site</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_site">
                <div class="form-row"><label>Name</label><input class="form-control" name="name" required></div>
                <div class="form-row"><label>Code</label><input class="form-control" name="code"></div>
                <div class="form-row"><label>City</label><input class="form-control" name="city"></div>
                <div class="form-row"><label>Timezone</label><input class="form-control" name="timezone" value="UTC"></div>
                <div class="form-row full"><label>Address</label><input class="form-control" name="address"></div>
                <div class="form-row"><button class="btn btn-primary" type="submit">Add Site</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Data Centers</h2></div>
        <div class="card-body flush">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Name</th><th>Site</th><th>Rooms</th><th>Floor (m)</th><th>North is…</th></tr></thead>
                    <tbody>
                    <?php foreach ($dcs as $d): ?>
                        <tr>
                            <td><?= App::e($d['name']) ?></td>
                            <td><?= App::e($d['site_name']) ?></td>
                            <td><?= (int)$d['room_count'] ?></td>
                            <td><?= App::e($d['floor_width_m'] . ' × ' . $d['floor_depth_m']) ?></td>
                            <td>
                                <form method="post" style="display:flex;gap:.35rem;align-items:center;margin:0">
                                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                    <input type="hidden" name="action" value="update_dc_north">
                                    <input type="hidden" name="datacenter_id" value="<?= (int)$d['datacenter_id'] ?>">
                                    <select class="form-control" name="north_edge" style="width:auto;min-width:7rem;padding:.25rem .4rem;font-size:.8rem" onchange="this.form.submit()">
                                        <?php
                                        $ne = strtolower((string)($d['north_edge'] ?? 'top'));
                                        foreach (['top' => 'Top of plan', 'right' => 'Right', 'bottom' => 'Bottom', 'left' => 'Left'] as $val => $lab):
                                        ?>
                                            <option value="<?= $val ?>" <?= $ne === $val ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-body">
            <h3 class="mt-0">Add Data Center</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_dc">
                <div class="form-row">
                    <label>Site</label>
                    <select class="form-control" name="site_id" required>
                        <?php foreach ($sites as $s): ?>
                            <option value="<?= (int)$s['site_id'] ?>"><?= App::e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Name</label><input class="form-control" name="name" required></div>
                <div class="form-row"><label>Code</label><input class="form-control" name="code"></div>
                <div class="form-row"><label>Max kW</label><input class="form-control" name="max_kw" type="number" step="0.1"></div>
                <div class="form-row"><label>Floor Width (m)</label><input class="form-control" name="floor_width_m" type="number" step="0.1" value="40"></div>
                <div class="form-row"><label>Floor Depth (m)</label><input class="form-control" name="floor_depth_m" type="number" step="0.1" value="25"></div>
                <div class="form-row">
                    <label>North is…</label>
                    <select class="form-control" name="north_edge">
                        <option value="top" selected>Top of floor plan</option>
                        <option value="right">Right side of plan</option>
                        <option value="bottom">Bottom of plan</option>
                        <option value="left">Left side of plan</option>
                    </select>
                </div>
                <div class="form-row"><button class="btn btn-primary" type="submit">Add Data Center</button></div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Rooms</h2></div>
    <div class="card-body flush">
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Name</th><th>Data Center</th><th>Size (m)</th><th>Cabinets</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rooms as $r): ?>
                    <tr>
                        <td><?= App::e($r['name']) ?></td>
                        <td><?= App::e($r['dc_name']) ?></td>
                        <td><?= App::e($r['width_m'] . ' × ' . $r['depth_m']) ?></td>
                        <td><?= (int)$r['cab_count'] ?></td>
                        <td class="actions">
                            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/floorplan.php')) ?>">Floor Plan</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-body">
        <h3 class="mt-0">Add Room</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="add_room">
            <div class="form-row">
                <label>Data Center</label>
                <select class="form-control" name="datacenter_id" required>
                    <?php foreach ($dcs as $d): ?>
                        <option value="<?= (int)$d['datacenter_id'] ?>"><?= App::e($d['site_name'] . ' / ' . $d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Name</label><input class="form-control" name="name" required></div>
            <div class="form-row"><label>Code</label><input class="form-control" name="code"></div>
            <div class="form-row"><label>Floor Level</label><input class="form-control" name="floor_level"></div>
            <div class="form-row"><label>Width (m)</label><input class="form-control" name="width_m" type="number" step="0.1" value="20"></div>
            <div class="form-row"><label>Depth (m)</label><input class="form-control" name="depth_m" type="number" step="0.1" value="15"></div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Add Room</button></div>
        </form>
    </div>
</div>
<?php layout_footer(); ?>
