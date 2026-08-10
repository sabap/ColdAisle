<?php
/**
 * Shared power infrastructure helpers (zones / PDUs)
 */
declare(strict_types=1);

/**
 * Natural (alphanumeric) string compare: "PDU 2" before "PDU 10".
 */
function power_natural_strcmp(string $a, string $b): int
{
    return strnatcasecmp($a, $b);
}

/**
 * How facility / site / zone load totals are built from polled PDUs.
 *
 * - all: sum every active PDU (legacy; can double-count rack under row meters)
 * - prefer_upstream: per zone, prefer row/room PDUs when any exist; else rack
 * - manual: only PDUs with include_in_site_load = 1
 */
function power_site_load_mode(): string
{
    $m = 'all';
    if (class_exists('SettingsService')) {
        try {
            $m = strtolower(trim((string)SettingsService::get('power_site_load_mode', 'all')));
        } catch (Throwable $e) {
            $m = 'all';
        }
    }
    return in_array($m, ['all', 'prefer_upstream', 'manual'], true) ? $m : 'all';
}

/** @return array<string,string> mode => short label */
function power_site_load_mode_labels(): array
{
    return [
        'all' => 'Sum all PDUs',
        'prefer_upstream' => 'Prefer row / room meters',
        'manual' => 'Manual (checkbox per PDU)',
    ];
}

function power_site_load_mode_description(string $mode = ''): string
{
    $mode = $mode !== '' ? $mode : power_site_load_mode();
    return match ($mode) {
        'prefer_upstream' => 'In each zone, use row/room PDU load when those exist; rack PDUs only fill zones that have no distribution meter. Avoids double-counting cabinets fed by row PDUs.',
        'manual' => 'Only PDUs with “Include in site load” checked contribute to facility and zone totals. Use for custom meter hierarchies.',
        default => 'Sum every active PDU’s polled load. Simple sites; can double-count if rack PDUs sit downstream of row/room meters.',
    };
}

/**
 * Which PDU IDs count toward site/zone facility load under the current rollup mode.
 *
 * @param list<array<string,mixed>> $pdus rows need pdu_id; ideally pdu_scope, zone_id, include_in_site_load
 * @return list<int>
 */
function power_site_load_pdu_ids(array $pdus): array
{
    $mode = power_site_load_mode();
    $ids = [];

    // Force-exclude always honored (include_in_site_load = 0)
    $eligible = [];
    foreach ($pdus as $p) {
        $pid = (int)($p['pdu_id'] ?? 0);
        if ($pid < 1) {
            continue;
        }
        $inc = $p['include_in_site_load'] ?? 1;
        if ($inc === null || $inc === '') {
            $inc = 1;
        }
        if (!(int)$inc) {
            continue;
        }
        $eligible[] = $p;
    }

    if ($mode === 'manual') {
        foreach ($eligible as $p) {
            $ids[] = (int)$p['pdu_id'];
        }
        return array_values(array_unique($ids));
    }

    if ($mode === 'all') {
        foreach ($eligible as $p) {
            $ids[] = (int)$p['pdu_id'];
        }
        return array_values(array_unique($ids));
    }

    // prefer_upstream: per zone (null zone = key 0)
    $byZone = [];
    foreach ($eligible as $p) {
        $z = $p['zone_id'] ?? null;
        $zk = ($z === null || $z === '') ? 0 : (int)$z;
        $byZone[$zk][] = $p;
    }
    foreach ($byZone as $group) {
        $hasUpstream = false;
        foreach ($group as $p) {
            $s = strtolower((string)($p['pdu_scope'] ?? 'rack'));
            if ($s === 'row' || $s === 'room') {
                $hasUpstream = true;
                break;
            }
        }
        foreach ($group as $p) {
            $s = strtolower((string)($p['pdu_scope'] ?? 'rack'));
            if ($hasUpstream) {
                if ($s === 'row' || $s === 'room') {
                    $ids[] = (int)$p['pdu_id'];
                }
            } else {
                $ids[] = (int)$p['pdu_id'];
            }
        }
    }
    return array_values(array_unique($ids));
}

/**
 * @param list<array<string,mixed>> $pdus
 * @return array{watts:float,kw:float,count:int,reporting:int,mode:string,ids:list<int>}
 */
function power_site_load_totals(array $pdus): array
{
    $ids = power_site_load_pdu_ids($pdus);
    $idSet = array_fill_keys($ids, true);
    $sum = 0.0;
    $reporting = 0;
    foreach ($pdus as $p) {
        $pid = (int)($p['pdu_id'] ?? 0);
        if ($pid < 1 || empty($idSet[$pid])) {
            continue;
        }
        if ($p['last_poll_watts'] !== null && $p['last_poll_watts'] !== '') {
            $sum += (float)$p['last_poll_watts'];
            $reporting++;
        }
    }
    return [
        'watts' => $sum,
        'kw' => $sum / 1000.0,
        'count' => count($ids),
        'reporting' => $reporting,
        'mode' => power_site_load_mode(),
        'ids' => $ids,
    ];
}

/**
 * Zone subtotal using the same rollup rules (only PDUs in that zone).
 *
 * @param list<array<string,mixed>> $allPdus
 * @return array{watts:float,kw:float,count:int,reporting:int}
 */
function power_zone_load_totals(array $allPdus, int $zoneId): array
{
    $inZone = array_values(array_filter(
        $allPdus,
        static fn($p) => (int)($p['zone_id'] ?? 0) === $zoneId
    ));
    // Apply rollup only within this zone's PDUs
    $mode = power_site_load_mode();
    $ids = [];
    if ($mode === 'manual' || $mode === 'all') {
        $ids = power_site_load_pdu_ids($inZone);
    } else {
        // prefer_upstream for this zone alone
        $ids = power_site_load_pdu_ids($inZone);
    }
    $idSet = array_fill_keys($ids, true);
    $sum = 0.0;
    $reporting = 0;
    foreach ($inZone as $p) {
        $pid = (int)($p['pdu_id'] ?? 0);
        if ($pid < 1 || empty($idSet[$pid])) {
            continue;
        }
        if ($p['last_poll_watts'] !== null && $p['last_poll_watts'] !== '') {
            $sum += (float)$p['last_poll_watts'];
            $reporting++;
        }
    }
    return [
        'watts' => $sum,
        'kw' => $sum / 1000.0,
        'count' => count($ids),
        'reporting' => $reporting,
    ];
}

/**
 * Sort list rows by a string column using natural order (digits as numbers).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function power_natural_sort_rows(array $rows, string $key = 'name'): array
{
    usort($rows, static function ($a, $b) use ($key) {
        $sa = (string)($a[$key] ?? '');
        $sb = (string)($b[$key] ?? '');
        $cmp = strnatcasecmp($sa, $sb);
        if ($cmp !== 0) {
            return $cmp;
        }
        // Stable-ish tie-breakers
        $ida = (int)($a['pdu_id'] ?? $a['ups_id'] ?? $a['zone_id'] ?? 0);
        $idb = (int)($b['pdu_id'] ?? $b['ups_id'] ?? $b['zone_id'] ?? 0);
        return $ida <=> $idb;
    });
    return $rows;
}

/**
 * Normalize PDU electrical topology from form POST.
 */
function power_pdu_electrical_from_post(array $post): array
{
    $phases = (int)($post['phases'] ?? 1);
    if (!in_array($phases, [1, 2, 3], true)) {
        $phases = 1;
    }
    $wiring = strtolower((string)($post['phase_wiring'] ?? 'single'));
    $allowed = [
        1 => ['single'],
        2 => ['split_phase', 'two_phase'],
        3 => ['wye', 'delta'],
    ];
    if (!in_array($wiring, $allowed[$phases], true)) {
        $wiring = match ($phases) {
            2 => 'split_phase',
            3 => 'wye',
            default => 'single',
        };
    }
    $inLlRaw = $post['input_voltage'] ?? '';
    $inLnRaw = $post['input_voltage_ln'] ?? '';
    $outVRaw = $post['output_voltage'] ?? '';
    $outLnRaw = $post['output_voltage_ln'] ?? '';
    $inLl = $inLlRaw !== '' && $inLlRaw !== null ? (int)$inLlRaw : null;
    $inLn = $inLnRaw !== '' && $inLnRaw !== null ? (int)$inLnRaw : null;
    $outV = $outVRaw !== '' && $outVRaw !== null ? (int)$outVRaw : null;
    $outLn = $outLnRaw !== '' && $outLnRaw !== null ? (int)$outLnRaw : null;

    if ($phases === 1) {
        $inLn = null;
        $outLn = null;
    }
    if ($phases === 2 && $wiring === 'split_phase' && $inLl !== null && $inLn === null) {
        $inLn = (int)round($inLl / 2);
    }
    if ($phases === 3 && $wiring === 'wye' && $inLl !== null && $inLn === null) {
        $inLn = (int)round($inLl / 1.732);
    }

    return [
        'phases' => $phases,
        'phase_wiring' => $wiring,
        'input_voltage' => $inLl,
        'input_voltage_ln' => $inLn,
        'output_voltage' => $outV,
        'output_voltage_ln' => $outLn,
        'rated_volts' => $inLl ?? $outV,
        'sync_zone_voltage' => !empty($post['sync_zone_voltage']) ? 1 : 0,
    ];
}

function power_sync_zone_voltage(?int $zoneId, array $elec, string $scope): void
{
    if (!$zoneId || empty($elec['sync_zone_voltage'])) {
        return;
    }
    if (!in_array($scope, ['row', 'room'], true)) {
        return;
    }
    $volts = $elec['input_voltage'] ?? $elec['input_voltage_ln'] ?? $elec['rated_volts'] ?? null;
    if ($volts === null) {
        return;
    }
    Database::update('power_zones', ['voltage' => (int)$volts], 'zone_id = :id', [':id' => $zoneId]);
}

function power_normalize_color(?string $hex, string $fallback = '#ef4444'): string
{
    $hex = trim((string)$hex);
    if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex)) {
        return '#' . ltrim($hex, '#');
    }
    return $fallback;
}

function power_wiring_label(?string $wiring, int $phases = 1): string
{
    return [
        'single' => '1φ',
        'split_phase' => '2φ split',
        'two_phase' => '2φ',
        'wye' => '3φ Y',
        'delta' => '3φ Δ',
    ][$wiring ?? ''] ?? ($phases . 'φ');
}

/** @return list<string> PDU input connector options (plug / inlet). */
function power_pdu_input_types(): array
{
    return [
        'L6-30P',
        'L6-20P',
        'L5-30P',
        'L5-20P',
        'L14-30P',
        'L21-20P',
        'L21-30P',
        'CS8365',
        'IEC 60309 3P+N+E 32A',
        'IEC 60309 3P+N+E 16A',
        'Hardwired',
        'C20',
        'Other',
    ];
}

/**
 * Decode pdus.last_poll_phases JSON for UI.
 *
 * @return array{
 *   rows:list<array{label:string,watts:?float,amps:?float,volts:?float,va:?float,pf:?float,peak_amps:?float,load_state:?float}>,
 *   ll:array<string,float>,
 *   device:array{va?:float,pf?:float,rated_amps?:float},
 *   ps:array{ps1?:float,ps2?:float,alarm?:float}
 * }|null
 */
function power_phase_poll_decode($json): ?array
{
    if ($json === null || $json === '') {
        return null;
    }
    if (is_array($json)) {
        $data = $json;
    } else {
        $data = json_decode((string)$json, true);
    }
    if (!is_array($data) || !$data) {
        return null;
    }
    $rows = [];
    foreach (['L1', 'L2', 'L3', 'A', 'B', 'C'] as $label) {
        if (!isset($data[$label]) || !is_array($data[$label])) {
            continue;
        }
        $p = $data[$label];
        $num = static function ($v): ?float {
            return isset($v) && is_numeric($v) ? (float)$v : null;
        };
        $watts = $num($p['watts'] ?? null);
        $amps = $num($p['amps'] ?? null);
        $volts = $num($p['volts'] ?? null);
        $va = $num($p['va'] ?? null);
        $pf = $num($p['pf'] ?? null);
        $peak = $num($p['peak_amps'] ?? null);
        $loadState = $num($p['load_state'] ?? null);
        // Older APC rPDU (e.g. AP786x) may only report load_state per phase
        if ($watts === null && $amps === null && $volts === null
            && $va === null && $pf === null && $peak === null
            && $loadState === null
        ) {
            continue;
        }
        $rows[] = [
            'label' => $label,
            'watts' => $watts,
            'amps' => $amps,
            'volts' => $volts,
            'va' => $va,
            'pf' => $pf,
            'peak_amps' => $peak,
            'load_state' => $loadState,
        ];
    }
    $ll = [];
    if (isset($data['_ll']) && is_array($data['_ll'])) {
        foreach ($data['_ll'] as $k => $v) {
            if (is_numeric($v)) {
                $ll[(string)$k] = (float)$v;
            }
        }
    }
    $device = [];
    if (isset($data['_device']) && is_array($data['_device'])) {
        foreach (['va', 'pf', 'rated_amps', 'input_volts'] as $k) {
            if (isset($data['_device'][$k]) && is_numeric($data['_device'][$k])) {
                $device[$k] = (float)$data['_device'][$k];
            }
        }
    }
    $ps = [];
    if (isset($data['_ps']) && is_array($data['_ps'])) {
        foreach (['ps1', 'ps2', 'alarm'] as $k) {
            if (isset($data['_ps'][$k]) && is_numeric($data['_ps'][$k])) {
                $ps[$k] = (float)$data['_ps'][$k];
            }
        }
    }
    if (!$rows && !$ll && !$device && !$ps) {
        return null;
    }
    return ['rows' => $rows, 'll' => $ll, 'device' => $device, 'ps' => $ps];
}

/**
 * @return list<array{label:string,watts:?float,amps:?float,volts:?float}>|null
 * @deprecated use power_phase_poll_decode
 */
function power_phase_poll_rows($json): ?array
{
    $d = power_phase_poll_decode($json);
    return $d['rows'] ?? null;
}

function power_fmt_metric(?float $n, int $decimals = 2): string
{
    if ($n === null) {
        return '—';
    }
    return rtrim(rtrim(sprintf('%.' . $decimals . 'F', $n), '0'), '.') ?: '0';
}

/**
 * APC phase/bank load-state labels.
 *
 * Classic rPDU (…12.2.3 / rPDULoadStatusLoadState):
 *   1=normal, 2=low, 3=near overload, 4=overload
 * rPDU2 (…26.6 / rPDU2PhaseStatusLoadState):
 *   1=low, 2=normal, 3=near overload, 4=overload
 *
 * @param 'classic'|'rpdu2'|'auto'|null $family
 */
function power_phase_load_state_label(?float $state, ?string $family = 'auto'): string
{
    if ($state === null) {
        return '—';
    }
    $s = (int)$state;
    $fam = strtolower((string)($family ?? 'auto'));
    if ($fam === 'classic' || $fam === 'rpdu' || $fam === 'rPDU') {
        return match ($s) {
            1 => 'normal',
            2 => 'low',
            3 => 'near overload',
            4 => 'overload',
            default => (string)$s,
        };
    }
    if ($fam === 'rpdu2' || $fam === 'rPDU2') {
        return match ($s) {
            1 => 'low',
            2 => 'normal',
            3 => 'near overload',
            4 => 'overload',
            default => (string)$s,
        };
    }
    // auto: values 3–4 same; 1–2 ambiguous — prefer classic wording when 1 with
    // zero amps is still "Normal Load" on AP786x NMC (low threshold often 0).
    return match ($s) {
        1 => 'normal', // classic default; rPDU2 low is less common for load_state-only UI
        2 => 'low',    // classic low; rPDU2 normal — see power_phase_load_state_label_for_map()
        3 => 'near overload',
        4 => 'overload',
        default => (string)$s,
    };
}

/**
 * Pick load-state family from site OID map (classic 12.x vs rPDU2 26.x).
 *
 * @param array<string,mixed> $oidMap
 */
function power_phase_load_state_family_from_map(array $oidMap): string
{
    $blob = '';
    foreach ($oidMap as $k => $v) {
        if (is_string($k) && preg_match('/load_state/', strtolower($k)) && is_string($v)) {
            $blob .= ' ' . $v;
        }
    }
    if (str_contains($blob, '1.3.6.1.4.1.318.1.1.12.')) {
        return 'classic';
    }
    if (str_contains($blob, '1.3.6.1.4.1.318.1.1.26.')) {
        return 'rpdu2';
    }
    return 'classic';
}

/**
 * True when this PDU is current/load-state centric (no real kW from SNMP), e.g. AP7862.
 * Matches APC NMC "Load Management" which shows amps, not kW.
 *
 * @param array<string,mixed> $oidMap
 * @param array<string,mixed>|null $phaseSnap power_phase_poll_decode() result
 */
function power_pdu_is_amps_centric(array $oidMap, ?array $phaseSnap, ?float $lastWatts): bool
{
    $hasPhaseWattsKey = false;
    $hasRpdu2Power = false;
    $hasAmpsOrState = false;
    foreach ($oidMap as $k => $oid) {
        $k = strtolower((string)$k);
        $o = is_string($oid) ? ltrim($oid, '.') : '';
        if (preg_match('/^phase[123]_watts/', $k) || str_contains($k, 'watts_hundredths')) {
            $hasPhaseWattsKey = true;
        }
        if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.4\.3\.1\.5/', $o)
            || preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.6\.3\.1\.7/', $o)
        ) {
            $hasRpdu2Power = true;
        }
        if (preg_match('/amp|load_state/', $k)) {
            $hasAmpsOrState = true;
        }
    }
    if ($hasRpdu2Power || $hasPhaseWattsKey) {
        // Still amps-centric if live power never appears
        if ($lastWatts !== null && abs($lastWatts) >= 0.5) {
            return false;
        }
        $phaseHasW = false;
        if (is_array($phaseSnap)) {
            foreach ($phaseSnap['rows'] ?? [] as $pr) {
                if (($pr['watts'] ?? null) !== null && abs((float)$pr['watts']) >= 0.5) {
                    $phaseHasW = true;
                    break;
                }
            }
        }
        if ($phaseHasW) {
            return false;
        }
        // rPDU2 map but power leaves unsupported → treat as amps UI when amps/state exist
        return $hasAmpsOrState;
    }
    // Classic Ident watts only / load-state maps
    return $hasAmpsOrState || ($lastWatts === null || abs((float)$lastWatts) < 0.5);
}

/** APC power-supply status enum (approx). */
function power_ps_status_label(?float $state): string
{
    if ($state === null) {
        return '—';
    }
    return match ((int)$state) {
        1 => 'OK',
        2 => 'fault',
        3 => 'not present',
        default => (string)(int)$state,
    };
}

/** @return list<string> Common receptacle / plug types for outlet inventory. */
function power_outlet_connector_types(): array
{
    return [
        'C13', 'C14', 'C19', 'C20',
        '5-15R', '5-20R', 'L5-20R', 'L5-30R',
        'L6-20R', 'L6-30R', 'L14-30R',
        'IEC 60309 16A', 'IEC 60309 32A',
        'Hardwired', 'Other',
    ];
}

/** @return list<string> Common device-side PSU plug / connector types (NEMA / IEC). */
function power_device_connector_types(): array
{
    return [
        'C13', 'C14', 'C19', 'C20',
        '5-15P', '5-20P', 'L5-20P', 'L5-30P',
        'L6-20P', 'L6-30P', 'L14-30P',
        'IEC 60309', 'Other',
    ];
}

/**
 * Normalize template / form PSU definitions (no PDU mapping).
 *
 * @param list<array<string,mixed>>|string|null $raw
 * @return list<array{name:string,watts:?float,connector_type:?string,sort_order:int,notes:?string}>
 */
function power_normalize_psu_defs($raw): array
{
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $i = 0;
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            $name = 'PSU-' . ($i + 1);
        }
        $watts = $row['watts'] ?? null;
        if ($watts === '' || $watts === null) {
            $watts = null;
        } else {
            $watts = (float)$watts;
        }
        $conn = trim((string)($row['connector_type'] ?? $row['connector'] ?? ''));
        $notes = trim((string)($row['notes'] ?? ''));
        $sort = isset($row['sort_order']) ? (int)$row['sort_order'] : $i;
        $out[] = [
            'name' => $name,
            'watts' => $watts,
            'connector_type' => $conn !== '' ? $conn : null,
            'sort_order' => $sort,
            'notes' => $notes !== '' ? $notes : null,
        ];
        $i++;
    }
    return $out;
}

/**
 * Build PSU defs from parallel form arrays (template editor).
 *
 * @param list<string>|null $names
 * @param list<string>|null $watts
 * @param list<string>|null $connectors
 * @return list<array{name:string,watts:?float,connector_type:?string,sort_order:int,notes:?string}>
 */
function power_psu_defs_from_form_arrays(?array $names, ?array $watts, ?array $connectors): array
{
    $names = $names ?? [];
    $watts = $watts ?? [];
    $connectors = $connectors ?? [];
    $n = max(count($names), count($watts), count($connectors));
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $name = trim((string)($names[$i] ?? ''));
        $w = $watts[$i] ?? '';
        $c = trim((string)($connectors[$i] ?? ''));
        // Skip fully empty rows
        if ($name === '' && ($w === '' || $w === null) && $c === '') {
            continue;
        }
        $rows[] = [
            'name' => $name !== '' ? $name : ('PSU-' . (count($rows) + 1)),
            'watts' => $w,
            'connector_type' => $c,
            'sort_order' => count($rows),
        ];
    }
    return power_normalize_psu_defs($rows);
}

/**
 * Resolve PSU defs for a device template (JSON preferred; legacy num_power_ports fallback).
 *
 * @return list<array{name:string,watts:?float,connector_type:?string,sort_order:int,notes:?string}>
 */
function power_template_psu_defs(array $tpl): array
{
    $defs = [];
    if (!empty($tpl['power_supplies_json'])) {
        $defs = power_normalize_psu_defs($tpl['power_supplies_json']);
    }
    if ($defs) {
        return $defs;
    }
    // Legacy: synthesize named PSUs from count (no connector/watts)
    $n = max(0, min(16, (int)($tpl['num_power_ports'] ?? 0)));
    for ($i = 0; $i < $n; $i++) {
        $defs[] = [
            'name' => $n === 1 ? 'PSU' : ('PSU-' . chr(65 + $i)), // PSU-A, PSU-B…
            'watts' => null,
            'connector_type' => null,
            'sort_order' => $i,
            'notes' => null,
        ];
    }
    return $defs;
}

/**
 * Insert device_power_supplies rows for a new device (no PDU mapping).
 *
 * @param list<array{name?:string,watts?:?float,connector_type?:?string,sort_order?:int,notes?:?string}> $defs
 */
function power_create_device_psus(int $deviceId, array $defs): int
{
    $defs = power_normalize_psu_defs($defs);
    $count = 0;
    foreach ($defs as $d) {
        Database::insert('device_power_supplies', [
            'device_id' => $deviceId,
            'name' => $d['name'],
            'watts' => $d['watts'],
            'connector_type' => $d['connector_type'],
            'pdu_id' => null,
            'pdu_outlet_id' => null,
            'sort_order' => (int)$d['sort_order'],
            'notes' => $d['notes'],
        ]);
        $count++;
    }
    return $count;
}

/**
 * Decode pdus.last_poll_outlets JSON for UI.
 * Snapshot keys are outlet numbers as strings: "1" => {amps,watts,name,state,num?}.
 *
 * @return array{
 *   by_num:array<int,array{num:int,amps:?float,watts:?float,name:?string,state:?float}>,
 *   count:int,
 *   sum_watts:?float,
 *   sum_amps:?float
 * }|null
 */
function power_outlet_poll_decode($json): ?array
{
    if ($json === null || $json === '') {
        return null;
    }
    if (is_array($json)) {
        $data = $json;
    } else {
        $data = json_decode((string)$json, true);
    }
    if (!is_array($data) || !$data) {
        return null;
    }
    $byNum = [];
    $sumW = 0.0;
    $sumA = 0.0;
    $anyW = false;
    $anyA = false;
    foreach ($data as $k => $row) {
        if (!is_array($row)) {
            continue;
        }
        $num = isset($row['num']) && is_numeric($row['num'])
            ? (int)$row['num']
            : (is_numeric($k) ? (int)$k : 0);
        if ($num < 1) {
            continue;
        }
        $amps = isset($row['amps']) && is_numeric($row['amps']) ? (float)$row['amps'] : null;
        $watts = isset($row['watts']) && is_numeric($row['watts']) ? (float)$row['watts'] : null;
        $state = isset($row['state']) && is_numeric($row['state']) ? (float)$row['state'] : null;
        $name = isset($row['name']) && is_string($row['name']) && trim($row['name']) !== ''
            ? trim($row['name'])
            : null;
        if ($amps === null && $watts === null && $state === null && $name === null) {
            continue;
        }
        $byNum[$num] = [
            'num' => $num,
            'amps' => $amps,
            'watts' => $watts,
            'name' => $name,
            'state' => $state,
        ];
        if ($watts !== null) {
            $sumW += $watts;
            $anyW = true;
        }
        if ($amps !== null) {
            $sumA += $amps;
            $anyA = true;
        }
    }
    if (!$byNum) {
        return null;
    }
    ksort($byNum, SORT_NUMERIC);
    return [
        'by_num' => $byNum,
        'count' => count($byNum),
        'sum_watts' => $anyW ? round($sumW, 3) : null,
        'sum_amps' => $anyA ? round($sumA, 3) : null,
    ];
}

/**
 * APC rPDU2OutletMeteredStatusState (load band) — same enum as phase load state.
 * Some switched PDUs use 1=on / 2=off; prefer load labels when 1–4.
 */
function power_outlet_state_label(?float $state): string
{
    if ($state === null) {
        return '—';
    }
    return match ((int)$state) {
        1 => 'low',
        2 => 'normal',
        3 => 'near overload',
        4 => 'overload',
        default => (string)(int)$state,
    };
}

/**
 * Approx kW capacity from amps × volts × phase factor (rough planning figure).
 */
function power_estimate_kw(?float $amps, ?int $volts, int $phases = 1): ?float
{
    if ($amps === null || $volts === null || $amps <= 0 || $volts <= 0) {
        return null;
    }
    $factor = $phases >= 3 ? 1.732 : ($phases === 2 ? 1.0 : 1.0);
    // 3φ: √3 × V_ll × I; 1φ: V × I
    if ($phases >= 3) {
        return round(($amps * $volts * 1.732) / 1000, 2);
    }
    return round(($amps * $volts) / 1000, 2);
}

function power_util_class(float $pct): string
{
    if ($pct >= 90) {
        return 'danger';
    }
    if ($pct >= 75) {
        return 'warning';
    }
    if ($pct >= 50) {
        return 'accent';
    }
    return 'success';
}

function power_normalize_output_mode(?string $mode): string
{
    $mode = strtolower(trim((string)$mode));
    return in_array($mode, ['outlets', 'breakers'], true) ? $mode : 'outlets';
}

/** @return list<string> */
function power_breaker_layout_options(): array
{
    return [
        'odd_right_even_left' => '2-col · odds right, evens left (1,3,5… / 2,4,6…) — common US',
        'odd_left_even_right' => '2-col · odds left, evens right',
        'sequential_rows' => '2-col · sequential left→right by row (1 2 / 3 4 / …)',
        'sequential_columns' => '2-col · fill left column top→bottom, then right',
        'single_column' => '1-col · sequential top→bottom (1,2,3…)',
        'three_col_sequential' => '3-col · sequential left→right by row',
    ];
}

function power_normalize_breaker_layout(?string $layout): string
{
    $layout = strtolower(trim((string)$layout));
    $ok = array_keys(power_breaker_layout_options());
    return in_array($layout, $ok, true) ? $layout : 'odd_right_even_left';
}

/**
 * Parse breaker slot list from JSON string or comma-separated / array.
 * @return list<int> sorted unique slot numbers
 */
function power_parse_breaker_slots($raw, int $maxSlot = 128): array
{
    if (is_array($raw)) {
        $list = $raw;
    } else {
        $s = trim((string)$raw);
        if ($s === '') {
            return [];
        }
        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
            $list = $decoded;
        } else {
            $list = preg_split('/[\s,;]+/', $s) ?: [];
        }
    }
    $out = [];
    foreach ($list as $v) {
        $n = (int)$v;
        if ($n >= 1 && $n <= $maxSlot) {
            $out[$n] = $n;
        }
    }
    $out = array_values($out);
    sort($out, SORT_NUMERIC);
    return $out;
}

/**
 * Slots occupied by a breaker row (supports slots_json or legacy start/end range).
 * @return list<int>
 */
function power_breaker_slots_of(array $br, int $maxSlot = 128): array
{
    if (!empty($br['slots_json'])) {
        return power_parse_breaker_slots($br['slots_json'], $maxSlot);
    }
    $s = (int)($br['slot_start'] ?? 0);
    $e = (int)($br['slot_end'] ?? $s);
    if ($s < 1) {
        return [];
    }
    if ($e < $s) {
        $e = $s;
    }
    $out = [];
    for ($i = $s; $i <= min($e, $maxSlot); $i++) {
        $out[] = $i;
    }
    return $out;
}

function power_breaker_slots_label(array $slots): string
{
    if (!$slots) {
        return '—';
    }
    return implode(', ', $slots);
}

/**
 * Whether the given slot set is free (no overlap with other breakers).
 * @param list<int> $slots
 */
function power_breaker_slots_available(int $pduId, array $slots, ?int $excludeBreakerId = null): bool
{
    if (!$slots) {
        return false;
    }
    $sql = 'SELECT * FROM pdu_breakers WHERE pdu_id = ?';
    $params = [$pduId];
    if ($excludeBreakerId) {
        $sql .= ' AND breaker_id <> ?';
        $params[] = $excludeBreakerId;
    }
    $rows = Database::fetchAll($sql, $params);
    $want = array_fill_keys($slots, true);
    foreach ($rows as $r) {
        foreach (power_breaker_slots_of($r) as $s) {
            if (isset($want[$s])) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Build slot# => breaker occupancy map.
 * @return array<int, ?array>
 */
function power_breaker_slot_map(int $pduId, int $numSlots, array $breakers): array
{
    $map = [];
    for ($i = 1; $i <= $numSlots; $i++) {
        $map[$i] = null;
    }
    foreach ($breakers as $br) {
        foreach (power_breaker_slots_of($br, $numSlots) as $i) {
            // array_key_exists — isset() is false when value is null (our free-slot default)
            if (array_key_exists($i, $map)) {
                $map[$i] = $br;
            }
        }
    }
    return $map;
}

/**
 * Visual grid positions for the breaker panel.
 * Returns list of rows; each row is list of cells {slot:int|null, col:int}.
 * null slot = empty padding cell.
 *
 * @return list<list<array{slot:?int}>>
 */
function power_breaker_panel_grid(int $numSlots, string $layout, int $columns = 2): array
{
    $numSlots = max(1, min(128, $numSlots));
    $layout = power_normalize_breaker_layout($layout);

    if ($layout === 'single_column' || $columns < 2) {
        $grid = [];
        for ($i = 1; $i <= $numSlots; $i++) {
            $grid[] = [['slot' => $i]];
        }
        return $grid;
    }

    if ($layout === 'three_col_sequential' || $columns >= 3) {
        $cols = 3;
        $rows = (int)ceil($numSlots / $cols);
        $grid = [];
        $n = 1;
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                $row[] = ['slot' => $n <= $numSlots ? $n : null];
                $n++;
            }
            $grid[] = $row;
        }
        return $grid;
    }

    // 2-column layouts
    if ($layout === 'odd_right_even_left') {
        // Classic US: left = 2,4,6…  right = 1,3,5…
        $rows = (int)ceil($numSlots / 2);
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $even = ($r + 1) * 2;
            $odd = $even - 1;
            $grid[] = [
                ['slot' => $even <= $numSlots ? $even : null],
                ['slot' => $odd <= $numSlots ? $odd : null],
            ];
        }
        return $grid;
    }

    if ($layout === 'odd_left_even_right') {
        $rows = (int)ceil($numSlots / 2);
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $even = ($r + 1) * 2;
            $odd = $even - 1;
            $grid[] = [
                ['slot' => $odd <= $numSlots ? $odd : null],
                ['slot' => $even <= $numSlots ? $even : null],
            ];
        }
        return $grid;
    }

    if ($layout === 'sequential_columns') {
        $rows = (int)ceil($numSlots / 2);
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $left = $r + 1;
            $right = $r + 1 + $rows;
            $grid[] = [
                ['slot' => $left <= $numSlots ? $left : null],
                ['slot' => $right <= $numSlots ? $right : null],
            ];
        }
        return $grid;
    }

    // sequential_rows (default fallback): 1 2 / 3 4 / …
    $rows = (int)ceil($numSlots / 2);
    $grid = [];
    $n = 1;
    for ($r = 0; $r < $rows; $r++) {
        $grid[] = [
            ['slot' => $n <= $numSlots ? $n : null],
            ['slot' => ($n + 1) <= $numSlots ? $n + 1 : null],
        ];
        $n += 2;
    }
    return $grid;
}

/**
 * Grow/shrink pdu_outlets inventory to $numOutlets.
 * Adds missing numbers (default type/amps); removes unmapped extras above count.
 * Never overwrites existing outlet_type / label / rated_amps.
 *
 * @return array{added:int,removed:int,total:int}
 */
function power_sync_outlet_inventory(
    int $pduId,
    int $numOutlets,
    string $defaultType = 'C13',
    ?float $defaultAmps = null
): array {
    $numOutlets = max(0, min(128, $numOutlets));
    $existing = Database::fetchAll(
        'SELECT outlet_id, outlet_number, connected_device_id, device_power_supply_id
         FROM pdu_outlets WHERE pdu_id = ?',
        [$pduId]
    );
    $byNum = [];
    foreach ($existing as $o) {
        $byNum[(int)$o['outlet_number']] = $o;
    }
    $added = 0;
    for ($i = 1; $i <= $numOutlets; $i++) {
        if (!isset($byNum[$i])) {
            Database::insert('pdu_outlets', [
                'pdu_id' => $pduId,
                'outlet_number' => $i,
                'label' => 'Outlet ' . $i,
                'outlet_type' => $defaultType !== '' ? $defaultType : 'C13',
                'rated_amps' => $defaultAmps,
            ]);
            $added++;
        }
    }
    $removed = 0;
    foreach ($byNum as $num => $o) {
        if ($num > $numOutlets) {
            if (empty($o['connected_device_id']) && empty($o['device_power_supply_id'])) {
                Database::delete('pdu_outlets', 'outlet_id = ?', [(int)$o['outlet_id']]);
                $removed++;
            }
        }
    }
    Database::update('pdus', ['num_outlets' => $numOutlets], 'pdu_id = :id', [':id' => $pduId]);
    return ['added' => $added, 'removed' => $removed, 'total' => $numOutlets];
}

/**
 * Apply optional per-outlet definitions (from a PDU template) without clobbering
 * rows that already have a non-default type/label set by the user — always applies
 * on brand-new rows just inserted, or when force is true.
 *
 * @param list<array{outlet_number?:int,outlet_type?:?string,rated_amps?:?float,label?:?string}> $defs
 */
function power_apply_outlet_defs(int $pduId, array $defs, bool $force = false): int
{
    $updated = 0;
    foreach ($defs as $d) {
        if (!is_array($d)) {
            continue;
        }
        $n = (int)($d['outlet_number'] ?? 0);
        if ($n < 1) {
            continue;
        }
        $row = Database::fetchOne(
            'SELECT * FROM pdu_outlets WHERE pdu_id = ? AND outlet_number = ?',
            [$pduId, $n]
        );
        if (!$row) {
            continue;
        }
        $fields = [];
        if (array_key_exists('outlet_type', $d) && $d['outlet_type'] !== null && $d['outlet_type'] !== '') {
            if ($force || empty($row['outlet_type']) || $row['outlet_type'] === 'C13') {
                $fields['outlet_type'] = (string)$d['outlet_type'];
            }
        }
        if (array_key_exists('rated_amps', $d) && $d['rated_amps'] !== null && $d['rated_amps'] !== '') {
            if ($force || $row['rated_amps'] === null) {
                $fields['rated_amps'] = is_numeric($d['rated_amps']) ? (float)$d['rated_amps'] : null;
            }
        }
        if (array_key_exists('label', $d) && $d['label'] !== null && trim((string)$d['label']) !== '') {
            $lab = trim((string)$d['label']);
            if ($force || empty($row['label']) || $row['label'] === ('Outlet ' . $n)) {
                $fields['label'] = $lab;
            }
        }
        if ($fields) {
            Database::update(
                'pdu_outlets',
                $fields,
                'outlet_id = :id',
                [':id' => (int)$row['outlet_id']]
            );
            $updated++;
        }
    }
    return $updated;
}

/** Fields stored on a PDU inventory template (no instance-specific data). */
function power_pdu_template_static_keys(): array
{
    return [
        'manufacturer', 'model', 'pdu_scope', 'mount_style', 'u_height',
        'phases', 'phase_wiring',
        'input_voltage', 'input_voltage_ln', 'output_voltage', 'output_voltage_ln',
        'rated_volts', 'sync_zone_voltage',
        'input_type', 'rated_amps',
        'output_mode', 'num_outlets', 'num_breaker_slots', 'breaker_layout', 'breaker_columns',
        // SNMP profile (not secrets) + site OID map used by Poll now / scheduler
        'snmp_enabled', 'snmp_version', 'snmp_port', 'snmp_v3_profile_id', 'snmp_v3_sec_level',
        'snmp_site_template_id', 'snmp_auto_poll',
        'notes',
    ];
}

/**
 * Build template payload from a pdus row + optional outlets.
 *
 * @param array<string,mixed> $pdu
 * @param list<array<string,mixed>>|null $outlets
 * @return array{fields:array<string,mixed>,outlets:list<array<string,mixed>>}
 */
function power_pdu_template_payload_from_pdu(array $pdu, ?array $outlets = null): array
{
    $fields = [];
    foreach (power_pdu_template_static_keys() as $k) {
        if (!array_key_exists($k, $pdu)) {
            continue;
        }
        $v = $pdu[$k];
        if ($v === null || $v === '') {
            continue;
        }
        // Normalize ints for template ids / flags
        if (in_array($k, ['snmp_site_template_id', 'snmp_v3_profile_id', 'snmp_port', 'snmp_auto_poll', 'snmp_enabled'], true)) {
            $fields[$k] = (int)$v;
            if ($fields[$k] === 0 && in_array($k, ['snmp_site_template_id', 'snmp_v3_profile_id'], true)) {
                unset($fields[$k]);
            }
            continue;
        }
        $fields[$k] = $v;
    }
    // Never keep placement / identity / host-specific secrets
    unset(
        $fields['name'],
        $fields['serial_no'],
        $fields['ip_address'],
        $fields['snmp_community'],
        $fields['snmp_auth_passphrase'],
        $fields['snmp_priv_passphrase']
    );
    // OID map without site template is incomplete for scheduled poll — leave flag only if map present
    if (empty($fields['snmp_site_template_id'])) {
        unset($fields['snmp_auto_poll']);
    }

    $outletDefs = [];
    if (is_array($outlets)) {
        foreach ($outlets as $o) {
            $n = (int)($o['outlet_number'] ?? 0);
            if ($n < 1) {
                continue;
            }
            $def = ['outlet_number' => $n];
            if (!empty($o['outlet_type'])) {
                $def['outlet_type'] = (string)$o['outlet_type'];
            }
            if (isset($o['rated_amps']) && $o['rated_amps'] !== null && $o['rated_amps'] !== '') {
                $def['rated_amps'] = (float)$o['rated_amps'];
            }
            $lab = trim((string)($o['label'] ?? ''));
            if ($lab !== '' && $lab !== ('Outlet ' . $n)) {
                $def['label'] = $lab;
            }
            $outletDefs[] = $def;
        }
    }
    return ['fields' => $fields, 'outlets' => $outletDefs];
}

function power_pdu_template_display_name(?string $vendor, ?string $model, ?string $fallback = null): string
{
    $v = trim((string)$vendor);
    $m = trim((string)$model);
    if ($v !== '' && $m !== '') {
        return $v . '+' . $m;
    }
    if ($m !== '') {
        return $m;
    }
    if ($v !== '') {
        return $v;
    }
    return $fallback !== null && trim($fallback) !== '' ? trim($fallback) : 'PDU template';
}

/**
 * Build fields_json + outlets_json from the PDU template editor POST.
 *
 * @return array{fields:array<string,mixed>,outlets:list<array<string,mixed>>,vendor:?string,model:?string,name:string,notes:?string}
 */
function power_pdu_template_payload_from_post(array $post): array
{
    $vendor = trim((string)($post['vendor'] ?? $post['manufacturer'] ?? ''));
    $model = trim((string)($post['model'] ?? ''));
    $name = trim((string)($post['name'] ?? ''));
    if ($name === '') {
        $name = power_pdu_template_display_name($vendor, $model);
    }
    $fields = [];
    if ($vendor !== '') {
        $fields['manufacturer'] = $vendor;
    }
    if ($model !== '') {
        $fields['model'] = $model;
    }
    $boolKeys = ['snmp_enabled', 'snmp_auto_poll', 'sync_zone_voltage'];
    $intKeys = [
        'u_height', 'phases', 'input_voltage', 'input_voltage_ln', 'output_voltage', 'output_voltage_ln',
        'rated_volts', 'num_outlets', 'num_breaker_slots', 'breaker_columns', 'snmp_port',
        'snmp_v3_profile_id', 'snmp_site_template_id',
    ];
    $floatKeys = ['rated_amps'];
    $strKeys = [
        'pdu_scope', 'mount_style', 'phase_wiring', 'input_type', 'output_mode',
        'breaker_layout', 'snmp_version', 'snmp_v3_sec_level', 'notes',
    ];
    foreach ($boolKeys as $k) {
        if (array_key_exists($k, $post)) {
            $fields[$k] = !empty($post[$k]) ? 1 : 0;
        }
    }
    foreach ($intKeys as $k) {
        if (!array_key_exists($k, $post) || $post[$k] === '' || $post[$k] === null) {
            continue;
        }
        $v = (int)$post[$k];
        if ($v === 0 && in_array($k, ['snmp_site_template_id', 'snmp_v3_profile_id'], true)) {
            continue;
        }
        $fields[$k] = $v;
    }
    foreach ($floatKeys as $k) {
        if (!array_key_exists($k, $post) || $post[$k] === '' || $post[$k] === null) {
            continue;
        }
        $fields[$k] = (float)$post[$k];
    }
    foreach ($strKeys as $k) {
        if (!array_key_exists($k, $post)) {
            continue;
        }
        $v = trim((string)$post[$k]);
        if ($v === '') {
            continue;
        }
        $fields[$k] = $v;
    }
    if (!empty($fields['output_mode'])) {
        $fields['output_mode'] = power_normalize_output_mode((string)$fields['output_mode']);
    }
    if (!empty($fields['breaker_layout'])) {
        $fields['breaker_layout'] = power_normalize_breaker_layout((string)$fields['breaker_layout']);
    }
    if (isset($fields['phases'])) {
        $fields['phases'] = max(1, min(3, (int)$fields['phases']));
    }
    if (isset($fields['num_outlets'])) {
        $fields['num_outlets'] = max(0, min(128, (int)$fields['num_outlets']));
    }
    if (empty($fields['snmp_site_template_id'])) {
        unset($fields['snmp_auto_poll']);
    }

    // Outlets: parallel arrays outlet_number[], outlet_type[], rated_amps[], label[]
    $outletDefs = [];
    $nums = $post['outlet_number'] ?? null;
    if (is_array($nums)) {
        $types = is_array($post['outlet_type'] ?? null) ? $post['outlet_type'] : [];
        $amps = is_array($post['outlet_rated_amps'] ?? null) ? $post['outlet_rated_amps'] : [];
        $labs = is_array($post['outlet_label'] ?? null) ? $post['outlet_label'] : [];
        foreach ($nums as $i => $nRaw) {
            $n = (int)$nRaw;
            if ($n < 1) {
                continue;
            }
            $def = ['outlet_number' => $n];
            $t = trim((string)($types[$i] ?? ''));
            if ($t !== '') {
                $def['outlet_type'] = $t;
            }
            $a = $amps[$i] ?? '';
            if ($a !== '' && $a !== null && is_numeric($a)) {
                $def['rated_amps'] = (float)$a;
            }
            $lab = trim((string)($labs[$i] ?? ''));
            if ($lab !== '' && $lab !== ('Outlet ' . $n)) {
                $def['label'] = $lab;
            }
            $outletDefs[] = $def;
        }
        usort($outletDefs, static fn($a, $b) => $a['outlet_number'] <=> $b['outlet_number']);
        if ($outletDefs && empty($fields['num_outlets'])) {
            $fields['num_outlets'] = count($outletDefs);
        }
    }

    $notes = trim((string)($post['template_notes'] ?? $post['notes_template'] ?? ''));
    if ($notes === '' && isset($fields['notes'])) {
        // keep fields notes as PDU notes field; template-level notes separate
    }

    return [
        'fields' => $fields,
        'outlets' => $outletDefs,
        'vendor' => $vendor !== '' ? $vendor : null,
        'model' => $model !== '' ? $model : null,
        'name' => $name,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

/**
 * PDUs considered "using" this inventory template (explicit link, or legacy vendor+model match).
 *
 * @return list<array<string,mixed>>
 */
function power_pdu_template_linked_pdus(int $templateId, ?string $vendor = null, ?string $model = null): array
{
    if ($templateId < 1) {
        return [];
    }
    try {
        $rows = Database::fetchAll(
            'SELECT pdu_id, name, manufacturer, model, ip_address, pdu_template_id, is_active
             FROM pdus
             WHERE is_active = 1 AND pdu_template_id = ?
             ORDER BY name',
            [$templateId]
        );
    } catch (Throwable $e) {
        // column may not exist until Schema::ensure
        $rows = [];
    }
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['pdu_id']] = $r;
    }
    $vendor = trim((string)$vendor);
    $model = trim((string)$model);
    if ($vendor !== '' && $model !== '') {
        try {
            $legacy = Database::fetchAll(
                'SELECT pdu_id, name, manufacturer, model, ip_address, pdu_template_id, is_active
                 FROM pdus
                 WHERE is_active = 1
                   AND manufacturer = ? AND model = ?
                   AND (pdu_template_id IS NULL OR pdu_template_id = 0 OR pdu_template_id = ?)
                 ORDER BY name',
                [$vendor, $model, $templateId]
            );
            foreach ($legacy as $r) {
                $byId[(int)$r['pdu_id']] = $r;
            }
        } catch (Throwable $e) {
            try {
                $legacy = Database::fetchAll(
                    'SELECT pdu_id, name, manufacturer, model, ip_address, is_active
                     FROM pdus
                     WHERE is_active = 1 AND manufacturer = ? AND model = ?
                     ORDER BY name',
                    [$vendor, $model]
                );
                foreach ($legacy as $r) {
                    $byId[(int)$r['pdu_id']] = $r;
                }
            } catch (Throwable $e2) {
                // ignore
            }
        }
    }
    return array_values($byId);
}

/**
 * Apply template fields to a PDU row (never touches name, serial, IP, placement, secrets).
 *
 * @param array<string,mixed> $fields
 * @param list<array<string,mixed>> $outletDefs
 * @return array{updated:bool,outlets:int}
 */
function power_pdu_template_apply_to_pdu(
    int $pduId,
    int $templateId,
    array $fields,
    array $outletDefs = [],
    bool $forceOutlets = true
): array {
    if ($pduId < 1) {
        return ['updated' => false, 'outlets' => 0];
    }
    $allowed = array_flip(power_pdu_template_static_keys());
    $row = [];
    foreach ($fields as $k => $v) {
        if (!isset($allowed[$k])) {
            continue;
        }
        if (in_array($k, ['name', 'serial_no', 'ip_address', 'cabinet_id', 'row_id', 'zone_id', 'position_u'], true)) {
            continue;
        }
        $row[$k] = $v;
    }
    $row['pdu_template_id'] = $templateId;
    if (isset($row['manufacturer']) && $row['manufacturer'] === '') {
        $row['manufacturer'] = null;
    }
    if (isset($row['model']) && $row['model'] === '') {
        $row['model'] = null;
    }
    if (array_key_exists('snmp_site_template_id', $row)) {
        $tid = (int)$row['snmp_site_template_id'];
        $row['snmp_site_template_id'] = $tid > 0 ? $tid : null;
        if ($tid > 0) {
            $row['snmp_enabled'] = 1;
        }
    }
    if (array_key_exists('snmp_v3_profile_id', $row)) {
        $pid = (int)$row['snmp_v3_profile_id'];
        $row['snmp_v3_profile_id'] = $pid > 0 ? $pid : null;
    }
    if (isset($row['output_mode'])) {
        $row['output_mode'] = power_normalize_output_mode((string)$row['output_mode']);
    }
    if (isset($row['breaker_layout'])) {
        $row['breaker_layout'] = power_normalize_breaker_layout((string)$row['breaker_layout']);
    }

    $updated = false;
    if ($row) {
        try {
            Database::update('pdus', $row, 'pdu_id = :id', [':id' => $pduId]);
            $updated = true;
        } catch (Throwable $e) {
            // Retry without pdu_template_id if column missing
            unset($row['pdu_template_id']);
            if ($row) {
                Database::update('pdus', $row, 'pdu_id = :id', [':id' => $pduId]);
                $updated = true;
            }
        }
    }

    $outletN = 0;
    $mode = power_normalize_output_mode((string)($fields['output_mode'] ?? 'outlets'));
    if ($mode === 'outlets') {
        $num = (int)($fields['num_outlets'] ?? 0);
        if ($num < 1 && $outletDefs) {
            $num = count($outletDefs);
        }
        if ($num > 0) {
            power_sync_outlet_inventory($pduId, $num, 'C13', null);
            if ($outletDefs) {
                $outletN = power_apply_outlet_defs($pduId, $outletDefs, $forceOutlets);
            }
        }
    }
    return ['updated' => $updated, 'outlets' => $outletN];
}
