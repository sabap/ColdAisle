<?php
/**
 * ColdAisle — IP address plan (statics, reserved, DHCP fences).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_ipam');
IpamService::ensure();
$canEdit = AuthManager::can($user, 'edit_ipam') || AuthManager::isAdmin($user);

$prefixId = isset($_GET['prefix_id']) ? (int)$_GET['prefix_id'] : 0;
$addressId = isset($_GET['address_id']) ? (int)$_GET['address_id'] : 0;
$view = strtolower(trim((string)($_GET['view'] ?? '')));
$q = class_exists('SearchService') ? SearchService::queryFromRequest() : trim((string)($_GET['q'] ?? ''));

if (strtolower(trim((string)($_GET['export'] ?? ''))) === 'csv_template') {
    if (class_exists('ListPager')) {
        ListPager::sendCsv('coldaisle-ipam-template.csv', IpamService::csvTemplateHeaders(), [
            ['10.12.40.10', 'web-01', 'assigned', '', 'App server', '', '40', '10.12.40.1', '10.12.40.0/24'],
            ['10.12.40.1', 'gateway', 'reserved', '', 'Default gateway', '', '', '', ''],
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to edit IPAM.');
        App::redirect('pages/ipam.php');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_prefix') {
            $pid = !empty($_POST['prefix_id']) ? (int)$_POST['prefix_id'] : null;
            $res = IpamService::savePrefix($_POST, $pid);
            App::flash('success', $res['message']);
            App::redirect('pages/ipam.php?prefix_id=' . (int)$res['prefix_id']);
        }
        if ($action === 'delete_prefix') {
            $res = IpamService::deletePrefix((int)($_POST['prefix_id'] ?? 0));
            App::flash('success', $res['message']);
            App::redirect('pages/ipam.php');
        }
        if ($action === 'save_address') {
            $aid = !empty($_POST['address_id']) ? (int)$_POST['address_id'] : null;
            $res = IpamService::saveAddress($_POST, $aid);
            App::flash('success', $res['message']);
            App::redirect('pages/ipam.php?prefix_id=' . (int)($_POST['prefix_id'] ?? 0));
        }
        if ($action === 'delete_address') {
            $pid = (int)($_POST['prefix_id'] ?? 0);
            IpamService::deleteAddress((int)($_POST['address_id'] ?? 0));
            App::flash('success', 'Address removed from the plan (the host is unchanged).');
            App::redirect('pages/ipam.php?prefix_id=' . $pid);
        }
        if ($action === 'reconcile') {
            $r = IpamService::reconcileInventory();
            App::flash(
                'success',
                'Inventory link: ' . $r['created'] . ' new, ' . $r['linked'] . ' updated, '
                . $r['orphans'] . ' IPs not in any prefix.'
            );
            App::redirect('pages/ipam.php' . ($prefixId ? '?prefix_id=' . $prefixId : '?view=conflicts'));
        }
        if ($action === 'import') {
            $file = $_FILES['sheet'] ?? null;
            $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if (!is_array($file) || $err === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Choose a .xlsx workbook or a .csv file.');
            }
            if ($err !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed (code ' . $err . ').');
            }
            $size = (int)($file['size'] ?? 0);
            if ($size < 1 || $size > 8 * 1024 * 1024) {
                throw new RuntimeException('File must be between 1 byte and 8 MB.');
            }
            $into = (int)($_POST['into_prefix_id'] ?? 0);
            $res = IpamService::importFile((string)$file['tmp_name'], (string)($file['name'] ?? 'import.csv'), [
                'prefix_id' => $into > 0 ? $into : 0,
            ]);
            if (class_exists('AuditService')) {
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'import', 'ipam', 0, [
                    'created' => $res['created'], 'updated' => $res['updated'],
                ]);
            }
            if (!empty($res['ok'])) {
                App::flash('success', (string)$res['message']);
            } else {
                App::flash('error', (string)($res['message'] ?? 'Import failed.'));
            }
            $errs = $res['errors'] ?? [];
            if (is_array($errs) && $errs !== []) {
                App::flash('error', implode(' ', array_slice($errs, 0, 8)) . (count($errs) > 8 ? ' …' : ''));
            }
            App::redirect('pages/ipam.php?view=import');
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/ipam.php' . ($prefixId ? '?prefix_id=' . $prefixId : ''));
}

$roles = IpamService::roles();
$statuses = IpamService::statuses();
$rooms = [];
try {
    $rooms = Database::fetchAll('SELECT room_id, name FROM rooms WHERE is_active = 1 ORDER BY name');
} catch (Throwable $e) {
}

if ($prefixId > 0 && strtolower((string)($_GET['export'] ?? '')) === 'csv') {
    $pfx = Database::fetchOne('SELECT * FROM ipam_prefixes WHERE prefix_id = ?', [$prefixId]);
    $rows = Database::fetchAll(
        'SELECT a.*, d.label AS device_label, p.name AS pdu_name, u.name AS ups_name
         FROM ipam_addresses a
         LEFT JOIN devices d ON d.device_id = a.device_id
         LEFT JOIN pdus p ON p.pdu_id = a.pdu_id
         LEFT JOIN ups_units u ON u.ups_id = a.ups_id
         WHERE a.prefix_id = ?
         ORDER BY a.ip_int, a.ip',
        [$prefixId]
    );
    $csv = [];
    foreach ($rows as $a) {
        $csv[] = [
            $a['ip'] ?? '',
            $a['hostname'] ?? '',
            $a['status'] ?? '',
            $a['mac_address'] ?? '',
            $a['description'] ?? '',
            $a['notes'] ?? '',
            $pfx['vlan_id'] ?? '',
            $pfx['gateway'] ?? '',
            $pfx['cidr'] ?? '',
            $a['device_label'] ?: $a['pdu_name'] ?: $a['ups_name'] ?: '',
        ];
    }
    if (class_exists('ListPager')) {
        ListPager::sendCsv('ipam-' . preg_replace('/[^\d.]+/', '-', (string)($pfx['cidr'] ?? 'prefix')) . '.csv', [
            'ip', 'hostname', 'status', 'mac', 'description', 'notes', 'vlan', 'gateway', 'cidr', 'linked',
        ], $csv);
    }
}

$like = $q !== '' ? ('%' . $q . '%') : '';
$prefixes = [];
try {
    if ($q !== '') {
        $prefixes = Database::fetchAll(
            'SELECT * FROM ipam_prefixes
             WHERE is_active = 1 AND (cidr LIKE ? OR ISNULL(name, \'\') LIKE ? OR ISNULL(description, \'\') LIKE ?
                OR CAST(vlan_id AS NVARCHAR(20)) = ? OR CAST(prefix_id AS NVARCHAR(20)) = ?)
             ORDER BY network_int, prefix_len',
            [$like, $like, $like, $q, $q]
        );
    } else {
        $prefixes = Database::fetchAll(
            'SELECT * FROM ipam_prefixes WHERE is_active = 1 ORDER BY network_int, prefix_len'
        );
    }
} catch (Throwable $e) {
    $prefixes = [];
}

$current = null;
if ($prefixId > 0) {
    $current = Database::fetchOne('SELECT * FROM ipam_prefixes WHERE prefix_id = ?', [$prefixId]);
    if (!$current) {
        App::flash('error', 'Prefix not found.');
        App::redirect('pages/ipam.php');
    }
}

$editAddress = null;
if ($addressId > 0) {
    $editAddress = Database::fetchOne('SELECT * FROM ipam_addresses WHERE address_id = ?', [$addressId]);
}

layout_header('IPAM', $user, 'ipam');
$keep = class_exists('SearchService') ? ['q' => $q] : [];
?>
<p class="text-muted" style="margin-top:0">
    Address <strong>plan</strong> for statics — one prefix per subnet (the tabs in your spreadsheet).
    Available space is counted, not stored as empty rows. DHCP is a range you mark “hands off,” not a server.
    <?php if ($canEdit): ?>
        <a href="<?= App::e(App::url('pages/ipam.php?view=import')) ?>">Import Excel or CSV</a>.
    <?php endif; ?>
</p>
<div class="flex-between mb-2" style="flex-wrap:wrap;gap:.5rem">
    <?php layout_search_form('Search CIDR, name, VLAN…', $q, 'pages/ipam.php', []); ?>
    <div class="flex gap-1" style="flex-wrap:wrap">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/ipam.php?view=conflicts')) ?>">Conflicts</a>
        <?php if ($canEdit): ?>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/ipam.php?view=import')) ?>">Import</a>
            <a class="btn btn-primary" href="<?= App::e(App::url('pages/ipam.php?view=prefix')) ?>">+ Prefix</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($view === 'import' && $canEdit): ?>
<div class="card">
    <div class="card-header"><h2>Import spreadsheet</h2></div>
    <div class="card-body docs-prose">
        <p>
            <strong>Excel (.xlsx):</strong> keep one workbook. Each <em>worksheet</em> (tab) is a subnet.
            Put the CIDR in the tab name (<code>10.12.40.0/24</code> or <code>ILO 10.12.40.0/24</code>)
            or in a Subnet/CIDR column. You do <strong>not</strong> need a CSV per tab.
        </p>
        <p>
            <strong>CSV:</strong> one subnet per file. Import into an existing prefix, or include a
            <code>cidr</code> column. <a href="<?= App::e(App::url('pages/ipam.php?export=csv_template')) ?>">Download column template</a>.
        </p>
        <p class="text-muted" style="font-size:.85rem">
            Columns (flexible names): IP, hostname/device, status, MAC, description, notes, VLAN, gateway, CIDR.
            Empty host rows are skipped (those IPs stay available). Gateway / reserved / DHCP in the status column are mapped automatically.
            Matching device, PDU, or UPS names are linked when unique. Format the IP column as text in Excel.
        </p>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="import">
            <div class="form-row full"><label>File</label>
                <input class="form-control" type="file" name="sheet" required
                       accept=".xlsx,.xlsm,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
            </div>
            <div class="form-row full"><label>Load into prefix (CSV, or force one sheet)</label>
                <select class="form-control" name="into_prefix_id">
                    <option value="">— Create/match from CIDR on each sheet —</option>
                    <?php foreach ($prefixes as $p): ?>
                        <option value="<?= (int)$p['prefix_id'] ?>">
                            <?= App::e((string)$p['cidr']) ?> · <?= App::e((string)($p['name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Import</button>
                <a class="btn btn-ghost" href="<?= App::e(App::url('pages/ipam.php')) ?>">Cancel</a></div>
        </form>
    </div>
</div>
<?php layout_footer();
    exit;
endif; ?>

<?php if ($view === 'conflicts'):
    $cf = IpamService::conflicts();
    ?>
<div class="card mb-2">
    <div class="card-header flex-between">
        <h2 style="margin:0">Conflicts &amp; orphans</h2>
        <?php if ($canEdit): ?>
        <form method="post" style="margin:0">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="reconcile">
            <button class="btn btn-secondary btn-sm" type="submit">Link inventory IPs into prefixes</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <h3 class="mt-0">Duplicate IPs</h3>
        <?php if (!$cf['duplicates']): ?>
            <p class="text-muted">None in IPAM.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($cf['duplicates'] as $d): ?>
                    <li><code><?= App::e((string)$d['ip']) ?></code>
                        <?= App::e((string)($d['detail'] ?? ($d['vrf'] . ' × ' . $d['n']))) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <h3>Inventory IPs not in any prefix</h3>
        <p class="text-muted" style="font-size:.85rem">Create the subnet, then use <strong>Link inventory</strong>.</p>
        <?php if (!$cf['orphans']): ?>
            <p class="text-muted">None — every documented device/PDU/UPS IP sits in a prefix.</p>
        <?php else: ?>
            <table class="data">
                <thead><tr><th>IP</th><th>Kind</th><th>Name</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($cf['orphans'], 0, 200) as $o):
                    $href = match ($o['kind']) {
                        'pdu' => App::url('pages/power_pdus.php?id=' . $o['id']),
                        'ups' => App::url('pages/power_ups.php?id=' . $o['id']),
                        default => App::url('pages/devices.php?id=' . $o['id']),
                    };
                    ?>
                    <tr>
                        <td><code><?= App::e($o['ip']) ?></code></td>
                        <td><?= App::e($o['kind']) ?></td>
                        <td><a href="<?= App::e($href) ?>"><?= App::e($o['label']) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($cf['orphans']) > 200): ?>
                <p class="text-muted">Showing 200 of <?= count($cf['orphans']) ?>.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer();
    exit;
endif; ?>

<?php if ($view === 'prefix' || ($current && isset($_GET['edit']))):
    $edit = ($view === 'prefix' && $prefixId < 1) ? [] : ($current ?? []);
    ?>
<div class="card">
    <div class="card-header"><h2><?= $edit ? 'Edit prefix' : 'New prefix' ?></h2></div>
    <div class="card-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="save_prefix">
            <?php if (!empty($edit['prefix_id'])): ?>
                <input type="hidden" name="prefix_id" value="<?= (int)$edit['prefix_id'] ?>">
            <?php endif; ?>
            <div class="form-row"><label>CIDR *</label>
                <input class="form-control" name="cidr" required placeholder="10.12.40.0/24"
                       value="<?= App::e((string)($edit['cidr'] ?? '')) ?>"></div>
            <div class="form-row"><label>Name</label>
                <input class="form-control" name="name" placeholder="ILO / iDRAC"
                       value="<?= App::e((string)($edit['name'] ?? '')) ?>"></div>
            <div class="form-row"><label>VLAN</label>
                <input class="form-control" type="number" min="1" max="4094" name="vlan_id"
                       value="<?= App::e((string)($edit['vlan_id'] ?? '')) ?>"></div>
            <div class="form-row"><label>Gateway</label>
                <input class="form-control" name="gateway" placeholder="10.12.40.1"
                       value="<?= App::e((string)($edit['gateway'] ?? '')) ?>"></div>
            <div class="form-row"><label>Role</label>
                <select class="form-control" name="role">
                    <option value="">—</option>
                    <?php foreach ($roles as $rk => $rl): ?>
                        <option value="<?= App::e($rk) ?>" <?= ($edit['role'] ?? '') === $rk ? 'selected' : '' ?>><?= App::e($rl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>VRF</label>
                <input class="form-control" name="vrf" value="<?= App::e((string)($edit['vrf'] ?? 'default')) ?>"></div>
            <div class="form-row"><label>DHCP pool start</label>
                <input class="form-control" name="dhcp_start" placeholder="Leave empty if all static"
                       value="<?= App::e((string)($edit['dhcp_start'] ?? '')) ?>"></div>
            <div class="form-row"><label>DHCP pool end</label>
                <input class="form-control" name="dhcp_end"
                       value="<?= App::e((string)($edit['dhcp_end'] ?? '')) ?>"></div>
            <div class="form-row"><label>Room</label>
                <select class="form-control" name="room_id">
                    <option value="">—</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= (int)$r['room_id'] ?>" <?= (int)($edit['room_id'] ?? 0) === (int)$r['room_id'] ? 'selected' : '' ?>>
                            <?= App::e($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row full"><label>Description</label>
                <input class="form-control" name="description" value="<?= App::e((string)($edit['description'] ?? '')) ?>"></div>
            <div class="form-row">
                <button class="btn btn-primary" type="submit">Save prefix</button>
                <a class="btn btn-ghost" href="<?= App::e(App::url('pages/ipam.php' . (!empty($edit['prefix_id']) ? '?prefix_id=' . (int)$edit['prefix_id'] : ''))) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php layout_footer();
    exit;
endif; ?>

<div class="split-2">
    <div class="card">
        <div class="card-header"><h2>Prefixes</h2></div>
        <div class="card-body flush">
            <table class="data">
                <thead>
                <tr><th>Prefix</th><th>VLAN</th><th>Role</th><th>Use</th></tr>
                </thead>
                <tbody>
                <?php foreach ($prefixes as $p):
                    $util = IpamService::utilization($p);
                    $activeRow = $current && (int)$current['prefix_id'] === (int)$p['prefix_id'];
                    ?>
                    <tr class="<?= $activeRow ? 'is-selected' : '' ?>">
                        <td>
                            <a href="<?= App::e(App::url('pages/ipam.php?prefix_id=' . (int)$p['prefix_id'])) ?>">
                                <code><?= App::e((string)$p['cidr']) ?></code>
                            </a>
                            <div class="text-muted" style="font-size:.75rem"><?= App::e((string)($p['name'] ?? '')) ?></div>
                        </td>
                        <td><?= !empty($p['vlan_id']) ? (int)$p['vlan_id'] : '—' ?></td>
                        <td><?= App::e($roles[$p['role'] ?? ''] ?? ($p['role'] ?? '—')) ?></td>
                        <td style="min-width:7rem">
                            <div class="ipam-bar" title="<?= (int)$util['used'] ?> assigned · <?= (int)$util['free'] ?> free of <?= (int)$util['usable'] ?>">
                                <span style="width:<?= min(100, (float)$util['pct']) ?>%"></span>
                            </div>
                            <span class="text-muted" style="font-size:.72rem"><?= App::e((string)$util['pct']) ?>%</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$prefixes): ?>
                    <tr><td colspan="4" class="text-muted">
                        No prefixes yet.
                        <?php if ($canEdit): ?>
                            <a href="<?= App::e(App::url('pages/ipam.php?view=import')) ?>">Import your workbook</a>
                            or <a href="<?= App::e(App::url('pages/ipam.php?view=prefix')) ?>">add a CIDR</a>.
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <?php if (!$current): ?>
            <div class="card-header"><h2>Addresses</h2></div>
            <div class="card-body">
                <p class="text-muted" style="margin:0">Select a prefix. Empty IPs are not listed — use <strong>Next free</strong> after you open one.</p>
            </div>
        <?php else:
            $util = IpamService::utilization($current);
            $next = IpamService::nextFree($current);
            $parsed = IpamService::parseCidr((string)$current['cidr']);
            $addrSql = 'SELECT a.*, d.label AS device_label, p.name AS pdu_name, u.name AS ups_name
                FROM ipam_addresses a
                LEFT JOIN devices d ON d.device_id = a.device_id
                LEFT JOIN pdus p ON p.pdu_id = a.pdu_id
                LEFT JOIN ups_units u ON u.ups_id = a.ups_id
                WHERE a.prefix_id = ?';
            $addrParams = [(int)$current['prefix_id']];
            if ($q !== '') {
                $addrSql .= ' AND (a.ip LIKE ? OR ISNULL(a.hostname, \'\') LIKE ? OR ISNULL(a.description, \'\') LIKE ?)';
                $addrParams[] = $like;
                $addrParams[] = $like;
                $addrParams[] = $like;
            }
            $addrSql .= ' ORDER BY a.ip_int, a.ip';
            $addresses = Database::fetchAll($addrSql, $addrParams);
            ?>
            <div class="card-header flex-between">
                <h2 style="margin:0"><code><?= App::e((string)$current['cidr']) ?></code>
                    <span class="text-muted" style="font-weight:500;font-size:.85rem"><?= App::e((string)($current['name'] ?? '')) ?></span>
                </h2>
                <div class="flex gap-1" style="flex-wrap:wrap">
                    <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/ipam.php?prefix_id=' . (int)$current['prefix_id'] . '&export=csv')) ?>">Export CSV</a>
                    <?php if ($canEdit): ?>
                        <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/ipam.php?prefix_id=' . (int)$current['prefix_id'] . '&edit=1')) ?>">Edit prefix</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body" style="padding-bottom:.5rem">
                <p class="text-muted" style="font-size:.85rem;margin:0 0 .5rem">
                    VLAN <?= !empty($current['vlan_id']) ? (int)$current['vlan_id'] : '—' ?>
                    · GW <?= App::e((string)($current['gateway'] ?? '—')) ?>
                    · <?= (int)$util['used'] ?> assigned · <?= (int)$util['reserved'] ?> reserved
                    · <?= (int)$util['free'] ?> free of <?= (int)$util['usable'] ?> usable
                    <?php if ($next): ?>
                        · Next free <code><?= App::e($next) ?></code>
                    <?php endif; ?>
                </p>
                <?php if ($canEdit): ?>
                <form method="post" class="form-grid" style="margin-bottom:.75rem">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_address">
                    <input type="hidden" name="prefix_id" value="<?= (int)$current['prefix_id'] ?>">
                    <?php if ($editAddress): ?>
                        <input type="hidden" name="address_id" value="<?= (int)$editAddress['address_id'] ?>">
                    <?php endif; ?>
                    <div class="form-row"><label>IP</label>
                        <input class="form-control" name="ip" required
                               value="<?= App::e((string)($editAddress['ip'] ?? $next ?? '')) ?>"></div>
                    <div class="form-row"><label>Status</label>
                        <select class="form-control" name="status">
                            <?php foreach ($statuses as $sk => $sl): ?>
                                <option value="<?= App::e($sk) ?>" <?= ($editAddress['status'] ?? 'assigned') === $sk ? 'selected' : '' ?>><?= App::e($sl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row"><label>Hostname / name</label>
                        <input class="form-control" name="hostname" value="<?= App::e((string)($editAddress['hostname'] ?? '')) ?>"></div>
                    <div class="form-row"><label>MAC</label>
                        <input class="form-control" name="mac_address" value="<?= App::e((string)($editAddress['mac_address'] ?? '')) ?>"></div>
                    <div class="form-row full"><label>Description</label>
                        <input class="form-control" name="description" value="<?= App::e((string)($editAddress['description'] ?? '')) ?>"></div>
                    <div class="form-row">
                        <button class="btn btn-primary" type="submit"><?= $editAddress ? 'Save address' : 'Add static' ?></button>
                        <?php if ($editAddress): ?>
                            <a class="btn btn-ghost" href="<?= App::e(App::url('pages/ipam.php?prefix_id=' . (int)$current['prefix_id'])) ?>">Cancel edit</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <div class="card-body flush">
                <table class="data">
                    <thead>
                    <tr><th>IP</th><th>Name</th><th>Status</th><th>Linked</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($addresses as $a):
                        $linked = $a['device_label'] ?: $a['pdu_name'] ?: $a['ups_name'] ?: '';
                        $lhref = '';
                        if (!empty($a['device_id'])) {
                            $lhref = App::url('pages/devices.php?id=' . (int)$a['device_id']);
                        } elseif (!empty($a['pdu_id'])) {
                            $lhref = App::url('pages/power_pdus.php?id=' . (int)$a['pdu_id']);
                        } elseif (!empty($a['ups_id'])) {
                            $lhref = App::url('pages/power_ups.php?id=' . (int)$a['ups_id']);
                        }
                        ?>
                        <tr>
                            <td><code><?= App::e((string)$a['ip']) ?></code></td>
                            <td>
                                <?= App::e((string)($a['hostname'] ?? '—')) ?>
                                <?php if (!empty($a['description'])): ?>
                                    <div class="text-muted" style="font-size:.75rem"><?= App::e((string)$a['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge"><?= App::e($statuses[$a['status'] ?? ''] ?? (string)($a['status'] ?? '')) ?></span></td>
                            <td>
                                <?php if ($lhref !== ''): ?>
                                    <a href="<?= App::e($lhref) ?>"><?= App::e($linked) ?></a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <?php if ($canEdit): ?>
                                    <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/ipam.php?prefix_id=' . (int)$current['prefix_id'] . '&address_id=' . (int)$a['address_id'])) ?>">Edit</a>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Remove this address from the plan?');">
                                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_address">
                                        <input type="hidden" name="prefix_id" value="<?= (int)$current['prefix_id'] ?>">
                                        <input type="hidden" name="address_id" value="<?= (int)$a['address_id'] ?>">
                                        <button class="btn btn-sm btn-danger" type="submit">×</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$addresses): ?>
                        <tr><td colspan="5" class="text-muted">No host records — the rest of the subnet is available.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canEdit): ?>
            <div class="card-body flex gap-1" style="flex-wrap:wrap">
                <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="reconcile">
                    <button class="btn btn-secondary btn-sm" type="submit">Link inventory IPs</button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this prefix and its address records?');">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_prefix">
                    <input type="hidden" name="prefix_id" value="<?= (int)$current['prefix_id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Delete prefix</button>
                </form>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<style>
.ipam-bar {
  height: .45rem; background: var(--surface-3); border-radius: 99px; overflow: hidden; min-width: 4.5rem;
}
.ipam-bar > span { display: block; height: 100%; background: #38bdf8; }
tr.is-selected td { background: var(--accent-dim); }
</style>
<?php layout_footer(); ?>
