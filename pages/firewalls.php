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
                'snmp_auth_protocol' => trim((string)($_POST['snmp_auth_protocol'] ?? '')) ?: 'SHA',
                'snmp_priv_protocol' => trim((string)($_POST['snmp_priv_protocol'] ?? '')) ?: 'AES',
                'snmp_context' => trim((string)($_POST['snmp_context'] ?? '')) ?: null,
                'snmp_auto_poll' => (!empty($_POST['snmp_enabled']) && !empty($_POST['snmp_auto_poll'])) ? 1 : 0,
                'notes' => trim((string)($_POST['notes'] ?? '')) ?: null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
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
    ];
    if ($action === 'edit') {
        $loaded = Database::fetchOne('SELECT * FROM firewalls WHERE firewall_id = ? AND is_active = 1', [$id]);
        if (!$loaded) {
            App::flash('error', 'Firewall not found.');
            App::redirect('pages/firewalls.php');
        }
        $fw = array_merge($fw, $loaded);
    }
    layout_header($action === 'edit' ? 'Edit firewall' : 'Add firewall', $user, 'firewalls');
    firewall_form($fw, $rooms);
    layout_footer();
    exit;
}

if ($id > 0) {
    $fw = Database::fetchOne(
        'SELECT f.*, r.name AS room_name, d.name AS dc_name
         FROM firewalls f
         LEFT JOIN rooms r ON r.room_id = f.room_id
         LEFT JOIN datacenters d ON d.datacenter_id = r.datacenter_id
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
    'SELECT f.*, r.name AS room_name
     FROM firewalls f
     LEFT JOIN rooms r ON r.room_id = f.room_id
     WHERE f.is_active = 1
     ORDER BY f.name'
);
layout_header('Firewalls', $user, 'firewalls');
?>
<div class="flex-between mb-2">
    <div>
        <h1 class="page-title" style="margin:0">Firewalls</h1>
        <p class="text-muted" style="margin:.35rem 0 0">Cisco ASA stats over SNMP. The first devices are ASA 5525-class units on 9.12. ColdAisle reads version, failover, CPU, memory, connections, PAT xlates, VPN session and tunnel counts, and interface counters. It does not collect the activation key or VPN user sessions. On a multiple-context ASA, set the SNMP context to the admin context.</p>
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
            <tr><td colspan="8" class="text-muted">No firewalls yet. Add the ASA and point SNMP at its management address.</td></tr>
        <?php endif; ?>
        <?php foreach ($list as $row): ?>
            <tr>
                <td><a href="?id=<?= (int)$row['firewall_id'] ?>"><?= App::e((string)$row['name']) ?></a></td>
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
 */
function firewall_form(array $fw, array $rooms): void
{
    $fid = (int)($fw['firewall_id'] ?? 0);
    ?>
    <div class="flex-between mb-2">
        <h1 class="page-title" style="margin:0"><?= $fid ? 'Edit firewall' : 'Add firewall' ?></h1>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/firewalls.php' . ($fid ? '?id=' . $fid : ''))) ?>">Cancel</a>
    </div>
    <form method="post" class="card" style="padding:1rem 1.25rem">
        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
        <input type="hidden" name="action" value="save_firewall">
        <input type="hidden" name="firewall_id" value="<?= $fid ?>">
        <div class="form-row">
            <label for="fw_name">Name</label>
            <input id="fw_name" name="name" required value="<?= App::e((string)$fw['name']) ?>">
        </div>
        <div class="form-row">
            <label for="fw_vendor">Vendor</label>
            <input id="fw_vendor" name="vendor" value="<?= App::e((string)($fw['vendor'] ?? 'Cisco')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_model">Model</label>
            <input id="fw_model" name="model" value="<?= App::e((string)($fw['model'] ?? '')) ?>" placeholder="ASA5525">
        </div>
        <div class="form-row">
            <label for="fw_host">Hostname</label>
            <input id="fw_host" name="hostname" value="<?= App::e((string)($fw['hostname'] ?? '')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_ip">Management IP</label>
            <input id="fw_ip" name="primary_ip" value="<?= App::e((string)($fw['primary_ip'] ?? '')) ?>" placeholder="SNMP target">
        </div>
        <div class="form-row">
            <label for="fw_serial">Serial number</label>
            <input id="fw_serial" name="serial_no" value="<?= App::e((string)($fw['serial_no'] ?? '')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_room">Room</label>
            <select id="fw_room" name="room_id">
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
            <select id="fw_ha" name="ha_role">
                <?php foreach (['primary' => 'Primary', 'secondary' => 'Secondary', 'standalone' => 'Standalone'] as $k => $label): ?>
                    <option value="<?= App::e($k) ?>" <?= (string)($fw['ha_role'] ?? '') === $k ? 'selected' : '' ?>><?= App::e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label><input type="checkbox" name="snmp_enabled" value="1" <?= !empty($fw['snmp_enabled']) ? 'checked' : '' ?>> SNMP enabled</label>
        </div>
        <div class="form-row">
            <label for="fw_snmp_ver">SNMP version</label>
            <select id="fw_snmp_ver" name="snmp_version">
                <option value="2c" <?= (string)($fw['snmp_version'] ?? '2c') === '2c' ? 'selected' : '' ?>>v2c</option>
                <option value="3" <?= (string)($fw['snmp_version'] ?? '') === '3' ? 'selected' : '' ?>>v3</option>
            </select>
        </div>
        <div class="form-row">
            <label for="fw_port">SNMP port</label>
            <input id="fw_port" name="snmp_port" value="<?= (int)($fw['snmp_port'] ?? 161) ?>">
        </div>
        <div class="form-row">
            <label for="fw_ctx">SNMP context</label>
            <input id="fw_ctx" name="snmp_context" value="<?= App::e((string)($fw['snmp_context'] ?? '')) ?>" placeholder="Blank for single context. Admin context name when the ASA is in multiple mode.">
        </div>
        <div class="form-row">
            <label for="fw_comm">SNMPv2c community</label>
            <input id="fw_comm" name="snmp_community" type="password" autocomplete="new-password" placeholder="<?= $fid ? 'Leave blank to keep the saved community' : '' ?>">
        </div>
        <div class="form-row">
            <label for="fw_user">SNMPv3 user</label>
            <input id="fw_user" name="snmp_security_name" value="<?= App::e((string)($fw['snmp_security_name'] ?? '')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_level">SNMPv3 level</label>
            <select id="fw_level" name="snmp_v3_sec_level">
                <?php foreach (['authPriv', 'authNoPriv', 'noAuthNoPriv'] as $level): ?>
                    <option value="<?= App::e($level) ?>" <?= (string)($fw['snmp_v3_sec_level'] ?? 'authPriv') === $level ? 'selected' : '' ?>><?= App::e($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="fw_authp">Auth protocol</label>
            <input id="fw_authp" name="snmp_auth_protocol" value="<?= App::e((string)($fw['snmp_auth_protocol'] ?? 'SHA')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_auth">Auth passphrase</label>
            <input id="fw_auth" name="snmp_auth_passphrase" type="password" autocomplete="new-password" placeholder="<?= $fid ? 'Leave blank to keep' : '' ?>">
        </div>
        <div class="form-row">
            <label for="fw_privp">Privacy protocol</label>
            <input id="fw_privp" name="snmp_priv_protocol" value="<?= App::e((string)($fw['snmp_priv_protocol'] ?? 'AES')) ?>">
        </div>
        <div class="form-row">
            <label for="fw_priv">Privacy passphrase</label>
            <input id="fw_priv" name="snmp_priv_passphrase" type="password" autocomplete="new-password" placeholder="<?= $fid ? 'Leave blank to keep' : '' ?>">
        </div>
        <div class="form-row">
            <label><input type="checkbox" name="snmp_auto_poll" value="1" <?= !empty($fw['snmp_auto_poll']) ? 'checked' : '' ?>> Include in scheduled SNMP polling</label>
        </div>
        <?php if ($fid > 0): ?>
            <?php
            $ifChoices = FirewallHistory::interfaceChoices($fw);
            $ifOff = FirewallHistory::graphDisabled(isset($fw['if_graph_json']) ? (string)$fw['if_graph_json'] : null);
            ?>
            <div class="form-row">
                <input type="hidden" name="if_graph_form" value="1">
                <span class="fw-if-toggle-label">Interface graphs</span>
                <p class="text-muted" style="margin:.2rem 0 .55rem">Turn off control-plane, failover, and any other interface that only adds noise. The status table still lists every interface. Throughput charts, including the NOC, show only the ones left on. A new interface starts on.</p>
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
        <?php endif; ?>
        <div class="form-row">
            <label for="fw_notes">Notes</label>
            <textarea id="fw_notes" name="notes" rows="3"><?= App::e((string)($fw['notes'] ?? '')) ?></textarea>
        </div>
        <button class="btn btn-primary" type="submit">Save</button>
    </form>
    <?php
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
    $rateByName = [];
    if (is_array($history) && !empty($history['interfaces']) && is_array($history['interfaces'])) {
        foreach ($history['interfaces'] as $hif) {
            if (is_array($hif) && isset($hif['name'])) {
                $rateByName[(string)$hif['name']] = $hif;
            }
        }
    }
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
                <?php if (!empty($fw['dc_name']) || !empty($fw['room_name'])): ?>
                    · <?= App::e(trim((string)($fw['dc_name'] ?? '') . ' / ' . (string)($fw['room_name'] ?? ''), ' /')) ?>
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
        <p><strong>Connections</strong> <?= isset($snap['connections']) ? number_format((int)$snap['connections']) : '—' ?>
            <?php if (isset($snap['conn_peak'])): ?>
                · table peak <?= number_format((int)$snap['conn_peak']) ?>
            <?php endif; ?>
            <?php if (isset($snap['connections_per_sec'])): ?>
                · <?= number_format((int)$snap['connections_per_sec']) ?> /s
                <?php if (isset($snap['connections_per_sec_peak'])): ?>
                    (peak <?= number_format((int)$snap['connections_per_sec_peak']) ?>)
                <?php endif; ?>
            <?php endif; ?>
        </p>
        <p><strong><?= (($snap['xlate_source'] ?? '') === 'active') ? 'Translations' : 'PAT xlates' ?></strong>
            <?= isset($snap['xlate_pat']) ? number_format((int)$snap['xlate_pat']) : '—' ?>
            <?php if (isset($snap['xlate_nat'])): ?>
                · <?= number_format((int)$snap['xlate_nat']) ?> address binds
            <?php endif; ?>
        </p>
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
        <?php if (!empty($snap['polled_at'])): ?>
            <p class="text-muted">Last poll <?= App::e((string)$snap['polled_at']) ?> UTC</p>
        <?php endif; ?>
        <?php if (!empty($snap['sys_descr'])): ?>
            <p class="text-muted" style="white-space:pre-wrap"><?= App::e((string)$snap['sys_descr']) ?></p>
        <?php endif; ?>
    </div>
    <?php
    $hwAlerts = FirewallPoll::hardwareAlerts(is_array($fail['rows'] ?? null) ? $fail['rows'] : []);
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
    <script src="<?= App::e(App::url('assets/js/firewall-charts.js')) ?>?v=7"></script>
    <div class="card">
        <p class="text-muted" style="margin:.75rem 1rem .25rem">In and out rates are the change since the previous poll, as a percent of interface speed. Errors and discards are the count since that same poll. Octet counters are totals since the interface came up. Down interfaces, links with no reported speed, and interfaces with graphing turned off stay in this table only.</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Interface</th><th>Status</th><th>Speed</th>
                    <th>In rate</th><th>Out rate</th>
                    <th>Errors</th><th>Graph</th>
                    <th>In octets</th><th>Out octets</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$ifaces): ?>
                <tr><td colspan="9" class="text-muted">No external interfaces in the last poll. Internal-Data interfaces are hidden.</td></tr>
            <?php endif; ?>
            <?php foreach ($ifaces as $iface): ?>
                <?php if (!is_array($iface)) { continue; } ?>
                <?php
                $ifLabel = substr(trim((string)($iface['name'] ?? '')), 0, 128);
                $rate = $rateByName[$ifLabel] ?? null;
                $graphed = !isset($graphOff[$ifLabel]);
                $speedText = isset($iface['speed_mbps']) ? FirewallHistory::formatSpeed((int)$iface['speed_mbps']) : '';
                if ($speedText === '' && isset($iface['speed_mbps'])) {
                    $speedText = number_format((int)$iface['speed_mbps']) . ' Mb/s';
                }
                ?>
                <tr>
                    <td><?= App::e((string)($iface['name'] ?? '')) ?></td>
                    <td><?= App::e((string)($iface['oper'] ?? '')) ?></td>
                    <td><?= App::e($speedText) ?></td>
                    <td><?= App::e(firewall_rate_label(is_array($rate) ? $rate : null, 'in')) ?></td>
                    <td><?= App::e(firewall_rate_label(is_array($rate) ? $rate : null, 'out')) ?></td>
                    <td><?= App::e(firewall_fault_label(is_array($rate) ? $rate : null)) ?></td>
                    <td><?= $graphed ? 'On' : 'Off' ?></td>
                    <td><?= isset($iface['in_octets']) ? number_format((int)$iface['in_octets']) : '' ?></td>
                    <td><?= isset($iface['out_octets']) ? number_format((int)$iface['out_octets']) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
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
