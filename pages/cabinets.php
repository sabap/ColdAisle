<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_cabinets');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id) {
    $cab = Database::fetchOne(
        'SELECT c.*, r.name AS room_name, dc.name AS dc_name
         FROM cabinets c
         INNER JOIN rooms r ON r.room_id = c.room_id
         INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
         WHERE c.cabinet_id = ?',
        [$id]
    );
    if (!$cab) {
        App::flash('error', 'Cabinet not found.');
        App::redirect('pages/cabinets.php');
    }
    // Devices + template images for front/rear faces (+ department color for outline)
    $devices = Database::fetchAll(
        'SELECT d.*,
                t.front_picture AS tpl_front,
                t.rear_picture AS tpl_rear,
                t.model AS tpl_model,
                dep.name AS department_name,
                dep.color_hex AS department_color
         FROM devices d
         LEFT JOIN device_templates t ON t.template_id = d.template_id
         LEFT JOIN departments dep ON dep.department_id = d.department_id
         WHERE d.cabinet_id = ? AND d.is_active = 1
         ORDER BY d.position_u DESC',
        [$id]
    );

    // PDUs for this cabinet (U-mounted shown in elevation; vertical rear listed only)
    $pdus = [];
    try {
        $pdus = Database::fetchAll(
            'SELECT p.*, z.name AS zone_name
             FROM pdus p
             LEFT JOIN power_zones z ON z.zone_id = p.zone_id
             WHERE p.cabinet_id = ? AND p.is_active = 1
             ORDER BY p.name',
            [$id]
        );
    } catch (Throwable $e) {
        $pdus = [];
    }

    $powerZones = [];
    try {
        $powerZones = Database::fetchAll(
            'SELECT zone_id, name FROM power_zones ORDER BY name'
        );
    } catch (Throwable $e) {
        $powerZones = [];
    }

    // EIA-310: 19" rail opening, 1.75" per rack unit — cabinet bay uses that aspect ratio
    $height = max(1, (int)$cab['u_height']);
    $railIn = 19.0;
    $uIn = 1.75;
    $aspectW = $railIn;
    $aspectH = $height * $uIn;

    /**
     * Device visible on a face?
     * - Full-depth: both front and rear elevations
     * - Half-depth front (back_side=0): front only
     * - Half-depth rear (back_side=1): rear only
     */
    $deviceOnFace = static function (array $d, string $face): bool {
        if ($d['position_u'] === null) {
            return false;
        }
        $half = !empty($d['half_depth']);
        $rear = !empty($d['back_side']);
        if (!$half) {
            return true;
        }
        return $face === 'rear' ? $rear : !$rear;
    };

    $uMountedPdus = array_values(array_filter(
        $pdus,
        static fn($p) => ($p['mount_style'] ?? '') === 'u_mounted' && $p['position_u'] !== null
    ));

    $uOccupiedFront = [];
    $uOccupiedRear = [];
    foreach ($devices as $d) {
        if ($d['position_u'] === null) {
            continue;
        }
        $start = (int)$d['position_u'];
        $uh = max(1, (int)$d['u_height']);
        for ($u = $start; $u < $start + $uh; $u++) {
            if ($deviceOnFace($d, 'front')) {
                $uOccupiedFront[$u] = true;
            }
            if ($deviceOnFace($d, 'rear')) {
                $uOccupiedRear[$u] = true;
            }
        }
    }
    // U-mounted PDUs occupy both faces (like full-depth devices)
    foreach ($uMountedPdus as $p) {
        $start = (int)$p['position_u'];
        $uh = max(1, (int)($p['u_height'] ?? 1));
        for ($u = $start; $u < $start + $uh; $u++) {
            $uOccupiedFront[$u] = true;
            $uOccupiedRear[$u] = true;
        }
    }

    $mediaUrl = static function (?string $rel): string {
        if (!$rel) {
            return '';
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        return App::url('media.php?f=' . rawurlencode($rel));
    };

    $deviceImage = static function (array $d, string $face) use ($mediaUrl): string {
        $front = $d['tpl_front'] ?? null;
        $rear = $d['tpl_rear'] ?? null;
        $path = $face === 'rear' ? ($rear ?: $front) : ($front ?: $rear);
        return $mediaUrl($path);
    };

    // Positions as % of bay height so aspect-ratio layout stays correct
    $renderRackFace = static function (
        string $face,
        array $devices,
        array $uMountedPdus,
        int $units,
        float $aspectW,
        float $aspectH,
        int $cabinetId,
        array $uOccupied,
        callable $deviceOnFace,
        callable $deviceImage
    ): void {
        $title = $face === 'rear' ? 'Rear' : 'Front';
        $mountHint = $face === 'rear' ? '&mount=rear' : '';
        ?>
        <div class="rack-face">
            <div class="rack-face-title">
                <?= App::e($title) ?>
                <small>19″ × <?= App::e((string)$units) ?>U (1.75″/U)</small>
            </div>
            <div class="rack-elevation-v2"
                 style="--units: <?= (int)$units ?>; --rail-in: <?= $aspectW ?>; --rack-h-in: <?= $aspectH ?>;">
                <div class="rack-u-rail" aria-hidden="true">
                    <?php for ($u = $units; $u >= 1; $u--): ?>
                        <div class="rack-u-rail-tick"><?= $u ?></div>
                    <?php endfor; ?>
                </div>
                <div class="rack-bay" role="img" aria-label="<?= App::e($title) ?> rack elevation">
                    <?php for ($u = 1; $u <= $units; $u++):
                        if (!empty($uOccupied[$u])) {
                            continue;
                        }
                        // bottom %: U1 sits at 0%; each U is 100/units %
                        $bottomPct = (($u - 1) / $units) * 100;
                        $hPct = (1 / $units) * 100;
                        $newUrl = App::url(
                            'pages/devices.php?action=new&cabinet_id=' . $cabinetId .
                            '&position_u=' . $u . $mountHint
                        );
                        ?>
                        <a class="rack-empty-slot"
                           style="bottom: <?= rtrim(rtrim(sprintf('%.4F', $bottomPct), '0'), '.') ?>%; height: <?= rtrim(rtrim(sprintf('%.4F', $hPct), '0'), '.') ?>%;"
                           href="<?= App::e($newUrl) ?>"
                           title="Add device at U<?= $u ?> (<?= App::e($title) ?>)"></a>
                    <?php endfor; ?>

                    <?php foreach ($devices as $d):
                        if (!$deviceOnFace($d, $face)) {
                            continue;
                        }
                        $pos = (int)$d['position_u'];
                        $uh = max(1, (int)$d['u_height']);
                        $bottomPct = (($pos - 1) / $units) * 100;
                        $hPct = ($uh / $units) * 100;
                        $img = $deviceImage($d, $face);
                        $typeClass = preg_replace('/[^a-z_]/', '', strtolower((string)$d['device_type']));
                        $href = App::url('pages/devices.php?id=' . (int)$d['device_id']);
                        $label = $d['label'] ?? '';
                        $topU = $pos + $uh - 1;
                        $deptColor = trim((string)($d['department_color'] ?? ''));
                        if ($deptColor !== '' && $deptColor[0] !== '#') {
                            $deptColor = '#' . $deptColor;
                        }
                        if ($deptColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $deptColor)) {
                            $deptColor = '';
                        }
                        $deptStyle = $deptColor !== ''
                            ? '; --dept-color: ' . $deptColor . ';'
                            : '';
                        $deptTitle = !empty($d['department_name']) ? ' · ' . $d['department_name'] : '';
                        ?>
                        <a class="rack-device type-<?= App::e($typeClass) ?><?= $deptColor !== '' ? ' has-dept-color' : '' ?>"
                           href="<?= App::e($href) ?>"
                           style="bottom: <?= rtrim(rtrim(sprintf('%.4F', $bottomPct), '0'), '.') ?>%; height: <?= rtrim(rtrim(sprintf('%.4F', $hPct), '0'), '.') ?>%;<?= App::e($deptStyle) ?>"
                           title="<?= App::e($label . ' · U' . $pos . '–' . $topU . ' · ' . $uh . 'U' . $deptTitle) ?>">
                            <?php if ($img !== ''): ?>
                                <img class="rack-device-img"
                                     src="<?= App::e($img) ?>"
                                     alt="<?= App::e($label) ?>"
                                     loading="lazy">
                            <?php endif; ?>
                            <span class="rack-device-meta">
                                <span class="rack-device-name"><?= App::e($label) ?></span>
                                <span class="rack-device-u">U<?= $pos ?>–<?= $topU ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>

                    <?php foreach ($uMountedPdus as $p):
                        $pos = (int)$p['position_u'];
                        $uh = max(1, (int)($p['u_height'] ?? 1));
                        $bottomPct = (($pos - 1) / $units) * 100;
                        $hPct = ($uh / $units) * 100;
                        $label = $p['name'] ?? 'PDU';
                        $topU = $pos + $uh - 1;
                        ?>
                        <button type="button"
                                class="rack-device type-pdu rack-pdu-btn"
                                data-pdu-id="<?= (int)$p['pdu_id'] ?>"
                                style="bottom: <?= rtrim(rtrim(sprintf('%.4F', $bottomPct), '0'), '.') ?>%; height: <?= rtrim(rtrim(sprintf('%.4F', $hPct), '0'), '.') ?>%;"
                                title="<?= App::e($label . ' · U' . $pos . '–' . $topU . ' · PDU') ?>">
                            <span class="rack-device-meta">
                                <span class="rack-device-name"><?= App::e($label) ?></span>
                                <span class="rack-device-u">U<?= $pos ?>–<?= $topU ?> · PDU</span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    };

    $usedDevices = array_sum(array_map(
        fn($d) => (int)$d['u_height'],
        array_filter($devices, fn($d) => $d['position_u'] !== null)
    ));
    $usedPdus = array_sum(array_map(
        fn($p) => max(1, (int)($p['u_height'] ?? 1)),
        $uMountedPdus
    ));
    $used = $usedDevices + $usedPdus;
    $pct = $height ? round(100 * $used / $height, 1) : 0;

    $connectorTypes = [
        'C13', 'C14', 'C19', 'C20', '5-15R', '5-20R', 'L5-20R', 'L5-30R',
        'L6-20R', 'L6-30R', 'L14-30R', 'IEC 60309 16A', 'IEC 60309 32A', 'Hardwired', 'Other',
    ];
    $inputTypes = [
        'L6-30P', 'L6-20P', 'L5-30P', 'L5-20P', 'L14-30P', 'CS8365',
        'IEC 60309 3P+N+E 32A', 'IEC 60309 3P+N+E 16A', 'Hardwired', 'C20', 'Other',
    ];

    $cabinetAuditCanLog = AuthManager::can($user, 'edit_audits')
        || AuthManager::can($user, 'edit_infrastructure')
        || AuthManager::can($user, 'edit_devices_all');
    $cabinetAudits = [];
    try {
        $cabinetAudits = Database::fetchAll(
            'SELECT TOP 20 a.cabinet_audit_id, a.audited_by_name, a.certified, a.comments, a.audited_at
             FROM cabinet_audits a
             WHERE a.cabinet_id = ?
             ORDER BY a.audited_at DESC',
            [$id]
        );
    } catch (Throwable $e) {
        $cabinetAudits = [];
    }
    $lastCabinetAudit = $cabinetAudits[0] ?? null;
    require_once dirname(__DIR__) . '/includes/audit_helpers.php';
    $cabAuditInterval = audit_cabinet_interval_days($cab);
    $cabAuditSchedule = audit_cabinet_schedule(
        $lastCabinetAudit ? (string)$lastCabinetAudit['audited_at'] : null,
        $cabAuditInterval
    );

    layout_header('Cabinet: ' . $cab['name'], $user, 'cabinets');
    ?>
    <div class="flex-between mb-2">
        <div>
            <span class="text-muted"><?= App::e($cab['dc_name'] . ' / ' . $cab['room_name']) ?></span>
            <p class="text-muted mb-0">
                Floor: <?= (int)$cab['width_mm'] ?>×<?= (int)$cab['depth_mm'] ?> mm ·
                Rails: 19″ · <?= $height ?>U (<?= App::e(rtrim(rtrim(sprintf('%.2F', $aspectH), '0'), '.')) ?>″ tall)
            </p>
            <p class="mb-0" style="font-size:.85rem;margin-top:.25rem">
                <?php if ($lastCabinetAudit): ?>
                    <span class="text-muted">Last audit
                        <?= App::e(date('Y-m-d H:i', strtotime((string)$lastCabinetAudit['audited_at']))) ?>
                        by <?= App::e((string)($lastCabinetAudit['audited_by_name'] ?? '—')) ?>
                    </span>
                <?php else: ?>
                    <span class="text-muted">No cabinet audit logged yet</span>
                <?php endif; ?>
                ·
                <span class="badge <?= App::e(audit_status_badge_class($cabAuditSchedule['status'])) ?>">
                    <?= App::e(audit_status_label($cabAuditSchedule['status'])) ?>
                </span>
                <?php if ($cabAuditSchedule['next_due']): ?>
                    <span class="text-muted">
                        · Next due <strong><?= App::e($cabAuditSchedule['next_due']) ?></strong>
                        (every <?= (int)$cabAuditInterval ?>d)
                    </span>
                <?php else: ?>
                    <span class="text-muted">· Schedule every <?= (int)$cabAuditInterval ?>d from first audit</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex gap-1">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cabinets.php')) ?>">← All Cabinets</a>
            <?php if ($cabinetAuditCanLog): ?>
                <button type="button" class="btn btn-secondary"
                        data-audit-cabinet="<?= (int)$id ?>"
                        data-audit-name="<?= App::e($cab['name']) ?>">✓ Audit</button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" id="btnAddPdu">+ PDU</button>
            <a class="btn btn-primary" href="<?= App::e(App::url('pages/devices.php?action=new&cabinet_id=' . $id)) ?>">+ Device</a>
        </div>
    </div>

    <!-- Three columns: Front | Rear | Properties -->
    <div class="rack-detail-grid">
        <div class="card rack-col">
            <div class="card-body">
                <?php $renderRackFace('front', $devices, $uMountedPdus, $height, $aspectW, $aspectH, $id, $uOccupiedFront, $deviceOnFace, $deviceImage); ?>
            </div>
        </div>
        <div class="card rack-col">
            <div class="card-body">
                <?php $renderRackFace('rear', $devices, $uMountedPdus, $height, $aspectW, $aspectH, $id, $uOccupiedRear, $deviceOnFace, $deviceImage); ?>
            </div>
        </div>
        <div class="card rack-col rack-props-col">
            <div class="card-header"><h2>Rack properties</h2></div>
            <div class="card-body">
                <div class="metrics" style="margin:0 0 1rem">
                    <div class="metric-card"><div class="label">U Used</div><div class="value"><?= $used ?></div></div>
                    <div class="metric-card"><div class="label">U Free</div><div class="value"><?= max(0, $height - $used) ?></div></div>
                    <div class="metric-card success"><div class="label">Utilization</div><div class="value"><?= $pct ?>%</div></div>
                </div>
                <dl class="rack-prop-list">
                    <div><dt>Name</dt><dd><?= App::e($cab['name']) ?></dd></div>
                    <div><dt>Data center</dt><dd><?= App::e($cab['dc_name']) ?></dd></div>
                    <div><dt>Room</dt><dd><?= App::e($cab['room_name']) ?></dd></div>
                    <div><dt>U height</dt><dd><?= $height ?>U</dd></div>
                    <div><dt>Rail width</dt><dd>19 in (EIA-310)</dd></div>
                    <div><dt>U pitch</dt><dd>1.75 in</dd></div>
                    <div><dt>Bay ratio</dt><dd>19 : <?= App::e(rtrim(rtrim(sprintf('%.2F', $aspectH), '0'), '.')) ?></dd></div>
                    <div><dt>Footprint</dt><dd><?= (int)$cab['width_mm'] ?> × <?= (int)$cab['depth_mm'] ?> mm</dd></div>
                </dl>
            </div>

            <div class="card-header" style="border-top:1px solid var(--border)">
                <div class="flex-between" style="width:100%">
                    <h2 style="margin:0">PDUs</h2>
                    <button type="button" class="btn btn-sm btn-secondary" id="btnAddPduProps">+ Add</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (!$pdus): ?>
                    <p class="text-muted mb-0" style="font-size:.88rem">No PDUs on this cabinet yet.</p>
                <?php else: ?>
                    <div class="pdu-box-list" id="pduBoxList">
                        <?php foreach ($pdus as $p):
                            $ms = ($p['mount_style'] ?? 'vertical_rear') === 'u_mounted' ? 'U-mounted' : 'Vertical rear';
                            $sub = $ms;
                            if (($p['mount_style'] ?? '') === 'u_mounted' && $p['position_u'] !== null) {
                                $uh = max(1, (int)($p['u_height'] ?? 1));
                                $sub .= ' · U' . (int)$p['position_u'] . '–' . ((int)$p['position_u'] + $uh - 1);
                            }
                            if (!empty($p['rated_amps'])) {
                                $sub .= ' · ' . rtrim(rtrim(sprintf('%.1F', (float)$p['rated_amps']), '0'), '.') . 'A';
                            }
                            ?>
                            <button type="button" class="pdu-box" data-pdu-id="<?= (int)$p['pdu_id'] ?>">
                                <span class="pdu-box-name"><?= App::e($p['name']) ?></span>
                                <span class="pdu-box-meta"><?= App::e($sub) ?></span>
                                <?php if (!empty($p['snmp_enabled'])): ?>
                                    <span class="badge badge-success pdu-box-badge">SNMP v<?= App::e((string)$p['snmp_version']) ?></span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card-header" style="border-top:1px solid var(--border)"><h2>Devices</h2></div>
            <div class="card-body flush">
                <table class="data">
                    <thead>
                    <tr><th>U</th><th>Label</th><th>Mount</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$devices): ?>
                        <tr><td colspan="4" class="text-muted">Empty rack.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($devices as $d):
                        $half = !empty($d['half_depth']);
                        $rear = !empty($d['back_side']);
                        $mount = $half ? ($rear ? 'Half · Rear' : 'Half · Front') : 'Full';
                        ?>
                        <tr>
                            <td><?= $d['position_u'] !== null ? (int)$d['position_u'] . '–' . ((int)$d['position_u'] + (int)$d['u_height'] - 1) : '—' ?></td>
                            <td><a href="<?= App::e(App::url('pages/devices.php?id=' . $d['device_id'])) ?>"><?= App::e($d['label']) ?></a></td>
                            <td><?= App::e($mount) ?></td>
                            <td><span class="badge badge-info"><?= App::e($d['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-header" style="border-top:1px solid var(--border)">
                <div class="flex-between" style="width:100%">
                    <h2 style="margin:0">Cabinet audits</h2>
                    <?php if ($cabinetAuditCanLog): ?>
                        <button type="button" class="btn btn-sm btn-secondary"
                                data-audit-cabinet="<?= (int)$id ?>"
                                data-audit-name="<?= App::e($cab['name']) ?>">+ Log audit</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body" style="padding-bottom:.5rem;font-size:.85rem">
                <span class="badge <?= App::e(audit_status_badge_class($cabAuditSchedule['status'])) ?>">
                    <?= App::e(audit_status_label($cabAuditSchedule['status'])) ?>
                </span>
                <?php if ($cabAuditSchedule['next_due']): ?>
                    Next due <strong><?= App::e($cabAuditSchedule['next_due']) ?></strong>
                    · cadence <?= (int)$cabAuditInterval ?> days
                    <?php if (!empty($cab['audit_interval_days'])): ?>
                        <span class="text-muted">(cabinet override)</span>
                    <?php else: ?>
                        <span class="text-muted">(site default)</span>
                    <?php endif; ?>
                <?php else: ?>
                    Log an audit to start the schedule (every <?= (int)$cabAuditInterval ?> days).
                <?php endif; ?>
            </div>
            <div class="card-body flush">
                <table class="data" id="cabinetAuditHistory">
                    <thead>
                    <tr><th>When</th><th>Auditor</th><th>Certified</th><th>Comments</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$cabinetAudits): ?>
                        <tr class="cab-audit-empty"><td colspan="4" class="text-muted">No audits logged yet for this cabinet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($cabinetAudits as $au): ?>
                        <tr>
                            <td style="white-space:nowrap;font-size:.85rem">
                                <?= App::e(date('Y-m-d H:i', strtotime((string)$au['audited_at']))) ?>
                            </td>
                            <td><?= App::e((string)($au['audited_by_name'] ?? '—')) ?></td>
                            <td><?= !empty($au['certified']) ? '✓' : '—' ?></td>
                            <td style="font-size:.85rem"><?= App::e((string)($au['comments'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PDU detail / create overlay -->
    <div class="modal-overlay" id="pduModal" hidden>
        <div class="modal-panel modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="pduModalTitle">
            <div class="modal-header">
                <h2 id="pduModalTitle">PDU</h2>
                <button type="button" class="modal-close" id="pduModalClose" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body" id="pduModalBody">
                <p class="text-muted">Loading…</p>
            </div>
            <div class="modal-footer" id="pduModalFooter">
                <button type="button" class="btn btn-secondary" id="pduModalCancel">Close</button>
                <button type="button" class="btn btn-danger" id="pduModalDelete" hidden>Delete</button>
                <button type="button" class="btn btn-primary" id="pduModalSave">Save</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var cabinetId = <?= (int)$id ?>;
        var rackU = <?= (int)$height ?>;
        var zones = <?= json_encode(array_map(static fn($z) => [
            'zone_id' => (int)$z['zone_id'],
            'name' => $z['name'],
        ], $powerZones), JSON_UNESCAPED_UNICODE) ?>;
        var connectorTypes = <?= json_encode($connectorTypes) ?>;
        var inputTypes = <?= json_encode($inputTypes) ?>;

        var modal = document.getElementById('pduModal');
        var body = document.getElementById('pduModalBody');
        var titleEl = document.getElementById('pduModalTitle');
        var btnSave = document.getElementById('pduModalSave');
        var btnDelete = document.getElementById('pduModalDelete');
        var btnClose = document.getElementById('pduModalClose');
        var btnCancel = document.getElementById('pduModalCancel');
        var currentPduId = null;
        var cabinetDevices = [];

        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function openModal() {
            modal.hidden = false;
            document.body.classList.add('modal-open');
        }
        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('modal-open');
            currentPduId = null;
        }
        btnClose.addEventListener('click', closeModal);
        btnCancel.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });

        function optionsHtml(list, selected, allowBlank, blankLabel) {
            var html = '';
            if (allowBlank) {
                html += '<option value="">' + esc(blankLabel || '—') + '</option>';
            }
            list.forEach(function (item) {
                var val = typeof item === 'object' ? item.value : item;
                var lab = typeof item === 'object' ? item.label : item;
                html += '<option value="' + esc(val) + '"' +
                    (String(selected || '') === String(val) ? ' selected' : '') +
                    '>' + esc(lab) + '</option>';
            });
            return html;
        }

        function wiringOptionsForPhases(p) {
            p = parseInt(p, 10) || 1;
            if (p === 2) {
                return [
                    { value: 'split_phase', label: 'Split-phase (L1/L2/N · 120/240)' },
                    { value: 'two_phase', label: 'Two-phase (L1/L2)' }
                ];
            }
            if (p === 3) {
                return [
                    { value: 'wye', label: 'Wye / star (3P+N)' },
                    { value: 'delta', label: 'Delta (3P)' }
                ];
            }
            return [{ value: 'single', label: 'Single-phase' }];
        }

        function mountFieldsToggle() {
            var style = body.querySelector('[name="mount_style"]');
            var scope = body.querySelector('[name="pdu_scope"]');
            var isRack = !scope || scope.value === 'rack';
            var isU = style && style.value === 'u_mounted';
            body.querySelectorAll('.pdu-rack-fields').forEach(function (el) {
                el.style.display = isRack ? '' : 'none';
            });
            body.querySelectorAll('.pdu-u-fields').forEach(function (el) {
                el.style.display = (isRack && isU) ? '' : 'none';
            });
            body.querySelectorAll('.pdu-zone-sync').forEach(function (el) {
                el.style.display = (scope && (scope.value === 'row' || scope.value === 'room')) ? '' : 'none';
            });
        }

        function phaseFieldsToggle(applyPreset) {
            var phEl = body.querySelector('[name="phases"]');
            var wrEl = body.querySelector('[name="phase_wiring"]');
            if (!phEl || !wrEl) return;
            var p = parseInt(phEl.value, 10) || 1;
            var opts = wiringOptionsForPhases(p);
            var prev = wrEl.value;
            wrEl.innerHTML = opts.map(function (o) {
                return '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>';
            }).join('');
            var keep = opts.some(function (o) { return o.value === prev; });
            wrEl.value = keep ? prev : opts[0].value;
            var w = wrEl.value;
            var showLn = (p === 2 && w === 'split_phase') || (p === 3 && w === 'wye');
            body.querySelectorAll('.pdu-ln-fields').forEach(function (el) {
                el.style.display = showLn ? '' : 'none';
            });
            body.querySelectorAll('.pdu-out-ln-fields').forEach(function (el) {
                el.style.display = showLn ? '' : 'none';
            });
            body.querySelectorAll('.pdu-in-ll-label').forEach(function (el) {
                el.textContent = p > 1 ? 'Input voltage (L–L)' : 'Input voltage';
            });
            body.querySelectorAll('.pdu-out-label').forEach(function (el) {
                el.textContent = p > 1 ? 'Output voltage (L–L)' : 'Output voltage';
            });
            if (applyPreset) {
                var presets = {
                    '1|single': [120, '', 120, ''],
                    '2|split_phase': [240, 120, 120, 120],
                    '2|two_phase': [240, '', 240, ''],
                    '3|wye': [208, 120, 208, 120],
                    '3|delta': [208, '', 208, '']
                };
                var pr = presets[p + '|' + w] || presets['1|single'];
                var set = function (n, v) {
                    var el = body.querySelector('[name="' + n + '"]');
                    if (el) el.value = v;
                };
                set('input_voltage', pr[0]);
                set('input_voltage_ln', pr[1]);
                set('output_voltage', pr[2]);
                set('output_voltage_ln', pr[3]);
            }
        }

        function snmpFieldsToggle() {
            var ver = body.querySelector('[name="snmp_version"]');
            var en = body.querySelector('[name="snmp_enabled"]');
            var enabled = en && en.checked;
            var v = ver ? ver.value : '2c';
            body.querySelectorAll('.snmp-any').forEach(function (el) {
                el.style.display = enabled ? '' : 'none';
            });
            body.querySelectorAll('.snmp-v12').forEach(function (el) {
                el.style.display = enabled && (v === '1' || v === '2c') ? '' : 'none';
            });
            body.querySelectorAll('.snmp-v3').forEach(function (el) {
                el.style.display = enabled && v === '3' ? '' : 'none';
            });
        }

        function renderForm(pdu, isNew) {
            pdu = pdu || {};
            currentPduId = isNew ? null : (pdu.pdu_id || null);
            titleEl.textContent = isNew ? 'Add PDU' : ('PDU: ' + (pdu.name || ''));
            btnDelete.hidden = !!isNew;
            var outlets = pdu.outlets || [];

            var zoneOpts = zones.map(function (z) {
                return { value: z.zone_id, label: z.name };
            });

            var html = '<form id="pduForm" class="pdu-form" onsubmit="return false">';
            html += '<div class="form-grid">';
            html += '<div class="form-row"><label>Name</label><input class="form-control" name="name" required value="' + esc(pdu.name || '') + '"></div>';
            html += '<div class="form-row"><label>Vendor</label><input class="form-control" name="manufacturer" value="' + esc(pdu.manufacturer || '') + '"></div>';
            html += '<div class="form-row"><label>Model</label><input class="form-control" name="model" value="' + esc(pdu.model || '') + '"></div>';
            html += '<div class="form-row"><label>Scope</label><select class="form-control" name="pdu_scope">' +
                optionsHtml([
                    { value: 'rack', label: 'Rack PDU' },
                    { value: 'row', label: 'Row PDU' },
                    { value: 'room', label: 'Room PDU' }
                ], pdu.pdu_scope || 'rack', false) + '</select></div>';
            html += '<div class="form-row"><label>Input connector</label><select class="form-control" name="input_type">' +
                optionsHtml(inputTypes, pdu.input_type, true) + '</select></div>';
            html += '<div class="form-row"><label>AMP rating</label><input class="form-control" type="number" step="0.1" name="rated_amps" value="' + esc(pdu.rated_amps != null ? pdu.rated_amps : '30') + '"></div>';
            html += '<div class="form-row"><label>IP address</label><input class="form-control" name="ip_address" value="' + esc(pdu.ip_address || '') + '"></div>';
            html += '<div class="form-row"><label>Zone</label><select class="form-control" name="zone_id">' +
                optionsHtml(zoneOpts, pdu.zone_id, true) + '</select></div>';

            // Electrical topology
            var ph = parseInt(pdu.phases, 10) || 1;
            var pw = pdu.phase_wiring || (ph === 3 ? 'wye' : (ph === 2 ? 'split_phase' : 'single'));
            html += '<div class="form-row full"><strong style="font-size:.85rem;color:var(--muted)">Electrical</strong></div>';
            html += '<div class="form-row"><label>Phases</label><select class="form-control" name="phases">' +
                optionsHtml([
                    { value: '1', label: 'Single-phase (1φ)' },
                    { value: '2', label: 'Two-phase / split-phase (2φ)' },
                    { value: '3', label: 'Three-phase (3φ)' }
                ], String(ph), false) + '</select></div>';
            html += '<div class="form-row"><label>Wiring</label><select class="form-control" name="phase_wiring" id="pduPhaseWiring">' +
                optionsHtml(wiringOptionsForPhases(ph), pw, false) + '</select></div>';
            html += '<div class="form-row"><label class="pdu-in-ll-label">Input voltage' + (ph > 1 ? ' (L–L)' : '') + '</label>' +
                '<input class="form-control" type="number" name="input_voltage" value="' +
                esc(pdu.input_voltage != null ? pdu.input_voltage : (pdu.rated_volts != null ? pdu.rated_volts : '208')) + '"></div>';
            html += '<div class="form-row pdu-ln-fields"><label>Input voltage (L–N)</label>' +
                '<input class="form-control" type="number" name="input_voltage_ln" value="' + esc(pdu.input_voltage_ln != null ? pdu.input_voltage_ln : '') + '"></div>';
            html += '<div class="form-row"><label class="pdu-out-label">Output voltage' + (ph > 1 ? ' (L–L)' : '') + '</label>' +
                '<input class="form-control" type="number" name="output_voltage" value="' +
                esc(pdu.output_voltage != null ? pdu.output_voltage : '208') + '"></div>';
            html += '<div class="form-row pdu-out-ln-fields"><label>Output voltage (L–N)</label>' +
                '<input class="form-control" type="number" name="output_voltage_ln" value="' + esc(pdu.output_voltage_ln != null ? pdu.output_voltage_ln : '') + '"></div>';
            html += '<div class="form-row full pdu-zone-sync"><label><input type="checkbox" name="sync_zone_voltage" value="1"' +
                ((pdu.sync_zone_voltage === undefined || pdu.sync_zone_voltage) ? ' checked' : '') +
                '> Auto-update power zone voltage from input (row/room PDUs)</label></div>';

            html += '<div class="form-row pdu-rack-fields"><label>Mount style</label><select class="form-control" name="mount_style">' +
                '<option value="vertical_rear"' + ((pdu.mount_style || 'vertical_rear') === 'vertical_rear' ? ' selected' : '') + '>Vertical rear (0U rails)</option>' +
                '<option value="u_mounted"' + ((pdu.mount_style || '') === 'u_mounted' ? ' selected' : '') + '>U-mounted (rack positions)</option>' +
                '</select></div>';
            html += '<div class="form-row pdu-u-fields"><label>Position (U)</label><input class="form-control" type="number" min="1" max="' + rackU + '" name="position_u" value="' + esc(pdu.position_u != null ? pdu.position_u : '') + '"></div>';
            html += '<div class="form-row pdu-u-fields"><label>U height</label><input class="form-control" type="number" min="1" max="10" name="u_height" value="' + esc(pdu.u_height != null ? pdu.u_height : '1') + '"></div>';
            html += '<div class="form-row"><label>Number of outlets</label><input class="form-control" type="number" min="1" max="128" name="num_outlets" value="' + esc(pdu.num_outlets != null ? pdu.num_outlets : '24') + '"></div>';
            html += '<div class="form-row"><label>Default outlet type</label><select class="form-control" name="outlet_type">' +
                optionsHtml(connectorTypes, (outlets[0] && outlets[0].outlet_type) || 'C13', false) + '</select></div>';

            html += '<div class="form-row full"><label><input type="checkbox" name="snmp_enabled" value="1"' +
                (pdu.snmp_enabled ? ' checked' : '') + '> Enable SNMP</label></div>';
            html += '<div class="form-row snmp-any"><label>SNMP version</label><select class="form-control" name="snmp_version">' +
                optionsHtml([
                    { value: '1', label: '1' },
                    { value: '2c', label: '2c' },
                    { value: '3', label: '3' }
                ], pdu.snmp_version || '2c', false) + '</select></div>';
            html += '<div class="form-row snmp-any"><label>SNMP port</label><input class="form-control" type="number" name="snmp_port" value="' + esc(pdu.snmp_port != null ? pdu.snmp_port : '161') + '"></div>';
            html += '<div class="form-row snmp-v12"><label>Public community</label><input class="form-control" name="snmp_community" value="' + esc(pdu.snmp_community || 'public') + '" autocomplete="off"></div>';
            html += '<div class="form-row snmp-v3"><label>Security level</label><select class="form-control" name="snmp_v3_sec_level">' +
                optionsHtml(['noAuthNoPriv', 'authNoPriv', 'authPriv'], pdu.snmp_v3_sec_level || 'authPriv', true) + '</select></div>';
            html += '<div class="form-row snmp-v3"><label>Security name (user)</label><input class="form-control" name="snmp_security_name" value="' + esc(pdu.snmp_security_name || '') + '"></div>';
            html += '<div class="form-row snmp-v3"><label>Auth protocol</label><select class="form-control" name="snmp_auth_protocol">' +
                optionsHtml(['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'], pdu.snmp_auth_protocol, true) + '</select></div>';
            html += '<div class="form-row snmp-v3"><label>Auth passphrase</label><input class="form-control" type="password" name="snmp_auth_passphrase" value="' + esc(pdu.snmp_auth_passphrase || '') + '" autocomplete="new-password"></div>';
            html += '<div class="form-row snmp-v3"><label>Priv protocol</label><select class="form-control" name="snmp_priv_protocol">' +
                optionsHtml(['DES', 'AES', 'AES192', 'AES256'], pdu.snmp_priv_protocol, true) + '</select></div>';
            html += '<div class="form-row snmp-v3"><label>Priv passphrase</label><input class="form-control" type="password" name="snmp_priv_passphrase" value="' + esc(pdu.snmp_priv_passphrase || '') + '" autocomplete="new-password"></div>';
            html += '<div class="form-row snmp-v3"><label>Context</label><input class="form-control" name="snmp_context" value="' + esc(pdu.snmp_context || '') + '"></div>';
            html += '<div class="form-row full"><label>Notes</label><textarea class="form-control" name="notes" rows="2">' + esc(pdu.notes || '') + '</textarea></div>';
            html += '</div>';

            if (!isNew) {
                html += '<h3 class="pdu-outlets-heading">Outlets</h3>';
                html += '<div class="table-wrap pdu-outlets-wrap"><table class="data pdu-outlets-table"><thead><tr>';
                html += '<th>#</th><th>NEMA / type</th><th>AMP</th><th>Device</th><th>Power supply</th>';
                html += '</tr></thead><tbody>';
                outlets.forEach(function (o) {
                    html += '<tr data-outlet-id="' + esc(o.outlet_id) + '">';
                    html += '<td>' + esc(o.outlet_number) + '</td>';
                    html += '<td><select class="form-control form-control-sm o-type">' +
                        optionsHtml(connectorTypes, o.outlet_type || 'C13', false) + '</select></td>';
                    html += '<td><input class="form-control form-control-sm o-amps" type="number" step="0.1" style="width:4.5rem" value="' + esc(o.rated_amps != null ? o.rated_amps : '') + '"></td>';
                    html += '<td><select class="form-control form-control-sm o-device">' +
                        deviceOptionsHtml(o.connected_device_id) + '</select></td>';
                    html += '<td><select class="form-control form-control-sm o-psu">' +
                        psuOptionsHtml(o.connected_device_id, o.device_power_supply_id) + '</select></td>';
                    html += '</tr>';
                });
                if (!outlets.length) {
                    html += '<tr><td colspan="5" class="text-muted">No outlets yet. Save with an outlet count to generate them.</td></tr>';
                }
                html += '</tbody></table></div>';
                html += '<p class="text-muted" style="font-size:.78rem;margin:.5rem 0 0">Selecting a device power supply maps this outlet ↔ PSU for power path tracking.</p>';
            }
            html += '</form>';
            body.innerHTML = html;

            var form = body.querySelector('#pduForm');
            form.querySelector('[name="mount_style"]').addEventListener('change', mountFieldsToggle);
            form.querySelector('[name="pdu_scope"]').addEventListener('change', mountFieldsToggle);
            form.querySelector('[name="phases"]').addEventListener('change', function () { phaseFieldsToggle(true); });
            form.querySelector('[name="phase_wiring"]').addEventListener('change', function () { phaseFieldsToggle(true); });
            form.querySelector('[name="snmp_enabled"]').addEventListener('change', snmpFieldsToggle);
            form.querySelector('[name="snmp_version"]').addEventListener('change', snmpFieldsToggle);
            mountFieldsToggle();
            phaseFieldsToggle(false);
            snmpFieldsToggle();

            body.querySelectorAll('.o-device').forEach(function (sel) {
                sel.addEventListener('change', function () {
                    var row = sel.closest('tr');
                    var psu = row.querySelector('.o-psu');
                    psu.innerHTML = psuOptionsHtml(sel.value, null);
                });
            });
        }

        function deviceOptionsHtml(selectedId) {
            var html = '<option value="">— Unassigned —</option>';
            cabinetDevices.forEach(function (d) {
                html += '<option value="' + d.device_id + '"' +
                    (String(selectedId || '') === String(d.device_id) ? ' selected' : '') +
                    '>' + esc(d.label) + '</option>';
            });
            return html;
        }

        function psuOptionsHtml(deviceId, selectedPsuId) {
            var html = '<option value="">—</option>';
            if (!deviceId) return html;
            var dev = cabinetDevices.find(function (d) { return String(d.device_id) === String(deviceId); });
            if (!dev) return html;
            (dev.power_supplies || []).forEach(function (ps) {
                html += '<option value="' + ps.power_supply_id + '"' +
                    (String(selectedPsuId || '') === String(ps.power_supply_id) ? ' selected' : '') +
                    '>' + esc(ps.name || ('PSU ' + ps.power_supply_id)) +
                    (ps.watts != null ? ' (' + ps.watts + 'W)' : '') + '</option>';
            });
            if (!(dev.power_supplies || []).length) {
                html += '<option value="" disabled>No PSUs on device — add under device properties</option>';
            }
            return html;
        }

        function collectForm() {
            var form = body.querySelector('#pduForm');
            if (!form) return null;
            var g = function (n) {
                var el = form.querySelector('[name="' + n + '"]');
                if (!el) return null;
                if (el.type === 'checkbox') return el.checked ? 1 : 0;
                return el.value;
            };
            var data = {
                name: g('name'),
                manufacturer: g('manufacturer'),
                model: g('model'),
                input_type: g('input_type'),
                rated_amps: g('rated_amps'),
                input_voltage: g('input_voltage'),
                input_voltage_ln: g('input_voltage_ln'),
                output_voltage: g('output_voltage'),
                output_voltage_ln: g('output_voltage_ln'),
                phases: parseInt(g('phases'), 10) || 1,
                phase_wiring: g('phase_wiring'),
                sync_zone_voltage: g('sync_zone_voltage'),
                ip_address: g('ip_address'),
                zone_id: g('zone_id') || null,
                mount_style: g('mount_style'),
                position_u: g('position_u') || null,
                u_height: g('u_height') || null,
                num_outlets: parseInt(g('num_outlets'), 10) || 24,
                outlet_type: g('outlet_type'),
                snmp_enabled: g('snmp_enabled'),
                snmp_version: g('snmp_version'),
                snmp_port: g('snmp_port'),
                snmp_community: g('snmp_community'),
                snmp_v3_sec_level: g('snmp_v3_sec_level'),
                snmp_security_name: g('snmp_security_name'),
                snmp_auth_protocol: g('snmp_auth_protocol'),
                snmp_auth_passphrase: g('snmp_auth_passphrase'),
                snmp_priv_protocol: g('snmp_priv_protocol'),
                snmp_priv_passphrase: g('snmp_priv_passphrase'),
                snmp_context: g('snmp_context'),
                notes: g('notes'),
                cabinet_id: cabinetId,
                pdu_scope: g('pdu_scope') || 'rack'
            };
            if (currentPduId) {
                data.pdu_id = currentPduId;
                data.outlets = [];
                body.querySelectorAll('.pdu-outlets-table tbody tr[data-outlet-id]').forEach(function (row) {
                    data.outlets.push({
                        outlet_id: parseInt(row.getAttribute('data-outlet-id'), 10),
                        outlet_type: row.querySelector('.o-type').value,
                        rated_amps: row.querySelector('.o-amps').value || null,
                        connected_device_id: row.querySelector('.o-device').value || null,
                        device_power_supply_id: row.querySelector('.o-psu').value || null
                    });
                });
            }
            return data;
        }

        async function openPdu(id) {
            openModal();
            body.innerHTML = '<p class="text-muted">Loading…</p>';
            btnDelete.hidden = true;
            try {
                var res = await ColdAisle.api('api/pdus.php?id=' + id);
                cabinetDevices = res.cabinet_devices || [];
                renderForm(res.pdu, false);
            } catch (err) {
                body.innerHTML = '<p class="text-danger">' + esc(err.message) + '</p>';
            }
        }

        function openNewPdu() {
            cabinetDevices = [];
            openModal();
            renderForm({
                name: '',
                mount_style: 'vertical_rear',
                num_outlets: 24,
                rated_amps: 30,
                rated_volts: 208,
                snmp_version: '2c'
            }, true);
        }

        document.querySelectorAll('.pdu-box, .rack-pdu-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-pdu-id'), 10);
                if (id) openPdu(id);
            });
        });
        var b1 = document.getElementById('btnAddPdu');
        var b2 = document.getElementById('btnAddPduProps');
        if (b1) b1.addEventListener('click', openNewPdu);
        if (b2) b2.addEventListener('click', openNewPdu);

        btnSave.addEventListener('click', async function () {
            var data = collectForm();
            if (!data || !data.name) {
                ColdAisle.toast('Name is required', 'danger');
                return;
            }
            btnSave.disabled = true;
            try {
                if (currentPduId) {
                    await ColdAisle.api('api/pdus.php', {
                        method: 'PUT',
                        forcePostOverride: true,
                        body: data
                    });
                    ColdAisle.toast('PDU saved', 'success');
                } else {
                    await ColdAisle.api('api/pdus.php', {
                        method: 'POST',
                        body: data
                    });
                    ColdAisle.toast('PDU created', 'success');
                }
                location.reload();
            } catch (err) {
                ColdAisle.toast(err.message || 'Save failed', 'danger');
                btnSave.disabled = false;
            }
        });

        btnDelete.addEventListener('click', async function () {
            if (!currentPduId) return;
            if (!confirm('Deactivate this PDU?')) return;
            btnDelete.disabled = true;
            try {
                await ColdAisle.api('api/pdus.php?id=' + currentPduId, {
                    method: 'DELETE',
                    forcePostOverride: true
                });
                ColdAisle.toast('PDU removed', 'success');
                location.reload();
            } catch (err) {
                ColdAisle.toast(err.message || 'Delete failed', 'danger');
                btnDelete.disabled = false;
            }
        });
    })();
    </script>
    <script>
    window.ColdAisle = window.ColdAisle || {};
    window.ColdAisle.refreshCabinetAuditHistory = function (cabinetId, audit) {
        var tbody = document.querySelector('#cabinetAuditHistory tbody');
        if (!tbody || !audit) return;
        var empty = tbody.querySelector('.cab-audit-empty');
        if (empty) empty.remove();
        var tr = document.createElement('tr');
        var when = audit.audited_at ? String(audit.audited_at).replace('T', ' ').slice(0, 16) : 'just now';
        tr.innerHTML =
            '<td style="white-space:nowrap;font-size:.85rem">' + when + '</td>' +
            '<td>' + (audit.audited_by_name || '—') + '</td>' +
            '<td>✓</td>' +
            '<td style="font-size:.85rem">' + (audit.comments ? String(audit.comments) : '—') + '</td>';
        tbody.insertBefore(tr, tbody.firstChild);
    };
    </script>
    <?php
    require __DIR__ . '/_cabinet_audit_modal.php';
    layout_footer();
    exit;
}

// ---------- Helpers: natural sort + front-view left→right order ----------
$naturalCmp = static function (string $a, string $b): int {
    return strnatcasecmp($a, $b);
};

/**
 * Sort cabinets as seen standing in the cold aisle looking at fronts.
 * pos_x grows east, pos_y grows north (plan meters).
 */
$sortCabinetsFrontView = static function (array &$cabs) use ($naturalCmp): string {
    if (!$cabs) {
        return 'north';
    }
    // Dominant facing
    $counts = [];
    foreach ($cabs as $c) {
        $f = strtolower((string)($c['front_facing'] ?? 'north'));
        if (!in_array($f, ['north', 'south', 'east', 'west'], true)) {
            $f = 'north';
        }
        $counts[$f] = ($counts[$f] ?? 0) + 1;
    }
    arsort($counts);
    $facing = (string)array_key_first($counts);

    usort($cabs, static function ($a, $b) use ($facing, $naturalCmp) {
        $ax = (float)($a['pos_x'] ?? 0);
        $ay = (float)($a['pos_y'] ?? 0);
        $bx = (float)($b['pos_x'] ?? 0);
        $by = (float)($b['pos_y'] ?? 0);
        // Looking at fronts: L→R mapping by compass
        $cmp = 0;
        switch ($facing) {
            case 'north': // look north → L is west → ascending x
                $cmp = $ax <=> $bx;
                if (abs($ax - $bx) < 0.001) {
                    $cmp = $ay <=> $by;
                }
                break;
            case 'south': // look south → L is east → descending x
                $cmp = $bx <=> $ax;
                if (abs($ax - $bx) < 0.001) {
                    $cmp = $by <=> $ay;
                }
                break;
            case 'east': // look east → L is north → descending y (if y north)
                $cmp = $by <=> $ay;
                if (abs($ay - $by) < 0.001) {
                    $cmp = $ax <=> $bx;
                }
                break;
            case 'west': // look west → L is south → ascending y
                $cmp = $ay <=> $by;
                if (abs($ay - $by) < 0.001) {
                    $cmp = $bx <=> $ax;
                }
                break;
        }
        if ($cmp !== 0) {
            return $cmp;
        }
        return $naturalCmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    return $facing;
};

// ========== Row View: fronts of all cabinets in a row ==========
$rowId = isset($_GET['row_id']) ? (int)$_GET['row_id'] : 0;
if ($rowId) {
    $rowMeta = Database::fetchOne(
        'SELECT cr.*, rm.name AS room_name, dc.name AS dc_name, dc.datacenter_id,
                z.name AS zone_name, z.color_hex AS zone_color
         FROM cabinet_rows cr
         INNER JOIN rooms rm ON rm.room_id = cr.room_id
         INNER JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         LEFT JOIN power_zones z ON z.zone_id = cr.zone_id
         WHERE cr.row_id = ?',
        [$rowId]
    );
    if (!$rowMeta) {
        App::flash('error', 'Row not found.');
        App::redirect('pages/cabinets.php');
    }

    $rowCabs = Database::fetchAll(
        'SELECT c.*,
            (SELECT COUNT(*) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1) AS device_count,
            (SELECT ISNULL(SUM(d.u_height),0) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1 AND d.position_u IS NOT NULL) AS u_used,
            (SELECT ISNULL(SUM(d.nominal_watts),0) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1) AS device_watts,
            (SELECT ISNULL(SUM(p.last_poll_watts),0) FROM pdus p WHERE p.cabinet_id = c.cabinet_id AND p.is_active = 1) AS pdu_watts
         FROM cabinets c
         WHERE c.is_active = 1 AND c.row_id = ?
         ORDER BY c.name',
        [$rowId]
    );
    $facing = $sortCabinetsFrontView($rowCabs);

    $mediaUrl = static function (?string $rel): string {
        if (!$rel) {
            return '';
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        return App::url('media.php?f=' . rawurlencode($rel));
    };

    // Load devices per cabinet for front elevations
    foreach ($rowCabs as &$rc) {
        $rc['devices'] = Database::fetchAll(
            'SELECT d.*, t.front_picture AS tpl_front, t.rear_picture AS tpl_rear
             FROM devices d
             LEFT JOIN device_templates t ON t.template_id = d.template_id
             WHERE d.cabinet_id = ? AND d.is_active = 1 AND d.position_u IS NOT NULL
             ORDER BY d.position_u DESC',
            [(int)$rc['cabinet_id']]
        );
        $rc['u_mounted_pdus'] = [];
        try {
            $rc['u_mounted_pdus'] = Database::fetchAll(
                "SELECT * FROM pdus
                 WHERE cabinet_id = ? AND is_active = 1 AND mount_style = 'u_mounted' AND position_u IS NOT NULL",
                [(int)$rc['cabinet_id']]
            );
        } catch (Throwable $e) {
            $rc['u_mounted_pdus'] = [];
        }
    }
    unset($rc);

    $totalU = array_sum(array_map(static fn($c) => (int)$c['u_height'], $rowCabs));
    $usedU = array_sum(array_map(static fn($c) => (int)$c['u_used'], $rowCabs));
    $pollKw = array_sum(array_map(static fn($c) => (float)$c['pdu_watts'], $rowCabs)) / 1000.0;
    $devKw = array_sum(array_map(static fn($c) => (float)$c['device_watts'], $rowCabs)) / 1000.0;

    $facingHint = match ($facing) {
        'north' => 'Fronts face North · left→right is West→East',
        'south' => 'Fronts face South · left→right is East→West',
        'east' => 'Fronts face East · left→right is North→South',
        'west' => 'Fronts face West · left→right is South→North',
        default => 'Fronts · left→right by plan position',
    };

    layout_header('Row: ' . $rowMeta['name'], $user, 'cabinets');
    ?>
    <div class="flex-between mb-2">
        <div>
            <span class="text-muted"><?= App::e($rowMeta['dc_name'] . ' / ' . $rowMeta['room_name']) ?></span>
            <?php if (!empty($rowMeta['zone_name'])): ?>
                <span class="badge" style="margin-left:.35rem"><?= App::e($rowMeta['zone_name']) ?></span>
            <?php endif; ?>
            <p class="text-muted mb-0" style="font-size:.88rem"><?= App::e($facingHint) ?></p>
        </div>
        <div class="flex gap-1">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cabinets.php')) ?>">← Cabinets</a>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/floorplan.php')) ?>">Floor plan</a>
        </div>
    </div>

    <div class="metrics">
        <div class="metric-card accent"><div class="label">Cabinets</div><div class="value"><?= count($rowCabs) ?></div></div>
        <div class="metric-card success">
            <div class="label">U used</div>
            <div class="value"><?= $usedU ?><span class="metric-unit"> / <?= $totalU ?></span></div>
            <div class="sub"><?= $totalU ? round(100 * $usedU / $totalU, 1) : 0 ?>% capacity</div>
        </div>
        <div class="metric-card warning">
            <div class="label">PDU load</div>
            <div class="value"><?= number_format($pollKw, 1) ?><span class="metric-unit"> kW</span></div>
            <div class="sub">Device nameplate ~<?= number_format($devKw, 1) ?> kW</div>
        </div>
        <div class="metric-card">
            <div class="label">Facing</div>
            <div class="value" style="font-size:1.2rem;text-transform:uppercase"><?= App::e($facing) ?></div>
        </div>
    </div>

    <?php if (!$rowCabs): ?>
        <div class="card"><div class="card-body empty-state"><p>No cabinets in this row.</p></div></div>
    <?php else:
        $maxUnits = 1;
        foreach ($rowCabs as $rcTmp) {
            $maxUnits = max($maxUnits, (int)$rcTmp['u_height']);
        }
        ?>
        <div class="row-view-shell card">
            <div class="card-header flex-between" style="width:100%">
                <h2 style="margin:0">Front aisle · left → right</h2>
                <span class="text-muted" style="font-size:.8rem">Scroll or drag horizontally · height fits viewport</span>
            </div>
            <div class="row-view-viewport" id="rowViewViewport" title="Drag to pan · scroll horizontally">
        <div class="row-view-strip" style="--max-units: <?= (int)$maxUnits ?>;">
            <?php foreach ($rowCabs as $rc):
                $units = max(1, (int)$rc['u_height']);
                $aspectW = 19.0;
                $aspectH = $units * 1.75;
                $uOcc = [];
                foreach ($rc['devices'] as $d) {
                    if ($d['position_u'] === null) {
                        continue;
                    }
                    // Front face only (full depth or half front)
                    $half = !empty($d['half_depth']);
                    $rear = !empty($d['back_side']);
                    if ($half && $rear) {
                        continue;
                    }
                    $start = (int)$d['position_u'];
                    $uh = max(1, (int)$d['u_height']);
                    for ($u = $start; $u < $start + $uh; $u++) {
                        $uOcc[$u] = true;
                    }
                }
                foreach ($rc['u_mounted_pdus'] as $p) {
                    $start = (int)$p['position_u'];
                    $uh = max(1, (int)($p['u_height'] ?? 1));
                    for ($u = $start; $u < $start + $uh; $u++) {
                        $uOcc[$u] = true;
                    }
                }
                $pct = $units ? round(100 * (int)$rc['u_used'] / $units, 1) : 0;
                ?>
                <div class="row-view-cab">
                    <div class="row-view-cab-head">
                        <a href="?id=<?= (int)$rc['cabinet_id'] ?>"><strong><?= App::e($rc['name']) ?></strong></a>
                        <span class="text-muted" style="font-size:.75rem">
                            <?= (int)$rc['u_used'] ?>/<?= $units ?>U · <?= $pct ?>%
                        </span>
                    </div>
                    <div class="rack-elevation-v2 row-view-elevation"
                         style="--units: <?= $units ?>; --rail-in: <?= $aspectW ?>; --rack-h-in: <?= $aspectH ?>;">
                        <div class="rack-u-rail" aria-hidden="true">
                            <?php
                            // Sparse ticks for compact height
                            $tickStep = $units > 48 ? 4 : ($units > 30 ? 2 : 1);
                            for ($u = $units; $u >= 1; $u--):
                                $show = ($u === $units || $u === 1 || $u % $tickStep === 0);
                                ?>
                                <div class="rack-u-rail-tick<?= $show ? '' : ' tick-muted' ?>"><?= $show ? $u : '' ?></div>
                            <?php endfor; ?>
                        </div>
                        <div class="rack-bay">
                            <?php for ($u = 1; $u <= $units; $u++):
                                if (!empty($uOcc[$u])) {
                                    continue;
                                }
                                $bottomPct = (($u - 1) / $units) * 100;
                                $hPct = (1 / $units) * 100;
                                ?>
                                <div class="rack-empty-slot"
                                     style="bottom:<?= rtrim(rtrim(sprintf('%.4F', $bottomPct), '0'), '.') ?>%;height:<?= rtrim(rtrim(sprintf('%.4F', $hPct), '0'), '.') ?>%;pointer-events:none"></div>
                            <?php endfor; ?>
                            <?php foreach ($rc['devices'] as $d):
                                $half = !empty($d['half_depth']);
                                $rear = !empty($d['back_side']);
                                if ($half && $rear) {
                                    continue;
                                }
                                $pos = (int)$d['position_u'];
                                $uh = max(1, (int)$d['u_height']);
                                $bottomPct = (($pos - 1) / $units) * 100;
                                $hPct = ($uh / $units) * 100;
                                $front = $d['tpl_front'] ?? null;
                                $rearPic = $d['tpl_rear'] ?? null;
                                $img = $mediaUrl($front ?: $rearPic);
                                $typeClass = preg_replace('/[^a-z_]/', '', strtolower((string)$d['device_type']));
                                $label = $d['label'] ?? '';
                                $topU = $pos + $uh - 1;
                                ?>
                                <a class="rack-device type-<?= App::e($typeClass) ?>"
                                   href="<?= App::e(App::url('pages/devices.php?id=' . (int)$d['device_id'])) ?>"
                                   style="bottom:<?= rtrim(rtrim(sprintf('%.4F', $bottomPct), '0'), '.') ?>%;height:<?= rtrim(rtrim(sprintf('%.4F', $hPct), '0'), '.') ?>%;"
                                   title="<?= App::e($label . ' · U' . $pos . '–' . $topU) ?>">
                                    <?php if ($img !== ''): ?>
                                        <img class="rack-device-img" src="<?= App::e($img) ?>" alt="" loading="lazy">
                                    <?php endif; ?>
                                    <span class="rack-device-meta">
                                        <span class="rack-device-name"><?= App::e($label) ?></span>
                                        <span class="rack-device-u">U<?= $pos ?>–<?= $topU ?></span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                            <?php foreach ($rc['u_mounted_pdus'] as $p):
                                $pos = (int)$p['position_u'];
                                $uh = max(1, (int)($p['u_height'] ?? 1));
                                $bottomPct = (($pos - 1) / $units) * 100;
                                $hPct = ($uh / $units) * 100;
                                $topU = $pos + $uh - 1;
                                ?>
                                <div class="rack-device type-pdu"
                                     style="bottom:<?= rtrim(rtrim(sprintf('%.4F', $bottomPct), '0'), '.') ?>%;height:<?= rtrim(rtrim(sprintf('%.4F', $hPct), '0'), '.') ?>%;"
                                     title="<?= App::e(($p['name'] ?? 'PDU') . ' · U' . $pos) ?>">
                                    <span class="rack-device-meta">
                                        <span class="rack-device-name"><?= App::e($p['name'] ?? 'PDU') ?></span>
                                        <span class="rack-device-u">U<?= $pos ?>–<?= $topU ?></span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="row-view-cab-foot">
                        <a class="btn btn-sm btn-secondary" href="?id=<?= (int)$rc['cabinet_id'] ?>">Open</a>
                        <?php
                        $rowAuditCanLog = AuthManager::can($user, 'edit_audits')
                            || AuthManager::can($user, 'edit_infrastructure')
                            || AuthManager::can($user, 'edit_devices_all');
                        if ($rowAuditCanLog): ?>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    data-audit-cabinet="<?= (int)$rc['cabinet_id'] ?>"
                                    data-audit-name="<?= App::e($rc['name'] ?? '') ?>"
                                    title="Log cabinet audit">✓ Audit</button>
                        <?php endif; ?>
                        <span class="text-muted" style="font-size:.72rem;text-transform:uppercase">
                            <?= App::e($rc['front_facing'] ?? $facing) ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
            </div>
        </div>
        <script>
        (function () {
            var vp = document.getElementById('rowViewViewport');
            if (!vp) return;
            var down = false, startX = 0, startScroll = 0, moved = false;
            vp.addEventListener('pointerdown', function (e) {
                if (e.button !== 0) return;
                // Allow clicks on links/buttons without pan capture
                if (e.target.closest('a, button, input, select, textarea')) return;
                down = true;
                moved = false;
                startX = e.clientX;
                startScroll = vp.scrollLeft;
                vp.classList.add('is-panning');
                try { vp.setPointerCapture(e.pointerId); } catch (err) {}
            });
            vp.addEventListener('pointermove', function (e) {
                if (!down) return;
                var dx = e.clientX - startX;
                if (Math.abs(dx) > 3) moved = true;
                vp.scrollLeft = startScroll - dx;
            });
            function endPan(e) {
                if (!down) return;
                down = false;
                vp.classList.remove('is-panning');
                try { vp.releasePointerCapture(e.pointerId); } catch (err) {}
            }
            vp.addEventListener('pointerup', endPan);
            vp.addEventListener('pointercancel', endPan);
            // Prevent accidental navigation when dragging over a device link
            vp.addEventListener('click', function (e) {
                // After a pan, suppress accidental navigation on device links only
                if (moved && e.target.closest('a.rack-device, a[href*="devices"]')) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                moved = false;
            }, true);
        })();
        </script>
    <?php endif; ?>
    <?php
    $cabinetAuditCanLog = AuthManager::can($user, 'edit_audits')
        || AuthManager::can($user, 'edit_infrastructure')
        || AuthManager::can($user, 'edit_devices_all');
    require __DIR__ . '/_cabinet_audit_modal.php';
    layout_footer();
    exit;
}

// ========== List view: rows → cabinets ==========
$cabinets = Database::fetchAll(
    'SELECT c.*, r.name AS room_name, r.room_id, dc.name AS dc_name, dc.datacenter_id,
            cr.row_id, cr.name AS row_name, cr.color_hex AS row_color,
            z.name AS zone_name,
        (SELECT COUNT(*) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1) AS device_count,
        (SELECT ISNULL(SUM(d.u_height),0) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1 AND d.position_u IS NOT NULL) AS u_used,
        (SELECT ISNULL(SUM(d.nominal_watts),0) FROM devices d WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1) AS device_watts,
        (SELECT ISNULL(SUM(p.last_poll_watts),0) FROM pdus p WHERE p.cabinet_id = c.cabinet_id AND p.is_active = 1) AS pdu_watts
     FROM cabinets c
     INNER JOIN rooms r ON r.room_id = c.room_id
     INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
     LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
     LEFT JOIN power_zones z ON z.zone_id = cr.zone_id
     WHERE c.is_active = 1
     ORDER BY dc.name, r.name, cr.name, c.name'
);

// Also load empty rows (no cabinets yet)
$allRows = Database::fetchAll(
    'SELECT cr.*, rm.name AS room_name, rm.room_id, dc.name AS dc_name, dc.datacenter_id,
            z.name AS zone_name
     FROM cabinet_rows cr
     INNER JOIN rooms rm ON rm.room_id = cr.room_id
     INNER JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
     LEFT JOIN power_zones z ON z.zone_id = cr.zone_id
     ORDER BY dc.name, rm.name, cr.name'
);

// Group: DC → Room → Row
$tree = [];
foreach ($cabinets as $c) {
    $dcId = (int)$c['datacenter_id'];
    $rmId = (int)$c['room_id'];
    $rid = $c['row_id'] !== null ? (int)$c['row_id'] : 0;
    if (!isset($tree[$dcId])) {
        $tree[$dcId] = ['name' => $c['dc_name'], 'rooms' => []];
    }
    if (!isset($tree[$dcId]['rooms'][$rmId])) {
        $tree[$dcId]['rooms'][$rmId] = ['name' => $c['room_name'], 'rows' => []];
    }
    if (!isset($tree[$dcId]['rooms'][$rmId]['rows'][$rid])) {
        $tree[$dcId]['rooms'][$rmId]['rows'][$rid] = [
            'row_id' => $rid ?: null,
            'name' => $rid ? ($c['row_name'] ?? 'Row') : 'Unassigned',
            'color' => $c['row_color'] ?? null,
            'zone_name' => $c['zone_name'] ?? null,
            'cabinets' => [],
        ];
    }
    $tree[$dcId]['rooms'][$rmId]['rows'][$rid]['cabinets'][] = $c;
}

// Ensure empty rows appear
foreach ($allRows as $ar) {
    $dcId = (int)$ar['datacenter_id'];
    $rmId = (int)$ar['room_id'];
    $rid = (int)$ar['row_id'];
    if (!isset($tree[$dcId])) {
        $tree[$dcId] = ['name' => $ar['dc_name'], 'rooms' => []];
    }
    if (!isset($tree[$dcId]['rooms'][$rmId])) {
        $tree[$dcId]['rooms'][$rmId] = ['name' => $ar['room_name'], 'rows' => []];
    }
    if (!isset($tree[$dcId]['rooms'][$rmId]['rows'][$rid])) {
        $tree[$dcId]['rooms'][$rmId]['rows'][$rid] = [
            'row_id' => $rid,
            'name' => $ar['name'],
            'color' => $ar['color_hex'] ?? null,
            'zone_name' => $ar['zone_name'] ?? null,
            'cabinets' => [],
        ];
    }
}

// Sort DCs / rooms / rows naturally; cabinets by front-view order
uksort($tree, static function ($a, $b) use ($tree, $naturalCmp) {
    return $naturalCmp((string)$tree[$a]['name'], (string)$tree[$b]['name']);
});
foreach ($tree as &$dcNode) {
    uksort($dcNode['rooms'], static function ($a, $b) use ($dcNode, $naturalCmp) {
        return $naturalCmp((string)$dcNode['rooms'][$a]['name'], (string)$dcNode['rooms'][$b]['name']);
    });
    foreach ($dcNode['rooms'] as &$rmNode) {
        uasort($rmNode['rows'], static function ($a, $b) use ($naturalCmp) {
            // Unassigned last
            if (empty($a['row_id']) && !empty($b['row_id'])) {
                return 1;
            }
            if (!empty($a['row_id']) && empty($b['row_id'])) {
                return -1;
            }
            return $naturalCmp((string)$a['name'], (string)$b['name']);
        });
        foreach ($rmNode['rows'] as &$rowNode) {
            $rowNode['facing'] = $sortCabinetsFrontView($rowNode['cabinets']);
            $rowNode['u_total'] = array_sum(array_map(static fn($c) => (int)$c['u_height'], $rowNode['cabinets']));
            $rowNode['u_used'] = array_sum(array_map(static fn($c) => (int)$c['u_used'], $rowNode['cabinets']));
            $rowNode['pdu_kw'] = array_sum(array_map(static fn($c) => (float)$c['pdu_watts'], $rowNode['cabinets'])) / 1000.0;
            $rowNode['dev_kw'] = array_sum(array_map(static fn($c) => (float)$c['device_watts'], $rowNode['cabinets'])) / 1000.0;
            $rowNode['cab_count'] = count($rowNode['cabinets']);
        }
        unset($rowNode);
    }
    unset($rmNode);
}
unset($dcNode);

// Global metrics
$mCabs = count($cabinets);
$mRows = 0;
$mUTotal = 0;
$mUUsed = 0;
$mPduKw = 0.0;
$mDevKw = 0.0;
foreach ($tree as $dcNode) {
    foreach ($dcNode['rooms'] as $rmNode) {
        foreach ($rmNode['rows'] as $rowNode) {
            if (!empty($rowNode['row_id'])) {
                $mRows++;
            }
            $mUTotal += (int)$rowNode['u_total'];
            $mUUsed += (int)$rowNode['u_used'];
            $mPduKw += (float)$rowNode['pdu_kw'];
            $mDevKw += (float)$rowNode['dev_kw'];
        }
    }
}
$mUPct = $mUTotal > 0 ? round(100 * $mUUsed / $mUTotal, 1) : 0;

layout_header('Cabinets', $user, 'cabinets');
?>
<div class="flex-between mb-2">
    <p class="text-muted mb-0">Cabinets grouped by row. Order follows label (natural A/1… sort) and front-facing L→R for each row.</p>
    <a class="btn btn-primary" href="<?= App::e(App::url('pages/floorplan.php')) ?>">Open Floor Planner</a>
</div>

<div class="metrics">
    <div class="metric-card accent">
        <div class="label">Cabinets</div>
        <div class="value"><?= $mCabs ?></div>
        <div class="sub"><?= $mRows ?> labeled rows</div>
    </div>
    <div class="metric-card success">
        <div class="label">U capacity</div>
        <div class="value"><?= $mUUsed ?><span class="metric-unit"> / <?= $mUTotal ?></span></div>
        <div class="sub"><?= $mUPct ?>% utilized</div>
    </div>
    <div class="metric-card warning">
        <div class="label">PDU load</div>
        <div class="value"><?= number_format($mPduKw, 1) ?><span class="metric-unit"> kW</span></div>
        <div class="sub">Nameplate ~<?= number_format($mDevKw, 1) ?> kW</div>
    </div>
    <div class="metric-card">
        <div class="label">Free U</div>
        <div class="value"><?= max(0, $mUTotal - $mUUsed) ?></div>
        <div class="sub">Across all racks</div>
    </div>
</div>

<?php if (!$tree): ?>
    <div class="card"><div class="card-body empty-state">
        <h3>No cabinets</h3>
        <p>Drag cabinets onto the floor plan to get started.</p>
        <a class="btn btn-primary" href="<?= App::e(App::url('pages/floorplan.php')) ?>">Floor Planner</a>
    </div></div>
<?php endif; ?>

<?php foreach ($tree as $dcNode): ?>
    <div class="cab-dc-block">
        <h2 class="cab-dc-title"><?= App::e($dcNode['name']) ?></h2>
        <?php foreach ($dcNode['rooms'] as $rmNode): ?>
            <div class="cab-room-block">
                <h3 class="cab-room-title"><?= App::e($rmNode['name']) ?></h3>
                <?php foreach ($rmNode['rows'] as $rowNode):
                    $uPct = $rowNode['u_total'] > 0
                        ? round(100 * $rowNode['u_used'] / $rowNode['u_total'], 1) : 0;
                    $facing = $rowNode['facing'] ?? 'north';
                    $facingShort = match ($facing) {
                        'north' => 'N · L→R = W→E',
                        'south' => 'S · L→R = E→W',
                        'east' => 'E · L→R = N→S',
                        'west' => 'W · L→R = S→N',
                        default => strtoupper($facing),
                    };
                    ?>
                    <div class="card cab-row-card">
                        <div class="card-header cab-row-header">
                            <div class="cab-row-title-wrap">
                                <?php if (!empty($rowNode['color'])): ?>
                                    <span class="dept-swatch" style="background:<?= App::e($rowNode['color']) ?>"></span>
                                <?php endif; ?>
                                <div>
                                    <h2 style="margin:0">
                                        <?= empty($rowNode['row_id']) ? 'Unassigned cabinets' : ('Row ' . App::e($rowNode['name'])) ?>
                                    </h2>
                                    <div class="cab-row-meta text-muted">
                                        <?= (int)$rowNode['cab_count'] ?> cabinets ·
                                        <?= (int)$rowNode['u_used'] ?>/<?= (int)$rowNode['u_total'] ?>U (<?= $uPct ?>%) ·
                                        <?= number_format((float)$rowNode['pdu_kw'], 1) ?> kW polled
                                        <?php if (!empty($rowNode['zone_name'])): ?>
                                            · Zone <?= App::e($rowNode['zone_name']) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($rowNode['row_id'])): ?>
                                            · Facing <?= App::e($facingShort) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($rowNode['row_id'])): ?>
                                <a class="btn btn-sm btn-primary" href="?row_id=<?= (int)$rowNode['row_id'] ?>">Row View</a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body flush">
                            <?php if (!$rowNode['cabinets']): ?>
                                <p class="text-muted" style="padding:1rem;margin:0">No cabinets in this row yet.</p>
                            <?php else: ?>
                                <table class="data">
                                    <thead>
                                    <tr>
                                        <th style="width:2.5rem">#</th>
                                        <th>Cabinet</th>
                                        <th>Facing</th>
                                        <th>U</th>
                                        <th>Devices</th>
                                        <th>Utilization</th>
                                        <th>Power</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $seq = 0;
                                    foreach ($rowNode['cabinets'] as $c):
                                        $seq++;
                                        $pct = $c['u_height'] ? round(100 * (int)$c['u_used'] / (int)$c['u_height'], 1) : 0;
                                        $kw = ((float)$c['pdu_watts']) / 1000.0;
                                        ?>
                                        <tr>
                                            <td class="text-muted"><?= $seq ?></td>
                                            <td>
                                                <a href="?id=<?= (int)$c['cabinet_id'] ?>"><strong><?= App::e($c['name']) ?></strong></a>
                                            </td>
                                            <td><span class="badge"><?= App::e(strtoupper((string)($c['front_facing'] ?? '—'))) ?></span></td>
                                            <td><?= (int)$c['u_height'] ?>U</td>
                                            <td><?= (int)$c['device_count'] ?></td>
                                            <td>
                                                <span class="badge <?= $pct > 85 ? 'badge-danger' : ($pct > 60 ? 'badge-warning' : 'badge-success') ?>">
                                                    <?= $pct ?>% (<?= (int)$c['u_used'] ?>U)
                                                </span>
                                            </td>
                                            <td><?= $kw > 0 ? number_format($kw, 2) . ' kW' : '—' ?></td>
                                            <td class="actions">
                                                <a class="btn btn-sm btn-secondary" href="?id=<?= (int)$c['cabinet_id'] ?>">Rack View</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<style>
.cab-dc-title {
  font-size: 1.15rem;
  margin: 0 0 .75rem;
  padding-bottom: .35rem;
  border-bottom: 1px solid var(--border);
}
.cab-room-title {
  font-size: .95rem;
  color: var(--muted);
  margin: 0 0 .65rem;
  font-weight: 600;
}
.cab-dc-block { margin-bottom: 1.75rem; }
.cab-room-block { margin-bottom: 1.25rem; padding-left: .25rem; }
.cab-row-card { margin-bottom: .85rem; }
.cab-row-header { align-items: center; }
.cab-row-title-wrap { display: flex; align-items: flex-start; gap: .65rem; }
.cab-row-meta { font-size: .78rem; margin-top: .2rem; }
/* Row View CSS is in assets/css/app.css (Row View exits before this block). */
</style>
<?php layout_footer(); ?>

