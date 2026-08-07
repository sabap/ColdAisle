<?php
/**
 * ColdAisle — aggregate health for cabinets (and optional PDUs).
 *
 * Worst-of signals:
 *   - Device ICMP (monitor-enabled devices in cabinet)
 *   - PDU ICMP (cabinet-linked PDUs)
 *   - Active power_alert_state on those PDUs
 *   - Env sensor last_alert_level (sensors on cabinet or on devices in cabinet)
 *
 * Status vocabulary (UI):
 *   ok | warn | crit | unknown
 * ICMP down → crit; degraded → warn; up → ok.
 */
declare(strict_types=1);

class CabinetHealthService
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const CRIT = 'crit';
    public const UNKNOWN = 'unknown';

    /** Hex used for 3D / floorplan tint targets */
    public const COLOR_OK = '#22c55e';
    public const COLOR_WARN = '#eab308';
    public const COLOR_CRIT = '#ef4444';
    public const COLOR_UNKNOWN = '#64748b';

    /**
     * @param list<int> $cabinetIds
     * @return array<int, array{
     *   status:string,label:string,reasons:list<string>,
     *   counts:array{ok:int,warn:int,crit:int,monitored:int},
     *   color:string
     * }>
     */
    public static function forCabinetIds(array $cabinetIds): array
    {
        $ids = [];
        foreach ($cabinetIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }

        $agg = [];
        foreach ($ids as $id) {
            $agg[$id] = [
                'rank' => 0,
                'reasons' => [],
                'counts' => ['ok' => 0, 'warn' => 0, 'crit' => 0, 'monitored' => 0],
            ];
        }

        $idList = implode(',', array_map('intval', $ids));

        // Devices with ICMP monitoring
        try {
            if (!class_exists('IcmpMonitorService')
                && is_file(__DIR__ . '/IcmpMonitorService.php')
            ) {
                require_once __DIR__ . '/IcmpMonitorService.php';
            }
            $devices = Database::fetchAll(
                "SELECT device_id, cabinet_id, label, icmp_monitor, icmp_fail_count,
                        icmp_last_at, icmp_last_ok, icmp_last_rtt_ms, icmp_last_error,
                        mgmt_ip, primary_ip
                 FROM devices
                 WHERE is_active = 1
                   AND cabinet_id IN ({$idList})
                   AND icmp_monitor = 1"
            );
            foreach ($devices as $d) {
                $cid = (int)$d['cabinet_id'];
                if (!isset($agg[$cid])) {
                    continue;
                }
                $st = class_exists('IcmpMonitorService')
                    ? IcmpMonitorService::statusFromRow('device', $d)
                    : ['status' => 'unknown', 'label' => '—'];
                $mapped = self::mapIcmp((string)($st['status'] ?? 'unknown'));
                self::bump($agg[$cid], $mapped, 'ICMP ' . (string)($d['label'] ?? 'device')
                    . ': ' . (string)($st['label'] ?? $mapped));
            }
        } catch (Throwable $e) {
            // columns may not exist yet
        }

        // PDUs on cabinet
        try {
            $pdus = Database::fetchAll(
                "SELECT pdu_id, cabinet_id, name, ip_address, icmp_monitor, icmp_fail_count,
                        icmp_last_at, icmp_last_ok, icmp_last_rtt_ms, icmp_last_error
                 FROM pdus
                 WHERE is_active = 1
                   AND cabinet_id IN ({$idList})"
            );
            $pduIds = [];
            foreach ($pdus as $p) {
                $cid = (int)($p['cabinet_id'] ?? 0);
                if (!isset($agg[$cid])) {
                    continue;
                }
                $pduIds[(int)$p['pdu_id']] = $cid;
                if (!empty($p['icmp_monitor']) && class_exists('IcmpMonitorService')) {
                    $st = IcmpMonitorService::statusFromRow('pdu', $p);
                    $mapped = self::mapIcmp((string)($st['status'] ?? 'unknown'));
                    self::bump($agg[$cid], $mapped, 'PDU ICMP ' . (string)($p['name'] ?? 'pdu')
                        . ': ' . (string)($st['label'] ?? $mapped));
                }
            }

            // Active power alerts for those PDUs
            if ($pduIds !== []) {
                $pList = implode(',', array_map('intval', array_keys($pduIds)));
                try {
                    $alerts = Database::fetchAll(
                        "SELECT pdu_id, severity, last_message
                         FROM power_alert_state
                         WHERE is_active = 1 AND pdu_id IN ({$pList})"
                    );
                    foreach ($alerts as $a) {
                        $pid = (int)$a['pdu_id'];
                        $cid = $pduIds[$pid] ?? 0;
                        if (!$cid || !isset($agg[$cid])) {
                            continue;
                        }
                        $sev = strtolower((string)($a['severity'] ?? 'warning'));
                        $mapped = $sev === 'critical' || $sev === 'crit' ? self::CRIT : self::WARN;
                        $msg = trim((string)($a['last_message'] ?? ''));
                        self::bump(
                            $agg[$cid],
                            $mapped,
                            'Power: ' . ($msg !== '' ? mb_substr($msg, 0, 80) : $sev)
                        );
                    }
                } catch (Throwable $e) {
                    // table may not exist
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        // Env sensors on cabinet or on devices in cabinet
        try {
            $sensors = Database::fetchAll(
                "SELECT s.sensor_id, s.name, s.last_alert_level, s.cabinet_id, s.device_id,
                        d.cabinet_id AS device_cabinet_id
                 FROM env_sensors s
                 LEFT JOIN devices d ON d.device_id = s.device_id AND d.is_active = 1
                 WHERE s.is_active = 1
                   AND (
                     s.cabinet_id IN ({$idList})
                     OR d.cabinet_id IN ({$idList})
                   )
                   AND s.last_alert_level IN ('warn', 'crit', 'warning', 'critical')"
            );
            foreach ($sensors as $s) {
                $cid = (int)($s['cabinet_id'] ?? 0);
                if ($cid < 1) {
                    $cid = (int)($s['device_cabinet_id'] ?? 0);
                }
                if ($cid < 1 || !isset($agg[$cid])) {
                    continue;
                }
                $lvl = strtolower((string)($s['last_alert_level'] ?? ''));
                $mapped = in_array($lvl, ['crit', 'critical'], true) ? self::CRIT : self::WARN;
                self::bump(
                    $agg[$cid],
                    $mapped,
                    'Env ' . (string)($s['name'] ?? 'sensor') . ': ' . $lvl
                );
            }
        } catch (Throwable $e) {
            // ignore
        }

        $out = [];
        foreach ($agg as $cid => $a) {
            $status = self::rankToStatus((int)$a['rank']);
            $out[$cid] = [
                'status' => $status,
                'label' => self::statusLabel($status, $a['counts']),
                'reasons' => array_slice(array_values(array_unique($a['reasons'])), 0, 12),
                'counts' => $a['counts'],
                'color' => self::statusColor($status),
            ];
        }
        return $out;
    }

    /**
     * Attach health blob onto each cabinet row (by cabinet_id).
     *
     * @param list<array<string,mixed>> $cabinets
     * @return list<array<string,mixed>>
     */
    public static function attach(array $cabinets): array
    {
        if ($cabinets === []) {
            return [];
        }
        $ids = [];
        foreach ($cabinets as $c) {
            $id = (int)($c['cabinet_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $map = self::forCabinetIds($ids);
        foreach ($cabinets as &$c) {
            $cid = (int)($c['cabinet_id'] ?? 0);
            $h = $map[$cid] ?? [
                'status' => self::UNKNOWN,
                'label' => 'No monitors',
                'reasons' => [],
                'counts' => ['ok' => 0, 'warn' => 0, 'crit' => 0, 'monitored' => 0],
                'color' => self::COLOR_UNKNOWN,
            ];
            $c['health'] = $h;
            $c['health_status'] = $h['status'];
            $c['health_label'] = $h['label'];
            $c['health_color'] = $h['color'];
            // Optional display tint (does not overwrite color_hex)
            $c['health_display_hex'] = self::blendHex(
                (string)($c['color_hex'] ?? '#2d3748'),
                $h['status']
            );
        }
        unset($c);
        return $cabinets;
    }

    /**
     * Health for standalone / floor PDUs (not only cabinet-linked).
     *
     * @param list<array<string,mixed>> $pdus
     * @return list<array<string,mixed>>
     */
    public static function attachPdus(array $pdus): array
    {
        if ($pdus === []) {
            return [];
        }
        if (!class_exists('IcmpMonitorService') && is_file(__DIR__ . '/IcmpMonitorService.php')) {
            require_once __DIR__ . '/IcmpMonitorService.php';
        }
        $pduIds = [];
        foreach ($pdus as $p) {
            $id = (int)($p['pdu_id'] ?? 0);
            if ($id > 0) {
                $pduIds[] = $id;
            }
        }
        $powerByPdu = [];
        if ($pduIds !== []) {
            $pList = implode(',', array_map('intval', $pduIds));
            try {
                $rows = Database::fetchAll(
                    "SELECT pdu_id, severity, last_message
                     FROM power_alert_state
                     WHERE is_active = 1 AND pdu_id IN ({$pList})"
                );
                foreach ($rows as $r) {
                    $powerByPdu[(int)$r['pdu_id']][] = $r;
                }
            } catch (Throwable $e) {
            }
        }

        foreach ($pdus as &$p) {
            $slot = [
                'rank' => 0,
                'reasons' => [],
                'counts' => ['ok' => 0, 'warn' => 0, 'crit' => 0, 'monitored' => 0],
            ];

            if (!empty($p['icmp_monitor']) && class_exists('IcmpMonitorService')) {
                $st = IcmpMonitorService::statusFromRow('pdu', $p);
                $mapped = self::mapIcmp((string)($st['status'] ?? 'unknown'));
                self::bump($slot, $mapped, 'ICMP: ' . (string)($st['label'] ?? $mapped));
            }
            $pid = (int)($p['pdu_id'] ?? 0);
            foreach ($powerByPdu[$pid] ?? [] as $a) {
                $sev = strtolower((string)($a['severity'] ?? 'warning'));
                $mapped = in_array($sev, ['critical', 'crit'], true) ? self::CRIT : self::WARN;
                $msg = trim((string)($a['last_message'] ?? ''));
                self::bump($slot, $mapped, 'Power: ' . ($msg !== '' ? mb_substr($msg, 0, 80) : $sev));
            }
            $status = self::rankToStatus((int)$slot['rank']);
            $h = [
                'status' => $status,
                'label' => self::statusLabel($status, $slot['counts']),
                'reasons' => array_slice(array_values(array_unique($slot['reasons'])), 0, 8),
                'counts' => $slot['counts'],
                'color' => self::statusColor($status),
            ];
            $p['health'] = $h;
            $p['health_status'] = $h['status'];
            $p['health_label'] = $h['label'];
            $p['health_color'] = $h['color'];
        }
        unset($p);
        return $pdus;
    }

    /**
     * Blend base cabinet color toward warn/crit for body fill.
     */
    public static function blendHex(string $baseHex, string $status): string
    {
        $base = self::parseHex($baseHex) ?? [45, 55, 72];
        if ($status === self::CRIT) {
            $t = self::parseHex(self::COLOR_CRIT) ?? [239, 68, 68];
            $a = 0.55;
        } elseif ($status === self::WARN) {
            $t = self::parseHex(self::COLOR_WARN) ?? [234, 179, 8];
            $a = 0.45;
        } elseif ($status === self::OK) {
            // subtle cool green shift, keep identity
            $t = self::parseHex(self::COLOR_OK) ?? [34, 197, 94];
            $a = 0.12;
        } else {
            return self::formatHex($base);
        }
        $out = [
            (int)round($base[0] * (1 - $a) + $t[0] * $a),
            (int)round($base[1] * (1 - $a) + $t[1] * $a),
            (int)round($base[2] * (1 - $a) + $t[2] * $a),
        ];
        return self::formatHex($out);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::OK => self::COLOR_OK,
            self::WARN => self::COLOR_WARN,
            self::CRIT => self::COLOR_CRIT,
            default => self::COLOR_UNKNOWN,
        };
    }

    public static function statusLabel(string $status, array $counts = []): string
    {
        return match ($status) {
            self::OK => 'Healthy',
            self::WARN => 'Warning'
                . (!empty($counts['warn']) ? ' (' . (int)$counts['warn'] . ')' : ''),
            self::CRIT => 'Critical'
                . (!empty($counts['crit']) ? ' (' . (int)$counts['crit'] . ')' : ''),
            default => 'No monitors',
        };
    }

    /**
     * Map ICMP status vocabulary → health status.
     */
    public static function mapIcmp(string $icmpStatus): string
    {
        return match (strtolower($icmpStatus)) {
            'up' => self::OK,
            'degraded' => self::WARN,
            'down' => self::CRIT,
            'off' => self::UNKNOWN,
            default => self::UNKNOWN,
        };
    }

    /** @param array{rank:int,reasons:list<string>,counts:array} $slot */
    private static function bump(array &$slot, string $status, string $reason): void
    {
        $rank = match ($status) {
            self::CRIT => 3,
            self::WARN => 2,
            self::OK => 1,
            default => 0,
        };
        if ($rank < 1) {
            return;
        }
        $slot['counts']['monitored'] = (int)$slot['counts']['monitored'] + 1;
        if ($status === self::CRIT) {
            $slot['counts']['crit'] = (int)$slot['counts']['crit'] + 1;
        } elseif ($status === self::WARN) {
            $slot['counts']['warn'] = (int)$slot['counts']['warn'] + 1;
        } else {
            $slot['counts']['ok'] = (int)$slot['counts']['ok'] + 1;
        }
        if ($rank > (int)$slot['rank']) {
            $slot['rank'] = $rank;
        }
        // Only surface non-ok reasons (keeps tooltips short)
        if ($status !== self::OK && $reason !== '') {
            $slot['reasons'][] = $reason;
        }
    }

    private static function rankToStatus(int $rank): string
    {
        return match ($rank) {
            3 => self::CRIT,
            2 => self::WARN,
            1 => self::OK,
            default => self::UNKNOWN,
        };
    }

    /** @return ?array{0:int,1:int,2:int} */
    private static function parseHex(string $hex): ?array
    {
        $hex = trim($hex);
        if ($hex === '') {
            return null;
        }
        if ($hex[0] === '#') {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            return null;
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function formatHex(array $rgb): string
    {
        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, $rgb[0])),
            max(0, min(255, $rgb[1])),
            max(0, min(255, $rgb[2]))
        );
    }
}
