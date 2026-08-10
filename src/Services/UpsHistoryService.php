<?php
/**
 * UPS history samples and time-series for charts (site / zone / unit).
 * Samples written on each successful UPS SNMP poll.
 */
declare(strict_types=1);

class UpsHistoryService
{
    public const RETENTION_DAYS = 400;

    /**
     * Persist a sample after poll.
     *
     * @param array{
     *   input_voltage?:?float,output_voltage?:?float,
     *   input_freq?:?float,output_freq?:?float,output_current?:?float
     * } $electrical
     */
    public static function recordSample(
        int $upsId,
        ?float $loadPct,
        ?float $batteryPct,
        ?float $runtimeMin = null,
        ?string $outputStatus = null,
        ?float $estimatedWatts = null,
        array $electrical = []
    ): void {
        if ($upsId < 1) {
            return;
        }
        $inV = isset($electrical['input_voltage']) && is_numeric($electrical['input_voltage'])
            ? (float)$electrical['input_voltage'] : null;
        $outV = isset($electrical['output_voltage']) && is_numeric($electrical['output_voltage'])
            ? (float)$electrical['output_voltage'] : null;
        $inHz = isset($electrical['input_freq']) && is_numeric($electrical['input_freq'])
            ? (float)$electrical['input_freq'] : null;
        $outHz = isset($electrical['output_freq']) && is_numeric($electrical['output_freq'])
            ? (float)$electrical['output_freq'] : null;
        $outA = isset($electrical['output_current']) && is_numeric($electrical['output_current'])
            ? (float)$electrical['output_current'] : null;

        if ($loadPct === null && $batteryPct === null && $runtimeMin === null && $estimatedWatts === null
            && $inV === null && $outV === null && $inHz === null && $outHz === null && $outA === null
        ) {
            return;
        }
        $row = [
            'ups_id' => $upsId,
            'load_pct' => $loadPct,
            'battery_pct' => $batteryPct,
            'runtime_min' => $runtimeMin,
            'output_status' => $outputStatus !== null && $outputStatus !== ''
                ? mb_substr($outputStatus, 0, 80) : null,
            'estimated_watts' => $estimatedWatts,
            'polled_at' => date('Y-m-d H:i:s'),
        ];
        // Extended electrical columns (Schema::ensure may add these)
        $extra = [
            'input_voltage' => $inV,
            'output_voltage' => $outV,
            'input_freq' => $inHz,
            'output_freq' => $outHz,
            'output_current' => $outA,
        ];
        try {
            Database::insert('ups_readings', array_merge($row, $extra));
        } catch (Throwable $e) {
            try {
                Database::insert('ups_readings', $row);
            } catch (Throwable $e2) {
                App::log('UpsHistory sample failed: ' . $e2->getMessage(), 'warning');
            }
        }
        if (random_int(1, 100) === 1) {
            self::pruneOld();
        }
    }

    public static function pruneOld(): void
    {
        try {
            $days = self::RETENTION_DAYS;
            Database::query(
                "DELETE FROM ups_readings WHERE polled_at < DATEADD(DAY, -{$days}, SYSUTCDATETIME())"
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * @return array{
     *   ok:bool,scope:string,scope_id:?int,hours:int,bucket_minutes:int,
     *   series:array<string,mixed>, outages:list, meta:array<string,mixed>
     * }
     */
    public static function series(
        string $scope,
        ?int $scopeId = null,
        int $hours = 24,
        ?string $fromIso = null,
        ?string $toIso = null
    ): array {
        $scope = strtolower(trim($scope));
        if ($scope === 'site') {
            $scope = 'ups_site';
        }
        if ($scope === 'zone') {
            $scope = 'ups_zone';
        }
        if ($scope === 'ups_unit') {
            $scope = 'ups';
        }
        if (!in_array($scope, ['ups_site', 'ups_zone', 'ups'], true)) {
            return [
                'ok' => false,
                'error' => 'Invalid UPS history scope',
                'scope' => $scope,
                'scope_id' => $scopeId,
                'hours' => $hours,
                'bucket_minutes' => 5,
                'series' => self::emptySeries(),
                'outages' => [],
                'meta' => [],
            ];
        }

        $hoursEff = max(1, min(24 * 400, $hours));
        $toTs = time();
        $fromTs = $toTs - ($hoursEff * 3600);
        if ($fromIso) {
            $t = strtotime($fromIso);
            if ($t !== false) {
                $fromTs = $t;
            }
        }
        if ($toIso) {
            $t = strtotime($toIso);
            if ($t !== false) {
                $toTs = $t;
            }
        }
        if ($toTs <= $fromTs) {
            $toTs = $fromTs + 3600;
        }
        $spanH = max(1, (int)ceil(($toTs - $fromTs) / 3600));
        $bucketMin = $spanH <= 48 ? 5 : ($spanH <= 24 * 14 ? 15 : ($spanH <= 24 * 90 ? 60 : 360));

        $fromSql = date('Y-m-d H:i:s', $fromTs);
        $toSql = date('Y-m-d H:i:s', $toTs);

        $upsSql = 'SELECT ups_id, rated_kw, rated_kva, zone_id FROM ups_units WHERE is_active = 1';
        $upsParams = [];
        if ($scope === 'ups_zone' && $scopeId && $scopeId > 0) {
            $upsSql .= ' AND zone_id = ?';
            $upsParams[] = $scopeId;
        } elseif ($scope === 'ups' && $scopeId && $scopeId > 0) {
            $upsSql .= ' AND ups_id = ?';
            $upsParams[] = $scopeId;
        }
        try {
            $upsRows = Database::fetchAll($upsSql, $upsParams);
        } catch (Throwable $e) {
            $upsRows = [];
        }
        $upsIds = array_map(static fn($r) => (int)$r['ups_id'], $upsRows);
        if (!$upsIds) {
            return [
                'ok' => true,
                'scope' => $scope,
                'scope_id' => $scopeId,
                'hours' => $hoursEff,
                'bucket_minutes' => $bucketMin,
                'series' => self::emptySeries(),
                'outages' => [],
                'meta' => [
                    'ups_count' => 0,
                    'sample_count' => 0,
                    'note' => 'No UPS in scope (or not yet polled)',
                ],
            ];
        }

        $bucketSec = $bucketMin * 60;
        $startBucket = (int)(floor($fromTs / $bucketSec) * $bucketSec);
        $tLabels = [];
        $bucketKeys = [];
        for ($t = $startBucket; $t < $toTs; $t += $bucketSec) {
            $bucketKeys[] = $t;
            $tLabels[] = gmdate('c', $t);
        }
        if (!$bucketKeys) {
            return [
                'ok' => true,
                'scope' => $scope,
                'scope_id' => $scopeId,
                'hours' => $hoursEff,
                'bucket_minutes' => $bucketMin,
                'series' => self::emptySeries(),
                'outages' => [],
                'meta' => ['ups_count' => count($upsIds)],
            ];
        }

        $lookbackSql = date('Y-m-d H:i:s', $fromTs - $bucketSec * 2);
        $inList = implode(',', array_fill(0, count($upsIds), '?'));
        $paramsLook = array_merge($upsIds, [$lookbackSql, $toSql]);

        $readings = [];
        $selectFull = 'ups_id, load_pct, battery_pct, runtime_min, estimated_watts,
                input_voltage, output_voltage, input_freq, output_freq, output_current, polled_at';
        $selectBase = 'ups_id, load_pct, battery_pct, runtime_min, estimated_watts, polled_at';
        try {
            $readings = Database::fetchAll(
                "SELECT {$selectFull}
                 FROM ups_readings
                 WHERE ups_id IN ($inList)
                   AND polled_at >= ? AND polled_at <= ?
                 ORDER BY ups_id, polled_at",
                $paramsLook
            );
        } catch (Throwable $e) {
            try {
                $readings = Database::fetchAll(
                    "SELECT {$selectBase}
                     FROM ups_readings
                     WHERE ups_id IN ($inList)
                       AND polled_at >= ? AND polled_at <= ?
                     ORDER BY ups_id, polled_at",
                    $paramsLook
                );
            } catch (Throwable $e2) {
                App::log('UpsHistory series: ' . $e2->getMessage(), 'warning');
                $readings = [];
            }
        }

        /** @var array<int, list<array<string,mixed>>> $byUps */
        $byUps = [];
        foreach ($upsIds as $id) {
            $byUps[$id] = [];
        }
        foreach ($readings as $r) {
            $uid = (int)$r['ups_id'];
            if (!isset($byUps[$uid])) {
                continue;
            }
            $ts = strtotime((string)$r['polled_at']);
            if ($ts === false) {
                continue;
            }
            $num = static function ($v): ?float {
                return $v !== null && $v !== '' && is_numeric($v) ? (float)$v : null;
            };
            $byUps[$uid][] = [
                'ts' => $ts,
                'load' => $num($r['load_pct'] ?? null),
                'batt' => $num($r['battery_pct'] ?? null),
                'rt' => $num($r['runtime_min'] ?? null),
                'w' => $num($r['estimated_watts'] ?? null),
                'in_v' => $num($r['input_voltage'] ?? null),
                'out_v' => $num($r['output_voltage'] ?? null),
                'in_hz' => $num($r['input_freq'] ?? null),
                'out_hz' => $num($r['output_freq'] ?? null),
                'out_a' => $num($r['output_current'] ?? null),
            ];
        }

        $ratedW = [];
        foreach ($upsRows as $ur) {
            $uid = (int)$ur['ups_id'];
            if ($ur['rated_kw'] !== null && $ur['rated_kw'] !== '') {
                $ratedW[$uid] = (float)$ur['rated_kw'] * 1000.0;
            } elseif ($ur['rated_kva'] !== null && $ur['rated_kva'] !== '') {
                $ratedW[$uid] = (float)$ur['rated_kva'] * 1000.0 * 0.9;
            }
        }

        $loadSeries = [];
        $battSeries = [];
        $rtSeries = [];
        $wattsSeries = [];
        $kwSeries = [];
        $inVSeries = [];
        $outVSeries = [];
        $inHzSeries = [];
        $outHzSeries = [];
        $outASeries = [];
        $sampleCount = 0;

        foreach ($bucketKeys as $bTs) {
            $acc = [
                'load' => [0.0, 0], 'batt' => [0.0, 0], 'rt' => [0.0, 0], 'w' => [0.0, 0],
                'in_v' => [0.0, 0], 'out_v' => [0.0, 0], 'in_hz' => [0.0, 0],
                'out_hz' => [0.0, 0], 'out_a' => [0.0, 0],
            ];
            foreach ($upsIds as $uid) {
                $pts = $byUps[$uid] ?? [];
                $last = null;
                foreach ($pts as $p) {
                    if ($p['ts'] <= $bTs + $bucketSec - 1) {
                        $last = $p;
                    } else {
                        break;
                    }
                }
                if ($last === null) {
                    continue;
                }
                if ($last['ts'] < $bTs - $bucketSec * 2) {
                    continue;
                }
                $sampleCount++;
                foreach (['load', 'batt', 'rt', 'in_v', 'out_v', 'in_hz', 'out_hz', 'out_a'] as $k) {
                    if ($last[$k] !== null) {
                        $acc[$k][0] += $last[$k];
                        $acc[$k][1]++;
                    }
                }
                $w = $last['w'];
                if ($w === null && $last['load'] !== null && isset($ratedW[$uid])) {
                    $w = $ratedW[$uid] * ($last['load'] / 100.0);
                }
                if ($w === null && $last['out_v'] !== null && $last['out_a'] !== null
                    && $last['out_v'] > 0 && $last['out_a'] > 0
                ) {
                    $w = $last['out_v'] * $last['out_a'];
                }
                if ($w !== null) {
                    $acc['w'][0] += $w;
                    $acc['w'][1]++;
                }
            }
            $avg = static function (array $pair, int $dec): ?float {
                return $pair[1] > 0 ? round($pair[0] / $pair[1], $dec) : null;
            };
            $loadSeries[] = $avg($acc['load'], 2);
            $battSeries[] = $avg($acc['batt'], 2);
            $rtSeries[] = $avg($acc['rt'], 1);
            if ($acc['w'][1] > 0) {
                $wattsSeries[] = round($acc['w'][0], 1);
                $kwSeries[] = round($acc['w'][0] / 1000.0, 3);
            } else {
                $wattsSeries[] = null;
                $kwSeries[] = null;
            }
            $inVSeries[] = $avg($acc['in_v'], 1);
            $outVSeries[] = $avg($acc['out_v'], 1);
            $inHzSeries[] = $avg($acc['in_hz'], 2);
            $outHzSeries[] = $avg($acc['out_hz'], 2);
            $outASeries[] = $avg($acc['out_a'], 2);
        }

        return [
            'ok' => true,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'hours' => $hoursEff,
            'bucket_minutes' => $bucketMin,
            'series' => [
                't' => $tLabels,
                'load_pct' => $loadSeries,
                'battery_pct' => $battSeries,
                'runtime_min' => $rtSeries,
                'watts' => $wattsSeries,
                'kw' => $kwSeries,
                'input_voltage' => $inVSeries,
                'output_voltage' => $outVSeries,
                'input_freq' => $inHzSeries,
                'output_freq' => $outHzSeries,
                'output_current' => $outASeries,
                // Aliases used by chart data-metric
                'volts' => $outVSeries,
                'amps' => $outASeries,
            ],
            'outages' => [],
            'meta' => [
                'ups_count' => count($upsIds),
                'sample_count' => $sampleCount,
                'from' => $fromSql,
                'to' => $toSql,
            ],
            'summary' => [
                'load_pct' => self::summaryStats($loadSeries),
                'battery_pct' => self::summaryStats($battSeries),
                'kw' => self::summaryStats($kwSeries),
            ],
        ];
    }

    /**
     * @return array<string,list>
     */
    private static function emptySeries(): array
    {
        return [
            't' => [],
            'load_pct' => [],
            'battery_pct' => [],
            'runtime_min' => [],
            'kw' => [],
            'watts' => [],
            'input_voltage' => [],
            'output_voltage' => [],
            'input_freq' => [],
            'output_freq' => [],
            'output_current' => [],
            'volts' => [],
            'amps' => [],
        ];
    }

    /**
     * @param list<?float> $series
     * @return array{min:?float,max:?float,avg:?float,last:?float}
     */
    private static function summaryStats(array $series): array
    {
        $nums = array_values(array_filter($series, static fn($v) => $v !== null && is_numeric($v)));
        if (!$nums) {
            return ['min' => null, 'max' => null, 'avg' => null, 'last' => null];
        }
        $nums = array_map('floatval', $nums);
        return [
            'min' => min($nums),
            'max' => max($nums),
            'avg' => round(array_sum($nums) / count($nums), 3),
            'last' => $nums[count($nums) - 1],
        ];
    }
}
