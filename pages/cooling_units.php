<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/cooling_helpers.php';
App::boot();
$user = App::requirePermission('view_cooling');
$canEdit = AuthManager::canEditCooling($user);

$unitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$types = cooling_unit_types();
$roles = cooling_unit_roles();
$media = cooling_media();
$statuses = cooling_unit_statuses();
$ashrae = cooling_ashrae_classes();

$rooms = [];
try {
    $rooms = Database::fetchAll(
        'SELECT r.room_id, r.name, dc.name AS dc_name
         FROM rooms r
         INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
         WHERE r.is_active = 1
         ORDER BY dc.name, r.name'
    );
} catch (Throwable $e) {
    $rooms = Database::fetchAll(
        'SELECT r.room_id, r.name, dc.name AS dc_name
         FROM rooms r
         INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
         ORDER BY dc.name, r.name'
    );
}

$peerUnits = [];
try {
    $peerUnits = Database::fetchAll(
        'SELECT cooling_unit_id, name, unit_role FROM cooling_units WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $peerUnits = [];
}

// Shared SNMPv3 profiles for add/edit forms
$snmpProfiles = [];
try {
    $snmpProfiles = Database::fetchAll(
        'SELECT profile_id, name, security_name, security_level,
                auth_protocol, priv_protocol, context_name
         FROM snmp_v3_profiles WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $snmpProfiles = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to modify cooling units.');
        App::redirect('pages/cooling_units.php');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add_unit' || $action === 'update_unit') {
            $row = cooling_unit_fields_from_post($_POST);
            if ($row['name'] === '') {
                throw new RuntimeException('Name is required.');
            }
            // Prevent self-standby
            if ($action === 'update_unit') {
                $uid = (int)($_POST['cooling_unit_id'] ?? 0);
                if ($uid > 0 && $row['standby_of_id'] === $uid) {
                    $row['standby_of_id'] = null;
                }
            }
            if ($action === 'update_unit') {
                $uid = (int)($_POST['cooling_unit_id'] ?? 0);
                if ($uid <= 0) {
                    throw new RuntimeException('Unit id required.');
                }
                $prev = null;
                try {
                    $prev = Database::fetchOne(
                        'SELECT snmp_community, snmp_auth_passphrase, snmp_priv_passphrase,
                                snmp_v3_profile_id, snmp_version
                         FROM cooling_units WHERE cooling_unit_id = ?',
                        [$uid]
                    );
                } catch (Throwable $e) {
                    // Pre-v3-column installs: Schema ensure adds columns
                    try {
                        $prev = Database::fetchOne(
                            'SELECT snmp_community, snmp_v3_profile_id, snmp_version
                             FROM cooling_units WHERE cooling_unit_id = ?',
                            [$uid]
                        );
                    } catch (Throwable $e2) {
                        $prev = null;
                    }
                }
                $row = cooling_unit_finalize_snmp($row, $prev ?: null);
                $row['updated_at'] = date('Y-m-d H:i:s');
                Database::update('cooling_units', $row, 'cooling_unit_id = :id', [':id' => $uid]);
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'update', 'cooling_unit', $uid, [
                    'name' => $row['name'],
                ]);
                $msg = 'Cooling unit updated.';
                if ((string)($row['snmp_version'] ?? '') === '3' && !empty($row['snmp_v3_profile_id'])) {
                    $msg = 'Cooling unit updated. SNMPv3 credentials applied from the selected profile.';
                } elseif ((string)($row['snmp_version'] ?? '') === '3' && empty($row['snmp_security_name'])) {
                    $msg = 'Cooling unit updated. SNMPv3 is set but no user/security name was saved — select a profile or enter the v3 user.';
                }
                App::flash('success', $msg);
                App::redirect('pages/cooling_units.php?id=' . $uid);
            }
            $row = cooling_unit_finalize_snmp($row, null);
            $id = Database::insert('cooling_units', $row);
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'create', 'cooling_unit', (int)$id, [
                'name' => $row['name'],
            ]);
            App::flash('success', 'Cooling unit created.');
            App::redirect('pages/cooling_units.php?id=' . (int)$id);
        }
        if ($action === 'deactivate_unit') {
            $uid = (int)($_POST['cooling_unit_id'] ?? 0);
            if ($uid <= 0) {
                throw new RuntimeException('Unit id required.');
            }
            Database::update('cooling_units', [
                'is_active' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'cooling_unit_id = :id', [':id' => $uid]);
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'delete', 'cooling_unit', $uid, []);
            App::flash('success', 'Cooling unit deactivated.');
            App::redirect('pages/cooling_units.php');
        }
    } catch (Throwable $e) {
        App::log('cooling_units POST: ' . $e->getMessage(), 'error');
        App::flash('error', $e->getMessage());
        $redirectId = (int)($_POST['cooling_unit_id'] ?? 0);
        App::redirect('pages/cooling_units.php' . ($redirectId ? '?id=' . $redirectId : ''));
    }
}

// Detail view
if ($unitId > 0) {
    $u = Database::fetchOne(
        'SELECT u.*,
                rm.name AS room_name, dc.name AS dc_name,
                primary_u.name AS primary_unit_name
         FROM cooling_units u
         LEFT JOIN rooms rm ON rm.room_id = u.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         LEFT JOIN cooling_units primary_u ON primary_u.cooling_unit_id = u.standby_of_id
         WHERE u.cooling_unit_id = ?',
        [$unitId]
    );
    if (!$u || empty($u['is_active'])) {
        App::flash('error', 'Cooling unit not found.');
        App::redirect('pages/cooling_units.php');
    }
    $linkedSensors = [];
    try {
        $linkedSensors = Database::fetchAll(
            'SELECT sensor_id, name, sensor_kind, last_value, unit, last_seen_at
             FROM env_sensors WHERE cooling_unit_id = ? AND is_active = 1 ORDER BY name',
            [$unitId]
        );
    } catch (Throwable $e) {
        $linkedSensors = [];
    }
    $env = cooling_ashrae_envelope((string)($u['ashrae_class'] ?? 'recommended'));
    $placed = $u['pos_x'] !== null && $u['pos_y'] !== null && $u['room_id'];

    layout_header('Cooling unit: ' . $u['name'], $user, 'cooling_units');
    ?>
    <div class="flex-between mb-2">
        <div>
            <a class="text-muted" href="<?= App::e(App::url('pages/cooling_units.php')) ?>">← All units</a>
            <h2 class="mt-1 mb-0"><?= App::e($u['name']) ?></h2>
            <p class="text-muted mb-0" style="font-size:.9rem">
                <?= App::e($types[$u['unit_type'] ?? ''] ?? ($u['unit_type'] ?? '')) ?>
                · <?= App::e($roles[$u['unit_role'] ?? ''] ?? '') ?>
                · <?= App::e($media[$u['cooling_medium'] ?? ''] ?? '') ?>
            </p>
        </div>
        <div class="flex gap-1">
            <?php if ($placed && $u['room_id']): ?>
                <a class="btn btn-secondary" href="<?= App::e(App::url('pages/floorplan.php?room_id=' . (int)$u['room_id'])) ?>">Floor plan</a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cooling.php')) ?>">Dashboard</a>
        </div>
    </div>

    <div class="metrics power-metrics mb-2">
        <div class="metric-card">
            <div class="label">Status</div>
            <div class="value" style="font-size:1.25rem"><?= App::e($statuses[$u['status'] ?? ''] ?? ($u['status'] ?? '—')) ?></div>
            <div class="sub"><?= App::e((string)($u['room_name'] ?? 'No room')) ?></div>
        </div>
        <div class="metric-card accent">
            <div class="label">Rated cooling</div>
            <div class="value">
                <?php if ($u['rated_kw_cooling'] !== null && $u['rated_kw_cooling'] !== ''): ?>
                    <?= number_format((float)$u['rated_kw_cooling'], 1) ?> <span class="metric-unit">kW</span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
            <div class="sub">
                <?php if ($u['rated_tons'] !== null && $u['rated_tons'] !== ''): ?>
                    <?= number_format((float)$u['rated_tons'], 1) ?> tons
                <?php else: ?>
                    optional tons / CFM on form
                <?php endif; ?>
            </div>
        </div>
        <div class="metric-card">
            <div class="label">Network</div>
            <div class="value" style="font-size:1.1rem"><?= App::e((string)($u['primary_ip'] ?? '—')) ?></div>
            <div class="sub">
                <?php
                if (empty($u['snmp_enabled'])) {
                    echo 'SNMP off';
                } else {
                    $sv = strtolower(trim((string)($u['snmp_version'] ?? '2c')));
                    $svLab = match ($sv) {
                        '3', 'v3' => 'v3',
                        '1', 'v1' => 'v1',
                        default => 'v2c',
                    };
                    echo 'SNMP ' . App::e($svLab);
                    if (in_array($sv, ['3', 'v3'], true) && !empty($u['snmp_security_name'])) {
                        echo ' · ' . App::e((string)$u['snmp_security_name']);
                    }
                }
                ?>
            </div>
        </div>
        <div class="metric-card success">
            <div class="label">ASHRAE class</div>
            <div class="value" style="font-size:1.1rem"><?= App::e($env['label']) ?></div>
            <div class="sub"><?= App::e($env['notes']) ?></div>
        </div>
    </div>

    <?php
    $siteTplId = (int)($u['snmp_site_template_id'] ?? 0);
    $siteTpl = null;
    if ($siteTplId > 0) {
        try {
            $siteTpl = Database::fetchOne(
                'SELECT template_id, name, vendor, model FROM snmp_site_oid_templates WHERE template_id = ?',
                [$siteTplId]
            );
        } catch (Throwable $e) {
            $siteTpl = null;
        }
    }
    $autoPoll = !empty($u['snmp_auto_poll']);
    $discoverHost = trim((string)($u['primary_ip'] ?? ''));
    $discoverReady = trim((string)($u['manufacturer'] ?? '')) !== ''
        && trim((string)($u['model'] ?? '')) !== ''
        && $discoverHost !== ''
        && !empty($u['snmp_enabled']);
    $canSnmpActions = $canEdit || AuthManager::canEditSnmp($user);
    $lastPollSnap = null;
    if (!empty($u['last_poll_json'])) {
        $decoded = json_decode((string)$u['last_poll_json'], true);
        if (is_array($decoded)) {
            $lastPollSnap = $decoded;
        }
    }
    $lastPollMetrics = is_array($lastPollSnap['metrics'] ?? null) ? $lastPollSnap['metrics'] : [];
    ?>
    <div class="card mb-2" id="coolingSnmpCard">
        <div class="card-header flex-between">
            <h3 class="mt-0 mb-0" style="font-size:1rem">SNMP</h3>
            <?php if ($canSnmpActions): ?>
            <div class="flex gap-1" style="align-items:center;flex-wrap:wrap">
                <label class="snmp-toggle" title="<?= $siteTplId > 0
                    ? 'Include this unit in the SNMP scheduler'
                    : 'Run Discover OIDs first to assign a site template' ?>">
                    <input type="checkbox" id="cuSnmpAutoPollToggle"
                        <?= $autoPoll ? 'checked' : '' ?>
                        <?= $siteTplId > 0 ? '' : 'disabled' ?>>
                    <span class="snmp-switch" aria-hidden="true"></span>
                    <span class="snmp-toggle-label" id="cuSnmpAutoPollLabel">
                        Scheduled poll <?= $autoPoll ? 'on' : 'off' ?>
                    </span>
                </label>
                <button type="button" class="btn btn-secondary btn-sm" id="btnCuSnmpDiscover"
                    <?= $discoverReady ? '' : 'disabled title="Need manufacturer, model, primary IP, and SNMP enabled"' ?>>
                    Discover OIDs
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="btnCuSnmpPollNow"
                    <?= $siteTplId > 0 ? '' : 'disabled title="Assign a site OID template first (Discover OIDs)"' ?>>
                    Poll now
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <dl class="detail-grid" style="margin:0">
                <dt>Site template</dt>
                <dd id="cuSnmpTplName">
                    <?php if ($siteTpl): ?>
                        <strong><?= App::e((string)($siteTpl['name'] ?? 'Template #' . $siteTplId)) ?></strong>
                        <?php if (!empty($siteTpl['vendor']) || !empty($siteTpl['model'])): ?>
                            <span class="text-muted"> · <?= App::e(trim(($siteTpl['vendor'] ?? '') . ' ' . ($siteTpl['model'] ?? ''))) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">None — Discover OIDs to create/assign</span>
                    <?php endif; ?>
                </dd>
                <dt>Last poll</dt>
                <dd id="cuSnmpLastPoll"><?= App::e((string)($u['snmp_last_poll_at'] ?? '—')) ?></dd>
            </dl>
            <?php if (!$discoverReady && $canSnmpActions): ?>
                <p class="text-muted snmp-poll-stats mb-0 mt-1" style="font-size:.85rem">
                    Discover needs manufacturer, model, primary IP, and SNMP enabled with credentials (edit unit below).
                </p>
            <?php elseif ($siteTplId < 1 && $canSnmpActions): ?>
                <p class="text-muted snmp-poll-stats mb-0 mt-1" style="font-size:.85rem">
                    Run <strong>Discover OIDs</strong>, save a site template, then use <strong>Poll now</strong>.
                </p>
            <?php endif; ?>
            <?php if ($lastPollMetrics): ?>
                <div class="mt-2">
                    <div class="text-muted" style="font-size:.8rem;margin-bottom:.35rem">Last metrics snapshot</div>
                    <div class="table-wrap">
                        <table class="table table-sm" id="cuSnmpMetricsTable">
                            <thead><tr><th>Key</th><th>Value</th></tr></thead>
                            <tbody>
                            <?php
                            $n = 0;
                            foreach ($lastPollMetrics as $mk => $mv):
                                if ($n++ >= 24) {
                                    break;
                                }
                                ?>
                                <tr>
                                    <td><code style="font-size:.78rem"><?= App::e((string)$mk) ?></code></td>
                                    <td><?= App::e(is_scalar($mv) ? (string)$mv : json_encode($mv)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($lastPollMetrics) > 24): ?>
                        <p class="text-muted mb-0" style="font-size:.75rem">Showing 24 of <?= count($lastPollMetrics) ?> keys.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div id="cuSnmpMetricsEmpty" class="text-muted mt-1" style="font-size:.85rem">No poll snapshot yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canSnmpActions): ?>
    <div class="modal-overlay modal-overlay-glass" id="cuSnmpDiscoverModal" hidden>
        <div class="modal-panel modal-panel-glass modal-panel-glass-wide" role="dialog" aria-modal="true" aria-labelledby="cuSnmpDiscoverTitle">
            <div class="modal-header">
                <h2 id="cuSnmpDiscoverTitle">Discover OIDs</h2>
                <button type="button" class="modal-close" id="cuSnmpDiscoverClose" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="cuSnmpDiscoverLoading" hidden>
                    <p class="text-muted">Walking SNMP roots… this may take up to a minute.</p>
                </div>
                <div id="cuSnmpDiscoverError" class="alert alert-error" hidden></div>
                <div id="cuSnmpDiscoverResults" hidden>
                    <dl class="snmp-discover-meta">
                        <div><dt>Host</dt><dd id="cuSnmpDiscHost">—</dd></div>
                        <div><dt>Template name</dt><dd id="cuSnmpDiscTplName">—</dd></div>
                        <div><dt>Walk count</dt><dd id="cuSnmpDiscWalk">—</dd></div>
                    </dl>
                    <p class="text-muted" id="cuSnmpDiscSys" style="font-size:.85rem;word-break:break-word"></p>
                    <p id="cuSnmpDiscMessage" style="font-size:.9rem"></p>
                    <div id="cuSnmpExistsWarn" class="alert alert-warning" hidden></div>
                    <h4 style="font-size:.95rem;margin:1rem 0 .5rem">Proposed OID map</h4>
                    <ul id="cuSnmpProposedMap" class="snmp-proposed-map" style="list-style:none;padding:0;margin:0"></ul>
                    <h4 style="font-size:.95rem;margin:1rem 0 .5rem">Candidates</h4>
                    <div class="table-wrap" style="max-height:14rem;overflow:auto">
                        <table class="table table-sm">
                            <thead><tr><th>Name</th><th>OID</th><th>Value</th></tr></thead>
                            <tbody id="cuSnmpCandidateBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cuSnmpDiscoverCancel">Close</button>
                <button type="button" class="btn btn-warning" id="cuSnmpDiscoverOverwrite" hidden>Overwrite template</button>
                <button type="button" class="btn btn-primary" id="cuSnmpDiscoverCreate" disabled>Create template</button>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var unitId = <?= (int)$unitId ?>;
        var hasTemplate = <?= $siteTplId > 0 ? 'true' : 'false' ?>;
        var modal = document.getElementById('cuSnmpDiscoverModal');
        var btnDiscover = document.getElementById('btnCuSnmpDiscover');
        var btnPoll = document.getElementById('btnCuSnmpPollNow');
        var autoToggle = document.getElementById('cuSnmpAutoPollToggle');
        var autoLabel = document.getElementById('cuSnmpAutoPollLabel');
        var loadingEl = document.getElementById('cuSnmpDiscoverLoading');
        var errEl = document.getElementById('cuSnmpDiscoverError');
        var resEl = document.getElementById('cuSnmpDiscoverResults');
        var createBtn = document.getElementById('cuSnmpDiscoverCreate');
        var overwriteBtn = document.getElementById('cuSnmpDiscoverOverwrite');
        var existsWarn = document.getElementById('cuSnmpExistsWarn');
        var lastDiscover = null;

        function toast(msg, type) {
            if (window.ColdAisle && ColdAisle.toast) ColdAisle.toast(msg, type || 'info');
            else alert(msg);
        }
        function api(body) {
            return ColdAisle.api('api/snmp_cooling.php', { method: 'POST', body: body });
        }
        function openModal() {
            if (!modal) return;
            modal.hidden = false;
            document.body.classList.add('modal-open');
        }
        function closeModal() {
            if (!modal) return;
            modal.hidden = true;
            document.body.classList.remove('modal-open');
        }
        function showErr(msg) {
            if (!errEl) return;
            errEl.hidden = !msg;
            errEl.textContent = msg || '';
        }
        function setLoading(on) {
            if (loadingEl) loadingEl.hidden = !on;
            if (resEl && on) resEl.hidden = true;
            if (createBtn) createBtn.disabled = true;
            if (overwriteBtn) overwriteBtn.hidden = true;
            if (existsWarn) existsWarn.hidden = true;
        }
        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }
        function renderDiscover(data) {
            lastDiscover = data;
            document.getElementById('cuSnmpDiscHost').textContent = data.host || '—';
            document.getElementById('cuSnmpDiscTplName').textContent = data.template_name || '—';
            document.getElementById('cuSnmpDiscWalk').textContent = String(data.walk_count != null ? data.walk_count : '—');
            document.getElementById('cuSnmpDiscSys').textContent = data.sysDescr || '—';
            document.getElementById('cuSnmpDiscMessage').textContent = data.message || '';

            var mapUl = document.getElementById('cuSnmpProposedMap');
            mapUl.innerHTML = '';
            var map = data.proposed_map || {};
            var keys = Object.keys(map);
            if (!keys.length) {
                mapUl.innerHTML = '<li class="text-muted">No proposed metrics — pick OIDs from candidates or type name=oid</li>';
            } else {
                keys.forEach(function (k) {
                    var li = document.createElement('li');
                    li.innerHTML = '<label>' + esc(k) + '</label>';
                    var inp = document.createElement('input');
                    inp.className = 'form-control';
                    inp.dataset.metric = k;
                    inp.value = map[k] || '';
                    li.appendChild(inp);
                    mapUl.appendChild(li);
                });
            }
            var li2 = document.createElement('li');
            li2.innerHTML = '<label class="text-muted">+ metric</label>';
            var extra = document.createElement('input');
            extra.className = 'form-control';
            extra.placeholder = 'name=1.3.6… (optional)';
            extra.id = 'cuSnmpExtraMapRow';
            li2.appendChild(extra);
            mapUl.appendChild(li2);

            var tbody = document.getElementById('cuSnmpCandidateBody');
            tbody.innerHTML = '';
            (data.candidates || []).forEach(function (c) {
                var tr = document.createElement('tr');
                var nm = c.name || '';
                tr.innerHTML =
                    '<td style="font-size:.78rem;max-width:14rem;word-break:break-all">' +
                        (nm ? '<code title="' + esc(nm) + '">' + esc(nm) + '</code>' : '<span class="text-muted">—</span>') +
                    '</td>' +
                    '<td style="font-size:.75rem;word-break:break-all"><code>' + esc(c.oid || '') + '</code></td>' +
                    '<td style="font-size:.8rem">' + esc(c.value != null ? c.value : '') + '</td>';
                tbody.appendChild(tr);
            });

            if (resEl) resEl.hidden = false;
            if (createBtn) createBtn.disabled = false;
            if (data.existing_template) {
                if (existsWarn) {
                    existsWarn.hidden = false;
                    existsWarn.textContent = 'Template "' + (data.template_name || '') +
                        '" already exists. Create will ask to overwrite.';
                }
                if (overwriteBtn) overwriteBtn.hidden = false;
            }
        }
        function collectMap() {
            var map = {};
            var mapUl = document.getElementById('cuSnmpProposedMap');
            if (!mapUl) return map;
            mapUl.querySelectorAll('input[data-metric]').forEach(function (inp) {
                var k = inp.dataset.metric;
                var v = (inp.value || '').trim();
                if (k && v) map[k] = v;
            });
            var extra = document.getElementById('cuSnmpExtraMapRow');
            if (extra && extra.value) {
                var parts = extra.value.split('=');
                if (parts.length >= 2) {
                    var ek = parts[0].trim();
                    var ev = parts.slice(1).join('=').trim();
                    if (ek && ev) map[ek] = ev;
                }
            }
            return map;
        }
        function saveTemplate(overwrite) {
            if (!lastDiscover) return;
            var map = collectMap();
            if (!Object.keys(map).length) {
                showErr('OID map is empty.');
                return;
            }
            createBtn.disabled = true;
            if (overwriteBtn) overwriteBtn.disabled = true;
            showErr('');
            api({
                action: 'save_template',
                cooling_unit_id: unitId,
                oid_map: map,
                overwrite: !!overwrite
            }).then(function (data) {
                toast(data.message || 'Template saved', 'success');
                hasTemplate = true;
                if (btnPoll) btnPoll.disabled = false;
                if (autoToggle) {
                    autoToggle.disabled = false;
                    if (data.snmp_auto_poll) {
                        autoToggle.checked = true;
                        if (autoLabel) autoLabel.textContent = 'Scheduled poll on';
                    }
                }
                var nameEl = document.getElementById('cuSnmpTplName');
                if (nameEl && data.template) {
                    var lab = data.template.name || 'Template';
                    nameEl.innerHTML = '<strong>' + esc(lab) + '</strong>';
                }
                closeModal();
            }).catch(function (err) {
                if (err.status === 409 && err.data && err.data.exists) {
                    if (existsWarn) {
                        existsWarn.hidden = false;
                        existsWarn.textContent = err.data.message || 'Template exists. Overwrite?';
                    }
                    if (overwriteBtn) overwriteBtn.hidden = false;
                    createBtn.disabled = false;
                    if (overwriteBtn) overwriteBtn.disabled = false;
                    return;
                }
                showErr((err && err.message) || 'Save failed');
                createBtn.disabled = false;
                if (overwriteBtn) overwriteBtn.disabled = false;
            });
        }

        if (btnDiscover) {
            btnDiscover.addEventListener('click', function () {
                openModal();
                setLoading(true);
                showErr('');
                lastDiscover = null;
                api({ action: 'discover', cooling_unit_id: unitId })
                    .then(function (data) {
                        setLoading(false);
                        renderDiscover(data);
                    })
                    .catch(function (err) {
                        setLoading(false);
                        showErr((err && err.message) || 'Discover failed');
                        toast((err && err.message) || 'Discover failed', 'error');
                    });
            });
        }
        if (createBtn) {
            createBtn.addEventListener('click', function () {
                if (lastDiscover && lastDiscover.existing_template) {
                    if (!confirm('Template "' + lastDiscover.template_name + '" already exists. Overwrite it?')) {
                        return;
                    }
                    saveTemplate(true);
                    return;
                }
                saveTemplate(false);
            });
        }
        if (overwriteBtn) {
            overwriteBtn.addEventListener('click', function () {
                if (!confirm('Overwrite existing template "' +
                    (lastDiscover && lastDiscover.template_name ? lastDiscover.template_name : '') + '"?')) {
                    return;
                }
                saveTemplate(true);
            });
        }
        function closeDiscover() { closeModal(); }
        var c1 = document.getElementById('cuSnmpDiscoverClose');
        var c2 = document.getElementById('cuSnmpDiscoverCancel');
        if (c1) c1.addEventListener('click', closeDiscover);
        if (c2) c2.addEventListener('click', closeDiscover);
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeDiscover();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && !modal.hidden) closeDiscover();
        });

        if (btnPoll) {
            btnPoll.addEventListener('click', function () {
                btnPoll.disabled = true;
                api({ action: 'poll_now', cooling_unit_id: unitId })
                    .then(function (data) {
                        toast(data.message || 'Poll complete', 'success');
                        var el = document.getElementById('cuSnmpLastPoll');
                        if (el) el.textContent = data.snmp_last_poll_at || '—';
                        var metrics = data.metrics || {};
                        var keys = Object.keys(metrics);
                        if (keys.length) {
                            var tbody = document.querySelector('#cuSnmpMetricsTable tbody');
                            var empty = document.getElementById('cuSnmpMetricsEmpty');
                            if (empty) empty.hidden = true;
                            if (!tbody) {
                                // Build table if missing
                                var cardBody = document.querySelector('#coolingSnmpCard .card-body');
                                if (cardBody) {
                                    var wrap = document.createElement('div');
                                    wrap.className = 'mt-2';
                                    wrap.innerHTML = '<div class="text-muted" style="font-size:.8rem;margin-bottom:.35rem">Last metrics snapshot</div>' +
                                        '<div class="table-wrap"><table class="table table-sm" id="cuSnmpMetricsTable">' +
                                        '<thead><tr><th>Key</th><th>Value</th></tr></thead><tbody></tbody></table></div>';
                                    cardBody.appendChild(wrap);
                                    tbody = wrap.querySelector('tbody');
                                }
                            }
                            if (tbody) {
                                tbody.innerHTML = '';
                                keys.slice(0, 24).forEach(function (k) {
                                    var tr = document.createElement('tr');
                                    tr.innerHTML = '<td><code style="font-size:.78rem">' + esc(k) + '</code></td>' +
                                        '<td>' + esc(metrics[k]) + '</td>';
                                    tbody.appendChild(tr);
                                });
                            }
                        }
                    })
                    .catch(function (err) {
                        toast((err && err.message) || 'Poll failed', 'error');
                    })
                    .finally(function () {
                        btnPoll.disabled = !hasTemplate;
                    });
            });
        }
        if (autoToggle) {
            autoToggle.addEventListener('change', function () {
                var enabled = !!autoToggle.checked;
                autoToggle.disabled = true;
                api({ action: 'set_auto_poll', cooling_unit_id: unitId, enabled: enabled })
                    .then(function (data) {
                        toast(data.message || 'Updated', 'success');
                        if (autoLabel) {
                            autoLabel.textContent = 'Scheduled poll ' + (data.snmp_auto_poll ? 'on' : 'off');
                        }
                    })
                    .catch(function (err) {
                        autoToggle.checked = !enabled;
                        toast((err && err.message) || 'Failed to update auto-poll', 'error');
                    })
                    .finally(function () {
                        autoToggle.disabled = !hasTemplate;
                    });
            });
        }
    })();
    </script>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <div class="card mb-2">
        <div class="card-header"><h3 class="mt-0 mb-0" style="font-size:1rem">Edit unit</h3></div>
        <div class="card-body">
            <?php
            $edit = $u;
            $formAction = 'update_unit';
            require __DIR__ . '/_cooling_unit_form.php';
            ?>
            <form method="post" class="mt-2" onsubmit="return confirm('Deactivate this cooling unit?');">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="deactivate_unit">
                <input type="hidden" name="cooling_unit_id" value="<?= (int)$unitId ?>">
                <button type="submit" class="btn btn-danger btn-sm">Deactivate unit</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="card mb-2">
        <div class="card-body">
            <dl class="detail-grid">
                <dt>Manufacturer</dt><dd><?= App::e((string)($u['manufacturer'] ?? '—')) ?></dd>
                <dt>Model</dt><dd><?= App::e((string)($u['model'] ?? '—')) ?></dd>
                <dt>Serial</dt><dd><?= App::e((string)($u['serial_no'] ?? '—')) ?></dd>
                <dt>Asset tag</dt><dd><?= App::e((string)($u['asset_tag'] ?? '—')) ?></dd>
                <dt>Warranty</dt>
                <dd>
                    <?= App::e((string)($u['warranty_provider'] ?? '—')) ?>
                    <?php if (!empty($u['warranty_end'])): ?>
                        · ends <?= App::e((string)$u['warranty_end']) ?>
                    <?php endif; ?>
                </dd>
                <dt>Standby of</dt><dd><?= App::e((string)($u['primary_unit_name'] ?? '—')) ?></dd>
                <dt>Notes</dt><dd><?= nl2br(App::e((string)($u['notes'] ?? '—'))) ?></dd>
            </dl>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($linkedSensors): ?>
    <div class="card">
        <div class="card-header flex-between">
            <h3 class="mt-0 mb-0" style="font-size:1rem">Linked sensors</h3>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/env_sensors.php?cooling_unit_id=' . $unitId)) ?>">Manage</a>
        </div>
        <div class="card-body" style="padding:0">
            <table class="table table-sm">
                <thead><tr><th>Name</th><th>Kind</th><th>Last</th></tr></thead>
                <tbody>
                <?php foreach ($linkedSensors as $s): ?>
                    <tr>
                        <td><a href="<?= App::e(App::url('pages/env_sensors.php?id=' . (int)$s['sensor_id'])) ?>"><?= App::e($s['name']) ?></a></td>
                        <td><?= App::e(env_sensor_kinds()[$s['sensor_kind'] ?? ''] ?? ($s['sensor_kind'] ?? '')) ?></td>
                        <td>
                            <?php if ($s['last_value'] !== null && $s['last_value'] !== ''): ?>
                                <?= App::e((string)$s['last_value']) ?> <?= App::e((string)($s['unit'] ?? '')) ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php
    layout_footer();
    return;
}

// List view
$units = [];
try {
    $units = Database::fetchAll(
        'SELECT u.*, rm.name AS room_name, dc.name AS dc_name,
                primary_u.name AS primary_unit_name
         FROM cooling_units u
         LEFT JOIN rooms rm ON rm.room_id = u.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         LEFT JOIN cooling_units primary_u ON primary_u.cooling_unit_id = u.standby_of_id
         WHERE u.is_active = 1
         ORDER BY u.name'
    );
} catch (Throwable $e) {
    $units = [];
    App::flash('error', 'Cooling tables not ready — open Settings → Schema health and run Ensure schema.');
}

layout_header('Air units & pumps', $user, 'cooling_units');
?>

<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0" style="font-size:.92rem">
            CRAC/CRAH, in-row, chillers, chilled-water and AC pumps. Mark primary/standby pairs without defining cooling zones.
            Place units on the floor plan from the palette (like row PDUs).
        </p>
    </div>
    <?php if ($canEdit): ?>
        <button type="button" class="btn btn-primary" data-modal-open="addCoolingUnit">Add unit</button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (!$units): ?>
            <p class="text-muted p-2 mb-0">No active cooling units. Add your active/standby pair to get started.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Role</th>
                        <th>Medium</th>
                        <th>Room</th>
                        <th>IP</th>
                        <th>kW</th>
                        <th>Status</th>
                        <th>Floor</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($units as $u):
                        $onFloor = $u['pos_x'] !== null && $u['pos_y'] !== null;
                        ?>
                        <tr>
                            <td>
                                <a href="<?= App::e(App::url('pages/cooling_units.php?id=' . (int)$u['cooling_unit_id'])) ?>">
                                    <strong><?= App::e($u['name']) ?></strong>
                                </a>
                                <?php if (!empty($u['manufacturer']) || !empty($u['model'])): ?>
                                    <div class="text-muted" style="font-size:.78rem">
                                        <?= App::e(trim(($u['manufacturer'] ?? '') . ' ' . ($u['model'] ?? ''))) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= App::e($types[$u['unit_type'] ?? ''] ?? ($u['unit_type'] ?? '—')) ?></td>
                            <td>
                                <?= App::e($roles[$u['unit_role'] ?? ''] ?? '—') ?>
                                <?php if (!empty($u['primary_unit_name'])): ?>
                                    <div class="text-muted" style="font-size:.75rem">→ <?= App::e($u['primary_unit_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= App::e($media[$u['cooling_medium'] ?? ''] ?? '—') ?></td>
                            <td class="text-muted" style="font-size:.85rem">
                                <?php
                                $loc = trim(($u['dc_name'] ?? '') . ' / ' . ($u['room_name'] ?? ''), ' /');
                                echo App::e($loc !== '' ? $loc : '—');
                                ?>
                            </td>
                            <td><?= App::e((string)($u['primary_ip'] ?? '—')) ?></td>
                            <td>
                                <?= $u['rated_kw_cooling'] !== null && $u['rated_kw_cooling'] !== ''
                                    ? number_format((float)$u['rated_kw_cooling'], 1)
                                    : '—' ?>
                            </td>
                            <td><span class="badge"><?= App::e((string)($u['status'] ?? '—')) ?></span></td>
                            <td><?= $onFloor ? '✓' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="modal-overlay" id="addCoolingUnit" hidden>
    <div class="modal-panel modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="addCoolingUnitTitle">
        <div class="modal-header">
            <h2 id="addCoolingUnitTitle">Add cooling unit</h2>
            <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <?php
            $edit = [];
            $formAction = 'add_unit';
            require __DIR__ . '/_cooling_unit_form.php';
            ?>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var overlay = document.getElementById('addCoolingUnit');
  if (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay && window.ColdAisle && ColdAisle.closeModal) {
        ColdAisle.closeModal(overlay);
      }
    });
  }
});
</script>
<?php endif; ?>

<?php layout_footer(); ?>
