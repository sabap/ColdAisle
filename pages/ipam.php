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
$canEdit = AuthManager::canEditIpam($user);
$isAdmin = AuthManager::isAdmin($user);

$prefixId = isset($_GET['prefix_id']) ? (int)$_GET['prefix_id'] : 0;
$addressId = isset($_GET['address_id']) ? (int)$_GET['address_id'] : 0;
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
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
        if ($action === 'clear_ipam') {
            if (!AuthManager::isAdmin($user)) {
                throw new RuntimeException('Only a Global Admin can clear all IPAM data.');
            }
            $typed = strtoupper(trim((string)($_POST['confirm_text'] ?? '')));
            if ($typed !== 'CLEAR') {
                throw new RuntimeException('Type CLEAR in the confirmation box to purge IPAM.');
            }
            $res = IpamService::purgeAll();
            if (class_exists('AuditService')) {
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'purge', 'ipam', 0, [
                    'prefixes' => $res['prefixes'],
                    'addresses' => $res['addresses'],
                ]);
            }
            App::flash('success', $res['message']);
            App::redirect('pages/ipam.php?view=import');
        }
        if ($action === 'import_cancel') {
            $id = (string)($_SESSION['ipam_import']['id'] ?? '');
            if ($id !== '') {
                IpamService::clearStagedImport($id);
            }
            unset($_SESSION['ipam_import']);
            App::flash('success', 'Import cancelled.');
            App::redirect('pages/ipam.php?view=import');
        }
        if ($action === 'import_apply') {
            $staged = $_SESSION['ipam_import'] ?? null;
            $id = is_array($staged) ? (string)($staged['id'] ?? '') : '';
            $path = $id !== '' ? IpamService::stagedImportPath($id) : null;
            $orig = is_array($staged) ? (string)($staged['name'] ?? 'import.xlsx') : 'import.xlsx';
            $into = is_array($staged) ? (int)($staged['into'] ?? 0) : 0;
            if ($path === null) {
                throw new RuntimeException('Upload expired. Choose the file again.');
            }
            $tracks = $_POST['sheet_track'] ?? [];
            if (!is_array($tracks)) {
                $tracks = [];
            }
            $clean = [];
            foreach ($tracks as $idx => $mode) {
                $mode = strtolower(trim((string)$mode));
                if (!in_array($mode, ['hosts', 'subnets', 'skip'], true)) {
                    $mode = 'skip';
                }
                $clean[(int)$idx] = $mode;
            }
            $res = IpamService::importFile($path, $orig, [
                'prefix_id' => $into > 0 ? $into : 0,
                'sheet_tracks' => $clean,
            ]);
            IpamService::clearStagedImport($id);
            unset($_SESSION['ipam_import']);
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
            App::redirect('pages/ipam.php');
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
            $orig = (string)($file['name'] ?? 'import.csv');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if ($ext === 'xlsx' || $ext === 'xlsm') {
                $old = (string)($_SESSION['ipam_import']['id'] ?? '');
                if ($old !== '') {
                    IpamService::clearStagedImport($old);
                }
                $st = IpamService::stageImportFile((string)$file['tmp_name'], $orig);
                $_SESSION['ipam_import'] = [
                    'id' => $st['id'],
                    'name' => $st['name'],
                    'into' => $into,
                ];
                App::redirect('pages/ipam.php?view=import');
            }
            $track = strtolower(trim((string)($_POST['track'] ?? 'auto')));
            if (!in_array($track, ['auto', 'hosts', 'subnets'], true)) {
                $track = 'auto';
            }
            $res = IpamService::importFile((string)$file['tmp_name'], $orig, [
                'prefix_id' => $into > 0 ? $into : 0,
                'track' => $track,
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
        if ($action === 'save_align_group') {
            $gid = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
            $res = IpamService::saveAlignGroup($_POST, $gid);
            if (class_exists('AuditService')) {
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'save', 'ipam_align', (int)$res['group_id'], [
                    'name' => (string)($_POST['name'] ?? ''),
                ]);
            }
            App::flash('success', $res['message']);
            App::redirect('pages/ipam.php?view=aligned&group_id=' . (int)$res['group_id']);
        }
        if ($action === 'delete_align_group') {
            $gid = (int)($_POST['group_id'] ?? 0);
            $res = IpamService::deleteAlignGroup($gid);
            if (class_exists('AuditService')) {
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'delete', 'ipam_align', $gid, []);
            }
            App::flash('success', $res['message']);
            App::redirect('pages/ipam.php?view=aligned');
        }
        if ($action === 'assign_align_slot') {
            $gid = (int)($_POST['group_id'] ?? 0);
            $res = IpamService::assignAlignSlot(
                $gid,
                (int)($_POST['idx'] ?? 0),
                (string)($_POST['hostname'] ?? ''),
                (string)($_POST['notes'] ?? '')
            );
            if (class_exists('AuditService')) {
                AuditService::log((int)$user['user_id'], $user['username'] ?? '', 'assign', 'ipam_align', $gid, [
                    'idx' => (int)($_POST['idx'] ?? 0),
                    'hostname' => (string)($_POST['hostname'] ?? ''),
                ]);
            }
            App::flash('success', $res['message']);
            $u = !empty($_POST['unused']) ? '&unused=1' : '';
            App::redirect('pages/ipam.php?view=aligned&group_id=' . $gid . $u);
        }
        if ($action === 'clear_align_slot') {
            $gid = (int)($_POST['group_id'] ?? 0);
            $res = IpamService::clearAlignSlot($gid, (int)($_POST['idx'] ?? 0));
            App::flash('success', $res['message']);
            $u = !empty($_POST['unused']) ? '&unused=1' : '';
            App::redirect('pages/ipam.php?view=aligned&group_id=' . $gid . $u);
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    if (str_contains($action, 'align')) {
        $gid = (int)($_POST['group_id'] ?? $groupId);
        App::redirect('pages/ipam.php?view=aligned' . ($gid > 0 ? '&group_id=' . $gid : ''));
    }
    App::redirect('pages/ipam.php' . ($prefixId ? '?prefix_id=' . $prefixId : ''));
}

$roles = IpamService::roles();
$tracks = IpamService::tracks();
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

$allPrefixes = [];
try {
    $allPrefixes = Database::fetchAll(
        'SELECT * FROM ipam_prefixes WHERE is_active = 1 ORDER BY network_int, prefix_len'
    );
} catch (Throwable $e) {
    $allPrefixes = [];
}
$childrenByParent = [];
$prefixesById = [];
foreach ($allPrefixes as $p) {
    $pp = (int)($p['parent_id'] ?? 0);
    $childrenByParent[$pp][] = $p;
    $prefixesById[(int)$p['prefix_id']] = $p;
}
$prefixes = $childrenByParent[0] ?? [];

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
?>
<p class="text-muted" style="margin-top:0">
    Two kinds of prefix: an <strong>address plan</strong> (individual IPs) or a <strong>subnet plan</strong> (a container you carve into smaller prefixes).
    Nesting is optional: set <strong>Parent</strong> when you add a prefix (for example a site /21, then VLAN /24s under it).
    <a href="<?= App::e(App::url('pages/ipam.php?view=aligned')) ?>">Aligned groups</a>
    pin the same host index across two or more prefixes (multi-homed WAN, or LAN + iDRAC).
    How it is designed:
    <a href="<?= App::e(App::url('pages/docs.php#ipam')) ?>">Documentation → IPAM</a>.
    DHCP on an address plan is a range fence, not a server.
    <?php if ($canEdit): ?>
        <a href="<?= App::e(App::url('pages/ipam.php?view=import')) ?>">Import Excel or CSV</a>.
    <?php endif; ?>
</p>
<div class="flex-between mb-2" style="flex-wrap:wrap;gap:.5rem">
    <?php layout_search_form('Search CIDR, name, VLAN…', $q, 'pages/ipam.php', [], [
        'rows' => '#ipamPrefixBody tr.ipam-prefix-row',
        'empty' => '#ipamPrefixEmpty',
        'count' => '#ipamPrefixCount',
    ]); ?>
    <div class="flex gap-1" style="flex-wrap:wrap">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/ipam.php?view=conflicts')) ?>">Conflicts</a>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/ipam.php?view=aligned')) ?>" data-tour="ipam-aligned">Aligned</a>
        <?php if ($canEdit): ?>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/ipam.php?view=import')) ?>">Import</a>
            <a class="btn btn-primary" href="<?= App::e(App::url('pages/ipam.php?view=prefix')) ?>">+ Prefix</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($view === 'import' && $canEdit):
    $staged = $_SESSION['ipam_import'] ?? null;
    $stagedId = is_array($staged) ? (string)($staged['id'] ?? '') : '';
    $stagedPath = $stagedId !== '' ? IpamService::stagedImportPath($stagedId) : null;
    $sheetPreview = [];
    if ($stagedPath) {
        try {
            $sheetPreview = IpamService::previewWorkbook($stagedPath);
        } catch (Throwable $e) {
            $sheetPreview = [];
            App::flash('error', 'Could not read the staged workbook: ' . $e->getMessage());
            IpamService::clearStagedImport($stagedId);
            unset($_SESSION['ipam_import']);
            $stagedPath = null;
        }
    }
    ?>
<div class="card">
    <div class="card-header"><h2>Import spreadsheet</h2></div>
    <div class="card-body docs-prose">
        <?php if ($stagedPath && $sheetPreview !== []): ?>
            <p>
                <strong><?= App::e((string)($staged['name'] ?? 'workbook.xlsx')) ?></strong>
                has <?= count($sheetPreview) ?> worksheet(s). Set <strong>Address plan</strong> (host IPs)
                or <strong>Subnet plan</strong> (prefixes only) on each tab — mixed files are expected.
            </p>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="import_apply">
                <p class="text-muted" style="font-size:.85rem">
                    Guess is from columns only. Skip legends and junk tabs.
                    <button type="button" class="btn btn-ghost btn-sm" onclick="ipamSetTracks('hosts')">All address</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="ipamSetTracks('subnets')">All subnet</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="ipamSetTracks('skip')">All skip</button>
                </p>
                <table class="data">
                    <thead>
                    <tr><th>Worksheet</th><th>Rows</th><th>Guess</th><th>Import as</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sheetPreview as $sh):
                        $guess = (string)$sh['guess'];
                        ?>
                        <tr>
                            <td>
                                <?= App::e((string)$sh['name']) ?>
                                <div class="text-muted" style="font-size:.75rem"><?= App::e((string)$sh['hint']) ?></div>
                            </td>
                            <td><?= (int)$sh['rows'] ?></td>
                            <td><?= $guess === 'subnets' ? 'Subnet plan' : ($guess === 'skip' ? 'Skip' : 'Address plan') ?></td>
                            <td>
                                <select class="form-control ipam-sheet-track" name="sheet_track[<?= (int)$sh['index'] ?>]">
                                    <option value="hosts" <?= $guess === 'hosts' ? 'selected' : '' ?>>Address plan</option>
                                    <option value="subnets" <?= $guess === 'subnets' ? 'selected' : '' ?>>Subnet plan</option>
                                    <option value="skip" <?= $guess === 'skip' ? 'selected' : '' ?>>Skip</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="flex gap-1" style="margin-top:.85rem;flex-wrap:wrap">
                    <button class="btn btn-primary" type="submit">Import selected worksheets</button>
                    <button class="btn btn-ghost" type="submit" name="action" value="import_cancel">Cancel</button>
                </div>
            </form>
            <script>
            function ipamSetTracks(v) {
                document.querySelectorAll('select.ipam-sheet-track').forEach(function (el) { el.value = v; });
            }
            </script>
        <?php else: ?>
        <p>
            <strong>Excel (.xlsx):</strong> one workbook, any mix of host tabs and prefix-list tabs.
            After upload you will set Address plan or Subnet plan <em>per worksheet</em>.
            An optional parent/supernet column on a subnet tab nests smaller blocks under a larger one.
        </p>
        <p>
            <strong>CSV:</strong> one subnet per file. Import into an existing prefix, or include a
            <code>cidr</code> column. <a href="<?= App::e(App::url('pages/ipam.php?export=csv_template')) ?>">Download column template</a>.
        </p>
        <p class="text-muted" style="font-size:.85rem">
            Address-plan columns: IP, hostname/device, status, MAC, description, notes, VLAN, gateway, CIDR.
            Subnet-plan columns: network/CIDR (or mask), name, VLAN, optional parent/supernet.
            Empty host rows are skipped. Format the IP column as text in Excel.
        </p>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="import">
            <div class="form-row full"><label>File</label>
                <input class="form-control" type="file" name="sheet" required
                       accept=".xlsx,.xlsm,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
            </div>
            <div class="form-row full"><label>CSV tracking (Excel chooses per tab next)</label>
                <select class="form-control" name="track">
                    <option value="auto">Auto</option>
                    <option value="hosts">Address plan — each row is an IP</option>
                    <option value="subnets">Subnet plan — each row is a prefix</option>
                </select>
            </div>
            <div class="form-row full"><label>Load into prefix (optional parent)</label>
                <select class="form-control" name="into_prefix_id">
                    <option value="">— Create/match from CIDR on each sheet —</option>
                    <?php foreach ($prefixes as $p): ?>
                        <option value="<?= (int)$p['prefix_id'] ?>">
                            <?= App::e((string)$p['cidr']) ?> · <?= App::e((string)($p['name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><button class="btn btn-primary" type="submit">Continue</button>
                <a class="btn btn-ghost" href="<?= App::e(App::url('pages/ipam.php')) ?>">Cancel</a></div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php if ($isAdmin): ?>
<div class="card" style="margin-top:1rem;border-color:rgba(248,113,113,.45)">
    <div class="card-header"><h2>Clear all IPAM</h2></div>
    <div class="card-body docs-prose">
        <p>
            Global Admin only. Deletes <strong>every prefix, host record, and aligned group</strong> so you can re-import from a spreadsheet.
            Cabinets, devices, PDUs, and UPS are not touched.
        </p>
        <form method="post" class="form-grid" onsubmit="return ipamConfirmPurge(this);">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="clear_ipam">
            <div class="form-row full"><label>Type <code>CLEAR</code> to confirm</label>
                <input class="form-control" name="confirm_text" autocomplete="off" spellcheck="false"
                       placeholder="CLEAR"></div>
            <div class="form-row">
                <button class="btn btn-danger" type="submit">Purge all prefixes and addresses</button>
            </div>
        </form>
        <script>
        function ipamConfirmPurge(form) {
            var typed = ((form.confirm_text && form.confirm_text.value) || '').trim().toUpperCase();
            if (typed !== 'CLEAR') {
                alert('Type CLEAR in the box to confirm.');
                return false;
            }
            return confirm('Permanently delete ALL IPAM prefixes and address records? This cannot be undone. Inventory (devices, PDUs, UPS) is kept.');
        }
        </script>
    </div>
</div>
<?php endif; ?>
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

<?php if ($view === 'aligned'):
    $showUnused = isset($_GET['unused']) && (string)$_GET['unused'] !== '0' && (string)$_GET['unused'] !== '';
    $alignGroups = IpamService::alignGroups();
    $alignGroup = $groupId > 0 ? IpamService::alignGroup($groupId) : null;
    if ($groupId > 0 && !$alignGroup) {
        $groupId = 0;
        $alignGroup = null;
    }
    $alignForm = $alignGroup ?? [];
    $alignMembers = $alignGroup ? IpamService::alignMembers($groupId) : [];
    $openNew = isset($_GET['new']);
    $openEdit = $alignGroup && isset($_GET['edit']);
    $hostPrefixes = $allPrefixes;
    ?>
<?php if ($alignGroup && !$openEdit):
    $grid = IpamService::alignGrid($groupId, $showUnused);
    $members = $grid['members'];
    $nextIdx = $grid['next'];
    ?>
<div class="card mb-2">
    <div class="card-header flex-between" style="flex-wrap:wrap;gap:.5rem">
        <h2 style="margin:0"><?= App::e((string)$alignGroup['name']) ?>
            <span class="text-muted" style="font-weight:500;font-size:.85rem">index <?= (int)$alignGroup['idx_from'] ?>–<?= (int)$alignGroup['idx_to'] ?></span>
        </h2>
        <div class="flex gap-1" style="flex-wrap:wrap">
            <a class="btn btn-sm btn-ghost" href="<?= App::e(App::url('pages/ipam.php?view=aligned')) ?>">All groups</a>
            <?php if ($showUnused): ?>
                <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/ipam.php?view=aligned&group_id=' . $groupId)) ?>">Hide unused</a>
            <?php else: ?>
                <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/ipam.php?view=aligned&group_id=' . $groupId . '&unused=1')) ?>">Show unused</a>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/ipam.php?view=aligned&group_id=' . $groupId . '&edit=1')) ?>">Edit group</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body docs-prose">
        <?php if (!empty($alignGroup['description'])): ?>
            <p><?= App::e((string)$alignGroup['description']) ?></p>
        <?php endif; ?>
        <p class="text-muted" style="margin-bottom:<?= $canEdit ? '.5rem' : '0' ?>">
            Index is the offset from each prefix’s network address.
            On a /24 that is the last octet — index 10 is
            <?php
            $ex = [];
            foreach (array_slice($members, 0, 3) as $m) {
                $ip = IpamService::ipAtOffset($m, 10);
                if ($ip) {
                    $ex[] = $ip;
                }
            }
            echo $ex ? '<code>' . App::e(implode(', ', $ex)) . '</code>' : 'the same last octet on every member';
            ?>.
            Assign once; every member prefix gets that host record.
        </p>
        <?php if ($canEdit): ?>
            <?php if (count($members) < 2): ?>
                <p class="text-muted">Add at least two prefixes on <a href="<?= App::e(App::url('pages/ipam.php?view=aligned&group_id=' . $groupId . '&edit=1')) ?>">Edit group</a> before assigning.</p>
            <?php else: ?>
            <form method="post" class="form-grid" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="assign_align_slot">
                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                <?php if ($showUnused): ?><input type="hidden" name="unused" value="1"><?php endif; ?>
                <div class="form-row"><label>Index</label>
                    <input class="form-control" type="number" name="idx" required min="<?= (int)$alignGroup['idx_from'] ?>" max="<?= (int)$alignGroup['idx_to'] ?>"
                           value="<?= $nextIdx !== null ? (int)$nextIdx : (int)$alignGroup['idx_from'] ?>"></div>
                <div class="form-row"><label>Site / hostname</label>
                    <input class="form-control" name="hostname" required placeholder="site-01"></div>
                <div class="form-row"><label>Notes</label>
                    <input class="form-control" name="notes" placeholder="optional"></div>
                <div class="form-row">
                    <button class="btn btn-primary" type="submit">Assign on all prefixes</button>
                </div>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="card-body flush" style="overflow:auto">
        <?php if (!$members): ?>
            <p class="text-muted" style="padding:.85rem">No member prefixes yet.</p>
        <?php else: ?>
        <table class="data">
            <thead>
            <tr>
                <th>Index</th>
                <th>Site</th>
                <?php foreach ($members as $m): ?>
                    <th>
                        <?= App::e((string)($m['label'] ?: $m['cidr'])) ?>
                        <div class="text-muted" style="font-weight:400;font-size:.72rem">
                            <a href="<?= App::e(App::url('pages/ipam.php?prefix_id=' . (int)$m['prefix_id'])) ?>"><?= App::e((string)$m['cidr']) ?></a>
                        </div>
                    </th>
                <?php endforeach; ?>
                <?php if ($canEdit): ?><th></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($grid['rows'] as $row):
                $isNext = !$row['used'] && $nextIdx !== null && (int)$row['idx'] === (int)$nextIdx;
                ?>
                <tr class="<?= $row['used'] ? '' : 'ipam-align-free' ?><?= !empty($row['mismatch']) ? ' ipam-align-mismatch' : '' ?>">
                    <td><code><?= (int)$row['idx'] ?></code></td>
                    <td>
                        <?= $row['hostname'] !== '' ? App::e((string)$row['hostname']) : ($isNext ? '<span class="text-muted">next free</span>' : '—') ?>
                        <?php if (!empty($row['mismatch'])): ?>
                            <div class="text-muted" style="font-size:.72rem">Hostnames differ across prefixes</div>
                        <?php endif; ?>
                        <?php if (!empty($row['notes'])): ?>
                            <div class="text-muted" style="font-size:.72rem"><?= App::e((string)$row['notes']) ?></div>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($row['cells'] as $cell): ?>
                        <td>
                            <?php if ($cell['ip']): ?>
                                <code><?= App::e((string)$cell['ip']) ?></code>
                                <?php if (!empty($cell['status']) && $cell['status'] !== 'assigned'): ?>
                                    <div class="text-muted" style="font-size:.72rem"><?= App::e((string)($statuses[$cell['status']] ?? $cell['status'])) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">out of range</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <?php if ($canEdit): ?>
                        <td style="white-space:nowrap">
                            <?php if ($row['used']): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Clear index <?= (int)$row['idx'] ?> on every member prefix?');">
                                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                    <input type="hidden" name="action" value="clear_align_slot">
                                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                    <input type="hidden" name="idx" value="<?= (int)$row['idx'] ?>">
                                    <?php if ($showUnused): ?><input type="hidden" name="unused" value="1"><?php endif; ?>
                                    <button class="btn btn-sm btn-ghost" type="submit">Clear</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$grid['rows']): ?>
                <tr><td colspan="<?= 2 + count($members) + ($canEdit ? 1 : 0) ?>" class="text-muted">No indexes in this range.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<style>
.ipam-align-free td { color: var(--muted); }
.ipam-align-mismatch td { background: rgba(250, 204, 21, .12); }
</style>
<?php layout_footer();
    exit;
endif; ?>

<div class="split-2">
    <div class="card">
        <div class="card-header flex-between">
            <h2 style="margin:0">Aligned groups</h2>
            <?php if ($canEdit && !$openNew): ?>
                <a class="btn btn-sm btn-primary" href="<?= App::e(App::url('pages/ipam.php?view=aligned&new=1')) ?>">+ Group</a>
            <?php endif; ?>
        </div>
        <div class="card-body flush">
            <table class="data">
                <thead>
                <tr><th>Name</th><th>Prefixes</th><th>Range</th></tr>
                </thead>
                <tbody>
                <?php foreach ($alignGroups as $g):
                    $gm = IpamService::alignMembers((int)$g['group_id']);
                    $labs = [];
                    foreach ($gm as $m) {
                        $labs[] = (string)($m['label'] ?: $m['cidr']);
                    }
                    ?>
                    <tr class="<?= $groupId === (int)$g['group_id'] ? 'is-selected' : '' ?>">
                        <td>
                            <a href="<?= App::e(App::url('pages/ipam.php?view=aligned&group_id=' . (int)$g['group_id'])) ?>">
                                <?= App::e((string)$g['name']) ?></a>
                            <?php if (!empty($g['description'])): ?>
                                <div class="text-muted" style="font-size:.75rem"><?= App::e((string)$g['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted" style="font-size:.85rem"><?= $labs ? App::e(implode(' · ', $labs)) : '—' ?></td>
                        <td class="text-muted"><?= (int)$g['idx_from'] ?>–<?= (int)$g['idx_to'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$alignGroups): ?>
                    <tr><td colspan="3" class="text-muted">None yet. Create a group, add the prefixes, then assign a site to an index.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <?php if ($canEdit && ($openNew || $openEdit)):
            $memberSlots = $alignMembers;
            while (count($memberSlots) < count($alignMembers) + 3) {
                $memberSlots[] = ['prefix_id' => 0, 'label' => ''];
            }
            if (!$memberSlots) {
                $memberSlots = array_fill(0, 3, ['prefix_id' => 0, 'label' => '']);
            }
            ?>
            <div class="card-header"><h2><?= $openEdit ? 'Edit group' : 'New aligned group' ?></h2></div>
            <div class="card-body">
                <p class="text-muted" style="font-size:.85rem;margin-top:0">
                    Same host index on every member prefix. Example: <code>10.10.0.0/24</code>,
                    <code>10.10.1.0/24</code>, <code>10.10.2.0/24</code> — a site at index 10
                    receives <code>.10</code> on each.
                </p>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_align_group">
                    <?php if ($openEdit): ?>
                        <input type="hidden" name="group_id" value="<?= $groupId ?>">
                    <?php endif; ?>
                    <div class="form-row"><label>Name *</label>
                        <input class="form-control" name="name" required placeholder="Aligned WAN"
                               value="<?= App::e((string)($alignForm['name'] ?? '')) ?>"></div>
                    <div class="form-row"><label>VRF</label>
                        <input class="form-control" name="vrf" value="<?= App::e((string)($alignForm['vrf'] ?? 'default')) ?>"></div>
                    <div class="form-row"><label>Index from</label>
                        <input class="form-control" type="number" name="idx_from" min="0" max="254"
                               value="<?= (int)($alignForm['idx_from'] ?? 1) ?>"></div>
                    <div class="form-row"><label>Index to</label>
                        <input class="form-control" type="number" name="idx_to" min="0" max="254"
                               value="<?= (int)($alignForm['idx_to'] ?? 254) ?>"></div>
                    <div class="form-row full"><label>Description</label>
                        <input class="form-control" name="description"
                               placeholder="Same last octet on each member prefix"
                               value="<?= App::e((string)($alignForm['description'] ?? '')) ?>"></div>
                    <div class="form-row full">
                        <label>Member prefixes (one column each)</label>
                        <table class="data" id="ipamAlignMembers">
                            <thead><tr><th>Prefix</th><th>Column label</th></tr></thead>
                            <tbody>
                            <?php foreach ($memberSlots as $slot): ?>
                                <tr>
                                    <td>
                                        <select class="form-control" name="prefix_id[]">
                                            <option value="">—</option>
                                            <?php foreach ($hostPrefixes as $hp): ?>
                                                <option value="<?= (int)$hp['prefix_id'] ?>" <?= (int)($slot['prefix_id'] ?? 0) === (int)$hp['prefix_id'] ? 'selected' : '' ?>>
                                                    <?= App::e((string)$hp['cidr']) ?><?php if (!empty($hp['name'])): ?> · <?= App::e((string)$hp['name']) ?><?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input class="form-control" name="member_label[]" placeholder="Provider A"
                                               value="<?= App::e((string)($slot['label'] ?? '')) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="text-muted" style="font-size:.8rem;margin:.4rem 0 0">
                            Empty rows are ignored. Saving replaces the member list; host records stay. Address-plan prefixes are typical.
                        </p>
                    </div>
                    <div class="form-row">
                        <button class="btn btn-primary" type="submit">Save group</button>
                        <a class="btn btn-ghost" href="<?= App::e(App::url('pages/ipam.php?view=aligned' . ($openEdit ? '&group_id=' . $groupId : ''))) ?>">Cancel</a>
                    </div>
                </form>
                <?php if ($openEdit): ?>
                    <form method="post" style="margin-top:1rem" onsubmit="return confirm('Remove this aligned group? Host IP records are kept.');">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="delete_align_group">
                        <input type="hidden" name="group_id" value="<?= $groupId ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">Delete group</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card-header"><h2>What this is</h2></div>
            <div class="card-body docs-prose">
                <p>
                    When several prefixes should share a memorable host number, put them in one group.
                    Assigning a site to index <strong>N</strong> writes that hostname on every member prefix at offset N.
                </p>
                <ul>
                    <li><strong>Multi-homed WAN:</strong> one /24 per provider; each site uses the same last octet so equal-cost paths are obvious.</li>
                    <li><strong>Server + iDRAC:</strong> production VLAN and OOB VLAN keep the same last octet per host.</li>
                </ul>
                <p class="text-muted" style="margin-bottom:0">
                    Create the prefixes first (import or + Prefix), then the group. This is not a parent/child subnet tree.
                    Index is the offset from each network address (last octet on a /24). Design and assign flow:
                    <a href="<?= App::e(App::url('pages/docs.php#ipam')) ?>">Documentation → IPAM</a>.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>
<style>
tr.is-selected td { background: var(--accent-dim); }
</style>
<?php layout_footer();
    exit;
endif; ?>

<?php if ($view === 'prefix' || ($current && isset($_GET['edit']))):
    $edit = ($view === 'prefix' && $prefixId < 1) ? [] : ($current ?? []);
    if ($view === 'prefix' && $prefixId < 1) {
        if (!empty($_GET['parent_id'])) {
            $edit['parent_id'] = (int)$_GET['parent_id'];
        }
        if (!empty($_GET['track'])) {
            $edit['track'] = (string)$_GET['track'];
        } elseif (!empty($edit['parent_id'])) {
            $edit['track'] = 'subnets';
        }
    }
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
                       value="<?= App::e((string)($edit['cidr'] ?? $_GET['cidr'] ?? '')) ?>"></div>
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
            <div class="form-row full"><label>Tracking</label>
                <select class="form-control" name="track">
                    <?php foreach ($tracks as $tk => $tl): ?>
                        <option value="<?= App::e($tk) ?>" <?= ($edit['track'] ?? 'hosts') === $tk ? 'selected' : '' ?>><?= App::e($tl) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="text-muted" style="font-size:.8rem">Address plan stores host records. Subnet plan is a container (no host list).</span>
            </div>
            <div class="form-row full"><label>Parent prefix</label>
                <select class="form-control" name="parent_id">
                    <option value="">— Top level —</option>
                    <?php foreach ($allPrefixes as $pp):
                        if (!empty($edit['prefix_id']) && (int)$pp['prefix_id'] === (int)$edit['prefix_id']) {
                            continue;
                        }
                        ?>
                        <option value="<?= (int)$pp['prefix_id'] ?>" <?= (int)($edit['parent_id'] ?? 0) === (int)$pp['prefix_id'] ? 'selected' : '' ?>>
                            <?= App::e((string)$pp['cidr']) ?>
                            <?php if (!empty($pp['name'])): ?> · <?= App::e((string)$pp['name']) ?><?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="text-muted" style="font-size:.8rem">Optional. Example: a site /21 as parent, VLAN /24s as children.</span>
            </div>
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
        <div class="card-header flex-between">
            <h2 style="margin:0">Prefixes <span id="ipamPrefixCount" class="text-muted" style="font-weight:500;font-size:.8rem"></span></h2>
        </div>
        <div class="card-body flush">
            <table class="data">
                <thead>
                <tr><th>Prefix</th><th>VLAN</th><th>Role</th><th>Use</th></tr>
                </thead>
                <tbody id="ipamPrefixBody">
                <?php foreach ($prefixes as $p):
                    $util = IpamService::utilization($p);
                    $kids = $childrenByParent[(int)$p['prefix_id']] ?? [];
                    $curId = $current ? (int)$current['prefix_id'] : 0;
                    $rootId = $current ? IpamService::rootPrefixId($current, $prefixesById) : 0;
                    $activeRow = $curId === (int)$p['prefix_id'] || $rootId === (int)$p['prefix_id'];
                    $hayBits = [
                        (string)($p['cidr'] ?? ''),
                        (string)($p['name'] ?? ''),
                        (string)($p['description'] ?? ''),
                        (string)($p['gateway'] ?? ''),
                        (string)($p['role'] ?? ''),
                        (string)($p['vlan_id'] ?? ''),
                        (string)($p['vrf'] ?? ''),
                        (string)($p['track'] ?? ''),
                        IpamService::descendantHaystack((int)$p['prefix_id'], $childrenByParent),
                    ];
                    $hay = strtolower(trim(implode(' ', $hayBits)));
                    $subnetPlan = IpamService::isSubnetTrack($p);
                    ?>
                    <tr class="ipam-prefix-row<?= $activeRow ? ' is-selected' : '' ?>" data-haystack="<?= App::e($hay) ?>">
                        <td>
                            <a href="<?= App::e(App::url('pages/ipam.php?prefix_id=' . (int)$p['prefix_id'])) ?>">
                                <code><?= App::e((string)$p['cidr']) ?></code>
                            </a>
                            <div class="text-muted" style="font-size:.75rem"><?= App::e((string)($p['name'] ?? '')) ?></div>
                            <?php if ($subnetPlan): ?>
                                <span class="badge" title="Subnet plan">Subnets</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($p['vlan_id']) ? (int)$p['vlan_id'] : '—' ?></td>
                        <td><?= App::e($roles[$p['role'] ?? ''] ?? ($p['role'] ?? '—')) ?></td>
                        <td style="min-width:7rem">
                            <?php if ($subnetPlan): ?>
                                <div class="ipam-bar" title="<?= count($kids) ?> child prefix(es) · <?= (int)$util['pct'] ?>% of block allocated">
                                    <span style="width:<?= min(100, (float)$util['pct']) ?>%"></span>
                                </div>
                                <span class="text-muted" style="font-size:.72rem"><?= count($kids) ?> nested</span>
                            <?php else: ?>
                                <div class="ipam-bar" title="<?= (int)$util['used'] ?> assigned · <?= (int)$util['free'] ?> free of <?= (int)$util['usable'] ?>">
                                    <span style="width:<?= min(100, (float)$util['pct']) ?>%"></span>
                                </div>
                                <span class="text-muted" style="font-size:.72rem"><?= App::e((string)$util['pct']) ?>%</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr id="ipamPrefixEmpty" hidden>
                    <td colspan="4" class="text-muted">No prefixes match that search.</td>
                </tr>
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
            <div class="card-header"><h2>Detail</h2></div>
            <div class="card-body">
                <p class="text-muted" style="margin:0">Select a prefix. Address plans list host records. Subnet plans list nested prefixes.</p>
            </div>
        <?php else:
            $util = IpamService::utilization($current);
            $next = IpamService::nextFree($current);
            $parsed = IpamService::parseCidr((string)$current['cidr']);
            $subnetPlan = IpamService::isSubnetTrack($current);
            $kids = $childrenByParent[(int)$current['prefix_id']] ?? [];
            $ancestors = IpamService::ancestorChain($current, $prefixesById);
            $addresses = [];
            if (!$subnetPlan) {
                $addresses = Database::fetchAll(
                    'SELECT a.*, d.label AS device_label, p.name AS pdu_name, u.name AS ups_name
                    FROM ipam_addresses a
                    LEFT JOIN devices d ON d.device_id = a.device_id
                    LEFT JOIN pdus p ON p.pdu_id = a.pdu_id
                    LEFT JOIN ups_units u ON u.ups_id = a.ups_id
                    WHERE a.prefix_id = ?
                    ORDER BY a.ip_int, a.ip',
                    [(int)$current['prefix_id']]
                );
            }
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
                <?php if ($ancestors): ?>
                    <p style="margin:0 0 .45rem;font-size:.85rem">
                        <?php foreach ($ancestors as $anc): ?>
                            <a href="<?= App::e(App::url('pages/ipam.php?prefix_id=' . (int)$anc['prefix_id'])) ?>"><?= App::e((string)$anc['cidr']) ?></a>
                            ›
                        <?php endforeach; ?>
                        <?= App::e((string)$current['cidr']) ?>
                    </p>
                <?php endif; ?>
                <p class="text-muted" style="font-size:.85rem;margin:0 0 .5rem">
                    <?php if ($subnetPlan): ?>
                        Subnet plan
                        · <?= count($kids) ?> nested prefix(es)
                        · <?= App::e((string)$util['pct']) ?>% of this block allocated
                        <?php if (!empty($current['vlan_id'])): ?>
                            · VLAN <?= (int)$current['vlan_id'] ?>
                        <?php endif; ?>
                    <?php else: ?>
                        VLAN <?= !empty($current['vlan_id']) ? (int)$current['vlan_id'] : '—' ?>
                        · GW <?= App::e((string)($current['gateway'] ?? '—')) ?>
                        · <?= (int)$util['used'] ?> assigned · <?= (int)$util['reserved'] ?> reserved
                        · <?= (int)$util['free'] ?> free of <?= (int)$util['usable'] ?> usable
                        <?php if ($next): ?>
                            · Next free <code><?= App::e($next) ?></code>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
                <?php if ($subnetPlan):
                    $carveLen = isset($_GET['len']) ? (int)$_GET['len'] : 24;
                    if ($carveLen < 8 || $carveLen > 32) {
                        $carveLen = 24;
                    }
                    $suggested = IpamService::nextAvailablePrefix($current, $carveLen);
                    $holes = IpamService::unallocatedCidrs($current);
                    ?>
                    <?php if ($canEdit): ?>
                        <form method="get" class="flex gap-1" style="flex-wrap:wrap;align-items:end;margin-bottom:.5rem">
                            <input type="hidden" name="prefix_id" value="<?= (int)$current['prefix_id'] ?>">
                            <label class="text-muted" style="font-size:.8rem">Next free
                                <select class="form-control" name="len" onchange="this.form.submit()">
                                    <?php for ($plen = 16; $plen <= 30; $plen++): ?>
                                        <option value="<?= $plen ?>" <?= $carveLen === $plen ? 'selected' : '' ?>>/<?= $plen ?></option>
                                    <?php endfor; ?>
                                </select>
                            </label>
                            <?php if ($suggested): ?>
                                <code><?= App::e($suggested) ?></code>
                                <a class="btn btn-sm btn-primary" href="<?= App::e(App::url('pages/ipam.php?view=prefix&track=subnets&parent_id=' . (int)$current['prefix_id'] . '&cidr=' . rawurlencode($suggested))) ?>">Add this prefix</a>
                            <?php else: ?>
                                <span class="text-muted">No free /<?= $carveLen ?> in this block.</span>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                <?php elseif ($canEdit): ?>
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
            <?php if ($subnetPlan): ?>
            <div class="card-body flush">
                <table class="data">
                    <thead>
                    <tr><th>Prefix</th><th>Name</th><th>VLAN</th><th>Len</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kids as $kid):
                        $kutil = IpamService::utilization($kid);
                        $grand = $childrenByParent[(int)$kid['prefix_id']] ?? [];
                        ?>
                        <tr>
                            <td><a href="<?= App::e(App::url('pages/ipam.php?prefix_id=' . (int)$kid['prefix_id'])) ?>">
                                <code><?= App::e((string)$kid['cidr']) ?></code></a></td>
                            <td><?= App::e((string)($kid['name'] ?? '—')) ?>
                                <?php if ($grand): ?>
                                    <div class="text-muted" style="font-size:.75rem"><?= count($grand) ?> nested</div>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($kid['vlan_id']) ? (int)$kid['vlan_id'] : '—' ?></td>
                            <td>/<?= (int)($kid['prefix_len'] ?? 0) ?></td>
                            <td class="text-muted" style="font-size:.75rem"><?= App::e((string)$kutil['pct']) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$kids): ?>
                        <tr><td colspan="5" class="text-muted">No child prefixes yet — this block is unallocated.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php if ($holes): ?>
                    <p class="text-muted" style="font-size:.8rem;margin:.75rem .85rem .35rem">Unallocated inside this prefix</p>
                    <table class="data">
                        <thead><tr><th>Available</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($holes as $hole): ?>
                            <tr>
                                <td><code><?= App::e((string)$hole['cidr']) ?></code></td>
                                <td>
                                    <?php if ($canEdit): ?>
                                        <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('pages/ipam.php?view=prefix&track=subnets&parent_id=' . (int)$current['prefix_id'] . '&cidr=' . rawurlencode((string)$hole['cidr']))) ?>">Use</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php else: ?>
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
            <?php endif; ?>
            <?php if ($canEdit): ?>
            <div class="card-body flex gap-1" style="flex-wrap:wrap">
                <?php if (!$subnetPlan): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="reconcile">
                    <button class="btn btn-secondary btn-sm" type="submit">Link inventory IPs</button>
                </form>
                <?php endif; ?>
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
