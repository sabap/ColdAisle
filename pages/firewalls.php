<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/src/Schema.php';
require_once dirname(__DIR__) . '/src/Services/FirewallPoll.php';
require_once dirname(__DIR__) . '/src/Services/FirewallHistory.php';
require_once dirname(__DIR__) . '/src/Services/Crypto.php';
require_once dirname(__DIR__) . '/src/Services/AuditService.php';

App::boot();
Schema::ensureFirewalls();
$user = App::requirePermission('view_firewalls');
$canEdit = AuthManager::can($user, 'edit_firewalls');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = (string)($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    $act = (string)($_POST['action'] ?? '');
    try {
        if (in_array($act, ['save_firewall', 'delete_firewall', 'poll_firewall'], true) && !$canEdit && $act !== 'poll_firewall') {
            throw new RuntimeException('You do not have permission to edit firewalls.');
        }
        if ($act === 'poll_firewall' && !AuthManager::can($user, 'edit_firewalls') && !AuthManager::can($user, 'edit_snmp')) {
            throw new RuntimeException('You do not have permission to poll firewalls.');
        }
        if ($act === 'save_firewall') {
            $fid = (int)($_POST['firewall_id'] ?? 0);
            $prev = $fid > 0
                ? Database::fetchOne('SELECT * FROM firewalls WHERE firewall_id = ? AND is_active = 1', [$fid])
                : null;
            if ($fid > 0 && !$prev) {
                throw new RuntimeException('Firewall not found.');
            }
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Name is required.');
            }
            $ver = (string)($_POST['snmp_version'] ?? '2c');
            if (!in_array($ver, ['2c', '3'], true)) {
                $ver = '2c';
            }
            $community = trim((string)($_POST['snmp_community'] ?? ''));
            $authPass = (string)($_POST['snmp_auth_passphrase'] ?? '');
            $privPass = (string)($_POST['snmp_priv_passphrase'] ?? '');
            $row = [
                'name' => $name,
                'vendor' => trim((string)($_POST['vendor'] ?? '')) ?: 'Cisco',
                'model' => trim((string)($_POST['model'] ?? '')) ?: null,
                'hostname' => trim((string)($_POST['hostname'] ?? '')) ?: null,
                'primary_ip' => trim((string)($_POST['primary_ip'] ?? '')) ?: null,
                'serial_no' => trim((string)($_POST['serial_no'] ?? '')) ?: null,
                'room_id' => (int)($_POST['room_id'] ?? 0) > 0 ? (int)$_POST['room_id'] : null,
                'ha_role' => in_array(($_POST['ha_role'] ?? ''), ['primary', 'secondary', 'standalone'], true)
                    ? (string)$_POST['ha_role'] : null,
                'snmp_enabled' => !empty($_POST['snmp_enabled']) ? 1 : 0,
                'snmp_version' => $ver,
                'snmp_port' => (int)($_POST['snmp_port'] ?? 161) > 0 ? (int)$_POST['snmp_port'] : 161,
                'snmp_v3_sec_level' => trim((string)($_POST['snmp_v3_sec_level'] ?? '')) ?: null,
                'snmp_security_name' => trim((string)($_POST['snmp_security_name'] ?? '')) ?: null,
                'snmp_auth_protocol' => firewall_snmp_proto((string)($_POST['snmp_auth_protocol'] ?? ''), 'auth'),
                'snmp_priv_protocol' => firewall_snmp_proto((string)($_POST['snmp_priv_protocol'] ?? ''), 'priv'),
                'snmp_context' => trim((string)($_POST['snmp_context'] ?? '')) ?: null,
                'snmp_auto_poll' => (!empty($_POST['snmp_enabled']) && !empty($_POST['snmp_auto_poll'])) ? 1 : 0,
                'notes' => trim((string)($_POST['notes'] ?? '')) ?: null,
                'platform' => (($_POST['platform'] ?? '') === 'ftd') ? 'ftd' : 'asa',
                'ha_group' => trim((string)($_POST['ha_group'] ?? '')) !== ''
                    ? substr(trim((string)$_POST['ha_group']), 0, 80) : null,
                'fmc_host' => ($fmcHost = trim((string)($_POST['fmc_host'] ?? ''))) !== '' ? substr($fmcHost, 0, 255) : null,
                'fmc_port' => (int)($_POST['fmc_port'] ?? 443) > 0 ? (int)$_POST['fmc_port'] : 443,
                'fmc_username' => ($fmcUser = trim((string)($_POST['fmc_username'] ?? ''))) !== '' ? substr($fmcUser, 0, 150) : null,
                'fmc_verify_tls' => !empty($_POST['fmc_verify_tls']) ? 1 : 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
            $linkId = (int)($_POST['device_id'] ?? 0);
            if ($linkId > 0) {
                $rack = Database::fetchOne(
                    'SELECT device_id FROM devices WHERE device_id = ? AND is_active = 1 AND device_type = ?',
                    [$linkId, 'firewall']
                );
                if (!$rack) {
                    throw new RuntimeException('Choose a rack device whose type is Firewall.');
                }
                Database::update('firewalls', [
                    'device_id' => null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ], 'device_id = :did AND is_active = 1 AND firewall_id <> :id', [
                    ':did' => $linkId,
                    ':id' => $fid,
                ]);
                $row['device_id'] = $linkId;
            } else {
                $row['device_id'] = null;
            }
            if ($prev && isset($_POST['if_graph_form'])) {
                $known = isset($_POST['if_known']) && is_array($_POST['if_known'])
                    ? array_map('strval', $_POST['if_known']) : [];
                $enabled = isset($_POST['if_graph']) && is_array($_POST['if_graph'])
                    ? array_map('strval', $_POST['if_graph']) : [];
                $row['if_graph_json'] = FirewallHistory::encodeGraphPref(
                    isset($prev['if_graph_json']) ? (string)$prev['if_graph_json'] : null,
                    $known,
                    $enabled
                );
            }
            if ($prev && isset($_POST['if_hide_form'])) {
                $hideKnown = isset($_POST['if_hide_known']) && is_array($_POST['if_hide_known'])
                    ? array_map('strval', $_POST['if_hide_known']) : [];
                $hideKept = isset($_POST['if_hide']) && is_array($_POST['if_hide'])
                    ? array_map('strval', $_POST['if_hide']) : [];
                $row['if_hide_json'] = FirewallHistory::encodeHidePref(
                    isset($prev['if_hide_json']) ? (string)$prev['if_hide_json'] : null,
                    $hideKnown,
                    $hideKept,
                    FirewallHistory::pollNames($prev)
                );
            }
            if ($community !== '') {
                $row['snmp_community'] = Crypto::encrypt($community);
            } elseif (!$prev) {
                $row['snmp_community'] = null;
            }
            if ($authPass !== '') {
                $row['snmp_auth_passphrase'] = Crypto::encrypt($authPass);
            } elseif (!$prev) {
                $row['snmp_auth_passphrase'] = null;
            }
            if ($privPass !== '') {
                $row['snmp_priv_passphrase'] = Crypto::encrypt($privPass);
            } elseif (!$prev) {
                $row['snmp_priv_passphrase'] = null;
            }
            $fmcPass = (string)($_POST['fmc_password'] ?? '');
            if ($fmcPass !== '') {
                $row['fmc_password'] = Crypto::encrypt($fmcPass);
            } elseif (!$prev) {
                $row['fmc_password'] = null;
            }
            if ($prev) {
                Database::update('firewalls', $row, 'firewall_id = :id', [':id' => $fid]);
                AuditService::log((int)$user['user_id'], (string)($user['username'] ?? ''), 'update', 'firewall', $fid, ['name' => $name]);
                App::flash('success', 'Firewall updated.');
                App::redirect('pages/firewalls.php?id=' . $fid);
            }
            $row['created_at'] = gmdate('Y-m-d H:i:s');
            $newId = (int)Database::insert('firewalls', $row);
            AuditService::log((int)$user['user_id'], (string)($user['username'] ?? ''), 'create', 'firewall', $newId, ['name' => $name]);
            App::flash('success', 'Firewall added.');
            App::redirect('pages/firewalls.php?id=' . $newId);
        }
        if ($act === 'hide_interface') {
            if (!$canEdit) {
                throw new RuntimeException('You do not have permission to edit firewalls.');
            }
            $fid = (int)($_POST['firewall_id'] ?? 0);
            $prev = Database::fetchOne(
                'SELECT if_hide_json FROM firewalls WHERE firewall_id = ? AND is_active = 1',
                [$fid]
            );
            if (!$prev) {
                throw new RuntimeException('Firewall not found.');
            }
            $ifName = trim((string)($_POST['if_name'] ?? ''));
            if ($ifName === '') {
                throw new RuntimeException('Interface name is required.');
            }
            Database::update('firewalls', [
                'if_hide_json' => FirewallHistory::hideName(
                    isset($prev['if_hide_json']) ? (string)$prev['if_hide_json'] : null,
                    $ifName
                ),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], 'firewall_id = :id', [':id' => $fid]);
            App::flash('success', 'Interface hidden. Show it again from Edit firewall.');
            App::redirect('pages/firewalls.php?id=' . $fid);
        }
        if ($act === 'delete_firewall') {
            if (!$canEdit) {
                throw new RuntimeException('You do not have permission to edit firewalls.');
            }
            $fid = (int)($_POST['firewall_id'] ?? 0);
            $prev = Database::fetchOne('SELECT name FROM firewalls WHERE firewall_id = ? AND is_active = 1', [$fid]);
            if (!$prev) {
                throw new RuntimeException('Firewall not found.');
            }
            Database::update('firewalls', [
                'is_active' => 0,
                'snmp_auto_poll' => 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], 'firewall_id = :id', [':id' => $fid]);
            AuditService::log((int)$user['user_id'], (string)($user['username'] ?? ''), 'delete', 'firewall', $fid, ['name' => $prev['name']]);
            App::flash('success', 'Firewall removed from inventory.');
            App::redirect('pages/firewalls.php');
        }
        if ($act === 'poll_firewall') {
            $fid = (int)($_POST['firewall_id'] ?? 0);
            $fw = Database::fetchOne('SELECT * FROM firewalls WHERE firewall_id = ? AND is_active = 1', [$fid]);
            if (!$fw) {
                throw new RuntimeException('Firewall not found.');
            }
            try {
                FirewallPoll::poll($fw);
                App::flash('success', 'Firewall polled.');
            } catch (Throwable $pollErr) {
                Database::update('firewalls', [
                    'last_error' => substr($pollErr->getMessage(), 0, 400),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ], 'firewall_id = :id', [':id' => $fid]);
                throw $pollErr;
            }
            App::redirect('pages/firewalls.php?id=' . $fid);
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
        $back = (int)($_POST['firewall_id'] ?? 0);
        App::redirect('pages/firewalls.php' . ($back > 0 ? '?id=' . $back : ''));
    }
}

$rooms = Database::fetchAll(
    'SELECT r.room_id, r.name AS room_name, d.name AS dc_name
     FROM rooms r
     INNER JOIN datacenters d ON d.datacenter_id = r.datacenter_id
     WHERE r.is_active = 1
     ORDER BY d.name, r.name'
);

if ($action === 'new' || ($action === 'edit' && $id > 0)) {
    if (!$canEdit) {
        App::flash('error', 'You do not have permission to edit firewalls.');
        App::redirect('pages/firewalls.php');
    }
    $fw = [
        'firewall_id' => 0,
        'name' => '',
        'vendor' => 'Cisco',
        'model' => 'ASA5525',
        'hostname' => '',
        'primary_ip' => '',
        'serial_no' => '',
        'room_id' => '',
        'ha_role' => 'primary',
        'snmp_enabled' => 1,
        'snmp_version' => '2c',
        'snmp_port' => 161,
        'snmp_security_name' => '',
        'snmp_auth_protocol' => 'SHA',
        'snmp_priv_protocol' => 'AES',
        'snmp_v3_sec_level' => 'authPriv',
        'snmp_context' => '',
        'snmp_auto_poll' => 1,
        'notes' => '',
        'platform' => 'asa',
        'ha_group' => '',
        'fmc_host' => '',
        'fmc_port' => 443,
        'fmc_username' => '',
        'fmc_verify_tls' => 1,
    ];
    $snmpSha2 = FirewallPoll::netSnmpSupportsSha2();
    if ($action === 'new' && (string)($_GET['template'] ?? '') === 'ftd') {
        $fw['platform'] = 'ftd';
        $fw['model'] = '';
        $fw['snmp_version'] = '3';
        $fw['snmp_auth_protocol'] = $snmpSha2 ? 'SHA256' : 'SHA';
        $fw['snmp_priv_protocol'] = $snmpSha2 ? 'AES256' : 'AES';
        $fw['snmp_v3_sec_level'] = 'authPriv';
    }
    if ($action === 'edit') {
        $loaded = Database::fetchOne('SELECT * FROM firewalls WHERE firewall_id = ? AND is_active = 1', [$id]);
        if (!$loaded) {
            App::flash('error', 'Firewall not found.');
            App::redirect('pages/firewalls.php');
        }
        $fw = array_merge($fw, $loaded);
    }
    if ($action === 'new') {
        $prefillId = (int)($_GET['device_id'] ?? 0);
        if ($prefillId > 0) {
            $src = FirewallHistory::devicePrefill($prefillId);
            if ($src) {
                foreach (['name', 'vendor', 'model', 'hostname', 'primary_ip', 'serial_no', 'room_id'] as $key) {
                    if (isset($src[$key]) && $src[$key] !== '' && $src[$key] !== null) {
                        $fw[$key] = $src[$key];
                    }
                }
                $fw['device_id'] = $prefillId;
            }
        }
    }
    $rackDevices = FirewallHistory::rackDevices((int)($fw['firewall_id'] ?? 0));
    layout_header($action === 'edit' ? 'Edit firewall' : 'Add firewall', $user, 'firewalls');
    firewall_form($fw, $rooms, $rackDevices);
    layout_footer();
    exit;
}

if ($id > 0) {
    $fw = Database::fetchOne(
        'SELECT f.*, r.name AS room_name, d.name AS dc_name,
                dv.label AS device_label, dv.device_id AS linked_device_id
         FROM firewalls f
         LEFT JOIN rooms r ON r.room_id = f.room_id
         LEFT JOIN datacenters d ON d.datacenter_id = r.datacenter_id
         LEFT JOIN devices dv ON dv.device_id = f.device_id AND dv.is_active = 1
         WHERE f.firewall_id = ? AND f.is_active = 1',
        [$id]
    );
    if (!$fw) {
        App::flash('error', 'Firewall not found.');
        App::redirect('pages/firewalls.php');
    }
    $snap = [];
    if (!empty($fw['last_poll_json'])) {
        $decoded = json_decode((string)$fw['last_poll_json'], true);
        if (is_array($decoded)) {
            $snap = $decoded;
        }
    }
    layout_header('Firewall: ' . $fw['name'], $user, 'firewalls');
    firewall_detail($fw, $snap, $canEdit, FirewallHistory::one((int)$fw['firewall_id']));
    layout_footer();
    exit;
}

$list = Database::fetchAll(
    'SELECT f.*, r.name AS room_name, dv.label AS device_label
     FROM firewalls f
     LEFT JOIN rooms r ON r.room_id = f.room_id
     LEFT JOIN devices dv ON dv.device_id = f.device_id AND dv.is_active = 1
     WHERE f.is_active = 1
     ORDER BY f.name'
);
layout_header('Firewalls', $user, 'firewalls');
?>
<div class="flex-between mb-2">
    <div>
        <h1 class="page-title" style="margin:0">Firewalls</h1>
        <p class="text-muted" style="margin:.35rem 0 0">Add a Cisco ASA or a Secure Firewall Threat Defense. Each firewall keeps its own charts. An HA pair name groups the two members on the NOC without adding their numbers together. ColdAisle reads version, failover, CPU, memory, connections, and interface counters, and, when the firewall reports them, PAT xlates, VPN session and tunnel counts, data-plane and Snort CPU, and disk use. It does not collect the activation key or VPN user sessions. On a multiple-context ASA, set the SNMP context to the admin context. Threat Defense is polled with SNMPv3 on the firewall. An optional management center adds health and the HA pair name, and does not replace those charts.</p>
    </div>
    <?php if ($canEdit): ?>
        <a class="btn btn-primary" href="?action=new">Add firewall</a>
    <?php endif; ?>
</div>
<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Model</th>
                <th>Management IP</th>
                <th>Version</th>
                <th>Failover</th>
                <th>CPU</th>
                <th>Connections</th>
                <th>Last poll</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$list): ?>
            <tr><td colspan="8" class="text-muted">No firewalls yet. Add one and choose the ASA or Threat Defense template.</td></tr>
        <?php endif; ?>
        <?php foreach ($list as $row): ?>
            <tr>
                <td><a href="?id=<?= (int)$row['firewall_id'] ?>"><?= App::e((string)$row['name']) ?></a>
                    <div class="text-muted"><?= (($row['platform'] ?? '') === 'ftd') ? 'Threat Defense' : 'ASA' ?><?php if (!empty($row['ha_group'])): ?> · <?= App::e((string)$row['ha_group']) ?><?php endif; ?><?php if (!empty($row['device_label'])): ?> · Rack <?= App::e((string)$row['device_label']) ?><?php endif; ?></div></td>
                <td><?= App::e((string)($row['model'] ?? '')) ?></td>
                <td><?= App::e((string)($row['primary_ip'] ?? '')) ?></td>
                <td><?= App::e((string)($row['last_version'] ?? '')) ?></td>
                <td><?= App::e((string)($row['last_failover'] ?? '')) ?></td>
                <td><?= $row['last_cpu_pct'] !== null && $row['last_cpu_pct'] !== '' ? App::e((string)$row['last_cpu_pct']) . '%' : '' ?></td>
                <td><?= $row['last_connections'] !== null && $row['last_connections'] !== '' ? number_format((int)$row['last_connections']) : '' ?></td>
                <td><?= App::e((string)($row['snmp_last_poll_at'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
layout_footer();

/**
 * @param array<string,mixed> $fw
 * @param list<array<string,mixed>> $rooms
 * @param list<array<string,mixed>> $rackDevices
 */
function firewall_form(array $fw, array $rooms, array $rackDevices = []): void
{
    $snmpSha2 = FirewallPoll::netSnmpSupportsSha2();
    $fid = (int)($fw['firewall_id'] ?? 0);
    $platform = strtolower((string)($fw['platform'] ?? 'asa')) === 'ftd' ? 'ftd' : 'asa';
    $authChoices = ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'];
    $privChoices = ['DES', 'AES', 'AES192', 'AES256'];
    $authNow = strtoupper(str_replace(['-', ' '], '', (string)($fw['snmp_auth_protocol'] ?? 'SHA')));
    if ($authNow === 'SHA1') {
        $authNow = 'SHA';
    }
    if ($authNow === '') {
        $authNow = 'SHA';
    }
    if (!in_array($authNow, $authChoices, true)) {
        array_unshift($authChoices, $authNow);
    }
    $privNow = strtoupper(str_replace(['-', ' '], '', (string)($fw['snmp_priv_protocol'] ?? 'AES')));
    if ($privNow === 'AES128') {
        $privNow = 'AES';
    }
    if ($privNow === '') {
        $privNow = 'AES';
    }
    if (!in_array($privNow, $privChoices, true)) {
        array_unshift($privChoices, $privNow);
    }
    $fmcVerify = $fw['fmc_verify_tls'] ?? null;
    $fmcVerifyOn = !($fmcVerify === 0 || $fmcVerify === '0' || $fmcVerify === false);
    $ctxHelp = $platform === 'ftd'
        ? 'Usually blank. SNMP is set in the management center platform settings, deployed, and polled on the management address of this firewall.'
        : 'Blank for single context. Admin context name when the ASA is in multiple mode.';
    $linkedId = (int)($fw['device_id'] ?? 0);
    ?>
    <div class="flex-between mb-2">
        <h1 class="page-title" style="margin:0"><?= $fid ? 'Edit firewall' : 'Add firewall' ?></h1>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/firewalls.php' . ($fid ? '?id=' . $fid : ''))) ?>">Cancel</a>
    </div>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
        <input type="hidden" name="action" value="save_firewall">
        <input type="hidden" name="firewall_id" value="<?= $fid ?>">
        <input type="hidden" name="platform" id="fw_platform" value="<?= App::e($platform) ?>">
        <div class="fw-templates" id="fwTemplates" data-mode="<?= $fid ? 'edit' : 'new' ?>" data-snmp-sha2="<?= !empty($snmpSha2) ? '1' : '0' ?>">
            <button type="button" class="fw-template<?= $platform === 'asa' ? ' is-selected' : '' ?>" data-platform="asa" aria-pressed="<?= $platform === 'asa' ? 'true' : 'false' ?>">
                <strong>Cisco ASA</strong>
                <span>SNMPv2c template. VPN, PAT, and interface charts. On a multiple-context ASA, set the context to the admin context.</span>
            </button>
            <button type="button" class="fw-template<?= $platform === 'ftd' ? ' is-selected' : '' ?>" data-platform="ftd" aria-pressed="<?= $platform === 'ftd' ? 'true' : 'false' ?>">
                <strong>Cisco Secure Firewall Threat Defense</strong>
                <span>SNMPv3 template for the firewall management address. Set SNMP in the management center platform settings and deploy it. Charts stay on this firewall.</span>
            </button>
        </div>
        <div class="card">
            <div class="card-header"><h2>Identity</h2></div>
            <div class="card-body form-grid">
        <div class="form-row">
            <label for="fw_name">Name</label>
            <input class="form-control" id="fw_name" name="name" required value="<?= App::e((string)$fw['name']) ?>">
        </div>
        <div class="form-row">
            <label for="fw_vendor">Vendor</label>
            <input class="form-control" id="fw_vendor" name="vendor" value="<?= App::e((string)($fw['vendor'] ?? 'Cisco')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_model">Model</label>
            <input class="form-control" id="fw_model" name="model" value="<?= App::e((string)($fw['model'] ?? '')) ?>" placeholder="<?= $platform === 'ftd' ? 'Discovered from the firewall' : 'ASA5525' ?>">
        </div>
        <div class="form-row">
            <label for="fw_host">Hostname</label>
            <input class="form-control" id="fw_host" name="hostname" value="<?= App::e((string)($fw['hostname'] ?? '')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_ip">Management IP</label>
            <input class="form-control" id="fw_ip" name="primary_ip" value="<?= App::e((string)($fw['primary_ip'] ?? '')) ?>" placeholder="SNMP target">
        </div>
        <div class="form-row">
            <label for="fw_serial">Serial number</label>
            <input class="form-control" id="fw_serial" name="serial_no" value="<?= App::e((string)($fw['serial_no'] ?? '')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_room">Room</label>
            <select class="form-control" id="fw_room" name="room_id">
                <option value="">Unplaced</option>
                <?php foreach ($rooms as $room): ?>
                    <option value="<?= (int)$room['room_id'] ?>" <?= (int)($fw['room_id'] ?? 0) === (int)$room['room_id'] ? 'selected' : '' ?>>
                        <?= App::e((string)$room['dc_name'] . ' / ' . (string)$room['room_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="fw_ha">Inventory role</label>
            <select class="form-control" id="fw_ha" name="ha_role">
                <?php foreach (['primary' => 'Primary', 'secondary' => 'Secondary', 'standalone' => 'Standalone'] as $k => $label): ?>
                    <option value="<?= App::e($k) ?>" <?= (string)($fw['ha_role'] ?? '') === $k ? 'selected' : '' ?>><?= App::e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row full">
            <label for="fw_ha_group">HA pair name</label>
            <input class="form-control" id="fw_ha_group" name="ha_group" maxlength="80" value="<?= App::e((string)($fw['ha_group'] ?? '')) ?>" placeholder="Same name on both members">
            <p class="text-muted" style="margin:.35rem 0 0">Members with the same name are grouped on the NOC. Each member keeps its own charts. The numbers are not added together.</p>
        </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2>Rack device</h2></div>
            <div class="card-body form-grid">
                <div class="form-row full">
                    <label for="fw_device">Firewall in a rack</label>
                    <select class="form-control" id="fw_device" name="device_id">
                        <option value="">Not linked</option>
                        <?php
                        $linkedSeen = false;
                        foreach ($rackDevices as $rack):
                            $rackId = (int)($rack['device_id'] ?? 0);
                            if ($rackId < 1) {
                                continue;
                            }
                            if ($rackId === $linkedId) {
                                $linkedSeen = true;
                            }
                            $rackLabel = trim((string)($rack['label'] ?? ''));
                            if (!empty($rack['cabinet_name'])) {
                                $rackLabel .= ' / ' . (string)$rack['cabinet_name'];
                            }
                            ?>
                            <option value="<?= $rackId ?>" <?= $linkedId === $rackId ? 'selected' : '' ?>><?= App::e($rackLabel) ?></option>
                        <?php endforeach; ?>
                        <?php if ($linkedId > 0 && !$linkedSeen): ?>
                            <option value="<?= $linkedId ?>" selected>Linked device <?= $linkedId ?></option>
                        <?php endif; ?>
                    </select>
                    <p class="text-muted" style="margin:.35rem 0 0">Link the chassis in a rack to this firewall. SNMP for that device is edited here, not on the device page. Only devices whose type is Firewall are listed.</p>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h2>SNMP</h2></div>
            <div class="card-body form-grid">
        <div class="form-row full">
            <label><input type="checkbox" name="snmp_enabled" value="1" <?= !empty($fw['snmp_enabled']) ? 'checked' : '' ?>> SNMP enabled</label>
        </div>
        <div class="form-row">
            <label for="fw_snmp_ver">SNMP version</label>
            <select class="form-control" id="fw_snmp_ver" name="snmp_version">
                <option value="2c" <?= (string)($fw['snmp_version'] ?? '2c') === '2c' ? 'selected' : '' ?>>v2c</option>
                <option value="3" <?= (string)($fw['snmp_version'] ?? '') === '3' ? 'selected' : '' ?>>v3</option>
            </select>
        </div>
        <div class="form-row">
            <label for="fw_port">SNMP port</label>
            <input class="form-control" id="fw_port" name="snmp_port" value="<?= (int)($fw['snmp_port'] ?? 161) ?>">
        </div>
        <div class="form-row full">
            <label for="fw_ctx">SNMP context</label>
            <input class="form-control" id="fw_ctx" name="snmp_context" value="<?= App::e((string)($fw['snmp_context'] ?? '')) ?>">
            <p class="text-muted" id="fw_ctx_help" style="margin:.35rem 0 0"><?= App::e($ctxHelp) ?></p>
        </div>
        <div class="form-row">
            <label for="fw_comm">SNMPv2c community</label>
            <input class="form-control" id="fw_comm" name="snmp_community" type="password" autocomplete="new-password" placeholder="<?= $fid ? 'Leave blank to keep the saved community' : '' ?>">
        </div>
        <div class="form-row">
            <label for="fw_user">SNMPv3 user</label>
            <input class="form-control" id="fw_user" name="snmp_security_name" value="<?= App::e((string)($fw['snmp_security_name'] ?? '')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_level">SNMPv3 level</label>
            <select class="form-control" id="fw_level" name="snmp_v3_sec_level">
                <?php foreach (['authPriv', 'authNoPriv', 'noAuthNoPriv'] as $level): ?>
                    <option value="<?= App::e($level) ?>" <?= (string)($fw['snmp_v3_sec_level'] ?? 'authPriv') === $level ? 'selected' : '' ?>><?= App::e($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="fw_authp">Auth protocol</label>
            <?php if (empty($snmpSha2)): ?>
                <p class="text-muted" style="margin:.2rem 0 .45rem">The SNMP library on this server is Net-SNMP <?= App::e(FirewallPoll::netSnmpVersion()) ?>. SHA256 and AES256 do not authenticate. Use SHA and AES.</p>
            <?php endif; ?>
            <select class="form-control" id="fw_authp" name="snmp_auth_protocol">
                <?php foreach ($authChoices as $ap): ?>
                    <option value="<?= App::e($ap) ?>" <?= $authNow === $ap ? 'selected' : '' ?>><?= App::e($ap) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="fw_auth">Auth passphrase</label>
            <input class="form-control" id="fw_auth" name="snmp_auth_passphrase" type="password" autocomplete="new-password" placeholder="<?= $fid ? 'Leave blank to keep' : '' ?>">
        </div>
        <div class="form-row">
            <label for="fw_privp">Privacy protocol</label>
            <select class="form-control" id="fw_privp" name="snmp_priv_protocol">
                <?php foreach ($privChoices as $pp): ?>
                    <option value="<?= App::e($pp) ?>" <?= $privNow === $pp ? 'selected' : '' ?>><?= App::e($pp) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="fw_priv">Privacy passphrase</label>
            <input class="form-control" id="fw_priv" name="snmp_priv_passphrase" type="password" autocomplete="new-password" placeholder="<?= $fid ? 'Leave blank to keep' : '' ?>">
        </div>
        <div class="form-row full">
            <label><input type="checkbox" name="snmp_auto_poll" value="1" <?= !empty($fw['snmp_auto_poll']) ? 'checked' : '' ?>> Include in scheduled SNMP polling</label>
        </div>
            </div>
        </div>
        <div class="card" id="fwFmc" <?= $platform === 'ftd' ? '' : 'hidden' ?>>
            <div class="card-header"><h2>Management center</h2></div>
            <div class="card-body form-grid">
            <p class="text-muted full" style="margin:0">Optional. Charts still come from SNMP on this firewall. The center adds health, software, and the HA pair name when SNMP left them blank. A center that cannot be reached does not fail the SNMP poll. Leave Verify TLS checked unless the center uses a private certificate.</p>
            <div class="form-row">
                <label for="fw_fmc_host">Host</label>
                <input class="form-control" id="fw_fmc_host" name="fmc_host" value="<?= App::e((string)($fw['fmc_host'] ?? '')) ?>" placeholder="Management center address">
            </div>
            <div class="form-row">
                <label for="fw_fmc_port">Port</label>
                <input class="form-control" id="fw_fmc_port" name="fmc_port" value="<?= (int)($fw['fmc_port'] ?? 443) > 0 ? (int)$fw['fmc_port'] : 443 ?>">
            </div>
            <div class="form-row">
                <label for="fw_fmc_user">Username</label>
                <input class="form-control" id="fw_fmc_user" name="fmc_username" value="<?= App::e((string)($fw['fmc_username'] ?? '')) ?>" autocomplete="off">
            </div>
            <div class="form-row">
                <label for="fw_fmc_pass">Password</label>
                <input class="form-control" id="fw_fmc_pass" name="fmc_password" type="password" autocomplete="new-password" placeholder="<?= $fid ? 'Leave blank to keep' : '' ?>">
            </div>
            <div class="form-row full">
                <label><input type="checkbox" name="fmc_verify_tls" value="1" <?= $fmcVerifyOn ? 'checked' : '' ?>> Verify TLS</label>
            </div>
            </div>
        </div>
        <?php if ($fid > 0): ?>
            <?php
            $ifChoices = FirewallHistory::interfaceChoices($fw);
            $ifOff = FirewallHistory::graphDisabled(isset($fw['if_graph_json']) ? (string)$fw['if_graph_json'] : null);
            ?>
            <div class="card">
                <div class="card-header"><h2>Interface graphs</h2></div>
                <div class="card-body">
                <input type="hidden" name="if_graph_form" value="1">
                <p class="text-muted" style="margin:0 0 .55rem">Turn off control-plane, failover, and any other interface that only adds noise. The status table still lists every interface that is not hidden. Throughput charts, including the NOC, show only the ones left on. A new interface starts on. Tunnel interfaces are status only and are not charted.</p>
                <?php if (!$ifChoices): ?>
                    <p class="text-muted" style="margin:0">Graph switches appear after the first poll.</p>
                <?php else: ?>
                    <div class="fw-if-toggle-list">
                        <?php foreach ($ifChoices as $ifName): ?>
                            <input type="hidden" name="if_known[]" value="<?= App::e($ifName) ?>">
                            <label class="tech-mode-switch fw-if-switch">
                                <span class="tech-mode-switch-track">
                                    <input type="checkbox" name="if_graph[]" value="<?= App::e($ifName) ?>" <?= !isset($ifOff[$ifName]) ? 'checked' : '' ?>>
                                    <span class="tech-mode-switch-thumb" aria-hidden="true"></span>
                                </span>
                                <span class="fw-if-switch-name"><?= App::e($ifName) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($fid > 0): ?>
            <?php $hideChoices = FirewallHistory::hiddenChoices($fw); ?>
            <div class="card">
                <div class="card-header"><h2>Hidden interfaces</h2></div>
                <div class="card-body">
                    <input type="hidden" name="if_hide_form" value="1">
                    <p class="text-muted" style="margin:0 0 .55rem">Hide an interface that should leave the status table and the charts. Clear the box and save to show it again. Saving also forgets a hidden name that the last poll no longer reports.</p>
                    <?php if (!$hideChoices): ?>
                        <p class="text-muted" style="margin:0">No interfaces are hidden.</p>
                    <?php else: ?>
                        <div class="fw-if-toggle-list">
                            <?php foreach ($hideChoices as $hideName): ?>
                                <input type="hidden" name="if_hide_known[]" value="<?= App::e($hideName) ?>">
                                <label class="tech-mode-switch fw-if-switch">
                                    <span class="tech-mode-switch-track">
                                        <input type="checkbox" name="if_hide[]" value="<?= App::e($hideName) ?>" checked>
                                        <span class="tech-mode-switch-thumb" aria-hidden="true"></span>
                                    </span>
                                    <span class="fw-if-switch-name"><?= App::e($hideName) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><h2>Notes</h2></div>
            <div class="card-body form-grid">
                <div class="form-row full">
                    <label for="fw_notes">Notes</label>
                    <textarea class="form-control" id="fw_notes" name="notes" rows="3"><?= App::e((string)($fw['notes'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body form-actions">
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </div>
    </form>
    <script>
    (function () {
        var root = document.getElementById('fwTemplates');
        var platform = document.getElementById('fw_platform');
        if (!root || !platform) return;
        var fresh = root.getAttribute('data-mode') === 'new';
        var fmc = document.getElementById('fwFmc');
        var model = document.getElementById('fw_model');
        var ver = document.getElementById('fw_snmp_ver');
        var auth = document.getElementById('fw_authp');
        var priv = document.getElementById('fw_privp');
        var level = document.getElementById('fw_level');
        var help = document.getElementById('fw_ctx_help');
        var sha2 = root.getAttribute('data-snmp-sha2') === '1';
        function apply(kind, fill) {
            kind = kind === 'ftd' ? 'ftd' : 'asa';
            platform.value = kind;
            root.querySelectorAll('.fw-template').forEach(function (btn) {
                var on = btn.getAttribute('data-platform') === kind;
                btn.classList.toggle('is-selected', on);
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            if (fmc) fmc.hidden = kind !== 'ftd';
            if (help) {
                help.textContent = kind === 'ftd'
                    ? 'Usually blank. SNMP is set in the management center platform settings, deployed, and polled on the management address of this firewall.'
                    : 'Blank for single context. Admin context name when the ASA is in multiple mode.';
            }
            if (model) model.placeholder = kind === 'ftd' ? 'Discovered from the firewall' : 'ASA5525';
            if (!fill) return;
            if (kind === 'ftd') {
                if (model && (model.value === '' || model.value === 'ASA5525')) model.value = '';
                if (ver) ver.value = '3';
                if (auth) auth.value = sha2 ? 'SHA256' : 'SHA';
                if (priv) priv.value = sha2 ? 'AES256' : 'AES';
                if (level) level.value = 'authPriv';
            } else {
                if (model && model.value === '') model.value = 'ASA5525';
                if (ver) ver.value = '2c';
                if (auth) auth.value = 'SHA';
                if (priv) priv.value = 'AES';
                if (level) level.value = 'authPriv';
            }
        }
        root.addEventListener('click', function (ev) {
            var btn = ev.target.closest ? ev.target.closest('.fw-template') : null;
            if (!btn) return;
            apply(btn.getAttribute('data-platform') || 'asa', fresh);
        });
        apply(platform.value, false);
    })();
    </script>
    <?php
}

/** Keep a known SNMP protocol, and pass an uncommon one through instead of wiping it. */
function firewall_snmp_proto(string $raw, string $kind): string
{
    $p = strtoupper(str_replace(['-', ' '], '', trim($raw)));
    if ($kind === 'auth') {
        if ($p === 'SHA1') {
            return 'SHA';
        }
        if (in_array($p, ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'], true)) {
            return $p;
        }
        return $p !== '' ? substr($p, 0, 20) : 'SHA';
    }
    if ($p === 'AES128') {
        return 'AES';
    }
    if (in_array($p, ['DES', 'AES', 'AES192', 'AES256'], true)) {
        return $p;
    }
    return $p !== '' ? substr($p, 0, 20) : 'AES';
}

/**
 * @param array<string,mixed> $fw
 * @param array<string,mixed> $snap
 */
/**
 * @param array<string,mixed>|null $history
 */
function firewall_detail(array $fw, array $snap, bool $canEdit, ?array $history = null): void
{
    $mem = is_array($snap['memory'] ?? null) ? $snap['memory'] : [];
    $fail = is_array($snap['failover'] ?? null) ? $snap['failover'] : [];
    $ifaces = is_array($snap['interfaces'] ?? null) ? $snap['interfaces'] : [];
    $graphOff = FirewallHistory::graphDisabled(isset($fw['if_graph_json']) ? (string)$fw['if_graph_json'] : null);
    $hiddenIf = FirewallHistory::hiddenNames(isset($fw['if_hide_json']) ? (string)$fw['if_hide_json'] : null);
    $physicalIf = [];
    $tunnelIf = [];
    foreach ($ifaces as $iface) {
        if (!is_array($iface) || !FirewallPoll::keepInterface($iface)) {
            continue;
        }
        $ifLabel = substr(trim((string)($iface['name'] ?? '')), 0, 128);
        if ($ifLabel === '' || isset($hiddenIf[$ifLabel])) {
            continue;
        }
        if (FirewallPoll::isVti($iface)) {
            $tunnelIf[] = $iface;
        } else {
            $physicalIf[] = $iface;
        }
    }
    $rateByName = [];
    if (is_array($history) && !empty($history['interfaces']) && is_array($history['interfaces'])) {
        foreach ($history['interfaces'] as $hif) {
            if (is_array($hif) && isset($hif['name'])) {
                $rateByName[(string)$hif['name']] = $hif;
            }
        }
    }
    $isFtd = strtolower((string)($fw['platform'] ?? ($snap['platform'] ?? ''))) === 'ftd';
    $historyJson = json_encode(
        $history,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    );
    if ($historyJson === false) {
        $historyJson = 'null';
    }
    ?>
    <div class="flex-between mb-2">
        <div>
            <h1 class="page-title" style="margin:0"><?= App::e((string)$fw['name']) ?></h1>
            <p class="text-muted" style="margin:.35rem 0 0">
                <?= App::e(trim((string)($fw['vendor'] ?? '') . ' ' . (string)($fw['model'] ?? ''))) ?>
                · <?= $isFtd ? 'Threat Defense' : 'ASA' ?>
                <?php if (!empty($fw['dc_name']) || !empty($fw['room_name'])): ?>
                    · <?= App::e(trim((string)($fw['dc_name'] ?? '') . ' / ' . (string)($fw['room_name'] ?? ''), ' /')) ?>
                <?php endif; ?>
                <?php if (!empty($fw['linked_device_id'])): ?>
                    · Rack <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$fw['linked_device_id'])) ?>"><?= App::e((string)($fw['device_label'] ?? 'device')) ?></a>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex gap-1">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/firewalls.php')) ?>">All firewalls</a>
            <?php if ($canEdit): ?>
                <a class="btn btn-primary" href="?id=<?= (int)$fw['firewall_id'] ?>&action=edit">Edit</a>
                <form method="post" style="display:inline;margin:0">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="poll_firewall">
                    <input type="hidden" name="firewall_id" value="<?= (int)$fw['firewall_id'] ?>">
                    <button class="btn btn-secondary" type="submit">Poll now</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($fw['last_error'])): ?>
        <div class="alert alert-warning"><?= App::e((string)$fw['last_error']) ?></div>
    <?php endif; ?>
    <div class="card" style="padding:1rem 1.25rem;margin-bottom:1rem">
        <p><strong>Management IP</strong> <?= App::e((string)($fw['primary_ip'] ?? '—')) ?></p>
        <p><strong>Scheduled poll</strong>
            <?= !empty($fw['snmp_auto_poll']) ? 'On, about once a minute' : 'Off' ?>
            · <a href="<?= App::e(App::url('pages/snmp.php#targets')) ?>">SNMP Polling</a>
        </p>
        <?php if (!empty($fw['serial_no'])): ?>
            <p><strong>Serial</strong> <?= App::e((string)$fw['serial_no']) ?></p>
        <?php endif; ?>
        <p><strong>Hostname</strong> <?= App::e((string)($snap['sys_name'] ?? $fw['hostname'] ?? '—')) ?></p>
        <p><strong>Software</strong> <?= App::e((string)($snap['version'] ?? $fw['last_version'] ?? '—')) ?></p>
        <p><strong>Uptime</strong> <?= App::e(firewall_uptime(isset($snap['uptime_sec']) ? (int)$snap['uptime_sec'] : null)) ?></p>
        <p><strong>This unit</strong> <?= App::e((string)($fail['this_unit'] ?? $fw['last_failover'] ?? '—')) ?>
            <?php if (!empty($fail['peer'])): ?> · peer <?= App::e((string)$fail['peer']) ?><?php endif; ?>
        </p>
        <?php if (!empty($fail['rows']) && is_array($fail['rows'])): ?>
            <ul class="text-muted" style="margin:.25rem 0 1rem 1.25rem">
                <?php foreach (array_slice($fail['rows'], 0, 24) as $hw): ?>
                    <?php if (!is_array($hw)) { continue; } ?>
                    <li><?= App::e(trim((string)($hw['label'] ?? '') . ' ' . (string)($hw['detail'] ?? ''))) ?> — <?= App::e((string)($hw['status'] ?? '')) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p><strong>CPU</strong> <?= isset($snap['cpu_pct']) && $snap['cpu_pct'] !== null ? App::e((string)$snap['cpu_pct']) . '%' : '—' ?></p>
        <p><strong>Memory</strong>
            <?php if (isset($mem['pct'])): ?>
                <?= App::e((string)$mem['pct']) ?>% used<?= !empty($mem['label']) ? ' (' . App::e((string)$mem['label']) . ')' : '' ?>
            <?php else: ?>—<?php endif; ?>
        </p>
        <?php if ($isFtd || isset($snap['cpu_lina_pct']) || isset($snap['cpu_snort_pct'])): ?>
            <p><strong>Data plane</strong> <?= isset($snap['cpu_lina_pct']) ? App::e((string)$snap['cpu_lina_pct']) . '%' : '—' ?>
                · <strong>Snort</strong> <?= isset($snap['cpu_snort_pct']) ? App::e((string)$snap['cpu_snort_pct']) . '%' : '—' ?></p>
        <?php endif; ?>
        <?php if ($isFtd || isset($snap['disk_pct'])): ?>
            <p><strong>Disk</strong> <?= isset($snap['disk_pct']) ? App::e((string)$snap['disk_pct']) . '%' : '—' ?></p>
        <?php endif; ?>
        <p><strong>Connections</strong> <?= isset($snap['connections']) ? number_format((int)$snap['connections']) : '—' ?>
            <?php if (isset($snap['conn_peak'])): ?>
                · table peak <?= number_format((int)$snap['conn_peak']) ?>
            <?php endif; ?>
            <?php if (isset($snap['conn_half_open'])): ?>
                · <?= number_format((int)$snap['conn_half_open']) ?> half-open
            <?php endif; ?>
            <?php if (isset($snap['connections_per_sec'])): ?>
                · <?= number_format((int)$snap['connections_per_sec']) ?> /s
                <?php if (isset($snap['connections_per_sec_peak'])): ?>
                    (peak <?= number_format((int)$snap['connections_per_sec_peak']) ?>)
                <?php endif; ?>
            <?php endif; ?>
        </p>
        <?php
        $patAny = isset($snap['xlate_pat']) || isset($snap['xlate_nat']);
        $vpnAny = false;
        foreach (['vpn_sessions', 'ipsec_tunnels', 'ike_tunnels', 'vpn_peak', 'vpn_svc', 'vpn_webvpn', 'vpn_l2l', 'vpn_ipsec'] as $vpnKey) {
            if (isset($snap[$vpnKey]) && $snap[$vpnKey] !== '' && $snap[$vpnKey] !== null) {
                $vpnAny = true;
                break;
            }
        }
        ?>
        <?php if (!$isFtd || $patAny): ?>
        <p><strong><?= (($snap['xlate_source'] ?? '') === 'active') ? 'Translations' : 'PAT xlates' ?></strong>
            <?= isset($snap['xlate_pat']) ? number_format((int)$snap['xlate_pat']) : '—' ?>
            <?php if (isset($snap['xlate_nat'])): ?>
                · <?= number_format((int)$snap['xlate_nat']) ?> address binds
            <?php endif; ?>
        </p>
        <?php endif; ?>
        <?php if (!$isFtd || $vpnAny): ?>
        <p><strong>VPN</strong>
            <?= isset($snap['vpn_sessions']) ? number_format((int)$snap['vpn_sessions']) . ' sessions' : '—' ?>
            <?php if (isset($snap['ipsec_tunnels'])): ?>
                · <?= number_format((int)$snap['ipsec_tunnels']) ?> IPsec tunnels
            <?php endif; ?>
            <?php if (isset($snap['ike_tunnels'])): ?>
                · <?= number_format((int)$snap['ike_tunnels']) ?> IKE
            <?php endif; ?>
            <?php if (isset($snap['vpn_peak'])): ?>
                · peak <?= number_format((int)$snap['vpn_peak']) ?>
            <?php endif; ?>
        </p>
        <?php endif; ?>
        <?php if (isset($snap['remote_access_total'])): ?>
            <p><strong>Remote-access connections since boot</strong> <?= number_format((int)$snap['remote_access_total']) ?></p>
        <?php endif; ?>
        <?php
        $pairName = trim((string)($fw['ha_group'] ?? ''));
        $fmcPair = trim((string)($snap['ha_pair'] ?? ''));
        if ($pairName !== '' || $fmcPair !== ''):
        ?>
            <p><strong>HA pair</strong> <?= App::e($pairName !== '' ? $pairName : $fmcPair) ?><?php if ($pairName !== '' && $fmcPair !== '' && strcasecmp($pairName, $fmcPair) !== 0): ?> · <?= App::e($fmcPair) ?><?php endif; ?></p>
        <?php endif; ?>
        <?php if (!empty($snap['fmc_health'])): ?>
            <p><strong>Management center health</strong> <?= App::e((string)$snap['fmc_health']) ?></p>
        <?php endif; ?>
        <?php if (!empty($snap['fmc_error'])): ?>
            <p><strong>Management center</strong> <?= App::e((string)$snap['fmc_error']) ?></p>
        <?php endif; ?>
        <?php if (!empty($snap['polled_at'])): ?>
            <p class="text-muted">Last poll <?= App::e((string)$snap['polled_at']) ?> UTC</p>
        <?php endif; ?>
        <?php if (!empty($snap['sys_descr'])): ?>
            <p class="text-muted" style="white-space:pre-wrap"><?= App::e((string)$snap['sys_descr']) ?></p>
        <?php endif; ?>
    </div>
    <?php
    $hwAlerts = FirewallPoll::hardwareAlerts(is_array($fail['rows'] ?? null) ? $fail['rows'] : []);
    foreach (is_array($snap['fmc_alerts'] ?? null) ? $snap['fmc_alerts'] : [] as $fmcAlert) {
        if (!is_array($fmcAlert)) {
            continue;
        }
        $fmcLabel = trim((string)($fmcAlert['label'] ?? ''));
        $fmcStatus = trim((string)($fmcAlert['status'] ?? ''));
        if ($fmcLabel === '' || $fmcStatus === '') {
            continue;
        }
        $hwAlerts[] = [
            'label' => $fmcLabel,
            'detail' => trim((string)($fmcAlert['detail'] ?? '')),
            'status' => $fmcStatus,
        ];
    }
    if ($hwAlerts): ?>
        <div class="alert alert-warning">
            <?php foreach ($hwAlerts as $alert): ?>
                <div><?= App::e(trim($alert['label'] . ' ' . $alert['detail'])) ?> — <?= App::e($alert['status']) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div id="fwCharts" class="fw-charts"
         data-id="<?= (int)$fw['firewall_id'] ?>"
         data-api="<?= App::e(App::url('api/firewall_history.php')) ?>">
        <p class="text-muted">Loading charts…</p>
    </div>
    <script type="application/json" id="fwHistoryBoot"><?= $historyJson ?></script>
    <script src="<?= App::e(App::url('assets/js/firewall-specks.js')) ?>?v=1"></script>
    <script src="<?= App::e(App::url('assets/js/firewall-charts.js')) ?>?v=10"></script>
    <div class="card">
        <div class="card-header"><h2>Interfaces</h2></div>
        <p class="text-muted" style="margin:.75rem 1rem .25rem">In and out rates are the change since the previous poll, as a percent of interface speed. Errors and discards are the count since that same poll. Octet counters are totals since the interface came up. Down interfaces, links with no reported speed, and interfaces with graphing turned off stay in this table. Tunnel interfaces are listed by status only. Hidden interfaces stay off this page until Edit shows them again.</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Interface</th><th>Type</th><th>Status</th><th>Speed</th>
                    <th>In rate</th><th>Out rate</th>
                    <th>Errors</th><th>Graph</th>
                    <th>In octets</th><th>Out octets</th>
                    <?php if ($canEdit): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$physicalIf): ?>
                <tr><td colspan="<?= $canEdit ? 11 : 10 ?>" class="text-muted">No external interfaces in the last poll. Internal-Data interfaces are omitted. A tunnel that is administratively down is omitted.</td></tr>
            <?php endif; ?>
            <?php foreach ($physicalIf as $iface): ?>
                <?php
                $ifLabel = substr(trim((string)($iface['name'] ?? '')), 0, 128);
                $rate = $rateByName[$ifLabel] ?? null;
                $graphed = !isset($graphOff[$ifLabel]);
                $speedText = isset($iface['speed_mbps']) ? FirewallHistory::formatSpeed((int)$iface['speed_mbps']) : '';
                if ($speedText === '' && isset($iface['speed_mbps'])) {
                    $speedText = number_format((int)$iface['speed_mbps']) . ' Mb/s';
                }
                $typeText = FirewallPoll::ifTypeLabel($iface['type'] ?? null);
                ?>
                <tr>
                    <td><?= App::e((string)($iface['name'] ?? '')) ?></td>
                    <td><?= $typeText !== '' ? App::e($typeText) : '—' ?></td>
                    <td><?= firewall_status_badge((string)($iface['oper'] ?? '')) ?></td>
                    <td><?= App::e($speedText) ?></td>
                    <td><?= App::e(firewall_rate_label(is_array($rate) ? $rate : null, 'in')) ?></td>
                    <td><?= App::e(firewall_rate_label(is_array($rate) ? $rate : null, 'out')) ?></td>
                    <td><?= App::e(firewall_fault_label(is_array($rate) ? $rate : null)) ?></td>
                    <td><?= $graphed ? 'On' : 'Off' ?></td>
                    <td><?= isset($iface['in_octets']) ? number_format((int)$iface['in_octets']) : '' ?></td>
                    <td><?= isset($iface['out_octets']) ? number_format((int)$iface['out_octets']) : '' ?></td>
                    <?php if ($canEdit): ?><td><?php firewall_hide_button((int)$fw['firewall_id'], $ifLabel); ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($tunnelIf): ?>
    <div class="card">
        <div class="card-header"><h2>Virtual tunnel interfaces</h2></div>
        <p class="text-muted" style="margin:.75rem 1rem .25rem">Tunnel status is Up or Down. Throughput is not charted for these interfaces.</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Interface</th><th>Type</th><th>Status</th>
                    <?php if ($canEdit): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tunnelIf as $iface): ?>
                <?php
                $ifLabel = substr(trim((string)($iface['name'] ?? '')), 0, 128);
                $typeText = FirewallPoll::ifTypeLabel($iface['type'] ?? null);
                ?>
                <tr>
                    <td><?= App::e($ifLabel) ?></td>
                    <td><?= $typeText !== '' ? App::e($typeText) : '—' ?></td>
                    <td><?= firewall_status_badge((string)($iface['oper'] ?? '')) ?></td>
                    <?php if ($canEdit): ?><td><?php firewall_hide_button((int)$fw['firewall_id'], $ifLabel); ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if ($canEdit): ?>
        <form method="post" style="margin-top:1rem" onsubmit="return confirm('Remove this firewall from inventory?');">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_firewall">
            <input type="hidden" name="firewall_id" value="<?= (int)$fw['firewall_id'] ?>">
            <button class="btn btn-danger" type="submit">Delete</button>
        </form>
    <?php endif; ?>
    <?php
}

function firewall_status_badge(string $oper): string
{
    $raw = strtolower(trim($oper));
    $cls = 'badge';
    if ($raw === 'up') {
        $cls = 'badge badge-success';
    } elseif ($raw === 'down' || $raw === 'lower layer down' || $raw === 'not present') {
        $cls = 'badge badge-danger';
    }
    $label = $raw === '' ? 'Unknown' : ucfirst($raw);
    return '<span class="' . $cls . '">' . App::e($label) . '</span>';
}

function firewall_hide_button(int $firewallId, string $name): void
{
    if ($name === '') {
        return;
    }
    ?>
    <form method="post" style="margin:0">
        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
        <input type="hidden" name="action" value="hide_interface">
        <input type="hidden" name="firewall_id" value="<?= $firewallId ?>">
        <input type="hidden" name="if_name" value="<?= App::e($name) ?>">
        <button class="btn btn-ghost btn-sm" type="submit">Hide</button>
    </form>
    <?php
}

/** @param array<string,mixed>|null $rate */
function firewall_rate_label(?array $rate, string $which): string
{
    if ($rate === null) {
        return '—';
    }
    $util = $rate[$which . '_util'] ?? null;
    $text = $rate[$which . '_text'] ?? null;
    if ($util === null || $util === '') {
        return '—';
    }
    $label = rtrim(rtrim(number_format((float)$util, 2, '.', ''), '0'), '.') . '%';
    if (is_string($text) && $text !== '') {
        $label .= ' · ' . $text;
    }
    return $label;
}

/** @param array<string,mixed>|null $rate */
function firewall_fault_label(?array $rate): string
{
    if ($rate === null) {
        return '—';
    }
    $parts = [];
    $any = false;
    foreach ([
        'in_err' => 'in err',
        'out_err' => 'out err',
        'in_discard' => 'in drop',
        'out_discard' => 'out drop',
    ] as $key => $label) {
        if (!array_key_exists($key, $rate) || $rate[$key] === null || $rate[$key] === '') {
            continue;
        }
        $any = true;
        $n = (int)$rate[$key];
        if ($n > 0) {
            $parts[] = $label . ' ' . number_format($n);
        }
    }
    if (!$any) {
        return '—';
    }
    return $parts ? implode(' · ', $parts) : '0';
}

function firewall_uptime(?int $sec): string
{
    if ($sec === null || $sec < 0) {
        return '—';
    }
    $days = intdiv($sec, 86400);
    $hours = intdiv($sec % 86400, 3600);
    $mins = intdiv($sec % 3600, 60);
    return $days . 'd ' . $hours . 'h ' . $mins . 'm';
}
