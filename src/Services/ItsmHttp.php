<?php
/**
 * Shared outbound HTTPS for ITSM providers (no public inbound URL required).
 */
declare(strict_types=1);

class ItsmHttp
{
    /**
     * @param list<string> $headers
     * @return array{code:int,body:string}
     */
    public static function request(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        bool $tlsVerify,
        string $ua = 'ColdAisle-ITSM'
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for ticketing integrations.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not start HTTP request.');
        }
        $headers[] = 'User-Agent: ' . $ua . '/' . App::VERSION;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];
        if ($body !== null && strtoupper($method) !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        $opts[CURLOPT_SSL_VERIFYPEER] = $tlsVerify;
        $opts[CURLOPT_SSL_VERIFYHOST] = $tlsVerify ? 2 : 0;
        if ($tlsVerify) {
            $ca = self::caBundle();
            if ($ca !== null) {
                $opts[CURLOPT_CAINFO] = $ca;
            }
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            $hint = '';
            if (stripos($err, 'certificate') !== false || stripos($err, 'SSL') !== false) {
                $hint = ' Install CA certificates under Settings → Updates, or uncheck Verify TLS for lab only.';
            }
            throw new RuntimeException(($err !== '' ? $err : 'HTTP request failed') . $hint);
        }
        return ['code' => $code, 'body' => (string)$resp];
    }

    /**
     * JSON REST helper. Throws on HTTP >= 400 with a vendor-ish message.
     *
     * @param list<string> $headers
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    public static function json(
        string $method,
        string $url,
        ?array $payload,
        array $headers,
        bool $tlsVerify,
        string $ua = 'ColdAisle-ITSM'
    ): array {
        $hasCt = false;
        foreach ($headers as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $hasCt = true;
                break;
            }
        }
        $body = null;
        if ($payload !== null) {
            if (!$hasCt) {
                $headers[] = 'Content-Type: application/json';
            }
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $hasAccept = false;
        foreach ($headers as $h) {
            if (stripos($h, 'Accept:') === 0) {
                $hasAccept = true;
                break;
            }
        }
        if (!$hasAccept) {
            $headers[] = 'Accept: application/json';
        }
        $raw = self::request($method, $url, $body, $headers, $tlsVerify, $ua);
        $json = json_decode($raw['body'], true);
        if ($raw['code'] >= 400) {
            throw new RuntimeException(self::errorMessage($json, $raw['code'], $raw['body']));
        }
        if ($raw['body'] === '' || $raw['body'] === 'null') {
            return [];
        }
        if (!is_array($json)) {
            $snip = trim(substr($raw['body'], 0, 180));
            throw new RuntimeException('Ticketing HTTP ' . $raw['code'] . ($snip !== '' ? ': ' . $snip : ''));
        }
        return $json;
    }

    public static function basicAuth(string $user, string $pass): string
    {
        return 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
    }

    public static function httpsOrigin(string $raw): string
    {
        $u = trim($raw);
        if ($u === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $u)) {
            $u = 'https://' . $u;
        }
        $p = parse_url($u);
        $host = (string)($p['host'] ?? '');
        if ($host === '') {
            throw new RuntimeException('Enter a valid https host.');
        }
        $scheme = strtolower((string)($p['scheme'] ?? 'https'));
        if ($scheme !== 'https') {
            throw new RuntimeException('Ticketing URLs must use https://');
        }
        $port = isset($p['port']) ? ':' . (int)$p['port'] : '';
        return 'https://' . $host . $port;
    }

    /** @param mixed $json */
    private static function errorMessage($json, int $code, string $raw): string
    {
        if (is_array($json)) {
            if (isset($json['error']) && is_array($json['error'])) {
                $m = (string)($json['error']['message'] ?? $json['error']['title'] ?? '');
                if ($m !== '') {
                    return $m;
                }
            }
            if (!empty($json['error']) && is_string($json['error'])) {
                $d = $json['description'] ?? $json['details'] ?? '';
                return $json['error'] . ($d !== '' && is_string($d) ? ': ' . $d : '');
            }
            if (!empty($json['errorMessages']) && is_array($json['errorMessages'])) {
                return (string)$json['errorMessages'][0];
            }
            if (!empty($json['errors']) && is_array($json['errors'])) {
                $first = reset($json['errors']);
                if (is_string($first)) {
                    $k = (string)array_key_first($json['errors']);
                    return ($k !== '' ? $k . ': ' : '') . $first;
                }
                if (is_array($first) && isset($first[0]) && is_array($first[0])) {
                    return (string)($first[0]['message'] ?? 'API error');
                }
            }
            if (!empty($json['message']) && is_string($json['message'])) {
                return $json['message'];
            }
            if (!empty($json['description']) && is_string($json['description'])) {
                return $json['description'];
            }
        }
        $snip = trim(substr($raw, 0, 180));
        return 'Ticketing HTTP ' . $code . ($snip !== '' ? ': ' . $snip : '');
    }

    public static function caBundle(): ?string
    {
        $candidates = [
            App::ROOT . '/config/cacert.pem',
            (string)ini_get('curl.cainfo'),
            (string)ini_get('openssl.cafile'),
            'C:/PHP/extras/ssl/cacert.pem',
            'C:/php/extras/ssl/cacert.pem',
        ];
        foreach ($candidates as $p) {
            $p = trim($p);
            if ($p !== '' && is_file($p)) {
                return $p;
            }
        }
        return null;
    }
}
