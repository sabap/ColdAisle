<?php
/**
 * ColdAisle — power / PDU load alerts after SNMP poll.
 *
 * Checks phase util %, APC load state, and dual power-supply health.
 * Raises in-app notifications (broadcast) and optional SMTP email with cooldown.
 */
declare(strict_types=1);

class PowerAlertService
{
    public const SETTING_ENABLED = 'power_alerts_enabled';
    public const SETTING_EMAIL = 'power_alerts_email';
    public const SETTING_WARN_PCT = 'power_alerts_warn_pct';
    public const SETTING_CRIT_PCT = 'power_alerts_crit_pct';
    public const SETTING_COOLDOWN = 'power_alerts_cooldown_min';
    public const SETTING_UTIL = 'power_alerts_util';
    public const SETTING_LOAD_STATE = 'power_alerts_load_state';
    public const SETTING_PS = 'power_alerts_ps';

    /**
     * @return array{
     *   enabled:bool,email:string,warn_pct:float,crit_pct:float,cooldown_min:int,
     *   util:bool,load_state:bool,ps:bool
     * }
     */
    public static function settings(): array
    {
        $warn = (float)SettingsService::get(self::SETTING_WARN_PCT, '75');
        $crit = (float)SettingsService::get(self::SETTING_CRIT_PCT, '90');
        if ($warn < 1) {
            $warn = 75.0;
        }
        if ($crit < $warn) {
            $crit = max($warn, 90.0);
        }
        $cd = (int)SettingsService::get(self::SETTING_COOLDOWN, '60');
        if ($cd < 5) {
            $cd = 5;
        }
        if ($cd > 10080) {
            $cd = 10080;
        }
        return [
            'enabled' => SettingsService::get(self::SETTING_ENABLED, '0') === '1',
            'email' => trim((string)SettingsService::get(self::SETTING_EMAIL, '')),
            'warn_pct' => $warn,
            'crit_pct' => $crit,
            'cooldown_min' => $cd,
            'util' => SettingsService::get(self::SETTING_UTIL, '1') !== '0',
            'load_state' => SettingsService::get(self::SETTING_LOAD_STATE, '1') !== '0',
            'ps' => SettingsService::get(self::SETTING_PS, '1') !== '0',
        ];
    }

    /** @param array<string,mixed> $post */
    public static function saveSettingsFromPost(array $post): void
    {
        SettingsService::set(
            self::SETTING_ENABLED,
            !empty($post['power_alerts_enabled']) ? '1' : '0',
            'power_alerts'
        );
        $emails = self::normalizeEmailList((string)($post['power_alerts_email'] ?? ''));
        SettingsService::set(self::SETTING_EMAIL, implode(', ', $emails), 'power_alerts');
        $warn = max(1, min(100, (float)($post['power_alerts_warn_pct'] ?? 75)));
        $crit = max(1, min(100, (float)($post['power_alerts_crit_pct'] ?? 90)));
        if ($crit < $warn) {
            $crit = $warn;
        }
        SettingsService::set(self::SETTING_WARN_PCT, (string)$warn, 'power_alerts');
        SettingsService::set(self::SETTING_CRIT_PCT, (string)$crit, 'power_alerts');
        $cd = max(5, min(10080, (int)($post['power_alerts_cooldown_min'] ?? 60)));
        SettingsService::set(self::SETTING_COOLDOWN, (string)$cd, 'power_alerts');
        SettingsService::set(
            self::SETTING_UTIL,
            !empty($post['power_alerts_util']) ? '1' : '0',
            'power_alerts'
        );
        SettingsService::set(
            self::SETTING_LOAD_STATE,
            !empty($post['power_alerts_load_state']) ? '1' : '0',
            'power_alerts'
        );
        SettingsService::set(
            self::SETTING_PS,
            !empty($post['power_alerts_ps']) ? '1' : '0',
            'power_alerts'
        );
    }

    /** @return list<string> */
    public static function normalizeEmailList(string $raw): array
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
     * Evaluate alerts for a PDU after poll data is written.
     * Safe to call always — no-ops when disabled or no data.
     */
    public static function evaluatePdu(int $pduId): void
    {
        if ($pduId < 1) {
            return;
        }
        $cfg = self::settings();
        if (!$cfg['enabled']) {
            return;
        }

        try {
            $pdu = Database::fetchOne(
                'SELECT pdu_id, name, rated_amps, last_poll_watts, last_poll_amps, last_poll_phases, ip_address
                 FROM pdus WHERE pdu_id = ? AND is_active = 1',
                [$pduId]
            );
        } catch (Throwable $e) {
            return;
        }
        if (!$pdu) {
            return;
        }

        $conditions = self::collectConditions($pdu, $cfg);
        $activeKeys = [];
        foreach ($conditions as $c) {
            $activeKeys[$c['key']] = true;
            self::raiseOrRefresh($pdu, $c, $cfg);
        }
        self::clearInactive($pduId, $activeKeys);
    }

    /**
     * @param array<string,mixed> $pdu
     * @param array<string,mixed> $cfg
     * @return list<array{key:string,severity:string,title:string,message:string}>
     */
    private static function collectConditions(array $pdu, array $cfg): array
    {
        $out = [];
        $name = (string)($pdu['name'] ?? ('PDU #' . $pdu['pdu_id']));
        $phasesJson = $pdu['last_poll_phases'] ?? null;
        $snap = null;
        if (function_exists('power_phase_poll_decode')) {
            $snap = power_phase_poll_decode($phasesJson);
        } elseif (is_string($phasesJson) && $phasesJson !== '') {
            $decoded = json_decode($phasesJson, true);
            if (is_array($decoded)) {
                $snap = ['rows' => [], 'll' => [], 'device' => $decoded['_device'] ?? [], 'ps' => $decoded['_ps'] ?? []];
                foreach (['L1', 'L2', 'L3'] as $lab) {
                    if (!empty($decoded[$lab]) && is_array($decoded[$lab])) {
                        $snap['rows'][] = array_merge(['label' => $lab], $decoded[$lab]);
                    }
                }
            }
        }

        $rated = null;
        if ($snap && isset($snap['device']['rated_amps']) && is_numeric($snap['device']['rated_amps'])) {
            $rated = (float)$snap['device']['rated_amps'];
        }
        if (($rated === null || $rated <= 0) && isset($pdu['rated_amps']) && is_numeric($pdu['rated_amps'])) {
            $rated = (float)$pdu['rated_amps'];
        }

        if ($cfg['util'] && $snap && $rated !== null && $rated > 0) {
            foreach ($snap['rows'] as $row) {
                $lab = (string)($row['label'] ?? '');
                $amps = isset($row['amps']) && is_numeric($row['amps']) ? (float)$row['amps'] : null;
                if ($amps === null || $lab === '') {
                    continue;
                }
                $pct = round(($amps / $rated) * 100, 1);
                if ($pct >= $cfg['crit_pct']) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':util:' . $lab . ':crit',
                        'severity' => 'critical',
                        'title' => $name . ' · ' . $lab . ' critical load',
                        'message' => sprintf(
                            '%s phase %s at %s A (%.1f%% of %s A rating). Critical threshold %.0f%%.',
                            $name,
                            $lab,
                            self::fmt($amps),
                            $pct,
                            self::fmt($rated),
                            $cfg['crit_pct']
                        ),
                    ];
                } elseif ($pct >= $cfg['warn_pct']) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':util:' . $lab . ':warn',
                        'severity' => 'warning',
                        'title' => $name . ' · ' . $lab . ' high load',
                        'message' => sprintf(
                            '%s phase %s at %s A (%.1f%% of %s A rating). Warning threshold %.0f%%.',
                            $name,
                            $lab,
                            self::fmt($amps),
                            $pct,
                            self::fmt($rated),
                            $cfg['warn_pct']
                        ),
                    ];
                }
            }
        }

        // Device total amps vs rating when no phase rows
        if ($cfg['util'] && $rated !== null && $rated > 0
            && $pdu['last_poll_amps'] !== null && is_numeric($pdu['last_poll_amps'])
            && (!$snap || empty($snap['rows']))
        ) {
            $amps = (float)$pdu['last_poll_amps'];
            $pct = round(($amps / $rated) * 100, 1);
            if ($pct >= $cfg['crit_pct']) {
                $out[] = [
                    'key' => $pdu['pdu_id'] . ':util:device:crit',
                    'severity' => 'critical',
                    'title' => $name . ' · critical load',
                    'message' => sprintf(
                        '%s total %s A (%.1f%% of %s A). Critical threshold %.0f%%.',
                        $name,
                        self::fmt($amps),
                        $pct,
                        self::fmt($rated),
                        $cfg['crit_pct']
                    ),
                ];
            } elseif ($pct >= $cfg['warn_pct']) {
                $out[] = [
                    'key' => $pdu['pdu_id'] . ':util:device:warn',
                    'severity' => 'warning',
                    'title' => $name . ' · high load',
                    'message' => sprintf(
                        '%s total %s A (%.1f%% of %s A). Warning threshold %.0f%%.',
                        $name,
                        self::fmt($amps),
                        $pct,
                        self::fmt($rated),
                        $cfg['warn_pct']
                    ),
                ];
            }
        }

        if ($cfg['load_state'] && $snap) {
            foreach ($snap['rows'] as $row) {
                $lab = (string)($row['label'] ?? '');
                $st = isset($row['load_state']) && is_numeric($row['load_state'])
                    ? (int)$row['load_state']
                    : null;
                if ($st === null || $lab === '') {
                    continue;
                }
                // APC: 3 near overload, 4 overload
                if ($st >= 4) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':loadstate:' . $lab,
                        'severity' => 'critical',
                        'title' => $name . ' · ' . $lab . ' overload',
                        'message' => $name . ' phase ' . $lab . ' reports load state OVERLOAD (' . $st . ').',
                    ];
                } elseif ($st === 3) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':loadstate:' . $lab,
                        'severity' => 'warning',
                        'title' => $name . ' · ' . $lab . ' near overload',
                        'message' => $name . ' phase ' . $lab . ' reports load state NEAR OVERLOAD.',
                    ];
                }
            }
        }

        if ($cfg['ps'] && $snap && !empty($snap['ps'])) {
            $ps = $snap['ps'];
            foreach (['ps1' => 'PS1', 'ps2' => 'PS2'] as $k => $label) {
                if (!isset($ps[$k]) || !is_numeric($ps[$k])) {
                    continue;
                }
                $v = (int)$ps[$k];
                // 1 = OK, 2 = fault, 3 = not present (not an alert by itself)
                if ($v === 2) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':ps:' . $k,
                        'severity' => 'critical',
                        'title' => $name . ' · ' . $label . ' fault',
                        'message' => $name . ' power supply ' . $label . ' reports FAULT.',
                    ];
                }
            }
            if (isset($ps['alarm']) && is_numeric($ps['alarm'])) {
                $a = (int)$ps['alarm'];
                // APC often 1 = no alarm; anything else may indicate alarm present
                if ($a !== 1 && $a !== 0) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':ps:alarm',
                        'severity' => 'warning',
                        'title' => $name . ' · power supply alarm',
                        'message' => $name . ' reports power supply alarm code ' . $a . '.',
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $pdu
     * @param array{key:string,severity:string,title:string,message:string} $cond
     * @param array<string,mixed> $cfg
     */
    private static function raiseOrRefresh(array $pdu, array $cond, array $cfg): void
    {
        $key = $cond['key'];
        $now = date('Y-m-d H:i:s');
        $state = null;
        try {
            $state = Database::fetchOne(
                'SELECT * FROM power_alert_state WHERE alert_key = ?',
                [$key]
            );
        } catch (Throwable $e) {
            return;
        }

        $shouldNotify = false;
        if (!$state) {
            $shouldNotify = true;
            try {
                Database::insert('power_alert_state', [
                    'alert_key' => $key,
                    'pdu_id' => (int)$pdu['pdu_id'],
                    'severity' => $cond['severity'],
                    'is_active' => 1,
                    'last_fired_at' => $now,
                    'last_message' => mb_substr($cond['message'], 0, 500),
                    'notify_count' => 1,
                ]);
            } catch (Throwable $e) {
                return;
            }
        } else {
            $wasActive = !empty($state['is_active']);
            $prevSev = (string)($state['severity'] ?? '');
            $escalated = self::severityRank($cond['severity']) > self::severityRank($prevSev);
            $lastFire = $state['last_fired_at'] ?? null;
            $cooled = true;
            if ($lastFire) {
                $elapsed = time() - strtotime((string)$lastFire);
                $cooled = $elapsed >= ((int)$cfg['cooldown_min'] * 60);
            }
            // Notify: new active, severity up, or still active past cooldown (reminder)
            if (!$wasActive || $escalated || $cooled) {
                $shouldNotify = true;
            }
            try {
                Database::update('power_alert_state', [
                    'severity' => $cond['severity'],
                    'is_active' => 1,
                    'last_message' => mb_substr($cond['message'], 0, 500),
                    'last_fired_at' => $shouldNotify ? $now : ($state['last_fired_at'] ?? $now),
                    'notify_count' => (int)($state['notify_count'] ?? 0) + ($shouldNotify ? 1 : 0),
                    'last_cleared_at' => null,
                ], 'alert_key = :k', [':k' => $key]);
            } catch (Throwable $e) {
                // ignore
            }
        }

        if ($shouldNotify) {
            self::notify($pdu, $cond);
        }
    }

    /** @param array<string,bool> $activeKeys */
    private static function clearInactive(int $pduId, array $activeKeys): void
    {
        try {
            $rows = Database::fetchAll(
                'SELECT alert_key FROM power_alert_state WHERE pdu_id = ? AND is_active = 1',
                [$pduId]
            );
        } catch (Throwable $e) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $k = (string)$r['alert_key'];
            if (isset($activeKeys[$k])) {
                continue;
            }
            try {
                Database::update('power_alert_state', [
                    'is_active' => 0,
                    'last_cleared_at' => $now,
                ], 'alert_key = :k', [':k' => $k]);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * @param array<string,mixed> $pdu
     * @param array{key:string,severity:string,title:string,message:string} $cond
     */
    private static function notify(array $pdu, array $cond): void
    {
        try {
            Database::insert('notifications', [
                'user_id' => null,
                'title' => mb_substr($cond['title'], 0, 200),
                'message' => $cond['message'],
                'category' => $cond['severity'] === 'critical' ? 'warning' : 'power',
                'entity_type' => 'pdu',
                'entity_id' => (int)$pdu['pdu_id'],
                'is_read' => 0,
            ]);
        } catch (Throwable $e) {
            App::log('Power alert notification insert failed: ' . $e->getMessage(), 'error');
        }

        $cfg = self::settings();
        $emails = self::normalizeEmailList($cfg['email']);
        if (!$emails || !class_exists('MailService') || !MailService::isEnabled()) {
            return;
        }

        $ip = trim((string)($pdu['ip_address'] ?? ''));
        $subject = '[' . App::APP_NAME . '] ' . $cond['title'];
        $text = $cond['message'] . "\n\n"
            . 'PDU: ' . ($pdu['name'] ?? $pdu['pdu_id']) . "\n"
            . ($ip !== '' ? 'IP: ' . $ip . "\n" : '')
            . 'Severity: ' . $cond['severity'] . "\n"
            . 'Time: ' . date('c') . "\n";
        try {
            $result = MailService::send($emails, $subject, ['text' => $text]);
            if (empty($result['ok'])) {
                App::log('Power alert email failed: ' . ($result['message'] ?? 'unknown'), 'warning');
            }
        } catch (Throwable $e) {
            App::log('Power alert email exception: ' . $e->getMessage(), 'error');
        }
    }

    private static function severityRank(string $sev): int
    {
        return match (strtolower($sev)) {
            'critical' => 3,
            'warning' => 2,
            'info' => 1,
            default => 0,
        };
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(sprintf('%.2F', $n), '0'), '.') ?: '0';
    }
}
