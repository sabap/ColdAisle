<?php
/**
 * Optional read of a Firewall Management Center for one Threat Defense device.
 * Charts still come from that device's SNMP. This adds health, software, and HA pair name.
 */
declare(strict_types=1);

class FmcClient
{
    /**
     * Never throws. SNMP results stay in place when the center cannot be read.
     *
     * @param array<string,mixed> $fw
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function enrich(array $fw, array $snapshot): array
    {
        $host = trim((string)($fw['fmc_host'] ?? ''));
        if ($host === '') {
            return $snapshot;
        }
        try {
            require_once __DIR__ . '/Crypto.php';
            $user = trim((string)($fw['fmc_username'] ?? ''));
            $pass = (string)(Crypto::decryptQuiet($fw['fmc_password'] ?? null) ?? '');
            if ($user === '' || $pass === '') {
                $snapshot['fmc_error'] = 'Management center username and password are required.';
                return $snapshot;
            }
            $verify = !isset($fw['fmc_verify_tls']) || $fw['fmc_verify_tls'] === null || (int)$fw['fmc_verify_tls'] === 1;
            $base = self::baseUrl($host, (int)($fw['fmc_port'] ?? 443));
            $auth = self::authenticate($base, $user, $pass, $verify);
            $records = self::deviceRecords($base, $auth['domain'], $auth['token'], $verify);
            $matched = self::matchDevice(
                $records,
                trim((string)($fw['primary_ip'] ?? '')),
                trim((string)($fw['name'] ?? '')),
                trim((string)($fw['hostname'] ?? ''))
            );
            if ($matched['device'] === null) {
                $snapshot['fmc_error'] = $matched['note'] !== ''
                    ? $matched['note']
                    : 'No managed device matched this firewall.';
                return $snapshot;
            }
            $device = $matched['device'];
            self::applyDevice($snapshot, $device);
            $metrics = self::aggregateMetrics($base, $auth['domain'], $auth['token'], (string)$device['id'], $verify);
            self::applyMetrics($snapshot, $metrics);
            $alerts = self::healthAlerts($base, $auth['domain'], $auth['token'], (string)$device['id'], $verify);
            if ($alerts) {
                $snapshot['fmc_alerts'] = $alerts;
            }
        } catch (Throwable $e) {
            $snapshot['fmc_error'] = substr($e->getMessage(), 0, 300);
        }
        return $snapshot;
    }

    public static function baseUrl(string $host, int $port): string
    {
        $host = trim($host);
        if (preg_match('#^https?://#i', $host)) {
            $parts = parse_url($host);
            $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
            $name = (string)($parts['host'] ?? '');
            $usePort = isset($parts['port']) ? (int)$parts['port'] : ($port > 0 ? $port : 443);
            if ($name === '') {
                throw new RuntimeException('Management center host is not a valid address.');
            }
            $host = $name;
        } else {
            $scheme = 'https';
            $usePort = $port > 0 ? $port : 443;
            $host = rtrim($host, '/');
        }
        $suffix = ($scheme === 'https' && $usePort === 443) || ($scheme === 'http' && $usePort === 80)
            ? ''
            : ':' . $usePort;
        return $scheme . '://' . $host . $suffix;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{device:?array<string,mixed>,note:string}
     */
    public static function matchDevice(array $items, string $ip, string $name, string $hostname): array
    {
        $ip = strtolower(trim($ip));
        $name = strtolower(trim($name));
        $hostname = strtolower(trim($hostname));
        $bestScore = 0;
        $best = null;
        $tie = false;
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $score = 0;
            $deviceName = strtolower(trim((string)($item['name'] ?? '')));
            $deviceHost = strtolower(trim((string)($item['hostName'] ?? $item['hostname'] ?? '')));
            if ($name !== '' && $deviceName === $name) {
                $score = 5;
            }
            if ($hostname !== '' && ($deviceHost === $hostname || $deviceName === $hostname)) {
                $score = max($score, 5);
            }
            if ($ip !== '' && self::containsIp($item, $ip)) {
                $score = max($score, 4);
            }
            if ($score < 1) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $item;
                $tie = false;
            } elseif ($score === $bestScore) {
                $tie = true;
            }
        }
        if ($tie || $best === null) {
            return [
                'device' => null,
                'note' => $tie
                    ? 'More than one managed device matched this firewall.'
                    : 'No managed device matched this name or address.',
            ];
        }
        return ['device' => $best, 'note' => ''];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $device
     */
    public static function applyDevice(array &$snapshot, array $device): void
    {
        if (empty($snapshot['model_guess']) && !empty($device['model'])) {
            $snapshot['model_guess'] = substr((string)$device['model'], 0, 100);
        }
        $version = trim((string)($device['sw_version'] ?? $device['softwareVersion'] ?? ''));
        if ($version !== '' && empty($snapshot['version'])) {
            $snapshot['version'] = substr($version, 0, 40);
        }
        if (trim((string)($snapshot['sys_name'] ?? '')) === '') {
            $hostName = trim((string)($device['hostName'] ?? $device['hostname'] ?? ''));
            if ($hostName === '') {
                $hostName = trim((string)($device['name'] ?? ''));
            }
            if ($hostName !== '') {
                $snapshot['sys_name'] = substr($hostName, 0, 128);
            }
        }
        $health = strtolower(trim((string)($device['healthStatus'] ?? '')));
        if ($health !== '') {
            $snapshot['fmc_health'] = $health;
            if (in_array($health, ['red', 'yellow', 'critical', 'warning'], true)) {
                $alerts = is_array($snapshot['fmc_alerts'] ?? null) ? $snapshot['fmc_alerts'] : [];
                $alerts[] = [
                    'label' => 'Management center health',
                    'detail' => (string)($device['name'] ?? ''),
                    'status' => $health,
                ];
                $snapshot['fmc_alerts'] = $alerts;
            }
        }
        $meta = is_array($device['metadata'] ?? null) ? $device['metadata'] : [];
        $container = is_array($meta['containerDetails'] ?? null) ? $meta['containerDetails'] : [];
        $pair = trim((string)($container['name'] ?? ''));
        if ($pair !== '') {
            $snapshot['ha_pair'] = substr($pair, 0, 80);
        }
        $role = '';
        foreach (['haRole', 'failoverRole', 'deviceRole', 'role', 'haStatus', 'failoverState'] as $key) {
            $role = trim((string)($meta[$key] ?? ''));
            if ($role !== '') {
                break;
            }
        }
        if ($role === '' && is_array($meta['haDetails'] ?? null)) {
            $details = $meta['haDetails'];
            $role = trim((string)($details['role'] ?? $details['haRole'] ?? ''));
        }
        if ($role === '' && array_key_exists('isHASecondary', $meta)) {
            $role = $meta['isHASecondary'] ? 'secondary' : 'primary';
        }
        if ($role !== '') {
            $fail = is_array($snapshot['failover'] ?? null) ? $snapshot['failover'] : [];
            if (empty($fail['this_unit'])) {
                $fail['this_unit'] = substr($role, 0, 40);
                $fail['peer'] = $fail['peer'] ?? null;
                $fail['rows'] = is_array($fail['rows'] ?? null) ? $fail['rows'] : [];
                $snapshot['failover'] = $fail;
            }
        }
    }

    /**
     * Fill only the gauges SNMP left empty. Averages are not written over a counter sample.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $metrics
     */
    public static function applyMetrics(array &$snapshot, array $metrics): void
    {
        $map = [
            'cpu_lina_pct' => 'cpu_lina_pct',
            'cpu_snort_pct' => 'cpu_snort_pct',
            'disk_pct' => 'disk_pct',
        ];
        foreach ($map as $from => $to) {
            if (!isset($snapshot[$to]) && isset($metrics[$from]) && is_numeric($metrics[$from])) {
                $snapshot[$to] = round((float)$metrics[$from], 2);
            }
        }
        if (!isset($snapshot['cpu_pct']) && isset($metrics['cpu_system_pct']) && is_numeric($metrics['cpu_system_pct'])) {
            $snapshot['cpu_pct'] = round((float)$metrics['cpu_system_pct'], 2);
        }
        $fans = is_array($metrics['fans'] ?? null) ? $metrics['fans'] : [];
        if ($fans) {
            $fail = is_array($snapshot['failover'] ?? null) ? $snapshot['failover'] : [
                'this_unit' => null,
                'peer' => null,
                'rows' => [],
            ];
            $rows = is_array($fail['rows'] ?? null) ? $fail['rows'] : [];
            foreach ($fans as $fan) {
                if (!is_array($fan)) {
                    continue;
                }
                $label = trim((string)($fan['name'] ?? 'Fan'));
                $rpm = isset($fan['rpm']) && is_numeric($fan['rpm']) ? (string)(int)round((float)$fan['rpm']) : '';
                $rows[] = ['label' => $label, 'detail' => $rpm !== '' ? $rpm . ' rpm' : '', 'status' => 'ok'];
            }
            $fail['rows'] = $rows;
            $snapshot['failover'] = $fail;
        }
    }

    /**
     * @param array<string,mixed> $json
     * @return array{cpu_lina_pct:?float,cpu_snort_pct:?float,cpu_system_pct:?float,disk_pct:?float,fans:list<array{name:string,rpm:?float}>}
     */
    public static function parseAggregate(array $json): array
    {
        $item = $json;
        if (isset($json['items']) && is_array($json['items']) && isset($json['items'][0]) && is_array($json['items'][0])) {
            $item = $json['items'][0];
        }
        $cpu = is_array($item['cpuHealthMetrics'] ?? null) ? $item['cpuHealthMetrics'] : [];
        $disk = is_array($item['diskHealthMetrics'] ?? null) ? $item['diskHealthMetrics'] : [];
        $chassis = is_array($item['chassisStatsHealthMetrics'] ?? null) ? $item['chassisStatsHealthMetrics'] : [];
        $fans = [];
        $fanList = is_array($chassis['fanRpmAvgList'] ?? null) ? $chassis['fanRpmAvgList'] : [];
        foreach ($fanList as $fan) {
            if (!is_array($fan)) {
                continue;
            }
            $fans[] = [
                'name' => trim((string)($fan['name'] ?? 'Fan')),
                'rpm' => isset($fan['rpm']) && is_numeric($fan['rpm']) ? (float)$fan['rpm'] : null,
            ];
        }
        return [
            'cpu_lina_pct' => isset($cpu['linaUsageAvg']) && is_numeric($cpu['linaUsageAvg']) ? (float)$cpu['linaUsageAvg'] : null,
            'cpu_snort_pct' => isset($cpu['snortUsageAvg']) && is_numeric($cpu['snortUsageAvg']) ? (float)$cpu['snortUsageAvg'] : null,
            'cpu_system_pct' => isset($cpu['systemUsageAvg']) && is_numeric($cpu['systemUsageAvg']) ? (float)$cpu['systemUsageAvg'] : null,
            'disk_pct' => isset($disk['totalDiskUsageAvg']) && is_numeric($disk['totalDiskUsageAvg']) ? (float)$disk['totalDiskUsageAvg'] : null,
            'fans' => $fans,
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function containsIp(array $item, string $ip): bool
    {
        foreach ($item as $key => $value) {
            if ($key === 'metadata' && is_array($value)) {
                foreach ($value as $inner) {
                    if (is_string($inner) && strtolower(trim($inner)) === $ip) {
                        return true;
                    }
                }
                continue;
            }
            if (is_string($value) && strtolower(trim($value)) === $ip) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{token:string,domain:string}
     */
    private static function authenticate(string $base, string $user, string $pass, bool $verify): array
    {
        $res = self::request('POST', $base . '/api/fmc_platform/v1/auth/generatetoken', null, [
            'Authorization: Basic ' . base64_encode($user . ':' . $pass),
        ], $verify);
        if ($res['code'] < 200 || $res['code'] >= 300) {
            throw new RuntimeException(self::httpError('Management center login failed', $res));
        }
        $token = $res['headers']['x-auth-access-token'] ?? '';
        $domain = $res['headers']['domain_uuid'] ?? '';
        if ($token === '' || $domain === '') {
            throw new RuntimeException('Management center login did not return a token.');
        }
        return ['token' => $token, 'domain' => $domain];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function deviceRecords(string $base, string $domain, string $token, bool $verify): array
    {
        $url = $base . '/api/fmc_config/v1/domain/' . rawurlencode($domain) . '/devices/devicerecords?limit=200&expanded=true';
        $items = [];
        for ($page = 0; $page < 4 && $url !== ''; $page++) {
            $res = self::request('GET', $url, null, self::authHeaders($token), $verify);
            if ($res['code'] < 200 || $res['code'] >= 300) {
                throw new RuntimeException(self::httpError('Management center device list failed', $res));
            }
            $json = json_decode($res['body'], true);
            if (!is_array($json)) {
                break;
            }
            $batch = is_array($json['items'] ?? null) ? $json['items'] : [];
            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $next = '';
            if (is_array($json['paging'] ?? null)) {
                $next = trim((string)($json['paging']['next'] ?? ''));
            }
            if ($next === '' || !str_starts_with($next, $base)) {
                break;
            }
            $url = $next;
        }
        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private static function aggregateMetrics(string $base, string $domain, string $token, string $deviceId, bool $verify): array
    {
        $filter = rawurlencode('device_uuid:' . $deviceId);
        $url = $base . '/api/fmc_config/v1/domain/' . rawurlencode($domain)
            . '/health/aggregatemetrics?filter=' . $filter . '&expanded=true';
        $res = self::request('GET', $url, null, self::authHeaders($token), $verify);
        if ($res['code'] < 200 || $res['code'] >= 300) {
            return [];
        }
        $json = json_decode($res['body'], true);
        return is_array($json) ? self::parseAggregate($json) : [];
    }

    /**
     * @return list<array{label:string,detail:string,status:string}>
     */
    private static function healthAlerts(string $base, string $domain, string $token, string $deviceId, bool $verify): array
    {
        $url = $base . '/api/fmc_config/v1/domain/' . rawurlencode($domain) . '/health/alerts?expanded=true&limit=50';
        $res = self::request('GET', $url, null, self::authHeaders($token), $verify);
        if ($res['code'] < 200 || $res['code'] >= 300) {
            return [];
        }
        $json = json_decode($res['body'], true);
        $items = is_array($json) && is_array($json['items'] ?? null) ? $json['items'] : [];
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $owner = (string)($item['deviceUUID'] ?? $item['deviceId'] ?? $item['device']['id'] ?? '');
            if ($owner !== '' && $owner !== $deviceId) {
                continue;
            }
            $status = strtolower(trim((string)($item['status'] ?? $item['severity'] ?? '')));
            if ($status === '' || in_array($status, ['green', 'normal', 'ok', 'healthy'], true)) {
                continue;
            }
            $label = trim((string)($item['module'] ?? $item['moduleId'] ?? $item['name'] ?? 'Health'));
            $detail = trim((string)($item['message'] ?? $item['description'] ?? $item['detail'] ?? ''));
            $out[] = [
                'label' => substr($label, 0, 80),
                'detail' => substr($detail, 0, 160),
                'status' => substr($status, 0, 40),
            ];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function authHeaders(string $token): array
    {
        return [
            'X-auth-access-token: ' . $token,
            'Accept: application/json',
        ];
    }

    /**
     * @param list<string> $headers
     * @return array{code:int,body:string,headers:array<string,string>}
     */
    private static function request(string $method, string $url, ?string $body, array $headers, bool $verify): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required to read a management center.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not start the management center request.');
        }
        $responseHeaders = [];
        $headers[] = 'User-Agent: ColdAisle-FMC/' . (class_exists('App') ? App::VERSION : '0');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trim = trim($line);
                $pos = strpos($trim, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(substr($trim, 0, $pos))] = trim(substr($trim, $pos + 1));
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            $hint = (stripos($err, 'certificate') !== false || stripos($err, 'SSL') !== false)
                ? ' Uncheck Verify TLS only for a management center that uses a private certificate.'
                : '';
            throw new RuntimeException(($err !== '' ? $err : 'Management center request failed') . $hint);
        }
        return ['code' => $code, 'body' => (string)$resp, 'headers' => $responseHeaders];
    }

    /** @param array{code:int,body:string,headers:array<string,string>} $res */
    private static function httpError(string $prefix, array $res): string
    {
        $json = json_decode($res['body'], true);
        $detail = '';
        if (is_array($json)) {
            $detail = trim((string)($json['error']['messages'][0]['description'] ?? $json['message'] ?? $json['error'] ?? ''));
        }
        if ($detail === '') {
            $detail = trim(substr(preg_replace('/\s+/', ' ', $res['body']) ?? '', 0, 160));
        }
        return $prefix . ' (HTTP ' . $res['code'] . ')' . ($detail !== '' ? ': ' . $detail : '');
    }
}
