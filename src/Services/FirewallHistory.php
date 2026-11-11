<?php
/**
 * Firewall chart series from stored SNMP samples.
 * Utilization is the octet delta over the poll gap, divided by interface speed.
 */
declare(strict_types=1);

class FirewallHistory
{
    public const WINDOW_HOURS = 6;
    public const MIN_DT_SEC = 20;
    public const MAX_DT_SEC = 900;
    public const CHART_POINTS = 180;

    /** @return list<array<string,mixed>> */
    public static function wall(): array
    {
        try {
            $rows = Database::fetchAll(
                'SELECT TOP 3 firewall_id, name, model, last_failover, last_cpu_pct, last_connections, last_cps,
                        snmp_last_poll_at, last_poll_json
                 FROM firewalls
                 WHERE is_active = 1
                 ORDER BY CASE WHEN last_connections IS NULL THEN 1 ELSE 0 END,
                          last_connections DESC, name'
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $one = self::fromRow($row);
            if ($one !== null) {
                $out[] = $one;
            }
        }
        usort($out, static function (array $a, array $b): int {
            $c = ((int)($b['connections'] ?? -1)) <=> ((int)($a['connections'] ?? -1));
            if ($c !== 0) {
                return $c;
            }
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
        return array_slice($out, 0, 3);
    }

    /** @return array<string,mixed>|null */
    public static function one(int $firewallId): ?array
    {
        if ($firewallId < 1) {
            return null;
        }
        try {
            $row = Database::fetchOne(
                'SELECT firewall_id, name, model, last_failover, last_cpu_pct, last_connections, last_cps,
                        snmp_last_poll_at, last_poll_json
                 FROM firewalls
                 WHERE firewall_id = ? AND is_active = 1',
                [$firewallId]
            );
        } catch (Throwable $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        return self::fromRow($row);
    }

    /**
     * Bytes-per-second and percent of interface speed between two counters.
     * Returns null when the gap is too short, too long, or the counter reset.
     *
     * @return array{bps:float,util:?float}|null
     */
    public static function counterDelta(?string $older, ?string $newer, int $dtSec, ?int $speedMbps): ?array
    {
        if ($older === null || $newer === null || $dtSec < self::MIN_DT_SEC || $dtSec > self::MAX_DT_SEC) {
            return null;
        }
        if (!preg_match('/^\d+$/', $older) || !preg_match('/^\d+$/', $newer)) {
            return null;
        }
        if (function_exists('bccomp')) {
            if (bccomp($newer, $older, 0) < 0) {
                return null;
            }
            $delta = bcsub($newer, $older, 0);
        } elseif ((float)$newer < (float)$older) {
            return null;
        } else {
            $delta = sprintf('%.0f', (float)$newer - (float)$older);
        }
        $bps = ((float)$delta) * 8.0 / $dtSec;
        if (!is_finite($bps) || $bps < 0) {
            return null;
        }
        $util = null;
        if ($speedMbps !== null && $speedMbps > 0) {
            $util = round($bps / ($speedMbps * 1000000.0) * 100.0, 2);
        }
        return ['bps' => $bps, 'util' => $util];
    }

    public static function formatBps(float $bps): string
    {
        $sign = $bps < 0 ? '-' : '';
        $a = abs($bps);
        if ($a >= 1000000000) {
            return $sign . round($a / 1000000000, 2) . ' Gb/s';
        }
        if ($a >= 1000000) {
            return $sign . round($a / 1000000, 1) . ' Mb/s';
        }
        if ($a >= 1000) {
            return $sign . round($a / 1000) . ' kb/s';
        }
        return $sign . round($a) . ' b/s';
    }

    public static function formatSpeed(?int $mbps): string
    {
        if ($mbps === null || $mbps <= 0) {
            return '';
        }
        if ($mbps >= 1000 && $mbps % 1000 === 0) {
            return (string)($mbps / 1000) . ' Gb/s';
        }
        return $mbps . ' Mb/s';
    }

    /**
     * @param array<string,mixed> $fw
     * @return array<string,mixed>|null
     */
    private static function fromRow(array $fw): ?array
    {
        $id = (int)($fw['firewall_id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $samples = [];
        $ifRows = [];
        try {
            $samples = Database::fetchAll(
                'SELECT cpu_pct, memory_pct, connections, cps, failover, polled_at
                 FROM firewall_samples
                 WHERE firewall_id = ? AND polled_at >= DATEADD(hour, -' . self::WINDOW_HOURS . ', SYSUTCDATETIME())
                 ORDER BY polled_at',
                [$id]
            );
            $ifRows = Database::fetchAll(
                'SELECT if_name, oper, speed_mbps, in_octets, out_octets, polled_at
                 FROM firewall_if_samples
                 WHERE firewall_id = ? AND polled_at >= DATEADD(hour, -' . self::WINDOW_HOURS . ', SYSUTCDATETIME())
                 ORDER BY polled_at, if_name',
                [$id]
            );
        } catch (Throwable $e) {
            $samples = [];
            $ifRows = [];
        }

        $cpu = [];
        $memory = [];
        $connections = [];
        $cpsSeries = [];
        foreach ($samples as $sample) {
            $cpu[] = self::numOrNull($sample['cpu_pct'] ?? null);
            $memory[] = self::numOrNull($sample['memory_pct'] ?? null);
            $connections[] = isset($sample['connections']) && $sample['connections'] !== null && $sample['connections'] !== ''
                ? (int)$sample['connections'] : null;
            $cpsSeries[] = isset($sample['cps']) && $sample['cps'] !== null && $sample['cps'] !== ''
                ? (int)$sample['cps'] : null;
        }
        $latest = $samples ? $samples[count($samples) - 1] : null;
        $cpuNow = $latest ? self::numOrNull($latest['cpu_pct'] ?? null) : self::numOrNull($fw['last_cpu_pct'] ?? null);
        $memNow = $latest ? self::numOrNull($latest['memory_pct'] ?? null) : null;
        if ($memNow === null) {
            $memNow = self::memoryFromSnapshot($fw['last_poll_json'] ?? null);
        }
        $connNow = $latest && $latest['connections'] !== null && $latest['connections'] !== ''
            ? (int)$latest['connections']
            : (isset($fw['last_connections']) && $fw['last_connections'] !== null && $fw['last_connections'] !== ''
                ? (int)$fw['last_connections'] : null);
        $cpsNow = $latest && $latest['cps'] !== null && $latest['cps'] !== ''
            ? (int)$latest['cps']
            : (isset($fw['last_cps']) && $fw['last_cps'] !== null && $fw['last_cps'] !== '' ? (int)$fw['last_cps'] : null);

        $grouped = [];
        foreach ($ifRows as $row) {
            $name = trim((string)($row['if_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $grouped[$name][] = [
                'oper' => (string)($row['oper'] ?? ''),
                'speed_mbps' => isset($row['speed_mbps']) && $row['speed_mbps'] !== null && $row['speed_mbps'] !== ''
                    ? (int)$row['speed_mbps'] : null,
                'in_octets' => self::digits($row['in_octets'] ?? null),
                'out_octets' => self::digits($row['out_octets'] ?? null),
                'polled_at' => (string)($row['polled_at'] ?? ''),
            ];
        }
        if (!$grouped) {
            foreach (self::snapshotInterfaces($fw['last_poll_json'] ?? null) as $iface) {
                $name = trim((string)($iface['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $grouped[$name][] = [
                    'oper' => (string)($iface['oper'] ?? ''),
                    'speed_mbps' => isset($iface['speed_mbps']) ? (int)$iface['speed_mbps'] : null,
                    'in_octets' => self::digits($iface['in_octets'] ?? null),
                    'out_octets' => self::digits($iface['out_octets'] ?? null),
                    'polled_at' => '',
                ];
            }
        }

        $interfaces = [];
        foreach ($grouped as $name => $points) {
            $last = $points[count($points) - 1];
            $speed = $last['speed_mbps'];
            $oper = strtolower((string)$last['oper']);
            if ($oper !== 'up' || $speed === null || $speed <= 0) {
                continue;
            }
            $inSeries = [];
            $outSeries = [];
            $now = null;
            $prev = null;
            foreach ($points as $point) {
                if ($prev !== null && $prev['polled_at'] !== '' && $point['polled_at'] !== '') {
                    $dt = self::secondsBetween($prev['polled_at'], $point['polled_at']);
                    $speedNow = $point['speed_mbps'] ?? $speed;
                    $inRate = self::counterDelta($prev['in_octets'], $point['in_octets'], $dt, $speedNow);
                    $outRate = self::counterDelta($prev['out_octets'], $point['out_octets'], $dt, $speedNow);
                    $inSeries[] = $inRate['util'] ?? null;
                    $outSeries[] = $outRate['util'] ?? null;
                    if ($inRate !== null || $outRate !== null) {
                        $now = [
                            'in_util' => $inRate['util'] ?? null,
                            'out_util' => $outRate['util'] ?? null,
                            'in_bps' => isset($inRate['bps']) ? (int)round($inRate['bps']) : null,
                            'out_bps' => isset($outRate['bps']) ? (int)round($outRate['bps']) : null,
                            'in_text' => isset($inRate['bps']) ? self::formatBps($inRate['bps']) : null,
                            'out_text' => isset($outRate['bps']) ? self::formatBps($outRate['bps']) : null,
                        ];
                    } else {
                        $now = null;
                    }
                }
                $prev = $point;
            }
            [$inSeries, $outSeries] = self::thinPair($inSeries, $outSeries);
            $interfaces[] = [
                'name' => $name,
                'oper' => 'up',
                'speed_mbps' => $speed,
                'speed_text' => self::formatSpeed($speed),
                'in_util' => $now['in_util'] ?? null,
                'out_util' => $now['out_util'] ?? null,
                'in_text' => $now['in_text'] ?? null,
                'out_text' => $now['out_text'] ?? null,
                'in_series' => $inSeries,
                'out_series' => $outSeries,
            ];
        }
        usort($interfaces, static function (array $a, array $b): int {
            $ua = max((float)($a['in_util'] ?? -1), (float)($a['out_util'] ?? -1));
            $ub = max((float)($b['in_util'] ?? -1), (float)($b['out_util'] ?? -1));
            if ($ua !== $ub) {
                return $ub <=> $ua;
            }
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        $sampleCount = count($samples);
        $note = $sampleCount < 2
            ? 'Interface utilization starts after the next poll. With scheduled SNMP polling on, this firewall is sampled about once a minute.'
            : 'Last ' . self::WINDOW_HOURS . ' hours. Utilization compares the octets since the previous sample with the interface speed.';

        return [
            'firewall_id' => $id,
            'name' => (string)($fw['name'] ?? ''),
            'model' => (string)($fw['model'] ?? ''),
            'failover' => (string)($latest['failover'] ?? $fw['last_failover'] ?? ''),
            'cpu' => $cpuNow,
            'memory' => $memNow,
            'connections' => $connNow,
            'cps' => $cpsNow,
            'polled_at' => $latest['polled_at'] ?? ($fw['snmp_last_poll_at'] ?? null),
            'sample_count' => $sampleCount,
            'window_hours' => self::WINDOW_HOURS,
            'cpu_series' => self::thinList($cpu),
            'memory_series' => self::thinList($memory),
            'connection_series' => self::thinList($connections),
            'cps_series' => self::thinList($cpsSeries),
            'interfaces' => $interfaces,
            'note' => $note,
        ];
    }

    /** @param mixed $raw */
    private static function digits($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw >= 0 ? (string)$raw : null;
        }
        if (is_float($raw)) {
            if ($raw < 0 || !is_finite($raw)) {
                return null;
            }
            return sprintf('%.0f', $raw);
        }
        $s = trim((string)$raw);
        return preg_match('/^\d+$/', $s) ? $s : null;
    }

    /** @param mixed $raw */
    private static function numOrNull($raw): ?float
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        return round((float)$raw, 2);
    }

    /** @param mixed $json */
    private static function memoryFromSnapshot($json): ?float
    {
        $snap = self::decode($json);
        $mem = is_array($snap['memory'] ?? null) ? $snap['memory'] : [];
        return self::numOrNull($mem['pct'] ?? null);
    }

    /**
     * @param mixed $json
     * @return list<array<string,mixed>>
     */
    private static function snapshotInterfaces($json): array
    {
        $snap = self::decode($json);
        $rows = $snap['interfaces'] ?? null;
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param mixed $json @return array<string,mixed> */
    private static function decode($json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function secondsBetween(string $older, string $newer): int
    {
        $a = strtotime(substr(trim($older), 0, 19));
        $b = strtotime(substr(trim($newer), 0, 19));
        if ($a === false || $b === false) {
            return 0;
        }
        return $b - $a;
    }

    /**
     * @param list<float|int|null> $values
     * @return list<float|int|null>
     */
    private static function thinList(array $values): array
    {
        [$a] = self::thinPair($values, $values);
        return $a;
    }

    /**
     * @param list<mixed> $a
     * @param list<mixed> $b
     * @return array{0:list<mixed>,1:list<mixed>}
     */
    private static function thinPair(array $a, array $b): array
    {
        $n = max(count($a), count($b));
        $max = self::CHART_POINTS;
        if ($n <= $max) {
            return [$a, $b];
        }
        $step = (int)ceil(($n - 1) / ($max - 1));
        $aa = [];
        $bb = [];
        for ($i = 0; $i < $n - 1; $i += $step) {
            $aa[] = $a[$i] ?? null;
            $bb[] = $b[$i] ?? null;
        }
        $aa[] = $a[$n - 1] ?? null;
        $bb[] = $b[$n - 1] ?? null;
        return [$aa, $bb];
    }
}
