<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/power_helpers.php';
require_once dirname(__DIR__) . '/includes/ups_helpers.php';
require_once dirname(__DIR__) . '/includes/snmp_helpers.php';
App::boot();
$user = App::requirePermission('view_power');

// Handle facility rollup mode save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_site_load_mode') {
    try {
        if (!AuthManager::canEditPower($user)) {
            App::flash('error', 'You do not have permission to change site load mode.');
            App::redirect('pages/power.php');
        }
        if (!App::verifyCsrf($_POST['_csrf'] ?? '')) {
            App::flash('error', 'Invalid session token. Please try again.');
            App::redirect('pages/power.php');
        }
        $mode = strtolower(trim((string)($_POST['power_site_load_mode'] ?? 'all')));
        if (!in_array($mode, ['all', 'prefer_upstream', 'manual'], true)) {
            $mode = 'all';
        }
        SettingsService::set('power_site_load_mode', $mode, 'power');
        App::flash('success', 'Site load mode updated: ' . (power_site_load_mode_labels()[$mode] ?? $mode));
    } catch (Throwable $e) {
        App::log('set_site_load_mode failed: ' . $e->getMessage(), 'error');
        App::flash('error', 'Could not save site load mode: ' . $e->getMessage());
    }
    App::redirect('pages/power.php');
}

$zones = Database::fetchAll(
    'SELECT z.*, dc.name AS dc_name,
            (SELECT COUNT(*) FROM pdus p WHERE p.zone_id = z.zone_id AND p.is_active = 1) AS pdu_count,
            (SELECT COUNT(*) FROM power_panels pp WHERE pp.zone_id = z.zone_id) AS panel_count,
            (SELECT COUNT(*) FROM ups_units u WHERE u.zone_id = z.zone_id AND u.is_active = 1) AS ups_count,
            (SELECT AVG(CAST(u.last_load_pct AS FLOAT)) FROM ups_units u
             WHERE u.zone_id = z.zone_id AND u.is_active = 1 AND u.last_load_pct IS NOT NULL) AS ups_avg_load,
            (SELECT MIN(u.last_battery_pct) FROM ups_units u
             WHERE u.zone_id = z.zone_id AND u.is_active = 1 AND u.last_battery_pct IS NOT NULL) AS ups_min_batt
     FROM power_zones z
     INNER JOIN datacenters dc ON dc.datacenter_id = z.datacenter_id
     ORDER BY z.name'
);

$pdus = power_natural_sort_rows(Database::fetchAll(
    'SELECT p.*, c.name AS cabinet_name, z.name AS zone_name, z.color_hex AS zone_color,
            r.name AS row_name
     FROM pdus p
     LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
     LEFT JOIN power_zones z ON z.zone_id = p.zone_id
     LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
     WHERE p.is_active = 1
     ORDER BY p.name'
), 'name');

$zoneUById = [];
try {
    $zoneUById = power_all_zones_u_capacity();
} catch (Throwable $e) {
    $zoneUById = [];
}

// Zone polled load + capacity (free kW/U, phase imbalance)
$siteFreeKw = 0.0;
$siteFreeKwKnown = false;
$siteFreeU = 0;
$worstImbalance = null; // [pct, name, zone_id]
$imbalancedZoneCount = 0;
foreach ($zones as &$zrow) {
    $zid = (int)$zrow['zone_id'];
    $zt = power_zone_load_totals($pdus, $zid);
    $zrow['poll_watts'] = $zt['watts'];
    $zrow['poll_pdu_count'] = $zt['count'];
    $zrow['poll_reporting'] = $zt['reporting'];
    try {
        $cap = power_zone_capacity_snapshot(
            $pdus,
            $zrow,
            $zoneUById[$zid] ?? ['u_total' => 0, 'u_used' => 0, 'free_u' => 0, 'cabinets' => 0]
        );
        $zrow['capacity'] = $cap;
        if ($cap['free_kw'] !== null) {
            $siteFreeKw += (float)$cap['free_kw'];
            $siteFreeKwKnown = true;
        }
        $siteFreeU += (int)$cap['free_u'];
        if (!empty($cap['imbalanced'])) {
            $imbalancedZoneCount++;
            $ipct = (float)($cap['imbalance']['pct'] ?? 0);
            if ($worstImbalance === null || $ipct > $worstImbalance['pct']) {
                $worstImbalance = [
                    'pct' => $ipct,
                    'name' => (string)$cap['name'],
                    'zone_id' => $zid,
                ];
            }
        }
    } catch (Throwable $e) {
        $zrow['capacity'] = null;
    }
}
unset($zrow);

$panelCount = (int) Database::fetchValue('SELECT COUNT(*) FROM power_panels');
$snmpOn = count(array_filter($pdus, static fn($p) => !empty($p['snmp_enabled'])));
$upsSnap = ups_dashboard_snapshot(24);
$upsCount = (int)($upsSnap['units'] ?? 0);
$upsHealthCls = '';
if ((int)($upsSnap['health_crit'] ?? 0) > 0 || (int)($upsSnap['on_battery'] ?? 0) > 0) {
    $upsHealthCls = 'danger';
} elseif ((int)($upsSnap['health_warn'] ?? 0) > 0) {
    $upsHealthCls = 'warning';
} elseif ($upsCount > 0 && (int)($upsSnap['health_ok'] ?? 0) > 0) {
    $upsHealthCls = 'success';
}
$siteLoad = power_site_load_totals($pdus);
$siteLoadMode = $siteLoad['mode'];
$siteLoadIds = array_fill_keys($siteLoad['ids'], true);
$withPoll = array_filter($pdus, static function ($p) use ($siteLoadIds) {
    $pid = (int)($p['pdu_id'] ?? 0);
    return $pid > 0 && !empty($siteLoadIds[$pid]) && $p['last_poll_watts'] !== null;
});
$totalKw = $siteLoad['kw'];
$rawAllKw = array_sum(array_map(static fn($p) => (float)($p['last_poll_watts'] ?? 0), $pdus)) / 1000.0;
$capacityKw = 0.0;
$capacityKnown = false;
foreach ($zones as $z) {
    if ($z['max_kw'] !== null && $z['max_kw'] !== '') {
        $capacityKw += (float)$z['max_kw'];
        $capacityKnown = true;
    }
}
$capacityPct = ($capacityKnown && $capacityKw > 0) ? min(100, round(100 * $totalKw / $capacityKw, 1)) : null;

// Sort PDUs by load for "top consumers"
$pdusByLoad = $pdus;
usort($pdusByLoad, static function ($a, $b) {
    return ((float)($b['last_poll_watts'] ?? 0)) <=> ((float)($a['last_poll_watts'] ?? 0));
});
$topPdus = array_slice($pdusByLoad, 0, 8);

// Feed split (A/B)
$feedStats = ['A' => 0, 'B' => 0, 'dual' => 0, 'other' => 0];
foreach ($zones as $z) {
    $ft = strtoupper((string)($z['feed_type'] ?? ''));
    if ($ft === 'A') {
        $feedStats['A']++;
    } elseif ($ft === 'B') {
        $feedStats['B']++;
    } elseif (strtolower((string)($z['feed_type'] ?? '')) === 'dual') {
        $feedStats['dual']++;
    } else {
        $feedStats['other']++;
    }
}

// Scope counts
$scopeCounts = ['rack' => 0, 'row' => 0, 'room' => 0];
foreach ($pdus as $p) {
    $s = strtolower((string)($p['pdu_scope'] ?? 'rack'));
    if (isset($scopeCounts[$s])) {
        $scopeCounts[$s]++;
    } else {
        $scopeCounts['rack']++;
    }
}
$hasRackAndUpstream = ($scopeCounts['rack'] > 0)
    && (($scopeCounts['row'] + $scopeCounts['room']) > 0);
$canEditSiteLoad = AuthManager::canEditPower($user);

$unassignedPdus = count(array_filter($pdus, static fn($p) => empty($p['zone_id'])));
$stalePdus = count(array_filter($pdus, static function ($p) {
    if (empty($p['snmp_enabled']) && empty($p['snmp_auto_poll'])) {
        return false;
    }
    return snmp_poll_is_stale($p['last_poll_at'] ?? null);
}));

$powerPathCounts = [
    'unmapped_psus' => 0,
    'single_feed_devices' => 0,
    'half_maps' => 0,
    'cabinets_no_row_feed' => 0,
];
try {
    if (class_exists('PowerPathService')) {
        $powerPathCounts = PowerPathService::summaryCounts();
    }
} catch (Throwable $e) {
    // keep zeros
}
$powerPathRisk = (int)$powerPathCounts['unmapped_psus']
    + (int)$powerPathCounts['single_feed_devices']
    + (int)$powerPathCounts['half_maps'];

layout_header('Power Dashboard', $user, 'power');
?>

<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0" style="font-size:.92rem">
            High-level power metrics across zones, PDUs, and UPS. Manage details on the sub-pages.
        </p>
    </div>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_zones.php')) ?>">Manage Zones</a>
        <a class="btn btn-primary" href="<?= App::e(App::url('pages/power_pdus.php')) ?>">Manage PDUs</a>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_ups.php')) ?>">UPS<?= $upsCount ? ' (' . (int)$upsCount . ')' : '' ?></a>
    </div>
</div>

<?php if ($hasRackAndUpstream && $siteLoadMode === 'all'): ?>
<div class="alert alert-warning" style="margin-bottom:1rem">
    <strong>Possible double-count:</strong>
    You have both rack PDUs and row/room PDUs.
    Site load currently <em>sums all PDUs</em> (<?= number_format($rawAllKw, 1) ?> kW raw).
    If cabinets are fed by row PDUs, switch to
    <strong>Prefer row / room meters</strong> below so facility totals use distribution meters only.
</div>
<?php endif; ?>

<div class="card mb-2">
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start;justify-content:space-between">
        <div style="flex:1;min-width:16rem">
            <strong>Site load calculation</strong>
            <p class="text-muted mb-0" style="font-size:.85rem;margin-top:.35rem">
                <?= App::e(power_site_load_mode_description($siteLoadMode)) ?>
            </p>
            <p class="text-muted mb-0" style="font-size:.8rem;margin-top:.35rem">
                Active mode:
                <strong><?= App::e(power_site_load_mode_labels()[$siteLoadMode] ?? $siteLoadMode) ?></strong>
                · counting <?= (int)$siteLoad['count'] ?> of <?= count($pdus) ?> PDUs
                <?php if ($siteLoadMode !== 'all' && abs($rawAllKw - $totalKw) > 0.05): ?>
                    · raw sum of all PDUs would be <?= number_format($rawAllKw, 1) ?> kW
                <?php endif; ?>
            </p>
        </div>
        <?php if ($canEditSiteLoad): ?>
        <form method="post" class="flex gap-1" style="align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="set_site_load_mode">
            <div class="form-row" style="margin:0;min-width:14rem">
                <label style="font-size:.8rem">Facility rollup</label>
                <select class="form-control" name="power_site_load_mode">
                    <?php foreach (power_site_load_mode_labels() as $val => $lab): ?>
                        <option value="<?= App::e($val) ?>" <?= $siteLoadMode === $val ? 'selected' : '' ?>>
                            <?= App::e($lab) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">Save</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="metrics power-metrics">
    <div class="metric-card warning">
        <div class="label">Polled load</div>
        <div class="value"><?= number_format($totalKw, 1) ?> <span class="metric-unit">kW</span></div>
        <div class="sub">
            <?= (int)$siteLoad['reporting'] ?> of <?= (int)$siteLoad['count'] ?> facility meters reporting
            <?php if ((int)$siteLoad['count'] !== count($pdus)): ?>
                · <?= count($pdus) ?> PDUs total
            <?php endif; ?>
        </div>
    </div>
    <div class="metric-card <?= $capacityPct !== null && $capacityPct >= 75 ? 'warning' : 'success' ?>">
        <div class="label">Zone capacity</div>
        <div class="value">
            <?php if ($capacityKnown): ?>
                <?= number_format($capacityKw, 1) ?> <span class="metric-unit">kW</span>
            <?php else: ?>
                —
            <?php endif; ?>
        </div>
        <div class="sub">
            <?php if ($capacityPct !== null): ?>
                <?= $capacityPct ?>% utilized
            <?php else: ?>
                Set max kW on zones
            <?php endif; ?>
        </div>
    </div>
    <div class="metric-card accent">
        <div class="label">Power zones</div>
        <div class="value"><?= count($zones) ?></div>
        <div class="sub"><?= $panelCount ?> panels · feeds A/B/dual</div>
    </div>
    <div class="metric-card">
        <div class="label">PDUs</div>
        <div class="value"><?= count($pdus) ?></div>
        <div class="sub">
            <?= (int)$scopeCounts['rack'] ?> rack ·
            <?= (int)$scopeCounts['row'] ?> row ·
            <?= (int)$scopeCounts['room'] ?> room
        </div>
    </div>
    <div class="metric-card <?= App::e($upsHealthCls) ?>">
        <div class="label">UPS</div>
        <div class="value"><?= (int)$upsCount ?></div>
        <div class="sub">
            <?php if ($upsCount < 1): ?>
                None in inventory
            <?php else: ?>
                <?= (int)($upsSnap['online'] ?? 0) ?> online
                <?php if ((int)($upsSnap['on_battery'] ?? 0) > 0): ?>
                    · <?= (int)$upsSnap['on_battery'] ?> on battery
                <?php endif; ?>
                <?php if ($upsSnap['avg_load_pct'] !== null): ?>
                    · avg load <?= App::e((string)$upsSnap['avg_load_pct']) ?>%
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <a class="metric-card <?= $stalePdus ? 'danger' : '' ?>"
       href="<?= App::e(App::url('pages/snmp.php#targets')) ?>"
       style="color:inherit;text-decoration:none">
        <div class="label">SNMP</div>
        <div class="value"><?= $snmpOn ?></div>
        <div class="sub">
            <?= $stalePdus ? $stalePdus . ' stale (&gt;1h / never)' : 'enabled on PDUs' ?>
            · schedule
        </div>
    </a>
    <a class="metric-card <?= $powerPathRisk > 0 ? 'warning' : 'success' ?>"
       href="<?= App::e(App::url('pages/reports.php?report=power_path')) ?>"
       style="color:inherit;text-decoration:none">
        <div class="label">Power path</div>
        <div class="value"><?= (int)$powerPathCounts['unmapped_psus'] ?></div>
        <div class="sub">
            unmapped PSUs
            <?php if ((int)$powerPathCounts['single_feed_devices'] > 0): ?>
                · <?= (int)$powerPathCounts['single_feed_devices'] ?> single-feed
            <?php endif; ?>
            <?php if ((int)$powerPathCounts['half_maps'] > 0): ?>
                · <?= (int)$powerPathCounts['half_maps'] ?> half-map
            <?php endif; ?>
            · open report
        </div>
    </a>
</div>

<?php if ($capacityPct !== null || $siteFreeKwKnown || $siteFreeU > 0): ?>
<div class="card power-capacity-banner">
    <div class="card-body power-capacity-body">
        <div class="power-capacity-meta">
            <strong>Facility headroom</strong>
            <span class="text-muted">
                <?php if ($capacityKnown): ?>
                    <?= number_format($totalKw, 1) ?> kW used of <?= number_format($capacityKw, 1) ?> kW
                    <?php if ($siteFreeKwKnown): ?>
                        · <strong><?= number_format($siteFreeKw, 1) ?> kW free</strong>
                    <?php endif; ?>
                <?php else: ?>
                    Set max kW on zones for power headroom
                <?php endif; ?>
                · <strong><?= (int)$siteFreeU ?> U free</strong> on zoned rows
                <?php if ($imbalancedZoneCount > 0 && $worstImbalance): ?>
                    · <a href="<?= App::e(App::url('pages/power_zones.php?id=' . (int)$worstImbalance['zone_id'])) ?>">
                        <?= (int)$imbalancedZoneCount ?> zone(s) phase imbalance
                        (worst <?= App::e((string)$worstImbalance['name']) ?> <?= number_format((float)$worstImbalance['pct'], 0) ?>%)
                    </a>
                <?php endif; ?>
                · <a href="<?= App::e(App::url('pages/reports.php?report=power_capacity')) ?>">Capacity report</a>
            </span>
        </div>
        <?php if ($capacityPct !== null): ?>
        <div class="util-bar util-bar-lg">
            <div class="util-bar-fill util-<?= App::e(power_util_class((float)$capacityPct)) ?>"
                 style="width:<?= min(100, (float)$capacityPct) ?>%"></div>
        </div>
        <div class="util-bar-label"><?= $capacityPct ?>%</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Overall load + UPS history -->
<div class="power-dash-grid mb-2" style="grid-template-columns:1fr 1fr;gap:1rem">
    <div class="card power-history-wide" data-power-history data-scope="site" data-hours="24">
        <div class="card-header flex-between">
            <h2 style="margin:0">Overall load — 24h</h2>
            <span class="text-muted" style="font-size:.8rem">
                <?= App::e(power_site_load_mode_labels()[$siteLoadMode] ?? 'Facility rollup') ?>
                · red = phase outages
            </span>
        </div>
        <div class="card-body power-history-body">
            <div class="power-outage-summary" data-outage-summary hidden></div>
            <div class="power-chart power-chart-lg" data-metric="kw" data-unit="kW" data-label="Facility output (kW)" data-color="#38bdf8" data-height="220"></div>
            <div class="power-chart power-chart-lg" data-metric="volts" data-unit="V" data-label="Input voltage (avg L–N)" data-color="#a78bfa" data-height="140" data-hide-empty="1"></div>
        </div>
    </div>
    <div class="card power-history-wide" data-power-history data-scope="ups_site" data-hours="24">
        <div class="card-header flex-between">
            <h2 style="margin:0">UPS load — 24h</h2>
            <span class="text-muted" style="font-size:.8rem">Avg across all UPS · needs scheduled poll</span>
        </div>
        <div class="card-body power-history-body">
            <div class="power-chart power-chart-lg" data-metric="load_pct" data-unit="%" data-label="UPS load %" data-color="#a78bfa" data-height="160" data-outages="0"></div>
            <div class="power-chart power-chart-lg" data-metric="battery_pct" data-unit="%" data-label="Battery %" data-color="#34d399" data-height="120" data-outages="0" data-hide-empty="1"></div>
            <div class="power-chart power-chart-lg" data-metric="kw" data-unit="kW" data-label="Est. UPS output (kW)" data-color="#c4b5fd" data-height="120" data-outages="0" data-hide-empty="1"></div>
        </div>
    </div>
</div>

<div class="power-dash-grid">
    <!-- Zones overview -->
    <div class="card">
        <div class="card-header">
            <h2>Zones</h2>
            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_zones.php')) ?>">Open</a>
        </div>
        <div class="card-body flush">
            <?php if (!$zones): ?>
                <div class="empty-state" style="padding:1.5rem">
                    <h3>No power zones</h3>
                    <p>Define feeds (A/B) and capacity limits to track utilization.</p>
                    <a class="btn btn-primary btn-sm" href="<?= App::e(App::url('pages/power_zones.php')) ?>">Add zone</a>
                </div>
            <?php else: ?>
                <div class="zone-cards">
                    <?php foreach ($zones as $z):
                        $color = power_normalize_color($z['color_hex'] ?? null);
                        $zCap = is_array($z['capacity'] ?? null) ? $z['capacity'] : null;
                        $pollKw = $zCap ? (float)$zCap['load_kw'] : (((float)($z['poll_watts'] ?? 0)) / 1000.0);
                        $maxKw = $zCap && $zCap['max_kw'] !== null
                            ? (float)$zCap['max_kw']
                            : ($z['max_kw'] !== null && $z['max_kw'] !== '' ? (float)$z['max_kw'] : null);
                        $pct = $zCap['util_pct'] ?? (($maxKw && $maxKw > 0) ? min(100, round(100 * $pollKw / $maxKw, 1)) : null);
                        $cls = $pct !== null ? power_util_class((float)$pct) : '';
                        $freeKw = $zCap['free_kw'] ?? null;
                        $freeU = $zCap ? (int)$zCap['free_u'] : 0;
                        $zImb = !empty($zCap['imbalanced']);
                        ?>
                        <a class="zone-card" href="<?= App::e(App::url('pages/power_zones.php?id=' . (int)$z['zone_id'])) ?>"
                           style="--zone-color: <?= App::e($color) ?>">
                            <div class="zone-card-top">
                                <span class="zone-swatch" style="background:<?= App::e($color) ?>"></span>
                                <div class="zone-card-title">
                                    <strong><?= App::e($z['name']) ?></strong>
                                    <span class="text-muted"><?= App::e($z['dc_name'] ?? '') ?></span>
                                </div>
                                <?php if ($zImb): ?>
                                    <span class="badge badge-warning">Imbalanced</span>
                                <?php else: ?>
                                    <span class="badge">Feed <?= App::e((string)($z['feed_type'] ?? '—')) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="zone-card-metrics">
                                <div>
                                    <span class="zcm-label">Load</span>
                                    <span class="zcm-val"><?= number_format($pollKw, 1) ?> kW</span>
                                </div>
                                <div>
                                    <span class="zcm-label">Free</span>
                                    <span class="zcm-val"><?= $freeKw !== null ? number_format((float)$freeKw, 1) . ' kW' : '—' ?></span>
                                </div>
                                <div>
                                    <span class="zcm-label">Free U</span>
                                    <span class="zcm-val"><?= (int)$freeU ?> U</span>
                                </div>
                                <div>
                                    <span class="zcm-label">Phases</span>
                                    <span class="zcm-val" style="font-size:.78rem">
                                        <?= App::e(power_format_phase_amps($zCap['phase_amps'] ?? [])) ?>
                                    </span>
                                </div>
                            </div>
                            <?php if ($pct !== null): ?>
                                <div class="util-bar">
                                    <div class="util-bar-fill util-<?= App::e($cls) ?>" style="width:<?= $pct ?>%"></div>
                                </div>
                                <div class="zone-card-util text-muted">
                                    <?= $pct ?>% of <?= number_format($maxKw, 1) ?> kW · <?= (int)$freeU ?> U free
                                </div>
                            <?php else: ?>
                                <div class="zone-card-util text-muted">No max kW · <?= (int)$freeU ?> U free</div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Side column -->
    <div class="power-dash-side">
        <div class="card">
            <div class="card-header"><h2>Feed distribution</h2></div>
            <div class="card-body">
                <div class="feed-pills">
                    <div class="feed-pill feed-a">
                        <span class="fp-count"><?= (int)$feedStats['A'] ?></span>
                        <span class="fp-label">Feed A</span>
                    </div>
                    <div class="feed-pill feed-b">
                        <span class="fp-count"><?= (int)$feedStats['B'] ?></span>
                        <span class="fp-label">Feed B</span>
                    </div>
                    <div class="feed-pill feed-dual">
                        <span class="fp-count"><?= (int)$feedStats['dual'] ?></span>
                        <span class="fp-label">Dual</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Attention</h2></div>
            <div class="card-body">
                <ul class="attn-list">
                    <li class="<?= $unassignedPdus ? 'attn-warn' : '' ?>">
                        <span><?= $unassignedPdus ?></span> PDUs without a zone
                    </li>
                    <li class="<?= $stalePdus ? 'attn-danger' : '' ?>">
                        <span><?= $stalePdus ?></span> SNMP PDUs not polled recently
                    </li>
                    <li>
                        <span><?= count($pdus) - $snmpOn ?></span> PDUs without SNMP
                    </li>
                    <li class="<?= (int)($upsSnap['on_battery'] ?? 0) > 0 || (int)($upsSnap['health_crit'] ?? 0) > 0 ? 'attn-danger' : '' ?>">
                        <span><?= (int)($upsSnap['on_battery'] ?? 0) ?></span> UPS on battery
                        <?php if ((int)($upsSnap['health_crit'] ?? 0) > 0): ?>
                            · <?= (int)$upsSnap['health_crit'] ?> critical
                        <?php endif; ?>
                    </li>
                    <li>
                        <span><?= count(array_filter($zones, static fn($z) => $z['max_kw'] === null || $z['max_kw'] === '')) ?></span> zones missing capacity
                    </li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Quick actions</h2>
            </div>
            <div class="card-body power-quick-actions">
                <a class="btn btn-secondary btn-block" href="<?= App::e(App::url('pages/power_zones.php#add-zone')) ?>">+ Add zone</a>
                <a class="btn btn-secondary btn-block" href="<?= App::e(App::url('pages/power_pdus.php#add-pdu')) ?>">+ Add PDU</a>
                <a class="btn btn-secondary btn-block" href="<?= App::e(App::url('pages/power_ups.php?action=new')) ?>">+ Add UPS</a>
                <a class="btn btn-ghost btn-block" href="<?= App::e(App::url('pages/snmp.php')) ?>">SNMP polling</a>
                <a class="btn btn-ghost btn-block" href="<?= App::e(App::url('pages/cabinets.php')) ?>">Cabinet rack views</a>
            </div>
        </div>
    </div>
</div>

<div class="card mb-2">
    <div class="card-header flex-between">
        <h2 style="margin:0">UPS inventory</h2>
        <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_ups.php')) ?>">All UPS</a>
    </div>
    <div class="card-body flush">
        <?php if ($upsCount < 1): ?>
            <div class="empty-state" style="padding:1.5rem">
                <h3>No UPS units</h3>
                <p>Add Schneider Symmetra / APC Smart-UPS units for floor plan, 3D, and SNMPv3 poll.</p>
                <a class="btn btn-primary btn-sm" href="<?= App::e(App::url('pages/power_ups.php?action=new')) ?>">Add UPS</a>
            </div>
        <?php else: ?>
            <div class="metrics" style="padding:1rem 1rem 0;margin:0">
                <div class="metric-card <?= (int)($upsSnap['on_battery'] ?? 0) > 0 ? 'danger' : 'success' ?>">
                    <div class="label">Output</div>
                    <div class="value" style="font-size:1.25rem"><?= (int)($upsSnap['online'] ?? 0) ?> <span class="metric-unit">online</span></div>
                    <div class="sub">
                        <?= (int)($upsSnap['on_battery'] ?? 0) ?> battery
                        · <?= (int)($upsSnap['bypass'] ?? 0) ?> bypass
                    </div>
                </div>
                <div class="metric-card <?= ($upsSnap['max_load_pct'] !== null && (float)$upsSnap['max_load_pct'] >= 80) ? 'warning' : '' ?>">
                    <div class="label">Load</div>
                    <div class="value" style="font-size:1.25rem">
                        <?= $upsSnap['avg_load_pct'] !== null ? App::e((string)$upsSnap['avg_load_pct']) . '%' : '—' ?>
                    </div>
                    <div class="sub">
                        avg
                        <?php if ($upsSnap['max_load_pct'] !== null): ?>
                            · max <?= App::e((string)$upsSnap['max_load_pct']) ?>%
                        <?php endif; ?>
                    </div>
                </div>
                <div class="metric-card <?= ($upsSnap['min_battery_pct'] !== null && (float)$upsSnap['min_battery_pct'] < 50) ? 'warning' : 'success' ?>">
                    <div class="label">Battery</div>
                    <div class="value" style="font-size:1.25rem">
                        <?= $upsSnap['min_battery_pct'] !== null ? App::e((string)$upsSnap['min_battery_pct']) . '%' : '—' ?>
                    </div>
                    <div class="sub">
                        min
                        <?php if ($upsSnap['avg_battery_pct'] !== null): ?>
                            · avg <?= App::e((string)$upsSnap['avg_battery_pct']) ?>%
                        <?php endif; ?>
                    </div>
                </div>
                <div class="metric-card accent">
                    <div class="label">Rated</div>
                    <div class="value" style="font-size:1.25rem">
                        <?= number_format((float)($upsSnap['rated_kva'] ?? 0), 0) ?>
                        <span class="metric-unit">kVA</span>
                    </div>
                    <div class="sub">
                        <?= (int)($upsSnap['snmp_on'] ?? 0) ?> SNMP ·
                        <?= (int)($upsSnap['polled'] ?? 0) ?> polled
                    </div>
                </div>
            </div>
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
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($upsSnap['list'] ?? []) as $uu):
                    $hp = (string)($uu['health'] ?? 'unknown');
                    ?>
                    <tr class="<?= in_array($hp, ['warn', 'crit'], true) ? 'health-row-' . App::e($hp) : '' ?>">
                        <td>
                            <a href="<?= App::e(App::url('pages/power_ups.php?id=' . (int)$uu['ups_id'])) ?>">
                                <strong><?= App::e((string)$uu['name']) ?></strong>
                            </a>
                            <?php if (!empty($uu['model'])): ?>
                                <div class="text-muted" style="font-size:.75rem"><?= App::e((string)$uu['model']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge"><?= App::e(ups_scopes()[$uu['scope'] ?? ''] ?? (string)($uu['scope'] ?? '')) ?></span></td>
                        <td><?= App::e((string)($uu['output_status'] ?: '—')) ?></td>
                        <td><?= $uu['load_pct'] !== null ? App::e((string)$uu['load_pct']) . '%' : '—' ?></td>
                        <td><?= $uu['battery_pct'] !== null ? App::e((string)$uu['battery_pct']) . '%' : '—' ?></td>
                        <td><?= $uu['runtime_min'] !== null ? App::e((string)$uu['runtime_min']) . ' min' : '—' ?></td>
                        <td>
                            <span class="health-chip health-chip-<?= App::e($hp) ?>">
                                <span class="health-pulse health-pulse-<?= App::e($hp) ?>" aria-hidden="true"></span>
                                <span class="health-chip-label"><?= App::e($hp) ?></span>
                            </span>
                        </td>
                        <td class="actions">
                            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_ups.php?id=' . (int)$uu['ups_id'])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>PDUs — highest load</h2>
        <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php')) ?>">All PDUs</a>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th>
                <th>Scope</th>
                <th>Phases</th>
                <th>Zone</th>
                <th>Location</th>
                <th>Load</th>
                <th>SNMP</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$topPdus): ?>
                <tr><td colspan="8" class="text-muted">No PDUs yet. <a href="<?= App::e(App::url('pages/power_pdus.php')) ?>">Add a PDU</a></td></tr>
            <?php endif; ?>
            <?php foreach ($topPdus as $p):
                $w = $p['last_poll_watts'] !== null ? (float)$p['last_poll_watts'] : null;
                $loc = [];
                if (!empty($p['cabinet_name'])) {
                    $loc[] = $p['cabinet_name'];
                }
                if (!empty($p['row_name'])) {
                    $loc[] = 'Row ' . $p['row_name'];
                }
                $zColor = power_normalize_color($p['zone_color'] ?? null, '#64748b');
                ?>
                <tr>
                    <td><strong><?= App::e($p['name']) ?></strong></td>
                    <td><span class="badge"><?= App::e($p['pdu_scope'] ?? 'rack') ?></span></td>
                    <td><?= App::e(power_wiring_label($p['phase_wiring'] ?? null, (int)($p['phases'] ?? 1))) ?></td>
                    <td>
                        <?php if (!empty($p['zone_name'])): ?>
                            <span class="dept-chip">
                                <span class="dept-swatch sm" style="background:<?= App::e($zColor) ?>"></span>
                                <?= App::e($p['zone_name']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= App::e($loc ? implode(' · ', $loc) : '—') ?></td>
                    <td>
                        <?php if ($w !== null): ?>
                            <strong><?= number_format($w / 1000, 2) ?></strong> <span class="text-muted">kW</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= !empty($p['snmp_enabled'])
                            ? '<span class="badge badge-success">v' . App::e((string)$p['snmp_version']) . '</span>'
                            : '<span class="text-muted">off</span>' ?>
                    </td>
                    <td class="actions">
                        <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php?id=' . (int)$p['pdu_id'])) ?>">View</a>
                        <?php if (!empty($p['cabinet_id'])): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$p['cabinet_id'])) ?>">Cabinet</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="<?= App::e(App::url('assets/js/power-charts.js')) ?>?v=6"></script>
<?php layout_footer(); ?>
