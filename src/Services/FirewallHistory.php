<?php
/**
 * Firewall chart series from stored SNMP samples.
 * Utilization is the octet delta over the poll gap, divided by interface speed.
 */
declare(strict_types=1);

class FirewallHistory
{
    public const WINDOW_HOURS = 6;
    public const THROUGHPUT_HOURS = 24;
    public const SHORT_WINDOW_MIN = 30;
    public const MIN_DT_SEC = 20;
    public const MAX_DT_SEC = 900;
    public const CHART_POINTS = 180;
    public const SHORT_CHART_POINTS = 60;

    /** @return list<array<string,mixed>> */
    public static function wall(): array
    {
        try {
            $rows = self::fetchFirewallList(
                'WHERE is_active = 1 ORDER BY name'
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
        return array_slice($out, 0, 12);
    }

    /** @return array<string,mixed>|null */
    public static function one(int $firewallId): ?array
    {
        if ($firewallId < 1) {
            return null;
        }
        try {
            $rows = self::fetchFirewallList('WHERE firewall_id = ? AND is_active = 1', [$firewallId]);
            $row = $rows[0] ?? null;
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

    /**
     * Error or discard count between two samples.
     * Null when the gap is too short, too long, or the counter reset.
     *
     * @return array{delta:float,per_sec:float}|null
     */
    public static function counterCount(?string $older, ?string $newer, int $dtSec): ?array
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
        $n = (float)$delta;
        if (!is_finite($n) || $n < 0) {
            return null;
        }
        return ['delta' => $n, 'per_sec' => $n / $dtSec];
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
     * Interface names whose throughput charts are turned off.
     * A missing preference means every interface is graphed.
     *
     * @return array<string,true>
     */
    public static function graphDisabled(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $off = [];
        foreach ($data as $name => $flag) {
            $name = self::ifKey((string)$name);
            if ($name === '') {
                continue;
            }
            if ($flag === 0 || $flag === '0' || $flag === false) {
                $off[$name] = true;
            }
        }
        return $off;
    }

    /**
     * Remember which of the known interfaces were left unchecked.
     * Names that are not on this form keep their previous setting.
     *
     * @param list<string> $known
     * @param list<string> $enabled
     */
    public static function encodeGraphPref(?string $previous, array $known, array $enabled): ?string
    {
        $off = self::graphDisabled($previous);
        $enabledMap = [];
        foreach ($enabled as $name) {
            $name = self::ifKey((string)$name);
            if ($name !== '') {
                $enabledMap[$name] = true;
            }
        }
        foreach ($known as $name) {
            $name = self::ifKey((string)$name);
            if ($name === '') {
                continue;
            }
            if (isset($enabledMap[$name])) {
                unset($off[$name]);
            } else {
                $off[$name] = true;
            }
        }
        if (!$off) {
            return null;
        }
        $payload = [];
        foreach (array_keys($off) as $name) {
            $payload[$name] = 0;
        }
        ksort($payload);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    /**
     * Names from the last poll only. Hidden tunnels and VTIs are omitted.
     * A name that left the poll is not listed again just because graphing was turned off.
     *
     * @param array<string,mixed> $fw
     * @return list<string>
     */
    public static function interfaceChoices(array $fw): array
    {
        $hidden = self::hiddenNames(isset($fw['if_hide_json']) ? (string)$fw['if_hide_json'] : null);
        self::loadPoll();
        $names = [];
        $snap = self::decode($fw['last_poll_json'] ?? null);
        $rows = $snap['interfaces'] ?? null;
        if (is_array($rows)) {
            foreach ($rows as $iface) {
                if (!is_array($iface)) {
                    continue;
                }
                $name = self::ifKey((string)($iface['name'] ?? ''));
                if ($name === '' || isset($hidden[$name]) || FirewallPoll::isVti($iface)) {
                    continue;
                }
                $names[$name] = true;
            }
        }
        $list = array_keys($names);
        natcasesort($list);
        return array_values($list);
    }

    /**
     * Hidden names that the last poll still reports, so Edit can show them again.
     *
     * @param array<string,mixed> $fw
     * @return list<string>
     */
    public static function hiddenChoices(array $fw): array
    {
        $hidden = self::hiddenNames(isset($fw['if_hide_json']) ? (string)$fw['if_hide_json'] : null);
        if (!$hidden) {
            return [];
        }
        $names = [];
        foreach (self::pollNames($fw) as $name) {
            if (isset($hidden[$name])) {
                $names[$name] = true;
            }
        }
        $list = array_keys($names);
        natcasesort($list);
        return array_values($list);
    }

    /**
     * Interface names in the stored poll. Empty when the firewall has not been polled.
     *
     * @param array<string,mixed> $fw
     * @return list<string>
     */
    public static function pollNames(array $fw): array
    {
        $names = [];
        $snap = self::decode($fw['last_poll_json'] ?? null);
        $rows = $snap['interfaces'] ?? null;
        if (!is_array($rows)) {
            return [];
        }
        foreach ($rows as $iface) {
            if (!is_array($iface)) {
                continue;
            }
            $name = self::ifKey((string)($iface['name'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        return array_keys($names);
    }

    /**
     * @return array<string,true>
     */
    public static function hiddenNames(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $hidden = [];
        foreach ($data as $name => $flag) {
            $name = self::ifKey((string)$name);
            if ($name === '') {
                continue;
            }
            if ($flag === 0 || $flag === '0' || $flag === false) {
                $hidden[$name] = true;
            }
        }
        return $hidden;
    }

    public static function hideName(?string $previous, string $name): ?string
    {
        $name = self::ifKey($name);
        $hidden = self::hiddenNames($previous);
        if ($name !== '') {
            $hidden[$name] = true;
        }
        return self::encodeHideMap($hidden);
    }

    /**
     * Checked names stay hidden. Names on the form that were cleared come back.
     * Names the last poll no longer reports are dropped, unless that poll is empty.
     *
     * @param list<string> $known
     * @param list<string> $keptHidden
     * @param list<string> $present
     */
    public static function encodeHidePref(?string $previous, array $known, array $keptHidden, array $present): ?string
    {
        $hidden = self::hiddenNames($previous);
        $kept = [];
        foreach ($keptHidden as $name) {
            $name = self::ifKey((string)$name);
            if ($name !== '') {
                $kept[$name] = true;
            }
        }
        foreach ($known as $name) {
            $name = self::ifKey((string)$name);
            if ($name === '') {
                continue;
            }
            if (isset($kept[$name])) {
                $hidden[$name] = true;
            } else {
                unset($hidden[$name]);
            }
        }
        $presentMap = [];
        foreach ($present as $name) {
            $name = self::ifKey((string)$name);
            if ($name !== '') {
                $presentMap[$name] = true;
            }
        }
        if ($presentMap) {
            foreach (array_keys($hidden) as $name) {
                if (!isset($presentMap[$name])) {
                    unset($hidden[$name]);
                }
            }
        }
        return self::encodeHideMap($hidden);
    }

    /**
     * Up to two WAN-facing links. Tunnels are skipped.
     * A name that looks like a WAN and is up ranks first. Otherwise the up link with the highest speed.
     *
     * @param list<array<string,mixed>> $interfaces
     * @param array<string,true> $hidden
     * @return list<array<string,mixed>>
     */
    public static function pickWan(array $interfaces, int $limit = 2, array $hidden = []): array
    {
        self::loadPoll();
        $limit = max(1, min(4, $limit));
        $scored = [];
        foreach ($interfaces as $iface) {
            if (!is_array($iface)) {
                continue;
            }
            $name = self::ifKey((string)($iface['name'] ?? ''));
            if ($name === '' || isset($hidden[$name]) || preg_match('/^Internal-/i', $name)) {
                continue;
            }
            if (FirewallPoll::isVti($iface)) {
                continue;
            }
            $score = self::wanScore($iface);
            if ($score === null) {
                continue;
            }
            $scored[] = ['score' => $score, 'iface' => $iface];
        }
        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            $sa = (int)($a['iface']['speed_mbps'] ?? 0);
            $sb = (int)($b['iface']['speed_mbps'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            return strcasecmp((string)($a['iface']['name'] ?? ''), (string)($b['iface']['name'] ?? ''));
        });
        $out = [];
        foreach ($scored as $row) {
            $out[] = $row['iface'];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Compact dashboard rows. Does not build chart series.
     *
     * @return array{rows:list<array<string,mixed>>,more:bool}
     */
    public static function wanBoard(int $limit = 8): array
    {
        $limit = max(1, min(12, $limit));
        $take = $limit + 1;
        $rows = [];
        $wide = 'firewall_id, name, model, platform, ha_group, last_failover, last_poll_json, if_hide_json';
        $base = 'firewall_id, name, model, platform, ha_group, last_failover, last_poll_json';
        try {
            $rows = Database::fetchAll(
                'SELECT TOP ' . $take . ' ' . $wide . ' FROM firewalls WHERE is_active = 1 ORDER BY name'
            );
        } catch (Throwable $e) {
            try {
                $rows = Database::fetchAll(
                    'SELECT TOP ' . $take . ' ' . $base . ' FROM firewalls WHERE is_active = 1 ORDER BY name'
                );
            } catch (Throwable $e2) {
                return ['rows' => [], 'more' => false];
            }
        }
        $more = count($rows) > $limit;
        if ($more) {
            $rows = array_slice($rows, 0, $limit);
        }
        $out = [];
        foreach ($rows as $fw) {
            $id = (int)($fw['firewall_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $hidden = self::hiddenNames(isset($fw['if_hide_json']) ? (string)$fw['if_hide_json'] : null);
            $snap = self::decode($fw['last_poll_json'] ?? null);
            $ifaces = is_array($snap['interfaces'] ?? null) ? $snap['interfaces'] : [];
            $picked = self::pickWan($ifaces, 2, $hidden);
            $names = [];
            foreach ($picked as $iface) {
                $names[] = self::ifKey((string)($iface['name'] ?? ''));
            }
            $rates = self::latestRates($id, $names);
            $links = [];
            foreach ($picked as $iface) {
                $name = self::ifKey((string)($iface['name'] ?? ''));
                $speed = isset($iface['speed_mbps']) ? (int)$iface['speed_mbps'] : 0;
                $rate = $rates[$name] ?? null;
                $links[] = [
                    'name' => $name,
                    'oper' => (string)($iface['oper'] ?? ''),
                    'speed_mbps' => $speed,
                    'in_text' => ($speed > 0 && is_array($rate)) ? ($rate['in_text'] ?? null) : null,
                    'out_text' => ($speed > 0 && is_array($rate)) ? ($rate['out_text'] ?? null) : null,
                ];
            }
            $platform = strtolower(trim((string)($fw['platform'] ?? 'asa')));
            $out[] = [
                'firewall_id' => $id,
                'name' => (string)($fw['name'] ?? ''),
                'model' => (string)($fw['model'] ?? ''),
                'platform' => $platform === 'ftd' ? 'ftd' : 'asa',
                'ha_group' => trim((string)($fw['ha_group'] ?? '')),
                'failover' => trim((string)($fw['last_failover'] ?? '')),
                'polled' => $ifaces !== [],
                'links' => $links,
            ];
        }
        return ['rows' => $out, 'more' => $more];
    }

    /**
     * Rack device first, then a firewall whose management address matches and is not linked elsewhere.
     *
     * @return array<string,mixed>|null
     */
    public static function matchDevice(int $deviceId, ?string $mgmt, ?string $primary): ?array
    {
        try {
            if ($deviceId > 0) {
                $row = Database::fetchOne(
                    'SELECT TOP 1 firewall_id, name, primary_ip, device_id
                     FROM firewalls WHERE is_active = 1 AND device_id = ?',
                    [$deviceId]
                );
                if ($row) {
                    $row['match'] = 'device';
                    return $row;
                }
            }
            $ips = [];
            foreach ([$mgmt, $primary] as $ip) {
                $ip = trim((string)$ip);
                if ($ip !== '' && !in_array($ip, $ips, true)) {
                    $ips[] = $ip;
                }
            }
            foreach ($ips as $ip) {
                $row = Database::fetchOne(
                    'SELECT TOP 1 firewall_id, name, primary_ip, device_id
                     FROM firewalls WHERE is_active = 1 AND primary_ip = ?',
                    [$ip]
                );
                if ($row) {
                    $row['match'] = 'ip';
                    return $row;
                }
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Identity fields copied from a Firewall rack device. SNMP stays on the firewall row.
     *
     * @return array<string,mixed>|null
     */
    public static function devicePrefill(int $deviceId): ?array
    {
        if ($deviceId < 1) {
            return null;
        }
        try {
            $row = Database::fetchOne(
                'SELECT d.label, d.manufacturer, d.model, d.hostname, d.mgmt_ip, d.primary_ip, d.serial_no, r.room_id
                 FROM devices d
                 LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                 LEFT JOIN rooms r ON r.room_id = c.room_id
                 WHERE d.device_id = ? AND d.is_active = 1 AND d.device_type = ?',
                [$deviceId, 'firewall']
            );
        } catch (Throwable $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        $ip = trim((string)($row['mgmt_ip'] ?? ''));
        if ($ip === '') {
            $ip = trim((string)($row['primary_ip'] ?? ''));
        }
        $vendor = trim((string)($row['manufacturer'] ?? ''));
        return [
            'name' => trim((string)($row['label'] ?? '')),
            'vendor' => $vendor !== '' ? $vendor : 'Cisco',
            'model' => trim((string)($row['model'] ?? '')),
            'hostname' => trim((string)($row['hostname'] ?? '')),
            'primary_ip' => $ip,
            'serial_no' => trim((string)($row['serial_no'] ?? '')),
            'room_id' => !empty($row['room_id']) ? (int)$row['room_id'] : '',
            'device_id' => $deviceId,
        ];
    }

    /**
     * Firewall-type rack devices that are free, plus the one already linked to this firewall.
     *
     * @return list<array<string,mixed>>
     */
    public static function rackDevices(int $firewallId): array
    {
        try {
            return Database::fetchAll(
                'SELECT d.device_id, d.label, c.name AS cabinet_name
                 FROM devices d
                 LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                 WHERE d.is_active = 1
                   AND (
                        (d.device_type = ? AND NOT EXISTS (
                            SELECT 1 FROM firewalls f
                            WHERE f.device_id = d.device_id AND f.is_active = 1 AND f.firewall_id <> ?
                        ))
                        OR d.device_id = (SELECT device_id FROM firewalls WHERE firewall_id = ?)
                   )
                 ORDER BY d.label',
                ['firewall', $firewallId, $firewallId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @param array<string,true> $hidden */
    private static function encodeHideMap(array $hidden): ?string
    {
        if (!$hidden) {
            return null;
        }
        $payload = [];
        foreach (array_keys($hidden) as $name) {
            $payload[$name] = 0;
        }
        ksort($payload);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    /** @param array<string,mixed> $iface */
    private static function wanScore(array $iface): ?float
    {
        $name = strtolower(trim((string)($iface['name'] ?? '')));
        if ($name === '') {
            return null;
        }
        $score = 0.0;
        if (preg_match('/wan|outside|internet|external/', $name)) {
            $score += 100;
        }
        if (preg_match('/failover|management|diagnostic|mgmt|(^|[^a-z])ha([^a-z]|$)/', $name)) {
            $score -= 40;
        }
        if (strtolower((string)($iface['oper'] ?? '')) === 'up') {
            $score += 20;
        }
        $speed = (int)($iface['speed_mbps'] ?? 0);
        if ($speed > 0) {
            $score += min(40.0, log10($speed + 1) * 10);
        }
        return round($score, 2);
    }

    /**
     * @param list<string> $names
     * @return array<string,array{in_text:?string,out_text:?string}>
     */
    private static function latestRates(int $firewallId, array $names): array
    {
        $clean = [];
        foreach ($names as $name) {
            $name = self::ifKey((string)$name);
            if ($name !== '') {
                $clean[$name] = true;
            }
        }
        $clean = array_keys($clean);
        if ($firewallId < 1 || !$clean) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($clean), '?'));
        $params = array_merge([$firewallId], $clean);
        try {
            $rows = Database::fetchAll(
                'SELECT if_name, speed_mbps, in_octets, out_octets, polled_at
                 FROM firewall_if_samples
                 WHERE firewall_id = ? AND if_name IN (' . $marks . ')
                   AND polled_at >= DATEADD(hour, -2, SYSUTCDATETIME())
                 ORDER BY polled_at ASC',
                $params
            );
        } catch (Throwable $e) {
            return [];
        }
        $by = [];
        foreach ($rows as $row) {
            $by[(string)($row['if_name'] ?? '')][] = $row;
        }
        $out = [];
        foreach ($by as $name => $points) {
            if (count($points) < 2) {
                continue;
            }
            $prev = $points[count($points) - 2];
            $cur = $points[count($points) - 1];
            $speed = isset($cur['speed_mbps']) && $cur['speed_mbps'] !== '' && $cur['speed_mbps'] !== null
                ? (int)$cur['speed_mbps'] : 0;
            if ($speed <= 0) {
                continue;
            }
            $dt = self::secondsBetween((string)($prev['polled_at'] ?? ''), (string)($cur['polled_at'] ?? ''));
            $in = self::counterDelta(self::digits($prev['in_octets'] ?? null), self::digits($cur['in_octets'] ?? null), $dt, $speed);
            $outRate = self::counterDelta(self::digits($prev['out_octets'] ?? null), self::digits($cur['out_octets'] ?? null), $dt, $speed);
            $out[$name] = [
                'in_text' => is_array($in) && isset($in['bps']) ? self::formatBps($in['bps']) : null,
                'out_text' => is_array($outRate) && isset($outRate['bps']) ? self::formatBps($outRate['bps']) : null,
            ];
        }
        return $out;
    }

    private static function loadPoll(): void
    {
        if (!class_exists('FirewallPoll', false)) {
            require_once __DIR__ . '/FirewallPoll.php';
        }
    }

    private static function ifKey(string $name): string
    {
        return substr(trim($name), 0, 128);
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
            $samples = self::fetchSamples($id);
            $ifRows = self::fetchIfSamples($id);
        } catch (Throwable $e) {
            $samples = [];
            $ifRows = [];
        }

        $cpu = [];
        $memory = [];
        $connections = [];
        $cpsSeries = [];
        $connPeakSeries = [];
        $xlateSeries = [];
        $vpnSeries = [];
        $ipsecSeries = [];
        $anyConnectSeries = [];
        $linaSeries = [];
        $snortSeries = [];
        $diskSeries = [];
        foreach ($samples as $sample) {
            $cpu[] = self::numOrNull($sample['cpu_pct'] ?? null);
            $memory[] = self::numOrNull($sample['memory_pct'] ?? null);
            $connections[] = self::intOrNull($sample['connections'] ?? null);
            $cpsSeries[] = self::intOrNull($sample['cps'] ?? null);
            $connPeakSeries[] = self::intOrNull($sample['conn_peak'] ?? null);
            $xlateSeries[] = self::intOrNull($sample['xlate_pat'] ?? null);
            $vpnSeries[] = self::intOrNull($sample['vpn_sessions'] ?? null);
            $ipsecSeries[] = self::intOrNull($sample['ipsec_tunnels'] ?? null);
            $anyConnectSeries[] = self::intOrNull($sample['vpn_svc'] ?? null);
            $linaSeries[] = self::numOrNull($sample['cpu_lina_pct'] ?? null);
            $snortSeries[] = self::numOrNull($sample['cpu_snort_pct'] ?? null);
            $diskSeries[] = self::numOrNull($sample['disk_pct'] ?? null);
        }
        $snap = self::decode($fw['last_poll_json'] ?? null);
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
                'in_errors' => self::digits($row['in_errors'] ?? null),
                'out_errors' => self::digits($row['out_errors'] ?? null),
                'in_discards' => self::digits($row['in_discards'] ?? null),
                'out_discards' => self::digits($row['out_discards'] ?? null),
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

        $graphOff = self::graphDisabled(self::graphJson($id, $fw));
        $snapRows = self::snapshotInterfaces($fw['last_poll_json'] ?? null);
        $snapByName = [];
        foreach ($snapRows as $snapIface) {
            $snapKey = self::ifKey((string)($snapIface['name'] ?? ''));
            if ($snapKey !== '') {
                $snapByName[$snapKey] = $snapIface;
            }
        }
        $snapHasRows = $snapByName !== [];
        $hiddenIf = self::hiddenNames(self::hideJson($id, $fw));
        self::loadPoll();
        $interfaces = [];
        foreach ($grouped as $name => $points) {
            $key = self::ifKey((string)$name);
            if ($key === '' || isset($hiddenIf[$key])) {
                continue;
            }
            if ($snapHasRows && !isset($snapByName[$key])) {
                continue;
            }
            $meta = $snapByName[$key] ?? ['name' => $name];
            if (FirewallPoll::isVti($meta)) {
                continue;
            }
            $last = $points[count($points) - 1];
            $speed = $last['speed_mbps'];
            $oper = strtolower((string)$last['oper']);
            if ($oper !== 'up' || $speed === null || $speed <= 0) {
                continue;
            }
            $seriesPoints = [];
            $now = null;
            $fault = null;
            $prev = null;
            foreach ($points as $point) {
                if ($prev !== null && $prev['polled_at'] !== '' && $point['polled_at'] !== '') {
                    $dt = self::secondsBetween($prev['polled_at'], $point['polled_at']);
                    $speedNow = $point['speed_mbps'] ?? $speed;
                    $inRate = self::counterDelta($prev['in_octets'], $point['in_octets'], $dt, $speedNow);
                    $outRate = self::counterDelta($prev['out_octets'], $point['out_octets'], $dt, $speedNow);
                    $inErr = self::counterCount($prev['in_errors'] ?? null, $point['in_errors'] ?? null, $dt);
                    $outErr = self::counterCount($prev['out_errors'] ?? null, $point['out_errors'] ?? null, $dt);
                    $inDrop = self::counterCount($prev['in_discards'] ?? null, $point['in_discards'] ?? null, $dt);
                    $outDrop = self::counterCount($prev['out_discards'] ?? null, $point['out_discards'] ?? null, $dt);
                    $seriesPoints[] = [
                        't' => $point['polled_at'],
                        'in' => $inRate['util'] ?? null,
                        'out' => $outRate['util'] ?? null,
                    ];
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
                    if ($inErr !== null || $outErr !== null || $inDrop !== null || $outDrop !== null) {
                        $fault = [
                            'in_err' => $inErr !== null ? (int)round($inErr['delta']) : null,
                            'out_err' => $outErr !== null ? (int)round($outErr['delta']) : null,
                            'in_discard' => $inDrop !== null ? (int)round($inDrop['delta']) : null,
                            'out_discard' => $outDrop !== null ? (int)round($outDrop['delta']) : null,
                        ];
                    } else {
                        $fault = null;
                    }
                }
                $prev = $point;
            }
            [$inShort, $outShort] = self::windowPair($seriesPoints, self::SHORT_WINDOW_MIN, self::SHORT_CHART_POINTS);
            [$inLong, $outLong] = self::windowPair($seriesPoints, self::THROUGHPUT_HOURS * 60, self::CHART_POINTS);
            $interfaces[] = [
                'name' => $name,
                'oper' => 'up',
                'speed_mbps' => $speed,
                'speed_text' => self::formatSpeed($speed),
                'in_util' => $now['in_util'] ?? null,
                'out_util' => $now['out_util'] ?? null,
                'in_text' => $now['in_text'] ?? null,
                'out_text' => $now['out_text'] ?? null,
                'in_err' => $fault['in_err'] ?? null,
                'out_err' => $fault['out_err'] ?? null,
                'in_discard' => $fault['in_discard'] ?? null,
                'out_discard' => $fault['out_discard'] ?? null,
                'graph' => !isset($graphOff[self::ifKey((string)$name)]),
                'in_series' => $inLong,
                'out_series' => $outLong,
                'in_series_30m' => $inShort,
                'out_series_30m' => $outShort,
                'in_series_24h' => $inLong,
                'out_series_24h' => $outLong,
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
            : 'Throughput is the last 30 minutes and the last 24 hours. Utilization compares the octets since the previous sample with the interface speed.';

        return [
            'firewall_id' => $id,
            'name' => (string)($fw['name'] ?? ''),
            'model' => (string)($fw['model'] ?? ''),
            'failover' => (string)($latest['failover'] ?? $fw['last_failover'] ?? ''),
            'cpu' => $cpuNow,
            'memory' => $memNow,
            'connections' => $connNow,
            'cps' => $cpsNow,
            'conn_peak' => self::seriesNow($samples, 'conn_peak', $snap),
            'xlate_pat' => self::seriesNow($samples, 'xlate_pat', $snap),
            'xlate_nat' => self::seriesNow($samples, 'xlate_nat', $snap),
            'xlate_source' => is_string($snap['xlate_source'] ?? null) ? (string)$snap['xlate_source'] : null,
            'vpn_sessions' => self::seriesNow($samples, 'vpn_sessions', $snap),
            'vpn_peak' => self::seriesNow($samples, 'vpn_peak', $snap),
            'vpn_ipsec' => self::seriesNow($samples, 'vpn_ipsec', $snap),
            'vpn_svc' => self::seriesNow($samples, 'vpn_svc', $snap),
            'vpn_webvpn' => self::seriesNow($samples, 'vpn_webvpn', $snap),
            'vpn_l2l' => self::seriesNow($samples, 'vpn_l2l', $snap),
            'ipsec_tunnels' => self::seriesNow($samples, 'ipsec_tunnels', $snap),
            'ike_tunnels' => self::seriesNow($samples, 'ike_tunnels', $snap),
            'platform' => self::platformOf($fw, $snap),
            'ha_group' => trim((string)($fw['ha_group'] ?? '')),
            'ha_pair' => trim((string)($snap['ha_pair'] ?? '')),
            'cpu_lina' => self::latestOrSnap($latest, 'cpu_lina_pct', $snap, 'cpu_lina_pct'),
            'cpu_snort' => self::latestOrSnap($latest, 'cpu_snort_pct', $snap, 'cpu_snort_pct'),
            'disk' => self::latestOrSnap($latest, 'disk_pct', $snap, 'disk_pct'),
            'conn_half_open' => self::intOrNull($snap['conn_half_open'] ?? null),
            'alerts' => self::hardwareAlerts($snap),
            'polled_at' => $latest['polled_at'] ?? ($fw['snmp_last_poll_at'] ?? null),
            'sample_count' => $sampleCount,
            'window_hours' => self::WINDOW_HOURS,
            'cpu_series' => self::thinList($cpu),
            'memory_series' => self::thinList($memory),
            'connection_series' => self::thinList($connections),
            'conn_peak_series' => self::thinList($connPeakSeries),
            'cps_series' => self::thinList($cpsSeries),
            'xlate_pat_series' => self::thinList($xlateSeries),
            'vpn_sessions_series' => self::thinList($vpnSeries),
            'ipsec_series' => self::thinList($ipsecSeries),
            'anyconnect_series' => self::thinList($anyConnectSeries),
            'cpu_lina_series' => self::thinList($linaSeries),
            'cpu_snort_series' => self::thinList($snortSeries),
            'disk_series' => self::thinList($diskSeries),
            'interfaces' => $interfaces,
            'note' => $note,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function fetchSamples(int $id): array
    {
        $where = ' FROM firewall_samples
            WHERE firewall_id = ? AND polled_at >= DATEADD(hour, -' . self::WINDOW_HOURS . ', SYSUTCDATETIME())
            ORDER BY polled_at';
        $extra = 'cpu_pct, memory_pct, connections, cps, conn_peak, xlate_pat, xlate_nat, vpn_sessions, vpn_peak, vpn_ipsec, vpn_svc, vpn_webvpn, vpn_l2l, ipsec_tunnels, ike_tunnels, cpu_lina_pct, cpu_snort_pct, disk_pct, failover, polled_at';
        $base = 'cpu_pct, memory_pct, connections, cps, failover, polled_at';
        try {
            return Database::fetchAll('SELECT ' . $extra . $where, [$id]);
        } catch (Throwable $e) {
            return Database::fetchAll('SELECT ' . $base . $where, [$id]);
        }
    }

    /** @return list<array<string,mixed>> */
    private static function fetchIfSamples(int $id): array
    {
        $where = ' FROM firewall_if_samples
            WHERE firewall_id = ? AND polled_at >= DATEADD(hour, -' . self::THROUGHPUT_HOURS . ', SYSUTCDATETIME())
            ORDER BY polled_at, if_name';
        $extra = 'if_name, oper, speed_mbps, in_octets, out_octets, in_errors, out_errors, in_discards, out_discards, polled_at';
        $base = 'if_name, oper, speed_mbps, in_octets, out_octets, polled_at';
        try {
            return Database::fetchAll('SELECT ' . $extra . $where, [$id]);
        } catch (Throwable $e) {
            return Database::fetchAll('SELECT ' . $base . $where, [$id]);
        }
    }

    /** @param array<string,mixed> $fw */
    private static function graphJson(int $id, array $fw): ?string
    {
        if (array_key_exists('if_graph_json', $fw)) {
            $raw = $fw['if_graph_json'];
            return $raw === null ? null : (string)$raw;
        }
        try {
            $raw = Database::fetchValue('SELECT if_graph_json FROM firewalls WHERE firewall_id = ?', [$id]);
        } catch (Throwable $e) {
            return null;
        }
        if ($raw === null || $raw === false) {
            return null;
        }
        return (string)$raw;
    }

    /** @param array<string,mixed> $fw */
    private static function hideJson(int $id, array $fw): ?string
    {
        if (array_key_exists('if_hide_json', $fw)) {
            $raw = $fw['if_hide_json'];
            return $raw === null ? null : (string)$raw;
        }
        try {
            $raw = Database::fetchValue('SELECT if_hide_json FROM firewalls WHERE firewall_id = ?', [$id]);
        } catch (Throwable $e) {
            return null;
        }
        if ($raw === null || $raw === false) {
            return null;
        }
        return (string)$raw;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param array<string,mixed> $snap
     */
    private static function seriesNow(array $samples, string $col, array $snap): ?int
    {
        if ($samples) {
            $last = $samples[count($samples) - 1];
            if (array_key_exists($col, $last)) {
                return self::intOrNull($last[$col]);
            }
        }
        return self::intOrNull($snap[$col] ?? null);
    }

    /** @param mixed $raw */
    private static function intOrNull($raw): ?int
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (int)round((float)$raw);
    }

    /**
     * @param array<string,mixed> $snap
     * @return list<array{label:string,detail:string,status:string}>
     */
    private static function hardwareAlerts(array $snap): array
    {
        $fail = is_array($snap['failover'] ?? null) ? $snap['failover'] : [];
        $rows = is_array($fail['rows'] ?? null) ? $fail['rows'] : [];
        if (!class_exists('FirewallPoll', false)) {
            require_once __DIR__ . '/FirewallPoll.php';
        }
        $alerts = FirewallPoll::hardwareAlerts($rows);
        $extra = is_array($snap['fmc_alerts'] ?? null) ? $snap['fmc_alerts'] : [];
        foreach ($extra as $alert) {
            if (!is_array($alert)) {
                continue;
            }
            $label = trim((string)($alert['label'] ?? ''));
            $status = trim((string)($alert['status'] ?? ''));
            if ($label === '' || $status === '') {
                continue;
            }
            $alerts[] = [
                'label' => $label,
                'detail' => trim((string)($alert['detail'] ?? '')),
                'status' => $status,
            ];
        }
        return $alerts;
    }

    /**
     * @param array<string,mixed> $fw
     * @param array<string,mixed> $snap
     */
    private static function platformOf(array $fw, array $snap): string
    {
        $platform = strtolower(trim((string)($fw['platform'] ?? $snap['platform'] ?? 'asa')));
        return $platform === 'ftd' ? 'ftd' : 'asa';
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private static function fetchFirewallList(string $where, array $params = []): array
    {
        $wide = 'firewall_id, name, model, platform, ha_group, last_failover, last_cpu_pct, last_connections, last_cps, snmp_last_poll_at, last_poll_json, if_hide_json';
        $base = 'firewall_id, name, model, last_failover, last_cpu_pct, last_connections, last_cps, snmp_last_poll_at, last_poll_json';
        $sql = 'SELECT TOP 12 ' . $wide . ' FROM firewalls ' . $where;
        try {
            return Database::fetchAll($sql, $params);
        } catch (Throwable $e) {
            return Database::fetchAll('SELECT TOP 12 ' . $base . ' FROM firewalls ' . $where, $params);
        }
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

    /**
     * @param array<string,mixed>|null $latest
     * @param array<string,mixed> $snap
     */
    private static function latestOrSnap(?array $latest, string $column, array $snap, string $snapKey): ?float
    {
        if (is_array($latest) && array_key_exists($column, $latest)) {
            return self::numOrNull($latest[$column]);
        }
        return self::numOrNull($snap[$snapKey] ?? null);
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
     * Keep samples inside the last $minutes before the newest point, then thin.
     *
     * @param list<array{t:string,in:float|null,out:float|null}> $points
     * @return array{0:list<float|null>,1:list<float|null>}
     */
    private static function windowPair(array $points, int $minutes, int $maxPoints): array
    {
        if (!$points) {
            return [[], []];
        }
        $lastRaw = substr(trim((string)$points[count($points) - 1]['t']), 0, 19);
        $last = strtotime($lastRaw);
        $cut = $last === false ? 0 : $last - ($minutes * 60);
        $in = [];
        $out = [];
        foreach ($points as $point) {
            $t = strtotime(substr(trim((string)$point['t']), 0, 19));
            if ($t === false || $t < $cut) {
                continue;
            }
            $in[] = $point['in'];
            $out[] = $point['out'];
        }
        return self::thinPair($in, $out, $maxPoints);
    }

    /**
     * @param list<mixed> $a
     * @param list<mixed> $b
     * @return array{0:list<mixed>,1:list<mixed>}
     */
    private static function thinPair(array $a, array $b, ?int $max = null): array
    {
        $n = max(count($a), count($b));
        $max = $max ?? self::CHART_POINTS;
        if ($max < 2) {
            $max = 2;
        }
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
