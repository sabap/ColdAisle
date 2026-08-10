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
    // Connectivity / unreachable (testing mode or real) → crit
    if (preg_match('/unreachable|no response|timeout|connectivity|offline|comm(unication)?\s*fail/i', $st)) {
        return 'crit';
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
 * Aggregate UPS inventory for Power / NOC / main dashboard.
 *
 * @return array{
 *   units:int, snmp_on:int, polled:int, online:int, on_battery:int, bypass:int,
 *   health_ok:int, health_warn:int, health_crit:int, health_unknown:int,
 *   avg_load_pct:?float, max_load_pct:?float, min_battery_pct:?float, avg_battery_pct:?float,
 *   rated_kva:float, rated_kw:float, last_poll_at:?string, list:list<array<string,mixed>>
 * }
 */
function ups_dashboard_snapshot(int $listLimit = 12): array
{
    $out = [
        'units' => 0,
        'snmp_on' => 0,
        'polled' => 0,
        'online' => 0,
        'on_battery' => 0,
        'bypass' => 0,
        'health_ok' => 0,
        'health_warn' => 0,
        'health_crit' => 0,
        'health_unknown' => 0,
        'avg_load_pct' => null,
        'max_load_pct' => null,
        'min_battery_pct' => null,
        'avg_battery_pct' => null,
        'est_kw' => null,
        'rated_kva' => 0.0,
        'rated_kw' => 0.0,
        'last_poll_at' => null,
        'list' => [],
    ];
    try {
        $rows = Database::fetchAll(
            'SELECT u.ups_id, u.name, u.ups_scope, u.manufacturer, u.model, u.status,
                    u.primary_ip, u.rated_kva, u.rated_kw,
                    u.last_output_status, u.last_load_pct, u.last_battery_pct, u.last_runtime_min,
                    u.last_input_voltage, u.last_output_voltage,
                    u.snmp_enabled, u.snmp_last_poll_at, u.snmp_site_template_id, u.last_poll_json,
                    z.name AS zone_name
             FROM ups_units u
             LEFT JOIN power_zones z ON z.zone_id = u.zone_id
             WHERE u.is_active = 1
             ORDER BY u.name'
        );
    } catch (Throwable $e) {
        // Columns last_input/output_voltage may be missing on very old DBs
        try {
            $rows = Database::fetchAll(
                'SELECT u.ups_id, u.name, u.ups_scope, u.manufacturer, u.model, u.status,
                        u.primary_ip, u.rated_kva, u.rated_kw,
                        u.last_output_status, u.last_load_pct, u.last_battery_pct, u.last_runtime_min,
                        u.snmp_enabled, u.snmp_last_poll_at, u.snmp_site_template_id, u.last_poll_json,
                        z.name AS zone_name
                 FROM ups_units u
                 LEFT JOIN power_zones z ON z.zone_id = u.zone_id
                 WHERE u.is_active = 1
                 ORDER BY u.name'
            );
        } catch (Throwable $e2) {
            return $out;
        }
    }
    $out['units'] = count($rows);
    $loadSum = 0.0;
    $loadN = 0;
    $battSum = 0.0;
    $battN = 0;
    $estKwSum = 0.0;
    $estKwN = 0;
    $maxLoad = null;
    $minBatt = null;
    $lastPoll = null;
    foreach ($rows as $row) {
        $health = ups_health_status($row);
        $hk = 'health_' . $health;
        if (isset($out[$hk])) {
            $out[$hk]++;
        } else {
            $out['health_unknown']++;
        }
        if (!empty($row['snmp_enabled'])) {
            $out['snmp_on']++;
        }
        if (!empty($row['snmp_last_poll_at'])) {
            $out['polled']++;
            $ts = (string)$row['snmp_last_poll_at'];
            if ($lastPoll === null || strcmp($ts, $lastPoll) > 0) {
                $lastPoll = $ts;
            }
        }
        $st = strtolower(trim((string)($row['last_output_status'] ?? '')));
        if (preg_match('/on line|online|eco|econversion/i', $st)) {
            $out['online']++;
        } elseif (preg_match('/battery/i', $st)) {
            $out['on_battery']++;
        } elseif (preg_match('/bypass/i', $st)) {
            $out['bypass']++;
        }
        if ($row['rated_kva'] !== null && $row['rated_kva'] !== '') {
            $out['rated_kva'] += (float)$row['rated_kva'];
        }
        if ($row['rated_kw'] !== null && $row['rated_kw'] !== '') {
            $out['rated_kw'] += (float)$row['rated_kw'];
        }
        if ($row['last_load_pct'] !== null && $row['last_load_pct'] !== '') {
            $lv = (float)$row['last_load_pct'];
            $loadSum += $lv;
            $loadN++;
            if ($maxLoad === null || $lv > $maxLoad) {
                $maxLoad = $lv;
            }
            // Est. output kW from rated capacity × load %
            $rk = null;
            if ($row['rated_kw'] !== null && $row['rated_kw'] !== '') {
                $rk = (float)$row['rated_kw'];
            } elseif ($row['rated_kva'] !== null && $row['rated_kva'] !== '') {
                $rk = (float)$row['rated_kva'] * 0.9;
            }
            if ($rk !== null && $rk > 0) {
                $estKwSum += $rk * ($lv / 100.0);
                $estKwN++;
            }
        }
        if ($row['last_battery_pct'] !== null && $row['last_battery_pct'] !== '') {
            $bv = (float)$row['last_battery_pct'];
            $battSum += $bv;
            $battN++;
            if ($minBatt === null || $bv < $minBatt) {
                $minBatt = $bv;
            }
        }
    }
    if ($loadN > 0) {
        $out['avg_load_pct'] = round($loadSum / $loadN, 1);
        $out['max_load_pct'] = $maxLoad !== null ? round($maxLoad, 1) : null;
    }
    if ($battN > 0) {
        $out['avg_battery_pct'] = round($battSum / $battN, 1);
        $out['min_battery_pct'] = $minBatt !== null ? round($minBatt, 1) : null;
    }
    if ($estKwN > 0) {
        $out['est_kw'] = round($estKwSum, 2);
    }
    $out['rated_kva'] = round($out['rated_kva'], 1);
    $out['rated_kw'] = round($out['rated_kw'], 1);
    $out['last_poll_at'] = $lastPoll;

    $limit = max(0, $listLimit);
    $i = 0;
    foreach ($rows as $row) {
        if ($limit === 0 || $i >= $limit) {
            break;
        }
        // Pull last electricals from poll JSON when columns empty
        $inV = isset($row['last_input_voltage']) && $row['last_input_voltage'] !== null && $row['last_input_voltage'] !== ''
            ? (float)$row['last_input_voltage'] : null;
        $outV = isset($row['last_output_voltage']) && $row['last_output_voltage'] !== null && $row['last_output_voltage'] !== ''
            ? (float)$row['last_output_voltage'] : null;
        $outA = null;
        $inHz = null;
        $outHz = null;
        if (!empty($row['last_poll_json'])) {
            $pj = json_decode((string)$row['last_poll_json'], true);
            $der = is_array($pj) ? ($pj['derived'] ?? []) : [];
            if (is_array($der)) {
                if ($inV === null && isset($der['input_voltage']) && is_numeric($der['input_voltage'])) {
                    $inV = (float)$der['input_voltage'];
                }
                if ($outV === null && isset($der['output_voltage']) && is_numeric($der['output_voltage'])) {
                    $outV = (float)$der['output_voltage'];
                }
                if (isset($der['output_current']) && is_numeric($der['output_current'])) {
                    $outA = (float)$der['output_current'];
                }
                if (isset($der['input_freq']) && is_numeric($der['input_freq'])) {
                    $inHz = (float)$der['input_freq'];
                }
                if (isset($der['output_freq']) && is_numeric($der['output_freq'])) {
                    $outHz = (float)$der['output_freq'];
                }
            }
        }
        $loadPct = $row['last_load_pct'] !== null && $row['last_load_pct'] !== ''
            ? (float)$row['last_load_pct'] : null;
        $estKw = null;
        if ($loadPct !== null) {
            if ($row['rated_kw'] !== null && $row['rated_kw'] !== '') {
                $estKw = round((float)$row['rated_kw'] * ($loadPct / 100.0), 2);
            } elseif ($row['rated_kva'] !== null && $row['rated_kva'] !== '') {
                $estKw = round((float)$row['rated_kva'] * 0.9 * ($loadPct / 100.0), 2);
            } elseif ($outV !== null && $outA !== null && $outV > 0 && $outA > 0) {
                $estKw = round(($outV * $outA) / 1000.0, 2);
            }
        }
        $out['list'][] = [
            'ups_id' => (int)$row['ups_id'],
            'name' => (string)$row['name'],
            'scope' => (string)($row['ups_scope'] ?? 'in_row'),
            'model' => trim((string)(($row['manufacturer'] ?? '') . ' ' . ($row['model'] ?? ''))),
            'status' => (string)($row['status'] ?? ''),
            'output_status' => (string)($row['last_output_status'] ?? ''),
            'load_pct' => $loadPct,
            'battery_pct' => $row['last_battery_pct'] !== null && $row['last_battery_pct'] !== ''
                ? (float)$row['last_battery_pct'] : null,
            'runtime_min' => $row['last_runtime_min'] !== null && $row['last_runtime_min'] !== ''
                ? (float)$row['last_runtime_min'] : null,
            'rated_kva' => $row['rated_kva'] !== null && $row['rated_kva'] !== ''
                ? (float)$row['rated_kva'] : null,
            'est_kw' => $estKw,
            'input_voltage' => $inV,
            'output_voltage' => $outV,
            'output_current' => $outA,
            'input_freq' => $inHz,
            'output_freq' => $outHz,
            'zone_name' => $row['zone_name'] ?? null,
            'primary_ip' => $row['primary_ip'] ?? null,
            'snmp' => !empty($row['snmp_enabled']),
            'last_poll' => $row['snmp_last_poll_at'] ?? null,
            'health' => ups_health_status($row),
        ];
        $i++;
    }
    return $out;
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
        // upsAdvIdentDateOfManufacture (mm/dd/yy or mm/dd/yyyy)
        'manufacture_date' => '1.3.6.1.4.1.318.1.1.1.1.2.2.0',
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
 * Parse APC / PowerNet manufacture date strings into Y-m-d.
 * Accepts mm/dd/yy, mm/dd/yyyy, mm-dd-yyyy, yyyy-mm-dd, etc.
 */
function ups_parse_manufacture_date(mixed $raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_array($raw)) {
        $raw = $raw['raw'] ?? $raw['numeric'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
    }
    if (!is_scalar($raw)) {
        return null;
    }
    $s = trim((string)$raw);
    $s = preg_replace('/^(STRING|OCTET STRING)\s*:\s*/i', '', $s) ?? $s;
    $s = trim($s, " \t\"'");
    if ($s === '' || strcasecmp($s, 'unknown') === 0 || $s === '0') {
        return null;
    }
    // Already ISO
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        return null;
    }
    // mm/dd/yy or mm/dd/yyyy (APC PowerNet typical)
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $s, $m)) {
        $mo = (int)$m[1];
        $day = (int)$m[2];
        $yr = (int)$m[3];
        if ($yr < 100) {
            // APC two-digit year: 00–69 → 2000+, 70–99 → 1900+
            $yr += ($yr >= 70) ? 1900 : 2000;
        }
        if (checkdate($mo, $day, $yr)) {
            return sprintf('%04d-%02d-%02d', $yr, $mo, $day);
        }
    }
    // dd-MMM-yyyy style fallbacks
    $ts = strtotime($s);
    if ($ts !== false) {
        $y = (int)date('Y', $ts);
        if ($y >= 1980 && $y <= 2100) {
            return date('Y-m-d', $ts);
        }
    }
    return null;
}

/**
 * Pull manufacture date from poll metrics (key manufacture_date / mfg / …).
 * @param array<string,mixed> $metrics
 */
function ups_manufacture_date_from_metrics(array $metrics): ?string
{
    $keys = [
        'manufacture_date', 'manufacturedate', 'mfg_date', 'mfgdate',
        'date_of_manufacture', 'dateofmanufacture', 'upsadvidentdateofmanufacture',
    ];
    foreach ($metrics as $k => $v) {
        $lk = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$k) ?? '');
        $match = false;
        foreach ($keys as $want) {
            $w = strtolower(preg_replace('/[^a-z0-9]/', '', $want) ?? '');
            if ($lk === $w || str_contains($lk, 'manufacture') || str_contains($lk, 'mfgdate')) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            continue;
        }
        $parsed = ups_parse_manufacture_date($v);
        if ($parsed !== null) {
            return $parsed;
        }
    }
    return null;
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

/** Inventory template field keys (no host identity / secrets). */
function ups_template_static_keys(): array
{
    return [
        'manufacturer', 'model', 'ups_scope',
        'rated_kva', 'rated_kw', 'phases',
        'width_mm', 'depth_mm', 'height_mm', 'color_hex',
        'snmp_enabled', 'snmp_version', 'snmp_port',
        'snmp_v3_profile_id', 'snmp_v3_sec_level',
        'snmp_site_template_id', 'snmp_auto_poll',
        'warranty_provider',
    ];
}

function ups_template_display_name(?string $vendor, ?string $model, ?string $fallback = null): string
{
    $v = trim((string)$vendor);
    $m = trim((string)$model);
    if ($v !== '' && $m !== '') {
        return $v . ' ' . $m;
    }
    if ($m !== '') {
        return $m;
    }
    if ($v !== '') {
        return $v;
    }
    return $fallback !== null && trim($fallback) !== '' ? trim($fallback) : 'UPS template';
}

/**
 * Build template fields from a ups_units row.
 * @param array<string,mixed> $unit
 * @return array<string,mixed>
 */
function ups_template_payload_from_unit(array $unit): array
{
    $fields = [];
    foreach (ups_template_static_keys() as $k) {
        if (!array_key_exists($k, $unit)) {
            continue;
        }
        $v = $unit[$k];
        if ($v === null || $v === '') {
            continue;
        }
        if (in_array($k, ['snmp_site_template_id', 'snmp_v3_profile_id', 'snmp_port', 'snmp_auto_poll', 'snmp_enabled', 'phases', 'width_mm', 'depth_mm', 'height_mm'], true)) {
            $fields[$k] = (int)$v;
            if ($fields[$k] === 0 && in_array($k, ['snmp_site_template_id', 'snmp_v3_profile_id'], true)) {
                unset($fields[$k]);
            }
            continue;
        }
        if (in_array($k, ['rated_kva', 'rated_kw'], true)) {
            $fields[$k] = (float)$v;
            continue;
        }
        $fields[$k] = $v;
    }
    if (empty($fields['snmp_site_template_id'])) {
        unset($fields['snmp_auto_poll']);
    }
    return $fields;
}

/**
 * Merge template fields into a UPS row.
 * When $force is false, non-empty existing values are kept.
 * @param array<string,mixed> $row
 * @param array<string,mixed> $fields
 * @return array<string,mixed>
 */
function ups_template_apply_fields(array $row, array $fields, bool $force = false): array
{
    $allowed = array_flip(ups_template_static_keys());
    foreach ($fields as $k => $v) {
        if (!isset($allowed[$k])) {
            continue;
        }
        if ($v === null || $v === '') {
            continue;
        }
        if (!$force) {
            $cur = $row[$k] ?? null;
            if ($cur !== null && $cur !== '') {
                continue;
            }
        }
        $row[$k] = $v;
    }
    return $row;
}

/**
 * @return list<array<string,mixed>>
 */
function ups_template_list(): array
{
    try {
        return Database::fetchAll(
            'SELECT template_id, name, vendor, model, fields_json, notes, updated_at, created_at
             FROM ups_templates WHERE is_active = 1 ORDER BY name'
        );
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function ups_template_linked_units(int $templateId, ?string $vendor = null, ?string $model = null): array
{
    if ($templateId < 1) {
        return [];
    }
    try {
        $byId = Database::fetchAll(
            'SELECT ups_id, name, manufacturer, model, primary_ip, ups_template_id, is_active
             FROM ups_units WHERE is_active = 1 AND ups_template_id = ?
             ORDER BY name',
            [$templateId]
        );
    } catch (Throwable $e) {
        // column may not exist yet
        $byId = [];
    }
    if ($byId) {
        return $byId;
    }
    $v = trim((string)$vendor);
    $m = trim((string)$model);
    if ($v === '' || $m === '') {
        return [];
    }
    try {
        return Database::fetchAll(
            'SELECT ups_id, name, manufacturer, model, primary_ip, is_active
             FROM ups_units
             WHERE is_active = 1 AND manufacturer = ? AND model = ?
               AND (ups_template_id IS NULL OR ups_template_id = 0 OR ups_template_id = ?)
             ORDER BY name',
            [$v, $m, $templateId]
        );
    } catch (Throwable $e) {
        try {
            return Database::fetchAll(
                'SELECT ups_id, name, manufacturer, model, primary_ip, is_active
                 FROM ups_units
                 WHERE is_active = 1 AND manufacturer = ? AND model = ?
                 ORDER BY name',
                [$v, $m]
            );
        } catch (Throwable $e2) {
            return [];
        }
    }
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
        'ups_template_id' => !empty($post['ups_template_id']) ? (int)$post['ups_template_id'] : null,
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

/**
 * Merge testing-mode flags into last_poll_json snapshot.
 * @param array<string,mixed> $unit
 * @param array<string,mixed> $sim
 * @return string
 */
function ups_merge_poll_json_sim(array $unit, array $sim): string
{
    $poll = [];
    if (!empty($unit['last_poll_json'])) {
        $decoded = json_decode((string)$unit['last_poll_json'], true);
        if (is_array($decoded)) {
            $poll = $decoded;
        }
    }
    $poll['testing'] = array_merge(
        is_array($poll['testing'] ?? null) ? $poll['testing'] : [],
        $sim,
        ['at' => date('c')]
    );
    return json_encode($poll, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
}

/**
 * Testing mode: simulate management/connectivity loss (health crit + [TEST] alert).
 * @param array<string,mixed> $unit
 * @return array{ok:bool,message:string,health:string,last_output_status:?string}
 */
function ups_simulate_connectivity_outage(array $unit): array
{
    if (!class_exists('IcmpMonitorService') || !IcmpMonitorService::testingModeEnabled()) {
        throw new RuntimeException('Testing mode is off. Enable it under Settings → Diagnostics (Global Admin).');
    }
    $id = (int)($unit['ups_id'] ?? 0);
    if ($id < 1) {
        throw new RuntimeException('Invalid UPS id.');
    }
    $label = (string)($unit['name'] ?? ('UPS #' . $id));
    $prevStatus = (string)($unit['last_output_status'] ?? '');
    $status = 'Unreachable';
    $now = date('Y-m-d H:i:s');
    $json = ups_merge_poll_json_sim($unit, [
        'mode' => 'connectivity_outage',
        'prev_output_status' => $prevStatus !== '' ? $prevStatus : null,
    ]);
    Database::update('ups_units', [
        'last_output_status' => $status,
        'last_poll_json' => $json,
        'updated_at' => $now,
    ], 'ups_id = :id', [':id' => $id]);

    if (class_exists('AlertService')) {
        try {
            AlertService::emit([
                'category' => AlertService::CAT_POWER,
                'severity' => AlertService::SEV_CRITICAL,
                'title' => '[TEST] UPS unreachable: ' . $label,
                'message' => "Simulated connectivity outage (testing mode).\n"
                    . 'UPS: ' . $label . "\n"
                    . 'IP: ' . (string)($unit['primary_ip'] ?? '—') . "\n"
                    . 'Previous status: ' . ($prevStatus !== '' ? $prevStatus : '—') . "\n"
                    . 'Time: ' . date('c'),
                'entity_type' => 'ups',
                'entity_id' => $id,
            ]);
        } catch (Throwable $e) {
            // continue
        }
    }
    $fresh = Database::fetchOne('SELECT * FROM ups_units WHERE ups_id = ?', [$id]) ?: $unit;
    return [
        'ok' => true,
        'message' => 'Simulated connectivity outage for ' . $label . ' — [TEST] alert fired.',
        'health' => ups_health_status($fresh),
        'last_output_status' => $status,
    ];
}

/**
 * Testing mode: simulate transfer from line-in to battery (on battery + [TEST] alert).
 * @param array<string,mixed> $unit
 * @return array{ok:bool,message:string,health:string,last_output_status:?string}
 */
function ups_simulate_on_battery(array $unit): array
{
    if (!class_exists('IcmpMonitorService') || !IcmpMonitorService::testingModeEnabled()) {
        throw new RuntimeException('Testing mode is off. Enable it under Settings → Diagnostics (Global Admin).');
    }
    $id = (int)($unit['ups_id'] ?? 0);
    if ($id < 1) {
        throw new RuntimeException('Invalid UPS id.');
    }
    $label = (string)($unit['name'] ?? ('UPS #' . $id));
    $prevStatus = (string)($unit['last_output_status'] ?? '');
    if ($prevStatus === '' || preg_match('/unreachable/i', $prevStatus)) {
        $prevStatus = 'On line';
    }
    $status = 'On battery';
    $now = date('Y-m-d H:i:s');
    $batt = $unit['last_battery_pct'] !== null && $unit['last_battery_pct'] !== ''
        ? (float)$unit['last_battery_pct'] : 95.0;
    // Nudge battery slightly lower for a realistic sim reading
    if ($batt > 10) {
        $batt = max(10.0, round($batt - 5.0, 1));
    }
    $runtime = $unit['last_runtime_min'] !== null && $unit['last_runtime_min'] !== ''
        ? (float)$unit['last_runtime_min'] : 30.0;
    $json = ups_merge_poll_json_sim($unit, [
        'mode' => 'on_battery',
        'prev_output_status' => $prevStatus,
    ]);
    Database::update('ups_units', [
        'last_output_status' => $status,
        'last_battery_pct' => $batt,
        'last_runtime_min' => $runtime,
        'last_poll_json' => $json,
        'snmp_last_poll_at' => $now,
        'updated_at' => $now,
    ], 'ups_id = :id', [':id' => $id]);

    if (class_exists('AlertService')) {
        try {
            AlertService::emit([
                'category' => AlertService::CAT_POWER,
                'severity' => AlertService::SEV_CRITICAL,
                'title' => '[TEST] UPS On battery: ' . $label,
                'message' => "Simulated transfer from line-in to battery (testing mode).\n"
                    . 'UPS: ' . $label . "\n"
                    . 'Previous: ' . $prevStatus . " → On battery\n"
                    . 'Battery: ' . $batt . "%\n"
                    . 'Runtime: ' . $runtime . " min\n"
                    . 'Time: ' . date('c'),
                'entity_type' => 'ups',
                'entity_id' => $id,
            ]);
        } catch (Throwable $e) {
            // continue
        }
    }
    $fresh = Database::fetchOne('SELECT * FROM ups_units WHERE ups_id = ?', [$id]) ?: $unit;
    return [
        'ok' => true,
        'message' => 'Simulated On battery for ' . $label . ' — [TEST] alert fired.',
        'health' => ups_health_status($fresh),
        'last_output_status' => $status,
    ];
}

/**
 * Testing mode: clear simulated outage/battery → On line + recovery alert.
 * @param array<string,mixed> $unit
 * @return array{ok:bool,message:string,health:string,last_output_status:?string}
 */
function ups_simulate_recovery(array $unit): array
{
    if (!class_exists('IcmpMonitorService') || !IcmpMonitorService::testingModeEnabled()) {
        throw new RuntimeException('Testing mode is off. Enable it under Settings → Diagnostics (Global Admin).');
    }
    $id = (int)($unit['ups_id'] ?? 0);
    if ($id < 1) {
        throw new RuntimeException('Invalid UPS id.');
    }
    $label = (string)($unit['name'] ?? ('UPS #' . $id));
    $prevFromSim = null;
    if (!empty($unit['last_poll_json'])) {
        $decoded = json_decode((string)$unit['last_poll_json'], true);
        if (is_array($decoded) && !empty($decoded['testing']['prev_output_status'])) {
            $prevFromSim = (string)$decoded['testing']['prev_output_status'];
        }
    }
    $status = 'On line';
    if ($prevFromSim && preg_match('/on line|online|eco|econversion/i', $prevFromSim)) {
        $status = $prevFromSim;
    }
    $now = date('Y-m-d H:i:s');
    $json = ups_merge_poll_json_sim($unit, [
        'mode' => 'recovery',
        'prev_output_status' => (string)($unit['last_output_status'] ?? ''),
    ]);
    Database::update('ups_units', [
        'last_output_status' => $status,
        'last_poll_json' => $json,
        'snmp_last_poll_at' => $now,
        'updated_at' => $now,
    ], 'ups_id = :id', [':id' => $id]);

    if (class_exists('AlertService')) {
        try {
            AlertService::emit([
                'category' => AlertService::CAT_POWER,
                'severity' => AlertService::SEV_INFO,
                'title' => '[TEST] UPS recovered: ' . $label,
                'message' => "Simulated recovery (testing mode).\n"
                    . 'UPS: ' . $label . "\n"
                    . 'Status: ' . $status . "\n"
                    . 'Time: ' . date('c'),
                'entity_type' => 'ups',
                'entity_id' => $id,
            ]);
        } catch (Throwable $e) {
            // continue
        }
    }
    $fresh = Database::fetchOne('SELECT * FROM ups_units WHERE ups_id = ?', [$id]) ?: $unit;
    return [
        'ok' => true,
        'message' => 'Simulated recovery for ' . $label . ' — status ' . $status . '.',
        'health' => ups_health_status($fresh),
        'last_output_status' => $status,
    ];
}
