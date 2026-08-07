<?php
/**
 * ColdAisle — ICMP (ping) reachability monitoring for devices and PDUs.
 *
 * Industry-aligned defaults (aligned with common NMS practice — LibreNMS / Zabbix / PRTG):
 *   - Probe interval: same Windows task tick as SNMP (typically 60–300s)
 *   - Packets per check: 3
 *   - Per-packet timeout: 1000 ms
 *   - Loss ≥ 100% of probes → failure for this check
 *   - Consecutive failures before DOWN: 3 (debounce flapping)
 *   - Consecutive successes before recovering to UP: 1 (fast recovery after confirmed down)
 *   - Optional email on state change (down / recovered)
 */
declare(strict_types=1);

class IcmpMonitorService
{
    public const SETTING_ENABLED = 'icmp_monitor_enabled';
    public const SETTING_ALERTS = 'icmp_alerts_enabled';
    public const SETTING_EMAIL = 'icmp_alerts_email';
    public const SETTING_CONSEC_DOWN = 'icmp_consec_fail';
    public const SETTING_PACKETS = 'icmp_packets';
    public const SETTING_TIMEOUT_MS = 'icmp_timeout_ms';
    public const SETTING_COOLDOWN_MIN = 'icmp_alert_cooldown_min';

    /** Default: 3 consecutive failed checks before marking DOWN (and alerting). */
    public const DEFAULT_CONSEC_DOWN = 3;
    /** Default: 3 echo requests per check. */
    public const DEFAULT_PACKETS = 3;
    /** Default: 1000 ms wait per echo (Windows ping -w). */
    public const DEFAULT_TIMEOUT_MS = 1000;
    /** Min minutes between repeat DOWN emails for the same target. */
    public const DEFAULT_COOLDOWN_MIN = 60;

    /**
     * @return array{
     *   enabled:bool,alerts:bool,email:string,consec_down:int,packets:int,timeout_ms:int,cooldown_min:int
     * }
     */
    public static function settings(): array
    {
        $consec = (int)SettingsService::get(self::SETTING_CONSEC_DOWN, (string)self::DEFAULT_CONSEC_DOWN);
        if ($consec < 1) {
            $consec = 1;
        }
        if ($consec > 20) {
            $consec = 20;
        }
        $packets = (int)SettingsService::get(self::SETTING_PACKETS, (string)self::DEFAULT_PACKETS);
        if ($packets < 1) {
            $packets = 1;
        }
        if ($packets > 10) {
            $packets = 10;
        }
        $timeout = (int)SettingsService::get(self::SETTING_TIMEOUT_MS, (string)self::DEFAULT_TIMEOUT_MS);
        if ($timeout < 200) {
            $timeout = 200;
        }
        if ($timeout > 10000) {
            $timeout = 10000;
        }
        $cd = (int)SettingsService::get(self::SETTING_COOLDOWN_MIN, (string)self::DEFAULT_COOLDOWN_MIN);
        if ($cd < 5) {
            $cd = 5;
        }
        if ($cd > 10080) {
            $cd = 10080;
        }
        return [
            // Master switch: default ON so toggles on inventory work once columns exist
            'enabled' => SettingsService::get(self::SETTING_ENABLED, '1') !== '0',
            'alerts' => SettingsService::get(self::SETTING_ALERTS, '0') === '1',
            'email' => trim((string)SettingsService::get(self::SETTING_EMAIL, '')),
            'consec_down' => $consec,
            'packets' => $packets,
            'timeout_ms' => $timeout,
            'cooldown_min' => $cd,
        ];
    }

    /**
     * Host to ping for a devices row.
     * Prefer iDRAC for Dell when set, else mgmt_ip, else primary_ip.
     * @param array<string,mixed> $device
     */
    public static function hostFromDevice(array $device): string
    {
        if (class_exists('SnmpDiscover')) {
            require_once __DIR__ . '/SnmpDiscover.php';
            $h = SnmpDiscover::snmpHostFromDevice($device);
            if ($h !== '') {
                return $h;
            }
        }
        $h = trim((string)($device['mgmt_ip'] ?? ''));
        if ($h === '') {
            $h = trim((string)($device['primary_ip'] ?? ''));
        }
        return $h;
    }

    /**
     * @param array<string,mixed> $pdu
     */
    public static function hostFromPdu(array $pdu): string
    {
        return trim((string)($pdu['ip_address'] ?? ''));
    }

    /**
     * Run OS ping. Returns reachability for this single check (not yet debounced).
     *
     * @return array{ok:bool,rtt_ms:?float,loss_pct:float,packets:int,received:int,error:?string,raw:?string}
     */
    public static function pingHost(string $host, ?int $packets = null, ?int $timeoutMs = null): array
    {
        $cfg = self::settings();
        $packets = $packets ?? $cfg['packets'];
        $timeoutMs = $timeoutMs ?? $cfg['timeout_ms'];
        $host = trim($host);
        if ($host === '') {
            return [
                'ok' => false,
                'rtt_ms' => null,
                'loss_pct' => 100.0,
                'packets' => 0,
                'received' => 0,
                'error' => 'No host/IP to ping',
                'raw' => null,
            ];
        }
        // Basic injection guard: only hostnames / IPs
        if (!preg_match('/^(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9][a-zA-Z0-9._-]*|[0-9.]+)$/', $host)
            && !filter_var($host, FILTER_VALIDATE_IP)
        ) {
            return [
                'ok' => false,
                'rtt_ms' => null,
                'loss_pct' => 100.0,
                'packets' => 0,
                'received' => 0,
                'error' => 'Invalid host for ICMP',
                'raw' => null,
            ];
        }

        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWin) {
            // -n count, -w timeout ms
            $cmd = 'ping -n ' . (int)$packets . ' -w ' . (int)$timeoutMs . ' ' . escapeshellarg($host);
        } else {
            // -c count, -W timeout seconds (ceil)
            $sec = max(1, (int)ceil($timeoutMs / 1000));
            $cmd = 'ping -c ' . (int)$packets . ' -W ' . $sec . ' ' . escapeshellarg($host);
        }

        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $raw = implode("\n", $out);
        $received = 0;
        $rtt = null;

        // Parse RTT samples
        if (preg_match_all('/time[=<]\s*([\d.]+)\s*ms/i', $raw, $m)) {
            $samples = array_map('floatval', $m[1]);
            if ($samples) {
                $received = count($samples);
                $rtt = round(array_sum($samples) / count($samples), 2);
            }
        }
        // Windows summary line: "Packets: Sent = 4, Received = 4, Lost = 0"
        if (preg_match('/Received\s*=\s*(\d+)/i', $raw, $rm)) {
            $received = max($received, (int)$rm[1]);
        } elseif (preg_match('/(\d+)\s+received/i', $raw, $rm)) {
            $received = max($received, (int)$rm[1]);
        }

        $loss = 100.0;
        if ($packets > 0) {
            $loss = max(0.0, min(100.0, (1.0 - ($received / $packets)) * 100.0));
        }
        // Industry default: require at least one reply for this check to be "up"
        $ok = $received > 0 && $code === 0;
        // Some Windows locales return code 0 with 100% loss — trust received count
        if ($received > 0) {
            $ok = true;
        }
        if ($received === 0) {
            $ok = false;
        }

        $err = null;
        if (!$ok) {
            $err = $received === 0
                ? ('No reply (' . (int)$loss . '% loss)')
                : ('Ping failed exit=' . $code);
            if (stripos($raw, 'could not find host') !== false
                || stripos($raw, 'unknown host') !== false
                || stripos($raw, 'Name or service not known') !== false
            ) {
                $err = 'DNS/host not found';
            }
        }

        return [
            'ok' => $ok,
            'rtt_ms' => $ok ? $rtt : null,
            'loss_pct' => round($loss, 1),
            'packets' => $packets,
            'received' => $received,
            'error' => $err,
            'raw' => mb_substr($raw, 0, 500),
        ];
    }

    /**
     * Check one inventory row and update debounce / alerts.
     *
     * @param 'device'|'pdu' $kind
     * @param array<string,mixed> $row
     * @return array{ok:bool,status:string,fail_count:int,rtt_ms:?float,host:string,error:?string,state_changed:bool}
     */
    public static function checkEntity(string $kind, array $row): array
    {
        $cfg = self::settings();
        $id = $kind === 'pdu' ? (int)($row['pdu_id'] ?? 0) : (int)($row['device_id'] ?? 0);
        $host = $kind === 'pdu' ? self::hostFromPdu($row) : self::hostFromDevice($row);
        $label = $kind === 'pdu'
            ? (string)($row['name'] ?? ('PDU #' . $id))
            : (string)($row['label'] ?? ('Device #' . $id));

        if ($host === '') {
            $patch = [
                'icmp_last_at' => date('Y-m-d H:i:s'),
                'icmp_last_ok' => 0,
                'icmp_last_rtt_ms' => null,
                'icmp_last_error' => 'No IP/hostname to ping',
            ];
            self::updateEntity($kind, $id, $patch);
            return [
                'ok' => false,
                'status' => 'down',
                'fail_count' => (int)($row['icmp_fail_count'] ?? 0) + 1,
                'rtt_ms' => null,
                'host' => '',
                'error' => 'No IP/hostname to ping',
                'state_changed' => false,
            ];
        }

        $probe = self::pingHost($host);
        $prevOk = $row['icmp_last_ok'] ?? null; // null = never checked
        $prevFail = (int)($row['icmp_fail_count'] ?? 0);
        $wasConfirmedDown = $prevOk !== null && !(int)$prevOk;

        if ($probe['ok']) {
            $failCount = 0;
            $isOk = true;
            $status = 'up';
        } else {
            $failCount = $prevFail + 1;
            if ($failCount >= $cfg['consec_down'] || $wasConfirmedDown) {
                // Confirmed down (threshold) or already down and still failing
                $isOk = false;
                $status = 'down';
            } else {
                // Grace window — do not flip last_ok yet (debounce flapping)
                $isOk = true;
                $status = 'degraded';
            }
        }

        $now = date('Y-m-d H:i:s');
        $patch = [
            'icmp_last_at' => $now,
            'icmp_last_ok' => $isOk ? 1 : 0,
            'icmp_fail_count' => $failCount,
            'icmp_last_rtt_ms' => $probe['rtt_ms'],
            'icmp_last_error' => $probe['ok'] ? null : ($probe['error'] ?? 'unreachable'),
        ];
        self::updateEntity($kind, $id, $patch);

        $stateChanged = false;
        $nowConfirmedDown = !$isOk;
        if (!$wasConfirmedDown && $nowConfirmedDown) {
            $stateChanged = true;
            self::maybeAlert($kind, $id, $label, $host, 'down', $probe);
        } elseif ($wasConfirmedDown && $probe['ok'] && $isOk) {
            $stateChanged = true;
            self::maybeAlert($kind, $id, $label, $host, 'up', $probe);
        }

        return [
            'ok' => $isOk,
            'status' => $status,
            'fail_count' => $failCount,
            'rtt_ms' => $probe['rtt_ms'],
            'host' => $host,
            'error' => $probe['error'],
            'state_changed' => $stateChanged,
            'loss_pct' => $probe['loss_pct'],
            'received' => $probe['received'],
            'packets' => $probe['packets'],
        ];
    }

    /**
     * Poll all devices/PDUs with icmp_monitor = 1.
     * @return array{checked:int,up:int,down:int,failed:int}
     */
    public static function pollAll(): array
    {
        $cfg = self::settings();
        if (!$cfg['enabled']) {
            return ['checked' => 0, 'up' => 0, 'down' => 0, 'failed' => 0];
        }
        $stats = ['checked' => 0, 'up' => 0, 'down' => 0, 'failed' => 0];
        try {
            $devices = Database::fetchAll(
                'SELECT * FROM devices WHERE is_active = 1 AND icmp_monitor = 1'
            );
        } catch (Throwable $e) {
            $devices = [];
        }
        foreach ($devices as $d) {
            try {
                $r = self::checkEntity('device', $d);
                $stats['checked']++;
                if ($r['ok']) {
                    $stats['up']++;
                } else {
                    $stats['down']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                App::log('ICMP device ' . ($d['device_id'] ?? '?') . ': ' . $e->getMessage(), 'error');
            }
        }
        try {
            $pdus = Database::fetchAll(
                'SELECT * FROM pdus WHERE is_active = 1 AND icmp_monitor = 1'
            );
        } catch (Throwable $e) {
            $pdus = [];
        }
        foreach ($pdus as $p) {
            try {
                $r = self::checkEntity('pdu', $p);
                $stats['checked']++;
                if ($r['ok']) {
                    $stats['up']++;
                } else {
                    $stats['down']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                App::log('ICMP pdu ' . ($p['pdu_id'] ?? '?') . ': ' . $e->getMessage(), 'error');
            }
        }
        return $stats;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function updateEntity(string $kind, int $id, array $fields): void
    {
        if ($id < 1 || !$fields) {
            return;
        }
        if ($kind === 'pdu') {
            Database::update('pdus', $fields, 'pdu_id = :id', [':id' => $id]);
        } else {
            Database::update('devices', $fields, 'device_id = :id', [':id' => $id]);
        }
    }

    /**
     * @param array<string,mixed> $probe
     */
    private static function maybeAlert(
        string $kind,
        int $id,
        string $label,
        string $host,
        string $event,
        array $probe
    ): void {
        $cfg = self::settings();
        if (!$cfg['alerts']) {
            return;
        }
        $emails = self::normalizeEmails($cfg['email']);
        if (!$emails && class_exists('PowerAlertService')) {
            // Fall back to power alerts mailbox if ICMP-specific empty
            $emails = self::normalizeEmails((string)SettingsService::get('power_alerts_email', ''));
        }
        if (!$emails || !class_exists('MailService') || !MailService::isEnabled()) {
            return;
        }
        $ck = 'icmp_alert_' . $kind . '_' . $id . '_' . $event;
        $last = SettingsService::get($ck, '');
        if (is_string($last) && $last !== '') {
            $ts = strtotime($last);
            if ($ts !== false && (time() - $ts) < ($cfg['cooldown_min'] * 60) && $event === 'down') {
                return; // suppress repeat DOWN
            }
        }
        SettingsService::set($ck, date('Y-m-d H:i:s'), 'icmp');

        $subj = $event === 'down'
            ? '[ColdAisle] ICMP DOWN: ' . $label
            : '[ColdAisle] ICMP recovered: ' . $label;
        $body = ($event === 'down' ? 'Host unreachable' : 'Host recovered') . "\n\n"
            . 'Entity: ' . $kind . ' #' . $id . ' — ' . $label . "\n"
            . 'Host: ' . $host . "\n"
            . 'Time: ' . date('c') . "\n";
        if ($event === 'down') {
            $body .= 'Error: ' . ($probe['error'] ?? 'no reply') . "\n"
                . 'Loss: ' . ($probe['loss_pct'] ?? 100) . "%\n";
        } else {
            $body .= 'RTT: ' . ($probe['rtt_ms'] ?? '—') . " ms\n";
        }
        try {
            MailService::send($emails, $subj, ['text' => $body]);
        } catch (Throwable $e) {
            App::log('ICMP alert mail: ' . $e->getMessage(), 'error');
        }
    }

    /** @return list<string> */
    private static function normalizeEmails(string $raw): array
    {
        $parts = preg_split('/[,;\s]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * UI helper: status badge data from a row.
     * @param array<string,mixed> $row
     * @return array{enabled:bool,status:string,label:string,rtt:?string,at:?string,host:string,error:?string}
     */
    public static function statusFromRow(string $kind, array $row): array
    {
        $enabled = !empty($row['icmp_monitor']);
        $host = $kind === 'pdu' ? self::hostFromPdu($row) : self::hostFromDevice($row);
        if (!$enabled) {
            return [
                'enabled' => false,
                'status' => 'off',
                'label' => 'ICMP off',
                'rtt' => null,
                'at' => null,
                'host' => $host,
                'error' => null,
            ];
        }
        $cfg = self::settings();
        $lastOk = $row['icmp_last_ok'] ?? null;
        $fail = (int)($row['icmp_fail_count'] ?? 0);
        $at = $row['icmp_last_at'] ?? null;
        $rtt = $row['icmp_last_rtt_ms'] ?? null;
        $err = $row['icmp_last_error'] ?? null;
        if ($lastOk === null && $at === null) {
            $status = 'unknown';
            $label = 'Not checked yet';
        } elseif ($fail >= $cfg['consec_down'] || ($lastOk !== null && !(int)$lastOk && $fail >= $cfg['consec_down'])) {
            $status = 'down';
            $label = 'DOWN';
        } elseif ($fail > 0 && (int)$lastOk === 1) {
            $status = 'degraded';
            $label = 'Degraded (' . $fail . '/' . $cfg['consec_down'] . ')';
        } elseif ($lastOk !== null && (int)$lastOk === 1) {
            $status = 'up';
            $label = 'UP';
        } else {
            $status = 'down';
            $label = 'DOWN';
        }
        return [
            'enabled' => true,
            'status' => $status,
            'label' => $label,
            'rtt' => $rtt !== null && $rtt !== '' ? rtrim(rtrim(sprintf('%.2F', (float)$rtt), '0'), '.') . ' ms' : null,
            'at' => $at ? (string)$at : null,
            'host' => $host,
            'error' => $err ? (string)$err : null,
        ];
    }
}
