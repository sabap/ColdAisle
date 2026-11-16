<?php
/**
 * One executive brief per catalog report. Stored data only. No device polls.
 */
declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function report_exec_brief(string $key): array
{
    try {
        return match ($key) {
            'power_history' => report_exec_brief_power_history(),
            'power_path' => report_exec_brief_power_path(),
            'inventory_summary' => report_exec_brief_inventory(),
            'cabinet_utilization' => report_exec_brief_cabinets(),
            'power_capacity' => report_exec_brief_capacity(),
            'cooling' => report_exec_brief_cooling(),
            'temperature' => report_exec_brief_temperature(),
            'humidity' => report_exec_brief_humidity(),
            'warranty_expiration' => report_exec_brief_warranty(),
            'disposal_queue' => report_exec_brief_disposal(),
            'cable_inventory' => report_exec_brief_cables(),
            'orphaned_devices' => report_exec_brief_orphans(),
            'work_orders' => report_exec_brief_work_orders(),
            'audit_history' => report_exec_brief_audits(),
            default => report_exec_pack('Executive', 'This brief could not be built.', '', [], [], [], '', ''),
        };
    } catch (Throwable $e) {
        if (class_exists('App')) {
            App::log('Executive brief failed for ' . $key, 'error');
        }
        return report_exec_pack(
            'Executive',
            'This brief could not be built.',
            '',
            [report_exec_card('Status', '—', 'Open the detail report', 'warning')],
            [],
            [],
            '',
            ''
        );
    }
}

/**
 * @param list<array<string,mixed>> $kpis
 * @param list<array<string,mixed>> $charts
 * @param array<string,mixed> $table
 * @return array<string,mixed>
 */
function report_exec_pack(
    string $title,
    string $lead,
    string $context,
    array $kpis,
    array $charts,
    array $table,
    string $footer,
    string $note = ''
): array {
    $org = '';
    if (class_exists('App')) {
        $org = trim((string)App::config('org_name', ''));
    }
    $clean = [];
    foreach ($kpis as $kpi) {
        if (!is_array($kpi)) {
            continue;
        }
        $clean[] = [
            'label' => (string)($kpi['label'] ?? ''),
            'value' => (string)($kpi['value'] ?? '—'),
            'sub' => (string)($kpi['sub'] ?? ''),
            'tone' => (string)($kpi['tone'] ?? 'accent'),
        ];
        if (count($clean) >= 4) {
            break;
        }
    }
    return [
        'title' => $title,
        'lead' => $lead,
        'context' => $context,
        'kpis' => $clean,
        'charts' => array_values($charts),
        'table' => $table,
        'footer_note' => $footer,
        'note' => $note,
        'generated' => date('M j, Y g:i A'),
        'org' => $org,
        'symbol' => class_exists('TempUnitService') ? TempUnitService::symbol() : '°C',
        'has_samples' => true,
    ];
}

/** @return array{label:string,value:string,sub:string,tone:string} */
function report_exec_card(string $label, string $value, string $sub, string $tone = 'accent'): array
{
    return ['label' => $label, 'value' => $value, 'sub' => $sub, 'tone' => $tone];
}

/** @param list<string> $bits */
function report_exec_sentences(array $bits): string
{
    $clean = [];
    foreach ($bits as $bit) {
        $bit = trim($bit);
        if ($bit !== '') {
            $clean[] = $bit;
        }
    }
    if ($clean === []) {
        return '';
    }
    $text = ucfirst($clean[0]);
    for ($i = 1, $c = count($clean); $i < $c; $i++) {
        $text .= '. ' . ucfirst($clean[$i]);
    }
    return rtrim($text, '.') . '.';
}

function report_exec_label(string $text, string $fallback = 'Unspecified'): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    return $text === '' ? $fallback : $text;
}

function report_exec_status_label(string $status): string
{
    $status = trim(str_replace('_', ' ', $status));
    return $status === '' ? 'Unspecified' : ucfirst($status);
}

function report_exec_int(int|float $n): string
{
    return number_format((float)$n, 0, '.', ',');
}

function report_exec_kw(?float $v): string
{
    return $v === null ? '—' : (number_format($v, 2, '.', ',') . ' kW');
}

function report_exec_days_label(int $days): string
{
    if ($days < 0) {
        return abs($days) . 'd overdue';
    }
    if ($days === 0) {
        return 'Today';
    }
    return $days . 'd left';
}

/** @return list<string> */
function report_exec_palette(): array
{
    return ['#0ea5e9', '#f97316', '#6366f1', '#10b981', '#c026d3', '#eab308', '#64748b', '#ef4444', '#38bdf8', '#fb7185', '#a3e635', '#f59e0b'];
}

/**
 * @param list<array{0:string,1:int|float,2?:string}> $pairs
 * @return array<string,mixed>
 */
function report_exec_count_chart(string $title, array $pairs, string $seriesLabel = 'Count', string $empty = 'Nothing to chart yet'): array
{
    $pairs = array_slice(array_values($pairs), 0, 12);
    $palette = report_exec_palette();
    $labels = [];
    $points = [];
    $colors = [];
    foreach ($pairs as $i => $pair) {
        $labels[] = (string)$pair[0];
        $points[] = (float)$pair[1];
        $colors[] = (isset($pair[2]) && is_string($pair[2]) && $pair[2] !== '')
            ? $pair[2]
            : $palette[$i % count($palette)];
    }
    return [
        'kind' => 'bar',
        'title' => $title,
        'unit' => '',
        'empty' => $empty,
        'labels' => $labels,
        'series' => $labels === [] ? [] : [[
            'key' => 'count',
            'label' => $seriesLabel,
            'color' => $palette[0],
            'colors' => $colors,
            'points' => $points,
        ]],
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{0:string,1:int}>
 */
function report_exec_tally(array $rows, callable $labelOf): array
{
    $counts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = report_exec_label((string)$labelOf($row));
        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }
    arsort($counts);
    $pairs = [];
    foreach ($counts as $label => $count) {
        $pairs[] = [$label, $count];
    }
    return $pairs;
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows
 * @return array{title:string,headers:list<string>,rows:list<list<string>>,note:string}
 */
function report_exec_table(string $title, array $headers, array $rows, string $note = ''): array
{
    $headers = array_slice(array_values(array_map('strval', $headers)), 0, 5);
    $width = count($headers);
    $clean = [];
    if ($width > 0) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach (array_slice(array_values($row), 0, $width) as $cell) {
                $cells[] = trim((string)$cell);
            }
            while (count($cells) < $width) {
                $cells[] = '';
            }
            $clean[] = $cells;
        }
    }
    return ['title' => $title, 'headers' => $headers, 'rows' => $clean, 'note' => $note];
}

/**
 * @param list<string> $labels
 * @param list<float|null> $points
 * @return array{0:list<string>,1:list<float|null>}
 */
function report_exec_downsample(array $labels, array $points, int $max): array
{
    $n = count($points);
    if ($n <= $max || $max < 2) {
        return [$labels, $points];
    }
    $step = (int)ceil(($n - 1) / ($max - 1));
    if ($step < 1) {
        $step = 1;
    }
    $outL = [];
    $outP = [];
    for ($i = 0; $i < $n; $i += $step) {
        $outL[] = $labels[$i] ?? '';
        $outP[] = $points[$i] ?? null;
    }
    $last = $n - 1;
    if (($last % $step) !== 0) {
        $outL[] = $labels[$last] ?? '';
        $outP[] = $points[$last] ?? null;
    }
    return [$outL, $outP];
}

function report_exec_chart_has_points(array $chart): bool
{
    foreach ($chart['series'] ?? [] as $series) {
        if (!is_array($series)) {
            continue;
        }
        foreach ($series['points'] ?? [] as $point) {
            if ($point !== null && is_numeric($point)) {
                return true;
            }
        }
    }
    return false;
}

function report_exec_power_scope_label(string $scope, ?int $id): string
{
    try {
        if ($scope === 'zone' && $id) {
            $row = Database::fetchOne('SELECT name FROM power_zones WHERE zone_id = ?', [$id]);
            $name = trim((string)($row['name'] ?? ''));
            return $name !== '' ? $name : 'Selected zone';
        }
        if ($scope === 'pdu' && $id) {
            $row = Database::fetchOne('SELECT name FROM pdus WHERE pdu_id = ?', [$id]);
            $name = trim((string)($row['name'] ?? ''));
            return $name !== '' ? $name : 'Selected PDU';
        }
    } catch (Throwable $e) {
        if ($scope === 'pdu') {
            return 'Selected PDU';
        }
        if ($scope === 'zone') {
            return 'Selected zone';
        }
    }
    return 'Entire site';
}

/** @return array<string,mixed> */
function report_exec_brief_power_history(): array
{
    $preset = strtolower(trim((string)($_GET['preset'] ?? 'week')));
    if (!in_array($preset, ['week', 'month', 'year', 'custom'], true)) {
        $preset = 'week';
    }
    $scope = strtolower(trim((string)($_GET['scope'] ?? 'site')));
    if (!in_array($scope, ['site', 'zone', 'pdu'], true)) {
        $scope = 'site';
    }
    $id = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    if ($preset === 'custom' && ($from === '' || $to === '')) {
        $from = $from !== '' ? $from : date('Y-m-d', strtotime('-7 days'));
        $to = $to !== '' ? $to : date('Y-m-d');
    }
    $range = match ($preset) {
        'month' => 'Last 31 days',
        'year' => 'Last 365 days',
        'custom' => $from . ' to ' . $to,
        default => 'Last 7 days',
    };
    $context = $range . ' · ' . report_exec_power_scope_label($scope, $id);
    $empty = report_exec_pack(
        'Executive · Power history',
        'Power history is not available on this install.',
        $context,
        [],
        [report_exec_count_chart('Load', [], 'Load', 'No samples in this period')],
        [],
        '',
        'Historical load comes from stored SNMP samples.'
    );
    if (!class_exists('PowerHistoryService')) {
        return $empty;
    }
    $hours = match ($preset) {
        'month' => 24 * 31,
        'year' => 24 * 365,
        default => 24 * 7,
    };
    $data = PowerHistoryService::series(
        $scope,
        $id,
        $hours,
        $preset === 'custom' ? $from : null,
        $preset === 'custom' ? $to : null
    );
    $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
    $kw = is_array($summary['kw'] ?? null) ? $summary['kw'] : [];
    $volts = is_array($summary['volts'] ?? null) ? $summary['volts'] : [];
    $peak = isset($kw['max']) && is_numeric($kw['max']) ? (float)$kw['max'] : null;
    $avg = isset($kw['avg']) && is_numeric($kw['avg']) ? (float)$kw['avg'] : null;
    $vmin = isset($volts['min']) && is_numeric($volts['min']) ? (float)$volts['min'] : null;
    $vmax = isset($volts['max']) && is_numeric($volts['max']) ? (float)$volts['max'] : null;
    $vavg = isset($volts['avg']) && is_numeric($volts['avg']) ? (float)$volts['avg'] : null;
    $outages = (int)($summary['outage_events'] ?? 0);
    $voltSub = ($vmin === null && $vmax === null)
        ? 'No voltage samples'
        : ('Peak ' . ($vmax === null ? '—' : number_format($vmax, 0)) . ' V · min ' . ($vmin === null ? '—' : number_format($vmin, 0)) . ' V');
    $kpis = [
        report_exec_card('Peak load', report_exec_kw($peak), 'Highest bucket', 'warning'),
        report_exec_card('Average load', report_exec_kw($avg), 'Across buckets', 'accent'),
        report_exec_card('Voltage', $vavg === null ? '—' : (number_format($vavg, 0) . ' V'), $voltSub, 'accent'),
        report_exec_card('Outages', (string)$outages, 'Phase dead, low voltage, or overload', $outages > 0 ? 'danger' : 'success'),
    ];
    $series = is_array($data['series'] ?? null) ? $data['series'] : [];
    $times = is_array($series['t'] ?? null) ? array_values($series['t']) : [];
    $kwPoints = is_array($series['kw'] ?? null) ? array_values($series['kw']) : [];
    $n = min(count($times), count($kwPoints));
    $labels = [];
    $points = [];
    $dayLabels = $preset === 'year' || $preset === 'month';
    for ($i = 0; $i < $n; $i++) {
        $ts = strtotime((string)$times[$i]);
        $labels[] = $ts ? date($dayLabels ? 'M j' : 'M j ga', $ts) : (string)$times[$i];
        $points[] = is_numeric($kwPoints[$i]) ? round((float)$kwPoints[$i], 3) : null;
    }
    [$labels, $points] = report_exec_downsample($labels, $points, 60);
    $chart = [
        'kind' => 'line',
        'title' => 'Load',
        'unit' => 'kW',
        'empty' => 'No samples in this period',
        'labels' => $labels,
        'series' => [[
            'key' => 'kw',
            'label' => 'Load',
            'color' => '#0ea5e9',
            'points' => $points,
        ]],
    ];
    $bits = [];
    if ($peak !== null) {
        $bits[] = 'peak load is ' . report_exec_kw($peak);
    }
    if ($avg !== null) {
        $bits[] = 'average load is ' . report_exec_kw($avg);
    }
    if ($vavg !== null) {
        $bits[] = 'input voltage averages ' . number_format($vavg, 0) . ' V';
    }
    if ($outages > 0) {
        $bits[] = $outages . ' outage event' . ($outages === 1 ? ' is' : 's are') . ' in this range';
    }
    if ($bits === []) {
        $bits[] = 'no power samples in this range';
    }
    return report_exec_pack(
        'Executive · Power history',
        report_exec_sentences($bits),
        $context,
        $kpis,
        [$chart],
        [],
        '',
        'Load is the stored SNMP sample for this scope. Voltage is the average in the same buckets.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_power_path(): array
{
    $zoneId = isset($_GET['zone_id']) && $_GET['zone_id'] !== '' ? (int)$_GET['zone_id'] : 0;
    $context = 'All zones';
    if ($zoneId > 0) {
        try {
            $row = Database::fetchOne('SELECT name FROM power_zones WHERE zone_id = ?', [$zoneId]);
            $name = trim((string)($row['name'] ?? ''));
            $context = $name !== '' ? $name : 'Selected zone';
        } catch (Throwable $e) {
            $context = 'Selected zone';
        }
    }
    if (!class_exists('PowerPathService')) {
        return report_exec_pack('Executive · Power path', 'Power path is not available on this install.', $context, [], [report_exec_count_chart('Path counts', [])], [], '', '');
    }
    $data = PowerPathService::report(['zone_id' => $zoneId, 'view' => 'all']);
    $s = is_array($data['summary'] ?? null) ? $data['summary'] : [];
    $mapped = (int)($s['mapped'] ?? 0);
    $unmapped = (int)($s['unmapped_psus'] ?? 0);
    $half = (int)($s['half_maps'] ?? 0);
    $single = (int)($s['single_feed_devices'] ?? 0);
    $cabinets = (int)($s['cabinets_no_row_feed'] ?? 0);
    $ups = (int)($s['ups_no_zone'] ?? 0);
    $paths = (int)($s['path_rows'] ?? 0);
    $pairs = [
        ['Mapped', $mapped],
        ['Unmapped PSUs', $unmapped],
        ['Half maps', $half],
        ['Single-feed', $single],
        ['No row feed', $cabinets],
        ['UPS without zone', $ups],
    ];
    $bits = [];
    if ($paths > 0) {
        $bits[] = $mapped . ' of ' . $paths . ' PSU lines are mapped';
    }
    if ($unmapped > 0) {
        $bits[] = $unmapped . ' PSU' . ($unmapped === 1 ? ' has' : 's have') . ' no outlet';
    }
    if ($single > 0) {
        $bits[] = $single . ' device' . ($single === 1 ? ' is' : 's are') . ' on a single feed';
    }
    if ($cabinets > 0) {
        $bits[] = $cabinets . ' cabinet' . ($cabinets === 1 ? ' has' : 's have') . ' no row or room feed';
    }
    if ($bits === []) {
        $bits[] = 'no power-supply paths are recorded yet';
    }
    return report_exec_pack(
        'Executive · Power path',
        report_exec_sentences($bits),
        $context,
        [
            report_exec_card('Mapped', (string)$mapped, 'Of ' . $paths . ' PSU lines', 'success'),
            report_exec_card('Unmapped PSUs', (string)$unmapped, 'No outlet map', $unmapped > 0 ? 'warning' : 'success'),
            report_exec_card('Single-feed', (string)$single, 'Devices', $single > 0 ? 'warning' : 'success'),
            report_exec_card('No row feed', (string)$cabinets, 'Cabinets', $cabinets > 0 ? 'warning' : 'success'),
        ],
        [report_exec_count_chart('Path counts', $pairs)],
        [],
        '',
        'Counts follow the power-path inventory. The detail report can filter to one kind of gap.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_inventory(): array
{
    $data = report_inventory_summary();
    $byType = is_array($data['by_type'] ?? null) ? $data['by_type'] : [];
    $byStatus = is_array($data['by_status'] ?? null) ? $data['by_status'] : [];
    $pairs = [];
    $total = 0;
    foreach ($byType as $row) {
        if (!is_array($row)) {
            continue;
        }
        $n = (int)($row['cnt'] ?? 0);
        $total += $n;
        $pairs[] = [report_exec_label((string)($row['device_type'] ?? '')), $n];
    }
    $top = $pairs[0] ?? null;
    $topStatus = null;
    foreach ($byStatus as $row) {
        if (is_array($row)) {
            $topStatus = $row;
            break;
        }
    }
    $statusSub = 'None';
    if (is_array($topStatus)) {
        $statusSub = report_exec_label((string)($topStatus['status'] ?? '')) . ' · ' . (int)($topStatus['cnt'] ?? 0);
    }
    $bits = [];
    if ($total > 0) {
        $bits[] = report_exec_int($total) . ' active device' . ($total === 1 ? '' : 's') . ' across ' . count($pairs) . ' type' . (count($pairs) === 1 ? '' : 's');
        if ($top) {
            $bits[] = (string)$top[0] . ' is the largest group, with ' . report_exec_int((int)$top[1]);
        }
    } else {
        $bits[] = 'no active devices yet';
    }
    $footer = count($pairs) > 12 ? 'The graph shows the 12 largest types.' : '';
    return report_exec_pack(
        'Executive · Inventory',
        report_exec_sentences($bits),
        'Active devices',
        [
            report_exec_card('Devices', report_exec_int($total), 'Active', 'accent'),
            report_exec_card('Types', (string)count($pairs), $top ? ((string)$top[0] . ' leads') : 'None yet', 'accent'),
            report_exec_card('Largest type', $top ? report_exec_int((int)$top[1]) : '—', $top ? (string)$top[0] : 'No devices', 'success'),
            report_exec_card('Statuses', (string)count($byStatus), $statusSub, 'accent'),
        ],
        [report_exec_count_chart('Devices by type', $pairs, 'Devices')],
        [],
        $footer,
        'Type counts use active devices. The detail report also splits them by status and data center.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_cabinets(): array
{
    $rows = report_cabinet_utilization();
    $items = [];
    $freeU = 0;
    $over = 0;
    $pcts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $height = (int)($row['u_height'] ?? 0);
        $used = (int)($row['u_used'] ?? 0);
        $freeU += max(0, $height - $used);
        $pct = $height > 0 ? round(100 * $used / $height, 1) : null;
        if ($pct !== null) {
            $pcts[] = $pct;
            if ($pct > 85) {
                $over++;
            }
        }
        $room = trim((string)($row['dc_name'] ?? ''));
        $roomName = trim((string)($row['room_name'] ?? ''));
        if ($roomName !== '') {
            $room = $room !== '' ? ($room . ' / ' . $roomName) : $roomName;
        }
        $items[] = [
            'name' => report_exec_label((string)($row['name'] ?? ''), 'Cabinet'),
            'room' => $room,
            'pct' => $pct,
            'free' => max(0, $height - $used),
        ];
    }
    $names = [];
    foreach ($items as $item) {
        $names[$item['name']] = ($names[$item['name']] ?? 0) + 1;
    }
    $chartRows = [];
    foreach ($items as $item) {
        if ($item['pct'] !== null) {
            $chartRows[] = $item;
        }
    }
    usort($chartRows, static function (array $a, array $b): int {
        $cmp = ((float)$b['pct']) <=> ((float)$a['pct']);
        return $cmp !== 0 ? $cmp : strcasecmp((string)$a['name'], (string)$b['name']);
    });
    $chartRows = array_slice($chartRows, 0, 12);
    $pairs = [];
    $tableRows = [];
    foreach ($chartRows as $item) {
        $label = (string)$item['name'];
        if (($names[$label] ?? 0) > 1 && $item['room'] !== '') {
            $label .= ' (' . $item['room'] . ')';
        }
        $pct = (float)$item['pct'];
        $color = $pct > 85 ? '#ef4444' : ($pct >= 60 ? '#f97316' : '#0ea5e9');
        $pairs[] = [$label, $pct, $color];
        $tableRows[] = [
            (string)$item['name'],
            $item['room'] !== '' ? (string)$item['room'] : '—',
            number_format($pct, 1) . '%',
            (string)$item['free'] . ' U',
        ];
    }
    $avg = $pcts === [] ? null : (array_sum($pcts) / count($pcts));
    $count = count($items);
    $bits = [];
    if ($count > 0) {
        $bits[] = $count . ' cabinet' . ($count === 1 ? ' is' : 's are') . ' active';
        if ($avg !== null) {
            $bits[] = 'average used space is ' . number_format($avg, 1) . '%';
        }
        if ($over > 0) {
            $bits[] = $over . ' cabinet' . ($over === 1 ? ' is' : 's are') . ' over 85%';
        }
        $bits[] = report_exec_int($freeU) . ' U are free';
    } else {
        $bits[] = 'no active cabinets yet';
    }
    $footer = '';
    if (count($pcts) > 12) {
        $footer = 'The graph and the list show the 12 fullest cabinets.';
    }
    return report_exec_pack(
        'Executive · Cabinet utilization',
        report_exec_sentences($bits),
        'Active cabinets',
        [
            report_exec_card('Cabinets', report_exec_int($count), 'Active', 'accent'),
            report_exec_card('Average full', $avg === null ? '—' : (number_format($avg, 1) . '%'), 'Used U', 'accent'),
            report_exec_card('Over 85%', (string)$over, 'Cabinets', $over > 0 ? 'danger' : 'success'),
            report_exec_card('Free U', report_exec_int($freeU), 'Across cabinets', 'success'),
        ],
        [report_exec_count_chart('Fullest cabinets', $pairs, 'Used %', 'No cabinet heights yet')],
        report_exec_table('Fullest cabinets', ['Cabinet', 'Location', 'Used', 'Free'], $tableRows),
        $footer,
        'Percent used is occupied U divided by cabinet height. The detail report lists every cabinet.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_capacity(): array
{
    $data = report_power_capacity(0, 0);
    $zones = is_array($data['zones'] ?? null) ? $data['zones'] : [];
    $items = [];
    $load = 0.0;
    $free = 0.0;
    $freeN = 0;
    $imbalanced = 0;
    foreach ($zones as $z) {
        if (!is_array($z)) {
            continue;
        }
        $loadKw = isset($z['load_kw']) && is_numeric($z['load_kw']) ? (float)$z['load_kw'] : 0.0;
        $freeKw = isset($z['free_kw']) && is_numeric($z['free_kw']) ? (float)$z['free_kw'] : null;
        $util = isset($z['util_pct']) && is_numeric($z['util_pct']) ? (float)$z['util_pct'] : null;
        $load += $loadKw;
        if ($freeKw !== null) {
            $free += $freeKw;
            $freeN++;
        }
        if (!empty($z['imbalanced'])) {
            $imbalanced++;
        }
        $items[] = [
            'name' => report_exec_label((string)($z['name'] ?? ''), 'Zone'),
            'load' => $loadKw,
            'free' => $freeKw,
            'util' => $util,
            'imbalanced' => !empty($z['imbalanced']),
        ];
    }
    $withUtil = array_values(array_filter($items, static fn(array $item): bool => $item['util'] !== null));
    $useUtil = $withUtil !== [];
    $chartSource = $useUtil ? $withUtil : $items;
    usort($chartSource, static function (array $a, array $b) use ($useUtil): int {
        $av = $useUtil ? (float)$a['util'] : (float)$a['load'];
        $bv = $useUtil ? (float)$b['util'] : (float)$b['load'];
        return $bv <=> $av;
    });
    $chartSource = array_slice($chartSource, 0, 12);
    $pairs = [];
    $tableRows = [];
    foreach ($chartSource as $item) {
        if ($useUtil) {
            $util = (float)$item['util'];
            $color = $util >= 85 ? '#ef4444' : ($util >= 60 ? '#f97316' : '#0ea5e9');
            $pairs[] = [(string)$item['name'], $util, $color];
        } else {
            $pairs[] = [(string)$item['name'], (float)$item['load']];
        }
        $tableRows[] = [
            (string)$item['name'],
            number_format((float)$item['load'], 1) . ' kW',
            $item['free'] === null ? '—' : (number_format((float)$item['free'], 1) . ' kW'),
            $item['util'] === null ? '—' : (number_format((float)$item['util'], 1) . '%'),
            $item['imbalanced'] ? 'Yes' : '',
        ];
    }
    $chart = report_exec_count_chart(
        $useUtil ? 'Utilization' : 'Load',
        $pairs,
        $useUtil ? 'Utilization' : 'Load',
        'No power zones yet'
    );
    $chart['unit'] = $useUtil ? '%' : 'kW';
    $bits = [];
    if ($items !== []) {
        $bits[] = count($items) . ' zone' . (count($items) === 1 ? ' carries' : 's carry') . ' ' . number_format($load, 1) . ' kW';
        if ($freeN > 0) {
            $bits[] = number_format($free, 1) . ' kW is free where a maximum is set';
        }
        if ($imbalanced > 0) {
            $bits[] = $imbalanced . ' zone' . ($imbalanced === 1 ? ' is' : 's are') . ' imbalanced';
        }
    } else {
        $bits[] = 'no power zones yet';
    }
    $footer = '';
    if ($useUtil && count($withUtil) < count($items)) {
        $footer = 'Zones without a maximum are left off the utilization graph.';
    } elseif (!$useUtil && $items !== []) {
        $footer = 'No zone has a maximum, so the graph shows load.';
    }
    if (count($useUtil ? $withUtil : $items) > 12) {
        $footer = trim($footer . ' The graph shows the 12 highest zones.');
    }
    return report_exec_pack(
        'Executive · Power capacity',
        report_exec_sentences($bits),
        'All zones',
        [
            report_exec_card('Zones', (string)count($items), 'In the plant', 'accent'),
            report_exec_card('Load', number_format($load, 1) . ' kW', 'Rollup', 'accent'),
            report_exec_card('Free', $freeN > 0 ? (number_format($free, 1) . ' kW') : '—', $freeN > 0 ? 'Where a maximum is set' : 'No zone maximum', 'success'),
            report_exec_card('Imbalanced', (string)$imbalanced, 'Phase spread at least 20%', $imbalanced > 0 ? 'warning' : 'success'),
        ],
        [$chart],
        report_exec_table('Zones', ['Zone', 'Load', 'Free', 'Used', 'Imbalance'], $tableRows, $footer),
        $footer,
        'Headroom is the zone maximum minus the rollup load. Imbalance is the phase spread used on the detail report.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_cooling(): array
{
    $rows = [];
    try {
        $rows = Database::fetchAll(
            'SELECT u.name, u.last_poll_json, rm.name AS room_name
             FROM cooling_units u
             LEFT JOIN rooms rm ON rm.room_id = u.room_id
             WHERE u.is_active = 1
             ORDER BY u.name'
        );
    } catch (Throwable $e) {
        $rows = [];
    }
    $symbol = class_exists('TempUnitService') ? TempUnitService::symbol() : '°C';
    $supplyVals = [];
    $returnVals = [];
    $humVals = [];
    $plotted = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !function_exists('cooling_poll_snapshot_promote')) {
            continue;
        }
        $snap = cooling_poll_snapshot_promote($row['last_poll_json'] ?? null);
        $supply = isset($snap['supply_temp']) && is_numeric($snap['supply_temp']) ? (float)$snap['supply_temp'] : null;
        $return = isset($snap['return_temp']) && is_numeric($snap['return_temp']) ? (float)$snap['return_temp'] : null;
        $hum = isset($snap['humidity']) && is_numeric($snap['humidity']) ? (float)$snap['humidity'] : null;
        if ($supply !== null && !report_exec_temp_ok($supply)) {
            $supply = null;
        }
        if ($return !== null && !report_exec_temp_ok($return)) {
            $return = null;
        }
        if ($hum !== null && !report_exec_rh_ok($hum)) {
            $hum = null;
        }
        if ($supply !== null) {
            $supplyVals[] = $supply;
        }
        if ($return !== null) {
            $returnVals[] = $return;
        }
        if ($hum !== null) {
            $humVals[] = $hum;
        }
        if ($supply !== null || $return !== null) {
            $plotted[] = [
                'name' => report_exec_label((string)($row['name'] ?? ''), 'Unit'),
                'room' => trim((string)($row['room_name'] ?? '')),
                'supply' => $supply,
                'return' => $return,
                'humidity' => $hum,
            ];
        }
    }
    $withReading = count($plotted);
    $shown = array_slice($plotted, 0, 8);
    $labels = [];
    $sPoints = [];
    $rPoints = [];
    $tableRows = [];
    $anyS = false;
    $anyR = false;
    foreach ($shown as $unit) {
        $labels[] = (string)$unit['name'];
        $sDisp = $unit['supply'] !== null ? report_exec_to_display((float)$unit['supply']) : null;
        $rDisp = $unit['return'] !== null ? report_exec_to_display((float)$unit['return']) : null;
        if ($sDisp !== null) {
            $anyS = true;
        }
        if ($rDisp !== null) {
            $anyR = true;
        }
        $sPoints[] = $sDisp;
        $rPoints[] = $rDisp;
        $tableRows[] = [
            (string)$unit['name'],
            $unit['room'] !== '' ? (string)$unit['room'] : '—',
            $unit['supply'] !== null ? report_fmt_temp($unit['supply']) : '—',
            $unit['return'] !== null ? report_fmt_temp($unit['return']) : '—',
            $unit['humidity'] !== null ? report_fmt_rh($unit['humidity']) : '—',
        ];
    }
    $series = [];
    if ($anyS) {
        $series[] = ['key' => 'supply', 'label' => 'Supply', 'color' => '#6366f1', 'points' => $sPoints];
    }
    if ($anyR) {
        $series[] = ['key' => 'return', 'label' => 'Return', 'color' => '#c026d3', 'points' => $rPoints];
    }
    $title = ($anyS && $anyR) ? 'Supply and return' : ($anyR ? 'Return' : 'Supply');
    $chart = [
        'kind' => 'bar',
        'title' => $title,
        'unit' => $symbol,
        'empty' => 'No supply or return temperatures yet',
        'labels' => $labels,
        'series' => $series,
    ];
    $avg = static function (array $vals): ?float {
        return $vals === [] ? null : (array_sum($vals) / count($vals));
    };
    $sAvg = $avg($supplyVals);
    $rAvg = $avg($returnVals);
    $hAvg = $avg($humVals);
    $count = count($rows);
    $bits = [];
    if ($count > 0) {
        $bits[] = $count . ' cooling unit' . ($count === 1 ? ' is' : 's are') . ' active';
    } else {
        $bits[] = 'no active cooling units yet';
    }
    if ($sAvg !== null) {
        $bits[] = 'average supply is ' . report_fmt_temp($sAvg);
    }
    if ($rAvg !== null) {
        $bits[] = 'average return is ' . report_fmt_temp($rAvg);
    }
    if ($hAvg !== null) {
        $bits[] = 'average humidity is ' . report_fmt_rh($hAvg);
    }
    $footer = $withReading > 8 ? 'The graph shows 8 units with a supply or return snapshot.' : '';
    return report_exec_pack(
        'Executive · Cooling',
        report_exec_sentences($bits),
        'Latest snapshot',
        [
            report_exec_card('Units', (string)$count, $withReading . ' with supply or return', 'accent'),
            report_exec_card('Supply', $sAvg === null ? '—' : report_fmt_temp($sAvg), 'Average now', 'accent'),
            report_exec_card('Return', $rAvg === null ? '—' : report_fmt_temp($rAvg), 'Average now', 'warning'),
            report_exec_card('Humidity', $hAvg === null ? '—' : report_fmt_rh($hAvg), 'Average now', 'success'),
        ],
        [$chart],
        report_exec_table('Cooling units', ['Unit', 'Room', 'Supply', 'Return', 'Humidity'], $tableRows),
        $footer,
        'Temperatures use the site unit. Values are the latest stored snapshot on each unit.'
    );
}

/**
 * @param array<string,mixed> $src
 * @param list<array<string,mixed>> $exceptions
 * @return list<list<string>>
 */
function report_exec_exception_rows(array $exceptions): array
{
    $rows = [];
    foreach ($exceptions as $ex) {
        if (!is_array($ex)) {
            continue;
        }
        $status = (string)($ex['status'] ?? '');
        $rows[] = [
            $status === 'crit' ? 'Crit' : 'Warn',
            (string)($ex['name'] ?? ''),
            trim((string)($ex['room'] ?? '')) !== '' ? (string)$ex['room'] : '—',
            trim((string)($ex['point'] ?? '')) !== '' ? (string)$ex['point'] : '—',
            trim((string)($ex['current'] ?? '')) !== '' ? (string)$ex['current'] : '—',
        ];
    }
    return $rows;
}

/** @return array<string,mixed> */
function report_exec_brief_temperature(): array
{
    $src = report_executive();
    $exceptions = [];
    foreach ($src['exceptions'] ?? [] as $ex) {
        if (is_array($ex) && (string)($ex['kind'] ?? '') !== 'humidity') {
            $exceptions[] = $ex;
        }
    }
    $outside = count($exceptions);
    $crit = 0;
    $warn = 0;
    foreach ($exceptions as $ex) {
        if (($ex['status'] ?? '') === 'crit') {
            $crit++;
        } elseif (($ex['status'] ?? '') === 'warn') {
            $warn++;
        }
    }
    $kpis = is_array($src['kpis'] ?? null) ? $src['kpis'] : [];
    $supply = $src['supply_now'] ?? null;
    $return = $src['return_now'] ?? null;
    $cards = [
        $kpis[0] ?? report_exec_card('Cold aisle', '—', 'No sensors', 'accent'),
        $kpis[1] ?? report_exec_card('Hot aisle', '—', 'No sensors', 'warning'),
        report_exec_card(
            'Outside limits',
            (string)$outside,
            $outside === 0 ? 'Within limits' : ($crit . ' crit, ' . $warn . ' warn'),
            $crit > 0 ? 'danger' : ($warn > 0 ? 'warning' : 'success')
        ),
        report_exec_card(
            'Supply',
            $supply !== null ? report_fmt_temp($supply) : '—',
            $return !== null ? ('Return ' . report_fmt_temp($return)) : 'No cooling snapshot',
            'accent'
        ),
    ];
    $chart = is_array($src['temp_chart'] ?? null) ? $src['temp_chart'] : [];
    $chart['kind'] = 'line';
    $chart['empty'] = 'No samples in this period';
    $copy = $src;
    $copy['outside'] = $outside;
    $copy['rh_now'] = null;
    $copy['rh_period'] = null;
    $span = is_array($src['span'] ?? null) ? $src['span'] : [];
    $context = ucfirst((string)($span['label'] ?? 'the last 7 days')) . ' · ' . (string)($src['room_label'] ?? 'All rooms');
    $symbol = (string)($src['symbol'] ?? '°C');
    $rows = report_exec_exception_rows($exceptions);
    $footer = $outside > 0
        ? ($outside . ' sensor' . ($outside === 1 ? '' : 's') . ' outside limits. The list is on the next page.')
        : 'Sensors with limits are inside them.';
    $packed = report_exec_pack(
        'Executive · Temperature',
        report_exec_lead($copy, false),
        $context,
        $cards,
        [$chart],
        report_exec_table('Sensors outside limits', ['Status', 'Name', 'Room', 'Point', 'Current'], $rows),
        $footer,
        'Cold aisle and hot aisle follow sensor placement. Supply and return come from cooling units. Temperatures use the site unit (' . $symbol . ').'
    );
    $packed['has_samples'] = report_exec_chart_has_points($chart);
    return $packed;
}

/** @return array<string,mixed> */
function report_exec_brief_humidity(): array
{
    $src = report_executive();
    $exceptions = [];
    foreach ($src['exceptions'] ?? [] as $ex) {
        if (is_array($ex) && (string)($ex['kind'] ?? '') === 'humidity') {
            $exceptions[] = $ex;
        }
    }
    $outside = count($exceptions);
    $crit = 0;
    $warn = 0;
    foreach ($exceptions as $ex) {
        if (($ex['status'] ?? '') === 'crit') {
            $crit++;
        } elseif (($ex['status'] ?? '') === 'warn') {
            $warn++;
        }
    }
    $kpis = is_array($src['kpis'] ?? null) ? $src['kpis'] : [];
    $span = is_array($src['span'] ?? null) ? $src['span'] : [];
    $periodLabel = ucfirst((string)($span['label'] ?? 'the last 7 days'));
    $period = $src['rh_period'] ?? null;
    $cool = $src['cool_rh_now'] ?? null;
    $cards = [
        $kpis[2] ?? report_exec_card('Humidity', '—', 'No sensors', 'success'),
        report_exec_card(
            'Period average',
            $period !== null ? report_exec_fmt_display((float)$period, '%RH') : '—',
            $periodLabel,
            'accent'
        ),
        report_exec_card(
            'Cooling humidity',
            $cool !== null ? report_fmt_rh($cool) : '—',
            'Latest cooling snapshot',
            'success'
        ),
        report_exec_card(
            'Outside limits',
            (string)$outside,
            $outside === 0 ? 'Within limits' : ($crit . ' crit, ' . $warn . ' warn'),
            $crit > 0 ? 'danger' : ($warn > 0 ? 'warning' : 'success')
        ),
    ];
    $chart = is_array($src['hum_chart'] ?? null) ? $src['hum_chart'] : [];
    $chart['kind'] = 'line';
    $chart['empty'] = 'No samples in this period';
    $context = $periodLabel . ' · ' . (string)($src['room_label'] ?? 'All rooms');
    $rows = report_exec_exception_rows($exceptions);
    $footer = $outside > 0
        ? ($outside . ' humidity sensor' . ($outside === 1 ? '' : 's') . ' outside limits. The list is on the next page.')
        : 'Humidity sensors with limits are inside them.';
    $packed = report_exec_pack(
        'Executive · Humidity',
        report_exec_humidity_lead($src, $outside),
        $context,
        $cards,
        [$chart],
        report_exec_table('Sensors outside limits', ['Status', 'Name', 'Room', 'Point', 'Current'], $rows),
        $footer,
        'Humidity includes humidity sensors and the humidity reading on combined probes. Cooling humidity is the latest snapshot from cooling units.'
    );
    $packed['has_samples'] = report_exec_chart_has_points($chart);
    return $packed;
}

/** @param array<string,mixed> $src */
function report_exec_humidity_lead(array $src, int $outside): string
{
    $span = is_array($src['span'] ?? null) ? $src['span'] : [];
    $period = (string)($span['label'] ?? 'the last 7 days');
    $roomId = (int)($src['room_id'] ?? 0);
    $where = $roomId > 0 ? ('In ' . (string)$src['room_label'] . ', ') : '';
    $bits = [];
    if (($src['rh_period'] ?? null) !== null) {
        $bits[] = 'relative humidity averaged ' . report_exec_fmt_display((float)$src['rh_period'], '%RH') . ' over ' . $period;
    } elseif (($src['rh_now'] ?? null) !== null) {
        $bits[] = 'relative humidity is ' . report_fmt_rh($src['rh_now']) . ' now';
    }
    if (($src['cool_rh_now'] ?? null) !== null) {
        $bits[] = 'cooling humidity is ' . report_fmt_rh($src['cool_rh_now']) . ' now';
    }
    if ($outside > 0) {
        $bits[] = $outside . ' humidity sensor' . ($outside === 1 ? ' is' : 's are') . ' outside limits';
    } elseif ($bits !== []) {
        $bits[] = 'humidity sensors with limits are inside them';
    }
    if ($bits === []) {
        $head = $where !== '' ? ($where . 'no humidity readings yet') : 'No humidity readings yet';
        return rtrim($head, '.') . '.';
    }
    $text = $where !== '' ? ($where . $bits[0]) : ucfirst($bits[0]);
    for ($i = 1, $c = count($bits); $i < $c; $i++) {
        $text .= '. ' . ucfirst($bits[$i]);
    }
    return $text . '.';
}

/** @return array<string,mixed> */
function report_exec_brief_warranty(): array
{
    $window = 60;
    if (class_exists('SettingsService')) {
        $window = (int)SettingsService::get('warranty_notify_days', '60');
    }
    if (isset($_GET['days']) && $_GET['days'] !== '') {
        $window = (int)$_GET['days'];
    }
    $window = max(0, min(730, $window));
    $rows = report_warranty('all', $window);
    $items = [];
    $expired = 0;
    $soon = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $end = isset($row['warranty_end']) ? (string)$row['warranty_end'] : '';
        $days = class_exists('AssetLifecycleService') ? AssetLifecycleService::daysUntil($end !== '' ? $end : null) : null;
        if ($days === null) {
            continue;
        }
        if ($days < 0) {
            $expired++;
        } elseif ($days <= $window) {
            $soon++;
        }
        $items[] = [
            'label' => report_exec_label((string)($row['label'] ?? ''), 'Asset'),
            'kind' => report_exec_label((string)($row['kind'] ?? ''), 'Asset'),
            'end' => substr($end, 0, 10),
            'days' => $days,
        ];
    }
    usort($items, static fn(array $a, array $b): int => $a['days'] <=> $b['days']);
    $chartItems = array_slice($items, 0, 12);
    $pairs = [];
    $tableRows = [];
    foreach ($chartItems as $item) {
        $days = (int)$item['days'];
        $color = $days < 0 ? '#ef4444' : ($days <= 30 ? '#f97316' : '#0ea5e9');
        $pairs[] = [(string)$item['label'], (float)$days, $color];
        $tableRows[] = [
            (string)$item['label'],
            ucfirst((string)$item['kind']),
            (string)$item['end'],
            report_exec_days_label($days),
        ];
    }
    $soonest = $items[0] ?? null;
    $dated = count($items);
    $bits = [];
    if ($dated > 0) {
        $bits[] = $dated . ' asset' . ($dated === 1 ? ' has' : 's have') . ' a warranty date';
        if ($expired > 0) {
            $bits[] = $expired . ( $expired === 1 ? ' is' : ' are') . ' expired';
        }
        if ($soon > 0) {
            $bits[] = $soon . ( $soon === 1 ? ' is' : ' are') . ' due within ' . $window . ' days';
        }
    } else {
        $bits[] = 'no warranty dates are recorded yet';
    }
    $chart = report_exec_count_chart('Days remaining', $pairs, 'Days', 'No warranty dates yet');
    $chart['unit'] = 'days';
    $footer = $dated > 12 ? 'The graph shows the 12 soonest dates, including those already expired.' : '';
    return report_exec_pack(
        'Executive · Warranty',
        report_exec_sentences($bits),
        'All dated assets',
        [
            report_exec_card('Expired', (string)$expired, 'Past the end date', $expired > 0 ? 'danger' : 'success'),
            report_exec_card('Due soon', (string)$soon, 'Within ' . $window . ' days', $soon > 0 ? 'warning' : 'success'),
            report_exec_card('Dated', (string)$dated, 'Devices, PDUs, and UPS', 'accent'),
            report_exec_card(
                'Soonest',
                $soonest ? report_exec_days_label((int)$soonest['days']) : '—',
                $soonest ? (string)$soonest['label'] : 'No warranty date',
                $soonest && (int)$soonest['days'] < 0 ? 'danger' : 'accent'
            ),
        ],
        [$chart],
        report_exec_table('Soonest warranties', ['Asset', 'Kind', 'Ends', 'Remaining'], $tableRows),
        $footer,
        'Days remaining count backward once the end date has passed. Due soon uses the notify window.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_disposal(): array
{
    $rows = report_disposal_queue();
    $pairs = report_exec_tally($rows, static fn(array $row): string => report_exec_status_label((string)($row['status'] ?? '')));
    $nextTs = null;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $raw = trim((string)($row['scheduled_date'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $ts = strtotime($raw);
        if ($ts && ($nextTs === null || $ts < $nextTs)) {
            $nextTs = $ts;
        }
    }
    $open = count($rows);
    $top = $pairs[0] ?? null;
    $cards = [
        report_exec_card('Open', (string)$open, 'Not completed or cancelled', $open > 0 ? 'warning' : 'success'),
        report_exec_card('Statuses', (string)count($pairs), $top ? ((string)$top[0] . ' is the largest') : 'None', 'accent'),
        report_exec_card('Next date', $nextTs ? date('M j, Y', $nextTs) : '—', 'Earliest scheduled date', 'accent'),
    ];
    if ($top) {
        $cards[] = report_exec_card((string)$top[0], (string)$top[1], 'Open items', 'warning');
    }
    $bits = [$open > 0 ? ($open . ' disposal' . ($open === 1 ? ' is' : 's are') . ' open') : 'no disposals are open'];
    if ($top && $open > 0) {
        $bits[] = (string)$top[1] . ' ' . ($top[1] === 1 ? 'is' : 'are') . ' ' . (string)$top[0];
    }
    return report_exec_pack(
        'Executive · Disposal queue',
        report_exec_sentences($bits),
        'Open items',
        $cards,
        [report_exec_count_chart('Open by status', $pairs, 'Items', 'No open disposals')],
        [],
        '',
        'The queue leaves out disposals that are completed or cancelled.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_cables(): array
{
    $rows = report_cables();
    $length = 0.0;
    $lengthN = 0;
    $media = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (isset($row['length_m']) && is_numeric($row['length_m'])) {
            $length += (float)$row['length_m'];
            $lengthN++;
        }
        $kind = trim((string)($row['media_type'] ?? ''));
        if ($kind !== '') {
            $media[$kind] = true;
        }
    }
    $pairs = report_exec_tally($rows, static fn(array $row): string => report_exec_status_label((string)($row['status'] ?? '')));
    $count = 0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            $count++;
        }
    }
    $top = $pairs[0] ?? null;
    $bits = [];
    if ($count > 0) {
        $bits[] = $count . ' cable' . ($count === 1 ? ' is' : 's are') . ' on the plant';
        if ($lengthN > 0) {
            $bits[] = 'recorded length totals ' . number_format($length, 1) . ' m';
        }
        if ($top) {
            $bits[] = (string)$top[0] . ' is the largest status, with ' . (string)$top[1];
        }
    } else {
        $bits[] = 'no cables are recorded yet';
    }
    return report_exec_pack(
        'Executive · Cable inventory',
        report_exec_sentences($bits),
        'All cables',
        [
            report_exec_card('Cables', report_exec_int($count), 'On the plant', 'accent'),
            report_exec_card('Length', $lengthN > 0 ? (number_format($length, 1) . ' m') : '—', $lengthN > 0 ? 'Recorded lengths' : 'No lengths recorded', 'accent'),
            report_exec_card('Media', (string)count($media), 'Distinct types', 'success'),
            report_exec_card('Statuses', (string)count($pairs), $top ? (string)$top[0] : 'None', 'accent'),
        ],
        [report_exec_count_chart('Cables by status', $pairs, 'Cables')],
        [],
        '',
        'Length adds the lengths that are filled in. The detail report lists each cable.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_orphans(): array
{
    $rows = report_orphans();
    $pairs = report_exec_tally($rows, static fn(array $row): string => report_exec_label((string)($row['device_type'] ?? '')));
    $statusPairs = report_exec_tally($rows, static fn(array $row): string => report_exec_status_label((string)($row['status'] ?? '')));
    $count = count($pairs) === 0 ? 0 : array_sum(array_map(static fn(array $pair): int => (int)$pair[1], $pairs));
    $top = $pairs[0] ?? null;
    $topStatus = $statusPairs[0] ?? null;
    $bits = [];
    if ($count > 0) {
        $bits[] = $count . ' device' . ($count === 1 ? ' is' : 's are') . ' not in a cabinet';
        if ($top) {
            $bits[] = (string)$top[0] . ' is the largest type, with ' . (string)$top[1];
        }
    } else {
        $bits[] = 'every active device is in a cabinet';
    }
    return report_exec_pack(
        'Executive · Orphaned devices',
        report_exec_sentences($bits),
        'No cabinet assignment',
        [
            report_exec_card('Devices', report_exec_int($count), 'Active, with no cabinet', $count > 0 ? 'warning' : 'success'),
            report_exec_card('Types', (string)count($pairs), $top ? ((string)$top[0] . ' leads') : 'None', 'accent'),
            report_exec_card('Largest type', $top ? (string)$top[1] : '—', $top ? (string)$top[0] : 'None', 'accent'),
            report_exec_card('Statuses', (string)count($statusPairs), $topStatus ? (string)$topStatus[0] : 'None', 'accent'),
        ],
        [report_exec_count_chart('Orphans by type', $pairs, 'Devices', 'No orphaned devices')],
        [],
        count($pairs) > 12 ? 'The graph shows the 12 largest types.' : '',
        'An orphan is an active device with no cabinet.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_work_orders(): array
{
    $rows = report_work_orders();
    $names = function_exists('work_order_statuses') ? work_order_statuses() : [];
    $open = 0;
    $progress = 0;
    $completed = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if ($status === 'completed') {
            $completed++;
        } elseif ($status === 'in_progress') {
            $progress++;
        }
        if (!in_array($status, ['completed', 'cancelled'], true)) {
            $open++;
        }
    }
    $pairs = report_exec_tally($rows, static function (array $row) use ($names): string {
        $status = (string)($row['status'] ?? '');
        if (isset($names[$status])) {
            return (string)$names[$status];
        }
        return report_exec_status_label($status);
    });
    $count = 0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            $count++;
        }
    }
    $bits = [$count . ' work order' . ($count === 1 ? '' : 's')];
    if ($count === 0) {
        $bits = ['no work orders yet'];
    } elseif ($open > 0) {
        $bits[] = $open . ( $open === 1 ? ' is' : ' are') . ' still open';
    } else {
        $bits[] = 'none are still open';
    }
    return report_exec_pack(
        'Executive · Work orders',
        report_exec_sentences($bits),
        'All work orders',
        [
            report_exec_card('Total', report_exec_int($count), 'On record', 'accent'),
            report_exec_card('Open', (string)$open, 'Not completed or cancelled', $open > 0 ? 'warning' : 'success'),
            report_exec_card('In progress', (string)$progress, 'Started', $progress > 0 ? 'accent' : 'success'),
            report_exec_card('Completed', (string)$completed, 'Finished', 'success'),
        ],
        [report_exec_count_chart('Work orders by status', $pairs, 'Work orders', 'No work orders yet')],
        [],
        '',
        'Open leaves out completed and cancelled work orders.'
    );
}

/** @return array<string,mixed> */
function report_exec_brief_audits(): array
{
    $rows = report_audit_history();
    $pairs = report_exec_tally($rows, static fn(array $row): string => report_exec_label((string)($row['audit_type'] ?? ''), 'Audit'));
    $count = 0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            $count++;
        }
    }
    $latest = '—';
    if (isset($rows[0]) && is_array($rows[0])) {
        $ts = strtotime((string)($rows[0]['completed_at'] ?? ''));
        if ($ts) {
            $latest = date('M j, Y', $ts);
        }
    }
    $top = $pairs[0] ?? null;
    $bits = [];
    if ($count > 0) {
        $bits[] = $count . ' audit' . ($count === 1 ? ' is' : 's are') . ' complete';
        if ($latest !== '—') {
            $bits[] = 'the latest finished on ' . $latest;
        }
        if ($top) {
            $bits[] = (string)$top[0] . ' is the largest type, with ' . (string)$top[1];
        }
    } else {
        $bits[] = 'no completed audits yet';
    }
    return report_exec_pack(
        'Executive · Audit history',
        report_exec_sentences($bits),
        'Completed audits',
        [
            report_exec_card('Completed', report_exec_int($count), 'Finished jobs', 'success'),
            report_exec_card('Types', (string)count($pairs), $top ? ((string)$top[0] . ' leads') : 'None', 'accent'),
            report_exec_card('Latest', $latest, 'Most recent completion', 'accent'),
            report_exec_card('Largest type', $top ? (string)$top[1] : '—', $top ? (string)$top[0] : 'None', 'accent'),
        ],
        [report_exec_count_chart('Audits by type', $pairs, 'Audits', 'No completed audits')],
        [],
        count($pairs) > 12 ? 'The graph shows the 12 largest types.' : '',
        'This brief counts completed audits. Findings stay on the detail report.'
    );
}
