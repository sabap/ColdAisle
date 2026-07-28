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
        if ($action === 'save_pdu_template') {
            $pid = (int)($_POST['pdu_id'] ?? 0);
            $overwrite = !empty($_POST['overwrite']);
            if ($pid <= 0) {
                throw new RuntimeException('PDU id required.');
            }
            $src = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$pid]);
            if (!$src) {
                throw new RuntimeException('PDU not found.');
            }
            $vendor = trim((string)($src['manufacturer'] ?? ''));
            $model = trim((string)($src['model'] ?? ''));
            if ($vendor === '' || $model === '') {
                throw new RuntimeException('Set manufacturer and model on this PDU before creating a template.');
            }
            $name = power_pdu_template_display_name($vendor, $model);
            $outlets = Database::fetchAll(
                'SELECT outlet_number, outlet_type, rated_amps, label
                 FROM pdu_outlets WHERE pdu_id = ? ORDER BY outlet_number',
                [$pid]
            );
            $payload = power_pdu_template_payload_from_pdu($src, $outlets);
            // Prefer inventory count when outlets exist
            if ($outlets) {
                $payload['fields']['num_outlets'] = count($outlets);
            }
            $fieldsJson = json_encode($payload['fields'], JSON_UNESCAPED_SLASHES);
            $outletsJson = $payload['outlets']
                ? json_encode($payload['outlets'], JSON_UNESCAPED_SLASHES)
                : null;
            $oidTplId = (int)($payload['fields']['snmp_site_template_id'] ?? 0);
            $oidTplLabel = '';
            if ($oidTplId > 0) {
                try {
                    $oidRow = Database::fetchOne(
                        'SELECT name FROM snmp_site_oid_templates WHERE template_id = ?',
                        [$oidTplId]
                    );
                    $oidTplLabel = $oidRow ? (string)$oidRow['name'] : ('#' . $oidTplId);
                } catch (Throwable $e) {
                    $oidTplLabel = '#' . $oidTplId;
                }
            }

            $existing = null;
            try {
                $existing = Database::fetchOne(
                    'SELECT template_id, name FROM pdu_templates
                     WHERE is_active = 1 AND (
                        name = ? OR (vendor = ? AND model = ?)
                     )',
                    [$name, $vendor, $model]
                );
            } catch (Throwable $e) {
                // table may not exist until Schema ensure — force ensure
                if (class_exists('Schema')) {
                    Schema::ensure();
                }
                $existing = Database::fetchOne(
                    'SELECT template_id, name FROM pdu_templates
                     WHERE is_active = 1 AND (
                        name = ? OR (vendor = ? AND model = ?)
                     )',
                    [$name, $vendor, $model]
                );
            }

            if ($existing && !$overwrite) {
                App::flash(
                    'error',
                    'PDU template "' . $name . '" already exists. '
                    . 'Confirm overwrite from the PDU page, or cancel.'
                );
                // Stash so UI can offer overwrite without re-typing
                $_SESSION['pdu_template_overwrite'] = [
                    'pdu_id' => $pid,
                    'template_id' => (int)$existing['template_id'],
                    'name' => $name,
                ];
                App::redirect('pages/power_pdus.php?id=' . $pid . '&tpl_exists=1');
            }

            if ($existing) {
                Database::update('pdu_templates', [
                    'name' => $name,
                    'vendor' => $vendor,
                    'model' => $model,
                    'fields_json' => $fieldsJson,
                    'outlets_json' => $outletsJson,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'is_active' => 1,
                ], 'template_id = :id', [':id' => (int)$existing['template_id']]);
                unset($_SESSION['pdu_template_overwrite']);
                $msg = 'Overwrote PDU template "' . $name . '".';
                if ($oidTplLabel !== '') {
                    $msg .= ' Includes OID map "' . $oidTplLabel . '".';
                } else {
                    $msg .= ' No site OID template on this PDU — assign one (Edit or Discover) and re-save the template if you need polling.';
                }
                App::flash('success', $msg);
            } else {
                Database::insert('pdu_templates', [
                    'name' => $name,
                    'vendor' => $vendor,
                    'model' => $model,
                    'fields_json' => $fieldsJson,
                    'outlets_json' => $outletsJson,
                    'is_active' => 1,
                ]);
                $msg = 'Created PDU template "' . $name . '". Apply it when adding new PDUs.';
                if ($oidTplLabel !== '') {
                    $msg .= ' Includes OID map "' . $oidTplLabel . '".';
                } else {
                    $msg .= ' No site OID template on this PDU — assign Discover/OID map first for poll-ready templates.';
                }
                App::flash('success', $msg);
            }
            App::redirect('pages/power_pdus.php?id=' . $pid);
        }

        if ($action === 'add_pdu' || $action === 'update_pdu') {
            $outputMode = power_normalize_output_mode($_POST['output_mode'] ?? 'outlets');
            // Outlets: count comes from SNMP / template / detail editor — not create form type pickers
            $numOutlets = max(0, min(128, (int)($_POST['num_outlets'] ?? 0)));
            $numBreakerSlots = max(1, min(128, (int)($_POST['num_breaker_slots'] ?? 42)));
            $applyTplId = (int)($_POST['pdu_template_id'] ?? 0);
            $tplOutlets = [];
            $tplFields = [];
            if ($action === 'add_pdu' && $applyTplId > 0) {
                try {
                    $tplRow = Database::fetchOne(
                        'SELECT * FROM pdu_templates WHERE template_id = ? AND is_active = 1',
                        [$applyTplId]
                    );
                    if ($tplRow) {
                        $tplFields = json_decode((string)($tplRow['fields_json'] ?? '{}'), true) ?: [];
                        $tplOutlets = json_decode((string)($tplRow['outlets_json'] ?? '[]'), true) ?: [];
                        if ($numOutlets < 1 && !empty($tplFields['num_outlets'])) {
                            $numOutlets = max(0, min(128, (int)$tplFields['num_outlets']));
                        }
                        if (!$tplOutlets && $numOutlets < 1 && is_array($tplOutlets)) {
                            // keep 0
                        }
                        if ($numOutlets < 1 && $tplOutlets) {
                            $numOutlets = count($tplOutlets);
                        }
                        // Bundle site OID template + poll flag from PDU template when form left blank
                        if ((int)($_POST['snmp_site_template_id'] ?? 0) < 1
                            && !empty($tplFields['snmp_site_template_id'])
                        ) {
                            $_POST['snmp_site_template_id'] = (string)(int)$tplFields['snmp_site_template_id'];
                        }
                        if (empty($_POST['snmp_enabled'])
                            && (!empty($tplFields['snmp_enabled']) || !empty($tplFields['snmp_site_template_id']))
                        ) {
                            $_POST['snmp_enabled'] = '1';
                        }
                        if (!isset($_POST['snmp_auto_poll']) && !empty($tplFields['snmp_auto_poll'])) {
                            $_POST['snmp_auto_poll'] = '1';
                        }
                        // Fill common SNMP profile fields from template if empty on form
                        foreach (['snmp_version', 'snmp_port', 'snmp_v3_profile_id', 'snmp_v3_sec_level'] as $sk) {
                            if ((!isset($_POST[$sk]) || $_POST[$sk] === '' || $_POST[$sk] === '0')
                                && isset($tplFields[$sk]) && $tplFields[$sk] !== '' && $tplFields[$sk] !== null
                            ) {
                                $_POST[$sk] = (string)$tplFields[$sk];
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $tplFields = [];
                    $tplOutlets = [];
                }
            }
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
            // Site OID template (Discover map) — required for Poll now / scheduler
            $siteTplId = (int)($_POST['snmp_site_template_id'] ?? 0);
            if ($siteTplId > 0) {
                try {
                    $stOk = Database::fetchOne(
                        'SELECT template_id FROM snmp_site_oid_templates WHERE template_id = ? AND is_active = 1',
                        [$siteTplId]
                    );
                    if (!$stOk) {
                        $siteTplId = 0;
                    }
                } catch (Throwable $e) {
                    $siteTplId = 0;
                }
            }
            $snmpEnabled = !empty($_POST['snmp_enabled']) ? 1 : 0;
            if ($siteTplId > 0) {
                $snmpEnabled = 1; // template implies SNMP on
            }
            $snmpAutoPoll = (!empty($_POST['snmp_auto_poll']) && $siteTplId > 0) ? 1 : 0;

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
                'serial_no' => trim((string)($_POST['serial_no'] ?? '')) !== ''
                    ? trim((string)$_POST['serial_no']) : null,
                'ip_address' => $_POST['ip_address'] !== '' ? $_POST['ip_address'] : null,
                'output_mode' => $outputMode,
                'num_outlets' => $outputMode === 'outlets' ? $numOutlets : 0,
                'num_breaker_slots' => $outputMode === 'breakers' ? $numBreakerSlots : null,
                'breaker_layout' => $outputMode === 'breakers' ? $breakerLayout : null,
                'breaker_columns' => $outputMode === 'breakers' ? $breakerColumns : null,
                'rated_amps' => $_POST['rated_amps'] !== '' ? (float)$_POST['rated_amps'] : null,
                'input_type' => $_POST['input_type'] !== '' ? $_POST['input_type'] : null,
                'snmp_enabled' => $snmpEnabled,
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
                'snmp_site_template_id' => $siteTplId > 0 ? $siteTplId : null,
                'snmp_auto_poll' => $snmpAutoPoll,
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
                // Don't zero out inventory count on edit if form omitted it
                if ($outputMode === 'outlets' && $numOutlets < 1) {
                    unset($row['num_outlets']);
                }
                Database::update('pdus', $row, 'pdu_id = :id', [':id' => $pid]);
                power_sync_zone_voltage($zoneId, $elec, $scope);
                App::flash('success', 'PDU updated.');
                App::redirect('pages/power_pdus.php?id=' . $pid);
            }

            $row['is_active'] = 1;
            if ($outputMode === 'outlets' && $numOutlets < 1) {
                $row['num_outlets'] = 0;
            }
            if ($action === 'add_pdu' && $applyTplId > 0) {
                $row['pdu_template_id'] = $applyTplId;
            }
            $pid = Database::insert('pdus', $row);
            if ($outputMode === 'outlets') {
                if ($numOutlets > 0) {
                    power_sync_outlet_inventory((int)$pid, $numOutlets, 'C13', null);
                    if ($tplOutlets) {
                        power_apply_outlet_defs((int)$pid, $tplOutlets, true);
                    }
                    $msg = 'PDU created with ' . $numOutlets . ' outlet inventory row(s)'
                        . ($applyTplId > 0 ? ' from template' : '') . '.';
                } else {
                    $msg = 'PDU created. Outlet inventory will fill from SNMP poll (device outlet count) or a template.';
                }
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

        if ($action === 'save_outlets') {
            $pid = (int)($_POST['pdu_id'] ?? 0);
            if ($pid <= 0) {
                throw new RuntimeException('PDU id required.');
            }
            $pduRow = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$pid]);
            if (!$pduRow) {
                throw new RuntimeException('PDU not found.');
            }
            $types = power_outlet_connector_types();
            $numOutlets = max(1, min(128, (int)($_POST['num_outlets'] ?? ($pduRow['num_outlets'] ?? 24))));
            $defaultType = (string)($_POST['default_outlet_type'] ?? 'C13');
            if (!in_array($defaultType, $types, true)) {
                $defaultType = 'C13';
            }
            // Grow/shrink inventory rows (new rows get default type)
            $existing = Database::fetchAll(
                'SELECT outlet_id, outlet_number FROM pdu_outlets WHERE pdu_id = ?',
                [$pid]
            );
            $byNum = [];
            foreach ($existing as $o) {
                $byNum[(int)$o['outlet_number']] = $o;
            }
            for ($i = 1; $i <= $numOutlets; $i++) {
                if (!isset($byNum[$i])) {
                    Database::insert('pdu_outlets', [
                        'pdu_id' => $pid,
                        'outlet_number' => $i,
                        'label' => 'Outlet ' . $i,
                        'outlet_type' => $defaultType,
                    ]);
                }
            }
            foreach ($byNum as $num => $o) {
                if ($num > $numOutlets) {
                    // Only delete unmapped extras
                    $full = Database::fetchOne('SELECT * FROM pdu_outlets WHERE outlet_id = ?', [(int)$o['outlet_id']]);
                    if ($full && empty($full['connected_device_id']) && empty($full['device_power_supply_id'])) {
                        Database::delete('pdu_outlets', 'outlet_id = ?', [(int)$o['outlet_id']]);
                    }
                }
            }
            Database::update('pdus', ['num_outlets' => $numOutlets], 'pdu_id = :id', [':id' => $pid]);

            $ids = $_POST['outlet_id'] ?? [];
            $labels = $_POST['outlet_label'] ?? [];
            $oTypes = $_POST['outlet_type'] ?? [];
            $amps = $_POST['outlet_rated_amps'] ?? [];
            if (is_array($ids)) {
                foreach ($ids as $idx => $oidRaw) {
                    $oid = (int)$oidRaw;
                    if ($oid < 1) {
                        continue;
                    }
                    $row = Database::fetchOne(
                        'SELECT * FROM pdu_outlets WHERE outlet_id = ? AND pdu_id = ?',
                        [$oid, $pid]
                    );
                    if (!$row) {
                        continue;
                    }
                    $type = trim((string)($oTypes[$idx] ?? $row['outlet_type'] ?? 'C13'));
                    if ($type === '' || !in_array($type, $types, true)) {
                        // Allow free-text types already stored / custom
                        if ($type === '') {
                            $type = 'C13';
                        }
                    }
                    $label = trim((string)($labels[$idx] ?? ''));
                    $rated = isset($amps[$idx]) && $amps[$idx] !== '' && is_numeric($amps[$idx])
                        ? (float)$amps[$idx]
                        : null;
                    Database::update('pdu_outlets', [
                        'label' => $label !== '' ? $label : null,
                        'outlet_type' => $type,
                        'rated_amps' => $rated,
                    ], 'outlet_id = :id AND pdu_id = :p', [
                        ':id' => $oid,
                        ':p' => $pid,
                    ]);
                }
            }
            // Optional bulk type range: e.g. 1-21 C13, 22-24 C19
            $bulkFrom = (int)($_POST['bulk_from'] ?? 0);
            $bulkTo = (int)($_POST['bulk_to'] ?? 0);
            $bulkType = trim((string)($_POST['bulk_type'] ?? ''));
            if ($bulkFrom >= 1 && $bulkTo >= $bulkFrom && $bulkType !== ''
                && in_array($bulkType, $types, true)
            ) {
                $bulkTo = min($bulkTo, $numOutlets);
                for ($n = $bulkFrom; $n <= $bulkTo; $n++) {
                    Database::update(
                        'pdu_outlets',
                        ['outlet_type' => $bulkType],
                        'pdu_id = :p AND outlet_number = :n',
                        [':p' => $pid, ':n' => $n]
                    );
                }
            }
            App::flash('success', 'Outlets saved (' . $numOutlets . ' inventory rows).');
            App::redirect('pages/power_pdus.php?id=' . $pid);
        }

        if ($action === 'poll_pdu') {
            $pid = (int)($_POST['pdu_id'] ?? 0);
            if ($pid <= 0) {
                throw new RuntimeException('PDU id required.');
            }
            require_once dirname(__DIR__) . '/src/Services/SnmpPoller.php';
            $result = SnmpPoller::pollPduById($pid);
            $fresh = Database::fetchOne(
                'SELECT last_poll_at, last_poll_watts, last_poll_amps, last_poll_outlets FROM pdus WHERE pdu_id = ?',
                [$pid]
            );
            $bits = [$result['message']];
            if ($fresh && $fresh['last_poll_watts'] !== null) {
                $bits[] = 'Load ' . number_format((float)$fresh['last_poll_watts'] / 1000, 3) . ' kW'
                    . ($fresh['last_poll_amps'] !== null ? ' · ' . rtrim(rtrim(sprintf('%.2F', (float)$fresh['last_poll_amps']), '0'), '.') . ' A' : '');
            }
            if (!empty($fresh['last_poll_outlets'])) {
                $od = json_decode((string)$fresh['last_poll_outlets'], true);
                if (is_array($od) && $od) {
                    $bits[] = count($od) . ' outlet(s)';
                }
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
    $tplExists = !empty($_GET['tpl_exists']) && !empty($_SESSION['pdu_template_overwrite'])
        && (int)($_SESSION['pdu_template_overwrite']['pdu_id'] ?? 0) === $pduId;
    $tplExistName = $tplExists ? (string)($_SESSION['pdu_template_overwrite']['name'] ?? '') : '';
    ?>
    <?php if ($tplExists): ?>
    <div class="alert alert-warning" style="margin-bottom:1rem">
        <strong>PDU template already exists:</strong> <?= App::e($tplExistName) ?>.
        Overwrite with this PDU’s electrical settings and outlet types, or cancel.
        <form method="post" style="display:inline;margin-left:.5rem">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="save_pdu_template">
            <input type="hidden" name="pdu_id" value="<?= $pduId ?>">
            <input type="hidden" name="overwrite" value="1">
            <button class="btn btn-sm btn-primary" type="submit">Overwrite template</button>
        </form>
        <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php?id=' . $pduId)) ?>">Cancel</a>
    </div>
    <?php endif; ?>
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
        <div class="flex gap-1" style="flex-wrap:wrap">
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power_pdus.php')) ?>">← All PDUs</a>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/power.php')) ?>">Dashboard</a>
            <?php if (AuthManager::canEditPower($user)): ?>
                <button type="button" class="btn btn-primary" data-open-modal="modal-edit-pdu">Edit PDU</button>
                <form method="post" style="display:inline" id="pduCreateTemplateForm">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_pdu_template">
                    <input type="hidden" name="pdu_id" value="<?= $pduId ?>">
                    <input type="hidden" name="overwrite" id="pduTplOverwrite" value="0">
                    <button class="btn btn-secondary" type="submit"
                            title="Save electrical + outlet layout + site OID map as a reusable template (Vendor+Model)">
                        Create PDU template
                    </button>
                </form>
            <?php endif; ?>
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
                <button class="btn btn-secondary" type="submit" title="Poll now using the site OID template (not SNMP Targets)">
                    Poll now
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

    <!-- 24h PDU history -->
    <div class="card power-history-wide mb-2" data-power-history data-scope="pdu" data-id="<?= (int)$pduId ?>" data-hours="24">
        <div class="card-header flex-between">
            <h2 style="margin:0;font-size:1.05rem">Last 24 hours</h2>
            <span class="text-muted" style="font-size:.8rem">SNMP samples · outage markers · per-phase V when available</span>
        </div>
        <div class="card-body power-history-body">
            <div class="power-outage-summary" data-outage-summary hidden></div>
            <div class="power-chart power-chart-lg" data-metric="kw" data-unit="kW" data-label="Output (usage)" data-color="#38bdf8" data-height="180"></div>
            <div class="power-chart" data-metric="volts" data-unit="V" data-label="Input voltage (avg L–N)" data-color="#a78bfa" data-height="140"></div>
            <div class="power-chart" data-metric="phase_volts" data-unit="V" data-label="Phase voltages L1 / L2 / L3" data-color="#94a3b8" data-height="160"></div>
        </div>
    </div>

    <?php
    $phaseSnap = power_phase_poll_decode($p['last_poll_phases'] ?? null);
    $phaseRows = $phaseSnap['rows'] ?? [];
    if ($phaseSnap && ($phaseRows || !empty($phaseSnap['ll']) || !empty($phaseSnap['device']) || !empty($phaseSnap['ps']))):
        $phaseWattsSum = 0.0;
        $phaseWattsAny = false;
        $showVa = false;
        $showPf = false;
        $showPeak = false;
        $showState = false;
        $ratedAmps = isset($phaseSnap['device']['rated_amps']) ? (float)$phaseSnap['device']['rated_amps'] : null;
        foreach ($phaseRows as $pr) {
            if ($pr['watts'] !== null) {
                $phaseWattsSum += $pr['watts'];
                $phaseWattsAny = true;
            }
            if ($pr['va'] !== null) {
                $showVa = true;
            }
            if ($pr['pf'] !== null) {
                $showPf = true;
            }
            if ($pr['peak_amps'] !== null) {
                $showPeak = true;
            }
            if ($pr['load_state'] !== null) {
                $showState = true;
            }
        }
        $showUtil = $ratedAmps !== null && $ratedAmps > 0;
        ?>
    <div class="card mb-2" id="pdu-phase-status">
        <div class="card-header flex-between" style="flex-wrap:wrap;gap:.5rem">
            <strong>Phase status</strong>
            <span class="text-muted" style="font-size:.85rem">
                L1 / L2 / L3 from last SNMP poll
                <?php if ($phaseWattsAny): ?>
                    · sum <?= App::e(power_fmt_metric($phaseWattsSum / 1000, 3)) ?> kW
                <?php endif; ?>
                <?php if (!empty($phaseSnap['device']['va'])): ?>
                    · device <?= App::e(power_fmt_metric($phaseSnap['device']['va'] / 1000, 3)) ?> kVA
                <?php endif; ?>
                <?php if (isset($phaseSnap['device']['pf'])): ?>
                    · PF <?= App::e(power_fmt_metric($phaseSnap['device']['pf'], 3)) ?>
                <?php endif; ?>
            </span>
        </div>
        <?php if ($phaseRows): ?>
        <div class="card-body" style="padding:0; overflow-x:auto">
            <table class="data" style="margin:0">
                <thead>
                    <tr>
                        <th>Phase</th>
                        <th>Voltage</th>
                        <th>Power</th>
                        <?php if ($showVa): ?><th>Apparent</th><?php endif; ?>
                        <?php if ($showPf): ?><th>PF</th><?php endif; ?>
                        <th>Current</th>
                        <?php if ($showPeak): ?><th>Peak A</th><?php endif; ?>
                        <?php if ($showUtil): ?><th>Util</th><?php endif; ?>
                        <?php if ($showState): ?><th>State</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($phaseRows as $pr):
                    $utilPct = ($showUtil && $pr['amps'] !== null)
                        ? round(($pr['amps'] / $ratedAmps) * 100, 1)
                        : null;
                    $utilClass = $utilPct !== null ? power_util_class($utilPct) : '';
                    ?>
                    <tr>
                        <td><strong><?= App::e($pr['label']) ?></strong></td>
                        <td><?= $pr['volts'] !== null ? App::e(power_fmt_metric($pr['volts'], 1)) . ' V' : '—' ?></td>
                        <td>
                            <?php if ($pr['watts'] !== null): ?>
                                <?= App::e(power_fmt_metric($pr['watts'] / 1000, 3)) ?> kW
                                <span class="text-muted" style="font-size:.8rem">(<?= App::e(power_fmt_metric($pr['watts'], 0)) ?> W)</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <?php if ($showVa): ?>
                            <td><?= $pr['va'] !== null ? App::e(power_fmt_metric($pr['va'] / 1000, 3)) . ' kVA' : '—' ?></td>
                        <?php endif; ?>
                        <?php if ($showPf): ?>
                            <td><?= $pr['pf'] !== null ? App::e(power_fmt_metric($pr['pf'], 2)) : '—' ?></td>
                        <?php endif; ?>
                        <td><?= $pr['amps'] !== null ? App::e(power_fmt_metric($pr['amps'], 2)) . ' A' : '—' ?></td>
                        <?php if ($showPeak): ?>
                            <td><?= $pr['peak_amps'] !== null ? App::e(power_fmt_metric($pr['peak_amps'], 2)) . ' A' : '—' ?></td>
                        <?php endif; ?>
                        <?php if ($showUtil): ?>
                            <td>
                                <?php if ($utilPct !== null): ?>
                                    <span class="badge badge-<?= App::e($utilClass) ?>"><?= App::e(power_fmt_metric($utilPct, 1)) ?>%</span>
                                    <span class="text-muted" style="font-size:.75rem">/ <?= App::e(power_fmt_metric($ratedAmps, 0)) ?> A</span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($showState): ?>
                            <td><?= App::e(power_phase_load_state_label($pr['load_state'])) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if (!empty($phaseSnap['ll']) || !empty($phaseSnap['ps'])): ?>
        <div class="card-body" style="border-top:1px solid var(--border); font-size:.9rem">
            <?php if (!empty($phaseSnap['ll'])): ?>
                <div style="margin-bottom:.35rem">
                    <strong>Line–line</strong>
                    <?php
                    $llBits = [];
                    foreach (['L1-2', 'L2-3', 'L3-1'] as $lk) {
                        if (isset($phaseSnap['ll'][$lk])) {
                            $llBits[] = $lk . ' ' . power_fmt_metric($phaseSnap['ll'][$lk], 0) . ' V';
                        }
                    }
                    foreach ($phaseSnap['ll'] as $lk => $lv) {
                        if (!in_array($lk, ['L1-2', 'L2-3', 'L3-1'], true)) {
                            $llBits[] = $lk . ' ' . power_fmt_metric($lv, 0) . ' V';
                        }
                    }
                    echo App::e(implode(' · ', $llBits));
                    ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($phaseSnap['ps'])): ?>
                <div>
                    <strong>Power supplies</strong>
                    <?php if (isset($phaseSnap['ps']['ps1'])): ?>
                        · PS1 <?= App::e(power_ps_status_label($phaseSnap['ps']['ps1'])) ?>
                    <?php endif; ?>
                    <?php if (isset($phaseSnap['ps']['ps2'])): ?>
                        · PS2 <?= App::e(power_ps_status_label($phaseSnap['ps']['ps2'])) ?>
                    <?php endif; ?>
                    <?php if (isset($phaseSnap['ps']['alarm'])): ?>
                        · alarm <?= (int)$phaseSnap['ps']['alarm'] === 1 ? 'none' : (string)(int)$phaseSnap['ps']['alarm'] ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif ((int)($p['phases'] ?? 1) >= 2 && $canConfigSnmp): ?>
    <div class="card mb-2">
        <div class="card-body text-muted" style="font-size:.9rem">
            This PDU is configured as multi-phase, but no per-phase SNMP data has been polled yet.
            Use Discover or the <strong>APC rPDU2 3-phase</strong> OID pack
            (<code>phase1_watts_hundredths_kw</code>, <code>phase1_amps_x10</code>, …), then
            <strong>Poll now</strong>.
        </div>
    </div>
    <?php endif; ?>

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
                                <tr><th>Name</th><th>OID</th><th>Value</th><th>Hint</th><th>Score</th></tr>
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
            if (data.serial_applied && data.serial_no) {
                toast('Serial number saved on PDU: ' + data.serial_no, 'success');
            } else if (data.serial_no) {
                toast('Device serial: ' + data.serial_no, 'info');
            }

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
                var nm = c.name || '';
                tr.innerHTML =
                    '<td style="font-size:.78rem;max-width:14rem;word-break:break-all">' +
                        (nm ? '<code title="' + esc(nm) + '">' + esc(nm) + '</code>' : '<span class="text-muted">—</span>') +
                    '</td>' +
                    '<td><code style="font-size:.78rem">' + esc(c.oid) + '</code></td>' +
                    '<td>' + esc(c.value) + '</td>' +
                    '<td>' + esc(c.hint || '') + '</td>' +
                    '<td>' + esc(c.score) + '</td>';
                tr.style.cursor = 'pointer';
                tr.title = 'Click to copy OID' + (nm ? ' · ' + nm : '');
                tr.addEventListener('click', function () {
                    if (navigator.clipboard) navigator.clipboard.writeText(c.oid || '');
                    toast('Copied ' + c.oid, 'info');
                });
                tbody.appendChild(tr);
            });
            if (!(data.candidates || []).length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-muted">No scored candidates</td></tr>';
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
                overwrite: !!overwrite,
                serial_no: (lastDiscover && lastDiscover.serial_no) ? lastDiscover.serial_no : null
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

    <?php
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
    $pduTemplates = [];
    try {
        $pduTemplates = Database::fetchAll(
            'SELECT template_id, name, vendor, model, fields_json, outlets_json
             FROM pdu_templates WHERE is_active = 1 ORDER BY name'
        );
    } catch (Throwable $e) {
        $pduTemplates = [];
    }
    $siteOidTemplates = [];
    try {
        $siteOidTemplates = Database::fetchAll(
            'SELECT template_id, name, vendor, model, oid_map
             FROM snmp_site_oid_templates WHERE is_active = 1 ORDER BY name'
        );
    } catch (Throwable $e) {
        $siteOidTemplates = [];
    }
    $mountLabel = ($p['mount_style'] ?? '') === 'u_mounted'
        ? ('U-mounted' . (!empty($p['position_u']) ? ' @ U' . (int)$p['position_u'] : '')
            . (!empty($p['u_height']) ? ' · ' . (int)$p['u_height'] . 'U' : ''))
        : 'Vertical rear (0U)';
    $locBits = [];
    if (!empty($p['cabinet_name'])) {
        $locBits[] = (string)$p['cabinet_name'];
    }
    if (!empty($p['row_name'])) {
        $locBits[] = 'Row ' . $p['row_name'];
    }
    $locSummary = $locBits ? implode(' · ', $locBits) : '—';
    $inVSum = $p['input_voltage'] ?? $p['rated_volts'] ?? null;
    $voltSummary = '—';
    if ($inVSum !== null || !empty($p['output_voltage'])) {
        if (!empty($p['input_voltage_ln'])) {
            $voltSummary = (int)$inVSum . '/' . (int)$p['input_voltage_ln'] . ' V in';
        } else {
            $voltSummary = $inVSum !== null ? ((int)$inVSum . ' V in') : '—';
        }
        if (!empty($p['output_voltage'])) {
            $voltSummary .= ' → ' . (int)$p['output_voltage'] . ' V out';
        }
    }
    ?>
    <div class="card mb-2">
        <div class="card-header flex-between">
            <h2>Overview</h2>
            <?php if (AuthManager::canEditPower($user)): ?>
                <button type="button" class="btn btn-sm btn-secondary" data-open-modal="modal-edit-pdu">Edit properties</button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <dl class="pdu-summary-grid">
                <div>
                    <dt>Vendor / model</dt>
                    <dd><?php
                        $vm = trim(($p['manufacturer'] ?? '') . ' ' . ($p['model'] ?? ''));
                        echo $vm !== '' ? App::e($vm) : '<span class="text-muted">—</span>';
                    ?></dd>
                </div>
                <div>
                    <dt>Serial number</dt>
                    <dd><?= !empty($p['serial_no'])
                        ? App::e((string)$p['serial_no'])
                        : '<span class="text-muted">—</span>' ?></dd>
                </div>
                <div>
                    <dt>Location</dt>
                    <dd>
                        <?php if (!empty($p['cabinet_id'])): ?>
                            <a href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$p['cabinet_id'])) ?>">
                                <?= App::e($locSummary) ?>
                            </a>
                        <?php else: ?>
                            <?= App::e($locSummary) ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Zone</dt>
                    <dd>
                        <?php if (!empty($p['zone_id'])): ?>
                            <a href="<?= App::e(App::url('pages/power_zones.php?id=' . (int)$p['zone_id'])) ?>">
                                <?= App::e($p['zone_name'] ?? '—') ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Mount</dt>
                    <dd><?= App::e($mountLabel) ?></dd>
                </div>
                <div>
                    <dt>Electrical</dt>
                    <dd><?= App::e(power_wiring_label($p['phase_wiring'] ?? null, (int)($p['phases'] ?? 1))) ?></dd>
                </div>
                <div>
                    <dt>Voltage</dt>
                    <dd><?= App::e($voltSummary) ?></dd>
                </div>
                <div>
                    <dt>Input / rating</dt>
                    <dd>
                        <?= App::e($p['input_type'] ?? '—') ?>
                        <?= $p['rated_amps'] !== null ? ' · ' . App::e((string)$p['rated_amps']) . ' A' : '' ?>
                    </dd>
                </div>
                <div>
                    <dt>IP address</dt>
                    <dd><?= !empty($p['ip_address']) ? App::e((string)$p['ip_address']) : '<span class="text-muted">—</span>' ?></dd>
                </div>
                <?php if (!empty($p['notes'])): ?>
                <div style="grid-column:1 / -1">
                    <dt>Notes</dt>
                    <dd><?= App::e((string)$p['notes']) ?></dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <?php if (AuthManager::canEditPower($user)): ?>
    <div class="app-modal" id="modal-edit-pdu" hidden aria-hidden="true">
        <div class="app-modal-backdrop" data-modal-close></div>
        <div class="app-modal-panel app-modal-panel-xl" role="dialog" aria-modal="true" aria-labelledby="modal-edit-pdu-title">
            <div class="app-modal-head">
                <h3 id="modal-edit-pdu-title">Edit PDU — <?= App::e($p['name']) ?></h3>
                <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
            </div>
            <div class="app-modal-body">
                <?php
                $edit = $p;
                $formAction = 'update_pdu';
                $formId = 'editPduForm';
                $formModal = true;
                require __DIR__ . '/_power_pdu_form.php';
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
            <?php if ($outputMode === 'breakers'):
                $layout = power_normalize_breaker_layout($p['breaker_layout'] ?? 'odd_right_even_left');
                $cols = max(1, min(3, (int)($p['breaker_columns'] ?? 2)));
                $panelGrid = power_breaker_panel_grid($numSlots, $layout, $cols);
                $layoutLabel = power_breaker_layout_options()[$layout] ?? $layout;
                ?>
                <div class="card-header flex-between">
                    <h2>Breaker panel</h2>
                    <span class="text-muted" style="font-size:.85rem"><?= count($breakers) ?> breakers · <?= max(0, $numSlots) ?> slots</span>
                </div>
                <div class="card-body">
                    <?php if ($numSlots < 1): ?>
                        <p class="text-muted">Set <strong>Breaker positions</strong> and layout under <strong>Edit PDU</strong> and save first.</p>
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
            <?php else:
                $outletLive = power_outlet_poll_decode($p['last_poll_outlets'] ?? null);
                $outletLiveBy = $outletLive['by_num'] ?? [];
                $hasOutletLive = !empty($outletLiveBy);
                $showOutletName = false;
                $showOutletState = false;
                $showOutletWatts = false;
                $showOutletAmps = false;
                foreach ($outletLiveBy as $lv) {
                    if (!empty($lv['name'])) {
                        $showOutletName = true;
                    }
                    if ($lv['state'] !== null) {
                        $showOutletState = true;
                    }
                    if ($lv['watts'] !== null) {
                        $showOutletWatts = true;
                    }
                    if ($lv['amps'] !== null) {
                        $showOutletAmps = true;
                    }
                }
                // Live-only rows when inventory is empty but SNMP returned outlets
                $outletRows = $outlets;
                if (!$outletRows && $hasOutletLive) {
                    foreach ($outletLiveBy as $num => $lv) {
                        $outletRows[] = [
                            'outlet_id' => null,
                            'outlet_number' => $num,
                            'outlet_type' => null,
                            'rated_amps' => null,
                            'connected_device_id' => null,
                            'device_label' => null,
                            'label' => null,
                        ];
                    }
                }
                $canEditOutlets = AuthManager::canEditPower($user);
                $connectorTypes = power_outlet_connector_types();
                $invCount = max(count($outlets), (int)($p['num_outlets'] ?? 0), $hasOutletLive ? (int)$outletLive['count'] : 0);
                if ($invCount < 1) {
                    $invCount = 24;
                }
                ?>
                <div class="card-header flex-between" style="flex-wrap:wrap;gap:.5rem">
                    <h2>Outlets</h2>
                    <span class="text-muted" style="font-size:.85rem">
                        <?= $usedOutlets ?> mapped · <?= count($outlets) ?> inventory
                        <?php if ($hasOutletLive): ?>
                            · <?= (int)$outletLive['count'] ?> polled
                            <?php if ($outletLive['sum_watts'] !== null): ?>
                                · Σ <?= App::e(power_fmt_metric($outletLive['sum_watts'] / 1000, 3)) ?> kW
                            <?php endif; ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="card-body flush">
                    <?php if ($canEditOutlets): ?>
                    <form method="post" id="pduOutletsForm">
                        <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                        <input type="hidden" name="action" value="save_outlets">
                        <input type="hidden" name="pdu_id" value="<?= $pduId ?>">
                        <div style="display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;padding:.75rem 1rem;border-bottom:1px solid var(--border, rgba(148,163,184,.15));align-items:flex-end">
                            <div>
                                <label class="text-muted" style="font-size:.75rem;display:block">Inventory count</label>
                                <input class="form-control" type="number" min="1" max="128" name="num_outlets"
                                       value="<?= (int)$invCount ?>" style="width:6rem">
                            </div>
                            <div>
                                <label class="text-muted" style="font-size:.75rem;display:block">Type for new rows</label>
                                <select class="form-control" name="default_outlet_type" style="min-width:7rem">
                                    <?php foreach ($connectorTypes as $ct): ?>
                                        <option value="<?= App::e($ct) ?>"<?= $ct === 'C13' ? ' selected' : '' ?>><?= App::e($ct) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="flex:1;min-width:14rem">
                                <label class="text-muted" style="font-size:.75rem;display:block">Bulk set type (range)</label>
                                <div style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center">
                                    <input class="form-control" type="number" min="1" max="128" name="bulk_from" placeholder="from" style="width:4.5rem" title="Outlet # from">
                                    <span class="text-muted">–</span>
                                    <input class="form-control" type="number" min="1" max="128" name="bulk_to" placeholder="to" style="width:4.5rem" title="Outlet # to">
                                    <select class="form-control" name="bulk_type" style="min-width:7rem">
                                        <option value="">— type —</option>
                                        <?php foreach ($connectorTypes as $ct): ?>
                                            <option value="<?= App::e($ct) ?>"><?= App::e($ct) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <button class="btn btn-primary btn-sm" type="submit">Save outlets</button>
                        </div>
                    <?php endif; ?>
                    <div class="table-wrap" style="max-height:480px;overflow:auto">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Label</th>
                                    <th>Type</th>
                                    <th>Rated</th>
                                    <?php if ($showOutletAmps): ?><th>Current</th><?php endif; ?>
                                    <?php if ($showOutletWatts): ?><th>Power</th><?php endif; ?>
                                    <?php if ($showOutletState): ?><th>Load</th><?php endif; ?>
                                    <th>Device</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($outletRows as $o):
                                $onum = (int)$o['outlet_number'];
                                $live = $outletLiveBy[$onum] ?? null;
                                $oid = (int)($o['outlet_id'] ?? 0);
                                $curType = (string)($o['outlet_type'] ?? 'C13');
                                ?>
                                <tr>
                                    <td><strong><?= $onum ?></strong>
                                        <?php if ($canEditOutlets && $oid > 0): ?>
                                            <input type="hidden" name="outlet_id[]" value="<?= $oid ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($canEditOutlets && $oid > 0): ?>
                                            <input class="form-control form-control-sm" name="outlet_label[]"
                                                   value="<?= App::e((string)($o['label'] ?? '')) ?>"
                                                   placeholder="<?= App::e($live['name'] ?? ('Outlet ' . $onum)) ?>"
                                                   style="min-width:7rem">
                                        <?php else:
                                            $nm = $live['name'] ?? null;
                                            if ($nm === null && !empty($o['label'])) {
                                                $nm = (string)$o['label'];
                                            }
                                            echo $nm !== null && $nm !== '' ? App::e($nm) : '<span class="text-muted">—</span>';
                                        endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($canEditOutlets && $oid > 0): ?>
                                            <select class="form-control form-control-sm" name="outlet_type[]" style="min-width:6.5rem">
                                                <?php
                                                $typeOpts = $connectorTypes;
                                                if ($curType !== '' && !in_array($curType, $typeOpts, true)) {
                                                    $typeOpts[] = $curType;
                                                }
                                                foreach ($typeOpts as $ct): ?>
                                                    <option value="<?= App::e($ct) ?>"<?= $ct === $curType ? ' selected' : '' ?>><?= App::e($ct) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <?= App::e($o['outlet_type'] ?? '—') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($canEditOutlets && $oid > 0): ?>
                                            <input class="form-control form-control-sm" type="number" step="0.1" name="outlet_rated_amps[]"
                                                   value="<?= $o['rated_amps'] !== null ? App::e((string)$o['rated_amps']) : '' ?>"
                                                   style="width:4.5rem" placeholder="A">
                                        <?php else: ?>
                                            <?= $o['rated_amps'] !== null ? App::e((string)$o['rated_amps']) . ' A' : '—' ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($showOutletAmps): ?>
                                        <td><?= ($live && $live['amps'] !== null)
                                            ? App::e(power_fmt_metric($live['amps'], 2)) . ' A'
                                            : '—' ?></td>
                                    <?php endif; ?>
                                    <?php if ($showOutletWatts): ?>
                                        <td>
                                            <?php if ($live && $live['watts'] !== null): ?>
                                                <?= App::e(power_fmt_metric($live['watts'] / 1000, 3)) ?> kW
                                                <span class="text-muted" style="font-size:.78rem">(<?= App::e(power_fmt_metric($live['watts'], 0)) ?> W)</span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <?php if ($showOutletState): ?>
                                        <td><?= ($live && $live['state'] !== null)
                                            ? App::e(power_outlet_state_label($live['state']))
                                            : '—' ?></td>
                                    <?php endif; ?>
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
                            <?php if (!$outletRows): ?>
                                <tr><td colspan="8" class="text-muted">No outlets yet — Poll now (uses SNMP NumOutlets), apply a PDU template, or set inventory count and save.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($canEditOutlets): ?>
                    </form>
                    <?php endif; ?>
                    <p class="text-muted" style="font-size:.78rem;padding:.65rem 1rem;margin:0">
                        Outlet <strong>count</strong> comes from SNMP device properties on poll (e.g. APC NumOutlets)
                        or a PDU template. Set mixed types/labels here (bulk range OK). Device mapping is from the
                        cabinet rack overlay or device Power Supply section.
                        <?php if ($hasOutletLive): ?>
                            Live A/W from last SNMP poll<?= !empty($p['last_poll_at'])
                                ? ' · ' . App::e((string)$p['last_poll_at'])
                                : '' ?>.
                        <?php elseif ($canConfigSnmp && !empty($p['last_poll_at'])): ?>
                            <strong>No live per-outlet power</strong> (typical for phase-metered AP8861:
                            inventory only). After labeling types, use <em>Create PDU template</em> to clone layout.
                        <?php elseif ($canConfigSnmp): ?>
                            Poll to pull outlet count from the device; label types afterward.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
            <?php if (AuthManager::canEditPower($user)): ?>
            <div class="card-body" style="border-top:1px solid var(--border, rgba(148,163,184,.15))">
                <form method="post" onsubmit="return confirm('Deactivate this PDU?');" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
                    <input type="hidden" name="action" value="deactivate_pdu">
                    <input type="hidden" name="pdu_id" value="<?= $pduId ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Deactivate PDU</button>
                </form>
            </div>
            <?php endif; ?>
    </div>
    <script>
    (function () {
        function openModal(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.hidden = false;
            el.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            var focus = el.querySelector('input:not([type=hidden]), select, textarea, button');
            if (focus) setTimeout(function () { focus.focus(); }, 50);
        }
        function closeModal(el) {
            if (!el) return;
            el.hidden = true;
            el.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.app-modal:not([hidden])')) {
                document.body.style.overflow = '';
            }
        }
        document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(btn.getAttribute('data-open-modal'));
            });
        });
        document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeModal(btn.closest('.app-modal'));
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var open = document.querySelector('.app-modal:not([hidden])');
            // SNMP discover uses a separate overlay; leave that alone if it is open
            if (!open) return;
            closeModal(open);
        });
        if (location.hash === '#edit') {
            openModal('modal-edit-pdu');
        }
    })();
    </script>
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
    <script src="<?= App::e(App::url('assets/js/power-charts.js')) ?>?v=4"></script>
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

$canEditPdu = AuthManager::canEditPower($user);
$countRack = count(array_filter($pdus, static fn($x) => ($x['pdu_scope'] ?? 'rack') === 'rack'));
$countRow = count(array_filter($pdus, static fn($x) => ($x['pdu_scope'] ?? '') === 'row'));
$countRoom = count(array_filter($pdus, static fn($x) => ($x['pdu_scope'] ?? '') === 'room'));
$totalKw = 0.0;
foreach ($pdus as $pp) {
    if ($pp['last_poll_watts'] !== null) {
        $totalKw += (float)$pp['last_poll_watts'] / 1000.0;
    }
}
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
$pduTemplates = [];
try {
    $pduTemplates = Database::fetchAll(
        'SELECT template_id, name, vendor, model, fields_json, outlets_json
         FROM pdu_templates WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $pduTemplates = [];
}
$siteOidTemplates = [];
try {
    $siteOidTemplates = Database::fetchAll(
        'SELECT template_id, name, vendor, model, oid_map
         FROM snmp_site_oid_templates WHERE is_active = 1 ORDER BY name'
    );
} catch (Throwable $e) {
    $siteOidTemplates = [];
}

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
        <?php if ($canEditPdu): ?>
            <button type="button" class="btn btn-primary" data-open-modal="modal-add-pdu" id="add-pdu">+ PDU</button>
        <?php endif; ?>
    </div>
</div>

<div class="metrics">
    <div class="metric-card">
        <div class="label">PDUs</div>
        <div class="value"><?= count($pdus) ?></div>
        <div class="sub"><?= $countRack ?> rack · <?= $countRow ?> row · <?= $countRoom ?> room</div>
    </div>
    <div class="metric-card warning">
        <div class="label">Polled load</div>
        <div class="value"><?= number_format($totalKw, 2) ?> <span class="metric-unit">kW</span></div>
        <div class="sub">sum of last SNMP polls</div>
    </div>
    <div class="metric-card accent">
        <div class="label">SNMP on</div>
        <div class="value"><?= count(array_filter($pdus, static fn($x) => !empty($x['snmp_enabled']))) ?></div>
        <div class="sub">of <?= count($pdus) ?> listed</div>
    </div>
</div>

<div class="card">
    <div class="card-header flex-between">
        <h2>All PDUs</h2>
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
            <span class="text-muted" style="font-size:.85rem"><?= count($pdus) ?> active</span>
            <?php if ($canEditPdu): ?>
                <button type="button" class="btn btn-sm btn-primary" data-open-modal="modal-add-pdu">Add PDU</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body flush">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th><th>Scope</th><th>Output</th><th>Phases</th><th>In → Out</th>
                <th>Location</th><th>Zone</th><th>Amps</th><th>Load</th><th>SNMP</th><th class="col-actions"></th>
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
                    <td class="actions col-actions">
                        <a class="btn btn-sm btn-secondary" href="?id=<?= (int)$p['pdu_id'] ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pdus): ?>
                <tr>
                    <td colspan="11" class="text-muted">
                        No PDUs yet.
                        <?php if ($canEditPdu): ?>
                            Use <strong>Add PDU</strong> to create one.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canEditPdu): ?>
<div class="app-modal" id="modal-add-pdu" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-modal-close></div>
    <div class="app-modal-panel app-modal-panel-xl" role="dialog" aria-modal="true" aria-labelledby="modal-add-pdu-title">
        <div class="app-modal-head">
            <h3 id="modal-add-pdu-title">Add PDU</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close aria-label="Close">✕</button>
        </div>
        <div class="app-modal-body">
            <?php
            $edit = [
                'zone_id' => $filterZone ?: null,
                'pdu_scope' => 'rack',
                'phases' => 1,
                'phase_wiring' => 'single',
                'input_voltage' => 208,
                'output_voltage' => 208,
                'num_outlets' => 0,
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
            $formId = 'addPduForm';
            $formModal = true;
            require __DIR__ . '/_power_pdu_form.php';
            ?>
        </div>
    </div>
</div>
<script>
(function () {
    function openModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.hidden = false;
        el.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        var focus = el.querySelector('input:not([type=hidden]), select, textarea, button');
        if (focus) setTimeout(function () { focus.focus(); }, 50);
    }
    function closeModal(el) {
        if (!el) return;
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.app-modal:not([hidden])')) {
            document.body.style.overflow = '';
        }
    }
    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn.getAttribute('data-open-modal'));
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.closest('.app-modal'));
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('.app-modal:not([hidden])');
        if (open) closeModal(open);
    });
    // Deep links from Power dashboard / Zones: #add-pdu
    if (location.hash === '#add-pdu') {
        openModal('modal-add-pdu');
    }
})();
</script>
<?php endif; ?>
<?php layout_footer(); ?>
