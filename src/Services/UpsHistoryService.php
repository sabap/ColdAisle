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
     */
    public static function recordSample(
        int $upsId,
        ?float $loadPct,
        ?float $batteryPct,
        ?float $runtimeMin = null,
        ?string $outputStatus = null,
        ?float $estimatedWatts = null
    ): void {
        if ($upsId < 1) {
            return;
        }
        if ($loadPct === null && $batteryPct === null && $runtimeMin === null && $estimatedWatts === null) {
            return;
        }
        try {
            Database::insert('ups_readings', [
                'ups_id' => $upsId,
                'load_pct' => $loadPct,
                'battery_pct' => $batteryPct,
                'runtime_min' => $runtimeMin,
                'output_status' => $outputStatus !== null && $outputStatus !== ''
                    ? mb_substr($outputStatus, 0, 80) : null,
                'estimated_watts' => $estimatedWatts,
                'polled_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            App::log('UpsHistory sample failed: ' . $e->getMessage(), 'warning');
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
     *   series:array{t:list<string>,load_pct:list<?float>,battery_pct:list<?float>,
     *     runtime_min:list<?float>,kw:list<?float>,watts:list<?float>},
     *   outages:list, meta:array<string,mixed>
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

        // Active UPS set for scope
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

        // Build time buckets
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

        // Hold-forward: for each UPS, load readings, then for each bucket take last value
        $inList = implode(',', array_fill(0, count($upsIds), '?'));
        $params = array_merge($upsIds, [$fromSql, $toSql]);
        // Lookback one bucket for hold
        $lookbackSql = date('Y-m-d H:i:s', $fromTs - $bucketSec * 2);
        $paramsLook = array_merge($upsIds, [$lookbackSql, $toSql]);

        $readings = [];
        try {
            $readings = Database::fetchAll(
                "SELECT ups_id, load_pct, battery_pct, runtime_min, estimated_watts, polled_at
                 FROM ups_readings
                 WHERE ups_id IN ($inList)
                   AND polled_at >= ? AND polled_at <= ?
                 ORDER BY ups_id, polled_at",
                $paramsLook
            );
        } catch (Throwable $e) {
            App::log('UpsHistory series: ' . $e->getMessage(), 'warning');
            $readings = [];
        }

        /** @var array<int, list<array{ts:int,load:?float,batt:?float,rt:?float,w:?float}>> $byUps */
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
            $byUps[$uid][] = [
                'ts' => $ts,
                'load' => $r['load_pct'] !== null && $r['load_pct'] !== '' ? (float)$r['load_pct'] : null,
                'batt' => $r['battery_pct'] !== null && $r['battery_pct'] !== '' ? (float)$r['battery_pct'] : null,
                'rt' => $r['runtime_min'] !== null && $r['runtime_min'] !== '' ? (float)$r['runtime_min'] : null,
                'w' => $r['estimated_watts'] !== null && $r['estimated_watts'] !== ''
                    ? (float)$r['estimated_watts'] : null,
            ];
        }

        // rated watts fallback for estimate
        $ratedW = [];
        foreach ($upsRows as $ur) {
            $uid = (int)$ur['ups_id'];
            if ($ur['rated_kw'] !== null && $ur['rated_kw'] !== '') {
                $ratedW[$uid] = (float)$ur['rated_kw'] * 1000.0;
            } elseif ($ur['rated_kva'] !== null && $ur['rated_kva'] !== '') {
                $ratedW[$uid] = (float)$ur['rated_kva'] * 1000.0 * 0.9; // rough PF
            }
        }

        $loadSeries = [];
        $battSeries = [];
        $rtSeries = [];
        $wattsSeries = [];
        $kwSeries = [];
        $sampleCount = 0;

        foreach ($bucketKeys as $bTs) {
            $loadSum = 0.0;
            $loadN = 0;
            $battSum = 0.0;
            $battN = 0;
            $rtSum = 0.0;
            $rtN = 0;
            $wSum = 0.0;
            $wN = 0;
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
                // Only count if sample is not too stale (2 buckets)
                if ($last['ts'] < $bTs - $bucketSec * 2) {
                    continue;
                }
                $sampleCount++;
                if ($last['load'] !== null) {
                    $loadSum += $last['load'];
                    $loadN++;
                }
                if ($last['batt'] !== null) {
                    $battSum += $last['batt'];
                    $battN++;
                }
                if ($last['rt'] !== null) {
                    $rtSum += $last['rt'];
                    $rtN++;
                }
                $w = $last['w'];
                if ($w === null && $last['load'] !== null && isset($ratedW[$uid])) {
                    $w = $ratedW[$uid] * ($last['load'] / 100.0);
                }
                if ($w !== null) {
                    $wSum += $w;
                    $wN++;
                }
            }
            $loadSeries[] = $loadN > 0 ? round($loadSum / $loadN, 2) : null;
            $battSeries[] = $battN > 0 ? round($battSum / $battN, 2) : null;
            $rtSeries[] = $rtN > 0 ? round($rtSum / $rtN, 1) : null;
            if ($wN > 0) {
                $wattsSeries[] = round($wSum, 1);
                $kwSeries[] = round($wSum / 1000.0, 3);
            } else {
                $wattsSeries[] = null;
                $kwSeries[] = null;
            }
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
     * @return array{t:list,load_pct:list,battery_pct:list,runtime_min:list,kw:list,watts:list}
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
        ];
    }

    /**
     * @param list<?float> $arr
     * @return array{avg:?float,min:?float,max:?float}
     */
    private static function summaryStats(array $arr): array
    {
        $nums = array_values(array_filter($arr, static fn($v) => $v !== null && is_numeric($v)));
        if (!$nums) {
            return ['avg' => null, 'min' => null, 'max' => null];
        }
        return [
            'avg' => round(array_sum($nums) / count($nums), 2),
            'min' => round(min($nums), 2),
            'max' => round(max($nums), 2),
        ];
    }
}
