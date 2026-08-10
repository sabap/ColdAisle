<?php
/**
 * Power → Power Templates — PDU + UPS inventory templates.
 * Edit can save template only, or save + apply to all linked PDUs (PDU type).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/power_helpers.php';
require_once dirname(__DIR__) . '/includes/ups_helpers.php';
App::boot();
$user = App::requirePermission('view_power');
$canEdit = AuthManager::canEditPower($user);

$templateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$actionGet = (string)($_GET['action'] ?? '');

// Ensure columns/tables
try {
    if (class_exists('Schema')) {
        Schema::ensure();
    }
} catch (Throwable $e) {
    // continue
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to modify PDU templates.');
        App::redirect('pages/power_pdu_templates.php');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'delete_template') {
            $tid = (int)($_POST['template_id'] ?? 0);
            $kind = strtolower(trim((string)($_POST['template_kind'] ?? 'pdu')));
            if ($tid < 1) {
                throw new RuntimeException('Template id required.');
            }
            if ($kind === 'ups') {
                Database::update(
                    'ups_templates',
                    ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                    'template_id = :id',
                    [':id' => $tid]
                );
                App::flash('success', 'UPS template deactivated.');
            } else {
                Database::update(
                    'pdu_templates',
                    ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                    'template_id = :id',
                    [':id' => $tid]
                );
                App::flash('success', 'PDU template deactivated.');
            }
            App::redirect('pages/power_pdu_templates.php');
        }

        if ($action === 'save_ups_template') {
            $tid = (int)($_POST['template_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $vendor = trim((string)($_POST['vendor'] ?? ''));
            $model = trim((string)($_POST['model'] ?? ''));
            if ($name === '') {
                $name = ups_template_display_name($vendor, $model);
            }
            if ($name === '' || $name === 'UPS template') {
                throw new RuntimeException('Name or vendor + model is required.');
            }
            $fields = [];
            foreach (ups_template_static_keys() as $k) {
                if (!array_key_exists($k, $_POST)) {
                    continue;
                }
                $v = $_POST[$k];
                if ($k === 'snmp_enabled' || $k === 'snmp_auto_poll') {
                    $fields[$k] = !empty($v) ? 1 : 0;
                    continue;
                }
                if ($v === null || $v === '') {
                    continue;
                }
                if (in_array($k, ['snmp_site_template_id', 'snmp_v3_profile_id', 'snmp_port', 'phases', 'width_mm', 'depth_mm', 'height_mm'], true)) {
                    $fields[$k] = (int)$v;
                    if ($fields[$k] === 0 && in_array($k, ['snmp_site_template_id', 'snmp_v3_profile_id'], true)) {
                        unset($fields[$k]);
                    }
                    continue;
                }
                if (in_array($k, ['rated_kva', 'rated_kw'], true)) {
                    $fields[$k] = (float)$v;
                    continue;
                }
                $fields[$k] = is_string($v) ? trim($v) : $v;
            }
            if ($vendor !== '') {
                $fields['manufacturer'] = $vendor;
            }
            if ($model !== '') {
                $fields['model'] = $model;
            }
            $row = [
                'name' => mb_substr($name, 0, 150),
                'vendor' => $vendor !== '' ? $vendor : null,
                'model' => $model !== '' ? $model : null,
                'fields_json' => json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'notes' => (($n = trim((string)($_POST['notes'] ?? ''))) !== '') ? $n : null,
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($tid > 0) {
                Database::update('ups_templates', $row, 'template_id = :id', [':id' => $tid]);
            } else {
                $row['created_at'] = date('Y-m-d H:i:s');
                $tid = (int)Database::insert('ups_templates', $row);
            }
            App::flash('success', 'UPS template saved.');
            App::redirect('pages/power_pdu_templates.php?type=ups&id=' . $tid);
        }

        if ($action === 'save_template') {
            $tid = (int)($_POST['template_id'] ?? 0);
            $mode = (string)($_POST['save_mode'] ?? 'template_only');
            if (!in_array($mode, ['template_only', 'template_and_pdus'], true)) {
                $mode = 'template_only';
            }
            $payload = power_pdu_template_payload_from_post($_POST);
            if ($payload['name'] === '' || $payload['name'] === 'PDU template') {
                if ($payload['vendor'] === null || $payload['model'] === null) {
                    throw new RuntimeException('Name or vendor + model is required.');
                }
            }
            $fieldsJson = json_encode($payload['fields'], JSON_UNESCAPED_SLASHES);
            $outletsJson = $payload['outlets']
                ? json_encode($payload['outlets'], JSON_UNESCAPED_SLASHES)
                : null;
            $row = [
                'name' => $payload['name'],
                'vendor' => $payload['vendor'],
                'model' => $payload['model'],
                'fields_json' => $fieldsJson,
                'outlets_json' => $outletsJson,
                'notes' => $payload['notes'],
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($tid > 0) {
                $exists = Database::fetchOne(
                    'SELECT template_id FROM pdu_templates WHERE template_id = ?',
                    [$tid]
                );
                if (!$exists) {
                    throw new RuntimeException('Template not found.');
                }
                Database::update('pdu_templates', $row, 'template_id = :id', [':id' => $tid]);
            } else {
                $tid = (int)Database::insert('pdu_templates', $row);
            }

            $applied = 0;
            $outletTouches = 0;
            if ($mode === 'template_and_pdus' && $tid > 0) {
                $linked = power_pdu_template_linked_pdus(
                    $tid,
                    $payload['vendor'],
                    $payload['model']
                );
                foreach ($linked as $p) {
                    $r = power_pdu_template_apply_to_pdu(
                        (int)$p['pdu_id'],
                        $tid,
                        $payload['fields'],
                        $payload['outlets'],
                        true
                    );
                    if ($r['updated']) {
                        $applied++;
                    }
                    $outletTouches += (int)$r['outlets'];
                }
            }

            if ($mode === 'template_and_pdus') {
                App::flash(
                    'success',
                    'Template saved and applied to ' . $applied . ' PDU(s)'
                    . ($outletTouches > 0 ? ' (outlet rows updated: ' . $outletTouches . ')' : '')
                    . '. Name, IP, serial, and placement on each PDU were left unchanged.'
                );
            } else {
                App::flash('success', 'PDU template saved.');
            }
            App::redirect('pages/power_pdu_templates.php?id=' . $tid);
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
        $redir = $templateId > 0
            ? 'pages/power_pdu_templates.php?id=' . $templateId
            : 'pages/power_pdu_templates.php';
        if ($actionGet === 'new' || ($action === 'save_template' && empty($_POST['template_id']))) {
            $redir = 'pages/power_pdu_templates.php?action=new';
        }
        App::redirect($redir);
    }
}

// Load site OID templates for editor
$siteOidTemplates = [];
try {
    $siteOidTemplates = Database::fetchAll(
        'SELECT template_id, name, vendor, model FROM snmp_site_oid_templates
         WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $siteOidTemplates = [];
}
$snmpProfiles = [];
try {
    $snmpProfiles = Database::fetchAll(
        'SELECT profile_id, name FROM snmp_v3_profiles WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $snmpProfiles = [];
}

$tplType = strtolower(trim((string)($_GET['type'] ?? 'pdu')));
if (!in_array($tplType, ['pdu', 'ups'], true)) {
    $tplType = 'pdu';
}

// ── UPS template detail / edit ─────────────────────────────────────────────
if ($tplType === 'ups' && ($templateId > 0 || $actionGet === 'new')) {
    $tpl = null;
    $fields = [];
    if ($templateId > 0) {
        $tpl = Database::fetchOne('SELECT * FROM ups_templates WHERE template_id = ?', [$templateId]);
        if (!$tpl || empty($tpl['is_active'])) {
            App::flash('error', 'UPS template not found.');
            App::redirect('pages/power_pdu_templates.php');
        }
        $fields = json_decode((string)($tpl['fields_json'] ?? '{}'), true) ?: [];
    }
    $f = static function (string $k, $default = '') use ($fields) {
        return $fields[$k] ?? $default;
    };
    $linked = $templateId > 0
        ? ups_template_linked_units($templateId, $tpl['vendor'] ?? null, $tpl['model'] ?? null)
        : [];
    layout_header($tpl ? ('UPS template: ' . $tpl['name']) : 'New UPS template', $user, 'power_templates');
    ?>
    <div class="flex-between mb-2">
        <div>
            <a class="btn btn-secondary btn-sm" href="<?= App::e(App::url('pages/power_pdu_templates.php#ups-templates')) ?>">← Power Templates</a>
        </div>
        <?php if ($canEdit && $templateId > 0): ?>
        <form method="post" onsubmit="return confirm('Deactivate this UPS template?');">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_template">
            <input type="hidden" name="template_kind" value="ups">
            <input type="hidden" name="template_id" value="<?= (int)$templateId ?>">
            <button type="submit" class="btn btn-danger btn-sm">Deactivate</button>
        </form>
        <?php endif; ?>
    </div>
    <form method="post" class="card">
        <div class="card-header"><h2><?= $tpl ? 'Edit UPS template' : 'New UPS template' ?></h2></div>
        <div class="card-body form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="save_ups_template">
            <?php if ($templateId > 0): ?>
                <input type="hidden" name="template_id" value="<?= (int)$templateId ?>">
            <?php endif; ?>
            <div class="form-row"><label>Name *</label>
                <input class="form-control" name="name" required value="<?= App::e($tpl['name'] ?? '') ?>" placeholder="Schneider Symmetra 40K"></div>
            <div class="form-row"><label>Vendor</label>
                <input class="form-control" name="vendor" value="<?= App::e($tpl['vendor'] ?? (string)$f('manufacturer', '')) ?>"></div>
            <div class="form-row"><label>Model</label>
                <input class="form-control" name="model" value="<?= App::e($tpl['model'] ?? (string)$f('model', '')) ?>"></div>
            <div class="form-row"><label>Scope</label>
                <select class="form-control" name="ups_scope">
                    <?php foreach (ups_scopes() as $k => $lab): ?>
                        <option value="<?= App::e($k) ?>" <?= (string)$f('ups_scope', 'in_row') === $k ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Rated kVA</label>
                <input class="form-control" type="number" step="0.1" name="rated_kva" value="<?= App::e((string)$f('rated_kva', '40')) ?>"></div>
            <div class="form-row"><label>Rated kW</label>
                <input class="form-control" type="number" step="0.1" name="rated_kw" value="<?= App::e((string)$f('rated_kw', '40')) ?>"></div>
            <div class="form-row"><label>Phases</label>
                <input class="form-control" type="number" min="1" max="3" name="phases" value="<?= (int)$f('phases', 3) ?>"></div>
            <div class="form-row"><label>Color</label>
                <input class="form-control" type="color" name="color_hex" value="<?= App::e((string)$f('color_hex', '#7c3aed')) ?>"></div>
            <div class="form-row"><label>Width mm</label>
                <input class="form-control" type="number" name="width_mm" value="<?= (int)$f('width_mm', 600) ?>"></div>
            <div class="form-row"><label>Depth mm</label>
                <input class="form-control" type="number" name="depth_mm" value="<?= (int)$f('depth_mm', 1100) ?>"></div>
            <div class="form-row"><label>Height mm</label>
                <input class="form-control" type="number" name="height_mm" value="<?= (int)$f('height_mm', 2000) ?>"></div>
            <div class="form-row"><label>Warranty company</label>
                <input class="form-control" name="warranty_provider" value="<?= App::e((string)$f('warranty_provider', '')) ?>"></div>
            <div class="form-row full"><h4 class="mt-0" style="font-size:.95rem;color:var(--muted)">SNMP defaults</h4></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="snmp_enabled" value="1" <?= !empty($f('snmp_enabled', 1)) ? 'checked' : '' ?>>
                SNMP enabled
            </label></div>
            <div class="form-row"><label>Version</label>
                <select class="form-control" name="snmp_version">
                    <?php foreach (['3' => 'v3', '2c' => 'v2c', '1' => 'v1'] as $v => $lab): ?>
                        <option value="<?= $v ?>" <?= (string)$f('snmp_version', '3') === $v ? 'selected' : '' ?>><?= $lab ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Port</label>
                <input class="form-control" type="number" name="snmp_port" value="<?= (int)$f('snmp_port', 161) ?>"></div>
            <div class="form-row"><label>SNMPv3 profile</label>
                <select class="form-control" name="snmp_v3_profile_id">
                    <option value="">—</option>
                    <?php foreach ($snmpProfiles as $p): ?>
                        <option value="<?= (int)$p['profile_id'] ?>" <?= (int)$f('snmp_v3_profile_id', 0) === (int)$p['profile_id'] ? 'selected' : '' ?>>
                            <?= App::e($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row full"><label>OID map (site template)</label>
                <select class="form-control" name="snmp_site_template_id">
                    <option value="">— None —</option>
                    <?php foreach ($siteOidTemplates as $st):
                        $stId = (int)$st['template_id'];
                        ?>
                        <option value="<?= $stId ?>" <?= (int)$f('snmp_site_template_id', 0) === $stId ? 'selected' : '' ?>>
                            <?= App::e((string)($st['name'] ?? ('#' . $stId))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row full"><label>
                <input type="checkbox" name="snmp_auto_poll" value="1" <?= !empty($f('snmp_auto_poll')) ? 'checked' : '' ?>>
                Enable scheduled poll when applied
            </label></div>
            <div class="form-row full"><label>Notes</label>
                <textarea class="form-control" name="notes" rows="2"><?= App::e($tpl['notes'] ?? '') ?></textarea></div>
            <?php if ($canEdit): ?>
            <div class="form-row full">
                <button class="btn btn-primary" type="submit">Save UPS template</button>
                <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_ups.php?action=new')) ?>">Use for new UPS</a>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($linked): ?>
    <div class="card mt-2">
        <div class="card-header"><h2>Linked UPS units</h2></div>
        <div class="card-body flush">
            <table class="data">
                <thead><tr><th>Name</th><th>Model</th><th>IP</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($linked as $lu): ?>
                    <tr>
                        <td><strong><?= App::e((string)$lu['name']) ?></strong></td>
                        <td><?= App::e(trim(($lu['manufacturer'] ?? '') . ' ' . ($lu['model'] ?? ''))) ?></td>
                        <td class="mono"><?= App::e((string)($lu['primary_ip'] ?? '—')) ?></td>
                        <td><a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_ups.php?id=' . (int)$lu['ups_id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <?php
    layout_footer();
    exit;
}

// ── PDU Detail / edit ──────────────────────────────────────────────────────
if ($templateId > 0 || $actionGet === 'new') {
    $tpl = null;
    $fields = [];
    $outlets = [];
    if ($templateId > 0) {
        $tpl = Database::fetchOne(
            'SELECT * FROM pdu_templates WHERE template_id = ?',
            [$templateId]
        );
        if (!$tpl || empty($tpl['is_active'])) {
            App::flash('error', 'Template not found.');
            App::redirect('pages/power_pdu_templates.php');
        }
        $fields = json_decode((string)($tpl['fields_json'] ?? '{}'), true) ?: [];
        $outlets = json_decode((string)($tpl['outlets_json'] ?? '[]'), true) ?: [];
        if (!is_array($outlets)) {
            $outlets = [];
        }
    } else {
        $tpl = [
            'template_id' => 0,
            'name' => '',
            'vendor' => '',
            'model' => '',
            'notes' => '',
            'is_active' => 1,
        ];
        $fields = [
            'pdu_scope' => 'rack',
            'phases' => 1,
            'phase_wiring' => 'single',
            'input_voltage' => 208,
            'output_voltage' => 208,
            'output_mode' => 'outlets',
            'num_outlets' => 0,
            'mount_style' => 'vertical_rear',
            'snmp_version' => '2c',
            'snmp_port' => 161,
        ];
    }

    $linked = $templateId > 0
        ? power_pdu_template_linked_pdus(
            $templateId,
            $tpl['vendor'] ?? ($fields['manufacturer'] ?? null),
            $tpl['model'] ?? ($fields['model'] ?? null)
        )
        : [];

    $f = static function (string $k, $default = '') use ($fields, $tpl) {
        if (array_key_exists($k, $fields)) {
            return $fields[$k];
        }
        if (is_array($tpl) && array_key_exists($k, $tpl) && $tpl[$k] !== null) {
            return $tpl[$k];
        }
        return $default;
    };

    $title = $templateId > 0
        ? ('PDU template: ' . ($tpl['name'] ?? ''))
        : 'New PDU template';
    layout_header($title, $user, 'power_templates');
    ?>
    <div class="flex-between mb-2">
        <div>
            <a class="btn btn-secondary btn-sm" href="<?= App::e(App::url('pages/power_pdu_templates.php#pdu-templates')) ?>">← Power Templates</a>
            <?php if ($templateId > 0): ?>
                <span class="badge" style="margin-left:.5rem"><?= count($linked) ?> linked PDU(s)</span>
            <?php endif; ?>
        </div>
        <div class="flex gap-1">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php')) ?>">PDUs</a>
        </div>
    </div>

    <?php if ($canEdit): ?>
    <form method="post" class="card" id="pduTplEditForm">
        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
        <input type="hidden" name="action" value="save_template">
        <input type="hidden" name="template_id" value="<?= (int)($tpl['template_id'] ?? 0) ?>">
        <div class="card-header"><h2><?= $templateId > 0 ? 'Edit template' : 'Create template' ?></h2></div>
        <div class="card-body form-grid">
            <div class="form-row"><label>Template name</label>
                <input class="form-control" name="name" required
                       value="<?= App::e((string)($tpl['name'] ?? '')) ?>"
                       placeholder="Usually Vendor+Model"></div>
            <div class="form-row"><label>Vendor</label>
                <input class="form-control" name="vendor"
                       value="<?= App::e((string)($tpl['vendor'] ?? $f('manufacturer', ''))) ?>"></div>
            <div class="form-row"><label>Model</label>
                <input class="form-control" name="model"
                       value="<?= App::e((string)($tpl['model'] ?? $f('model', ''))) ?>"></div>
            <div class="form-row full"><label>Template notes</label>
                <textarea class="form-control" name="template_notes" rows="2"
                          placeholder="Ops notes about this template (not copied to PDUs)"><?= App::e((string)($tpl['notes'] ?? '')) ?></textarea></div>

            <div class="form-row full"><h4 class="mt-0" style="font-size:.95rem;color:var(--muted)">Electrical / layout</h4></div>
            <div class="form-row"><label>Scope</label>
                <select class="form-control" name="pdu_scope">
                    <?php foreach (['rack' => 'Rack PDU', 'row' => 'Row PDU', 'room' => 'Room PDU'] as $val => $lab): ?>
                        <option value="<?= $val ?>" <?= (string)$f('pdu_scope', 'rack') === $val ? 'selected' : '' ?>><?= $lab ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row"><label>Mount style</label>
                <select class="form-control" name="mount_style">
                    <option value="vertical_rear" <?= (string)$f('mount_style', 'vertical_rear') === 'vertical_rear' ? 'selected' : '' ?>>Vertical rear (0U)</option>
                    <option value="u_mounted" <?= (string)$f('mount_style') === 'u_mounted' ? 'selected' : '' ?>>U-mounted</option>
                </select></div>
            <div class="form-row"><label>U height</label>
                <input class="form-control" type="number" min="1" max="10" name="u_height"
                       value="<?= App::e((string)$f('u_height', '1')) ?>"></div>
            <div class="form-row"><label>Phases</label>
                <select class="form-control" name="phases">
                    <?php foreach ([1 => '1φ', 2 => '2φ', 3 => '3φ'] as $pv => $pl): ?>
                        <option value="<?= $pv ?>" <?= (int)$f('phases', 1) === $pv ? 'selected' : '' ?>><?= $pl ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row"><label>Wiring</label>
                <select class="form-control" name="phase_wiring">
                    <?php foreach ([
                        'single' => 'Single-phase',
                        'split_phase' => 'Split-phase',
                        'two_phase' => 'Two-phase',
                        'wye' => 'Wye',
                        'delta' => 'Delta',
                    ] as $val => $lab): ?>
                        <option value="<?= $val ?>" <?= (string)$f('phase_wiring', 'single') === $val ? 'selected' : '' ?>><?= $lab ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row"><label>Input voltage (L–L)</label>
                <input class="form-control" type="number" name="input_voltage"
                       value="<?= App::e((string)$f('input_voltage', '')) ?>"></div>
            <div class="form-row"><label>Input voltage (L–N)</label>
                <input class="form-control" type="number" name="input_voltage_ln"
                       value="<?= App::e((string)$f('input_voltage_ln', '')) ?>"></div>
            <div class="form-row"><label>Output voltage</label>
                <input class="form-control" type="number" name="output_voltage"
                       value="<?= App::e((string)$f('output_voltage', '')) ?>"></div>
            <div class="form-row"><label>Output voltage (L–N)</label>
                <input class="form-control" type="number" name="output_voltage_ln"
                       value="<?= App::e((string)$f('output_voltage_ln', '')) ?>"></div>
            <div class="form-row"><label>Input connector</label>
                <select class="form-control" name="input_type">
                    <option value="">—</option>
                    <?php foreach (power_pdu_input_types() as $t): ?>
                        <option <?= (string)$f('input_type') === $t ? 'selected' : '' ?>><?= App::e($t) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row"><label>AMP rating</label>
                <input class="form-control" type="number" step="0.1" name="rated_amps"
                       value="<?= App::e((string)$f('rated_amps', '')) ?>"></div>
            <div class="form-row"><label>Output type</label>
                <select class="form-control" name="output_mode" id="tpl_output_mode">
                    <option value="outlets" <?= (string)$f('output_mode', 'outlets') !== 'breakers' ? 'selected' : '' ?>>Outlets</option>
                    <option value="breakers" <?= (string)$f('output_mode') === 'breakers' ? 'selected' : '' ?>>Breakers</option>
                </select></div>
            <div class="form-row tpl-outlet-fields"><label>Outlet count</label>
                <input class="form-control" type="number" min="0" max="128" name="num_outlets" id="tpl_num_outlets"
                       value="<?= App::e((string)$f('num_outlets', count($outlets) ?: '0')) ?>"></div>
            <div class="form-row tpl-breaker-fields" style="display:none"><label>Breaker slots</label>
                <input class="form-control" type="number" min="1" max="128" name="num_breaker_slots"
                       value="<?= App::e((string)$f('num_breaker_slots', '42')) ?>"></div>
            <div class="form-row tpl-breaker-fields" style="display:none"><label>Breaker layout</label>
                <select class="form-control" name="breaker_layout">
                    <?php foreach (power_breaker_layout_options() as $val => $lab): ?>
                        <option value="<?= App::e($val) ?>" <?= (string)$f('breaker_layout', 'odd_right_even_left') === $val ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row tpl-breaker-fields" style="display:none"><label>Breaker columns</label>
                <select class="form-control" name="breaker_columns">
                    <?php for ($c = 1; $c <= 3; $c++): ?>
                        <option value="<?= $c ?>" <?= (int)$f('breaker_columns', 2) === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endfor; ?>
                </select></div>

            <div class="form-row full"><h4 class="mt-0" style="font-size:.95rem;color:var(--muted)">SNMP / OID map</h4></div>
            <div class="form-row full"><label>
                <input type="checkbox" name="snmp_enabled" value="1" <?= !empty($f('snmp_enabled')) || (int)$f('snmp_site_template_id', 0) > 0 ? 'checked' : '' ?>>
                SNMP enabled (on PDUs created/updated from this template)
            </label></div>
            <div class="form-row"><label>SNMP version</label>
                <select class="form-control" name="snmp_version">
                    <?php foreach (['1', '2c', '3'] as $v): ?>
                        <option value="<?= $v ?>" <?= (string)$f('snmp_version', '2c') === $v ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row"><label>SNMP port</label>
                <input class="form-control" type="number" name="snmp_port"
                       value="<?= App::e((string)$f('snmp_port', '161')) ?>"></div>
            <div class="form-row"><label>SNMPv3 profile</label>
                <select class="form-control" name="snmp_v3_profile_id">
                    <option value="">—</option>
                    <?php foreach ($snmpProfiles as $sp): ?>
                        <option value="<?= (int)$sp['profile_id'] ?>"
                            <?= (int)$f('snmp_v3_profile_id', 0) === (int)$sp['profile_id'] ? 'selected' : '' ?>>
                            <?= App::e($sp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row full"><label>Site OID template</label>
                <select class="form-control" name="snmp_site_template_id">
                    <option value="">— None —</option>
                    <?php foreach ($siteOidTemplates as $st): ?>
                        <option value="<?= (int)$st['template_id'] ?>"
                            <?= (int)$f('snmp_site_template_id', 0) === (int)$st['template_id'] ? 'selected' : '' ?>>
                            <?= App::e($st['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">
                    Applied to PDUs when this template is used. Create maps via Discover on a live PDU.
                </p>
            </div>
            <div class="form-row full"><label>
                <input type="checkbox" name="snmp_auto_poll" value="1" <?= !empty($f('snmp_auto_poll')) ? 'checked' : '' ?>>
                Include in scheduled SNMP poll
            </label></div>
            <div class="form-row full"><label>Default PDU notes</label>
                <textarea class="form-control" name="notes" rows="2"><?= App::e((string)$f('notes', '')) ?></textarea></div>

            <div class="form-row full tpl-outlet-fields">
                <h4 class="mt-0" style="font-size:.95rem;color:var(--muted)">Outlet layout</h4>
                <p class="text-muted" style="font-size:.8rem;margin-top:0">
                    Optional per-outlet type/amps/label. Applied when creating PDUs or when you choose
                    “save + apply to PDUs” (force-updates types).
                </p>
                <div class="table-wrap">
                    <table class="data" id="tplOutletTable">
                        <thead>
                        <tr><th>#</th><th>Type</th><th>Amps</th><th>Label</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php
                        if (!$outlets && (int)$f('num_outlets', 0) > 0) {
                            for ($i = 1; $i <= min(128, (int)$f('num_outlets')); $i++) {
                                $outlets[] = ['outlet_number' => $i, 'outlet_type' => 'C13'];
                            }
                        }
                        foreach ($outlets as $o):
                            $n = (int)($o['outlet_number'] ?? 0);
                            if ($n < 1) {
                                continue;
                            }
                            ?>
                            <tr>
                                <td><input class="form-control" type="number" min="1" name="outlet_number[]"
                                           value="<?= $n ?>" style="width:4rem"></td>
                                <td>
                                    <select class="form-control" name="outlet_type[]">
                                        <?php foreach (power_outlet_connector_types() as $ot): ?>
                                            <option <?= (string)($o['outlet_type'] ?? 'C13') === $ot ? 'selected' : '' ?>><?= App::e($ot) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input class="form-control" type="number" step="0.1" name="outlet_rated_amps[]"
                                           value="<?= App::e(isset($o['rated_amps']) ? (string)$o['rated_amps'] : '') ?>" style="width:5rem"></td>
                                <td><input class="form-control" name="outlet_label[]"
                                           value="<?= App::e((string)($o['label'] ?? '')) ?>"></td>
                                <td><button type="button" class="btn btn-sm btn-ghost tpl-rm-outlet">✕</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="tplAddOutlet" style="margin-top:.5rem">+ Outlet row</button>
            </div>

            <div class="form-row full" style="border-top:1px solid var(--border,#334155);padding-top:1rem;margin-top:.5rem">
                <h4 class="mt-0" style="font-size:.95rem">Save options</h4>
                <label style="display:flex;align-items:flex-start;gap:.5rem;margin:.5rem 0;cursor:pointer">
                    <input type="radio" name="save_mode" value="template_only" checked style="margin-top:.25rem">
                    <span>
                        <strong>Save template only</strong><br>
                        <span class="text-muted" style="font-size:.85rem">Update this catalog entry. Existing PDUs are not changed.</span>
                    </span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:.5rem;margin:.5rem 0;cursor:pointer">
                    <input type="radio" name="save_mode" value="template_and_pdus" style="margin-top:.25rem"
                        <?= $templateId < 1 ? 'disabled' : '' ?>>
                    <span>
                        <strong>Save template and apply to all PDUs using it</strong>
                        (<?= count($linked) ?> PDU<?= count($linked) === 1 ? '' : 's' ?>)<br>
                        <span class="text-muted" style="font-size:.85rem">
                            Updates electrical, SNMP options, OID map, and outlet layout on linked PDUs.
                            Does <em>not</em> change name, IP, serial, or cabinet/row placement.
                            <?= $templateId < 1 ? ' (Available after the template is created.)' : '' ?>
                        </span>
                    </span>
                </label>
                <?php if ($linked): ?>
                    <details style="margin:.5rem 0;font-size:.85rem">
                        <summary class="text-muted">PDUs that will receive apply (<?= count($linked) ?>)</summary>
                        <ul style="margin:.35rem 0 0 1.2rem">
                            <?php foreach ($linked as $lp): ?>
                                <li>
                                    <a href="<?= App::e(App::url('pages/power_pdus.php?id=' . (int)$lp['pdu_id'])) ?>">
                                        <?= App::e($lp['name'] ?? ('PDU #' . $lp['pdu_id'])) ?>
                                    </a>
                                    <?php if (!empty($lp['ip_address'])): ?>
                                        <span class="text-muted">· <?= App::e($lp['ip_address']) ?></span>
                                    <?php endif; ?>
                                    <?php if (empty($lp['pdu_template_id'])): ?>
                                        <span class="badge">via vendor/model</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </div>

            <div class="form-row full" style="display:flex;flex-wrap:wrap;gap:.5rem">
                <button class="btn btn-primary" type="submit" id="tplSaveBtn">Save</button>
                <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_pdu_templates.php')) ?>">Cancel</a>
                <?php if ($templateId > 0): ?>
                <button class="btn btn-danger" type="submit" form="tplDeleteForm"
                        onclick="return confirm('Deactivate this PDU template? Linked PDUs are not deleted.');">
                    Deactivate
                </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <?php if ($templateId > 0): ?>
    <form method="post" id="tplDeleteForm" style="display:none">
        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
        <input type="hidden" name="action" value="delete_template">
        <input type="hidden" name="template_id" value="<?= $templateId ?>">
    </form>
    <?php endif; ?>

    <script>
    (function () {
        var mode = document.getElementById('tpl_output_mode');
        function toggleMode() {
            var br = mode && mode.value === 'breakers';
            document.querySelectorAll('.tpl-outlet-fields').forEach(function (el) {
                el.style.display = br ? 'none' : '';
            });
            document.querySelectorAll('.tpl-breaker-fields').forEach(function (el) {
                el.style.display = br ? '' : 'none';
            });
        }
        if (mode) mode.addEventListener('change', toggleMode);
        toggleMode();

        var tbody = document.querySelector('#tplOutletTable tbody');
        var addBtn = document.getElementById('tplAddOutlet');
        var typeOpts = <?= json_encode(array_values(power_outlet_connector_types()), JSON_UNESCAPED_SLASHES) ?>;
        function typeSelectHtml(sel) {
            return typeOpts.map(function (t) {
                return '<option' + (t === sel ? ' selected' : '') + '>' + t + '</option>';
            }).join('');
        }
        if (addBtn && tbody) {
            addBtn.addEventListener('click', function () {
                var max = 0;
                tbody.querySelectorAll('input[name="outlet_number[]"]').forEach(function (inp) {
                    var n = parseInt(inp.value, 10) || 0;
                    if (n > max) max = n;
                });
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><input class="form-control" type="number" min="1" name="outlet_number[]" value="' + (max + 1) + '" style="width:4rem"></td>' +
                    '<td><select class="form-control" name="outlet_type[]">' + typeSelectHtml('C13') + '</select></td>' +
                    '<td><input class="form-control" type="number" step="0.1" name="outlet_rated_amps[]" style="width:5rem"></td>' +
                    '<td><input class="form-control" name="outlet_label[]"></td>' +
                    '<td><button type="button" class="btn btn-sm btn-ghost tpl-rm-outlet">✕</button></td>';
                tbody.appendChild(tr);
                var nOut = document.getElementById('tpl_num_outlets');
                if (nOut) nOut.value = String(max + 1);
            });
            tbody.addEventListener('click', function (e) {
                var btn = e.target.closest('.tpl-rm-outlet');
                if (btn) {
                    var tr = btn.closest('tr');
                    if (tr) tr.remove();
                }
            });
        }

        var form = document.getElementById('pduTplEditForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                var modeEl = form.querySelector('input[name="save_mode"]:checked');
                if (modeEl && modeEl.value === 'template_and_pdus') {
                    if (!confirm('Save template and apply to all linked PDUs? Instance fields (name, IP, serial, placement) stay as-is.')) {
                        e.preventDefault();
                    }
                }
            });
        }
    })();
    </script>
    <?php else: ?>
        <div class="card"><div class="card-body">
            <p class="text-muted">You can view templates but need <strong>edit power</strong> permission to change them.</p>
            <pre style="font-size:.85rem;white-space:pre-wrap"><?= App::e(json_encode([
                'name' => $tpl['name'] ?? '',
                'fields' => $fields,
                'outlets' => $outlets,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div></div>
    <?php endif; ?>
    <?php
    layout_footer();
    exit;
}

// ── List (PDU + UPS cards) ─────────────────────────────────────────────────
$templates = [];
try {
    $templates = Database::fetchAll(
        'SELECT template_id, name, vendor, model, fields_json, outlets_json, notes, updated_at, created_at
         FROM pdu_templates WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $templates = [];
}
$upsTemplates = ups_template_list();

layout_header('Power Templates', $user, 'power_templates');
?>
<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0">
            Reusable inventory templates for <strong>PDUs</strong> and <strong>UPS</strong> units
            (electrical defaults, dimensions, SNMP / site OID map).
            Create from a configured device or add here.
        </p>
    </div>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php')) ?>">PDUs</a>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_ups.php')) ?>">UPS</a>
    </div>
</div>

<div class="card mb-2" id="pdu-templates">
    <div class="card-header flex-between">
        <h2>PDU templates</h2>
        <div class="flex gap-1" style="align-items:center">
            <span class="text-muted" style="font-size:.85rem"><?= count($templates) ?> active</span>
            <?php if ($canEdit): ?>
                <a class="btn btn-primary btn-sm" href="<?= App::e(App::url('pages/power_pdu_templates.php?action=new')) ?>">+ PDU template</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th>
                <th>Vendor / model</th>
                <th>OID map</th>
                <th>Outlets</th>
                <th>Linked PDUs</th>
                <th>Updated</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$templates): ?>
                <tr><td colspan="7" class="text-muted">
                    No PDU templates yet. Open a fully configured PDU and use
                    <strong>Create PDU template</strong>, or add one here.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($templates as $t):
                $fields = json_decode((string)($t['fields_json'] ?? '{}'), true) ?: [];
                $outs = json_decode((string)($t['outlets_json'] ?? '[]'), true) ?: [];
                $oidId = (int)($fields['snmp_site_template_id'] ?? 0);
                $oidName = '';
                if ($oidId > 0) {
                    foreach ($siteOidTemplates as $st) {
                        if ((int)$st['template_id'] === $oidId) {
                            $oidName = (string)$st['name'];
                            break;
                        }
                    }
                    if ($oidName === '') {
                        $oidName = '#' . $oidId;
                    }
                }
                $nOut = (int)($fields['num_outlets'] ?? 0);
                if ($nOut < 1 && is_array($outs)) {
                    $nOut = count($outs);
                }
                $linked = power_pdu_template_linked_pdus(
                    (int)$t['template_id'],
                    $t['vendor'] ?? null,
                    $t['model'] ?? null
                );
                $updated = $t['updated_at'] ?? $t['created_at'] ?? null;
                ?>
                <tr>
                    <td>
                        <a href="<?= App::e(App::url('pages/power_pdu_templates.php?id=' . (int)$t['template_id'])) ?>">
                            <strong><?= App::e($t['name']) ?></strong>
                        </a>
                    </td>
                    <td style="font-size:.85rem">
                        <?= App::e(trim(($t['vendor'] ?? '') . ' / ' . ($t['model'] ?? ''), ' /')) ?: '—' ?>
                    </td>
                    <td style="font-size:.85rem">
                        <?= $oidName !== '' ? App::e($oidName) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td><?= $nOut > 0 ? (int)$nOut : '—' ?></td>
                    <td><?= count($linked) ?></td>
                    <td style="font-size:.8rem" class="text-muted">
                        <?= $updated ? App::e(is_string($updated) ? substr($updated, 0, 16) : '') : '—' ?>
                    </td>
                    <td class="actions">
                        <a class="btn btn-sm btn-secondary"
                           href="<?= App::e(App::url('pages/power_pdu_templates.php?id=' . (int)$t['template_id'])) ?>">
                            <?= $canEdit ? 'Edit' : 'View' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="ups-templates">
    <div class="card-header flex-between">
        <h2>UPS templates</h2>
        <div class="flex gap-1" style="align-items:center">
            <span class="text-muted" style="font-size:.85rem"><?= count($upsTemplates) ?> active</span>
            <?php if ($canEdit): ?>
                <a class="btn btn-primary btn-sm" href="<?= App::e(App::url('pages/power_pdu_templates.php?type=ups&action=new')) ?>">+ UPS template</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th>
                <th>Vendor / model</th>
                <th>OID map</th>
                <th>Rated</th>
                <th>Linked UPS</th>
                <th>Updated</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$upsTemplates): ?>
                <tr><td colspan="7" class="text-muted">
                    No UPS templates yet. Open a configured UPS and use
                    <strong>Create UPS template</strong>, or add one here.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($upsTemplates as $t):
                $fields = json_decode((string)($t['fields_json'] ?? '{}'), true) ?: [];
                $oidId = (int)($fields['snmp_site_template_id'] ?? 0);
                $oidName = '';
                if ($oidId > 0) {
                    foreach ($siteOidTemplates as $st) {
                        if ((int)$st['template_id'] === $oidId) {
                            $oidName = (string)$st['name'];
                            break;
                        }
                    }
                    if ($oidName === '') {
                        $oidName = '#' . $oidId;
                    }
                }
                $linkedU = ups_template_linked_units(
                    (int)$t['template_id'],
                    $t['vendor'] ?? null,
                    $t['model'] ?? null
                );
                $updated = $t['updated_at'] ?? $t['created_at'] ?? null;
                $rated = '';
                if (isset($fields['rated_kva']) && $fields['rated_kva'] !== '') {
                    $rated = rtrim(rtrim(number_format((float)$fields['rated_kva'], 1), '0'), '.') . ' kVA';
                }
                ?>
                <tr>
                    <td>
                        <a href="<?= App::e(App::url('pages/power_pdu_templates.php?type=ups&id=' . (int)$t['template_id'])) ?>">
                            <strong><?= App::e($t['name']) ?></strong>
                        </a>
                    </td>
                    <td style="font-size:.85rem">
                        <?= App::e(trim(($t['vendor'] ?? '') . ' / ' . ($t['model'] ?? ''), ' /')) ?: '—' ?>
                    </td>
                    <td style="font-size:.85rem">
                        <?= $oidName !== '' ? App::e($oidName) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td><?= $rated !== '' ? App::e($rated) : '—' ?></td>
                    <td><?= count($linkedU) ?></td>
                    <td style="font-size:.8rem" class="text-muted">
                        <?= $updated ? App::e(is_string($updated) ? substr($updated, 0, 16) : '') : '—' ?>
                    </td>
                    <td class="actions">
                        <a class="btn btn-sm btn-secondary"
                           href="<?= App::e(App::url('pages/power_pdu_templates.php?type=ups&id=' . (int)$t['template_id'])) ?>">
                            <?= $canEdit ? 'Edit' : 'View' ?>
                        </a>
                        <a class="btn btn-sm btn-ghost"
                           href="<?= App::e(App::url('pages/power_ups.php?action=new')) ?>">New UPS</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
layout_footer();
