<?php
/**
 * ColdAisle — outbound email via SMTP (no Composer dependencies).
 *
 * Supports: plain TCP, STARTTLS (typically 587), implicit SSL/TLS (465),
 * AUTH none / LOGIN / PLAIN. Used by Settings test mail and future notifications.
 */
declare(strict_types=1);

class MailService
{
    /** @return array<string,mixed> */
    public static function config(): array
    {
        $c = App::config('mail');
        if (!is_array($c)) {
            $c = [];
        }
        $enc = strtolower(trim((string)($c['encryption'] ?? 'tls')));
        if (!in_array($enc, ['none', 'tls', 'ssl'], true)) {
            $enc = 'tls';
        }
        $authMode = strtolower(trim((string)($c['auth_mode'] ?? '')));
        if (!in_array($authMode, ['none', 'login', 'plain'], true)) {
            // Fall back to boolean auth flag from older/sample configs
            $authMode = !empty($c['auth']) ? 'login' : 'none';
        }

        $port = (int)($c['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $port = $enc === 'ssl' ? 465 : ($enc === 'tls' ? 587 : 25);
        }

        return [
            'enabled' => !empty($c['enabled']),
            'host' => trim((string)($c['host'] ?? '')),
            'port' => $port,
            'encryption' => $enc,
            'auth_mode' => $authMode,
            'username' => (string)($c['username'] ?? ''),
            'password' => (string)($c['password'] ?? ''),
            'from_email' => trim((string)($c['from_email'] ?? '')),
            'from_name' => trim((string)($c['from_name'] ?? App::APP_NAME)),
            'reply_to' => trim((string)($c['reply_to'] ?? '')),
            'timeout' => max(5, min(120, (int)($c['timeout'] ?? 30))),
            'verify_peer' => array_key_exists('verify_peer', $c) ? !empty($c['verify_peer']) : true,
        ];
    }

    /** Defaults for writing config.php / sample. */
    public static function defaultConfig(): array
    {
        return [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'encryption' => 'tls', // none | tls (STARTTLS) | ssl (implicit TLS)
            'auth' => true,
            'auth_mode' => 'login', // none | login | plain
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => App::APP_NAME,
            'reply_to' => '',
            'timeout' => 30,
            'verify_peer' => true,
        ];
    }

    public static function isEnabled(): bool
    {
        $c = self::config();
        return !empty($c['enabled']) && self::isConfigured($c);
    }

    /** @param array<string,mixed>|null $c */
    public static function isConfigured(?array $c = null): bool
    {
        $c = $c ?? self::config();
        if (trim((string)($c['host'] ?? '')) === '') {
            return false;
        }
        if (trim((string)($c['from_email'] ?? '')) === '' || !filter_var($c['from_email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $mode = (string)($c['auth_mode'] ?? 'none');
        if ($mode !== 'none' && trim((string)($c['username'] ?? '')) === '') {
            return false;
        }
        return true;
    }

    /**
     * Status for Settings UI badge.
     * @return array{ready:bool,enabled:bool,label:string,detail:string}
     */
    public static function status(): array
    {
        $c = self::config();
        if (empty($c['enabled'])) {
            return [
                'ready' => false,
                'enabled' => false,
                'label' => 'Disabled',
                'detail' => 'Outbound mail is off. Enable and save SMTP settings when ready.',
            ];
        }
        if (!self::isConfigured($c)) {
            return [
                'ready' => false,
                'enabled' => true,
                'label' => 'Incomplete',
                'detail' => 'Host and a valid From address are required'
                    . (($c['auth_mode'] ?? 'none') !== 'none' ? '; username required when authentication is on.' : '.'),
            ];
        }
        $enc = match ((string)$c['encryption']) {
            'ssl' => 'SSL/TLS',
            'tls' => 'STARTTLS',
            default => 'no encryption',
        };
        $auth = match ((string)$c['auth_mode']) {
            'login' => 'AUTH LOGIN',
            'plain' => 'AUTH PLAIN',
            default => 'no auth',
        };
        return [
            'ready' => true,
            'enabled' => true,
            'label' => 'Ready',
            'detail' => $c['host'] . ':' . $c['port'] . ' · ' . $enc . ' · ' . $auth,
        ];
    }

    /**
     * Send a simple text and/or HTML message.
     *
     * @param string|list<string> $to
     * @param array{
     *   html?:string,
     *   text?:string,
     *   reply_to?:string,
     *   cc?:string|list<string>,
     *   bcc?:string|list<string>,
     *   headers?:array<string,string>
     * } $options
     * @return array{ok:bool,message:string}
     */
    public static function send(string|array $to, string $subject, array $options = []): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) {
            return ['ok' => false, 'message' => 'Outbound mail is disabled in Settings → Email (SMTP).'];
        }
        if (!self::isConfigured($cfg)) {
            return ['ok' => false, 'message' => 'SMTP is not fully configured (host / from address).'];
        }

        $recipients = self::normalizeAddresses($to);
        if (!$recipients) {
            return ['ok' => false, 'message' => 'No valid recipient address.'];
        }
        $cc = self::normalizeAddresses($options['cc'] ?? []);
        $bcc = self::normalizeAddresses($options['bcc'] ?? []);
        $allRcpt = array_values(array_unique(array_merge($recipients, $cc, $bcc)));

        $text = isset($options['text']) ? (string)$options['text'] : '';
        $html = isset($options['html']) ? (string)$options['html'] : '';
        if ($text === '' && $html === '') {
            return ['ok' => false, 'message' => 'Message body is empty.'];
        }
        if ($text === '' && $html !== '') {
            $text = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $fromEmail = $cfg['from_email'];
        $fromName = $cfg['from_name'] !== '' ? $cfg['from_name'] : App::APP_NAME;
        $replyTo = trim((string)($options['reply_to'] ?? $cfg['reply_to'] ?? ''));
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = '';
        }

        try {
            $socket = self::connect($cfg);
            self::expect($socket, [220], 'connect');
            $ehloHost = self::ehloName();
            self::command($socket, 'EHLO ' . $ehloHost, [250]);

            if (($cfg['encryption'] ?? '') === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!self::enableCrypto($socket, $cfg)) {
                    self::quit($socket);
                    return ['ok' => false, 'message' => 'STARTTLS negotiation failed.'];
                }
                self::command($socket, 'EHLO ' . $ehloHost, [250]);
            }

            if (($cfg['auth_mode'] ?? 'none') !== 'none') {
                self::authenticate($socket, $cfg);
            }

            self::command($socket, 'MAIL FROM:<' . self::addrOnly($fromEmail) . '>', [250]);
            foreach ($allRcpt as $rcpt) {
                self::command($socket, 'RCPT TO:<' . self::addrOnly($rcpt) . '>', [250, 251]);
            }
            self::command($socket, 'DATA', [354]);

            $message = self::buildMime(
                $fromEmail,
                $fromName,
                $recipients,
                $cc,
                $subject,
                $text,
                $html,
                $replyTo,
                is_array($options['headers'] ?? null) ? $options['headers'] : []
            );
            // Dot-stuffing
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            fwrite($socket, $message . "\r\n.\r\n");
            self::expect($socket, [250], 'DATA end');
            self::quit($socket);

            return [
                'ok' => true,
                'message' => 'Message accepted for delivery to ' . implode(', ', $recipients) . '.',
            ];
        } catch (Throwable $e) {
            App::log('MailService send failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send a test message using current (or override) config.
     *
     * @param array<string,mixed>|null $cfgOverride form values before save
     * @return array{ok:bool,message:string}
     */
    public static function sendTest(string $to, ?array $cfgOverride = null): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter a valid test recipient email address.'];
        }

        // Temporarily merge override into App config for this request
        if ($cfgOverride !== null) {
            $prev = App::config();
            if (!is_array($prev)) {
                $prev = [];
            }
            $merged = array_merge(self::defaultConfig(), is_array($prev['mail'] ?? null) ? $prev['mail'] : [], $cfgOverride);
            // Force enabled for test so admins can test before enabling
            $merged['enabled'] = true;
            // Use reflection-free approach: pass via static? Better: direct send with internal cfg
            return self::sendWithConfig($merged, $to, 'ColdAisle SMTP test', [
                'text' => self::testBodyText(),
                'html' => self::testBodyHtml(),
            ]);
        }

        $cfg = self::config();
        if (!self::isConfigured($cfg)) {
            return ['ok' => false, 'message' => 'SMTP is not fully configured. Save host and From address first.'];
        }
        $cfg['enabled'] = true;
        return self::sendWithConfig($cfg, $to, 'ColdAisle SMTP test', [
            'text' => self::testBodyText(),
            'html' => self::testBodyHtml(),
        ]);
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array{html?:string,text?:string,reply_to?:string} $options
     * @return array{ok:bool,message:string}
     */
    private static function sendWithConfig(array $cfg, string|array $to, string $subject, array $options): array
    {
        // Normalize cfg the same way as config()
        $norm = self::normalizeConfigArray($cfg);
        if (!self::isConfigured($norm)) {
            return ['ok' => false, 'message' => 'SMTP is not fully configured (host / from address'
                . (($norm['auth_mode'] ?? 'none') !== 'none' ? ' / username' : '') . ').'];
        }

        $recipients = self::normalizeAddresses($to);
        if (!$recipients) {
            return ['ok' => false, 'message' => 'No valid recipient address.'];
        }

        $text = (string)($options['text'] ?? '');
        $html = (string)($options['html'] ?? '');
        if ($text === '' && $html === '') {
            $text = 'ColdAisle mail test.';
        }
        if ($text === '' && $html !== '') {
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $fromEmail = $norm['from_email'];
        $fromName = $norm['from_name'] !== '' ? $norm['from_name'] : App::APP_NAME;
        $replyTo = trim((string)($options['reply_to'] ?? $norm['reply_to'] ?? ''));

        try {
            $socket = self::connect($norm);
            self::expect($socket, [220], 'connect');
            $ehloHost = self::ehloName();
            self::command($socket, 'EHLO ' . $ehloHost, [250]);

            if (($norm['encryption'] ?? '') === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!self::enableCrypto($socket, $norm)) {
                    self::quit($socket);
                    return ['ok' => false, 'message' => 'STARTTLS negotiation failed.'];
                }
                self::command($socket, 'EHLO ' . $ehloHost, [250]);
            }

            if (($norm['auth_mode'] ?? 'none') !== 'none') {
                self::authenticate($socket, $norm);
            }

            self::command($socket, 'MAIL FROM:<' . self::addrOnly($fromEmail) . '>', [250]);
            foreach ($recipients as $rcpt) {
                self::command($socket, 'RCPT TO:<' . self::addrOnly($rcpt) . '>', [250, 251]);
            }
            self::command($socket, 'DATA', [354]);

            $message = self::buildMime(
                $fromEmail,
                $fromName,
                $recipients,
                [],
                $subject,
                $text,
                $html,
                $replyTo,
                []
            );
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            fwrite($socket, $message . "\r\n.\r\n");
            self::expect($socket, [250], 'DATA end');
            self::quit($socket);

            return [
                'ok' => true,
                'message' => 'Test message sent to ' . implode(', ', $recipients) . '.',
            ];
        } catch (Throwable $e) {
            App::log('MailService test failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private static function normalizeConfigArray(array $c): array
    {
        $enc = strtolower(trim((string)($c['encryption'] ?? 'tls')));
        if (!in_array($enc, ['none', 'tls', 'ssl'], true)) {
            $enc = 'tls';
        }
        $authMode = strtolower(trim((string)($c['auth_mode'] ?? 'none')));
        if (!in_array($authMode, ['none', 'login', 'plain'], true)) {
            $authMode = !empty($c['auth']) ? 'login' : 'none';
        }
        $port = (int)($c['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $port = $enc === 'ssl' ? 465 : ($enc === 'tls' ? 587 : 25);
        }
        return [
            'enabled' => !empty($c['enabled']),
            'host' => trim((string)($c['host'] ?? '')),
            'port' => $port,
            'encryption' => $enc,
            'auth_mode' => $authMode,
            'username' => (string)($c['username'] ?? ''),
            'password' => (string)($c['password'] ?? ''),
            'from_email' => trim((string)($c['from_email'] ?? '')),
            'from_name' => trim((string)($c['from_name'] ?? App::APP_NAME)),
            'reply_to' => trim((string)($c['reply_to'] ?? '')),
            'timeout' => max(5, min(120, (int)($c['timeout'] ?? 30))),
            'verify_peer' => array_key_exists('verify_peer', $c) ? !empty($c['verify_peer']) : true,
        ];
    }

    private static function testBodyText(): string
    {
        $host = gethostname() ?: 'server';
        $when = date('c');
        return "This is a test message from ColdAisle.\r\n\r\n"
            . "Host: {$host}\r\n"
            . "Time: {$when}\r\n"
            . "Version: " . App::VERSION . "\r\n\r\n"
            . "If you received this, SMTP settings are working.\r\n";
    }

    private static function testBodyHtml(): string
    {
        $host = htmlspecialchars(gethostname() ?: 'server', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $when = htmlspecialchars(date('c'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ver = htmlspecialchars(App::VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!DOCTYPE html><html><body style="font-family:system-ui,sans-serif;line-height:1.5">'
            . '<h2 style="margin:0 0 .5rem">ColdAisle SMTP test</h2>'
            . '<p>This is a test message from <strong>ColdAisle</strong>.</p>'
            . '<ul>'
            . '<li><strong>Host:</strong> ' . $host . '</li>'
            . '<li><strong>Time:</strong> ' . $when . '</li>'
            . '<li><strong>Version:</strong> ' . $ver . '</li>'
            . '</ul>'
            . '<p style="color:#64748b;font-size:.9rem">If you received this, SMTP settings are working.</p>'
            . '</body></html>';
    }

    /**
     * @param array<string,mixed> $cfg
     * @return resource
     */
    private static function connect(array $cfg)
    {
        $host = (string)$cfg['host'];
        $port = (int)$cfg['port'];
        $timeout = (int)$cfg['timeout'];
        $enc = (string)$cfg['encryption'];

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => self::sslOptions($cfg),
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            throw new RuntimeException(
                'Could not connect to SMTP server ' . $host . ':' . $port
                . ($errstr !== '' ? " ({$errstr})" : '')
                . ($errno ? " [#{$errno}]" : '') . '.'
            );
        }
        stream_set_timeout($socket, $timeout);
        return $socket;
    }

    /** @param array<string,mixed> $cfg @return array<string,mixed> */
    private static function sslOptions(array $cfg): array
    {
        $verify = !empty($cfg['verify_peer']);
        $opts = [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if ($verify) {
            $ca = self::caBundlePath();
            if ($ca !== null) {
                $opts['cafile'] = $ca;
            }
        }
        $peer = (string)($cfg['host'] ?? '');
        if ($peer !== '') {
            $opts['peer_name'] = $peer;
        }
        return $opts;
    }

    private static function caBundlePath(): ?string
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

    /** @param resource $socket @param array<string,mixed> $cfg */
    private static function enableCrypto($socket, array $cfg): bool
    {
        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        // Apply SSL context options where possible
        $opts = self::sslOptions($cfg);
        foreach ($opts as $k => $v) {
            @stream_context_set_option($socket, 'ssl', $k, $v);
        }
        $ok = @stream_socket_enable_crypto($socket, true, $crypto);
        return $ok === true;
    }

    /** @param resource $socket @param array<string,mixed> $cfg */
    private static function authenticate($socket, array $cfg): void
    {
        $user = (string)$cfg['username'];
        $pass = (string)$cfg['password'];
        $mode = (string)($cfg['auth_mode'] ?? 'login');

        if ($user === '') {
            throw new RuntimeException('SMTP authentication is enabled but username is empty.');
        }

        if ($mode === 'plain') {
            self::command($socket, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235]);
            return;
        }

        // LOGIN (default)
        self::command($socket, 'AUTH LOGIN', [334]);
        self::command($socket, base64_encode($user), [334]);
        self::command($socket, base64_encode($pass), [235]);
    }

    /**
     * @param resource $socket
     * @param list<int> $expectCodes
     */
    private static function command($socket, string $line, array $expectCodes): string
    {
        fwrite($socket, $line . "\r\n");
        return self::expect($socket, $expectCodes, $line);
    }

    /**
     * @param resource $socket
     * @param list<int> $expectCodes
     */
    private static function expect($socket, array $expectCodes, string $context): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // Multi-line: "250-..." continues; "250 " ends
            if (preg_match('/^\d{3}[\s]/', $line)) {
                break;
            }
        }
        if ($response === '') {
            throw new RuntimeException('SMTP server closed the connection during ' . $context . '.');
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectCodes, true)) {
            $msg = trim(preg_replace('/^\d{3}[-\s]?/m', '', $response) ?? $response);
            throw new RuntimeException(
                'SMTP error during ' . $context . ' (code ' . $code . '): '
                . ($msg !== '' ? $msg : trim($response))
            );
        }
        return $response;
    }

    /** @param resource $socket */
    private static function quit($socket): void
    {
        try {
            @fwrite($socket, "QUIT\r\n");
            @fgets($socket, 515);
        } catch (Throwable $e) {
            // ignore
        }
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }

    private static function ehloName(): string
    {
        $name = gethostname();
        if (!is_string($name) || $name === '') {
            $name = 'coldaisle.local';
        }
        // EHLO argument should be a hostname without spaces
        return preg_replace('/\s+/', '', $name) ?: 'coldaisle.local';
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param array<string,string> $extraHeaders
     */
    private static function buildMime(
        string $fromEmail,
        string $fromName,
        array $to,
        array $cc,
        string $subject,
        string $text,
        string $html,
        string $replyTo,
        array $extraHeaders
    ): string {
        $boundary = 'ca_' . bin2hex(random_bytes(12));
        $date = date('r');
        $messageId = sprintf(
            '<%s@%s>',
            bin2hex(random_bytes(16)),
            preg_replace('/[^a-zA-Z0-9.-]/', '', self::ehloName()) ?: 'coldaisle.local'
        );

        $headers = [];
        $headers[] = 'Date: ' . $date;
        $headers[] = 'Message-ID: ' . $messageId;
        $headers[] = 'From: ' . self::formatAddress($fromEmail, $fromName);
        $headers[] = 'To: ' . implode(', ', array_map(static fn($a) => self::formatAddress($a, ''), $to));
        if ($cc) {
            $headers[] = 'Cc: ' . implode(', ', array_map(static fn($a) => self::formatAddress($a, ''), $cc));
        }
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . self::formatAddress($replyTo, '');
        }
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: ColdAisle/' . App::VERSION;

        foreach ($extraHeaders as $k => $v) {
            $k = trim((string)$k);
            $v = trim((string)$v);
            if ($k === '' || $v === '' || preg_match('/[\r\n]/', $k . $v)) {
                continue;
            }
            // Prevent overriding critical headers
            if (preg_match('/^(from|to|cc|bcc|subject|date|message-id|mime-version)$/i', $k)) {
                continue;
            }
            $headers[] = $k . ': ' . $v;
        }

        if ($html !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($text !== '' ? $text : strip_tags($html)))
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html))
                . '--' . $boundary . "--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($text));
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function formatAddress(string $email, string $name): string
    {
        $email = self::addrOnly($email);
        $name = trim($name);
        if ($name === '') {
            return $email;
        }
        if (preg_match('/^[\x20-\x7E]*$/', $name) && !preg_match('/[,"\\\\]/', $name)) {
            return '"' . $name . '" <' . $email . '>';
        }
        return self::encodeHeader($name) . ' <' . $email . '>';
    }

    private static function addrOnly(string $email): string
    {
        $email = trim($email);
        if (preg_match('/<([^>]+)>/', $email, $m)) {
            $email = trim($m[1]);
        }
        return $email;
    }

    /**
     * @param string|list<string> $list
     * @return list<string>
     */
    private static function normalizeAddresses(string|array $list): array
    {
        if (is_string($list)) {
            $list = preg_split('/[,;]+/', $list) ?: [];
        }
        $out = [];
        foreach ($list as $a) {
            $a = self::addrOnly((string)$a);
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) {
                $out[] = $a;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Build mail config array from Settings POST (keeps password if blank).
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    public static function configFromPost(array $post, array $existing = []): array
    {
        $enc = strtolower(trim((string)($post['mail_encryption'] ?? 'tls')));
        if (!in_array($enc, ['none', 'tls', 'ssl'], true)) {
            $enc = 'tls';
        }
        $authMode = strtolower(trim((string)($post['mail_auth_mode'] ?? 'login')));
        if (!in_array($authMode, ['none', 'login', 'plain'], true)) {
            $authMode = 'login';
        }

        $port = (int)($post['mail_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $port = $enc === 'ssl' ? 465 : ($enc === 'tls' ? 587 : 25);
        }

        $password = (string)($post['mail_password'] ?? '');
        if ($password === '') {
            $password = (string)($existing['password'] ?? '');
        }

        $fromEmail = trim((string)($post['mail_from_email'] ?? ''));
        $fromName = trim((string)($post['mail_from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = App::APP_NAME;
        }

        return [
            'enabled' => !empty($post['mail_enabled']),
            'host' => trim((string)($post['mail_host'] ?? '')),
            'port' => $port,
            'encryption' => $enc,
            'auth' => $authMode !== 'none',
            'auth_mode' => $authMode,
            'username' => trim((string)($post['mail_username'] ?? '')),
            'password' => $password,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to' => trim((string)($post['mail_reply_to'] ?? '')),
            'timeout' => max(5, min(120, (int)($post['mail_timeout'] ?? 30))),
            'verify_peer' => !empty($post['mail_verify_peer']),
        ];
    }
}
