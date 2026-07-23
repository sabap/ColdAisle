<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/power_helpers.php';
App::boot();
$user = App::requirePermission('view_power');

$zones = Database::fetchAll(
    'SELECT z.*, dc.name AS dc_name,
            (SELECT COUNT(*) FROM pdus p WHERE p.zone_id = z.zone_id AND p.is_active = 1) AS pdu_count,
            (SELECT COUNT(*) FROM power_panels pp WHERE pp.zone_id = z.zone_id) AS panel_count,
            (SELECT ISNULL(SUM(p.last_poll_watts), 0) FROM pdus p WHERE p.zone_id = z.zone_id AND p.is_active = 1) AS poll_watts
     FROM power_zones z
     INNER JOIN datacenters dc ON dc.datacenter_id = z.datacenter_id
     ORDER BY z.name'
);

$pdus = Database::fetchAll(
    'SELECT p.*, c.name AS cabinet_name, z.name AS zone_name, z.color_hex AS zone_color,
            r.name AS row_name
     FROM pdus p
     LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
     LEFT JOIN power_zones z ON z.zone_id = p.zone_id
     LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
     WHERE p.is_active = 1
     ORDER BY p.name'
);

$panelCount = (int) Database::fetchValue('SELECT COUNT(*) FROM power_panels');
$snmpOn = count(array_filter($pdus, static fn($p) => !empty($p['snmp_enabled'])));
$withPoll = array_filter($pdus, static fn($p) => $p['last_poll_watts'] !== null);
$totalKw = array_sum(array_map(static fn($p) => (float)($p['last_poll_watts'] ?? 0), $pdus)) / 1000.0;
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

$unassignedPdus = count(array_filter($pdus, static fn($p) => empty($p['zone_id'])));
$stalePdus = count(array_filter($pdus, static function ($p) {
    if (empty($p['snmp_enabled'])) {
        return false;
    }
    if (empty($p['last_poll_at'])) {
        return true;
    }
    return strtotime((string)$p['last_poll_at']) < (time() - 3600);
}));

layout_header('Power Dashboard', $user, 'power');
?>

<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0" style="font-size:.92rem">
            High-level power metrics across zones and PDUs. Manage details on the sub-pages.
        </p>
    </div>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_zones.php')) ?>">Manage Zones</a>
        <a class="btn btn-primary" href="<?= App::e(App::url('pages/power_pdus.php')) ?>">Manage PDUs</a>
    </div>
</div>

<div class="metrics power-metrics">
    <div class="metric-card warning">
        <div class="label">Polled load</div>
        <div class="value"><?= number_format($totalKw, 1) ?> <span class="metric-unit">kW</span></div>
        <div class="sub"><?= count($withPoll) ?> of <?= count($pdus) ?> PDUs reporting</div>
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
    <div class="metric-card <?= $stalePdus ? 'danger' : '' ?>">
        <div class="label">SNMP</div>
        <div class="value"><?= $snmpOn ?></div>
        <div class="sub"><?= $stalePdus ? $stalePdus . ' stale / no poll' : 'enabled on PDUs' ?></div>
    </div>
</div>

<?php if ($capacityPct !== null): ?>
<div class="card power-capacity-banner">
    <div class="card-body power-capacity-body">
        <div class="power-capacity-meta">
            <strong>Facility load vs zone capacity</strong>
            <span class="text-muted"><?= number_format($totalKw, 1) ?> kW of <?= number_format($capacityKw, 1) ?> kW</span>
        </div>
        <div class="util-bar util-bar-lg">
            <div class="util-bar-fill util-<?= App::e(power_util_class((float)$capacityPct)) ?>"
                 style="width:<?= min(100, (float)$capacityPct) ?>%"></div>
        </div>
        <div class="util-bar-label"><?= $capacityPct ?>%</div>
    </div>
</div>
<?php endif; ?>

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
                        $pollKw = ((float)($z['poll_watts'] ?? 0)) / 1000.0;
                        $maxKw = $z['max_kw'] !== null && $z['max_kw'] !== '' ? (float)$z['max_kw'] : null;
                        $pct = ($maxKw && $maxKw > 0) ? min(100, round(100 * $pollKw / $maxKw, 1)) : null;
                        $cls = $pct !== null ? power_util_class((float)$pct) : '';
                        ?>
                        <a class="zone-card" href="<?= App::e(App::url('pages/power_zones.php?id=' . (int)$z['zone_id'])) ?>"
                           style="--zone-color: <?= App::e($color) ?>">
                            <div class="zone-card-top">
                                <span class="zone-swatch" style="background:<?= App::e($color) ?>"></span>
                                <div class="zone-card-title">
                                    <strong><?= App::e($z['name']) ?></strong>
                                    <span class="text-muted"><?= App::e($z['dc_name'] ?? '') ?></span>
                                </div>
                                <span class="badge">Feed <?= App::e((string)($z['feed_type'] ?? '—')) ?></span>
                            </div>
                            <div class="zone-card-metrics">
                                <div>
                                    <span class="zcm-label">Voltage</span>
                                    <span class="zcm-val"><?= $z['voltage'] !== null ? (int)$z['voltage'] . ' V' : '—' ?></span>
                                </div>
                                <div>
                                    <span class="zcm-label">Load</span>
                                    <span class="zcm-val"><?= number_format($pollKw, 1) ?> kW</span>
                                </div>
                                <div>
                                    <span class="zcm-label">PDUs</span>
                                    <span class="zcm-val"><?= (int)($z['pdu_count'] ?? 0) ?></span>
                                </div>
                                <div>
                                    <span class="zcm-label">Panels</span>
                                    <span class="zcm-val"><?= (int)($z['panel_count'] ?? 0) ?></span>
                                </div>
                            </div>
                            <?php if ($pct !== null): ?>
                                <div class="util-bar">
                                    <div class="util-bar-fill util-<?= App::e($cls) ?>" style="width:<?= $pct ?>%"></div>
                                </div>
                                <div class="zone-card-util text-muted">
                                    <?= $pct ?>% of <?= number_format($maxKw, 1) ?> kW capacity
                                </div>
                            <?php else: ?>
                                <div class="zone-card-util text-muted">No max kW set</div>
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
                <a class="btn btn-ghost btn-block" href="<?= App::e(App::url('pages/snmp.php')) ?>">SNMP polling</a>
                <a class="btn btn-ghost btn-block" href="<?= App::e(App::url('pages/cabinets.php')) ?>">Cabinet rack views</a>
            </div>
        </div>
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
<?php layout_footer(); ?>
