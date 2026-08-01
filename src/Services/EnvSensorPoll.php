<?php
/**
 * Map site-template SNMP metrics (temperature.N / humidity.N) onto env_sensors.
 * APC EMS: one management module holds a flat probe table for MM + TH expansion modules.
 */
declare(strict_types=1);

class EnvSensorPoll
{
    /**
     * APC PowerNet EMSProbeStatusEntry (powernet MIB):
     *  .1 index  .2 name  .3 temperature  .4 highTempThresh  .5 lowTempThresh  .6 humidity
     * Earlier bug used .4/.5 as "names" → all probes showed "59" (threshold).
     */
    private const EMS_TEMP_OID = '1.3.6.1.4.1.318.1.1.10.3.13.1.1.3';
    private const EMS_HUM_OID = '1.3.6.1.4.1.318.1.1.10.3.13.1.1.6';
    private const EMS_STATUS_NAME_OID = '1.3.6.1.4.1.318.1.1.10.3.13.1.1.2';
    /** Config table names (often more descriptive): emsProbeConfigProbeName */
    private const EMS_CONFIG_NAME_OID = '1.3.6.1.4.1.318.1.1.10.3.7.1.1.2';
    private const MAX_PROBE_INDEX = 32;
    private const MISS_STREAK_STOP = 3;

    /**
     * Expand metrics with live EMS probe table (temp/humidity/name for indices 1..N).
     * Call while SNMP session is still open.
     *
     * @param array<string,mixed> $session SnmpPoller session
     * @param array<string,array{numeric:?float,raw?:mixed,oid?:string}> $metrics
     * @return array{metrics:array,probe_names:array<int,string>,expanded:int}
     */
    public static function expandApcEmsProbes(array $session, array $metrics): array
    {
        $probeNames = [];
        $expanded = 0;
        $miss = 0;
        $anyHit = false;

        for ($i = 1; $i <= self::MAX_PROBE_INDEX; $i++) {
            $tOid = self::EMS_TEMP_OID . '.' . $i;
            $hOid = self::EMS_HUM_OID . '.' . $i;
            $tKey = 'temperature.' . $i;
            $hKey = 'humidity.' . $i;

            $gotTemp = null;
            $gotHum = null;
            $gotName = null;

            // Prefer already-collected template values
            if (isset($metrics[$tKey]['numeric']) && $metrics[$tKey]['numeric'] !== null) {
                $gotTemp = (float)$metrics[$tKey]['numeric'];
            } else {
                try {
                    $raw = SnmpPoller::sessionGet($session, $tOid);
                    $n = SnmpPoller::sessionToNumber($raw);
                    if ($n !== null) {
                        $gotTemp = self::normalizeEnvValue((float)$n, 'temperature');
                        $metrics[$tKey] = ['numeric' => $gotTemp, 'raw' => $raw, 'oid' => $tOid];
                        $expanded++;
                    }
                } catch (Throwable $e) {
                    // miss
                }
            }

            if (isset($metrics[$hKey]['numeric']) && $metrics[$hKey]['numeric'] !== null) {
                $gotHum = (float)$metrics[$hKey]['numeric'];
            } else {
                try {
                    $raw = SnmpPoller::sessionGet($session, $hOid);
                    $n = SnmpPoller::sessionToNumber($raw);
                    if ($n !== null) {
                        $gotHum = self::normalizeEnvValue((float)$n, 'humidity');
                        $metrics[$hKey] = ['numeric' => $gotHum, 'raw' => $raw, 'oid' => $hOid];
                        $expanded++;
                    }
                } catch (Throwable $e) {
                    // miss
                }
            }

            // Status name (.2) first, then config name — never thresh columns (.4/.5)
            foreach ([self::EMS_STATUS_NAME_OID, self::EMS_CONFIG_NAME_OID] as $nameBase) {
                try {
                    $raw = SnmpPoller::sessionGet($session, $nameBase . '.' . $i);
                    $label = self::cleanProbeName($raw);
                    if ($label !== '' && self::isPlausibleProbeName($label)) {
                        $gotName = $label;
                        $metrics['probe_name.' . $i] = [
                            'numeric' => null,
                            'raw' => $raw,
                            'oid' => $nameBase . '.' . $i,
                        ];
                        break;
                    }
                } catch (Throwable $e) {
                    // try next name column
                }
            }
            if ($gotName !== null) {
                $probeNames[$i] = $gotName;
            }

            if ($gotTemp !== null || $gotHum !== null || $gotName !== null) {
                $anyHit = true;
                $miss = 0;
            } else {
                $miss++;
                if ($anyHit && $miss >= self::MISS_STREAK_STOP) {
                    break;
                }
                // Before first hit, still scan a few (sparse agents) but stop early if empty table
                if (!$anyHit && $i >= 8 && $miss >= 8) {
                    break;
                }
            }
        }

        if ($probeNames) {
            App::log(
                'EnvSensorPoll EMS probes named: ' . implode(', ', array_map(
                    static fn($i, $n) => $i . '=' . $n,
                    array_keys($probeNames),
                    array_values($probeNames)
                )),
                'info'
            );
        }

        return [
            'metrics' => $metrics,
            'probe_names' => $probeNames,
            'expanded' => $expanded,
        ];
    }

    /**
     * After a successful device poll, write matching env metrics to sensors + history.
     *
     * @param array<string,array{numeric:?float,raw?:mixed,oid?:string}> $metrics lowercase keys
     * @param array<string,mixed> $oidMap original template map
     * @param array<int,string> $probeNames SNMP probe labels by table index
     * @return array{updated:int,readings:int,unmatched:int,keys:int,matched:list<string>,candidates:int,probes:int}
     */
    public static function applyFromDevicePoll(
        int $deviceId,
        int $templateId,
        array $metrics,
        array $oidMap,
        array $probeNames = []
    ): array {
        // Build names from metrics if expand stored probe_name.N
        if (!$probeNames) {
            foreach ($metrics as $k => $meta) {
                if (preg_match('/^probe_name\.(\d+)$/', (string)$k, $m)) {
                    $label = self::cleanProbeName($meta['raw'] ?? null);
                    if ($label !== '') {
                        $probeNames[(int)$m[1]] = $label;
                    }
                }
            }
        }

        $envMetrics = self::extractEnvMetrics($metrics);
        if (!$envMetrics['temperature'] && !$envMetrics['humidity']) {
            return [
                'updated' => 0,
                'readings' => 0,
                'unmatched' => 0,
                'keys' => 0,
                'matched' => [],
                'candidates' => 0,
                'probes' => 0,
            ];
        }

        $sensors = self::loadCandidateSensors($deviceId, $templateId);
        $keyCount = count($envMetrics['temperature']) + count($envMetrics['humidity']);
        $probeCount = max(
            count($envMetrics['temperature']),
            count($envMetrics['humidity']),
            count($probeNames)
        );

        if (!$sensors) {
            return [
                'updated' => 0,
                'readings' => 0,
                'unmatched' => $keyCount,
                'keys' => $keyCount,
                'matched' => [],
                'candidates' => 0,
                'probes' => $probeCount,
            ];
        }

        $now = date('Y-m-d H:i:s');
        $updated = 0;
        $readings = 0;
        $matchedLabels = [];
        $usedTemp = [];
        $usedHum = [];

        // Pre-assign ordered fallbacks for sensors that still lack a name match
        $orderMap = self::buildOrderFallbackMap($sensors, $envMetrics, $probeNames);

        foreach ($sensors as $sensor) {
            $sid = (int)$sensor['sensor_id'];
            $kind = strtolower((string)($sensor['sensor_kind'] ?? 'temperature'));
            $inst = self::matchSensorToProbeIndex($sensor, $envMetrics, $probeNames);
            if ($inst === null && isset($orderMap[$sid])) {
                $inst = $orderMap[$sid];
            }
            $tempVal = null;
            $humVal = null;
            $mapKeyTemp = null;
            $mapKeyHum = null;

            if ($inst !== null) {
                if (isset($envMetrics['temperature'][$inst])) {
                    $tempVal = $envMetrics['temperature'][$inst];
                    $mapKeyTemp = 'temperature.' . $inst;
                }
                if (isset($envMetrics['humidity'][$inst])) {
                    $humVal = $envMetrics['humidity'][$inst];
                    $mapKeyHum = 'humidity.' . $inst;
                }
            }

            // Explicit map key still wins if present and valid
            $idxRaw = strtolower(trim((string)($sensor['snmp_index'] ?? '')));
            if (preg_match('/^(temperature|temp)\.(\d+)$/', $idxRaw, $m)) {
                $i = (int)$m[2];
                if (isset($envMetrics['temperature'][$i])) {
                    $tempVal = $envMetrics['temperature'][$i];
                    $mapKeyTemp = 'temperature.' . $i;
                    $inst = $i;
                }
            }
            if (preg_match('/^(humidity|humid)\.(\d+)$/', $idxRaw, $m)) {
                $i = (int)$m[2];
                if (isset($envMetrics['humidity'][$i])) {
                    $humVal = $envMetrics['humidity'][$i];
                    $mapKeyHum = 'humidity.' . $i;
                    $inst = $i;
                }
            }

            // Exact OID match
            $oid = ltrim(trim((string)($sensor['snmp_oid'] ?? '')), '.');
            if ($oid !== '') {
                foreach ($metrics as $mk => $meta) {
                    $moid = ltrim((string)($meta['oid'] ?? ''), '.');
                    if ($moid === '' || $moid !== $oid) {
                        continue;
                    }
                    $n = $meta['numeric'] ?? null;
                    if ($n === null) {
                        continue;
                    }
                    if (preg_match('/^humidity/', (string)$mk) || str_contains((string)$mk, 'humid')) {
                        $humVal = self::normalizeEnvValue((float)$n, 'humidity');
                        $mapKeyHum = (string)$mk;
                    } else {
                        $tempVal = self::normalizeEnvValue((float)$n, 'temperature');
                        $mapKeyTemp = (string)$mk;
                    }
                }
            }

            $primary = null;
            $primaryMetric = null;
            if ($kind === 'humidity') {
                $primary = $humVal;
                $primaryMetric = 'humidity';
            } elseif ($kind === 'temp_humidity') {
                $primary = $tempVal ?? $humVal;
                $primaryMetric = $tempVal !== null ? 'temperature' : 'humidity';
            } else {
                $primary = $tempVal;
                $primaryMetric = 'temperature';
                if ($primary === null && $kind === 'other' && $humVal !== null) {
                    $primary = $humVal;
                    $primaryMetric = 'humidity';
                }
            }

            if ($primary === null && $humVal === null) {
                continue;
            }

            $fields = [
                'last_seen_at' => $now,
                'updated_at' => $now,
            ];
            if ($primary !== null) {
                $fields['last_value'] = $primary;
            }
            if ($kind === 'temp_humidity' && $humVal !== null) {
                $fields['last_humidity'] = $humVal;
            }
            // Persist resolved SNMP table index (not module port alone)
            if ($inst !== null) {
                $fields['snmp_index'] = (string)$inst;
            }
            if ($templateId > 0 && empty($sensor['snmp_site_template_id'])) {
                $fields['snmp_site_template_id'] = $templateId;
            }

            try {
                Database::update('env_sensors', $fields, 'sensor_id = :id', [':id' => $sid]);
            } catch (Throwable $e) {
                if (isset($fields['last_humidity'])) {
                    unset($fields['last_humidity']);
                    try {
                        Database::update('env_sensors', $fields, 'sensor_id = :id', [':id' => $sid]);
                    } catch (Throwable $e2) {
                        App::log('EnvSensorPoll update sensor ' . $sid . ': ' . $e2->getMessage(), 'warning');
                        continue;
                    }
                } else {
                    App::log('EnvSensorPoll update sensor ' . $sid . ': ' . $e->getMessage(), 'warning');
                    continue;
                }
            }

            if ($primary !== null) {
                try {
                    Database::insert('env_readings', [
                        'sensor_id' => $sid,
                        'value' => $primary,
                        'recorded_at' => $now,
                        'metric' => $primaryMetric,
                    ]);
                    $readings++;
                } catch (Throwable $e) {
                    try {
                        Database::insert('env_readings', [
                            'sensor_id' => $sid,
                            'value' => $primary,
                            'recorded_at' => $now,
                        ]);
                        $readings++;
                    } catch (Throwable $e2) {
                        App::log('EnvSensorPoll reading sensor ' . $sid . ': ' . $e2->getMessage(), 'warning');
                    }
                }
            }
            if ($kind === 'temp_humidity' && $humVal !== null) {
                try {
                    Database::insert('env_readings', [
                        'sensor_id' => $sid,
                        'value' => $humVal,
                        'recorded_at' => $now,
                        'metric' => 'humidity',
                    ]);
                    $readings++;
                } catch (Throwable $e) {
                    // optional metric column
                }
            }

            $updated++;
            $label = (string)($sensor['name'] ?? ('#' . $sid));
            if ($primary !== null) {
                $matchedLabels[] = $label . '[#' . ($inst ?? '?') . ']=' . self::fmt($primary)
                    . ($kind === 'temp_humidity' && $humVal !== null ? '/' . self::fmt($humVal) . '%RH' : '');
            }
            if ($mapKeyTemp && $inst !== null) {
                $usedTemp[$inst] = true;
            }
            if ($mapKeyHum && $inst !== null) {
                $usedHum[$inst] = true;
            }
        }

        $unusedKeys = 0;
        foreach ($envMetrics['temperature'] as $i => $_) {
            if (empty($usedTemp[$i])) {
                $unusedKeys++;
            }
        }
        foreach ($envMetrics['humidity'] as $i => $_) {
            if (empty($usedHum[$i])) {
                $unusedKeys++;
            }
        }

        App::log(
            'EnvSensorPoll device_id=' . $deviceId
            . ' candidates=' . count($sensors)
            . ' updated=' . $updated
            . ' readings=' . $readings
            . ' probes=' . $probeCount
            . ' names=' . count($probeNames),
            'info'
        );

        return [
            'updated' => $updated,
            'readings' => $readings,
            'unmatched' => $unusedKeys,
            'keys' => $keyCount,
            'matched' => $matchedLabels,
            'candidates' => count($sensors),
            'probes' => $probeCount,
        ];
    }

    /**
     * Resolve which EMS table index a sensor row maps to.
     * Prefer SNMP probe names (TH01:1 labels) over naive port→index mapping.
     *
     * @param array{temperature:array<int,float>,humidity:array<int,float>} $envMetrics
     * @param array<int,string> $probeNames
     */
    public static function matchSensorToProbeIndex(
        array $sensor,
        array $envMetrics,
        array $probeNames
    ): ?int {
        $idx = trim((string)($sensor['snmp_index'] ?? ''));
        // Pure numeric index or temperature.N — trusted when set
        if ($idx !== '') {
            if (preg_match('/^(?:temperature|temp|humidity|humid|rh)\.(\d+)$/i', $idx, $m)) {
                return (int)$m[1];
            }
            if (preg_match('/^\d+$/', $idx)) {
                $n = (int)$idx;
                if (isset($envMetrics['temperature'][$n]) || isset($envMetrics['humidity'][$n])
                    || isset($probeNames[$n])
                ) {
                    return $n;
                }
            }
        }

        $sensorName = (string)($sensor['name'] ?? '');
        $sensorNorm = self::normalizeLabel($sensorName);

        // 1) Exact / contains match against SNMP probe names
        if ($probeNames && $sensorNorm !== '') {
            $best = null;
            $bestScore = 0;
            foreach ($probeNames as $i => $pname) {
                $pnorm = self::normalizeLabel($pname);
                if ($pnorm === '') {
                    continue;
                }
                $score = 0;
                if ($pnorm === $sensorNorm) {
                    $score = 100;
                } elseif (str_contains($sensorNorm, $pnorm) || str_contains($pnorm, $sensorNorm)) {
                    $score = 80;
                } else {
                    // Token overlap: TH01, 1, MM, TEMP…
                    $st = self::labelTokens($sensorNorm);
                    $pt = self::labelTokens($pnorm);
                    $overlap = count(array_intersect($st, $pt));
                    if ($overlap >= 2) {
                        $score = 40 + ($overlap * 10);
                    } elseif ($overlap === 1 && (in_array('mm', $st, true) || preg_match('/^th\d+$/', implode('', $st)))) {
                        $score = 25;
                    }
                }
                // Prefer TH/MM codes matching both sides
                if (preg_match('/\b(th\d+|mm)\b/', $sensorNorm, $sm)
                    && preg_match('/\b' . preg_quote($sm[1], '/') . '\b/', $pnorm)
                ) {
                    $score += 20;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = (int)$i;
                }
            }
            if ($best !== null && $bestScore >= 40) {
                return $best;
            }
        }

        // 2) Structured codes against probe names: TH01:1, MM:1
        if ($probeNames) {
            if (preg_match('/\bTH\s*0*(\d+)\s*:?\s*(\d+)\b/i', $sensorName, $m)) {
                $mod = (int)$m[1];
                $port = (int)$m[2];
                $patterns = [
                    sprintf('th%02d:%d', $mod, $port),
                    sprintf('th%d:%d', $mod, $port),
                    sprintf('th%02d %d', $mod, $port),
                    sprintf('th%02d-%d', $mod, $port),
                    sprintf('th%02d_%d', $mod, $port),
                ];
                foreach ($probeNames as $i => $pname) {
                    $p = self::normalizeLabel($pname);
                    foreach ($patterns as $pat) {
                        if (str_contains($p, $pat) || $p === $pat) {
                            return (int)$i;
                        }
                    }
                    // "TH01" and port digit as separate tokens
                    if (str_contains($p, sprintf('th%02d', $mod)) || str_contains($p, 'th' . $mod)) {
                        if (preg_match('/(?:^|[^0-9])' . $port . '(?:[^0-9]|$)/', $p)) {
                            return (int)$i;
                        }
                    }
                }
            }
            if (preg_match('/\bMM\s*:?\s*(\d+)\b/i', $sensorName, $m)) {
                $port = (int)$m[1];
                foreach ($probeNames as $i => $pname) {
                    $p = self::normalizeLabel($pname);
                    if (str_contains($p, 'mm') && (
                        str_contains($p, 'mm:' . $port)
                        || str_contains($p, 'mm ' . $port)
                        || preg_match('/\bmm\b.*\b' . $port . '\b/', $p)
                    )) {
                        return (int)$i;
                    }
                }
                // Fallback: first probe named with MM only when port is 1
                if ($port === 1) {
                    foreach ($probeNames as $i => $pname) {
                        if (str_contains(self::normalizeLabel($pname), 'mm')) {
                            return (int)$i;
                        }
                    }
                }
            }
        }

        // 3) MM:N → SNMP index N only when no probe names (legacy template-only poll)
        if (!$probeNames && preg_match('/\bMM\s*:?\s*(\d+)\b/i', $sensorName, $m)) {
            $n = (int)$m[1];
            if (isset($envMetrics['temperature'][$n]) || isset($envMetrics['humidity'][$n])) {
                return $n;
            }
        }

        // 4) Do NOT map TH01:1 → index 1 by port alone (collides with MM and is usually wrong)

        return null;
    }

    /**
     * @param array<string,array{numeric:?float,raw?:mixed,oid?:string}> $metrics
     * @return array{temperature:array<int,float>,humidity:array<int,float>}
     */
    public static function extractEnvMetrics(array $metrics): array
    {
        $temp = [];
        $hum = [];
        foreach ($metrics as $key => $meta) {
            $k = strtolower(trim((string)$key));
            $n = $meta['numeric'] ?? null;
            if ($n === null) {
                continue;
            }
            if (preg_match('/^(temperature|temp)\.(\d+)$/', $k, $m)) {
                $temp[(int)$m[2]] = self::normalizeEnvValue((float)$n, 'temperature');
                continue;
            }
            if (preg_match('/^(humidity|humid|rh)\.(\d+)$/', $k, $m)) {
                $hum[(int)$m[2]] = self::normalizeEnvValue((float)$n, 'humidity');
                continue;
            }
            if ($k === 'temperature' || $k === 'temp') {
                $temp[1] = self::normalizeEnvValue((float)$n, 'temperature');
            } elseif ($k === 'humidity' || $k === 'humid' || $k === 'rh') {
                $hum[1] = self::normalizeEnvValue((float)$n, 'humidity');
            }
        }
        return ['temperature' => $temp, 'humidity' => $hum];
    }

    public static function normalizeEnvValue(float $n, string $kind): float
    {
        if ($kind === 'humidity') {
            if ($n > 100 && $n <= 1000) {
                return round($n / 10.0, 2);
            }
            return round($n, 2);
        }
        if ($n >= 100 && $n <= 800) {
            return round($n / 10.0, 2);
        }
        return round($n, 2);
    }

    /**
     * Sensors on the polled EMS host, all expansion modules (env_module),
     * same site template, or any device-hosted sensor on env_* types.
     *
     * @return list<array<string,mixed>>
     */
    private static function loadCandidateSensors(int $deviceId, int $templateId): array
    {
        $seen = [];
        $rows = [];

        $add = static function (array $batch) use (&$rows, &$seen): void {
            foreach ($batch as $r) {
                $id = (int)($r['sensor_id'] ?? 0);
                if ($id < 1 || !empty($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $rows[] = $r;
            }
        };

        try {
            $add(Database::fetchAll(
                'SELECT sensor_id, name, sensor_kind, host_type, device_id, snmp_oid, snmp_index,
                        snmp_site_template_id, last_value, unit
                 FROM env_sensors
                 WHERE is_active = 1 AND (
                    device_id = ?
                    OR (snmp_site_template_id IS NOT NULL AND snmp_site_template_id = ?)
                 )',
                [$deviceId, $templateId > 0 ? $templateId : -1]
            ));
        } catch (Throwable $e) {
            return [];
        }

        // All sensors on env expansion modules (TH) and other env monitors — single-system sites
        // typically have one EMS; multi-EMS sites should set snmp_site_template_id / snmp_index.
        try {
            $add(Database::fetchAll(
                "SELECT s.sensor_id, s.name, s.sensor_kind, s.host_type, s.device_id, s.snmp_oid, s.snmp_index,
                        s.snmp_site_template_id, s.last_value, s.unit
                 FROM env_sensors s
                 INNER JOIN devices d ON d.device_id = s.device_id AND d.is_active = 1
                 WHERE s.is_active = 1
                   AND d.device_type IN ('env_module', 'env_monitor')
                   AND s.device_id <> ?",
                [$deviceId]
            ));
        } catch (Throwable $e) {
            // optional
        }

        // Sensors whose name looks like EMS probes even if host type wrong
        try {
            $add(Database::fetchAll(
                "SELECT sensor_id, name, sensor_kind, host_type, device_id, snmp_oid, snmp_index,
                        snmp_site_template_id, last_value, unit
                 FROM env_sensors
                 WHERE is_active = 1
                   AND (
                     name LIKE '%TH0%' OR name LIKE '%TH %' OR name LIKE '%MM:%'
                     OR name LIKE '%MM %' OR name LIKE '%Temp Sensor%' OR name LIKE '%Humidity%'
                   )"
            ));
        } catch (Throwable $e) {
            // optional
        }

        return $rows;
    }

    public static function cleanProbeName($raw): string
    {
        if ($raw === null || $raw === false) {
            return '';
        }
        $s = is_string($raw) ? $raw : (string)$raw;
        // Strip SNMP type prefixes: STRING: "foo" / Hex-STRING: …
        if (preg_match(
            '/^(?:STRING|OCTET\s*STRING|Hex-STRING|DisplayString)\s*:\s*(.*)$/i',
            trim($s),
            $m
        )) {
            $s = $m[1];
        }
        // INTEGER / Gauge etc. are not names
        if (preg_match('/^(?:INTEGER|Integer32|Gauge32|Counter(?:32|64)|Unsigned32)\s*:/i', trim($s))) {
            return '';
        }
        $s = trim($s, " \t\n\r\0\x0B\"'");
        if ($s === '' || strcasecmp($s, 'null') === 0 || $s === '""') {
            return '';
        }
        return $s;
    }

    /**
     * Reject threshold/enum leftovers (e.g. "59") so they never become probe labels.
     */
    public static function isPlausibleProbeName(string $label): bool
    {
        $t = trim($label);
        if ($t === '') {
            return false;
        }
        // Pure numbers are almost always wrong column (temp/thresh/status)
        if (preg_match('/^[-+]?\d+(?:\.\d+)?$/', $t)) {
            return false;
        }
        // Need at least one letter
        if (!preg_match('/[A-Za-z]/', $t)) {
            return false;
        }
        return true;
    }

    /**
     * When SNMP names are missing/useless, map sensors in rack order to probe indexes
     * that have temperature (or humidity): MM ports first, then TH01, TH02, …
     *
     * @param list<array<string,mixed>> $sensors
     * @param array{temperature:array<int,float>,humidity:array<int,float>} $envMetrics
     * @param array<int,string> $probeNames
     * @return array<int,int> sensor_id => probe index
     */
    public static function buildOrderFallbackMap(
        array $sensors,
        array $envMetrics,
        array $probeNames
    ): array {
        // Probe indexes that have live data, ascending
        $indexes = [];
        foreach (array_keys($envMetrics['temperature'] + $envMetrics['humidity']) as $i) {
            $indexes[] = (int)$i;
        }
        $indexes = array_values(array_unique($indexes));
        sort($indexes, SORT_NUMERIC);
        if (!$indexes) {
            return [];
        }

        // Sensors still unmatched by name/index — skip those already resolvable
        $pending = [];
        $claimed = [];
        foreach ($sensors as $s) {
            $sid = (int)($s['sensor_id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            $hit = self::matchSensorToProbeIndex($s, $envMetrics, $probeNames);
            if ($hit !== null) {
                $claimed[$hit] = true;
                continue;
            }
            $pending[] = $s;
        }
        if (!$pending) {
            return [];
        }

        $free = [];
        foreach ($indexes as $ix) {
            if (empty($claimed[$ix])) {
                $free[] = $ix;
            }
        }
        if (!$free) {
            return [];
        }

        usort($pending, static function (array $a, array $b): int {
            return self::sensorSortKey($a) <=> self::sensorSortKey($b);
        });

        $map = [];
        $n = min(count($pending), count($free));
        for ($i = 0; $i < $n; $i++) {
            $map[(int)$pending[$i]['sensor_id']] = $free[$i];
        }
        return $map;
    }

    /**
     * Sort key: MM before TH, module number, port, then name.
     * @return array{0:int,1:int,2:int,3:string}
     */
    public static function sensorSortKey(array $sensor): array
    {
        $name = (string)($sensor['name'] ?? '');
        if (preg_match('/\bMM\s*:?\s*(\d+)\b/i', $name, $m)) {
            return [0, 0, (int)$m[1], strtolower($name)];
        }
        if (preg_match('/\bTH\s*0*(\d+)\s*:?\s*(\d+)\b/i', $name, $m)) {
            return [1, (int)$m[1], (int)$m[2], strtolower($name)];
        }
        return [9, 0, 0, strtolower($name)];
    }

    public static function normalizeLabel(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = str_replace(['_', '-'], ' ', $s);
        // collapse "th 01" → "th01"
        $s = preg_replace('/\bth\s*0*(\d+)\b/', 'th$1', $s) ?? $s;
        $s = preg_replace('/\bmm\s*:?\s*/', 'mm:', $s) ?? $s;
        return trim($s);
    }

    /** @return list<string> */
    private static function labelTokens(string $norm): array
    {
        $parts = preg_split('/[^a-z0-9]+/', $norm) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === 'sensor' || $p === 'temp' || $p === 'temperature' || $p === 'humidity') {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(sprintf('%.2F', $n), '0'), '.');
    }
}
