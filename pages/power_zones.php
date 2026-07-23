<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/power_helpers.php';
App::boot();
$user = App::requirePermission('view_power');

$zoneId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$dcs = Database::fetchAll('SELECT datacenter_id, name FROM datacenters WHERE is_active = 1 ORDER BY name');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!AuthManager::canEditPower($user)) {
        App::flash('error', 'You do not have permission to modify power zones.');
        App::redirect('pages/power_zones.php');
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_zone') {
            $id = Database::insert('power_zones', [
                'datacenter_id' => (int)$_POST['datacenter_id'],
                'name' => trim($_POST['name']),
                'feed_type' => $_POST['feed_type'] ?? 'A',
                'voltage' => $_POST['voltage'] !== '' ? (int)$_POST['voltage'] : 208,
                'max_kw' => $_POST['max_kw'] !== '' ? (float)$_POST['max_kw'] : null,
                'max_amps' => $_POST['max_amps'] !== '' ? (float)$_POST['max_amps'] : null,
                'color_hex' => power_normalize_color($_POST['color_hex'] ?? null),
                'description' => trim($_POST['description'] ?? '') !== '' ? trim($_POST['description']) : null,
            ]);
            App::flash('success', 'Power zone created.');
            if ($id) {
                App::redirect('pages/power_zones.php?id=' . (int)$id);
            }
        }
        if ($action === 'update_zone') {
            $zid = (int)($_POST['zone_id'] ?? 0);
            if ($zid <= 0) {
                throw new RuntimeException('Zone required.');
            }
            Database::update('power_zones', [
                'datacenter_id' => (int)$_POST['datacenter_id'],
                'name' => trim($_POST['name']),
                'feed_type' => $_POST['feed_type'] ?? 'A',
                'voltage' => $_POST['voltage'] !== '' ? (int)$_POST['voltage'] : null,
                'max_kw' => $_POST['max_kw'] !== '' ? (float)$_POST['max_kw'] : null,
                'max_amps' => $_POST['max_amps'] !== '' ? (float)$_POST['max_amps'] : null,
                'color_hex' => power_normalize_color($_POST['color_hex'] ?? null),
                'description' => trim($_POST['description'] ?? '') !== '' ? trim($_POST['description']) : null,
                'notes' => trim($_POST['notes'] ?? '') !== '' ? trim($_POST['notes']) : null,
            ], 'zone_id = :id', [':id' => $zid]);
            App::flash('success', 'Zone updated.');
            App::redirect('pages/power_zones.php?id=' . $zid);
        }
        if ($action === 'add_panel') {
            $zid = $_POST['zone_id'] !== '' ? (int)$_POST['zone_id'] : null;
            Database::insert('power_panels', [
                'zone_id' => $zid,
                'name' => trim($_POST['name']),
                'panel_type' => $_POST['panel_type'] ?? 'sub',
                'voltage' => $_POST['voltage'] !== '' ? (int)$_POST['voltage'] : null,
                'phases' => (int)($_POST['phases'] ?? 3),
                'main_breaker_amps' => $_POST['main_breaker_amps'] !== '' ? (float)$_POST['main_breaker_amps'] : null,
                'num_poles' => $_POST['num_poles'] !== '' ? (int)$_POST['num_poles'] : null,
            ]);
            App::flash('success', 'Power panel created.');
            if ($zid) {
                App::redirect('pages/power_zones.php?id=' . $zid);
            }
        }
        if ($action === 'delete_panel') {
            $pid = (int)($_POST['panel_id'] ?? 0);
            $zid = (int)($_POST['zone_id'] ?? 0);
            if ($pid > 0) {
                Database::delete('power_panels', 'panel_id = ?', [$pid]);
                App::flash('success', 'Panel removed.');
            }
            if ($zid) {
                App::redirect('pages/power_zones.php?id=' . $zid);
            }
        }
        if ($action === 'assign_rows') {
            $zid = (int)($_POST['zone_id'] ?? 0);
            if ($zid <= 0) {
                throw new RuntimeException('Zone required.');
            }
            $zone = Database::fetchOne('SELECT * FROM power_zones WHERE zone_id = ?', [$zid]);
            if (!$zone) {
                throw new RuntimeException('Zone not found.');
            }
            $raw = $_POST['row_ids'] ?? [];
            if (!is_array($raw)) {
                $raw = $raw !== '' && $raw !== null ? [$raw] : [];
            }
            $ids = [];
            foreach ($raw as $v) {
                $n = (int)$v;
                if ($n > 0) {
                    $ids[$n] = $n;
                }
            }
            if (!$ids) {
                throw new RuntimeException('Select at least one row to assign.');
            }
            $dcId = (int)$zone['datacenter_id'];
            $assigned = 0;
            foreach ($ids as $rid) {
                $row = Database::fetchOne(
                    'SELECT r.row_id, r.zone_id, rm.datacenter_id
                     FROM cabinet_rows r
                     LEFT JOIN rooms rm ON rm.room_id = r.room_id
                     WHERE r.row_id = ?',
                    [$rid]
                );
                if (!$row) {
                    continue;
                }
                // Prefer rows in the same data center; still allow if room/DC unknown
                if (!empty($row['datacenter_id']) && (int)$row['datacenter_id'] !== $dcId) {
                    continue;
                }
                Database::update('cabinet_rows', ['zone_id' => $zid], 'row_id = :id', [':id' => $rid]);
                $assigned++;
            }
            if ($assigned < 1) {
                throw new RuntimeException('No eligible rows assigned (check data center match).');
            }
            App::flash('success', $assigned === 1
                ? '1 row assigned to this zone.'
                : "{$assigned} rows assigned to this zone.");
            App::redirect('pages/power_zones.php?id=' . $zid);
        }
        if ($action === 'unassign_row') {
            $zid = (int)($_POST['zone_id'] ?? 0);
            $rid = (int)($_POST['row_id'] ?? 0);
            if ($zid <= 0 || $rid <= 0) {
                throw new RuntimeException('Zone and row required.');
            }
            Database::update(
                'cabinet_rows',
                ['zone_id' => null],
                'row_id = :id AND zone_id = :z',
                [':id' => $rid, ':z' => $zid]
            );
            App::flash('success', 'Row removed from zone.');
            App::redirect('pages/power_zones.php?id=' . $zid);
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/power_zones.php' . ($zoneId ? '?id=' . $zoneId : ''));
}

$zones = Database::fetchAll(
    'SELECT z.*, dc.name AS dc_name,
            (SELECT COUNT(*) FROM pdus p WHERE p.zone_id = z.zone_id AND p.is_active = 1) AS pdu_count,
            (SELECT COUNT(*) FROM power_panels pp WHERE pp.zone_id = z.zone_id) AS panel_count,
            (SELECT COUNT(*) FROM cabinet_rows cr WHERE cr.zone_id = z.zone_id) AS row_count,
            (SELECT ISNULL(SUM(p.last_poll_watts), 0) FROM pdus p WHERE p.zone_id = z.zone_id AND p.is_active = 1) AS poll_watts
     FROM power_zones z
     INNER JOIN datacenters dc ON dc.datacenter_id = z.datacenter_id
     ORDER BY dc.name, z.name'
);

// Detail view
if ($zoneId) {
    $zone = null;
    foreach ($zones as $z) {
        if ((int)$z['zone_id'] === $zoneId) {
            $zone = $z;
            break;
        }
    }
    if (!$zone) {
        App::flash('error', 'Zone not found.');
        App::redirect('pages/power_zones.php');
    }
    $panels = Database::fetchAll(
        'SELECT * FROM power_panels WHERE zone_id = ? ORDER BY name',
        [$zoneId]
    );
    $zonePdus = Database::fetchAll(
        'SELECT p.*, c.name AS cabinet_name
         FROM pdus p
         LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
         WHERE p.zone_id = ? AND p.is_active = 1
         ORDER BY p.name',
        [$zoneId]
    );
    $zoneRows = [];
    try {
        $zoneRows = Database::fetchAll(
            'SELECT r.row_id, r.name, r.color_hex, rm.name AS room_name, dc.name AS dc_name,
                    (SELECT COUNT(*) FROM cabinets c WHERE c.row_id = r.row_id AND c.is_active = 1) AS cabinet_count
             FROM cabinet_rows r
             LEFT JOIN rooms rm ON rm.room_id = r.room_id
             LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
             WHERE r.zone_id = ?
             ORDER BY rm.name, r.name',
            [$zoneId]
        );
    } catch (Throwable $e) {
        $zoneRows = [];
    }
    // Rows in the same DC not already on this zone (may be unassigned or on another zone)
    $assignableRows = [];
    try {
        $assignableRows = Database::fetchAll(
            'SELECT r.row_id, r.name, r.zone_id, rm.name AS room_name,
                    z.name AS other_zone_name,
                    (SELECT COUNT(*) FROM cabinets c WHERE c.row_id = r.row_id AND c.is_active = 1) AS cabinet_count
             FROM cabinet_rows r
             LEFT JOIN rooms rm ON rm.room_id = r.room_id
             LEFT JOIN power_zones z ON z.zone_id = r.zone_id
             WHERE (rm.datacenter_id = ? OR rm.datacenter_id IS NULL)
               AND (r.zone_id IS NULL OR r.zone_id <> ?)
             ORDER BY CASE WHEN r.zone_id IS NULL THEN 0 ELSE 1 END, rm.name, r.name',
            [(int)$zone['datacenter_id'], $zoneId]
        );
    } catch (Throwable $e) {
        $assignableRows = [];
    }
    $pollKw = ((float)($zone['poll_watts'] ?? 0)) / 1000.0;
    $maxKw = $zone['max_kw'] !== null && $zone['max_kw'] !== '' ? (float)$zone['max_kw'] : null;
    $pct = ($maxKw && $maxKw > 0) ? min(100, round(100 * $pollKw / $maxKw, 1)) : null;
    $color = power_normalize_color($zone['color_hex'] ?? null);

    layout_header('Zone: ' . $zone['name'], $user, 'power_zones');
    ?>
    <div class="flex-between mb-2">
        <div>
            <span class="dept-chip">
                <span class="dept-swatch" style="background:<?= App::e($color) ?>"></span>
                <span class="badge">Feed <?= App::e((string)$zone['feed_type']) ?></span>
                <span class="text-muted"><?= App::e($zone['dc_name']) ?></span>
            </span>
        </div>
        <div class="flex gap-1">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_zones.php')) ?>">← All zones</a>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">Dashboard</a>
            <a class="btn btn-primary" href="<?= App::e(App::url('pages/power_pdus.php?zone_id=' . $zoneId)) ?>">Zone PDUs</a>
        </div>
    </div>

    <div class="metrics">
        <div class="metric-card warning">
            <div class="label">Load</div>
            <div class="value"><?= number_format($pollKw, 1) ?> <span class="metric-unit">kW</span></div>
        </div>
        <div class="metric-card">
            <div class="label">Capacity</div>
            <div class="value"><?= $maxKw !== null ? number_format($maxKw, 1) . ' <span class="metric-unit">kW</span>' : '—' ?></div>
            <div class="sub"><?= $pct !== null ? $pct . '% used' : 'No max set' ?></div>
        </div>
        <div class="metric-card accent">
            <div class="label">Voltage</div>
            <div class="value"><?= $zone['voltage'] !== null ? (int)$zone['voltage'] : '—' ?> <span class="metric-unit">V</span></div>
        </div>
        <div class="metric-card">
            <div class="label">PDUs / Panels / Rows</div>
            <div class="value"><?= (int)$zone['pdu_count'] ?> <span class="metric-unit">/ <?= (int)$zone['panel_count'] ?> / <?= count($zoneRows) ?></span></div>
        </div>
    </div>

    <?php if ($pct !== null): ?>
        <div class="util-bar util-bar-lg mb-2">
            <div class="util-bar-fill util-<?= App::e(power_util_class((float)$pct)) ?>" style="width:<?= $pct ?>%"></div>
        </div>
    <?php endif; ?>

    <div class="split-2">
        <div class="card">
            <div class="card-header"><h2>Edit zone</h2></div>
            <div class="card-body">
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="update_zone">
                    <input type="hidden" name="zone_id" value="<?= $zoneId ?>">
                    <div class="form-row"><label>Name</label>
                        <input class="form-control" name="name" required value="<?= App::e($zone['name']) ?>"></div>
                    <div class="form-row"><label>Data center</label>
                        <select class="form-control" name="datacenter_id" required>
                            <?php foreach ($dcs as $d): ?>
                                <option value="<?= (int)$d['datacenter_id'] ?>"
                                    <?= (int)$zone['datacenter_id'] === (int)$d['datacenter_id'] ? 'selected' : '' ?>>
                                    <?= App::e($d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row"><label>Feed</label>
                        <select class="form-control" name="feed_type">
                            <?php foreach (['A', 'B', 'dual'] as $f): ?>
                                <option value="<?= $f ?>" <?= ($zone['feed_type'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row"><label>Color</label>
                        <input class="form-control" type="color" name="color_hex" value="<?= App::e($color) ?>"></div>
                    <div class="form-row"><label>Voltage</label>
                        <input class="form-control" type="number" name="voltage" value="<?= App::e((string)($zone['voltage'] ?? '')) ?>"></div>
                    <div class="form-row"><label>Max kW</label>
                        <input class="form-control" type="number" step="0.1" name="max_kw" value="<?= App::e((string)($zone['max_kw'] ?? '')) ?>"></div>
                    <div class="form-row"><label>Max amps</label>
                        <input class="form-control" type="number" step="0.1" name="max_amps" value="<?= App::e((string)($zone['max_amps'] ?? '')) ?>"></div>
                    <div class="form-row full"><label>Description</label>
                        <input class="form-control" name="description" value="<?= App::e($zone['description'] ?? '') ?>"></div>
                    <div class="form-row full"><label>Notes</label>
                        <textarea class="form-control" name="notes" rows="2"><?= App::e($zone['notes'] ?? '') ?></textarea></div>
                    <div class="form-row"><button class="btn btn-primary" type="submit">Save zone</button></div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Panels in zone</h2></div>
            <div class="card-body flush">
                <table class="data">
                    <thead><tr><th>Name</th><th>Type</th><th>V</th><th>φ</th><th>Breaker</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($panels as $p): ?>
                        <tr>
                            <td><?= App::e($p['name']) ?></td>
                            <td><?= App::e($p['panel_type'] ?? '—') ?></td>
                            <td><?= App::e((string)($p['voltage'] ?? '—')) ?></td>
                            <td><?= (int)($p['phases'] ?? 3) ?></td>
                            <td><?= $p['main_breaker_amps'] !== null ? App::e((string)$p['main_breaker_amps']) . ' A' : '—' ?></td>
                            <td class="actions">
                                <form method="post" style="display:inline" onsubmit="return confirm('Remove panel?');">
                                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_panel">
                                    <input type="hidden" name="panel_id" value="<?= (int)$p['panel_id'] ?>">
                                    <input type="hidden" name="zone_id" value="<?= $zoneId ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">×</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$panels): ?>
                        <tr><td colspan="6" class="text-muted">No panels on this zone.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body">
                <h3 class="mt-0">Add panel</h3>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_panel">
                    <input type="hidden" name="zone_id" value="<?= $zoneId ?>">
                    <div class="form-row"><label>Name</label><input class="form-control" name="name" required></div>
                    <div class="form-row"><label>Type</label>
                        <select class="form-control" name="panel_type">
                            <option>main</option><option selected>sub</option><option>busway</option>
                        </select>
                    </div>
                    <div class="form-row"><label>Voltage</label>
                        <input class="form-control" type="number" name="voltage" value="<?= App::e((string)($zone['voltage'] ?? 208)) ?>"></div>
                    <div class="form-row"><label>Phases</label>
                        <select class="form-control" name="phases">
                            <option value="1">1</option><option value="2">2</option><option value="3" selected>3</option>
                        </select>
                    </div>
                    <div class="form-row"><label>Main breaker (A)</label>
                        <input class="form-control" type="number" name="main_breaker_amps"></div>
                    <div class="form-row"><label>Poles</label>
                        <input class="form-control" type="number" name="num_poles" value="42"></div>
                    <div class="form-row"><button class="btn btn-primary" type="submit">Add panel</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Cabinet rows in this zone</h2></div>
        <div class="card-body">
            <p class="text-muted" style="font-size:.85rem;margin-top:0">
                Assign cabinet rows so breaker pigtails and zone views can reach every rack on the feed
                (e.g. a Row&nbsp;A PDU feeding cabinets in Row&nbsp;B).
            </p>
        </div>
        <div class="card-body flush">
            <table class="data">
                <thead><tr><th>Row</th><th>Room</th><th>Cabinets</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($zoneRows as $r): ?>
                    <tr>
                        <td>
                            <a href="<?= App::e(App::url('pages/cabinets.php?row_id=' . (int)$r['row_id'])) ?>">
                                <strong><?= App::e($r['name']) ?></strong>
                            </a>
                        </td>
                        <td><?= App::e($r['room_name'] ?? '—') ?></td>
                        <td><?= (int)($r['cabinet_count'] ?? 0) ?></td>
                        <td class="actions">
                            <?php if (AuthManager::canEditPower($user)): ?>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('Remove this row from the zone? Cabinets stay in the row.');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="unassign_row">
                                <input type="hidden" name="zone_id" value="<?= $zoneId ?>">
                                <input type="hidden" name="row_id" value="<?= (int)$r['row_id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit" title="Unassign row">×</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$zoneRows): ?>
                    <tr><td colspan="4" class="text-muted">No rows assigned yet — add them below.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (AuthManager::canEditPower($user)): ?>
        <div class="card-body">
            <h3 class="mt-0">Assign rows</h3>
            <?php if ($assignableRows): ?>
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="assign_rows">
                <input type="hidden" name="zone_id" value="<?= $zoneId ?>">
                <div class="form-row full"><label>Rows (same data center)</label>
                    <select class="form-control" name="row_ids[]" multiple size="<?= min(10, max(4, count($assignableRows))) ?>"
                            style="min-height:7rem">
                        <?php foreach ($assignableRows as $ar):
                            $lab = trim(($ar['room_name'] ?? '') . ' / ' . ($ar['name'] ?? ''), ' /');
                            if ($lab === '') {
                                $lab = 'Row #' . (int)$ar['row_id'];
                            }
                            $lab .= ' (' . (int)($ar['cabinet_count'] ?? 0) . ' cab)';
                            if (!empty($ar['other_zone_name'])) {
                                $lab .= ' · currently ' . $ar['other_zone_name'];
                            } else {
                                $lab .= ' · unassigned';
                            }
                            ?>
                            <option value="<?= (int)$ar['row_id'] ?>"><?= App::e($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:.75rem;margin:.35rem 0 0">
                        Ctrl/Cmd+click to select multiple. Reassigning a row moves it from its current zone.
                    </p>
                </div>
                <div class="form-row">
                    <button class="btn btn-primary" type="submit">Assign selected rows</button>
                </div>
            </form>
            <?php else: ?>
                <p class="text-muted mb-0">All rows in this data center are already on this zone.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>PDUs on this zone</h2>
            <a class="btn btn-sm btn-primary" href="<?= App::e(App::url('pages/power_pdus.php?zone_id=' . $zoneId . '#add-pdu')) ?>">+ PDU</a>
        </div>
        <div class="card-body flush">
            <table class="data">
                <thead><tr><th>Name</th><th>Scope</th><th>Cabinet</th><th>Load</th><th>SNMP</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($zonePdus as $p): ?>
                    <tr>
                        <td><?= App::e($p['name']) ?></td>
                        <td><span class="badge"><?= App::e($p['pdu_scope'] ?? 'rack') ?></span></td>
                        <td><?= App::e($p['cabinet_name'] ?? '—') ?></td>
                        <td><?= $p['last_poll_watts'] !== null ? number_format((float)$p['last_poll_watts'] / 1000, 2) . ' kW' : '—' ?></td>
                        <td><?= !empty($p['snmp_enabled']) ? 'v' . App::e((string)$p['snmp_version']) : '—' ?></td>
                        <td class="actions">
                            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php?id=' . (int)$p['pdu_id'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$zonePdus): ?>
                    <tr><td colspan="6" class="text-muted">No PDUs linked to this zone.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_footer();
    exit;
}

// List + add
layout_header('Power Zones', $user, 'power_zones');
?>
<div class="flex-between mb-2">
    <p class="text-muted mb-0">Define power zones (feeds), capacity limits, and electrical panels.</p>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">← Dashboard</a>
        <a class="btn btn-primary" href="#add-zone">+ Zone</a>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>All zones</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Color</th><th>Name</th><th>DC</th><th>Feed</th><th>Voltage</th>
                <th>Load / Cap</th><th>PDUs</th><th>Panels</th><th>Rows</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($zones as $z):
                $color = power_normalize_color($z['color_hex'] ?? null);
                $pollKw = ((float)($z['poll_watts'] ?? 0)) / 1000.0;
                $maxKw = $z['max_kw'] !== null && $z['max_kw'] !== '' ? (float)$z['max_kw'] : null;
                $pct = ($maxKw && $maxKw > 0) ? min(100, round(100 * $pollKw / $maxKw, 1)) : null;
                ?>
                <tr>
                    <td><span class="dept-swatch" style="background:<?= App::e($color) ?>"></span></td>
                    <td><a href="?id=<?= (int)$z['zone_id'] ?>"><strong><?= App::e($z['name']) ?></strong></a></td>
                    <td><?= App::e($z['dc_name']) ?></td>
                    <td><span class="badge"><?= App::e((string)$z['feed_type']) ?></span></td>
                    <td><?= $z['voltage'] !== null ? (int)$z['voltage'] . ' V' : '—' ?></td>
                    <td style="min-width:9rem">
                        <?= number_format($pollKw, 1) ?> kW
                        <?php if ($maxKw !== null): ?>
                            <span class="text-muted">/ <?= number_format($maxKw, 1) ?></span>
                            <div class="util-bar" style="margin-top:.25rem">
                                <div class="util-bar-fill util-<?= App::e(power_util_class((float)($pct ?? 0))) ?>"
                                     style="width:<?= (float)($pct ?? 0) ?>%"></div>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$z['pdu_count'] ?></td>
                    <td><?= (int)$z['panel_count'] ?></td>
                    <td><?= (int)($z['row_count'] ?? 0) ?></td>
                    <td class="actions">
                        <a class="btn btn-sm btn-secondary" href="?id=<?= (int)$z['zone_id'] ?>">Manage</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$zones): ?>
                <tr><td colspan="10" class="text-muted">No zones defined yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="add-zone">
    <div class="card-header"><h2>Add power zone</h2></div>
    <div class="card-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="add_zone">
            <div class="form-row"><label>Data center</label>
                <select class="form-control" name="datacenter_id" required>
                    <?php foreach ($dcs as $d): ?>
                        <option value="<?= (int)$d['datacenter_id'] ?>"><?= App::e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Name</label>
                <input class="form-control" name="name" required placeholder="Zone A / UPS-1"></div>
            <div class="form-row"><label>Feed</label>
                <select class="form-control" name="feed_type">
                    <option>A</option><option>B</option><option>dual</option>
                </select>
            </div>
            <div class="form-row"><label>Color</label>
                <input class="form-control" type="color" name="color_hex" value="#ef4444"></div>
            <div class="form-row"><label>Voltage</label>
                <input class="form-control" type="number" name="voltage" value="208"></div>
            <div class="form-row"><label>Max kW</label>
                <input class="form-control" type="number" step="0.1" name="max_kw" placeholder="Capacity budget"></div>
            <div class="form-row"><label>Max amps</label>
                <input class="form-control" type="number" step="0.1" name="max_amps"></div>
            <div class="form-row full"><label>Description</label>
                <input class="form-control" name="description" placeholder="Optional"></div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Create zone</button></div>
        </form>
    </div>
</div>
<?php layout_footer(); ?>
