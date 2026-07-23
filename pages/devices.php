<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_devices');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

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

$deviceStatuses = [
    'production' => 'Production',
    'testing' => 'Testing',
    'development' => 'Development',
    'reserved' => 'Reserved',
    'spare' => 'Spare',
    'decommissioning' => 'Decommissioning',
    'disposed' => 'Disposed',
];

$cabinets = Database::fetchAll(
    'SELECT c.cabinet_id, c.name, c.u_height, c.row_id,
            r.name AS room_name, r.room_id,
            dc.name AS dc_name, dc.datacenter_id,
            cr.name AS row_name,
            z.name AS zone_name, z.zone_id
     FROM cabinets c
     INNER JOIN rooms r ON r.room_id = c.room_id
     INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
     LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
     LEFT JOIN power_zones z ON z.zone_id = cr.zone_id
     WHERE c.is_active = 1
     ORDER BY dc.name, c.name'
);

$departments = Database::fetchAll(
    'SELECT department_id, name FROM departments WHERE is_active = 1 ORDER BY name'
);

// Contacts for primary-contact dropdown (also merged with users per department)
$contacts = [];
try {
    $contacts = Database::fetchAll(
        'SELECT contact_id, department_id, first_name, last_name, email
         FROM contacts WHERE is_active = 1
         ORDER BY last_name, first_name'
    );
} catch (Throwable $e) {
    $contacts = [];
}
// Active users — used as contact candidates when a department is selected
$usersAsContacts = [];
try {
    $usersAsContacts = Database::fetchAll(
        "SELECT user_id, department_id, display_name, username, email
         FROM users WHERE is_active = 1
         ORDER BY display_name, username"
    );
} catch (Throwable $e) {
    $usersAsContacts = [];
}

// Parent device candidates (chassis etc.)
$parentDevices = Database::fetchAll(
    "SELECT device_id, label, device_type FROM devices
     WHERE is_active = 1 AND device_type IN ('chassis','server','other')
     ORDER BY label"
);

// Templates (table may be empty) — include fields for auto-fill
$templates = [];
try {
    $templates = Database::fetchAll(
        'SELECT t.template_id, t.model, t.device_type, t.manufacturer_id, t.u_height,
                t.weight_kg, t.watts, t.num_power_ports, t.num_data_ports, t.snmp_template,
                m.name AS manufacturer_name
         FROM device_templates t
         LEFT JOIN manufacturers m ON m.manufacturer_id = t.manufacturer_id
         WHERE t.is_active = 1
         ORDER BY m.name, t.model'
    );
} catch (Throwable $e) {
    $templates = [];
}

function device_empty_to_null($v)
{
    if ($v === null) {
        return null;
    }
    if (is_string($v) && trim($v) === '') {
        return null;
    }
    return $v;
}

// ---- Save device ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '') && ($_POST['action'] ?? '') === 'save_device') {
    $existingForAuth = null;
    if (!empty($_POST['device_id'])) {
        $existingForAuth = Database::fetchOne(
            'SELECT device_id, department_id, label FROM devices WHERE device_id = ?',
            [(int)$_POST['device_id']]
        );
        if (!$existingForAuth) {
            App::flash('error', 'Device not found.');
            App::redirect('pages/devices.php');
        }
        if (!AuthManager::canEditDevice($user, $existingForAuth)) {
            App::flash('error', 'You do not have permission to edit this device (department ownership).');
            App::redirect('pages/devices.php?id=' . (int)$_POST['device_id']);
        }
    } elseif (!AuthManager::canEditDevice($user, null)) {
        App::flash('error', 'You do not have permission to create devices.');
        App::redirect('pages/devices.php');
    }

    $mount = strtolower((string)($_POST['mount_side'] ?? 'front'));
    $backSide = ($mount === 'rear' || $mount === 'back') ? 1 : 0;

    $data = [
        'label' => trim((string)($_POST['label'] ?? '')),
        'cabinet_id' => $_POST['cabinet_id'] !== '' ? (int)$_POST['cabinet_id'] : null,
        'position_u' => $_POST['position_u'] !== '' ? (int)$_POST['position_u'] : null,
        'u_height' => max(1, (int)($_POST['u_height'] ?? 1)),
        'half_depth' => !empty($_POST['half_depth']) ? 1 : 0,
        'back_side' => $backSide,
        'weight_kg' => $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null,
        'num_data_ports' => $_POST['num_data_ports'] !== '' ? (int)$_POST['num_data_ports'] : null,
        'num_power_ports' => $_POST['num_power_ports'] !== '' ? (int)$_POST['num_power_ports'] : null,
        'template_id' => $_POST['template_id'] !== '' ? (int)$_POST['template_id'] : null,
        'parent_device_id' => $_POST['parent_device_id'] !== '' ? (int)$_POST['parent_device_id'] : null,
        'manufacture_date' => device_empty_to_null($_POST['manufacture_date'] ?? null),
        'model' => device_empty_to_null($_POST['model'] ?? null),
        'manufacturer' => device_empty_to_null($_POST['manufacturer'] ?? null),
        'device_type' => $_POST['device_type'] ?? 'server',
        'asset_tag' => device_empty_to_null($_POST['asset_tag'] ?? null),
        'primary_ip' => device_empty_to_null($_POST['primary_ip'] ?? null),
        'serial_no' => device_empty_to_null($_POST['serial_no'] ?? null),
        'install_date' => device_empty_to_null($_POST['install_date'] ?? null),
        'warranty_provider' => device_empty_to_null($_POST['warranty_provider'] ?? null),
        'warranty_end' => device_empty_to_null($_POST['warranty_end'] ?? null),
        'department_id' => $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null,
        'nominal_watts' => $_POST['nominal_watts'] !== '' ? (float)$_POST['nominal_watts'] : null,
        'status' => $_POST['status'] ?? 'production',
        'owner_contact_id' => null,
        'tags' => device_empty_to_null($_POST['tags'] ?? null),
        'snmp_version' => device_empty_to_null($_POST['snmp_version'] ?? null),
        'snmp_community' => device_empty_to_null($_POST['snmp_community'] ?? null),
        'snmp_fail_count' => $_POST['snmp_fail_count'] !== '' ? (int)$_POST['snmp_fail_count'] : 0,
        'snmp_v3_profile_id' => !empty($_POST['snmp_v3_profile_id']) ? (int)$_POST['snmp_v3_profile_id'] : null,
        'snmp_v3_user' => device_empty_to_null($_POST['snmp_v3_user'] ?? null),
        'snmp_v3_sec_level' => device_empty_to_null($_POST['snmp_v3_sec_level'] ?? null),
        'snmp_v3_auth_proto' => device_empty_to_null($_POST['snmp_v3_auth_proto'] ?? null),
        'snmp_v3_auth_pass' => device_empty_to_null($_POST['snmp_v3_auth_pass'] ?? null),
        'snmp_v3_priv_proto' => device_empty_to_null($_POST['snmp_v3_priv_proto'] ?? null),
        'snmp_v3_priv_pass' => device_empty_to_null($_POST['snmp_v3_priv_pass'] ?? null),
        'snmp_v3_context' => device_empty_to_null($_POST['snmp_v3_context'] ?? null),
        'hostname' => device_empty_to_null($_POST['hostname'] ?? null),
        'mgmt_ip' => device_empty_to_null($_POST['mgmt_ip'] ?? null),
    ];

    // Prevent self-parent
    if (!empty($_POST['device_id']) && (int)$data['parent_device_id'] === (int)$_POST['device_id']) {
        $data['parent_device_id'] = null;
    }

    // Apply SNMPv3 profile credentials onto device fields when a profile is selected
    if (!empty($data['snmp_v3_profile_id'])) {
        try {
            $prof = Database::fetchOne(
                'SELECT * FROM snmp_v3_profiles WHERE profile_id = ? AND is_active = 1',
                [(int)$data['snmp_v3_profile_id']]
            );
            if ($prof) {
                $data['snmp_version'] = $data['snmp_version'] ?: '3';
                $data['snmp_v3_user'] = $prof['security_name'] ?? $data['snmp_v3_user'];
                $data['snmp_v3_sec_level'] = $prof['security_level'] ?? $data['snmp_v3_sec_level'];
                $data['snmp_v3_auth_proto'] = $prof['auth_protocol'] ?? $data['snmp_v3_auth_proto'];
                $data['snmp_v3_priv_proto'] = $prof['priv_protocol'] ?? $data['snmp_v3_priv_proto'];
                $data['snmp_v3_context'] = $prof['context_name'] ?? $data['snmp_v3_context'];
                if (!empty($prof['auth_passphrase'])) {
                    $data['snmp_v3_auth_pass'] = $prof['auth_passphrase']; // already sealed in profile
                }
                if (!empty($prof['priv_passphrase'])) {
                    $data['snmp_v3_priv_pass'] = $prof['priv_passphrase'];
                }
            }
        } catch (Throwable $e) {
            // table may not exist yet on first request after deploy
        }
    }

    // On edit: blank secret fields mean "keep existing"
    if (!empty($_POST['device_id'])) {
        $prevDev = Database::fetchOne(
            'SELECT snmp_community, snmp_v3_auth_pass, snmp_v3_priv_pass FROM devices WHERE device_id = ?',
            [(int)$_POST['device_id']]
        );
        if ($prevDev) {
            if (($data['snmp_community'] === null || $data['snmp_community'] === '')
                && !empty($prevDev['snmp_community'])) {
                $data['snmp_community'] = $prevDev['snmp_community'];
            }
            if (($data['snmp_v3_auth_pass'] === null || $data['snmp_v3_auth_pass'] === '')
                && !empty($prevDev['snmp_v3_auth_pass'])) {
                $data['snmp_v3_auth_pass'] = $prevDev['snmp_v3_auth_pass'];
            }
            if (($data['snmp_v3_priv_pass'] === null || $data['snmp_v3_priv_pass'] === '')
                && !empty($prevDev['snmp_v3_priv_pass'])) {
                $data['snmp_v3_priv_pass'] = $prevDev['snmp_v3_priv_pass'];
            }
        }
    }

    // Seal SNMP secrets at rest (already-encrypted values unchanged)
    $data = Crypto::sealFields($data, ['snmp_community', 'snmp_v3_auth_pass', 'snmp_v3_priv_pass']);

    // Resolve primary contact: plain contact_id, or "user:123" → ensure contacts row
    $contactRaw = trim((string)($_POST['owner_contact_id'] ?? ''));
    if ($contactRaw !== '') {
        if (preg_match('/^user:(\d+)$/', $contactRaw, $m)) {
            $uidContact = (int)$m[1];
            $uRow = Database::fetchOne(
                'SELECT user_id, display_name, username, email, department_id FROM users WHERE user_id = ? AND is_active = 1',
                [$uidContact]
            );
            if ($uRow) {
                $email = trim((string)($uRow['email'] ?? ''));
                $existingCt = null;
                if ($email !== '') {
                    $existingCt = Database::fetchOne(
                        'SELECT contact_id FROM contacts WHERE LOWER(email) = LOWER(?) AND is_active = 1',
                        [$email]
                    );
                }
                if ($existingCt) {
                    $data['owner_contact_id'] = (int)$existingCt['contact_id'];
                } else {
                    $name = trim((string)($uRow['display_name'] ?? ''));
                    if ($name === '') {
                        $name = (string)($uRow['username'] ?? 'User');
                    }
                    $parts = preg_split('/\s+/', $name, 2);
                    $first = $parts[0] ?? 'User';
                    $last = $parts[1] ?? ($uRow['username'] ?? '');
                    $newCid = Database::insert('contacts', [
                        'department_id' => !empty($uRow['department_id']) ? (int)$uRow['department_id'] : ($data['department_id'] ?? null),
                        'first_name' => $first,
                        'last_name' => $last !== '' ? $last : '—',
                        'email' => $email !== '' ? $email : null,
                        'title' => 'User account',
                        'is_active' => 1,
                    ]);
                    if (!$newCid && $email !== '') {
                        $found = Database::fetchOne(
                            'SELECT TOP 1 contact_id FROM contacts WHERE LOWER(email) = LOWER(?) ORDER BY contact_id DESC',
                            [$email]
                        );
                        $newCid = $found ? (int)$found['contact_id'] : 0;
                    }
                    $data['owner_contact_id'] = $newCid ?: null;
                }
            }
        } else {
            $data['owner_contact_id'] = (int)$contactRaw ?: null;
        }
    }

    // Department-scoped users cannot reassign devices out of their department
    if (!AuthManager::isAdmin($user) && !empty($user['department_id'])) {
        $userDept = (int)$user['department_id'];
        if ($data['department_id'] === null) {
            $data['department_id'] = $userDept;
        } elseif ((int)$data['department_id'] !== $userDept) {
            App::flash('error', 'You can only assign devices to your own department.');
            $redir = !empty($_POST['device_id'])
                ? 'pages/devices.php?id=' . (int)$_POST['device_id'] . '&action=edit'
                : 'pages/devices.php?action=new';
            App::redirect($redir);
        }
    }

    try {
        if ($data['label'] === '') {
            throw new RuntimeException('Device Name is required.');
        }
        if (!isset($deviceTypes[$data['device_type']])) {
            $data['device_type'] = 'other';
        }
        if (!isset($deviceStatuses[$data['status']])) {
            $data['status'] = 'production';
        }

        // U conflict check
        if ($data['cabinet_id'] && $data['position_u'] !== null) {
            $exclude = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : 0;
            $others = Database::fetchAll(
                'SELECT device_id, label, position_u, u_height FROM devices
                 WHERE cabinet_id = ? AND is_active = 1 AND position_u IS NOT NULL' .
                ($exclude ? ' AND device_id <> ' . $exclude : ''),
                [$data['cabinet_id']]
            );
            $end = $data['position_u'] + $data['u_height'] - 1;
            foreach ($others as $o) {
                $os = (int)$o['position_u'];
                $oe = $os + (int)$o['u_height'] - 1;
                if ($data['position_u'] <= $oe && $end >= $os) {
                    throw new RuntimeException("U-space conflict with {$o['label']}");
                }
            }
        }

        if (!empty($_POST['device_id'])) {
            $did = (int)$_POST['device_id'];
            $data['updated_at'] = date('Y-m-d H:i:s');
            Database::update('devices', $data, 'device_id = :id', [':id' => $did]);
            AuditService::log((int)$user['user_id'], $user['username'], 'update', 'device', $did);
            App::flash('success', 'Device updated.');
            App::redirect('pages/devices.php?id=' . $did);
        } else {
            $data['is_active'] = 1;
            $did = Database::insert('devices', $data);
            if (!$did) {
                $row = Database::fetchOne(
                    'SELECT TOP 1 device_id FROM devices WHERE label = ? ORDER BY device_id DESC',
                    [$data['label']]
                );
                $did = $row ? (int)$row['device_id'] : 0;
            }
            // Auto-create ports from counts
            $dp = (int)($data['num_data_ports'] ?? 0);
            $pp = (int)($data['num_power_ports'] ?? 0);
            for ($i = 1; $i <= $dp; $i++) {
                Database::insert('device_ports', [
                    'device_id' => $did,
                    'port_type' => 'data',
                    'port_number' => $i,
                    'label' => 'Eth' . $i,
                    'media_type' => 'RJ45',
                ]);
            }
            for ($i = 1; $i <= $pp; $i++) {
                Database::insert('device_ports', [
                    'device_id' => $did,
                    'port_type' => 'power',
                    'port_number' => $i,
                    'label' => 'PSU' . $i,
                    'media_type' => 'C14',
                ]);
            }
            AuditService::log((int)$user['user_id'], $user['username'], 'create', 'device', $did);
            App::flash('success', 'Device created.');
            App::redirect('pages/devices.php?id=' . $did);
        }
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
        if (!empty($_POST['device_id'])) {
            App::redirect('pages/devices.php?id=' . (int)$_POST['device_id']);
        }
        App::redirect('pages/devices.php?action=new');
    }
}

// ---- Add timestamped note ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '') && ($_POST['action'] ?? '') === 'add_note') {
    $did = (int)($_POST['device_id'] ?? 0);
    $text = trim((string)($_POST['note_text'] ?? ''));
    try {
        if ($did <= 0 || $text === '') {
            throw new RuntimeException('Note text is required.');
        }
        $noteDev = Database::fetchOne('SELECT device_id, department_id FROM devices WHERE device_id = ?', [$did]);
        if (!$noteDev || !AuthManager::canEditDevice($user, $noteDev)) {
            throw new RuntimeException('You do not have permission to add notes on this device.');
        }
        Database::insert('device_notes', [
            'device_id' => $did,
            'user_id' => (int)$user['user_id'],
            'username' => $user['username'] ?? null,
            'note_text' => $text,
        ]);
        App::flash('success', 'Note added.');
    } catch (Throwable $e) {
        App::flash('error', $e->getMessage());
    }
    App::redirect('pages/devices.php?id=' . $did);
}

// ---- Port update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_port' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    Database::update('device_ports', [
        'label' => $_POST['label'] ?? null,
        'media_type' => $_POST['media_type'] ?? null,
        'speed' => $_POST['speed'] ?? null,
        'notes' => $_POST['notes'] ?? null,
    ], 'port_id = :id', [':id' => (int)$_POST['port_id']]);
    App::flash('success', 'Port updated.');
    App::redirect('pages/devices.php?id=' . (int)$_POST['device_id']);
}

// ---- Device detail (view) or form (new / edit) ----
if ($action === 'new' || $id) {
    $device = null;
    if ($id) {
        try {
            $device = Database::fetchOne(
                'SELECT d.*,
                        t.front_picture AS tpl_front,
                        t.rear_picture AS tpl_rear,
                        t.model AS tpl_model,
                        t.device_type AS tpl_device_type,
                        dep.name AS department_name,
                        dep.color_hex AS department_color,
                        ct.first_name AS contact_first,
                        ct.last_name AS contact_last,
                        ct.email AS contact_email,
                        pd.label AS parent_label
                 FROM devices d
                 LEFT JOIN device_templates t ON t.template_id = d.template_id
                 LEFT JOIN departments dep ON dep.department_id = d.department_id
                 LEFT JOIN contacts ct ON ct.contact_id = d.owner_contact_id
                 LEFT JOIN devices pd ON pd.device_id = d.parent_device_id
                 WHERE d.device_id = ?',
                [$id]
            );
        } catch (Throwable $e) {
            $device = Database::fetchOne('SELECT * FROM devices WHERE device_id = ?', [$id]);
        }
    }
    if ($id && !$device) {
        App::flash('error', 'Device not found.');
        App::redirect('pages/devices.php');
    }

    $ports = $id ? Database::fetchAll(
        'SELECT * FROM device_ports WHERE device_id = ? ORDER BY port_type, port_number',
        [$id]
    ) : [];

    // Enrich data ports with cable peer (best-effort)
    $dataPortLinks = [];
    if ($id) {
        try {
            $dataPortLinks = Database::fetchAll(
                "SELECT p.port_id, p.port_number, p.label, p.media_type, p.speed, p.notes,
                        c.cable_id, c.cable_label, c.media_type AS cable_media, c.length_m,
                        peer.port_id AS peer_port_id,
                        peer.label AS peer_port_label,
                        peer.port_number AS peer_port_number,
                        peer_dev.device_id AS peer_device_id,
                        peer_dev.label AS peer_device_label
                 FROM device_ports p
                 LEFT JOIN cables c ON (c.a_port_id = p.port_id OR c.b_port_id = p.port_id)
                      AND c.status <> 'retired'
                 LEFT JOIN device_ports peer ON peer.port_id = CASE
                        WHEN c.a_port_id = p.port_id THEN c.b_port_id
                        WHEN c.b_port_id = p.port_id THEN c.a_port_id
                        ELSE NULL END
                 LEFT JOIN devices peer_dev ON peer_dev.device_id = peer.device_id
                 WHERE p.device_id = ? AND p.port_type = 'data'
                 ORDER BY p.port_number",
                [$id]
            );
        } catch (Throwable $e) {
            $dataPortLinks = array_values(array_filter($ports, static fn($p) => ($p['port_type'] ?? '') === 'data'));
        }
    }

    $deviceNotes = [];
    if ($id) {
        try {
            $deviceNotes = Database::fetchAll(
                'SELECT * FROM device_notes WHERE device_id = ? ORDER BY created_at DESC',
                [$id]
            );
        } catch (Throwable $e) {
            $deviceNotes = [];
        }
    }

    // Power supplies + PDUs in same cabinet (for mapping)
    $powerSupplies = [];
    $cabinetPdus = [];
    if ($id && $device) {
        try {
            $powerSupplies = Database::fetchAll(
                'SELECT ps.*,
                        p.name AS pdu_name,
                        o.outlet_number,
                        o.outlet_type AS pdu_outlet_type
                 FROM device_power_supplies ps
                 LEFT JOIN pdus p ON p.pdu_id = ps.pdu_id
                 LEFT JOIN pdu_outlets o ON o.outlet_id = ps.pdu_outlet_id
                 WHERE ps.device_id = ?
                 ORDER BY ps.sort_order, ps.power_supply_id',
                [$id]
            );
        } catch (Throwable $e) {
            $powerSupplies = [];
        }
        $cabForPdu = (int)($device['cabinet_id'] ?? 0);
        if ($cabForPdu) {
            try {
                $cabinetPdus = Database::fetchAll(
                    'SELECT pdu_id, name, num_outlets FROM pdus
                     WHERE cabinet_id = ? AND is_active = 1 ORDER BY name',
                    [$cabForPdu]
                );
                foreach ($cabinetPdus as &$cp) {
                    $cp['outlets'] = Database::fetchAll(
                        'SELECT outlet_id, outlet_number, outlet_type, rated_amps,
                                connected_device_id, device_power_supply_id
                         FROM pdu_outlets WHERE pdu_id = ? ORDER BY outlet_number',
                        [(int)$cp['pdu_id']]
                    );
                }
                unset($cp);
            } catch (Throwable $e) {
                $cabinetPdus = [];
            }
        }
    }

    $nemaTypes = [
        'C13', 'C14', 'C19', 'C20', '5-15P', '5-20P', 'L5-20P', 'L5-30P',
        'L6-20P', 'L6-30P', 'L14-30P', 'IEC 60309', 'Other',
    ];

    $snmpProfiles = [];
    try {
        $snmpProfiles = Database::fetchAll(
            'SELECT profile_id, name, security_name, security_level,
                    auth_protocol, auth_passphrase, priv_protocol, priv_passphrase, context_name
             FROM snmp_v3_profiles WHERE is_active = 1 ORDER BY name'
        );
    } catch (Throwable $e) {
        $snmpProfiles = [];
    }

    // Location context from cabinet
    $loc = [
        'dc_name' => '',
        'zone_name' => '',
        'row_name' => '',
        'cabinet_name' => '',
        'room_name' => '',
    ];
    $cabId = (int)($_GET['cabinet_id'] ?? ($device['cabinet_id'] ?? 0));
    foreach ($cabinets as $c) {
        if ((int)$c['cabinet_id'] === $cabId) {
            $loc = [
                'dc_name' => $c['dc_name'] ?? '',
                'zone_name' => $c['zone_name'] ?? '',
                'row_name' => $c['row_name'] ?? '',
                'cabinet_name' => $c['name'] ?? '',
                'room_name' => $c['room_name'] ?? '',
            ];
            break;
        }
    }

    $defaults = [
        'cabinet_id' => $_GET['cabinet_id'] ?? ($device['cabinet_id'] ?? ''),
        'position_u' => $_GET['position_u'] ?? ($device['position_u'] ?? ''),
    ];

    // Prefer explicit ?mount=rear from rack rear elevation empty-slot clicks
    if (isset($_GET['mount']) && !$device) {
        $mountSide = strtolower((string)$_GET['mount']) === 'rear' ? 'rear' : 'front';
    } else {
        $mountSide = !empty($device['back_side']) ? 'rear' : 'front';
    }

    // JSON for cabinet location map (client-side DC/Row/Zone update)
    $cabLocMap = [];
    foreach ($cabinets as $c) {
        $cabLocMap[(int)$c['cabinet_id']] = [
            'dc' => $c['dc_name'] ?? '',
            'zone' => $c['zone_name'] ?? '',
            'row' => $c['row_name'] ?? '',
            'name' => $c['name'] ?? '',
        ];
    }

    $mediaUrl = static function (?string $rel): string {
        if (!$rel) {
            return '';
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        return App::url('media.php?f=' . rawurlencode($rel));
    };

    // ========== CLEAN VIEW MODE (existing device, not editing) ==========
    if ($device && $action !== 'edit' && $action !== 'new') {
        $frontImg = $mediaUrl($device['tpl_front'] ?? null);
        $rearImg = $mediaUrl($device['tpl_rear'] ?? null);
        $typeLabel = $deviceTypes[$device['device_type'] ?? ''] ?? ($device['device_type'] ?? '—');
        $statusLabel = $deviceStatuses[$device['status'] ?? ''] ?? ($device['status'] ?? '—');
        $posU = $device['position_u'] !== null ? (int)$device['position_u'] : null;
        $uH = max(1, (int)($device['u_height'] ?? 1));
        $uRange = $posU !== null ? ('U' . $posU . '–' . ($posU + $uH - 1)) : '—';
        $half = !empty($device['half_depth']);
        $mountLabel = $half
            ? (!empty($device['back_side']) ? 'Half-depth · Rear' : 'Half-depth · Front')
            : 'Full depth';
        $powerPorts = array_values(array_filter($ports, static fn($p) => ($p['port_type'] ?? '') === 'power'));
        $dataPorts = array_values(array_filter($ports, static fn($p) => ($p['port_type'] ?? '') === 'data'));
        $numPower = max(
            (int)($device['num_power_ports'] ?? 0),
            count($powerSupplies),
            count($powerPorts)
        );
        $numData = max(
            (int)($device['num_data_ports'] ?? 0),
            count($dataPortLinks) ?: count($dataPorts)
        );
        // Index data links by port number
        $dataByNum = [];
        foreach ($dataPortLinks as $dp) {
            $dataByNum[(int)$dp['port_number']] = $dp;
        }
        foreach ($dataPorts as $dp) {
            $n = (int)$dp['port_number'];
            if (!isset($dataByNum[$n])) {
                $dataByNum[$n] = $dp;
            }
        }
        $contactName = trim(($device['contact_last'] ?? '') . ', ' . ($device['contact_first'] ?? ''), ' ,');
        if ($contactName === '' || $contactName === ',') {
            $contactName = '—';
        } elseif (!empty($device['contact_email'])) {
            $contactName .= ' · ' . $device['contact_email'];
        }

        $dash = static function ($v): string {
            if ($v === null || $v === '') {
                return '—';
            }
            return App::e((string)$v);
        };

        $canEditThis = AuthManager::canEditDevice($user, $device);
        $deptColor = trim((string)($device['department_color'] ?? ''));
        if ($deptColor !== '' && $deptColor[0] !== '#') {
            $deptColor = '#' . $deptColor;
        }
        if ($deptColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $deptColor)) {
            $deptColor = '';
        }

        layout_header('Device: ' . $device['label'], $user, 'devices');
        ?>
        <div class="flex-between mb-2">
            <div>
                <span class="badge badge-info"><?= App::e($typeLabel) ?></span>
                <span class="badge <?= ($device['status'] ?? '') === 'production' ? 'badge-success' : 'badge-warning' ?>">
                    <?= App::e($statusLabel) ?>
                </span>
                <?php if (!empty($device['department_name'])): ?>
                    <span class="dept-chip" style="margin-left:.35rem">
                        <?php if ($deptColor !== ''): ?>
                            <span class="dept-swatch sm" style="background:<?= App::e($deptColor) ?>"></span>
                        <?php endif; ?>
                        <?= App::e($device['department_name']) ?>
                    </span>
                <?php endif; ?>
                <?php if ($loc['cabinet_name']): ?>
                    <span class="text-muted" style="margin-left:.35rem;font-size:.9rem">
                        <?= App::e(trim($loc['dc_name'] . ' / ' . $loc['room_name'] . ' / ' . $loc['cabinet_name'], ' /')) ?>
                        · <?= App::e($uRange) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="flex gap-1">
                <a class="btn btn-secondary" href="<?= App::e(App::url('pages/devices.php')) ?>">← Devices</a>
                <?php if (!empty($device['cabinet_id'])): ?>
                    <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$device['cabinet_id'])) ?>">Cabinet</a>
                <?php endif; ?>
                <?php if ($canEditThis): ?>
                    <a class="btn btn-primary" href="<?= App::e(App::url('pages/devices.php?id=' . (int)$device['device_id'] . '&action=edit')) ?>">Edit</a>
                    <?php
                    $devStatus = (string)($device['status'] ?? '');
                    $openDecomId = null;
                    try {
                        $openDecom = Database::fetchOne(
                            "SELECT disposal_id FROM disposals
                             WHERE device_id = ? AND status NOT IN ('completed','cancelled')
                             ORDER BY disposal_id DESC",
                            [(int)$device['device_id']]
                        );
                        $openDecomId = $openDecom ? (int)$openDecom['disposal_id'] : null;
                    } catch (Throwable $e) {
                        $openDecomId = null;
                    }
                    $canDecommission = $devStatus !== 'disposed' && !$openDecomId;
                    ?>
                    <?php if ($openDecomId): ?>
                        <a class="btn btn-warning" href="<?= App::e(App::url('pages/disposals.php?id=' . $openDecomId)) ?>">Open decommission</a>
                    <?php elseif ($canDecommission): ?>
                        <a class="btn btn-warning" href="<?= App::e(App::url('pages/disposals.php?action=start&device_id=' . (int)$device['device_id'])) ?>">Decommission</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="device-view-grid">
            <!-- Left: images -->
            <div class="device-view-left">
                <div class="card device-img-card">
                    <div class="card-header"><h2>Front</h2></div>
                    <div class="card-body device-img-body">
                        <?php if ($frontImg !== ''): ?>
                            <img src="<?= App::e($frontImg) ?>" alt="Front of <?= App::e($device['label']) ?>" class="device-face-img">
                        <?php else: ?>
                            <div class="device-img-placeholder">No front image<br><span class="text-muted">Set on device template</span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card device-img-card">
                    <div class="card-header"><h2>Rear</h2></div>
                    <div class="card-body device-img-body">
                        <?php if ($rearImg !== ''): ?>
                            <img src="<?= App::e($rearImg) ?>" alt="Rear of <?= App::e($device['label']) ?>" class="device-face-img">
                        <?php else: ?>
                            <div class="device-img-placeholder">No rear image<br><span class="text-muted">Set on device template</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right: property panes -->
            <div class="device-view-right">
                <div class="card view-pane">
                    <div class="card-header"><h2>Physical infrastructure</h2></div>
                    <div class="card-body">
                        <dl class="view-dl">
                            <div><dt>Class / type</dt><dd><?= App::e($typeLabel) ?></dd></div>
                            <div><dt>Manufacturer</dt><dd><?= $dash($device['manufacturer'] ?? null) ?></dd></div>
                            <div><dt>Model</dt><dd><?= $dash($device['model'] ?? ($device['tpl_model'] ?? null)) ?></dd></div>
                            <div><dt>Template</dt><dd><?= !empty($device['template_id']) ? $dash($device['tpl_model'] ?? ('#' . $device['template_id'])) : '—' ?></dd></div>
                            <div><dt>Height</dt><dd><?= (int)$uH ?>U</dd></div>
                            <div><dt>Weight</dt><dd><?= $device['weight_kg'] !== null && $device['weight_kg'] !== '' ? App::e((string)$device['weight_kg']) . ' kg' : '—' ?></dd></div>
                            <div><dt>U position</dt><dd><?= App::e($uRange) ?></dd></div>
                            <div><dt>Mount</dt><dd><?= App::e($mountLabel) ?></dd></div>
                            <div><dt>Cabinet</dt><dd>
                                <?php if (!empty($device['cabinet_id'])): ?>
                                    <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$device['cabinet_id'])) ?>"><?= App::e($loc['cabinet_name'] ?: ('#' . $device['cabinet_id'])) ?></a>
                                <?php else: ?>—<?php endif; ?>
                            </dd></div>
                            <div><dt>Data center</dt><dd class="text-muted-dd"><?= $dash($loc['dc_name'] ?: null) ?> <span class="view-derived">(from cabinet)</span></dd></div>
                            <div><dt>Room</dt><dd class="text-muted-dd"><?= $dash($loc['room_name'] ?: null) ?> <span class="view-derived">(from cabinet)</span></dd></div>
                            <div><dt>Row</dt><dd class="text-muted-dd"><?= $dash($loc['row_name'] ?: null) ?> <span class="view-derived">(from cabinet)</span></dd></div>
                            <div><dt>Zone</dt><dd class="text-muted-dd"><?= $dash($loc['zone_name'] ?: null) ?> <span class="view-derived">(from row)</span></dd></div>
                            <div><dt>Parent chassis</dt><dd>
                                <?php if (!empty($device['parent_device_id'])): ?>
                                    <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$device['parent_device_id'])) ?>"><?= App::e($device['parent_label'] ?? ('#' . $device['parent_device_id'])) ?></a>
                                <?php else: ?>—<?php endif; ?>
                            </dd></div>
                            <div><dt>Wattage draw</dt><dd><?= $device['nominal_watts'] !== null && $device['nominal_watts'] !== '' ? App::e((string)$device['nominal_watts']) . ' W' : '—' ?></dd></div>
                            <div><dt>Data ports</dt><dd><?= (int)($device['num_data_ports'] ?? count($dataPorts)) ?></dd></div>
                            <div><dt>Power connections</dt><dd><?= (int)($device['num_power_ports'] ?? max(count($powerSupplies), count($powerPorts))) ?></dd></div>
                        </dl>
                    </div>
                </div>

                <div class="card view-pane">
                    <div class="card-header"><h2>Asset tracking</h2></div>
                    <div class="card-body">
                        <dl class="view-dl">
                            <div><dt>Device ID</dt><dd><code><?= (int)$device['device_id'] ?></code></dd></div>
                            <div><dt>Status</dt><dd><span class="badge"><?= App::e($statusLabel) ?></span></dd></div>
                            <div><dt>Name</dt><dd><strong><?= App::e($device['label'] ?? '') ?></strong></dd></div>
                            <div><dt>Hostname</dt><dd><?= $dash($device['hostname'] ?? null) ?></dd></div>
                            <div><dt>Serial number</dt><dd><?= $dash($device['serial_no'] ?? null) ?></dd></div>
                            <div><dt>Asset tag</dt><dd><?= $dash($device['asset_tag'] ?? null) ?></dd></div>
                            <div><dt>Primary IP</dt><dd><?= $dash($device['primary_ip'] ?? null) ?></dd></div>
                            <div><dt>Management IP</dt><dd><?= $dash($device['mgmt_ip'] ?? null) ?></dd></div>
                            <div><dt>Manufacture date</dt><dd><?= $dash($device['manufacture_date'] ?? null) ?></dd></div>
                            <div><dt>Install date</dt><dd><?= $dash($device['install_date'] ?? null) ?></dd></div>
                            <div><dt>Warranty company</dt><dd><?= $dash($device['warranty_provider'] ?? null) ?></dd></div>
                            <div><dt>Warranty expiration</dt><dd><?= $dash($device['warranty_end'] ?? null) ?></dd></div>
                            <div><dt>Department owner</dt><dd>
                                <?php if (!empty($device['department_name'])): ?>
                                    <span class="dept-chip">
                                        <?php if ($deptColor !== ''): ?>
                                            <span class="dept-swatch sm" style="background:<?= App::e($deptColor) ?>"></span>
                                        <?php endif; ?>
                                        <?= App::e($device['department_name']) ?>
                                    </span>
                                <?php else: ?>—<?php endif; ?>
                            </dd></div>
                            <div><dt>Primary contact</dt><dd><?= App::e($contactName) ?></dd></div>
                            <div class="full"><dt>Tags</dt><dd>
                                <?php
                                $tags = array_filter(array_map('trim', explode(',', (string)($device['tags'] ?? ''))));
                                if ($tags):
                                    foreach ($tags as $tag): ?>
                                        <span class="badge badge-info" style="margin:0 .25rem .25rem 0"><?= App::e($tag) ?></span>
                                    <?php endforeach;
                                else: ?>
                                    —
                                <?php endif; ?>
                            </dd></div>
                        </dl>
                    </div>
                </div>

                <div class="card view-pane">
                    <div class="card-header flex-between" style="width:100%">
                        <h2 style="margin:0">Notes</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($deviceNotes): ?>
                            <ul class="view-notes-list">
                                <?php foreach ($deviceNotes as $n): ?>
                                    <li>
                                        <div class="view-note-meta">
                                            <span><?= App::e($n['created_at'] ?? '') ?></span>
                                            <span><?= App::e($n['username'] ?? '—') ?></span>
                                        </div>
                                        <div class="view-note-text"><?= nl2br(App::e($n['note_text'] ?? '')) ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-1">No notes yet.</p>
                        <?php endif; ?>
                        <?php if (!empty($device['notes'])): ?>
                            <details class="mt-1">
                                <summary class="text-muted" style="cursor:pointer;font-size:.85rem">Legacy freeform notes</summary>
                                <pre class="view-legacy-notes"><?= App::e($device['notes']) ?></pre>
                            </details>
                        <?php endif; ?>
                        <?php if ($canEditThis): ?>
                        <form method="post" class="view-add-note">
                            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                            <input type="hidden" name="action" value="add_note">
                            <input type="hidden" name="device_id" value="<?= (int)$device['device_id'] ?>">
                            <textarea class="form-control" name="note_text" rows="2" required placeholder="Add a note…"></textarea>
                            <button class="btn btn-sm btn-secondary" type="submit">Add note</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card view-pane">
                    <div class="card-header"><h2>Power connections</h2></div>
                    <div class="card-body flush">
                        <?php if ($numPower <= 0): ?>
                            <p class="text-muted" style="padding:1rem;margin:0">No power connections defined. Set “Number of Power ports” when editing.</p>
                        <?php else: ?>
                            <table class="data">
                                <thead>
                                <tr>
                                    <th>#</th><th>Name</th><th>Watts</th><th>Connector</th>
                                    <th>PDU</th><th>Plug #</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php for ($i = 0; $i < $numPower; $i++):
                                    $ps = $powerSupplies[$i] ?? null;
                                    $pp = $powerPorts[$i] ?? null;
                                    $name = $ps['name'] ?? ($pp['label'] ?? ('PSU-' . ($i + 1)));
                                    $watts = $ps['watts'] ?? null;
                                    $conn = $ps['connector_type'] ?? ($pp['media_type'] ?? null);
                                    $pduName = $ps['pdu_name'] ?? null;
                                    $plug = $ps['outlet_number'] ?? null;
                                    ?>
                                    <tr class="<?= $ps || $pp ? '' : 'row-empty' ?>">
                                        <td><?= $i + 1 ?></td>
                                        <td><?= App::e($name) ?></td>
                                        <td><?= $watts !== null && $watts !== '' ? App::e((string)$watts) . ' W' : '—' ?></td>
                                        <td><?= $dash($conn) ?></td>
                                        <td><?= $dash($pduName) ?></td>
                                        <td><?= $plug !== null && $plug !== '' ? '#' . App::e((string)$plug) : '—' ?></td>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card view-pane">
                    <div class="card-header"><h2>Data connections</h2></div>
                    <div class="card-body flush">
                        <?php if ($numData <= 0): ?>
                            <p class="text-muted" style="padding:1rem;margin:0">No data ports defined. Set “Number of Data ports” when editing.</p>
                        <?php else: ?>
                            <table class="data">
                                <thead>
                                <tr>
                                    <th>#</th><th>Label</th><th>Media</th><th>Speed</th>
                                    <th>Connected to</th><th>Cable</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php for ($i = 1; $i <= $numData; $i++):
                                    $dp = $dataByNum[$i] ?? null;
                                    $peer = '—';
                                    if ($dp && !empty($dp['peer_device_id'])) {
                                        $peer = '<a href="' . App::e(App::url('pages/devices.php?id=' . (int)$dp['peer_device_id'])) . '">'
                                            . App::e($dp['peer_device_label'] ?? ('Device #' . $dp['peer_device_id']))
                                            . '</a>';
                                        if (!empty($dp['peer_port_label']) || !empty($dp['peer_port_number'])) {
                                            $peer .= ' <span class="text-muted">· '
                                                . App::e($dp['peer_port_label'] ?? ('Port ' . $dp['peer_port_number']))
                                                . '</span>';
                                        }
                                    }
                                    $cable = '—';
                                    if ($dp && !empty($dp['cable_id'])) {
                                        $cable = App::e($dp['cable_label'] ?: ('Cable #' . $dp['cable_id']));
                                        if (!empty($dp['cable_media'])) {
                                            $cable .= ' <span class="text-muted">(' . App::e($dp['cable_media']) . ')</span>';
                                        }
                                    }
                                    ?>
                                    <tr class="<?= $dp ? '' : 'row-empty' ?>">
                                        <td><?= $i ?></td>
                                        <td><?= App::e($dp['label'] ?? ('Port ' . $i)) ?></td>
                                        <td><?= $dash($dp['media_type'] ?? null) ?></td>
                                        <td><?= $dash($dp['speed'] ?? null) ?></td>
                                        <td><?= $peer ?></td>
                                        <td><?= $cable ?></td>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>
                            <p class="text-muted" style="font-size:.75rem;padding:.5rem 1rem;margin:0">
                                Connections resolve from cabling records when present. Full cable mapping can be managed under Cabling.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($device['snmp_version'])):
                    $siteTpl = null;
                    $siteTplId = (int)($device['snmp_site_template_id'] ?? 0);
                    if ($siteTplId > 0) {
                        try {
                            $siteTpl = Database::fetchOne(
                                'SELECT template_id, name, vendor, model, oid_map, source, updated_at
                                 FROM snmp_site_oid_templates WHERE template_id = ?',
                                [$siteTplId]
                            ); // name kept for uniqueness; UI shows vendor/model
                        } catch (Throwable $e) {
                            $siteTpl = null;
                        }
                    }
                    $siteOidCount = 0;
                    if ($siteTpl && !empty($siteTpl['oid_map'])) {
                        $m = json_decode((string)$siteTpl['oid_map'], true);
                        if (is_array($m)) {
                            foreach ($m as $k => $v) {
                                if (!is_string($k) || str_starts_with($k, '_')) {
                                    continue;
                                }
                                if (is_string($v) && $v !== '') {
                                    $siteOidCount++;
                                }
                            }
                        }
                    }
                    $autoPoll = !empty($device['snmp_auto_poll']);
                    $discoverHost = trim((string)($device['mgmt_ip'] ?? ''));
                    if ($discoverHost === '') {
                        $discoverHost = trim((string)($device['primary_ip'] ?? ''));
                    }
                    $discoverReady = trim((string)($device['manufacturer'] ?? '')) !== ''
                        && trim((string)($device['model'] ?? '')) !== ''
                        && $discoverHost !== '';
                    $canSnmpActions = $canEditThis || AuthManager::canEditSnmp($user);
                ?>
                <div class="card view-pane" id="deviceSnmpCard">
                    <div class="card-header flex-between">
                        <h2>SNMP</h2>
                        <?php if ($canSnmpActions): ?>
                        <div class="flex gap-1" style="align-items:center;flex-wrap:wrap">
                            <label class="snmp-toggle" title="<?= $siteTplId > 0
                                ? 'Include this device in the SNMP scheduler (site OID template)'
                                : 'Run Discover OIDs first to assign a site template' ?>">
                                <input type="checkbox" id="snmpAutoPollToggle"
                                    <?= $autoPoll ? 'checked' : '' ?>
                                    <?= $siteTplId > 0 ? '' : 'disabled' ?>>
                                <span class="snmp-switch" aria-hidden="true"></span>
                                <span class="snmp-toggle-label" id="snmpAutoPollLabel">
                                    Scheduled poll <?= $autoPoll ? 'on' : 'off' ?>
                                </span>
                            </label>
                            <button type="button" class="btn btn-secondary btn-sm" id="btnSnmpDiscover"
                                <?= $discoverReady ? '' : 'disabled title="Need manufacturer, model, and IP"' ?>>
                                Discover OIDs
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" id="btnSnmpPollNow"
                                <?= $siteTplId > 0 ? '' : 'disabled title="Assign a site OID template first (Discover OIDs)"' ?>>
                                Poll now
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <dl class="view-dl">
                            <div><dt>Version</dt><dd><?= App::e((string)$device['snmp_version']) ?></dd></div>
                            <div><dt>Site template</dt><dd id="snmpTplName">
                                <?php if ($siteTpl):
                                    $devTplLabel = trim(($siteTpl['vendor'] ?? '') . ' / ' . ($siteTpl['model'] ?? ''), ' /');
                                    if ($devTplLabel === '') {
                                        $devTplLabel = (string)($siteTpl['name'] ?? '');
                                    }
                                    ?>
                                    <strong><?= App::e($devTplLabel) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">None — run Discover OIDs</span>
                                <?php endif; ?>
                                · <a href="<?= App::e(App::url('pages/snmp.php#oid-templates')) ?>">OID templates</a>
                            </dd></div>
                            <div><dt>Last poll</dt><dd id="snmpLastPoll">
                                <?php
                                if (!empty($device['snmp_last_poll_at'])) {
                                    echo App::e((string)$device['snmp_last_poll_at']);
                                    $bits = [];
                                    if ($device['snmp_last_poll_watts'] !== null && $device['snmp_last_poll_watts'] !== '') {
                                        $w = (float)$device['snmp_last_poll_watts'];
                                        $bits[] = $w >= 1000
                                            ? number_format($w / 1000, 3) . ' kW'
                                            : rtrim(rtrim(sprintf('%.2F', $w), '0'), '.') . ' W';
                                    }
                                    if ($device['snmp_last_poll_amps'] !== null && $device['snmp_last_poll_amps'] !== '') {
                                        $bits[] = rtrim(rtrim(sprintf('%.2F', (float)$device['snmp_last_poll_amps']), '0'), '.') . ' A';
                                    }
                                    if ($bits) {
                                        echo ' · ' . App::e(implode(' · ', $bits));
                                    }
                                } else {
                                    echo '—';
                                }
                                ?>
                            </dd></div>
                        </dl>
                        <?php if (!$discoverReady && $canSnmpActions): ?>
                            <p class="text-muted snmp-poll-stats">
                                Discover needs manufacturer, model, and management/primary IP.
                            </p>
                        <?php elseif ($siteTplId < 1 && $canSnmpActions): ?>
                            <p class="text-muted snmp-poll-stats">
                                Scheduled poll unlocks after Discover OIDs creates/assigns a site template.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canSnmpActions): ?>
                <div class="modal-overlay modal-overlay-glass" id="snmpDiscoverModal" hidden>
                    <div class="modal-panel modal-panel-glass modal-panel-glass-wide" role="dialog" aria-modal="true" aria-labelledby="snmpDiscoverTitle">
                        <div class="modal-header">
                            <h2 id="snmpDiscoverTitle">Discover OIDs</h2>
                            <button type="button" class="modal-close" id="snmpDiscoverClose" aria-label="Close">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div id="snmpDiscoverLoading" hidden>
                                <p class="text-muted">Walking SNMP roots… this may take up to a minute.</p>
                            </div>
                            <div id="snmpDiscoverError" class="alert alert-error" hidden></div>
                            <div id="snmpDiscoverResults" hidden>
                                <dl class="snmp-discover-meta">
                                    <div><dt>Host</dt><dd id="snmpDiscHost">—</dd></div>
                                    <div><dt>Template name</dt><dd id="snmpDiscTplName">—</dd></div>
                                    <div><dt>Walk count</dt><dd id="snmpDiscWalk">—</dd></div>
                                    <div><dt>sysDescr</dt><dd id="snmpDiscSys">—</dd></div>
                                </dl>
                                <p id="snmpDiscMessage" class="text-muted" style="font-size:.9rem;margin-top:0"></p>

                                <h3 style="font-size:.95rem;margin:1rem 0 .4rem">Proposed OID map</h3>
                                <p class="text-muted" style="font-size:.75rem;margin:0 0 .5rem">
                                    Edit before creating the site template. Metrics with empty OIDs are skipped.
                                </p>
                                <ul class="snmp-map-list" id="snmpProposedMap"></ul>

                                <h3 style="font-size:.95rem;margin:1.1rem 0 .4rem">Candidates</h3>
                                <div style="max-height:220px;overflow:auto;border:1px solid rgba(148,163,184,.2);border-radius:8px">
                                    <table class="snmp-oid-table">
                                        <thead>
                                            <tr>
                                                <th>OID</th>
                                                <th>Value</th>
                                                <th>Hint</th>
                                                <th>Score</th>
                                            </tr>
                                        </thead>
                                        <tbody id="snmpCandidateBody"></tbody>
                                    </table>
                                </div>
                                <div id="snmpExistsWarn" class="alert alert-warning" hidden style="margin-top:.85rem"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" id="snmpDiscoverCancel">Close</button>
                            <button type="button" class="btn btn-warning" id="snmpDiscoverOverwrite" hidden>Overwrite template</button>
                            <button type="button" class="btn btn-primary" id="snmpDiscoverCreate" disabled>Create template</button>
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    var deviceId = <?= (int)$device['device_id'] ?>;
                    var hasTemplate = <?= $siteTplId > 0 ? 'true' : 'false' ?>;
                    var modal = document.getElementById('snmpDiscoverModal');
                    var btnDiscover = document.getElementById('btnSnmpDiscover');
                    var btnPoll = document.getElementById('btnSnmpPollNow');
                    var autoToggle = document.getElementById('snmpAutoPollToggle');
                    var autoLabel = document.getElementById('snmpAutoPollLabel');
                    var loadingEl = document.getElementById('snmpDiscoverLoading');
                    var errEl = document.getElementById('snmpDiscoverError');
                    var resEl = document.getElementById('snmpDiscoverResults');
                    var createBtn = document.getElementById('snmpDiscoverCreate');
                    var overwriteBtn = document.getElementById('snmpDiscoverOverwrite');
                    var existsWarn = document.getElementById('snmpExistsWarn');
                    var lastDiscover = null;

                    function toast(msg, type) {
                        if (window.ColdAisle && ColdAisle.toast) ColdAisle.toast(msg, type || 'info');
                        else alert(msg);
                    }
                    function api(body) {
                        return ColdAisle.api('api/snmp_device.php', { method: 'POST', body: body });
                    }
                    function openModal() {
                        modal.hidden = false;
                        document.body.classList.add('modal-open');
                    }
                    function closeModal() {
                        modal.hidden = true;
                        document.body.classList.remove('modal-open');
                    }
                    function showErr(msg) {
                        if (!errEl) return;
                        errEl.hidden = !msg;
                        errEl.textContent = msg || '';
                    }
                    function setLoading(on) {
                        if (loadingEl) loadingEl.hidden = !on;
                        if (resEl && on) resEl.hidden = true;
                        if (createBtn) createBtn.disabled = true;
                        if (overwriteBtn) overwriteBtn.hidden = true;
                        if (existsWarn) existsWarn.hidden = true;
                    }
                    function esc(s) {
                        var d = document.createElement('div');
                        d.textContent = s == null ? '' : String(s);
                        return d.innerHTML;
                    }
                    function renderDiscover(data) {
                        lastDiscover = data;
                        document.getElementById('snmpDiscHost').textContent = data.host || '—';
                        document.getElementById('snmpDiscTplName').textContent = data.template_name || '—';
                        document.getElementById('snmpDiscWalk').textContent = String(data.walk_count != null ? data.walk_count : '—');
                        document.getElementById('snmpDiscSys').textContent = data.sysDescr || '—';
                        document.getElementById('snmpDiscMessage').textContent = data.message || '';

                        var mapUl = document.getElementById('snmpProposedMap');
                        mapUl.innerHTML = '';
                        var map = data.proposed_map || {};
                        var keys = Object.keys(map);
                        if (!keys.length) {
                            mapUl.innerHTML = '<li class="text-muted">No proposed metrics</li>';
                        } else {
                            keys.forEach(function (k) {
                                var li = document.createElement('li');
                                li.innerHTML = '<label>' + esc(k) + '</label>';
                                var inp = document.createElement('input');
                                inp.className = 'form-control';
                                inp.dataset.metric = k;
                                inp.value = map[k] || '';
                                li.appendChild(inp);
                                mapUl.appendChild(li);
                            });
                            // Allow adding a freeform extra row
                            var li2 = document.createElement('li');
                            li2.innerHTML = '<label class="text-muted">+ metric</label>';
                            var extra = document.createElement('input');
                            extra.className = 'form-control';
                            extra.placeholder = 'name=1.3.6… (optional)';
                            extra.id = 'snmpExtraMapRow';
                            li2.appendChild(extra);
                            mapUl.appendChild(li2);
                        }

                        var tbody = document.getElementById('snmpCandidateBody');
                        tbody.innerHTML = '';
                        (data.candidates || []).forEach(function (c) {
                            var tr = document.createElement('tr');
                            tr.innerHTML =
                                '<td><code>' + esc(c.oid) + '</code></td>' +
                                '<td>' + esc(c.value) + '</td>' +
                                '<td>' + esc(c.hint || '') + '</td>' +
                                '<td>' + esc(c.score) + '</td>';
                            tr.style.cursor = 'pointer';
                            tr.title = 'Click to copy OID';
                            tr.addEventListener('click', function () {
                                if (navigator.clipboard) navigator.clipboard.writeText(c.oid || '');
                                toast('Copied ' + c.oid, 'info');
                            });
                            tbody.appendChild(tr);
                        });
                        if (!(data.candidates || []).length) {
                            tbody.innerHTML = '<tr><td colspan="4" class="text-muted">No scored candidates</td></tr>';
                        }

                        if (data.existing_template) {
                            existsWarn.hidden = false;
                            existsWarn.textContent = 'Template "' + data.template_name +
                                '" already exists. Create will ask to overwrite, or use Overwrite template.';
                            overwriteBtn.hidden = false;
                        } else {
                            existsWarn.hidden = true;
                            overwriteBtn.hidden = true;
                        }
                        resEl.hidden = false;
                        createBtn.disabled = false;
                        createBtn.textContent = 'Create template “' + (data.template_name || '') + '”';
                    }
                    function collectMap() {
                        var map = {};
                        document.querySelectorAll('#snmpProposedMap input[data-metric]').forEach(function (inp) {
                            var k = inp.dataset.metric;
                            var v = (inp.value || '').trim();
                            if (k && v) map[k] = v;
                        });
                        var extra = document.getElementById('snmpExtraMapRow');
                        if (extra && extra.value) {
                            var parts = extra.value.split('=');
                            if (parts.length >= 2) {
                                var ek = parts[0].trim();
                                var ev = parts.slice(1).join('=').trim();
                                if (ek && ev) map[ek] = ev;
                            }
                        }
                        return map;
                    }
                    function saveTemplate(overwrite) {
                        if (!lastDiscover) return;
                        var map = collectMap();
                        if (!Object.keys(map).length) {
                            showErr('OID map is empty.');
                            return;
                        }
                        createBtn.disabled = true;
                        overwriteBtn.disabled = true;
                        showErr('');
                        api({
                            action: 'save_template',
                            device_id: deviceId,
                            oid_map: map,
                            overwrite: !!overwrite
                        }).then(function (data) {
                            toast(data.message || 'Template saved', 'success');
                            hasTemplate = true;
                            if (btnPoll) btnPoll.disabled = false;
                            if (autoToggle) autoToggle.disabled = false;
                            var nameEl = document.getElementById('snmpTplName');
                            if (nameEl && data.template) {
                                var lab = [data.template.vendor, data.template.model].filter(Boolean).join(' / ')
                                    || data.template.name || 'Template';
                                nameEl.innerHTML = '<strong>' + esc(lab) + '</strong>';
                            }
                            closeModal();
                        }).catch(function (err) {
                            if (err.status === 409 && err.data && err.data.exists) {
                                existsWarn.hidden = false;
                                existsWarn.textContent = err.data.message ||
                                    'Template already exists. Cancel or overwrite.';
                                overwriteBtn.hidden = false;
                                createBtn.disabled = false;
                                overwriteBtn.disabled = false;
                                return;
                            }
                            showErr((err && err.message) || 'Save failed');
                            createBtn.disabled = false;
                            overwriteBtn.disabled = false;
                        });
                    }

                    if (btnDiscover) {
                        btnDiscover.addEventListener('click', function () {
                            openModal();
                            setLoading(true);
                            showErr('');
                            lastDiscover = null;
                            api({ action: 'discover', device_id: deviceId })
                                .then(function (data) {
                                    setLoading(false);
                                    renderDiscover(data);
                                })
                                .catch(function (err) {
                                    setLoading(false);
                                    showErr((err && err.message) || 'Discover failed — no template will be created.');
                                    toast((err && err.message) || 'Discover failed', 'error');
                                });
                        });
                    }
                    if (createBtn) {
                        createBtn.addEventListener('click', function () {
                            if (lastDiscover && lastDiscover.existing_template) {
                                if (!confirm('Template "' + lastDiscover.template_name +
                                    '" already exists. Overwrite it?')) {
                                    return;
                                }
                                saveTemplate(true);
                                return;
                            }
                            saveTemplate(false);
                        });
                    }
                    if (overwriteBtn) {
                        overwriteBtn.addEventListener('click', function () {
                            if (!confirm('Overwrite existing template "' +
                                (lastDiscover && lastDiscover.template_name ? lastDiscover.template_name : '') +
                                '"?')) {
                                return;
                            }
                            saveTemplate(true);
                        });
                    }
                    function closeDiscover() { closeModal(); }
                    var c1 = document.getElementById('snmpDiscoverClose');
                    var c2 = document.getElementById('snmpDiscoverCancel');
                    if (c1) c1.addEventListener('click', closeDiscover);
                    if (c2) c2.addEventListener('click', closeDiscover);
                    if (modal) {
                        modal.addEventListener('click', function (e) {
                            if (e.target === modal) closeDiscover();
                        });
                    }
                    document.addEventListener('keydown', function (e) {
                        if (e.key === 'Escape' && modal && !modal.hidden) closeDiscover();
                    });

                    if (btnPoll) {
                        btnPoll.addEventListener('click', function () {
                            btnPoll.disabled = true;
                            api({ action: 'poll_now', device_id: deviceId })
                                .then(function (data) {
                                    toast(data.message || 'Poll complete', 'success');
                                    var el = document.getElementById('snmpLastPoll');
                                    if (el) {
                                        var txt = data.snmp_last_poll_at || '—';
                                        var bits = [];
                                        if (data.snmp_last_poll_watts != null) {
                                            var w = parseFloat(data.snmp_last_poll_watts);
                                            bits.push(w >= 1000
                                                ? (w / 1000).toFixed(3) + ' kW'
                                                : w.toFixed(2).replace(/\.?0+$/, '') + ' W');
                                        }
                                        if (data.snmp_last_poll_amps != null) {
                                            bits.push(parseFloat(data.snmp_last_poll_amps).toFixed(2)
                                                .replace(/\.?0+$/, '') + ' A');
                                        }
                                        if (bits.length) txt += ' · ' + bits.join(' · ');
                                        el.textContent = txt;
                                    }
                                })
                                .catch(function (err) {
                                    toast((err && err.message) || 'Poll failed', 'error');
                                })
                                .finally(function () {
                                    btnPoll.disabled = !hasTemplate;
                                });
                        });
                    }
                    if (autoToggle) {
                        autoToggle.addEventListener('change', function () {
                            var enabled = !!autoToggle.checked;
                            autoToggle.disabled = true;
                            api({ action: 'set_auto_poll', device_id: deviceId, enabled: enabled })
                                .then(function (data) {
                                    toast(data.message || 'Updated', 'success');
                                    if (autoLabel) {
                                        autoLabel.textContent = 'Scheduled poll ' + (data.snmp_auto_poll ? 'on' : 'off');
                                    }
                                })
                                .catch(function (err) {
                                    autoToggle.checked = !enabled;
                                    toast((err && err.message) || 'Failed to update auto-poll', 'error');
                                })
                                .finally(function () {
                                    autoToggle.disabled = !hasTemplate;
                                });
                        });
                    }
                })();
                </script>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        layout_footer();
        exit;
    }

    // ========== EDIT / NEW FORM ==========
    if ($device && $action === 'edit' && !AuthManager::canEditDevice($user, $device)) {
        App::flash('error', 'You do not have permission to edit this device (department ownership).');
        App::redirect('pages/devices.php?id=' . (int)$device['device_id']);
    }
    if (!$device && $action === 'new' && !AuthManager::canEditDevice($user, null)) {
        App::flash('error', 'You do not have permission to create devices.');
        App::redirect('pages/devices.php');
    }

    layout_header($device ? 'Edit: ' . $device['label'] : 'New Device', $user, 'devices');
    ?>
    <div class="flex-between mb-2">
        <p class="text-muted mb-0"><?= $device ? 'Editing device properties. Derived location fields stay read-only.' : 'Create a new device inventory record.' ?></p>
        <div class="flex gap-1">
            <?php if ($device): ?>
                <a class="btn btn-secondary" href="<?= App::e(App::url('pages/devices.php?id=' . (int)$device['device_id'])) ?>">Cancel</a>
            <?php else: ?>
                <a class="btn btn-secondary" href="<?= App::e(App::url('pages/devices.php')) ?>">← Devices</a>
            <?php endif; ?>
        </div>
    </div>
    <form method="post" id="deviceForm">
        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
        <input type="hidden" name="action" value="save_device">
        <?php if ($device): ?>
            <input type="hidden" name="device_id" value="<?= (int)$device['device_id'] ?>">
        <?php endif; ?>

        <!-- Location (derived + cabinet) -->
        <div class="card">
            <div class="card-header"><h2>Location</h2></div>
            <div class="card-body form-grid">
                <div class="form-row"><label>DC Name <span class="view-derived">(from cabinet)</span></label>
                    <input class="form-control" id="loc_dc" readonly value="<?= App::e($loc['dc_name']) ?>" placeholder="(from cabinet)"></div>
                <div class="form-row"><label>Zone <span class="view-derived">(from row)</span></label>
                    <input class="form-control" id="loc_zone" readonly value="<?= App::e($loc['zone_name']) ?>" placeholder="(from row → zone)"></div>
                <div class="form-row"><label>Row <span class="view-derived">(from cabinet)</span></label>
                    <input class="form-control" id="loc_row" readonly value="<?= App::e($loc['row_name']) ?>" placeholder="(from cabinet row)"></div>
                <div class="form-row"><label>Cabinet</label>
                    <select class="form-control" name="cabinet_id" id="cabinet_id">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($cabinets as $c): ?>
                            <option value="<?= (int)$c['cabinet_id'] ?>"
                                <?= (string)$defaults['cabinet_id'] === (string)$c['cabinet_id'] ? 'selected' : '' ?>>
                                <?= App::e(($c['dc_name'] ?? '') . ' / ' . $c['name'] . ' (' . ($c['room_name'] ?? '') . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Position (U base)</label>
                    <input class="form-control" type="number" name="position_u" min="1"
                           value="<?= App::e((string)$defaults['position_u']) ?>"
                           title="Bottom U of device"></div>
                <div class="form-row"><label>Half Depth / Mount</label>
                    <select class="form-control" name="mount_side">
                        <option value="front" <?= $mountSide === 'front' ? 'selected' : '' ?>>Front of cabinet</option>
                        <option value="rear" <?= $mountSide === 'rear' ? 'selected' : '' ?>>Rear of cabinet</option>
                    </select>
                </div>
                <div class="form-row">
                    <label style="display:flex;align-items:center;gap:.4rem;margin-top:1.5rem">
                        <input type="checkbox" name="half_depth" value="1" <?= !empty($device['half_depth']) ? 'checked' : '' ?>>
                        Half-depth device
                    </label>
                </div>
                <div class="form-row"><label>Height (U)</label>
                    <input class="form-control" type="number" name="u_height" min="1" max="60"
                           value="<?= (int)($device['u_height'] ?? 1) ?>"></div>
            </div>
        </div>

        <!-- Identity -->
        <div class="card">
            <div class="card-header"><h2>Device identity</h2></div>
            <div class="card-body form-grid">
                <div class="form-row"><label>Device Name *</label>
                    <input class="form-control" name="label" required
                           value="<?= App::e($device['label'] ?? '') ?>"></div>
                <div class="form-row"><label>Device Type</label>
                    <select class="form-control" name="device_type">
                        <?php foreach ($deviceTypes as $val => $lab): ?>
                            <option value="<?= App::e($val) ?>"
                                <?= ($device['device_type'] ?? 'server') === $val ? 'selected' : '' ?>>
                                <?= App::e($lab) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Device Template</label>
                    <select class="form-control" name="template_id" id="template_id">
                        <option value="">— None —</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= (int)$t['template_id'] ?>"
                                data-type="<?= App::e($t['device_type'] ?? '') ?>"
                                data-model="<?= App::e($t['model'] ?? '') ?>"
                                data-mfr="<?= App::e($t['manufacturer_name'] ?? '') ?>"
                                data-u="<?= (int)($t['u_height'] ?? 1) ?>"
                                data-weight="<?= App::e((string)($t['weight_kg'] ?? '')) ?>"
                                data-watts="<?= App::e((string)($t['watts'] ?? '')) ?>"
                                data-dataports="<?= (int)($t['num_data_ports'] ?? 0) ?>"
                                data-powerports="<?= (int)($t['num_power_ports'] ?? 0) ?>"
                                data-snmp="<?= App::e($t['snmp_template'] ?? '') ?>"
                                <?= (int)($device['template_id'] ?? 0) === (int)$t['template_id'] ? 'selected' : '' ?>>
                                <?= App::e(trim(($t['manufacturer_name'] ?? '') . ' ' . ($t['model'] ?? '') . ' (' . ($t['device_type'] ?? '') . ')')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">
                        Selecting a template fills type, model, U height, weight, wattage, ports, and SNMP version.
                        <a href="<?= App::e(App::url('pages/device_templates.php')) ?>">Manage templates</a>
                    </p>
                </div>
                <div class="form-row"><label>Parent Device</label>
                    <select class="form-control" name="parent_device_id">
                        <option value="">— None —</option>
                        <?php foreach ($parentDevices as $p): ?>
                            <?php if ($device && (int)$p['device_id'] === (int)$device['device_id']) {
                                continue;
                            } ?>
                            <option value="<?= (int)$p['device_id'] ?>"
                                <?= (int)($device['parent_device_id'] ?? 0) === (int)$p['device_id'] ? 'selected' : '' ?>>
                                <?= App::e($p['label'] . ' (' . $p['device_type'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:.75rem;margin:.25rem 0 0">e.g. blade chassis</p>
                </div>
                <div class="form-row"><label>Manufacturer</label>
                    <input class="form-control" name="manufacturer"
                           value="<?= App::e($device['manufacturer'] ?? '') ?>"></div>
                <div class="form-row"><label>Model</label>
                    <input class="form-control" name="model"
                           value="<?= App::e($device['model'] ?? '') ?>"></div>
                <div class="form-row"><label>Manufacture Date</label>
                    <input class="form-control" type="date" name="manufacture_date"
                           value="<?= App::e($device['manufacture_date'] ?? '') ?>"></div>
                <div class="form-row"><label>Asset Number</label>
                    <input class="form-control" name="asset_tag"
                           value="<?= App::e($device['asset_tag'] ?? '') ?>"></div>
                <div class="form-row"><label>Serial No.</label>
                    <input class="form-control" name="serial_no"
                           value="<?= App::e($device['serial_no'] ?? '') ?>"></div>
                <div class="form-row"><label>Hostname</label>
                    <input class="form-control" name="hostname"
                           value="<?= App::e($device['hostname'] ?? '') ?>"></div>
                <div class="form-row"><label>Primary IP Address</label>
                    <input class="form-control" name="primary_ip"
                           value="<?= App::e($device['primary_ip'] ?? '') ?>"></div>
                <div class="form-row"><label>Mgmt IP</label>
                    <input class="form-control" name="mgmt_ip"
                           value="<?= App::e($device['mgmt_ip'] ?? '') ?>"></div>
            </div>
        </div>

        <!-- Physical / power / ports -->
        <div class="card">
            <div class="card-header"><h2>Physical &amp; power</h2></div>
            <div class="card-body form-grid">
                <div class="form-row"><label>Weight (kg)</label>
                    <input class="form-control" type="number" step="0.01" name="weight_kg"
                           value="<?= App::e((string)($device['weight_kg'] ?? '')) ?>"></div>
                <div class="form-row"><label>Power (Wattage)</label>
                    <input class="form-control" type="number" step="0.1" name="nominal_watts"
                           value="<?= App::e((string)($device['nominal_watts'] ?? '')) ?>"></div>
                <div class="form-row"><label>Number of Data ports</label>
                    <input class="form-control" type="number" min="0" name="num_data_ports"
                           value="<?= App::e((string)($device['num_data_ports'] ?? ($device ? count(array_filter($ports, fn($p) => $p['port_type'] === 'data')) : '4'))) ?>"></div>
                <div class="form-row"><label>Number of Power ports</label>
                    <input class="form-control" type="number" min="0" name="num_power_ports"
                           value="<?= App::e((string)($device['num_power_ports'] ?? ($device ? count(array_filter($ports, fn($p) => $p['port_type'] === 'power')) : '2'))) ?>"></div>
                <?php if (!$device): ?>
                    <p class="text-muted form-row full" style="font-size:.8rem;margin:0">
                        On create, data/power ports are auto-created from these counts.
                        After create, add Power Supply line items to map watts/NEMA to PDU outlets.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($device): ?>
        <!-- Power Supply line items (PSU ↔ PDU mapping) -->
        <div class="card" id="powerSupplyCard">
            <div class="card-header">
                <div class="flex-between" style="width:100%">
                    <h2 style="margin:0">Power Supply</h2>
                    <button type="button" class="btn btn-sm btn-secondary" id="btnAddPsu">+ Add PSU</button>
                </div>
            </div>
            <div class="card-body flush">
                <div class="table-wrap">
                    <table class="data" id="psuTable">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Watts</th>
                            <th>Connector (NEMA)</th>
                            <th>PDU</th>
                            <th>PDU plug #</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="psuTableBody">
                        <?php if (!$powerSupplies): ?>
                            <tr class="psu-empty"><td colspan="6" class="text-muted">No power supplies defined. Add PSU-A / PSU-B for dual-corded devices.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($powerSupplies as $ps): ?>
                            <tr data-psu-id="<?= (int)$ps['power_supply_id'] ?>">
                                <td><input class="form-control form-control-sm psu-name" value="<?= App::e($ps['name'] ?? 'PSU') ?>"></td>
                                <td><input class="form-control form-control-sm psu-watts" type="number" step="0.1" style="width:5.5rem" value="<?= App::e((string)($ps['watts'] ?? '')) ?>"></td>
                                <td>
                                    <select class="form-control form-control-sm psu-connector">
                                        <option value="">—</option>
                                        <?php foreach ($nemaTypes as $nt): ?>
                                            <option value="<?= App::e($nt) ?>" <?= ($ps['connector_type'] ?? '') === $nt ? 'selected' : '' ?>><?= App::e($nt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select class="form-control form-control-sm psu-pdu">
                                        <option value="">—</option>
                                        <?php foreach ($cabinetPdus as $cp): ?>
                                            <option value="<?= (int)$cp['pdu_id'] ?>" <?= (int)($ps['pdu_id'] ?? 0) === (int)$cp['pdu_id'] ? 'selected' : '' ?>>
                                                <?= App::e($cp['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select class="form-control form-control-sm psu-outlet">
                                        <option value="">—</option>
                                        <?php
                                        $selPduId = (int)($ps['pdu_id'] ?? 0);
                                        foreach ($cabinetPdus as $cp) {
                                            if ((int)$cp['pdu_id'] !== $selPduId) {
                                                continue;
                                            }
                                            foreach ($cp['outlets'] as $o) {
                                                $lab = '#' . (int)$o['outlet_number'];
                                                if (!empty($o['outlet_type'])) {
                                                    $lab .= ' · ' . $o['outlet_type'];
                                                }
                                                $sel = (int)($ps['pdu_outlet_id'] ?? 0) === (int)$o['outlet_id'] ? ' selected' : '';
                                                echo '<option value="' . (int)$o['outlet_id'] . '"' . $sel . '>' . App::e($lab) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </td>
                                <td class="actions" style="white-space:nowrap">
                                    <button type="button" class="btn btn-sm btn-secondary psu-save">Save</button>
                                    <button type="button" class="btn btn-sm btn-danger psu-del">×</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$cabinetPdus): ?>
                    <p class="text-muted" style="font-size:.8rem;padding:.65rem 1rem;margin:0">
                        No PDUs on this cabinet yet — add them from the
                        <?php if (!empty($device['cabinet_id'])): ?>
                            <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$device['cabinet_id'])) ?>">cabinet view</a>
                        <?php else: ?>
                            cabinet view
                        <?php endif; ?>
                        or Power page to map outlets.
                    </p>
                <?php else: ?>
                    <p class="text-muted" style="font-size:.78rem;padding:.5rem 1rem;margin:0">
                        Mapping a PDU plug updates both the PSU and the PDU outlet for end-to-end power path tracking.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Lifecycle / ownership -->
        <div class="card">
            <div class="card-header"><h2>Lifecycle &amp; ownership</h2></div>
            <div class="card-body form-grid">
                <div class="form-row"><label>Status</label>
                    <select class="form-control" name="status">
                        <?php foreach ($deviceStatuses as $val => $lab): ?>
                            <option value="<?= App::e($val) ?>"
                                <?= ($device['status'] ?? 'production') === $val ? 'selected' : '' ?>>
                                <?= App::e($lab) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>Install Date</label>
                    <input class="form-control" type="date" name="install_date"
                           value="<?= App::e($device['install_date'] ?? '') ?>"></div>
                <div class="form-row"><label>Warranty Provider</label>
                    <input class="form-control" name="warranty_provider"
                           value="<?= App::e($device['warranty_provider'] ?? '') ?>"></div>
                <div class="form-row"><label>Warranty End date</label>
                    <input class="form-control" type="date" name="warranty_end"
                           value="<?= App::e($device['warranty_end'] ?? '') ?>"></div>
                <div class="form-row"><label>Departmental Owner</label>
                    <select class="form-control" name="department_id" id="department_id"
                        <?= (!AuthManager::isAdmin($user) && !empty($user['department_id'])) ? 'data-locked="1"' : '' ?>>
                        <option value="">— Unassigned —</option>
                        <?php
                        $defaultDept = (int)($device['department_id'] ?? ($user['department_id'] ?? 0));
                        foreach ($departments as $dep):
                            // Non-admin department users only see their department
                            if (!AuthManager::isAdmin($user) && !empty($user['department_id'])
                                && (int)$dep['department_id'] !== (int)$user['department_id']) {
                                continue;
                            }
                            ?>
                            <option value="<?= (int)$dep['department_id'] ?>"
                                <?= $defaultDept === (int)$dep['department_id'] ? 'selected' : '' ?>>
                                <?= App::e($dep['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$departments): ?>
                        <p class="text-muted" style="font-size:.75rem">No departments yet — add under Users &amp; Departments.</p>
                    <?php elseif (!AuthManager::isAdmin($user) && !empty($user['department_id'])): ?>
                        <p class="text-muted" style="font-size:.75rem">You can only assign devices to your department.</p>
                    <?php endif; ?>
                </div>
                <div class="form-row"><label>Primary contact</label>
                    <select class="form-control" name="owner_contact_id" id="owner_contact_id">
                        <option value="">—</option>
                        <!-- Options rebuilt by JS from contacts + department users -->
                    </select>
                    <p class="text-muted" style="font-size:.75rem" id="contact_hint">
                        Populated from contacts and users in the selected department.
                    </p>
                </div>
                <div class="form-row full"><label>Tags</label>
                    <input class="form-control" name="tags"
                           value="<?= App::e($device['tags'] ?? '') ?>"
                           placeholder="comma,separated,tags"></div>
            </div>
        </div>

        <!-- SNMP -->
        <div class="card">
            <div class="card-header"><h2>SNMP</h2></div>
            <div class="card-body form-grid">
                <div class="form-row"><label>SNMP Version</label>
                    <select class="form-control" name="snmp_version" id="snmp_version">
                        <option value="">— Disabled —</option>
                        <?php foreach (['1' => '1', '2c' => '2c', '3' => '3'] as $val => $lab): ?>
                            <option value="<?= $val ?>"
                                <?= (string)($device['snmp_version'] ?? '') === $val ? 'selected' : '' ?>>
                                <?= $lab ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>SNMP Read-Only Community</label>
                    <input class="form-control" name="snmp_community" id="snmp_community"
                           value=""
                           placeholder="<?= !empty($device['snmp_community']) ? '•••• saved (leave blank to keep)' : '' ?>"
                           autocomplete="off"></div>
                <div class="form-row"><label>SNMP Consecutive Failures</label>
                    <input class="form-control" type="number" min="0" name="snmp_fail_count"
                           value="<?= (int)($device['snmp_fail_count'] ?? 0) ?>"
                           title="Stop polling after this many consecutive failures"></div>

                <div class="form-row full snmp-v3-fields">
                    <label>SNMPv3 credential profile</label>
                    <select class="form-control" name="snmp_v3_profile_id" id="snmp_v3_profile_id">
                        <option value="">— Manual / none —</option>
                        <?php foreach ($snmpProfiles as $sp): ?>
                            <option value="<?= (int)$sp['profile_id'] ?>"
                                data-user="<?= App::e($sp['security_name'] ?? '') ?>"
                                data-level="<?= App::e($sp['security_level'] ?? '') ?>"
                                data-auth-proto="<?= App::e($sp['auth_protocol'] ?? '') ?>"
                                data-priv-proto="<?= App::e($sp['priv_protocol'] ?? '') ?>"
                                data-context="<?= App::e($sp['context_name'] ?? '') ?>"
                                <?= (int)($device['snmp_v3_profile_id'] ?? 0) === (int)$sp['profile_id'] ? 'selected' : '' ?>>
                                <?= App::e($sp['name']) ?>
                                (<?= App::e($sp['security_level'] ?? '') ?> · <?= App::e($sp['security_name'] ?? '') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-muted" style="font-size:.75rem;margin:.3rem 0 0">
                        Manage profiles under <a href="<?= App::e(App::url('pages/snmp.php#profiles')) ?>">SNMP → Profiles</a>.
                        Selecting a profile fills the fields below (saved on the device).
                    </p>
                </div>
                <div class="form-row snmp-v3-fields"><label>SNMPv3 User (security name)</label>
                    <input class="form-control" name="snmp_v3_user" id="snmp_v3_user"
                           value="<?= App::e($device['snmp_v3_user'] ?? '') ?>" autocomplete="off"></div>
                <div class="form-row snmp-v3-fields"><label>SNMPv3 Security Level</label>
                    <select class="form-control" name="snmp_v3_sec_level" id="snmp_v3_sec_level">
                        <option value="">—</option>
                        <?php foreach (['noAuthNoPriv', 'authNoPriv', 'authPriv'] as $lvl): ?>
                            <option value="<?= $lvl ?>"
                                <?= ($device['snmp_v3_sec_level'] ?? '') === $lvl ? 'selected' : '' ?>>
                                <?= $lvl ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row snmp-v3-fields"><label>SNMPv3 Auth Protocol</label>
                    <select class="form-control" name="snmp_v3_auth_proto" id="snmp_v3_auth_proto">
                        <option value="">—</option>
                        <?php foreach (['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'] as $p): ?>
                            <option value="<?= $p ?>"
                                <?= strtoupper((string)($device['snmp_v3_auth_proto'] ?? '')) === $p ? 'selected' : '' ?>>
                                <?= $p ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row snmp-v3-fields"><label>SNMPv3 Auth Passphrase</label>
                    <input class="form-control" type="password" name="snmp_v3_auth_pass" id="snmp_v3_auth_pass"
                           value=""
                           placeholder="<?= !empty($device['snmp_v3_auth_pass']) ? '•••• saved (leave blank to keep)' : '' ?>"
                           autocomplete="new-password"></div>
                <div class="form-row snmp-v3-fields"><label>SNMPv3 Priv Protocol (encryption)</label>
                    <select class="form-control" name="snmp_v3_priv_proto" id="snmp_v3_priv_proto">
                        <option value="">—</option>
                        <?php foreach (['DES', 'AES', 'AES192', 'AES256'] as $p): ?>
                            <option value="<?= $p ?>"
                                <?= strtoupper((string)($device['snmp_v3_priv_proto'] ?? '')) === $p ? 'selected' : '' ?>>
                                <?= $p ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row snmp-v3-fields"><label>SNMPv3 Priv Passphrase</label>
                    <input class="form-control" type="password" name="snmp_v3_priv_pass" id="snmp_v3_priv_pass"
                           value=""
                           placeholder="<?= !empty($device['snmp_v3_priv_pass']) ? '•••• saved (leave blank to keep)' : '' ?>"
                           autocomplete="new-password"></div>
                <div class="form-row snmp-v3-fields"><label>SNMPv3 Context</label>
                    <input class="form-control" name="snmp_v3_context" id="snmp_v3_context"
                           value="<?= App::e($device['snmp_v3_context'] ?? '') ?>"></div>

            </div>
        </div>

        <div class="card">
            <div class="card-body form-actions">
                <button class="btn btn-primary" type="submit">
                    <?= $device ? 'Save Changes' : 'Create Device' ?>
                </button>
            </div>
        </div>
    </form>

    <?php if ($device): ?>
        <!-- Timestamped notes -->
        <div class="card">
            <div class="card-header"><h2>Notes</h2></div>
            <div class="card-body">
                <form method="post" class="form-grid" style="margin-bottom:1rem">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_note">
                    <input type="hidden" name="device_id" value="<?= (int)$device['device_id'] ?>">
                    <div class="form-row full"><label>Add note</label>
                        <textarea class="form-control" name="note_text" rows="2" required
                                  placeholder="Each note is saved with date, time, and your username"></textarea>
                    </div>
                    <div class="form-row">
                        <button class="btn btn-secondary btn-sm" type="submit">Add note</button>
                    </div>
                </form>
                <?php if ($deviceNotes): ?>
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                            <tr><th style="width:11rem">When</th><th style="width:8rem">Who</th><th>Note</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($deviceNotes as $n): ?>
                                <tr>
                                    <td class="text-muted" style="white-space:nowrap;font-size:.8rem">
                                        <?= App::e($n['created_at'] ?? '') ?>
                                    </td>
                                    <td style="font-size:.85rem"><?= App::e($n['username'] ?? '—') ?></td>
                                    <td style="white-space:pre-wrap"><?= App::e($n['note_text'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No notes yet.</p>
                <?php endif; ?>
                <?php if (!empty($device['notes'])): ?>
                    <details style="margin-top:1rem">
                        <summary class="text-muted" style="cursor:pointer">Legacy freeform notes</summary>
                        <pre style="white-space:pre-wrap;font-size:.85rem"><?= App::e($device['notes']) ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($device && $ports): ?>
        <div class="card">
            <div class="card-header"><h2>Interface labels (Power &amp; Data)</h2></div>
            <div class="card-body flush">
                <table class="data">
                    <thead>
                    <tr><th>Type</th><th>#</th><th>Label</th><th>Media</th><th>Speed</th><th>Notes</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ports as $p): ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                <input type="hidden" name="action" value="update_port">
                                <input type="hidden" name="port_id" value="<?= (int)$p['port_id'] ?>">
                                <input type="hidden" name="device_id" value="<?= (int)$device['device_id'] ?>">
                                <td><span class="badge <?= $p['port_type'] === 'power' ? 'badge-warning' : 'badge-info' ?>"><?= App::e($p['port_type']) ?></span></td>
                                <td><?= (int)$p['port_number'] ?></td>
                                <td><input class="form-control" name="label" value="<?= App::e($p['label']) ?>" style="min-width:100px"></td>
                                <td><input class="form-control" name="media_type" value="<?= App::e($p['media_type']) ?>" style="min-width:80px"></td>
                                <td><input class="form-control" name="speed" value="<?= App::e($p['speed']) ?>" style="min-width:70px"></td>
                                <td><input class="form-control" name="notes" value="<?= App::e($p['notes']) ?>"></td>
                                <td><button class="btn btn-sm btn-secondary" type="submit">Save</button></td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <script>
    (function () {
        var map = <?= json_encode($cabLocMap, JSON_UNESCAPED_UNICODE) ?>;
        var cab = document.getElementById('cabinet_id');
        var dc = document.getElementById('loc_dc');
        var zone = document.getElementById('loc_zone');
        var row = document.getElementById('loc_row');
        function refreshLoc() {
            var id = cab && cab.value ? String(cab.value) : '';
            var info = map[id] || { dc: '', zone: '', row: '', name: '' };
            if (dc) dc.value = info.dc || '';
            if (zone) zone.value = info.zone || '';
            if (row) row.value = info.row || '';
        }
        if (cab) cab.addEventListener('change', refreshLoc);

        // Filter contacts by department
        var dept = document.getElementById('department_id');
        var contact = document.getElementById('owner_contact_id');
        var contactCatalog = <?= json_encode(array_values(array_merge(
            array_map(static function ($ct) {
                return [
                    'value' => (string)(int)$ct['contact_id'],
                    'department_id' => $ct['department_id'] !== null && $ct['department_id'] !== ''
                        ? (int)$ct['department_id'] : null,
                    'label' => trim(($ct['last_name'] ?? '') . ', ' . ($ct['first_name'] ?? ''), ' ,')
                        . (!empty($ct['email']) ? ' · ' . $ct['email'] : ''),
                    'email' => strtolower(trim((string)($ct['email'] ?? ''))),
                    'source' => 'contact',
                ];
            }, $contacts),
            array_map(static function ($u) {
                $name = trim((string)($u['display_name'] ?? ''));
                if ($name === '') {
                    $name = (string)($u['username'] ?? 'User');
                }
                return [
                    'value' => 'user:' . (int)$u['user_id'],
                    'department_id' => $u['department_id'] !== null && $u['department_id'] !== ''
                        ? (int)$u['department_id'] : null,
                    'label' => $name
                        . (!empty($u['email']) ? ' · ' . $u['email'] : '')
                        . ' (user)',
                    'email' => strtolower(trim((string)($u['email'] ?? ''))),
                    'source' => 'user',
                    'user_id' => (int)$u['user_id'],
                ];
            }, $usersAsContacts)
        )), JSON_UNESCAPED_UNICODE) ?>;
        var selectedContactId = <?= json_encode(
            !empty($device['owner_contact_id']) ? (string)(int)$device['owner_contact_id'] : ''
        ) ?>;
        var selectedContactEmail = <?= json_encode(
            strtolower(trim((string)($device['contact_email'] ?? '')))
        ) ?>;

        function rebuildContactOptions() {
            if (!contact) return;
            var d = dept && dept.value ? String(dept.value) : '';
            var prev = contact.value || selectedContactId || '';
            // Clear all but keep structure
            contact.innerHTML = '';
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = '—';
            contact.appendChild(blank);

            var seenEmail = {};
            var added = 0;
            contactCatalog.forEach(function (item) {
                // Filter: show people in selected dept; if no dept selected show all
                var itemDept = item.department_id != null ? String(item.department_id) : '';
                if (d) {
                    // Only this department (skip unassigned people when filtering)
                    if (itemDept !== d) return;
                }
                // Prefer contact record over user when same email
                if (item.email && seenEmail[item.email]) {
                    if (item.source === 'user') return;
                }
                if (item.email) seenEmail[item.email] = true;

                var opt = document.createElement('option');
                opt.value = item.value;
                opt.textContent = item.label || item.value;
                opt.setAttribute('data-dept', itemDept || '0');
                opt.setAttribute('data-source', item.source || '');
                if (item.email) opt.setAttribute('data-email', item.email);
                contact.appendChild(opt);
                added++;
            });

            // Restore selection: exact value, or contact id, or user with matching email
            var restored = false;
            if (prev) {
                for (var i = 0; i < contact.options.length; i++) {
                    if (contact.options[i].value === prev) {
                        contact.selectedIndex = i;
                        restored = true;
                        break;
                    }
                }
            }
            if (!restored && selectedContactEmail) {
                for (var j = 0; j < contact.options.length; j++) {
                    if ((contact.options[j].getAttribute('data-email') || '') === selectedContactEmail) {
                        contact.selectedIndex = j;
                        restored = true;
                        break;
                    }
                }
            }
            if (!restored) {
                contact.value = '';
            }

            var hint = document.getElementById('contact_hint');
            if (hint) {
                if (!d) {
                    hint.textContent = 'Select a department to list its users and contacts, or leave unassigned to see everyone.';
                } else if (added === 0) {
                    hint.textContent = 'No users or contacts in this department yet. Add users under Users & Depts, or contacts with this department.';
                } else {
                    hint.textContent = added + ' contact' + (added === 1 ? '' : 's') + ' available for this department.';
                }
            }
        }
        if (dept) dept.addEventListener('change', function () {
            // Changing department: clear sticky selected contact if it no longer applies
            selectedContactId = '';
            rebuildContactOptions();
        });
        rebuildContactOptions();

        // Apply device template → related fields
        var tpl = document.getElementById('template_id');
        function setVal(name, value) {
            var el = document.querySelector('[name="' + name + '"]');
            if (!el || value === undefined || value === null) return;
            el.value = value;
        }
        if (tpl) {
            tpl.addEventListener('change', function () {
                var opt = tpl.options[tpl.selectedIndex];
                if (!opt || !opt.value) return;
                var type = opt.getAttribute('data-type') || '';
                var model = opt.getAttribute('data-model') || '';
                var mfr = opt.getAttribute('data-mfr') || '';
                var u = opt.getAttribute('data-u') || '1';
                var weight = opt.getAttribute('data-weight') || '';
                var watts = opt.getAttribute('data-watts') || '';
                var dp = opt.getAttribute('data-dataports') || '0';
                var pp = opt.getAttribute('data-powerports') || '0';
                var snmp = opt.getAttribute('data-snmp') || '';
                if (type) setVal('device_type', type);
                if (model) setVal('model', model);
                if (mfr) setVal('manufacturer', mfr);
                setVal('u_height', u);
                setVal('weight_kg', weight);
                setVal('nominal_watts', watts);
                setVal('num_data_ports', dp);
                setVal('num_power_ports', pp);
                if (snmp) setVal('snmp_version', snmp);
            });
        }

        // SNMP version / v3 profile helpers
        var snmpVer = document.getElementById('snmp_version');
        var snmpProf = document.getElementById('snmp_v3_profile_id');
        function toggleSnmpV3Fields() {
            var v3 = snmpVer && snmpVer.value === '3';
            document.querySelectorAll('.snmp-v3-fields').forEach(function (el) {
                el.style.display = v3 ? '' : 'none';
            });
        }
        function applySnmpProfile() {
            if (!snmpProf) return;
            var opt = snmpProf.options[snmpProf.selectedIndex];
            if (!opt || !opt.value) return;
            if (snmpVer && snmpVer.value !== '3') {
                snmpVer.value = '3';
                toggleSnmpV3Fields();
            }
            function setId(id, val) {
                var el = document.getElementById(id);
                if (el && val != null) el.value = val;
            }
            setId('snmp_v3_user', opt.getAttribute('data-user') || '');
            setId('snmp_v3_sec_level', opt.getAttribute('data-level') || '');
            setId('snmp_v3_auth_proto', opt.getAttribute('data-auth-proto') || '');
            setId('snmp_v3_priv_proto', opt.getAttribute('data-priv-proto') || '');
            setId('snmp_v3_context', opt.getAttribute('data-context') || '');
            // Passphrases applied server-side from the profile (not embedded in HTML)
            setId('snmp_v3_auth_pass', '');
            setId('snmp_v3_priv_pass', '');
            var authEl = document.getElementById('snmp_v3_auth_pass');
            var privEl = document.getElementById('snmp_v3_priv_pass');
            if (authEl) authEl.placeholder = 'From selected profile (saved on device)';
            if (privEl) privEl.placeholder = 'From selected profile (saved on device)';
        }
        if (snmpVer) snmpVer.addEventListener('change', toggleSnmpV3Fields);
        if (snmpProf) snmpProf.addEventListener('change', applySnmpProfile);
        toggleSnmpV3Fields();

        // Power Supply line items
        <?php if ($device): ?>
        var deviceId = <?= (int)$device['device_id'] ?>;
        var cabinetPdus = <?= json_encode(array_map(static function ($p) {
            return [
                'pdu_id' => (int)$p['pdu_id'],
                'name' => $p['name'],
                'outlets' => array_map(static function ($o) {
                    return [
                        'outlet_id' => (int)$o['outlet_id'],
                        'outlet_number' => (int)$o['outlet_number'],
                        'outlet_type' => $o['outlet_type'],
                        'connected_device_id' => $o['connected_device_id'] !== null ? (int)$o['connected_device_id'] : null,
                        'device_power_supply_id' => $o['device_power_supply_id'] !== null ? (int)$o['device_power_supply_id'] : null,
                    ];
                }, $p['outlets'] ?? []),
            ];
        }, $cabinetPdus), JSON_UNESCAPED_UNICODE) ?>;
        var nemaTypes = <?= json_encode($nemaTypes) ?>;

        function psuOutletOptions(pduId, selectedOutletId) {
            var html = '<option value="">—</option>';
            var pdu = cabinetPdus.find(function (p) { return String(p.pdu_id) === String(pduId); });
            if (!pdu) return html;
            (pdu.outlets || []).forEach(function (o) {
                var lab = '#' + o.outlet_number;
                if (o.outlet_type) lab += ' · ' + o.outlet_type;
                html += '<option value="' + o.outlet_id + '"' +
                    (String(selectedOutletId || '') === String(o.outlet_id) ? ' selected' : '') +
                    '>' + lab + '</option>';
            });
            return html;
        }

        function psuNemaOptions(selected) {
            var html = '<option value="">—</option>';
            nemaTypes.forEach(function (t) {
                html += '<option value="' + t + '"' + (selected === t ? ' selected' : '') + '>' + t + '</option>';
            });
            return html;
        }

        function psuPduOptions(selected) {
            var html = '<option value="">—</option>';
            cabinetPdus.forEach(function (p) {
                html += '<option value="' + p.pdu_id + '"' +
                    (String(selected || '') === String(p.pdu_id) ? ' selected' : '') +
                    '>' + (p.name || ('PDU ' + p.pdu_id)) + '</option>';
            });
            return html;
        }

        function bindPsuRow(row) {
            var pduSel = row.querySelector('.psu-pdu');
            var outSel = row.querySelector('.psu-outlet');
            if (pduSel) {
                pduSel.addEventListener('change', function () {
                    outSel.innerHTML = psuOutletOptions(pduSel.value, null);
                });
            }
            var saveBtn = row.querySelector('.psu-save');
            var delBtn = row.querySelector('.psu-del');
            if (saveBtn) {
                saveBtn.addEventListener('click', async function () {
                    var id = row.getAttribute('data-psu-id');
                    var payload = {
                        name: row.querySelector('.psu-name').value || 'PSU',
                        watts: row.querySelector('.psu-watts').value,
                        connector_type: row.querySelector('.psu-connector').value,
                        pdu_id: pduSel.value || null,
                        pdu_outlet_id: outSel.value || null
                    };
                    saveBtn.disabled = true;
                    try {
                        if (id && id !== 'new') {
                            payload.power_supply_id = parseInt(id, 10);
                            await ColdAisle.api('api/device_power.php', {
                                method: 'PUT',
                                forcePostOverride: true,
                                body: payload
                            });
                        } else {
                            payload.device_id = deviceId;
                            var res = await ColdAisle.api('api/device_power.php', {
                                method: 'POST',
                                body: payload
                            });
                            if (res.power_supply && res.power_supply.power_supply_id) {
                                row.setAttribute('data-psu-id', res.power_supply.power_supply_id);
                            }
                        }
                        ColdAisle.toast('Power supply saved', 'success');
                    } catch (err) {
                        ColdAisle.toast(err.message || 'Save failed', 'danger');
                    }
                    saveBtn.disabled = false;
                });
            }
            if (delBtn) {
                delBtn.addEventListener('click', async function () {
                    var id = row.getAttribute('data-psu-id');
                    if (!confirm('Remove this power supply?')) return;
                    if (id && id !== 'new') {
                        try {
                            await ColdAisle.api('api/device_power.php?id=' + id, {
                                method: 'DELETE',
                                forcePostOverride: true
                            });
                        } catch (err) {
                            ColdAisle.toast(err.message || 'Delete failed', 'danger');
                            return;
                        }
                    }
                    row.remove();
                    var tbody = document.getElementById('psuTableBody');
                    if (tbody && !tbody.querySelector('tr[data-psu-id]')) {
                        tbody.innerHTML = '<tr class="psu-empty"><td colspan="6" class="text-muted">No power supplies defined.</td></tr>';
                    }
                    ColdAisle.toast('Power supply removed', 'success');
                });
            }
        }

        document.querySelectorAll('#psuTableBody tr[data-psu-id]').forEach(bindPsuRow);

        var btnAddPsu = document.getElementById('btnAddPsu');
        if (btnAddPsu) {
            btnAddPsu.addEventListener('click', function () {
                var tbody = document.getElementById('psuTableBody');
                var empty = tbody.querySelector('.psu-empty');
                if (empty) empty.remove();
                var n = tbody.querySelectorAll('tr[data-psu-id]').length + 1;
                var tr = document.createElement('tr');
                tr.setAttribute('data-psu-id', 'new');
                tr.innerHTML =
                    '<td><input class="form-control form-control-sm psu-name" value="PSU-' + n + '"></td>' +
                    '<td><input class="form-control form-control-sm psu-watts" type="number" step="0.1" style="width:5.5rem"></td>' +
                    '<td><select class="form-control form-control-sm psu-connector">' + psuNemaOptions('') + '</select></td>' +
                    '<td><select class="form-control form-control-sm psu-pdu">' + psuPduOptions('') + '</select></td>' +
                    '<td><select class="form-control form-control-sm psu-outlet"><option value="">—</option></select></td>' +
                    '<td class="actions" style="white-space:nowrap">' +
                    '<button type="button" class="btn btn-sm btn-secondary psu-save">Save</button> ' +
                    '<button type="button" class="btn btn-sm btn-danger psu-del">×</button></td>';
                tbody.appendChild(tr);
                bindPsuRow(tr);
            });
        }
        <?php endif; ?>
    })();
    </script>
    <?php
    layout_footer();
    exit;
}

// ---- List ----
$q = trim($_GET['q'] ?? '');
$sql = 'SELECT d.*, c.name AS cabinet_name, dc.name AS dc_name, cr.name AS row_name, z.name AS zone_name,
               dep.name AS department_name, dep.color_hex AS department_color
        FROM devices d
        LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
        LEFT JOIN rooms r ON r.room_id = c.room_id
        LEFT JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
        LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
        LEFT JOIN power_zones z ON z.zone_id = cr.zone_id
        LEFT JOIN departments dep ON dep.department_id = d.department_id
        WHERE d.is_active = 1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (d.label LIKE ? OR d.hostname LIKE ? OR d.serial_no LIKE ? OR d.asset_tag LIKE ? OR d.primary_ip LIKE ? OR d.tags LIKE ? OR dep.name LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like, $like, $like, $like];
}
$sql .= ' ORDER BY d.label';
$devices = Database::fetchAll($sql, $params);
$canCreate = AuthManager::canEditDevice($user, null);

layout_header('Devices', $user, 'devices');
?>
<div class="flex-between mb-2">
    <form method="get" class="flex gap-1" style="flex-wrap:wrap;align-items:center">
        <input class="form-control" name="q" placeholder="Search name, serial, IP, tags, department..." value="<?= App::e($q) ?>" style="width:280px">
        <button class="btn btn-secondary" type="submit">Search</button>
        <?php if ($q !== ''): ?>
            <a class="btn btn-ghost" href="<?= App::e(App::url('pages/devices.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/device_templates.php')) ?>">Device templates</a>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="?action=new">+ Add Device</a>
        <?php endif; ?>
    </div>
</div>
<div class="card">
    <div class="card-body flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Device Name</th>
                    <th>Type</th>
                    <th>Department</th>
                    <th>DC</th>
                    <th>Cabinet</th>
                    <th>U</th>
                    <th>IP</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($devices as $d):
                    $dc = trim((string)($d['department_color'] ?? ''));
                    if ($dc !== '' && $dc[0] !== '#') {
                        $dc = '#' . $dc;
                    }
                    if ($dc !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $dc)) {
                        $dc = '';
                    }
                    ?>
                    <tr>
                        <td><a href="?id=<?= (int)$d['device_id'] ?>"><?= App::e($d['label']) ?></a></td>
                        <td><?= App::e($deviceTypes[$d['device_type']] ?? $d['device_type']) ?></td>
                        <td>
                            <?php if (!empty($d['department_name'])): ?>
                                <span class="dept-chip">
                                    <?php if ($dc !== ''): ?>
                                        <span class="dept-swatch sm" style="background:<?= App::e($dc) ?>"></span>
                                    <?php endif; ?>
                                    <?= App::e($d['department_name']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= App::e($d['dc_name'] ?? '—') ?></td>
                        <td><?= App::e($d['cabinet_name'] ?? '—') ?></td>
                        <td><?= $d['position_u'] !== null ? (int)$d['position_u'] . '–' . ((int)$d['position_u'] + (int)$d['u_height'] - 1) : '—' ?></td>
                        <td><?= App::e($d['primary_ip'] ?? '—') ?></td>
                        <td><span class="badge badge-info"><?= App::e($deviceStatuses[$d['status']] ?? $d['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$devices): ?>
                    <tr><td colspan="8" class="text-muted">No devices found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
