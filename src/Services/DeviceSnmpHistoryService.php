<?php
/**
 * Device SNMP live values + optional history (CPU %, memory %, custom gauges).
 *
 * Site OID templates already store {metric → OID}. This service:
 *   - classifies keys (text / uptime / chartable gauge)
 *   - writes device_snmp_readings on poll
 *   - serves 24h series for the device page
 */
declare(strict_types=1);

class DeviceSnmpHistoryService
{
    public const RETENTION_DAYS = 90;

    /** Keys that are never charted (identity / display-only). */
    public static function isTextKey(string $key): bool
    {
        $k = strtolower(trim($key));
        if ($k === '' || str_starts_with($k, '_')) {
            return true;
        }
        return (bool)preg_match(
            '/^(sysdescr|sysname|syscontact|syslocation|serial|serial_no|model|system_model|'
            . 'service_tag|firmware|software|descr|name)$/',
            $k
        ) || (bool)preg_match('/(descr|name|serial|firmware|software|model)$/', $k);
    }

    public static function isUptimeKey(string $key): bool
    {
        $k = strtolower(trim($key));
        return (bool)preg_match('/^(sys)?uptime$/', $k) || str_contains($k, 'uptime');
    }

    /**
     * Default: chart numeric gauges (cpu/mem %, watts, temps). Honor _charts in oid_map.
     *
     * @param array<string,mixed> $oidMap
     */
    public static function shouldChart(string $key, array $oidMap = []): bool
    {
        $k = strtolower(trim($key));
        if ($k === '' || str_starts_with($k, '_') || self::isTextKey($k) || self::isUptimeKey($k)) {
            return false;
        }
        if (array_key_exists('_charts', $oidMap) && is_array($oidMap['_charts'])) {
            foreach ($oidMap['_charts'] as $c) {
                if (strtolower((string)$c) === $k) {
                    return true;
                }
            }
            return false;
        }
        return (bool)preg_match(
            '/(^|_)(cpu|mem|memory|load).*(pct|percent)?$|pct$|percent$|'
            . '^(watts|amps|volts)|temperature|humidity|temp_|humid_/',
            $k
        );
    }

    public static function labelFor(string $key): string
    {
        $k = strtolower(trim($key));
        $known = [
            'cpu_pct' => 'CPU',
            'mem_pct' => 'Memory',
            'sysdescr' => 'Software / sysDescr',
            'sysuptime' => 'Uptime',
            'uptime' => 'Uptime',
            'watts' => 'Power',
            'amps' => 'Current',
        ];
        if (isset($known[$k])) {
            return $known[$k];
        }
        $t = str_replace(['_', '.'], ' ', $key);
        return ucwords($t);
    }

    public static function unitFor(string $key): string
    {
        $k = strtolower(trim($key));
        if (str_contains($k, 'pct') || str_contains($k, 'percent') || preg_match('/cpu|mem|memory|load/', $k)) {
            return '%';
        }
        if (str_contains($k, 'watt')) {
            return 'W';
        }
        if (str_contains($k, 'amp')) {
            return 'A';
        }
        if (str_contains($k, 'volt')) {
            return 'V';
        }
        if (preg_match('/temp/', $k)) {
            return '°C';
        }
        if (preg_match('/humid/', $k)) {
            return '%';
        }
        return '';
    }

    /**
     * @param mixed $raw
     */
    public static function formatDisplay(string $key, $raw, ?float $num): string
    {
        if (self::isUptimeKey($key) && $num !== null && $num >= 0) {
            return self::formatTimeticks($num);
        }
        if ($num !== null && !self::isTextKey($key)) {
            $u = self::unitFor($key);
            if ($u === '%') {
                return round($num) . ' %';
            }
            if ($u === '°C') {
                return rtrim(rtrim(sprintf('%.1F', $num), '0'), '.') . ' °C';
            }
            if ($u !== '') {
                return rtrim(rtrim(sprintf('%.2F', $num), '0'), '.') . ' ' . $u;
            }
            return rtrim(rtrim(sprintf('%.4F', $num), '0'), '.');
        }
        $s = self::rawToString($raw);
        if (strlen($s) > 180) {
            $s = substr($s, 0, 177) . '…';
        }
        return $s !== '' ? $s : '—';
    }

    public static function formatTimeticks(float $ticks): string
    {
        // MIB-II TimeTicks are hundredths of a second
        $sec = (int)floor($ticks / 100);
        if ($sec < 0) {
            $sec = 0;
        }
        $d = intdiv($sec, 86400);
        $h = intdiv($sec % 86400, 3600);
        $m = intdiv($sec % 3600, 60);
        if ($d > 0) {
            return $d . 'd ' . $h . 'h ' . $m . 'm';
        }
        if ($h > 0) {
            return $h . 'h ' . $m . 'm';
        }
        return $m . 'm';
    }

    /**
     * @param mixed $raw
     */
    public static function rawToString($raw): string
    {
        if ($raw === null) {
            return '';
        }
        if (is_array($raw)) {
            if (isset($raw['raw'])) {
                return self::rawToString($raw['raw']);
            }
            return '';
        }
        $s = trim((string)$raw);
        $s = preg_replace('/^(STRING|OCTET STRING|Hex-STRING|Gauge32|INTEGER|Timeticks|Counter(?:32|64))\s*:\s*/i', '', $s) ?? $s;
        $s = trim($s, " \t\"'");
        if (preg_match('/^\(\s*\d+\s*\)\s*(.*)$/', $s, $m)) {
            $s = trim((string)$m[1]);
        }
        return $s;
    }

    /**
     * @param mixed $sample
     * @return array{numeric:?float,raw:mixed}
     */
    public static function splitSample($sample): array
    {
        if (is_array($sample)) {
            $num = $sample['numeric'] ?? null;
            if ($num !== null && $num !== '' && is_numeric($num)) {
                $num = (float)$num;
            } else {
                $num = null;
            }
            return ['numeric' => $num, 'raw' => $sample['raw'] ?? $sample['value'] ?? null];
        }
        if (is_numeric($sample)) {
            return ['numeric' => (float)$sample, 'raw' => $sample];
        }
        return ['numeric' => null, 'raw' => $sample];
    }

    /**
     * Persist last-poll snapshot extras + history rows.
     *
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $oidMap
     */
    public static function record(int $deviceId, array $metrics, array $oidMap = []): void
    {
        if ($deviceId < 1) {
            return;
        }
        foreach ($metrics as $key => $sample) {
            $k = (string)$key;
            if ($k === '' || str_starts_with($k, '_')) {
                continue;
            }
            if (!self::shouldChart($k, $oidMap)) {
                continue;
            }
            $split = self::splitSample($sample);
            if ($split['numeric'] === null) {
                continue;
            }
            try {
                Database::insert('device_snmp_readings', [
                    'device_id' => $deviceId,
                    'metric_name' => mb_substr($k, 0, 100),
                    'metric_value' => $split['numeric'],
                    'metric_text' => mb_substr(self::rawToString($split['raw']), 0, 255) ?: null,
                ]);
            } catch (Throwable $e) {
                // table may not exist yet on a racing first poll
            }
        }
    }

    public static function prune(): void
    {
        $days = self::RETENTION_DAYS;
        Database::query(
            "DELETE FROM device_snmp_readings WHERE polled_at < DATEADD(DAY, -{$days}, SYSUTCDATETIME())"
        );
    }

    /**
     * @return array{ok:bool,series:array<string,mixed>,meta:array<string,mixed>}
     */
    public static function series(int $deviceId, int $hours = 24): array
    {
        $hours = max(1, min(24 * 90, $hours));
        $rows = [];
        try {
            $rows = Database::fetchAll(
                "SELECT metric_name, metric_value, polled_at
                 FROM device_snmp_readings
                 WHERE device_id = ?
                   AND polled_at >= DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
                 ORDER BY polled_at ASC",
                [$deviceId]
            );
        } catch (Throwable $e) {
            $rows = [];
        }

        $byTime = [];
        $metrics = [];
        foreach ($rows as $r) {
            $t = (string)($r['polled_at'] ?? '');
            $m = (string)($r['metric_name'] ?? '');
            if ($t === '' || $m === '') {
                continue;
            }
            $metrics[$m] = true;
            if (!isset($byTime[$t])) {
                $byTime[$t] = [];
            }
            $v = $r['metric_value'];
            $byTime[$t][$m] = ($v === null || $v === '') ? null : (float)$v;
        }
        ksort($byTime);
        $tArr = array_keys($byTime);
        $series = ['t' => $tArr];
        $names = array_keys($metrics);
        sort($names);
        foreach ($names as $m) {
            $col = [];
            foreach ($tArr as $t) {
                $col[] = $byTime[$t][$m] ?? null;
            }
            $series[$m] = $col;
        }

        $fields = [];
        foreach ($names as $m) {
            $fields[] = [
                'key' => $m,
                'label' => self::labelFor($m),
                'unit' => self::unitFor($m),
            ];
        }

        return [
            'ok' => true,
            'scope' => 'device_snmp',
            'scope_id' => $deviceId,
            'hours' => $hours,
            'series' => $series,
            'outages' => [],
            'meta' => ['fields' => $fields, 'samples' => count($tArr)],
        ];
    }
}
