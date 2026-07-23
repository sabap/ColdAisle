<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/audit_helpers.php';
App::boot();
$user = App::requirePermission('view_audits');

$canEditAudits = AuthManager::can($user, 'edit_audits')
    || AuthManager::can($user, 'edit_infrastructure')
    || AuthManager::can($user, 'manage_settings');

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'set_audit_interval' && $canEditAudits) {
            $days = (int)($_POST['audit_interval_days'] ?? 90);
            if ($days < 1 || $days > 3650) {
                throw new RuntimeException('Interval must be between 1 and 3650 days.');
            }
            SettingsService::set('audit_interval_days', (string)$days, 'compliance');
            App::flash('success', 'Default audit schedule set to every ' . $days . ' days (' . audit_interval_label($days) . ').');
            App::redirect('pages/audits.php#schedule');
        }
        if ($action === 'set_cabinet_interval' && $canEditAudits) {
            $cid = (int)($_POST['cabinet_id'] ?? 0);
            $raw = trim((string)($_POST['cabinet_interval_days'] ?? ''));
            if ($cid <= 0) {
                throw new RuntimeException('Cabinet required.');
            }
            $val = $raw === '' ? null : max(1, min(3650, (int)$raw));
            Database::update('cabinets', ['audit_interval_days' => $val], 'cabinet_id = :id', [':id' => $cid]);
            App::flash('success', $val
                ? "Cabinet schedule override set to every {$val} days."
                : 'Cabinet schedule override cleared (uses site default).');
            App::redirect('pages/audits.php#schedule');
        }
        if ($action === 'create_job' && $canEditAudits) {
            $jobId = Database::insert('audit_jobs', [
                'name' => trim($_POST['name']),
                'audit_type' => $_POST['audit_type'] ?? 'cabinet',
                'scope_type' => $_POST['scope_type'] ?: null,
                'scope_id' => $_POST['scope_id'] !== '' ? (int)$_POST['scope_id'] : null,
                'assigned_to' => $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null,
                'status' => 'open',
                'due_date' => $_POST['due_date'] !== '' ? $_POST['due_date'] : null,
                'created_by' => (int)$user['user_id'],
            ]);
            if (($_POST['audit_type'] ?? '') === 'cabinet' && ($_POST['scope_type'] ?? '') === 'cabinet' && $_POST['scope_id'] !== '') {
                $devices = Database::fetchAll(
                    'SELECT device_id, label, position_u, u_height, serial_no FROM devices WHERE cabinet_id = ? AND is_active = 1',
                    [(int)$_POST['scope_id']]
                );
                foreach ($devices as $d) {
                    Database::insert('audit_items', [
                        'job_id' => $jobId,
                        'entity_type' => 'device',
                        'entity_id' => (int)$d['device_id'],
                        'expected_value' => json_encode([
                            'label' => $d['label'],
                            'position_u' => $d['position_u'],
                            'u_height' => $d['u_height'],
                            'serial_no' => $d['serial_no'],
                        ]),
                        'result' => 'pending',
                    ]);
                }
            }
            if (($_POST['audit_type'] ?? '') === 'inventory') {
                $devices = Database::fetchAll('SELECT TOP 200 device_id, label, status FROM devices WHERE is_active = 1');
                foreach ($devices as $d) {
                    Database::insert('audit_items', [
                        'job_id' => $jobId,
                        'entity_type' => 'device',
                        'entity_id' => (int)$d['device_id'],
                        'expected_value' => json_encode(['label' => $d['label'], 'status' => $d['status']]),
                        'result' => 'pending',
                    ]);
                }
            }
            App::flash('success', 'Audit job created.');
            App::redirect('pages/audits.php?job_id=' . (int)$jobId);
        }
        if ($action === 'check_item' && $canEditAudits) {
            Database::update('audit_items', [
                'result' => $_POST['result'],
                'actual_value' => $_POST['actual_value'] ?? null,
                'notes' => $_POST['notes'] ?? null,
                'checked_by' => (int)$user['user_id'],
                'checked_at' => date('Y-m-d H:i:s'),
            ], 'item_id = :id', [':id' => (int)$_POST['item_id']]);
            App::flash('success', 'Item recorded.');
            App::redirect('pages/audits.php?job_id=' . (int)$_POST['job_id']);
        }
        if ($action === 'complete_job' && $canEditAudits) {
            $jobId = (int)$_POST['job_id'];
            $mismatch = (int) Database::fetchValue(
                "SELECT COUNT(*) FROM audit_items WHERE job_id = ? AND result IN ('mismatch','missing','extra')",
                [$jobId]
            );
            $match = (int) Database::fetchValue(
                "SELECT COUNT(*) FROM audit_items WHERE job_id = ? AND result = 'match'",
                [$jobId]
            );
            Database::update('audit_jobs', [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'findings_summary' => "Matches: {$match}, Issues: {$mismatch}",
            ], 'job_id = :id', [':id' => $jobId]);
            App::flash('success', 'Audit completed.');
            App::redirect('pages/audits.php?job_id=' . $jobId);
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/audits.php');
}

// ---------- Job detail (legacy checklist jobs) ----------
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$users = Database::fetchAll('SELECT user_id, username, display_name FROM users WHERE is_active = 1 ORDER BY username');
$cabinets = Database::fetchAll('SELECT cabinet_id, name, row_id FROM cabinets WHERE is_active = 1 ORDER BY name');
$rows = [];
try {
    $rows = Database::fetchAll(
        'SELECT cr.row_id, cr.name, rm.name AS room_name
         FROM cabinet_rows cr
         LEFT JOIN rooms rm ON rm.room_id = cr.room_id
         ORDER BY rm.name, cr.name'
    );
} catch (Throwable $e) {
    $rows = [];
}

if ($jobId) {
    $job = Database::fetchOne('SELECT * FROM audit_jobs WHERE job_id = ?', [$jobId]);
    if (!$job) {
        App::flash('error', 'Audit job not found.');
        App::redirect('pages/audits.php');
    }
    $items = Database::fetchAll(
        'SELECT i.*, d.label AS device_label FROM audit_items i
         LEFT JOIN devices d ON d.device_id = i.entity_id AND i.entity_type = \'device\'
         WHERE i.job_id = ? ORDER BY i.item_id',
        [$jobId]
    );
    layout_header('Audit: ' . ($job['name'] ?? ''), $user, 'audits');
    ?>
    <div class="flex-between mb-2">
        <div>
            <span class="badge badge-info"><?= App::e($job['audit_type'] ?? '') ?></span>
            <span class="badge"><?= App::e($job['status'] ?? '') ?></span>
            <?php if (!empty($job['findings_summary'])): ?>
                <span class="text-muted"><?= App::e($job['findings_summary']) ?></span>
            <?php endif; ?>
        </div>
        <div class="flex gap-1">
            <a class="btn btn-ghost" href="<?= App::e(App::url('pages/audits.php#jobs')) ?>">← Audits</a>
            <?php if (($job['status'] ?? '') !== 'completed' && $canEditAudits): ?>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="complete_job">
                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                <button class="btn btn-primary" type="submit">Complete Audit</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-body flush">
            <table class="data">
                <thead><tr><th>Entity</th><th>Expected</th><th>Result</th><th>Actual / Notes</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= App::e(($item['device_label'] ?? $item['entity_type']) . ' #' . $item['entity_id']) ?></td>
                        <td><code style="font-size:.75rem"><?= App::e(substr((string)$item['expected_value'], 0, 120)) ?></code></td>
                        <td><span class="badge"><?= App::e($item['result']) ?></span></td>
                        <td>
                            <?php if ($item['result'] === 'pending' && ($job['status'] ?? '') !== 'completed' && $canEditAudits): ?>
                            <form method="post" class="flex gap-1" style="flex-wrap:wrap">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="check_item">
                                <input type="hidden" name="item_id" value="<?= (int)$item['item_id'] ?>">
                                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                                <select class="form-control" name="result" style="width:auto">
                                    <option value="match">match</option>
                                    <option value="mismatch">mismatch</option>
                                    <option value="missing">missing</option>
                                    <option value="extra">extra</option>
                                </select>
                                <input class="form-control" name="actual_value" placeholder="Actual" style="width:140px">
                                <input class="form-control" name="notes" placeholder="Notes" style="width:120px">
                                <button class="btn btn-sm btn-secondary" type="submit">Save</button>
                            </form>
                            <?php else: ?>
                                <?= App::e($item['actual_value'] ?? '') ?> <?= App::e($item['notes'] ?? '') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= App::e($item['checked_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?><tr><td colspan="5" class="text-muted">No items in this audit.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    layout_footer();
    exit;
}

// ---------- Main audits dashboard ----------
$compliance = audit_compliance_summary();
$overdue = audit_overdue_cabinets(40);
$dueSoonList = [];
try {
    $allForSoon = Database::fetchAll(
        'SELECT c.cabinet_id, c.name, c.audit_interval_days, cr.name AS row_name,
                (SELECT MAX(a.audited_at) FROM cabinet_audits a WHERE a.cabinet_id = c.cabinet_id) AS last_audit_at
         FROM cabinets c
         LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
         WHERE c.is_active = 1'
    );
    foreach ($allForSoon as $r) {
        $sch = audit_cabinet_schedule(
            isset($r['last_audit_at']) ? (string)$r['last_audit_at'] : null,
            audit_cabinet_interval_days($r)
        );
        if ($sch['status'] === 'due_soon') {
            $r['next_due'] = $sch['next_due'];
            $r['days_until'] = $sch['days_until'];
            $dueSoonList[] = $r;
        }
    }
    usort($dueSoonList, static fn($a, $b) => ((int)$a['days_until']) <=> ((int)$b['days_until']));
    $dueSoonList = array_slice($dueSoonList, 0, 15);
} catch (Throwable $e) {
    $dueSoonList = [];
}

// Cabinet audit filters
$fCabinet = isset($_GET['cabinet_id']) ? (int)$_GET['cabinet_id'] : 0;
$fRow = isset($_GET['row_id']) ? (int)$_GET['row_id'] : 0;
$fUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$fFrom = trim((string)($_GET['date_from'] ?? ''));
$fTo = trim((string)($_GET['date_to'] ?? ''));
$cabLimit = (int)($_GET['cab_limit'] ?? 10);
if (!in_array($cabLimit, [10, 25, 50, 100], true)) {
    $cabLimit = 10;
}
$cabPage = max(1, (int)($_GET['cab_page'] ?? 1));
$cabOffset = ($cabPage - 1) * $cabLimit;
$hasCabFilters = $fCabinet || $fRow || $fUser || $fFrom !== '' || $fTo !== '';

$cabList = audit_cabinet_audit_list([
    'cabinet_id' => $fCabinet ?: null,
    'row_id' => $fRow ?: null,
    'user_id' => $fUser ?: null,
    'date_from' => $fFrom !== '' ? $fFrom : null,
    'date_to' => $fTo !== '' ? $fTo : null,
    'limit' => $cabLimit,
    'offset' => $cabOffset,
]);
$cabTotal = $cabList['total'];
$cabPages = max(1, (int)ceil($cabTotal / $cabLimit));
if ($cabPage > $cabPages) {
    $cabPage = $cabPages;
    $cabOffset = ($cabPage - 1) * $cabLimit;
    $cabList = audit_cabinet_audit_list([
        'cabinet_id' => $fCabinet ?: null,
        'row_id' => $fRow ?: null,
        'user_id' => $fUser ?: null,
        'date_from' => $fFrom !== '' ? $fFrom : null,
        'date_to' => $fTo !== '' ? $fTo : null,
        'limit' => $cabLimit,
        'offset' => $cabOffset,
    ]);
}

// System log pagination
$logLimit = (int)($_GET['log_limit'] ?? 50);
if (!in_array($logLimit, [10, 50, 100, 200], true)) {
    $logLimit = 50;
}
$logPage = max(1, (int)($_GET['log_page'] ?? 1));
$logOffset = ($logPage - 1) * $logLimit;
$logData = audit_system_log_page($logLimit, $logOffset);
$logTotal = $logData['total'];
$logPages = max(1, (int)ceil($logTotal / $logLimit));
if ($logPage > $logPages) {
    $logPage = $logPages;
    $logOffset = ($logPage - 1) * $logLimit;
    $logData = audit_system_log_page($logLimit, $logOffset);
}

$jobs = [];
try {
    $jobs = Database::fetchAll(
        'SELECT TOP 20 j.*, u.username AS assigned_name,
            (SELECT COUNT(*) FROM audit_items i WHERE i.job_id = j.job_id) AS item_count,
            (SELECT COUNT(*) FROM audit_items i WHERE i.job_id = j.job_id AND i.result = \'pending\') AS pending_count
         FROM audit_jobs j
         LEFT JOIN users u ON u.user_id = j.assigned_to
         ORDER BY j.created_at DESC'
    );
} catch (Throwable $e) {
    $jobs = [];
}

$defaultInterval = audit_default_interval_days();
$pct = $compliance['compliance_pct'];
$utilClass = $pct >= 90 ? 'success' : ($pct >= 75 ? 'warning' : 'danger');
// util-bar uses util-success|warning|danger|accent from power helpers
$barClass = $pct >= 90 ? 'success' : ($pct >= 75 ? 'warning' : ($pct >= 50 ? 'accent' : 'danger'));

$qsBase = static function (array $extra = []) use ($fCabinet, $fRow, $fUser, $fFrom, $fTo, $cabLimit, $logLimit): string {
    $p = array_filter([
        'cabinet_id' => $fCabinet ?: null,
        'row_id' => $fRow ?: null,
        'user_id' => $fUser ?: null,
        'date_from' => $fFrom !== '' ? $fFrom : null,
        'date_to' => $fTo !== '' ? $fTo : null,
        'cab_limit' => $cabLimit,
        'log_limit' => $logLimit,
    ], static fn($v) => $v !== null && $v !== '');
    $p = array_merge($p, $extra);
    return http_build_query($p);
};

layout_header('Audits', $user, 'audits');
?>

<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0">
            Cabinet walkthrough certifications, compliance schedule, and system activity.
            Default cadence: <strong><?= App::e(audit_interval_label($defaultInterval)) ?></strong>.
        </p>
    </div>
    <div class="flex gap-1">
        <a class="btn btn-secondary btn-sm" href="#cabinet-audits">Cabinet audits</a>
        <a class="btn btn-secondary btn-sm" href="#schedule">Schedule</a>
        <a class="btn btn-secondary btn-sm" href="#syslog">System log</a>
    </div>
</div>

<!-- Compliance dashboard -->
<div class="metrics">
    <div class="metric-card <?= $utilClass === 'success' ? 'success' : ($utilClass === 'danger' ? 'danger' : ($utilClass === 'warning' ? 'warning' : 'accent')) ?>">
        <div class="label">Audit compliance</div>
        <div class="value"><?= number_format($pct, 1) ?><span class="metric-unit">%</span></div>
        <div class="sub"><?= (int)$compliance['compliant'] ?> / <?= (int)$compliance['total'] ?> cabinets in window</div>
    </div>
    <div class="metric-card <?= $compliance['overdue'] ? 'danger' : 'success' ?>">
        <div class="label">Overdue / never</div>
        <div class="value"><?= (int)$compliance['overdue'] ?></div>
        <div class="sub"><?= (int)$compliance['never'] ?> never audited</div>
    </div>
    <div class="metric-card warning">
        <div class="label">Due within 14 days</div>
        <div class="value"><?= (int)$compliance['due_soon'] ?></div>
        <div class="sub">Plan walkthroughs soon</div>
    </div>
    <div class="metric-card">
        <div class="label">Cadence</div>
        <div class="value" style="font-size:1.15rem"><?= (int)$defaultInterval ?><span class="metric-unit"> days</span></div>
        <div class="sub"><?= App::e(audit_interval_label($defaultInterval)) ?></div>
    </div>
</div>
<?php if ($compliance['total'] > 0): ?>
    <div class="util-bar util-bar-lg mb-2" title="Compliance">
        <div class="util-bar-fill util-<?= App::e($barClass) ?>"
             style="width:<?= min(100, $pct) ?>%"></div>
    </div>
<?php endif; ?>

<div class="split-2 mb-2">
    <div class="card">
        <div class="card-header">
            <h2>Overdue cabinets</h2>
            <span class="badge badge-danger"><?= count($overdue) ?></span>
        </div>
        <div class="card-body flush">
            <div class="table-wrap" style="max-height:280px;overflow:auto">
                <table class="data">
                    <thead><tr><th>Cabinet</th><th>Row</th><th>Last audit</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($overdue as $o): ?>
                        <tr>
                            <td>
                                <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$o['cabinet_id'])) ?>">
                                    <?= App::e($o['name']) ?>
                                </a>
                            </td>
                            <td><?= App::e($o['row_name'] ?? '—') ?></td>
                            <td style="font-size:.85rem">
                                <?= !empty($o['last_audit_at'])
                                    ? App::e(date('Y-m-d', strtotime((string)$o['last_audit_at'])))
                                    : '—' ?>
                            </td>
                            <td>
                                <span class="badge <?= App::e(audit_status_badge_class((string)$o['audit_status'])) ?>">
                                    <?= App::e(audit_status_label((string)$o['audit_status'])) ?>
                                </span>
                                <?php if ($o['audit_status'] === 'overdue' && $o['days_until'] !== null): ?>
                                    <span class="text-muted" style="font-size:.75rem">
                                        <?= abs((int)$o['days_until']) ?>d late
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$overdue): ?>
                        <tr><td colspan="4" class="text-muted">All cabinets are within the audit window. Nice work.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <h2>Due soon (14 days)</h2>
            <span class="badge badge-warning"><?= count($dueSoonList) ?></span>
        </div>
        <div class="card-body flush">
            <div class="table-wrap" style="max-height:280px;overflow:auto">
                <table class="data">
                    <thead><tr><th>Cabinet</th><th>Row</th><th>Next due</th><th>In</th></tr></thead>
                    <tbody>
                    <?php foreach ($dueSoonList as $d): ?>
                        <tr>
                            <td>
                                <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$d['cabinet_id'])) ?>">
                                    <?= App::e($d['name']) ?>
                                </a>
                            </td>
                            <td><?= App::e($d['row_name'] ?? '—') ?></td>
                            <td><?= App::e((string)($d['next_due'] ?? '—')) ?></td>
                            <td><?= (int)($d['days_until'] ?? 0) ?>d</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$dueSoonList): ?>
                        <tr><td colspan="4" class="text-muted">Nothing due in the next two weeks.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Cabinet audit log browser -->
<div class="card" id="cabinet-audits">
    <div class="card-header">
        <h2>Cabinet audits</h2>
        <span class="text-muted" style="font-size:.85rem">
            <?= $hasCabFilters ? 'Filtered results' : 'Most recent' ?>
            · <?= (int)$cabTotal ?> total
        </span>
    </div>
    <div class="card-body">
        <form method="get" class="form-grid" style="margin-bottom:0">
            <div class="form-row"><label>Cabinet</label>
                <select class="form-control" name="cabinet_id">
                    <option value="">— All —</option>
                    <?php foreach ($cabinets as $c): ?>
                        <option value="<?= (int)$c['cabinet_id'] ?>" <?= $fCabinet === (int)$c['cabinet_id'] ? 'selected' : '' ?>>
                            <?= App::e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Row</label>
                <select class="form-control" name="row_id">
                    <option value="">— All —</option>
                    <?php foreach ($rows as $r): ?>
                        <option value="<?= (int)$r['row_id'] ?>" <?= $fRow === (int)$r['row_id'] ? 'selected' : '' ?>>
                            <?= App::e(trim(($r['room_name'] ?? '') . ' / ' . ($r['name'] ?? ''), ' /')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Auditor</label>
                <select class="form-control" name="user_id">
                    <option value="">— All —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['user_id'] ?>" <?= $fUser === (int)$u['user_id'] ? 'selected' : '' ?>>
                            <?= App::e($u['display_name'] ?: $u['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>From</label>
                <input class="form-control" type="date" name="date_from" value="<?= App::e($fFrom) ?>"></div>
            <div class="form-row"><label>To</label>
                <input class="form-control" type="date" name="date_to" value="<?= App::e($fTo) ?>"></div>
            <div class="form-row"><label>Rows per page</label>
                <select class="form-control" name="cab_limit">
                    <?php foreach ([10, 25, 50, 100] as $n): ?>
                        <option value="<?= $n ?>" <?= $cabLimit === $n ? 'selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="log_limit" value="<?= (int)$logLimit ?>">
            <div class="form-row" style="align-self:end">
                <button class="btn btn-primary" type="submit">Apply filters</button>
                <?php if ($hasCabFilters): ?>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/audits.php?log_limit=' . $logLimit . '#cabinet-audits')) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>When</th><th>Cabinet</th><th>Row</th><th>Auditor</th><th>Certified</th><th>Comments</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($cabList['rows'] as $a): ?>
                <tr>
                    <td style="white-space:nowrap;font-size:.85rem">
                        <?= App::e(date('Y-m-d H:i', strtotime((string)$a['audited_at']))) ?>
                    </td>
                    <td>
                        <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$a['cabinet_id'])) ?>">
                            <?= App::e($a['cabinet_name'] ?? '—') ?>
                        </a>
                    </td>
                    <td><?= App::e($a['row_name'] ?? '—') ?></td>
                    <td><?= App::e($a['audited_by_name'] ?? '—') ?></td>
                    <td><?= !empty($a['certified']) ? '✓' : '—' ?></td>
                    <td style="font-size:.85rem;max-width:22rem">
                        <?= App::e((string)($a['comments'] ?? '—')) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cabList['rows']): ?>
                <tr><td colspan="6" class="text-muted">No cabinet audits match these filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($cabPages > 1): ?>
    <div class="card-body flex-between" style="padding-top:.65rem;padding-bottom:.65rem">
        <span class="text-muted" style="font-size:.85rem">
            Page <?= $cabPage ?> of <?= $cabPages ?>
            · showing <?= count($cabList['rows']) ?> of <?= (int)$cabTotal ?>
        </span>
        <div class="flex gap-1">
            <?php if ($cabPage > 1): ?>
                <a class="btn btn-sm btn-secondary" href="?<?= App::e($qsBase(['cab_page' => $cabPage - 1])) ?>#cabinet-audits">← Prev</a>
            <?php endif; ?>
            <?php if ($cabPage < $cabPages): ?>
                <a class="btn btn-sm btn-secondary" href="?<?= App::e($qsBase(['cab_page' => $cabPage + 1])) ?>#cabinet-audits">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Schedule settings -->
<div class="card" id="schedule">
    <div class="card-header"><h2>Audit schedule</h2></div>
    <div class="card-body">
        <p class="text-muted" style="margin-top:0;font-size:.9rem">
            Next due date for each cabinet is <strong>last audit + interval</strong>.
            Cabinets with no audit are treated as overdue. Override interval per cabinet if a row needs a different cadence.
        </p>
        <?php if ($canEditAudits): ?>
        <form method="post" class="form-grid" style="margin-bottom:1.25rem">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="set_audit_interval">
            <div class="form-row"><label>Site default interval</label>
                <select class="form-control" name="audit_interval_days" id="auditIntervalSelect">
                    <?php
                    $presetDays = array_column(audit_interval_presets(), 'days');
                    foreach (audit_interval_presets() as $p): ?>
                        <option value="<?= (int)$p['days'] ?>" <?= $defaultInterval === (int)$p['days'] ? 'selected' : '' ?>>
                            <?= App::e($p['label']) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (!in_array($defaultInterval, $presetDays, true)): ?>
                        <option value="<?= (int)$defaultInterval ?>" selected>Custom (<?= (int)$defaultInterval ?> days)</option>
                    <?php endif; ?>
                    <option value="custom">Custom days…</option>
                </select>
            </div>
            <div class="form-row" id="auditCustomDaysRow" style="display:none"><label>Custom days</label>
                <input class="form-control" type="number" min="1" max="3650" name="audit_interval_days_custom"
                       id="auditIntervalCustom" value="<?= (int)$defaultInterval ?>"></div>
            <div class="form-row" style="align-self:end">
                <button class="btn btn-primary" type="submit" id="auditIntervalSubmit">Save site schedule</button>
            </div>
        </form>
        <script>
        (function () {
            var sel = document.getElementById('auditIntervalSelect');
            var row = document.getElementById('auditCustomDaysRow');
            var custom = document.getElementById('auditIntervalCustom');
            var form = sel && sel.form;
            if (!sel || !form) return;
            function sync() {
                var isCustom = sel.value === 'custom';
                if (row) row.style.display = isCustom ? '' : 'none';
            }
            sel.addEventListener('change', sync);
            form.addEventListener('submit', function () {
                if (sel.value === 'custom' && custom) {
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = 'audit_interval_days';
                    h.value = custom.value;
                    form.appendChild(h);
                    sel.disabled = true;
                }
            });
            sync();
        })();
        </script>

        <h3 class="mt-0" style="font-size:.95rem">Per-cabinet override</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="set_cabinet_interval">
            <div class="form-row"><label>Cabinet</label>
                <select class="form-control" name="cabinet_id" required>
                    <option value="">— Select —</option>
                    <?php foreach ($cabinets as $c): ?>
                        <option value="<?= (int)$c['cabinet_id'] ?>"><?= App::e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Interval (days)</label>
                <input class="form-control" type="number" min="1" max="3650" name="cabinet_interval_days"
                       placeholder="Blank = site default"></div>
            <div class="form-row" style="align-self:end">
                <button class="btn btn-secondary" type="submit">Save override</button>
            </div>
        </form>
        <?php else: ?>
            <p class="text-muted mb-0">You can view schedules; edit rights are required to change cadence.</p>
        <?php endif; ?>
    </div>
</div>

<!-- System log -->
<div class="card" id="syslog">
    <div class="card-header">
        <h2>System activity log</h2>
        <span class="text-muted" style="font-size:.85rem"><?= (int)$logTotal ?> entries</span>
    </div>
    <div class="card-body" style="padding-bottom:.5rem">
        <form method="get" class="flex gap-1" style="flex-wrap:wrap;align-items:center">
            <?php if ($fCabinet): ?><input type="hidden" name="cabinet_id" value="<?= $fCabinet ?>"><?php endif; ?>
            <?php if ($fRow): ?><input type="hidden" name="row_id" value="<?= $fRow ?>"><?php endif; ?>
            <?php if ($fUser): ?><input type="hidden" name="user_id" value="<?= $fUser ?>"><?php endif; ?>
            <?php if ($fFrom !== ''): ?><input type="hidden" name="date_from" value="<?= App::e($fFrom) ?>"><?php endif; ?>
            <?php if ($fTo !== ''): ?><input type="hidden" name="date_to" value="<?= App::e($fTo) ?>"><?php endif; ?>
            <input type="hidden" name="cab_limit" value="<?= (int)$cabLimit ?>">
            <label class="text-muted" style="font-size:.85rem;margin:0">Rows</label>
            <select class="form-control" name="log_limit" style="width:auto" onchange="this.form.submit()">
                <?php foreach ([10, 50, 100, 200] as $n): ?>
                    <option value="<?= $n ?>" <?= $logLimit === $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm btn-secondary" type="submit">Go</button></noscript>
        </form>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logData['rows'] as $l): ?>
                <tr>
                    <td style="white-space:nowrap;font-size:.85rem"><?= App::e((string)$l['created_at']) ?></td>
                    <td><?= App::e($l['username'] ?? '—') ?></td>
                    <td><?= App::e($l['action'] ?? '') ?></td>
                    <td><?= App::e(($l['entity_type'] ?? '') . (!empty($l['entity_id']) ? ' #' . $l['entity_id'] : '')) ?></td>
                    <td class="text-muted" style="font-size:.8rem"><?= App::e($l['ip_address'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logData['rows']): ?>
                <tr><td colspan="5" class="text-muted">No system log entries.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body flex-between" style="padding-top:.65rem;padding-bottom:.65rem">
        <span class="text-muted" style="font-size:.85rem">
            Page <?= $logPage ?> of <?= $logPages ?>
            · <?= min($logLimit, max(0, $logTotal - $logOffset)) ?> shown
            (offset <?= $logOffset ?>)
        </span>
        <div class="flex gap-1">
            <?php if ($logPage > 1): ?>
                <a class="btn btn-sm btn-secondary" href="?<?= App::e($qsBase(['log_page' => $logPage - 1, 'cab_page' => $cabPage])) ?>#syslog">← Prev</a>
            <?php endif; ?>
            <?php if ($logPage < $logPages): ?>
                <a class="btn btn-sm btn-secondary" href="?<?= App::e($qsBase(['log_page' => $logPage + 1, 'cab_page' => $cabPage])) ?>#syslog">Next →</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Legacy checklist jobs (collapsed secondary) -->
<div class="card" id="jobs">
    <div class="card-header">
        <h2>Checklist audit jobs</h2>
        <span class="text-muted" style="font-size:.8rem">Optional device-level walkthrough jobs</span>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead><tr><th>Name</th><th>Type</th><th>Status</th><th>Items</th><th>Pending</th><th>Due</th><th>Assigned</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $j): ?>
                <tr>
                    <td><a href="?job_id=<?= (int)$j['job_id'] ?>"><?= App::e($j['name']) ?></a></td>
                    <td><?= App::e($j['audit_type']) ?></td>
                    <td><span class="badge badge-info"><?= App::e($j['status']) ?></span></td>
                    <td><?= (int)$j['item_count'] ?></td>
                    <td><?= (int)$j['pending_count'] ?></td>
                    <td><?= App::e($j['due_date'] ?? '—') ?></td>
                    <td><?= App::e($j['assigned_name'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$jobs): ?>
                <tr><td colspan="7" class="text-muted">No checklist jobs. Cabinet certifications above are the primary audit trail.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($canEditAudits): ?>
    <div class="card-body">
        <h3 class="mt-0">Create checklist job</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="create_job">
            <div class="form-row"><label>Name</label>
                <input class="form-control" name="name" required placeholder="Q3 Rack Audit - Row A"></div>
            <div class="form-row"><label>Type</label>
                <select class="form-control" name="audit_type">
                    <option value="cabinet">Cabinet</option>
                    <option value="inventory">Inventory</option>
                    <option value="power">Power</option>
                    <option value="cable">Cable</option>
                    <option value="full">Full</option>
                </select>
            </div>
            <div class="form-row"><label>Scope Type</label>
                <select class="form-control" name="scope_type">
                    <option value="">—</option>
                    <option value="cabinet">Cabinet</option>
                    <option value="room">Room</option>
                    <option value="datacenter">Data Center</option>
                </select>
            </div>
            <div class="form-row"><label>Scope Cabinet</label>
                <select class="form-control" name="scope_id">
                    <option value="">—</option>
                    <?php foreach ($cabinets as $c): ?>
                        <option value="<?= (int)$c['cabinet_id'] ?>"><?= App::e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Assign To</label>
                <select class="form-control" name="assigned_to">
                    <option value="">—</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['user_id'] ?>"><?= App::e($u['display_name'] ?: $u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Due Date</label>
                <input class="form-control" type="date" name="due_date"></div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Create Job</button></div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php layout_footer(); ?>
