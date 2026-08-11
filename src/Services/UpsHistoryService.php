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
                App::log(
                    'UpsHistory sample failed: ' . $e2->getMessage()
                    . ' (full insert: ' . $e->getMessage() . ')',
                    'warning'
                );
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

        // Note: poll timestamp column is snmp_last_poll_at (not last_poll_at)
        $upsSql = 'SELECT ups_id, rated_kw, rated_kva, zone_id,
                          snmp_last_poll_at, last_load_pct, last_battery_pct, last_runtime_min,
                          last_input_voltage, last_output_voltage,
                          last_input_freq, last_output_freq, last_output_current
                   FROM ups_units WHERE is_active = 1';
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
            // Older schema without electrical last_* columns
            try {
                $upsSql = 'SELECT ups_id, rated_kw, rated_kva, zone_id,
                                  snmp_last_poll_at, last_load_pct, last_battery_pct, last_runtime_min
                           FROM ups_units WHERE is_active = 1';
                if ($scope === 'ups_zone' && $scopeId && $scopeId > 0) {
                    $upsSql .= ' AND zone_id = ?';
                } elseif ($scope === 'ups' && $scopeId && $scopeId > 0) {
                    $upsSql .= ' AND ups_id = ?';
                }
                $upsRows = Database::fetchAll($upsSql, $upsParams);
            } catch (Throwable $e2) {
                App::log('UpsHistory unit load: ' . $e2->getMessage(), 'warning');
                $upsRows = [];
            }
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
        // Hold last good sample across poll gaps (was only ~2 buckets / 10 min → single "blips")
        $holdSec = max(90 * 60, $bucketSec * 12);
        $startBucket = (int)(floor($fromTs / $bucketSec) * $bucketSec);
        $tLabels = [];
        $bucketKeys = [];
        for ($t = $startBucket; $t < $toTs; $t += $bucketSec) {
            $bucketKeys[] = $t;
            // Local ISO (not gmdate) so chart axis matches poll timestamps
            $tLabels[] = date('c', $t);
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

        $lookbackSql = date('Y-m-d H:i:s', $fromTs - $holdSec);
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
        $rawSampleCount = 0;
        foreach ($readings as $r) {
            $uid = (int)$r['ups_id'];
            if (!isset($byUps[$uid])) {
                continue;
            }
            $ts = self::parsePollTs($r['polled_at'] ?? null);
            if ($ts === null) {
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
            $rawSampleCount++;
        }

        // If history is empty but units have last-poll snapshot, synthesize one point so charts
        // aren't blank after a successful Poll that failed to insert history (schema race).
        if ($rawSampleCount === 0) {
            foreach ($upsRows as $ur) {
                $uid = (int)$ur['ups_id'];
                $synth = self::sampleFromUnitRow($ur);
                if ($synth !== null) {
                    $byUps[$uid][] = $synth;
                    $rawSampleCount++;
                }
            }
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
        $heldBuckets = 0;
        $rawBuckets = 0;

        foreach ($bucketKeys as $bTs) {
            $bEnd = $bTs + $bucketSec;
            $acc = [
                'load' => [0.0, 0], 'batt' => [0.0, 0], 'rt' => [0.0, 0], 'w' => [0.0, 0],
                'in_v' => [0.0, 0], 'out_v' => [0.0, 0], 'in_hz' => [0.0, 0],
                'out_hz' => [0.0, 0], 'out_a' => [0.0, 0],
            ];
            $anyInBucket = false;
            $anyHeld = false;
            foreach ($upsIds as $uid) {
                $pts = $byUps[$uid] ?? [];
                $last = null;
                $inBucket = false;
                foreach ($pts as $p) {
                    if ($p['ts'] < $bEnd) {
                        $last = $p;
                        if ($p['ts'] >= $bTs) {
                            $inBucket = true;
                        }
                    } else {
                        break;
                    }
                }
                if ($last === null) {
                    continue;
                }
                // Age of sample relative to end of this bucket
                $age = ($bEnd - 1) - $last['ts'];
                if ($age > $holdSec) {
                    continue;
                }
                if ($inBucket) {
                    $anyInBucket = true;
                } else {
                    $anyHeld = true;
                }
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
            if ($anyInBucket) {
                $rawBuckets++;
            } elseif ($anyHeld) {
                $heldBuckets++;
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

        // Forward-fill null gaps so charts draw a continuous historical line (same idea as PDU volts).
        // Hold already caps how far a sample can stretch; carry fills small holes between buckets.
        $loadSeries = self::carryForward($loadSeries);
        $battSeries = self::carryForward($battSeries);
        $rtSeries = self::carryForward($rtSeries);
        $wattsSeries = self::carryForward($wattsSeries);
        $kwSeries = self::carryForward($kwSeries);
        $inVSeries = self::carryForward($inVSeries);
        $outVSeries = self::carryForward($outVSeries);
        $inHzSeries = self::carryForward($inHzSeries);
        $outHzSeries = self::carryForward($outHzSeries);
        $outASeries = self::carryForward($outASeries);

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
                'sample_count' => $rawSampleCount,
                'held_buckets' => $heldBuckets,
                'raw_buckets' => $rawBuckets,
                'hold_sec' => $holdSec,
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
     * @param mixed $raw
     */
    private static function parsePollTs($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            return $raw->getTimestamp();
        }
        if (is_numeric($raw)) {
            $n = (int)$raw;
            // Heuristic: ms vs s
            return $n > 2_000_000_000_000 ? (int)round($n / 1000) : $n;
        }
        $s = trim((string)$raw);
        // SQL Server often returns "Y-m-d H:i:s.mmm"
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $s, $m)) {
            $s = str_replace('T', ' ', $m[1]);
        }
        $ts = strtotime($s);
        return $ts !== false ? $ts : null;
    }

    /**
     * Build a synthetic history point from ups_units last_* columns when ups_readings is empty.
     *
     * @param array<string,mixed> $ur
     * @return array<string,mixed>|null
     */
    private static function sampleFromUnitRow(array $ur): ?array
    {
        $num = static function ($v): ?float {
            return $v !== null && $v !== '' && is_numeric($v) ? (float)$v : null;
        };
        $load = $num($ur['last_load_pct'] ?? null);
        $batt = $num($ur['last_battery_pct'] ?? null);
        $rt = $num($ur['last_runtime_min'] ?? null);
        $inV = $num($ur['last_input_voltage'] ?? null);
        $outV = $num($ur['last_output_voltage'] ?? null);
        $inHz = $num($ur['last_input_freq'] ?? null);
        $outHz = $num($ur['last_output_freq'] ?? null);
        $outA = $num($ur['last_output_current'] ?? null);
        if ($load === null && $batt === null && $rt === null
            && $inV === null && $outV === null && $outA === null
        ) {
            return null;
        }
        $ts = self::parsePollTs($ur['snmp_last_poll_at'] ?? $ur['last_poll_at'] ?? null) ?? time();
        $w = null;
        if ($load !== null) {
            if ($ur['rated_kw'] !== null && $ur['rated_kw'] !== '') {
                $w = (float)$ur['rated_kw'] * 1000.0 * ($load / 100.0);
            } elseif ($ur['rated_kva'] !== null && $ur['rated_kva'] !== '') {
                $w = (float)$ur['rated_kva'] * 1000.0 * 0.9 * ($load / 100.0);
            }
        }
        if ($w === null && $outV !== null && $outA !== null && $outV > 0 && $outA > 0) {
            $w = $outV * $outA;
        }
        return [
            'ts' => $ts,
            'load' => $load,
            'batt' => $batt,
            'rt' => $rt,
            'w' => $w,
            'in_v' => $inV,
            'out_v' => $outV,
            'in_hz' => $inHz,
            'out_hz' => $outHz,
            'out_a' => $outA,
        ];
    }

    /**
     * Forward-fill nulls so sparse polls still draw a continuous line.
     *
     * @param list<?float> $vals
     * @return list<?float>
     */
    private static function carryForward(array $vals): array
    {
        $last = null;
        $out = [];
        foreach ($vals as $v) {
            if ($v !== null && is_numeric($v)) {
                $last = (float)$v;
                $out[] = $last;
            } else {
                $out[] = $last;
            }
        }
        return $out;
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
