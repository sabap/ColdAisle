<?php
/**
 * Executive briefs: one-page summary, graph, and PDF for each catalog report.
 * Temperature and humidity still read stored sensor samples only.
 */
declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function report_executive(): array
{
    $span = report_climate_span();
    $roomId = report_climate_room_id();
    $since = report_climate_since((int)$span['hours']);
    $step = match ($span['span']) {
        '24h' => 1,
        '30d' => 24,
        default => 6,
    };
    $symbol = class_exists('TempUnitService') ? TempUnitService::symbol() : '°C';
    $roomLabel = report_exec_room_label($roomId);

    $sensors = report_exec_sensors($roomId);
    $placements = function_exists('env_sensor_placements') ? env_sensor_placements() : [];

    $coldNow = [];
    $hotNow = [];
    $otherNow = [];
    $rhNow = [];
    $coldCount = 0;
    $hotCount = 0;
    $tempCount = 0;
    $rhCount = 0;
    $exceptions = [];

    foreach ($sensors as $s) {
        $kind = (string)($s['sensor_kind'] ?? '');
        $lane = report_exec_lane((string)($s['placement'] ?? ''));
        $placeLabel = $placements[(string)($s['placement'] ?? '')] ?? '';
        $temp = report_exec_num($s['last_value'] ?? null);
        if ($temp !== null && !report_exec_temp_ok($temp)) {
            $temp = null;
        }
        $rh = report_exec_num($s['last_humidity'] ?? null);
        if ($kind === 'humidity' && $rh === null) {
            $rh = report_exec_num($s['last_value'] ?? null);
        }
        if ($rh !== null && !report_exec_rh_ok($rh)) {
            $rh = null;
        }

        if (in_array($kind, ['temperature', 'temp_humidity'], true)) {
            $tempCount++;
            if ($lane === 'cold') {
                $coldCount++;
                if ($temp !== null) {
                    $coldNow[] = $temp;
                }
            } elseif ($lane === 'hot') {
                $hotCount++;
                if ($temp !== null) {
                    $hotNow[] = $temp;
                }
            } elseif ($temp !== null) {
                $otherNow[] = $temp;
            }
            $status = function_exists('env_sensor_threshold_status')
                ? env_sensor_threshold_status($temp, $s)
                : 'unknown';
            if ($status === 'warn' || $status === 'crit') {
                $exceptions[] = report_exec_exception($s, $placeLabel, $kind, report_fmt_temp($temp), $status);
            }
        } elseif ($kind === 'dew_point') {
            $status = function_exists('env_sensor_threshold_status')
                ? env_sensor_threshold_status($temp, $s)
                : 'unknown';
            if ($status === 'warn' || $status === 'crit') {
                $point = $placeLabel !== '' ? ('Dew point · ' . $placeLabel) : 'Dew point';
                $exceptions[] = report_exec_exception($s, $point, $kind, report_fmt_temp($temp), $status, true);
            }
        }

        if ($kind === 'humidity' || $kind === 'temp_humidity') {
            $rhCount++;
            if ($rh !== null) {
                $rhNow[] = $rh;
            }
            if ($kind === 'humidity' && function_exists('env_sensor_threshold_status')) {
                $status = env_sensor_threshold_status($rh, $s);
                if ($status === 'warn' || $status === 'crit') {
                    $point = $placeLabel !== '' ? $placeLabel : 'Humidity';
                    $exceptions[] = report_exec_exception($s, $point, $kind, report_fmt_rh($rh), $status, true);
                }
            }
        }
    }

    usort($exceptions, static function (array $a, array $b): int {
        $rank = ['crit' => 0, 'warn' => 1];
        $ra = $rank[$a['status']] ?? 2;
        $rb = $rank[$b['status']] ?? 2;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    $tempRows = report_exec_temp_rows($since, $roomId, $step);
    $humRows = report_exec_hum_rows($since, $roomId, $step);
    $coolRows = report_exec_cool_rows($since, $roomId, $step);
    $coolNow = report_exec_cool_now($roomId);

    $tempSeries = [
        'cold' => report_exec_blank_series('cold', 'Cold aisle', '#0ea5e9'),
        'hot' => report_exec_blank_series('hot', 'Hot aisle', '#f97316'),
        'other' => report_exec_blank_series('other', 'Room', '#94a3b8'),
        'supply' => report_exec_blank_series('supply', 'Supply', '#6366f1'),
        'return' => report_exec_blank_series('return', 'Return', '#c026d3'),
    ];
    $humSeries = [
        'sensors' => report_exec_blank_series('sensors', 'Sensors', '#10b981'),
        'cooling' => report_exec_blank_series('cooling', 'Cooling units', '#0f766e'),
    ];
    $times = [];

    foreach ($tempRows as $row) {
        $key = report_exec_bucket_key($row['bucket'] ?? '');
        $avg = report_exec_num($row['avg_v'] ?? null);
        $n = (int)($row['n'] ?? 0);
        if ($key === '' || $avg === null || $n < 1) {
            continue;
        }
        $lane = (string)($row['lane'] ?? 'other');
        if (!isset($tempSeries[$lane])) {
            $lane = 'other';
        }
        $times[$key] = true;
        $tempSeries[$lane]['buckets'][$key] = ['avg' => $avg, 'n' => $n];
    }
    foreach ($coolRows as $row) {
        $key = report_exec_bucket_key($row['bucket'] ?? '');
        if ($key === '') {
            continue;
        }
        $used = false;
        foreach (['supply' => 'supply_v', 'return' => 'return_v'] as $lane => $col) {
            $avg = report_exec_num($row[$col] ?? null);
            $n = (int)($row[$lane . '_n'] ?? 0);
            if ($avg === null || $n < 1 || !report_exec_temp_ok($avg)) {
                continue;
            }
            $times[$key] = true;
            $tempSeries[$lane]['buckets'][$key] = ['avg' => $avg, 'n' => $n];
            $used = true;
        }
        $havg = report_exec_num($row['hum_v'] ?? null);
        $hn = (int)($row['hum_n'] ?? 0);
        if ($havg !== null && $hn > 0 && report_exec_rh_ok($havg)) {
            $times[$key] = true;
            $humSeries['cooling']['buckets'][$key] = ['avg' => $havg, 'n' => $hn];
            $used = true;
        }
        if ($used) {
            $times[$key] = true;
        }
    }
    foreach ($humRows as $row) {
        $key = report_exec_bucket_key($row['bucket'] ?? '');
        $avg = report_exec_num($row['avg_v'] ?? null);
        $n = (int)($row['n'] ?? 0);
        if ($key === '' || $avg === null || $n < 1 || !report_exec_rh_ok($avg)) {
            continue;
        }
        $times[$key] = true;
        $humSeries['sensors']['buckets'][$key] = ['avg' => $avg, 'n' => $n];
    }

    $labels = array_keys($times);
    sort($labels);
    $stepSec = $step * 3600;
    if ($tempSeries['cold']['buckets'] === [] && $tempSeries['hot']['buckets'] === [] && $coldCount === 0 && $hotCount === 0) {
        $tempSeries['other']['label'] = 'Temperature';
    }
    $tempChart = report_exec_chart('Temperature', $symbol, $labels, $tempSeries, $stepSec, true);
    $humChart = report_exec_chart('Humidity', '%RH', $labels, $humSeries, $stepSec, false);

    $hasCold = $coldCount > 0;
    $hasHot = $hotCount > 0;
    $coldAvg = report_exec_avg($coldNow);
    $hotAvg = report_exec_avg($hotNow);
    $otherAvg = report_exec_avg($otherNow);
    $allNow = array_merge($coldNow, $hotNow, $otherNow);
    $allAvg = report_exec_avg($allNow);
    $rhAvg = report_exec_avg($rhNow);

    $coldPeriod = report_exec_period($tempSeries['cold']['buckets'], true);
    $hotPeriod = report_exec_period($tempSeries['hot']['buckets'], true);
    $otherPeriod = report_exec_period($tempSeries['other']['buckets'], true);
    $rhPeriod = report_exec_period($humSeries['sensors']['buckets'], false);
    $sampleCount = 0;
    foreach ([$tempSeries, $humSeries] as $group) {
        foreach ($group as $series) {
            foreach ($series['buckets'] as $bucket) {
                $sampleCount += (int)$bucket['n'];
            }
        }
    }

    $primaryLabel = $hasCold ? 'Cold aisle' : 'Temperature';
    $primaryNow = $hasCold ? $coldAvg : $allAvg;
    $primaryPeriod = $hasCold ? $coldPeriod : report_exec_period_merge($tempSeries, true);
    $primaryCount = $hasCold ? $coldCount : $tempCount;

    $crit = 0;
    $warn = 0;
    foreach ($exceptions as $ex) {
        if ($ex['status'] === 'crit') {
            $crit++;
        } elseif ($ex['status'] === 'warn') {
            $warn++;
        }
    }
    $outside = $crit + $warn;

    $kpis = [
        report_exec_kpi(
            'cold',
            $primaryLabel,
            $primaryNow,
            $primaryPeriod,
            $primaryCount,
            true,
            'accent'
        ),
        report_exec_kpi(
            'hot',
            'Hot aisle',
            $hasHot ? $hotAvg : null,
            $hasHot ? $hotPeriod : null,
            $hotCount,
            true,
            'warning'
        ),
        report_exec_kpi('humidity', 'Humidity', $rhAvg, $rhPeriod, $rhCount, false, 'success'),
        [
            'key' => 'alerts',
            'label' => 'Outside limits',
            'value' => (string)$outside,
            'sub' => $outside === 0 ? 'Within limits' : ($crit . ' crit, ' . $warn . ' warn'),
            'tone' => $crit > 0 ? 'danger' : ($warn > 0 ? 'warning' : 'success'),
        ],
    ];

    $org = '';
    if (class_exists('App')) {
        $org = trim((string)App::config('org_name', ''));
    }

    $brief = [
        'span' => $span,
        'room_id' => $roomId,
        'room_label' => $roomLabel,
        'symbol' => $symbol,
        'generated' => date('M j, Y g:i A'),
        'org' => $org,
        'kpis' => $kpis,
        'temp_chart' => $tempChart,
        'hum_chart' => $humChart,
        'exceptions' => $exceptions,
        'has_samples' => $sampleCount > 0,
        'supply_now' => $coolNow['supply'],
        'return_now' => $coolNow['return'],
        'cool_rh_now' => $coolNow['humidity'],
        'has_cold' => $hasCold,
        'has_hot' => $hasHot,
        'cold_now' => $coldAvg,
        'hot_now' => $hotAvg,
        'cold_period' => $coldPeriod,
        'hot_period' => $hotPeriod,
        'temp_now' => $allAvg,
        'temp_period' => $hasCold ? $coldPeriod : report_exec_period_merge($tempSeries, true),
        'other_now' => $otherAvg,
        'other_period' => $otherPeriod,
        'rh_now' => $rhAvg,
        'rh_period' => $rhPeriod,
        'outside' => $outside,
    ];
    $brief['lead'] = report_exec_lead($brief);
    $brief['csv'] = report_exec_csv_table($tempChart, $humChart);
    return $brief;
}

/**
 * @param array<string,mixed> $brief
 */
function report_exec_send_pdf(array $brief): never
{
    $bin = report_exec_pdf_binary($brief);
    $name = 'coldaisle-executive-' . date('Ymd-His') . '.pdf';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string)strlen($bin));
    header('Cache-Control: no-store');
    echo $bin;
    exit;
}

/**
 * @param array<string,mixed> $chart
 */
function report_exec_svg(array $chart, string $periodLabel): string
{
    $geo = report_exec_geometry($chart);
    $bar = ($geo['kind'] ?? '') === 'bar';
    $xCount = count($geo['x_labels'] ?? []);
    $rotate = $bar && $xCount > 5;
    $w = 760;
    $h = $rotate ? 340 : 292;
    $left = 54.0;
    $right = 16.0;
    $top = 16.0;
    $plotH = $rotate ? 168.0 : 196.0;
    $aria = trim((string)($chart['title'] ?? 'Chart') . ($periodLabel !== '' ? (' over ' . $periodLabel) : ''));
    $svg = '<svg class="exec-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . App::e($aria) . '">';
    $svg .= '<rect x="0" y="0" width="' . $w . '" height="' . $h . '" fill="none"/>';
    if (!empty($geo['empty'])) {
        $emptyText = trim((string)($chart['empty'] ?? ''));
        if ($emptyText === '') {
            $emptyText = $bar ? 'Nothing to chart yet' : 'No samples in this period';
        }
        $svg .= '<text x="380" y="150" text-anchor="middle" fill="#8b9bb0" font-size="14">' . App::e($emptyText) . '</text>';
        $svg .= '</svg>';
        return $svg;
    }
    $plotW = $w - $left - $right;
    $baseY = $top + $plotH;
    foreach ($geo['y_labels'] as $tick) {
        $y = $top + (1 - (float)$tick['y']) * $plotH;
        $svg .= '<line x1="' . report_exec_px($left) . '" y1="' . report_exec_px($y) . '" x2="' . report_exec_px($left + $plotW) . '" y2="' . report_exec_px($y) . '" stroke="rgba(148,163,184,.22)" stroke-width="1"/>';
        $svg .= '<text x="' . report_exec_px($left - 8) . '" y="' . report_exec_px($y + 4) . '" text-anchor="end" fill="#8b9bb0" font-size="11">' . App::e((string)$tick['label']) . '</text>';
    }
    if ($bar && isset($geo['zero_y'])) {
        $zy = $top + (1 - (float)$geo['zero_y']) * $plotH;
        $svg .= '<line x1="' . report_exec_px($left) . '" y1="' . report_exec_px($zy) . '" x2="' . report_exec_px($left + $plotW) . '" y2="' . report_exec_px($zy) . '" stroke="rgba(148,163,184,.55)" stroke-width="1"/>';
    }
    if ($bar) {
        foreach ($geo['bars'] ?? [] as $rect) {
            $x = $left + (float)$rect['x'] * $plotW;
            $bw = max(2.0, (float)$rect['w'] * $plotW);
            $yTop = $top + (1 - max((float)$rect['y0'], (float)$rect['y1'])) * $plotH;
            $yBot = $top + (1 - min((float)$rect['y0'], (float)$rect['y1'])) * $plotH;
            $bh = max(2.0, $yBot - $yTop);
            $color = report_exec_safe_hex((string)$rect['color']);
            $svg .= '<rect x="' . report_exec_px($x) . '" y="' . report_exec_px($yTop) . '" width="' . report_exec_px($bw) . '" height="' . report_exec_px($bh) . '" rx="2" fill="' . App::e($color) . '" fill-opacity="0.9"/>';
        }
    }
    foreach ($geo['series'] as $series) {
        $color = (string)$series['color'];
        foreach ($series['segs'] as $seg) {
            if (count($seg) < 2) {
                continue;
            }
            $line = '';
            $area = '';
            foreach ($seg as $i => $pt) {
                $x = $left + (float)$pt[0] * $plotW;
                $y = $top + (1 - (float)$pt[1]) * $plotH;
                $cmd = $i === 0 ? 'M' : 'L';
                $line .= $cmd . report_exec_px($x) . ' ' . report_exec_px($y) . ' ';
                $area .= $cmd . report_exec_px($x) . ' ' . report_exec_px($y) . ' ';
            }
            $x0 = $left + (float)$seg[0][0] * $plotW;
            $x1 = $left + (float)$seg[count($seg) - 1][0] * $plotW;
            $area .= 'L' . report_exec_px($x1) . ' ' . report_exec_px($baseY) . ' L' . report_exec_px($x0) . ' ' . report_exec_px($baseY) . ' Z';
            $svg .= '<path d="' . trim($area) . '" fill="' . App::e($color) . '" fill-opacity="0.14" stroke="none"/>';
            $svg .= '<path d="' . trim($line) . '" fill="none" stroke="' . App::e($color) . '" stroke-opacity="0.35" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>';
            $svg .= '<path d="' . trim($line) . '" fill="none" stroke="' . App::e($color) . '" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/>';
        }
        foreach ($series['segs'] as $seg) {
            if (count($seg) !== 1) {
                continue;
            }
            $x = $left + (float)$seg[0][0] * $plotW;
            $y = $top + (1 - (float)$seg[0][1]) * $plotH;
            $svg .= '<circle cx="' . report_exec_px($x) . '" cy="' . report_exec_px($y) . '" r="4" fill="' . App::e($color) . '"/>';
        }
    }
    foreach ($geo['x_labels'] as $i => $tick) {
        $x = $left + (float)$tick['x'] * $plotW;
        $label = App::e((string)$tick['label']);
        if ($rotate) {
            $y = $baseY + 14;
            $svg .= '<text x="' . report_exec_px($x) . '" y="' . report_exec_px($y) . '" text-anchor="end" fill="#8b9bb0" font-size="11" transform="rotate(-38 ' . report_exec_px($x) . ' ' . report_exec_px($y) . ')">' . $label . '</text>';
            continue;
        }
        $anchor = 'middle';
        if (!$bar && $i === 0) {
            $anchor = 'start';
        } elseif (!$bar && $i === count($geo['x_labels']) - 1) {
            $anchor = 'end';
        }
        $svg .= '<text x="' . report_exec_px($x) . '" y="' . report_exec_px($baseY + 20) . '" text-anchor="' . $anchor . '" fill="#8b9bb0" font-size="11">' . $label . '</text>';
    }
    $lx = $left;
    $ly = $rotate ? ($baseY + 86) : ($baseY + 48);
    foreach ($geo['series'] as $series) {
        $label = (string)$series['label'];
        $color = (string)$series['color'];
        $svg .= '<line x1="' . report_exec_px($lx) . '" y1="' . report_exec_px($ly - 4) . '" x2="' . report_exec_px($lx + 18) . '" y2="' . report_exec_px($ly - 4) . '" stroke="' . App::e($color) . '" stroke-width="3" stroke-linecap="round"/>';
        $svg .= '<text x="' . report_exec_px($lx + 24) . '" y="' . report_exec_px($ly) . '" fill="#e5eef7" font-size="12">' . App::e($label) . '</text>';
        $lx += 36 + (strlen($label) * 7.1);
    }
    $svg .= '</svg>';
    return $svg;
}

function report_exec_pdf_url(): string
{
    $q = $_GET;
    unset($q['export']);
    if (($q['report'] ?? '') === '' || ($q['report'] ?? '') === 'executive') {
        $q['report'] = 'temperature';
    }
    $q['mode'] = 'executive';
    $q['export'] = 'pdf';
    $qs = http_build_query($q);
    return App::url('pages/reports.php' . ($qs !== '' ? '?' . $qs : ''));
}

/**
 * @param array<string,mixed> $brief
 */
function report_exec_pdf_binary(array $brief): string
{
    $pdf = new ExecutiveReportPdf();
    report_exec_pdf_page($pdf, $brief);
    $table = is_array($brief['table'] ?? null) ? $brief['table'] : [];
    $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
    if ($rows !== []) {
        report_exec_pdf_table($pdf, $brief, $table);
    } else {
        $exceptions = $brief['exceptions'] ?? [];
        if (is_array($exceptions) && $exceptions !== []) {
            report_exec_pdf_exceptions($pdf, $brief, $exceptions);
        }
    }
    return $pdf->render();
}

/**
 * @param array<string,mixed> $brief
 * @param array<string,mixed> $table
 */
function report_exec_pdf_table(ExecutiveReportPdf $pdf, array $brief, array $table): void
{
    $headers = array_values(array_filter(
        is_array($table['headers'] ?? null) ? $table['headers'] : [],
        static fn($h): bool => is_scalar($h)
    ));
    $headers = array_slice($headers, 0, 5);
    $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
    $count = count($headers);
    if ($count < 1 || $rows === []) {
        return;
    }
    $perPage = 16;
    $pages = array_chunk($rows, $perPage);
    $pageCount = count($pages);
    $title = trim((string)($table['title'] ?? ''));
    if ($title === '') {
        $title = 'Detail';
    }
    $width = 720.0 / $count;
    $clip = max(8, (int)floor(($width - 12) / 4.6));
    foreach ($pages as $index => $pageRows) {
        report_exec_pdf_header($pdf, $brief, $title);
        $y = 520.0;
        $note = trim((string)($table['note'] ?? ''));
        if ($note !== '' && $index === 0) {
            $pdf->text(36, $y, report_exec_clip($note, 110), 10, false, [0.28, 0.35, 0.42]);
            $y -= 18;
        }
        $pdf->rect(36, $y - 16, 720, 22, [0.09, 0.12, 0.16], null);
        foreach ($headers as $i => $head) {
            $pdf->text(36 + ($i * $width) + 6, $y - 10, report_exec_clip((string)$head, $clip), 9, true, [1, 1, 1]);
        }
        $y -= 22;
        foreach ($pageRows as $n => $row) {
            $cells = is_array($row) ? array_values($row) : [];
            $fill = ($n % 2) === 0 ? [0.973, 0.980, 0.988] : [1, 1, 1];
            $pdf->rect(36, $y - 16, 720, 20, $fill, null);
            for ($i = 0; $i < $count; $i++) {
                $pdf->text(36 + ($i * $width) + 6, $y - 10, report_exec_clip((string)($cells[$i] ?? ''), $clip), 9, false, [0.06, 0.09, 0.16]);
            }
            $y -= 20;
        }
        $pdf->text(36, 36, 'Page ' . ($index + 2) . ' of ' . ($pageCount + 1), 8, false, [0.39, 0.45, 0.55]);
        $pdf->text(620, 36, 'ColdAisle', 8, false, [0.39, 0.45, 0.55]);
        $pdf->endPage();
    }
}

/**
 * @param list<array<string,mixed>> $exceptions
 * @param array<string,mixed> $brief
 */
function report_exec_pdf_exceptions(ExecutiveReportPdf $pdf, array $brief, array $exceptions): void
{
    $perPage = 18;
    $pages = array_chunk($exceptions, $perPage);
    $pageCount = count($pages);
    foreach ($pages as $index => $rows) {
        report_exec_pdf_header($pdf, $brief, 'Sensors outside limits');
        $pdf->text(36, 520, 'Latest reading compared with each sensor limit. Combined-probe limits apply to temperature.', 10, false, [0.28, 0.35, 0.42]);
        $y = 492.0;
        $cols = [36.0, 100.0, 320.0, 470.0, 620.0];
        $heads = ['Status', 'Name', 'Room', 'Point', 'Current'];
        $pdf->rect(36, $y - 16, 720, 22, [0.09, 0.12, 0.16], null);
        foreach ($heads as $i => $head) {
            $pdf->text($cols[$i] + 6, $y - 10, $head, 9, true, [1, 1, 1]);
        }
        $y -= 22;
        foreach ($rows as $n => $row) {
            $fill = ($n % 2) === 0 ? [0.973, 0.980, 0.988] : [1, 1, 1];
            $pdf->rect(36, $y - 16, 720, 20, $fill, null);
            $status = (string)($row['status'] ?? '');
            $statusRgb = $status === 'crit' ? [0.72, 0.11, 0.11] : [0.71, 0.33, 0.04];
            $pdf->text($cols[0] + 6, $y - 10, $status === 'crit' ? 'Crit' : 'Warn', 9, true, $statusRgb);
            $pdf->text($cols[1] + 6, $y - 10, report_exec_clip((string)$row['name'], 34), 9, false, [0.06, 0.09, 0.16]);
            $pdf->text($cols[2] + 6, $y - 10, report_exec_clip((string)$row['room'], 22), 9, false, [0.2, 0.25, 0.33]);
            $pdf->text($cols[3] + 6, $y - 10, report_exec_clip((string)$row['point'], 22), 9, false, [0.2, 0.25, 0.33]);
            $pdf->text($cols[4] + 6, $y - 10, report_exec_clip((string)$row['current'], 16), 9, false, [0.06, 0.09, 0.16]);
            $y -= 20;
        }
        $pdf->text(36, 36, 'Page ' . ($index + 2) . ' of ' . ($pageCount + 1), 8, false, [0.39, 0.45, 0.55]);
        $pdf->text(620, 36, 'ColdAisle', 8, false, [0.39, 0.45, 0.55]);
        $pdf->endPage();
    }
}

/**
 * @param array<string,mixed> $brief
 */
function report_exec_pdf_page(ExecutiveReportPdf $pdf, array $brief): void
{
    $pageTitle = trim((string)($brief['title'] ?? ''));
    if ($pageTitle === '') {
        $pageTitle = 'Executive climate brief';
    }
    report_exec_pdf_header($pdf, $brief, $pageTitle);
    $leadLines = report_exec_wrap((string)($brief['lead'] ?? ''), 10, 720);
    $leadLines = array_slice($leadLines, 0, 3);
    $y = 528.0;
    foreach ($leadLines as $line) {
        $pdf->text(36, $y, $line, 10, false, [0.12, 0.16, 0.22]);
        $y -= 14;
    }
    if (array_key_exists('has_samples', $brief) && empty($brief['has_samples'])) {
        $pdf->text(36, $y, 'No samples were stored in this period. Figures are the latest reading kept on each sensor.', 9, false, [0.39, 0.45, 0.55]);
        $y -= 16;
    }

    $kpis = is_array($brief['kpis'] ?? null) ? $brief['kpis'] : [];
    $cardW = 171.0;
    $gap = 12.0;
    $cardH = 82.0;
    $cardY = $y - $cardH - 8;
    $tones = [
        'accent' => [0.05, 0.45, 0.78],
        'warning' => [0.85, 0.40, 0.08],
        'success' => [0.05, 0.55, 0.40],
        'danger' => [0.75, 0.15, 0.15],
    ];
    foreach ($kpis as $i => $kpi) {
        if ($i > 3) {
            break;
        }
        $x = 36 + ($i * ($cardW + $gap));
        $pdf->rect($x, $cardY, $cardW, $cardH, [0.973, 0.980, 0.988], [0.86, 0.90, 0.93]);
        $tone = $tones[(string)($kpi['tone'] ?? 'accent')] ?? $tones['accent'];
        $pdf->rect($x, $cardY + $cardH - 4, $cardW, 4, $tone, null);
        $pdf->text($x + 10, $cardY + 58, strtoupper((string)$kpi['label']), 8, true, [0.39, 0.45, 0.55]);
        $pdf->text($x + 10, $cardY + 36, report_exec_clip((string)$kpi['value'], 18), 14, true, [0.06, 0.09, 0.16]);
        $subLines = array_slice(report_exec_wrap((string)($kpi['sub'] ?? ''), 8, $cardW - 20), 0, 2);
        $sy = $cardY + 18;
        foreach ($subLines as $line) {
            $pdf->text($x + 10, $sy, $line, 8, false, [0.28, 0.35, 0.42]);
            $sy -= 10;
        }
    }

    $cursor = $cardY - 14;
    $charts = report_exec_brief_charts($brief);
    $room = $cursor - 46;
    $heights = [];
    if (count($charts) === 1) {
        $heights = [min(210.0, max(150.0, $room))];
    } elseif (count($charts) >= 2) {
        $each = max(90.0, ($room - 28) / 2);
        $heights = [min(156.0, $each), min(120.0, $each)];
    }
    foreach ($charts as $i => $chart) {
        if (!isset($heights[$i])) {
            break;
        }
        $h = $heights[$i];
        $unit = (string)($chart['unit'] ?? '');
        $cursor = report_exec_pdf_chart($pdf, $chart, 36, $cursor - $h, 720, $h, $unit);
        if (!isset($brief['charts']) && $i === 0) {
            $caption = report_exec_cool_caption($brief);
            if ($caption !== '') {
                $pdf->text(36, $cursor - 12, $caption, 9, false, [0.28, 0.35, 0.42]);
                $cursor -= 18;
            }
        }
        $cursor -= 8;
    }
    $extraPages = report_exec_extra_pages($brief);
    $totalPages = 1 + $extraPages;
    if (array_key_exists('footer_note', $brief)) {
        $note = trim((string)$brief['footer_note']);
    } else {
        $outside = (int)($brief['outside'] ?? 0);
        $note = $outside > 0
            ? ($outside . ' sensor' . ($outside === 1 ? '' : 's') . ' outside limits. The list is on the next page.')
            : 'Sensors with limits are inside them.';
    }
    if ($note !== '') {
        $pdf->text(36, 28, report_exec_clip($note, 108), 9, false, [0.2, 0.25, 0.33]);
    }
    $pageMark = '1 of ' . $totalPages;
    $pdf->text(756 - $pdf->textWidth($pageMark, 8), 28, $pageMark, 8, false, [0.39, 0.45, 0.55]);
    $pdf->endPage();
}

function report_exec_pdf_chart(ExecutiveReportPdf $pdf, array $chart, float $x, float $y, float $w, float $h, string $unit): float
{
    $title = (string)($chart['title'] ?? 'Chart');
    $pdf->text($x, $y + $h - 14, $title . ($unit !== '' ? ' (' . $unit . ')' : ''), 11, true, [0.06, 0.09, 0.16]);
    $plotX = $x;
    $plotY = $y + 22;
    $plotW = $w;
    $plotH = $h - 40;
    $pdf->rect($plotX, $plotY, $plotW, $plotH, [1, 1, 1], [0.86, 0.90, 0.93]);
    $geo = report_exec_geometry($chart);
    $xLabelCount = count(is_array($geo['x_labels'] ?? null) ? $geo['x_labels'] : []);
    $tiltLabels = ($geo['kind'] ?? '') === 'bar' && $xLabelCount > 5;
    $padL = 42.0;
    $padR = 12.0;
    $padT = 10.0;
    $padB = $tiltLabels ? 50.0 : 22.0;
    $innerX = $plotX + $padL;
    $innerY = $plotY + $padB;
    $innerW = $plotW - $padL - $padR;
    $innerH = $plotH - $padT - $padB;
    if (!empty($geo['empty']) || $innerW <= 0 || $innerH <= 0) {
        $msg = trim((string)($chart['empty'] ?? ''));
        if ($msg === '') {
            $msg = (($chart['kind'] ?? 'line') === 'bar') ? 'Nothing to chart yet' : 'No samples in this period';
        }
        $tw = $pdf->textWidth($msg, 10);
        $pdf->text($plotX + ($plotW / 2) - ($tw / 2), $plotY + ($plotH / 2), $msg, 10, false, [0.39, 0.45, 0.55]);
        return $y;
    }
    foreach ($geo['y_labels'] as $tick) {
        $yy = $innerY + ((float)$tick['y'] * $innerH);
        $pdf->line($innerX, $yy, $innerX + $innerW, $yy, [0.886, 0.910, 0.941], 0.6);
        $pdf->text($plotX + 4, $yy - 3, (string)$tick['label'], 8, false, [0.39, 0.45, 0.55]);
    }
    if (($geo['kind'] ?? '') === 'bar') {
        if (isset($geo['zero_y'])) {
            $zy = $innerY + ((float)$geo['zero_y'] * $innerH);
            $pdf->line($innerX, $zy, $innerX + $innerW, $zy, [0.62, 0.66, 0.72], 0.8);
        }
        foreach ($geo['bars'] ?? [] as $rect) {
            $x0 = $innerX + ((float)$rect['x'] * $innerW);
            $bw = max(1.5, (float)$rect['w'] * $innerW);
            $y0 = $innerY + ((float)$rect['y0'] * $innerH);
            $y1 = $innerY + ((float)$rect['y1'] * $innerH);
            $yLo = min($y0, $y1);
            $bh = abs($y1 - $y0);
            if ($bh < 1.2) {
                $bh = 1.2;
                $yLo = $y1 >= $y0 ? $y0 : ($y0 - 1.2);
            }
            $pdf->rect($x0, $yLo, $bw, $bh, report_exec_hex_rgb(report_exec_safe_hex((string)$rect['color'])), null);
        }
        report_exec_pdf_chart_labels($pdf, $geo, $innerX, $innerW, $innerY, $plotY, $x, $y);
        return $y;
    }
    foreach ($geo['series'] as $series) {
        $rgb = report_exec_hex_rgb((string)$series['color']);
        $tint = report_exec_tint($rgb, 0.16);
        foreach ($series['segs'] as $seg) {
            $pts = [];
            foreach ($seg as $pt) {
                $pts[] = [
                    $innerX + ((float)$pt[0] * $innerW),
                    $innerY + ((float)$pt[1] * $innerH),
                ];
            }
            if (count($pts) >= 2) {
                $pdf->fillColor($tint);
                $pdf->area($pts, $innerY);
                $pdf->strokeColor($tint, 3.2);
                $pdf->polyline($pts);
                $pdf->strokeColor($rgb, 1.6);
                $pdf->polyline($pts);
            } elseif (count($pts) === 1) {
                $pdf->fillColor($rgb);
                $pdf->rect($pts[0][0] - 2.2, $pts[0][1] - 2.2, 4.4, 4.4, $rgb, null);
            }
        }
    }
    $xLabels = $geo['x_labels'];
    foreach ($xLabels as $i => $tick) {
        $xx = $innerX + ((float)$tick['x'] * $innerW);
        $label = (string)$tick['label'];
        $tw = $pdf->textWidth($label, 8);
        if ($i === 0) {
            $tx = $xx;
        } elseif ($i === count($xLabels) - 1) {
            $tx = $xx - $tw;
        } else {
            $tx = $xx - ($tw / 2);
        }
        $pdf->text($tx, $plotY + 6, $label, 8, false, [0.39, 0.45, 0.55]);
    }
    $lx = $x;
    $ly = $y + 4;
    foreach ($geo['series'] as $series) {
        $rgb = report_exec_hex_rgb((string)$series['color']);
        $pdf->strokeColor($rgb, 2.4);
        $pdf->line($lx, $ly + 3, $lx + 16, $ly + 3, $rgb, 2.4);
        $pdf->text($lx + 20, $ly, (string)$series['label'], 9, false, [0.12, 0.16, 0.22]);
        $lx += 28 + (strlen((string)$series['label']) * 5.2);
    }
    return $y;
}

/**
 * @param array<string,mixed> $geo
 */
function report_exec_pdf_chart_labels(ExecutiveReportPdf $pdf, array $geo, float $innerX, float $innerW, float $innerY, float $plotY, float $x, float $y): void
{
    $xLabels = is_array($geo['x_labels'] ?? null) ? $geo['x_labels'] : [];
    $bar = ($geo['kind'] ?? '') === 'bar';
    $last = count($xLabels) - 1;
    $tilt = $bar && count($xLabels) > 5;
    if ($tilt) {
        $deg = 40.0;
        $rad = deg2rad($deg);
        $cos = cos($rad);
        $sin = sin($rad);
        $anchorY = $innerY - 3.0;
        foreach ($xLabels as $tick) {
            $label = (string)$tick['label'];
            $tw = $pdf->textWidth($label, 8);
            $xx = $innerX + ((float)$tick['x'] * $innerW);
            $pdf->textRotated($xx - ($tw * $cos), $anchorY - ($tw * $sin), $label, 8, false, [0.39, 0.45, 0.55], $deg);
        }
    } else {
        foreach ($xLabels as $i => $tick) {
            $xx = $innerX + ((float)$tick['x'] * $innerW);
            $label = (string)$tick['label'];
            $tw = $pdf->textWidth($label, 8);
            if ($bar || ($i !== 0 && $i !== $last)) {
                $tx = $xx - ($tw / 2);
            } elseif ($i === 0) {
                $tx = $xx;
            } else {
                $tx = $xx - $tw;
            }
            $pdf->text($tx, $plotY + 6, $label, 8, false, [0.39, 0.45, 0.55]);
        }
    }
    $lx = $x;
    $ly = $y + 4;
    foreach ($geo['series'] ?? [] as $series) {
        if (!is_array($series)) {
            continue;
        }
        $rgb = report_exec_hex_rgb(report_exec_safe_hex((string)($series['color'] ?? '')));
        $pdf->line($lx, $ly + 3, $lx + 16, $ly + 3, $rgb, 2.4);
        $pdf->text($lx + 20, $ly, (string)($series['label'] ?? ''), 9, false, [0.12, 0.16, 0.22]);
        $lx += 28 + (strlen((string)($series['label'] ?? '')) * 5.2);
    }
}

/**
 * Charts to draw. A brief with a charts list wins. The climate brief still draws temperature, then humidity.
 *
 * @param array<string,mixed> $brief
 * @return list<array<string,mixed>>
 */
function report_exec_brief_charts(array $brief): array
{
    if (isset($brief['charts']) && is_array($brief['charts'])) {
        $charts = [];
        foreach ($brief['charts'] as $chart) {
            if (is_array($chart)) {
                $charts[] = $chart;
            }
        }
        return $charts;
    }
    $charts = [];
    if (is_array($brief['temp_chart'] ?? null)) {
        $charts[] = $brief['temp_chart'];
    }
    if (is_array($brief['hum_chart'] ?? null)) {
        $charts[] = $brief['hum_chart'];
    }
    return $charts;
}

/**
 * @param array<string,mixed> $brief
 */
function report_exec_extra_pages(array $brief): int
{
    $table = is_array($brief['table'] ?? null) ? $brief['table'] : [];
    $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
    if ($rows !== []) {
        return (int)ceil(count($rows) / 16);
    }
    $exceptions = $brief['exceptions'] ?? [];
    if (is_array($exceptions) && $exceptions !== []) {
        return (int)ceil(count($exceptions) / 18);
    }
    return 0;
}

/**
 * @param array<string,mixed> $brief
 */
function report_exec_pdf_header(ExecutiveReportPdf $pdf, array $brief, string $title): void
{
    $pdf->rect(0, 556, 792, 56, [0.07, 0.09, 0.14], null);
    $pdf->rect(0, 556, 792, 3, [0.23, 0.51, 0.96], null);
    $org = trim((string)($brief['org'] ?? ''));
    $brand = ($org !== '' && strcasecmp($org, 'My Organization') !== 0) ? $org : 'ColdAisle';
    $pdf->text(36, 586, report_exec_clip($brand, 28), 16, true, [1, 1, 1]);
    $pdf->text(36, 568, $title, 10, false, [0.73, 0.82, 0.91]);
    $context = trim((string)($brief['context'] ?? ''));
    if ($context === '') {
        $span = is_array($brief['span'] ?? null) ? $brief['span'] : [];
        $when = ucfirst((string)($span['label'] ?? 'the last 7 days'));
        $room = (string)($brief['room_label'] ?? 'All rooms');
        $context = $when . ' · ' . $room;
    }
    $right = report_exec_clip($context, 48);
    $rw = $pdf->textWidth($right, 9);
    $pdf->text(756 - $rw, 586, $right, 9, false, [0.80, 0.86, 0.93]);
    $gen = 'Generated ' . (string)($brief['generated'] ?? '');
    $gw = $pdf->textWidth($gen, 9);
    $pdf->text(756 - $gw, 570, $gen, 9, false, [0.58, 0.66, 0.76]);
}

function report_exec_cool_caption(array $brief): string
{
    $parts = [];
    if ($brief['supply_now'] !== null) {
        $parts[] = 'Supply now ' . report_fmt_temp($brief['supply_now']);
    }
    if ($brief['return_now'] !== null) {
        $parts[] = 'return now ' . report_fmt_temp($brief['return_now']);
    }
    if ($brief['cool_rh_now'] !== null) {
        $parts[] = 'cooling humidity now ' . report_fmt_rh($brief['cool_rh_now']);
    }
    if ($parts === []) {
        return '';
    }
    $parts[0] = ucfirst($parts[0]);
    return implode(' · ', $parts);
}

/**
 * @param array<string,mixed> $brief
 */
function report_exec_lead(array $brief, bool $includeHumidity = true): string
{
    $span = is_array($brief['span'] ?? null) ? $brief['span'] : [];
    $period = (string)($span['label'] ?? 'the last 7 days');
    $roomId = (int)($brief['room_id'] ?? 0);
    $where = $roomId > 0 ? ('In ' . (string)$brief['room_label'] . ', ') : '';
    $bits = [];
    $coldP = $brief['cold_period'] ?? null;
    $hotP = $brief['hot_period'] ?? null;
    $coldN = $brief['cold_now'] ?? null;
    $hotN = $brief['hot_now'] ?? null;
    if (!empty($brief['has_cold']) && $coldP !== null && !empty($brief['has_hot']) && $hotP !== null) {
        $delta = (float)$hotP - (float)$coldP;
        $bits[] = 'over ' . $period . ', the cold aisle averaged ' . report_exec_fmt_display((float)$coldP, (string)$brief['symbol'])
            . ' and the hot aisle averaged ' . report_exec_fmt_display((float)$hotP, (string)$brief['symbol'])
            . ' (' . report_exec_fmt_delta($delta, (string)$brief['symbol']) . ')';
    } elseif (!empty($brief['has_cold']) && $coldN !== null && !empty($brief['has_hot']) && $hotN !== null) {
        $coldD = report_exec_to_display((float)$coldN);
        $hotD = report_exec_to_display((float)$hotN);
        $bits[] = 'the cold aisle is ' . report_exec_fmt_display($coldD, (string)$brief['symbol'])
            . ' now and the hot aisle is ' . report_exec_fmt_display($hotD, (string)$brief['symbol']);
    } elseif (!empty($brief['has_cold']) && $coldN !== null) {
        $bits[] = 'the cold aisle is ' . report_fmt_temp($coldN) . ' now';
    } elseif (($brief['temp_period'] ?? null) !== null) {
        $bits[] = 'over ' . $period . ', temperature averaged ' . report_exec_fmt_display((float)$brief['temp_period'], (string)$brief['symbol']);
    } elseif (($brief['temp_now'] ?? null) !== null) {
        $bits[] = 'temperature is ' . report_fmt_temp($brief['temp_now']) . ' now';
    }
    if ($includeHumidity && ($brief['rh_period'] ?? null) !== null) {
        $bits[] = 'relative humidity averaged ' . report_exec_fmt_display((float)$brief['rh_period'], '%RH');
    } elseif ($includeHumidity && ($brief['rh_now'] ?? null) !== null) {
        $bits[] = 'relative humidity is ' . report_fmt_rh($brief['rh_now']) . ' now';
    }
    $outside = (int)($brief['outside'] ?? 0);
    if ($outside > 0) {
        $bits[] = $outside . ' sensor' . ($outside === 1 ? ' is' : 's are') . ' outside limits';
    } elseif ($bits !== []) {
        $bits[] = 'sensors with limits are inside them';
    }
    if ($bits === []) {
        $none = $includeHumidity
            ? 'no temperature or humidity readings yet'
            : 'no temperature readings yet';
        $head = $where !== '' ? ($where . $none) : ucfirst($none);
        return rtrim($head, '.') . '.';
    }
    $text = $where !== '' ? ($where . $bits[0]) : ucfirst($bits[0]);
    for ($i = 1, $c = count($bits); $i < $c; $i++) {
        $text .= '. ' . ucfirst($bits[$i]);
    }
    return $text . '.';
}

/**
 * @param array<string,array{avg:float,n:int}> $buckets
 */
function report_exec_period(array $buckets, bool $temp): ?float
{
    $sum = 0.0;
    $n = 0;
    foreach ($buckets as $bucket) {
        $count = (int)$bucket['n'];
        if ($count < 1) {
            continue;
        }
        $sum += ((float)$bucket['avg']) * $count;
        $n += $count;
    }
    if ($n < 1) {
        return null;
    }
    $avg = $sum / $n;
    return $temp ? report_exec_to_display($avg) : $avg;
}

/**
 * @param array<string,array<string,mixed>> $series
 */
function report_exec_period_merge(array $series, bool $temp): ?float
{
    $merged = [];
    foreach (['cold', 'hot', 'other'] as $key) {
        if (!isset($series[$key]['buckets'])) {
            continue;
        }
        foreach ($series[$key]['buckets'] as $bucket => $point) {
            $merged[$bucket . '|' . $key] = $point;
        }
    }
    return report_exec_period($merged, $temp);
}

/**
 * @param array<string,array<string,mixed>> $series
 * @param list<string> $labels
 * @return array{title:string,unit:string,labels:list<string>,series:list<array<string,mixed>>}
 */
function report_exec_chart(string $title, string $unit, array $labels, array $series, int $stepSec, bool $temp): array
{
    $out = [];
    foreach ($series as $item) {
        $points = [];
        $any = false;
        foreach ($labels as $label) {
            if (isset($item['buckets'][$label])) {
                $avg = (float)$item['buckets'][$label]['avg'];
                $points[] = $temp ? report_exec_to_display($avg) : $avg;
                $any = true;
            } else {
                $points[] = null;
            }
        }
        if (!$any) {
            continue;
        }
        $out[] = [
            'key' => $item['key'],
            'label' => $item['label'],
            'color' => $item['color'],
            'points' => $points,
        ];
    }
    $pretty = [];
    foreach ($labels as $label) {
        $ts = strtotime($label) ?: time();
        $pretty[] = $stepSec >= 86400 ? date('M j', $ts) : date('M j ga', $ts);
    }
    return [
        'kind' => 'line',
        'title' => $title,
        'unit' => $unit,
        'labels' => $pretty,
        'series' => $out,
        'empty' => 'No samples in this period',
    ];
}

/**
 * @param array<string,mixed> $chart
 * @return array<string,mixed>
 */
function report_exec_geometry(array $chart): array
{
    if (($chart['kind'] ?? 'line') === 'bar') {
        return report_exec_bar_geometry($chart);
    }
    $series = is_array($chart['series'] ?? null) ? $chart['series'] : [];
    $labels = is_array($chart['labels'] ?? null) ? $chart['labels'] : [];
    $n = count($labels);
    $vals = [];
    foreach ($series as $item) {
        foreach ($item['points'] ?? [] as $point) {
            if ($point !== null && is_numeric($point)) {
                $vals[] = (float)$point;
            }
        }
    }
    if ($vals === [] || $n < 1) {
        return ['empty' => true, 'series' => [], 'x_labels' => [], 'y_labels' => []];
    }
    $ticks = report_exec_ticks(min($vals), max($vals));
    $min = $ticks[0];
    $max = $ticks[count($ticks) - 1];
    $span = $max - $min;
    if ($span <= 0) {
        $span = 1.0;
    }
    $drawn = [];
    foreach ($series as $item) {
        $segs = [];
        $cur = [];
        $points = array_values($item['points'] ?? []);
        $count = count($points);
        for ($i = 0; $i < $count; $i++) {
            $point = $points[$i];
            if ($point === null || !is_numeric($point)) {
                if ($cur !== []) {
                    $segs[] = $cur;
                    $cur = [];
                }
                continue;
            }
            $x = $n <= 1 ? 0.5 : ($i / max(1, $n - 1));
            $y = ((float)$point - $min) / $span;
            $cur[] = [$x, $y];
        }
        if ($cur !== []) {
            $segs[] = $cur;
        }
        if ($segs !== []) {
            $drawn[] = [
                'label' => (string)($item['label'] ?? ''),
                'color' => (string)($item['color'] ?? '#38bdf8'),
                'segs' => $segs,
            ];
        }
    }
    $xLabels = [];
    if ($n === 1) {
        $xLabels[] = ['x' => 0.5, 'label' => (string)$labels[0]];
    } else {
        $idxs = array_values(array_unique([0, (int)floor(($n - 1) / 2), $n - 1]));
        foreach ($idxs as $idx) {
            $xLabels[] = ['x' => $idx / ($n - 1), 'label' => (string)$labels[$idx]];
        }
    }
    $yLabels = [];
    foreach ($ticks as $tick) {
        $yLabels[] = [
            'y' => ($tick - $min) / $span,
            'label' => report_exec_tick_label($tick),
        ];
    }
    return [
        'kind' => 'line',
        'empty' => $drawn === [],
        'series' => $drawn,
        'bars' => [],
        'x_labels' => $xLabels,
        'y_labels' => $yLabels,
    ];
}

/**
 * Bars grow from zero when every value is at least zero.
 * A mix of positive and negative values, such as warranty days, keeps the zero line inside the plot.
 *
 * @param array<string,mixed> $chart
 * @return array<string,mixed>
 */
function report_exec_bar_geometry(array $chart): array
{
    $seriesIn = is_array($chart['series'] ?? null) ? $chart['series'] : [];
    $labels = is_array($chart['labels'] ?? null) ? array_values($chart['labels']) : [];
    $n = count($labels);
    $vals = [];
    $usable = [];
    foreach ($seriesIn as $item) {
        if (!is_array($item)) {
            continue;
        }
        $any = false;
        foreach ($item['points'] ?? [] as $point) {
            if ($point !== null && is_numeric($point)) {
                $vals[] = (float)$point;
                $any = true;
            }
        }
        if ($any) {
            $usable[] = $item;
        }
    }
    if ($vals === [] || $n < 1 || $usable === []) {
        return ['kind' => 'bar', 'empty' => true, 'series' => [], 'bars' => [], 'x_labels' => [], 'y_labels' => []];
    }
    $minV = min($vals);
    $maxV = max($vals);
    if ($minV >= 0) {
        $minV = 0.0;
    }
    if ($maxV <= 0) {
        $maxV = 0.0;
    }
    if ($maxV <= $minV) {
        $maxV = $minV + 1.0;
    }
    $ticks = report_exec_ticks($minV, $maxV);
    $min = $ticks[0];
    $max = $ticks[count($ticks) - 1];
    $span = $max - $min;
    if ($span <= 0) {
        $span = 1.0;
    }
    $seriesCount = count($usable);
    $slot = 1.0 / $n;
    $inner = min($slot * 0.78, $seriesCount > 1 ? 0.28 : 0.14);
    $gap = ($slot - $inner) / 2.0;
    $seriesSpan = $inner / $seriesCount;
    $barW = $seriesSpan * 0.86;
    $bars = [];
    $legend = [];
    foreach ($usable as $sIndex => $item) {
        $baseColor = report_exec_safe_hex((string)($item['color'] ?? '#0ea5e9'));
        $pointColors = is_array($item['colors'] ?? null) ? $item['colors'] : [];
        $legend[] = [
            'label' => (string)($item['label'] ?? ''),
            'color' => $baseColor,
            'segs' => [],
        ];
        $points = array_values($item['points'] ?? []);
        for ($i = 0; $i < $n; $i++) {
            $point = $points[$i] ?? null;
            if ($point === null || !is_numeric($point)) {
                continue;
            }
            $color = $baseColor;
            if (isset($pointColors[$i]) && is_string($pointColors[$i]) && $pointColors[$i] !== '') {
                $color = report_exec_safe_hex($pointColors[$i]);
            }
            $x = ($i * $slot) + $gap + ($sIndex * $seriesSpan) + (($seriesSpan - $barW) / 2.0);
            $bars[] = [
                'x' => $x,
                'w' => $barW,
                'y0' => report_exec_unit((0.0 - $min) / $span),
                'y1' => report_exec_unit(((float)$point - $min) / $span),
                'color' => $color,
            ];
        }
    }
    $clipAt = $n <= 4 ? 22 : ($n <= 6 ? 16 : ($n <= 8 ? 12 : 9));
    $xLabels = [];
    for ($i = 0; $i < $n; $i++) {
        $xLabels[] = [
            'x' => ($i + 0.5) * $slot,
            'label' => report_exec_clip((string)$labels[$i], $clipAt),
        ];
    }
    $yLabels = [];
    foreach ($ticks as $tick) {
        $yLabels[] = [
            'y' => ($tick - $min) / $span,
            'label' => report_exec_tick_label($tick),
        ];
    }
    return [
        'kind' => 'bar',
        'empty' => $bars === [],
        'series' => $seriesCount > 1 ? $legend : [],
        'bars' => $bars,
        'x_labels' => $xLabels,
        'y_labels' => $yLabels,
        'zero_y' => report_exec_unit((0.0 - $min) / $span),
    ];
}

function report_exec_unit(float $n): float
{
    if ($n < 0) {
        return 0.0;
    }
    if ($n > 1) {
        return 1.0;
    }
    return $n;
}

function report_exec_safe_hex(string $hex): string
{
    $hex = strtolower(trim($hex));
    if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) {
        return '#0ea5e9';
    }
    return $hex;
}

/** @return list<float> */
function report_exec_ticks(float $min, float $max): array
{
    $span = $max - $min;
    if ($span < 0.05) {
        $min -= 1;
        $max += 1;
        $span = $max - $min;
    }
    $rough = $span / 4;
    $pow = pow(10, floor(log10(max($rough, 0.0001))));
    $err = $rough / $pow;
    if ($err > 7.5) {
        $step = 10 * $pow;
    } elseif ($err > 3.5) {
        $step = 5 * $pow;
    } elseif ($err > 1.5) {
        $step = 2 * $pow;
    } else {
        $step = $pow;
    }
    if ($step <= 0) {
        $step = 1;
    }
    $start = floor($min / $step) * $step;
    $end = ceil($max / $step) * $step;
    $ticks = [];
    for ($v = $start; $v <= $end + ($step * 0.01); $v += $step) {
        $ticks[] = round($v, 6);
        if (count($ticks) > 6) {
            break;
        }
    }
    if (count($ticks) < 2) {
        return [$min - 1, $max + 1];
    }
    return $ticks;
}

function report_exec_tick_label(float $tick): string
{
    $abs = abs($tick);
    $decimals = $abs >= 10 ? 0 : 1;
    $s = number_format($tick, $decimals, '.', '');
    if ($decimals === 1) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s === '' || $s === '-' ? '0' : $s;
}

/**
 * @return array{headers:list<string>,rows:list<list<string>>}
 */
function report_exec_csv_table(array $tempChart, array $humChart): array
{
    $symbol = (string)($tempChart['unit'] ?? 'C');
    $headers = [
        'Time',
        'Cold aisle (' . $symbol . ')',
        'Hot aisle (' . $symbol . ')',
        'Room (' . $symbol . ')',
        'Supply (' . $symbol . ')',
        'Return (' . $symbol . ')',
        'Sensor humidity (%RH)',
        'Cooling humidity (%RH)',
    ];
    $keys = ['cold', 'hot', 'other', 'supply', 'return'];
    $rowsByTime = [];
    $labels = $tempChart['labels'] ?? [];
    if (!is_array($labels) || $labels === []) {
        $labels = $humChart['labels'] ?? [];
    }
    if (!is_array($labels)) {
        $labels = [];
    }
    foreach ($labels as $i => $label) {
        $rowsByTime[$i] = array_fill(0, 7, '');
        $rowsByTime[$i][0] = (string)$label;
    }
    $map = ['cold' => 1, 'hot' => 2, 'other' => 3, 'supply' => 4, 'return' => 5];
    foreach ($tempChart['series'] ?? [] as $series) {
        $col = $map[$series['key'] ?? ''] ?? null;
        if ($col === null) {
            continue;
        }
        foreach ($series['points'] ?? [] as $i => $point) {
            if (!isset($rowsByTime[$i])) {
                continue;
            }
            if ($point !== null && is_numeric($point)) {
                $rowsByTime[$i][$col] = number_format((float)$point, 1, '.', '');
            }
        }
    }
    $hmap = ['sensors' => 6, 'cooling' => 7];
    foreach ($humChart['series'] ?? [] as $series) {
        $col = $hmap[$series['key'] ?? ''] ?? null;
        if ($col === null) {
            continue;
        }
        foreach ($series['points'] ?? [] as $i => $point) {
            if (!isset($rowsByTime[$i]) || $point === null || !is_numeric($point)) {
                continue;
            }
            $rowsByTime[$i][$col] = number_format((float)$point, 1, '.', '');
        }
    }
    return ['headers' => $headers, 'rows' => array_values($rowsByTime)];
}

/** @return list<array<string,mixed>> */
function report_exec_sensors(int $roomId): array
{
    $roomSql = $roomId > 0 ? ' AND s.room_id = ?' : '';
    $params = $roomId > 0 ? [$roomId] : [];
    $sql = 'SELECT s.sensor_id, s.name, s.sensor_kind, s.placement, s.last_value, s.last_humidity,
                   s.warn_low, s.warn_high, s.crit_low, s.crit_high, s.room_id, rm.name AS room_name
            FROM env_sensors s
            LEFT JOIN rooms rm ON rm.room_id = s.room_id
            WHERE s.is_active = 1' . $roomSql . '
            ORDER BY s.name';
    return report_exec_try($sql, $params) ?? [];
}

/** @return list<array<string,mixed>> */
function report_exec_temp_rows(string $since, int $roomId, int $step): array
{
    $step = max(1, $step);
    $bucket = "DATEADD(hour, (DATEDIFF(hour, '20000101', r.recorded_at) / $step) * $step, '20000101')";
    $lane = "CASE
        WHEN LOWER(REPLACE(REPLACE(ISNULL(s.placement, ''), '-', '_'), ' ', '_'))
             IN ('cold_aisle', 'intake', 'equipment_intake', 'supply_air') THEN 'cold'
        WHEN LOWER(REPLACE(REPLACE(ISNULL(s.placement, ''), '-', '_'), ' ', '_'))
             IN ('hot_aisle', 'exhaust', 'return_air') THEN 'hot'
        ELSE 'other'
    END";
    $roomSql = $roomId > 0 ? ' AND s.room_id = ?' : '';
    $base = "SELECT $bucket AS bucket, $lane AS lane, AVG(r.value) AS avg_v, COUNT(*) AS n
             FROM env_readings r
             INNER JOIN env_sensors s ON s.sensor_id = r.sensor_id
             WHERE s.is_active = 1
               AND r.recorded_at >= ?
               AND s.sensor_kind IN ('temperature', 'temp_humidity')
               AND r.value BETWEEN -40 AND 80
               $roomSql
               AND %s
             GROUP BY $bucket, $lane";
    $params = [$since];
    if ($roomId > 0) {
        $params[] = $roomId;
    }
    $metric = "(r.metric IS NULL OR r.metric = '' OR r.metric IN ('temperature', 'temp', 'celsius', 'primary'))";
    $rows = report_exec_try(sprintf($base, $metric), $params);
    if ($rows === null) {
        $rows = report_exec_try(sprintf($base, '1 = 1'), $params) ?? [];
    }
    return $rows;
}

/** @return list<array<string,mixed>> */
function report_exec_hum_rows(string $since, int $roomId, int $step): array
{
    $step = max(1, $step);
    $bucket = "DATEADD(hour, (DATEDIFF(hour, '20000101', r.recorded_at) / $step) * $step, '20000101')";
    $roomSql = $roomId > 0 ? ' AND s.room_id = ?' : '';
    $base = "SELECT $bucket AS bucket, AVG(r.value) AS avg_v, COUNT(*) AS n
             FROM env_readings r
             INNER JOIN env_sensors s ON s.sensor_id = r.sensor_id
             WHERE s.is_active = 1
               AND r.recorded_at >= ?
               AND r.value BETWEEN 0 AND 100
               $roomSql
               AND %s
             GROUP BY $bucket";
    $params = [$since];
    if ($roomId > 0) {
        $params[] = $roomId;
    }
    $metric = "((s.sensor_kind = 'humidity' AND (r.metric IS NULL OR r.metric = '' OR r.metric IN ('humidity', 'primary')))
                OR (s.sensor_kind = 'temp_humidity' AND r.metric = 'humidity'))";
    $rows = report_exec_try(sprintf($base, $metric), $params);
    if ($rows === null) {
        $rows = report_exec_try(sprintf($base, "s.sensor_kind = 'humidity'"), $params) ?? [];
    }
    return $rows;
}

/** @return list<array<string,mixed>> */
function report_exec_cool_rows(string $since, int $roomId, int $step): array
{
    $step = max(1, $step);
    $bucket = "DATEADD(hour, (DATEDIFF(hour, '20000101', c.polled_at) / $step) * $step, '20000101')";
    $roomSql = $roomId > 0 ? ' AND u.room_id = ?' : '';
    $params = [$since];
    if ($roomId > 0) {
        $params[] = $roomId;
    }
    $sql = "SELECT $bucket AS bucket,
                   AVG(CASE WHEN c.supply_temp_c BETWEEN -40 AND 80 THEN c.supply_temp_c END) AS supply_v,
                   AVG(CASE WHEN c.return_temp_c BETWEEN -40 AND 80 THEN c.return_temp_c END) AS return_v,
                   AVG(CASE WHEN c.humidity BETWEEN 0 AND 100 THEN c.humidity END) AS hum_v,
                   SUM(CASE WHEN c.supply_temp_c BETWEEN -40 AND 80 THEN 1 ELSE 0 END) AS supply_n,
                   SUM(CASE WHEN c.return_temp_c BETWEEN -40 AND 80 THEN 1 ELSE 0 END) AS return_n,
                   SUM(CASE WHEN c.humidity BETWEEN 0 AND 100 THEN 1 ELSE 0 END) AS hum_n
            FROM cooling_readings c
            INNER JOIN cooling_units u ON u.cooling_unit_id = c.cooling_unit_id
            WHERE u.is_active = 1
              AND c.polled_at >= ?
              $roomSql
            GROUP BY $bucket";
    return report_exec_try($sql, $params) ?? [];
}

/** @return array{supply:?float,return:?float,humidity:?float} */
function report_exec_cool_now(int $roomId): array
{
    $out = ['supply' => null, 'return' => null, 'humidity' => null];
    $roomSql = $roomId > 0 ? ' AND u.room_id = ?' : '';
    $params = $roomId > 0 ? [$roomId] : [];
    $rows = report_exec_try(
        'SELECT u.last_poll_json FROM cooling_units u WHERE u.is_active = 1' . $roomSql,
        $params
    ) ?? [];
    $supply = [];
    $return = [];
    $hum = [];
    foreach ($rows as $row) {
        $snap = function_exists('cooling_poll_snapshot_promote')
            ? cooling_poll_snapshot_promote($row['last_poll_json'] ?? null)
            : [];
        $s = report_exec_num($snap['supply_temp'] ?? null);
        $r = report_exec_num($snap['return_temp'] ?? null);
        $h = report_exec_num($snap['humidity'] ?? null);
        if ($s !== null && report_exec_temp_ok($s)) {
            $supply[] = $s;
        }
        if ($r !== null && report_exec_temp_ok($r)) {
            $return[] = $r;
        }
        if ($h !== null && report_exec_rh_ok($h)) {
            $hum[] = $h;
        }
    }
    $out['supply'] = report_exec_avg($supply);
    $out['return'] = report_exec_avg($return);
    $out['humidity'] = report_exec_avg($hum);
    return $out;
}

/** @return array<string,mixed> */
function report_exec_blank_series(string $key, string $label, string $color): array
{
    return ['key' => $key, 'label' => $label, 'color' => $color, 'buckets' => []];
}

/**
 * @param array<string,mixed> $sensor
 * @return array<string,mixed>
 */
function report_exec_exception(array $sensor, string $point, string $kind, string $current, string $status, bool $pointReady = false): array
{
    $place = $point;
    if (!$pointReady) {
        $place = $point !== '' ? $point : 'Temperature';
    }
    return [
        'name' => (string)($sensor['name'] ?? ''),
        'room' => (string)($sensor['room_name'] ?? ''),
        'point' => $place,
        'current' => $current,
        'status' => $status,
        'href' => 'pages/env_sensors.php?id=' . (int)($sensor['sensor_id'] ?? 0),
        'kind' => $kind,
    ];
}

function report_exec_kpi(string $key, string $label, ?float $nowC, ?float $periodDisplay, int $count, bool $temp, string $tone): array
{
    $symbol = class_exists('TempUnitService') ? TempUnitService::symbol() : '°C';
    if ($count < 1 && $key !== 'alerts') {
        return [
            'key' => $key,
            'label' => $label,
            'value' => '—',
            'sub' => 'No sensors',
            'tone' => $tone,
        ];
    }
    if ($temp) {
        $value = $nowC === null ? '—' : report_fmt_temp($nowC);
        if ($periodDisplay !== null) {
            $sub = 'Period average ' . report_exec_fmt_display($periodDisplay, $symbol);
        } elseif ($nowC !== null) {
            $sub = 'Latest reading';
        } else {
            $sub = 'No current reading';
        }
    } else {
        $value = $nowC === null ? '—' : report_fmt_rh($nowC);
        if ($periodDisplay !== null) {
            $sub = 'Period average ' . report_exec_fmt_display($periodDisplay, '%RH');
        } elseif ($nowC !== null) {
            $sub = 'Latest reading';
        } else {
            $sub = 'No current reading';
        }
    }
    if ($count > 0) {
        $sub .= ' · ' . $count . ' sensor' . ($count === 1 ? '' : 's');
    }
    return ['key' => $key, 'label' => $label, 'value' => $value, 'sub' => $sub, 'tone' => $tone];
}

function report_exec_room_label(int $roomId): string
{
    if ($roomId < 1) {
        return 'All rooms';
    }
    foreach (report_climate_rooms() as $room) {
        if ((int)($room['room_id'] ?? 0) !== $roomId) {
            continue;
        }
        $dc = trim((string)($room['dc_name'] ?? ''));
        $name = (string)($room['name'] ?? '');
        return $dc !== '' ? ($dc . ' / ' . $name) : $name;
    }
    return 'Room';
}

function report_exec_lane(string $placement): string
{
    $p = strtolower(str_replace(['-', ' '], '_', trim($placement)));
    if (in_array($p, ['cold_aisle', 'intake', 'equipment_intake', 'supply_air'], true)) {
        return 'cold';
    }
    if (in_array($p, ['hot_aisle', 'exhaust', 'return_air'], true)) {
        return 'hot';
    }
    return 'other';
}

/** @param list<float> $vals */
function report_exec_avg(array $vals): ?float
{
    if ($vals === []) {
        return null;
    }
    return array_sum($vals) / count($vals);
}

function report_exec_num(mixed $v): ?float
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return null;
    }
    return (float)$v;
}

function report_exec_temp_ok(float $c): bool
{
    return $c >= -40 && $c <= 80;
}

function report_exec_rh_ok(float $rh): bool
{
    return $rh >= 0 && $rh <= 100;
}

function report_exec_to_display(float $celsius): float
{
    if (class_exists('TempUnitService')) {
        $v = TempUnitService::fromC($celsius);
        if ($v !== null) {
            return $v;
        }
    }
    return $celsius;
}

function report_exec_fmt_display(float $display, string $suffix): string
{
    $s = rtrim(rtrim(number_format($display, 1, '.', ''), '0'), '.');
    if ($s === '' || $s === '-' || $s === '-0') {
        $s = '0';
    }
    return $s . ' ' . $suffix;
}

function report_exec_fmt_delta(float $displayDelta, string $suffix): string
{
    $abs = report_exec_fmt_display(abs($displayDelta), $suffix);
    if ($displayDelta > 0.05) {
        return $abs . ' warmer';
    }
    if ($displayDelta < -0.05) {
        return $abs . ' cooler';
    }
    return 'about the same';
}

function report_exec_bucket_key(mixed $raw): string
{
    if ($raw instanceof DateTimeInterface) {
        $raw = $raw->format('Y-m-d H:i:s');
    }
    $text = trim((string)$raw);
    $text = preg_replace('/\.\d+$/', '', $text) ?? $text;
    $ts = strtotime($text);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d H:i:s', $ts);
}

/** @return list<array<string,mixed>>|null */
function report_exec_try(string $sql, array $params): ?array
{
    try {
        return Database::fetchAll($sql, $params);
    } catch (Throwable $e) {
        return null;
    }
}

function report_exec_px(float $n): string
{
    return number_format($n, 1, '.', '');
}

function report_exec_clip(string $text, int $max): string
{
    if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
        return rtrim(mb_substr($text, 0, max(1, $max - 1))) . '…';
    }
    if (strlen($text) > $max) {
        return rtrim(substr($text, 0, max(1, $max - 1))) . '...';
    }
    return $text;
}

/** @return list<string> */
function report_exec_wrap(string $text, float $size, float $maxW): array
{
    $words = preg_split('/\s+/', trim($text)) ?: [];
    $lines = [];
    $cur = '';
    $pdf = new ExecutiveReportPdf();
    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        $try = $cur === '' ? $word : ($cur . ' ' . $word);
        if ($pdf->textWidth($try, $size) <= $maxW) {
            $cur = $try;
            continue;
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        $cur = $word;
    }
    if ($cur !== '') {
        $lines[] = $cur;
    }
    return $lines;
}

/** @param array{0:float,1:float,2:float} $rgb */
function report_exec_tint(array $rgb, float $amount): array
{
    $amount = max(0.0, min(1.0, $amount));
    return [
        (1 - $amount) + ($rgb[0] * $amount),
        (1 - $amount) + ($rgb[1] * $amount),
        (1 - $amount) + ($rgb[2] * $amount),
    ];
}

/** @return array{0:float,1:float,2:float} */
function report_exec_hex_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return [0.22, 0.51, 0.96];
    }
    return [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    ];
}

/**
 * One-page (or two) vector PDF. Standard fonts only, so Windows IIS needs no extra library.
 */
final class ExecutiveReportPdf
{
    /** @var list<string> */
    private array $streams = [];

    private string $ops = '';

    public function rect(float $x, float $y, float $w, float $h, ?array $fill, ?array $stroke): void
    {
        $this->ops .= $this->n($x) . ' ' . $this->n($y) . ' ' . $this->n($w) . ' ' . $this->n($h) . " re\n";
        if ($fill !== null) {
            $this->ops .= $this->rgb($fill) . " rg\n";
        }
        if ($stroke !== null) {
            $this->ops .= $this->rgb($stroke) . " RG\n0.6 w\n";
        }
        if ($fill !== null && $stroke !== null) {
            $this->ops .= "B\n";
        } elseif ($fill !== null) {
            $this->ops .= "f\n";
        } else {
            $this->ops .= "S\n";
        }
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $width): void
    {
        $this->ops .= $this->rgb($rgb) . " RG\n" . $this->n($width) . " w\n";
        $this->ops .= $this->n($x1) . ' ' . $this->n($y1) . " m\n";
        $this->ops .= $this->n($x2) . ' ' . $this->n($y2) . " l\nS\n";
    }

    /** @param list<array{0:float,1:float}> $pts */
    public function polyline(array $pts): void
    {
        if (count($pts) < 2) {
            return;
        }
        $this->ops .= $this->n($pts[0][0]) . ' ' . $this->n($pts[0][1]) . " m\n";
        for ($i = 1, $c = count($pts); $i < $c; $i++) {
            $this->ops .= $this->n($pts[$i][0]) . ' ' . $this->n($pts[$i][1]) . " l\n";
        }
        $this->ops .= "S\n";
    }

    /** @param list<array{0:float,1:float}> $pts */
    public function area(array $pts, float $baseY): void
    {
        if (count($pts) < 2) {
            return;
        }
        $this->ops .= $this->n($pts[0][0]) . ' ' . $this->n($pts[0][1]) . " m\n";
        for ($i = 1, $c = count($pts); $i < $c; $i++) {
            $this->ops .= $this->n($pts[$i][0]) . ' ' . $this->n($pts[$i][1]) . " l\n";
        }
        $last = $pts[count($pts) - 1];
        $this->ops .= $this->n($last[0]) . ' ' . $this->n($baseY) . " l\n";
        $this->ops .= $this->n($pts[0][0]) . ' ' . $this->n($baseY) . " l\nh\nf\n";
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    public function fillColor(array $rgb): void
    {
        $this->ops .= $this->rgb($rgb) . " rg\n";
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    public function strokeColor(array $rgb, float $width): void
    {
        $this->ops .= $this->rgb($rgb) . ' RG ' . $this->n($width) . " w\n";
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    public function text(float $x, float $y, string $text, float $size, bool $bold, array $rgb): void
    {
        $font = $bold ? '/F2' : '/F1';
        $literal = report_exec_pdf_literal(report_exec_winansi($text));
        $this->ops .= "BT\n" . $this->rgb($rgb) . " rg\n" . $font . ' ' . $this->n($size) . " Tf\n";
        $this->ops .= '1 0 0 1 ' . $this->n($x) . ' ' . $this->n($y) . " Tm\n" . $literal . " Tj\nET\n";
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    public function textRotated(float $x, float $y, string $text, float $size, bool $bold, array $rgb, float $degrees): void
    {
        $font = $bold ? '/F2' : '/F1';
        $literal = report_exec_pdf_literal(report_exec_winansi($text));
        $rad = deg2rad($degrees);
        $this->ops .= "BT\n" . $this->rgb($rgb) . " rg\n" . $font . ' ' . $this->n($size) . " Tf\n";
        $this->ops .= $this->n(cos($rad)) . ' ' . $this->n(sin($rad)) . ' ' . $this->n(-sin($rad)) . ' ' . $this->n(cos($rad));
        $this->ops .= ' ' . $this->n($x) . ' ' . $this->n($y) . " Tm\n" . $literal . " Tj\nET\n";
    }

    public function textWidth(string $text, float $size): float
    {
        $encoded = report_exec_winansi($text);
        $width = 0.0;
        $len = strlen($encoded);
        for ($i = 0; $i < $len; $i++) {
            $ch = $encoded[$i];
            $width += match ($ch) {
                ' ', 'i', 'l', 'I', 'j', 't', 'f', 'r' => 0.28,
                'm', 'w', 'M', 'W' => 0.78,
                default => ctype_upper($ch) ? 0.62 : 0.5,
            };
        }
        return $width * $size;
    }

    public function endPage(): void
    {
        $this->streams[] = $this->ops;
        $this->ops = '';
    }

    public function render(): string
    {
        if ($this->ops !== '') {
            $this->endPage();
        }
        if ($this->streams === []) {
            $this->streams[] = '';
        }
        $fontRegular = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $fontBold = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds = [];
        $next = 3;
        foreach ($this->streams as $unused) {
            $pageIds[] = $next;
            $next += 2;
        }
        $fontRegularId = $next;
        $fontBoldId = $next + 1;
        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds));
        $objects[] = '<< /Type /Pages /Count ' . count($pageIds) . ' /Kids [' . $kids . '] >>';
        foreach ($this->streams as $i => $stream) {
            $pageId = $pageIds[$i];
            $contentId = $pageId + 1;
            $objects[$pageId - 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] /Contents ' . $contentId . ' 0 R /Resources << /Font << /F1 ' . $fontRegularId . ' 0 R /F2 ' . $fontBoldId . ' 0 R >> >> >>';
            $objects[$contentId - 1] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }
        $objects[$fontRegularId - 1] = $fontRegular;
        $objects[$fontBoldId - 1] = $fontBold;
        ksort($objects);

        $out = "%PDF-1.4\n";
        $offsets = [0];
        $count = count($objects);
        for ($i = 1; $i <= $count; $i++) {
            $offsets[$i] = strlen($out);
            $out .= $i . " 0 obj\n" . $objects[$i - 1] . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref\n0 ' . ($count + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . ($count + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $out;
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    private function rgb(array $rgb): string
    {
        return $this->n($rgb[0]) . ' ' . $this->n($rgb[1]) . ' ' . $this->n($rgb[2]);
    }

    private function n(float $n): string
    {
        return number_format($n, 2, '.', '');
    }
}

function report_exec_winansi(string $text): string
{
    $text = str_replace(
        ["\r", "\n", '’', '‘', '“', '”'],
        [' ', ' ', "'", "'", '"', '"'],
        $text
    );
    $out = function_exists('iconv') ? iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) : false;
    if ($out === false) {
        $text = str_replace('°', "\xB0", $text);
        $out = preg_replace('/[^\x20-\x7E\x80-\x9F\xA0-\xFF]/', '', $text) ?? '';
    }
    return str_replace("\x00", '', $out);
}

function report_exec_pdf_literal(string $encoded): string
{
    return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded) . ')';
}

require_once __DIR__ . '/executive_briefs.php';
