<?php
/**
 * HTTP client for openDCIM REST API (/api/v1).
 *
 * Auth: UserID + APIKey request headers (not Basic).
 * Retries transient TLS/network failures (common on Windows Schannel + Apache renegotiation).
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
    private int $retries;

    /**
     * @param array{
     *   base_url?:string,
     *   user_id?:string,
     *   api_key?:string,
     *   tls_verify?:bool,
     *   timeout?:int,
     *   resolve?:array<string,string>,
     *   cache_dir?:string,
     *   retries?:int
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
            $this->retries = 0;
            return;
        }

        $base = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        if ($base === '') {
            throw new InvalidArgumentException('OpenDCIM base_url is required (or set cache_dir for offline mode).');
        }
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
        $this->timeout = max(10, min(300, (int)($config['timeout'] ?? 90)));
        $this->resolve = is_array($config['resolve'] ?? null) ? $config['resolve'] : [];
        $this->retries = max(0, min(5, (int)($config['retries'] ?? 3)));

        // If URL host is an IP, still allow Host header override via resolve keys
        $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: '';
        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) && empty($this->resolve)) {
            // connecting by IP with no SNI name — leave as-is
        }
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
     * Download a binary asset (e.g. /pictures/Dell_Front.png). Returns raw bytes or null.
     */
    public function downloadBinary(string $path): ?string
    {
        if ($this->cacheDir !== null) {
            $path = '/' . ltrim($path, '/');
            $local = $this->cacheDir . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
            // also try pictures/filename in cache root
            $base = basename($path);
            $candidates = [
                $local,
                $this->cacheDir . DIRECTORY_SEPARATOR . 'pictures' . DIRECTORY_SEPARATOR . $base,
                $this->cacheDir . DIRECTORY_SEPARATOR . $base,
            ];
            foreach ($candidates as $f) {
                if (is_file($f)) {
                    $b = @file_get_contents($f);
                    return $b === false ? null : $b;
                }
            }
            return null;
        }
        $path = '/' . ltrim($path, '/');
        $url = rtrim($this->baseUrl, '/') . $path;
        try {
            $raw = $this->requestWithRetry('GET', $url);
            return $raw !== '' ? $raw : null;
        } catch (Throwable $e) {
            $this->lastErrors[] = 'download ' . $path . ': ' . $e->getMessage();
            return null;
        }
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

        $raw = $this->requestWithRetry('GET', $url);
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
     * @param array<string,scalar|null> $query
     * @return array<string,mixed>
     */
    private function getFromCache(string $path, array $query = []): array
    {
        $path = '/' . ltrim($path, '/');
        $rel = preg_replace('#^/api/v1/?#', '', $path) ?? '';
        $rel = trim($rel, '/');

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
                return ['error' => false, 'errorcode' => 200, $collections[$base] => []];
            }
            $json = json_decode((string)file_get_contents($file), true);
            if (!is_array($json)) {
                throw new RuntimeException('Invalid cache JSON: ' . $file);
            }
            return $json;
        }

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
            $wrap = $kind === 'device' ? 'device' : $kind;
            return ['error' => false, 'errorcode' => 200, $wrap => []];
        }

        if (strtolower($rel) === 'audit' || str_starts_with(strtolower($rel), 'audit')) {
            foreach (['api_v1_audit.json', 'audit_sample.json'] as $name) {
                $file = $this->cacheDir . DIRECTORY_SEPARATOR . $name;
                if (is_file($file)) {
                    $json = json_decode((string)file_get_contents($file), true);
                    if (is_array($json)) {
                        return $json;
                    }
                }
            }
            return ['error' => false, 'errorcode' => 200, 'audit' => []];
        }

        throw new RuntimeException('Offline cache has no data for path: ' . $path);
    }

    /**
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
                if ($v === []) {
                    return [];
                }
                if (array_is_list($v)) {
                    return $v;
                }
                $first = reset($v);
                if (is_array($first) && !array_is_list($first) && $this->looksLikeEntityMap($v)) {
                    return array_values($v);
                }
                if (is_array($first) || is_scalar($first)) {
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
     * @return array{ok:bool,base_url:string,counts:array<string,int>,errors:list<string>,hint?:string}
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
        $ok = ($counts['datacenter'] ?? -1) >= 0;
        $out = [
            'ok' => $ok,
            'base_url' => $this->baseUrl,
            'counts' => $counts,
            'errors' => $errors,
        ];
        if (!$ok && $errors) {
            $joined = implode(' ', $errors);
            $hint = 'Check network path from this ColdAisle host to openDCIM, DNS, and that “Skip TLS verify” is enabled for lab certs.';
            if (str_contains($joined, 'resolve') || str_contains(strtolower($joined), 'could not resolve')) {
                $hint = 'Hostname did not resolve. Set DNS resolve to hostname:IP (e.g. dcim.example.org:192.0.2.10).';
            } elseif (str_contains($joined, 'stream') || str_contains($joined, 'curl') || str_contains($joined, 'SSL') || str_contains($joined, 'TLS')) {
                $hint = 'TLS/network glitch is common with openDCIM on Windows PHP. Retry Test; keep Skip TLS verify on; use DNS resolve if DNS is flaky. If it keeps failing, use Offline JSON dumps.';
            } elseif (str_contains($joined, '401') || str_contains($joined, '403') || str_contains($joined, 'Access Denied')) {
                $hint = 'Credentials rejected. Re-paste the API key (the field is cleared after each page load) and confirm UserID.';
            }
            $out['hint'] = $hint;
        }
        return $out;
    }

    private function requestWithRetry(string $method, string $url): string
    {
        $attempts = 1 + $this->retries;
        $lastEx = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                if ($i > 0) {
                    // brief backoff: 200ms, 500ms, 1000ms…
                    usleep((int)(200000 * $i * $i + 100000));
                }
                return $this->requestOnce($method, $url, $i > 0);
            } catch (Throwable $e) {
                $lastEx = $e;
                $msg = $e->getMessage();
                // Do not retry auth failures
                if (str_contains($msg, 'HTTP 401') || str_contains($msg, 'HTTP 403') || str_contains($msg, 'Access Denied')) {
                    throw $e;
                }
            }
        }
        throw $lastEx ?? new RuntimeException('OpenDCIM request failed for ' . $url);
    }

    private function requestOnce(string $method, string $url, bool $freshConnect): string
    {
        $this->lastErrors = [];
        $curlErr = null;
        if (function_exists('curl_init')) {
            try {
                return $this->requestCurl($method, $url, $freshConnect);
            } catch (Throwable $e) {
                $curlErr = $e;
                $this->lastErrors[] = 'curl: ' . $e->getMessage();
                // fall through to stream
            }
        }

        try {
            return $this->requestStream($method, $url);
        } catch (Throwable $e) {
            $bits = [];
            if ($curlErr) {
                $bits[] = 'curl: ' . $curlErr->getMessage();
            }
            $bits[] = 'stream: ' . $e->getMessage();
            if ($this->resolve) {
                $bits[] = 'resolve=' . json_encode($this->resolve);
            }
            throw new RuntimeException(
                'OpenDCIM request failed for ' . $url . ' (' . implode('; ', $bits) . ')'
            );
        }
    }

    private function requestCurl(string $method, string $url, bool $freshConnect): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        $headers = [
            'UserID: ' . $this->userId,
            'APIKey: ' . $this->apiKey,
            'Accept: application/json',
            'Connection: close',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(20, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'ColdAisle-OpenDcimClient/1.1',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => '',
        ];
        if ($freshConnect) {
            $opts[CURLOPT_FRESH_CONNECT] = true;
            $opts[CURLOPT_FORBID_REUSE] = true;
        }
        if (!$this->tlsVerify) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        // Prefer TLS 1.2+ when constant exists (avoids some renegotiation edge cases)
        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $opts[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        $resolveList = $this->buildCurlResolve();
        if ($resolveList) {
            $opts[CURLOPT_RESOLVE] = $resolveList;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno) {
            throw new RuntimeException(
                ($err !== '' ? $err : 'curl error ' . $errno) . ' (errno ' . $errno . ')'
            );
        }
        // Empty body with code 0 often means connection reset mid-TLS
        if ($code === 0) {
            throw new RuntimeException('empty response / TLS reset (HTTP 0)');
        }
        if ($code === 401 || $code === 403) {
            $j = json_decode((string)$body, true);
            $msg = is_array($j) && !empty($j['message']) ? (string)$j['message'] : (string)$body;
            throw new RuntimeException("HTTP {$code}: {$msg}");
        }
        if ($code >= 400) {
            throw new RuntimeException("HTTP {$code}: " . substr((string)$body, 0, 300));
        }
        return (string)$body;
    }

    /** @return list<string> */
    private function buildCurlResolve(): array
    {
        if (!$this->resolve) {
            return [];
        }
        $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: '';
        $port = (int)(parse_url($this->baseUrl, PHP_URL_PORT) ?: (str_starts_with($this->baseUrl, 'https') ? 443 : 80));
        $out = [];
        foreach ($this->resolve as $h => $ip) {
            $h = trim((string)$h);
            $ip = trim((string)$ip);
            if ($h === '' || $ip === '') {
                continue;
            }
            $out[] = $h . ':' . $port . ':' . $ip;
            // Also map the base URL host if resolve key is only the hostname form
            if ($host !== '' && strcasecmp($h, $host) !== 0 && filter_var($ip, FILTER_VALIDATE_IP)) {
                // if user entered only IP mapping under a different name, still ok
            }
        }
        if ($host !== '' && isset($this->resolve[$host])) {
            $out[] = $host . ':' . $port . ':' . $this->resolve[$host];
        }
        // If single resolve entry and host differs, also bind base host to that IP
        if ($host !== '' && count($this->resolve) === 1) {
            $ip = (string)reset($this->resolve);
            if (filter_var($ip, FILTER_VALIDATE_IP) && !isset($this->resolve[$host])) {
                $out[] = $host . ':' . $port . ':' . $ip;
            }
        }
        return array_values(array_unique($out));
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
        } elseif (count($this->resolve) === 1) {
            $onlyIp = (string)reset($this->resolve);
            if (filter_var($onlyIp, FILTER_VALIDATE_IP)) {
                $connectHost = $onlyIp;
            }
        }

        $hdr = "Host: {$host}\r\n";
        $hdr .= 'UserID: ' . $this->userId . "\r\n";
        $hdr .= 'APIKey: ' . $this->apiKey . "\r\n";
        $hdr .= "Accept: application/json\r\n";
        $hdr .= "Connection: close\r\n";
        $hdr .= "User-Agent: ColdAisle-OpenDcimClient/1.1\r\n";

        $ssl = [
            'verify_peer' => $this->tlsVerify,
            'verify_peer_name' => $this->tlsVerify,
            'allow_self_signed' => !$this->tlsVerify,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'capture_peer_cert' => false,
        ];
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $ssl['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $hdr,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'protocol_version' => 1.1,
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
            $err = error_get_last();
            $detail = is_array($err) ? (string)($err['message'] ?? '') : '';
            throw new RuntimeException(
                'stream open failed'
                . ($detail !== '' ? ': ' . $detail : '')
                . " (connect={$connectHost} host={$host})"
            );
        }
        if ($code === 401 || $code === 403) {
            $j = json_decode($body, true);
            $msg = is_array($j) ? (string)($j['message'] ?? $body) : $body;
            throw new RuntimeException("HTTP {$code}: {$msg}");
        }
        if ($code >= 400) {
            throw new RuntimeException("HTTP {$code}: " . substr($body, 0, 300));
        }
        if ($code === 0 && $body === '') {
            throw new RuntimeException('empty stream response');
        }
        return $body;
    }
}
