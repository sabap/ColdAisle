<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_disposals');

$stages = [
    'planning' => '1. Inventory & planning',
    'sanitization' => '2. Data sanitization',
    'verification' => '3. Verification & docs',
    'disposition' => '4. Physical disposition',
    'post_review' => '5. Post-review',
    'closed' => 'Closed',
];

$stageOrder = array_keys($stages);

$sensitivityLevels = [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'critical' => 'Critical',
];

$nistCategories = [
    'Clear' => 'Clear — logical techniques (overwrite, factory reset where appropriate)',
    'Purge' => 'Purge — render recovery infeasible (crypto-erase, degauss, secure erase)',
    'Destroy' => 'Destroy — physical destruction (shred, pulverize, incinerate)',
];

$methods = [
    'recycle' => 'Recycle (ITAD)',
    'destroy' => 'Destroy',
    'resale' => 'Resell',
    'return_lease' => 'Return lease',
    'donate' => 'Donate',
];

function disposal_null($v)
{
    if ($v === null || (is_string($v) && trim($v) === '')) {
        return null;
    }
    return is_string($v) ? trim($v) : $v;
}

function disposal_bit($v): int
{
    return !empty($v) ? 1 : 0;
}

function disposal_stage_index(string $stage, array $order): int
{
    $i = array_search($stage, $order, true);
    return $i === false ? 0 : (int)$i;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$startDeviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$actionGet = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if (!AuthManager::canEditDisposals($user) && $action !== '') {
            throw new RuntimeException('You do not have permission to manage decommissions.');
        }
        // ---- Start / request decommission ----
        if ($action === 'start' || $action === 'request') {
            $deviceId = (int)($_POST['device_id'] ?? 0);
            if ($deviceId <= 0) {
                throw new RuntimeException('Device is required.');
            }
            $dev = Database::fetchOne(
                'SELECT device_id, label, status, is_active FROM devices WHERE device_id = ?',
                [$deviceId]
            );
            if (!$dev) {
                throw new RuntimeException('Device not found.');
            }
            $open = Database::fetchOne(
                "SELECT disposal_id FROM disposals
                 WHERE device_id = ? AND status NOT IN ('completed','cancelled')",
                [$deviceId]
            );
            if ($open) {
                App::flash('info', 'An open decommission already exists for this device.');
                App::redirect('pages/disposals.php?id=' . (int)$open['disposal_id']);
            }

            $disposalId = Database::insert('disposals', [
                'device_id' => $deviceId,
                'requested_by' => (int)$user['user_id'],
                'status' => 'pending',
                'stage' => 'planning',
                'reason' => disposal_null($_POST['reason'] ?? null),
                'method' => disposal_null($_POST['method'] ?? null),
                'change_ticket' => disposal_null($_POST['change_ticket'] ?? null),
                'data_sensitivity' => disposal_null($_POST['data_sensitivity'] ?? 'medium'),
                'workload_migration' => disposal_null($_POST['workload_migration'] ?? null),
                'planning_notes' => disposal_null($_POST['planning_notes'] ?? null),
                'scheduled_date' => disposal_null($_POST['scheduled_date'] ?? null),
                'notes' => disposal_null($_POST['notes'] ?? null),
            ]);
            if (!$disposalId) {
                $found = Database::fetchOne(
                    'SELECT TOP 1 disposal_id FROM disposals WHERE device_id = ? ORDER BY disposal_id DESC',
                    [$deviceId]
                );
                $disposalId = $found ? (int)$found['disposal_id'] : 0;
            }

            Database::update('devices', [
                'status' => 'decommissioning',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'device_id = :id', [':id' => $deviceId]);

            Database::insert('notifications', [
                'user_id' => null,
                'title' => 'Decommission started',
                'message' => 'Decommission workflow started for ' . ($dev['label'] ?? $deviceId),
                'category' => 'disposal',
                'entity_type' => 'disposal',
                'entity_id' => $disposalId,
            ]);
            $admins = Database::fetchAll(
                "SELECT u.user_id FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE r.name = 'Administrator' AND u.is_active = 1"
            );
            foreach ($admins as $a) {
                Database::insert('notifications', [
                    'user_id' => (int)$a['user_id'],
                    'title' => 'Decommission approval / review',
                    'message' => 'Device ' . ($dev['label'] ?? $deviceId) . ' entered decommission planning.',
                    'category' => 'disposal',
                    'entity_type' => 'disposal',
                    'entity_id' => $disposalId,
                ]);
            }
            AuditService::log((int)$user['user_id'], $user['username'], 'decommission_start', 'device', $deviceId, [
                'disposal_id' => $disposalId,
            ]);
            App::flash('success', 'Decommission process started. Complete each stage below.');
            App::redirect('pages/disposals.php?id=' . (int)$disposalId);
        }

        // ---- Update a workflow stage ----
        if ($action === 'save_stage') {
            $disposalId = (int)($_POST['disposal_id'] ?? 0);
            $stage = (string)($_POST['stage'] ?? 'planning');
            if (!$disposalId || !isset($stages[$stage])) {
                throw new RuntimeException('Invalid disposal or stage.');
            }
            $disp = Database::fetchOne('SELECT * FROM disposals WHERE disposal_id = ?', [$disposalId]);
            if (!$disp) {
                throw new RuntimeException('Disposal not found.');
            }
            if (in_array($disp['status'], ['completed', 'cancelled'], true)) {
                throw new RuntimeException('This decommission is closed.');
            }

            $fields = ['updated_at' => date('Y-m-d H:i:s')];
            $advance = !empty($_POST['advance_stage']);

            if ($stage === 'planning') {
                $fields['change_ticket'] = disposal_null($_POST['change_ticket'] ?? null);
                $fields['data_sensitivity'] = disposal_null($_POST['data_sensitivity'] ?? null);
                $fields['workload_migration'] = disposal_null($_POST['workload_migration'] ?? null);
                $fields['asset_verified'] = disposal_bit($_POST['asset_verified'] ?? 0);
                $fields['planning_notes'] = disposal_null($_POST['planning_notes'] ?? null);
                $fields['reason'] = disposal_null($_POST['reason'] ?? null);
                $fields['scheduled_date'] = disposal_null($_POST['scheduled_date'] ?? null);
                if ($advance) {
                    $fields['planning_completed_at'] = date('Y-m-d H:i:s');
                    $fields['stage'] = 'sanitization';
                    $fields['status'] = 'approved';
                    $fields['approved_by'] = (int)$user['user_id'];
                }
            }

            if ($stage === 'sanitization') {
                $fields['sanitize_category'] = disposal_null($_POST['sanitize_category'] ?? null);
                $fields['sanitize_method'] = disposal_null($_POST['sanitize_method'] ?? null);
                $fields['sanitize_on_site'] = isset($_POST['sanitize_on_site']) ? disposal_bit($_POST['sanitize_on_site']) : null;
                $fields['network_config_cleared'] = disposal_bit($_POST['network_config_cleared'] ?? 0);
                $fields['credentials_cleared'] = disposal_bit($_POST['credentials_cleared'] ?? 0);
                $fields['logs_cleared'] = disposal_bit($_POST['logs_cleared'] ?? 0);
                $fields['sanitize_details'] = disposal_null($_POST['sanitize_details'] ?? null);
                $fields['sanitize_performed_by'] = disposal_null($_POST['sanitize_performed_by'] ?? null);
                if (!empty($_POST['sanitize_performed_at'])) {
                    $fields['sanitize_performed_at'] = $_POST['sanitize_performed_at'];
                } elseif ($advance) {
                    $fields['sanitize_performed_at'] = date('Y-m-d H:i:s');
                }
                if ($advance) {
                    $fields['stage'] = 'verification';
                    $fields['status'] = 'in_progress';
                }
            }

            if ($stage === 'verification') {
                $fields['certificate_no'] = disposal_null($_POST['certificate_no'] ?? null);
                $fields['chain_of_custody'] = disposal_null($_POST['chain_of_custody'] ?? null);
                $fields['verification_notes'] = disposal_null($_POST['verification_notes'] ?? null);
                $fields['verified_by'] = disposal_null($_POST['verified_by'] ?? ($user['display_name'] ?? $user['username']));
                if (!empty($_POST['verified_at'])) {
                    $fields['verified_at'] = $_POST['verified_at'];
                } elseif ($advance) {
                    $fields['verified_at'] = date('Y-m-d H:i:s');
                }
                if ($advance) {
                    $fields['stage'] = 'disposition';
                }
            }

            if ($stage === 'disposition') {
                $fields['method'] = disposal_null($_POST['method'] ?? null);
                $fields['vendor_id'] = !empty($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : null;
                $fields['disposition_ref'] = disposal_null($_POST['disposition_ref'] ?? null);
                $fields['pickup_date'] = disposal_null($_POST['pickup_date'] ?? null);
                $fields['scheduled_date'] = disposal_null($_POST['scheduled_date'] ?? null);
                $fields['notes'] = disposal_null($_POST['notes'] ?? null);
                if ($advance) {
                    $fields['stage'] = 'post_review';
                    $fields['completed_date'] = date('Y-m-d');
                    // Remove from rack / mark disposed physically after disposition
                    Database::update('devices', [
                        'status' => 'disposed',
                        'is_active' => 0,
                        'cabinet_id' => null,
                        'position_u' => null,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], 'device_id = :id', [':id' => (int)$disp['device_id']]);
                }
            }

            if ($stage === 'post_review') {
                $fields['lessons_learned'] = disposal_null($_POST['lessons_learned'] ?? null);
                $fields['policy_updates'] = disposal_null($_POST['policy_updates'] ?? null);
                $fields['post_review_by'] = disposal_null($_POST['post_review_by'] ?? ($user['display_name'] ?? $user['username']));
                if ($advance) {
                    $fields['post_review_at'] = date('Y-m-d H:i:s');
                    $fields['stage'] = 'closed';
                    $fields['status'] = 'completed';
                }
            }

            Database::update('disposals', $fields, 'disposal_id = :id', [':id' => $disposalId]);
            App::flash('success', $advance ? 'Stage saved and advanced.' : 'Stage saved.');
            App::redirect('pages/disposals.php?id=' . $disposalId);
        }

        if ($action === 'set_status') {
            $disposalId = (int)($_POST['disposal_id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['pending', 'approved', 'in_progress', 'completed', 'cancelled'], true)) {
                throw new RuntimeException('Invalid status.');
            }
            $disp = Database::fetchOne('SELECT * FROM disposals WHERE disposal_id = ?', [$disposalId]);
            if (!$disp) {
                throw new RuntimeException('Not found.');
            }
            $fields = [
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($status === 'approved') {
                $fields['approved_by'] = (int)$user['user_id'];
            }
            if ($status === 'cancelled') {
                // Restore device if still decommissioning
                Database::update('devices', [
                    'status' => 'spare',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], "device_id = :id AND status = 'decommissioning'", [':id' => (int)$disp['device_id']]);
            }
            if ($status === 'completed') {
                $fields['stage'] = 'closed';
                $fields['completed_date'] = date('Y-m-d');
                Database::update('devices', [
                    'status' => 'disposed',
                    'is_active' => 0,
                    'cabinet_id' => null,
                    'position_u' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'device_id = :id', [':id' => (int)$disp['device_id']]);
            }
            Database::update('disposals', $fields, 'disposal_id = :id', [':id' => $disposalId]);
            App::flash('success', 'Status updated.');
            App::redirect('pages/disposals.php?id=' . $disposalId);
        }

        // ---- Vendors ----
        if ($action === 'add_vendor') {
            Database::insert('disposal_vendors', [
                'name' => trim((string)$_POST['name']),
                'vendor_type' => $_POST['vendor_type'] ?? 'itad',
                'contact_name' => disposal_null($_POST['contact_name'] ?? null),
                'contact_email' => disposal_null($_POST['contact_email'] ?? null),
                'contact_phone' => disposal_null($_POST['contact_phone'] ?? null),
                'website' => disposal_null($_POST['website'] ?? null),
                'certifications' => disposal_null($_POST['certifications'] ?? null),
                'address' => disposal_null($_POST['address'] ?? null),
                'notes' => disposal_null($_POST['notes'] ?? null),
                'is_active' => 1,
            ]);
            App::flash('success', 'Disposal vendor added.');
            App::redirect('pages/disposals.php?tab=vendors');
        }
        if ($action === 'deactivate_vendor') {
            $vid = (int)($_POST['vendor_id'] ?? 0);
            if ($vid > 0) {
                Database::update('disposal_vendors', ['is_active' => 0], 'vendor_id = :id', [':id' => $vid]);
                App::flash('success', 'Vendor deactivated.');
            }
            App::redirect('pages/disposals.php?tab=vendors');
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/disposals.php' . ($id ? '?id=' . $id : ''));
}

// Vendors list
$vendors = [];
try {
    $vendors = Database::fetchAll(
        'SELECT * FROM disposal_vendors WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $vendors = [];
}

// ---- Detail workflow ----
if ($id) {
    $disp = Database::fetchOne(
        'SELECT d.*,
                dev.label AS device_label, dev.device_type, dev.serial_no, dev.asset_tag,
                dev.manufacturer, dev.model, dev.primary_ip, dev.cabinet_id, dev.status AS device_status,
                c.name AS cabinet_name,
                u.username AS requested_by_name,
                ua.username AS approved_by_name,
                v.name AS vendor_name, v.vendor_type, v.certifications AS vendor_certs
         FROM disposals d
         INNER JOIN devices dev ON dev.device_id = d.device_id
         LEFT JOIN cabinets c ON c.cabinet_id = dev.cabinet_id
         LEFT JOIN users u ON u.user_id = d.requested_by
         LEFT JOIN users ua ON ua.user_id = d.approved_by
         LEFT JOIN disposal_vendors v ON v.vendor_id = d.vendor_id
         WHERE d.disposal_id = ?',
        [$id]
    );
    if (!$disp) {
        App::flash('error', 'Decommission record not found.');
        App::redirect('pages/disposals.php');
    }
    $curStage = $disp['stage'] ?? 'planning';
    if ($curStage === '' || $curStage === null) {
        $curStage = 'planning';
    }
    $stageIdx = disposal_stage_index((string)$curStage, $stageOrder);
    $closed = in_array($disp['status'], ['completed', 'cancelled'], true)
        || $curStage === 'closed';

    layout_header('Decommission: ' . $disp['device_label'], $user, 'disposals');
    ?>
    <div class="flex-between mb-2">
        <div>
            <span class="badge badge-warning"><?= App::e((string)$disp['status']) ?></span>
            <span class="badge badge-info"><?= App::e($stages[$curStage] ?? $curStage) ?></span>
            <span class="text-muted" style="margin-left:.4rem;font-size:.9rem">
                #<?= (int)$disp['disposal_id'] ?> ·
                Requested by <?= App::e($disp['requested_by_name'] ?? '—') ?>
            </span>
        </div>
        <div class="flex gap-1">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/disposals.php')) ?>">← Queue</a>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/devices.php?id=' . (int)$disp['device_id'])) ?>">Device</a>
            <?php if (!$closed): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Cancel this decommission?');">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="disposal_id" value="<?= $id ?>">
                    <input type="hidden" name="status" value="cancelled">
                    <button class="btn btn-danger" type="submit">Cancel process</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stage stepper -->
    <div class="decom-stepper card">
        <div class="card-body">
            <ol class="decom-steps">
                <?php foreach ($stageOrder as $i => $sk):
                    if ($sk === 'closed') {
                        continue;
                    }
                    $cls = 'decom-step';
                    if ($i < $stageIdx || $curStage === 'closed') {
                        $cls .= ' done';
                    } elseif ($i === $stageIdx) {
                        $cls .= ' current';
                    }
                    ?>
                    <li class="<?= $cls ?>">
                        <span class="decom-step-num"><?= $i + 1 ?></span>
                        <span class="decom-step-label"><?= App::e(preg_replace('/^\d+\.\s*/', '', $stages[$sk])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>

    <div class="metrics">
        <div class="metric-card">
            <div class="label">Device</div>
            <div class="value" style="font-size:1.1rem"><?= App::e($disp['device_label']) ?></div>
            <div class="sub"><?= App::e(($disp['manufacturer'] ?? '') . ' ' . ($disp['model'] ?? '')) ?></div>
        </div>
        <div class="metric-card">
            <div class="label">Serial / Asset</div>
            <div class="value" style="font-size:1rem"><?= App::e($disp['serial_no'] ?? '—') ?></div>
            <div class="sub"><?= App::e($disp['asset_tag'] ?? 'no asset tag') ?></div>
        </div>
        <div class="metric-card <?= ($disp['data_sensitivity'] ?? '') === 'critical' || ($disp['data_sensitivity'] ?? '') === 'high' ? 'danger' : '' ?>">
            <div class="label">Data sensitivity</div>
            <div class="value" style="font-size:1.1rem"><?= App::e($sensitivityLevels[$disp['data_sensitivity'] ?? ''] ?? ($disp['data_sensitivity'] ?? '—')) ?></div>
        </div>
        <div class="metric-card">
            <div class="label">Disposition</div>
            <div class="value" style="font-size:1rem"><?= App::e($methods[$disp['method'] ?? ''] ?? ($disp['method'] ?? 'TBD')) ?></div>
            <div class="sub"><?= App::e($disp['vendor_name'] ?? 'No vendor yet') ?></div>
        </div>
    </div>

    <?php if ($closed): ?>
        <div class="alert alert-success">This decommission is <strong><?= App::e((string)$disp['status']) ?></strong>
            (stage: <?= App::e((string)$curStage) ?>).</div>
    <?php endif; ?>

    <!-- ===== Stage panels ===== -->
    <?php
    // Show current stage form, and read-only summaries for prior stages
    $activeStage = $closed ? 'closed' : $curStage;
    ?>

    <!-- PLANNING -->
    <div class="card decom-stage <?= $activeStage === 'planning' ? 'decom-stage-active' : '' ?>">
        <div class="card-header"><h2>1. Inventory &amp; planning</h2></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;font-size:.88rem">
                Confirm asset identity, classify data sensitivity, migrate workloads, and record the change ticket before sanitization.
            </p>
            <?php if ($activeStage === 'planning' && !$closed): ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_stage">
                    <input type="hidden" name="disposal_id" value="<?= $id ?>">
                    <input type="hidden" name="stage" value="planning">
                    <div class="form-row"><label>Change management ticket #</label>
                        <input class="form-control" name="change_ticket" value="<?= App::e($disp['change_ticket'] ?? '') ?>"
                               placeholder="CHG0001234"></div>
                    <div class="form-row"><label>Data sensitivity</label>
                        <select class="form-control" name="data_sensitivity">
                            <?php foreach ($sensitivityLevels as $val => $lab): ?>
                                <option value="<?= $val ?>" <?= ($disp['data_sensitivity'] ?? 'medium') === $val ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row"><label>Reason for decommission</label>
                        <input class="form-control" name="reason" value="<?= App::e($disp['reason'] ?? '') ?>"
                               placeholder="EOL, refresh, failure, project end…"></div>
                    <div class="form-row"><label>Target / scheduled date</label>
                        <input class="form-control" type="date" name="scheduled_date" value="<?= App::e($disp['scheduled_date'] ?? '') ?>"></div>
                    <div class="form-row full"><label>Workload migration notes</label>
                        <textarea class="form-control" name="workload_migration" rows="2"
                                  placeholder="Where were VMs, services, IPs, storage volumes moved?"><?= App::e($disp['workload_migration'] ?? '') ?></textarea></div>
                    <div class="form-row full"><label>Planning notes / inventory checks</label>
                        <textarea class="form-control" name="planning_notes" rows="2"
                                  placeholder="Cables removed, spare parts retained, RAID config documented…"><?= App::e($disp['planning_notes'] ?? '') ?></textarea></div>
                    <div class="form-row full">
                        <label><input type="checkbox" name="asset_verified" value="1" <?= !empty($disp['asset_verified']) ? 'checked' : '' ?>>
                            Asset identity verified (serial, asset tag, location match inventory)</label>
                    </div>
                    <div class="form-row flex gap-1">
                        <button class="btn btn-secondary" type="submit" name="advance_stage" value="0">Save draft</button>
                        <button class="btn btn-primary" type="submit" name="advance_stage" value="1"
                                onclick="return confirm('Mark planning complete and proceed to sanitization?');">
                            Complete planning → Sanitization
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <dl class="view-dl">
                    <div><dt>Change ticket</dt><dd><?= App::e($disp['change_ticket'] ?? '—') ?></dd></div>
                    <div><dt>Sensitivity</dt><dd><?= App::e($sensitivityLevels[$disp['data_sensitivity'] ?? ''] ?? '—') ?></dd></div>
                    <div><dt>Reason</dt><dd><?= App::e($disp['reason'] ?? '—') ?></dd></div>
                    <div><dt>Scheduled</dt><dd><?= App::e($disp['scheduled_date'] ?? '—') ?></dd></div>
                    <div><dt>Asset verified</dt><dd><?= !empty($disp['asset_verified']) ? 'Yes' : 'No' ?></dd></div>
                    <div class="full"><dt>Migration</dt><dd style="white-space:pre-wrap"><?= App::e($disp['workload_migration'] ?? '—') ?></dd></div>
                    <div class="full"><dt>Planning notes</dt><dd style="white-space:pre-wrap"><?= App::e($disp['planning_notes'] ?? '—') ?></dd></div>
                </dl>
            <?php endif; ?>
        </div>
    </div>

    <!-- SANITIZATION -->
    <div class="card decom-stage <?= $activeStage === 'sanitization' ? 'decom-stage-active' : '' ?>">
        <div class="card-header"><h2>2. Data sanitization (NIST SP 800-88)</h2></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;font-size:.88rem">
                Choose Clear / Purge / Destroy based on sensitivity. For network gear, factory reset alone often leaves credentials or logs — clear configs, secrets, and logging stores.
                Prefer <strong>on-site</strong> purge/destroy for high/critical data.
            </p>
            <?php if ($activeStage === 'sanitization' && !$closed): ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_stage">
                    <input type="hidden" name="disposal_id" value="<?= $id ?>">
                    <input type="hidden" name="stage" value="sanitization">
                    <div class="form-row full"><label>NIST 800-88 category</label>
                        <select class="form-control" name="sanitize_category" required>
                            <option value="">— Select —</option>
                            <?php foreach ($nistCategories as $val => $lab): ?>
                                <option value="<?= App::e($val) ?>" <?= ($disp['sanitize_category'] ?? '') === $val ? 'selected' : '' ?>>
                                    <?= App::e($lab) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row"><label>Method used</label>
                        <input class="form-control" name="sanitize_method"
                               value="<?= App::e($disp['sanitize_method'] ?? '') ?>"
                               placeholder="Crypto-erase, ATA Secure Erase, degauss, shred…"></div>
                    <div class="form-row"><label>Location</label>
                        <select class="form-control" name="sanitize_on_site">
                            <option value="1" <?= ($disp['sanitize_on_site'] ?? 1) ? 'selected' : '' ?>>On-site (preferred for high sensitivity)</option>
                            <option value="0" <?= isset($disp['sanitize_on_site']) && !(int)$disp['sanitize_on_site'] ? 'selected' : '' ?>>Off-site (vendor facility)</option>
                        </select>
                    </div>
                    <div class="form-row"><label>Performed by</label>
                        <input class="form-control" name="sanitize_performed_by"
                               value="<?= App::e($disp['sanitize_performed_by'] ?? ($user['display_name'] ?? $user['username'] ?? '')) ?>"></div>
                    <div class="form-row"><label>Performed at</label>
                        <input class="form-control" type="datetime-local" name="sanitize_performed_at"
                               value="<?= !empty($disp['sanitize_performed_at']) ? App::e(date('Y-m-d\TH:i', strtotime((string)$disp['sanitize_performed_at']))) : '' ?>"></div>
                    <div class="form-row full decom-check-grid">
                        <label><input type="checkbox" name="network_config_cleared" value="1" <?= !empty($disp['network_config_cleared']) ? 'checked' : '' ?>>
                            Network configs / startup configs cleared</label>
                        <label><input type="checkbox" name="credentials_cleared" value="1" <?= !empty($disp['credentials_cleared']) ? 'checked' : '' ?>>
                            Credentials / keys / certificates removed</label>
                        <label><input type="checkbox" name="logs_cleared" value="1" <?= !empty($disp['logs_cleared']) ? 'checked' : '' ?>>
                            Logs / crash dumps / accounting records cleared</label>
                    </div>
                    <div class="form-row full"><label>Sanitization details</label>
                        <textarea class="form-control" name="sanitize_details" rows="3"
                                  placeholder="Tools used, passes, media serials, exceptions…"><?= App::e($disp['sanitize_details'] ?? '') ?></textarea></div>
                    <div class="form-row flex gap-1">
                        <button class="btn btn-secondary" type="submit" name="advance_stage" value="0">Save draft</button>
                        <button class="btn btn-primary" type="submit" name="advance_stage" value="1"
                                onclick="return confirm('Mark sanitization complete and proceed to verification?');">
                            Complete sanitization → Verification
                        </button>
                    </div>
                </form>
            <?php elseif (disposal_stage_index((string)$curStage, $stageOrder) > disposal_stage_index('sanitization', $stageOrder) || $curStage === 'closed'): ?>
                <dl class="view-dl">
                    <div><dt>NIST category</dt><dd><?= App::e($disp['sanitize_category'] ?? '—') ?></dd></div>
                    <div><dt>Method</dt><dd><?= App::e($disp['sanitize_method'] ?? '—') ?></dd></div>
                    <div><dt>On-site</dt><dd><?= isset($disp['sanitize_on_site']) ? (!empty($disp['sanitize_on_site']) ? 'Yes' : 'No') : '—' ?></dd></div>
                    <div><dt>Performed by</dt><dd><?= App::e($disp['sanitize_performed_by'] ?? '—') ?></dd></div>
                    <div><dt>When</dt><dd><?= App::e($disp['sanitize_performed_at'] ?? '—') ?></dd></div>
                    <div><dt>Configs / creds / logs</dt>
                        <dd>
                            <?= !empty($disp['network_config_cleared']) ? '✓ configs' : '· configs' ?>
                            · <?= !empty($disp['credentials_cleared']) ? '✓ credentials' : '· credentials' ?>
                            · <?= !empty($disp['logs_cleared']) ? '✓ logs' : '· logs' ?>
                        </dd>
                    </div>
                    <div class="full"><dt>Details</dt><dd style="white-space:pre-wrap"><?= App::e($disp['sanitize_details'] ?? '—') ?></dd></div>
                </dl>
            <?php else: ?>
                <p class="text-muted mb-0">Complete planning first.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- VERIFICATION -->
    <div class="card decom-stage <?= $activeStage === 'verification' ? 'decom-stage-active' : '' ?>">
        <div class="card-header"><h2>3. Verification &amp; documentation</h2></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;font-size:.88rem">
                Capture certificates of sanitization/destruction and chain-of-custody references for audit readiness.
            </p>
            <?php if ($activeStage === 'verification' && !$closed): ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_stage">
                    <input type="hidden" name="disposal_id" value="<?= $id ?>">
                    <input type="hidden" name="stage" value="verification">
                    <div class="form-row"><label>Certificate of sanitization / destruction #</label>
                        <input class="form-control" name="certificate_no" value="<?= App::e($disp['certificate_no'] ?? '') ?>"></div>
                    <div class="form-row"><label>Chain of custody reference</label>
                        <input class="form-control" name="chain_of_custody" value="<?= App::e($disp['chain_of_custody'] ?? '') ?>"
                               placeholder="Form #, barcode, CoC ID"></div>
                    <div class="form-row"><label>Verified by</label>
                        <input class="form-control" name="verified_by"
                               value="<?= App::e($disp['verified_by'] ?? ($user['display_name'] ?? $user['username'] ?? '')) ?>"></div>
                    <div class="form-row"><label>Verified at</label>
                        <input class="form-control" type="datetime-local" name="verified_at"
                               value="<?= !empty($disp['verified_at']) ? App::e(date('Y-m-d\TH:i', strtotime((string)$disp['verified_at']))) : '' ?>"></div>
                    <div class="form-row full"><label>Verification notes</label>
                        <textarea class="form-control" name="verification_notes" rows="2"><?= App::e($disp['verification_notes'] ?? '') ?></textarea></div>
                    <div class="form-row flex gap-1">
                        <button class="btn btn-secondary" type="submit" name="advance_stage" value="0">Save draft</button>
                        <button class="btn btn-primary" type="submit" name="advance_stage" value="1"
                                onclick="return confirm('Verification complete — proceed to physical disposition?');">
                            Complete verification → Disposition
                        </button>
                    </div>
                </form>
            <?php elseif (disposal_stage_index((string)$curStage, $stageOrder) > disposal_stage_index('verification', $stageOrder) || $curStage === 'closed'): ?>
                <dl class="view-dl">
                    <div><dt>Certificate #</dt><dd><?= App::e($disp['certificate_no'] ?? '—') ?></dd></div>
                    <div><dt>Chain of custody</dt><dd><?= App::e($disp['chain_of_custody'] ?? '—') ?></dd></div>
                    <div><dt>Verified by</dt><dd><?= App::e($disp['verified_by'] ?? '—') ?></dd></div>
                    <div><dt>When</dt><dd><?= App::e($disp['verified_at'] ?? '—') ?></dd></div>
                    <div class="full"><dt>Notes</dt><dd style="white-space:pre-wrap"><?= App::e($disp['verification_notes'] ?? '—') ?></dd></div>
                </dl>
            <?php else: ?>
                <p class="text-muted mb-0">Available after sanitization.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- DISPOSITION -->
    <div class="card decom-stage <?= $activeStage === 'disposition' ? 'decom-stage-active' : '' ?>">
        <div class="card-header"><h2>4. Physical disposition</h2></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;font-size:.88rem">
                Resell (if sanitized and valuable), recycle via certified ITAD, or destroy. Completing this step removes the device from the rack and marks it disposed.
            </p>
            <?php if ($activeStage === 'disposition' && !$closed): ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_stage">
                    <input type="hidden" name="disposal_id" value="<?= $id ?>">
                    <input type="hidden" name="stage" value="disposition">
                    <div class="form-row"><label>Disposition method</label>
                        <select class="form-control" name="method">
                            <?php foreach ($methods as $val => $lab): ?>
                                <option value="<?= $val ?>" <?= ($disp['method'] ?? '') === $val ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row"><label>ITAD / vendor</label>
                        <select class="form-control" name="vendor_id">
                            <option value="">— None / internal —</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= (int)$v['vendor_id'] ?>"
                                    <?= (int)($disp['vendor_id'] ?? 0) === (int)$v['vendor_id'] ? 'selected' : '' ?>>
                                    <?= App::e($v['name']) ?> (<?= App::e($v['vendor_type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">
                            <a href="<?= App::e(App::url('pages/disposals.php?tab=vendors')) ?>">Manage vendors</a>
                        </p>
                    </div>
                    <div class="form-row"><label>Pickup / BOL reference</label>
                        <input class="form-control" name="disposition_ref" value="<?= App::e($disp['disposition_ref'] ?? '') ?>"></div>
                    <div class="form-row"><label>Pickup date</label>
                        <input class="form-control" type="date" name="pickup_date" value="<?= App::e($disp['pickup_date'] ?? '') ?>"></div>
                    <div class="form-row"><label>Scheduled date</label>
                        <input class="form-control" type="date" name="scheduled_date" value="<?= App::e($disp['scheduled_date'] ?? '') ?>"></div>
                    <div class="form-row full"><label>Disposition notes</label>
                        <textarea class="form-control" name="notes" rows="2"><?= App::e($disp['notes'] ?? '') ?></textarea></div>
                    <div class="form-row flex gap-1">
                        <button class="btn btn-secondary" type="submit" name="advance_stage" value="0">Save draft</button>
                        <button class="btn btn-primary" type="submit" name="advance_stage" value="1"
                                onclick="return confirm('Confirm physical disposition? Device will be removed from the rack and marked disposed.');">
                            Complete disposition → Post-review
                        </button>
                    </div>
                </form>
            <?php elseif (disposal_stage_index((string)$curStage, $stageOrder) > disposal_stage_index('disposition', $stageOrder) || $curStage === 'closed'): ?>
                <dl class="view-dl">
                    <div><dt>Method</dt><dd><?= App::e($methods[$disp['method'] ?? ''] ?? ($disp['method'] ?? '—')) ?></dd></div>
                    <div><dt>Vendor</dt><dd><?= App::e($disp['vendor_name'] ?? '—') ?><?= !empty($disp['vendor_certs']) ? ' · ' . App::e($disp['vendor_certs']) : '' ?></dd></div>
                    <div><dt>Reference</dt><dd><?= App::e($disp['disposition_ref'] ?? '—') ?></dd></div>
                    <div><dt>Pickup</dt><dd><?= App::e($disp['pickup_date'] ?? '—') ?></dd></div>
                    <div><dt>Completed</dt><dd><?= App::e($disp['completed_date'] ?? '—') ?></dd></div>
                </dl>
            <?php else: ?>
                <p class="text-muted mb-0">Available after verification.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- POST REVIEW -->
    <div class="card decom-stage <?= $activeStage === 'post_review' ? 'decom-stage-active' : '' ?>">
        <div class="card-header"><h2>5. Post-review</h2></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;font-size:.88rem">
                Capture lessons learned and policy updates to improve future decommissions (feeds audits &amp; reports later).
            </p>
            <?php if ($activeStage === 'post_review' && !$closed): ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_stage">
                    <input type="hidden" name="disposal_id" value="<?= $id ?>">
                    <input type="hidden" name="stage" value="post_review">
                    <div class="form-row full"><label>Lessons learned</label>
                        <textarea class="form-control" name="lessons_learned" rows="3"
                                  placeholder="What went well / what to change?"><?= App::e($disp['lessons_learned'] ?? '') ?></textarea></div>
                    <div class="form-row full"><label>Policy / procedure updates</label>
                        <textarea class="form-control" name="policy_updates" rows="2"
                                  placeholder="Checklist changes, vendor SLA notes, sensitivity rules…"><?= App::e($disp['policy_updates'] ?? '') ?></textarea></div>
                    <div class="form-row"><label>Reviewer</label>
                        <input class="form-control" name="post_review_by"
                               value="<?= App::e($disp['post_review_by'] ?? ($user['display_name'] ?? $user['username'] ?? '')) ?>"></div>
                    <div class="form-row flex gap-1">
                        <button class="btn btn-secondary" type="submit" name="advance_stage" value="0">Save draft</button>
                        <button class="btn btn-primary" type="submit" name="advance_stage" value="1"
                                onclick="return confirm('Close this decommission record?');">
                            Close decommission
                        </button>
                    </div>
                </form>
            <?php elseif ($curStage === 'closed' || $disp['status'] === 'completed'): ?>
                <dl class="view-dl">
                    <div><dt>Reviewer</dt><dd><?= App::e($disp['post_review_by'] ?? '—') ?></dd></div>
                    <div><dt>When</dt><dd><?= App::e($disp['post_review_at'] ?? '—') ?></dd></div>
                    <div class="full"><dt>Lessons learned</dt><dd style="white-space:pre-wrap"><?= App::e($disp['lessons_learned'] ?? '—') ?></dd></div>
                    <div class="full"><dt>Policy updates</dt><dd style="white-space:pre-wrap"><?= App::e($disp['policy_updates'] ?? '—') ?></dd></div>
                </dl>
            <?php else: ?>
                <p class="text-muted mb-0">Available after physical disposition.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    layout_footer();
    exit;
}

// ---- Start form for a specific device ----
if ($actionGet === 'start' && $startDeviceId) {
    $dev = Database::fetchOne(
        'SELECT d.*, c.name AS cabinet_name FROM devices d
         LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
         WHERE d.device_id = ?',
        [$startDeviceId]
    );
    if (!$dev) {
        App::flash('error', 'Device not found.');
        App::redirect('pages/devices.php');
    }
    $open = Database::fetchOne(
        "SELECT disposal_id FROM disposals WHERE device_id = ? AND status NOT IN ('completed','cancelled')",
        [$startDeviceId]
    );
    if ($open) {
        App::redirect('pages/disposals.php?id=' . (int)$open['disposal_id']);
    }

    layout_header('Start decommission', $user, 'disposals');
    ?>
    <div class="flex-between mb-2">
        <p class="text-muted mb-0">Begin the formal decommission workflow for this asset.</p>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/devices.php?id=' . $startDeviceId)) ?>">← Device</a>
    </div>
    <div class="card">
        <div class="card-header"><h2><?= App::e($dev['label']) ?></h2></div>
        <div class="card-body">
            <dl class="view-dl" style="margin-bottom:1.25rem">
                <div><dt>Type</dt><dd><?= App::e($dev['device_type'] ?? '—') ?></dd></div>
                <div><dt>Serial</dt><dd><?= App::e($dev['serial_no'] ?? '—') ?></dd></div>
                <div><dt>Asset tag</dt><dd><?= App::e($dev['asset_tag'] ?? '—') ?></dd></div>
                <div><dt>Cabinet</dt><dd><?= App::e($dev['cabinet_name'] ?? '—') ?></dd></div>
            </dl>
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="device_id" value="<?= $startDeviceId ?>">
                <div class="form-row"><label>Change ticket #</label>
                    <input class="form-control" name="change_ticket" placeholder="CHG0001234"></div>
                <div class="form-row"><label>Data sensitivity</label>
                    <select class="form-control" name="data_sensitivity">
                        <?php foreach ($sensitivityLevels as $val => $lab): ?>
                            <option value="<?= $val ?>" <?= $val === 'medium' ? 'selected' : '' ?>><?= App::e($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Intended disposition</label>
                    <select class="form-control" name="method">
                        <?php foreach ($methods as $val => $lab): ?>
                            <option value="<?= $val ?>"><?= App::e($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Target date</label>
                    <input class="form-control" type="date" name="scheduled_date"></div>
                <div class="form-row full"><label>Reason</label>
                    <input class="form-control" name="reason" required placeholder="Why is this device being decommissioned?"></div>
                <div class="form-row full"><label>Workload migration (initial)</label>
                    <textarea class="form-control" name="workload_migration" rows="2"
                              placeholder="Optional — can complete in planning stage"></textarea></div>
                <div class="form-row full"><label>Notes</label>
                    <textarea class="form-control" name="notes" rows="2"></textarea></div>
                <div class="form-row">
                    <button class="btn btn-primary" type="submit">Start decommission process</button>
                </div>
            </form>
        </div>
    </div>
    <?php
    layout_footer();
    exit;
}

// ---- List / vendors tab ----
$tab = $_GET['tab'] ?? 'queue';
$notifyDays = (int) SettingsService::get('disposal_notify_days', 7);
$dueSoon = Database::fetchAll(
    "SELECT d.*, dev.label AS device_label
     FROM disposals d
     INNER JOIN devices dev ON dev.device_id = d.device_id
     WHERE d.status IN ('pending','approved','in_progress')
       AND d.scheduled_date IS NOT NULL
       AND d.scheduled_date <= DATEADD(day, ?, CAST(GETUTCDATE() AS date))
     ORDER BY d.scheduled_date",
    [$notifyDays]
);

$disposals = Database::fetchAll(
    'SELECT d.*, dev.label AS device_label, dev.serial_no,
            u.username AS requested_by_name, v.name AS vendor_name
     FROM disposals d
     INNER JOIN devices dev ON dev.device_id = d.device_id
     LEFT JOIN users u ON u.user_id = d.requested_by
     LEFT JOIN disposal_vendors v ON v.vendor_id = d.vendor_id
     ORDER BY d.created_at DESC'
);

$openCount = count(array_filter($disposals, static fn($d) => !in_array($d['status'], ['completed', 'cancelled'], true)));
$devices = Database::fetchAll(
    "SELECT device_id, label FROM devices
     WHERE is_active = 1 AND status NOT IN ('disposed')
     ORDER BY label"
);

layout_header('Decommission & Disposal', $user, 'disposals');
?>

<div class="flex-between mb-2">
    <p class="text-muted mb-0">NIST-aligned decommission workflow: plan → sanitize → verify → dispose → review.</p>
    <div class="flex gap-1">
        <a class="btn btn-secondary <?= $tab === 'queue' ? 'btn-primary' : '' ?>" href="?tab=queue">Queue</a>
        <a class="btn btn-secondary <?= $tab === 'vendors' ? 'btn-primary' : '' ?>" href="?tab=vendors">Vendors</a>
    </div>
</div>

<?php if ($dueSoon): ?>
<div class="alert alert-warning">
    <strong><?= count($dueSoon) ?> decommission(s)</strong> scheduled within <?= $notifyDays ?> days:
    <?= App::e(implode(', ', array_map(static fn($x) => $x['device_label'] . ' (' . $x['scheduled_date'] . ')', $dueSoon))) ?>
</div>
<?php endif; ?>

<div class="metrics">
    <div class="metric-card warning"><div class="label">Open</div><div class="value"><?= $openCount ?></div></div>
    <div class="metric-card"><div class="label">Total records</div><div class="value"><?= count($disposals) ?></div></div>
    <div class="metric-card accent"><div class="label">Vendors</div><div class="value"><?= count($vendors) ?></div></div>
</div>

<?php if ($tab === 'vendors'): ?>
<div class="card">
    <div class="card-header"><h2>ITAD / recycle / destruction vendors</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr><th>Name</th><th>Type</th><th>Certifications</th><th>Contact</th><th>Phone</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($vendors as $v): ?>
                <tr>
                    <td><strong><?= App::e($v['name']) ?></strong>
                        <?php if (!empty($v['website'])): ?>
                            <div class="text-muted" style="font-size:.75rem"><?= App::e($v['website']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge"><?= App::e($v['vendor_type']) ?></span></td>
                    <td><?= App::e($v['certifications'] ?? '—') ?></td>
                    <td><?= App::e($v['contact_name'] ?? '—') ?>
                        <?php if (!empty($v['contact_email'])): ?>
                            <div class="text-muted" style="font-size:.75rem"><?= App::e($v['contact_email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= App::e($v['contact_phone'] ?? '—') ?></td>
                    <td class="actions">
                        <form method="post" style="display:inline" onsubmit="return confirm('Deactivate vendor?');">
                            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                            <input type="hidden" name="action" value="deactivate_vendor">
                            <input type="hidden" name="vendor_id" value="<?= (int)$v['vendor_id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">Deactivate</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$vendors): ?>
                <tr><td colspan="6" class="text-muted">No vendors yet. Add certified ITAD / e-waste partners below.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body">
        <h3 class="mt-0">Add vendor</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="add_vendor">
            <div class="form-row"><label>Name *</label><input class="form-control" name="name" required></div>
            <div class="form-row"><label>Type</label>
                <select class="form-control" name="vendor_type">
                    <?php foreach (['itad' => 'ITAD', 'recycle' => 'Recycle', 'destroy' => 'Destruction', 'resale' => 'Resale', 'donate' => 'Donate'] as $val => $lab): ?>
                        <option value="<?= $val ?>"><?= $lab ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Certifications</label>
                <input class="form-control" name="certifications" placeholder="R2, e-Stewards, NAID AAA…"></div>
            <div class="form-row"><label>Contact name</label><input class="form-control" name="contact_name"></div>
            <div class="form-row"><label>Email</label><input class="form-control" type="email" name="contact_email"></div>
            <div class="form-row"><label>Phone</label><input class="form-control" name="contact_phone"></div>
            <div class="form-row"><label>Website</label><input class="form-control" name="website"></div>
            <div class="form-row full"><label>Address</label><input class="form-control" name="address"></div>
            <div class="form-row full"><label>Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Add vendor</button></div>
        </form>
    </div>
</div>
<?php else: ?>

<div class="card">
    <div class="card-header"><h2>Decommission queue</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Device</th><th>Stage</th><th>Status</th><th>Sensitivity</th>
                <th>Method</th><th>Vendor</th><th>Scheduled</th><th>Requested</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($disposals as $d):
                $bc = match ($d['status']) {
                    'completed' => 'badge-success',
                    'pending' => 'badge-warning',
                    'cancelled' => '',
                    default => 'badge-info',
                };
                $st = $d['stage'] ?? 'planning';
                ?>
                <tr>
                    <td>
                        <a href="?id=<?= (int)$d['disposal_id'] ?>"><strong><?= App::e($d['device_label']) ?></strong></a>
                        <?php if (!empty($d['serial_no'])): ?>
                            <div class="text-muted" style="font-size:.75rem"><?= App::e($d['serial_no']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-info"><?= App::e(preg_replace('/^\d+\.\s*/', '', $stages[$st] ?? $st)) ?></span></td>
                    <td><span class="badge <?= $bc ?>"><?= App::e($d['status']) ?></span></td>
                    <td><?= App::e($sensitivityLevels[$d['data_sensitivity'] ?? ''] ?? ($d['data_sensitivity'] ?? '—')) ?></td>
                    <td><?= App::e($methods[$d['method'] ?? ''] ?? ($d['method'] ?? '—')) ?></td>
                    <td><?= App::e($d['vendor_name'] ?? '—') ?></td>
                    <td><?= App::e($d['scheduled_date'] ?? '—') ?></td>
                    <td><?= App::e($d['requested_by_name'] ?? '—') ?></td>
                    <td class="actions">
                        <a class="btn btn-sm btn-secondary" href="?id=<?= (int)$d['disposal_id'] ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$disposals): ?>
                <tr><td colspan="9" class="text-muted">No decommission records. Start from a device page or below.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body">
        <h3 class="mt-0">Quick start (select device)</h3>
        <form method="get" class="form-grid">
            <input type="hidden" name="action" value="start">
            <div class="form-row"><label>Device</label>
                <select class="form-control" name="device_id" required>
                    <option value="">—</option>
                    <?php foreach ($devices as $dev): ?>
                        <option value="<?= (int)$dev['device_id'] ?>"><?= App::e($dev['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Start decommission…</button></div>
        </form>
        <p class="text-muted" style="font-size:.8rem;margin:.75rem 0 0">
            Prefer the <strong>Decommission</strong> button on the device detail page for a cleaner start.
        </p>
    </div>
</div>
<?php endif; ?>

<style>
.decom-stepper { margin-bottom: 1.25rem; }
.decom-steps {
  list-style: none; margin: 0; padding: 0;
  display: flex; flex-wrap: wrap; gap: .5rem;
}
.decom-step {
  display: flex; align-items: center; gap: .45rem;
  flex: 1 1 120px;
  padding: .5rem .65rem;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--surface-2);
  opacity: .55;
  font-size: .82rem;
}
.decom-step.done { opacity: 1; border-color: #22c55e66; background: #14532d33; }
.decom-step.current { opacity: 1; border-color: #3b82f6aa; background: #1e3a5f55; font-weight: 600; }
.decom-step-num {
  display: inline-grid; place-items: center;
  width: 1.5rem; height: 1.5rem; border-radius: 50%;
  background: var(--surface-3); font-weight: 700; font-size: .75rem;
}
.decom-step.done .decom-step-num { background: #166534; color: #bbf7d0; }
.decom-step.current .decom-step-num { background: #1d4ed8; color: #dbeafe; }
.decom-stage { opacity: .85; }
.decom-stage-active { opacity: 1; box-shadow: 0 0 0 1px #3b82f655; }
.decom-check-grid {
  display: flex; flex-direction: column; gap: .45rem;
  padding: .5rem 0;
}
.decom-check-grid label { font-size: .9rem; display: flex; align-items: center; gap: .45rem; }
</style>
<?php layout_footer(); ?>
