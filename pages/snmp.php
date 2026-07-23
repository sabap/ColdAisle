<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_snmp');

$authProtos = ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'];
$privProtos = ['DES', 'AES', 'AES192', 'AES256'];
$secLevels = ['noAuthNoPriv', 'authNoPriv', 'authPriv'];

function snmp_null($v)
{
    if ($v === null || (is_string($v) && trim($v) === '')) {
        return null;
    }
    return is_string($v) ? trim($v) : $v;
}

/**
 * Display label for a site OID template (vendor / model).
 * Internal unique key remains name (Vendor+Model) for discovery upserts.
 * @param array<string,mixed>|null $t
 */
function snmp_site_template_label(?array $t): string
{
    if (!$t) {
        return '';
    }
    $v = trim((string)($t['vendor'] ?? ''));
    $m = trim((string)($t['model'] ?? ''));
    if ($v !== '' && $m !== '') {
        return $v . ' / ' . $m;
    }
    if ($v !== '') {
        return $v;
    }
    if ($m !== '') {
        return $m;
    }
    return trim((string)($t['name'] ?? ''));
}

/**
 * Build oid_map + optional site_template_id from target form POST.
 * Site templates come from OID discovery (snmp_site_oid_templates).
 *
 * @param array<string,mixed> $post
 * @param array<int,array<string,mixed>> $siteById template_id => row
 * @return array{oid_map:array<string,string>,site_template_id:?int}
 */
function snmp_target_oid_from_post(array $post, array $siteById): array
{
    $map = [];
    $siteTplId = null;
    $raw = trim((string)($post['oid_template'] ?? ''));
    if (preg_match('/^site:(\d+)$/', $raw, $m)) {
        $id = (int)$m[1];
        if ($id > 0 && isset($siteById[$id])) {
            $siteTplId = $id;
            $decoded = json_decode((string)($siteById[$id]['oid_map'] ?? '{}'), true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $k = trim((string)$k);
                    $v = trim((string)$v);
                    if ($k === '' || $v === '' || str_starts_with($k, '_')) {
                        continue;
                    }
                    $map[$k] = $v;
                }
            }
        }
    }

    $uptime = trim((string)($post['oid_uptime'] ?? ''));
    $watts = trim((string)($post['oid_watts'] ?? ''));
    $amps = trim((string)($post['oid_amps'] ?? ''));
    $temp = trim((string)($post['oid_temp'] ?? ''));
    $ampsMetric = trim((string)($post['oid_amps_metric'] ?? 'amps'));
    if ($ampsMetric !== 'amps_x10') {
        $ampsMetric = 'amps';
    }

    if ($uptime !== '') {
        $map['sysUpTime'] = $uptime;
    } elseif (!isset($map['sysUpTime'])) {
        $map['sysUpTime'] = '1.3.6.1.2.1.1.3.0';
    }
    if ($watts !== '') {
        $map['watts'] = $watts;
    }
    if ($amps !== '') {
        if ($ampsMetric === 'amps_x10') {
            $map['amps_x10'] = $amps;
            unset($map['amps']);
        } else {
            $map['amps'] = $amps;
            unset($map['amps_x10']);
        }
    }
    if ($temp !== '') {
        $map['temperature'] = $temp;
    }

    $out = [];
    foreach ($map as $k => $v) {
        $v = trim((string)$v);
        if ($v !== '') {
            $out[(string)$k] = $v;
        }
    }
    return ['oid_map' => $out, 'site_template_id' => $siteTplId];
}

/**
 * Resolve target credentials from POST + optional SNMPv3 profile.
 * @return array{security_name:?string,auth_protocol:?string,auth_passphrase:?string,priv_protocol:?string,priv_passphrase:?string,context_name:?string}
 */
function snmp_target_creds_from_post(array $post, ?array $existing = null): array
{
    $profileId = !empty($post['profile_id']) ? (int)$post['profile_id'] : 0;
    $secName = snmp_null($post['security_name'] ?? null);
    $authProto = snmp_null($post['auth_protocol'] ?? null);
    $authPass = snmp_null($post['auth_passphrase'] ?? null);
    $privProto = snmp_null($post['priv_protocol'] ?? null);
    $privPass = snmp_null($post['priv_passphrase'] ?? null);
    $context = snmp_null($post['context_name'] ?? null);
    if ($profileId) {
        $prof = Database::fetchOne(
            'SELECT * FROM snmp_v3_profiles WHERE profile_id = ? AND is_active = 1',
            [$profileId]
        );
        if ($prof) {
            $secName = $prof['security_name'] ?? $secName;
            $authProto = $prof['auth_protocol'] ?? $authProto;
            $privProto = $prof['priv_protocol'] ?? $privProto;
            $context = $prof['context_name'] ?? $context;
            if (!empty($prof['auth_passphrase'])) {
                $authPass = $prof['auth_passphrase'];
            }
            if (!empty($prof['priv_passphrase'])) {
                $privPass = $prof['priv_passphrase'];
            }
        }
    }
    // Keep existing secrets on edit when left blank and no profile passphrase
    if ($existing) {
        if ($authPass === null && !empty($existing['auth_passphrase'])) {
            $authPass = $existing['auth_passphrase'];
        }
        if ($privPass === null && !empty($existing['priv_passphrase'])) {
            $privPass = $existing['priv_passphrase'];
        }
        if ($secName === null && !empty($existing['security_name'])) {
            $secName = $existing['security_name'];
        }
    }
    return [
        'security_name' => $secName,
        'auth_protocol' => $authProto,
        'auth_passphrase' => $authPass,
        'priv_protocol' => $privProto,
        'priv_passphrase' => $privPass,
        'context_name' => $context,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        if (in_array($action, [
            'add_profile', 'update_profile', 'deactivate_profile',
            'add_target', 'update_target', 'toggle', 'delete_target',
            'unschedule_pdu', 'unschedule_device',
        ], true) && !AuthManager::canEditSnmp($user)) {
            throw new RuntimeException('You do not have permission to edit SNMP settings.');
        }

        if ($action === 'add_profile' || $action === 'update_profile') {
            $name = trim((string)($_POST['name'] ?? ''));
            $secName = trim((string)($_POST['security_name'] ?? ''));
            if ($name === '' || $secName === '') {
                throw new RuntimeException('Profile name and SNMPv3 user (security name) are required.');
            }
            $level = (string)($_POST['security_level'] ?? 'authPriv');
            if (!in_array($level, $secLevels, true)) {
                $level = 'authPriv';
            }
            $row = [
                'name' => $name,
                'security_name' => $secName,
                'security_level' => $level,
                'auth_protocol' => snmp_null($_POST['auth_protocol'] ?? null),
                'priv_protocol' => snmp_null($_POST['priv_protocol'] ?? null),
                'context_name' => snmp_null($_POST['context_name'] ?? null),
                'notes' => snmp_null($_POST['notes'] ?? null),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            // Only overwrite passphrases when non-empty (edit can leave blank to keep)
            if (trim((string)($_POST['auth_passphrase'] ?? '')) !== '') {
                $row['auth_passphrase'] = (string)$_POST['auth_passphrase'];
            } elseif ($action === 'add_profile') {
                $row['auth_passphrase'] = snmp_null($_POST['auth_passphrase'] ?? null);
            }
            if (trim((string)($_POST['priv_passphrase'] ?? '')) !== '') {
                $row['priv_passphrase'] = (string)$_POST['priv_passphrase'];
            } elseif ($action === 'add_profile') {
                $row['priv_passphrase'] = snmp_null($_POST['priv_passphrase'] ?? null);
            }

            if ($action === 'update_profile') {
                $pid = (int)($_POST['profile_id'] ?? 0);
                if ($pid <= 0) {
                    throw new RuntimeException('Profile id required.');
                }
                Database::update('snmp_v3_profiles', $row, 'profile_id = :id', [':id' => $pid]);
                App::flash('success', 'SNMPv3 profile updated.');
                App::redirect('pages/snmp.php?edit_profile=' . $pid . '#profiles');
            }

            $row['is_active'] = 1;
            unset($row['updated_at']);
            Database::insert('snmp_v3_profiles', array_merge($row, [
                'auth_passphrase' => $row['auth_passphrase'] ?? snmp_null($_POST['auth_passphrase'] ?? null),
                'priv_passphrase' => $row['priv_passphrase'] ?? snmp_null($_POST['priv_passphrase'] ?? null),
            ]));
            App::flash('success', 'SNMPv3 profile created.');
            App::redirect('pages/snmp.php#profiles');
        }

        if ($action === 'deactivate_profile') {
            $pid = (int)($_POST['profile_id'] ?? 0);
            if ($pid > 0) {
                Database::update('snmp_v3_profiles', [
                    'is_active' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'profile_id = :id', [':id' => $pid]);
                App::flash('success', 'Profile deactivated.');
            }
            App::redirect('pages/snmp.php#profiles');
        }

        if ($action === 'add_target' || $action === 'update_target') {
            $name = trim((string)($_POST['name'] ?? ''));
            $host = trim((string)($_POST['host'] ?? ''));
            if ($name === '' || $host === '') {
                throw new RuntimeException('Name and host are required.');
            }
            $siteById = [];
            try {
                foreach (Database::fetchAll('SELECT * FROM snmp_site_oid_templates') as $st) {
                    $siteById[(int)$st['template_id']] = $st;
                }
            } catch (Throwable $e) {
                $siteById = [];
            }
            $oidPack = snmp_target_oid_from_post($_POST, $siteById);
            $oidMap = $oidPack['oid_map'];
            $existing = null;
            $tid = 0;
            if ($action === 'update_target') {
                $tid = (int)($_POST['target_id'] ?? 0);
                if ($tid <= 0) {
                    throw new RuntimeException('Target id required.');
                }
                $existing = Database::fetchOne('SELECT * FROM snmp_targets WHERE target_id = ?', [$tid]);
                if (!$existing) {
                    throw new RuntimeException('Target not found.');
                }
            }
            $creds = snmp_target_creds_from_post($_POST, $existing);
            $row = [
                'name' => $name,
                'host' => $host,
                'port' => (int)($_POST['port'] ?? 161),
                'snmp_version' => $_POST['snmp_version'] ?? '3',
                'security_name' => $creds['security_name'],
                'auth_protocol' => $creds['auth_protocol'],
                'auth_passphrase' => $creds['auth_passphrase'],
                'priv_protocol' => $creds['priv_protocol'],
                'priv_passphrase' => $creds['priv_passphrase'],
                'context_name' => $creds['context_name'],
                'poll_interval_sec' => (int)($_POST['poll_interval_sec'] ?? 300),
                'oid_map' => json_encode($oidMap, JSON_UNESCAPED_SLASHES),
                'site_template_id' => $oidPack['site_template_id'],
                'device_id' => ($_POST['device_id'] ?? '') !== '' ? (int)$_POST['device_id'] : null,
                'pdu_id' => ($_POST['pdu_id'] ?? '') !== '' ? (int)$_POST['pdu_id'] : null,
                // Explicit scheduled targets are always enabled for the scheduler list
                'is_enabled' => 1,
            ];
            $tplName = '';
            if ($oidPack['site_template_id'] && isset($siteById[$oidPack['site_template_id']])) {
                $tplName = (string)$siteById[$oidPack['site_template_id']]['name'];
            }
            if ($action === 'update_target') {
                Database::update('snmp_targets', $row, 'target_id = :id', [':id' => $tid]);
                App::flash('success', 'SNMP target updated'
                    . ($tplName !== '' ? ' (OID template: ' . $tplName . ')' : '') . '.');
                App::redirect('pages/snmp.php?edit_target=' . $tid . '#targets');
            }
            Database::insert('snmp_targets', $row);
            App::flash('success', 'SNMP target added'
                . ($tplName !== '' ? ' (OID template: ' . $tplName . ')' : '') . '.');
            $redirPdu = ($_POST['pdu_id'] ?? '') !== '' ? (int)$_POST['pdu_id'] : 0;
            if ($redirPdu > 0 && !empty($_POST['return_to_pdu'])) {
                App::redirect('pages/power_pdus.php?id=' . $redirPdu);
            }
        }
        if ($action === 'toggle') {
            $tid = (int)$_POST['target_id'];
            $cur = (int) Database::fetchValue('SELECT is_enabled FROM snmp_targets WHERE target_id = ?', [$tid]);
            Database::update('snmp_targets', ['is_enabled' => $cur ? 0 : 1], 'target_id = :id', [':id' => $tid]);
            App::flash('success', $cur ? 'Target removed from scheduled polling.' : 'Target enabled for scheduled polling.');
        }
        if ($action === 'delete_target') {
            $tid = (int)($_POST['target_id'] ?? 0);
            if ($tid <= 0) {
                throw new RuntimeException('Target id required.');
            }
            // Readings cascade if FK set; otherwise clear readings then target
            try {
                Database::delete('snmp_readings', 'target_id = ?', [$tid]);
            } catch (Throwable $e) {
                // ignore
            }
            Database::delete('snmp_targets', 'target_id = ?', [$tid]);
            App::flash('success', 'SNMP target deleted.');
        }
        if ($action === 'unschedule_pdu') {
            $pid = (int)($_POST['pdu_id'] ?? 0);
            if ($pid <= 0) {
                throw new RuntimeException('PDU id required.');
            }
            Database::update('pdus', ['snmp_auto_poll' => 0], 'pdu_id = :id', [':id' => $pid]);
            App::flash('success', 'PDU removed from scheduled polling.');
        }
        if ($action === 'unschedule_device') {
            $did = (int)($_POST['device_id'] ?? 0);
            if ($did <= 0) {
                throw new RuntimeException('Device id required.');
            }
            Database::update('devices', [
                'snmp_auto_poll' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'device_id = :id', [':id' => $did]);
            App::flash('success', 'Device removed from scheduled polling.');
        }
        if ($action === 'poll_now') {
            require_once dirname(__DIR__) . '/src/Services/SnmpPoller.php';
            $result = SnmpPoller::pollAll();
            App::flash('success', "Poll complete: {$result['success']} ok, {$result['failed']} failed.");
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/snmp.php' . (!empty($_GET['pdu_id']) ? '?pdu_id=' . (int)$_GET['pdu_id'] . '#targets' : '#targets'));
}

// Free-standing scheduled targets (is_enabled = 1)
$targets = Database::fetchAll(
    'SELECT t.*, d.label AS device_label, p.name AS pdu_name
     FROM snmp_targets t
     LEFT JOIN devices d ON d.device_id = t.device_id
     LEFT JOIN pdus p ON p.pdu_id = t.pdu_id
     WHERE t.is_enabled = 1
     ORDER BY t.name'
);
// PDUs / devices with Scheduled poll toggle ON (scheduler inventory)
$scheduledPdus = [];
$scheduledDevices = [];
try {
    $scheduledPdus = Database::fetchAll(
        'SELECT p.pdu_id, p.name, p.ip_address, p.snmp_version, p.last_poll_at, p.last_poll_watts, p.last_poll_amps,
                p.snmp_site_template_id, t.vendor AS template_vendor, t.model AS template_model, t.name AS template_name
         FROM pdus p
         LEFT JOIN snmp_site_oid_templates t ON t.template_id = p.snmp_site_template_id
         WHERE p.is_active = 1 AND p.snmp_auto_poll = 1
         ORDER BY p.name'
    );
} catch (Throwable $e) {
    $scheduledPdus = [];
}
try {
    $scheduledDevices = Database::fetchAll(
        'SELECT d.device_id, d.label, d.mgmt_ip, d.primary_ip, d.snmp_version,
                d.snmp_last_poll_at, d.snmp_last_poll_watts, d.snmp_last_poll_amps,
                d.snmp_site_template_id, t.vendor AS template_vendor, t.model AS template_model, t.name AS template_name
         FROM devices d
         LEFT JOIN snmp_site_oid_templates t ON t.template_id = d.snmp_site_template_id
         WHERE d.is_active = 1 AND d.snmp_auto_poll = 1
         ORDER BY d.label'
    );
} catch (Throwable $e) {
    $scheduledDevices = [];
}
$devices = Database::fetchAll('SELECT device_id, label FROM devices WHERE is_active = 1 ORDER BY label');
$pdus = Database::fetchAll(
    'SELECT pdu_id, name, ip_address, snmp_enabled, snmp_version, snmp_v3_profile_id
     FROM pdus WHERE is_active = 1 ORDER BY name'
);
$recent = Database::fetchAll(
    'SELECT TOP 50 r.*, t.name AS target_name
     FROM snmp_readings r
     INNER JOIN snmp_targets t ON t.target_id = r.target_id
     ORDER BY r.polled_at DESC'
);
$profiles = [];
try {
    $profiles = Database::fetchAll(
        'SELECT * FROM snmp_v3_profiles WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $profiles = [];
}
$editProfileId = (int)($_GET['edit_profile'] ?? 0);
$editProfile = null;
foreach ($profiles as $p) {
    if ((int)$p['profile_id'] === $editProfileId) {
        $editProfile = $p;
        break;
    }
}
$hasSnmp = extension_loaded('snmp');
$canEdit = AuthManager::canEditSnmp($user);

// Site OID templates created via Discover OIDs (Vendor+Model)
$siteOidTemplates = [];
$siteOidById = [];
try {
    $siteOidTemplates = Database::fetchAll(
        'SELECT t.*,
            (SELECT COUNT(*) FROM snmp_targets st WHERE st.site_template_id = t.template_id) AS target_count,
            (SELECT COUNT(*) FROM pdus p WHERE p.snmp_site_template_id = t.template_id AND p.is_active = 1) AS pdu_count,
            (SELECT COUNT(*) FROM devices d WHERE d.snmp_site_template_id = t.template_id AND d.is_active = 1) AS device_count
         FROM snmp_site_oid_templates t
         WHERE t.is_active = 1
         ORDER BY t.vendor, t.model, t.name'
    );
} catch (Throwable $e) {
    // Fallback without usage counts if columns not ready
    try {
        $siteOidTemplates = Database::fetchAll(
            'SELECT * FROM snmp_site_oid_templates WHERE is_active = 1 ORDER BY name'
        );
    } catch (Throwable $e2) {
        $siteOidTemplates = [];
    }
}
foreach ($siteOidTemplates as $st) {
    $siteOidById[(int)$st['template_id']] = $st;
}

$editTargetId = (int)($_GET['edit_target'] ?? 0);
$preselectPduId = (int)($_GET['pdu_id'] ?? 0);
$editTarget = null;
if ($editTargetId > 0) {
    foreach ($targets as $t) {
        if ((int)$t['target_id'] === $editTargetId) {
            $editTarget = $t;
            break;
        }
    }
    if (!$editTarget) {
        try {
            $editTarget = Database::fetchOne('SELECT * FROM snmp_targets WHERE target_id = ?', [$editTargetId]);
        } catch (Throwable $e) {
            $editTarget = null;
        }
    }
}
// Prefill form from edit target or preselected PDU
$tf = [
    'name' => $editTarget['name'] ?? '',
    'host' => $editTarget['host'] ?? '',
    'port' => (int)($editTarget['port'] ?? 161),
    'snmp_version' => $editTarget['snmp_version'] ?? '3',
    'security_name' => $editTarget['security_name'] ?? '',
    'auth_protocol' => $editTarget['auth_protocol'] ?? '',
    'priv_protocol' => $editTarget['priv_protocol'] ?? '',
    'context_name' => $editTarget['context_name'] ?? '',
    'poll_interval_sec' => (int)($editTarget['poll_interval_sec'] ?? 300),
    'device_id' => (int)($editTarget['device_id'] ?? 0),
    'pdu_id' => (int)($editTarget['pdu_id'] ?? $preselectPduId),
    'profile_id' => 0,
];
$tfOids = [
    'oid_uptime' => '1.3.6.1.2.1.1.3.0',
    'oid_watts' => '',
    'oid_amps' => '',
    'oid_temp' => '',
    'oid_amps_metric' => 'amps',
    'oid_template' => '',
];
if ($editTarget && !empty($editTarget['site_template_id'])) {
    $tfOids['oid_template'] = 'site:' . (int)$editTarget['site_template_id'];
}
if ($editTarget && !empty($editTarget['oid_map'])) {
    $em = json_decode((string)$editTarget['oid_map'], true) ?: [];
    $tfOids['oid_uptime'] = (string)($em['sysUpTime'] ?? $tfOids['oid_uptime']);
    $tfOids['oid_watts'] = (string)($em['watts'] ?? '');
    if (!empty($em['amps_x10'])) {
        $tfOids['oid_amps'] = (string)$em['amps_x10'];
        $tfOids['oid_amps_metric'] = 'amps_x10';
    } else {
        $tfOids['oid_amps'] = (string)($em['amps'] ?? '');
    }
    $tfOids['oid_temp'] = (string)($em['temperature'] ?? $em['temp'] ?? '');
}
// Prefill site template from linked PDU when creating a new target
if (!$editTarget && $preselectPduId > 0) {
    try {
        $preTpl = Database::fetchValue(
            'SELECT snmp_site_template_id FROM pdus WHERE pdu_id = ?',
            [$preselectPduId]
        );
        if ($preTpl) {
            $tfOids['oid_template'] = 'site:' . (int)$preTpl;
            if (isset($siteOidById[(int)$preTpl])) {
                $em = json_decode((string)($siteOidById[(int)$preTpl]['oid_map'] ?? '{}'), true) ?: [];
                $tfOids['oid_uptime'] = (string)($em['sysUpTime'] ?? $tfOids['oid_uptime']);
                $tfOids['oid_watts'] = (string)($em['watts'] ?? '');
                if (!empty($em['amps_x10'])) {
                    $tfOids['oid_amps'] = (string)$em['amps_x10'];
                    $tfOids['oid_amps_metric'] = 'amps_x10';
                } else {
                    $tfOids['oid_amps'] = (string)($em['amps'] ?? '');
                }
                $tfOids['oid_temp'] = (string)($em['temperature'] ?? $em['temp'] ?? '');
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}
if (!$editTarget && $preselectPduId > 0) {
    $prePdu = null;
    foreach ($pdus as $pp) {
        if ((int)$pp['pdu_id'] === $preselectPduId) {
            $prePdu = $pp;
            break;
        }
    }
    if (!$prePdu) {
        $prePdu = Database::fetchOne(
            'SELECT pdu_id, name, ip_address, snmp_v3_profile_id FROM pdus WHERE pdu_id = ?',
            [$preselectPduId]
        );
    }
    if ($prePdu) {
        // Use the PDU name as the target name (admin can edit)
        $tf['name'] = (string)($prePdu['name'] ?? 'PDU');
        $tf['host'] = (string)($prePdu['ip_address'] ?? '');
        $tf['pdu_id'] = $preselectPduId;
        $tf['profile_id'] = (int)($prePdu['snmp_v3_profile_id'] ?? 0);
    }
}

layout_header('SNMP Polling', $user, 'snmp');
?>

<?php if (!$hasSnmp): ?>
<div class="alert alert-warning">
    PHP SNMP extension is not loaded. Targets can be configured now; enable <code>extension=snmp</code> (or php_snmp.dll on Windows)
    and schedule <code>scripts/poll_snmp.php</code> via Task Scheduler for live polling.
</div>
<?php endif; ?>

<div class="flex-between mb-2">
    <p class="text-muted mb-0">SNMPv3 credential profiles and poll targets for PDUs, sensors, and managed devices.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
        <input type="hidden" name="action" value="poll_now">
        <button class="btn btn-secondary" type="submit">Poll Now</button>
    </form>
</div>

<!-- SNMPv3 Profiles -->
<div class="card" id="profiles">
    <div class="card-header">
        <h2>SNMPv3 credential profiles</h2>
        <span class="text-muted" style="font-size:.8rem"><?= count($profiles) ?> active</span>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th><th>User</th><th>Level</th><th>Auth</th><th>Priv</th><th>Context</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($profiles as $p): ?>
                <tr>
                    <td><strong><?= App::e($p['name']) ?></strong></td>
                    <td><?= App::e($p['security_name']) ?></td>
                    <td><span class="badge"><?= App::e($p['security_level'] ?? '—') ?></span></td>
                    <td><?= App::e($p['auth_protocol'] ?? '—') ?><?= !empty($p['auth_passphrase']) ? ' · ••••' : '' ?></td>
                    <td><?= App::e($p['priv_protocol'] ?? '—') ?><?= !empty($p['priv_passphrase']) ? ' · ••••' : '' ?></td>
                    <td><?= App::e($p['context_name'] ?? '—') ?></td>
                    <td class="actions" style="white-space:nowrap">
                        <?php if ($canEdit): ?>
                            <a class="btn btn-sm btn-secondary" href="?edit_profile=<?= (int)$p['profile_id'] ?>#profiles">Edit</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this profile?');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="deactivate_profile">
                                <input type="hidden" name="profile_id" value="<?= (int)$p['profile_id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">×</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$profiles): ?>
                <tr><td colspan="7" class="text-muted">No profiles yet. Create one below, then select it on device SNMP settings.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($canEdit): ?>
    <div class="card-body">
        <h3 class="mt-0"><?= $editProfile ? 'Edit profile' : 'Create SNMPv3 profile' ?></h3>
        <form method="post" class="form-grid" id="snmpProfileForm">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $editProfile ? 'update_profile' : 'add_profile' ?>">
            <?php if ($editProfile): ?>
                <input type="hidden" name="profile_id" value="<?= (int)$editProfile['profile_id'] ?>">
            <?php endif; ?>
            <div class="form-row"><label>Profile name *</label>
                <input class="form-control" name="name" required
                       value="<?= App::e($editProfile['name'] ?? '') ?>"
                       placeholder="DC-Core-ReadOnly, PDU-AuthPriv…"></div>
            <div class="form-row"><label>SNMPv3 user (security name) *</label>
                <input class="form-control" name="security_name" required
                       value="<?= App::e($editProfile['security_name'] ?? '') ?>"
                       autocomplete="off"></div>
            <div class="form-row"><label>Security level</label>
                <select class="form-control" name="security_level" id="prof_sec_level">
                    <?php foreach ($secLevels as $lvl): ?>
                        <option value="<?= $lvl ?>"
                            <?= ($editProfile['security_level'] ?? 'authPriv') === $lvl ? 'selected' : '' ?>>
                            <?= $lvl ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row prof-auth"><label>Auth protocol</label>
                <select class="form-control" name="auth_protocol">
                    <option value="">—</option>
                    <?php foreach ($authProtos as $ap): ?>
                        <option value="<?= $ap ?>"
                            <?= strtoupper((string)($editProfile['auth_protocol'] ?? '')) === $ap ? 'selected' : '' ?>>
                            <?= $ap ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row prof-auth"><label>Auth passphrase</label>
                <input class="form-control" type="password" name="auth_passphrase" autocomplete="new-password"
                       placeholder="<?= $editProfile ? 'Leave blank to keep existing' : '' ?>"></div>
            <div class="form-row prof-priv"><label>Priv protocol (encryption)</label>
                <select class="form-control" name="priv_protocol">
                    <option value="">—</option>
                    <?php foreach ($privProtos as $pp): ?>
                        <option value="<?= $pp ?>"
                            <?= strtoupper((string)($editProfile['priv_protocol'] ?? '')) === $pp ? 'selected' : '' ?>>
                            <?= $pp ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row prof-priv"><label>Priv passphrase</label>
                <input class="form-control" type="password" name="priv_passphrase" autocomplete="new-password"
                       placeholder="<?= $editProfile ? 'Leave blank to keep existing' : '' ?>"></div>
            <div class="form-row"><label>Context name</label>
                <input class="form-control" name="context_name" value="<?= App::e($editProfile['context_name'] ?? '') ?>"></div>
            <div class="form-row full"><label>Notes</label>
                <input class="form-control" name="notes" value="<?= App::e($editProfile['notes'] ?? '') ?>"></div>
            <div class="form-row">
                <button class="btn btn-primary" type="submit"><?= $editProfile ? 'Save profile' : 'Create profile' ?></button>
                <?php if ($editProfile): ?>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/snmp.php#profiles')) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
        <script>
        (function () {
            var lvl = document.getElementById('prof_sec_level');
            if (!lvl) return;
            function sync() {
                var v = lvl.value;
                document.querySelectorAll('.prof-auth').forEach(function (el) {
                    el.style.display = (v === 'authNoPriv' || v === 'authPriv') ? '' : 'none';
                });
                document.querySelectorAll('.prof-priv').forEach(function (el) {
                    el.style.display = (v === 'authPriv') ? '' : 'none';
                });
            }
            lvl.addEventListener('change', sync);
            sync();
        })();
        </script>
    </div>
    <?php endif; ?>
</div>

<div class="card" id="targets">
    <div class="card-header flex-between">
        <h2>Scheduled polling</h2>
        <span class="text-muted" style="font-size:.85rem">
            <?= count($scheduledPdus) + count($scheduledDevices) + count($targets) ?> job(s) · Task Scheduler / Poll all
        </span>
    </div>
    <div class="card-body" style="padding-bottom:.5rem">
        <p class="text-muted mb-0" style="font-size:.85rem">
            Everything the SNMP scheduler will poll:
            PDUs and devices with <strong>Scheduled poll</strong> enabled on their properties page
            (site OID template), plus any free-standing targets added below.
            <strong>Poll now</strong> on a PDU/device uses the site template only and does not require a target row.
        </p>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Host</th>
                    <th>OID template</th>
                    <th>Last poll</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($scheduledPdus as $sp):
                $host = trim((string)($sp['ip_address'] ?? ''));
                $last = (string)($sp['last_poll_at'] ?? '');
                $bits = [];
                if ($sp['last_poll_watts'] !== null && $sp['last_poll_watts'] !== '') {
                    $w = (float)$sp['last_poll_watts'];
                    $bits[] = $w >= 1000
                        ? number_format($w / 1000, 3) . ' kW'
                        : rtrim(rtrim(sprintf('%.2F', $w), '0'), '.') . ' W';
                }
                if ($sp['last_poll_amps'] !== null && $sp['last_poll_amps'] !== '') {
                    $bits[] = rtrim(rtrim(sprintf('%.2F', (float)$sp['last_poll_amps']), '0'), '.') . ' A';
                }
                ?>
                <tr>
                    <td><span class="badge badge-info">PDU</span></td>
                    <td>
                        <a href="<?= App::e(App::url('pages/power_pdus.php?id=' . (int)$sp['pdu_id'])) ?>">
                            <?= App::e((string)$sp['name']) ?>
                        </a>
                    </td>
                    <td><?= App::e($host !== '' ? $host : '—') ?></td>
                    <td style="font-size:.85rem">
                        <?php
                        $spTpl = snmp_site_template_label([
                            'vendor' => $sp['template_vendor'] ?? '',
                            'model' => $sp['template_model'] ?? '',
                            'name' => $sp['template_name'] ?? '',
                        ]);
                        echo $spTpl !== '' ? App::e($spTpl) : '<span class="text-muted">No template</span>';
                        ?>
                    </td>
                    <td style="font-size:.85rem">
                        <?= $last !== '' ? App::e($last) : '—' ?>
                        <?php if ($bits): ?> · <?= App::e(implode(' · ', $bits)) ?><?php endif; ?>
                    </td>
                    <td><span class="badge badge-success">Scheduled</span></td>
                    <td class="actions" style="white-space:nowrap">
                        <a class="btn btn-sm btn-secondary"
                           href="<?= App::e(App::url('pages/power_pdus.php?id=' . (int)$sp['pdu_id'])) ?>">Open</a>
                        <?php if ($canEdit): ?>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Turn off scheduled poll for this PDU?');">
                            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                            <input type="hidden" name="action" value="unschedule_pdu">
                            <input type="hidden" name="pdu_id" value="<?= (int)$sp['pdu_id'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Unschedule</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php foreach ($scheduledDevices as $sd):
                $host = trim((string)($sd['mgmt_ip'] ?? ''));
                if ($host === '') {
                    $host = trim((string)($sd['primary_ip'] ?? ''));
                }
                $last = (string)($sd['snmp_last_poll_at'] ?? '');
                $bits = [];
                if ($sd['snmp_last_poll_watts'] !== null && $sd['snmp_last_poll_watts'] !== '') {
                    $w = (float)$sd['snmp_last_poll_watts'];
                    $bits[] = $w >= 1000
                        ? number_format($w / 1000, 3) . ' kW'
                        : rtrim(rtrim(sprintf('%.2F', $w), '0'), '.') . ' W';
                }
                if ($sd['snmp_last_poll_amps'] !== null && $sd['snmp_last_poll_amps'] !== '') {
                    $bits[] = rtrim(rtrim(sprintf('%.2F', (float)$sd['snmp_last_poll_amps']), '0'), '.') . ' A';
                }
                ?>
                <tr>
                    <td><span class="badge">Device</span></td>
                    <td>
                        <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$sd['device_id'])) ?>">
                            <?= App::e((string)$sd['label']) ?>
                        </a>
                    </td>
                    <td><?= App::e($host !== '' ? $host : '—') ?></td>
                    <td style="font-size:.85rem">
                        <?php
                        $sdTpl = snmp_site_template_label([
                            'vendor' => $sd['template_vendor'] ?? '',
                            'model' => $sd['template_model'] ?? '',
                            'name' => $sd['template_name'] ?? '',
                        ]);
                        echo $sdTpl !== '' ? App::e($sdTpl) : '<span class="text-muted">No template</span>';
                        ?>
                    </td>
                    <td style="font-size:.85rem">
                        <?= $last !== '' ? App::e($last) : '—' ?>
                        <?php if ($bits): ?> · <?= App::e(implode(' · ', $bits)) ?><?php endif; ?>
                    </td>
                    <td><span class="badge badge-success">Scheduled</span></td>
                    <td class="actions" style="white-space:nowrap">
                        <a class="btn btn-sm btn-secondary"
                           href="<?= App::e(App::url('pages/devices.php?id=' . (int)$sd['device_id'])) ?>">Open</a>
                        <?php if ($canEdit): ?>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Turn off scheduled poll for this device?');">
                            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                            <input type="hidden" name="action" value="unschedule_device">
                            <input type="hidden" name="device_id" value="<?= (int)$sd['device_id'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Unschedule</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php foreach ($targets as $t):
                $tplLab = '';
                $stId = (int)($t['site_template_id'] ?? 0);
                if ($stId > 0 && isset($siteOidById[$stId])) {
                    $tplLab = snmp_site_template_label($siteOidById[$stId]);
                }
                $linkLabel = '—';
                if (!empty($t['pdu_id'])) {
                    $linkLabel = (string)($t['pdu_name'] ?? ('PDU #' . $t['pdu_id']));
                } elseif (!empty($t['device_label'])) {
                    $linkLabel = (string)$t['device_label'];
                }
                ?>
                <tr>
                    <td><span class="badge badge-warning">Target</span></td>
                    <td>
                        <?= App::e($t['name']) ?>
                        <?php if ($linkLabel !== '—'): ?>
                            <div class="text-muted" style="font-size:.72rem">Linked: <?= App::e($linkLabel) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= App::e($t['host'] . ':' . $t['port']) ?></td>
                    <td style="font-size:.85rem"><?= $tplLab !== '' ? App::e($tplLab) : '—' ?></td>
                    <td style="font-size:.85rem"><?= App::e($t['last_success_at'] ?? '—') ?></td>
                    <td>
                        <?php if ($t['last_error']): ?>
                            <span class="badge badge-danger" title="<?= App::e($t['last_error']) ?>">Error</span>
                        <?php else: ?>
                            <span class="badge badge-success">Scheduled</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions" style="white-space:nowrap">
                        <?php if ($canEdit): ?>
                            <a class="btn btn-sm btn-secondary" href="?edit_target=<?= (int)$t['target_id'] ?>#targets">Edit</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Remove this target from scheduled polling?');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="target_id" value="<?= (int)$t['target_id'] ?>">
                                <button class="btn btn-sm btn-ghost" type="submit">Unschedule</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Permanently delete this SNMP target and its readings?');">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_target">
                                <input type="hidden" name="target_id" value="<?= (int)$t['target_id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$scheduledPdus && !$scheduledDevices && !$targets): ?>
                <tr>
                    <td colspan="7" class="text-muted">
                        Nothing scheduled yet. On a PDU or device, run <strong>Discover OIDs</strong>, then turn on
                        <strong>Scheduled poll</strong>. Optional free-standing targets can still be added below.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($canEdit): ?>
    <div class="card-body">
        <h3 class="mt-0"><?= $editTarget ? 'Edit free-standing SNMP target' : 'Add free-standing SNMP target' ?></h3>
        <p class="text-muted" style="font-size:.85rem;margin-top:0">
            Prefer <strong>Scheduled poll</strong> on the PDU/device for normal gear.
            Use a free-standing target only for sensors or hosts that are not inventory PDUs/devices.
        </p>
        <?php if ($preselectPduId && !$editTarget): ?>
            <p class="text-muted" style="font-size:.85rem;margin-top:0">
                Prefilling for existing PDU
                <a href="<?= App::e(App::url('pages/power_pdus.php?id=' . $preselectPduId)) ?>">#<?= $preselectPduId ?></a>.
            </p>
        <?php endif; ?>
        <form method="post" class="form-grid" id="snmpTargetForm">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $editTarget ? 'update_target' : 'add_target' ?>">
            <?php if ($editTarget): ?>
                <input type="hidden" name="target_id" value="<?= (int)$editTarget['target_id'] ?>">
            <?php endif; ?>
            <?php if ($preselectPduId && !$editTarget): ?>
                <input type="hidden" name="return_to_pdu" value="1">
            <?php endif; ?>
            <div class="form-row"><label>Name</label>
                <input class="form-control" name="name" id="target_name" required value="<?= App::e($tf['name']) ?>"
                       data-from-pdu="<?= ($preselectPduId && !$editTarget && $tf['name'] !== '') ? '1' : '0' ?>"></div>
            <div class="form-row"><label>Host / IP</label>
                <input class="form-control" name="host" id="target_host" required value="<?= App::e($tf['host']) ?>"
                       data-from-pdu="<?= ($preselectPduId && !$editTarget && $tf['host'] !== '') ? '1' : '0' ?>"></div>
            <div class="form-row"><label>Port</label>
                <input class="form-control" type="number" name="port" value="<?= (int)$tf['port'] ?>"></div>
            <div class="form-row"><label>Version</label>
                <select class="form-control" name="snmp_version">
                    <option value="3" <?= $tf['snmp_version'] === '3' ? 'selected' : '' ?>>3</option>
                    <option value="2c" <?= $tf['snmp_version'] === '2c' ? 'selected' : '' ?>>2c</option>
                </select>
            </div>
            <div class="form-row"><label>SNMPv3 profile</label>
                <select class="form-control" name="profile_id" id="target_profile_id"
                        data-from-pdu="<?= !empty($tf['profile_id']) && !$editTarget ? '1' : '0' ?>">
                    <option value="">— Manual credentials —</option>
                    <?php foreach ($profiles as $p): ?>
                        <option value="<?= (int)$p['profile_id'] ?>"
                            <?= (int)$tf['profile_id'] === (int)$p['profile_id'] ? 'selected' : '' ?>>
                            <?= App::e($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-muted" style="font-size:.72rem;margin:.25rem 0 0">
                    When you link a PDU that already has a credential profile, it is selected automatically.
                </p>
            </div>
            <div class="form-row target-manual"><label>Security Name (user)</label>
                <input class="form-control" name="security_name" value="<?= App::e($tf['security_name']) ?>"></div>
            <div class="form-row target-manual"><label>Auth Protocol</label>
                <select class="form-control" name="auth_protocol"><option value="">—</option>
                    <?php foreach ($authProtos as $ap): ?>
                        <option value="<?= $ap ?>" <?= strtoupper((string)$tf['auth_protocol']) === $ap ? 'selected' : '' ?>><?= $ap ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row target-manual"><label>Auth Passphrase</label>
                <input class="form-control" type="password" name="auth_passphrase"
                       placeholder="<?= $editTarget ? 'Leave blank to keep' : '' ?>" autocomplete="new-password"></div>
            <div class="form-row target-manual"><label>Priv Protocol</label>
                <select class="form-control" name="priv_protocol"><option value="">—</option>
                    <?php foreach ($privProtos as $pp): ?>
                        <option value="<?= $pp ?>" <?= strtoupper((string)$tf['priv_protocol']) === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row target-manual"><label>Priv Passphrase</label>
                <input class="form-control" type="password" name="priv_passphrase"
                       placeholder="<?= $editTarget ? 'Leave blank to keep' : '' ?>" autocomplete="new-password"></div>
            <div class="form-row target-manual"><label>Context</label>
                <input class="form-control" name="context_name" value="<?= App::e($tf['context_name']) ?>"></div>
            <script>
            (function () {
                var sel = document.getElementById('target_profile_id');
                if (!sel) return;
                function sync() {
                    var manual = !sel.value;
                    document.querySelectorAll('.target-manual').forEach(function (el) {
                        el.style.display = manual ? '' : 'none';
                    });
                }
                sel.addEventListener('change', sync);
                sync();
            })();
            </script>
            <div class="form-row"><label>Poll Interval (sec)</label>
                <input class="form-control" type="number" name="poll_interval_sec" value="<?= (int)$tf['poll_interval_sec'] ?>"></div>
            <div class="form-row"><label>Link Device</label>
                <select class="form-control" name="device_id">
                    <option value="">—</option>
                    <?php foreach ($devices as $d): ?>
                        <option value="<?= (int)$d['device_id'] ?>" <?= $tf['device_id'] === (int)$d['device_id'] ? 'selected' : '' ?>>
                            <?= App::e($d['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row"><label>Link existing PDU</label>
                <select class="form-control" name="pdu_id" id="target_pdu_id">
                    <option value="">— None —</option>
                    <?php foreach ($pdus as $p): ?>
                        <option value="<?= (int)$p['pdu_id'] ?>"
                                data-ip="<?= App::e((string)($p['ip_address'] ?? '')) ?>"
                                data-name="<?= App::e((string)($p['name'] ?? '')) ?>"
                                data-profile="<?= (int)($p['snmp_v3_profile_id'] ?? 0) ?>"
                            <?= $tf['pdu_id'] === (int)$p['pdu_id'] ? 'selected' : '' ?>>
                            <?= App::e($p['name']) ?>
                            <?= !empty($p['ip_address']) ? ' · ' . App::e((string)$p['ip_address']) : ' · (no IP)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-muted" style="font-size:.72rem;margin:.25rem 0 0">
                    Link a PDU so watt/amp metrics update its polled load. Selecting a PDU fills
                    <strong>Name</strong>, <strong>Host</strong>, and <strong>SNMPv3 profile</strong> when set on the PDU.
                </p>
            </div>

            <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">OID / metric map</h4></div>
            <div class="form-row full"><label>OID template</label>
                <select class="form-control" name="oid_template" id="oid_template_select">
                    <option value="">— Manual / blank —</option>
                    <?php
                    $byVendor = [];
                    foreach ($siteOidTemplates as $ot) {
                        $v = trim((string)($ot['vendor'] ?? '')) ?: 'Other';
                        $byVendor[$v][] = $ot;
                    }
                    $selTpl = $tfOids['oid_template'] ?: '';
                    foreach ($byVendor as $vendor => $list):
                        ?>
                        <optgroup label="<?= App::e($vendor) ?>">
                            <?php foreach ($list as $ot):
                                $optVal = 'site:' . (int)$ot['template_id'];
                                ?>
                                <option value="<?= App::e($optVal) ?>"
                                    <?= $optVal === $selTpl ? 'selected' : '' ?>>
                                    <?= App::e(trim((string)($ot['model'] ?? '')) !== ''
                                        ? (string)$ot['model']
                                        : snmp_site_template_label($ot)) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <p class="text-muted" id="oid_template_notes" style="font-size:.75rem;margin:.35rem 0 0">
                    Templates are created with <strong>Discover OIDs</strong> on a PDU or device (one per vendor + model).
                    <?php if (!$siteOidTemplates): ?>
                        None yet — discover from a live host first.
                    <?php endif; ?>
                </p>
            </div>
            <div class="form-row"><label>OID sysUpTime</label>
                <input class="form-control" name="oid_uptime" id="oid_uptime" value="<?= App::e($tfOids['oid_uptime']) ?>"></div>
            <div class="form-row"><label>OID Watts</label>
                <input class="form-control" name="oid_watts" id="oid_watts" value="<?= App::e($tfOids['oid_watts']) ?>" placeholder="1.3.6.1.4.1…"></div>
            <div class="form-row"><label>OID Amps</label>
                <input class="form-control" name="oid_amps" id="oid_amps" value="<?= App::e($tfOids['oid_amps']) ?>" placeholder="1.3.6.1.4.1…"></div>
            <div class="form-row"><label>Amps scale</label>
                <select class="form-control" name="oid_amps_metric" id="oid_amps_metric">
                    <option value="amps" <?= $tfOids['oid_amps_metric'] === 'amps' ? 'selected' : '' ?>>Amps (as reported)</option>
                    <option value="amps_x10" <?= $tfOids['oid_amps_metric'] === 'amps_x10' ? 'selected' : '' ?>>Tenths of amps (÷10 on poll)</option>
                </select>
            </div>
            <div class="form-row"><label>OID Temperature</label>
                <input class="form-control" name="oid_temp" id="oid_temp" value="<?= App::e($tfOids['oid_temp']) ?>" placeholder="optional"></div>
            <div class="form-row full">
                <p class="text-muted" style="font-size:.75rem;margin:0">
                    Metric names containing <strong>watt</strong> / <strong>amp</strong> update a linked PDU’s polled load.
                </p>
            </div>
            <div class="form-row">
                <button class="btn btn-primary" type="submit"><?= $editTarget ? 'Save target' : 'Add Target' ?></button>
                <?php if ($editTarget): ?>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/snmp.php#targets')) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
        <script>
        window.WINDCIM_OID_TEMPLATES = <?= json_encode(
            array_values(array_map(static function ($t) {
                $map = json_decode((string)($t['oid_map'] ?? '{}'), true);
                if (!is_array($map)) {
                    $map = [];
                }
                return [
                    'id' => 'site:' . (int)$t['template_id'],
                    'label' => snmp_site_template_label($t),
                    'vendor' => (string)($t['vendor'] ?? ''),
                    'notes' => (string)($t['notes'] ?? ($t['source'] ?? 'discovered')),
                    'oid_map' => $map,
                ];
            }, $siteOidTemplates)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?>;
        (function () {
            var sel = document.getElementById('oid_template_select');
            var notes = document.getElementById('oid_template_notes');
            var catalog = window.WINDCIM_OID_TEMPLATES || [];
            var editing = <?= $editTarget ? 'true' : 'false' ?>;
            var notesDefault = notes ? notes.textContent : '';
            if (!sel) return;
            function findTpl(id) {
                for (var i = 0; i < catalog.length; i++) {
                    if (catalog[i].id === id) return catalog[i];
                }
                return null;
            }
            function applyTpl() {
                var t = findTpl(sel.value);
                if (!t) {
                    if (notes) notes.textContent = notesDefault;
                    return;
                }
                if (notes) notes.textContent = t.notes || ('Template: ' + (t.label || ''));
                var map = t.oid_map || {};
                var set = function (id, v) {
                    var el = document.getElementById(id);
                    if (el) el.value = v != null ? v : '';
                };
                set('oid_uptime', map.sysUpTime || '1.3.6.1.2.1.1.3.0');
                set('oid_watts', map.watts || '');
                var amps = map.amps_x10 || map.amps || '';
                set('oid_amps', amps);
                set('oid_temp', map.temperature || map.temp || '');
                var metric = document.getElementById('oid_amps_metric');
                if (metric) metric.value = map.amps_x10 ? 'amps_x10' : 'amps';
            }
            sel.addEventListener('change', applyTpl);
            // Only auto-apply template OIDs for new targets; edit keeps saved OIDs until template changes
            if (!editing) applyTpl();
            else {
                var t0 = findTpl(sel.value);
                if (notes && t0) notes.textContent = t0.notes || ('Template: ' + (t0.label || ''));
            }

            // PDU link → always fill Name, Host, and SNMPv3 profile from the selected PDU
            var pduSel = document.getElementById('target_pdu_id');
            var hostEl = document.getElementById('target_host');
            var nameEl = document.getElementById('target_name');
            var profileEl = document.getElementById('target_profile_id');
            function applyPduLink() {
                if (!pduSel) return;
                var opt = pduSel.options[pduSel.selectedIndex];
                if (!opt || !String(opt.value || '').length) return;
                var ip = opt.getAttribute('data-ip') || '';
                var pname = opt.getAttribute('data-name') || '';
                // Prefer dataset (decoded) when available
                if (opt.dataset) {
                    if (opt.dataset.ip) ip = opt.dataset.ip;
                    if (opt.dataset.name) pname = opt.dataset.name;
                }
                var profile = (opt.dataset && opt.dataset.profile != null)
                    ? String(opt.dataset.profile)
                    : (opt.getAttribute('data-profile') || '');
                if (nameEl && pname) {
                    nameEl.value = pname;
                }
                if (hostEl && ip) {
                    hostEl.value = ip;
                }
                if (profileEl) {
                    if (profile && profile !== '0') {
                        profileEl.value = profile;
                    }
                    // Sync show/hide of manual credential fields
                    if (typeof profileEl.onchange === 'function') {
                        profileEl.onchange();
                    } else {
                        profileEl.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            }
            if (pduSel) {
                pduSel.addEventListener('change', applyPduLink);
                // If a PDU is already selected on load, fill fields (new target only)
                if (!editing && pduSel.value) {
                    applyPduLink();
                }
            }
        })();
        </script>
    </div>
    <?php endif; ?>
</div>

<div class="card" id="oid-templates">
    <div class="card-header flex-between">
        <h2>OID Templates</h2>
        <span class="text-muted" style="font-size:.85rem">
            From Discover OIDs · one per vendor + model
        </span>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Model</th>
                    <th>Metrics</th>
                    <th>Source</th>
                    <th>In use</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($siteOidTemplates as $ot):
                $map = json_decode((string)($ot['oid_map'] ?? '{}'), true) ?: [];
                $keys = [];
                foreach ($map as $k => $v) {
                    if (!is_string($k) || str_starts_with($k, '_')) {
                        continue;
                    }
                    if ($v === '' || $v === null) {
                        continue;
                    }
                    $keys[] = $k;
                }
                $inUse = (int)($ot['target_count'] ?? 0)
                    + (int)($ot['pdu_count'] ?? 0)
                    + (int)($ot['device_count'] ?? 0);
                $useBits = [];
                if ((int)($ot['target_count'] ?? 0) > 0) {
                    $useBits[] = (int)$ot['target_count'] . ' target' . ((int)$ot['target_count'] === 1 ? '' : 's');
                }
                if ((int)($ot['pdu_count'] ?? 0) > 0) {
                    $useBits[] = (int)$ot['pdu_count'] . ' PDU' . ((int)$ot['pdu_count'] === 1 ? '' : 's');
                }
                if ((int)($ot['device_count'] ?? 0) > 0) {
                    $useBits[] = (int)$ot['device_count'] . ' device' . ((int)$ot['device_count'] === 1 ? '' : 's');
                }
                ?>
                <tr>
                    <td><strong><?= App::e((string)($ot['vendor'] ?? '—')) ?></strong></td>
                    <td><?= App::e((string)($ot['model'] ?? '—')) ?></td>
                    <td style="font-size:.8rem">
                        <?php if ($keys): ?>
                            <code style="font-size:.75rem"><?= App::e(implode(', ', $keys)) ?></code>
                            <div class="text-muted" style="font-size:.72rem;margin-top:.15rem">
                                <?php
                                $samples = [];
                                foreach (array_slice($keys, 0, 3) as $mk) {
                                    $samples[] = $mk . '=' . $map[$mk];
                                }
                                echo App::e(implode(' · ', $samples));
                                if (count($keys) > 3) {
                                    echo ' …';
                                }
                                ?>
                            </div>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge"><?= App::e((string)($ot['source'] ?? 'discovered')) ?></span>
                    </td>
                    <td style="font-size:.85rem">
                        <?= $inUse > 0 ? App::e(implode(', ', $useBits)) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td style="font-size:.85rem"><?= App::e((string)($ot['updated_at'] ?? $ot['created_at'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$siteOidTemplates): ?>
                <tr>
                    <td colspan="6" class="text-muted">
                        No OID templates yet. Open a PDU or device with manufacturer, model, and IP, then use
                        <strong>Discover OIDs</strong> to create a template for that vendor + model.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body">
        <p class="text-muted mb-0" style="font-size:.8rem">
            Site templates store OID maps once and are shared by identical gear (matched by vendor + model).
            PDUs and devices reference a template by id — not by a separate display name.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Recent Readings</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead><tr><th>Time</th><th>Target</th><th>Metric</th><th>Value</th><th>Text</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= App::e($r['polled_at']) ?></td>
                    <td><?= App::e($r['target_name']) ?></td>
                    <td><?= App::e($r['metric_name']) ?></td>
                    <td><?= App::e((string)$r['metric_value']) ?></td>
                    <td><?= App::e($r['metric_text'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="5" class="text-muted">No readings yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layout_footer(); ?>
