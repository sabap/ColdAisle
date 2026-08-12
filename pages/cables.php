<?php
/**
 * ColdAisle — Cable plant: port-to-port links + raceway / pathway catalog.
 * Business logic for paths: CablePlantService (shared with floorplan).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_cables');
$canEdit = AuthManager::can($user, 'edit_cables')
    || AuthManager::can($user, 'edit_infrastructure')
    || AuthManager::isAdmin($user);

$ports = Database::fetchAll(
    'SELECT p.port_id, p.label, p.port_type, p.port_number, p.speed AS port_speed, d.label AS device_label
     FROM device_ports p
     INNER JOIN devices d ON d.device_id = p.device_id
     WHERE d.is_active = 1
     ORDER BY d.label, p.port_type, p.port_number'
);
$rooms = Database::fetchAll('SELECT room_id, name FROM rooms WHERE is_active = 1 ORDER BY name');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to edit cabling.');
        App::redirect('pages/cables.php');
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_cable') {
            $fields = CablePlantService::cableFieldsFromInput($_POST);
            $fields['installed_at'] = date('Y-m-d H:i:s');
            Database::insert('cables', $fields);
            App::flash('success', 'Cable connection recorded.');
        }
        if ($action === 'add_path') {
            if (trim((string)($_POST['name'] ?? '')) === '' && trim((string)($_POST['path_code'] ?? '')) !== '') {
                $_POST['name'] = trim((string)$_POST['path_code']);
            }
            $res = CablePlantService::savePath($_POST, null);
            if (empty($res['ok'])) {
                throw new RuntimeException($res['message'] ?? 'Could not save path.');
            }
            App::flash('success', $res['message']);
        }
        if ($action === 'update_path') {
            $pid = (int)($_POST['path_id'] ?? 0);
            $res = CablePlantService::savePath($_POST, $pid > 0 ? $pid : null);
            if (empty($res['ok'])) {
                throw new RuntimeException($res['message'] ?? 'Could not update path.');
            }
            App::flash('success', $res['message']);
        }
        if ($action === 'delete_cable') {
            Database::delete('cables', 'cable_id = ?', [(int)$_POST['cable_id']]);
            App::flash('success', 'Cable removed.');
        }
        if ($action === 'delete_path') {
            $pid = (int)($_POST['path_id'] ?? 0);
            $inUse = (int)Database::fetchValue('SELECT COUNT(*) FROM cables WHERE path_id = ?', [$pid]);
            if ($inUse > 0) {
                throw new RuntimeException("Path is used by {$inUse} cable(s). Reassign them first.");
            }
            Database::delete('cable_paths', 'path_id = ?', [$pid]);
            App::flash('success', 'Path removed.');
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/cables.php');
}

try {
    $paths = Database::fetchAll('SELECT * FROM cable_paths ORDER BY name');
} catch (Throwable $e) {
    $paths = [];
}
foreach ($paths as &$p) {
    $p['waypoints_list'] = CablePlantService::parseWaypoints($p['waypoints'] ?? null);
    $p['point_count'] = count($p['waypoints_list']);
}
unset($p);

$cables = Database::fetchAll(
    'SELECT c.*,
        pa.label AS a_label, da.label AS a_device, da.device_id AS a_device_id,
        pb.label AS b_label, db.label AS b_device, db.device_id AS b_device_id,
        cp.name AS path_name, cp.color_hex AS path_color, cp.media_class AS path_media,
        cp.feed_to AS path_feed, cp.path_kind AS path_kind
     FROM cables c
     LEFT JOIN device_ports pa ON pa.port_id = c.a_port_id
     LEFT JOIN devices da ON da.device_id = pa.device_id
     LEFT JOIN device_ports pb ON pb.port_id = c.b_port_id
     LEFT JOIN devices db ON db.device_id = pb.device_id
     LEFT JOIN cable_paths cp ON cp.path_id = c.path_id
     ORDER BY c.cable_id DESC'
);

$mediaPresets = CablePlantService::mediaPresets();
$speedOpts = CablePlantService::speedOptions();
$speedColors = CablePlantService::speedColors();
$pathKinds = CablePlantService::pathKinds();
$mediaClasses = CablePlantService::mediaClasses();
$feedModes = CablePlantService::feedModes();
$roles = CablePlantService::cableRoles();

layout_header('Cable plant', $user, 'cables');
?>

<p class="text-muted" style="margin-top:0">
    Port-to-port connections with optional <strong>raceway / pathway</strong> (draw geometry on the
    <a href="<?= App::e(App::url('pages/floorplan.php')) ?>">Floor planner</a>).
    Fiber troughs, copper trays, overhead vs raised-floor feed, and speed colors live here.
</p>

<div class="split-2">
    <div class="card">
        <div class="card-header"><h2>Connections</h2></div>
        <div class="card-body flush">
            <table class="data">
                <thead>
                <tr>
                    <th>Label</th><th>Role</th><th>A End</th><th>B End</th>
                    <th>Media</th><th>Speed</th><th>Path</th><th>Circuit</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cables as $c):
                    $swatch = $c['color_hex'] ?? null;
                    if (!$swatch && !empty($c['speed']) && isset($speedColors[$c['speed']])) {
                        $swatch = $speedColors[$c['speed']];
                    }
                    ?>
                    <tr>
                        <td>
                            <?php if ($swatch): ?>
                                <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?= App::e((string)$swatch) ?>;margin-right:.35rem;vertical-align:middle"></span>
                            <?php endif; ?>
                            <?= App::e($c['cable_label'] ?? ('#' . $c['cable_id'])) ?>
                        </td>
                        <td><span class="badge"><?= App::e($roles[$c['cable_role'] ?? ''] ?? ($c['cable_role'] ?? '—')) ?></span></td>
                        <td>
                            <?php if (!empty($c['a_device_id'])): ?>
                                <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$c['a_device_id'])) ?>">
                                    <?= App::e(($c['a_device'] ?? '?') . ' / ' . ($c['a_label'] ?? '—')) ?>
                                </a>
                            <?php else: ?>
                                <?= App::e(($c['a_device'] ?? '?') . ' / ' . ($c['a_label'] ?? '—')) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($c['b_device_id'])): ?>
                                <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$c['b_device_id'])) ?>">
                                    <?= App::e(($c['b_device'] ?? '?') . ' / ' . ($c['b_label'] ?? '—')) ?>
                                </a>
                            <?php else: ?>
                                <?= App::e(($c['b_device'] ?? '?') . ' / ' . ($c['b_label'] ?? '—')) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= App::e($c['media_type'] ?? '—') ?></td>
                        <td><?= App::e($c['speed'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($c['path_name'])): ?>
                                <?php if (!empty($c['path_color'])): ?>
                                    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?= App::e((string)$c['path_color']) ?>;margin-right:.25rem"></span>
                                <?php endif; ?>
                                <?= App::e((string)$c['path_name']) ?>
                                <?php if (!empty($c['path_feed'])): ?>
                                    <div class="text-muted" style="font-size:.72rem"><?= App::e((string)$c['path_feed']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= App::e($c['circuit_id'] ?? '—') ?></td>
                        <td>
                            <?php if ($canEdit): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete cable?')">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_cable">
                                <input type="hidden" name="cable_id" value="<?= (int)$c['cable_id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">×</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$cables): ?><tr><td colspan="9" class="text-muted">No cables recorded.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEdit): ?>
        <div class="card-body">
            <h3 class="mt-0">Add connection</h3>
            <form method="post" class="form-grid" id="cableAddForm">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_cable">
                <div class="form-row"><label>Cable label</label><input class="form-control" name="cable_label" placeholder="Optional tag / barcode"></div>
                <div class="form-row"><label>Role</label>
                    <select class="form-control" name="cable_role">
                        <?php foreach ($roles as $rv => $rl): ?>
                            <option value="<?= App::e($rv) ?>"><?= App::e($rl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Media</label>
                    <select class="form-control" name="media_type" id="cable_media">
                        <option value="">— Custom / other —</option>
                        <?php foreach ($mediaPresets as $mp): ?>
                            <option value="<?= App::e($mp['value']) ?>"
                                    data-speed="<?= App::e((string)($mp['default_speed'] ?? '')) ?>"
                                    data-color="<?= App::e($mp['default_color']) ?>">
                                <?= App::e($mp['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Speed</label>
                    <select class="form-control" name="speed" id="cable_speed">
                        <option value="">—</option>
                        <?php foreach ($speedOpts as $sp): ?>
                            <option value="<?= App::e($sp) ?>" data-color="<?= App::e($speedColors[$sp] ?? '') ?>">
                                <?= App::e($sp) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Jacket / map color</label>
                    <input class="form-control" type="color" name="color_hex" id="cable_color" value="#2563eb"></div>
                <div class="form-row"><label>Circuit ID</label>
                    <input class="form-control" name="circuit_id" placeholder="e.g. IDF-A-12"></div>
                <div class="form-row"><label>Strand count</label>
                    <input class="form-control" type="number" min="1" name="strand_count" placeholder="Fiber strands"></div>
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
                <div class="form-row"><label>Raceway / path</label>
                    <select class="form-control" name="path_id">
                        <option value="">— None —</option>
                        <?php foreach ($paths as $path): ?>
                            <option value="<?= (int)$path['path_id'] ?>">
                                <?= App::e($path['name']) ?>
                                (<?= App::e((string)($path['media_class'] ?? $path['path_type'] ?? '')) ?>
                                · <?= App::e((string)($path['feed_to'] ?? '')) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Length (m)</label>
                    <input class="form-control" type="number" step="0.1" name="length_m"></div>
                <div class="form-row full"><label>Notes</label><input class="form-control" name="notes"></div>
                <div class="form-row"><button class="btn btn-primary" type="submit">Add cable</button></div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header flex-between">
            <h2 style="margin:0">Raceways &amp; pathways</h2>
            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/floorplan.php')) ?>">Draw on floor plan</a>
        </div>
        <div class="card-body flush">
            <table class="data">
                <thead>
                <tr><th>Name</th><th>Kind</th><th>Media</th><th>Feed</th><th>Map</th><th>Points</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($paths as $path):
                    $code = trim((string)($path['path_code'] ?? ''));
                    if ($code === '') {
                        $code = (string)($path['name'] ?? '');
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?= App::e($code) ?></strong>
                            <?php if (!empty($path['name']) && (string)$path['name'] !== $code): ?>
                                <div class="text-muted" style="font-size:.75rem"><?= App::e((string)$path['name']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($path['segment_class'])): ?>
                                <span class="badge" style="margin-top:.2rem"><?= App::e(strtoupper((string)$path['segment_class'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge"><?= App::e($pathKinds[$path['path_kind'] ?? ''] ?? ($path['path_kind'] ?? $path['path_type'] ?? '—')) ?></span></td>
                        <td><?= App::e($mediaClasses[$path['media_class'] ?? ''] ?? ($path['media_class'] ?? '—')) ?></td>
                        <td><?= App::e($feedModes[$path['feed_to'] ?? ''] ?? ($path['feed_to'] ?? '—')) ?></td>
                        <td><span style="display:inline-block;width:16px;height:16px;border-radius:3px;background:<?= App::e($path['color_hex'] ?? '#38bdf8') ?>;border:1px solid var(--border)"></span></td>
                        <td><?= (int)($path['point_count'] ?? 0) ?></td>
                        <td>
                            <?php if ($canEdit): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete path?')">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_path">
                                <input type="hidden" name="path_id" value="<?= (int)$path['path_id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">×</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$paths): ?><tr><td colspan="7" class="text-muted">No raceways yet — add one below or draw on the floor plan.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEdit): ?>
        <div class="card-body">
            <h3 class="mt-0">Add raceway / path</h3>
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_path">
                <div class="form-row"><label>Pathway code *</label>
                    <input class="form-control" name="path_code" required placeholder="RS-A / ORC-AB.1"></div>
                <div class="form-row"><label>Display name</label>
                    <input class="form-control" name="name" placeholder="Defaults to code"></div>
                <div class="form-row"><label>Segment class</label>
                    <select class="form-control" name="segment_class">
                        <?php foreach (CablePlantService::segmentClasses() as $sv => $sl): ?>
                            <option value="<?= App::e($sv) ?>"><?= App::e($sl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Raceway type *</label>
                    <select class="form-control" name="path_kind" id="path_kind">
                        <?php foreach (['ladder', 'fiber_raceway', 'conduit'] as $pk): ?>
                            <option value="<?= App::e($pk) ?>" <?= $pk === 'fiber_raceway' ? 'selected' : '' ?>>
                                <?= App::e($pathKinds[$pk] ?? $pk) ?>
                            </option>
                        <?php endforeach; ?>
                        <optgroup label="Advanced">
                        <?php foreach ($pathKinds as $kv => $kl):
                            if (in_array($kv, ['ladder', 'fiber_raceway', 'conduit'], true)) {
                                continue;
                            }
                            ?>
                            <option value="<?= App::e($kv) ?>"><?= App::e($kl) ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="form-row"><label>Media class</label>
                    <select class="form-control" name="media_class" id="path_media_class">
                        <?php foreach ($mediaClasses as $mv => $ml): ?>
                            <option value="<?= App::e($mv) ?>"><?= App::e($ml) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Cabinet feed</label>
                    <select class="form-control" name="feed_to">
                        <?php foreach ($feedModes as $fv => $fl): ?>
                            <option value="<?= App::e($fv) ?>"><?= App::e($fl) ?></option>
                        <?php endforeach; ?>
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
                <div class="form-row"><label>Map color</label>
                    <input class="form-control" type="color" name="color_hex" id="path_color" value="#eab308"></div>
                <div class="form-row"><label>Nominal width (m)</label>
                    <input class="form-control" type="number" step="0.05" min="0.05" name="width_m" placeholder="0.30"></div>
                <div class="form-row full"><label>Notes</label>
                    <input class="form-control" name="notes" placeholder="e.g. yellow FiberGuide over cold aisle"></div>
                <div class="form-row">
                    <button class="btn btn-primary" type="submit">Add path</button>
                </div>
                <p class="text-muted full" style="font-size:.78rem;margin:0">
                    Geometry (polyline on the floor) is drawn in Floor planner → <strong>Draw raceway</strong>.
                    Assign the path when adding cables so links know which trough they ride.
                </p>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><h2>Speed color guide</h2></div>
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:.65rem">
        <?php foreach ($speedColors as $sp => $hx): ?>
            <span class="badge" style="background:<?= App::e($hx) ?>;color:#0b1220;font-weight:600">
                <?= App::e($sp) ?>
            </span>
        <?php endforeach; ?>
        <p class="text-muted" style="width:100%;font-size:.8rem;margin:.35rem 0 0">
            Defaults when you pick a speed; override per cable with the color picker. Fiber raceways default yellow (PVC trough).
        </p>
    </div>
</div>

<script>
(function () {
  var media = document.getElementById('cable_media');
  var speed = document.getElementById('cable_speed');
  var color = document.getElementById('cable_color');
  var pathMedia = document.getElementById('path_media_class');
  var pathColor = document.getElementById('path_color');
  var pathKind = document.getElementById('path_kind');
  if (media && color) {
    media.addEventListener('change', function () {
      var opt = media.options[media.selectedIndex];
      if (!opt) return;
      var c = opt.getAttribute('data-color');
      var s = opt.getAttribute('data-speed');
      if (c) color.value = c;
      if (s && speed) {
        for (var i = 0; i < speed.options.length; i++) {
          if (speed.options[i].value === s) { speed.selectedIndex = i; break; }
        }
      }
    });
  }
  if (speed && color) {
    speed.addEventListener('change', function () {
      var opt = speed.options[speed.selectedIndex];
      var c = opt && opt.getAttribute('data-color');
      if (c) color.value = c;
    });
  }
  function syncPathColor() {
    if (!pathMedia || !pathColor) return;
    var map = { fiber: '#eab308', copper: '#2563eb', power: '#111827', mixed: '#38bdf8' };
    var v = pathMedia.value;
    if (map[v]) pathColor.value = map[v];
  }
  if (pathMedia) pathMedia.addEventListener('change', syncPathColor);
  if (pathKind) {
    pathKind.addEventListener('change', function () {
      if (pathKind.value === 'fiber_trough' && pathMedia) {
        pathMedia.value = 'fiber';
        syncPathColor();
      }
    });
  }
})();
</script>
<?php layout_footer(); ?>
