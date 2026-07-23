<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_cables');

$ports = Database::fetchAll(
    'SELECT p.port_id, p.label, p.port_type, p.port_number, d.label AS device_label
     FROM device_ports p
     INNER JOIN devices d ON d.device_id = p.device_id
     WHERE d.is_active = 1
     ORDER BY d.label, p.port_type, p.port_number'
);
$paths = Database::fetchAll('SELECT * FROM cable_paths ORDER BY name');
$rooms = Database::fetchAll('SELECT room_id, name FROM rooms WHERE is_active = 1 ORDER BY name');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_cable') {
            Database::insert('cables', [
                'cable_label' => $_POST['cable_label'] ?: null,
                'media_type' => $_POST['media_type'] ?: null,
                'length_m' => $_POST['length_m'] !== '' ? (float)$_POST['length_m'] : null,
                'color' => $_POST['color'] ?: null,
                'a_port_id' => $_POST['a_port_id'] !== '' ? (int)$_POST['a_port_id'] : null,
                'b_port_id' => $_POST['b_port_id'] !== '' ? (int)$_POST['b_port_id'] : null,
                'path_id' => $_POST['path_id'] !== '' ? (int)$_POST['path_id'] : null,
                'status' => 'active',
                'notes' => $_POST['notes'] ?: null,
                'installed_at' => date('Y-m-d H:i:s'),
            ]);
            App::flash('success', 'Cable connection recorded.');
        }
        if ($action === 'add_path') {
            Database::insert('cable_paths', [
                'room_id' => $_POST['room_id'] !== '' ? (int)$_POST['room_id'] : null,
                'name' => trim($_POST['name']),
                'path_type' => $_POST['path_type'] ?? 'overhead',
                'color_hex' => $_POST['color_hex'] ?? '#38bdf8',
                'notes' => $_POST['notes'] ?? null,
            ]);
            App::flash('success', 'Cable path created.');
        }
        if ($action === 'delete_cable') {
            Database::delete('cables', 'cable_id = ?', [(int)$_POST['cable_id']]);
            App::flash('success', 'Cable removed.');
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/cables.php');
}

$cables = Database::fetchAll(
    'SELECT c.*,
        pa.label AS a_label, da.label AS a_device,
        pb.label AS b_label, db.label AS b_device,
        cp.name AS path_name
     FROM cables c
     LEFT JOIN device_ports pa ON pa.port_id = c.a_port_id
     LEFT JOIN devices da ON da.device_id = pa.device_id
     LEFT JOIN device_ports pb ON pb.port_id = c.b_port_id
     LEFT JOIN devices db ON db.device_id = pb.device_id
     LEFT JOIN cable_paths cp ON cp.path_id = c.path_id
     ORDER BY c.cable_id DESC'
);

layout_header('Cable Management', $user, 'cables');
?>

<div class="split-2">
    <div class="card">
        <div class="card-header"><h2>Connections</h2></div>
        <div class="card-body flush">
            <table class="data">
                <thead>
                    <tr><th>Label</th><th>A End</th><th>B End</th><th>Media</th><th>Path</th><th>Length</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($cables as $c): ?>
                    <tr>
                        <td><?= App::e($c['cable_label'] ?? '#' . $c['cable_id']) ?></td>
                        <td><?= App::e(($c['a_device'] ?? '?') . ' / ' . ($c['a_label'] ?? '—')) ?></td>
                        <td><?= App::e(($c['b_device'] ?? '?') . ' / ' . ($c['b_label'] ?? '—')) ?></td>
                        <td><?= App::e($c['media_type'] ?? '—') ?></td>
                        <td><?= App::e($c['path_name'] ?? '—') ?></td>
                        <td><?= $c['length_m'] !== null ? App::e($c['length_m'] . ' m') : '—' ?></td>
                        <td>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete cable?')">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_cable">
                                <input type="hidden" name="cable_id" value="<?= (int)$c['cable_id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">×</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$cables): ?><tr><td colspan="7" class="text-muted">No cables recorded.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body">
            <h3 class="mt-0">Add Connection</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_cable">
                <div class="form-row"><label>Cable Label</label><input class="form-control" name="cable_label"></div>
                <div class="form-row"><label>Media Type</label>
                    <input class="form-control" name="media_type" placeholder="Cat6, OM4, DAC, Power..."></div>
                <div class="form-row full"><label>Port A</label>
                    <select class="form-control" name="a_port_id">
                        <option value="">—</option>
                        <?php foreach ($ports as $p): ?>
                            <option value="<?= (int)$p['port_id'] ?>">
                                <?= App::e($p['device_label'] . ' · ' . $p['port_type'] . ' · ' . ($p['label'] ?: '#' . $p['port_number'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row full"><label>Port B</label>
                    <select class="form-control" name="b_port_id">
                        <option value="">—</option>
                        <?php foreach ($ports as $p): ?>
                            <option value="<?= (int)$p['port_id'] ?>">
                                <?= App::e($p['device_label'] . ' · ' . $p['port_type'] . ' · ' . ($p['label'] ?: '#' . $p['port_number'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Route Path</label>
                    <select class="form-control" name="path_id">
                        <option value="">—</option>
                        <?php foreach ($paths as $path): ?>
                            <option value="<?= (int)$path['path_id'] ?>"><?= App::e($path['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Length (m)</label><input class="form-control" type="number" step="0.1" name="length_m"></div>
                <div class="form-row"><label>Color</label><input class="form-control" name="color" placeholder="blue, orange..."></div>
                <div class="form-row full"><label>Notes</label><input class="form-control" name="notes"></div>
                <div class="form-row"><button class="btn btn-primary" type="submit">Add Cable</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Cable Management Routes</h2></div>
        <div class="card-body flush">
            <table class="data">
                <thead><tr><th>Name</th><th>Type</th><th>Color</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($paths as $path): ?>
                    <tr>
                        <td><?= App::e($path['name']) ?></td>
                        <td><span class="badge"><?= App::e($path['path_type']) ?></span></td>
                        <td><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= App::e($path['color_hex']) ?>"></span></td>
                        <td><?= App::e($path['notes'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$paths): ?><tr><td colspan="4" class="text-muted">No routes defined.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body">
            <h3 class="mt-0">Add Route</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_path">
                <div class="form-row"><label>Name</label><input class="form-control" name="name" required placeholder="Tray A-North"></div>
                <div class="form-row"><label>Type</label>
                    <select class="form-control" name="path_type">
                        <option value="overhead">Overhead</option>
                        <option value="underfloor">Underfloor</option>
                        <option value="tray">Tray</option>
                        <option value="conduit">Conduit</option>
                    </select>
                </div>
                <div class="form-row"><label>Room</label>
                    <select class="form-control" name="room_id">
                        <option value="">—</option>
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?= (int)$r['room_id'] ?>"><?= App::e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Color</label><input class="form-control" type="color" name="color_hex" value="#38bdf8"></div>
                <div class="form-row full"><label>Notes</label><input class="form-control" name="notes"></div>
                <div class="form-row"><button class="btn btn-primary" type="submit">Add Path</button></div>
            </form>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
