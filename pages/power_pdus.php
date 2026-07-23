<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/power_helpers.php';
require_once dirname(__DIR__) . '/src/Services/SnmpOidTemplates.php';
App::boot();
$user = App::requirePermission('view_power');

$pduId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$filterZone = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;

$cabinets = Database::fetchAll('SELECT cabinet_id, name FROM cabinets WHERE is_active = 1 ORDER BY name');
$rows = [];
try {
    $rows = Database::fetchAll(
        'SELECT r.row_id, r.name, r.zone_id, rm.name AS room_name, dc.name AS dc_name
         FROM cabinet_rows r
         LEFT JOIN rooms rm ON rm.room_id = r.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         ORDER BY dc.name, rm.name, r.name'
    );
} catch (Throwable $e) {
    $rows = [];
}
$zones = Database::fetchAll(
    'SELECT z.*, dc.name AS dc_name FROM power_zones z
     INNER JOIN datacenters dc ON dc.datacenter_id = z.datacenter_id ORDER BY z.name'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::verifyCsrf($_POST['_csrf'] ?? '')) {
    if (!AuthManager::canEditPower($user)) {
        App::flash('error', 'You do not have permission to modify PDUs.');
        App::redirect('pages/power_pdus.php');
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_pdu' || $action === 'update_pdu') {
            $outputMode = power_normalize_output_mode($_POST['output_mode'] ?? 'outlets');
            $numOutlets = max(1, min(128, (int)($_POST['num_outlets'] ?? 24)));
            $numBreakerSlots = max(1, min(128, (int)($_POST['num_breaker_slots'] ?? 42)));
            $breakerLayout = power_normalize_breaker_layout($_POST['breaker_layout'] ?? 'odd_right_even_left');
            $breakerColumns = max(1, min(3, (int)($_POST['breaker_columns'] ?? 2)));
            if ($breakerLayout === 'single_column') {
                $breakerColumns = 1;
            } elseif ($breakerLayout === 'three_col_sequential') {
                $breakerColumns = 3;
            } elseif ($breakerColumns < 2 && $breakerLayout !== 'single_column') {
                $breakerColumns = 2;
            }
            $mount = strtolower((string)($_POST['mount_style'] ?? 'vertical_rear'));
            if (!in_array($mount, ['vertical_rear', 'u_mounted'], true)) {
                $mount = 'vertical_rear';
            }
            $scope = strtolower((string)($_POST['pdu_scope'] ?? 'rack'));
            if (!in_array($scope, ['rack', 'row', 'room'], true)) {
                $scope = 'rack';
            }
            if ($scope === 'row' && empty($_POST['phases'])) {
                $_POST['phases'] = '3';
                $_POST['phase_wiring'] = $_POST['phase_wiring'] ?? 'wye';
            }
            $elec = power_pdu_electrical_from_post($_POST);
            $zoneId = $_POST['zone_id'] !== '' ? (int)$_POST['zone_id'] : null;
            $profileId = !empty($_POST['snmp_v3_profile_id']) ? (int)$_POST['snmp_v3_profile_id'] : null;
            $snmpUser = $_POST['snmp_security_name'] !== '' ? $_POST['snmp_security_name'] : null;
            $snmpAuthProto = $_POST['snmp_auth_protocol'] !== '' ? $_POST['snmp_auth_protocol'] : null;
            $snmpAuthPass = $_POST['snmp_auth_passphrase'] !== '' ? $_POST['snmp_auth_passphrase'] : null;
            $snmpPrivProto = $_POST['snmp_priv_protocol'] !== '' ? $_POST['snmp_priv_protocol'] : null;
            $snmpPrivPass = $_POST['snmp_priv_passphrase'] !== '' ? $_POST['snmp_priv_passphrase'] : null;
            $snmpContext = $_POST['snmp_context'] !== '' ? $_POST['snmp_context'] : null;
            $snmpSecLevel = $_POST['snmp_v3_sec_level'] !== '' ? $_POST['snmp_v3_sec_level'] : null;
            // Apply SNMPv3 profile credentials onto PDU fields when a profile is selected
            if ($profileId) {
                try {
                    $prof = Database::fetchOne(
                        'SELECT * FROM snmp_v3_profiles WHERE profile_id = ? AND is_active = 1',
                        [$profileId]
                    );
                    if ($prof) {
                        $snmpUser = $prof['security_name'] ?? $snmpUser;
                        $snmpSecLevel = $prof['security_level'] ?? $snmpSecLevel;
                        $snmpAuthProto = $prof['auth_protocol'] ?? $snmpAuthProto;
                        $snmpPrivProto = $prof['priv_protocol'] ?? $snmpPrivProto;
                        $snmpContext = $prof['context_name'] ?? $snmpContext;
                        if (!empty($prof['auth_passphrase'])) {
                            $snmpAuthPass = $prof['auth_passphrase'];
                        }
                        if (!empty($prof['priv_passphrase'])) {
                            $snmpPrivPass = $prof['priv_passphrase'];
                        }
                    }
                } catch (Throwable $e) {
                    // profile table missing — keep form values
                }
            }
            $row = array_merge([
                'cabinet_id' => $_POST['cabinet_id'] !== '' ? (int)$_POST['cabinet_id'] : null,
                'row_id' => $_POST['row_id'] !== '' ? (int)$_POST['row_id'] : null,
                'zone_id' => $zoneId,
                'name' => trim($_POST['name']),
                'pdu_scope' => $scope,
                'mount_style' => $mount,
                'position_u' => $mount === 'u_mounted' && $_POST['position_u'] !== ''
                    ? (int)$_POST['position_u'] : null,
                'u_height' => $mount === 'u_mounted'
                    ? max(1, (int)($_POST['u_height'] ?? 1)) : null,
                'manufacturer' => $_POST['manufacturer'] !== '' ? $_POST['manufacturer'] : null,
                'model' => $_POST['model'] !== '' ? $_POST['model'] : null,
                'ip_address' => $_POST['ip_address'] !== '' ? $_POST['ip_address'] : null,
                'output_mode' => $outputMode,
                'num_outlets' => $outputMode === 'outlets' ? $numOutlets : 0,
                'num_breaker_slots' => $outputMode === 'breakers' ? $numBreakerSlots : null,
                'breaker_layout' => $outputMode === 'breakers' ? $breakerLayout : null,
                'breaker_columns' => $outputMode === 'breakers' ? $breakerColumns : null,
                'rated_amps' => $_POST['rated_amps'] !== '' ? (float)$_POST['rated_amps'] : null,
                'input_type' => $_POST['input_type'] !== '' ? $_POST['input_type'] : null,
                'snmp_enabled' => !empty($_POST['snmp_enabled']) ? 1 : 0,
                'snmp_version' => $_POST['snmp_version'] ?? '2c',
                'snmp_port' => (int)($_POST['snmp_port'] ?? 161),
                'snmp_community' => $_POST['snmp_community'] !== '' ? $_POST['snmp_community'] : null,
                'snmp_security_name' => $snmpUser,
                'snmp_auth_protocol' => $snmpAuthProto,
                'snmp_auth_passphrase' => $snmpAuthPass,
                'snmp_priv_protocol' => $snmpPrivProto,
                'snmp_priv_passphrase' => $snmpPrivPass,
                'snmp_context' => $snmpContext,
                'snmp_v3_sec_level' => $snmpSecLevel,
                'snmp_v3_profile_id' => $profileId,
                'notes' => trim($_POST['notes'] ?? '') !== '' ? trim($_POST['notes']) : null,
            ], $elec);

            // On update: blank secrets mean "keep existing" when not applying a profile
            if ($action === 'update_pdu' && !$profileId) {
                $pidKeep = (int)($_POST['pdu_id'] ?? 0);
                if ($pidKeep > 0) {
                    $prev = Database::fetchOne(
                        'SELECT snmp_community, snmp_auth_passphrase, snmp_priv_passphrase FROM pdus WHERE pdu_id = ?',
                        [$pidKeep]
                    );
                    if ($prev) {
                        if (($row['snmp_community'] === null || $row['snmp_community'] === '')
                            && !empty($prev['snmp_community'])) {
                            $row['snmp_community'] = $prev['snmp_community'];
                        }
                        if ($snmpAuthPass === null && !empty($prev['snmp_auth_passphrase'])) {
                            $row['snmp_auth_passphrase'] = $prev['snmp_auth_passphrase'];
                        }
                        if ($snmpPrivPass === null && !empty($prev['snmp_priv_passphrase'])) {
                            $row['snmp_priv_passphrase'] = $prev['snmp_priv_passphrase'];
                        }
                    }
                }
            }

            // New v1/v2c PDUs: default community when left blank
            if ($action === 'add_pdu'
                && ($row['snmp_community'] === null || $row['snmp_community'] === '')
                && in_array((string)($row['snmp_version'] ?? ''), ['1', '2c'], true)
            ) {
                $row['snmp_community'] = 'public';
            }

            // Seal SNMP secrets at rest
            $row = Crypto::sealFields($row, [
                'snmp_community', 'snmp_auth_passphrase', 'snmp_priv_passphrase',
            ]);

            if ($row['name'] === '') {
                throw new RuntimeException('Name is required.');
            }

            if ($action === 'update_pdu') {
                $pid = (int)($_POST['pdu_id'] ?? 0);
                if ($pid <= 0) {
                    throw new RuntimeException('PDU id required.');
                }
                Database::update('pdus', $row, 'pdu_id = :id', [':id' => $pid]);
                if ($outputMode === 'outlets') {
                    $existingOutlets = (int) Database::fetchValue(
                        'SELECT COUNT(*) FROM pdu_outlets WHERE pdu_id = ?',
                        [$pid]
                    );
                    $outletType = $_POST['outlet_type'] ?? 'C13';
                    $outletAmps = $_POST['outlet_amps'] !== '' ? (float)$_POST['outlet_amps'] : null;
                    for ($i = $existingOutlets + 1; $i <= $numOutlets; $i++) {
                        Database::insert('pdu_outlets', [
                            'pdu_id' => $pid,
                            'outlet_number' => $i,
                            'label' => 'Outlet ' . $i,
                            'outlet_type' => $outletType,
                            'rated_amps' => $outletAmps,
                        ]);
                    }
                }
                power_sync_zone_voltage($zoneId, $elec, $scope);
                App::flash('success', 'PDU updated.');
                App::redirect('pages/power_pdus.php?id=' . $pid);
            }

            $row['is_active'] = 1;
            $pid = Database::insert('pdus', $row);
            if ($outputMode === 'outlets') {
                $outletType = $_POST['outlet_type'] ?? 'C13';
                $outletAmps = $_POST['outlet_amps'] !== '' ? (float)$_POST['outlet_amps'] : null;
                for ($i = 1; $i <= $numOutlets; $i++) {
                    Database::insert('pdu_outlets', [
                        'pdu_id' => $pid,
                        'outlet_number' => $i,
                        'label' => 'Outlet ' . $i,
                        'outlet_type' => $outletType,
                        'rated_amps' => $outletAmps,
                    ]);
                }
                $msg = 'PDU created with ' . $numOutlets . ' outlets.';
            } else {
                $msg = 'PDU created with ' . $numBreakerSlots . ' breaker positions. Add breakers below.';
            }
            power_sync_zone_voltage($zoneId, $elec, $scope);
            if ($zoneId && !empty($elec['sync_zone_voltage']) && in_array($scope, ['row', 'room'], true)
                && ($elec['input_voltage'] ?? null) !== null) {
                $msg .= ' Power zone voltage set to ' . (int)$elec['input_voltage'] . ' V.';
            }
            App::flash('success', $msg);
            if ($pid) {
                App::redirect('pages/power_pdus.php?id=' . (int)$pid);
            }
        }

        if ($action === 'add_breaker' || $action === 'update_breaker') {
            $pid = (int)($_POST['pdu_id'] ?? 0);
            $pdu = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ?', [$pid]);
            if (!$pdu) {
                throw new RuntimeException('PDU not found.');
            }
            $maxSlots = max(1, (int)($pdu['num_breaker_slots'] ?? 0));
            if (($pdu['output_mode'] ?? '') !== 'breakers' || $maxSlots < 1) {
                throw new RuntimeException('This PDU is not configured for breakers.');
            }
            $selected = power_parse_breaker_slots($_POST['slots_json'] ?? ($_POST['slots'] ?? ''), $maxSlots);
            if (!$selected) {
                throw new RuntimeException('Select at least one slot on the panel grid.');
            }
            $breakerId = $action === 'update_breaker' ? (int)($_POST['breaker_id'] ?? 0) : null;
            if (!power_breaker_slots_available($pid, $selected, $breakerId ?: null)) {
                throw new RuntimeException('One or more selected slots are already used by another breaker.');
            }
            $num = (int)($_POST['breaker_number'] ?? 0);
            if ($num < 1) {
                $maxN = (int) Database::fetchValue(
                    'SELECT ISNULL(MAX(breaker_number),0) FROM pdu_breakers WHERE pdu_id = ?',
                    [$pid]
                );
                $num = $maxN + 1;
            }
            $fields = [
                'breaker_number' => $num,
                'label' => trim((string)($_POST['label'] ?? '')) !== ''
                    ? trim((string)$_POST['label'])
                    : ('Breaker ' . $num),
                'slots_json' => json_encode($selected),
                'slot_start' => min($selected),
                'slot_end' => max($selected),
                'rated_amps' => $_POST['rated_amps'] !== '' ? (float)$_POST['rated_amps'] : null,
                'phase' => trim((string)($_POST['phase'] ?? '')) !== '' ? trim((string)$_POST['phase']) : null,
                'connected_cabinet_id' => $_POST['connected_cabinet_id'] !== ''
                    ? (int)$_POST['connected_cabinet_id'] : null,
                'notes' => trim((string)($_POST['notes'] ?? '')) !== '' ? trim((string)$_POST['notes']) : null,
            ];
            if ($action === 'update_breaker' && $breakerId) {
                Database::update('pdu_breakers', $fields, 'breaker_id = :id AND pdu_id = :p', [
                    ':id' => $breakerId,
                    ':p' => $pid,
                ]);
                App::flash('success', 'Breaker updated.');
            } else {
                $fields['pdu_id'] = $pid;
                Database::insert('pdu_breakers', $fields);
                $poles = count($selected);
                App::flash('success', "Breaker {$num} added (slots " . power_breaker_slots_label($selected) . ", {$poles} pole).");
            }
            App::redirect('pages/power_pdus.php?id=' . $pid);
        }

        if ($action === 'delete_breaker') {
            $pid = (int)($_POST['pdu_id'] ?? 0);
            $bid = (int)($_POST['breaker_id'] ?? 0);
            if ($bid > 0) {
                Database::delete('pdu_breakers', 'breaker_id = ? AND pdu_id = ?', [$bid, $pid]);
                App::flash('success', 'Breaker removed.');
            }
            App::redirect('pages/power_pdus.php?id=' . $pid);
        }

        if ($action === 'deactivate_pdu') {
            $pid = (int)($_POST['pdu_id'] ?? 0);
            if ($pid > 0) {
                Database::update('pdus', ['is_active' => 0], 'pdu_id = :id', [':id' => $pid]);
                App::flash('success', 'PDU deactivated.');
            }
            App::redirect('pages/power_pdus.php');
        }

        if ($action === 'poll_pdu') {
            $pid = (int)($_POST['pdu_id'] ?? 0);
            if ($pid <= 0) {
                throw new RuntimeException('PDU id required.');
            }
            require_once dirname(__DIR__) . '/src/Services/SnmpPoller.php';
            $result = SnmpPoller::pollPduById($pid);
            $fresh = Database::fetchOne(
                'SELECT last_poll_at, last_poll_watts, last_poll_amps FROM pdus WHERE pdu_id = ?',
                [$pid]
            );
            $bits = [$result['message']];
            if ($fresh && $fresh['last_poll_watts'] !== null) {
                $bits[] = 'Load ' . number_format((float)$fresh['last_poll_watts'] / 1000, 3) . ' kW'
                    . ($fresh['last_poll_amps'] !== null ? ' · ' . rtrim(rtrim(sprintf('%.2F', (float)$fresh['last_poll_amps']), '0'), '.') . ' A' : '');
            }
            App::flash('success', implode(' ', $bits));
            App::redirect('pages/power_pdus.php?id=' . $pid);
        }

        // Create or update SNMP poll target for this PDU using an OID template
        if ($action === 'apply_oid_template') {
            if (!AuthManager::canEditPower($user) && !AuthManager::can($user, 'edit_snmp')) {
                throw new RuntimeException('You do not have permission to configure SNMP targets.');
            }
            $pid = (int)($_POST['pdu_id'] ?? 0);
            if ($pid <= 0) {
                throw new RuntimeException('PDU id required.');
            }
            $pdu = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$pid]);
            if (!$pdu) {
                throw new RuntimeException('PDU not found.');
            }
            $host = trim((string)($_POST['host'] ?? ''));
            if ($host === '') {
                $host = trim((string)($pdu['ip_address'] ?? ''));
            }
            if ($host === '') {
                throw new RuntimeException('Set a host/IP on the PDU or in this form before applying a template.');
            }
            $port = max(1, (int)($_POST['port'] ?? 161));
            $oidMap = SnmpOidTemplates::oidMapFromPost($_POST);
            $templateId = trim((string)($_POST['oid_template'] ?? ''));
            if ($templateId === '' || $templateId === 'custom') {
                // allow custom if OIDs provided
                if (empty($oidMap['watts']) && empty($oidMap['amps']) && empty($oidMap['amps_x10'])) {
                    throw new RuntimeException('Choose a vendor OID template (or fill watt/amp OIDs).');
                }
            }

            // Credentials: profile preferred, else PDU stored SNMPv3 fields
            $profileId = !empty($_POST['profile_id']) ? (int)$_POST['profile_id'] : (int)($pdu['snmp_v3_profile_id'] ?? 0);
            $secName = $pdu['snmp_security_name'] ?? null;
            $authProto = $pdu['snmp_auth_protocol'] ?? null;
            $authPass = $pdu['snmp_auth_passphrase'] ?? null;
            $privProto = $pdu['snmp_priv_protocol'] ?? null;
            $privPass = $pdu['snmp_priv_passphrase'] ?? null;
            $context = $pdu['snmp_context'] ?? null;
            $version = (string)($_POST['snmp_version'] ?? $pdu['snmp_version'] ?? '3');
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
                    $version = '3';
                }
            }

            $targetId = (int)($_POST['target_id'] ?? 0);
            $existing = null;
            if ($targetId > 0) {
                $existing = Database::fetchOne(
                    'SELECT * FROM snmp_targets WHERE target_id = ? AND pdu_id = ?',
                    [$targetId, $pid]
                );
            }
            if (!$existing) {
                $existing = Database::fetchOne(
                    'SELECT TOP 1 * FROM snmp_targets WHERE pdu_id = ? ORDER BY target_id',
                    [$pid]
                );
            }

            $row = [
                'name' => trim((string)($_POST['target_name'] ?? '')) !== ''
                    ? trim((string)$_POST['target_name'])
                    : (($pdu['name'] ?? 'PDU') . ' poll'),
                'host' => $host,
                'port' => $port,
                'snmp_version' => $version,
                'security_name' => $secName,
                'auth_protocol' => $authProto,
                'auth_passphrase' => $authPass,
                'priv_protocol' => $privProto,
                'priv_passphrase' => $privPass,
                'context_name' => $context,
                'poll_interval_sec' => max(30, (int)($_POST['poll_interval_sec'] ?? 300)),
                'oid_map' => json_encode($oidMap, JSON_UNESCAPED_SLASHES),
                'pdu_id' => $pid,
                'device_id' => null,
                'is_enabled' => 1,
            ];

            if ($existing) {
                // keep secrets if null
                if ($row['auth_passphrase'] === null || $row['auth_passphrase'] === '') {
                    $row['auth_passphrase'] = $existing['auth_passphrase'];
                }
                if ($row['priv_passphrase'] === null || $row['priv_passphrase'] === '') {
                    $row['priv_passphrase'] = $existing['priv_passphrase'];
                }
            }
            // Seal target secrets (already-encrypted from profile/PDU pass through)
            $row = Crypto::sealFields($row, ['auth_passphrase', 'priv_passphrase']);

            if ($existing) {
                Database::update('snmp_targets', $row, 'target_id = :id', [':id' => (int)$existing['target_id']]);
                $msg = 'Updated SNMP target for this PDU';
            } else {
                Database::insert('snmp_targets', $row);
                $msg = 'Created SNMP target for this PDU';
            }

            // Optionally enable SNMP on the PDU and store IP
            $pduPatch = [
                'ip_address' => $host,
                'snmp_enabled' => 1,
                'snmp_version' => $version,
                'snmp_port' => $port,
            ];
            if ($profileId) {
                $pduPatch['snmp_v3_profile_id'] = $profileId;
            }
            if ($secName) {
                $pduPatch['snmp_security_name'] = $secName;
            }
            if ($authProto) {
                $pduPatch['snmp_auth_protocol'] = $authProto;
            }
            if ($privProto) {
                $pduPatch['snmp_priv_protocol'] = $privProto;
            }
            if ($authPass) {
                $pduPatch['snmp_auth_passphrase'] = Crypto::encrypt((string)$authPass);
            }
            if ($privPass) {
                $pduPatch['snmp_priv_passphrase'] = Crypto::encrypt((string)$privPass);
            }
            Database::update('pdus', $pduPatch, 'pdu_id = :id', [':id' => $pid]);

            $tpl = !empty($oidMap['_template']) ? SnmpOidTemplates::get((string)$oidMap['_template']) : null;
            App::flash('success', $msg
                . ($tpl ? ' · template: ' . $tpl['label'] : '')
                . '. Use Poll now to test.');
            App::redirect('pages/power_pdus.php?id=' . $pid);
        }
    } catch (Throwable $e) {
        App::log('power_pdus POST failed: ' . $e->getMessage(), 'error');
        App::flash('error', $e->getMessage());
    }
    // Prefer returning to the same PDU detail so errors/saves aren't lost in the list view
    $redirectPid = (int)($_POST['pdu_id'] ?? 0);
    if ($redirectPid > 0) {
        App::redirect('pages/power_pdus.php?id=' . $redirectPid);
    }
    App::redirect('pages/power_pdus.php' . ($filterZone ? '?zone_id=' . $filterZone : ''));
}

// Detail
if ($pduId) {
    $p = Database::fetchOne(
        'SELECT p.*, c.name AS cabinet_name, z.name AS zone_name, z.voltage AS zone_voltage,
                r.name AS row_name
         FROM pdus p
         LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
         LEFT JOIN power_zones z ON z.zone_id = p.zone_id
         LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
         WHERE p.pdu_id = ?',
        [$pduId]
    );
    if (!$p || empty($p['is_active'])) {
        App::flash('error', 'PDU not found.');
        App::redirect('pages/power_pdus.php');
    }
    $outputMode = power_normalize_output_mode($p['output_mode'] ?? 'outlets');
    $outlets = [];
    $breakers = [];
    $slotMap = [];
    $numSlots = (int)($p['num_breaker_slots'] ?? 0);
    if ($outputMode === 'outlets') {
        $outlets = Database::fetchAll(
            'SELECT o.*, d.label AS device_label
             FROM pdu_outlets o
             LEFT JOIN devices d ON d.device_id = o.connected_device_id
             WHERE o.pdu_id = ?
             ORDER BY o.outlet_number',
            [$pduId]
        );
    } else {
        try {
            $breakers = Database::fetchAll(
                'SELECT b.*, c.name AS cabinet_name
                 FROM pdu_breakers b
                 LEFT JOIN cabinets c ON c.cabinet_id = b.connected_cabinet_id
                 WHERE b.pdu_id = ?
                 ORDER BY b.slot_start, b.breaker_number',
                [$pduId]
            );
        } catch (Throwable $e) {
            $breakers = [];
        }
        $slotMap = power_breaker_slot_map($pduId, max(1, $numSlots), $breakers);
    }
    $usedOutlets = count(array_filter($outlets, static fn($o) => !empty($o['connected_device_id'])));
    $usedBreakers = count(array_filter($breakers, static fn($b) => !empty($b['connected_cabinet_id'])));
    $loadKw = $p['last_poll_watts'] !== null ? (float)$p['last_poll_watts'] / 1000.0 : null;
    // Cabinets a breaker can feed: prefer whole power zone (all rows), not just the PDU's row.
    $feedCabinets = [];
    $feedCabinetSource = 'all';
    if (!empty($p['zone_id'])) {
        try {
            $feedCabinets = Database::fetchAll(
                'SELECT c.cabinet_id, c.name, r.name AS row_name, r.row_id
                 FROM cabinets c
                 INNER JOIN cabinet_rows r ON r.row_id = c.row_id
                 WHERE c.is_active = 1 AND r.zone_id = ?
                 ORDER BY r.name, c.name',
                [(int)$p['zone_id']]
            );
            if ($feedCabinets) {
                $feedCabinetSource = 'zone';
            }
        } catch (Throwable $e) {
            $feedCabinets = [];
        }
    }
    if (!$feedCabinets && !empty($p['row_id'])) {
        $feedCabinets = Database::fetchAll(
            'SELECT c.cabinet_id, c.name, r.name AS row_name, r.row_id
             FROM cabinets c
             LEFT JOIN cabinet_rows r ON r.row_id = c.row_id
             WHERE c.is_active = 1 AND c.row_id = ?
             ORDER BY c.name',
            [(int)$p['row_id']]
        );
        if ($feedCabinets) {
            $feedCabinetSource = 'row';
        }
    }
    if (!$feedCabinets) {
        $feedCabinets = array_map(static function ($c) {
            return [
                'cabinet_id' => $c['cabinet_id'],
                'name' => $c['name'],
                'row_name' => null,
                'row_id' => null,
            ];
        }, $cabinets);
        $feedCabinetSource = 'all';
    }
    // Group for <optgroup> by row name
    $feedCabinetsByRow = [];
    foreach ($feedCabinets as $fc) {
        $rn = trim((string)($fc['row_name'] ?? ''));
        if ($rn === '') {
            $rn = 'Other';
        }
        $feedCabinetsByRow[$rn][] = $fc;
    }

    $canConfigSnmp = AuthManager::canEditPower($user) || AuthManager::can($user, 'edit_snmp');

    // Site OID template linked to this PDU (Vendor+Model discover)
    $pduSiteTpl = null;
    $pduSiteTplId = (int)($p['snmp_site_template_id'] ?? 0);
    if ($pduSiteTplId > 0) {
        try {
            $pduSiteTpl = Database::fetchOne(
                'SELECT template_id, name, vendor, model FROM snmp_site_oid_templates WHERE template_id = ?',
                [$pduSiteTplId]
            );
        } catch (Throwable $e) {
            $pduSiteTpl = null;
        }
    }
    $pduDiscoverReady = trim((string)($p['manufacturer'] ?? '')) !== ''
        && trim((string)($p['model'] ?? '')) !== ''
        && trim((string)($p['ip_address'] ?? '')) !== '';

    layout_header('PDU: ' . $p['name'], $user, 'power_pdus');
    ?>
    <div class="flex-between mb-2">
        <div>
            <span class="badge"><?= App::e($p['pdu_scope'] ?? 'rack') ?></span>
            <span class="badge badge-info"><?= App::e(power_wiring_label($p['phase_wiring'] ?? null, (int)($p['phases'] ?? 1))) ?></span>
            <span class="badge <?= $outputMode === 'breakers' ? 'badge-warning' : 'badge-success' ?>">
                <?= $outputMode === 'breakers' ? 'Breakers' : 'Outlets' ?>
            </span>
            <?php if (!empty($p['zone_name'])): ?>
                <span class="text-muted" style="margin-left:.35rem"><?= App::e($p['zone_name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="flex gap-1">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php')) ?>">← All PDUs</a>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">Dashboard</a>
            <?php if ($canConfigSnmp):
                $pduAutoPoll = !empty($p['snmp_auto_poll']);
                ?>
            <label class="snmp-toggle" title="<?= $pduSiteTplId > 0
                ? 'Include this PDU in the SNMP scheduler (uses site OID template)'
                : 'Run Discover OIDs first to assign a site template' ?>">
                <input type="checkbox" id="pduSnmpAutoPollToggle"
                    <?= $pduAutoPoll ? 'checked' : '' ?>
                    <?= $pduSiteTplId > 0 ? '' : 'disabled' ?>>
                <span class="snmp-switch" aria-hidden="true"></span>
                <span class="snmp-toggle-label" id="pduSnmpAutoPollLabel">
                    Scheduled poll <?= $pduAutoPoll ? 'on' : 'off' ?>
                </span>
            </label>
            <button type="button" class="btn btn-secondary" id="btnPduSnmpDiscover"
                <?= $pduDiscoverReady ? '' : 'disabled title="Need manufacturer, model, and IP on this PDU"' ?>>
                Discover OIDs
            </button>
            <form method="post" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="action" value="poll_pdu">
                <input type="hidden" name="pdu_id" value="<?= $pduId ?>">
                <button class="btn btn-primary" type="submit" title="Poll now using the site OID template (not SNMP Targets)">
                    📡 Poll now
                </button>
            </form>
            <?php endif; ?>
            <?php if (!empty($p['cabinet_id'])): ?>
                <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$p['cabinet_id'])) ?>">Cabinet</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="metrics">
        <div class="metric-card warning">
            <div class="label">Polled load</div>
            <div class="value"><?= $loadKw !== null ? number_format($loadKw, 2) : '—' ?> <span class="metric-unit">kW</span></div>
            <div class="sub">
                <?php if ($loadKw !== null && $p['last_poll_watts'] !== null): ?>
                    <?= (int)round((float)$p['last_poll_watts']) ?> W
                    <?php if ($p['last_poll_amps'] !== null): ?>
                        · <?= App::e(rtrim(rtrim(sprintf('%.2F', (float)$p['last_poll_amps']), '0'), '.')) ?> A
                    <?php endif; ?>
                    ·
                <?php endif; ?>
                <?= !empty($p['last_poll_at']) ? App::e((string)$p['last_poll_at']) : 'Never polled' ?>
            </div>
        </div>
        <div class="metric-card">
            <div class="label">Rated</div>
            <div class="value"><?= $p['rated_amps'] !== null ? App::e((string)$p['rated_amps']) : '—' ?> <span class="metric-unit">A</span></div>
            <div class="sub">
                <?php
                $inV = $p['input_voltage'] ?? $p['rated_volts'] ?? null;
                echo $inV !== null ? (int)$inV . ' V in' : '—';
                if (!empty($p['output_voltage'])) {
                    echo ' → ' . (int)$p['output_voltage'] . ' V out';
                }
                ?>
            </div>
        </div>
        <div class="metric-card accent">
            <?php if ($outputMode === 'breakers'): ?>
                <div class="label">Breakers</div>
                <div class="value"><?= count($breakers) ?> <span class="metric-unit">/ <?= max(0, $numSlots) ?> slots</span></div>
                <div class="sub"><?= $usedBreakers ?> assigned to cabinets</div>
            <?php else: ?>
                <div class="label">Outlets</div>
                <div class="value"><?= $usedOutlets ?> <span class="metric-unit">/ <?= count($outlets) ?></span></div>
                <div class="sub">mapped to devices</div>
            <?php endif; ?>
        </div>
        <div class="metric-card">
            <div class="label">SNMP</div>
            <div class="value"><?= !empty($p['snmp_enabled']) ? 'v' . App::e((string)$p['snmp_version']) : 'off' ?></div>
            <div class="sub">
                <?= App::e($p['ip_address'] ?? 'No IP') ?>
                <?php if ($pduSiteTpl):
                    $pduTplLabel = trim(($pduSiteTpl['vendor'] ?? '') . ' / ' . ($pduSiteTpl['model'] ?? ''), ' /');
                    if ($pduTplLabel === '') {
                        $pduTplLabel = (string)($pduSiteTpl['name'] ?? '');
                    }
                    ?>
                    · <?= App::e($pduTplLabel) ?>
                <?php endif; ?>
                <?php if (!empty($p['snmp_auto_poll'])): ?>
                    · scheduled
                <?php endif; ?>
                · <a href="<?= App::e(App::url('pages/snmp.php#oid-templates')) ?>">OID templates</a>
            </div>
        </div>
    </div>

    <?php if ($canConfigSnmp): ?>
    <div class="modal-overlay modal-overlay-glass" id="pduSnmpDiscoverModal" hidden>
        <div class="modal-panel modal-panel-glass modal-panel-glass-wide" role="dialog" aria-modal="true" aria-labelledby="pduSnmpDiscoverTitle">
            <div class="modal-header">
                <h2 id="pduSnmpDiscoverTitle">Discover OIDs — <?= App::e($p['name']) ?></h2>
                <button type="button" class="modal-close" id="pduSnmpDiscoverClose" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="pduSnmpDiscoverLoading" hidden>
                    <p class="text-muted">Walking SNMP roots on <?= App::e((string)($p['ip_address'] ?? 'host')) ?>… this may take up to a minute.</p>
                </div>
                <div id="pduSnmpDiscoverError" class="alert alert-error" hidden></div>
                <div id="pduSnmpDiscoverResults" hidden>
                    <dl class="snmp-discover-meta">
                        <div><dt>Host</dt><dd id="pduSnmpDiscHost">—</dd></div>
                        <div><dt>Template name</dt><dd id="pduSnmpDiscTplName">—</dd></div>
                        <div><dt>Walk count</dt><dd id="pduSnmpDiscWalk">—</dd></div>
                        <div><dt>sysDescr</dt><dd id="pduSnmpDiscSys">—</dd></div>
                    </dl>
                    <p id="pduSnmpDiscMessage" class="text-muted" style="font-size:.9rem;margin-top:0"></p>
                    <h3 style="font-size:.95rem;margin:1rem 0 .4rem">Proposed OID map</h3>
                    <p class="text-muted" style="font-size:.75rem;margin:0 0 .5rem">
                        Edit before creating the site template. Empty metrics are skipped.
                    </p>
                    <ul class="snmp-map-list" id="pduSnmpProposedMap"></ul>
                    <h3 style="font-size:.95rem;margin:1.1rem 0 .4rem">Candidates</h3>
                    <div style="max-height:220px;overflow:auto;border:1px solid rgba(148,163,184,.2);border-radius:8px">
                        <table class="snmp-oid-table">
                            <thead>
                                <tr><th>OID</th><th>Value</th><th>Hint</th><th>Score</th></tr>
                            </thead>
                            <tbody id="pduSnmpCandidateBody"></tbody>
                        </table>
                    </div>
                    <div id="pduSnmpExistsWarn" class="alert alert-warning" hidden style="margin-top:.85rem"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="pduSnmpDiscoverCancel">Close</button>
                <button type="button" class="btn btn-warning" id="pduSnmpDiscoverOverwrite" hidden>Overwrite template</button>
                <button type="button" class="btn btn-primary" id="pduSnmpDiscoverCreate" disabled>Create template</button>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var pduId = <?= (int)$pduId ?>;
        var modal = document.getElementById('pduSnmpDiscoverModal');
        if (!modal) return;
        var loadingEl = document.getElementById('pduSnmpDiscoverLoading');
        var errEl = document.getElementById('pduSnmpDiscoverError');
        var resEl = document.getElementById('pduSnmpDiscoverResults');
        var createBtn = document.getElementById('pduSnmpDiscoverCreate');
        var overwriteBtn = document.getElementById('pduSnmpDiscoverOverwrite');
        var existsWarn = document.getElementById('pduSnmpExistsWarn');
        var lastDiscover = null;

        function toast(msg, type) {
            if (window.ColdAisle && ColdAisle.toast) ColdAisle.toast(msg, type || 'info');
            else alert(msg);
        }
        function api(body) {
            return ColdAisle.api('api/snmp_pdu.php', { method: 'POST', body: body });
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
            document.getElementById('pduSnmpDiscHost').textContent = data.host || '—';
            document.getElementById('pduSnmpDiscTplName').textContent = data.template_name || '—';
            document.getElementById('pduSnmpDiscWalk').textContent = String(data.walk_count != null ? data.walk_count : '—');
            document.getElementById('pduSnmpDiscSys').textContent = data.sysDescr || '—';
            document.getElementById('pduSnmpDiscMessage').textContent = data.message || '';

            var mapUl = document.getElementById('pduSnmpProposedMap');
            mapUl.innerHTML = '';
            var map = data.proposed_map || {};
            Object.keys(map).forEach(function (k) {
                var li = document.createElement('li');
                li.innerHTML = '<label>' + esc(k) + '</label>';
                var inp = document.createElement('input');
                inp.className = 'form-control';
                inp.dataset.metric = k;
                inp.value = map[k] || '';
                li.appendChild(inp);
                mapUl.appendChild(li);
            });
            var li2 = document.createElement('li');
            li2.innerHTML = '<label class="text-muted">+ metric</label>';
            var extra = document.createElement('input');
            extra.className = 'form-control';
            extra.placeholder = 'name=1.3.6… (optional)';
            extra.id = 'pduSnmpExtraMapRow';
            li2.appendChild(extra);
            mapUl.appendChild(li2);

            var tbody = document.getElementById('pduSnmpCandidateBody');
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
            document.querySelectorAll('#pduSnmpProposedMap input[data-metric]').forEach(function (inp) {
                var k = inp.dataset.metric;
                var v = (inp.value || '').trim();
                if (k && v) map[k] = v;
            });
            var extra = document.getElementById('pduSnmpExtraMapRow');
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
                pdu_id: pduId,
                oid_map: map,
                overwrite: !!overwrite
            }).then(function (data) {
                toast(data.message || 'Template saved', 'success');
                setTimeout(function () { window.location.reload(); }, 600);
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
        function startDiscover() {
            openModal();
            setLoading(true);
            showErr('');
            lastDiscover = null;
            api({ action: 'discover', pdu_id: pduId })
                .then(function (data) {
                    setLoading(false);
                    renderDiscover(data);
                })
                .catch(function (err) {
                    setLoading(false);
                    showErr((err && err.message) || 'Discover failed — no template will be created.');
                    toast((err && err.message) || 'Discover failed', 'error');
                });
        }

        var btnDiscover = document.getElementById('btnPduSnmpDiscover');
        if (btnDiscover) btnDiscover.addEventListener('click', startDiscover);

        var autoToggle = document.getElementById('pduSnmpAutoPollToggle');
        var autoLabel = document.getElementById('pduSnmpAutoPollLabel');
        var hasTemplate = <?= $pduSiteTplId > 0 ? 'true' : 'false' ?>;
        if (autoToggle) {
            autoToggle.addEventListener('change', function () {
                var enabled = !!autoToggle.checked;
                autoToggle.disabled = true;
                api({ action: 'set_auto_poll', pdu_id: pduId, enabled: enabled })
                    .then(function (data) {
                        toast(data.message || 'Updated', 'success');
                        if (autoLabel) {
                            autoLabel.textContent = 'Scheduled poll ' + (data.snmp_auto_poll ? 'on' : 'off');
                        }
                    })
                    .catch(function (err) {
                        autoToggle.checked = !enabled;
                        toast((err && err.message) || 'Failed to update scheduled poll', 'error');
                    })
                    .finally(function () {
                        autoToggle.disabled = !hasTemplate;
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
        var c1 = document.getElementById('pduSnmpDiscoverClose');
        var c2 = document.getElementById('pduSnmpDiscoverCancel');
        if (c1) c1.addEventListener('click', closeDiscover);
        if (c2) c2.addEventListener('click', closeDiscover);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeDiscover();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeDiscover();
        });
    })();
    </script>
    <?php endif; ?>

    <div class="split-2">
        <div class="card">
            <div class="card-header"><h2>Edit PDU</h2></div>
            <div class="card-body">
                <?php
                $edit = $p;
                $formAction = 'update_pdu';
                $snmpProfiles = [];
                try {
                    $snmpProfiles = Database::fetchAll(
                        'SELECT profile_id, name, security_name, security_level,
                                auth_protocol, priv_protocol, context_name
                         FROM snmp_v3_profiles WHERE is_active = 1 ORDER BY name'
                    );
                } catch (Throwable $e) {
                    $snmpProfiles = [];
                }
                require __DIR__ . '/_power_pdu_form.php';
                ?>
            </div>
        </div>
        <div class="card">
            <?php if ($outputMode === 'breakers'):
                $layout = power_normalize_breaker_layout($p['breaker_layout'] ?? 'odd_right_even_left');
                $cols = max(1, min(3, (int)($p['breaker_columns'] ?? 2)));
                $panelGrid = power_breaker_panel_grid($numSlots, $layout, $cols);
                $layoutLabel = power_breaker_layout_options()[$layout] ?? $layout;
                ?>
                <div class="card-header"><h2>Breaker panel</h2></div>
                <div class="card-body">
                    <?php if ($numSlots < 1): ?>
                        <p class="text-muted">Set <strong>Breaker positions</strong> and layout on the PDU form and save first.</p>
                    <?php else: ?>
                        <p class="text-muted" style="font-size:.85rem;margin-top:0">
                            Layout: <strong><?= App::e($layoutLabel) ?></strong>.
                            Click free slots to select poles for a new breaker (e.g. 1, 3, 5), then fill AMP / cabinet below.
                        </p>
                        <div class="breaker-panel" id="breakerPanel"
                             style="--brk-cols: <?= (int)max(1, count($panelGrid[0] ?? [1])) ?>;">
                            <?php foreach ($panelGrid as $rowCells): ?>
                                <div class="breaker-panel-row">
                                    <?php foreach ($rowCells as $cell):
                                        $s = $cell['slot'];
                                        if ($s === null): ?>
                                            <div class="breaker-slot pad"></div>
                                        <?php else:
                                            $br = $slotMap[$s] ?? null;
                                            $slotsOf = $br ? power_breaker_slots_of($br, $numSlots) : [];
                                            $isPrimary = $br && $slotsOf && (int)$slotsOf[0] === (int)$s;
                                            $cls = $br ? 'occupied' : 'empty free';
                                            if ($br && !$isPrimary) {
                                                $cls .= ' cont';
                                            }
                                            $title = $br
                                                ? (($br['label'] ?? 'Breaker') . ' · slots ' . power_breaker_slots_label($slotsOf)
                                                    . ($br['rated_amps'] !== null ? ' · ' . $br['rated_amps'] . 'A' : '')
                                                    . (!empty($br['cabinet_name']) ? ' → ' . $br['cabinet_name'] : ''))
                                                : ('Slot ' . $s . ' — click to select');
                                            ?>
                                            <button type="button"
                                                    class="breaker-slot <?= $cls ?>"
                                                    data-slot="<?= (int)$s ?>"
                                                    <?= $br ? 'disabled' : '' ?>
                                                    title="<?= App::e($title) ?>">
                                                <?php if ($isPrimary): ?>
                                                    <span class="bs-num">B<?= (int)$br['breaker_number'] ?></span>
                                                    <?php if ($br['rated_amps'] !== null): ?>
                                                        <span class="bs-amps"><?= (int)$br['rated_amps'] ?>A</span>
                                                    <?php endif; ?>
                                                <?php elseif ($br): ?>
                                                    <span class="bs-slot-id"><?= (int)$s ?></span>
                                                <?php else: ?>
                                                    <span class="bs-slot"><?= (int)$s ?></span>
                                                <?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-muted" style="font-size:.78rem;margin:.55rem 0 0">
                            Selected: <strong id="brkSelLabel">none</strong>
                            <button type="button" class="btn btn-sm btn-ghost" id="brkSelClear" style="margin-left:.5rem">Clear selection</button>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="card-body flush">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>#</th><th>Label</th><th>Slots</th><th>Poles</th><th>Amps</th>
                            <th>Phase</th><th>Cabinet</th><th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($breakers as $b):
                            $slotList = power_breaker_slots_of($b, $numSlots);
                            $poles = count($slotList);
                            ?>
                            <tr>
                                <td><?= (int)$b['breaker_number'] ?></td>
                                <td><?= App::e($b['label'] ?? '—') ?></td>
                                <td style="font-family:var(--mono);font-size:.85rem"><?= App::e(power_breaker_slots_label($slotList)) ?></td>
                                <td><?= $poles ?></td>
                                <td><?= $b['rated_amps'] !== null ? App::e((string)$b['rated_amps']) . ' A' : '—' ?></td>
                                <td><?= App::e($b['phase'] ?? '—') ?></td>
                                <td>
                                    <?php if (!empty($b['connected_cabinet_id'])): ?>
                                        <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$b['connected_cabinet_id'])) ?>">
                                            <?= App::e($b['cabinet_name'] ?? ('#' . $b['connected_cabinet_id'])) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this breaker?');">
                                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_breaker">
                                        <input type="hidden" name="pdu_id" value="<?= $pduId ?>">
                                        <input type="hidden" name="breaker_id" value="<?= (int)$b['breaker_id'] ?>">
                                        <button class="btn btn-sm btn-danger" type="submit">×</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$breakers): ?>
                            <tr><td colspan="8" class="text-muted">No breakers defined yet — select slots on the grid.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($numSlots >= 1 && AuthManager::canEditPower($user)): ?>
                <div class="card-body">
                    <h3 class="mt-0">Create breaker from selection</h3>
                    <form method="post" class="form-grid" id="addBreakerForm">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="add_breaker">
                        <input type="hidden" name="pdu_id" value="<?= $pduId ?>">
                        <input type="hidden" name="slots_json" id="brkSlotsJson" value="[]">
                        <div class="form-row"><label>Breaker #</label>
                            <input class="form-control" type="number" min="1" name="breaker_number"
                                   placeholder="Auto"></div>
                        <div class="form-row"><label>Label</label>
                            <input class="form-control" name="label" placeholder="e.g. Cab-01 feed"></div>
                        <div class="form-row"><label>AMP rating</label>
                            <input class="form-control" type="number" step="0.1" name="rated_amps" value="20"></div>
                        <div class="form-row"><label>Phase</label>
                            <select class="form-control" name="phase">
                                <option value="">—</option>
                                <?php foreach (['A','B','C','AB','BC','CA','ABC','N'] as $ph): ?>
                                    <option><?= $ph ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row"><label>Cabinet (fed by this breaker)</label>
                            <select class="form-control" name="connected_cabinet_id">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($feedCabinetsByRow as $rowLabel => $cabs): ?>
                                    <?php if (count($feedCabinetsByRow) > 1): ?>
                                        <optgroup label="<?= App::e((string)$rowLabel) ?>">
                                            <?php foreach ($cabs as $c): ?>
                                                <option value="<?= (int)$c['cabinet_id'] ?>"><?= App::e($c['name']) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php else: ?>
                                        <?php foreach ($cabs as $c): ?>
                                            <option value="<?= (int)$c['cabinet_id'] ?>">
                                                <?= App::e($c['name']) ?><?= !empty($c['row_name']) ? ' · ' . App::e((string)$c['row_name']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-muted" style="font-size:.72rem;margin:.25rem 0 0">
                                <?php if ($feedCabinetSource === 'zone'): ?>
                                    Cabinets in this power zone (all assigned rows).
                                <?php elseif ($feedCabinetSource === 'row'): ?>
                                    Cabinets on this PDU’s row only —
                                    <?php if (!empty($p['zone_id'])): ?>
                                        assign more rows on the
                                        <a href="<?= App::e(App::url('pages/power_zones.php?id=' . (int)$p['zone_id'])) ?>">zone page</a>
                                        to include other rows on the same feed.
                                    <?php else: ?>
                                        set the PDU’s zone and assign rows on
                                        <a href="<?= App::e(App::url('pages/power_zones.php')) ?>">Zones</a>
                                        to include racks from other rows.
                                    <?php endif; ?>
                                <?php else: ?>
                                    All cabinets (no zone/row filter). Assign rows to the zone for a focused list.
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="form-row full"><label>Notes</label>
                            <input class="form-control" name="notes" placeholder="Pigtail color, panel schedule ref…"></div>
                        <div class="form-row">
                            <button class="btn btn-primary" type="submit" id="brkSubmit" disabled>Add breaker (select slots first)</button>
                        </div>
                    </form>
                    <script>
                    (function () {
                        var panel = document.getElementById('breakerPanel');
                        var jsonEl = document.getElementById('brkSlotsJson');
                        var labelEl = document.getElementById('brkSelLabel');
                        var clearBtn = document.getElementById('brkSelClear');
                        var submitBtn = document.getElementById('brkSubmit');
                        if (!panel || !jsonEl) return;
                        var selected = {};
                        function refresh() {
                            var list = Object.keys(selected).map(Number).sort(function (a, b) { return a - b; });
                            jsonEl.value = JSON.stringify(list);
                            if (labelEl) labelEl.textContent = list.length ? list.join(', ') : 'none';
                            if (submitBtn) {
                                submitBtn.disabled = list.length === 0;
                                submitBtn.textContent = list.length
                                    ? ('Add breaker on slots ' + list.join(', '))
                                    : 'Add breaker (select slots first)';
                            }
                            panel.querySelectorAll('.breaker-slot.free').forEach(function (btn) {
                                var s = parseInt(btn.getAttribute('data-slot'), 10);
                                btn.classList.toggle('selected', !!selected[s]);
                            });
                        }
                        panel.addEventListener('click', function (e) {
                            var btn = e.target.closest('.breaker-slot.free');
                            if (!btn || btn.disabled) return;
                            var s = parseInt(btn.getAttribute('data-slot'), 10);
                            if (!s) return;
                            if (selected[s]) delete selected[s];
                            else selected[s] = true;
                            refresh();
                        });
                        if (clearBtn) clearBtn.addEventListener('click', function () {
                            selected = {};
                            refresh();
                        });
                        refresh();
                    })();
                    </script>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="card-header"><h2>Outlets</h2></div>
                <div class="card-body flush">
                    <div class="table-wrap" style="max-height:420px;overflow:auto">
                        <table class="data">
                            <thead><tr><th>#</th><th>Type</th><th>A</th><th>Device</th></tr></thead>
                            <tbody>
                            <?php foreach ($outlets as $o): ?>
                                <tr>
                                    <td><?= (int)$o['outlet_number'] ?></td>
                                    <td><?= App::e($o['outlet_type'] ?? '—') ?></td>
                                    <td><?= $o['rated_amps'] !== null ? App::e((string)$o['rated_amps']) : '—' ?></td>
                                    <td>
                                        <?php if (!empty($o['connected_device_id'])): ?>
                                            <a href="<?= App::e(App::url('pages/devices.php?id=' . (int)$o['connected_device_id'])) ?>">
                                                <?= App::e($o['device_label'] ?? ('#' . $o['connected_device_id'])) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$outlets): ?>
                                <tr><td colspan="4" class="text-muted">No outlets.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted" style="font-size:.78rem;padding:.65rem 1rem;margin:0">
                        Map outlets to devices from the cabinet rack view PDU overlay or device Power Supply section.
                    </p>
                </div>
            <?php endif; ?>
            <div class="card-body">
                <form method="post" onsubmit="return confirm('Deactivate this PDU?');">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="deactivate_pdu">
                    <input type="hidden" name="pdu_id" value="<?= $pduId ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Deactivate PDU</button>
                </form>
            </div>
        </div>
    </div>
    <style>
    .breaker-panel {
      display: flex;
      flex-direction: column;
      gap: 2px;
      max-width: 11.5rem;
      user-select: none;
    }
    .breaker-panel-row {
      display: grid;
      grid-template-columns: repeat(var(--brk-cols, 2), minmax(0, 1fr));
      gap: 2px;
    }
    .breaker-slot {
      appearance: none;
      min-height: 1.35rem;
      height: 1.35rem;
      border-radius: 3px;
      border: 1px solid var(--border);
      background: #0f172a;
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: center;
      gap: 2px;
      font-size: .62rem;
      color: var(--muted);
      cursor: pointer;
      padding: 0 2px;
      font: inherit;
      line-height: 1;
    }
    .breaker-slot.pad {
      visibility: hidden;
      pointer-events: none;
      min-height: 1.35rem;
      height: 1.35rem;
    }
    .breaker-slot.free:hover {
      border-color: var(--accent);
      background: #1e293b;
      color: var(--text);
    }
    .breaker-slot.free.selected {
      border-color: #f59e0b;
      background: linear-gradient(160deg, #78350f, #b45309);
      color: #fef3c7;
      box-shadow: 0 0 0 1px #f59e0b88;
    }
    /* Free = dark slate; used = translucent red so taken poles are obvious */
    .breaker-slot.occupied,
    .breaker-slot.occupied:disabled {
      cursor: default;
      opacity: 1;
      background: rgba(239, 68, 68, 0.42);
      border-color: rgba(248, 113, 113, 0.8);
      color: #fecaca;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
    }
    .breaker-slot.occupied.cont,
    .breaker-slot.occupied.cont:disabled {
      /* Additional poles of same breaker — slightly lighter wash */
      background: rgba(239, 68, 68, 0.26);
      border-color: rgba(248, 113, 113, 0.55);
      border-style: solid;
      opacity: 1;
      color: #fca5a5;
    }
    .breaker-slot:disabled { cursor: default; opacity: 1; }
    .breaker-slot .bs-num { font-weight: 700; font-size: .58rem; line-height: 1; }
    .breaker-slot .bs-amps { font-size: .5rem; opacity: .85; }
    .breaker-slot .bs-slot { font-family: var(--mono); font-size: .68rem; font-weight: 600; }
    .breaker-slot .bs-slot-id { font-family: var(--mono); font-size: .58rem; opacity: .9; font-weight: 600; }
    </style>
    <?php
    layout_footer();
    exit;
}

// List
$sql = 'SELECT p.*, c.name AS cabinet_name, z.name AS zone_name, z.voltage AS zone_voltage,
               r.name AS row_name
        FROM pdus p
        LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
        LEFT JOIN power_zones z ON z.zone_id = p.zone_id
        LEFT JOIN cabinet_rows r ON r.row_id = p.row_id
        WHERE p.is_active = 1';
$params = [];
if ($filterZone) {
    $sql .= ' AND p.zone_id = ?';
    $params[] = $filterZone;
}
$sql .= ' ORDER BY p.name';
$pdus = Database::fetchAll($sql, $params);

layout_header('PDU Management', $user, 'power_pdus');
?>
<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0">
            Rack, row, and room PDUs
            <?php if ($filterZone): ?>
                · filtered by zone
                <a href="<?= App::e(App::url('pages/power_pdus.php')) ?>">(clear)</a>
            <?php endif; ?>
        </p>
    </div>
    <div class="flex gap-1">
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">← Dashboard</a>
        <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_zones.php')) ?>">Zones</a>
        <a class="btn btn-primary" href="#add-pdu">+ PDU</a>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>All PDUs (<?= count($pdus) ?>)</h2></div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th><th>Scope</th><th>Output</th><th>Phases</th><th>In → Out</th>
                <th>Location</th><th>Zone</th><th>Amps</th><th>Load</th><th>SNMP</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pdus as $p):
                $inV = $p['input_voltage'] ?? $p['rated_volts'] ?? null;
                $outV = $p['output_voltage'] ?? null;
                $voltLabel = '—';
                if ($inV !== null || $outV !== null) {
                    if (!empty($p['input_voltage_ln'])) {
                        $voltLabel = $inV . '/' . $p['input_voltage_ln'] . 'V';
                    } else {
                        $voltLabel = $inV !== null ? $inV . 'V' : '—';
                    }
                    $voltLabel .= ' → ' . ($outV !== null ? $outV . 'V' : '—');
                }
                $loc = [];
                if (!empty($p['cabinet_name'])) {
                    $loc[] = $p['cabinet_name'];
                }
                if (!empty($p['row_name'])) {
                    $loc[] = 'Row ' . $p['row_name'];
                }
                ?>
                <tr>
                    <td><a href="?id=<?= (int)$p['pdu_id'] ?>"><strong><?= App::e($p['name']) ?></strong></a></td>
                    <td><span class="badge"><?= App::e($p['pdu_scope'] ?? 'rack') ?></span></td>
                    <td>
                        <?php $om = power_normalize_output_mode($p['output_mode'] ?? 'outlets'); ?>
                        <span class="badge <?= $om === 'breakers' ? 'badge-warning' : '' ?>">
                            <?= $om === 'breakers'
                                ? ('Breakers · ' . (int)($p['num_breaker_slots'] ?? 0) . ' slots')
                                : ((int)($p['num_outlets'] ?? 0) . ' outlets') ?>
                        </span>
                    </td>
                    <td><span class="badge badge-info"><?= App::e(power_wiring_label($p['phase_wiring'] ?? null, (int)($p['phases'] ?? 1))) ?></span></td>
                    <td style="font-size:.85rem"><?= App::e($voltLabel) ?></td>
                    <td>
                        <?php if (!empty($p['cabinet_id'])): ?>
                            <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$p['cabinet_id'])) ?>">
                                <?= App::e(implode(' · ', $loc) ?: '—') ?>
                            </a>
                        <?php else: ?>
                            <?= App::e(implode(' · ', $loc) ?: '—') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($p['zone_id'])): ?>
                            <a href="<?= App::e(App::url('pages/power_zones.php?id=' . (int)$p['zone_id'])) ?>">
                                <?= App::e($p['zone_name'] ?? '—') ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $p['rated_amps'] !== null ? App::e((string)$p['rated_amps']) : '—' ?></td>
                    <td><?= $p['last_poll_watts'] !== null ? number_format((float)$p['last_poll_watts'] / 1000, 2) . ' kW' : '—' ?></td>
                    <td><?= !empty($p['snmp_enabled']) ? '<span class="badge badge-success">v' . App::e((string)$p['snmp_version']) . '</span>' : '—' ?></td>
                    <td class="actions">
                        <a class="btn btn-sm btn-secondary" href="?id=<?= (int)$p['pdu_id'] ?>">Manage</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pdus): ?>
                <tr><td colspan="11" class="text-muted">No PDUs yet. Add one below or from a cabinet view.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="add-pdu">
    <div class="card-header"><h2>Add PDU</h2></div>
    <div class="card-body">
        <?php
        $edit = [
            'zone_id' => $filterZone ?: null,
            'pdu_scope' => 'rack',
            'phases' => 1,
            'phase_wiring' => 'single',
            'input_voltage' => 208,
            'output_voltage' => 208,
            'num_outlets' => 24,
            'output_mode' => 'outlets',
            'num_breaker_slots' => 42,
            'breaker_layout' => 'odd_right_even_left',
            'breaker_columns' => 2,
            'rated_amps' => 30,
            'mount_style' => 'vertical_rear',
            'snmp_version' => '2c',
            'snmp_port' => 161,
            'sync_zone_voltage' => 1,
        ];
        $formAction = 'add_pdu';
        $snmpProfiles = [];
        try {
            $snmpProfiles = Database::fetchAll(
                'SELECT profile_id, name, security_name, security_level,
                        auth_protocol, priv_protocol, context_name
                 FROM snmp_v3_profiles WHERE is_active = 1 ORDER BY name'
            );
        } catch (Throwable $e) {
            $snmpProfiles = [];
        }
        require __DIR__ . '/_power_pdu_form.php';
        ?>
    </div>
</div>
<?php layout_footer(); ?>
