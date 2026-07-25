<?php
/**
 * ColdAisle — power / PDU load alerts after SNMP poll.
 *
 * Alerts are evaluated per PDU, then queued. After a hold window (or end of
 * pollAll), a single cascaded digest is sent:
 *   PDU → Cabinet → Row → Zone → Datacenter
 * so a site-wide event does not produce dozens of emails.
 */
declare(strict_types=1);

class PowerAlertService
{
    public const SETTING_ENABLED = 'power_alerts_enabled';
    public const SETTING_EMAIL = 'power_alerts_email';
    public const SETTING_WARN_PCT = 'power_alerts_warn_pct';
    public const SETTING_CRIT_PCT = 'power_alerts_crit_pct';
    public const SETTING_COOLDOWN = 'power_alerts_cooldown_min';
    public const SETTING_HOLD = 'power_alerts_hold_sec';
    public const SETTING_UTIL = 'power_alerts_util';
    public const SETTING_LOAD_STATE = 'power_alerts_load_state';
    public const SETTING_PS = 'power_alerts_ps';
    public const SETTING_WINDOW_START = 'power_alerts_window_start';

    /**
     * @return array{
     *   enabled:bool,email:string,warn_pct:float,crit_pct:float,cooldown_min:int,
     *   hold_sec:int,util:bool,load_state:bool,ps:bool
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
        // Hold: default 120s — gather multi-PDU events before one digest
        $hold = (int)SettingsService::get(self::SETTING_HOLD, '120');
        if ($hold < 15) {
            $hold = 15;
        }
        if ($hold > 3600) {
            $hold = 3600;
        }
        return [
            'enabled' => SettingsService::get(self::SETTING_ENABLED, '0') === '1',
            'email' => trim((string)SettingsService::get(self::SETTING_EMAIL, '')),
            'warn_pct' => $warn,
            'crit_pct' => $crit,
            'cooldown_min' => $cd,
            'hold_sec' => $hold,
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
        // UI may send minutes for hold — accept hold_sec or hold_min
        if (isset($post['power_alerts_hold_sec'])) {
            $hold = max(15, min(3600, (int)$post['power_alerts_hold_sec']));
        } else {
            $holdMin = (float)($post['power_alerts_hold_min'] ?? 2);
            $hold = max(15, min(3600, (int)round($holdMin * 60)));
        }
        SettingsService::set(self::SETTING_HOLD, (string)$hold, 'power_alerts');
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
     * Queues digests — does not send email immediately.
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

        // Opportunistic flush if hold window already elapsed
        self::flushDigestsIfDue(false);
    }

    /**
     * Call at end of pollAll (and optionally CLI) so a multi-PDU cycle
     * can flush once the hold window has elapsed — or force after a long poll.
     *
     * @param bool $force If true, flush any pending queue now (end of scheduled run
     *                    when hold already passed, or admin "send now").
     * @return array{flushed:bool,pdu_count:int,alert_count:int}
     */
    public static function flushDigestsIfDue(bool $force = false): array
    {
        $empty = ['flushed' => false, 'pdu_count' => 0, 'alert_count' => 0];
        $cfg = self::settings();
        if (!$cfg['enabled']) {
            return $empty;
        }

        try {
            $pending = Database::fetchAll(
                'SELECT * FROM power_alert_queue WHERE digest_id IS NULL ORDER BY queued_at ASC'
            );
        } catch (Throwable $e) {
            return $empty;
        }
        if (!$pending) {
            self::clearWindowStart();
            return $empty;
        }

        $oldest = strtotime((string)($pending[0]['queued_at'] ?? 'now')) ?: time();
        $hold = (int)$cfg['hold_sec'];
        $elapsed = time() - $oldest;
        // Force only if we have waited at least a short floor (15s) unless empty
        if (!$force && $elapsed < $hold) {
            return $empty;
        }
        if ($force && $elapsed < 15 && count($pending) < 2) {
            // Single-PDU manual poll: still respect a tiny floor so rapid re-polls merge
            if ($elapsed < min(15, $hold)) {
                return $empty;
            }
        }

        return self::sendDigest($pending, $cfg);
    }

    /**
     * Force-flush pending digests (e.g. end of scheduled poll after hold, or settings test).
     * @return array{flushed:bool,pdu_count:int,alert_count:int}
     */
    public static function flushDigestsNow(): array
    {
        return self::flushDigestsIfDue(true);
    }

    /**
     * @param list<array<string,mixed>> $pending
     * @param array<string,mixed> $cfg
     * @return array{flushed:bool,pdu_count:int,alert_count:int}
     */
    private static function sendDigest(array $pending, array $cfg): array
    {
        $digestId = date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        // Mark queued rows as batched
        $ids = [];
        foreach ($pending as $row) {
            if (isset($row['queue_id'])) {
                $ids[] = (int)$row['queue_id'];
            }
        }
        if ($ids) {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                Database::query(
                    "UPDATE power_alert_queue SET digest_id = ?, digested_at = ? WHERE queue_id IN ($placeholders)",
                    array_merge([$digestId, $now], $ids)
                );
            } catch (Throwable $e) {
                App::log('Power alert digest mark failed: ' . $e->getMessage(), 'error');
            }
        }

        $pduIds = [];
        foreach ($pending as $row) {
            $pduIds[(int)$row['pdu_id']] = true;
        }
        $locations = self::loadPduLocations(array_keys($pduIds));

        // Aggregate by PDU: worst severity + list of issues
        /** @var array<int,array{severity:string,issues:list<string>,name:string}> $byPdu */
        $byPdu = [];
        foreach ($pending as $row) {
            $pid = (int)$row['pdu_id'];
            if (!isset($byPdu[$pid])) {
                $loc = $locations[$pid] ?? [];
                $byPdu[$pid] = [
                    'severity' => (string)$row['severity'],
                    'issues' => [],
                    'name' => (string)($loc['pdu_name'] ?? ('PDU #' . $pid)),
                ];
            }
            if (self::severityRank((string)$row['severity']) > self::severityRank($byPdu[$pid]['severity'])) {
                $byPdu[$pid]['severity'] = (string)$row['severity'];
            }
            $summary = trim((string)($row['summary'] ?? $row['message'] ?? ''));
            if ($summary !== '' && !in_array($summary, $byPdu[$pid]['issues'], true)) {
                $byPdu[$pid]['issues'][] = $summary;
            }
        }

        $tree = self::buildCascadeTree($byPdu, $locations);
        $critCount = 0;
        $warnCount = 0;
        foreach ($byPdu as $p) {
            if ($p['severity'] === 'critical') {
                $critCount++;
            } else {
                $warnCount++;
            }
        }
        $pduCount = count($byPdu);
        $alertCount = count($pending);

        $title = $pduCount === 1
            ? ('Power alert: ' . reset($byPdu)['name'])
            : sprintf('Power event: %d PDUs affected', $pduCount);
        if ($critCount > 0 && $warnCount > 0) {
            $title .= sprintf(' (%d critical, %d warning)', $critCount, $warnCount);
        } elseif ($critCount > 0) {
            $title .= ' (critical)';
        }

        $bodyText = self::formatDigestText($title, $tree, $pduCount, $critCount, $warnCount, $alertCount);
        $bodyShort = self::formatDigestShort($tree, $pduCount, $critCount, $warnCount);

        // Single in-app notification (not 84)
        try {
            Database::insert('notifications', [
                'user_id' => null,
                'title' => mb_substr($title, 0, 200),
                'message' => mb_substr($bodyShort, 0, 4000),
                'category' => $critCount > 0 ? 'warning' : 'power',
                'entity_type' => 'power_digest',
                'entity_id' => null,
                'is_read' => 0,
            ]);
        } catch (Throwable $e) {
            App::log('Power digest notification failed: ' . $e->getMessage(), 'error');
        }

        $emails = self::normalizeEmailList($cfg['email']);
        if ($emails && class_exists('MailService') && MailService::isEnabled()) {
            $subject = '[' . App::APP_NAME . '] ' . $title;
            try {
                $result = MailService::send($emails, $subject, ['text' => $bodyText]);
                if (empty($result['ok'])) {
                    App::log('Power digest email failed: ' . ($result['message'] ?? 'unknown'), 'warning');
                }
            } catch (Throwable $e) {
                App::log('Power digest email exception: ' . $e->getMessage(), 'error');
            }
        }

        self::clearWindowStart();
        App::log(sprintf(
            'Power alert digest %s: %d PDU(s), %d condition(s)',
            $digestId,
            $pduCount,
            $alertCount
        ), 'info');

        return ['flushed' => true, 'pdu_count' => $pduCount, 'alert_count' => $alertCount];
    }

    /**
     * @param list<int> $pduIds
     * @return array<int,array<string,mixed>>
     */
    private static function loadPduLocations(array $pduIds): array
    {
        if (!$pduIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($pduIds), '?'));
        try {
            $rows = Database::fetchAll(
                "SELECT p.pdu_id, p.name AS pdu_name, p.cabinet_id, p.row_id AS pdu_row_id, p.zone_id AS pdu_zone_id,
                        c.name AS cabinet_name, c.row_id AS cabinet_row_id,
                        r.row_id, r.name AS row_name, r.zone_id AS row_zone_id, r.room_id,
                        z.zone_id, z.name AS zone_name, z.datacenter_id AS zone_dc_id,
                        rm.name AS room_name, rm.datacenter_id AS room_dc_id,
                        dc.datacenter_id, dc.name AS dc_name
                 FROM pdus p
                 LEFT JOIN cabinets c ON c.cabinet_id = p.cabinet_id
                 LEFT JOIN cabinet_rows r ON r.row_id = COALESCE(p.row_id, c.row_id)
                 LEFT JOIN power_zones z ON z.zone_id = COALESCE(p.zone_id, r.zone_id)
                 LEFT JOIN rooms rm ON rm.room_id = r.room_id
                 LEFT JOIN datacenters dc ON dc.datacenter_id = COALESCE(z.datacenter_id, rm.datacenter_id)
                 WHERE p.pdu_id IN ($placeholders)",
                array_values($pduIds)
            );
        } catch (Throwable $e) {
            // Minimal fallback
            try {
                $rows = Database::fetchAll(
                    "SELECT pdu_id, name AS pdu_name FROM pdus WHERE pdu_id IN ($placeholders)",
                    array_values($pduIds)
                );
            } catch (Throwable $e2) {
                return [];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['pdu_id']] = $r;
        }
        return $out;
    }

    /**
     * Nest: DC → Zone → Row → Cabinet → PDUs
     *
     * @param array<int,array{severity:string,issues:list<string>,name:string}> $byPdu
     * @param array<int,array<string,mixed>> $locations
     * @return array<string,mixed>
     */
    private static function buildCascadeTree(array $byPdu, array $locations): array
    {
        $tree = [];
        foreach ($byPdu as $pid => $info) {
            $loc = $locations[$pid] ?? [];
            $dc = trim((string)($loc['dc_name'] ?? '')) ?: 'Unassigned datacenter';
            $zone = trim((string)($loc['zone_name'] ?? '')) ?: 'Unassigned zone';
            $row = trim((string)($loc['row_name'] ?? '')) ?: 'Unassigned row';
            $cab = trim((string)($loc['cabinet_name'] ?? ''));
            if ($cab === '') {
                $cab = !empty($loc['cabinet_id']) ? ('Cabinet #' . $loc['cabinet_id']) : 'No cabinet (row/room PDU)';
            }

            if (!isset($tree[$dc])) {
                $tree[$dc] = [];
            }
            if (!isset($tree[$dc][$zone])) {
                $tree[$dc][$zone] = [];
            }
            if (!isset($tree[$dc][$zone][$row])) {
                $tree[$dc][$zone][$row] = [];
            }
            if (!isset($tree[$dc][$zone][$row][$cab])) {
                $tree[$dc][$zone][$row][$cab] = [];
            }
            $tree[$dc][$zone][$row][$cab][] = [
                'pdu_id' => $pid,
                'name' => $info['name'],
                'severity' => $info['severity'],
                'issues' => $info['issues'],
            ];
        }
        return $tree;
    }

    /**
     * @param array<string,mixed> $tree
     */
    private static function formatDigestText(
        string $title,
        array $tree,
        int $pduCount,
        int $critCount,
        int $warnCount,
        int $alertCount
    ): string {
        $lines = [
            $title,
            str_repeat('=', min(60, strlen($title))),
            sprintf('%d PDU(s) · %d condition(s) · %d critical / %d warning', $pduCount, $alertCount, $critCount, $warnCount),
            'Time: ' . date('c'),
            '',
            'Hierarchy: Datacenter → Zone → Row → Cabinet → PDU',
            '',
        ];
        foreach ($tree as $dc => $zones) {
            $lines[] = 'DATACENTER: ' . $dc;
            foreach ($zones as $zone => $rows) {
                $lines[] = '  Zone: ' . $zone;
                foreach ($rows as $row => $cabs) {
                    $lines[] = '    Row: ' . $row;
                    foreach ($cabs as $cab => $pdus) {
                        $lines[] = '      Cabinet: ' . $cab;
                        foreach ($pdus as $p) {
                            $tag = strtoupper((string)$p['severity']);
                            $issue = $p['issues'] ? implode('; ', $p['issues']) : $tag;
                            $lines[] = sprintf('        [%s] %s — %s', $tag, $p['name'], $issue);
                        }
                    }
                }
            }
            $lines[] = '';
        }
        $lines[] = '— ' . App::APP_NAME . ' power alerts (batched digest)';
        return implode("\n", $lines);
    }

    /**
     * Compact body for in-app notification list.
     * @param array<string,mixed> $tree
     */
    private static function formatDigestShort(array $tree, int $pduCount, int $critCount, int $warnCount): string
    {
        $bits = [sprintf('%d PDU(s) affected (%d critical, %d warning).', $pduCount, $critCount, $warnCount)];
        $n = 0;
        foreach ($tree as $dc => $zones) {
            foreach ($zones as $zone => $rows) {
                foreach ($rows as $row => $cabs) {
                    foreach ($cabs as $cab => $pdus) {
                        foreach ($pdus as $p) {
                            if ($n >= 12) {
                                $bits[] = '…';
                                return implode(' ', $bits);
                            }
                            $bits[] = $p['name'] . ' [' . $p['severity'] . ']';
                            $n++;
                        }
                    }
                }
            }
        }
        return implode(' · ', $bits);
    }

    /**
     * @param array<string,mixed> $pdu
     * @param array<string,mixed> $cfg
     * @return list<array{key:string,severity:string,title:string,message:string,summary:string,kind:string}>
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
                        'kind' => 'util',
                        'title' => $name . ' · ' . $lab . ' critical load',
                        'summary' => sprintf('%s %.0f%% util', $lab, $pct),
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
                        'kind' => 'util',
                        'title' => $name . ' · ' . $lab . ' high load',
                        'summary' => sprintf('%s %.0f%% util', $lab, $pct),
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
                    'kind' => 'util',
                    'title' => $name . ' · critical load',
                    'summary' => sprintf('total %.0f%% util', $pct),
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
                    'kind' => 'util',
                    'title' => $name . ' · high load',
                    'summary' => sprintf('total %.0f%% util', $pct),
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
                if ($st >= 4) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':loadstate:' . $lab,
                        'severity' => 'critical',
                        'kind' => 'loadstate',
                        'title' => $name . ' · ' . $lab . ' overload',
                        'summary' => $lab . ' OVERLOAD',
                        'message' => $name . ' phase ' . $lab . ' reports load state OVERLOAD (' . $st . ').',
                    ];
                } elseif ($st === 3) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':loadstate:' . $lab,
                        'severity' => 'warning',
                        'kind' => 'loadstate',
                        'title' => $name . ' · ' . $lab . ' near overload',
                        'summary' => $lab . ' near overload',
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
                if ($v === 2) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':ps:' . $k,
                        'severity' => 'critical',
                        'kind' => 'ps',
                        'title' => $name . ' · ' . $label . ' fault',
                        'summary' => $label . ' FAULT',
                        'message' => $name . ' power supply ' . $label . ' reports FAULT.',
                    ];
                }
            }
            if (isset($ps['alarm']) && is_numeric($ps['alarm'])) {
                $a = (int)$ps['alarm'];
                if ($a !== 1 && $a !== 0) {
                    $out[] = [
                        'key' => $pdu['pdu_id'] . ':ps:alarm',
                        'severity' => 'warning',
                        'kind' => 'ps',
                        'title' => $name . ' · power supply alarm',
                        'summary' => 'PS alarm ' . $a,
                        'message' => $name . ' reports power supply alarm code ' . $a . '.',
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $pdu
     * @param array{key:string,severity:string,title:string,message:string,summary?:string,kind?:string} $cond
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

        $shouldQueue = false;
        if (!$state) {
            $shouldQueue = true;
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
            if (!$wasActive || $escalated || $cooled) {
                $shouldQueue = true;
            }
            try {
                Database::update('power_alert_state', [
                    'severity' => $cond['severity'],
                    'is_active' => 1,
                    'last_message' => mb_substr($cond['message'], 0, 500),
                    'last_fired_at' => $shouldQueue ? $now : ($state['last_fired_at'] ?? $now),
                    'notify_count' => (int)($state['notify_count'] ?? 0) + ($shouldQueue ? 1 : 0),
                    'last_cleared_at' => null,
                ], 'alert_key = :k', [':k' => $key]);
            } catch (Throwable $e) {
                // ignore
            }
        }

        if ($shouldQueue) {
            self::enqueue($pdu, $cond);
        }
    }

    /**
     * @param array<string,mixed> $pdu
     * @param array{key:string,severity:string,title:string,message:string,summary?:string,kind?:string} $cond
     */
    private static function enqueue(array $pdu, array $cond): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            // Replace any undigested row for same alert_key (keep latest message)
            $existing = Database::fetchOne(
                'SELECT queue_id FROM power_alert_queue WHERE alert_key = ? AND digest_id IS NULL',
                [$cond['key']]
            );
            if ($existing) {
                Database::update('power_alert_queue', [
                    'severity' => $cond['severity'],
                    'kind' => (string)($cond['kind'] ?? 'power'),
                    'summary' => mb_substr((string)($cond['summary'] ?? $cond['title']), 0, 200),
                    'message' => mb_substr($cond['message'], 0, 500),
                    'queued_at' => $now,
                ], 'queue_id = :id', [':id' => (int)$existing['queue_id']]);
            } else {
                Database::insert('power_alert_queue', [
                    'alert_key' => $cond['key'],
                    'pdu_id' => (int)$pdu['pdu_id'],
                    'severity' => $cond['severity'],
                    'kind' => (string)($cond['kind'] ?? 'power'),
                    'summary' => mb_substr((string)($cond['summary'] ?? $cond['title']), 0, 200),
                    'message' => mb_substr($cond['message'], 0, 500),
                    'queued_at' => $now,
                    'digest_id' => null,
                ]);
            }
        } catch (Throwable $e) {
            App::log('Power alert enqueue failed: ' . $e->getMessage(), 'error');
            return;
        }

        // Open hold window on first pending item
        $start = SettingsService::get(self::SETTING_WINDOW_START, '');
        if ($start === '' || $start === null) {
            SettingsService::set(self::SETTING_WINDOW_START, $now, 'power_alerts');
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
                // Drop undigested queue rows for cleared conditions
                Database::query(
                    'DELETE FROM power_alert_queue WHERE alert_key = ? AND digest_id IS NULL',
                    [$k]
                );
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    private static function clearWindowStart(): void
    {
        try {
            // Only clear if queue empty
            $n = (int)Database::fetchValue(
                'SELECT COUNT(*) FROM power_alert_queue WHERE digest_id IS NULL'
            );
            if ($n === 0) {
                SettingsService::set(self::SETTING_WINDOW_START, '', 'power_alerts');
            }
        } catch (Throwable $e) {
            // ignore
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
