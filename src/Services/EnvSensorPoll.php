<?php
/**
 * Map site-template SNMP metrics (temperature.N / humidity.N) onto env_sensors.
 */
declare(strict_types=1);

class EnvSensorPoll
{
    /**
     * After a successful device poll, write matching env metrics to sensors + history.
     *
     * @param array<string,array{numeric:?float,raw?:mixed,oid?:string}> $metrics lowercase keys
     * @param array<string,mixed> $oidMap original template map
     * @return array{updated:int,readings:int,unmatched:int,keys:int,matched:list<string>}
     */
    public static function applyFromDevicePoll(
        int $deviceId,
        int $templateId,
        array $metrics,
        array $oidMap
    ): array {
        $envMetrics = self::extractEnvMetrics($metrics);
        if (!$envMetrics['temperature'] && !$envMetrics['humidity']) {
            return [
                'updated' => 0,
                'readings' => 0,
                'unmatched' => 0,
                'keys' => 0,
                'matched' => [],
            ];
        }

        $sensors = self::loadCandidateSensors($deviceId, $templateId, $oidMap);
        $keyCount = count($envMetrics['temperature']) + count($envMetrics['humidity']);
        if (!$sensors) {
            return [
                'updated' => 0,
                'readings' => 0,
                'unmatched' => $keyCount,
                'keys' => $keyCount,
                'matched' => [],
            ];
        }

        $now = date('Y-m-d H:i:s');
        $updated = 0;
        $readings = 0;
        $matchedLabels = [];
        $usedTemp = [];
        $usedHum = [];

        foreach ($sensors as $sensor) {
            $sid = (int)$sensor['sensor_id'];
            $kind = strtolower((string)($sensor['sensor_kind'] ?? 'temperature'));
            $inst = self::resolveProbeInstance($sensor, $envMetrics);
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

            // Explicit map key in snmp_index (temperature.3 / humidity.2)
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
                    if (preg_match('/^humidity/', $mk) || str_contains($mk, 'humid')) {
                        $humVal = self::normalizeEnvValue($n, 'humidity');
                        $mapKeyHum = $mk;
                    } else {
                        $tempVal = self::normalizeEnvValue($n, 'temperature');
                        $mapKeyTemp = $mk;
                    }
                }
            }

            $primary = null;
            $primaryMetric = null;
            if ($kind === 'humidity') {
                $primary = $humVal;
                $primaryMetric = 'humidity';
                if ($primary === null && $tempVal !== null && $mapKeyHum === null) {
                    // mis-kinded? leave null
                }
            } elseif ($kind === 'temp_humidity') {
                $primary = $tempVal ?? $humVal;
                $primaryMetric = $tempVal !== null ? 'temperature' : 'humidity';
            } else {
                // temperature, dew_point, other → prefer temp
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
            // Persist resolved index for next poll (help admin)
            if ($inst !== null && trim((string)($sensor['snmp_index'] ?? '')) === '') {
                if ($kind === 'humidity') {
                    $fields['snmp_index'] = 'humidity.' . $inst;
                } elseif ($kind === 'temp_humidity') {
                    $fields['snmp_index'] = (string)$inst;
                } else {
                    $fields['snmp_index'] = 'temperature.' . $inst;
                }
            }
            if ($templateId > 0 && empty($sensor['snmp_site_template_id'])) {
                $fields['snmp_site_template_id'] = $templateId;
            }

            try {
                Database::update('env_sensors', $fields, 'sensor_id = :id', [':id' => $sid]);
            } catch (Throwable $e) {
                // last_humidity column may not exist yet — retry without it
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
                    // metric column optional
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
                    // ignore if metric column missing — humidity already in last_humidity
                }
            }

            $updated++;
            $label = (string)($sensor['name'] ?? ('#' . $sid));
            if ($primary !== null) {
                $matchedLabels[] = $label . '=' . self::fmt($primary)
                    . ($kind === 'temp_humidity' && $humVal !== null ? '/' . self::fmt($humVal) . '%RH' : '');
            }
            if ($mapKeyTemp) {
                $usedTemp[$inst ?? 0] = true;
            }
            if ($mapKeyHum) {
                $usedHum[$inst ?? 0] = true;
            }
        }

        $envKeyCount = count($envMetrics['temperature']) + count($envMetrics['humidity']);
        $unmatched = max(0, $envKeyCount - count($usedTemp) - count($usedHum));
        // Recompute unmatched as sensors with no write is less useful; report unused metric keys
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

        if ($updated > 0) {
            App::log(
                'EnvSensorPoll device_id=' . $deviceId
                . ' updated=' . $updated
                . ' readings=' . $readings
                . ' keys=' . $envKeyCount,
                'info'
            );
        }

        return [
            'updated' => $updated,
            'readings' => $readings,
            'unmatched' => $unusedKeys,
            'keys' => $envKeyCount,
            'matched' => $matchedLabels,
        ];
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
            // Bare keys
            if ($k === 'temperature' || $k === 'temp') {
                $temp[1] = self::normalizeEnvValue((float)$n, 'temperature');
            } elseif ($k === 'humidity' || $k === 'humid' || $k === 'rh') {
                $hum[1] = self::normalizeEnvValue((float)$n, 'humidity');
            }
        }
        return ['temperature' => $temp, 'humidity' => $hum];
    }

    /**
     * APC EMS sometimes reports temp in tenths (°C × 10). Humidity is usually 0–100.
     */
    public static function normalizeEnvValue(float $n, string $kind): float
    {
        if ($kind === 'humidity') {
            // Some agents report RH × 10
            if ($n > 100 && $n <= 1000) {
                return round($n / 10.0, 2);
            }
            return round($n, 2);
        }
        // Temperature: if clearly tenths of °C (e.g. 235 → 23.5)
        if ($n >= 100 && $n <= 800) {
            return round($n / 10.0, 2);
        }
        return round($n, 2);
    }

    /**
     * @param array<string,mixed> $oidMap
     * @return list<array<string,mixed>>
     */
    private static function loadCandidateSensors(int $deviceId, int $templateId, array $oidMap): array
    {
        $oids = [];
        foreach ($oidMap as $v) {
            if (is_string($v) && $v !== '' && preg_match('/^\d/', ltrim($v, '.'))) {
                $oids[] = ltrim($v, '.');
            }
        }

        // Sensors on this device, on the same template, or pointing at any map OID
        $sql = 'SELECT sensor_id, name, sensor_kind, host_type, device_id, snmp_oid, snmp_index,
                       snmp_site_template_id, last_value, unit
                FROM env_sensors
                WHERE is_active = 1 AND (
                    device_id = ?
                    OR (snmp_site_template_id IS NOT NULL AND snmp_site_template_id = ?)
                )';
        $params = [$deviceId, $templateId > 0 ? $templateId : -1];
        try {
            $rows = Database::fetchAll($sql, $params);
        } catch (Throwable $e) {
            return [];
        }

        // Also sensors hosted on env expansion modules that share a cabinet with this device
        // (TH modules) — match by name / snmp_index only
        try {
            $extra = Database::fetchAll(
                "SELECT s.sensor_id, s.name, s.sensor_kind, s.host_type, s.device_id, s.snmp_oid, s.snmp_index,
                        s.snmp_site_template_id, s.last_value, s.unit
                 FROM env_sensors s
                 INNER JOIN devices d ON d.device_id = s.device_id AND d.is_active = 1
                 WHERE s.is_active = 1
                   AND d.device_type = 'env_module'
                   AND s.device_id <> ?
                   AND (
                     s.cabinet_id IN (SELECT cabinet_id FROM devices WHERE device_id = ? AND cabinet_id IS NOT NULL)
                     OR d.cabinet_id IN (SELECT cabinet_id FROM devices WHERE device_id = ? AND cabinet_id IS NOT NULL)
                   )",
                [$deviceId, $deviceId, $deviceId]
            );
            $seen = [];
            foreach ($rows as $r) {
                $seen[(int)$r['sensor_id']] = true;
            }
            foreach ($extra as $r) {
                $id = (int)$r['sensor_id'];
                if (empty($seen[$id])) {
                    $rows[] = $r;
                    $seen[$id] = true;
                }
            }
        } catch (Throwable $e) {
            // optional
        }

        return $rows;
    }

    /**
     * @param array{temperature:array<int,float>,humidity:array<int,float>} $envMetrics
     */
    public static function resolveProbeInstance(array $sensor, array $envMetrics): ?int
    {
        $idx = trim((string)($sensor['snmp_index'] ?? ''));
        if ($idx !== '') {
            if (preg_match('/^(?:temperature|temp|humidity|humid|rh)\.(\d+)$/i', $idx, $m)) {
                return (int)$m[1];
            }
            if (preg_match('/^\d+$/', $idx)) {
                return (int)$idx;
            }
            // TH01:1 style stored as index
            if (preg_match('/:(\d+)\s*$/', $idx, $m)) {
                return (int)$m[1];
            }
        }

        $name = (string)($sensor['name'] ?? '');
        // Temp Sensor MM:1 / MM:1 / main module : 2
        if (preg_match('/\bMM\s*:?\s*(\d+)\b/i', $name, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/\b(?:probe|sensor)\s*#?\s*(\d+)\b/i', $name, $m)) {
            return (int)$m[1];
        }
        // TH01:1 / TH 01:2 — use the port number as instance when it exists in metrics
        if (preg_match('/\bTH\s*0*(\d+)\s*:?\s*(\d+)\b/i', $name, $m)) {
            $port = (int)$m[2];
            if (isset($envMetrics['temperature'][$port]) || isset($envMetrics['humidity'][$port])) {
                return $port;
            }
            // Port-only may not match flat EMS index; try module*10+port heuristic only if that key exists
            $mod = (int)$m[1];
            $guess = $mod * 10 + $port;
            if (isset($envMetrics['temperature'][$guess]) || isset($envMetrics['humidity'][$guess])) {
                return $guess;
            }
            // Fall through: still return port as best effort for small deployments
            return $port;
        }
        // Trailing :N
        if (preg_match('/:(\d+)\s*$/', $name, $m)) {
            return (int)$m[1];
        }

        return null;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(sprintf('%.2F', $n), '0'), '.');
    }
}
