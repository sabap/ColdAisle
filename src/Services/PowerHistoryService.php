<?php
/**
 * ColdAisle — power history samples and time-series queries.
 *
 * Samples are written on each successful PDU poll. UI charts request
 * aggregated series by site / zone / pdu over a rolling window.
 *
 * Roadmap (sub-phases):
 *  1) Foundation + 24h charts (dashboard, PDU) — done
 *  2) Zone list mini-charts + zone detail full charts — done
 *  3) Per-phase outage detection & markers on charts
 *  4) Reports: weekly / monthly / annual / custom range export
 */
declare(strict_types=1);

class PowerHistoryService
{
    /** Retention for raw samples (days). Older rows pruned opportunistically. */
    public const RETENTION_DAYS = 400;

    /**
     * Persist a history sample after poll.
     * @param array<string,mixed>|null $phases last_poll_phases structure
     */
    public static function recordSample(
        int $pduId,
        ?float $watts,
        ?float $amps,
        ?array $phases = null
    ): void {
        if ($pduId < 1) {
            return;
        }
        if ($watts === null && $amps === null && !$phases) {
            return;
        }

        $voltsAvg = null;
        $voltsLl = null;
        $phasePack = null;
        $outage = null;

        if (is_array($phases)) {
            $vSum = 0.0;
            $vN = 0;
            $phaseSlim = [];
            foreach (['L1', 'L2', 'L3'] as $lab) {
                if (empty($phases[$lab]) || !is_array($phases[$lab])) {
                    continue;
                }
                $p = $phases[$lab];
                $row = [];
                foreach (['watts', 'amps', 'volts', 'va', 'pf', 'peak_amps', 'load_state'] as $k) {
                    if (isset($p[$k]) && is_numeric($p[$k])) {
                        $row[$k] = (float)$p[$k];
                    }
                }
                if ($row) {
                    $phaseSlim[$lab] = $row;
                    if (isset($row['volts'])) {
                        $vSum += (float)$row['volts'];
                        $vN++;
                    }
                    // Crude outage flag: LN voltage collapsed or APC overload state
                    if ((isset($row['volts']) && $row['volts'] < 50)
                        || (isset($row['load_state']) && (int)$row['load_state'] >= 4)
                    ) {
                        $outage = $outage ? ($outage . ',' . $lab) : $lab;
                    }
                }
            }
            if ($vN > 0) {
                $voltsAvg = round($vSum / $vN, 2);
            }
            if (!empty($phases['_ll']) && is_array($phases['_ll'])) {
                $llSum = 0.0;
                $llN = 0;
                foreach ($phases['_ll'] as $v) {
                    if (is_numeric($v)) {
                        $llSum += (float)$v;
                        $llN++;
                    }
                }
                if ($llN > 0) {
                    $voltsLl = round($llSum / $llN, 2);
                }
            }
            if ($phaseSlim) {
                $phasePack = json_encode($phaseSlim, JSON_UNESCAPED_SLASHES);
            }
        }

        try {
            $row = [
                'pdu_id' => $pduId,
                'watts' => $watts,
                'amps' => $amps,
                'volts' => $voltsAvg,
                'polled_at' => date('Y-m-d H:i:s'),
            ];
            // Optional columns (Schema::ensure)
            try {
                $row['volts_ll'] = $voltsLl;
                $row['phases_json'] = $phasePack;
                $row['outage_phases'] = $outage;
                Database::insert('pdu_readings', $row);
            } catch (Throwable $e) {
                unset($row['volts_ll'], $row['phases_json'], $row['outage_phases']);
                Database::insert('pdu_readings', $row);
            }
        } catch (Throwable $e) {
            App::log('PowerHistory sample failed: ' . $e->getMessage(), 'warning');
        }

        // Occasional prune (1% of writes)
        if (random_int(1, 100) === 1) {
            self::pruneOld();
        }
    }

    public static function pruneOld(): void
    {
        try {
            $days = self::RETENTION_DAYS;
            Database::query(
                "DELETE FROM pdu_readings WHERE polled_at < DATEADD(DAY, -{$days}, SYSUTCDATETIME())"
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * Aggregated time series for charts.
     *
     * @param string $scope site|zone|pdu
     * @return array{
     *   ok:bool,scope:string,scope_id:?int,hours:int,bucket_minutes:int,
     *   series:array{t:list<string>,watts:list<?float>,kw:list<?float>,volts:list<?float>,amps:list<?float>},
     *   meta:array<string,mixed>
     * }
     */
    public static function series(string $scope, ?int $scopeId, int $hours = 24): array
    {
        $scope = strtolower($scope);
        if (!in_array($scope, ['site', 'zone', 'pdu'], true)) {
            $scope = 'site';
        }
        $hours = max(1, min(24 * 90, $hours)); // up to 90 days for reports later
        $bucketMin = self::bucketMinutesForHours($hours);

        $params = [];
        $where = 'r.polled_at >= DATEADD(HOUR, -' . (int)$hours . ', SYSUTCDATETIME())';
        $join = '';

        if ($scope === 'pdu' && $scopeId && $scopeId > 0) {
            $where .= ' AND r.pdu_id = ?';
            $params[] = $scopeId;
        } elseif ($scope === 'zone' && $scopeId && $scopeId > 0) {
            $join = ' INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1 AND p.zone_id = ? ';
            $params[] = $scopeId;
        } else {
            $join = ' INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1 ';
            $scope = 'site';
            $scopeId = null;
        }

        // Bucket: floor polled_at to N-minute grid (SQL Server)
        $bucketExpr = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '20000101', r.polled_at) / {$bucketMin}) * {$bucketMin}, '20000101')";

        // Site/zone: sum watts across PDUs per bucket; volts = avg of PDU averages
        if ($scope === 'pdu') {
            $sql = "SELECT {$bucketExpr} AS bucket,
                           AVG(r.watts) AS watts,
                           AVG(r.amps) AS amps,
                           AVG(r.volts) AS volts
                    FROM pdu_readings r
                    {$join}
                    WHERE {$where}
                    GROUP BY {$bucketExpr}
                    ORDER BY bucket";
        } else {
            // Per-bucket: sum of per-PDU average watts in that bucket
            $sql = "SELECT bucket,
                           SUM(pdu_watts) AS watts,
                           SUM(pdu_amps) AS amps,
                           AVG(pdu_volts) AS volts
                    FROM (
                        SELECT r.pdu_id,
                               {$bucketExpr} AS bucket,
                               AVG(r.watts) AS pdu_watts,
                               AVG(r.amps) AS pdu_amps,
                               AVG(r.volts) AS pdu_volts
                        FROM pdu_readings r
                        {$join}
                        WHERE {$where}
                        GROUP BY r.pdu_id, {$bucketExpr}
                    ) x
                    GROUP BY bucket
                    ORDER BY bucket";
        }

        $rows = [];
        try {
            $rows = Database::fetchAll($sql, $params);
        } catch (Throwable $e) {
            App::log('PowerHistory series: ' . $e->getMessage(), 'warning');
            $rows = [];
        }

        $t = [];
        $watts = [];
        $kw = [];
        $volts = [];
        $amps = [];
        foreach ($rows as $r) {
            $t[] = self::formatBucket($r['bucket'] ?? null);
            $w = isset($r['watts']) && is_numeric($r['watts']) ? (float)$r['watts'] : null;
            $watts[] = $w;
            $kw[] = $w !== null ? round($w / 1000.0, 3) : null;
            $volts[] = isset($r['volts']) && is_numeric($r['volts']) ? round((float)$r['volts'], 2) : null;
            $amps[] = isset($r['amps']) && is_numeric($r['amps']) ? round((float)$r['amps'], 3) : null;
        }

        return [
            'ok' => true,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'hours' => $hours,
            'bucket_minutes' => $bucketMin,
            'series' => [
                't' => $t,
                'watts' => $watts,
                'kw' => $kw,
                'volts' => $volts,
                'amps' => $amps,
            ],
            'meta' => [
                'points' => count($t),
                'sample_count' => self::countSamples($scope, $scopeId, $hours),
            ],
        ];
    }

    private static function bucketMinutesForHours(int $hours): int
    {
        if ($hours <= 6) {
            return 5;
        }
        if ($hours <= 24) {
            return 5;
        }
        if ($hours <= 24 * 7) {
            return 30;
        }
        if ($hours <= 24 * 31) {
            return 60;
        }
        return 360; // 6h for long ranges
    }

    private static function formatBucket($bucket): string
    {
        if ($bucket instanceof DateTimeInterface) {
            return $bucket->format('c');
        }
        $s = (string)$bucket;
        $ts = strtotime($s);
        return $ts ? date('c', $ts) : $s;
    }

    private static function countSamples(string $scope, ?int $scopeId, int $hours): int
    {
        try {
            if ($scope === 'pdu' && $scopeId) {
                return (int)Database::fetchValue(
                    'SELECT COUNT(*) FROM pdu_readings
                     WHERE pdu_id = ? AND polled_at >= DATEADD(HOUR, -' . (int)$hours . ', SYSUTCDATETIME())',
                    [$scopeId]
                );
            }
            if ($scope === 'zone' && $scopeId) {
                return (int)Database::fetchValue(
                    'SELECT COUNT(*) FROM pdu_readings r
                     INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.zone_id = ? AND p.is_active = 1
                     WHERE r.polled_at >= DATEADD(HOUR, -' . (int)$hours . ', SYSUTCDATETIME())',
                    [$scopeId]
                );
            }
            return (int)Database::fetchValue(
                'SELECT COUNT(*) FROM pdu_readings r
                 INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1
                 WHERE r.polled_at >= DATEADD(HOUR, -' . (int)$hours . ', SYSUTCDATETIME())'
            );
        } catch (Throwable $e) {
            return 0;
        }
    }
}
