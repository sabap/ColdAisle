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
    'SELECT p.port_id, p.label, p.port_type, p.port_number, p.speed AS port_speed,
            d.label AS device_label, d.device_id, d.cabinet_id,
            c.name AS cabinet_name, c.room_id
     FROM device_ports p
     INNER JOIN devices d ON d.device_id = p.device_id
     LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
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
        if ($action === 'calculate_path' || $action === 'calculate_and_apply') {
            $cableId = (int)($_POST['cable_id'] ?? 0);
            if ($cableId < 1 || !class_exists('CableRouteService')) {
                throw new RuntimeException('Cable or route service unavailable.');
            }
            $r0 = CableRouteService::routeForCable($cableId, false);
            $from = (int)($r0['route']['a']['cabinet_id'] ?? 0);
            $to = (int)($r0['route']['b']['cabinet_id'] ?? 0);
            $calc = CableRouteService::calculateBetweenCabinets($from, $to);
            if (empty($calc['ok'])) {
                throw new RuntimeException($calc['message'] ?? 'No raceway route found.');
            }
            if ($action === 'calculate_and_apply') {
                $apply = CableRouteService::applyRouteToCable($cableId, $calc['path_ids'] ?? [], 'calculated');
                if (empty($apply['ok'])) {
                    throw new RuntimeException($apply['message'] ?? 'Could not apply route.');
                }
                App::flash('success', ($calc['message'] ?? 'Route calculated') . ' — applied to cable.');
            } else {
                App::flash(
                    'success',
                    ($calc['message'] ?? 'Route calculated')
                    . ' · ' . number_format((float)($calc['length_m'] ?? 0), 1) . ' m'
                    . ' (not saved — use Apply to store multi-hop path).'
                );
            }
        }
        if ($action === 'delete_path') {
            $pid = (int)($_POST['path_id'] ?? 0);
            $force = !empty($_POST['force']);
            $res = CablePlantService::deletePath($pid, $force);
            if (empty($res['ok'])) {
                throw new RuntimeException($res['message'] ?? 'Could not delete path.');
            }
            App::flash('success', $res['message']);
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/cables.php');
}

$q = class_exists('SearchService') ? SearchService::queryFromRequest() : trim((string)($_GET['q'] ?? ''));
$like = $q !== '' ? ('%' . $q . '%') : '';

try {
    if ($q !== '') {
        $paths = Database::fetchAll(
            'SELECT * FROM cable_paths
             WHERE name LIKE ? OR ISNULL(path_code, \'\') LIKE ? OR ISNULL(notes, \'\') LIKE ?
                OR CAST(path_id AS NVARCHAR(20)) = ?
             ORDER BY name',
            [$like, $like, $like, $q]
        );
    } else {
        $paths = Database::fetchAll('SELECT * FROM cable_paths ORDER BY name');
    }
} catch (Throwable $e) {
    $paths = [];
}
foreach ($paths as &$p) {
    $p['waypoints_list'] = CablePlantService::parseWaypoints($p['waypoints'] ?? null);
    $p['point_count'] = count($p['waypoints_list']);
}
unset($p);

$cableSql = 'SELECT c.*,
        pa.label AS a_label, da.label AS a_device, da.device_id AS a_device_id,
        da.cabinet_id AS a_cabinet_id, ca.name AS a_cabinet_name, ca.room_id AS a_room_id,
        pb.label AS b_label, db.label AS b_device, db.device_id AS b_device_id,
        db.cabinet_id AS b_cabinet_id, cb.name AS b_cabinet_name, cb.room_id AS b_room_id,
        cp.name AS path_name, cp.path_code AS path_code, cp.color_hex AS path_color,
        cp.media_class AS path_media, cp.feed_to AS path_feed, cp.path_kind AS path_kind
     FROM cables c
     LEFT JOIN device_ports pa ON pa.port_id = c.a_port_id
     LEFT JOIN devices da ON da.device_id = pa.device_id
     LEFT JOIN cabinets ca ON ca.cabinet_id = da.cabinet_id
     LEFT JOIN device_ports pb ON pb.port_id = c.b_port_id
     LEFT JOIN devices db ON db.device_id = pb.device_id
     LEFT JOIN cabinets cb ON cb.cabinet_id = db.cabinet_id
     LEFT JOIN cable_paths cp ON cp.path_id = c.path_id';
$cableParams = [];
if ($q !== '') {
    $cableSql .= ' WHERE ISNULL(c.cable_label, \'\') LIKE ? OR ISNULL(c.circuit_id, \'\') LIKE ?
                      OR ISNULL(da.label, \'\') LIKE ? OR ISNULL(db.label, \'\') LIKE ?
                      OR ISNULL(ca.name, \'\') LIKE ? OR ISNULL(cb.name, \'\') LIKE ?
                      OR ISNULL(cp.path_code, \'\') LIKE ? OR ISNULL(cp.name, \'\') LIKE ?
                      OR CAST(c.cable_id AS NVARCHAR(20)) = ?';
    $cableParams = [$like, $like, $like, $like, $like, $like, $like, $like, $q];
}
$cableSql .= ' ORDER BY c.cable_id DESC';
$cableKeep = class_exists('ListPager') ? ListPager::keepGet(['q']) : [];
if (class_exists('ListPager') && ListPager::wantsCsv()) {
    $exportRows = Database::fetchAll(ListPager::applyLimit($cableSql, 0, ListPager::CSV_MAX), $cableParams);
    $csv = [];
    foreach ($exportRows as $c) {
        $csv[] = [
            $c['cable_label'] ?? ('#' . ($c['cable_id'] ?? '')),
            $c['cable_role'] ?? '',
            trim(($c['a_device'] ?? '') . ' / ' . ($c['a_label'] ?? ''), ' /'),
            trim(($c['b_device'] ?? '') . ' / ' . ($c['b_label'] ?? ''), ' /'),
            $c['a_cabinet_name'] ?? '',
            $c['b_cabinet_name'] ?? '',
            $c['media_type'] ?? '',
            $c['speed'] ?? '',
            $c['path_code'] ?: ($c['path_name'] ?? ''),
            $c['circuit_id'] ?? '',
        ];
    }
    ListPager::sendCsv('coldaisle-cables-' . date('Ymd-His') . '.csv', [
        'Label', 'Role', 'A end', 'B end', 'A cabinet', 'B cabinet', 'Media', 'Speed', 'Path', 'Circuit',
    ], $csv);
}
$cableTotal = class_exists('ListPager') ? ListPager::count($cableSql, $cableParams) : count($cables ?? []);
$cablePager = class_exists('ListPager') ? ListPager::fromRequest($cableTotal) : [
    'page' => 1, 'per_page' => 50, 'offset' => 0, 'total' => $cableTotal,
    'pages' => 1, 'from' => 0, 'to' => 0,
];
$cables = Database::fetchAll(
    class_exists('ListPager')
        ? ListPager::applyLimit($cableSql, $cablePager['offset'], $cablePager['per_page'])
        : $cableSql,
    $cableParams
);

// Map path_id → short code for multi-hop labels
$pathCodeById = [];
foreach ($paths as $pRow) {
    $pid = (int)($pRow['path_id'] ?? 0);
    if ($pid < 1) {
        continue;
    }
    $code = trim((string)($pRow['path_code'] ?? ''));
    if ($code === '') {
        $code = trim((string)($pRow['name'] ?? ('#' . $pid)));
    }
    $pathCodeById[$pid] = $code;
}

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
    Port-to-port connections with optional multi-hop <strong>raceway routes</strong>
    (e.g. Cabinet → RS-A → IRC → RS-B → Cabinet). Draw raceways on the
    <a href="<?= App::e(App::url('pages/floorplan.php')) ?>">Floor planner</a>,
    then <strong>Show path</strong> or <strong>Calculate shortest path</strong> on a connection.
    Fiber troughs, copper trays, overhead vs raised-floor feed, and speed colors live here.
</p>
<div class="flex-between mb-2" style="flex-wrap:wrap;gap:.5rem">
    <?php layout_search_form('Search label, circuit, device, cabinet, raceway…', $q, 'pages/cables.php', $cableKeep); ?>
    <?php if (!empty($cablePager['total']) && class_exists('ListPager')): ?>
        <a class="btn btn-secondary" href="<?= App::e(ListPager::href('pages/cables.php', $cableKeep, ['export' => 'csv'])) ?>">Export CSV</a>
    <?php endif; ?>
</div>

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
                    $hopIds = class_exists('CableRouteService')
                        ? CableRouteService::pathIdsForCable($c)
                        : ((int)($c['path_id'] ?? 0) > 0 ? [(int)$c['path_id']] : []);
                    $hopCodes = [];
                    foreach ($hopIds as $hid) {
                        $hopCodes[] = $pathCodeById[$hid] ?? ('#' . $hid);
                    }
                    $routeParts = [];
                    if (!empty($c['a_cabinet_name'])) {
                        $routeParts[] = (string)$c['a_cabinet_name'];
                    }
                    foreach ($hopCodes as $hc) {
                        $routeParts[] = $hc;
                    }
                    if (!empty($c['b_cabinet_name'])) {
                        $routeParts[] = (string)$c['b_cabinet_name'];
                    }
                    $routeLabel = $routeParts !== [] ? implode(' → ', $routeParts) : '';
                    $roomForFp = (int)($c['a_room_id'] ?? $c['b_room_id'] ?? 0);
                    $fpShow = App::url('pages/floorplan.php?' . http_build_query(array_filter([
                        'room_id' => $roomForFp > 0 ? $roomForFp : null,
                        'cable_id' => (int)$c['cable_id'],
                        'show_routes' => 1,
                        'calculate' => 1,
                    ])));
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
                        <td style="max-width:14rem">
                            <?php if ($hopCodes !== []): ?>
                                <?php if (!empty($c['path_color'])): ?>
                                    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?= App::e((string)$c['path_color']) ?>;margin-right:.25rem"></span>
                                <?php endif; ?>
                                <span title="<?= App::e($routeLabel) ?>"><?= App::e(implode(' → ', $hopCodes)) ?></span>
                                <?php if (count($hopCodes) > 1): ?>
                                    <div class="text-muted" style="font-size:.7rem"><?= count($hopCodes) ?> hops</div>
                                <?php elseif (!empty($c['path_feed'])): ?>
                                    <div class="text-muted" style="font-size:.72rem"><?= App::e((string)$c['path_feed']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= App::e($c['circuit_id'] ?? '—') ?></td>
                        <td style="white-space:nowrap">
                            <a class="btn btn-ghost btn-sm" href="<?= App::e($fpShow) ?>" title="Draw route on floor plan">Path</a>
                            <?php if ($canEdit && !empty($c['a_cabinet_id']) && !empty($c['b_cabinet_id'])): ?>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('Calculate shortest raceway route and apply it to this cable?');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="calculate_and_apply">
                                <input type="hidden" name="cable_id" value="<?= (int)$c['cable_id'] ?>">
                                <button class="btn btn-secondary btn-sm" type="submit"
                                        title="Shortest multi-hop raceway path between cabinets">Calc path</button>
                            </form>
                            <?php endif; ?>
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
                <?php if (!$cables): ?>
                    <tr><td colspan="9" class="text-muted">
                        <?php if ($q !== ''): ?>
                            No connections match “<?= App::e($q) ?>”.
                            <a href="<?= App::e(App::url('pages/cables.php')) ?>">Clear search</a>
                        <?php else: ?>
                            No cables recorded.
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if (class_exists('ListPager')) {
                layout_list_pager($cablePager, 'pages/cables.php', $cableKeep);
            } ?>
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
                    <select class="form-control" name="a_port_id" id="cable_a_port">
                        <option value="">—</option>
                        <?php foreach ($ports as $p): ?>
                            <option value="<?= (int)$p['port_id'] ?>"
                                    data-cabinet="<?= (int)($p['cabinet_id'] ?? 0) ?>"
                                    data-cabinet-name="<?= App::e((string)($p['cabinet_name'] ?? '')) ?>">
                                <?= App::e($p['device_label'] . ' · ' . $p['port_type'] . ' · ' . ($p['label'] ?: '#' . $p['port_number'])) ?>
                                <?php if (!empty($p['cabinet_name'])): ?>
                                    · <?= App::e((string)$p['cabinet_name']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row full"><label>Port B</label>
                    <select class="form-control" name="b_port_id" id="cable_b_port">
                        <option value="">—</option>
                        <?php foreach ($ports as $p): ?>
                            <option value="<?= (int)$p['port_id'] ?>"
                                    data-cabinet="<?= (int)($p['cabinet_id'] ?? 0) ?>"
                                    data-cabinet-name="<?= App::e((string)($p['cabinet_name'] ?? '')) ?>">
                                <?= App::e($p['device_label'] . ' · ' . $p['port_type'] . ' · ' . ($p['label'] ?: '#' . $p['port_number'])) ?>
                                <?php if (!empty($p['cabinet_name'])): ?>
                                    · <?= App::e((string)$p['cabinet_name']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row full">
                    <label>Raceway route (multi-hop)</label>
                    <select class="form-control" name="path_ids[]" id="cable_path_ids" multiple size="5"
                            title="Hold Ctrl/Cmd to select multiple raceways in order A→B">
                        <?php foreach ($paths as $path):
                            $pc = trim((string)($path['path_code'] ?? ''));
                            if ($pc === '') {
                                $pc = (string)($path['name'] ?? '');
                            }
                            ?>
                            <option value="<?= (int)$path['path_id'] ?>">
                                <?= App::e($pc) ?>
                                (<?= App::e((string)($path['media_class'] ?? $path['path_type'] ?? '')) ?>
                                · <?= App::e((string)($path['feed_to'] ?? '')) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="flex gap-1" style="margin-top:.4rem;flex-wrap:wrap;align-items:center">
                        <label class="text-muted" style="font-size:.78rem;margin:0" for="cable_route_network">Network</label>
                        <select class="form-control" id="cable_route_network" style="width:auto;min-width:10rem;font-size:.85rem">
                            <option value="all">All raceways</option>
                            <option value="fiber_u_channel" selected>Fiber U-channel</option>
                            <option value="fiber">Fiber (U-ch + trough)</option>
                            <option value="fiber_raceway">Fiber trough</option>
                            <option value="ladder">Ladder</option>
                            <option value="conduit">Conduit</option>
                        </select>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnCalcShortestPath">
                            Calculate shortest path
                        </button>
                        <span class="text-muted" style="font-size:.78rem" id="calcPathHint">
                            Shortest sequence on the selected raceway network.
                        </span>
                    </div>
                    <p id="calcPathResult" class="text-muted" style="font-size:.8rem;margin:.35rem 0 0;display:none"></p>
                </div>
                <div class="form-row"><label>Length (m)</label>
                    <input class="form-control" type="number" step="0.1" name="length_m" id="cable_length_m"></div>
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
                <tr><th>Name</th><th>Kind</th><th>Media</th><th>Feed</th><th>W / elev</th><th>Map</th><th>Points</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($paths as $path):
                    $code = trim((string)($path['path_code'] ?? ''));
                    if ($code === '') {
                        $code = (string)($path['name'] ?? '');
                    }
                    $wShow = isset($path['width_m']) && $path['width_m'] !== null && $path['width_m'] !== ''
                        ? number_format((float)$path['width_m'], 2) . ' m'
                        : '—';
                    $eShow = isset($path['elevation_m']) && $path['elevation_m'] !== null && $path['elevation_m'] !== ''
                        ? number_format((float)$path['elevation_m'], 2) . ' m'
                        : '—';
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
                        <td style="font-size:.8rem;white-space:nowrap"><?= App::e($wShow) ?> / <?= App::e($eShow) ?></td>
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
                <?php if (!$paths): ?>
                    <tr><td colspan="8" class="text-muted">
                        <?php if ($q !== ''): ?>
                            No raceways match “<?= App::e($q) ?>”.
                        <?php else: ?>
                            No raceways yet — add one below or draw on the floor plan.
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
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
                        <?php foreach (['ladder', 'fiber_u_channel', 'fiber_raceway', 'conduit'] as $pk): ?>
                            <option value="<?= App::e($pk) ?>" <?= $pk === 'fiber_u_channel' ? 'selected' : '' ?>>
                                <?= App::e($pathKinds[$pk] ?? $pk) ?>
                            </option>
                        <?php endforeach; ?>
                        <optgroup label="Advanced">
                        <?php foreach ($pathKinds as $kv => $kl):
                            if (in_array($kv, ['ladder', 'fiber_u_channel', 'fiber_raceway', 'conduit'], true)) {
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
                    <input class="form-control" type="number" step="0.05" min="0.03" name="width_m" placeholder="0.30" title="Tray / trough width for 3D"></div>
                <div class="form-row"><label>Elevation AFF (m)</label>
                    <input class="form-control" type="number" step="0.05" name="elevation_m" placeholder="2.70" title="Height above finished floor for 3D (underfloor: negative)"></div>
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

  // Calculate shortest multi-hop raceway path between Port A / Port B cabinets
  var btnCalc = document.getElementById('btnCalcShortestPath');
  var aPort = document.getElementById('cable_a_port');
  var bPort = document.getElementById('cable_b_port');
  var pathMulti = document.getElementById('cable_path_ids');
  var calcOut = document.getElementById('calcPathResult');
  var lenInput = document.getElementById('cable_length_m');
  if (btnCalc && aPort && bPort && pathMulti && window.ColdAisle && ColdAisle.api) {
    btnCalc.addEventListener('click', function () {
      var oa = aPort.options[aPort.selectedIndex];
      var ob = bPort.options[bPort.selectedIndex];
      var ca = oa ? Number(oa.getAttribute('data-cabinet') || 0) : 0;
      var cb = ob ? Number(ob.getAttribute('data-cabinet') || 0) : 0;
      var na = oa ? (oa.getAttribute('data-cabinet-name') || 'A') : 'A';
      var nb = ob ? (ob.getAttribute('data-cabinet-name') || 'B') : 'B';
      if (!(ca > 0) || !(cb > 0)) {
        if (calcOut) {
          calcOut.style.display = 'block';
          calcOut.textContent = 'Select ports whose devices are both in placed cabinets.';
        }
        ColdAisle.toast('Both ports need devices in cabinets', 'error');
        return;
      }
      if (ca === cb) {
        if (calcOut) {
          calcOut.style.display = 'block';
          calcOut.textContent = 'Same cabinet — no raceway route needed for intra-rack links.';
        }
        return;
      }
      btnCalc.disabled = true;
      var netEl = document.getElementById('cable_route_network');
      var net = netEl ? netEl.value : 'all';
      ColdAisle.api('api/cables.php?entity=routes', {
        method: 'POST',
        body: { action: 'calculate', from_cabinet_id: ca, to_cabinet_id: cb, network: net },
      }).then(function (data) {
        btnCalc.disabled = false;
        var ids = data.path_ids || [];
        // Select matching options (order of selection may not preserve hop order in multi select,
        // but path_ids[] POST order follows option list order when using selectedOptions in some browsers;
        // encode order via hidden fields if needed — CablePlantService accepts path_ids array.)
        for (var i = 0; i < pathMulti.options.length; i++) {
          pathMulti.options[i].selected = false;
        }
        ids.forEach(function (id) {
          for (var j = 0; j < pathMulti.options.length; j++) {
            if (Number(pathMulti.options[j].value) === Number(id)) {
              pathMulti.options[j].selected = true;
              break;
            }
          }
        });
        // Ensure path_ids[] is posted in hop order (multi-select alone is unreliable)
        var form = document.getElementById('cableAddForm');
        if (form) {
          form.querySelectorAll('input[name="path_ids_ordered"]').forEach(function (el) {
            el.parentNode.removeChild(el);
          });
          var hid = document.createElement('input');
          hid.type = 'hidden';
          hid.name = 'path_ids_ordered';
          hid.value = ids.join(',');
          form.appendChild(hid);
        }
        if (lenInput && data.length_m != null && !lenInput.value) {
          lenInput.value = String(data.length_m);
        }
        if (calcOut) {
          calcOut.style.display = 'block';
          calcOut.textContent = (data.message || (na + ' → … → ' + nb))
            + (data.length_m != null ? (' · ≈ ' + Number(data.length_m).toFixed(1) + ' m') : '')
            + ' — will be saved when you add the cable.';
        }
        ColdAisle.toast('Shortest path selected (' + ids.length + ' hop' + (ids.length === 1 ? '' : 's') + ')', 'success');
      }).catch(function (e) {
        btnCalc.disabled = false;
        if (calcOut) {
          calcOut.style.display = 'block';
          calcOut.textContent = (e && e.message) || 'No route found';
        }
        ColdAisle.toast((e && e.message) || 'No route found', 'error');
      });
    });
  }
})();
</script>
<?php layout_footer(); ?>
