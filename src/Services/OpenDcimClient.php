<?php
/**
 * HTTP client for openDCIM REST API (/api/v1).
 *
 * Auth: UserID + APIKey request headers (not Basic).
 */
declare(strict_types=1);

class OpenDcimClient
{
    private string $baseUrl;
    private string $userId;
    private string $apiKey;
    private bool $tlsVerify;
    private int $timeout;
    /** @var array<string,string> host => ip for CURLOPT_RESOLVE */
    private array $resolve;
    /** @var list<string> */
    private array $lastErrors = [];
    /** Offline mode: directory of api_v1_*.json dumps (no network). */
    private ?string $cacheDir = null;

    /**
     * @param array{
     *   base_url?:string,
     *   user_id?:string,
     *   api_key?:string,
     *   tls_verify?:bool,
     *   timeout?:int,
     *   resolve?:array<string,string>,
     *   cache_dir?:string
     * } $config
     */
    public function __construct(array $config)
    {
        $cacheDir = trim((string)($config['cache_dir'] ?? ''));
        if ($cacheDir !== '') {
            $cacheDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cacheDir), DIRECTORY_SEPARATOR);
            if (!is_dir($cacheDir)) {
                throw new InvalidArgumentException('OpenDCIM cache_dir does not exist: ' . $cacheDir);
            }
            $this->cacheDir = $cacheDir;
            $this->baseUrl = 'cache://' . $cacheDir;
            $this->userId = (string)($config['user_id'] ?? 'cache');
            $this->apiKey = (string)($config['api_key'] ?? 'cache');
            $this->tlsVerify = false;
            $this->timeout = 5;
            $this->resolve = [];
            return;
        }

        $base = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        if ($base === '') {
            throw new InvalidArgumentException('OpenDCIM base_url is required (or set cache_dir for offline mode).');
        }
        // Allow host without scheme
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $this->baseUrl = $base;
        $this->userId = trim((string)($config['user_id'] ?? ''));
        $this->apiKey = (string)($config['api_key'] ?? '');
        if ($this->userId === '' || $this->apiKey === '') {
            throw new InvalidArgumentException('OpenDCIM user_id and api_key are required.');
        }
        $this->tlsVerify = !array_key_exists('tls_verify', $config) || !empty($config['tls_verify']);
        $this->timeout = max(5, min(300, (int)($config['timeout'] ?? 60)));
        $this->resolve = is_array($config['resolve'] ?? null) ? $config['resolve'] : [];
    }

    public function isOfflineCache(): bool
    {
        return $this->cacheDir !== null;
    }

    public function cacheDir(): ?string
    {
        return $this->cacheDir;
    }

    /** @return list<string> */
    public function lastErrors(): array
    {
        return $this->lastErrors;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * GET /api/v1/{path} and return decoded JSON array.
     *
     * @param array<string,scalar|null> $query
     * @return array<string,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $path = '/' . ltrim($path, '/');
        if (!str_starts_with($path, '/api/')) {
            $path = '/api/v1' . $path;
        }

        if ($this->cacheDir !== null) {
            return $this->getFromCache($path, $query);
        }

        $url = $this->baseUrl . $path;
        if ($query) {
            $pairs = [];
            foreach ($query as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
            }
            if ($pairs) {
                $url .= (str_contains($url, '?') ? '&' : '?') . implode('&', $pairs);
            }
        }

        $raw = $this->request('GET', $url);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('OpenDCIM returned non-JSON for ' . $path . ': ' . substr($raw, 0, 200));
        }
        if (!empty($json['error']) && (int)($json['errorcode'] ?? 0) >= 400) {
            $msg = (string)($json['message'] ?? 'Access denied or API error');
            $code = (int)($json['errorcode'] ?? 0);
            throw new RuntimeException("OpenDCIM API error {$code}: {$msg} ({$path})");
        }
        return $json;
    }

    /**
     * Read offline dumps produced by probes / a future export.
     * Expected files: api_v1_datacenter.json, api_v1_device.json, …
     * Per-device: api_v1_powerport_{id}.json, cdu_{id}_powerport.json, etc.
     *
     * @param array<string,scalar|null> $query
     * @return array<string,mixed>
     */
    private function getFromCache(string $path, array $query = []): array
    {
        $path = '/' . ltrim($path, '/');
        // Strip query already separated
        $rel = preg_replace('#^/api/v1/?#', '', $path) ?? '';
        $rel = trim($rel, '/');

        // Collection endpoints
        $collections = [
            'datacenter' => 'datacenter',
            'department' => 'department',
            'cabinet' => 'cabinet',
            'device' => 'device',
            'devicetemplate' => 'devicetemplate',
            'people' => 'people',
            'project' => 'project',
            'disposition' => 'disposition',
            'audit' => 'audit',
        ];
        $base = strtolower(explode('/', $rel)[0] ?? '');
        if (isset($collections[$base]) && !str_contains($rel, '/')) {
            $file = $this->cacheDir . DIRECTORY_SEPARATOR . 'api_v1_' . $base . '.json';
            if (!is_file($file)) {
                // empty ok for optional collections
                return ['error' => false, 'errorcode' => 200, $collections[$base] => []];
            }
            $json = json_decode((string)file_get_contents($file), true);
            if (!is_array($json)) {
                throw new RuntimeException('Invalid cache JSON: ' . $file);
            }
            return $json;
        }

        // /device/{id}, /deviceport/{id}, /powerport/{id}
        if (preg_match('#^(device|deviceport|powerport)/([^/]+)$#i', $rel, $m)) {
            $kind = strtolower($m[1]);
            $id = rawurldecode($m[2]);
            $candidates = [
                $this->cacheDir . DIRECTORY_SEPARATOR . "api_v1_{$kind}_{$id}.json",
                $this->cacheDir . DIRECTORY_SEPARATOR . "detail__api_v1_{$kind}_{$id}.json",
                $this->cacheDir . DIRECTORY_SEPARATOR . "cdu_{$id}_{$kind}.json",
                $this->cacheDir . DIRECTORY_SEPARATOR . "cdu_{$id}_powerport.json",
            ];
            foreach ($candidates as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $json = json_decode((string)file_get_contents($file), true);
                if (is_array($json)) {
                    return $json;
                }
            }
            // Offline without per-device dumps: empty ports (inventory still imports)
            $wrap = $kind === 'device' ? 'device' : $kind;
            return ['error' => false, 'errorcode' => 200, $wrap => $kind === 'device' ? [] : []];
        }

        // /audit?CabinetID= / DeviceID=
        if (strtolower($rel) === 'audit' || str_starts_with(strtolower($rel), 'audit')) {
            $file = $this->cacheDir . DIRECTORY_SEPARATOR . 'api_v1_audit.json';
            if (is_file($file)) {
                $json = json_decode((string)file_get_contents($file), true);
                if (is_array($json)) {
                    return $json;
                }
            }
            $file = $this->cacheDir . DIRECTORY_SEPARATOR . 'audit_sample.json';
            if (is_file($file)) {
                $json = json_decode((string)file_get_contents($file), true);
                if (is_array($json)) {
                    return $json;
                }
            }
            return ['error' => false, 'errorcode' => 200, 'audit' => []];
        }

        throw new RuntimeException('Offline cache has no data for path: ' . $path);
    }

    /**
     * Unwrap standard openDCIM collection payload { error, errorcode, <name>: [...] }.
     *
     * @return list<array<string,mixed>>
     */
    public function collection(string $path, ?string $wrapKey = null): array
    {
        $json = $this->get($path);
        if ($wrapKey !== null) {
            $inner = $json[$wrapKey] ?? [];
            return is_array($inner) ? array_values($inner) : [];
        }
        foreach ($json as $k => $v) {
            if (in_array($k, ['error', 'errorcode', 'message', 'input'], true)) {
                continue;
            }
            if (is_array($v)) {
                // Associative map of id => row OR list
                if ($v === []) {
                    return [];
                }
                if (array_is_list($v)) {
                    return $v;
                }
                // Could be single object (has DeviceID etc.) or map of rows
                $first = reset($v);
                if (is_array($first) && !array_is_list($first) && $this->looksLikeEntityMap($v)) {
                    return array_values($v);
                }
                if (is_array($first) || is_scalar($first)) {
                    // single entity returned as field bag under wrap key
                    if (isset($v['DeviceID']) || isset($v['CabinetID']) || isset($v['TemplateID']) || isset($v['DataCenterID'])) {
                        return [$v];
                    }
                    return array_values($v);
                }
            }
        }
        return [];
    }

    /** @param array<mixed> $v */
    private function looksLikeEntityMap(array $v): bool
    {
        foreach ($v as $row) {
            if (!is_array($row)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Test connection; return inventory counts.
     *
     * @return array{ok:bool,base_url:string,counts:array<string,int>,errors:list<string>}
     */
    public function testConnection(): array
    {
        $counts = [];
        $errors = [];
        $map = [
            'datacenter' => 'datacenter',
            'department' => 'department',
            'cabinet' => 'cabinet',
            'device' => 'device',
            'devicetemplate' => 'devicetemplate',
            'people' => 'people',
            'project' => 'project',
            'disposition' => 'disposition',
        ];
        foreach ($map as $path => $wrap) {
            try {
                $rows = $this->collection('/api/v1/' . $path, $wrap);
                $counts[$path] = count($rows);
            } catch (Throwable $e) {
                $counts[$path] = -1;
                $errors[] = $path . ': ' . $e->getMessage();
            }
        }
        $ok = $counts['datacenter'] >= 0 && $errors === [];
        // Partial success still useful
        if ($counts['datacenter'] >= 0) {
            $ok = true;
        }
        return [
            'ok' => $ok,
            'base_url' => $this->baseUrl,
            'counts' => $counts,
            'errors' => $errors,
        ];
    }

    private function request(string $method, string $url): string
    {
        $this->lastErrors = [];
        if (!function_exists('curl_init')) {
            return $this->requestStream($method, $url);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        $headers = [
            'UserID: ' . $this->userId,
            'APIKey: ' . $this->apiKey,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'ColdAisle-OpenDcimClient/1.0',
        ];
        if (!$this->tlsVerify) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if ($this->resolve) {
            $resolve = [];
            $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: '';
            $port = (int)(parse_url($this->baseUrl, PHP_URL_PORT) ?: (str_starts_with($this->baseUrl, 'https') ? 443 : 80));
            foreach ($this->resolve as $h => $ip) {
                $resolve[] = $h . ':' . $port . ':' . $ip;
                if ($host !== '' && strcasecmp($h, $host) !== 0) {
                    // also bind configured base host if only IP alias given
                }
            }
            // Convenience: if resolve has IP for the base host name
            if ($host && isset($this->resolve[$host])) {
                $resolve[] = $host . ':' . $port . ':' . $this->resolve[$host];
            }
            if ($resolve) {
                $opts[CURLOPT_RESOLVE] = array_values(array_unique($resolve));
            }
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno) {
            $this->lastErrors[] = $err !== '' ? $err : 'curl error ' . $errno;
            // Fallback for environments where curl+schannel fails
            return $this->requestStream($method, $url);
        }
        if ($code === 401 || $code === 403) {
            $msg = $body;
            $j = json_decode((string)$body, true);
            if (is_array($j) && !empty($j['message'])) {
                $msg = (string)$j['message'];
            }
            throw new RuntimeException("OpenDCIM HTTP {$code}: {$msg}");
        }
        if ($code >= 400) {
            throw new RuntimeException("OpenDCIM HTTP {$code} for {$url}: " . substr((string)$body, 0, 300));
        }
        return (string)$body;
    }

    private function requestStream(string $method, string $url): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $scheme = $parts['scheme'] ?? 'https';
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $connectHost = $host;
        if ($host && isset($this->resolve[$host])) {
            $connectHost = $this->resolve[$host];
        }

        $hdr = "Host: {$host}\r\n";
        $hdr .= 'UserID: ' . $this->userId . "\r\n";
        $hdr .= 'APIKey: ' . $this->apiKey . "\r\n";
        $hdr .= "Accept: application/json\r\n";
        $hdr .= "User-Agent: ColdAisle-OpenDcimClient/1.0\r\n";

        $ssl = [
            'verify_peer' => $this->tlsVerify,
            'verify_peer_name' => $this->tlsVerify,
            'allow_self_signed' => !$this->tlsVerify,
            'peer_name' => $host,
        ];
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $hdr,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
            ],
            'ssl' => $ssl,
        ]);

        $target = $scheme . '://' . $connectHost;
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            $target .= ':' . $port;
        }
        $target .= $path;

        $body = @file_get_contents($target, false, $ctx);
        $statusLine = $http_response_header[0] ?? '';
        $code = preg_match('/\s(\d{3})\s/', $statusLine, $m) ? (int)$m[1] : 0;
        if ($body === false) {
            throw new RuntimeException('OpenDCIM request failed (stream) for ' . $url);
        }
        if ($code === 401 || $code === 403) {
            $j = json_decode($body, true);
            $msg = is_array($j) ? (string)($j['message'] ?? $body) : $body;
            throw new RuntimeException("OpenDCIM HTTP {$code}: {$msg}");
        }
        if ($code >= 400) {
            throw new RuntimeException("OpenDCIM HTTP {$code}: " . substr($body, 0, 300));
        }
        return $body;
    }
}
