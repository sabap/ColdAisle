<?php
/**
 * WinDCIM - Device Templates catalog
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/src/Services/ImageUpload.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_devices');

$deviceTypes = [
    'server' => 'Server',
    'pdu' => 'PDU',
    'router' => 'Router',
    'network_switch' => 'Network Switch',
    'storage_array' => 'Storage array',
    'storage_switch' => 'Storage switch',
    'kvm' => 'KVM',
    'monitor' => 'Monitor',
    'nvr' => 'NVR',
    'chassis' => 'Chassis',
    'ups' => 'UPS',
    'firewall' => 'Firewall',
    'other' => 'Other',
];

function tpl_empty($v)
{
    if ($v === null || (is_string($v) && trim($v) === '')) {
        return null;
    }
    return $v;
}

function media_url(?string $rel): string
{
    if (!$rel) {
        return '';
    }
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    // stored as templates/12/front.jpg under storage/uploads
    return App::url('media.php?f=' . rawurlencode($rel));
}

// ---- Manufacturer quick-add ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '') && ($_POST['action'] ?? '') === 'add_manufacturer') {
    $name = trim((string)($_POST['mfr_name'] ?? ''));
    try {
        if ($name === '') {
            throw new RuntimeException('Manufacturer name is required.');
        }
        $exists = Database::fetchValue('SELECT manufacturer_id FROM manufacturers WHERE name = ?', [$name]);
        if ($exists) {
            App::flash('success', 'Manufacturer already exists.');
        } else {
            Database::insert('manufacturers', [
                'name' => $name,
                'website' => tpl_empty($_POST['mfr_website'] ?? null),
                'notes' => tpl_empty($_POST['mfr_notes'] ?? null),
            ]);
            App::flash('success', 'Manufacturer added: ' . $name);
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    $redir = !empty($_POST['return_id'])
        ? 'pages/device_templates.php?id=' . (int)$_POST['return_id']
        : 'pages/device_templates.php?action=new';
    if (!empty($_POST['return_new'])) {
        $redir = 'pages/device_templates.php?action=new';
    }
    App::redirect($redir);
}

// ---- Soft-delete template ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '') && ($_POST['action'] ?? '') === 'delete_template') {
    $tid = (int)($_POST['template_id'] ?? 0);
    try {
        Database::update('device_templates', ['is_active' => 0], 'template_id = :id', [':id' => $tid]);
        App::flash('success', 'Template deactivated.');
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/device_templates.php');
}

// ---- Save template ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '') && ($_POST['action'] ?? '') === 'save_template') {
    $tid = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
    $uHeight = max(1, min(60, (int)($_POST['u_height'] ?? 1)));
    $data = [
        'manufacturer_id' => $_POST['manufacturer_id'] !== '' ? (int)$_POST['manufacturer_id'] : null,
        'model' => trim((string)($_POST['model'] ?? '')),
        'device_type' => $_POST['device_type'] ?? 'server',
        'u_height' => $uHeight,
        'weight_kg' => $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null,
        'watts' => $_POST['watts'] !== '' ? (float)$_POST['watts'] : null,
        'num_power_ports' => max(0, (int)($_POST['num_power_ports'] ?? 0)),
        'num_data_ports' => max(0, (int)($_POST['num_data_ports'] ?? 0)),
        'snmp_template' => tpl_empty($_POST['snmp_template'] ?? null),
        'notes' => tpl_empty($_POST['notes'] ?? null),
        'is_active' => 1,
    ];

    try {
        if ($data['model'] === '') {
            throw new RuntimeException('Model is required.');
        }
        if (!isset($deviceTypes[$data['device_type']])) {
            $data['device_type'] = 'other';
        }
        if ($data['snmp_template'] !== null && !in_array($data['snmp_template'], ['1', '2c', '3'], true)) {
            $data['snmp_template'] = null;
        }

        if ($tid) {
            Database::update('device_templates', $data, 'template_id = :id', [':id' => $tid]);
        } else {
            $tid = Database::insert('device_templates', $data);
            if (!$tid) {
                $row = Database::fetchOne(
                    'SELECT TOP 1 template_id FROM device_templates WHERE model = ? ORDER BY template_id DESC',
                    [$data['model']]
                );
                $tid = $row ? (int)$row['template_id'] : 0;
            }
            if (!$tid) {
                throw new RuntimeException('Could not create template.');
            }
        }

        // Images
        $uploadRoot = App::ROOT . '/storage/uploads/templates/' . $tid;
        foreach (['front_picture' => 'front', 'rear_picture' => 'rear'] as $field => $stem) {
            if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (!empty($_POST['clear_' . $field])) {
                continue;
            }
            $dest = $uploadRoot . '/' . $stem . '.jpg';
            $result = ImageUpload::processUpload($_FILES[$field], $dest, $uHeight);
            $rel = 'templates/' . $tid . '/' . basename($result['path']);
            Database::update('device_templates', [$field => $rel], 'template_id = :id', [':id' => $tid]);
        }
        // Clear pictures if requested
        foreach (['front_picture', 'rear_picture'] as $field) {
            if (!empty($_POST['clear_' . $field])) {
                Database::update('device_templates', [$field => null], 'template_id = :id', [':id' => $tid]);
            }
        }

        AuditService::log((int)$user['user_id'], $user['username'], $tid ? 'update' : 'create', 'device_template', $tid);
        App::flash('success', 'Template saved.');
        App::redirect('pages/device_templates.php?id=' . $tid);
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
        App::redirect($tid ? 'pages/device_templates.php?id=' . $tid : 'pages/device_templates.php?action=new');
    }
}

$manufacturers = Database::fetchAll('SELECT * FROM manufacturers ORDER BY name');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

// ---- Form ----
if ($action === 'new' || $id) {
    $tpl = $id ? Database::fetchOne('SELECT * FROM device_templates WHERE template_id = ?', [$id]) : null;
    if ($id && !$tpl) {
        App::flash('error', 'Template not found.');
        App::redirect('pages/device_templates.php');
    }

    layout_header($tpl ? 'Template: ' . $tpl['model'] : 'New Device Template', $user, 'device_templates');
    ?>
    <div class="flex-between mb-2">
        <div class="flex gap-1">
            <a class="btn btn-sm btn-ghost" href="<?= App::e(App::url('pages/devices.php')) ?>">Devices</a>
            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/device_templates.php')) ?>">All templates</a>
        </div>
    </div>

    <div class="split-2">
        <form method="post" enctype="multipart/form-data" class="card">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="save_template">
            <?php if ($tpl): ?>
                <input type="hidden" name="template_id" value="<?= (int)$tpl['template_id'] ?>">
            <?php endif; ?>
            <div class="card-header"><h2><?= $tpl ? 'Edit template' : 'New template' ?></h2></div>
            <div class="card-body form-grid">
                <div class="form-row"><label>Manufacturer</label>
                    <select class="form-control" name="manufacturer_id" id="manufacturer_id">
                        <option value="">— Select —</option>
                        <?php foreach ($manufacturers as $m): ?>
                            <option value="<?= (int)$m['manufacturer_id'] ?>"
                                <?= (int)($tpl['manufacturer_id'] ?? 0) === (int)$m['manufacturer_id'] ? 'selected' : '' ?>>
                                <?= App::e($m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">Add a new manufacturer in the panel on the right.</p>
                </div>
                <div class="form-row"><label>Model *</label>
                    <input class="form-control" name="model" required value="<?= App::e($tpl['model'] ?? '') ?>"></div>
                <div class="form-row"><label>Device type</label>
                    <select class="form-control" name="device_type">
                        <?php foreach ($deviceTypes as $val => $lab): ?>
                            <option value="<?= App::e($val) ?>"
                                <?= ($tpl['device_type'] ?? 'server') === $val ? 'selected' : '' ?>>
                                <?= App::e($lab) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Height (U)</label>
                    <input class="form-control" type="number" name="u_height" min="1" max="60"
                           value="<?= (int)($tpl['u_height'] ?? 1) ?>"></div>
                <div class="form-row"><label>Weight (kg)</label>
                    <input class="form-control" type="number" step="0.01" name="weight_kg"
                           value="<?= App::e((string)($tpl['weight_kg'] ?? '')) ?>"></div>
                <div class="form-row"><label>Wattage</label>
                    <input class="form-control" type="number" step="0.1" name="watts"
                           value="<?= App::e((string)($tpl['watts'] ?? '')) ?>"></div>
                <div class="form-row"><label>Number of power connections</label>
                    <input class="form-control" type="number" min="0" name="num_power_ports"
                           value="<?= (int)($tpl['num_power_ports'] ?? 2) ?>"></div>
                <div class="form-row"><label>Number of Ports (data)</label>
                    <input class="form-control" type="number" min="0" name="num_data_ports"
                           value="<?= (int)($tpl['num_data_ports'] ?? 0) ?>"></div>
                <div class="form-row"><label>SNMP version</label>
                    <select class="form-control" name="snmp_template">
                        <option value="">— None —</option>
                        <?php foreach (['1', '2c', '3'] as $v): ?>
                            <option value="<?= $v ?>"
                                <?= (string)($tpl['snmp_template'] ?? '') === $v ? 'selected' : '' ?>>
                                <?= $v ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row full"><label>Notes</label>
                    <textarea class="form-control" name="notes" rows="3"><?= App::e($tpl['notes'] ?? '') ?></textarea></div>

                <div class="form-row">
                    <label>Front picture</label>
                    <?php if (!empty($tpl['front_picture'])): ?>
                        <div style="margin-bottom:.4rem">
                            <img src="<?= App::e(media_url($tpl['front_picture'])) ?>" alt="Front"
                                 style="max-width:100%;max-height:160px;object-fit:contain;background:#0f172a;border-radius:6px;border:1px solid var(--border)">
                        </div>
                        <label style="font-size:.8rem"><input type="checkbox" name="clear_front_picture" value="1"> Remove current</label>
                    <?php endif; ?>
                    <input class="form-control" type="file" name="front_picture" accept="image/jpeg,image/png,image/gif,image/webp">
                    <p class="text-muted" style="font-size:.72rem;margin:.25rem 0 0">Scaled to max <?= ImageUpload::MAX_WIDTH ?>px wide (aspect preserved, never stretched).</p>
                </div>
                <div class="form-row">
                    <label>Rear picture</label>
                    <?php if (!empty($tpl['rear_picture'])): ?>
                        <div style="margin-bottom:.4rem">
                            <img src="<?= App::e(media_url($tpl['rear_picture'])) ?>" alt="Rear"
                                 style="max-width:100%;max-height:160px;object-fit:contain;background:#0f172a;border-radius:6px;border:1px solid var(--border)">
                        </div>
                        <label style="font-size:.8rem"><input type="checkbox" name="clear_rear_picture" value="1"> Remove current</label>
                    <?php endif; ?>
                    <input class="form-control" type="file" name="rear_picture" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
            </div>
            <div class="card-body form-actions">
                <button class="btn btn-primary" type="submit"><?= $tpl ? 'Save template' : 'Create template' ?></button>
            </div>
        </form>

        <div>
            <form method="post" class="card">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="add_manufacturer">
                <?php if ($tpl): ?>
                    <input type="hidden" name="return_id" value="<?= (int)$tpl['template_id'] ?>">
                <?php else: ?>
                    <input type="hidden" name="return_new" value="1">
                <?php endif; ?>
                <div class="card-header"><h2>Add manufacturer</h2></div>
                <div class="card-body form-grid">
                    <div class="form-row full"><label>Name *</label>
                        <input class="form-control" name="mfr_name" required placeholder="e.g. Dell, HPE, Cisco"></div>
                    <div class="form-row full"><label>Website</label>
                        <input class="form-control" name="mfr_website" placeholder="https://"></div>
                    <div class="form-row full"><label>Notes</label>
                        <input class="form-control" name="mfr_notes"></div>
                    <div class="form-row">
                        <button class="btn btn-secondary btn-sm" type="submit">Add manufacturer</button>
                    </div>
                </div>
            </form>

            <div class="card">
                <div class="card-header"><h2>Manufacturers</h2></div>
                <div class="card-body flush">
                    <table class="data">
                        <thead><tr><th>Name</th></tr></thead>
                        <tbody>
                        <?php foreach ($manufacturers as $m): ?>
                            <tr><td><?= App::e($m['name']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$manufacturers): ?>
                            <tr><td class="text-muted">None yet</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php
    layout_footer();
    exit;
}

// ---- List ----
$templates = Database::fetchAll(
    'SELECT t.*, m.name AS manufacturer_name
     FROM device_templates t
     LEFT JOIN manufacturers m ON m.manufacturer_id = t.manufacturer_id
     WHERE t.is_active = 1
     ORDER BY m.name, t.model'
);

layout_header('Device Templates', $user, 'device_templates');
?>
<div class="flex-between mb-2">
    <div class="flex gap-1" style="align-items:center">
        <a class="btn btn-sm btn-ghost" href="<?= App::e(App::url('pages/devices.php')) ?>">← Devices</a>
        <span class="text-muted" style="font-size:.85rem">Templates pre-fill fields when creating a device</span>
    </div>
    <a class="btn btn-primary" href="?action=new">+ New template</a>
</div>

<div class="card">
    <div class="card-body flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Front</th>
                    <th>Manufacturer</th>
                    <th>Model</th>
                    <th>Type</th>
                    <th>U</th>
                    <th>Watts</th>
                    <th>Data</th>
                    <th>Power</th>
                    <th>SNMP</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($templates as $t): ?>
                    <tr>
                        <td style="width:64px">
                            <?php if (!empty($t['front_picture'])): ?>
                                <img src="<?= App::e(media_url($t['front_picture'])) ?>" alt=""
                                     style="width:48px;height:48px;object-fit:contain;background:#0f172a;border-radius:4px">
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= App::e($t['manufacturer_name'] ?? '—') ?></td>
                        <td><a href="?id=<?= (int)$t['template_id'] ?>"><?= App::e($t['model']) ?></a></td>
                        <td><?= App::e($deviceTypes[$t['device_type']] ?? $t['device_type']) ?></td>
                        <td><?= (int)$t['u_height'] ?></td>
                        <td><?= $t['watts'] !== null ? App::e((string)$t['watts']) : '—' ?></td>
                        <td><?= (int)$t['num_data_ports'] ?></td>
                        <td><?= (int)$t['num_power_ports'] ?></td>
                        <td><?= App::e($t['snmp_template'] ?? '—') ?></td>
                        <td class="actions">
                            <a class="btn btn-sm btn-secondary" href="?id=<?= (int)$t['template_id'] ?>">Edit</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this template?');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_template">
                                <input type="hidden" name="template_id" value="<?= (int)$t['template_id'] ?>">
                                <button class="btn btn-sm btn-ghost" type="submit">Deactivate</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$templates): ?>
                    <tr><td colspan="10" class="text-muted">No templates yet. Create one to speed up device entry.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
