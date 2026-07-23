<?php
/**
 * Shared power infrastructure helpers (zones / PDUs)
 */
declare(strict_types=1);

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
