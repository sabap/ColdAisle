<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/ups_helpers.php';
App::boot();
$user = App::requirePermission('view_power');
$canEdit = AuthManager::canEditPower($user);
$canSnmp = $canEdit || AuthManager::canEditSnmp($user);

$upsId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = (string)($_GET['action'] ?? '');
$scopes = ups_scopes();
$statuses = ups_statuses();

$rooms = Database::fetchAll(
    'SELECT r.room_id, r.name, dc.name AS dc_name
     FROM rooms r
     INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
     WHERE r.is_active = 1
     ORDER BY dc.name, r.name'
);
$zones = [];
try {
    $zones = Database::fetchAll('SELECT zone_id, name FROM power_zones ORDER BY name');
} catch (Throwable $e) {
    $zones = [];
}
$snmpProfiles = [];
try {
    $snmpProfiles = Database::fetchAll(
        'SELECT profile_id, name FROM snmp_v3_profiles WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $snmpProfiles = [];
}
$siteOidTemplates = [];
try {
    $siteOidTemplates = Database::fetchAll(
        'SELECT template_id, name, vendor, model, oid_map, notes
         FROM snmp_site_oid_templates
         WHERE is_active = 1
         ORDER BY name'
    );
} catch (Throwable $e) {
    $siteOidTemplates = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to modify UPS units.');
        App::redirect('pages/power_ups.php');
    }
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'save_ups_template') {
            $uid = (int)($_POST['ups_id'] ?? 0);
            $overwrite = !empty($_POST['overwrite']);
            if ($uid < 1) {
                throw new RuntimeException('UPS id required.');
            }
            $src = Database::fetchOne('SELECT * FROM ups_units WHERE ups_id = ? AND is_active = 1', [$uid]);
            if (!$src) {
                throw new RuntimeException('UPS not found.');
            }
            $vendor = trim((string)($src['manufacturer'] ?? ''));
            $model = trim((string)($src['model'] ?? ''));
            if ($vendor === '' || $model === '') {
                throw new RuntimeException('Set manufacturer and model on this UPS before creating a template.');
            }
            $name = trim((string)($_POST['template_name'] ?? ''));
            if ($name === '') {
                $name = ups_template_display_name($vendor, $model);
            }
            $fields = ups_template_payload_from_unit($src);
            $fieldsJson = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $oidTplId = (int)($fields['snmp_site_template_id'] ?? 0);
            $oidTplLabel = '';
            if ($oidTplId > 0) {
                try {
                    $oidRow = Database::fetchOne(
                        'SELECT name FROM snmp_site_oid_templates WHERE template_id = ?',
                        [$oidTplId]
                    );
                    $oidTplLabel = $oidRow ? (string)$oidRow['name'] : ('#' . $oidTplId);
                } catch (Throwable $e) {
                    $oidTplLabel = '#' . $oidTplId;
                }
            }
            if (class_exists('Schema')) {
                Schema::ensure();
            }
            $existing = Database::fetchOne(
                'SELECT template_id, name FROM ups_templates
                 WHERE is_active = 1 AND (name = ? OR (vendor = ? AND model = ?))',
                [$name, $vendor, $model]
            );
            if ($existing && !$overwrite) {
                $_SESSION['ups_template_overwrite'] = [
                    'ups_id' => $uid,
                    'template_id' => (int)$existing['template_id'],
                    'name' => $name,
                ];
                App::flash(
                    'error',
                    'UPS template "' . $name . '" already exists. Confirm overwrite on the UPS page, or cancel.'
                );
                App::redirect('pages/power_ups.php?id=' . $uid . '&tpl_exists=1');
            }
            $rowTpl = [
                'name' => mb_substr($name, 0, 150),
                'vendor' => $vendor !== '' ? $vendor : null,
                'model' => $model !== '' ? $model : null,
                'fields_json' => $fieldsJson,
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($existing) {
                Database::update(
                    'ups_templates',
                    $rowTpl,
                    'template_id = :id',
                    [':id' => (int)$existing['template_id']]
                );
                $tid = (int)$existing['template_id'];
                unset($_SESSION['ups_template_overwrite']);
                $msg = 'Overwrote UPS template "' . $name . '".';
            } else {
                $rowTpl['created_at'] = date('Y-m-d H:i:s');
                $tid = (int)Database::insert('ups_templates', $rowTpl);
                $msg = 'Created UPS template "' . $name . '". Apply it when adding new UPS units.';
            }
            try {
                Database::update(
                    'ups_units',
                    ['ups_template_id' => $tid, 'updated_at' => date('Y-m-d H:i:s')],
                    'ups_id = :id',
                    [':id' => $uid]
                );
            } catch (Throwable $e) {
                // column may lag ensure
            }
            if ($oidTplLabel !== '') {
                $msg .= ' Includes OID map "' . $oidTplLabel . '".';
            } else {
                $msg .= ' No site OID template on this UPS — assign one then re-save the inventory template for poll-ready copies.';
            }
            App::flash('success', $msg);
            App::redirect('pages/power_ups.php?id=' . $uid);
        }

        if ($act === 'add_ups' || $act === 'update_ups') {
            $applyTplId = (int)($_POST['ups_template_id'] ?? 0);
            if ($act === 'add_ups' && $applyTplId > 0) {
                try {
                    $tplRow = Database::fetchOne(
                        'SELECT * FROM ups_templates WHERE template_id = ? AND is_active = 1',
                        [$applyTplId]
                    );
                    if ($tplRow) {
                        $tplFields = json_decode((string)($tplRow['fields_json'] ?? '{}'), true) ?: [];
                        // Fill blank POST slots from template (like PDU create)
                        foreach (ups_template_static_keys() as $sk) {
                            if (!array_key_exists($sk, $tplFields)) {
                                continue;
                            }
                            $posted = $_POST[$sk] ?? null;
                            $blank = $posted === null || $posted === '' || $posted === '0';
                            if ($sk === 'snmp_enabled' && empty($_POST['snmp_enabled'])
                                && (!empty($tplFields['snmp_enabled']) || !empty($tplFields['snmp_site_template_id']))
                            ) {
                                $_POST['snmp_enabled'] = '1';
                                continue;
                            }
                            if ($blank && $tplFields[$sk] !== null && $tplFields[$sk] !== '') {
                                // Don't treat manufacturer/model defaults as intentional blanks on new form
                                if (in_array($sk, ['manufacturer', 'model', 'ups_scope', 'phases', 'width_mm', 'depth_mm', 'height_mm', 'color_hex', 'rated_kva', 'rated_kw', 'snmp_version', 'snmp_port'], true)
                                    || $blank
                                ) {
                                    // Prefer template values when form still has stock defaults
                                    $defaults = [
                                        'manufacturer' => 'Schneider Electric',
                                        'model' => 'Symmetra 40K',
                                        'ups_scope' => 'in_row',
                                        'color_hex' => '#7c3aed',
                                        'snmp_version' => '3',
                                        'snmp_port' => '161',
                                    ];
                                    if (isset($defaults[$sk]) && (string)$posted === (string)$defaults[$sk]) {
                                        $_POST[$sk] = (string)$tplFields[$sk];
                                    } elseif ($posted === null || $posted === '' || $posted === '0') {
                                        $_POST[$sk] = is_bool($tplFields[$sk])
                                            ? ($tplFields[$sk] ? '1' : '0')
                                            : (string)$tplFields[$sk];
                                    }
                                }
                            }
                        }
                        // Always take site OID map from template if form left empty
                        if ((int)($_POST['snmp_site_template_id'] ?? 0) < 1
                            && !empty($tplFields['snmp_site_template_id'])
                        ) {
                            $_POST['snmp_site_template_id'] = (string)(int)$tplFields['snmp_site_template_id'];
                        }
                        if (!isset($_POST['snmp_auto_poll']) && !empty($tplFields['snmp_auto_poll'])) {
                            $_POST['snmp_auto_poll'] = '1';
                        }
                        if (empty($_POST['snmp_enabled'])
                            && (!empty($tplFields['snmp_enabled']) || !empty($tplFields['snmp_site_template_id']))
                        ) {
                            $_POST['snmp_enabled'] = '1';
                        }
                    } else {
                        $applyTplId = 0;
                    }
                } catch (Throwable $e) {
                    $applyTplId = 0;
                }
            }

            $row = ups_fields_from_post($_POST);
            if ($row['name'] === '') {
                throw new RuntimeException('Name is required.');
            }
            if ($act === 'update_ups') {
                $uid = (int)($_POST['ups_id'] ?? 0);
                if ($uid < 1) {
                    throw new RuntimeException('UPS id required.');
                }
                $prev = Database::fetchOne(
                    'SELECT snmp_community, snmp_auth_passphrase, snmp_priv_passphrase,
                            snmp_v3_profile_id, snmp_version
                     FROM ups_units WHERE ups_id = ?',
                    [$uid]
                );
                $row = ups_finalize_snmp($row, $prev ?: null);
                $row['updated_at'] = date('Y-m-d H:i:s');
                Database::update('ups_units', $row, 'ups_id = :id', [':id' => $uid]);
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'update', 'ups', $uid, [
                    'name' => $row['name'],
                ]);
                App::flash('success', 'UPS updated.');
                App::redirect('pages/power_ups.php?id=' . $uid);
            }
            if ($applyTplId > 0) {
                $row['ups_template_id'] = $applyTplId;
            }
            $row = ups_finalize_snmp($row, null);
            $row['created_at'] = date('Y-m-d H:i:s');
            $row['updated_at'] = date('Y-m-d H:i:s');
            $id = Database::insert('ups_units', $row);
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'create', 'ups', (int)$id, [
                'name' => $row['name'],
            ]);
            App::flash('success', 'UPS created.');
            App::redirect('pages/power_ups.php?id=' . (int)$id);
        }
        if ($act === 'deactivate_ups' || $act === 'delete_ups') {
            $uid = (int)($_POST['ups_id'] ?? 0);
            if ($uid < 1) {
                throw new RuntimeException('UPS id required.');
            }
            $prev = Database::fetchOne(
                'SELECT name FROM ups_units WHERE ups_id = ? AND is_active = 1',
                [$uid]
            );
            if (!$prev) {
                throw new RuntimeException('UPS not found.');
            }
            Database::update('ups_units', [
                'is_active' => 0,
                'pos_x' => null,
                'pos_y' => null,
                'pos_z' => null,
                'snmp_auto_poll' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'ups_id = :id', [':id' => $uid]);
            AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'delete', 'ups', $uid, [
                'name' => $prev['name'] ?? null,
            ]);
            App::flash('success', 'UPS removed from inventory (soft-deleted).');
            App::redirect('pages/power_ups.php');
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
        App::redirect('pages/power_ups.php' . ($upsId ? '?id=' . $upsId : ''));
    }
}

// Detail
if ($upsId > 0 && $action !== 'edit' && $action !== 'new') {
    $u = Database::fetchOne(
        'SELECT u.*, rm.name AS room_name, dc.name AS dc_name, z.name AS zone_name
         FROM ups_units u
         LEFT JOIN rooms rm ON rm.room_id = u.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         LEFT JOIN power_zones z ON z.zone_id = u.zone_id
         WHERE u.ups_id = ? AND u.is_active = 1',
        [$upsId]
    );
    if (!$u) {
        App::flash('error', 'UPS not found.');
        App::redirect('pages/power_ups.php');
    }
    $health = ups_health_status($u);
    $poll = null;
    if (!empty($u['last_poll_json'])) {
        $poll = json_decode((string)$u['last_poll_json'], true);
    }
    $tplExists = !empty($_GET['tpl_exists']) && !empty($_SESSION['ups_template_overwrite'])
        && (int)($_SESSION['ups_template_overwrite']['ups_id'] ?? 0) === $upsId;
    $tplExistName = $tplExists ? (string)($_SESSION['ups_template_overwrite']['name'] ?? '') : '';
    layout_header('UPS: ' . $u['name'], $user, 'power_ups');
    ?>
    <?php if ($tplExists && $canEdit): ?>
    <div class="alert alert-warning" style="margin-bottom:1rem">
        <strong>UPS template already exists:</strong> <?= App::e($tplExistName) ?>
        <form method="post" style="display:inline;margin-left:.75rem">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="save_ups_template">
            <input type="hidden" name="ups_id" value="<?= (int)$upsId ?>">
            <input type="hidden" name="overwrite" value="1">
            <input type="hidden" name="template_name" value="<?= App::e($tplExistName) ?>">
            <button type="submit" class="btn btn-sm btn-primary">Overwrite template</button>
        </form>
        <a class="btn btn-sm btn-secondary" href="?id=<?= (int)$upsId ?>">Cancel</a>
    </div>
    <?php endif; ?>
    <div class="flex-between mb-2">
        <div>
            <span class="text-muted"><?= App::e(($u['dc_name'] ?? '') . ' / ' . ($u['room_name'] ?? 'Unplaced')) ?></span>
            <p class="mb-0" style="margin-top:.35rem">
                <span class="health-chip health-chip-<?= App::e($health) ?>">
                    <span class="health-pulse health-pulse-<?= App::e($health) ?>" aria-hidden="true"></span>
                    <span class="health-chip-label"><?= App::e($u['last_output_status'] ?? ($health === 'ok' ? 'Healthy' : $health)) ?></span>
                </span>
                <span class="badge" style="margin-left:.35rem"><?= App::e($scopes[$u['ups_scope'] ?? ''] ?? ($u['ups_scope'] ?? '')) ?></span>
            </p>
        </div>
        <div class="flex gap-1" style="flex-wrap:wrap">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_ups.php')) ?>">← UPSs</a>
            <?php if ($canEdit): ?>
                <a class="btn btn-primary" href="?id=<?= (int)$upsId ?>&action=edit">Edit</a>
                <form method="post" style="display:inline;margin:0"
                      onsubmit="return confirm('Create a Power → UPS inventory template from this unit (manufacturer, model, dimensions, SNMP/OID map)?');">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_ups_template">
                    <input type="hidden" name="ups_id" value="<?= (int)$upsId ?>">
                    <button type="submit" class="btn btn-secondary">Create UPS template</button>
                </form>
                <form method="post" style="display:inline;margin:0"
                      onsubmit="return confirm('Remove this UPS from inventory? It will leave the floor plan and list. This soft-deletes the record (can be restored from DB if needed).');">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_ups">
                    <input type="hidden" name="ups_id" value="<?= (int)$upsId ?>">
                    <button type="submit" class="btn btn-danger">Delete UPS</button>
                </form>
            <?php endif; ?>
            <?php if ($canSnmp): ?>
                <button type="button" class="btn btn-secondary" id="btnUpsDiscover">Discover OIDs</button>
                <button type="button" class="btn btn-secondary" id="btnUpsPoll">Poll now</button>
                <label class="snmp-toggle">
                    <input type="checkbox" id="upsAutoPoll" <?= !empty($u['snmp_auto_poll']) ? 'checked' : '' ?>
                        <?= empty($u['snmp_site_template_id']) ? 'disabled' : '' ?>>
                    <span class="snmp-switch" aria-hidden="true"></span>
                    <span class="snmp-toggle-label">Scheduled poll</span>
                </label>
            <?php endif; ?>
            <?php
            $testingModeUps = class_exists('IcmpMonitorService') && IcmpMonitorService::testingModeEnabled();
            if ($testingModeUps && $canSnmp):
                ?>
                <button type="button" class="btn btn-warning btn-sm" id="btnUpsSimConn"
                    title="Testing mode: simulate connectivity / management unreachable and fire [TEST] alert">
                    Simulate connectivity outage
                </button>
                <button type="button" class="btn btn-warning btn-sm" id="btnUpsSimBatt"
                    title="Testing mode: simulate transfer from line-in to On battery and fire [TEST] alert">
                    Simulate on battery
                </button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnUpsSimRec"
                    title="Testing mode: restore On line and fire [TEST] recovery">
                    Simulate recovery
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="metrics">
        <div class="metric-card"><div class="label">Load</div>
            <div class="value"><?= $u['last_load_pct'] !== null ? App::e((string)$u['last_load_pct']) . '%' : '—' ?></div></div>
        <div class="metric-card success"><div class="label">Battery</div>
            <div class="value"><?= $u['last_battery_pct'] !== null ? App::e((string)$u['last_battery_pct']) . '%' : '—' ?></div></div>
        <div class="metric-card"><div class="label">Runtime</div>
            <div class="value"><?= $u['last_runtime_min'] !== null ? App::e((string)$u['last_runtime_min']) . ' min' : '—' ?></div></div>
        <div class="metric-card"><div class="label">Rated</div>
            <div class="value"><?= $u['rated_kva'] !== null ? App::e((string)$u['rated_kva']) . ' kVA' : '—' ?></div>
            <div class="sub"><?= $u['rated_kw'] !== null ? App::e((string)$u['rated_kw']) . ' kW' : '' ?></div></div>
    </div>
    <?php
    $upsInV = $u['last_input_voltage'] ?? null;
    $upsOutV = $u['last_output_voltage'] ?? null;
    $upsInHz = $u['last_input_freq'] ?? null;
    $upsOutHz = $u['last_output_freq'] ?? null;
    $upsOutA = $u['last_output_current'] ?? null;
    if (is_array($poll) && !empty($poll['derived'])) {
        $d = $poll['derived'];
        if ($upsInV === null && isset($d['input_voltage'])) {
            $upsInV = $d['input_voltage'];
        }
        if ($upsOutV === null && isset($d['output_voltage'])) {
            $upsOutV = $d['output_voltage'];
        }
        if ($upsInHz === null && isset($d['input_freq'])) {
            $upsInHz = $d['input_freq'];
        }
        if ($upsOutHz === null && isset($d['output_freq'])) {
            $upsOutHz = $d['output_freq'];
        }
        if ($upsOutA === null && isset($d['output_current'])) {
            $upsOutA = $d['output_current'];
        }
    }
    ?>
    <div class="metrics">
        <div class="metric-card"><div class="label">Input V</div>
            <div class="value"><?= $upsInV !== null ? App::e(rtrim(rtrim(sprintf('%.1F', (float)$upsInV), '0'), '.')) : '—' ?>
                <span class="metric-unit">V</span></div></div>
        <div class="metric-card"><div class="label">Output V</div>
            <div class="value"><?= $upsOutV !== null ? App::e(rtrim(rtrim(sprintf('%.1F', (float)$upsOutV), '0'), '.')) : '—' ?>
                <span class="metric-unit">V</span></div></div>
        <div class="metric-card"><div class="label">Output current</div>
            <div class="value"><?= $upsOutA !== null ? App::e(rtrim(rtrim(sprintf('%.2F', (float)$upsOutA), '0'), '.')) : '—' ?>
                <span class="metric-unit">A</span></div></div>
        <div class="metric-card"><div class="label">Freq (in / out)</div>
            <div class="value" style="font-size:1.1rem">
                <?= $upsInHz !== null ? App::e(rtrim(rtrim(sprintf('%.1F', (float)$upsInHz), '0'), '.')) : '—' ?>
                <span class="text-muted">/</span>
                <?= $upsOutHz !== null ? App::e(rtrim(rtrim(sprintf('%.1F', (float)$upsOutHz), '0'), '.')) : '—' ?>
                <span class="metric-unit">Hz</span>
            </div></div>
    </div>

    <!-- 24h UPS electrical history -->
    <div class="card power-history-wide mb-2" data-power-history data-scope="ups" data-id="<?= (int)$upsId ?>" data-hours="24">
        <div class="card-header flex-between">
            <h2 style="margin:0;font-size:1.05rem">Last 24 hours</h2>
            <span class="text-muted" style="font-size:.8rem">From SNMP samples · needs Poll / scheduled poll</span>
        </div>
        <div class="card-body power-history-body">
            <div class="power-chart power-chart-lg" data-metric="load_pct" data-unit="%" data-label="Output load" data-color="#a78bfa" data-height="160" data-outages="0"></div>
            <div class="power-chart" data-metric="output_voltage" data-unit="V" data-label="Output voltage" data-color="#38bdf8" data-height="120" data-hide-empty="1" data-outages="0"></div>
            <div class="power-chart" data-metric="input_voltage" data-unit="V" data-label="Input voltage" data-color="#818cf8" data-height="120" data-hide-empty="1" data-outages="0"></div>
            <div class="power-chart" data-metric="output_current" data-unit="A" data-label="Output current" data-color="#34d399" data-height="120" data-hide-empty="1" data-outages="0"></div>
            <div class="power-chart" data-metric="output_freq" data-unit="Hz" data-label="Output frequency" data-color="#fbbf24" data-height="100" data-hide-empty="1" data-outages="0"></div>
            <div class="power-chart" data-metric="input_freq" data-unit="Hz" data-label="Input frequency" data-color="#fb923c" data-height="100" data-hide-empty="1" data-outages="0"></div>
            <div class="power-chart" data-metric="battery_pct" data-unit="%" data-label="Battery" data-color="#34d399" data-height="100" data-hide-empty="1" data-outages="0"></div>
            <div class="power-chart" data-metric="kw" data-unit="kW" data-label="Est. output power" data-color="#c4b5fd" data-height="100" data-hide-empty="1" data-outages="0"></div>
        </div>
    </div>
    <script src="<?= App::e(App::url('assets/js/power-charts.js')) ?>?v=9"></script>

    <div class="card">
        <div class="card-header"><h2>Identity &amp; placement</h2></div>
        <div class="card-body">
            <dl class="rack-prop-list">
                <div><dt>Manufacturer</dt><dd><?= App::e($u['manufacturer'] ?? '—') ?></dd></div>
                <div><dt>Model</dt><dd><?= App::e($u['model'] ?? '—') ?></dd></div>
                <div><dt>Serial</dt><dd><?= App::e($u['serial_no'] ?? '—') ?></dd></div>
                <div><dt>Asset tag</dt><dd><?= App::e($u['asset_tag'] ?? '—') ?></dd></div>
                <div><dt>IP</dt><dd><?= App::e($u['primary_ip'] ?? '—') ?></dd></div>
                <div><dt>Zone</dt><dd><?= App::e($u['zone_name'] ?? '—') ?></dd></div>
                <div><dt>Warranty company</dt><dd><?= App::e($u['warranty_provider'] ?? '—') ?></dd></div>
                <div><dt>Warranty expiration</dt><dd><?= App::e($u['warranty_end'] ?? '—') ?></dd></div>
                <div><dt>Install date</dt><dd><?= App::e($u['install_date'] ?? '—') ?></dd></div>
                <div><dt>Manufacture date</dt><dd><?= App::e($u['manufacture_date'] ?? '—') ?></dd></div>
                <div><dt>Floor plan</dt><dd>
                    <?= $u['pos_x'] !== null
                        ? App::e(sprintf('x=%.2f · y=%.2f', (float)$u['pos_x'], (float)$u['pos_y']))
                        : 'Not placed — use Floor plan palette' ?>
                </dd></div>
                <div><dt>Last SNMP poll</dt>
                    <dd>
                        <?php
                        if (!function_exists('snmp_poll_age_html')) {
                            require_once dirname(__DIR__) . '/includes/snmp_helpers.php';
                        }
                        echo snmp_poll_age_html(
                            $u['snmp_last_poll_at'] ?? null,
                            !empty($u['snmp_enabled']) || !empty($u['snmp_auto_poll']),
                            (string)($u['snmp_last_poll_at'] ?? '')
                        );
                        ?>
                    </dd>
                </div>
                <?php
                $uptimeDisp = null;
                if (is_array($poll) && !empty($poll['derived']['sysuptime_label'])) {
                    $uptimeDisp = (string)$poll['derived']['sysuptime_label'];
                }
                if ($uptimeDisp):
                    ?>
                <div><dt>System uptime</dt><dd><?= App::e($uptimeDisp) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <?php
    $siteTplId = (int)($u['snmp_site_template_id'] ?? 0);
    $siteTpl = null;
    if ($siteTplId > 0) {
        foreach ($siteOidTemplates as $st) {
            if ((int)($st['template_id'] ?? 0) === $siteTplId) {
                $siteTpl = $st;
                break;
            }
        }
        if (!$siteTpl) {
            try {
                $siteTpl = Database::fetchOne(
                    'SELECT template_id, name, vendor, model, notes FROM snmp_site_oid_templates WHERE template_id = ?',
                    [$siteTplId]
                );
            } catch (Throwable $e) {
                $siteTpl = null;
            }
        }
    }
    $siteTplKeyCount = 0;
    if ($siteTpl && !empty($siteTpl['oid_map'])) {
        $m = is_array($siteTpl['oid_map'])
            ? $siteTpl['oid_map']
            : (json_decode((string)$siteTpl['oid_map'], true) ?: []);
        $siteTplKeyCount = is_array($m) ? count($m) : 0;
    }
    ?>
    <div class="card mb-2" id="upsSnmpCard">
        <div class="card-header flex-between">
            <h2 class="mt-0 mb-0" style="font-size:1.05rem">SNMP / OID template</h2>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/snmp.php')) ?>">Manage site templates</a>
        </div>
        <div class="card-body">
            <dl class="rack-prop-list" style="margin-bottom:1rem">
                <div>
                    <dt>Assigned template</dt>
                    <dd id="upsTplName">
                        <?php if ($siteTpl): ?>
                            <strong><?= App::e((string)($siteTpl['name'] ?? ('Template #' . $siteTplId))) ?></strong>
                            <?php if ($siteTplKeyCount > 0): ?>
                                <span class="text-muted"> · <?= (int)$siteTplKeyCount ?> OIDs</span>
                            <?php endif; ?>
                            <?php if (!empty($siteTpl['vendor']) || !empty($siteTpl['model'])): ?>
                                <span class="text-muted"> · <?= App::e(trim(($siteTpl['vendor'] ?? '') . ' ' . ($siteTpl['model'] ?? ''))) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">None — assign an existing template, run Discover OIDs, or use the default APC map</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div><dt>SNMP</dt>
                    <dd><?= !empty($u['snmp_enabled']) ? 'Enabled' : 'Disabled' ?>
                        · v<?= App::e((string)($u['snmp_version'] ?? '—')) ?>
                        <?php if (!empty($u['primary_ip'])): ?>
                            · <?= App::e((string)$u['primary_ip']) ?>:<?= (int)($u['snmp_port'] ?? 161) ?>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
            <?php if ($canSnmp): ?>
            <div class="form-grid" style="margin:0">
                <div class="form-row full">
                    <label for="upsAssignTpl">Assign site OID template</label>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                        <select class="form-control" id="upsAssignTpl" style="flex:1;min-width:14rem">
                            <option value="">— None (clear) —</option>
                            <?php foreach ($siteOidTemplates as $st):
                                $stId = (int)$st['template_id'];
                                $stLabel = (string)($st['name'] ?? ('Template #' . $stId));
                                $keyCount = 0;
                                if (!empty($st['oid_map'])) {
                                    $mm = is_array($st['oid_map'])
                                        ? $st['oid_map']
                                        : (json_decode((string)$st['oid_map'], true) ?: []);
                                    $keyCount = is_array($mm) ? count($mm) : 0;
                                }
                                $hint = '';
                                $notes = strtolower((string)($st['notes'] ?? '') . ' ' . $stLabel);
                                if (str_contains($notes, 'ups') || str_contains($notes, 'powernet') || str_contains($notes, '318')) {
                                    $hint = ' [UPS]';
                                }
                                ?>
                                <option value="<?= $stId ?>" <?= $siteTplId === $stId ? 'selected' : '' ?>>
                                    <?= App::e($stLabel) ?><?= $hint ?>
                                    <?php if ($keyCount > 0): ?> · <?= $keyCount ?> OIDs<?php endif; ?>
                                    <?php if (!empty($st['vendor']) || !empty($st['model'])): ?>
                                        (<?= App::e(trim(($st['vendor'] ?? '') . ' ' . ($st['model'] ?? ''))) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-primary btn-sm" id="btnUpsAssignTpl">Apply</button>
                    </div>
                    <p class="text-muted" style="font-size:.75rem;margin:.35rem 0 0">
                        Poll now and scheduled poll use this site OID map. Prefer a template created by
                        <strong>Discover OIDs</strong> on another UPS, or create the default APC PowerNet map below.
                        Edit maps under <a href="<?= App::e(App::url('pages/snmp.php')) ?>">SNMP</a>.
                    </p>
                </div>
                <div class="form-row full" style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <button type="button" class="btn btn-secondary btn-sm" id="btnUpsDefaultTpl"
                        title="Create or reuse the standard APC/Symmetra PowerNet OID map and assign it">
                        Use default APC UPS map
                    </button>
                </div>
            </div>
            <?php elseif (!$siteTpl): ?>
                <p class="text-muted mb-0" style="font-size:.85rem">
                    You need edit power or SNMP permission to assign a template.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (is_array($poll) && !empty($poll['metrics'])): ?>
    <div class="card">
        <div class="card-header"><h2>Last poll metrics</h2></div>
        <div class="card-body flush">
            <table class="data">
                <thead><tr><th>Key</th><th>Value</th></tr></thead>
                <tbody>
                <?php
                $displayMap = is_array($poll['metrics_display'] ?? null) ? $poll['metrics_display'] : [];
                foreach ($poll['metrics'] as $k => $v):
                    $disp = $displayMap[(string)$k] ?? ups_format_metric_display((string)$k, $v);
                    ?>
                    <tr><td><code><?= App::e((string)$k) ?></code></td>
                        <td><?= App::e(is_scalar($disp) ? (string)$disp : json_encode($disp)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div id="upsSnmpLog" class="text-muted" style="font-size:.85rem;margin-top:1rem"></div>
    <?php if ($canSnmp): ?>
    <script>
    (function () {
        var upsId = <?= (int)$upsId ?>;
        function toast(m, t) {
            if (window.ColdAisle && ColdAisle.toast) ColdAisle.toast(m, t || 'info');
            else alert(m);
        }
        function api(body, timeoutMs) {
            var opts = { method: 'POST', body: body };
            if (timeoutMs) opts.timeoutMs = timeoutMs;
            return ColdAisle.api('api/snmp_ups.php', opts);
        }
        var upsLabel = <?= json_encode((string)($u['name'] ?? $u['label'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
        var upsHost = <?= json_encode(trim((string)($u['primary_ip'] ?? '')), JSON_UNESCAPED_SLASHES) ?>;
        var log = document.getElementById('upsSnmpLog');
        var btnD = document.getElementById('btnUpsDiscover');
        var btnP = document.getElementById('btnUpsPoll');
        var auto = document.getElementById('upsAutoPoll');
        var btnAssign = document.getElementById('btnUpsAssignTpl');
        var selTpl = document.getElementById('upsAssignTpl');
        var btnDefault = document.getElementById('btnUpsDefaultTpl');
        if (btnAssign && selTpl) {
            btnAssign.addEventListener('click', function () {
                var tid = parseInt(selTpl.value || '0', 10) || 0;
                btnAssign.disabled = true;
                api({ action: 'assign_template', ups_id: upsId, template_id: tid })
                    .then(function (data) {
                        toast(data.message || 'Template updated', 'success');
                        if (auto) auto.disabled = !(tid > 0);
                        setTimeout(function () { location.reload(); }, 600);
                    })
                    .catch(function (e) { toast(e.message || 'Assign failed', 'error'); })
                    .finally(function () { btnAssign.disabled = false; });
            });
        }
        if (btnDefault) {
            btnDefault.addEventListener('click', function () {
                btnDefault.disabled = true;
                api({ action: 'create_default_template', ups_id: upsId })
                    .then(function (data) {
                        toast(data.message || 'Default template assigned', 'success');
                        if (auto) auto.disabled = false;
                        setTimeout(function () { location.reload(); }, 600);
                    })
                    .catch(function (e) { toast(e.message || 'Failed', 'error'); })
                    .finally(function () { btnDefault.disabled = false; });
            });
        }
        if (btnD) btnD.addEventListener('click', function () {
            btnD.disabled = true;
            api({ action: 'discover', ups_id: upsId })
                .then(function (data) {
                    toast(data.message || 'Discover complete', 'success');
                    if (data.proposed_map) {
                        return api({
                            action: 'save_template',
                            ups_id: upsId,
                            oid_map: data.proposed_map,
                            template_name: 'APC UPS Symmetra',
                            overwrite: true
                        });
                    }
                })
                .then(function (data) {
                    if (data && data.message) toast(data.message, 'success');
                    if (auto) auto.disabled = false;
                    setTimeout(function () { location.reload(); }, 700);
                })
                .catch(function (e) { toast(e.message || 'Discover failed', 'error'); })
                .finally(function () { btnD.disabled = false; });
        });
        if (btnP) btnP.addEventListener('click', function () {
            if (btnP.disabled) return;
            btnP.disabled = true;
            if (typeof ColdAisle.runSnmpPoll !== 'function') {
                api({ action: 'poll', ups_id: upsId }, 55000)
                    .then(function (data) {
                        toast(data.message || 'Poll complete', 'success');
                        if (log) log.textContent = data.message || '';
                        setTimeout(function () { location.reload(); }, 800);
                    })
                    .catch(function (e) { toast(e.message || 'Poll failed', 'error'); })
                    .finally(function () { btnP.disabled = false; });
                return;
            }
            ColdAisle.runSnmpPoll({
                title: 'SNMP poll — UPS',
                name: upsLabel,
                host: upsHost,
                timeoutMs: 55000,
                request: function (ctx) {
                    return api({ action: 'poll', ups_id: upsId }, (ctx && ctx.timeoutMs) || 55000);
                },
                onSuccess: function (data) {
                    toast(data.message || 'Poll complete', 'success');
                    if (log) log.textContent = data.message || '';
                    setTimeout(function () { location.reload(); }, 900);
                },
                onError: function () {
                    btnP.disabled = false;
                }
            });
        });
        if (auto) auto.addEventListener('change', function () {
            var on = !!auto.checked;
            auto.disabled = true;
            api({ action: 'set_auto_poll', ups_id: upsId, enabled: on })
                .then(function (d) { toast(d.message || 'Updated', 'success'); })
                .catch(function (e) { auto.checked = !on; toast(e.message || 'Failed', 'error'); })
                .finally(function () { auto.disabled = false; });
        });
        function wireSim(btnId, action, confirmMsg, toastType) {
            var btn = document.getElementById(btnId);
            if (!btn) return;
            btn.addEventListener('click', function () {
                if (!confirm(confirmMsg)) return;
                btn.disabled = true;
                api({ action: action, ups_id: upsId })
                    .then(function (data) {
                        toast(data.message || 'Done', toastType || 'warning');
                        setTimeout(function () { location.reload(); }, 700);
                    })
                    .catch(function (e) { toast(e.message || 'Failed', 'error'); })
                    .finally(function () { btn.disabled = false; });
            });
        }
        wireSim(
            'btnUpsSimConn',
            'simulate_connectivity_outage',
            'Simulate connectivity outage and fire a [TEST] alert for this UPS?',
            'warning'
        );
        wireSim(
            'btnUpsSimBatt',
            'simulate_on_battery',
            'Simulate transfer from line-in to On battery and fire a [TEST] alert?',
            'warning'
        );
        wireSim(
            'btnUpsSimRec',
            'simulate_recovery',
            'Simulate recovery to On line and fire a [TEST] recovery alert?',
            'success'
        );
    })();
    </script>
    <?php endif; ?>
    <?php
    layout_footer();
    exit;
}

// Edit / new form
if ($action === 'new' || ($action === 'edit' && $upsId > 0)) {
    if (!$canEdit) {
        App::flash('error', 'Permission denied.');
        App::redirect('pages/power_ups.php');
    }
    $u = null;
    if ($action === 'edit') {
        $u = Database::fetchOne('SELECT * FROM ups_units WHERE ups_id = ? AND is_active = 1', [$upsId]);
        if (!$u) {
            App::flash('error', 'UPS not found.');
            App::redirect('pages/power_ups.php');
        }
    }
    $upsInvTemplates = ups_template_list();
    $upsTplPayloads = [];
    foreach ($upsInvTemplates as $t) {
        $upsTplPayloads[(int)$t['template_id']] = json_decode((string)($t['fields_json'] ?? '{}'), true) ?: [];
    }
    layout_header($u ? 'Edit UPS' : 'New UPS', $user, 'power_ups');
    ?>
    <form method="post" class="card" id="upsEditForm">
        <div class="card-header flex-between">
            <h2><?= $u ? 'Edit UPS' : 'Add UPS' ?></h2>
            <a class="btn btn-secondary btn-sm" href="<?= App::e(App::url('pages/power_ups.php' . ($upsId ? '?id=' . $upsId : ''))) ?>">Cancel</a>
        </div>
        <div class="card-body form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $u ? 'update_ups' : 'add_ups' ?>">
            <?php if ($u): ?><input type="hidden" name="ups_id" value="<?= (int)$u['ups_id'] ?>"><?php endif; ?>

            <?php if (!$u): ?>
            <div class="form-row full">
                <label>UPS template</label>
                <select class="form-control" name="ups_template_id" id="ups_template_id">
                    <option value="">— None (manual entry) —</option>
                    <?php foreach ($upsInvTemplates as $t):
                        $tid = (int)$t['template_id'];
                        $lab = (string)($t['name'] ?? ('Template #' . $tid));
                        $vm = trim(($t['vendor'] ?? '') . ' ' . ($t['model'] ?? ''));
                        ?>
                        <option value="<?= $tid ?>"
                                data-fields="<?= App::e(json_encode($upsTplPayloads[$tid] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <?= App::e($lab) ?><?= $vm !== '' ? ' · ' . App::e($vm) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-muted" style="font-size:.75rem;margin:.3rem 0 0">
                    Inventory templates from <a href="<?= App::e(App::url('pages/power_pdu_templates.php#ups-templates')) ?>">Power → Power Templates</a>
                    (create from a configured UPS with <strong>Create UPS template</strong>).
                    Selecting one fills manufacturer, model, size, SNMP, and site OID map.
                    <?php if (!$upsInvTemplates): ?>
                        <br><strong>No UPS templates yet</strong> — configure a UPS, then create a template from its detail page.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="form-row"><label>Name *</label>
                <input class="form-control" name="name" required value="<?= App::e($u['name'] ?? '') ?>" placeholder="Symmetra 40K: UPS A"></div>
            <div class="form-row"><label>Scope</label>
                <select class="form-control" name="ups_scope" id="ups_scope">
                    <?php foreach ($scopes as $k => $lab): ?>
                        <option value="<?= App::e($k) ?>" <?= ($u['ups_scope'] ?? 'in_row') === $k ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Manufacturer</label>
                <input class="form-control" name="manufacturer" id="ups_manufacturer" value="<?= App::e($u['manufacturer'] ?? 'Schneider Electric') ?>"></div>
            <div class="form-row"><label>Model</label>
                <input class="form-control" name="model" id="ups_model" value="<?= App::e($u['model'] ?? 'Symmetra 40K') ?>"></div>
            <div class="form-row"><label>Serial</label>
                <input class="form-control" name="serial_no" value="<?= App::e($u['serial_no'] ?? '') ?>"
                       placeholder="Filled from SNMP poll when available"></div>
            <div class="form-row"><label>Asset tag</label>
                <input class="form-control" name="asset_tag" value="<?= App::e($u['asset_tag'] ?? '') ?>"></div>
            <div class="form-row"><label>Primary IP</label>
                <input class="form-control" name="primary_ip" value="<?= App::e($u['primary_ip'] ?? '') ?>" placeholder="NMC / management IP"></div>
            <div class="form-row"><label>Rated kVA</label>
                <input class="form-control" type="number" step="0.1" name="rated_kva" id="ups_rated_kva" value="<?= App::e((string)($u['rated_kva'] ?? '40')) ?>"></div>
            <div class="form-row"><label>Rated kW</label>
                <input class="form-control" type="number" step="0.1" name="rated_kw" id="ups_rated_kw" value="<?= App::e((string)($u['rated_kw'] ?? '40')) ?>"></div>
            <div class="form-row"><label>Phases</label>
                <input class="form-control" type="number" min="1" max="3" name="phases" id="ups_phases" value="<?= (int)($u['phases'] ?? 3) ?>"></div>
            <div class="form-row"><label>Warranty company</label>
                <input class="form-control" name="warranty_provider" id="ups_warranty_provider" value="<?= App::e($u['warranty_provider'] ?? '') ?>"
                       placeholder="e.g. Schneider Electric"></div>
            <div class="form-row"><label>Warranty expiration</label>
                <input class="form-control" type="date" name="warranty_end" value="<?= App::e($u['warranty_end'] ?? '') ?>"></div>
            <div class="form-row"><label>Install date</label>
                <input class="form-control" type="date" name="install_date" value="<?= App::e($u['install_date'] ?? '') ?>"></div>
            <div class="form-row"><label>Manufacture date</label>
                <input class="form-control" type="date" name="manufacture_date" value="<?= App::e($u['manufacture_date'] ?? '') ?>"></div>
            <div class="form-row"><label>Room (optional)</label>
                <select class="form-control" name="room_id">
                    <option value="">—</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= (int)$r['room_id'] ?>" <?= (int)($u['room_id'] ?? 0) === (int)$r['room_id'] ? 'selected' : '' ?>>
                            <?= App::e($r['dc_name'] . ' / ' . $r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Power zone</label>
                <select class="form-control" name="zone_id">
                    <option value="">—</option>
                    <?php foreach ($zones as $z): ?>
                        <option value="<?= (int)$z['zone_id'] ?>" <?= (int)($u['zone_id'] ?? 0) === (int)$z['zone_id'] ? 'selected' : '' ?>>
                            <?= App::e($z['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Status</label>
                <select class="form-control" name="status">
                    <?php foreach ($statuses as $k => $lab): ?>
                        <option value="<?= App::e($k) ?>" <?= ($u['status'] ?? 'production') === $k ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Color</label>
                <input class="form-control" type="color" name="color_hex" id="ups_color_hex" value="<?= App::e($u['color_hex'] ?? '#7c3aed') ?>"></div>
            <div class="form-row"><label>Width mm</label>
                <input class="form-control" type="number" name="width_mm" id="ups_width_mm" value="<?= (int)($u['width_mm'] ?? 600) ?>"></div>
            <div class="form-row"><label>Depth mm</label>
                <input class="form-control" type="number" name="depth_mm" id="ups_depth_mm" value="<?= (int)($u['depth_mm'] ?? 1100) ?>"></div>
            <div class="form-row"><label>Height mm</label>
                <input class="form-control" type="number" name="height_mm" id="ups_height_mm" value="<?= (int)($u['height_mm'] ?? 2000) ?>"></div>

            <div class="form-row full"><h4 class="mt-0" style="font-size:.95rem;color:var(--muted)">SNMP</h4></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="snmp_enabled" id="ups_snmp_enabled" value="1" <?= !empty($u['snmp_enabled']) || !$u ? 'checked' : '' ?>>
                SNMP enabled
            </label></div>
            <div class="form-row"><label>Version</label>
                <select class="form-control" name="snmp_version" id="ups_snmp_version">
                    <?php foreach (['3' => 'v3', '2c' => 'v2c', '1' => 'v1'] as $v => $lab): ?>
                        <option value="<?= $v ?>" <?= (string)($u['snmp_version'] ?? '3') === $v ? 'selected' : '' ?>><?= $lab ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Port</label>
                <input class="form-control" type="number" name="snmp_port" id="ups_snmp_port" value="<?= (int)($u['snmp_port'] ?? 161) ?>"></div>
            <div class="form-row"><label>SNMPv3 profile</label>
                <select class="form-control" name="snmp_v3_profile_id" id="ups_snmp_v3_profile_id">
                    <option value="">— manual / keep —</option>
                    <?php foreach ($snmpProfiles as $p): ?>
                        <option value="<?= (int)$p['profile_id'] ?>" <?= (int)($u['snmp_v3_profile_id'] ?? 0) === (int)$p['profile_id'] ? 'selected' : '' ?>>
                            <?= App::e($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php
            $editTplId = (int)($u['snmp_site_template_id'] ?? 0);
            ?>
            <div class="form-row full">
                <label>OID map (site template)</label>
                <select class="form-control" name="snmp_site_template_id" id="ups_snmp_site_template_id">
                    <option value="">— None (Discover later or assign on detail page) —</option>
                    <?php foreach ($siteOidTemplates as $st):
                        $stId = (int)$st['template_id'];
                        $stLabel = (string)($st['name'] ?? ('Template #' . $stId));
                        $keyCount = 0;
                        if (!empty($st['oid_map'])) {
                            $mm = is_array($st['oid_map'])
                                ? $st['oid_map']
                                : (json_decode((string)$st['oid_map'], true) ?: []);
                            $keyCount = is_array($mm) ? count($mm) : 0;
                        }
                        $notes = strtolower((string)($st['notes'] ?? '') . ' ' . $stLabel);
                        $hint = (str_contains($notes, 'ups') || str_contains($notes, 'powernet') || str_contains($notes, '318'))
                            ? ' [UPS]' : '';
                        ?>
                        <option value="<?= $stId ?>" <?= $editTplId === $stId ? 'selected' : '' ?>>
                            <?= App::e($stLabel) ?><?= $hint ?>
                            <?php if ($keyCount > 0): ?> · <?= $keyCount ?> OIDs<?php endif; ?>
                            <?php if (!empty($st['vendor']) || !empty($st['model'])): ?>
                                (<?= App::e(trim(($st['vendor'] ?? '') . ' ' . ($st['model'] ?? ''))) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-muted" style="font-size:.75rem;margin:.3rem 0 0">
                    Required for <strong>Poll now</strong> and scheduled poll. Create via Discover OIDs on a UPS,
                    <strong>Use default APC UPS map</strong> on the detail page, or manage under
                    <a href="<?= App::e(App::url('pages/snmp.php')) ?>">SNMP</a>.
                    <?php if (!$siteOidTemplates): ?>
                        <br><strong>No site templates yet</strong> — open a UPS and Discover OIDs, or use the default APC map.
                    <?php endif; ?>
                </p>
            </div>
            <div class="form-row full"><label>Notes</label>
                <textarea class="form-control" name="notes" rows="2"><?= App::e($u['notes'] ?? '') ?></textarea></div>
            <div class="form-row full">
                <button class="btn btn-primary" type="submit">Save UPS</button>
            </div>
        </div>
    </form>
    <?php if (!$u): ?>
    <script>
    (function () {
        var sel = document.getElementById('ups_template_id');
        if (!sel) return;
        function setVal(name, val) {
            var el = document.querySelector('#upsEditForm [name="' + name + '"]');
            if (!el || val == null || val === '') return;
            if (el.type === 'checkbox') {
                el.checked = !!val && val !== '0' && val !== 0;
                return;
            }
            el.value = String(val);
        }
        sel.addEventListener('change', function () {
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) return;
            var raw = opt.getAttribute('data-fields') || '{}';
            var f;
            try { f = JSON.parse(raw); } catch (e) { f = {}; }
            if (!f || typeof f !== 'object') return;
            [
                'manufacturer', 'model', 'ups_scope', 'rated_kva', 'rated_kw', 'phases',
                'width_mm', 'depth_mm', 'height_mm', 'color_hex', 'warranty_provider',
                'snmp_version', 'snmp_port', 'snmp_v3_profile_id', 'snmp_site_template_id',
                'snmp_v3_sec_level', 'snmp_auto_poll', 'snmp_enabled'
            ].forEach(function (k) {
                if (f[k] != null && f[k] !== '') setVal(k, f[k]);
            });
            if (f.snmp_enabled || f.snmp_site_template_id) {
                setVal('snmp_enabled', 1);
            }
            if (window.ColdAisle && ColdAisle.toast) {
                ColdAisle.toast('Applied UPS template fields — review and set name / IP', 'info');
            }
        });
    })();
    </script>
    <?php endif; ?>
    <?php
    layout_footer();
    exit;
}

// List
$units = [];
try {
    if (!function_exists('power_natural_sort_rows')) {
        require_once dirname(__DIR__) . '/includes/power_helpers.php';
    }
    $units = power_natural_sort_rows(Database::fetchAll(
        'SELECT u.*, rm.name AS room_name, dc.name AS dc_name, z.name AS zone_name
         FROM ups_units u
         LEFT JOIN rooms rm ON rm.room_id = u.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         LEFT JOIN power_zones z ON z.zone_id = u.zone_id
         WHERE u.is_active = 1
         ORDER BY u.name'
    ), 'name');
} catch (Throwable $e) {
    $units = [];
}

layout_header('UPS', $user, 'power_ups');
?>
<div class="flex-between mb-2">
    <p class="text-muted mb-0">
        In-row and in-rack UPS inventory (e.g. Schneider Symmetra 40K). Place in-row units on the floor plan for 3D / NOC.
    </p>
    <?php if ($canEdit): ?>
        <a class="btn btn-primary" href="?action=new">+ Add UPS</a>
    <?php endif; ?>
</div>
<div class="card">
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th>
                <th>Scope</th>
                <th>Model</th>
                <th>IP</th>
                <th>Location</th>
                <th>Load</th>
                <th>Battery</th>
                <th>Polled</th>
                <th>Status</th>
                <th>Health</th>
            </tr>
            </thead>
            <tbody>
            <?php
            if (!function_exists('snmp_poll_age_html')) {
                require_once dirname(__DIR__) . '/includes/snmp_helpers.php';
            }
            foreach ($units as $u):
                $hp = ups_health_status($u);
                ?>
                <tr class="<?= in_array($hp, ['warn', 'crit'], true) ? 'health-row-' . App::e($hp) : '' ?>">
                    <td><a href="?id=<?= (int)$u['ups_id'] ?>"><strong><?= App::e($u['name']) ?></strong></a></td>
                    <td><span class="badge"><?= App::e($scopes[$u['ups_scope'] ?? ''] ?? ($u['ups_scope'] ?? '')) ?></span></td>
                    <td><?= App::e(trim(($u['manufacturer'] ?? '') . ' ' . ($u['model'] ?? '')) ?: '—') ?></td>
                    <td class="mono"><?= App::e($u['primary_ip'] ?? '—') ?></td>
                    <td><?= App::e(trim(($u['dc_name'] ?? '') . ' / ' . ($u['room_name'] ?? ''), ' /') ?: '—') ?></td>
                    <td><?= $u['last_load_pct'] !== null ? App::e((string)$u['last_load_pct']) . '%' : '—' ?></td>
                    <td><?= $u['last_battery_pct'] !== null ? App::e((string)$u['last_battery_pct']) . '%' : '—' ?></td>
                    <td>
                        <?= snmp_poll_age_html(
                            $u['snmp_last_poll_at'] ?? null,
                            !empty($u['snmp_enabled']) || !empty($u['snmp_auto_poll']),
                            (string)($u['snmp_last_poll_at'] ?? '')
                        ) ?>
                    </td>
                    <td><?= App::e($u['last_output_status'] ?? '—') ?></td>
                    <td>
                        <span class="health-chip health-chip-<?= App::e($hp) ?>">
                            <span class="health-pulse health-pulse-<?= App::e($hp) ?>" aria-hidden="true"></span>
                            <span class="health-chip-label"><?= App::e($hp) ?></span>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$units): ?>
                <tr><td colspan="10" class="text-muted">No UPS units yet. Add a Symmetra / Smart-UPS and place in-row frames on the floor plan.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layout_footer(); ?>
