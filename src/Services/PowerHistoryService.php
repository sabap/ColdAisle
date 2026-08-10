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
 *  4) Reports: weekly / monthly / annual / custom range export — done
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
     * True when L–N volts sit in a normal utility band (not "low voltage").
     * Avoids false low_v when inventory L–N was set to L–L (e.g. 208) so
     * threshold becomes ~156 V while the feed is healthy 120 V wye.
     */
    public static function isHealthyLnVoltage(float $v): bool
    {
        if ($v >= 100.0 && $v <= 132.0) {
            return true; // 110 / 120 V class
        }
        if ($v >= 200.0 && $v <= 254.0) {
            return true; // 220 / 230 / 240 V class
        }
        if ($v >= 265.0 && $v <= 290.0) {
            return true; // 277 V class
        }
        return false;
    }

    /**
     * Normalize inventory "L–N" when operators typed L–L (208/400/480).
     * Values already in L–N range are left alone.
     */
    public static function normalizeNominalLn(?float $nominalVoltsLn): ?float
    {
        if ($nominalVoltsLn === null || $nominalVoltsLn <= 0) {
            return null;
        }
        // Common L–L ratings stuffed into the L–N field
        if ($nominalVoltsLn >= 180.0) {
            return round($nominalVoltsLn / 1.732, 1);
        }
        return $nominalVoltsLn;
    }

    /**
     * Build outage_phases string from a slim phase map (L1/L2/L3 rows).
     * Used at sample write time and when re-evaluating history for charts.
     *
     * @param array<string,mixed> $phaseSlim
     */
    public static function evaluatePhaseOutages(array $phaseSlim, ?float $nominalVoltsLn = null): ?string
    {
        $rawVolts = [];
        foreach (['L1', 'L2', 'L3'] as $lab) {
            if (!empty($phaseSlim[$lab]) && is_array($phaseSlim[$lab])
                && isset($phaseSlim[$lab]['volts']) && is_numeric($phaseSlim[$lab]['volts'])
            ) {
                $rawVolts[] = (float)$phaseSlim[$lab]['volts'];
            }
        }
        $nominalVoltsLn = self::normalizeNominalLn($nominalVoltsLn);
        if ($nominalVoltsLn === null) {
            $healthy = array_filter($rawVolts, static fn($v) => $v >= self::VOLTS_DEAD);
            if ($healthy) {
                $nominalVoltsLn = array_sum($healthy) / count($healthy);
            } else {
                $nominalVoltsLn = 120.0;
            }
        }
        // If inventory nominal would flag a tight healthy measured cluster, trust the feed
        if ($rawVolts) {
            $avgM = array_sum($rawVolts) / count($rawVolts);
            if (self::isHealthyLnVoltage($avgM) && $avgM < $nominalVoltsLn * self::VOLTS_LOW_FRAC) {
                $nominalVoltsLn = $avgM;
            }
        }
        $lowThresh = max(self::VOLTS_DEAD + 5, $nominalVoltsLn * self::VOLTS_LOW_FRAC);

        $outageBits = [];
        foreach (['L1', 'L2', 'L3'] as $lab) {
            if (empty($phaseSlim[$lab]) || !is_array($phaseSlim[$lab])) {
                continue;
            }
            $p = $phaseSlim[$lab];
            $reasons = [];
            if (isset($p['volts']) && is_numeric($p['volts'])) {
                $v = (float)$p['volts'];
                if ($v < self::VOLTS_DEAD) {
                    $reasons[] = 'dead';
                } elseif ($v < $lowThresh && !self::isHealthyLnVoltage($v)) {
                    // Relative low only when outside normal utility L–N bands
                    $reasons[] = 'low_v';
                }
            }
            if (isset($p['load_state']) && is_numeric($p['load_state']) && (int)$p['load_state'] >= 4) {
                $reasons[] = 'overload';
            }
            if ($reasons) {
                $outageBits[] = $lab . ':' . implode('+', $reasons);
            }
        }
        return $outageBits ? implode(',', $outageBits) : null;
    }

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
            if (isset($row['volts'])) {
                $vSum += (float)$row['volts'];
                $vN++;
            }
            $phaseSlim[$lab] = $row;
        }

        // Annotate slim rows with outage reasons (for phases_json consumers)
        $outageStr = self::evaluatePhaseOutages($phaseSlim, $nominalVoltsLn);
        if ($outageStr) {
            foreach (explode(',', $outageStr) as $bit) {
                $bit = trim($bit);
                if (!str_contains($bit, ':')) {
                    continue;
                }
                [$ph, $rs] = explode(':', $bit, 2);
                $ph = strtoupper(trim($ph));
                if (isset($phaseSlim[$ph])) {
                    $phaseSlim[$ph]['outage'] = trim($rs);
                }
            }
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
            'outage_phases' => $outageStr,
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
     *     phase_volts?:array{L1:list<?float>,L2:list<?float>,L3:list<?float>},
     *     phase_load_state?:array{L1:list<?float>,L2:list<?float>,L3:list<?float>}},
     *   outages:list<array{t:string,phases:list<string>,label:string,count:int}>,
     *   meta:array<string,mixed>
     * }
     */
    /**
     * @param string|null $fromIso Inclusive start (Y-m-d or ISO); null = now - $hours
     * @param string|null $toIso Exclusive/end (Y-m-d or ISO); null = now
     */
    public static function series(
        string $scope,
        ?int $scopeId,
        int $hours = 24,
        ?string $fromIso = null,
        ?string $toIso = null
    ): array {
        $scope = strtolower($scope);
        if (!in_array($scope, ['site', 'zone', 'pdu'], true)) {
            $scope = 'site';
        }
        [$fromTs, $toTs, $hoursEff] = self::resolveRange($hours, $fromIso, $toIso);
        $bucketMin = self::bucketMinutesForHours($hoursEff);
        $fromSql = date('Y-m-d H:i:s', $fromTs);
        $toSql = date('Y-m-d H:i:s', $toTs);

        // PDU scope: aggregate entirely in PHP so volts / phase lines / outages
        // share the same time buckets (avoids SQL vs PHP timezone mismatches).
        if ($scope === 'pdu' && $scopeId && $scopeId > 0) {
            $result = self::seriesFromRawPduSamples($scopeId, $hoursEff, $bucketMin, $fromTs, $toTs);
            $result['from'] = date('c', $fromTs);
            $result['to'] = date('c', $toTs);
            $result['summary'] = self::summarizeSeries($result);
            return $result;
        }

        // Site/zone: build series in PHP with per-PDU hold-forward.
        // SQL "SUM of PDUs present in bucket only" creates comb-to-zero charts when
        // poll times stagger (many PDUs, 5‑min buckets, not all units sampled every bucket).
        if ($scope === 'zone' && $scopeId && $scopeId > 0) {
            $result = self::seriesSiteOrZoneWithHold('zone', $scopeId, $hoursEff, $bucketMin, $fromTs, $toTs);
        } else {
            $scope = 'site';
            $scopeId = null;
            $result = self::seriesSiteOrZoneWithHold('site', null, $hoursEff, $bucketMin, $fromTs, $toTs);
        }
        $result['from'] = date('c', $fromTs);
        $result['to'] = date('c', $toTs);
        $result['summary'] = self::summarizeSeries($result);
        return $result;
    }

    /**
     * Facility/zone total: for each time bucket, sum each active PDU's latest reading
     * in that bucket, or hold the last known good watts/amps within a hold window.
     * Prevents sawtooth/comb graphs when poll cycles don't hit every PDU every bucket.
     *
     * @return array<string,mixed>
     */
    private static function seriesSiteOrZoneWithHold(
        string $scope,
        ?int $zoneId,
        int $hours,
        int $bucketMin,
        int $fromTs,
        int $toTs
    ): array {
        $fromSql = date('Y-m-d H:i:s', $fromTs);
        $toSql = date('Y-m-d H:i:s', $toTs);
        // Hold last *power* reading longer than a single poll gap. Null-watts samples
        // (load-state-only / sanitized Ident=0) must not drop a PDU from facility sum.
        // 45 min covers staggered site polls + Discover/UPS work without comb-to-zero cliffs.
        $holdSec = max(45 * 60, $bucketMin * 60 * 6);

        $pduSql = 'SELECT pdu_id FROM pdus WHERE is_active = 1';
        $pduParams = [];
        if ($scope === 'zone' && $zoneId && $zoneId > 0) {
            $pduSql .= ' AND zone_id = ?';
            $pduParams[] = $zoneId;
        }
        $pduIds = [];
        try {
            foreach (Database::fetchAll($pduSql, $pduParams) as $pr) {
                $pduIds[] = (int)$pr['pdu_id'];
            }
        } catch (Throwable $e) {
            $pduIds = [];
        }

        // Pull samples with a lookback so hold works at the left edge of the window
        $lookbackSql = date('Y-m-d H:i:s', $fromTs - $holdSec);
        $samples = [];
        try {
            if ($scope === 'zone' && $zoneId && $zoneId > 0) {
                $samples = Database::fetchAll(
                    'SELECT r.pdu_id, r.polled_at, r.watts, r.amps, r.volts, r.volts_ll
                     FROM pdu_readings r
                     INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1 AND p.zone_id = ?
                     WHERE r.polled_at >= ? AND r.polled_at < ?
                     ORDER BY r.polled_at',
                    [$zoneId, $lookbackSql, $toSql]
                );
            } else {
                $samples = Database::fetchAll(
                    'SELECT r.pdu_id, r.polled_at, r.watts, r.amps, r.volts, r.volts_ll
                     FROM pdu_readings r
                     INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1
                     WHERE r.polled_at >= ? AND r.polled_at < ?
                     ORDER BY r.polled_at',
                    [$lookbackSql, $toSql]
                );
            }
        } catch (Throwable $e) {
            try {
                if ($scope === 'zone' && $zoneId && $zoneId > 0) {
                    $samples = Database::fetchAll(
                        'SELECT r.pdu_id, r.polled_at, r.watts, r.amps, r.volts
                         FROM pdu_readings r
                         INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1 AND p.zone_id = ?
                         WHERE r.polled_at >= ? AND r.polled_at < ?
                         ORDER BY r.polled_at',
                        [$zoneId, $lookbackSql, $toSql]
                    );
                } else {
                    $samples = Database::fetchAll(
                        'SELECT r.pdu_id, r.polled_at, r.watts, r.amps, r.volts
                         FROM pdu_readings r
                         INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1
                         WHERE r.polled_at >= ? AND r.polled_at < ?
                         ORDER BY r.polled_at',
                        [$lookbackSql, $toSql]
                    );
                }
            } catch (Throwable $e2) {
                App::log('PowerHistory site/zone hold series: ' . $e2->getMessage(), 'warning');
                $samples = [];
            }
        }

        // Per PDU: list of [ts, watts, amps, volts]
        /** @var array<int,list<array{0:int,1:?float,2:?float,3:?float}>> $byPdu */
        $byPdu = [];
        foreach ($samples as $r) {
            $pid = (int)($r['pdu_id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $ts = strtotime((string)($r['polled_at'] ?? ''));
            if (!$ts) {
                continue;
            }
            $w = isset($r['watts']) && is_numeric($r['watts']) ? (float)$r['watts'] : null;
            $a = isset($r['amps']) && is_numeric($r['amps']) ? (float)$r['amps'] : null;
            $v = null;
            if (isset($r['volts']) && is_numeric($r['volts'])) {
                $v = (float)$r['volts'];
            } elseif (isset($r['volts_ll']) && is_numeric($r['volts_ll'])) {
                $v = (float)$r['volts_ll'] / 1.732;
            }
            // Ignore pure null rows for hold (load_state-only samples without power)
            if ($w === null && $a === null && $v === null) {
                continue;
            }
            $byPdu[$pid][] = [$ts, $w, $a, $v];
        }
        if (!$pduIds && $byPdu) {
            $pduIds = array_keys($byPdu);
        }

        $step = max(1, $bucketMin) * 60;
        $startBucket = (int)(floor($fromTs / $step) * $step);
        $endBucket = (int)(floor(($toTs - 1) / $step) * $step);

        $t = [];
        $watts = [];
        $kw = [];
        $volts = [];
        $amps = [];
        $heldBuckets = 0;
        $rawBuckets = 0;

        for ($b = $startBucket; $b <= $endBucket; $b += $step) {
            $bEnd = $b + $step;
            $sumW = 0.0;
            $sumA = 0.0;
            $sumV = 0.0;
            $nW = 0;
            $nA = 0;
            $nV = 0;
            $anyHeld = false;
            $anyRaw = false;

            foreach ($pduIds as $pid) {
                $list = $byPdu[$pid] ?? [];
                $bucketW = null;
                $bucketA = null;
                $bucketV = null;
                $nIn = 0;
                $wSum = 0.0;
                $aSum = 0.0;
                $vSum = 0.0;
                $nVw = 0;
                $nVa = 0;
                $nVv = 0;
                $lastBefore = null; // last sample with ts < bEnd
                $lastWattsBefore = null; // last sample with non-null watts before bucket end

                foreach ($list as $sample) {
                    [$ts, $w, $a, $v] = $sample;
                    if ($ts < $bEnd) {
                        $lastBefore = $sample;
                        if ($w !== null) {
                            $lastWattsBefore = $sample;
                        }
                    }
                    if ($ts >= $b && $ts < $bEnd) {
                        $nIn++;
                        if ($w !== null) {
                            $wSum += $w;
                            $nVw++;
                        }
                        if ($a !== null) {
                            $aSum += $a;
                            $nVa++;
                        }
                        if ($v !== null) {
                            $vSum += $v;
                            $nVv++;
                        }
                    }
                }

                if ($nIn > 0) {
                    $anyRaw = true;
                    $bucketW = $nVw > 0 ? $wSum / $nVw : null;
                    $bucketA = $nVa > 0 ? $aSum / $nVa : null;
                    $bucketV = $nVv > 0 ? $vSum / $nVv : null;
                    // Critical: poll samples often have watts=NULL (sanitized zero / load-state-only
                    // AP7862) but still land in the bucket. That must not drop the PDU from the
                    // facility sum — hold the last real watts within the hold window.
                    // Explicit watts=0 in the bucket (nVw>0, average ~0) still counts as 0.
                    if ($bucketW === null && $lastWattsBefore !== null) {
                        [$lts, $lw, $la, $lv] = $lastWattsBefore;
                        if ($lw !== null && ($bEnd - 1 - $lts) <= $holdSec) {
                            $bucketW = $lw;
                            $anyHeld = true;
                            if ($bucketA === null && $la !== null) {
                                $bucketA = $la;
                            }
                            if ($bucketV === null && $lv !== null) {
                                $bucketV = $lv;
                            }
                        }
                    }
                } elseif ($lastBefore !== null) {
                    [$lts, $lw, $la, $lv] = $lastBefore;
                    // Prefer last sample that actually had watts when holding across empty buckets
                    if ($lastWattsBefore !== null) {
                        [$lts, $lw, $la, $lv] = $lastWattsBefore;
                    }
                    if (($bEnd - 1 - $lts) <= $holdSec) {
                        $anyHeld = true;
                        $bucketW = $lw;
                        $bucketA = $la;
                        $bucketV = $lv;
                    }
                }

                if ($bucketW !== null) {
                    $sumW += $bucketW;
                    $nW++;
                }
                if ($bucketA !== null) {
                    $sumA += $bucketA;
                    $nA++;
                }
                if ($bucketV !== null) {
                    $sumV += $bucketV;
                    $nV++;
                }
            }

            if ($nW === 0 && $nA === 0 && $nV === 0) {
                continue; // nothing at all in this empty edge
            }
            if ($anyHeld && !$anyRaw) {
                $heldBuckets++;
            }
            if ($anyRaw) {
                $rawBuckets++;
            }

            $t[] = date('c', $b);
            $watts[] = $nW > 0 ? round($sumW, 3) : null;
            $kw[] = $nW > 0 ? round($sumW / 1000.0, 3) : null;
            $amps[] = $nA > 0 ? round($sumA, 3) : null;
            $volts[] = $nV > 0 ? round($sumV / $nV, 2) : null;
        }

        $volts = self::carryForward($volts);

        $outages = self::loadOutageMarkers($scope, $zoneId, $bucketMin, $fromTs, $toTs);
        $outagePhases = [];
        foreach ($outages as $o) {
            foreach ($o['phases'] as $ph) {
                $outagePhases[$ph] = true;
            }
        }

        return [
            'ok' => true,
            'scope' => $scope,
            'scope_id' => $zoneId,
            'hours' => $hours,
            'bucket_minutes' => $bucketMin,
            'series' => [
                't' => $t,
                'watts' => $watts,
                'kw' => $kw,
                'volts' => $volts,
                'amps' => $amps,
            ],
            'outages' => $outages,
            'meta' => [
                'points' => count($t),
                'sample_count' => self::countSamplesRange($scope, $zoneId, $fromTs, $toTs),
                'outage_events' => count($outages),
                'outage_phases' => array_keys($outagePhases),
                'pdu_count' => count($pduIds),
                'hold_seconds' => $holdSec,
                'buckets_with_raw' => $rawBuckets,
                'buckets_hold_only' => $heldBuckets,
                'aggregation' => 'per_pdu_hold_forward',
            ],
        ];
    }

    /**
     * Resolve rolling hours or absolute from/to into unix range.
     * @return array{0:int,1:int,2:int} [fromTs, toTs, hoursEff]
     */
    public static function resolveRange(int $hours, ?string $fromIso, ?string $toIso): array
    {
        $toTs = time();
        $fromTs = $toTs - max(1, $hours) * 3600;
        if ($toIso !== null && trim($toIso) !== '') {
            $t = strtotime(trim($toIso));
            if ($t !== false) {
                // date-only → end of day
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($toIso))) {
                    $t = strtotime(trim($toIso) . ' 23:59:59') ?: $t;
                }
                $toTs = $t;
            }
        }
        if ($fromIso !== null && trim($fromIso) !== '') {
            $t = strtotime(trim($fromIso));
            if ($t !== false) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($fromIso))) {
                    $t = strtotime(trim($fromIso) . ' 00:00:00') ?: $t;
                }
                $fromTs = $t;
            }
        }
        if ($fromTs >= $toTs) {
            $fromTs = $toTs - 86400;
        }
        $maxSpan = self::RETENTION_DAYS * 86400;
        if (($toTs - $fromTs) > $maxSpan) {
            $fromTs = $toTs - $maxSpan;
        }
        $hoursEff = max(1, (int)ceil(($toTs - $fromTs) / 3600));
        return [$fromTs, $toTs, $hoursEff];
    }

    /**
     * Peak / avg / min stats for report cards.
     * @param array<string,mixed> $seriesResult
     * @return array<string,mixed>
     */
    public static function summarizeSeries(array $seriesResult): array
    {
        $s = $seriesResult['series'] ?? [];
        $kw = is_array($s['kw'] ?? null) ? $s['kw'] : [];
        $volts = is_array($s['volts'] ?? null) ? $s['volts'] : [];
        $amps = is_array($s['amps'] ?? null) ? $s['amps'] : [];
        $kwN = array_values(array_filter($kw, static fn($v) => $v !== null && is_numeric($v)));
        $vN = array_values(array_filter($volts, static fn($v) => $v !== null && is_numeric($v)));
        $aN = array_values(array_filter($amps, static fn($v) => $v !== null && is_numeric($v)));

        $stat = static function (array $vals): array {
            if (!$vals) {
                return ['min' => null, 'max' => null, 'avg' => null];
            }
            $min = min($vals);
            $max = max($vals);
            $avg = array_sum($vals) / count($vals);
            return [
                'min' => round((float)$min, 3),
                'max' => round((float)$max, 3),
                'avg' => round((float)$avg, 3),
            ];
        };

        return [
            'kw' => $stat($kwN),
            'volts' => $stat($vN),
            'amps' => $stat($aN),
            'outage_events' => (int)($seriesResult['meta']['outage_events'] ?? count($seriesResult['outages'] ?? [])),
            'points' => (int)($seriesResult['meta']['points'] ?? count($s['t'] ?? [])),
            'sample_count' => (int)($seriesResult['meta']['sample_count'] ?? 0),
            'bucket_minutes' => (int)($seriesResult['bucket_minutes'] ?? 0),
        ];
    }

    /**
     * Flat rows for CSV export.
     * @param array<string,mixed> $seriesResult
     * @return list<array{time:string,kw:?float,watts:?float,volts:?float,amps:?float}>
     */
    public static function tableRows(array $seriesResult): array
    {
        $s = $seriesResult['series'] ?? [];
        $t = $s['t'] ?? [];
        $rows = [];
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'time' => (string)$t[$i],
                'kw' => $s['kw'][$i] ?? null,
                'watts' => $s['watts'][$i] ?? null,
                'volts' => $s['volts'][$i] ?? null,
                'amps' => $s['amps'][$i] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * Build PDU series in PHP so watts/volts/phase lines share identical buckets.
     *
     * @return array<string,mixed>
     */
    private static function seriesFromRawPduSamples(
        int $pduId,
        int $hours,
        int $bucketMin,
        ?int $fromTs = null,
        ?int $toTs = null
    ): array {
        if ($fromTs === null || $toTs === null) {
            $toTs = time();
            $fromTs = $toTs - max(1, $hours) * 3600;
        }
        $fromSql = date('Y-m-d H:i:s', $fromTs);
        $toSql = date('Y-m-d H:i:s', $toTs);
        $rows = [];
        try {
            $rows = Database::fetchAll(
                'SELECT polled_at, watts, amps, volts, volts_ll, phases_json, outage_phases
                 FROM pdu_readings
                 WHERE pdu_id = ?
                   AND polled_at >= ? AND polled_at < ?
                 ORDER BY polled_at',
                [$pduId, $fromSql, $toSql]
            );
        } catch (Throwable $e) {
            try {
                $rows = Database::fetchAll(
                    'SELECT polled_at, watts, amps, volts, phases_json, outage_phases
                     FROM pdu_readings
                     WHERE pdu_id = ?
                       AND polled_at >= ? AND polled_at < ?
                     ORDER BY polled_at',
                    [$pduId, $fromSql, $toSql]
                );
            } catch (Throwable $e2) {
                $rows = [];
            }
        }

        /** @var array<int,array{w:list<float>,a:list<float>,v:list<float>,L1:list<float>,L2:list<float>,L3:list<float>,S1:list<float>,S2:list<float>,S3:list<float>,out:array}> $buckets */
        $buckets = [];
        foreach ($rows as $r) {
            $ts = strtotime((string)($r['polled_at'] ?? ''));
            if (!$ts) {
                continue;
            }
            $bKey = self::bucketUnix($ts, $bucketMin);
            if (!isset($buckets[$bKey])) {
                $buckets[$bKey] = [
                    'w' => [], 'a' => [], 'v' => [],
                    'L1' => [], 'L2' => [], 'L3' => [],
                    'S1' => [], 'S2' => [], 'S3' => [],
                    'out' => [],
                ];
            }
            if (isset($r['watts']) && is_numeric($r['watts'])) {
                $buckets[$bKey]['w'][] = (float)$r['watts'];
            }
            if (isset($r['amps']) && is_numeric($r['amps'])) {
                $buckets[$bKey]['a'][] = (float)$r['amps'];
            }

            $phaseV = ['L1' => null, 'L2' => null, 'L3' => null];
            $json = null;
            if (!empty($r['phases_json'])) {
                $json = json_decode((string)$r['phases_json'], true);
            }
            if (is_array($json)) {
                foreach (['L1', 'L2', 'L3'] as $lab) {
                    if (isset($json[$lab]['volts']) && is_numeric($json[$lab]['volts'])) {
                        $vv = (float)$json[$lab]['volts'];
                        $phaseV[$lab] = $vv;
                        $buckets[$bKey][$lab][] = $vv;
                    }
                    if (isset($json[$lab]['load_state']) && is_numeric($json[$lab]['load_state'])) {
                        $sk = 'S' . substr($lab, 1); // S1/S2/S3
                        $buckets[$bKey][$sk][] = (float)$json[$lab]['load_state'];
                    }
                }
            }

            $v = null;
            if (isset($r['volts']) && is_numeric($r['volts'])) {
                $v = (float)$r['volts'];
            } else {
                $pv = array_filter($phaseV, static fn($x) => $x !== null);
                if ($pv) {
                    $v = array_sum($pv) / count($pv);
                } elseif (isset($r['volts_ll']) && is_numeric($r['volts_ll'])) {
                    // L–L → approximate L–N for display continuity
                    $v = (float)$r['volts_ll'] / 1.732;
                }
            }
            if ($v !== null) {
                $buckets[$bKey]['v'][] = $v;
            }

            // Prefer re-evaluating outages from phases_json (clears legacy false low_v)
            // so charts match current detection rules without rewriting history rows.
            $op = '';
            if (is_array($json) && ($phaseV['L1'] !== null || $phaseV['L2'] !== null || $phaseV['L3'] !== null
                || isset($json['L1']['load_state']) || isset($json['L2']['load_state']) || isset($json['L3']['load_state']))
            ) {
                $re = self::evaluatePhaseOutages($json, null);
                $op = $re !== null ? $re : '';
            } else {
                $op = trim((string)($r['outage_phases'] ?? ''));
            }
            if ($op !== '') {
                $buckets[$bKey]['out'][] = $op;
            }
        }

        ksort($buckets, SORT_NUMERIC);

        $t = [];
        $watts = [];
        $kw = [];
        $volts = [];
        $amps = [];
        $L1 = [];
        $L2 = [];
        $L3 = [];
        $S1 = [];
        $S2 = [];
        $S3 = [];
        $outages = [];

        foreach ($buckets as $bKey => $b) {
            $t[] = date('c', $bKey);
            $w = $b['w'] ? array_sum($b['w']) / count($b['w']) : null;
            $a = $b['a'] ? array_sum($b['a']) / count($b['a']) : null;
            $v = $b['v'] ? array_sum($b['v']) / count($b['v']) : null;
            $watts[] = $w !== null ? round($w, 3) : null;
            $kw[] = $w !== null ? round($w / 1000.0, 3) : null;
            $volts[] = $v !== null ? round($v, 2) : null;
            $amps[] = $a !== null ? round($a, 3) : null;
            $L1[] = $b['L1'] ? round(array_sum($b['L1']) / count($b['L1']), 2) : null;
            $L2[] = $b['L2'] ? round(array_sum($b['L2']) / count($b['L2']), 2) : null;
            $L3[] = $b['L3'] ? round(array_sum($b['L3']) / count($b['L3']), 2) : null;
            // Mode-ish: round average load_state enum to nearest int for display
            $S1[] = $b['S1'] ? (float)(int)round(array_sum($b['S1']) / count($b['S1'])) : null;
            $S2[] = $b['S2'] ? (float)(int)round(array_sum($b['S2']) / count($b['S2'])) : null;
            $S3[] = $b['S3'] ? (float)(int)round(array_sum($b['S3']) / count($b['S3'])) : null;

            if ($b['out']) {
                $phases = [];
                $reasons = [];
                foreach ($b['out'] as $op) {
                    $parsed = self::parseOutagePhasesString($op);
                    foreach ($parsed['phases'] as $ph) {
                        $phases[$ph] = true;
                    }
                    foreach ($parsed['reasons'] as $rs) {
                        $reasons[$rs] = true;
                    }
                }
                $phList = array_keys($phases);
                sort($phList);
                $rsList = array_keys($reasons);
                $label = $phList ? implode(',', $phList) : 'outage';
                if ($rsList) {
                    $label .= ' (' . implode('/', $rsList) . ')';
                }
                $outages[] = [
                    't' => date('c', $bKey),
                    'phases' => $phList,
                    'label' => $label,
                    'count' => count($b['out']),
                    'reasons' => $rsList,
                ];
            }
        }

        // Continuous lines when some buckets lack a metric but earlier ones had it
        $volts = self::carryForward($volts);
        $L1 = self::carryForward($L1);
        $L2 = self::carryForward($L2);
        $L3 = self::carryForward($L3);
        $S1 = self::carryForward($S1);
        $S2 = self::carryForward($S2);
        $S3 = self::carryForward($S3);

        $hasPhase = false;
        foreach (array_merge($L1, $L2, $L3) as $pv) {
            if ($pv !== null) {
                $hasPhase = true;
                break;
            }
        }
        $hasLoadState = false;
        foreach (array_merge($S1, $S2, $S3) as $sv) {
            if ($sv !== null) {
                $hasLoadState = true;
                break;
            }
        }
        $hasAvgVolts = false;
        foreach ($volts as $vv) {
            if ($vv !== null) {
                $hasAvgVolts = true;
                break;
            }
        }

        $series = [
            't' => $t,
            'watts' => $watts,
            'kw' => $kw,
            'volts' => $volts,
            'amps' => $amps,
        ];
        if ($hasPhase) {
            $series['phase_volts'] = ['L1' => $L1, 'L2' => $L2, 'L3' => $L3];
        }
        if ($hasLoadState) {
            $series['phase_load_state'] = ['L1' => $S1, 'L2' => $S2, 'L3' => $S3];
        }

        $outagePhases = [];
        foreach ($outages as $o) {
            foreach ($o['phases'] as $ph) {
                $outagePhases[$ph] = true;
            }
        }

        return [
            'ok' => true,
            'scope' => 'pdu',
            'scope_id' => $pduId,
            'hours' => $hours,
            'from' => date('c', $fromTs),
            'to' => date('c', $toTs),
            'bucket_minutes' => $bucketMin,
            'series' => $series,
            'outages' => $outages,
            'meta' => [
                'points' => count($t),
                'sample_count' => count($rows),
                'outage_events' => count($outages),
                'outage_phases' => array_keys($outagePhases),
                'has_phase_volts' => $hasPhase,
                'has_phase_load_state' => $hasLoadState,
                'has_avg_volts' => $hasAvgVolts,
            ],
        ];
    }

    /** Unix timestamp floored to bucket start (seconds). */
    private static function bucketUnix(int $ts, int $bucketMin): int
    {
        $step = max(1, $bucketMin) * 60;
        return (int)(floor($ts / $step) * $step);
    }

    /**
     * Forward-fill nulls so a sparse metric still draws a continuous line.
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
     * Outage markers bucketed for charts.
     *
     * @return list<array{t:string,phases:list<string>,label:string,count:int,reasons:list<string>}>
     */
    private static function loadOutageMarkers(
        string $scope,
        ?int $scopeId,
        int $bucketMin,
        int $fromTs,
        int $toTs
    ): array {
        $params = [];
        $where = "r.polled_at >= ? AND r.polled_at < ?
                  AND r.outage_phases IS NOT NULL AND LTRIM(RTRIM(r.outage_phases)) <> ''";
        $params[] = date('Y-m-d H:i:s', $fromTs);
        $params[] = date('Y-m-d H:i:s', $toTs);
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
        if ($hours <= 24 * 100) {
            return 180; // 3h
        }
        return 360; // 6h for annual-scale
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

    private static function countSamplesRange(string $scope, ?int $scopeId, int $fromTs, int $toTs): int
    {
        $fromSql = date('Y-m-d H:i:s', $fromTs);
        $toSql = date('Y-m-d H:i:s', $toTs);
        try {
            if ($scope === 'pdu' && $scopeId) {
                return (int)Database::fetchValue(
                    'SELECT COUNT(*) FROM pdu_readings
                     WHERE pdu_id = ? AND polled_at >= ? AND polled_at < ?',
                    [$scopeId, $fromSql, $toSql]
                );
            }
            if ($scope === 'zone' && $scopeId) {
                return (int)Database::fetchValue(
                    'SELECT COUNT(*) FROM pdu_readings r
                     INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.zone_id = ? AND p.is_active = 1
                     WHERE r.polled_at >= ? AND polled_at < ?',
                    [$scopeId, $fromSql, $toSql]
                );
            }
            return (int)Database::fetchValue(
                'SELECT COUNT(*) FROM pdu_readings r
                 INNER JOIN pdus p ON p.pdu_id = r.pdu_id AND p.is_active = 1
                 WHERE r.polled_at >= ? AND r.polled_at < ?',
                [$fromSql, $toSql]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }
}
