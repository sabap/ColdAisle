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
 *  3) Per-phase outage detection & markers on charts — done
 *  4) Reports: weekly / monthly / annual / custom range export
 */
declare(strict_types=1);

class PowerHistoryService
{
    /** Retention for raw samples (days). Older rows pruned opportunistically. */
    public const RETENTION_DAYS = 400;

    /** Phase LN below this (V) counts as hard dead/outage. */
    public const VOLTS_DEAD = 50.0;
    /** Fraction of nominal LN below which phase is "low voltage" outage. */
    public const VOLTS_LOW_FRAC = 0.75;

    /**
     * Persist a history sample after poll.
     * @param array<string,mixed>|null $phases last_poll_phases structure
     * @param float|null $nominalVoltsLn optional L–N nominal (from PDU form / zone)
     */
    public static function recordSample(
        int $pduId,
        ?float $watts,
        ?float $amps,
        ?array $phases = null,
        ?float $nominalVoltsLn = null
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
            $parsed = self::parsePhasesForSample($phases, $nominalVoltsLn);
            $voltsAvg = $parsed['volts_avg'];
            $voltsLl = $parsed['volts_ll'];
            $phasePack = $parsed['phases_json'];
            $outage = $parsed['outage_phases'];
        }

        try {
            $row = [
                'pdu_id' => $pduId,
                'watts' => $watts,
                'amps' => $amps,
                'volts' => $voltsAvg,
                'polled_at' => date('Y-m-d H:i:s'),
            ];
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

        if (random_int(1, 100) === 1) {
            self::pruneOld();
        }
    }

    /**
     * @param array<string,mixed> $phases
     * @return array{volts_avg:?float,volts_ll:?float,phases_json:?string,outage_phases:?string}
     */
    public static function parsePhasesForSample(array $phases, ?float $nominalVoltsLn = null): array
    {
        $vSum = 0.0;
        $vN = 0;
        $phaseSlim = [];
        $outageBits = [];

        // Infer nominal from healthy-looking phases if not provided
        $rawVolts = [];
        foreach (['L1', 'L2', 'L3'] as $lab) {
            if (!empty($phases[$lab]['volts']) && is_numeric($phases[$lab]['volts'])) {
                $rawVolts[] = (float)$phases[$lab]['volts'];
            }
        }
        if ($nominalVoltsLn === null || $nominalVoltsLn <= 0) {
            $healthy = array_filter($rawVolts, static fn($v) => $v >= self::VOLTS_DEAD);
            if ($healthy) {
                $nominalVoltsLn = array_sum($healthy) / count($healthy);
            } else {
                $nominalVoltsLn = 120.0;
            }
        }
        $lowThresh = max(self::VOLTS_DEAD + 5, $nominalVoltsLn * self::VOLTS_LOW_FRAC);

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
            if (!$row) {
                continue;
            }
            $reasons = [];
            if (isset($row['volts'])) {
                $vSum += (float)$row['volts'];
                $vN++;
                if ($row['volts'] < self::VOLTS_DEAD) {
                    $reasons[] = 'dead';
                } elseif ($row['volts'] < $lowThresh) {
                    $reasons[] = 'low_v';
                }
            }
            // APC load-state 4 = overload (treat as phase fault/outage class event)
            if (isset($row['load_state']) && (int)$row['load_state'] >= 4) {
                $reasons[] = 'overload';
            }
            if ($reasons) {
                $row['outage'] = implode('+', $reasons);
                $outageBits[] = $lab . ':' . $row['outage'];
            }
            $phaseSlim[$lab] = $row;
        }

        $voltsAvg = $vN > 0 ? round($vSum / $vN, 2) : null;
        $voltsLl = null;
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

        return [
            'volts_avg' => $voltsAvg,
            'volts_ll' => $voltsLl,
            'phases_json' => $phaseSlim ? json_encode($phaseSlim, JSON_UNESCAPED_SLASHES) : null,
            'outage_phases' => $outageBits ? implode(',', $outageBits) : null,
        ];
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
     * Aggregated time series for charts + outage markers.
     *
     * @return array{
     *   ok:bool,scope:string,scope_id:?int,hours:int,bucket_minutes:int,
     *   series:array{t:list<string>,watts:list<?float>,kw:list<?float>,volts:list<?float>,amps:list<?float>,
     *     phase_volts?:array{L1:list<?float>,L2:list<?float>,L3:list<?float>}},
     *   outages:list<array{t:string,phases:list<string>,label:string,count:int}>,
     *   meta:array<string,mixed>
     * }
     */
    public static function series(string $scope, ?int $scopeId, int $hours = 24): array
    {
        $scope = strtolower($scope);
        if (!in_array($scope, ['site', 'zone', 'pdu'], true)) {
            $scope = 'site';
        }
        $hours = max(1, min(24 * 90, $hours));
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

        $bucketExpr = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '20000101', r.polled_at) / {$bucketMin}) * {$bucketMin}, '20000101')";

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
        $bucketKeys = [];
        foreach ($rows as $r) {
            $bt = self::formatBucket($r['bucket'] ?? null);
            $t[] = $bt;
            $bucketKeys[$bt] = count($t) - 1;
            $w = isset($r['watts']) && is_numeric($r['watts']) ? (float)$r['watts'] : null;
            $watts[] = $w;
            $kw[] = $w !== null ? round($w / 1000.0, 3) : null;
            $volts[] = isset($r['volts']) && is_numeric($r['volts']) ? round((float)$r['volts'], 2) : null;
            $amps[] = isset($r['amps']) && is_numeric($r['amps']) ? round((float)$r['amps'], 3) : null;
        }

        $outages = self::loadOutageMarkers($scope, $scopeId, $hours, $bucketMin);
        $phaseVolts = null;
        if ($scope === 'pdu' && $scopeId) {
            $phaseVolts = self::loadPhaseVoltageSeries($scopeId, $hours, $bucketMin, $t);
        }

        $series = [
            't' => $t,
            'watts' => $watts,
            'kw' => $kw,
            'volts' => $volts,
            'amps' => $amps,
        ];
        if ($phaseVolts) {
            $series['phase_volts'] = $phaseVolts;
        }

        $outageCount = count($outages);
        $outagePhases = [];
        foreach ($outages as $o) {
            foreach ($o['phases'] as $ph) {
                $outagePhases[$ph] = true;
            }
        }

        return [
            'ok' => true,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'hours' => $hours,
            'bucket_minutes' => $bucketMin,
            'series' => $series,
            'outages' => $outages,
            'meta' => [
                'points' => count($t),
                'sample_count' => self::countSamples($scope, $scopeId, $hours),
                'outage_events' => $outageCount,
                'outage_phases' => array_keys($outagePhases),
            ],
        ];
    }

    /**
     * Outage markers bucketed for charts.
     *
     * @return list<array{t:string,phases:list<string>,label:string,count:int,reasons:list<string>}>
     */
    private static function loadOutageMarkers(
        string $scope,
        ?int $scopeId,
        int $hours,
        int $bucketMin
    ): array {
        $params = [];
        $where = "r.polled_at >= DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
                  AND r.outage_phases IS NOT NULL AND LTRIM(RTRIM(r.outage_phases)) <> ''";
        $join = '';
        if ($scope === 'pdu' && $scopeId) {
            $where .= ' AND r.pdu_id = ?';
            $params[] = $scopeId;
        } elseif ($scope === 'zone' && $scopeId) {
            $join = ' INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1 AND p.zone_id = ? ';
            $params[] = $scopeId;
        } else {
            $join = ' INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1 ';
        }

        $bucketExpr = "DATEADD(MINUTE, (DATEDIFF(MINUTE, '20000101', r.polled_at) / {$bucketMin}) * {$bucketMin}, '20000101')";
        $sql = "SELECT {$bucketExpr} AS bucket,
                       r.outage_phases,
                       COUNT(*) AS hits
                FROM pdu_readings r
                {$join}
                WHERE {$where}
                GROUP BY {$bucketExpr}, r.outage_phases
                ORDER BY bucket";

        try {
            $rows = Database::fetchAll($sql, $params);
        } catch (Throwable $e) {
            // Column may not exist yet
            return [];
        }

        /** @var array<string,array{phases:array<string,bool>,reasons:array<string,bool>,count:int}> $byBucket */
        $byBucket = [];
        foreach ($rows as $r) {
            $bt = self::formatBucket($r['bucket'] ?? null);
            if (!isset($byBucket[$bt])) {
                $byBucket[$bt] = ['phases' => [], 'reasons' => [], 'count' => 0];
            }
            $byBucket[$bt]['count'] += (int)($r['hits'] ?? 1);
            $parsed = self::parseOutagePhasesString((string)($r['outage_phases'] ?? ''));
            foreach ($parsed['phases'] as $ph) {
                $byBucket[$bt]['phases'][$ph] = true;
            }
            foreach ($parsed['reasons'] as $rs) {
                $byBucket[$bt]['reasons'][$rs] = true;
            }
        }

        $out = [];
        foreach ($byBucket as $bt => $info) {
            $phases = array_keys($info['phases']);
            sort($phases);
            $reasons = array_keys($info['reasons']);
            $label = $phases ? implode(',', $phases) : 'outage';
            if ($reasons) {
                $label .= ' (' . implode('/', $reasons) . ')';
            }
            $out[] = [
                't' => $bt,
                'phases' => $phases,
                'label' => $label,
                'count' => $info['count'],
                'reasons' => $reasons,
            ];
        }
        return $out;
    }

    /**
     * @return array{phases:list<string>,reasons:list<string>}
     */
    public static function parseOutagePhasesString(string $raw): array
    {
        $phases = [];
        $reasons = [];
        $raw = trim($raw);
        if ($raw === '') {
            return ['phases' => [], 'reasons' => []];
        }
        // Formats: "L1,L2" or "L1:dead,L2:low_v+overload"
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_contains($part, ':')) {
                [$ph, $rs] = explode(':', $part, 2);
                $ph = strtoupper(trim($ph));
                if ($ph !== '') {
                    $phases[$ph] = true;
                }
                foreach (explode('+', $rs) as $r) {
                    $r = trim($r);
                    if ($r !== '') {
                        $reasons[$r] = true;
                    }
                }
            } else {
                $ph = strtoupper($part);
                $phases[$ph] = true;
            }
        }
        return [
            'phases' => array_keys($phases),
            'reasons' => array_keys($reasons),
        ];
    }

    /**
     * Per-phase LN voltage series for a single PDU (from phases_json samples).
     *
     * @param list<string> $bucketTimes aligned empty series lengths
     * @return array{L1:list<?float>,L2:list<?float>,L3:list<?float>}|null
     */
    private static function loadPhaseVoltageSeries(
        int $pduId,
        int $hours,
        int $bucketMin,
        array $bucketTimes
    ): ?array {
        if (!$bucketTimes) {
            return null;
        }
        try {
            $rows = Database::fetchAll(
                'SELECT polled_at, phases_json FROM pdu_readings
                 WHERE pdu_id = ?
                   AND polled_at >= DATEADD(HOUR, -' . (int)$hours . ', SYSUTCDATETIME())
                   AND phases_json IS NOT NULL
                 ORDER BY polled_at',
                [$pduId]
            );
        } catch (Throwable $e) {
            return null;
        }
        if (!$rows) {
            return null;
        }

        // Map bucket ISO -> accumulators
        $acc = [];
        foreach ($bucketTimes as $bt) {
            $acc[$bt] = ['L1' => [], 'L2' => [], 'L3' => []];
        }

        foreach ($rows as $r) {
            $ts = strtotime((string)$r['polled_at']);
            if (!$ts) {
                continue;
            }
            // Floor to bucket
            $floor = (int)(floor($ts / 60 / $bucketMin) * $bucketMin * 60);
            $bt = date('c', $floor);
            // Find nearest bucket key in series (format may differ slightly)
            $key = self::nearestBucketKey($bt, $bucketTimes);
            if ($key === null) {
                continue;
            }
            $json = json_decode((string)$r['phases_json'], true);
            if (!is_array($json)) {
                continue;
            }
            foreach (['L1', 'L2', 'L3'] as $lab) {
                if (isset($json[$lab]['volts']) && is_numeric($json[$lab]['volts'])) {
                    $acc[$key][$lab][] = (float)$json[$lab]['volts'];
                }
            }
        }

        $out = ['L1' => [], 'L2' => [], 'L3' => []];
        $any = false;
        foreach ($bucketTimes as $bt) {
            foreach (['L1', 'L2', 'L3'] as $lab) {
                $vals = $acc[$bt][$lab] ?? [];
                if ($vals) {
                    $out[$lab][] = round(array_sum($vals) / count($vals), 2);
                    $any = true;
                } else {
                    $out[$lab][] = null;
                }
            }
        }
        return $any ? $out : null;
    }

    /** @param list<string> $bucketTimes */
    private static function nearestBucketKey(string $bt, array $bucketTimes): ?string
    {
        if (isset(array_flip($bucketTimes)[$bt])) {
            return $bt;
        }
        $ts = strtotime($bt);
        if (!$ts) {
            return null;
        }
        $best = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($bucketTimes as $k) {
            $kts = strtotime($k);
            if (!$kts) {
                continue;
            }
            $d = abs($kts - $ts);
            if ($d < $bestDiff) {
                $bestDiff = $d;
                $best = $k;
            }
        }
        // Within 10 minutes
        return ($best !== null && $bestDiff <= 600) ? $best : null;
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
        return 360;
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
