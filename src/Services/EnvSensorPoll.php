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
    /** Serial label e.g. L1 (local/MM) or R3 (remote/TH expansion) — PowerNet */
    private const EMS_SERIAL_OID = '1.3.6.1.4.1.318.1.1.10.3.13.1.1.9';
    /** 1=neverDiscovered 2=established 3=lost */
    private const EMS_COMM_OID = '1.3.6.1.4.1.318.1.1.10.3.13.1.1.10';
    private const MAX_PROBE_INDEX = 32;
    private const MISS_STREAK_STOP = 3;
    private const COMM_ESTABLISHED = 2;

    /**
     * Expand metrics with live EMS probe table (temp/humidity/name/serial/comm).
     * Call while SNMP session is still open.
     *
     * MIB: temperature/humidity are whole-number degrees / %RH (not tenths).
     * Serial is L# (local / MM) or R# (remote / expansion modules).
     *
     * @param array<string,mixed> $session SnmpPoller session
     * @param array<string,array{numeric:?float,raw?:mixed,oid?:string}> $metrics
     * @return array{
     *   metrics:array,
     *   probe_names:array<int,string>,
     *   probe_meta:array<int,array{temp:?float,hum:?float,name:?string,serial:?string,comm:?int,live:bool}>,
     *   expanded:int
     * }
     */
    public static function expandApcEmsProbes(array $session, array $metrics): array
    {
        $probeNames = [];
        /** @var array<int,array{temp:?float,hum:?float,name:?string,serial:?string,comm:?int,live:bool}> $probeMeta */
        $probeMeta = [];
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
            $gotSerial = null;
            $gotComm = null;

            // Always re-GET live temp/humidity for EMS (template may have stale zeros).
            // PowerNet: whole °C/°F — do not apply tenths scaling.
            try {
                $raw = SnmpPoller::sessionGet($session, $tOid);
                $n = SnmpPoller::sessionToNumber($raw);
                if ($n !== null) {
                    $gotTemp = self::normalizeEmsTemperature((float)$n);
                    $metrics[$tKey] = ['numeric' => $gotTemp, 'raw' => $raw, 'oid' => $tOid];
                    $expanded++;
                }
            } catch (Throwable $e) {
                // miss
            }

            try {
                $raw = SnmpPoller::sessionGet($session, $hOid);
                $n = SnmpPoller::sessionToNumber($raw);
                if ($n !== null) {
                    $gotHum = self::normalizeEmsHumidity((float)$n);
                    $metrics[$hKey] = ['numeric' => $gotHum, 'raw' => $raw, 'oid' => $hOid];
                    $expanded++;
                }
            } catch (Throwable $e) {
                // miss
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

            try {
                $raw = SnmpPoller::sessionGet($session, self::EMS_SERIAL_OID . '.' . $i);
                $ser = self::cleanProbeName($raw);
                if ($ser !== '' && preg_match('/^[LR]\d+$/i', $ser)) {
                    $gotSerial = strtoupper($ser);
                    $metrics['probe_serial.' . $i] = [
                        'numeric' => null,
                        'raw' => $raw,
                        'oid' => self::EMS_SERIAL_OID . '.' . $i,
                    ];
                }
            } catch (Throwable $e) {
                // optional
            }

            try {
                $raw = SnmpPoller::sessionGet($session, self::EMS_COMM_OID . '.' . $i);
                $n = SnmpPoller::sessionToNumber($raw);
                if ($n !== null) {
                    $gotComm = (int)$n;
                    $metrics['probe_comm.' . $i] = [
                        'numeric' => (float)$gotComm,
                        'raw' => $raw,
                        'oid' => self::EMS_COMM_OID . '.' . $i,
                    ];
                }
            } catch (Throwable $e) {
                // optional
            }

            // Live if we have a real non-zero reading, or comm explicitly OK with any reading.
            // Do not trust unknown/0 comm enums alone (mis-read columns mark everything dead).
            $live = self::isProbeLive($gotTemp, $gotHum, $gotComm);

            $probeMeta[$i] = [
                'temp' => $gotTemp,
                'hum' => $gotHum,
                'name' => $gotName,
                'serial' => $gotSerial,
                'comm' => $gotComm,
                'live' => $live,
                'source' => 'ems',
            ];

            if ($gotTemp !== null || $gotHum !== null || $gotName !== null || $gotSerial !== null) {
                $anyHit = true;
                $miss = 0;
            } else {
                $miss++;
                if ($anyHit && $miss >= self::MISS_STREAK_STOP) {
                    break;
                }
                if (!$anyHit && $i >= 8 && $miss >= 8) {
                    break;
                }
            }
        }

        $logBits = [];
        foreach ($probeMeta as $i => $pm) {
            if ($pm['name'] === null && $pm['temp'] === null && $pm['serial'] === null) {
                continue;
            }
            $logBits[] = $i . '=' . ($pm['serial'] ?? '?')
                . '/' . ($pm['name'] ?? '?')
                . '@' . ($pm['temp'] !== null ? (string)$pm['temp'] : '—')
                . 'c' . ($pm['comm'] ?? '?');
        }
        if ($logBits) {
            App::log('EnvSensorPoll EMS probes: ' . implode(', ', $logBits), 'info');
        }

        return [
            'metrics' => $metrics,
            'probe_names' => $probeNames,
            'probe_meta' => $probeMeta,
            'expanded' => $expanded,
        ];
    }

    /** PowerNet: whole number degrees in system scale (°C or °F). */
    public static function normalizeEmsTemperature(float $n): float
    {
        // Sentinel / not present
        if ($n < -100 || $n > 200) {
            return $n; // keep but matching treats non-live separately
        }
        return round($n, 1);
    }

    /** PowerNet: whole number %RH. */
    public static function normalizeEmsHumidity(float $n): float
    {
        if ($n > 100 && $n <= 1000) {
            return round($n / 10.0, 1);
        }
        return round(max(0, min(100, $n)), 1);
    }

    public static function isProbeLive(?float $temp, ?float $hum, ?int $comm): bool
    {
        // Explicit lost / never-discovered with zero readings → empty slot
        if ($comm !== null && $comm !== self::COMM_ESTABLISHED
            && ($temp === null || abs($temp) < 0.05)
            && ($hum === null || abs($hum) < 0.05)
        ) {
            return false;
        }
        // Non-zero temp or humidity → live regardless of odd comm codes
        if ($temp !== null && abs($temp) >= 0.05) {
            return true;
        }
        if ($hum !== null && abs($hum) >= 0.05) {
            return true;
        }
        // Comm OK with a reading (including true 0°C)
        if ($comm === self::COMM_ESTABLISHED && ($temp !== null || $hum !== null)) {
            return true;
        }
        return false;
    }

    /**
     * Modular Environmental Manager (MEM) status table — AP9340 MM + TH expansion modules.
     * OID: 1.3.6.1.4.1.318.1.1.10.4.2.3.1  INDEX { moduleNumber, sensorNumber }
     *   .3 name  .4 location  .5 temperature (whole °)  .6 humidity
     *   .7 comm  .9 temperatureHighPrec (tenths of °)
     *
     * Module/sensor map to UI labels: MM:N → module 0 (or 1) sensor N; TH02:3 → module 2 sensor 3.
     *
     * @param array<string,mixed> $session
     * @param array<string,array{numeric:?float,raw?:mixed,oid?:string}> $metrics
     * @param array<int,string> $probeNames
     * @param array<int,array<string,mixed>> $probeMeta
     * @return array{metrics:array,probe_names:array,probe_meta:array,mem_count:int,uio_count:int}
     */
    public static function expandMemSensors(
        array $session,
        array $metrics,
        array $probeNames,
        array $probeMeta
    ): array {
        $entry = '1.3.6.1.4.1.318.1.1.10.4.2.3.1';
        // Prefer high-precision (tenths), fall back to whole degrees
        $tempHi = SnmpPoller::sessionWalk($session, $entry . '.9');
        $tempLo = SnmpPoller::sessionWalk($session, $entry . '.5');
        $humWalk = SnmpPoller::sessionWalk($session, $entry . '.6');
        $nameWalk = SnmpPoller::sessionWalk($session, $entry . '.3');
        $locWalk = SnmpPoller::sessionWalk($session, $entry . '.4');
        $commWalk = SnmpPoller::sessionWalk($session, $entry . '.7');

        $suffixes = [];
        foreach ([$tempHi, $tempLo, $nameWalk, $humWalk] as $walk) {
            foreach (array_keys($walk) as $suf) {
                $suffixes[(string)$suf] = true;
            }
        }
        if (!$suffixes) {
            App::log('EnvSensorPoll MEM: no memSensorsStatus rows (walk empty)', 'info');
            // Still try legacy UIO table
            $uio = self::expandUioSensors($session, $metrics, $probeNames, $probeMeta);
            $uio['mem_count'] = 0;
            return $uio;
        }

        $nextIdx = 200;
        foreach (array_keys($probeMeta) as $i) {
            $nextIdx = max($nextIdx, (int)$i + 1);
        }
        if ($nextIdx < 200) {
            $nextIdx = 200;
        }

        $memCount = 0;
        $seen = [];
        foreach (array_keys($suffixes) as $suffix) {
            $suffix = trim((string)$suffix, '.');
            if (!preg_match('/(\d+)\.(\d+)$/', $suffix, $m)) {
                continue;
            }
            $module = (int)$m[1];
            $sensor = (int)$m[2];
            $key = $module . '.' . $sensor;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $temp = null;
            $rawTemp = null;
            // High precision tenths first
            foreach ([$suffix, $key] as $sk) {
                if (isset($tempHi[$sk])) {
                    $rawTemp = $tempHi[$sk];
                    $n = SnmpPoller::sessionToNumber($rawTemp);
                    if ($n !== null && $n > -500 && $n < 2000) {
                        $temp = round(((float)$n) / 10.0, 1);
                        break;
                    }
                }
            }
            if ($temp === null) {
                foreach ([$suffix, $key] as $sk) {
                    if (isset($tempLo[$sk])) {
                        $rawTemp = $tempLo[$sk];
                        $n = SnmpPoller::sessionToNumber($rawTemp);
                        if ($n !== null && $n > -100 && $n < 200) {
                            $temp = self::normalizeEmsTemperature((float)$n);
                            break;
                        }
                    }
                }
            }

            $hum = null;
            foreach ([$suffix, $key] as $sk) {
                if (isset($humWalk[$sk])) {
                    $hn = SnmpPoller::sessionToNumber($humWalk[$sk]);
                    if ($hn !== null && $hn > -1 && $hn <= 1000) {
                        $hum = self::normalizeEmsHumidity((float)$hn);
                        break;
                    }
                }
            }

            $name = null;
            foreach ([$suffix, $key] as $sk) {
                if (isset($nameWalk[$sk])) {
                    $label = self::cleanProbeName($nameWalk[$sk]);
                    if ($label !== '' && self::isPlausibleProbeName($label)) {
                        $name = $label;
                        break;
                    }
                }
            }
            if ($name === null) {
                // Synthesize from module/sensor — module 0/1 often manager
                if ($module <= 1 && $sensor >= 1) {
                    $name = sprintf('Temp Sensor MM:%d', $sensor);
                } else {
                    $name = sprintf('Temp Sensor TH%02d:%d', $module, $sensor);
                }
            }

            $location = null;
            foreach ([$suffix, $key] as $sk) {
                if (isset($locWalk[$sk])) {
                    $location = self::cleanProbeName($locWalk[$sk]);
                    if ($location !== '') {
                        break;
                    }
                }
            }

            $comm = null;
            foreach ([$suffix, $key] as $sk) {
                if (isset($commWalk[$sk])) {
                    $cn = SnmpPoller::sessionToNumber($commWalk[$sk]);
                    if ($cn !== null) {
                        $comm = (int)$cn;
                        break;
                    }
                }
            }

            // Skip not-installed empty sockets
            if ($comm === 1 && ($temp === null || abs($temp) < 0.05)
                && ($hum === null || abs($hum) < 0.05)
            ) {
                continue;
            }

            $live = self::isProbeLive($temp, $hum, $comm);
            if (!$live && $temp !== null && abs($temp) >= 0.05) {
                $live = true;
            }
            // Installed but no reading yet — still register for name matching
            if (!$live && $name !== null && $comm === self::COMM_ESTABLISHED) {
                $live = ($temp !== null || $hum !== null);
            }

            $idx = $nextIdx++;
            $serial = 'M' . $module . 'S' . $sensor;
            if ($temp !== null) {
                $metrics['temperature.' . $idx] = [
                    'numeric' => $temp,
                    'raw' => $rawTemp,
                    'oid' => $entry . '.5.' . $module . '.' . $sensor,
                ];
            }
            if ($hum !== null) {
                $metrics['humidity.' . $idx] = [
                    'numeric' => $hum,
                    'raw' => $hum,
                    'oid' => $entry . '.6.' . $module . '.' . $sensor,
                ];
            }
            $probeNames[$idx] = $name;
            $probeMeta[$idx] = [
                'temp' => $temp,
                'hum' => $hum,
                'name' => $name,
                'serial' => $serial,
                'comm' => $comm,
                'live' => $live,
                'source' => 'mem',
                'mem_module' => $module,
                'mem_sensor' => $sensor,
                'location' => $location,
            ];
            $memCount++;
        }

        App::log('EnvSensorPoll MEM sensors: ' . $memCount . ' row(s)', 'info');

        // Optional UIO add-on
        $uio = self::expandUioSensors($session, $metrics, $probeNames, $probeMeta);
        $uio['mem_count'] = $memCount;
        return $uio;
    }

    /**
     * Universal I/O sensor status table (legacy / some SKUs).
     * OID: 1.3.6.1.4.1.318.1.1.25.1.2.1 (INDEX portID.sensorID)
     *
     * @param array<string,mixed> $session
     * @param array<string,array{numeric:?float,raw?:mixed,oid?:string}> $metrics
     * @param array<int,string> $probeNames
     * @param array<int,array<string,mixed>> $probeMeta
     * @return array{metrics:array,probe_names:array,probe_meta:array,uio_count:int,mem_count?:int}
     */
    public static function expandUioSensors(
        array $session,
        array $metrics,
        array $probeNames,
        array $probeMeta
    ): array {
        $entry = '1.3.6.1.4.1.318.1.1.25.1.2.1';
        $tempC = SnmpPoller::sessionWalk($session, $entry . '.6');
        if (!$tempC) {
            $tempF = SnmpPoller::sessionWalk($session, $entry . '.5');
            if (!$tempF) {
                return [
                    'metrics' => $metrics,
                    'probe_names' => $probeNames,
                    'probe_meta' => $probeMeta,
                    'uio_count' => 0,
                ];
            }
            $tempC = [];
            foreach ($tempF as $suf => $raw) {
                $n = SnmpPoller::sessionToNumber($raw);
                if ($n === null || $n < -50) {
                    continue;
                }
                if ($n <= -1 && $n > -2) {
                    continue;
                }
                $tempC[$suf] = round(((float)$n - 32.0) * 5.0 / 9.0, 1);
            }
        }

        $humWalk = SnmpPoller::sessionWalk($session, $entry . '.7');
        $nameWalk = SnmpPoller::sessionWalk($session, $entry . '.3');
        $commWalk = SnmpPoller::sessionWalk($session, $entry . '.10');

        $nextIdx = 100;
        foreach (array_keys($probeMeta) as $i) {
            $nextIdx = max($nextIdx, (int)$i + 1);
        }
        if ($nextIdx < 100) {
            $nextIdx = 100;
        }

        $uioCount = 0;
        $seen = [];
        foreach ($tempC as $suffix => $rawOrNum) {
            $suffix = trim((string)$suffix, '.');
            if (!preg_match('/(\d+)\.(\d+)$/', $suffix, $m)) {
                continue;
            }
            $port = (int)$m[1];
            $sid = (int)$m[2];
            $key = $port . '.' . $sid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (is_float($rawOrNum) || is_int($rawOrNum)) {
                $temp = self::normalizeEmsTemperature((float)$rawOrNum);
            } else {
                $n = SnmpPoller::sessionToNumber($rawOrNum);
                if ($n === null || $n <= -1) {
                    continue;
                }
                $temp = self::normalizeEmsTemperature((float)$n);
            }

            $hum = null;
            foreach ([$suffix, $key] as $hk) {
                if (isset($humWalk[$hk])) {
                    $hn = SnmpPoller::sessionToNumber($humWalk[$hk]);
                    if ($hn !== null && $hn > -1) {
                        $hum = self::normalizeEmsHumidity((float)$hn);
                        break;
                    }
                }
            }

            $name = null;
            foreach ([$suffix, $key] as $nk) {
                if (isset($nameWalk[$nk])) {
                    $label = self::cleanProbeName($nameWalk[$nk]);
                    if ($label !== '' && self::isPlausibleProbeName($label)) {
                        $name = $label;
                        break;
                    }
                }
            }
            if ($name === null) {
                $name = sprintf('UIO Port %d Sensor %d', $port, $sid);
            }

            $comm = null;
            foreach ([$suffix, $key] as $ck) {
                if (isset($commWalk[$ck])) {
                    $cn = SnmpPoller::sessionToNumber($commWalk[$ck]);
                    if ($cn !== null) {
                        $comm = (int)$cn;
                        break;
                    }
                }
            }

            $live = self::isProbeLive($temp, $hum, $comm);
            if (!$live && $temp !== null && abs($temp) >= 0.05) {
                $live = true;
            }

            $idx = $nextIdx++;
            $serial = 'U' . $port . 'S' . $sid;
            $metrics['temperature.' . $idx] = [
                'numeric' => $temp,
                'raw' => $rawOrNum,
                'oid' => $entry . '.6.' . $port . '.' . $sid,
            ];
            if ($hum !== null) {
                $metrics['humidity.' . $idx] = [
                    'numeric' => $hum,
                    'raw' => $hum,
                    'oid' => $entry . '.7.' . $port . '.' . $sid,
                ];
            }
            $probeNames[$idx] = $name;
            $probeMeta[$idx] = [
                'temp' => $temp,
                'hum' => $hum,
                'name' => $name,
                'serial' => $serial,
                'comm' => $comm,
                'live' => $live,
                'source' => 'uio',
                'uio_port' => $port,
                'uio_sensor' => $sid,
            ];
            $uioCount++;
        }

        if ($uioCount > 0) {
            App::log('EnvSensorPoll UIO sensors: ' . $uioCount . ' row(s)', 'info');
        }

        return [
            'metrics' => $metrics,
            'probe_names' => $probeNames,
            'probe_meta' => $probeMeta,
            'uio_count' => $uioCount,
        ];
    }

    /**
     * After a successful device poll, write matching env metrics to sensors + history.
     *
     * @param array<string,array{numeric:?float,raw?:mixed,oid?:string}> $metrics lowercase keys
     * @param array<string,mixed> $oidMap original template map
     * @param array<int,string> $probeNames SNMP probe labels by table index
     * @param array<int,array{temp:?float,hum:?float,name:?string,serial:?string,comm:?int,live:bool}> $probeMeta
     * @return array{updated:int,readings:int,unmatched:int,keys:int,matched:list<string>,candidates:int,probes:int,skipped_dead:int,snapshot:list<string>}
     */
    public static function applyFromDevicePoll(
        int $deviceId,
        int $templateId,
        array $metrics,
        array $oidMap,
        array $probeNames = [],
        array $probeMeta = []
    ): array {
        // Build names / meta from metrics if expand stored them
        if (!$probeNames) {
            foreach ($metrics as $k => $meta) {
                if (preg_match('/^probe_name\.(\d+)$/', (string)$k, $m)) {
                    $label = self::cleanProbeName($meta['raw'] ?? null);
                    if ($label !== '' && self::isPlausibleProbeName($label)) {
                        $probeNames[(int)$m[1]] = $label;
                    }
                }
            }
        }
        if (!$probeMeta) {
            $probeMeta = self::probeMetaFromMetrics($metrics, $probeNames);
        }

        $envMetrics = self::extractEnvMetrics($metrics);
        // Overlay only live probes into a "good" temp/hum map for fallback
        $liveEnv = ['temperature' => [], 'humidity' => []];
        foreach ($probeMeta as $i => $pm) {
            if (empty($pm['live'])) {
                continue;
            }
            if ($pm['temp'] !== null) {
                $liveEnv['temperature'][$i] = $pm['temp'];
            }
            if ($pm['hum'] !== null) {
                $liveEnv['humidity'][$i] = $pm['hum'];
            }
        }
        if (!$liveEnv['temperature'] && !$liveEnv['humidity']) {
            // Fall back to all extracted if comm OIDs missing
            $liveEnv = $envMetrics;
        }

        if (!$envMetrics['temperature'] && !$envMetrics['humidity']) {
            return [
                'updated' => 0,
                'readings' => 0,
                'unmatched' => 0,
                'keys' => 0,
                'matched' => [],
                'candidates' => 0,
                'probes' => 0,
                'skipped_dead' => 0,
                'snapshot' => [],
            ];
        }

        $sensors = self::loadCandidateSensors($deviceId, $templateId);
        $keyCount = count($envMetrics['temperature']) + count($envMetrics['humidity']);
        $probeCount = max(
            count($envMetrics['temperature']),
            count($envMetrics['humidity']),
            count($probeNames),
            count($probeMeta)
        );

        $snapshot = [];
        // Prefer showing live MEM/UIO first in toast
        $snapOrder = $probeMeta;
        uasort($snapOrder, static function (array $a, array $b): int {
            $la = !empty($a['live']) ? 0 : 1;
            $lb = !empty($b['live']) ? 0 : 1;
            if ($la !== $lb) {
                return $la <=> $lb;
            }
            $rank = static function (array $p): int {
                return match ($p['source'] ?? 'ems') {
                    'mem' => 0,
                    'uio' => 1,
                    default => 2,
                };
            };
            return $rank($a) <=> $rank($b);
        });
        foreach ($snapOrder as $i => $pm) {
            if ($pm['serial'] === null && $pm['temp'] === null && $pm['name'] === null) {
                continue;
            }
            $src = match ($pm['source'] ?? 'ems') {
                'mem' => 'MEM',
                'uio' => 'UIO',
                default => 'EMS',
            };
            $label = $pm['name'] ?? ($pm['serial'] ?? '?');
            $snapshot[] = $src . ':' . $label
                . '=' . ($pm['temp'] !== null ? self::fmt((float)$pm['temp']) : '—')
                . '°'
                . (!empty($pm['live']) ? '' : '(dead)');
            if (count($snapshot) >= 16) {
                break;
            }
        }

        if (!$sensors) {
            return [
                'updated' => 0,
                'readings' => 0,
                'unmatched' => $keyCount,
                'keys' => $keyCount,
                'matched' => [],
                'candidates' => 0,
                'probes' => $probeCount,
                'skipped_dead' => 0,
                'snapshot' => $snapshot,
            ];
        }

        $now = date('Y-m-d H:i:s');
        $updated = 0;
        $readings = 0;
        $skippedDead = 0;
        $matchedLabels = [];
        $usedTemp = [];
        $usedHum = [];

        // L/R + name + order (prefer live remotes for TH)
        $orderMap = self::buildOrderFallbackMap($sensors, $liveEnv, $probeNames, $probeMeta);

        foreach ($sensors as $sensor) {
            $sid = (int)$sensor['sensor_id'];
            $kind = strtolower((string)($sensor['sensor_kind'] ?? 'temperature'));
            $inst = self::matchSensorToProbeIndex($sensor, $liveEnv, $probeNames, $probeMeta);
            if ($inst === null && isset($orderMap[$sid])) {
                $inst = $orderMap[$sid];
            }
            $tempVal = null;
            $humVal = null;
            $mapKeyTemp = null;
            $mapKeyHum = null;

            if ($inst !== null && isset($probeMeta[$inst]) && empty($probeMeta[$inst]['live'])) {
                // Don't pin a sensor to a dead/empty slot
                $skippedDead++;
                $inst = null;
                if (isset($orderMap[$sid])) {
                    $alt = $orderMap[$sid];
                    if (!isset($probeMeta[$alt]) || !empty($probeMeta[$alt]['live'])) {
                        $inst = $alt;
                    }
                }
            }

            if ($inst !== null) {
                if (isset($probeMeta[$inst]['temp']) && $probeMeta[$inst]['temp'] !== null) {
                    $tempVal = $probeMeta[$inst]['temp'];
                    $mapKeyTemp = 'temperature.' . $inst;
                } elseif (isset($envMetrics['temperature'][$inst])) {
                    $tempVal = $envMetrics['temperature'][$inst];
                    $mapKeyTemp = 'temperature.' . $inst;
                }
                if (isset($probeMeta[$inst]['hum']) && $probeMeta[$inst]['hum'] !== null) {
                    $humVal = $probeMeta[$inst]['hum'];
                    $mapKeyHum = 'humidity.' . $inst;
                } elseif (isset($envMetrics['humidity'][$inst])) {
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
            // Persist L#/R# serial when known, else table index
            if ($inst !== null) {
                $ser = isset($probeMeta[$inst]['serial']) ? (string)$probeMeta[$inst]['serial'] : '';
                $fields['snmp_index'] = $ser !== '' ? $ser : (string)$inst;
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

            // Skip writing obvious dead zeros when probe is not live
            if ($inst !== null && isset($probeMeta[$inst]) && empty($probeMeta[$inst]['live'])) {
                $skippedDead++;
                continue;
            }
            if ($primary !== null && abs($primary) < 0.05 && $humVal !== null && abs($humVal) < 0.05
                && $inst !== null && isset($probeMeta[$inst]['comm'])
                && (int)$probeMeta[$inst]['comm'] !== self::COMM_ESTABLISHED
            ) {
                $skippedDead++;
                continue;
            }

            $updated++;
            $label = (string)($sensor['name'] ?? ('#' . $sid));
            if ($primary !== null) {
                $ser = ($inst !== null && isset($probeMeta[$inst]['serial']))
                    ? (string)$probeMeta[$inst]['serial']
                    : '';
                $matchedLabels[] = $label . '[' . ($ser !== '' ? $ser : ('#' . ($inst ?? '?'))) . ']='
                    . self::fmt($primary)
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
            . ' skipped_dead=' . $skippedDead
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
            'skipped_dead' => $skippedDead,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param array<string,array{numeric:?float,raw?:mixed,oid?:string}> $metrics
     * @param array<int,string> $probeNames
     * @return array<int,array{temp:?float,hum:?float,name:?string,serial:?string,comm:?int,live:bool}>
     */
    public static function probeMetaFromMetrics(array $metrics, array $probeNames): array
    {
        $meta = [];
        $env = self::extractEnvMetrics($metrics);
        $indexes = array_unique(array_merge(
            array_keys($env['temperature']),
            array_keys($env['humidity']),
            array_keys($probeNames)
        ));
        foreach ($indexes as $i) {
            $i = (int)$i;
            $ser = null;
            if (isset($metrics['probe_serial.' . $i]['raw'])) {
                $s = self::cleanProbeName($metrics['probe_serial.' . $i]['raw']);
                if (preg_match('/^[LR]\d+$/i', $s)) {
                    $ser = strtoupper($s);
                }
            }
            $comm = isset($metrics['probe_comm.' . $i]['numeric'])
                ? (int)$metrics['probe_comm.' . $i]['numeric']
                : null;
            $temp = $env['temperature'][$i] ?? null;
            $hum = $env['humidity'][$i] ?? null;
            $live = ($comm === null || $comm === self::COMM_ESTABLISHED)
                && ($temp !== null || $hum !== null);
            if ($comm !== null && $comm !== self::COMM_ESTABLISHED) {
                $live = false;
            }
            $meta[$i] = [
                'temp' => $temp,
                'hum' => $hum,
                'name' => $probeNames[$i] ?? null,
                'serial' => $ser,
                'comm' => $comm,
                'live' => $live,
            ];
        }
        return $meta;
    }

    /**
     * Resolve which EMS table index a sensor row maps to.
     * Prefer L#/R# serial (local MM vs remote TH), then probe names.
     *
     * @param array{temperature:array<int,float>,humidity:array<int,float>} $envMetrics
     * @param array<int,string> $probeNames
     * @param array<int,array{temp:?float,hum:?float,name:?string,serial:?string,comm:?int,live:bool}> $probeMeta
     */
    public static function matchSensorToProbeIndex(
        array $sensor,
        array $envMetrics,
        array $probeNames,
        array $probeMeta = []
    ): ?int {
        $idx = trim((string)($sensor['snmp_index'] ?? ''));
        // Trust stored key only if that slot is live (avoid sticky 0°C empty slots from prior polls)
        if ($idx !== '') {
            if (preg_match('/^(?:temperature|temp|humidity|humid|rh)\.(\d+)$/i', $idx, $m)) {
                $n = (int)$m[1];
                if (!$probeMeta || !isset($probeMeta[$n]) || !empty($probeMeta[$n]['live'])) {
                    return $n;
                }
                // else re-resolve below
            } elseif (preg_match('/^[LR]\d+$/i', $idx)) {
                $want = strtoupper($idx);
                foreach ($probeMeta as $i => $pm) {
                    if (isset($pm['serial']) && strtoupper((string)$pm['serial']) === $want) {
                        return (int)$i;
                    }
                }
            } elseif (preg_match('/^\d+$/', $idx)) {
                $n = (int)$idx;
                if (!$probeMeta || !isset($probeMeta[$n]) || !empty($probeMeta[$n]['live'])) {
                    if (isset($envMetrics['temperature'][$n]) || isset($envMetrics['humidity'][$n])
                        || isset($probeNames[$n]) || isset($probeMeta[$n])
                    ) {
                        return $n;
                    }
                }
            }
        }

        $sensorName = (string)($sensor['name'] ?? '');

        // 1) APC serial L# / R# / U#S# — MM:N → L{N} or L{N-1} (0-based agents)
        if ($probeMeta) {
            if (preg_match('/\bMM\s*:?\s*(\d+)\b/i', $sensorName, $m)) {
                $n = (int)$m[1];
                foreach (['L' . $n, 'L' . max(0, $n - 1)] as $want) {
                    foreach ($probeMeta as $i => $pm) {
                        if (isset($pm['serial']) && strtoupper((string)$pm['serial']) === $want
                            && !empty($pm['live'])
                        ) {
                            return (int)$i;
                        }
                    }
                }
            }
            // TH01:1 / TH02:3 → MEM module.sensor (primary for AP9340 expansions)
            if (preg_match('/\bTH\s*0*(\d+)\s*:?\s*(\d+)\b/i', $sensorName, $m)) {
                $mod = (int)$m[1];
                $port = (int)$m[2];
                foreach ($probeMeta as $i => $pm) {
                    if (($pm['source'] ?? '') !== 'mem') {
                        continue;
                    }
                    $mm = (int)($pm['mem_module'] ?? -1);
                    $ms = (int)($pm['mem_sensor'] ?? -1);
                    // Module number often matches TH index (1,2,3); allow 0-based module too
                    if ($ms === $port && ($mm === $mod || $mm === $mod - 1 || $mm === $mod + 1)
                        && !empty($pm['live'])
                    ) {
                        return (int)$i;
                    }
                }
                foreach ($probeMeta as $i => $pm) {
                    if (($pm['source'] ?? '') !== 'mem' || empty($pm['live'])) {
                        continue;
                    }
                    $mm = (int)($pm['mem_module'] ?? -1);
                    $ms = (int)($pm['mem_sensor'] ?? -1);
                    if ($mm === $mod && $ms === $port) {
                        return (int)$i;
                    }
                }
                // UIO fallback
                foreach ($probeMeta as $i => $pm) {
                    if (($pm['source'] ?? '') !== 'uio' || empty($pm['live'])) {
                        continue;
                    }
                    $up = (int)($pm['uio_port'] ?? 0);
                    $us = (int)($pm['uio_sensor'] ?? 0);
                    if ($up === $mod && $us === $port) {
                        return (int)$i;
                    }
                }
                // EMS remote R# (last resort)
                foreach (['R' . $port, 'R' . max(0, $port - 1)] as $wantR) {
                    foreach ($probeMeta as $i => $pm) {
                        if (isset($pm['serial']) && strtoupper((string)$pm['serial']) === $wantR
                            && !empty($pm['live'])
                        ) {
                            return (int)$i;
                        }
                    }
                }
            }
            // MM:N via MEM manager module (0 or 1)
            if (preg_match('/\bMM\s*:?\s*(\d+)\b/i', $sensorName, $m)) {
                $port = (int)$m[1];
                foreach ($probeMeta as $i => $pm) {
                    if (($pm['source'] ?? '') !== 'mem' || empty($pm['live'])) {
                        continue;
                    }
                    $mm = (int)($pm['mem_module'] ?? -99);
                    $ms = (int)($pm['mem_sensor'] ?? -99);
                    if ($ms === $port && ($mm === 0 || $mm === 1)) {
                        return (int)$i;
                    }
                }
            }
        }

        $sensorNorm = self::normalizeLabel($sensorName);

        // 2) Exact / strong name match — prefer MEM/live over empty EMS placeholders
        if ($probeNames && $sensorNorm !== '') {
            $sensorIsTh = (bool)preg_match('/\bth\d+\b/', $sensorNorm);
            $sensorIsMm = (bool)preg_match('/\bmm\b/', $sensorNorm);
            $best = null;
            $bestScore = 0;
            foreach ($probeNames as $i => $pname) {
                $pnorm = self::normalizeLabel($pname);
                if ($pnorm === '') {
                    continue;
                }
                $pm = $probeMeta[$i] ?? [];
                $probeIsTh = (bool)preg_match('/\bth\d+\b/', $pnorm);
                $probeIsMm = (bool)preg_match('/\bmm\b/', $pnorm);
                if ($sensorIsTh && $probeIsMm && !$probeIsTh) {
                    continue;
                }
                if ($sensorIsMm && $probeIsTh && !$probeIsMm) {
                    continue;
                }
                $score = 0;
                if ($pnorm === $sensorNorm) {
                    $score = 100;
                } elseif (str_contains($sensorNorm, $pnorm) || str_contains($pnorm, $sensorNorm)) {
                    $score = 80;
                } else {
                    $st = self::labelTokens($sensorNorm);
                    $pt = self::labelTokens($pnorm);
                    $overlap = count(array_intersect($st, $pt));
                    if ($overlap >= 2) {
                        $score = 40 + ($overlap * 10);
                    }
                }
                if (preg_match('/\b(th\d+|mm)\b/', $sensorNorm, $sm)
                    && preg_match('/\b' . preg_quote($sm[1], '/') . '\b/', $pnorm)
                ) {
                    $score += 30;
                }
                // Prefer live modular rows over dead EMS R#/L# placeholders with same name
                if (!empty($pm['live'])) {
                    $score += 25;
                } else {
                    $score -= 40;
                }
                if (($pm['source'] ?? '') === 'mem') {
                    $score += 20;
                } elseif (($pm['source'] ?? '') === 'uio') {
                    $score += 10;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = (int)$i;
                }
            }
            if ($best !== null && $bestScore >= 50) {
                return $best;
            }
        }

        // 3) Structured TH/MM codes in probe names
        if ($probeNames) {
            if (preg_match('/\bTH\s*0*(\d+)\s*:?\s*(\d+)\b/i', $sensorName, $m)) {
                $mod = (int)$m[1];
                $port = (int)$m[2];
                $patterns = [
                    sprintf('th%02d:%d', $mod, $port),
                    sprintf('th%d:%d', $mod, $port),
                    sprintf('th%02d %d', $mod, $port),
                ];
                foreach ($probeNames as $i => $pname) {
                    $p = self::normalizeLabel($pname);
                    foreach ($patterns as $pat) {
                        if (str_contains($p, $pat) || $p === $pat) {
                            return (int)$i;
                        }
                    }
                }
            }
            if (preg_match('/\bMM\s*:?\s*(\d+)\b/i', $sensorName, $m)) {
                $port = (int)$m[1];
                foreach ($probeNames as $i => $pname) {
                    $p = self::normalizeLabel($pname);
                    if (str_contains($p, 'mm:' . $port) || preg_match('/\bmm\b.*\b' . $port . '\b/', $p)) {
                        return (int)$i;
                    }
                }
            }
        }

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
     * Map remaining sensors using APC L# (local/MM) vs R# (remote/TH) serials, then order.
     *
     * @param list<array<string,mixed>> $sensors
     * @param array{temperature:array<int,float>,humidity:array<int,float>} $envMetrics
     * @param array<int,string> $probeNames
     * @param array<int,array{temp:?float,hum:?float,name:?string,serial:?string,comm:?int,live:bool}> $probeMeta
     * @return array<int,int> sensor_id => probe index
     */
    public static function buildOrderFallbackMap(
        array $sensors,
        array $envMetrics,
        array $probeNames,
        array $probeMeta = []
    ): array {
        $pendingMm = [];
        $pendingTh = [];
        $claimed = [];
        foreach ($sensors as $s) {
            $sid = (int)($s['sensor_id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            $hit = self::matchSensorToProbeIndex($s, $envMetrics, $probeNames, $probeMeta);
            if ($hit !== null) {
                $claimed[$hit] = true;
                continue;
            }
            $name = (string)($s['name'] ?? '');
            if (preg_match('/\bTH\s*0*\d+/i', $name)) {
                $pendingTh[] = $s;
            } else {
                $pendingMm[] = $s;
            }
        }
        if (!$pendingMm && !$pendingTh) {
            return [];
        }

        // Partition free probes: local (L#/MM) vs remote (R#) vs other live
        $freeLocal = [];
        $freeRemote = [];
        $freeOther = [];
        $indexes = array_keys($envMetrics['temperature'] + $envMetrics['humidity']);
        if ($probeMeta) {
            $indexes = array_unique(array_merge($indexes, array_keys($probeMeta)));
        }
        sort($indexes, SORT_NUMERIC);
        foreach ($indexes as $ix) {
            $ix = (int)$ix;
            if (!empty($claimed[$ix])) {
                continue;
            }
            $pm = $probeMeta[$ix] ?? null;
            if ($pm !== null && empty($pm['live'])) {
                continue; // skip dead / no-comms empty slots (often 0°C)
            }
            // Prefer non-zero temps when choosing free slots
            $ser = strtoupper((string)($pm['serial'] ?? ''));
            $src = (string)($pm['source'] ?? 'ems');
            if ($src === 'mem' || $src === 'uio' || preg_match('/^M\d+S\d+$/', $ser)
                || preg_match('/^U\d+S\d+$/', $ser)
            ) {
                // MEM manager module (0/1) → local pool; higher modules → expansion
                if ($src === 'mem' && (int)($pm['mem_module'] ?? 99) <= 1) {
                    $freeLocal[] = $ix;
                } else {
                    $freeRemote[] = $ix;
                }
            } elseif (preg_match('/^L\d+$/', $ser) || (
                isset($probeNames[$ix]) && preg_match('/\bmm\b/i', $probeNames[$ix])
                && !preg_match('/\bth\d+/i', $probeNames[$ix])
            )) {
                $freeLocal[] = $ix;
            } elseif (preg_match('/^R\d+$/', $ser) || (
                isset($probeNames[$ix]) && preg_match('/\bth\d+/i', $probeNames[$ix])
            )) {
                // Only use live R# with non-zero temp — empty R slots stay out (already filtered)
                $freeRemote[] = $ix;
            } else {
                $freeOther[] = $ix;
            }
        }
        // Sort remotes by R number when known
        usort($freeRemote, static function (int $a, int $b) use ($probeMeta): int {
            $sa = strtoupper((string)($probeMeta[$a]['serial'] ?? ''));
            $sb = strtoupper((string)($probeMeta[$b]['serial'] ?? ''));
            $na = preg_match('/^R(\d+)$/', $sa, $m) ? (int)$m[1] : $a;
            $nb = preg_match('/^R(\d+)$/', $sb, $m) ? (int)$m[1] : $b;
            return $na <=> $nb;
        });
        usort($freeLocal, static function (int $a, int $b) use ($probeMeta): int {
            $sa = strtoupper((string)($probeMeta[$a]['serial'] ?? ''));
            $sb = strtoupper((string)($probeMeta[$b]['serial'] ?? ''));
            $na = preg_match('/^L(\d+)$/', $sa, $m) ? (int)$m[1] : $a;
            $nb = preg_match('/^L(\d+)$/', $sb, $m) ? (int)$m[1] : $b;
            return $na <=> $nb;
        });

        usort($pendingMm, static fn($a, $b) => self::sensorSortKey($a) <=> self::sensorSortKey($b));
        usort($pendingTh, static fn($a, $b) => self::sensorSortKey($a) <=> self::sensorSortKey($b));

        $map = [];
        $assign = static function (array $pending, array &$pool) use (&$map): void {
            foreach ($pending as $s) {
                if (!$pool) {
                    return;
                }
                $ix = array_shift($pool);
                $map[(int)$s['sensor_id']] = $ix;
            }
        };
        $assign($pendingMm, $freeLocal);
        // leftover MM sensors can take other free
        $mmRest = array_values(array_filter(
            $pendingMm,
            static fn($s) => !isset($map[(int)$s['sensor_id']])
        ));
        $assign($mmRest, $freeOther);

        $assign($pendingTh, $freeRemote);
        $thRest = array_values(array_filter(
            $pendingTh,
            static fn($s) => !isset($map[(int)$s['sensor_id']])
        ));
        // TH leftovers: remaining remotes already empty — try other live free (not preferred locals)
        $assign($thRest, $freeOther);
        $thRest2 = array_values(array_filter(
            $pendingTh,
            static fn($s) => !isset($map[(int)$s['sensor_id']])
        ));
        $assign($thRest2, $freeLocal);

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
