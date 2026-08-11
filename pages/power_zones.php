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
            (SELECT COUNT(*) FROM ups_units u WHERE u.zone_id = z.zone_id AND u.is_active = 1) AS ups_count,
            (SELECT AVG(CAST(u.last_load_pct AS FLOAT)) FROM ups_units u
             WHERE u.zone_id = z.zone_id AND u.is_active = 1 AND u.last_load_pct IS NOT NULL) AS ups_avg_load,
            (SELECT MIN(u.last_battery_pct) FROM ups_units u
             WHERE u.zone_id = z.zone_id AND u.is_active = 1 AND u.last_battery_pct IS NOT NULL) AS ups_min_batt
     FROM power_zones z
     INNER JOIN datacenters dc ON dc.datacenter_id = z.datacenter_id
     ORDER BY dc.name, z.name'
);
// Zone load + capacity snapshots (rollup, free U, phase imbalance)
$allPdusForRollup = [];
$zoneUById = [];
try {
    $allPdusForRollup = Database::fetchAll(
        'SELECT pdu_id, zone_id, pdu_scope, include_in_site_load, last_poll_watts,
                last_poll_phases, rated_amps, phases
         FROM pdus WHERE is_active = 1'
    );
} catch (Throwable $e) {
    try {
        $allPdusForRollup = Database::fetchAll(
            'SELECT pdu_id, zone_id, pdu_scope, include_in_site_load, last_poll_watts
             FROM pdus WHERE is_active = 1'
        );
    } catch (Throwable $e2) {
        $allPdusForRollup = [];
    }
}
try {
    $zoneUById = power_all_zones_u_capacity();
} catch (Throwable $e) {
    $zoneUById = [];
}
foreach ($zones as &$zRoll) {
    $zid = (int)$zRoll['zone_id'];
    try {
        $cap = power_zone_capacity_snapshot(
            $allPdusForRollup,
            $zRoll,
            $zoneUById[$zid] ?? ['u_total' => 0, 'u_used' => 0, 'free_u' => 0, 'cabinets' => 0]
        );
        $zRoll['poll_watts'] = $cap['load_kw'] * 1000.0;
        $zRoll['capacity'] = $cap;
    } catch (Throwable $e) {
        $zt = power_zone_load_totals($allPdusForRollup, $zid);
        $zRoll['poll_watts'] = $zt['watts'];
        $zRoll['capacity'] = null;
    }
}
unset($zRoll);

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
    $zonePdus = power_natural_sort_rows(Database::fetchAll(
        'SELECT p.*, c.name AS cabinet_name
         FROM pdus p
         LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
         WHERE p.zone_id = ? AND p.is_active = 1
         ORDER BY p.name',
        [$zoneId]
    ), 'name');
    $zoneUps = [];
    try {
        require_once dirname(__DIR__) . '/includes/ups_helpers.php';
        $zoneUps = power_natural_sort_rows(Database::fetchAll(
            'SELECT u.ups_id, u.name, u.ups_scope, u.manufacturer, u.model, u.primary_ip,
                    u.last_output_status, u.last_load_pct, u.last_battery_pct, u.last_runtime_min,
                    u.rated_kva, u.rated_kw, u.snmp_enabled, u.snmp_last_poll_at, u.last_poll_json
             FROM ups_units u
             WHERE u.zone_id = ? AND u.is_active = 1
             ORDER BY u.name',
            [$zoneId]
        ), 'name');
        foreach ($zoneUps as &$zu) {
            $zu['health'] = function_exists('ups_health_status') ? ups_health_status($zu) : 'unknown';
        }
        unset($zu);
    } catch (Throwable $e) {
        $zoneUps = [];
    }
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
    $cap = is_array($zone['capacity'] ?? null) ? $zone['capacity'] : null;
    $pollKw = $cap ? (float)$cap['load_kw'] : (((float)($zone['poll_watts'] ?? 0)) / 1000.0);
    $maxKw = $cap && $cap['max_kw'] !== null
        ? (float)$cap['max_kw']
        : ($zone['max_kw'] !== null && $zone['max_kw'] !== '' ? (float)$zone['max_kw'] : null);
    $pct = $cap['util_pct'] ?? (($maxKw && $maxKw > 0) ? min(100, round(100 * $pollKw / $maxKw, 1)) : null);
    $freeKw = $cap['free_kw'] ?? (($maxKw !== null) ? max(0.0, $maxKw - $pollKw) : null);
    $freeU = $cap ? (int)$cap['free_u'] : 0;
    $uTotal = $cap ? (int)$cap['u_total'] : 0;
    $uUsed = $cap ? (int)$cap['u_used'] : 0;
    $phaseAmps = $cap['phase_amps'] ?? ['L1' => null, 'L2' => null, 'L3' => null];
    $imbalance = $cap['imbalance'] ?? ['imbalanced' => false, 'label' => '—', 'pct' => null];
    $imbalanced = !empty($imbalance['imbalanced']);
    $color = power_normalize_color($zone['color_hex'] ?? null);
    $canEditZone = AuthManager::canEditPower($user);

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
        <div class="flex gap-1" style="flex-wrap:wrap">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_zones.php')) ?>">← All zones</a>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">Dashboard</a>
            <?php if ($canEditZone): ?>
                <button type="button" class="btn btn-primary" data-open-modal="modal-edit-zone">Edit zone</button>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php?zone_id=' . $zoneId)) ?>">Zone PDUs</a>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_ups.php')) ?>">UPS</a>
        </div>
    </div>

    <?php
    $zoneUpsCount = count($zoneUps);
    $zoneUpsAvgLoad = null;
    $zoneUpsMinBatt = null;
    if ($zoneUpsCount > 0) {
        $ls = [];
        $bs = [];
        foreach ($zoneUps as $zu) {
            if ($zu['last_load_pct'] !== null && $zu['last_load_pct'] !== '') {
                $ls[] = (float)$zu['last_load_pct'];
            }
            if ($zu['last_battery_pct'] !== null && $zu['last_battery_pct'] !== '') {
                $bs[] = (float)$zu['last_battery_pct'];
            }
        }
        if ($ls) {
            $zoneUpsAvgLoad = round(array_sum($ls) / count($ls), 1);
        }
        if ($bs) {
            $zoneUpsMinBatt = min($bs);
        }
    }
    ?>
    <div class="metrics">
        <div class="metric-card warning">
            <div class="label">Load</div>
            <div class="value"><?= number_format($pollKw, 1) ?> <span class="metric-unit">kW</span></div>
            <div class="sub"><?= $pct !== null ? $pct . '% of capacity' : 'No max kW' ?></div>
        </div>
        <div class="metric-card <?= $freeKw !== null && $maxKw !== null && $freeKw < max(1.0, $maxKw * 0.1) ? 'warning' : 'success' ?>">
            <div class="label">Free power</div>
            <div class="value">
                <?= $freeKw !== null ? number_format((float)$freeKw, 1) . ' <span class="metric-unit">kW</span>' : '—' ?>
            </div>
            <div class="sub"><?= $maxKw !== null ? 'of ' . number_format($maxKw, 1) . ' kW max' : 'Set max kW on zone' ?></div>
        </div>
        <div class="metric-card">
            <div class="label">Free U</div>
            <div class="value"><?= (int)$freeU ?> <span class="metric-unit">U</span></div>
            <div class="sub"><?= (int)$uUsed ?> used / <?= (int)$uTotal ?> total · rows on zone</div>
        </div>
        <div class="metric-card <?= $imbalanced ? 'warning' : 'accent' ?>">
            <div class="label">Phases</div>
            <div class="value" style="font-size:1rem"><?= App::e(power_format_phase_amps($phaseAmps)) ?></div>
            <div class="sub">
                <?php if ($imbalanced): ?>
                    <span class="badge badge-warning"><?= App::e((string)$imbalance['label']) ?></span>
                <?php else: ?>
                    <?= App::e((string)($imbalance['label'] ?? '—')) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="metric-card accent">
            <div class="label">Voltage</div>
            <div class="value"><?= $zone['voltage'] !== null ? (int)$zone['voltage'] : '—' ?> <span class="metric-unit">V</span></div>
        </div>
        <div class="metric-card">
            <div class="label">PDUs / Panels / Rows</div>
            <div class="value"><?= (int)$zone['pdu_count'] ?> <span class="metric-unit">/ <?= (int)$zone['panel_count'] ?> / <?= count($zoneRows) ?></span></div>
        </div>
        <div class="metric-card <?= $zoneUpsCount && $zoneUpsAvgLoad !== null && $zoneUpsAvgLoad >= 80 ? 'warning' : '' ?>">
            <div class="label">UPS</div>
            <div class="value"><?= (int)$zoneUpsCount ?></div>
            <div class="sub">
                <?php if ($zoneUpsCount < 1): ?>
                    None assigned
                <?php else: ?>
                    <?= $zoneUpsAvgLoad !== null ? 'load ' . App::e((string)$zoneUpsAvgLoad) . '%' : '—' ?>
                    <?php if ($zoneUpsMinBatt !== null): ?>
                        · batt min <?= App::e((string)$zoneUpsMinBatt) ?>%
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($pct !== null): ?>
    <div class="card power-capacity-banner mb-2">
        <div class="card-body power-capacity-body">
            <div class="power-capacity-meta">
                <strong>Zone capacity</strong>
                <span class="text-muted">
                    <?= number_format($pollKw, 1) ?> kW used ·
                    <?= $freeKw !== null ? number_format((float)$freeKw, 1) . ' kW free' : '—' ?>
                    · <?= (int)$freeU ?> U free
                    <?php if ($imbalanced): ?>
                        · <span class="badge badge-warning">Phase imbalance</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="util-bar util-bar-lg">
                <div class="util-bar-fill util-<?= App::e(power_util_class((float)$pct)) ?>"
                     style="width:<?= min(100, (float)$pct) ?>%"></div>
            </div>
            <div class="util-bar-label"><?= App::e((string)$pct) ?>%</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($pct !== null): ?>
        <div class="util-bar util-bar-lg mb-2">
            <div class="util-bar-fill util-<?= App::e(power_util_class((float)$pct)) ?>" style="width:<?= $pct ?>%"></div>
        </div>
    <?php endif; ?>

    <!-- Zone PDU + UPS history -->
    <div class="power-dash-grid mb-2" style="grid-template-columns:1fr 1fr;gap:1rem">
        <div class="card power-history-wide" data-power-history data-scope="zone" data-id="<?= (int)$zoneId ?>" data-hours="24">
            <div class="card-header flex-between">
                <h2 style="margin:0;font-size:1.05rem">PDU load — 24h</h2>
                <span class="text-muted" style="font-size:.8rem">Zone PDUs · red = outages</span>
            </div>
            <div class="card-body power-history-body">
                <div class="power-outage-summary" data-outage-summary hidden></div>
                <div class="power-chart power-chart-lg" data-metric="kw" data-unit="kW" data-label="Output (usage)" data-color="#38bdf8" data-height="200"></div>
                <div class="power-chart power-chart-lg" data-metric="volts" data-unit="V" data-label="Input voltage (avg L–N)" data-color="#a78bfa" data-height="140" data-hide-empty="1"></div>
            </div>
        </div>
        <div class="card power-history-wide" data-power-history data-scope="ups_zone" data-id="<?= (int)$zoneId ?>" data-hours="24">
            <div class="card-header flex-between">
                <h2 style="margin:0;font-size:1.05rem">UPS load — 24h</h2>
                <span class="text-muted" style="font-size:.8rem">UPS on this zone · poll to fill</span>
            </div>
            <div class="card-body power-history-body">
                <div class="power-chart power-chart-lg" data-metric="load_pct" data-unit="%" data-label="UPS load %" data-color="#a78bfa" data-height="160" data-outages="0"></div>
                <div class="power-chart power-chart-lg" data-metric="battery_pct" data-unit="%" data-label="Battery %" data-color="#34d399" data-height="120" data-outages="0" data-hide-empty="1"></div>
            </div>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-header flex-between">
            <h2>Overview</h2>
            <?php if ($canEditZone): ?>
                <button type="button" class="btn btn-sm btn-secondary" data-open-modal="modal-edit-zone">Edit properties</button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <dl class="pdu-summary-grid">
                <div>
                    <dt>Data center</dt>
                    <dd><?= App::e($zone['dc_name'] ?? '—') ?></dd>
                </div>
                <div>
                    <dt>Feed</dt>
                    <dd><span class="badge"><?= App::e((string)($zone['feed_type'] ?? '—')) ?></span></dd>
                </div>
                <div>
                    <dt>Voltage</dt>
                    <dd><?= $zone['voltage'] !== null ? (int)$zone['voltage'] . ' V' : '—' ?></dd>
                </div>
                <div>
                    <dt>Max capacity</dt>
                    <dd>
                        <?php if ($maxKw !== null): ?>
                            <?= number_format($maxKw, 1) ?> kW
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                        <?php if ($zone['max_amps'] !== null && $zone['max_amps'] !== ''): ?>
                            · <?= App::e((string)$zone['max_amps']) ?> A
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if (!empty($zone['description'])): ?>
                <div style="grid-column:1 / -1">
                    <dt>Description</dt>
                    <dd><?= App::e((string)$zone['description']) ?></dd>
                </div>
                <?php endif; ?>
                <?php if (!empty($zone['notes'])): ?>
                <div style="grid-column:1 / -1">
                    <dt>Notes</dt>
                    <dd><?= App::e((string)$zone['notes']) ?></dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <div class="split-2">
        <div class="card">
            <div class="card-header flex-between">
                <h2>Panels</h2>
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                    <span class="text-muted" style="font-size:.85rem"><?= count($panels) ?></span>
                    <?php if ($canEditZone): ?>
                        <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-add-panel">Add panel</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body flush">
                <table class="data">
                    <thead><tr><th>Name</th><th>Type</th><th>V</th><th>φ</th><th>Breaker</th><th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($panels as $p): ?>
                        <tr>
                            <td><strong><?= App::e($p['name']) ?></strong></td>
                            <td><?= App::e($p['panel_type'] ?? '—') ?></td>
                            <td><?= App::e((string)($p['voltage'] ?? '—')) ?></td>
                            <td><?= (int)($p['phases'] ?? 3) ?></td>
                            <td><?= $p['main_breaker_amps'] !== null ? App::e((string)$p['main_breaker_amps']) . ' A' : '—' ?></td>
                            <td class="actions col-actions">
                                <?php if ($canEditZone): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Remove panel?');">
                                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_panel">
                                    <input type="hidden" name="panel_id" value="<?= (int)$p['panel_id'] ?>">
                                    <input type="hidden" name="zone_id" value="<?= $zoneId ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">×</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$panels): ?>
                        <tr>
                            <td colspan="6" class="text-muted">
                                No panels yet.
                                <?php if ($canEditZone): ?> Use <strong>Add panel</strong>.<?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header flex-between">
                <h2>Cabinet rows</h2>
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                    <span class="text-muted" style="font-size:.85rem"><?= count($zoneRows) ?></span>
                    <?php if ($canEditZone && $assignableRows): ?>
                        <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-assign-rows">Assign rows</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body" style="padding-bottom:.35rem">
                <p class="text-muted mb-0" style="font-size:.85rem">
                    Rows on this feed so breaker pigtails and zone views reach every rack
                    (e.g. a Row&nbsp;A PDU feeding cabinets in Row&nbsp;B).
                </p>
            </div>
            <div class="card-body flush">
                <table class="data">
                    <thead><tr><th>Row</th><th>Room</th><th>Cabinets</th><th class="col-actions"></th></tr></thead>
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
                            <td class="actions col-actions">
                                <?php if ($canEditZone): ?>
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
                        <tr>
                            <td colspan="4" class="text-muted">
                                No rows assigned.
                                <?php if ($canEditZone && $assignableRows): ?>
                                    Use <strong>Assign rows</strong>.
                                <?php elseif ($canEditZone): ?>
                                    All rows in this data center are already on this zone.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header flex-between">
            <h2>PDUs on this zone</h2>
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                <span class="text-muted" style="font-size:.85rem"><?= count($zonePdus) ?></span>
                <?php if ($canEditZone): ?>
                    <a class="btn btn-sm btn-primary" href="<?= App::e(App::url('pages/power_pdus.php?zone_id=' . $zoneId . '#add-pdu')) ?>">+ PDU</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body flush">
            <table class="data">
                <thead><tr><th>Name</th><th>Scope</th><th>Cabinet</th><th>Load</th><th>SNMP</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($zonePdus as $p): ?>
                    <tr>
                        <td>
                            <a href="<?= App::e(App::url('pages/power_pdus.php?id=' . (int)$p['pdu_id'])) ?>">
                                <strong><?= App::e($p['name']) ?></strong>
                            </a>
                        </td>
                        <td><span class="badge"><?= App::e($p['pdu_scope'] ?? 'rack') ?></span></td>
                        <td><?= App::e($p['cabinet_name'] ?? '—') ?></td>
                        <td><?= $p['last_poll_watts'] !== null ? number_format((float)$p['last_poll_watts'] / 1000, 2) . ' kW' : '—' ?></td>
                        <td><?= !empty($p['snmp_enabled']) ? '<span class="badge badge-success">v' . App::e((string)$p['snmp_version']) . '</span>' : '—' ?></td>
                        <td class="actions col-actions">
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

    <div class="card mb-2">
        <div class="card-header flex-between">
            <h2>UPS on this zone</h2>
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                <span class="text-muted" style="font-size:.85rem"><?= count($zoneUps) ?></span>
                <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_ups.php')) ?>">All UPS</a>
                <?php if ($canEditZone): ?>
                    <a class="btn btn-sm btn-primary" href="<?= App::e(App::url('pages/power_ups.php?action=new')) ?>">+ UPS</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body flush">
            <table class="data">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Scope</th>
                    <th>Status</th>
                    <th>Load</th>
                    <th>Battery</th>
                    <th>Runtime</th>
                    <th>Health</th>
                    <th class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($zoneUps as $zu):
                    $hp = (string)($zu['health'] ?? 'unknown');
                    ?>
                    <tr class="<?= in_array($hp, ['warn', 'crit'], true) ? 'health-row-' . App::e($hp) : '' ?>">
                        <td>
                            <a href="<?= App::e(App::url('pages/power_ups.php?id=' . (int)$zu['ups_id'])) ?>">
                                <strong><?= App::e((string)$zu['name']) ?></strong>
                            </a>
                            <?php
                            $m = trim(($zu['manufacturer'] ?? '') . ' ' . ($zu['model'] ?? ''));
                            if ($m !== ''): ?>
                                <div class="text-muted" style="font-size:.75rem"><?= App::e($m) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge"><?= App::e((string)($zu['ups_scope'] ?? 'in_row')) ?></span></td>
                        <td><?= App::e((string)($zu['last_output_status'] ?? '—')) ?></td>
                        <td><?= $zu['last_load_pct'] !== null ? App::e((string)$zu['last_load_pct']) . '%' : '—' ?></td>
                        <td><?= $zu['last_battery_pct'] !== null ? App::e((string)$zu['last_battery_pct']) . '%' : '—' ?></td>
                        <td><?= $zu['last_runtime_min'] !== null ? App::e((string)$zu['last_runtime_min']) . ' min' : '—' ?></td>
                        <td>
                            <span class="health-chip health-chip-<?= App::e($hp) ?>">
                                <span class="health-pulse health-pulse-<?= App::e($hp) ?>" aria-hidden="true"></span>
                                <span class="health-chip-label"><?= App::e($hp) ?></span>
                            </span>
                        </td>
                        <td class="actions col-actions">
                            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_ups.php?id=' . (int)$zu['ups_id'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$zoneUps): ?>
                    <tr>
                        <td colspan="8" class="text-muted">
                            No UPS linked to this zone.
                            Assign a power zone on the UPS edit form (Power → UPS).
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canEditZone): ?>
    <!-- Edit zone modal -->
    <div class="app-modal" id="modal-edit-zone" hidden aria-hidden="true">
        <div class="app-modal-backdrop" data-modal-close></div>
        <div class="app-modal-panel app-modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="modal-edit-zone-title">
            <div class="app-modal-head">
                <h3 id="modal-edit-zone-title">Edit zone — <?= App::e($zone['name']) ?></h3>
                <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
            </div>
            <div class="app-modal-body">
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
                    <div class="form-row full app-modal-actions">
                        <button class="btn btn-primary" type="submit">Save zone</button>
                        <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add panel modal -->
    <div class="app-modal" id="modal-add-panel" hidden aria-hidden="true">
        <div class="app-modal-backdrop" data-modal-close></div>
        <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-add-panel-title">
            <div class="app-modal-head">
                <h3 id="modal-add-panel-title">Add panel</h3>
                <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
            </div>
            <div class="app-modal-body">
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
                    <div class="form-row full app-modal-actions">
                        <button class="btn btn-primary" type="submit">Add panel</button>
                        <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($assignableRows): ?>
    <!-- Assign rows modal -->
    <div class="app-modal" id="modal-assign-rows" hidden aria-hidden="true">
        <div class="app-modal-backdrop" data-modal-close></div>
        <div class="app-modal-panel app-modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="modal-assign-rows-title">
            <div class="app-modal-head">
                <h3 id="modal-assign-rows-title">Assign rows</h3>
                <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
            </div>
            <div class="app-modal-body">
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
                    <div class="form-row full app-modal-actions">
                        <button class="btn btn-primary" type="submit">Assign selected rows</button>
                        <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    (function () {
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
                openModal(btn.getAttribute('data-open-modal'));
            });
        });
        document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeModal(btn.closest('.app-modal'));
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var open = document.querySelector('.app-modal:not([hidden])');
            if (open) closeModal(open);
        });
        if (location.hash === '#edit') {
            openModal('modal-edit-zone');
        }
    })();
    </script>
    <?php endif; ?>
    <script src="<?= App::e(App::url('assets/js/power-charts.js')) ?>?v=6"></script>
    <?php
    layout_footer();
    exit;
}

// List
$canEditZone = AuthManager::canEditPower($user);
$listTotalKw = 0.0;
$listMaxKw = 0.0;
$listHasMax = false;
$listPduCount = 0;
foreach ($zones as $z) {
    $listTotalKw += ((float)($z['poll_watts'] ?? 0)) / 1000.0;
    $listPduCount += (int)($z['pdu_count'] ?? 0);
    if ($z['max_kw'] !== null && $z['max_kw'] !== '') {
        $listMaxKw += (float)$z['max_kw'];
        $listHasMax = true;
    }
}

layout_header('Power Zones', $user, 'power_zones');
?>
<div class="flex-between mb-2">
    <p class="text-muted mb-0">Power zones (feeds), capacity limits, and electrical panels.</p>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">← Dashboard</a>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php')) ?>">PDUs</a>
        <?php if ($canEditZone): ?>
            <button type="button" class="btn btn-primary" data-open-modal="modal-add-zone" id="add-zone">+ Zone</button>
        <?php endif; ?>
    </div>
</div>

<div class="metrics">
    <div class="metric-card">
        <div class="label">Zones</div>
        <div class="value"><?= count($zones) ?></div>
        <div class="sub"><?= $listPduCount ?> PDUs linked</div>
    </div>
    <div class="metric-card warning">
        <div class="label">Polled load</div>
        <div class="value"><?= number_format($listTotalKw, 1) ?> <span class="metric-unit">kW</span></div>
        <div class="sub">across all zones</div>
    </div>
    <div class="metric-card accent">
        <div class="label">Capacity budget</div>
        <div class="value"><?= $listHasMax ? number_format($listMaxKw, 1) . ' <span class="metric-unit">kW</span>' : '—' ?></div>
        <div class="sub"><?= $listHasMax ? 'sum of zone max kW' : 'set max kW on zones' ?></div>
    </div>
</div>

<?php if ($zones): ?>
<div class="card mb-2">
    <div class="card-header flex-between">
        <h2>Zones — 24h glance</h2>
        <span class="text-muted" style="font-size:.8rem">Usage + input voltage per zone</span>
    </div>
    <div class="card-body">
        <div class="zone-cards zone-cards-history">
            <?php foreach ($zones as $z):
                $color = power_normalize_color($z['color_hex'] ?? null);
                $pollKw = ((float)($z['poll_watts'] ?? 0)) / 1000.0;
                $maxKw = $z['max_kw'] !== null && $z['max_kw'] !== '' ? (float)$z['max_kw'] : null;
                $pct = ($maxKw && $maxKw > 0) ? min(100, round(100 * $pollKw / $maxKw, 1)) : null;
                $cls = $pct !== null ? power_util_class((float)$pct) : '';
                $zid = (int)$z['zone_id'];
                ?>
                <div class="zone-card zone-card-static" style="--zone-color: <?= App::e($color) ?>">
                    <a class="zone-card-link" href="<?= App::e(App::url('pages/power_zones.php?id=' . $zid)) ?>">
                        <div class="zone-card-top">
                            <span class="zone-swatch" style="background:<?= App::e($color) ?>"></span>
                            <div class="zone-card-title">
                                <strong><?= App::e($z['name']) ?></strong>
                                <span class="text-muted"><?= App::e($z['dc_name'] ?? '') ?> · Feed <?= App::e((string)($z['feed_type'] ?? '—')) ?></span>
                            </div>
                            <span class="badge"><?= (int)($z['pdu_count'] ?? 0) ?> PDU · <?= (int)($z['ups_count'] ?? 0) ?> UPS</span>
                        </div>
                        <?php
                        $zCap = is_array($z['capacity'] ?? null) ? $z['capacity'] : null;
                        $zFreeKw = $zCap['free_kw'] ?? null;
                        $zFreeU = $zCap ? (int)$zCap['free_u'] : 0;
                        $zImb = !empty($zCap['imbalanced']);
                        $zPhase = $zCap['phase_amps'] ?? [];
                        ?>
                        <div class="zone-card-metrics">
                            <div>
                                <span class="zcm-label">Load</span>
                                <span class="zcm-val"><?= number_format($pollKw, 1) ?> kW</span>
                            </div>
                            <div>
                                <span class="zcm-label">Free</span>
                                <span class="zcm-val"><?= $zFreeKw !== null ? number_format((float)$zFreeKw, 1) . ' kW' : '—' ?></span>
                            </div>
                            <div>
                                <span class="zcm-label">Free U</span>
                                <span class="zcm-val"><?= (int)$zFreeU ?> U</span>
                            </div>
                            <?php if ($pct !== null): ?>
                            <div>
                                <span class="zcm-label">Util</span>
                                <span class="zcm-val"><?= $pct ?>%</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="zone-card-util text-muted" style="font-size:.78rem;margin-top:.25rem">
                            <?= App::e(power_format_phase_amps($zPhase)) ?>
                            <?php if ($zImb): ?>
                                · <span class="badge badge-warning" style="font-size:.68rem">Imbalanced</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($pct !== null): ?>
                            <div class="util-bar" style="margin-top:.35rem">
                                <div class="util-bar-fill util-<?= App::e($cls) ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                        <?php endif; ?>
                    </a>
                    <div class="zone-card-charts" data-power-history data-scope="zone" data-id="<?= $zid ?>" data-hours="24">
                        <div class="power-chart power-chart-sm" data-metric="kw" data-unit="kW" data-label="PDU 24h" data-color="#38bdf8" data-height="72"></div>
                    </div>
                    <div class="zone-card-charts" data-power-history data-scope="ups_zone" data-id="<?= $zid ?>" data-hours="24">
                        <div class="power-chart power-chart-sm" data-metric="load_pct" data-unit="%" data-label="UPS load 24h" data-color="#a78bfa" data-height="64" data-outages="0"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header flex-between">
        <h2>All zones</h2>
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
            <span class="text-muted" style="font-size:.85rem"><?= count($zones) ?> total</span>
            <?php if ($canEditZone): ?>
                <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-add-zone">Add zone</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th class="col-swatch"></th><th>Name</th><th>DC</th><th>Feed</th><th>Voltage</th>
                <th>Load / Cap</th><th>PDUs</th><th>UPS</th><th>Panels</th><th>Rows</th><th class="col-actions"></th>
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
                    <td class="col-swatch"><span class="dept-swatch" style="background:<?= App::e($color) ?>"></span></td>
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
                    <td>
                        <?= (int)($z['ups_count'] ?? 0) ?>
                        <?php if ($z['ups_avg_load'] !== null && $z['ups_avg_load'] !== ''): ?>
                            <span class="text-muted" style="font-size:.75rem"> · <?= number_format((float)$z['ups_avg_load'], 0) ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$z['panel_count'] ?></td>
                    <td><?= (int)($z['row_count'] ?? 0) ?></td>
                    <td class="actions col-actions">
                        <a class="btn btn-sm btn-secondary" href="?id=<?= (int)$z['zone_id'] ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$zones): ?>
                <tr>
                    <td colspan="11" class="text-muted">
                        No zones yet.
                        <?php if ($canEditZone): ?> Use <strong>Add zone</strong> to create one.<?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canEditZone): ?>
<div class="app-modal" id="modal-add-zone" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel app-modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="modal-add-zone-title">
        <div class="app-modal-head">
            <h3 id="modal-add-zone-title">Add power zone</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
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
                <div class="form-row full app-modal-actions">
                    <button class="btn btn-primary" type="submit">Create zone</button>
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
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
            openModal(btn.getAttribute('data-open-modal'));
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.closest('.app-modal'));
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('.app-modal:not([hidden])');
        if (open) closeModal(open);
    });
    if (location.hash === '#add-zone') {
        openModal('modal-add-zone');
    }
})();
</script>
<?php endif; ?>
<script src="<?= App::e(App::url('assets/js/power-charts.js')) ?>?v=6"></script>
<?php layout_footer(); ?>
