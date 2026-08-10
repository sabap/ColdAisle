<?php
/**
 * UPS inventory helpers (Schneider / APC Symmetra and generic UPS).
 */
declare(strict_types=1);

/** @return array<string,string> */
function ups_scopes(): array
{
    return [
        'in_row' => 'In-row',
        'in_rack' => 'In-rack',
    ];
}

/** @return array<string,string> */
function ups_statuses(): array
{
    return [
        'production' => 'Production',
        'standby' => 'Standby',
        'maintenance' => 'Maintenance',
        'decommissioned' => 'Decommissioned',
    ];
}

/**
 * Map APC upsBasicOutputStatus integer → label.
 * @see PowerNet-MIB upsBasicOutputStatus
 */
function ups_output_status_label(mixed $code): string
{
    $n = is_numeric($code) ? (int)$code : -1;
    return match ($n) {
        1 => 'Unknown',
        2 => 'On line',
        3 => 'On battery',
        4 => 'On smart boost',
        5 => 'Timed sleeping',
        6 => 'Software bypass',
        7 => 'Off',
        8 => 'Rebooting',
        9 => 'Switched bypass',
        10 => 'Hardware bypass',
        11 => 'Waiting',
        12 => 'On smart trim',
        13 => 'Eco mode',
        14 => 'Hot standby',
        15 => 'On battery test',
        16 => 'Emergency static bypass',
        17 => 'Static bypass standby',
        18 => 'Power saving',
        19 => 'Spot mode',
        20 => 'eConversion',
        default => is_scalar($code) ? (string)$code : '—',
    };
}

/** Health for floor/3D from last poll fields. */
function ups_health_status(array $row): string
{
    $st = strtolower(trim((string)($row['last_output_status'] ?? '')));
    if ($st === '' && isset($row['last_poll_json'])) {
        $j = json_decode((string)$row['last_poll_json'], true);
        if (is_array($j)) {
            $code = $j['metrics']['output_status']['numeric']
                ?? $j['metrics']['output_status']
                ?? null;
            if ($code !== null) {
                $st = strtolower(ups_output_status_label($code));
            }
        }
    }
    // Battery / bypass / offline → crit
    if (preg_match('/battery|bypass|off|emergency|unknown/i', $st)) {
        if (preg_match('/on line|online|eco|econversion/i', $st)) {
            // "on line" wins
        } else {
            return 'crit';
        }
    }
    $load = isset($row['last_load_pct']) && $row['last_load_pct'] !== null && $row['last_load_pct'] !== ''
        ? (float)$row['last_load_pct'] : null;
    $batt = isset($row['last_battery_pct']) && $row['last_battery_pct'] !== null && $row['last_battery_pct'] !== ''
        ? (float)$row['last_battery_pct'] : null;
    if ($load !== null && $load >= 95) {
        return 'crit';
    }
    if ($load !== null && $load >= 80) {
        return 'warn';
    }
    if ($batt !== null && $batt < 20) {
        return 'crit';
    }
    if ($batt !== null && $batt < 50) {
        return 'warn';
    }
    if ($st !== '' || $load !== null || $batt !== null) {
        return 'ok';
    }
    return 'unknown';
}

/**
 * Default APC PowerNet UPS OID map (Symmetra / Smart-UPS family).
 * Keys used by poll + threshold rules.
 *
 * @return array<string,string>
 */
function ups_default_apc_oid_map(): array
{
    return [
        'model' => '1.3.6.1.4.1.318.1.1.1.1.1.1.0',
        'name' => '1.3.6.1.4.1.318.1.1.1.1.1.2.0',
        // upsAdvIdentSerialNumber
        'serial_no' => '1.3.6.1.4.1.318.1.1.1.1.2.3.0',
        // MIB-II sysUpTime (TimeTicks, hundredths of a second)
        'sysuptime' => '1.3.6.1.2.1.1.3.0',
        'battery_status' => '1.3.6.1.4.1.318.1.1.1.2.1.1.0',
        'battery_capacity' => '1.3.6.1.4.1.318.1.1.1.2.2.1.0',
        'battery_temp_c' => '1.3.6.1.4.1.318.1.1.1.2.2.2.0',
        'runtime_ticks' => '1.3.6.1.4.1.318.1.1.1.2.2.3.0',
        'battery_replace' => '1.3.6.1.4.1.318.1.1.1.2.2.4.0',
        'input_voltage' => '1.3.6.1.4.1.318.1.1.1.3.2.1.0',
        'input_freq' => '1.3.6.1.4.1.318.1.1.1.3.2.4.0',
        'output_status' => '1.3.6.1.4.1.318.1.1.1.4.1.1.0',
        'output_voltage' => '1.3.6.1.4.1.318.1.1.1.4.2.1.0',
        'output_freq' => '1.3.6.1.4.1.318.1.1.1.4.2.2.0',
        'output_load' => '1.3.6.1.4.1.318.1.1.1.4.2.3.0',
        'output_current' => '1.3.6.1.4.1.318.1.1.1.4.2.4.0',
    ];
}

/**
 * Format SNMP TimeTicks (hundredths of a second) as "Nd Nh Nm" (or seconds if tiny).
 */
function ups_format_timeticks(mixed $ticks): string
{
    if ($ticks === null || $ticks === '' || !is_numeric($ticks)) {
        return '—';
    }
    $t = (float)$ticks;
    // Guard: some agents return already-seconds; treat huge values as TimeTicks
    $seconds = $t >= 1000 ? ($t / 100.0) : $t;
    if ($seconds < 0) {
        $seconds = 0;
    }
    $days = (int)floor($seconds / 86400);
    $seconds -= $days * 86400;
    $hours = (int)floor($seconds / 3600);
    $seconds -= $hours * 3600;
    $mins = (int)floor($seconds / 60);
    $secs = (int)round($seconds - $mins * 60);
    if ($days > 0) {
        return sprintf('%dd %dh %dm', $days, $hours, $mins);
    }
    if ($hours > 0) {
        return sprintf('%dh %dm', $hours, $mins);
    }
    if ($mins > 0) {
        return sprintf('%dm %ds', $mins, $secs);
    }
    return $secs . 's';
}

/**
 * Display helper for last-poll metric rows (formats uptime / ticks / status enums).
 * @param mixed $value metric entry (scalar or {numeric,raw})
 */
function ups_format_metric_display(string $key, mixed $value): string
{
    $k = strtolower($key);
    $raw = null;
    $num = null;
    if (is_array($value)) {
        $num = isset($value['numeric']) && is_numeric($value['numeric']) ? (float)$value['numeric'] : null;
        $raw = $value['raw'] ?? $value['numeric'] ?? null;
    } else {
        $raw = $value;
        $num = is_numeric($value) ? (float)$value : null;
    }
    if (preg_match('/uptime|sysuptime|timeticks|runtime_ticks/i', $k) && $num !== null) {
        return ups_format_timeticks($num);
    }
    if (preg_match('/output_status|battery_status/i', $k) && $num !== null && function_exists('ups_output_status_label')) {
        if (preg_match('/output_status/i', $k)) {
            return ups_output_status_label($num) . ' (' . (int)$num . ')';
        }
    }
    if ($num !== null && is_float($num) && abs($num - round($num)) > 0.0001) {
        return rtrim(rtrim(sprintf('%.4F', $num), '0'), '.');
    }
    if ($raw === null) {
        return '—';
    }
    if (is_scalar($raw)) {
        $s = trim((string)$raw);
        $s = preg_replace('/^(STRING|OCTET STRING|Timeticks|Gauge32|INTEGER|Counter32)\s*:\s*/i', '', $s) ?? $s;
        return trim($s, " \t\"'");
    }
    return json_encode($raw) ?: '—';
}

/**
 * Pull a cleaned serial string from a poll metrics map.
 * @param array<string,mixed> $metrics
 */
function ups_serial_from_metrics(array $metrics): ?string
{
    $keys = [
        'serial_no', 'serial', 'serialnumber', 'serial_number',
        'upsadvidentserialnumber', 'upsbasicidentserialnumber', 'service_tag',
    ];
    foreach ($metrics as $k => $v) {
        $lk = strtolower((string)$k);
        $match = false;
        foreach ($keys as $want) {
            if ($lk === $want || str_contains($lk, 'serial')) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            continue;
        }
        $raw = is_array($v) ? ($v['raw'] ?? $v['numeric'] ?? null) : $v;
        if ($raw === null || $raw === '') {
            continue;
        }
        if (class_exists('SnmpDiscover')) {
            $clean = SnmpDiscover::cleanSerialValue($raw);
            if ($clean !== null && $clean !== '') {
                return mb_substr($clean, 0, 100);
            }
        }
        $s = is_scalar($raw) ? trim((string)$raw) : '';
        $s = preg_replace('/^(STRING|OCTET STRING)\s*:\s*/i', '', $s) ?? $s;
        $s = trim($s, " \t\"'");
        if ($s !== '' && !preg_match('/^\d+\.?\d*$/', $s)) {
            // Prefer non-numeric pure values; still allow alphanumeric serials
            return mb_substr($s, 0, 100);
        }
        if ($s !== '' && preg_match('/[A-Za-z]/', $s)) {
            return mb_substr($s, 0, 100);
        }
        if ($s !== '' && strlen($s) >= 6) {
            return mb_substr($s, 0, 100);
        }
    }
    return null;
}

/**
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function ups_fields_from_post(array $post): array
{
    $scope = strtolower(trim((string)($post['ups_scope'] ?? 'in_row')));
    if (!isset(ups_scopes()[$scope])) {
        $scope = 'in_row';
    }
    $status = strtolower(trim((string)($post['status'] ?? 'production')));
    if (!isset(ups_statuses()[$status])) {
        $status = 'production';
    }
    $w = (int)($post['width_mm'] ?? ($scope === 'in_rack' ? 600 : 600));
    $d = (int)($post['depth_mm'] ?? ($scope === 'in_rack' ? 1000 : 1100));
    $h = (int)($post['height_mm'] ?? ($scope === 'in_rack' ? 1800 : 2000));
    if ($w < 100) {
        $w = 600;
    }
    if ($d < 100) {
        $d = 1000;
    }
    if ($h < 100) {
        $h = 2000;
    }
    $color = trim((string)($post['color_hex'] ?? '#7c3aed'));
    if ($color !== '' && $color[0] !== '#') {
        $color = '#' . $color;
    }
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $color = '#7c3aed';
    }

    $row = [
        'name' => trim((string)($post['name'] ?? '')),
        'ups_scope' => $scope,
        'room_id' => !empty($post['room_id']) ? (int)$post['room_id'] : null,
        'row_id' => !empty($post['row_id']) ? (int)$post['row_id'] : null,
        'cabinet_id' => !empty($post['cabinet_id']) ? (int)$post['cabinet_id'] : null,
        'zone_id' => !empty($post['zone_id']) ? (int)$post['zone_id'] : null,
        'manufacturer' => (($m = trim((string)($post['manufacturer'] ?? ''))) !== '') ? $m : 'Schneider Electric',
        'model' => (($m = trim((string)($post['model'] ?? ''))) !== '') ? $m : null,
        'serial_no' => (($m = trim((string)($post['serial_no'] ?? ''))) !== '') ? $m : null,
        'asset_tag' => (($m = trim((string)($post['asset_tag'] ?? ''))) !== '') ? $m : null,
        'primary_ip' => (($m = trim((string)($post['primary_ip'] ?? ''))) !== '') ? $m : null,
        'hostname' => (($m = trim((string)($post['hostname'] ?? ''))) !== '') ? $m : null,
        'rated_kva' => isset($post['rated_kva']) && $post['rated_kva'] !== '' ? (float)$post['rated_kva'] : null,
        'rated_kw' => isset($post['rated_kw']) && $post['rated_kw'] !== '' ? (float)$post['rated_kw'] : null,
        'phases' => !empty($post['phases']) ? max(1, min(3, (int)$post['phases'])) : 3,
        'warranty_provider' => (($m = trim((string)($post['warranty_provider'] ?? ''))) !== '') ? $m : null,
        'warranty_end' => (($m = trim((string)($post['warranty_end'] ?? ''))) !== '') ? $m : null,
        'install_date' => (($m = trim((string)($post['install_date'] ?? ''))) !== '') ? $m : null,
        'manufacture_date' => (($m = trim((string)($post['manufacture_date'] ?? ''))) !== '') ? $m : null,
        'status' => $status,
        'width_mm' => $w,
        'depth_mm' => $d,
        'height_mm' => $h,
        'color_hex' => $color,
        'notes' => (($n = trim((string)($post['notes'] ?? ''))) !== '') ? $n : null,
        'snmp_enabled' => !empty($post['snmp_enabled']) ? 1 : 0,
        'snmp_version' => (($v = trim((string)($post['snmp_version'] ?? ''))) !== '') ? $v : null,
        'snmp_port' => !empty($post['snmp_port']) ? (int)$post['snmp_port'] : 161,
        'snmp_v3_profile_id' => !empty($post['snmp_v3_profile_id']) ? (int)$post['snmp_v3_profile_id'] : null,
        'snmp_site_template_id' => !empty($post['snmp_site_template_id']) ? (int)$post['snmp_site_template_id'] : null,
        'snmp_auto_poll' => !empty($post['snmp_auto_poll']) ? 1 : 0,
        'snmp_v3_sec_level' => (($v = trim((string)($post['snmp_v3_sec_level'] ?? ''))) !== '') ? $v : null,
        'snmp_security_name' => (($v = trim((string)($post['snmp_security_name'] ?? ''))) !== '') ? $v : null,
        'snmp_auth_protocol' => (($v = trim((string)($post['snmp_auth_protocol'] ?? ''))) !== '') ? $v : null,
        'snmp_priv_protocol' => (($v = trim((string)($post['snmp_priv_protocol'] ?? ''))) !== '') ? $v : null,
        'snmp_context' => (($v = trim((string)($post['snmp_context'] ?? ''))) !== '') ? $v : null,
    ];
    // Passphrases / community only when provided (keep on update)
    if (array_key_exists('snmp_community', $post) && trim((string)$post['snmp_community']) !== '') {
        $row['snmp_community'] = trim((string)$post['snmp_community']);
    }
    if (array_key_exists('snmp_auth_passphrase', $post) && (string)$post['snmp_auth_passphrase'] !== '') {
        $row['snmp_auth_passphrase'] = (string)$post['snmp_auth_passphrase'];
    }
    if (array_key_exists('snmp_priv_passphrase', $post) && (string)$post['snmp_priv_passphrase'] !== '') {
        $row['snmp_priv_passphrase'] = (string)$post['snmp_priv_passphrase'];
    }
    return $row;
}

/**
 * Apply SNMPv3 profile credentials when selected (same pattern as cooling).
 * @param array<string,mixed> $row
 * @param array<string,mixed>|null $prev
 * @return array<string,mixed>
 */
function ups_finalize_snmp(array $row, ?array $prev): array
{
    $ver = strtolower(trim((string)($row['snmp_version'] ?? '')));
    if ($ver === 'v3') {
        $ver = '3';
    }
    if ($ver === '2' || $ver === 'v2c') {
        $ver = '2c';
    }
    if ($ver !== '') {
        $row['snmp_version'] = $ver;
    }
    $profileId = (int)($row['snmp_v3_profile_id'] ?? 0);
    if ($profileId > 0 && ($ver === '3' || $ver === '')) {
        try {
            $p = Database::fetchOne(
                'SELECT * FROM snmp_v3_profiles WHERE profile_id = ? AND is_active = 1',
                [$profileId]
            );
            if ($p) {
                $row['snmp_version'] = '3';
                $row['snmp_security_name'] = (string)($p['security_name'] ?? $row['snmp_security_name'] ?? '');
                $row['snmp_v3_sec_level'] = (string)($p['security_level'] ?? $row['snmp_v3_sec_level'] ?? 'authPriv');
                $row['snmp_auth_protocol'] = (string)($p['auth_protocol'] ?? $row['snmp_auth_protocol'] ?? 'SHA');
                $row['snmp_priv_protocol'] = (string)($p['priv_protocol'] ?? $row['snmp_priv_protocol'] ?? 'AES');
                $row['snmp_context'] = (string)($p['context_name'] ?? $row['snmp_context'] ?? '');
                if (class_exists('Crypto')) {
                    $auth = Crypto::decryptQuiet($p['auth_passphrase'] ?? null);
                    $priv = Crypto::decryptQuiet($p['priv_passphrase'] ?? null);
                    if ($auth !== null && $auth !== '') {
                        $row['snmp_auth_passphrase'] = Crypto::encrypt((string)$auth);
                    }
                    if ($priv !== null && $priv !== '') {
                        $row['snmp_priv_passphrase'] = Crypto::encrypt((string)$priv);
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    // Encrypt free-typed secrets
    if (class_exists('Crypto')) {
        foreach (['snmp_community', 'snmp_auth_passphrase', 'snmp_priv_passphrase'] as $k) {
            if (!empty($row[$k]) && is_string($row[$k]) && !str_starts_with($row[$k], 'enc:')) {
                // only encrypt if looks plaintext (Crypto may use different prefix — encryptQuiet path)
                try {
                    $row[$k] = Crypto::encrypt($row[$k]);
                } catch (Throwable $e) {
                }
            }
        }
        // Keep previous secrets when blank on update
        if ($prev) {
            foreach (['snmp_community', 'snmp_auth_passphrase', 'snmp_priv_passphrase'] as $k) {
                if (empty($row[$k]) && !empty($prev[$k])) {
                    $row[$k] = $prev[$k];
                }
            }
        }
    }
    return $row;
}
